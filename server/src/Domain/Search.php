<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\ExpansionBudget;
use BetterCal\Support\Time;
use BetterCal\Support\WorkBudgetExceeded;

final class Search
{
    private const EXCERPT_CHARS = 200;
    /** A query's words past this many are ignored; it bounds the SQL a query builds. */
    public const MAX_WORDS = 8;
    /**
     * InnoDB's built-in stopword list (MySQL and MariaDB ship the same one;
     * the database suite checks it against the server's). A stopword is
     * never indexed, and required in boolean mode it empties the result, so
     * such a word is matched with LIKE instead.
     */
    public const STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for',
        'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the',
        'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und',
        'www',
    ];

    /** @var array{0:int,1:bool}|null the server's minimum token length, and whether it drops stopwords */
    private ?array $fulltext = null;

    public function __construct(
        private readonly Db $db,
        private readonly Labels $labels,
    ) {
    }

    /**
     * Event search, ranked in tiers (docs/design/search-ranking.md): every
     * word of the query must be in one field, the title or the description;
     * the address is not searched. Best first: the title holding the query
     * as a phrase, every word as a whole word, every word as a word start;
     * then the same three for the description. A tag or linked person whose
     * name matches the same way ranks with the title's tier. Within a tier,
     * upcoming results run soonest first and past ones newest first, and
     * upcoming come before past. A `#` prefix (e.g. "#work") searches tags
     * only; an `@` prefix (e.g. "@alex") people only. Published search
     * feeds match the same way. $excerpt trims descriptions for list
     * payloads; feed export passes false.
     *
     * $opts (0.7.3, #60): 'when' => 'all' | 'upcoming' | 'past', applied in
     * the query, before the limit, so a term with a long history can't push
     * upcoming matches out; 'calendarId' narrows to one calendar; 'now'.
     * Upcoming means not yet ended, and a repeating series counts as upcoming
     * until its UNTIL date (one with no end, or ending by COUNT, always does).
     * Each row carries '_upcoming' for the caller to group by.
     *
     * 'viewer' (a DateTimeZone, the request's device clock as the event
     * window reads it): a floating event (Floating) is over, upcoming,
     * ordered and given its next or latest date as it shows on that clock,
     * in the query and here alike (Floating::nowByZone), before the limit. Without
     * one, as stored.
     *
     * @return list<array> event rows
     */
    public function search(
        int $userId,
        string $q,
        int $limit = 50,
        bool $excerpt = true,
        array $opts = [],
        ?array $projection = null,
    ): array
    {
        $plan = $this->plan($userId, $q, $opts);
        if ($plan === null) {
            return [];
        }
        $limit = max(1, $limit); // feeds ask for 500; the search route caps at 200
        [$where, $params, $order, $select, $selectParams, $orderParams] = $plan;
        $columns = '*';
        if ($projection !== null) {
            $allowed = [
                'id', 'uid', 'title', 'description', 'location', 'url', 'start_utc', 'end_utc',
                'all_day', 'tzid', 'rrule', 'exdates_json', 'recurrence_instance_utc', 'status',
                'reminders_json',
            ];
            if ($projection === [] || array_diff($projection, $allowed) !== []) {
                throw new \LogicException('Invalid internal search projection');
            }
            $columns = implode(', ', array_values(array_unique($projection)));
        }
        $rows = $this->db->all(
            "SELECT $columns$select FROM events WHERE $where ORDER BY $order LIMIT $limit",
            [...$selectParams, ...$params, ...$orderParams]
        );
        $now = $this->nowSql($opts);
        $dayNow = $this->dayNowSql($userId, $now);
        $viewer = self::viewerOf($opts);
        $clocks = Floating::nowByZone($this->db, $userId, $now, $viewer);
        $expansionBudget = ExpansionBudget::standard();
        $out = [];
        foreach ($rows as $row) {
            $row['_tier'] = (int) $row['search_tier'];
            unset($row['search_tier']);
            // A floating row is compared on its own clock: now as it stores
            // the viewer's clock (Floating::nowByZone), as the query compared it.
            $rowNow = Floating::is($row) ? ($clocks[(string) $row['tzid']] ?? $now) : $now;
            $row['_upcoming'] = self::isUpcoming($row, $rowNow, $dayNow);
            if (($opts['when'] ?? 'all') === 'upcoming' && !$row['_upcoming']) {
                continue; // a series whose UNTIL has passed
            }
            if (($opts['when'] ?? 'all') === 'past' && $row['_upcoming']) {
                // A series still running matched Past by its first date: show
                // its latest occurrence that has ended (0.7.4), not its next.
                try {
                    $prev = self::previousOccurrence($row, $now, null, $expansionBudget, $viewer);
                } catch (WorkBudgetExceeded) {
                    throw new HttpError('event_search_too_large', 'This search has too many repeating occurrences to process safely. Narrow the search and try again.', 422);
                }
                if ($prev === null) {
                    continue;
                }
                [$row['start_utc'], $row['end_utc']] = $prev;
                if (isset($prev[2])) {
                    $row['_wall'] = $prev[2];
                }
                $row['_upcoming'] = false;
            } elseif ($row['_upcoming'] && !empty($row['rrule']) && (string) $row['end_utc'] < $rowNow) {
                // A series that began in the past: show (and open) its next date, not its first.
                try {
                    $next = self::nextOccurrence($row, $now, null, $expansionBudget, $viewer);
                } catch (WorkBudgetExceeded) {
                    throw new HttpError('event_search_too_large', 'This search has too many repeating occurrences to process safely. Narrow the search and try again.', 422);
                }
                if ($next === null) {
                    if (($opts['when'] ?? 'all') === 'upcoming') {
                        continue;
                    }
                    $row['_upcoming'] = false;
                } else {
                    [$row['start_utc'], $row['end_utc']] = $next;
                    if (isset($next[2])) {
                        $row['_wall'] = $next[2];
                    }
                }
            }
            // Its start as shown, which orders it among the others.
            $row['_at'] = Time::toDb(Floating::span($row, Time::fromDb((string) $row['start_utc']), Time::fromDb((string) $row['end_utc']), $viewer)[0]);
            if ($excerpt && $row['description'] !== null && mb_strlen((string) $row['description']) > self::EXCERPT_CHARS) {
                $row['description'] = mb_substr((string) $row['description'], 0, self::EXCERPT_CHARS) . '…';
            }
            $out[] = $row;
        }
        // A running series shows its next date, known only here, so the
        // order within a tier is settled after the query.
        usort($out, static function (array $a, array $b): int {
            $c = [$a['_upcoming'] ? 0 : 1, $a['_tier']] <=> [$b['_upcoming'] ? 0 : 1, $b['_tier']];
            if ($c !== 0) {
                return $c;
            }
            return $a['_upcoming']
                ? strcmp($a['_at'], $b['_at'])
                : strcmp($b['_at'], $a['_at']);
        });
        foreach ($out as &$row) {
            unset($row['_tier'], $row['_at']);
        }
        unset($row);
        return $out;
    }

    /** How many matches lie in the past, for "N past matches" under an Upcoming search. */
    public function countPast(int $userId, string $q, array $opts = []): int
    {
        return $this->count($userId, $q, ['when' => 'past'] + $opts);
    }

    /**
     * How many events match, before any limit: the query search() runs,
     * counted. Under 'when' => 'all' search() keeps every row that query
     * returns, so this is how many it would list with no limit.
     */
    public function count(int $userId, string $q, array $opts = []): int
    {
        $plan = $this->plan($userId, $q, $opts);
        if ($plan === null) {
            return 0;
        }
        [$where, $params] = $plan;
        return (int) $this->db->scalar("SELECT COUNT(*) FROM events WHERE $where", $params);
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

    /**
     * [start, end] of a series' first occurrence ending after $nowSql, within
     * two years; null when there is none. With $viewer, a floating series'
     * occurrences are read as they show on that clock (Recurrence::expand),
     * and the one found is given as stored, with the wall clock it shows at
     * third (Floating::span reads it as '_wall'). With $notStarted, the first
     * starting at or after $nowSql instead (a person's next event); $until
     * looks only that far, in place of two years.
     *
     * @return array{0:string,1:string,2?:int}|null
     */
    public static function nextOccurrence(
        array $row,
        string $nowSql,
        ?Recurrence $recurrence = null,
        ?ExpansionBudget $budget = null,
        ?\DateTimeZone $viewer = null,
        bool $notStarted = false,
        ?\DateTimeImmutable $until = null,
    ): ?array
    {
        $from = Time::fromDb($nowSql);
        try {
            $occs = ($recurrence ?? new Recurrence())->expand($row, [], $from, $until ?? $from->modify('+2 years'), $budget, $viewer);
        } catch (WorkBudgetExceeded $e) {
            throw $e;
        } catch (\Throwable) {
            return null;
        }
        foreach ($occs as $o) {
            if ($notStarted ? $o['start'] >= $from : $o['end'] > $from) {
                return self::storedOccurrence($row, $o, $viewer);
            }
        }
        return null;
    }

    /** [start, end] of a series' latest occurrence that ended by $nowSql, within two years; null when there is none. With $viewer, as nextOccurrence. */
    public static function previousOccurrence(
        array $row,
        string $nowSql,
        ?Recurrence $recurrence = null,
        ?ExpansionBudget $budget = null,
        ?\DateTimeZone $viewer = null,
    ): ?array
    {
        $to = Time::fromDb($nowSql);
        try {
            $occs = ($recurrence ?? new Recurrence())->expand($row, [], $to->modify('-2 years'), $to, $budget, $viewer);
        } catch (WorkBudgetExceeded $e) {
            throw $e;
        } catch (\Throwable) {
            return null;
        }
        $last = null;
        foreach ($occs as $o) {
            if ($o['end'] <= $to && ($last === null || $o['start'] >= $last['start'])) {
                $last = $o;
            }
        }
        return $last === null ? null : self::storedOccurrence($row, $last, $viewer);
    }

    /**
     * An occurrence Recurrence::expand gave, as the row stores it: as given,
     * or for a floating series read on a viewer's clock its stored key and
     * length, with the wall clock it shows at.
     *
     * @return array{0:string,1:string,2?:int}
     */
    private static function storedOccurrence(array $row, array $o, ?\DateTimeZone $viewer): array
    {
        if ($viewer === null || !Floating::is($row)) {
            return [Time::toDb($o['start']), Time::toDb($o['end'])];
        }
        $start = Time::fromDb((string) $o['instanceUtc']);
        $stored = [Time::toDb($start), Time::toDb($start->modify('+' . Recurrence::durationSeconds($row) . ' seconds'))];
        return isset($o['wall']) ? [...$stored, (int) $o['wall']] : $stored;
    }

    /** The viewer's clock search reads floating events on, if one was given. */
    private static function viewerOf(array $opts): ?\DateTimeZone
    {
        return ($opts['viewer'] ?? null) instanceof \DateTimeZone ? $opts['viewer'] : null;
    }

    /**
     * SQL for a row's start as shown, to order by before the limit: a
     * floating row's moved by how far its zone's clock stands from the
     * viewer's now (Floating::nowByZone), every other row's as stored. search() orders
     * the rows it keeps by their exact start as shown.
     *
     * @param array<string,string> $clocks
     * @return array{0:string,1:list<string|int>}
     */
    private static function shownStartSql(array $clocks, string $nowSql): array
    {
        if ($clocks === []) {
            return ['start_utc', []];
        }
        $now = Time::fromDb($nowSql)->getTimestamp();
        $sql = 'CASE';
        $params = [];
        foreach ($clocks as $tzid => $at) {
            $sql .= ' WHEN floating = 1 AND all_day = 0 AND tzid = ? THEN DATE_ADD(start_utc, INTERVAL ? SECOND)';
            array_push($params, (string) $tzid, $now - Time::fromDb($at)->getTimestamp());
        }
        return [$sql . ' ELSE start_utc END', $params];
    }

    private function nowSql(array $opts): string
    {
        $now = $opts['now'] ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The query's words: runs of letters and digits, lowercased, at most
     * MAX_WORDS, each once. Anything else separates words, so no typed
     * operator ever reaches MATCH, and an apostrophe splits a word the way
     * the index does ("o'brien" is "o" and "brien").
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        if (preg_match_all('/[\p{L}\p{N}\p{M}]+/u', mb_strtolower($text), $m) === false) {
            return [];
        }
        return array_slice(array_values(array_unique($m[0])), 0, self::MAX_WORDS);
    }

    /**
     * The query as a phrase for MATCH: its words in order, an apostrophe
     * inside a word kept, since that is how the index read the text it
     * stored. Only letters, digits, apostrophes and spaces, so it can't end
     * the quotes it goes in.
     */
    public static function phrase(string $text): string
    {
        $text = str_replace("\u{2019}", "'", mb_strtolower($text));
        if (preg_match_all("/[\\p{L}\\p{N}\\p{M}]+(?:'[\\p{L}\\p{N}\\p{M}]+)*/u", $text, $m) === false) {
            return '';
        }
        return implode(' ', array_slice($m[0], 0, self::MAX_WORDS));
    }

    /**
     * How a name (a tag's, a person's) matches the words, by the title's
     * tiers: 1 the words as a phrase (more than one word), 2 every word whole,
     * 3 every word starting a word of the name; null when it doesn't.
     *
     * @param list<string> $words from words()
     */
    public static function nameTier(array $words, string $name): ?int
    {
        $tokens = preg_match_all('/[\p{L}\p{N}\p{M}]+/u', mb_strtolower($name), $m) === false ? [] : $m[0];
        if ($words === [] || $tokens === []) {
            return null;
        }
        $n = count($words);
        if ($n > 1) {
            for ($i = 0; $i + $n <= count($tokens); $i++) {
                if (array_slice($tokens, $i, $n) === $words) {
                    return 1;
                }
            }
        }
        if (array_diff($words, $tokens) === []) {
            return 2;
        }
        foreach ($words as $w) {
            $starts = false;
            foreach ($tokens as $t) {
                if (str_starts_with($t, $w)) {
                    $starts = true;
                    break;
                }
            }
            if (!$starts) {
                return null;
            }
        }
        return 3;
    }

    /**
     * One field's three conditions, each [sql, params] or null: 'phrase',
     * 'whole' (every word whole), 'prefix' (every word a word start). Words
     * the index holds (at least $minToken long, not a stopword when the
     * server drops them) are required in one boolean-mode MATCH; the others
     * are required with LIKE on the same field. With no indexed word at all
     * the field is matched with LIKE alone, a word or the phrase anywhere in
     * it. $field is a column name from this class, never input.
     *
     * @param list<string> $words from words()
     * @return array{phrase:?array{0:string,1:list<string>},whole:array{0:string,1:list<string>},prefix:array{0:string,1:list<string>}}
     */
    public static function fieldMatch(string $field, array $words, string $phrase, int $minToken, bool $stopwords): array
    {
        $indexed = [];
        $likeSql = [];
        $likeParams = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= $minToken && !($stopwords && in_array($w, self::STOPWORDS, true))) {
                $indexed[] = $w;
            } else {
                $likeSql[] = "$field LIKE ?";
                $likeParams[] = '%' . addcslashes($w, '%_\\') . '%';
            }
        }
        $multi = count($words) > 1;
        if ($indexed === []) {
            $all = [implode(' AND ', $likeSql), $likeParams];
            return [
                'phrase' => $multi ? ["$field LIKE ?", ['%' . addcslashes(implode(' ', $words), '%_\\') . '%']] : null,
                'whole' => $all,
                'prefix' => $all,
            ];
        }
        $match = "MATCH($field) AGAINST (? IN BOOLEAN MODE)";
        $with = static fn(string $against): array => [
            implode(' AND ', [$match, ...$likeSql]),
            [$against, ...$likeParams],
        ];
        $whole = $with(implode(' ', array_map(static fn(string $w): string => '+' . $w, $indexed)));
        // A phrase is MATCH's (punctuation between the words ignored) or the
        // words together as typed, which also holds a phrase whose last word
        // is still being typed.
        return [
            'phrase' => $multi && $phrase !== '' ? [
                "($match OR $field LIKE ?) AND " . $whole[0],
                ['"' . $phrase . '"', '%' . addcslashes(implode(' ', $words), '%_\\') . '%', ...$whole[1]],
            ] : null,
            'whole' => $whole,
            'prefix' => $with(implode(' ', array_map(static fn(string $w): string => '+' . $w . '*', $indexed))),
        ];
    }

    /**
     * The tier CASE and the matching condition from the fields' conditions
     * (fieldMatch, title first then description) and the label matches
     * (event id => its best name tier). Title tiers are 1 to 3 and a label
     * shares its tier; description tiers are 4 to 6. Matching is the loosest
     * condition of either field or a label: all the words in one field.
     *
     * @param array<string,array> $fields 'title' and/or 'description' => fieldMatch()
     * @param array<int,int> $labelTiers
     * @return array{0:string,1:list<mixed>,2:string,3:list<mixed>} [caseSql, caseParams, whereSql, whereParams]
     */
    public static function rank(array $fields, array $labelTiers): array
    {
        $byTier = [1 => [], 2 => [], 3 => []];
        foreach ($labelTiers as $eventId => $tier) {
            $byTier[$tier][] = (int) $eventId;
        }
        $when = [];
        $offsets = ['title' => 0, 'description' => 3];
        $steps = ['phrase' => 1, 'whole' => 2, 'prefix' => 3];
        foreach ($fields as $field => $conds) {
            foreach ($steps as $step => $n) {
                $tier = $offsets[$field] + $n;
                if ($conds[$step] !== null) {
                    $when[] = [$conds[$step][0], $conds[$step][1], $tier];
                }
                if ($field === 'title' && $byTier[$n] !== []) {
                    [$in, $inParams] = Db::in($byTier[$n]);
                    $when[] = ["id IN $in", $inParams, $tier];
                }
            }
        }
        if (!isset($fields['title'])) {
            foreach ($byTier as $n => $ids) {
                if ($ids !== []) {
                    [$in, $inParams] = Db::in($ids);
                    $when[] = ["id IN $in", $inParams, $n];
                }
            }
        }
        $caseSql = 'CASE';
        $caseParams = [];
        foreach ($when as [$sql, $params, $tier]) {
            $caseSql .= " WHEN $sql THEN $tier";
            array_push($caseParams, ...$params);
        }
        $caseSql .= ' ELSE 9 END';
        $where = [];
        $whereParams = [];
        foreach ($fields as $conds) {
            $where[] = '(' . $conds['prefix'][0] . ')';
            array_push($whereParams, ...$conds['prefix'][1]);
        }
        if ($labelTiers !== []) {
            [$in, $inParams] = Db::in(array_map('intval', array_keys($labelTiers)));
            $where[] = "id IN $in";
            array_push($whereParams, ...$inParams);
        }
        return [$caseSql, $caseParams, $where === [] ? '0 = 1' : '(' . implode(' OR ', $where) . ')', $whereParams];
    }

    /** The server's innodb_ft_min_token_size and innodb_ft_enable_stopword, read once. @return array{0:int,1:bool} */
    private function fulltextSettings(): array
    {
        if ($this->fulltext === null) {
            try {
                $row = $this->db->one('SELECT @@innodb_ft_min_token_size AS min_token, @@innodb_ft_enable_stopword AS stopwords');
                $this->fulltext = [max(1, (int) ($row['min_token'] ?? 3)), (int) ($row['stopwords'] ?? 1) === 1];
            } catch (\PDOException) {
                $this->fulltext = [3, true];
            }
        }
        return $this->fulltext;
    }

    /**
     * The query: [where, params, order, extraSelect, selectParams,
     * orderParams]. The extra
     * select is the row's tier as search_tier. Null when there is nothing to
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
        $words = self::words($term);
        if ($words === []) {
            return null;
        }
        $labelTiers = $this->labels->eventTiersForWords($userId, $words, !$personOnly, !$tagOnly);

        // Shared narrowing: the owner, not deleted, the time choice, a calendar.
        $base = 'user_id = ? AND deleted_at IS NULL';
        $baseParams = [$userId];
        $now = $this->nowSql($opts);
        $dayNow = $this->dayNowSql($userId, $now);
        $clocks = Floating::nowByZone($this->db, $userId, $now, self::viewerOf($opts));
        [$nowSql, $nowParams] = Floating::nowOnRowSql($clocks, $now);
        $when = $opts['when'] ?? 'all';
        if ($when === 'upcoming') {
            $base .= " AND ((all_day = 1 AND end_utc > ?) OR (all_day = 0 AND end_utc >= $nowSql) OR (rrule IS NOT NULL AND rrule <> ''))";
            array_push($baseParams, $dayNow, ...$nowParams);
        } elseif ($when === 'past') {
            $base .= " AND ((all_day = 1 AND end_utc <= ?) OR (all_day = 0 AND end_utc < $nowSql))";
            array_push($baseParams, $dayNow, ...$nowParams);
        }
        if (isset($opts['calendarId']) && $opts['calendarId'] !== null) {
            $base .= ' AND calendar_id = ?';
            $baseParams[] = (int) $opts['calendarId'];
        }

        $fields = [];
        if (!$tagOnly && !$personOnly) {
            [$minToken, $stopwords] = $this->fulltextSettings();
            $phrase = self::phrase($term);
            foreach (['title', 'description'] as $field) {
                $fields[$field] = self::fieldMatch($field, $words, $phrase, $minToken, $stopwords);
            }
        } elseif ($labelTiers === []) {
            return null;
        }
        [$caseSql, $caseParams, $matchSql, $matchParams] = self::rank($fields, $labelTiers);
        // The limit keeps the best tiers, and within one the dates nearest
        // the side asked for; search() settles the final order.
        [$startSql, $startParams] = self::shownStartSql($clocks, $now);
        return [
            0 => "$base AND $matchSql",
            1 => [...$baseParams, ...$matchParams],
            2 => "search_tier, $startSql " . ($when === 'upcoming' ? 'ASC' : 'DESC'),
            3 => ", $caseSql AS search_tier",
            4 => $caseParams,
            5 => $startParams,
        ];
    }
}
