<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Support\Time;

final class Search
{
    private const EXCERPT_CHARS = 200;
    /** Below this length FULLTEXT (min token length 3, stopwords) is unreliable. */
    private const MIN_FULLTEXT_LEN = 3;

    public function __construct(
        private readonly Db $db,
        private readonly Labels $labels,
    ) {
    }

    /**
     * FULLTEXT natural-language search with LIKE fallback for short or
     * stopword-only queries. Ranked by relevance, then newest first.
     * Tag and people names match too: events tagged with a name containing
     * the query, or linked to a person whose name contains it, are included.
     * A `#` prefix (e.g. "#work") searches tags only; an `@` prefix (e.g.
     * "@virginia") searches people only. $excerpt trims descriptions for
     * list payloads; feed export passes false.
     *
     * $opts (0.7.3, #60): 'when' => 'all' | 'upcoming' | 'past', applied in
     * the query, before the limit, so a term with a long history can't push
     * upcoming matches out; 'calendarId' narrows to one calendar; 'now'.
     * Upcoming means not yet ended, and a repeating series counts as upcoming
     * until its UNTIL date (one with no end, or ending by COUNT, always does).
     * Each row carries '_upcoming' for the caller to group by.
     *
     * @return list<array> event rows
     */
    public function search(int $userId, string $q, int $limit = 50, bool $excerpt = true, array $opts = []): array
    {
        $plan = $this->plan($userId, $q, $opts);
        if ($plan === null) {
            return [];
        }
        $limit = max(1, $limit); // feeds ask for 500; the search route caps at 200
        [$where, $params, $order, $select, $selectParams] = $plan;
        $rows = $this->db->all(
            "SELECT *$select FROM events WHERE $where ORDER BY $order LIMIT $limit",
            [...$selectParams, ...$params]
        );
        if ($rows === [] && $plan['fallback'] !== null) {
            [$where, $params] = $plan['fallback'];
            $rows = $this->db->all("SELECT * FROM events WHERE $where ORDER BY start_utc DESC LIMIT $limit", $params);
        }
        $now = $this->nowSql($opts);
        $dayNow = $this->dayNowSql($userId, $now);
        $out = [];
        foreach ($rows as $row) {
            unset($row['relevance']);
            $row['_upcoming'] = self::isUpcoming($row, $now, $dayNow);
            if (($opts['when'] ?? 'all') === 'upcoming' && !$row['_upcoming']) {
                continue; // a series whose UNTIL has passed
            }
            if (($opts['when'] ?? 'all') === 'past' && $row['_upcoming']) {
                // A series still running matched Past by its first date: show
                // its latest occurrence that has ended (0.7.4), not its next.
                $prev = self::previousOccurrence($row, $now);
                if ($prev === null) {
                    continue;
                }
                [$row['start_utc'], $row['end_utc']] = $prev;
                $row['_upcoming'] = false;
            } elseif ($row['_upcoming'] && !empty($row['rrule']) && (string) $row['end_utc'] < $now) {
                // A series that began in the past: show (and open) its next date, not its first.
                $next = self::nextOccurrence($row, $now);
                if ($next === null) {
                    if (($opts['when'] ?? 'all') === 'upcoming') {
                        continue;
                    }
                    $row['_upcoming'] = false;
                } else {
                    [$row['start_utc'], $row['end_utc']] = $next;
                }
            }
            if ($excerpt && $row['description'] !== null && mb_strlen((string) $row['description']) > self::EXCERPT_CHARS) {
                $row['description'] = mb_substr((string) $row['description'], 0, self::EXCERPT_CHARS) . '…';
            }
            $out[] = $row;
        }
        return $out;
    }

    /** How many matches lie in the past, for "N past matches" under an Upcoming search. */
    public function countPast(int $userId, string $q, array $opts = []): int
    {
        $plan = $this->plan($userId, $q, ['when' => 'past'] + $opts);
        if ($plan === null) {
            return 0;
        }
        [$where, $params] = $plan;
        $n = (int) $this->db->scalar("SELECT COUNT(*) FROM events WHERE $where", $params);
        if ($n === 0 && $plan['fallback'] !== null) {
            [$where, $params] = $plan['fallback'];
            $n = (int) $this->db->scalar("SELECT COUNT(*) FROM events WHERE $where", $params);
        }
        return $n;
    }

    /**
     * Now as an all-day date compares (0.9.16): all-day events are stored as
     * UTC dates, so "is today's all-day event over" asks what date and time it
     * is on the owner's Home clock, written as if it were UTC. Measured in UTC
     * instead, today's all-day event ended at 5 PM in Los Angeles.
     */
    private function dayNowSql(int $userId, string $nowSql): string
    {
        $home = Settings::homeTzid($this->db, $userId);
        return $home === null ? $nowSql : Time::fromDb($nowSql)->setTimezone(Time::zone($home))->format('Y-m-d H:i:s');
    }

    /**
     * Not yet ended, or a series still running ('YYYY-MM-DD HH:MM:SS' UTC now).
     * $dayNowSql: now on the Home clock, for all-day events (see dayNowSql). Pure.
     */
    public static function isUpcoming(array $row, string $nowSql, ?string $dayNowSql = null): bool
    {
        if ((int) ($row['all_day'] ?? 0) === 1 && $dayNowSql !== null && Time::normalizeTzid((string) ($row['tzid'] ?? 'UTC')) === 'UTC') {
            $nowSql = $dayNowSql;
            if ((string) ($row['end_utc'] ?? '') > $nowSql) {
                return true;
            }
        } elseif ((string) ($row['end_utc'] ?? '') >= $nowSql) {
            return true;
        }
        $rrule = (string) ($row['rrule'] ?? '');
        if ($rrule === '') {
            return false;
        }
        if (preg_match('/UNTIL=(\d{8})(T(\d{6}))?/i', $rrule, $m) === 1) {
            $date = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
            // A date-only UNTIL runs to the end of that day in the event's own
            // zone, not in UTC (audit, 0.9.14).
            $until = isset($m[3]) && $m[3] !== ''
                ? $date . ' ' . substr($m[3], 0, 2) . ':' . substr($m[3], 2, 2) . ':' . substr($m[3], 4, 2)
                : Time::toDb((new \DateTimeImmutable($date . ' 23:59:59', Time::zone((string) ($row['tzid'] ?? 'UTC'))))->setTimezone(Time::utc()));
            return $until >= $nowSql;
        }
        return true;
    }

    /** [start, end] of a series' first occurrence ending after $nowSql, within two years; null when there is none. */
    public static function nextOccurrence(array $row, string $nowSql, ?Recurrence $recurrence = null): ?array
    {
        $from = Time::fromDb($nowSql);
        try {
            $occs = ($recurrence ?? new Recurrence())->expand($row, [], $from, $from->modify('+2 years'));
        } catch (\Throwable) {
            return null;
        }
        foreach ($occs as $o) {
            if ($o['end'] > $from) {
                return [Time::toDb($o['start']), Time::toDb($o['end'])];
            }
        }
        return null;
    }

    /** [start, end] of a series' latest occurrence that ended by $nowSql, within two years; null when there is none. */
    public static function previousOccurrence(array $row, string $nowSql, ?Recurrence $recurrence = null): ?array
    {
        $to = Time::fromDb($nowSql);
        try {
            $occs = ($recurrence ?? new Recurrence())->expand($row, [], $to->modify('-2 years'), $to);
        } catch (\Throwable) {
            return null;
        }
        $last = null;
        foreach ($occs as $o) {
            if ($o['end'] <= $to) {
                $last = [Time::toDb($o['start']), Time::toDb($o['end'])];
            }
        }
        return $last;
    }

    private function nowSql(array $opts): string
    {
        $now = $opts['now'] ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The WHERE for a query: [where, params, order, extraSelect, selectParams,
     * 'fallback' => [where, params] | null]. Null when there is nothing to
     * search for.
     */
    private function plan(int $userId, string $q, array $opts): ?array
    {
        $q = trim($q);
        if ($q === '') {
            return null;
        }
        $tagOnly = str_starts_with($q, '#');
        $personOnly = str_starts_with($q, '@');
        $term = ($tagOnly || $personOnly) ? trim(mb_substr($q, 1)) : $q;
        if ($term === '') {
            return null;
        }
        $tagEventIds = $personOnly ? [] : $this->labels->eventIdsForTagQuery($userId, $term);
        if (!$tagOnly) {
            $tagEventIds = array_values(array_unique([
                ...$tagEventIds,
                ...$this->labels->eventIdsForPersonQuery($userId, $term),
            ]));
        }

        // Shared narrowing: the owner, not deleted, the time choice, a calendar.
        $base = 'user_id = ? AND deleted_at IS NULL';
        $baseParams = [$userId];
        $now = $this->nowSql($opts);
        $dayNow = $this->dayNowSql($userId, $now);
        $when = $opts['when'] ?? 'all';
        if ($when === 'upcoming') {
            $base .= " AND ((all_day = 1 AND end_utc > ?) OR (all_day = 0 AND end_utc >= ?) OR (rrule IS NOT NULL AND rrule <> ''))";
            array_push($baseParams, $dayNow, $now);
        } elseif ($when === 'past') {
            $base .= " AND ((all_day = 1 AND end_utc <= ?) OR (all_day = 0 AND end_utc < ?))";
            array_push($baseParams, $dayNow, $now);
        }
        if (isset($opts['calendarId']) && $opts['calendarId'] !== null) {
            $base .= ' AND calendar_id = ?';
            $baseParams[] = (int) $opts['calendarId'];
        }

        if ($tagOnly || $personOnly) {
            if ($tagEventIds === []) {
                return null;
            }
            [$in, $inParams] = Db::in($tagEventIds);
            return [0 => "$base AND id IN $in", 1 => [...$baseParams, ...$inParams], 2 => 'start_utc DESC', 3 => '', 4 => [], 'fallback' => null];
        }
        [$tagIn, $tagParams] = Db::in($tagEventIds !== [] ? $tagEventIds : [0]);
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $fallback = ["$base AND (title LIKE ? OR description LIKE ? OR location LIKE ? OR id IN $tagIn)", [...$baseParams, $like, $like, $like, ...$tagParams]];
        if (mb_strlen($term) >= self::MIN_FULLTEXT_LEN) {
            return [
                0 => "$base AND (MATCH(title, description, location) AGAINST (? IN NATURAL LANGUAGE MODE) OR id IN $tagIn)",
                1 => [...$baseParams, $term, ...$tagParams],
                2 => 'relevance DESC, start_utc DESC',
                3 => ', MATCH(title, description, location) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance',
                4 => [$term],
                'fallback' => $fallback,
            ];
        }
        return [0 => $fallback[0], 1 => $fallback[1], 2 => 'start_utc DESC', 3 => '', 4 => [], 'fallback' => null];
    }
}
