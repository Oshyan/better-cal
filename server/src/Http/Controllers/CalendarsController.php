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
        // Size first, from the upload metadata, so an oversized file is never
        // even read into memory; then the event count, on the raw text, before
        // the parser turns each one into objects and the loop below into rows
        // inside one transaction (BC-12).
        $maxBytes = Limits::get('IMPORT_BYTES');
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new HttpError('import_too_large', 'That file is over the ' . round($maxBytes / 1048576) . ' MiB import limit. Split it, or raise BETTERCAL_LIMIT_IMPORT_BYTES on the server.', 413);
        }
        $ics = file_get_contents((string) $file['tmp_name']);
        if ($ics === false || !str_contains($ics, 'BEGIN:VCALENDAR')) {
            throw HttpError::badRequest('File is not an ICS calendar', 'invalid_ics');
        }
        // The configured cap, or fewer when this PHP process could not hold
        // that many parsed events: a clear refusal beats an out-of-memory crash.
        $maxEvents = Limits::importEventBudget();
        $problem = Ics::budgetProblem($ics, $maxBytes, $maxEvents);
        if ($problem !== null) {
            $how = $maxEvents < Limits::get('IMPORT_EVENTS')
                ? 'This server has memory for about ' . number_format($maxEvents) . ' events at once: split the file, or raise PHP memory_limit.'
                : 'Split the file, or raise BETTERCAL_LIMIT_IMPORT_EVENTS on the server.';
            throw new HttpError('import_too_large', $problem . '. ' . $how, 413);
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
                    'created_via' => 'import',
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
