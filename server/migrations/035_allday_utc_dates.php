<?php

declare(strict_types=1);

// All-day events are stored one way from 0.9.16: each date as a UTC midnight,
// tzid UTC, which is how imports, Google and CalDAV already store them. Events
// made in the app before were stored as midnights in a zone; this converts
// them to the same dates. A series' skipped dates and the occurrence keys of
// its edited days convert with it (by their date in the old zone), an UNTIL
// written as a UTC time becomes the date it fell on there, and an edited day
// that is itself all-day converts too. Occurrences show on the same dates
// before and after. Changed events are journaled for CalDAV.

use BetterCal\Dav\ChangeLog;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

return static function (Db $db): string {
    $now = Time::nowDb();
    $asDate = static fn(string $utc, \DateTimeZone $tz): string => Time::fromDb($utc)->setTimezone($tz)->format('Y-m-d') . ' 00:00:00';
    $events = 0;
    $keys = 0;

    // Series and single events (not edited occurrences) stored the old way.
    $rows = $db->all("SELECT id, calendar_id, uid, start_utc, end_utc, tzid, rrule, exdates_json FROM events
                      WHERE all_day = 1 AND recurrence_parent_id IS NULL AND tzid <> 'UTC'");
    foreach ($rows as $row) {
        $tz = Time::zone((string) $row['tzid']);
        $set = [
            'start_utc' => $asDate((string) $row['start_utc'], $tz),
            'end_utc' => $asDate((string) $row['end_utc'], $tz),
            'tzid' => 'UTC',
            'updated_at' => $now,
        ];
        if ($set['end_utc'] <= $set['start_utc']) {
            $set['end_utc'] = Time::toDb(Time::fromDb($set['start_utc'])->modify('+1 day'));
        }
        $ex = json_decode((string) ($row['exdates_json'] ?? ''), true);
        if (is_array($ex) && $ex !== []) {
            $set['exdates_json'] = json_encode(array_map(static fn($e) => $asDate((string) $e, $tz), $ex));
            $keys += count($ex);
        }
        if (!empty($row['rrule']) && preg_match('/UNTIL=(\d{8}T\d{6}Z)/', (string) $row['rrule'], $m) === 1) {
            $until = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $m[1], Time::utc());
            if ($until !== false) {
                $set['rrule'] = str_replace('UNTIL=' . $m[1], 'UNTIL=' . $until->setTimezone($tz)->format('Ymd'), (string) $row['rrule']);
            }
        }
        $db->update('events', $set, 'id = ?', [(int) $row['id']]);
        ChangeLog::record($db, (int) $row['calendar_id'], (string) $row['uid'], ChangeLog::OP_MODIFY);
        $events++;

        // Its edited days: the key names an occurrence of the old series.
        foreach ($db->all('SELECT id, start_utc, end_utc, all_day, tzid, recurrence_instance_utc FROM events WHERE recurrence_parent_id = ?', [(int) $row['id']]) as $ov) {
            $ovSet = ['recurrence_instance_utc' => $asDate((string) $ov['recurrence_instance_utc'], $tz), 'updated_at' => $now];
            if ((int) $ov['all_day'] === 1 && Time::normalizeTzid((string) $ov['tzid']) !== 'UTC') {
                $ovTz = Time::zone((string) $ov['tzid']);
                $ovSet += ['start_utc' => $asDate((string) $ov['start_utc'], $ovTz), 'end_utc' => $asDate((string) $ov['end_utc'], $ovTz), 'tzid' => 'UTC'];
                if ($ovSet['end_utc'] <= $ovSet['start_utc']) {
                    $ovSet['end_utc'] = Time::toDb(Time::fromDb($ovSet['start_utc'])->modify('+1 day'));
                }
            }
            $db->update('events', $ovSet, 'id = ?', [(int) $ov['id']]);
            $keys++;
        }
    }

    // All-day edited days of series that are not all-day (or already UTC):
    // only their own dates convert; their keys name the series' occurrence.
    foreach ($db->all("SELECT id, start_utc, end_utc, tzid FROM events WHERE all_day = 1 AND recurrence_parent_id IS NOT NULL AND tzid <> 'UTC'") as $ov) {
        $tz = Time::zone((string) $ov['tzid']);
        $set = ['start_utc' => $asDate((string) $ov['start_utc'], $tz), 'end_utc' => $asDate((string) $ov['end_utc'], $tz), 'tzid' => 'UTC', 'updated_at' => $now];
        if ($set['end_utc'] <= $set['start_utc']) {
            $set['end_utc'] = Time::toDb(Time::fromDb($set['start_utc'])->modify('+1 day'));
        }
        $db->update('events', $set, 'id = ?', [(int) $ov['id']]);
        $events++;
    }

    return "$events all-day event" . ($events === 1 ? '' : 's') . " moved to UTC dates, $keys skipped or edited day" . ($keys === 1 ? '' : 's') . ' re-keyed';
};
