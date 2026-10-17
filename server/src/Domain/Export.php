<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Infra\ExportWorkspace;

/**
 * Taking your calendars out of Better-Cal: each calendar as an .ics file,
 * or all of them in one zip, a file per calendar, the shape Google
 * Calendar's own export has. Built with the same calendar-file code as
 * outbound feeds and CalDAV (Ics::buildCalendar), so repeating events,
 * their exceptions, time zones, per-event reminders, formatted descriptions
 * and trips (RELATED-TO) carry over.
 *
 * Better-Cal imports the whole zip in one step (CalendarImport); Google,
 * Apple, Outlook, Fastmail, Proton and Thunderbird import the .ics files one
 * calendar at a time. Everything in a calendar is included, whatever its
 * date. People, labels, filters and settings aren't calendar data and aren't
 * in the files; a database backup keeps those.
 */
final class Export
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * One calendar as an .ics file. Throws not-found for a calendar that
     * isn't the user's.
     *
     * Metadata only: the body is streamed by writeCalendar().
     * @return array{id:int,name:string,fileName:string}
     */
    public function calendar(int $userId, int $calendarId): array
    {
        $cal = $this->db->one('SELECT id, name FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]);
        if ($cal === null) {
            throw \BetterCal\Http\HttpError::notFound('No such calendar');
        }
        $used = [];
        return [
            'id' => (int) $cal['id'],
            'name' => (string) $cal['name'],
            'fileName' => self::fileName((string) $cal['name'], $used),
        ];
    }

    /**
     * Every calendar of the user into a new zip at $path, one .ics each.
     *
     * @return array{calendars:int,events:int}
     */
    public function zip(int $userId, string $path, ExportWorkspace $workspace): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the export file');
        }
        $used = [];
        $total = 0;
        $count = 0;
        try {
            foreach ($this->calendars($userId) as $cal) {
                $spool = $workspace->file('ics');
                $fh = fopen($spool, 'wb');
                if ($fh === false) {
                    throw new \RuntimeException('Could not open the calendar export file.');
                }
                try {
                    $total += $this->writeCalendar($userId, $cal, static function (string $bytes) use ($fh): void {
                        if (fwrite($fh, $bytes) !== strlen($bytes)) {
                            throw new \RuntimeException('Writing the export failed. Check the available disk space.');
                        }
                    });
                } finally {
                    fclose($fh);
                }
                if (!$zip->addFile($spool, self::fileName((string) $cal['name'], $used))) {
                    throw new \RuntimeException('Could not add a calendar to the export.');
                }
                $count++;
            }
            // ZipArchive reads addFile sources here, not when they are added.
            if (!$zip->close()) {
                throw new \RuntimeException('Writing the export failed. Check the available disk space.');
            }
            $zip = null;
            if ($count === 0) {
                // libzip removes empty archives by default. A valid empty ZIP
                // still represents a complete export of an empty account.
                if (file_put_contents($path, "PK\x05\x06" . str_repeat("\0", 18)) !== 22) {
                    throw new \RuntimeException('Writing the empty export failed.');
                }
            }
        } finally {
            if ($zip !== null) {
                @$zip->close();
                @unlink($path);
            }
        }
        return ['calendars' => $count, 'events' => $total];
    }

    /**
     * A file name for a calendar, unique within one export: its name without
     * characters file systems refuse, "Name (2).ics" for a repeat. Pure.
     *
     * @param array<string,true> $used names taken so far, lowercased
     */
    public static function fileName(string $name, array &$used): string
    {
        $base = trim((string) preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/u', ' ', $name));
        $base = trim((string) preg_replace('/\s+/u', ' ', $base), ' .');
        if ($base === '') {
            $base = 'Calendar';
        }
        $base = mb_substr($base, 0, 100);
        $file = $base . '.ics';
        for ($n = 2; isset($used[mb_strtolower($file)]); $n++) {
            $file = $base . ' (' . $n . ').ics';
        }
        $used[mb_strtolower($file)] = true;
        return $file;
    }

    /** One database snapshot for all batches, without retaining all event rows. */
    public function snapshot(callable $fn): mixed
    {
        $pdo = $this->db->pdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        return $this->db->tx($fn);
    }

    public function writeCalendar(int $userId, array $cal, callable $write): int
    {
        $zones = array_column($this->db->all(
            'SELECT DISTINCT tzid FROM events WHERE user_id = ? AND calendar_id = ? AND deleted_at IS NULL AND all_day = 0',
            [$userId, (int) $cal['id']]
        ), 'tzid');
        return Ics::writeCalendar((string) $cal['name'], $zones, $this->events($userId, (int) $cal['id']), $write);
    }

    private function calendars(int $userId): \Generator
    {
        $position = null;
        $id = 0;
        do {
            $where = $position === null ? '' : ' AND (position > ? OR (position = ? AND id > ?))';
            $params = $position === null ? [$userId] : [$userId, $position, $position, $id];
            $batch = $this->db->all('SELECT id, name, position FROM calendars WHERE user_id = ?' . $where . ' ORDER BY position, id LIMIT 100', $params);
            foreach ($batch as $cal) {
                yield $cal;
                $position = (int) $cal['position'];
                $id = (int) $cal['id'];
            }
        } while (count($batch) === 100);
    }

    private function events(int $userId, int $calendarId): \Generator
    {
        $start = null;
        $id = 0;
        do {
            $where = $start === null ? '' : ' AND (start_utc > ? OR (start_utc = ? AND id > ?))';
            $params = $start === null ? [$userId, $calendarId] : [$userId, $calendarId, $start, $start, $id];
            $batch = $this->db->all('SELECT ' . OutFeeds::SOURCE_PROJECTION . ' FROM events WHERE user_id = ? AND calendar_id = ? AND deleted_at IS NULL'
                . $where . ' ORDER BY start_utc, id LIMIT 100', $params);
            $masters = [];
            $uids = array_column(array_filter($batch, static fn(array $e): bool => !empty($e['recurrence_instance_utc'])), 'uid');
            if ($uids !== []) {
                [$in, $args] = Db::in($uids);
                foreach ($this->db->all('SELECT uid, all_day, tzid, rrule FROM events WHERE user_id = ? AND calendar_id = ? AND deleted_at IS NULL'
                    . " AND recurrence_instance_utc IS NULL AND uid IN $in ORDER BY start_utc, id", [$userId, $calendarId, ...$args]) as $master) {
                    $masters[(string) $master['uid']] = $master;
                }
            }
            foreach ($batch as $event) {
                $event['_series'] = !empty($event['recurrence_instance_utc']) ? ($masters[(string) $event['uid']] ?? null) : null;
                $event['related_children'] = $this->relations((int) $event['id'], false);
                $event['related_parents'] = $this->relations((int) $event['id'], true);
                yield $event;
                $start = (string) $event['start_utc'];
                $id = (int) $event['id'];
            }
        } while (count($batch) === 100);
    }

    /** Even one container with many children must not grow an in-memory map. */
    private function relations(int $id, bool $parents): \Generator
    {
        $cursor = 0;
        $join = $parents ? 'l.container_id' : 'l.event_id';
        $side = $parents ? 'l.event_id' : 'l.container_id';
        do {
            $rows = $this->db->all("SELECT l.id, e.uid FROM event_links l JOIN events e ON e.id = $join
                WHERE $side = ? AND l.id > ? AND e.deleted_at IS NULL" . ($parents ? ' AND e.is_container = 1' : '') . ' ORDER BY l.id LIMIT 100', [$id, $cursor]);
            foreach ($rows as $row) {
                yield (string) $row['uid'];
                $cursor = (int) $row['id'];
            }
        } while (count($rows) === 100);
    }
}
