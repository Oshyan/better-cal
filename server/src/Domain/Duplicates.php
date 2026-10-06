<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/**
 * The same real event arriving by two routes (#9): a Luma feed and the
 * confirmation forwarded from Gmail, a Takeout import and the Google
 * calendar it came from, a booking read from mail and the venue's feed.
 *
 * Nothing is merged or deleted. A scan pairs the copies in
 * event_duplicates: `linked` when sure (the same UID on two calendars, or
 * the same title at the same moment on two calendars), `possible` when only
 * close (Review asks), `dismissed` once the owner says they differ. The
 * client shows a linked group as one event, the copy from the most live
 * source, and the event's details say where else it is. Reminders fire from
 * one copy only.
 */
final class Duplicates
{
    /** How far apart two timed starts may be and still be the same event. */
    private const NEAR_SECONDS = 1800;
    /** How far back the title scan looks; ahead it looks a year. */
    private const SCAN_BACK = 'P14D';
    private const SCAN_AHEAD = 'P400D';

    private const PREFIXES = '/^(?:(?:updated\s+)?invitation(?:\s+from\s+[^:]{1,80})?:|(?:your\s+)?reservation\s+(?:at|for|with)|booking\s+(?:at|for|confirmed:?|confirmation:?)|(?:order\s+)?confirmed:|registration\s+confirmed:?|you\'?re\s+(?:registered\s+for|going\s+to|in:?)|tickets?\s+(?:for|to):?|rsvp:|reminder:)\s*/u';
    private const STOPWORDS = ['the', 'a', 'an', 'at', 'with', 'and', 'of', 'for', 'to', 'in', 'on'];
    /** Words that name a kind of event, not one event: alone they prove nothing. */
    private const GENERIC = ['lunch', 'dinner', 'breakfast', 'brunch', 'coffee', 'drinks', 'meeting', 'call', 'party', 'sync', 'standup', 'class', 'practice', 'appointment', 'workout', 'event', 'session', 'reservation', 'booking', 'meetup', 'hangout', 'date', 'birthday'];

    public function __construct(private readonly Db $db)
    {
    }

    // ---- Matching (pure, unit-tested) --------------------------------------------

    /** A title reduced to what names the event: no booking prefixes, no trailing (note), no punctuation. */
    public static function normTitle(string $title): string
    {
        $t = mb_strtolower(trim($title));
        $t = (string) preg_replace('/\s*\([^()]*\)\s*$/u', '', $t);
        for ($i = 0; $i < 3; $i++) {
            $next = (string) preg_replace(self::PREFIXES, '', $t);
            if ($next === $t) {
                break;
            }
            $t = $next;
        }
        $t = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /** @return list<string> */
    private static function words(string $norm): array
    {
        return array_values(array_unique(array_filter(
            explode(' ', $norm),
            static fn(string $w): bool => $w !== '' && !in_array($w, self::STOPWORDS, true)
        )));
    }

    /**
     * Titles close enough to be one event: nearly all the words of the
     * shorter appear in the longer ("Kinkally" and "Dinner at Kinkally").
     * One short common word alone ("Lunch") is not enough.
     */
    public static function similarTitles(string $a, string $b): bool
    {
        $wa = self::words(self::normTitle($a));
        $wb = self::words(self::normTitle($b));
        if ($wa === [] || $wb === []) {
            return false;
        }
        $short = count($wa) <= count($wb) ? $wa : $wb;
        $shared = count(array_intersect($wa, $wb));
        if ($shared / count($short) < 0.8) {
            return false;
        }
        if (count($short) >= 2) {
            return true;
        }
        // One word: a name ("Kinkally"), not a kind of event ("Lunch").
        return mb_strlen($short[0]) >= 5 && !in_array($short[0], self::GENERIC, true);
    }

    /**
     * When two events are: 'same' (the same start, or the same date for two
     * all-day ones), 'near' (timed within half an hour, or an all-day one on
     * the timed one's date), else null.
     *
     * @param array{start_utc:string,all_day:int|string,tzid:string} $a
     * @param array{start_utc:string,all_day:int|string,tzid:string} $b
     */
    public static function when(array $a, array $b): ?string
    {
        $aDay = (int) $a['all_day'] === 1;
        $bDay = (int) $b['all_day'] === 1;
        // An event's date in its own zone: an all-day day made in the app is
        // local midnight (07:00 UTC in Los Angeles), its Google copy a UTC
        // midnight; compared as raw instants they never paired (audit, 0.9.14).
        $date = static fn(array $e): string => Time::fromDb((string) $e['start_utc'])
            ->setTimezone(Time::zone(Time::normalizeTzid((string) $e['tzid'])))->format('Y-m-d');
        if ($aDay && $bDay) {
            return $date($a) === $date($b) ? 'same' : null;
        }
        if ($aDay !== $bDay) {
            [$day, $timed] = $aDay ? [$a, $b] : [$b, $a];
            return $date($timed) === $date($day) ? 'near' : null;
        }
        $gap = abs(Time::fromDb((string) $a['start_utc'])->getTimestamp() - Time::fromDb((string) $b['start_utc'])->getTimestamp());
        if ($gap === 0) {
            return 'same';
        }
        return $gap <= self::NEAR_SECONDS ? 'near' : null;
    }

    /**
     * Two events with different UIDs: linked, possible, or not a pair.
     * Linking without asking needs the same title at the same moment on two
     * calendars; one calendar holding the same thing twice, or anything
     * looser, is only possible.
     *
     * @return array{status:string,basis:string}|null
     */
    public static function classify(array $a, array $b): ?array
    {
        $when = self::when($a, $b);
        if ($when === null) {
            return null;
        }
        $sameTitle = self::normTitle((string) $a['title']) !== '' && self::normTitle((string) $a['title']) === self::normTitle((string) $b['title']);
        // "Lunch" at noon on two calendars may be two people's lunches: ask.
        $generic = count($w = self::words(self::normTitle((string) $a['title']))) === 1 && in_array($w[0], self::GENERIC, true);
        if ($sameTitle && !$generic && $when === 'same' && (int) $a['calendar_id'] !== (int) $b['calendar_id']) {
            return ['status' => 'linked', 'basis' => 'title'];
        }
        if ($sameTitle || self::similarTitles((string) $a['title'], (string) $b['title'])) {
            return ['status' => 'possible', 'basis' => 'similar'];
        }
        return null;
    }

    /**
     * Which copy of a linked group to show, and to remind from: the most
     * live source first. Google (edited in place, always current), then
     * your own calendars, then feeds, then bookings read from mail.
     */
    public static function rank(array $row, array $calendar): int
    {
        if (($calendar['provider'] ?? 'ics') === 'google') {
            return 4;
        }
        $invite = $row['invite_json'] ?? null;
        $invite = is_string($invite) ? json_decode($invite, true) : $invite;
        if (is_array($invite) && Rsvp::kind($invite) === 'booking') {
            return 1;
        }
        return ($calendar['kind'] ?? 'local') === 'local' ? 3 : 2;
    }

    // ---- Scanning ------------------------------------------------------------------

    /**
     * Find new pairs for one user and record them. Pairs already recorded,
     * in any status, stay as they are: a dismissal is never re-proposed.
     *
     * @return array{linked:int,possible:int}
     */
    public function scan(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $found = $this->find($userId, $now);
        $counts = ['linked' => 0, 'possible' => 0];
        if ($found === []) {
            return $counts;
        }
        $this->db->tx(function () use ($found, $userId, &$counts): void {
            foreach ($found as $p) {
                $this->db->insert('event_duplicates', [
                    'user_id' => $userId,
                    'event_a' => $p['event_a'],
                    'event_b' => $p['event_b'],
                    'status' => $p['status'],
                    'basis' => $p['basis'],
                ]);
                $counts[$p['status']]++;
            }
        });
        $parts = [];
        if ($counts['linked'] > 0) {
            $parts[] = $counts['linked'] . ' shown as one';
        }
        if ($counts['possible'] > 0) {
            $parts[] = $counts['possible'] . ' to check in Review';
        }
        ActivityContext::with('dedup', fn() => (new Undo($this->db))->record(
            $userId,
            'event',
            $found[0]['event_a'],
            'update',
            null,
            null,
            'Found the same event twice: ' . implode(', ', $parts),
            ['titles' => array_slice(array_values(array_unique(array_column($found, 'title'))), 0, 20)]
        ));
        return $counts;
    }

    /**
     * The new pairs a scan would record, without recording them.
     *
     * @return list<array{event_a:int,event_b:int,status:string,basis:string,title:string}>
     */
    public function find(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= Time::nowUtc();
        $known = [];
        foreach ($this->db->all('SELECT event_a, event_b FROM event_duplicates WHERE user_id = ?', [$userId]) as $p) {
            $known[(int) $p['event_a'] . ':' . (int) $p['event_b']] = true;
        }
        $found = [];
        $add = static function (array $a, array $b, string $status, string $basis) use (&$found, &$known): void {
            [$x, $y] = (int) $a['id'] < (int) $b['id'] ? [(int) $a['id'], (int) $b['id']] : [(int) $b['id'], (int) $a['id']];
            if (isset($known["$x:$y"])) {
                return;
            }
            $known["$x:$y"] = true;
            $found[] = ['event_a' => $x, 'event_b' => $y, 'status' => $status, 'basis' => $basis, 'title' => (string) $a['title']];
        };

        // Calendars that hold things people do: not weather, tides or sun.
        $eligible = "c.kind <> 'plugin' AND c.role <> 'context'";
        $base = "e.user_id = ? AND e.deleted_at IS NULL AND e.recurrence_instance_utc IS NULL AND e.status <> 'cancelled' AND e.is_container = 0 AND $eligible";

        // The same UID on two calendars: one event, copied by two routes.
        $byUid = [];
        foreach ($this->db->all(
            "SELECT e.id, e.uid, e.title FROM events e JOIN calendars c ON c.id = e.calendar_id
             WHERE $base AND e.uid IN (
               SELECT d.uid FROM events d WHERE d.user_id = ? AND d.deleted_at IS NULL AND d.recurrence_instance_utc IS NULL
               GROUP BY d.uid HAVING COUNT(*) > 1)",
            [$userId, $userId]
        ) as $row) {
            $byUid[(string) $row['uid']][] = $row;
        }
        foreach ($byUid as $rows) {
            for ($i = 0; $i < count($rows); $i++) {
                for ($j = $i + 1; $j < count($rows); $j++) {
                    $add($rows[$i], $rows[$j], 'linked', 'uid');
                }
            }
        }

        // Different UIDs, one event: single events near in time with titles
        // that agree. Series are matched by UID only.
        $rows = $this->db->all(
            "SELECT e.id, e.calendar_id, e.title, e.start_utc, e.all_day, e.tzid FROM events e JOIN calendars c ON c.id = e.calendar_id
             WHERE $base AND e.rrule IS NULL AND e.start_utc >= ? AND e.start_utc < ?
             ORDER BY e.start_utc",
            [$userId, Time::toDb($now->sub(new \DateInterval(self::SCAN_BACK))), Time::toDb($now->add(new \DateInterval(self::SCAN_AHEAD)))]
        );
        $n = count($rows);
        $starts = array_map(static fn(array $r): int => Time::fromDb((string) $r['start_utc'])->getTimestamp(), $rows);
        for ($i = 0; $i < $n; $i++) {
            // An all-day event and a timed one on its date can be a day apart in UTC.
            for ($j = $i + 1; $j < $n && $starts[$j] - $starts[$i] <= 86400 + 14 * 3600; $j++) {
                $verdict = self::classify($rows[$i], $rows[$j]);
                if ($verdict !== null) {
                    $add($rows[$i], $rows[$j], $verdict['status'], $verdict['basis']);
                }
            }
        }

        // Don't ask about two copies already shown as one through a third.
        $parent = [];
        $root = function (int $x) use (&$parent, &$root): int {
            return !isset($parent[$x]) || $parent[$x] === $x ? $x : ($parent[$x] = $root($parent[$x]));
        };
        $linkedPairs = $this->db->all("SELECT event_a, event_b FROM event_duplicates WHERE user_id = ? AND status = 'linked'", [$userId]);
        foreach ($found as $p) {
            if ($p['status'] === 'linked') {
                $linkedPairs[] = $p;
            }
        }
        foreach ($linkedPairs as $p) {
            $parent[$root((int) $p['event_a'])] = $root((int) $p['event_b']);
        }
        return array_values(array_filter(
            $found,
            static fn(array $p): bool => $p['status'] !== 'possible' || $root($p['event_a']) !== $root($p['event_b'])
        ));
    }

    /** Every user, for the worker. @return array{linked:int,possible:int} */
    public function scanAll(): array
    {
        $total = ['linked' => 0, 'possible' => 0];
        foreach ($this->db->all('SELECT id FROM users') as $u) {
            $c = $this->scan((int) $u['id']);
            $total['linked'] += $c['linked'];
            $total['possible'] += $c['possible'];
        }
        return $total;
    }

    // ---- Reading -------------------------------------------------------------------

    /**
     * Linked copies of each event, for serialize: eventId => [{pairId,
     * eventId, calendarId}]. Deleted copies drop out.
     *
     * @param list<int> $eventIds
     * @return array<int, list<array{pairId:int,eventId:int,calendarId:int}>>
     */
    public function linkedFor(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        [$in, $params] = Db::in($eventIds);
        $out = [];
        foreach ($this->db->all(
            "SELECT p.id, p.event_a, p.event_b, a.calendar_id AS cal_a, b.calendar_id AS cal_b
             FROM event_duplicates p
             JOIN events a ON a.id = p.event_a AND a.deleted_at IS NULL
             JOIN events b ON b.id = p.event_b AND b.deleted_at IS NULL
             WHERE p.status = 'linked' AND (p.event_a IN $in OR p.event_b IN $in)",
            [...$params, ...$params]
        ) as $p) {
            $out[(int) $p['event_a']][] = ['pairId' => (int) $p['id'], 'eventId' => (int) $p['event_b'], 'calendarId' => (int) $p['cal_b']];
            $out[(int) $p['event_b']][] = ['pairId' => (int) $p['id'], 'eventId' => (int) $p['event_a'], 'calendarId' => (int) $p['cal_a']];
        }
        return $out;
    }

    /**
     * Masters whose reminders stay quiet because a linked copy reminds
     * instead: in each linked group, only the best-ranked copy that has
     * reminders at all speaks.
     *
     * @param array<int,array> $rowsById masters in the reminder window
     * @param callable(array):bool $hasReminders
     * @param array<int,array> $calendars id => calendar row (provider, kind)
     * @return array<int,true>
     */
    public function silenced(array $rowsById, callable $hasReminders, array $calendars): array
    {
        $linked = $this->linkedFor(array_keys($rowsById));
        if ($linked === []) {
            return [];
        }
        // Groups among the rows at hand.
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            return !isset($parent[$x]) || $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        foreach ($linked as $id => $copies) {
            foreach ($copies as $c) {
                if (isset($rowsById[$c['eventId']])) {
                    $parent[$find($id)] = $find($c['eventId']);
                }
            }
        }
        $groups = [];
        foreach (array_keys($linked) as $id) {
            if (isset($rowsById[$id])) {
                $groups[$find($id)][] = $id;
            }
        }
        $quiet = [];
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }
            $speaker = null;
            $best = -1;
            foreach ($members as $id) {
                $row = $rowsById[$id];
                if (!$hasReminders($row)) {
                    continue;
                }
                $r = self::rank($row, $calendars[(int) $row['calendar_id']] ?? []);
                if ($r > $best || ($r === $best && $id < $speaker)) {
                    [$best, $speaker] = [$r, $id];
                }
            }
            foreach ($members as $id) {
                if ($id !== $speaker) {
                    $quiet[$id] = true;
                }
            }
        }
        return $quiet;
    }

    /** Possible pairs still waiting, both events alive, soonest first. */
    public function possible(int $userId): array
    {
        return $this->db->all(
            "SELECT p.id, p.basis, p.created_at,
                    a.id AS a_id, a.title AS a_title, a.start_utc AS a_start, a.all_day AS a_all_day, a.tzid AS a_tzid, ca.name AS a_cal,
                    b.id AS b_id, b.title AS b_title, b.start_utc AS b_start, b.all_day AS b_all_day, b.tzid AS b_tzid, cb.name AS b_cal
             FROM event_duplicates p
             JOIN events a ON a.id = p.event_a AND a.deleted_at IS NULL
             JOIN events b ON b.id = p.event_b AND b.deleted_at IS NULL
             JOIN calendars ca ON ca.id = a.calendar_id
             JOIN calendars cb ON cb.id = b.calendar_id
             WHERE p.user_id = ? AND p.status = 'possible'
             ORDER BY a.start_utc",
            [$userId]
        );
    }

    // ---- Deciding ------------------------------------------------------------------

    /** The owner's word on a pair: the same event (linked) or not (dismissed). */
    public function decide(int $userId, int $pairId, string $status): void
    {
        if (!in_array($status, ['linked', 'dismissed'], true)) {
            throw HttpError::badRequest('status must be linked or dismissed');
        }
        $pair = $this->db->one(
            'SELECT p.*, a.title FROM event_duplicates p JOIN events a ON a.id = p.event_a WHERE p.id = ? AND p.user_id = ?',
            [$pairId, $userId]
        );
        if ($pair === null) {
            throw HttpError::notFound('No such pair');
        }
        $this->db->update('event_duplicates', ['status' => $status, 'basis' => 'owner', 'decided_at' => Time::nowDb()], 'id = ?', [$pairId]);
        ActivityContext::with('dedup', fn() => (new Undo($this->db))->record(
            $userId,
            'event',
            (int) $pair['event_a'],
            'update',
            null,
            null,
            ($status === 'linked' ? 'Shown as one event: ' : 'Not the same event: ') . "'" . (string) $pair['title'] . "'"
        ));
    }

    /** A copy made on purpose (Copy to) is never a duplicate of its source. */
    public static function markDistinct(Db $db, int $userId, int $a, int $b): void
    {
        [$x, $y] = $a < $b ? [$a, $b] : [$b, $a];
        if ($db->scalar('SELECT id FROM event_duplicates WHERE event_a = ? AND event_b = ?', [$x, $y]) !== null) {
            $db->update('event_duplicates', ['status' => 'dismissed', 'basis' => 'owner'], 'event_a = ? AND event_b = ?', [$x, $y]);
            return;
        }
        $db->insert('event_duplicates', ['user_id' => $userId, 'event_a' => $x, 'event_b' => $y, 'status' => 'dismissed', 'basis' => 'owner']);
    }
}
