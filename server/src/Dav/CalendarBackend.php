<?php

declare(strict_types=1);

namespace BetterCal\Dav;

use BetterCal\Domain\Ics;
use BetterCal\Domain\Trips;
use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;
use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Backend\SyncSupport;
use Sabre\CalDAV\Plugin as CalDAVPlugin;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\PropPatch;

/**
 * CalDAV backend over the Better-Cal schema.
 *
 * Mapping: calendars rows -> DAV calendars (uri "cal-{id}"); one calendar
 * object per event uid ("{uid}.ics") containing the master VEVENT (with
 * RRULE/EXDATEs) plus per-instance overrides as RECURRENCE-ID VEVENTs.
 * Subscribed (feed) calendars are advertised read-only and reject writes.
 * Mutations are journaled via ChangeLog and recorded in the Undo log for
 * parity with the JSON API.
 */
final class CalendarBackend extends AbstractBackend implements SyncSupport
{
    public function __construct(
        private readonly Db $db,
        private readonly Undo $undo,
    ) {
    }

    // ---- Calendars ----------------------------------------------------

    public function getCalendarsForUser($principalUri)
    {
        $email = self::emailFromPrincipal((string) $principalUri);
        $user = $this->db->one('SELECT id FROM users WHERE email = ?', [$email]);
        if ($user === null) {
            return [];
        }
        $out = [];
        $rows = $this->db->all('SELECT * FROM calendars WHERE user_id = ? ORDER BY position, id', [(int) $user['id']]);
        foreach ($rows as $row) {
            $token = (int) ($row['synctoken'] ?? 1);
            $info = [
                'id' => (int) $row['id'],
                'uri' => DavIcs::calendarUri((int) $row['id']),
                'principaluri' => (string) $principalUri,
                '{DAV:}displayname' => (string) $row['name'],
                '{http://apple.com/ns/ical/}calendar-color' => strtoupper((string) $row['color']) . 'FF',
                '{http://apple.com/ns/ical/}calendar-order' => (string) (int) $row['position'],
                '{http://calendarserver.org/ns/}getctag' => 'http://sabre.io/ns/sync/' . $token,
                '{http://sabredav.org/ns}sync-token' => $token,
                '{' . CalDAVPlugin::NS_CALDAV . '}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
                // The object size putObject enforces is advertised to clients as
                // CALDAV:max-resource-size by Dav\SizedCalDavPlugin (the stock plugin
                // answers that property itself, so it cannot be set from here).
                // The cap on overrides has no standard property: max-instances
                // means how many occurrences a series may generate, which is not
                // limited, and advertising it could make a strict client refuse
                // an ordinary "repeats forever" event.
            ];
            if ($row['kind'] === 'subscribed') {
                $info['{http://sabredav.org/ns}read-only'] = true;
            }
            $out[] = $info;
        }
        return $out;
    }

    public function createCalendar($principalUri, $calendarUri, array $properties)
    {
        throw new Forbidden('Create calendars in the Better-Cal app or API; MKCALENDAR is not supported');
    }

    public function updateCalendar($calendarId, PropPatch $propPatch)
    {
        $calendar = $this->calendarRow($calendarId);
        $propPatch->handle(
            ['{DAV:}displayname', '{http://apple.com/ns/ical/}calendar-color'],
            function (array $mutations) use ($calendar) {
                if ($calendar['kind'] === 'subscribed') {
                    return 403;
                }
                $fields = [];
                if (array_key_exists('{DAV:}displayname', $mutations)) {
                    $name = trim((string) $mutations['{DAV:}displayname']);
                    if ($name === '') {
                        return 400;
                    }
                    $fields['name'] = mb_substr($name, 0, 160);
                }
                if (array_key_exists('{http://apple.com/ns/ical/}calendar-color', $mutations)) {
                    // Apple sends #RRGGBBAA; store #rrggbb.
                    $color = (string) $mutations['{http://apple.com/ns/ical/}calendar-color'];
                    if (preg_match('/^#([0-9a-fA-F]{6})(?:[0-9a-fA-F]{2})?$/', $color, $m) !== 1) {
                        return 400;
                    }
                    $fields['color'] = '#' . strtolower($m[1]);
                }
                if ($fields !== []) {
                    $this->db->update('calendars', $fields, 'id = ?', [(int) $calendar['id']]);
                }
                return true;
            }
        );
    }

    public function deleteCalendar($calendarId)
    {
        throw new Forbidden('Delete calendars in the Better-Cal app or API');
    }

    // ---- Calendar objects (read) --------------------------------------

    public function getCalendarObjects($calendarId)
    {
        // Metadata only; bodies are built on demand in getCalendarObject().
        $rows = $this->db->all(
            'SELECT m.id, m.uid, m.updated_at,
                    (SELECT MAX(o.updated_at) FROM events o
                      WHERE o.calendar_id = m.calendar_id AND o.uid = m.uid
                        AND o.recurrence_instance_utc IS NOT NULL AND o.deleted_at IS NULL) AS override_updated_at
             FROM events m
             WHERE m.calendar_id = ? AND m.deleted_at IS NULL
               AND m.recurrence_parent_id IS NULL AND m.recurrence_instance_utc IS NULL',
            [(int) $calendarId]
        );
        return array_map(fn(array $row): array => $this->objectMeta($row), $rows);
    }

    public function getCalendarObject($calendarId, $objectUri)
    {
        $objects = $this->getMultipleCalendarObjects($calendarId, [(string) $objectUri]);
        return $objects === [] ? null : $objects[0];
    }

    public function getMultipleCalendarObjects($calendarId, array $uris)
    {
        $uids = [];
        foreach ($uris as $uri) {
            $uid = DavIcs::uidFromObjectUri((string) $uri);
            if ($uid !== null) {
                $uids[] = $uid;
            }
        }
        if ($uids === []) {
            return [];
        }
        [$in, $params] = Db::in($uids);
        $rows = $this->db->all(
            "SELECT * FROM events WHERE calendar_id = ? AND uid IN $in AND deleted_at IS NULL
             ORDER BY recurrence_instance_utc IS NOT NULL, recurrence_instance_utc, id",
            [(int) $calendarId, ...$params]
        );

        $masters = [];
        $overrides = [];
        foreach ($rows as $row) {
            $uid = (string) $row['uid'];
            if ($row['recurrence_instance_utc'] === null && $row['recurrence_parent_id'] === null) {
                $masters[$uid] = $row;
            } else {
                $overrides[$uid][] = $row;
            }
        }

        // Trip relationships export as RELATED-TO on the master VEVENT
        // (batch-loaded once for all requested objects).
        $related = Trips::relatedUidMap(
            $this->db,
            array_map(static fn(array $m): int => (int) $m['id'], array_values($masters))
        );

        $out = [];
        foreach ($uids as $uid) {
            $master = $masters[$uid] ?? null;
            if ($master === null) {
                continue;
            }
            $mid = (int) $master['id'];
            $data = DavIcs::buildObject(
                $master,
                $overrides[$uid] ?? [],
                $related['children'][$mid] ?? [],
                $related['parents'][$mid] ?? []
            );
            $updated = (string) $master['updated_at'];
            foreach ($overrides[$uid] ?? [] as $ov) {
                $updated = max($updated, (string) $ov['updated_at']);
            }
            $out[] = [
                'id' => (int) $master['id'],
                'uri' => DavIcs::objectUri($uid),
                'lastmodified' => Time::fromDb($updated)->getTimestamp(),
                'etag' => '"' . DavIcs::etag($updated, (int) $master['id']) . '"',
                'size' => strlen($data),
                'calendardata' => $data,
                'component' => 'vevent',
            ];
        }
        return $out;
    }

    // ---- Calendar objects (write) -------------------------------------

    public function createCalendarObject($calendarId, $objectUri, $calendarData)
    {
        return $this->putObject((int) $calendarId, (string) $objectUri, (string) $calendarData);
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData)
    {
        return $this->putObject((int) $calendarId, (string) $objectUri, (string) $calendarData);
    }

    public function deleteCalendarObject($calendarId, $objectUri)
    {
        $calendar = $this->calendarRow($calendarId);
        $this->assertWritable($calendar);
        $uid = DavIcs::uidFromObjectUri((string) $objectUri);
        if ($uid === null) {
            return;
        }
        $rows = $this->db->all(
            'SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND deleted_at IS NULL',
            [(int) $calendar['id'], $uid]
        );
        if ($rows === []) {
            return;
        }
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $masterId = (int) $ids[0];
        foreach ($rows as $row) {
            if ($row['recurrence_instance_utc'] === null && $row['recurrence_parent_id'] === null) {
                $masterId = (int) $row['id'];
            }
        }
        [$in, $params] = Db::in($ids);
        $this->db->tx(function () use ($in, $params): void {
            $this->db->run("UPDATE events SET deleted_at = ? WHERE id IN $in", [Time::nowDb(), ...$params]);
        });
        $afterRows = $this->db->all("SELECT * FROM events WHERE id IN $in", $params);
        $this->undo->record((int) $calendar['user_id'], 'event', $masterId, 'delete', ['events' => $rows], ['events' => $afterRows]);
        ChangeLog::record($this->db, (int) $calendar['id'], $uid, ChangeLog::OP_DELETE);
    }

    /** Upsert one calendar object (PUT): master by (calendar_id, uid), overrides by instance. */
    private function putObject(int $calendarId, string $objectUri, string $calendarData): ?string
    {
        $calendar = $this->calendarRow($calendarId);
        $this->assertWritable($calendar);

        // Bounded BEFORE parsing: one object may carry any number of
        // RECURRENCE-ID overrides, and each becomes parser memory and then a row
        // written inside one transaction (BC-13). An event with its overrides
        // is small; a megabyte or 500 overrides is not an event.
        $problem = Ics::budgetProblem($calendarData, Limits::get('DAV_OBJECT_BYTES'), Limits::get('DAV_OVERRIDES') + 1);
        if ($problem !== null) {
            throw new Forbidden(str_replace('calendar file', 'calendar object', $problem) . ' (CALDAV:max-resource-size)');
        }

        $parsed = Ics::parse($calendarData);
        $masters = array_values(array_filter($parsed, static fn(array $p): bool => $p['recurrence_instance_utc'] === null));
        $parsedOverrides = array_values(array_filter($parsed, static fn(array $p): bool => $p['recurrence_instance_utc'] !== null));
        if (count($masters) !== 1) {
            throw new BadRequest('Calendar object must contain exactly one non-RECURRENCE-ID VEVENT');
        }
        $uid = (string) $masters[0]['uid'];
        foreach ($parsed as $p) {
            if ((string) $p['uid'] !== $uid) {
                throw new BadRequest('All VEVENTs in a calendar object must share one UID');
            }
        }
        $uriUid = DavIcs::uidFromObjectUri($objectUri);
        if ($uriUid !== $uid) {
            throw new BadRequest('Object URI must be "<UID>.ics" matching the VEVENT UID');
        }

        $userId = (int) $calendar['user_id'];
        $existing = $this->db->all(
            'SELECT * FROM events WHERE calendar_id = ? AND uid = ?',
            [$calendarId, $uid]
        );
        $existingMaster = null;
        $existingByInstance = [];
        foreach ($existing as $row) {
            if ($row['recurrence_instance_utc'] === null && $row['recurrence_parent_id'] === null) {
                $existingMaster = $row;
            } elseif ($row['recurrence_instance_utc'] !== null) {
                $existingByInstance[(string) $row['recurrence_instance_utc']] = $row;
            }
        }
        $beforeRows = array_values(array_filter($existing, static fn(array $r): bool => $r['deleted_at'] === null));
        $isNewObject = $existingMaster === null || $existingMaster['deleted_at'] !== null;

        $masterId = $this->db->tx(function () use ($masters, $parsedOverrides, $existingMaster, $existingByInstance, $calendarId, $userId, $uid): int {
            $now = Time::nowDb();
            $masterCols = DavIcs::eventColumns($masters[0]);
            if ($existingMaster !== null) {
                $masterId = (int) $existingMaster['id'];
                $this->db->update('events', $masterCols + ['deleted_at' => null, 'updated_at' => $now], 'id = ?', [$masterId]);
            } else {
                $masterId = $this->db->insert('events', $masterCols + [
                    'user_id' => $userId,
                    'calendar_id' => $calendarId,
                    'uid' => $uid,
                    'source' => 'local',
                    'created_via' => 'caldav',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $seenInstances = [];
            foreach ($parsedOverrides as $ov) {
                $cols = DavIcs::eventColumns($ov);
                $instance = (string) $cols['recurrence_instance_utc'];
                $seenInstances[$instance] = true;
                $cols['recurrence_parent_id'] = $masterId;
                $current = $existingByInstance[$instance] ?? null;
                if ($current !== null) {
                    $this->db->update('events', $cols + ['deleted_at' => null, 'updated_at' => $now], 'id = ?', [(int) $current['id']]);
                } else {
                    $this->db->insert('events', $cols + [
                        'user_id' => $userId,
                        'calendar_id' => $calendarId,
                        'uid' => $uid,
                        'source' => 'local',
                        'created_via' => 'caldav',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
            // Overrides dropped from the PUT body are gone for good.
            foreach ($existingByInstance as $instance => $row) {
                if (!isset($seenInstances[$instance])) {
                    $this->db->run('DELETE FROM events WHERE id = ?', [(int) $row['id']]);
                }
            }
            return $masterId;
        });

        $afterRows = $this->db->all(
            'SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND deleted_at IS NULL',
            [$calendarId, $uid]
        );
        $this->undo->record(
            $userId,
            'event',
            $masterId,
            $isNewObject ? 'create' : 'update',
            $beforeRows === [] ? null : ['events' => $beforeRows],
            ['events' => $afterRows]
        );
        ChangeLog::record($this->db, $calendarId, $uid, $isNewObject ? ChangeLog::OP_ADD : ChangeLog::OP_MODIFY);

        // We re-serialize on read, so the stored bytes differ from the PUT
        // body: per sabre's contract we must not return an etag here.
        return null;
    }

    // ---- Sync (RFC 6578) ----------------------------------------------

    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null)
    {
        $current = $this->db->scalar('SELECT synctoken FROM calendars WHERE id = ?', [(int) $calendarId]);
        if ($current === null) {
            return null;
        }
        $current = (int) $current;
        $result = ['syncToken' => $current, 'added' => [], 'modified' => [], 'deleted' => []];

        if ($syncToken !== null && $syncToken !== '' && (int) $syncToken > 0) {
            $since = (int) $syncToken;
            if ($since > $current) {
                return null; // bogus/foreign token: force a full resync
            }
            $sql = 'SELECT uri, operation FROM dav_changes
                    WHERE calendar_id = ? AND synctoken >= ? AND synctoken < ?
                    ORDER BY synctoken, id';
            if (is_int($limit) && $limit > 0) {
                $sql .= ' LIMIT ' . $limit;
            }
            $latest = [];
            foreach ($this->db->all($sql, [(int) $calendarId, $since, $current]) as $row) {
                $latest[(string) $row['uri']] = (int) $row['operation'];
            }
            foreach ($latest as $uri => $op) {
                match ($op) {
                    ChangeLog::OP_ADD => $result['added'][] = $uri,
                    ChangeLog::OP_MODIFY => $result['modified'][] = $uri,
                    ChangeLog::OP_DELETE => $result['deleted'][] = $uri,
                    default => null,
                };
            }
            return $result;
        }

        // Initial sync: everything is an add.
        $rows = $this->db->all(
            'SELECT uid FROM events WHERE calendar_id = ? AND deleted_at IS NULL
               AND recurrence_parent_id IS NULL AND recurrence_instance_utc IS NULL',
            [(int) $calendarId]
        );
        foreach ($rows as $row) {
            $result['added'][] = DavIcs::objectUri((string) $row['uid']);
        }
        return $result;
    }

    // ---- Helpers ------------------------------------------------------

    /** @param array<string,mixed> $row from getCalendarObjects query */
    private function objectMeta(array $row): array
    {
        $updated = max((string) $row['updated_at'], (string) ($row['override_updated_at'] ?? ''));
        return [
            'id' => (int) $row['id'],
            'uri' => DavIcs::objectUri((string) $row['uid']),
            'lastmodified' => Time::fromDb($updated)->getTimestamp(),
            'etag' => '"' . DavIcs::etag($updated, (int) $row['id']) . '"',
            'component' => 'vevent',
        ];
    }

    /** @return array<string,mixed> */
    private function calendarRow(mixed $calendarId): array
    {
        $row = $this->db->one('SELECT * FROM calendars WHERE id = ?', [(int) $calendarId]);
        if ($row === null) {
            throw new \Sabre\DAV\Exception\NotFound('Calendar not found');
        }
        return $row;
    }

    /** @param array<string,mixed> $calendar */
    private function assertWritable(array $calendar): void
    {
        if ($calendar['kind'] === 'subscribed') {
            throw new Forbidden('This calendar is a read-only feed subscription; changes must be made at the source');
        }
    }

    private static function emailFromPrincipal(string $principalUri): string
    {
        $pos = strrpos($principalUri, '/');
        return $pos === false ? $principalUri : substr($principalUri, $pos + 1);
    }
}
