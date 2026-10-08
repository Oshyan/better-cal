<?php

declare(strict_types=1);

// Rows created before the shared event-input policy may contain active URL
// schemes, unsafe recurrence grammar or impossible coordinates. Clear only
// those unsafe fields; the event itself and its ordinary content remain.

use BetterCal\Dav\ChangeLog;
use BetterCal\Domain\Coordinates;
use BetterCal\Domain\Ics;
use BetterCal\Domain\Recurrence;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

return static function (Db $db): string {
    $changed = 0;
    $lastId = 0;
    $now = Time::nowDb();
    while (true) {
        $rows = $db->all(
            'SELECT id, calendar_id, uid, url, rrule, location_lat, location_lng FROM events WHERE id > ? ORDER BY id LIMIT 500',
            [$lastId]
        );
        if ($rows === []) {
            break;
        }
        foreach ($rows as $row) {
            $lastId = (int) $row['id'];
            $set = [];
            if ($row['url'] !== null && Ics::webUrl((string) $row['url']) === null) {
                $set['url'] = null;
            }
            if ($row['rrule'] !== null) {
                $safe = Recurrence::safeRrule((string) $row['rrule']);
                if ($safe !== (string) $row['rrule']) {
                    $set['rrule'] = $safe;
                }
            }
            foreach ([['location_lat', 'latitude'], ['location_lng', 'longitude']] as [$column, $method]) {
                if ($row[$column] === null) {
                    continue;
                }
                try {
                    Coordinates::$method($row[$column]);
                } catch (\InvalidArgumentException) {
                    $set[$column] = null;
                }
            }
            if ($set === []) {
                continue;
            }
            $set['updated_at'] = $now;
            $db->update('events', $set, 'id = ?', [$lastId]);
            ChangeLog::record($db, (int) $row['calendar_id'], (string) $row['uid'], ChangeLog::OP_MODIFY);
            $changed++;
        }
    }
    return "$changed event" . ($changed === 1 ? '' : 's') . ' normalized';
};
