<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Limits;
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

    public function __construct(
        private readonly Db $db,
        private readonly ?\Closure $clock = null,
    ) {
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
     * shorter appear in the longer ("Bellwether" and "Dinner at Bellwether").
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
        // One word: a name ("Bellwether"), not a kind of event ("Lunch").
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
        if ($sameTitle && !$generic && $when === 'same'
            && (int) $a['calendar_id'] !== (int) $b['calendar_id']
            && self::trustedLocal($a) && self::trustedLocal($b)
        ) {
            return ['status' => 'linked', 'basis' => 'title'];
        }
        if ($sameTitle || self::similarTitles((string) $a['title'], (string) $b['title'])) {
            return ['status' => 'possible', 'basis' => 'similar'];
        }
        return null;
    }

    /** Only owner-controlled local lineage may auto-link on title and time. */
    private static function trustedLocal(array $row): bool
    {
        $via = (string) ($row['created_via'] ?? '');
        return (string) ($row['source'] ?? '') === 'local'
            && (string) ($row['calendar_kind'] ?? '') === 'local'
            && (string) ($row['calendar_provider'] ?? '') === 'ics'
            && !str_starts_with($via, 'mail:')
            && !str_starts_with($via, 'plugin:');
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
     * Find one bounded batch of new pairs for one user and record them. Pairs
     * already recorded, in any status, stay as they are: a dismissal is never
     * re-proposed. The worker uses scanAllSlice() for durable continuation.
     *
     * @return array{linked:int,possible:int}
     */
    public function scan(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $this->pruneStaleAutomatic($userId);
        return $this->storePairs($userId, $this->find($userId, $now));
    }

    /**
     * The bounded batch of new pairs scan() would record, without recording.
     * Large sets deliberately require worker continuation rather than an
     * exhaustive in-memory preview.
     *
     * @return list<array{event_a:int,event_b:int,status:string,basis:string,title:string}>
     */
    public function find(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= Time::nowUtc();
        $cursor = ['phase' => 'uid', 'afterId' => 0];
        $found = [];
        // A small calendar completes both phases as before. A large one gets
        // at most one bounded UID page and one bounded time page here; the
        // worker uses scanAllSlice() to persist and resume every page.
        for ($i = 0; $i < 2; $i++) {
            $slice = $this->discoverUserSlice($userId, $cursor, $now);
            $found = $this->mergePairs($found, $slice['pairs']);
            if (!$slice['more'] || ($slice['cursor']['phase'] ?? '') !== 'time') {
                break;
            }
            $cursor = $slice['cursor'];
        }
        return $found;
    }

    /** Every user, one bounded slice. Kept for callers that do not need continuation metadata. */
    public function scanAll(): array
    {
        $slice = $this->scanAllSlice();
        return ['linked' => $slice['linked'], 'possible' => $slice['possible']];
    }

    /**
     * One resumable worker slice across all users.
     *
     * The cursor is deliberately carried in the durable job payload rather
     * than a new table. If a worker dies after storing pairs but before it
     * enqueues/finishes, the event-pair unique key makes retry idempotent.
     *
     * @param array{userId?:int,phase?:string,afterId?:int,activityRecorded?:bool} $cursor
     * @return array{linked:int,possible:int,more:bool,cursor:?array,throttled:bool,comparisons:int,rows:int}
     */
    public function scanAllSlice(array $cursor = [], ?\DateTimeImmutable $now = null): array
    {
        $now ??= Time::nowUtc();
        $userId = max(0, (int) ($cursor['userId'] ?? 0));
        if ($userId < 1) {
            $userId = (int) ($this->db->scalar('SELECT id FROM users ORDER BY id LIMIT 1') ?? 0);
        }
        if ($userId < 1) {
            return ['linked' => 0, 'possible' => 0, 'more' => false, 'cursor' => null, 'throttled' => false, 'comparisons' => 0, 'rows' => 0];
        }
        if (($cursor['phase'] ?? 'uid') === 'uid' && max(0, (int) ($cursor['afterId'] ?? 0)) === 0) {
            $this->pruneStaleAutomatic($userId);
        }
        $slice = $this->discoverUserSlice($userId, $cursor, $now);
        $activityRecorded = !empty($cursor['activityRecorded']);
        $counts = $this->storePairs($userId, $slice['pairs'], !$activityRecorded);
        if ($counts['linked'] + $counts['possible'] > 0) {
            $activityRecorded = true;
        }
        if ($slice['more']) {
            return $counts + [
                'more' => true,
                'cursor' => ['userId' => $userId, 'activityRecorded' => $activityRecorded] + $slice['cursor'],
                'throttled' => $slice['throttled'],
                'comparisons' => $slice['comparisons'],
                'rows' => $slice['rows'],
            ];
        }
        $nextUser = (int) ($this->db->scalar('SELECT id FROM users WHERE id > ? ORDER BY id LIMIT 1', [$userId]) ?? 0);
        return $counts + [
            'more' => $nextUser > 0,
            'cursor' => $nextUser > 0 ? ['userId' => $nextUser, 'phase' => 'uid', 'afterId' => 0] : null,
            'throttled' => $slice['throttled'],
            'comparisons' => $slice['comparisons'],
            'rows' => $slice['rows'],
        ];
    }

    /** @return array{pairs:list<array>,more:bool,cursor:array,throttled:bool,comparisons:int,rows:int} */
    private function discoverUserSlice(int $userId, array $cursor, \DateTimeImmutable $now): array
    {
        $phase = ($cursor['phase'] ?? 'uid') === 'time' ? 'time' : 'uid';
        return $phase === 'uid'
            ? $this->discoverUidSlice($userId, max(0, (int) ($cursor['afterId'] ?? 0)))
            : $this->discoverTimeSlice($userId, max(0, (int) ($cursor['afterId'] ?? 0)), $now);
    }

    /** Same-UID groups need only a spanning star, not every pair in a clique. */
    private function discoverUidSlice(int $userId, int $afterId): array
    {
        $maxRows = Limits::get('DUPLICATE_SCAN_ROWS');
        $maxPairs = Limits::get('DUPLICATE_PAIRS');
        $maxLinked = Limits::get('DUPLICATE_LINKED_EDGES');
        $linkedTotal = (int) ($this->db->scalar(
            "SELECT COUNT(*) FROM event_duplicates WHERE user_id = ? AND status = 'linked'",
            [$userId]
        ) ?? 0);
        $deadline = $this->nowSeconds() + Limits::get('DUPLICATE_SCAN_SECONDS');
        $base = $this->eligibleBase();
        $rows = $this->db->all(
            "SELECT e.id, e.uid, e.title FROM events e JOIN calendars c ON c.id = e.calendar_id
             WHERE $base AND e.id > ? ORDER BY e.id LIMIT " . ($maxRows + 1),
            [$userId, $afterId]
        );
        $hasAnotherPage = count($rows) > $maxRows;
        if ($hasAnotherPage) {
            array_pop($rows);
        }

        $representatives = [];
        $uids = array_values(array_unique(array_map(static fn(array $r): string => (string) $r['uid'], $rows)));
        foreach (array_chunk($uids, 400) as $uidChunk) {
            [$in, $params] = Db::in($uidChunk);
            foreach ($this->db->all(
                "SELECT e.uid, MIN(e.id) AS rep_id FROM events e JOIN calendars c ON c.id = e.calendar_id
                 WHERE $base AND e.uid IN $in GROUP BY e.uid",
                [$userId, ...$params]
            ) as $rep) {
                $representatives[(string) $rep['uid']] = (int) $rep['rep_id'];
            }
        }
        $repTitles = [];
        if ($representatives !== []) {
            [$in, $params] = Db::in(array_values(array_unique($representatives)));
            foreach ($this->db->all("SELECT id, title FROM events WHERE id IN $in", $params) as $rep) {
                $repTitles[(int) $rep['id']] = (string) $rep['title'];
            }
        }

        $known = [];
        $pairs = [];
        $lastCompleted = $afterId;
        $throttled = false;
        foreach ($rows as $row) {
            if ($this->nowSeconds() >= $deadline || count($pairs) >= $maxPairs) {
                $throttled = true;
                break;
            }
            $repId = $representatives[(string) $row['uid']] ?? (int) $row['id'];
            if ($repId !== (int) $row['id']) {
                $rep = ['id' => $repId, 'title' => $repTitles[$repId] ?? (string) $row['title']];
                $existing = $this->pairStatus($rep, $row, $userId, $known);
                if ($existing === null && $linkedTotal >= $maxLinked) {
                    $throttled = true;
                } elseif ($this->addPair($rep, $row, 'linked', 'uid', $userId, $known, $pairs) === 'added') {
                    $linkedTotal++;
                }
            }
            $lastCompleted = (int) $row['id'];
        }
        $stopped = $lastCompleted < (int) ($rows[count($rows) - 1]['id'] ?? $lastCompleted);
        if ($hasAnotherPage || $stopped) {
            return ['pairs' => $pairs, 'more' => true, 'cursor' => ['phase' => 'uid', 'afterId' => $lastCompleted], 'throttled' => $throttled, 'comparisons' => 0, 'rows' => count($rows)];
        }
        return ['pairs' => $pairs, 'more' => true, 'cursor' => ['phase' => 'time', 'afterId' => 0], 'throttled' => $throttled, 'comparisons' => 0, 'rows' => count($rows)];
    }

    /** Different-UID title candidates: bounded anchors, neighbours, work and output. */
    private function discoverTimeSlice(int $userId, int $afterId, \DateTimeImmutable $now): array
    {
        $maxRows = Limits::get('DUPLICATE_SCAN_ROWS');
        $maxComparisons = Limits::get('DUPLICATE_COMPARISONS');
        // Independently tuned limits must still make progress: an operator
        // may set the aggregate comparison ceiling below the per-event value.
        $maxCandidates = min(Limits::get('DUPLICATE_CANDIDATES_PER_EVENT'), $maxComparisons);
        $maxLinked = Limits::get('DUPLICATE_LINKED_EDGES');
        $maxOpenSuggestions = Limits::get('DUPLICATE_OPEN_SUGGESTIONS');
        $maxOpenSuggestionsPerEvent = Limits::get('DUPLICATE_OPEN_SUGGESTIONS_PER_EVENT');
        $maxPairs = Limits::get('DUPLICATE_PAIRS');
        $deadline = $this->nowSeconds() + Limits::get('DUPLICATE_SCAN_SECONDS');
        $base = $this->eligibleBase();
        $from = Time::toDb($now->sub(new \DateInterval(self::SCAN_BACK)));
        $to = Time::toDb($now->add(new \DateInterval(self::SCAN_AHEAD)));
        $rows = $this->db->all(
            "SELECT e.id, e.calendar_id, e.uid, e.title, e.start_utc, e.all_day, e.tzid,
                    e.source, e.created_via, c.kind AS calendar_kind,
                    COALESCE(c.provider, 'ics') AS calendar_provider
             FROM events e JOIN calendars c ON c.id = e.calendar_id
             WHERE $base AND e.rrule IS NULL AND e.start_utc >= ? AND e.start_utc < ? AND e.id > ?
             ORDER BY e.id LIMIT " . ($maxRows + 1),
            [$userId, $from, $to, $afterId]
        );
        $hasAnotherPage = count($rows) > $maxRows;
        if ($hasAnotherPage) {
            array_pop($rows);
        }

        $known = [];
        $openPossibleCounts = [];
        $linkedTotal = (int) ($this->db->scalar(
            "SELECT COUNT(*) FROM event_duplicates WHERE user_id = ? AND status = 'linked'",
            [$userId]
        ) ?? 0);
        $openPossibleTotal = (int) ($this->db->scalar(
            "SELECT COUNT(*) FROM event_duplicates p
             JOIN events a ON a.id = p.event_a AND a.deleted_at IS NULL
             JOIN events b ON b.id = p.event_b AND b.deleted_at IS NULL
             WHERE p.user_id = ? AND p.status = 'possible'",
            [$userId]
        ) ?? 0);
        $pairs = [];
        $comparisons = 0;
        $lastCompleted = $afterId;
        $throttled = false;
        foreach ($rows as $row) {
            // Each candidate list is independently bounded, so deadline and
            // aggregate limits are checked between anchors rather than at an
            // attacker-controlled inner-loop cardinality.
            if ($this->nowSeconds() >= $deadline
                || $comparisons + $maxCandidates > $maxComparisons
                || count($pairs) >= $maxPairs
            ) {
                $throttled = true;
                break;
            }
            $start = Time::fromDb((string) $row['start_utc']);
            $candidates = $this->db->all(
                "SELECT e.id, e.calendar_id, e.uid, e.title, e.start_utc, e.all_day, e.tzid,
                        e.source, e.created_via, c.kind AS calendar_kind,
                        COALESCE(c.provider, 'ics') AS calendar_provider
                 FROM events e JOIN calendars c ON c.id = e.calendar_id
                 WHERE $base AND e.rrule IS NULL AND e.id <> ? AND e.uid <> ?
                   AND e.start_utc >= ? AND e.start_utc <= ?
                 ORDER BY e.start_utc, e.id LIMIT " . ($maxCandidates + 1),
                [
                    $userId,
                    (int) $row['id'],
                    (string) $row['uid'],
                    Time::toDb($start->sub(new \DateInterval('PT38H'))),
                    Time::toDb($start->add(new \DateInterval('PT38H'))),
                ]
            );
            if (count($candidates) > $maxCandidates) {
                array_pop($candidates);
                $throttled = true;
            }
            $completedAnchor = true;
            $linkedForAnchor = false;
            foreach ($candidates as $candidate) {
                if ($this->nowSeconds() >= $deadline) {
                    $completedAnchor = false;
                    $throttled = true;
                    break;
                }
                $comparisons++;
                $verdict = self::classify($row, $candidate);
                if ($verdict !== null) {
                    if ($verdict['status'] === 'linked') {
                        if (!$linkedForAnchor) {
                            $existing = $this->pairStatus($row, $candidate, $userId, $known);
                            if ($existing === null && $linkedTotal >= $maxLinked) {
                                $throttled = true;
                            } else {
                                $pairState = $this->addPair($row, $candidate, 'linked', $verdict['basis'], $userId, $known, $pairs);
                                if ($pairState === 'added') {
                                    $linkedTotal++;
                                }
                                $linkedForAnchor = $pairState === 'added' || $pairState === 'linked';
                            }
                        }
                    } else {
                        $hasCapacity = $openPossibleTotal < $maxOpenSuggestions
                            && $this->openPossibleCount($userId, (int) $row['id'], $openPossibleCounts) < $maxOpenSuggestionsPerEvent
                            && $this->openPossibleCount($userId, (int) $candidate['id'], $openPossibleCounts) < $maxOpenSuggestionsPerEvent;
                        if ($hasCapacity && $this->addPair($row, $candidate, 'possible', $verdict['basis'], $userId, $known, $pairs) === 'added') {
                            $openPossibleTotal++;
                            $openPossibleCounts[(int) $row['id']]++;
                            $openPossibleCounts[(int) $candidate['id']]++;
                        } elseif (!$hasCapacity && $this->pairStatus($row, $candidate, $userId, $known) === null) {
                            $throttled = true;
                        }
                    }
                }
                if (count($pairs) >= $maxPairs) {
                    $completedAnchor = false;
                    $throttled = true;
                    break;
                }
            }
            if (!$completedAnchor) {
                break;
            }
            $lastCompleted = (int) $row['id'];
        }
        $pairs = $this->withoutAlreadyLinkedPossibles($userId, $pairs, $throttled);
        $stopped = $lastCompleted < (int) ($rows[count($rows) - 1]['id'] ?? $lastCompleted);
        return [
            'pairs' => $pairs,
            'more' => $hasAnotherPage || $stopped,
            'cursor' => ['phase' => 'time', 'afterId' => $lastCompleted],
            'throttled' => $throttled,
            'comparisons' => $comparisons,
            'rows' => count($rows),
        ];
    }

    private function eligibleBase(): string
    {
        return "e.user_id = ? AND e.deleted_at IS NULL AND e.recurrence_instance_utc IS NULL
            AND e.status <> 'cancelled' AND e.is_container = 0 AND c.kind <> 'plugin' AND c.role <> 'context'";
    }

    private function pruneStaleAutomatic(int $userId): void
    {
        // Soft-deleted events are absent from every duplicate surface. Their
        // undecided/automatic edges carry no state worth preserving and must
        // not accumulate outside the live caps. Owner dismissals remain so a
        // restored event is not proposed again against the owner's decision.
        $this->db->run(
            "DELETE FROM event_duplicates
             WHERE user_id = ? AND status IN ('linked', 'possible')
               AND (EXISTS (SELECT 1 FROM events e WHERE e.id = event_a AND e.deleted_at IS NOT NULL)
                 OR EXISTS (SELECT 1 FROM events e WHERE e.id = event_b AND e.deleted_at IS NOT NULL))",
            [$userId]
        );
    }

    /**
     * @param array<string,string|null> $known
     * @param list<array> $pairs
     * @return string added, or the existing pair status
     */
    private function addPair(array $a, array $b, string $status, string $basis, int $userId, array &$known, array &$pairs): string
    {
        [$x, $y] = (int) $a['id'] < (int) $b['id'] ? [(int) $a['id'], (int) $b['id']] : [(int) $b['id'], (int) $a['id']];
        $existing = $this->pairStatus($a, $b, $userId, $known);
        if ($existing !== null) {
            return $existing;
        }
        $key = "$x:$y";
        $known[$key] = $status;
        $pairs[] = ['event_a' => $x, 'event_b' => $y, 'status' => $status, 'basis' => $basis, 'title' => (string) $a['title']];
        return 'added';
    }

    /** @param array<string,string|null> $known */
    private function pairStatus(array $a, array $b, int $userId, array &$known): ?string
    {
        [$x, $y] = (int) $a['id'] < (int) $b['id'] ? [(int) $a['id'], (int) $b['id']] : [(int) $b['id'], (int) $a['id']];
        $key = "$x:$y";
        if (!array_key_exists($key, $known)) {
            $existing = $this->db->scalar(
                'SELECT status FROM event_duplicates WHERE user_id = ? AND event_a = ? AND event_b = ? LIMIT 1',
                [$userId, $x, $y]
            );
            $known[$key] = is_string($existing) ? $existing : null;
        }
        return $known[$key];
    }

    /** @param array<int,int> $cache */
    private function openPossibleCount(int $userId, int $eventId, array &$cache): int
    {
        if (!array_key_exists($eventId, $cache)) {
            $cache[$eventId] = (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM event_duplicates p
                 JOIN events a ON a.id = p.event_a AND a.deleted_at IS NULL
                 JOIN events b ON b.id = p.event_b AND b.deleted_at IS NULL
                 WHERE p.user_id = ? AND p.status = 'possible' AND (p.event_a = ? OR p.event_b = ?)",
                [$userId, $eventId, $eventId]
            ) ?? 0);
        }
        return $cache[$eventId];
    }

    /** Do not ask about two copies already joined through a third copy. */
    private function withoutAlreadyLinkedPossibles(int $userId, array $pairs, bool &$throttled): array
    {
        $ids = [];
        foreach ($pairs as $pair) {
            $ids[(int) $pair['event_a']] = true;
            $ids[(int) $pair['event_b']] = true;
        }
        $edges = [];
        $edgeLimit = Limits::get('DUPLICATE_PAIRS') * 2;
        foreach (array_chunk(array_keys($ids), 400) as $chunk) {
            if (count($edges) >= $edgeLimit) {
                $throttled = true;
                break;
            }
            [$in, $params] = Db::in($chunk);
            $left = $edgeLimit - count($edges) + 1;
            foreach ($this->db->all(
                "SELECT event_a, event_b FROM event_duplicates
                 WHERE user_id = ? AND status = 'linked' AND (event_a IN $in OR event_b IN $in)
                 ORDER BY id LIMIT $left",
                [$userId, ...$params, ...$params]
            ) as $edge) {
                $edges[(int) $edge['event_a'] . ':' . (int) $edge['event_b']] = $edge;
            }
            if (count($edges) > $edgeLimit) {
                $edges = array_slice($edges, 0, $edgeLimit, true);
                $throttled = true;
                break;
            }
        }
        foreach ($pairs as $pair) {
            if ($pair['status'] === 'linked') {
                $edges[(int) $pair['event_a'] . ':' . (int) $pair['event_b']] = $pair;
            }
        }
        $parent = [];
        $root = function (int $x) use (&$parent, &$root): int {
            return !isset($parent[$x]) || $parent[$x] === $x ? $x : ($parent[$x] = $root($parent[$x]));
        };
        foreach ($edges as $edge) {
            $parent[$root((int) $edge['event_a'])] = $root((int) $edge['event_b']);
        }
        return array_values(array_filter(
            $pairs,
            static fn(array $pair): bool => $pair['status'] !== 'possible'
                || $root((int) $pair['event_a']) !== $root((int) $pair['event_b'])
        ));
    }

    /** @param list<array> $left @param list<array> $right @return list<array> */
    private function mergePairs(array $left, array $right): array
    {
        $out = [];
        foreach ([...$left, ...$right] as $pair) {
            $key = (int) $pair['event_a'] . ':' . (int) $pair['event_b'];
            $out[$key] ??= $pair;
        }
        return array_values($out);
    }

    /** @param list<array> $found @return array{linked:int,possible:int} */
    private function storePairs(int $userId, array $found, bool $recordActivity = true): array
    {
        $counts = ['linked' => 0, 'possible' => 0];
        $stored = [];
        if ($found === []) {
            return $counts;
        }
        $this->db->tx(function () use ($found, $userId, &$counts, &$stored): void {
            foreach ($found as $pair) {
                if ($this->db->scalar(
                    'SELECT id FROM event_duplicates WHERE user_id = ? AND event_a = ? AND event_b = ? LIMIT 1',
                    [$userId, $pair['event_a'], $pair['event_b']]
                ) !== null) {
                    continue;
                }
                $this->db->insert('event_duplicates', [
                    'user_id' => $userId,
                    'event_a' => $pair['event_a'],
                    'event_b' => $pair['event_b'],
                    'status' => $pair['status'],
                    'basis' => $pair['basis'],
                ]);
                $counts[$pair['status']]++;
                $stored[] = $pair;
            }
        });
        if ($stored === [] || !$recordActivity) {
            return $counts;
        }
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
            $stored[0]['event_a'],
            'update',
            null,
            null,
            'Found the same event twice: ' . implode(', ', $parts),
            ['titles' => array_slice(array_values(array_unique(array_column($stored, 'title'))), 0, 20)]
        ));
        return $counts;
    }

    private function nowSeconds(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    // ---- Reading -------------------------------------------------------------------

    /**
     * Linked copies of each event, for serialize. groupId identifies the
     * whole connected component even when its sparse hub is outside the
     * current window. Traversal and output are bounded so a legacy dense
     * graph cannot turn a window or reminder read into an all-pairs load.
     * Deleted copies drop out.
     *
     * @param list<int> $eventIds
     * @return array<int, list<array{pairId:int,eventId:int,calendarId:int,groupId:int}>>
     */
    public function linkedFor(array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds), static fn(int $id): bool => $id > 0)));
        if ($eventIds === []) {
            return [];
        }
        $requested = array_fill_keys($eventIds, true);
        $frontier = $requested;
        $expanded = [];
        $edges = [];
        $maxEdges = Limits::get('DUPLICATE_LINKED_EDGES');
        $maxQueries = Limits::get('DUPLICATE_LINK_TRAVERSAL_QUERIES');
        $queries = 0;
        while ($frontier !== [] && count($edges) < $maxEdges) {
            $wave = array_values(array_diff(array_keys($frontier), array_keys($expanded)));
            $frontier = [];
            if ($wave === []) {
                break;
            }
            foreach ($wave as $id) {
                $expanded[$id] = true;
            }
            foreach (array_chunk($wave, 400) as $chunk) {
                if (count($edges) >= $maxEdges || $queries >= $maxQueries) {
                    break 2;
                }
                $queries++;
                [$in, $params] = Db::in($chunk);
                foreach ($this->db->all(
                    "SELECT p.id, p.event_a, p.event_b, a.calendar_id AS cal_a, b.calendar_id AS cal_b
                     FROM event_duplicates p
                     JOIN events a ON a.id = p.event_a AND a.deleted_at IS NULL
                     JOIN events b ON b.id = p.event_b AND b.deleted_at IS NULL
                     WHERE p.status = 'linked' AND (p.event_a IN $in OR p.event_b IN $in)
                     ORDER BY p.id LIMIT " . ($maxEdges + 1),
                    [...$params, ...$params]
                ) as $edge) {
                    $edgeId = (int) $edge['id'];
                    if (isset($edges[$edgeId])) {
                        continue;
                    }
                    if (count($edges) >= $maxEdges) {
                        break 3;
                    }
                    $edges[$edgeId] = $edge;
                    foreach ([(int) $edge['event_a'], (int) $edge['event_b']] as $memberId) {
                        if (!isset($expanded[$memberId])) {
                            $frontier[$memberId] = true;
                        }
                    }
                }
            }
        }

        $parent = [];
        $find = function (int $id) use (&$parent, &$find): int {
            $parent[$id] ??= $id;
            return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
        };
        foreach ($edges as $edge) {
            $a = $find((int) $edge['event_a']);
            $b = $find((int) $edge['event_b']);
            if ($a !== $b) {
                $parent[$a] = $b;
            }
        }
        $groupIds = [];
        foreach (array_keys($parent) as $id) {
            $root = $find($id);
            $groupIds[$root] = min($groupIds[$root] ?? $id, $id);
        }

        $out = [];
        foreach ($edges as $edge) {
            $a = (int) $edge['event_a'];
            $b = (int) $edge['event_b'];
            $groupId = $groupIds[$find($a)] ?? min($a, $b);
            if (isset($requested[$a])) {
                $out[$a][] = ['pairId' => (int) $edge['id'], 'eventId' => $b, 'calendarId' => (int) $edge['cal_b'], 'groupId' => $groupId];
            }
            if (isset($requested[$b])) {
                $out[$b][] = ['pairId' => (int) $edge['id'], 'eventId' => $a, 'calendarId' => (int) $edge['cal_a'], 'groupId' => $groupId];
            }
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
        $groups = [];
        foreach ($rowsById as $id => $_row) {
            if (isset($linked[$id][0]['groupId'])) {
                $groups[(int) $linked[$id][0]['groupId']][] = (int) $id;
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
             ORDER BY a.start_utc
             LIMIT " . Limits::get('DUPLICATE_OPEN_SUGGESTIONS'),
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
