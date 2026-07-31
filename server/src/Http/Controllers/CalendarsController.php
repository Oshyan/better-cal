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
        return Response::json($this->calendars->listAll($this->userId($req)));
    }

    public function create(Request $req): Response
    {
        return Response::json($this->calendars->create($this->userId($req), $req->body), 201);
    }

    public function patch(Request $req, array $params): Response
    {
        return Response::json($this->calendars->patch($this->userId($req), (int) $params['id'], $req->body));
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
            sourceUrl: $url
        );
        try {
            $this->feeds->poll((int) $calendar['id']);
        } catch (\Throwable $e) {
            error_log('initial feed poll failed for calendar ' . $calendar['id'] . ': ' . $e->getMessage());
        }
        return Response::json($this->calendars->serializeById($userId, (int) $calendar['id']), 201);
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

    /** Multipart ICS file import into a new local calendar. */
    public function import(Request $req): Response
    {
        $userId = $this->userId($req);
        $file = $req->files['ics'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw HttpError::badRequest('Multipart file field "ics" is required');
        }
        $ics = file_get_contents((string) $file['tmp_name']);
        if ($ics === false || !str_contains($ics, 'BEGIN:VCALENDAR')) {
            throw HttpError::badRequest('File is not an ICS calendar', 'invalid_ics');
        }
        try {
            $parsed = Ics::parse($ics);
        } catch (\Throwable $e) {
            throw HttpError::badRequest('Could not parse ICS: ' . $e->getMessage(), 'invalid_ics');
        }

        $name = trim((string) ($req->str('name') ?? ''));
        if ($name === '') {
            $name = pathinfo((string) ($file['name'] ?? 'Imported'), PATHINFO_FILENAME) ?: 'Imported';
        }
        $calendar = $this->calendars->create($userId, ['name' => $name]);
        $calendarId = (int) $calendar['id'];

        $imported = 0;
        $masterIdByUid = [];
        usort($parsed, static fn(array $a, array $b): int => ($a['recurrence_instance_utc'] === null ? 0 : 1) <=> ($b['recurrence_instance_utc'] === null ? 0 : 1));
        $this->db->tx(function () use ($parsed, $userId, $calendarId, &$imported, &$masterIdByUid): void {
            $seen = [];
            foreach ($parsed as $ev) {
                $key = $ev['uid'] . '|' . ($ev['recurrence_instance_utc'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $id = $this->db->insert('events', [
                    'user_id' => $userId,
                    'calendar_id' => $calendarId,
                    'uid' => $ev['uid'],
                    'title' => $ev['title'],
                    'description' => $ev['description'],
                    'location' => $ev['location'],
                    'url' => $ev['url'],
                    'start_utc' => $ev['start_utc'],
                    'end_utc' => $ev['end_utc'],
                    'all_day' => $ev['all_day'],
                    'tzid' => $ev['tzid'],
                    'rrule' => $ev['rrule'],
                    'exdates_json' => $ev['exdates'] === [] ? null : json_encode($ev['exdates']),
                    // VALARM reminders become explicit overrides; none = inherit defaults.
                    'reminders_json' => ($ev['reminders'] ?? []) === [] ? null : json_encode($ev['reminders']),
                    'status' => $ev['status'],
                    'recurrence_instance_utc' => $ev['recurrence_instance_utc'],
                    'recurrence_parent_id' => $ev['recurrence_instance_utc'] !== null
                        ? ($masterIdByUid[(string) $ev['uid']] ?? null)
                        : null,
                    'source' => 'local',
                ]);
                if ($ev['recurrence_instance_utc'] === null) {
                    $masterIdByUid[(string) $ev['uid']] = $id;
                }
                $imported++;
            }
        });

        $out = $this->calendars->serializeById($userId, $calendarId);
        $out['imported'] = $imported;
        return Response::json($out, 201);
    }

    private function userId(Request $req): int
    {
        return (int) $req->user['id'];
    }
}
