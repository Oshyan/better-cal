<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
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
 *
 * What a stored link is for decides which are stored (#317): its readers are
 * the views and search (linkedFor; any date a person can go to, and the
 * app collapses a group among the calendars shown), reminders (silenced;
 * hidden calendars remind too) and the app badge. So every pair is stored,
 * whatever its date and whether a calendar is hidden: a hidden calendar
 * shown again collapses at once, with no wait for a scan. Nothing caps the
 * rows over the account's life; they are bounded by how they are made: a
 * same-UID group is a star (one row per extra copy, and a calendar holds a
 * UID once), a title link needs the owner's own calendars on both sides and
 * is made at most once per event a pass. The limits bound one slice's work
 * (rows, comparisons, pairs, seconds; the next slice resumes by cursor), one
 * read (linkedFor), and the Review queue.
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
    public static function when(array $a, array $b, ?string $homeTzid = null): ?string
    {
        $aDay = (int) $a['all_day'] === 1;
        $bDay = (int) $b['all_day'] === 1;
        // The day each is on (Time::dayOf): an all-day day its date, compared
        // as dates rather than instants (audit, 0.9.14); a timed event stored
        // in UTC on the owner's clock (an evening event is that day's, not
        // the next).
        $date = static fn(array $e): string => Time::dayOf(Time::fromDb((string) $e['start_utc']), (int) $e['all_day'] === 1, Time::displayZone((string) $e['tzid'], $homeTzid));
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
    public static function classify(array $a, array $b, ?string $homeTzid = null): ?array
    {
        $when = self::when($a, $b, $homeTzid);
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
     * `throttled` says the slice stopped at a bound on one slice's work (its
     * rows, comparisons, pairs or seconds), which the next slice continues
     * from the cursor; `skipped` is what this pass will not come back to
     * (see mergeSkipped).
     *
     * @param array{userId?:int,phase?:string,afterId?:int,activityRecorded?:bool} $cursor
     * @return array{linked:int,possible:int,more:bool,cursor:?array,throttled:bool,skipped:array,comparisons:int,rows:int}
     */
    public function scanAllSlice(array $cursor = [], ?\DateTimeImmutable $now = null): array
    {
        $now ??= Time::nowUtc();
        $userId = max(0, (int) ($cursor['userId'] ?? 0));
        if ($userId < 1) {
            $userId = (int) ($this->db->scalar('SELECT id FROM users ORDER BY id LIMIT 1') ?? 0);
        }
        if ($userId < 1) {
            return ['linked' => 0, 'possible' => 0, 'more' => false, 'cursor' => null, 'throttled' => false, 'skipped' => [], 'comparisons' => 0, 'rows' => 0];
        }
        if (($cursor['phase'] ?? 'uid') === 'uid' && max(0, (int) ($cursor['afterId'] ?? 0)) === 0) {
            $this->pruneStaleAutomatic($userId);
        }
        $slice = $this->discoverUserSlice($userId, $cursor, $now);
        $activityRecorded = !empty($cursor['activityRecorded']);
        $counts = $this->storePairs($userId, $slice['pairs'], !$activityRecorded);
        if ($counts['linked'] > 0) {
            ChangeLog::touchAccount($this->db, $userId); // linked copies now show as one
        }
        if ($counts['linked'] + $counts['possible'] > 0) {
            $activityRecorded = true;
        }
        if ($slice['more']) {
            return $counts + [
                'more' => true,
                'cursor' => ['userId' => $userId, 'activityRecorded' => $activityRecorded] + $slice['cursor'],
                'throttled' => $slice['throttled'],
                'skipped' => $slice['skipped'],
                'comparisons' => $slice['comparisons'],
                'rows' => $slice['rows'],
            ];
        }
        $nextUser = (int) ($this->db->scalar('SELECT id FROM users WHERE id > ? ORDER BY id LIMIT 1', [$userId]) ?? 0);
        return $counts + [
            'more' => $nextUser > 0,
            'cursor' => $nextUser > 0 ? ['userId' => $nextUser, 'phase' => 'uid', 'afterId' => 0] : null,
            'throttled' => $slice['throttled'],
            'skipped' => $slice['skipped'],
            'comparisons' => $slice['comparisons'],
            'rows' => $slice['rows'],
        ];
    }

    /** The health line a pass over every account leaves (Settings, System; Activity). */
    public const HEALTH_SUBJECT = 'job:duplicate_scan_budget';
    public const HEALTH_LABEL = 'Duplicate check';

    /**
     * One worker job: one slice, and once a pass over every account ends,
     * its health line. A pass that left nothing out clears the line; one
     * that did says what, in words, and the same words twice are recorded
     * once (SystemHealth::recordCondition). Returns the slice and the next
     * job's payload, null when the pass is done.
     *
     * @param array{cursor?:array,skipped?:array} $payload
     * @return array{slice:array,next:?array,skipped:array,words:?string}
     */
    public function workerSlice(array $payload, SystemHealth $health, ?\DateTimeImmutable $now = null): array
    {
        $cursor = is_array($payload['cursor'] ?? null) ? $payload['cursor'] : [];
        $slice = $this->scanAllSlice($cursor, $now);
        $skipped = self::mergeSkipped(is_array($payload['skipped'] ?? null) ? $payload['skipped'] : [], $slice['skipped']);
        if ($slice['more'] && is_array($slice['cursor'])) {
            return ['slice' => $slice, 'next' => ['cursor' => $slice['cursor'], 'skipped' => $skipped], 'skipped' => $skipped, 'words' => null];
        }
        $calendarIds = [];
        foreach ($skipped as $kind) {
            $calendarIds += $kind['calendars'];
        }
        $names = [];
        if ($calendarIds !== []) {
            [$in, $params] = Db::in(array_keys($calendarIds));
            foreach ($this->db->all("SELECT id, name FROM calendars WHERE id IN $in", $params) as $cal) {
                $names[(int) $cal['id']] = (string) $cal['name'];
            }
        }
        $words = self::shortfallWords($skipped, $names);
        if ($words !== null) {
            $health->recordCondition(self::HEALTH_SUBJECT, 'job', null, self::HEALTH_LABEL, $words);
        } else {
            $health->clearCondition(self::HEALTH_SUBJECT, self::HEALTH_LABEL, 'every event was checked again, and nothing was left out.');
        }
        return ['slice' => $slice, 'next' => null, 'skipped' => $skipped, 'words' => $words];
    }

    /**
     * What a pass left out, summed across its slices. Two kinds, each of
     * events counted once (an event is one slice's anchor): `crowded`, an
     * event with more neighbours within a day and a half than one event is
     * compared with (DUPLICATE_CANDIDATES_PER_EVENT); `waiting`, an event
     * with a possible duplicate not added to Review because Review holds as
     * many as it takes (`reviewFull`) or the event already has its share.
     * Each: {events, calendars:{calendarId: events}}. Pure; sanitises what a
     * job payload carried.
     */
    public static function mergeSkipped(array $a, array $b): array
    {
        $out = [];
        foreach ([$a, $b] as $side) {
            foreach (['crowded', 'waiting'] as $kind) {
                $part = $side[$kind] ?? null;
                if (!is_array($part) || (int) ($part['events'] ?? 0) < 1) {
                    continue;
                }
                $out[$kind] ??= ['events' => 0, 'calendars' => [], 'reviewFull' => false];
                $out[$kind]['events'] += (int) $part['events'];
                foreach (is_array($part['calendars'] ?? null) ? $part['calendars'] : [] as $id => $n) {
                    if ((int) $id > 0) {
                        $out[$kind]['calendars'][(int) $id] = ($out[$kind]['calendars'][(int) $id] ?? 0) + max(1, (int) $n);
                    }
                }
                $out[$kind]['reviewFull'] = $out[$kind]['reviewFull'] || !empty($part['reviewFull']);
            }
        }
        return $out;
    }

    /**
     * The health line for what a pass left out, or null when it left out
     * nothing: which calendars, about how many events, what a person sees,
     * what they can do. Counts are rounded ("about 2,400") so a condition
     * that holds unchanged reads the same each pass. Pure.
     *
     * @param array<int,string> $calendarNames id => name
     */
    public static function shortfallWords(array $skipped, array $calendarNames): ?string
    {
        $about = static function (int $n): string {
            if ($n < 20) {
                return (string) $n;
            }
            $step = 10 ** max(0, strlen((string) $n) - 2);
            return 'about ' . number_format((int) (round($n / $step) * $step));
        };
        $calendars = static function (array $counts) use ($calendarNames): string {
            arsort($counts);
            $names = [];
            foreach (array_keys($counts) as $id) {
                $names[] = $calendarNames[$id] ?? 'a removed calendar';
            }
            $names = array_values(array_unique($names));
            $shown = array_slice($names, 0, 3);
            $more = count($names) - count($shown);
            if ($more > 0) {
                return implode(', ', $shown) . " and $more more calendar" . ($more === 1 ? '' : 's');
            }
            return count($shown) > 1 ? implode(', ', array_slice($shown, 0, -1)) . ' and ' . $shown[count($shown) - 1] : ($shown[0] ?? '');
        };
        $parts = [];
        if (isset($skipped['crowded'])) {
            $n = (int) $skipped['crowded']['events'];
            $max = Limits::get('DUPLICATE_CANDIDATES_PER_EVENT');
            $parts[] = ucfirst($about($n)) . ' event' . ($n === 1 ? '' : 's') . ' on busy days in ' . $calendars($skipped['crowded']['calendars'])
                . " were compared with only $max of the events within a day and a half, so a copy on another calendar may show twice."
                . " Setting BETTERCAL_LIMIT_DUPLICATE_CANDIDATES_PER_EVENT above $max checks them all.";
        }
        if (isset($skipped['waiting'])) {
            $n = (int) $skipped['waiting']['events'];
            $why = !empty($skipped['waiting']['reviewFull'])
                ? 'Review already holds ' . Limits::get('DUPLICATE_OPEN_SUGGESTIONS') . ' waiting for your answer'
                : 'each of ' . ($n === 1 ? 'them' : 'those events') . ' already has ' . Limits::get('DUPLICATE_OPEN_SUGGESTIONS_PER_EVENT') . ' waiting in Review';
            $parts[] = 'Possible duplicates of ' . $about($n) . ' event' . ($n === 1 ? '' : 's') . ' in ' . $calendars($skipped['waiting']['calendars'])
                . " are not in Review yet, because $why. Answering those makes room, and the next check adds the rest.";
        }
        return $parts === [] ? null : implode(' ', $parts);
    }

    /** @return array{pairs:list<array>,more:bool,cursor:array,throttled:bool,skipped:array,comparisons:int,rows:int} */
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
                $this->addPair($rep, $row, 'linked', 'uid', $userId, $known, $pairs);
            }
            $lastCompleted = (int) $row['id'];
        }
        $stopped = $lastCompleted < (int) ($rows[count($rows) - 1]['id'] ?? $lastCompleted);
        if ($hasAnotherPage || $stopped) {
            return ['pairs' => $pairs, 'more' => true, 'cursor' => ['phase' => 'uid', 'afterId' => $lastCompleted], 'throttled' => $throttled, 'skipped' => [], 'comparisons' => 0, 'rows' => count($rows)];
        }
        return ['pairs' => $pairs, 'more' => true, 'cursor' => ['phase' => 'time', 'afterId' => 0], 'throttled' => $throttled, 'skipped' => [], 'comparisons' => 0, 'rows' => count($rows)];
    }

    /** Different-UID title candidates: bounded anchors, neighbours, work and output. */
    private function discoverTimeSlice(int $userId, int $afterId, \DateTimeImmutable $now): array
    {
        $homeTzid = Settings::homeTzid($this->db, $userId);
        $maxRows = Limits::get('DUPLICATE_SCAN_ROWS');
        $maxComparisons = Limits::get('DUPLICATE_COMPARISONS');
        // Independently tuned limits must still make progress: an operator
        // may set the aggregate comparison ceiling below the per-event value.
        $maxCandidates = min(Limits::get('DUPLICATE_CANDIDATES_PER_EVENT'), $maxComparisons);
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
        $skipped = [];
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
            // More neighbours than one event is compared with: the rest are
            // never compared with it this pass, which the pass reports.
            $crowded = count($candidates) > $maxCandidates;
            if ($crowded) {
                array_pop($candidates);
                $throttled = true;
            }
            $completedAnchor = true;
            $linkedForAnchor = false;
            $waiting = false;
            foreach ($candidates as $candidate) {
                if ($this->nowSeconds() >= $deadline) {
                    $completedAnchor = false;
                    $throttled = true;
                    break;
                }
                $comparisons++;
                $verdict = self::classify($row, $candidate, $homeTzid);
                if ($verdict !== null) {
                    if ($verdict['status'] === 'linked') {
                        if (!$linkedForAnchor) {
                            $pairState = $this->addPair($row, $candidate, 'linked', $verdict['basis'], $userId, $known, $pairs);
                            $linkedForAnchor = $pairState === 'added' || $pairState === 'linked';
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
                            $waiting = $openPossibleTotal >= $maxOpenSuggestions ? 'full' : ($waiting ?: 'event');
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
            // Counted once per event: an unfinished anchor is redone by the
            // next slice and counted there.
            if ($crowded) {
                $skipped = self::mergeSkipped($skipped, ['crowded' => ['events' => 1, 'calendars' => [(int) $row['calendar_id'] => 1]]]);
            }
            if ($waiting !== false) {
                $skipped = self::mergeSkipped($skipped, ['waiting' => ['events' => 1, 'calendars' => [(int) $row['calendar_id'] => 1], 'reviewFull' => $waiting === 'full']]);
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
            'skipped' => $skipped,
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
        $titles = array_values(array_unique(array_column($stored, 'title')));
        ActivityContext::with('dedup', fn() => (new Undo($this->db))->record(
            $userId,
            'event',
            $stored[0]['event_a'],
            'update',
            null,
            null,
            self::foundSummary($counts['linked'], $counts['possible'], $titles),
            ['titles' => array_slice($titles, 0, 20)]
        ));
        return $counts;
    }

    /**
     * The Activity line for one run's new pairs. Each pair is one extra copy
     * of an event, so the count is of copies: "Found the same event twice: 8
     * shown as one" read as eight events collapsed into a single one. Names
     * the events, like the geocoder's line names the addresses. Pure.
     *
     * @param list<string> $titles
     */
    public static function foundSummary(int $linked, int $possible, array $titles): string
    {
        $parts = [];
        if ($linked > 0) {
            $parts[] = $linked === 1
                ? 'Hid 1 duplicate copy, so the event shows once'
                : "Hid $linked duplicate copies, so each event shows once";
        }
        if ($possible > 0) {
            $parts[] = ($linked > 0 ? '' : 'Found ') . $possible . ' possible duplicate' . ($possible === 1 ? '' : 's') . ' to check in Review';
        }
        $titles = array_values(array_filter($titles, static fn($t) => trim((string) $t) !== ''));
        $shown = array_map(static fn(string $t): string => mb_substr($t, 0, 60), array_slice($titles, 0, 3));
        $more = count($titles) - count($shown);
        return implode('; ', $parts)
            . ($shown !== [] ? ': ' . implode('; ', $shown) . ($more > 0 ? "; and $more more" : '') : '');
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
        $maxEdges = Limits::get('DUPLICATE_LINK_READ_EDGES');
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
    public function decide(int $userId, int $pairId, string $status, ?RequestAuthority $authority = null): void
    {
        if (!in_array($status, ['linked', 'dismissed'], true)) {
            throw HttpError::badRequest('status must be linked or dismissed');
        }
        (new Undo($this->db))->write($userId, function () use ($userId, $pairId, $status, $authority): void {
            $authority?->assertValid($this->db);
            $pair = $this->db->one(
                'SELECT p.*, a.title FROM event_duplicates p JOIN events a ON a.id = p.event_a WHERE p.id = ? AND p.user_id = ?',
                [$pairId, $userId]
            );
            if ($pair === null) {
                throw HttpError::notFound('No such pair');
            }
            $this->db->update('event_duplicates', ['status' => $status, 'basis' => 'owner', 'decided_at' => Time::nowDb()], 'id = ?', [$pairId]);
            // The person's own decision (dedup:owner), told apart from the
            // automatic scan's for undoing their last change (Undo::undoLatest).
            ActivityContext::with('dedup:owner', fn() => (new Undo($this->db))->record(
                $userId,
                'event',
                (int) $pair['event_a'],
                'update',
                null,
                null,
                ($status === 'linked' ? 'Shown as one event: ' : 'Not the same event: ') . "'" . (string) $pair['title'] . "'"
            ));
        });
    }

    /** A copy made on purpose (Copy to) is never a duplicate of its source. */
    public static function markDistinct(Db $db, int $userId, int $a, int $b): void
    {
        [$x, $y] = $a < $b ? [$a, $b] : [$b, $a];
        // Through the write boundary (the request's credential checked), with
        // no journal entry: the pairs are the duplicate check's own, outside undo.
        (new Undo($db))->write($userId, static function () use ($db, $userId, $x, $y): void {
            if ($db->scalar('SELECT id FROM event_duplicates WHERE event_a = ? AND event_b = ?', [$x, $y]) !== null) {
                $db->update('event_duplicates', ['status' => 'dismissed', 'basis' => 'owner'], 'event_a = ? AND event_b = ?', [$x, $y]);
                return;
            }
            $db->insert('event_duplicates', ['user_id' => $userId, 'event_a' => $x, 'event_b' => $y, 'status' => 'dismissed', 'basis' => 'owner']);
        });
    }
}
