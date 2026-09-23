<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Calendars;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
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
    ) {
    }

    public function status(Request $req): Response
    {
        $userId = (int) $req->user['id'];
        return Response::json([
            'configured' => $this->auth->configured(),
            'accounts' => array_map([GoogleAuth::class, 'serializeAccount'], $this->auth->accounts($userId)),
        ]);
    }

    public function connect(Request $req): Response
    {
        $url = $this->auth->authUrl((int) $req->user['id']);
        return Response::text('', 'text/plain; charset=utf-8', 302, ['Location' => $url]);
    }

    public function callback(Request $req): Response
    {
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
        if ($code === '' || !$this->auth->verifyState($state, $userId)) {
            return $land('error=state');
        }
        try {
            $this->auth->connect($userId, $code);
        } catch (\Throwable $e) {
            error_log('google connect failed: ' . $e->getMessage());
            return $land('error=failed');
        }
        return $land('connected=1');
    }

    public function disconnect(Request $req, array $params): Response
    {
        $this->auth->disconnect((int) $req->user['id'], (int) $params['id']);
        return Response::json(['ok' => true]);
    }

    /** The account's calendars at Google, annotated with which are already subscribed here. */
    public function calendars(Request $req, array $params): Response
    {
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
        foreach ($list as &$c) {
            $c['calendarId'] = $subscribed[$c['id']] ?? null;
        }
        unset($c);
        return Response::json(['calendars' => $list]);
    }

    /** Subscribe to one of the account's calendars; first sync happens now. */
    public function subscribe(Request $req, array $params): Response
    {
        $userId = (int) $req->user['id'];
        $account = $this->auth->account($userId, (int) $params['id']);
        $googleCalendarId = trim((string) ($req->str('googleCalendarId') ?? ''));
        if ($googleCalendarId === '') {
            throw HttpError::badRequest('googleCalendarId is required');
        }
        $existing = $this->db->one(
            'SELECT id FROM calendars WHERE user_id = ? AND google_account_id = ? AND google_calendar_id = ?',
            [$userId, (int) $account['id'], $googleCalendarId]
        );
        if ($existing !== null) {
            throw HttpError::conflict('already_subscribed', 'That calendar is already here');
        }
        $name = trim((string) ($req->str('name') ?? ''));
        $role = (string) ($req->str('accessRole') ?? 'reader');
        if (!in_array($role, ['owner', 'writer', 'reader', 'freeBusyReader'], true)) {
            $role = 'reader';
        }
        $calendar = $this->calendars->create(
            $userId,
            ['name' => $name !== '' ? $name : $googleCalendarId, 'color' => $req->body['color'] ?? null, 'folderIds' => $req->body['folderIds'] ?? null],
            kind: 'subscribed',
            sourceUrl: null,
            google: ['accountId' => (int) $account['id'], 'calendarId' => $googleCalendarId, 'accessRole' => $role],
        );
        try {
            $this->feeds->poll((int) $calendar['id']);
        } catch (\Throwable $e) {
            error_log('initial google sync failed for calendar ' . $calendar['id'] . ': ' . $e->getMessage());
        }
        return Response::json($this->calendars->serializeById($userId, (int) $calendar['id']), 201);
    }
}
