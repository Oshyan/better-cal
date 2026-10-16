<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Calendars;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\Ics;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Infra\Db;
use BetterCal\Support\Limits;

final class CalendarsController
{
    public function __construct(
        private readonly Db $db,
        private readonly Calendars $calendars,
        private readonly Feeds $feeds,
    ) {
    }

    public function index(Request $req): Response
    {
        return Response::json($this->calendars->listAll(
            $this->userId($req),
            $req->authMethod === 'token' ? $req->tokenId : null,
        ));
    }

    public function create(Request $req): Response
    {
        return Response::json($this->calendars->create($this->userId($req), $req->body), 201);
    }

    public function patch(Request $req, array $params): Response
    {
        return Response::json($this->calendars->patch(
            $this->userId($req),
            (int) $params['id'],
            $req->body,
            $req->authMethod === 'token' ? $req->tokenId : null,
        ));
    }

    public function adopt(Request $req, array $params): Response
    {
        return Response::json($this->calendars->adopt((int) $req->user['id'], (int) $params['id']));
    }

    public function delete(Request $req, array $params): Response
    {
        $this->calendars->delete($this->userId($req), (int) $params['id']);
        return Response::json(['ok' => true]);
    }

    public function subscribe(Request $req): Response
    {
        $userId = $this->userId($req);
        $url = trim((string) ($req->str('url') ?? ''));
        if ($url === '') {
            throw HttpError::badRequest('url is required');
        }
        $name = trim((string) ($req->str('name') ?? ''));
        if ($name === '') {
            $host = parse_url(str_replace('webcal://', 'https://', $url), PHP_URL_HOST);
            $name = is_string($host) && $host !== '' ? $host : 'Subscribed calendar';
        }
        $calendar = $this->calendars->create(
            $userId,
            ['name' => $name, 'color' => $req->body['color'] ?? null],
            kind: 'subscribed',
            sourceUrl: $url,
            creatorTokenId: $req->authMethod === 'token' ? $req->tokenId : null,
        );
        try {
            $this->feeds->poll((int) $calendar['id']);
        } catch (\Throwable $e) {
            error_log('initial feed poll failed for calendar ' . $calendar['id'] . ': ' . $e->getMessage());
        }
        return Response::json($this->calendars->serializeById(
            $userId,
            (int) $calendar['id'],
            $req->authMethod === 'token' ? $req->tokenId : null,
        ), 201);
    }

    public function refresh(Request $req, array $params): Response
    {
        $userId = $this->userId($req);
        $id = (int) $params['id'];
        $this->calendars->get($userId, $id);
        try {
            $imported = $this->feeds->poll($id);
        } catch (HttpError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new HttpError('feed_fetch_failed', $e->getMessage(), 502);
        }
        return Response::json(['ok' => true, 'imported' => $imported]);
    }

    /** Explicit owner review/rebinding for a paused token or legacy subscription. */
    public function claimSubscription(Request $req, array $params): Response
    {
        $req->requireSession('Keeping this subscription active');
        return Response::json($this->calendars->claimSubscription((int) $req->user['id'], (int) $params['id']));
    }

    /**
     * Multipart import: an .ics file into a new local calendar, or a zip of
     * them (Google Calendar's export, Takeout) into one calendar per file
     * (Domain\CalendarImport, #136).
     */
    public function import(Request $req): Response
    {
        $userId = $this->userId($req);
        $file = $req->files['ics'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw HttpError::badRequest('Multipart file field "ics" is required');
        }
        // Size first, from the upload metadata, so an oversized file is never
        // even read into memory (BC-12).
        $maxBytes = Limits::get('IMPORT_BYTES');
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new HttpError('import_too_large', 'That file is over the ' . round($maxBytes / 1048576) . ' MiB import limit. Split it, or raise BETTERCAL_LIMIT_IMPORT_BYTES on the server.', 413);
        }
        $importer = new \BetterCal\Domain\CalendarImport($this->db, $this->calendars);
        $tmp = (string) $file['tmp_name'];
        $head = (string) @file_get_contents($tmp, false, null, 0, 4);
        if ($head === "PK\x03\x04" || str_ends_with(strtolower((string) ($file['name'] ?? '')), '.zip')) {
            $result = $importer->importZip($userId, $tmp);
            return Response::json([
                'calendars' => array_map(fn(array $c): array => $this->calendars->serializeById($userId, $c['calendarId']) + ['imported' => $c['imported']], $result['calendars']),
                'skipped' => $result['skipped'],
                'imported' => array_sum(array_column($result['calendars'], 'imported')),
            ], $result['calendars'] === [] ? 200 : 201);
        }
        $ics = file_get_contents($tmp);
        if ($ics === false) {
            throw HttpError::badRequest('File is not an ICS calendar', 'invalid_ics');
        }
        $name = trim((string) ($req->str('name') ?? ''));
        if ($name === '') {
            $name = pathinfo((string) ($file['name'] ?? 'Imported'), PATHINFO_FILENAME) ?: 'Imported';
        }
        // Into one of your calendars (calendarId), or a new one.
        $into = $req->str('calendarId');
        $done = $importer->importIcs($userId, $ics, $name, null, $into !== null && $into !== '' ? (int) $into : null);
        $out = $this->calendars->serializeById($userId, $done['calendarId']);
        $out['imported'] = $done['imported'];
        $out['alreadyThere'] = $done['alreadyThere'];
        return Response::json($out, $into !== null && $into !== '' ? 200 : 201);
    }

    private function userId(Request $req): int
    {
        return (int) $req->user['id'];
    }
}
