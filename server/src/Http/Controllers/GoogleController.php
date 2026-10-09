<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Auth;
use BetterCal\Domain\Calendars;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleMove;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Infra\Db;

/**
 * Google account connection and calendar subscription (docs/api-contract.md,
 * Google). Consent runs through the browser: /google/connect sends it to
 * Google, Google sends it back to /google/callback, which lands on the app's
 * /google handoff path with a result.
 */
final class GoogleController
{
    public function __construct(
        private readonly Db $db,
        private readonly GoogleAuth $auth,
        private readonly Calendars $calendars,
        private readonly Feeds $feeds,
        private readonly GoogleMove $move,
    ) {
    }

    /**
     * Move a local calendar to Google (#55): body {accountId, googleCalendarId?}.
     * Without googleCalendarId Better-Cal creates the Google calendar. The
     * request may do the first bounded slice; the worker owns every
     * continuation, and GET reports progress.
     */
    public function moveToGoogle(Request $req, array $params): Response
    {
        $req->requireRecentAuthentication('Moving a calendar to Google');
        $userId = (int) $req->user['id'];
        $accountId = (int) ($req->body['accountId'] ?? 0);
        $target = trim((string) ($req->str('googleCalendarId') ?? ''));
        $sessionToken = (string) ($req->cookies[Auth::COOKIE] ?? '');
        return Response::json($this->move->start($userId, (int) $params['id'], $accountId, $sessionToken, $target !== '' ? $target : null));
    }

    public function moveStatus(Request $req, array $params): Response
    {
        return Response::json(['move' => $this->move->status((int) $req->user['id'], (int) $params['id'])]);
    }

    public function stopMove(Request $req, array $params): Response
    {
        $req->requireRecentAuthentication('Stopping a Google calendar move');
        return Response::json($this->move->abandon((int) $req->user['id'], (int) $params['id']));
    }

    public function status(Request $req): Response
    {
        $userId = (int) $req->user['id'];
        // Moves to Google that haven't finished, per account: disconnecting
        // cancels them, and the confirmation says so before it happens.
        $unfinished = [];
        foreach ($this->db->all(
            "SELECT m.google_account_id, m.status, m.total, m.done_count, c.name FROM calendar_moves m
             JOIN calendars c ON c.id = m.calendar_id
             WHERE m.user_id = ? AND m.status IN ('queued', 'running', 'failed') AND m.cancelled_at IS NULL
             ORDER BY m.id",
            [$userId]
        ) as $m) {
            $unfinished[(int) $m['google_account_id']][] = [
                'calendarName' => (string) $m['name'], 'status' => (string) $m['status'],
                'done' => (int) $m['done_count'], 'total' => (int) $m['total'],
            ];
        }
        $accounts = array_map(
            static fn(array $a): array => GoogleAuth::serializeAccount($a) + ['unfinishedMoves' => $unfinished[(int) $a['id']] ?? []],
            $this->auth->accounts($userId)
        );
        return Response::json(['configured' => $this->auth->configured(), 'accounts' => $accounts]);
    }

    public function connect(Request $req): Response
    {
        $req->requireRecentAuthentication('Connecting a Google account');
        $sessionToken = (string) ($req->cookies[\BetterCal\Domain\Auth::COOKIE] ?? '');
        $url = $this->auth->authUrl((int) $req->user['id'], $sessionToken);
        if ($req->method === 'POST') {
            return Response::json(['url' => $url]);
        }
        return Response::text('', 'text/plain; charset=utf-8', 302, ['Location' => $url]);
    }

    public function callback(Request $req): Response
    {
        $req->requireSession('Connecting a Google account');
        $userId = (int) $req->user['id'];
        $land = static fn(string $q): Response => Response::text('', 'text/plain; charset=utf-8', 302, ['Location' => '/google?' . $q]);
        // The landing page shows a fixed message for a code, never text from
        // the URL: any link could otherwise put words in the app's own voice
        // (scan 2026-09-23, F16). Details go to the server log.
        if ($req->q('error') !== null) {
            return $land('error=' . ((string) $req->q('error') === 'access_denied' ? 'denied' : 'google'));
        }
        $state = (string) ($req->q('state') ?? '');
        $code = (string) ($req->q('code') ?? '');
        $sessionToken = (string) ($req->cookies[\BetterCal\Domain\Auth::COOKIE] ?? '');
        if ($code === '' || !$this->auth->verifyState($state, $userId, $sessionToken)) {
            return $land('error=state');
        }
        try {
            $this->auth->connect($userId, $code, $sessionToken);
        } catch (\Throwable $e) {
            error_log('google connect failed: ' . $e->getMessage());
            return $land('error=failed');
        }
        return $land('connected=1');
    }

    public function disconnect(Request $req, array $params): Response
    {
        $req->requireSession('Disconnecting a Google account');
        $this->auth->disconnect((int) $req->user['id'], (int) $params['id']);
        return Response::json(['ok' => true]);
    }

    /** The account's calendars at Google, annotated with which are already subscribed here. */
    public function calendars(Request $req, array $params): Response
    {
        $req->requireSession('Viewing calendars available through Google');
        $userId = (int) $req->user['id'];
        $account = $this->auth->account($userId, (int) $params['id']);
        try {
            $list = $this->auth->listCalendars($account);
        } catch (HttpError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new HttpError('google_unavailable', $e->getMessage(), 502);
        }
        $subscribed = [];
        foreach ($this->db->all('SELECT id, google_calendar_id FROM calendars WHERE user_id = ? AND google_account_id = ?', [$userId, (int) $account['id']]) as $row) {
            $subscribed[(string) $row['google_calendar_id']] = (int) $row['id'];
        }
        // A calendar left here by a disconnected account: Add re-attaches it.
        $orphaned = [];
        foreach ($this->db->all(
            "SELECT id, google_calendar_id FROM calendars
             WHERE user_id = ? AND kind = 'subscribed' AND provider = 'google' AND google_account_id IS NULL
             ORDER BY id DESC",
            [$userId]
        ) as $row) {
            $orphaned[(string) $row['google_calendar_id']] = (int) $row['id'];
        }
        foreach ($list as &$c) {
            $c['calendarId'] = $subscribed[$c['id']] ?? null;
            $c['reattachId'] = $c['calendarId'] === null ? ($orphaned[$c['id']] ?? null) : null;
        }
        unset($c);
        return Response::json(['calendars' => $list]);
    }

    /**
     * Subscribe to one of the account's calendars; first sync happens now.
     * When a calendar here followed this Google calendar until its account
     * was disconnected, that one is re-attached instead of adding a copy
     * (200 with reattached: true, rather than 201).
     */
    public function subscribe(Request $req, array $params): Response
    {
        $req->requireRecentAuthentication('Adding a Google calendar');
        $userId = (int) $req->user['id'];
        $account = $this->auth->account($userId, (int) $params['id']);
        $googleCalendarId = trim((string) ($req->str('googleCalendarId') ?? ''));
        if ($googleCalendarId === '') {
            throw HttpError::badRequest('googleCalendarId is required');
        }
        $name = trim((string) ($req->str('name') ?? ''));
        $role = (string) ($req->str('accessRole') ?? 'reader');
        if (!in_array($role, ['owner', 'writer', 'reader', 'freeBusyReader'], true)) {
            $role = 'reader';
        }
        $sessionToken = (string) ($req->cookies[Auth::COOKIE] ?? '');
        $calendar = $this->db->tx(function () use ($userId, $account, $googleCalendarId, $name, $req, $role, $sessionToken): array {
            // Serialize the durable subscription with the exact initiating
            // session and compromise quarantine. Reconnecting the account
            // cannot revive a request whose session the reset deleted.
            Auth::assertRecentSession($this->db, $userId, $sessionToken, true);
            $account = $this->auth->assertUsable($account, true);
            $existing = $this->db->one(
                'SELECT id FROM calendars WHERE user_id = ? AND google_account_id = ? AND google_calendar_id = ?',
                [$userId, (int) $account['id'], $googleCalendarId]
            );
            if ($existing !== null) {
                throw HttpError::conflict('already_subscribed', 'That calendar is already here');
            }
            $moving = $this->db->scalar(
                "SELECT id FROM calendar_moves
                 WHERE google_account_id = ? AND google_calendar_id = ?
                   AND status IN ('queued', 'running', 'failed') AND cancelled_at IS NULL LIMIT 1",
                [(int) $account['id'], $googleCalendarId]
            );
            if ($moving !== null) {
                throw HttpError::conflict('google_calendar_in_use', 'That Google calendar is reserved by an unfinished move');
            }
            $orphan = $this->calendars->orphanedGoogleCalendar($userId, $googleCalendarId, true);
            if ($orphan !== null) {
                $this->calendars->reattachGoogle($userId, $orphan, (int) $account['id'], $role, (string) $account['email']);
                return ['id' => $orphan, 'reattached' => true];
            }
            return $this->calendars->create(
                $userId,
                [
                    'name' => $name !== '' ? $name : $googleCalendarId,
                    'color' => $req->body['color'] ?? null,
                    'folderIds' => $req->body['folderIds'] ?? null,
                    // A primary calendar's id is the account's email.
                    'role' => GoogleAuth::defaultRole(GoogleAuth::calendarKind($googleCalendarId, $role, $googleCalendarId === strtolower((string) $account['email']))),
                ],
                kind: 'subscribed',
                sourceUrl: null,
                google: ['accountId' => (int) $account['id'], 'calendarId' => $googleCalendarId, 'accessRole' => $role],
            );
        });
        try {
            $this->feeds->poll((int) $calendar['id']);
        } catch (\Throwable $e) {
            error_log('initial google sync failed for calendar ' . $calendar['id'] . ': ' . $e->getMessage());
        }
        $reattached = !empty($calendar['reattached']);
        return Response::json(
            $this->calendars->serializeById($userId, (int) $calendar['id']) + ['reattached' => $reattached],
            $reattached ? 200 : 201
        );
    }
}
