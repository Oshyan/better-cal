<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;

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
     * @return array{name:string,fileName:string,ics:string,events:int}
     */
    public function calendar(int $userId, int $calendarId): array
    {
        $cal = $this->db->one('SELECT id, name FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]);
        if ($cal === null) {
            throw \BetterCal\Http\HttpError::notFound('No such calendar');
        }
        $events = $this->events($userId, (int) $cal['id']);
        $used = [];
        return [
            'name' => (string) $cal['name'],
            'fileName' => self::fileName((string) $cal['name'], $used),
            'ics' => Ics::buildCalendar((string) $cal['name'], null, $events),
            'events' => count($events),
        ];
    }

    /**
     * Every calendar of the user into a new zip at $path, one .ics each.
     *
     * @return array{calendars:int,events:int}
     */
    public function zip(int $userId, string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Could not create the export file');
        }
        $used = [];
        $total = 0;
        $count = 0;
        try {
            foreach ($this->db->all('SELECT id, name FROM calendars WHERE user_id = ? ORDER BY position, id', [$userId]) as $cal) {
                $events = $this->events($userId, (int) $cal['id']);
                $zip->addFromString(self::fileName((string) $cal['name'], $used), Ics::buildCalendar((string) $cal['name'], null, $events));
                $total += count($events);
                $count++;
            }
        } finally {
            if (!$zip->close()) {
                @unlink($path);
                throw new \RuntimeException('Writing the export file failed');
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

    /** @return list<array<string,mixed>> the calendar's events, as Ics::buildCalendar takes them */
    private function events(int $userId, int $calendarId): array
    {
        $events = $this->db->all(
            'SELECT ' . OutFeeds::SOURCE_PROJECTION . ' FROM events WHERE user_id = ? AND calendar_id = ? AND deleted_at IS NULL ORDER BY start_utc, id',
            [$userId, $calendarId]
        );
        $related = Trips::relatedUidMap($this->db, array_map(static fn(array $e): int => (int) $e['id'], $events));
        foreach ($events as &$ev) {
            $id = (int) $ev['id'];
            if (!empty($related['children'][$id])) {
                $ev['related_children'] = $related['children'][$id];
            }
            if (!empty($related['parents'][$id])) {
                $ev['related_parents'] = $related['parents'][$id];
            }
        }
        unset($ev);
        return $events;
    }
}
