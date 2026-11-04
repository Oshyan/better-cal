<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/**
 * Mutation log + undo. Each mutating API call records before/after snapshots
 * as {"tables": {table: [rows...]}}, and what it changed: each row it made
 * or removed, each column it changed in a row it kept, and what those rows
 * belong to (touchesOf). Undo puts back exactly that: rows made are
 * removed, rows removed come back, and changed columns return to their
 * earlier values. A row only carried along in a snapshot is left alone. An
 * undo is stale when a later change, not undone, changed the ground it
 * stands on (staleFor, conflicts).
 */
final class Undo
{
    /** Restore order respects FK dependencies; deletions run in reverse. */
    private const TABLE_ORDER = [
        'folders', 'tags', 'people', 'calendars', 'calendar_folders',
        'events', 'event_links', 'event_tags', 'event_people', 'out_feeds', 'filters', 'saved_views',
        'availability', 'review_items',
    ];

    private const TABLE_PK = [
        'folders' => ['id'], 'tags' => ['id'], 'people' => ['id'], 'calendars' => ['id'],
        'events' => ['id'], 'out_feeds' => ['id'], 'filters' => ['id'], 'saved_views' => ['id'],
        'event_links' => ['id'], 'availability' => ['id'], 'review_items' => ['id'],
        'calendar_folders' => ['calendar_id', 'folder_id'],
        'event_tags' => ['event_id', 'tag_id'],
        'event_people' => ['event_id', 'person_id'],
    ];

    /**
     * What a row belongs to, by column: the parent's table, and the mark a
     * row joining or leaving it leaves on the parent. "+" is membership (an
     * event added to a calendar, a calendar filed in a folder); "~" is a
     * date of a series edited on its own, which depends on the series
     * itself (conflicts).
     */
    private const PARENTS = [
        'events' => ['calendar_id' => ['calendars', '+'], 'recurrence_parent_id' => ['events', '~']],
        'calendar_folders' => ['calendar_id' => ['calendars', '+'], 'folder_id' => ['folders', '+']],
        'event_tags' => ['event_id' => ['events', '+'], 'tag_id' => ['tags', '+']],
        'event_people' => ['event_id' => ['events', '+'], 'person_id' => ['people', '+']],
        'event_links' => ['container_id' => ['events', '+'], 'event_id' => ['events', '+']],
        'availability' => ['person_id' => ['people', '+']],
    ];

    /** Rows that only link two others: put back only while both are still there. */
    private const LINK_TABLES = ['calendar_folders', 'event_tags', 'event_people', 'event_links'];

    /**
     * Tables whose rows one change can make or remove by the tens of
     * thousands (a calendar deleted, a file imported): those rows can be
     * kept in pieces apart from the entry (mutation_rows), as one row of the
     * journal they were more than the database takes in one statement
     * (#191). Rows a change kept and changed stay in the entry.
     */
    private const PIECE_TABLES = ['events', 'event_links', 'event_tags', 'event_people'];

    /** The most rows' text one piece holds, far under the smallest packet size a database is likely to allow. */
    private const PIECE_BYTES = 512 * 1024;

    /**
     * An entry's snapshots longer than this keep their PIECE_TABLES rows
     * made or removed in pieces.
     *
     * @internal A test seam: only the database suite sets it (lower, to put
     * an entry's rows in pieces), and puts it back. App code never writes it.
     */
    public static int $inlineBytes = 1024 * 1024;

    /**
     * How the journal writes its JSON: characters as themselves, not as \u
     * escapes, so a row of emoji or CJK text is about its own size in the
     * journal rather than three to six times it, and a large one still fits
     * in one statement. Entries written escaped, before, read back the same.
     */
    private const JSON_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * A calendar's columns that are Better-Cal's own, never its source's:
     * its place in the list and whether it shows. A Google calendar's
     * change to only these is undoable like any other.
     */
    private const LOCAL_CALENDAR_COLUMNS = ['position', 'visible'];

    /**
     * Where a person's own changes come from, for undoing the last one
     * (undoLatest): the app, quick add, review decisions, invitation
     * answers and duplicate decisions.
     */
    private const OWN_SOURCES = ['web', 'quickadd', 'review', 'rsvp', 'dedup:owner'];

    /** $plugins: the plugin registry undo uses for plugin edits (tests pass one with a stand-in plugin). */
    public function __construct(private readonly Db $db, private readonly ?Plugins $plugins = null)
    {
    }

    /**
     * Changes recorded inside group(), per connection: kept back, and
     * written as one entry when it ends. Per connection, not per Undo, so a
     * step made by another class's Undo (Events', within a Review decision)
     * is part of the group too.
     *
     * @var array<int,list<array<string,mixed>>>
     */
    private static array $groups = [];

    /**
     * The accounts whose rows are locked for a write() now, per connection.
     *
     * @var array<string,true>
     */
    private static array $writing = [];

    /**
     * Every change undo should know of is made here: in one transaction,
     * the account's row locked first, then $fn, which records its entries
     * (record() refuses anywhere else). Undo takes the same lock first, so
     * an undo and any change to the same account never wait on each other
     * in opposite orders, and an undo's check sees every change committed
     * before it. Within a write() for the same account, just $fn.
     *
     * Lock order, for every transaction that changes an account's data:
     * the account's row (here), then the request's credential (its session
     * or key), then calendars (CalendarMoveGuard), then a Google account,
     * then the rows changed.
     */
    public function write(int $userId, callable $fn): mixed
    {
        $key = spl_object_id($this->db) . ':' . $userId;
        if (isset(self::$writing[$key])) {
            return $fn();
        }
        if ($this->db->pdo()->inTransaction()) {
            // Rows this transaction already locked come before the account:
            // the order an undo waiting on the account could deadlock with.
            self::misuse('Undo::write inside a transaction that began elsewhere');
        }
        return $this->db->tx(function () use ($key, $userId, $fn): mixed {
            $this->lockAccount($userId);
            self::$writing[$key] = true;
            try {
                return $fn();
            } finally {
                unset(self::$writing[$key]);
            }
        });
    }

    /** @var list<string> journaling mistakes seen, in the test suites (misuse) */
    private static array $misuses = [];

    /**
     * The account's row, locked: the first lock any change to its data takes
     * (write(), apply()), so none waits on another in the opposite order.
     */
    private function lockAccount(int $userId): void
    {
        if ($this->db->forUpdate() !== '') {
            $this->db->one('SELECT id FROM users WHERE id = ?' . $this->db->forUpdate(), [$userId]);
        }
    }

    /**
     * A journaling mistake in the code. The change itself still goes
     * through as it did before the rule; production logs it, and the test
     * suites (BETTERCAL_STRICT_JOURNAL) keep each, with where it came from,
     * and fail on them (misuses()).
     */
    private static function misuse(string $what): void
    {
        if (getenv('BETTERCAL_STRICT_JOURNAL') !== '1') {
            error_log('undo: ' . $what);
            return;
        }
        $at = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file !== '' && $file !== __FILE__ && !str_ends_with($file, '/Infra/Db.php') && !str_ends_with($file, '/ActivityContext.php')) {
                $at[] = basename($file) . ':' . ($frame['line'] ?? 0);
                if (count($at) === 4) {
                    break;
                }
            }
        }
        self::$misuses[$what . ' at ' . implode(' < ', $at)] = true;
    }

    /** The journaling mistakes the test suites ran into, each once. @return list<string> */
    public static function misuses(): array
    {
        return array_keys(self::$misuses);
    }

    /**
     * $touches: what the change changed that its snapshots don't show, as
     * [row key (keyOf), column] pairs: a change sent to Google or a plugin,
     * which keeps no snapshots, or a list numbered afresh. $details may say
     * which of the account's settings the change also changed, as
     * ['settings' => [key => [before, after]]]; undo puts each back while
     * it's still as the change left it.
     *
     * A change to anything undo knows (snapshots or touches) is recorded
     * inside write(); a note that changed nothing of the kind may be
     * recorded anywhere.
     *
     * @param array<string,list<array>>|null $beforeTables
     * @param array<string,list<array>>|null $afterTables
     * @param list<array{0:string,1:string}> $touches
     */
    public function record(int $userId, string $entity, int $entityId, string $op, ?array $beforeTables, ?array $afterTables, ?string $summary = null, ?array $details = null, array $calendarIds = [], array $touches = []): int
    {
        if (isset(self::$groups[spl_object_id($this->db)])) {
            self::$groups[spl_object_id($this->db)][] = compact('userId', 'entity', 'entityId', 'op', 'beforeTables', 'afterTables', 'summary', 'details', 'calendarIds', 'touches');
            return 0; // written when the group ends
        }
        if (!isset(self::$writing[spl_object_id($this->db) . ':' . $userId])
            && ($beforeTables !== null || $afterTables !== null || $touches !== [])) {
            self::misuse("Undo::record of $entity $op outside Undo::write");
        }
        $beforeJson = self::encodeTables($beforeTables);
        $afterJson = self::encodeTables($afterTables);
        // A large one keeps the rows it made or removed in pieces.
        $pieces = [];
        if (strlen($beforeJson ?? '') + strlen($afterJson ?? '') > self::$inlineBytes) {
            [$keptBefore, $keptAfter, $pieces] = self::splitPieces($beforeTables, $afterTables);
            if ($pieces !== []) {
                [$beforeJson, $afterJson] = [self::encodeTables($keptBefore), self::encodeTables($keptAfter)];
            }
        }
        $mutationId = $this->db->insert('mutations', [
            'user_id' => $userId,
            'entity' => $entity,
            'entity_id' => $entityId,
            'op' => $op,
            'before_json' => $beforeJson,
            'after_json' => $afterJson,
            'source' => ActivityContext::get(),
            'run_id' => ActivityContext::runId(),
            'summary' => mb_substr($summary ?? self::defaultSummary($entity, $op, $beforeTables, $afterTables, Settings::homeTzid($this->db, $userId)), 0, 300),
            'details_json' => $details === null ? null : json_encode($details, self::JSON_FLAGS),
        ]);
        ActivityContext::noteEntry($mutationId);
        // Before the refs: a Google calendar's entry has its snapshots
        // emptied there, and what it changed has to stay known.
        $this->recordTouches($mutationId, array_merge(self::touchesOf($beforeTables, $afterTables), $touches));
        if (!$this->recordCalendarRefs($mutationId, $beforeTables, $afterTables, $calendarIds)) {
            foreach ($pieces as $side => $tables) {
                $this->writePieces($mutationId, $side, $tables);
            }
        }
        return $mutationId;
    }

    /**
     * Rows a change removes, added to its entry ($mutationId, from record())
     * and kept in pieces apart from it: a calendar's events, a page at a
     * time, so neither the journal nor this process holds all of them at
     * once (#191). What they touch and the calendars they're in are
     * recorded as record() records them. In the entry's write(), before the
     * rows go.
     *
     * @param array<string,list<array>> $tables
     */
    public function recordRemoved(int $mutationId, array $tables): void
    {
        self::assertEntry($mutationId, 'recordRemoved');
        foreach (array_keys($tables) as $table) {
            if (!in_array($table, self::PIECE_TABLES, true)) {
                // Kept in a piece, they would never be put back.
                throw new \LogicException("Undo::recordRemoved can't keep $table rows, only " . implode(', ', self::PIECE_TABLES));
            }
        }
        $this->recordTouches($mutationId, self::touchesOf($tables, null));
        if ($this->recordCalendarRefs($mutationId, $tables, null)
            || (int) $this->db->scalar('SELECT before_json IS NULL AND after_json IS NULL FROM mutations WHERE id = ?', [$mutationId]) === 1) {
            return; // log-only: a Google calendar's
        }
        $this->writePieces($mutationId, 'before', $tables);
    }

    /**
     * The events $where finds, with their tags, people and trip links,
     * added to an entry as rows it removes (recordRemoved), a page at a
     * time in id order.
     */
    public function recordRemovedEvents(int $mutationId, string $where, array $params): void
    {
        self::assertEntry($mutationId, 'recordRemovedEvents');
        $after = 0;
        $links = [];
        do {
            $page = $this->db->all("SELECT * FROM events WHERE ($where) AND id > ? ORDER BY id LIMIT 500", array_merge($params, [$after]));
            if ($page === []) {
                break;
            }
            $after = (int) $page[count($page) - 1]['id'];
            $tables = ['events' => $page] + self::linksOf($this->db, array_map(static fn(array $e): int => (int) $e['id'], $page));
            // A trip link between two of them is found from both pages.
            $fresh = [];
            foreach ($tables['event_links'] ?? [] as $link) {
                if (!isset($links[(int) $link['id']])) {
                    $links[(int) $link['id']] = true;
                    $fresh[] = $link;
                }
            }
            $tables['event_links'] = $fresh;
            $this->recordRemoved($mutationId, array_filter($tables));
        } while (count($page) === 500);
    }

    /**
     * Account settings a change also changed, as ['key' => [before, after]],
     * added to its entry once they're known (a calendar deleted moves where
     * new events go only after it's gone); undo puts each back.
     */
    public function noteSettings(int $mutationId, array $settings): void
    {
        if ($settings === []) {
            return;
        }
        self::assertEntry($mutationId, 'noteSettings');
        $details = json_decode((string) $this->db->scalar('SELECT details_json FROM mutations WHERE id = ?', [$mutationId]), true);
        $details = is_array($details) ? $details : [];
        $details['settings'] = array_merge((array) ($details['settings'] ?? []), $settings);
        $this->db->update('mutations', ['details_json' => json_encode($details, self::JSON_FLAGS)], 'id = ?', [$mutationId]);
    }

    /**
     * An entry to add to, from record(). Inside group() there is none yet
     * (it's written when the group ends; record() returns 0 there): going
     * on would remove rows the entry never holds, and an undo would bring
     * back an empty calendar. So the change fails instead.
     */
    private static function assertEntry(int $mutationId, string $what): void
    {
        if ($mutationId <= 0) {
            throw new \LogicException("Undo::$what without an entry to add to (inside Undo::group?)");
        }
    }

    private static function encodeTables(?array $tables): ?string
    {
        return $tables === null ? null : (string) json_encode(['tables' => $tables], self::JSON_FLAGS);
    }

    /**
     * A large entry's snapshots split: what stays in the entry, and the
     * PIECE_TABLES rows only one side has (made: after; removed: before),
     * which go to pieces. A row both sides have stays, where undo compares
     * them. Pure.
     *
     * @return array{0:?array,1:?array,2:array<string,array<string,list<array>>>}
     */
    private static function splitPieces(?array $before, ?array $after): array
    {
        $pieces = [];
        foreach (self::PIECE_TABLES as $table) {
            $keys = [[], []];
            foreach ([$before, $after] as $i => $side) {
                foreach ($side[$table] ?? [] as $row) {
                    $keys[$i][self::keyOf($table, $row)] = true;
                }
            }
            foreach (['before' => 0, 'after' => 1] as $name => $i) {
                $side = $i === 0 ? $before : $after;
                $only = [];
                $kept = [];
                foreach ($side[$table] ?? [] as $row) {
                    if (isset($keys[1 - $i][self::keyOf($table, $row)])) {
                        $kept[] = $row;
                    } else {
                        $only[] = $row;
                    }
                }
                if ($only === []) {
                    continue;
                }
                $pieces[$name][$table] = $only;
                if ($i === 0) {
                    $before[$table] = $kept;
                } else {
                    $after[$table] = $kept;
                }
            }
        }
        return [$before, $after, $pieces];
    }

    /**
     * Rows of an entry kept apart from it (mutation_rows), as many to a
     * piece as fit in PIECE_BYTES; each piece one table's rows, numbered
     * on from the entry's last.
     *
     * @param array<string,list<array>> $tables
     */
    private function writePieces(int $mutationId, string $side, array $tables): void
    {
        $seq = (int) $this->db->scalar('SELECT COALESCE(MAX(seq), 0) FROM mutation_rows WHERE mutation_id = ?', [$mutationId]);
        foreach ($tables as $table => $rows) {
            $buffer = [];
            $bytes = 0;
            $flush = function () use ($mutationId, $side, $table, &$seq, &$buffer, &$bytes): void {
                $this->db->insert('mutation_rows', [
                    'mutation_id' => $mutationId,
                    'seq' => ++$seq,
                    'side' => $side,
                    'table_name' => $table,
                    'rows_json' => '[' . implode(',', $buffer) . ']',
                ]);
                [$buffer, $bytes] = [[], 0];
            };
            foreach ($rows as $row) {
                $json = json_encode($row, self::JSON_FLAGS);
                if ($json === false) {
                    // Failing the change beats an entry that can't put it back.
                    throw new \RuntimeException("undo: a $table row can't be kept for undo: " . json_last_error_msg());
                }
                if ($buffer !== [] && $bytes + strlen($json) > self::PIECE_BYTES) {
                    $flush();
                }
                $buffer[] = $json;
                $bytes += strlen($json) + 1;
            }
            if ($buffer !== []) {
                $flush();
            }
        }
    }

    /** The tables an entry keeps rows of in pieces, by side. @return array<string,list<string>> */
    private function pieceTables(int $mutationId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT DISTINCT side, table_name FROM mutation_rows WHERE mutation_id = ?', [$mutationId]) as $r) {
            $out[(string) $r['side']][] = (string) $r['table_name'];
        }
        return $out;
    }

    /**
     * An entry's rows of one table and side kept in pieces, a piece at a
     * time.
     *
     * @return \Generator<int,list<array>>
     */
    private function piecesOf(int $mutationId, string $side, string $table): \Generator
    {
        $seqs = array_column($this->db->all(
            'SELECT seq FROM mutation_rows WHERE mutation_id = ? AND side = ? AND table_name = ? ORDER BY seq',
            [$mutationId, $side, $table]
        ), 'seq');
        foreach ($seqs as $seq) {
            $rows = json_decode((string) $this->db->scalar('SELECT rows_json FROM mutation_rows WHERE mutation_id = ? AND seq = ?', [$mutationId, (int) $seq]), true);
            if (!is_array($rows)) {
                throw HttpError::badRequest("This entry's rows can't be read back", 'not_undoable');
            }
            yield $rows;
        }
    }

    /**
     * Drop the pieces of entries that can no longer be undone (their
     * snapshots cleared: past the undo window, a calendar moved to Google).
     * An entry removed takes its pieces with it. Returns the pieces dropped.
     */
    public static function dropUnusedPieces(Db $db): int
    {
        $ids = array_column($db->all(
            'SELECT DISTINCT r.mutation_id FROM mutation_rows r JOIN mutations m ON m.id = r.mutation_id
             WHERE m.before_json IS NULL AND m.after_json IS NULL'
        ), 'mutation_id');
        $dropped = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            [$in, $params] = Db::in($chunk);
            $dropped += $db->run("DELETE FROM mutation_rows WHERE mutation_id IN $in", $params)->rowCount();
        }
        return $dropped;
    }

    /**
     * Run $fn and record everything it changes as one entry, so one undo
     * puts back one save made in several steps: each row as it was before
     * the first step that touched it, and as the last left it. Run it
     * inside the caller's transaction; if $fn throws, nothing is recorded.
     * A group inside a group is part of the outer one.
     */
    public function group(callable $fn, string $summary): mixed
    {
        $key = spl_object_id($this->db);
        if (isset(self::$groups[$key])) {
            return $fn();
        }
        self::$groups[$key] = [];
        try {
            $result = $fn();
            $records = self::$groups[$key];
        } finally {
            unset(self::$groups[$key]);
        }
        if ($records === []) {
            return $result;
        }
        if (count($records) === 1) {
            $r = $records[0];
            $this->record($r['userId'], $r['entity'], $r['entityId'], $r['op'], $r['beforeTables'], $r['afterTables'], $summary, $r['details'], $r['calendarIds'], $r['touches']);
            return $result;
        }
        $before = [];
        $after = [];
        $seen = [];
        $logOnly = false;
        $calendarIds = [];
        $touches = [];
        $settings = []; // each as before the first step and after the last
        foreach ($records as $r) {
            if ($r['beforeTables'] === null && $r['afterTables'] === null) {
                $logOnly = true;
            }
            foreach ((array) ($r['details']['settings'] ?? []) as $name => $pair) {
                $settings[$name] = isset($settings[$name]) ? [$settings[$name][0], $pair[1] ?? null] : $pair;
            }
            $calendarIds = array_merge($calendarIds, $r['calendarIds']);
            // What each step changed, snapshots or not: a step's own
            // touches would be lost in the merge (a row changed and changed
            // back is the same in the merged snapshots).
            $touches = array_merge($touches, self::touchesOf($r['beforeTables'], $r['afterTables']), $r['touches']);
            $b = $r['beforeTables'] ?? [];
            $a = $r['afterTables'] ?? [];
            foreach (array_unique(array_merge(array_keys($b), array_keys($a))) as $table) {
                $inBefore = [];
                foreach ($b[$table] ?? [] as $row) {
                    $inBefore[self::keyOf($table, $row)] = $row;
                }
                $inAfter = [];
                foreach ($a[$table] ?? [] as $row) {
                    $inAfter[self::keyOf($table, $row)] = $row;
                }
                foreach ($inBefore + $inAfter as $k => $_) {
                    if (!isset($seen[$table][$k])) {
                        $seen[$table][$k] = true;
                        if (isset($inBefore[$k])) {
                            $before[$table][$k] = $inBefore[$k];
                        }
                    }
                }
                foreach ($inBefore as $k => $_) {
                    if (!isset($inAfter[$k])) {
                        unset($after[$table][$k]);
                    }
                }
                foreach ($inAfter as $k => $row) {
                    $after[$table][$k] = $row;
                }
            }
        }
        $list = static fn(array $tables): array => array_map('array_values', $tables);
        $first = $records[0];
        $this->record(
            $first['userId'], $first['entity'], $first['entityId'], 'update',
            $logOnly ? null : $list($before), $logOnly ? null : $list($after),
            $summary, $settings !== [] ? ['settings' => $settings] : null, array_values(array_unique($calendarIds)), $touches
        );
        return $result;
    }

    /**
     * What hangs off these events (their tags, people and trip links), for
     * a change that removes them: the database takes those with them, and
     * undo puts them back.
     *
     * @param list<int> $eventIds
     * @return array<string,list<array>>
     */
    public static function linksOf(Db $db, array $eventIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($eventIds)), 500) as $chunk) {
            [$in, $params] = Db::in($chunk);
            foreach ([
                'event_tags' => ["event_id IN $in", $params],
                'event_people' => ["event_id IN $in", $params],
                'event_links' => ["event_id IN $in OR container_id IN $in", array_merge($params, $params)],
            ] as $table => [$where, $args]) {
                foreach ($db->all("SELECT * FROM $table WHERE $where", $args) as $row) {
                    $out[$table][self::keyOf($table, $row)] = $row;
                }
            }
        }
        return array_map('array_values', $out);
    }

    /** A row's key: "table:id", or the parts of a composite key joined by "|". */
    public static function keyOf(string $table, array $row): string
    {
        return $table . ':' . implode('|', array_map(static fn($c) => (string) ($row[$c] ?? ''), self::TABLE_PK[$table] ?? ['id']));
    }

    /**
     * What a change changed, as [row key, column] pairs: "*" for a row it
     * made or removed, each column that differs for a row in both snapshots
     * (updated_at aside), and, for a row made, removed or moved, the mark
     * it leaves on what it belongs to (PARENTS). A row the same in both was
     * only carried along: a rename doesn't touch the folders a calendar is
     * in. Pure.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function touchesOf(?array $before, ?array $after): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? []))) as $table) {
            $b = [];
            foreach ($before[$table] ?? [] as $r) {
                if (is_array($r)) $b[self::keyOf($table, $r)] = $r;
            }
            $a = [];
            foreach ($after[$table] ?? [] as $r) {
                if (is_array($r)) $a[self::keyOf($table, $r)] = $r;
            }
            foreach ($b + $a as $k => $_) {
                $was = $b[$k] ?? null;
                $now = $a[$k] ?? null;
                $changed = null; // all of it: made or removed
                if ($was !== null && $now !== null) {
                    $changed = self::fieldsChanged($table, $was, $now);
                    foreach (self::TOGETHER[$table] ?? [] as $group) {
                        if (array_intersect($changed, $group) !== []) {
                            $changed = array_values(array_unique(array_merge($changed, $group)));
                        }
                    }
                    // An event deleted (or brought back) keeps its row: still
                    // a removal, as one gone from the table is.
                    if (in_array('deleted_at', $changed, true)) {
                        $changed = null;
                    }
                }
                if ($changed === null) {
                    $out[] = [$k, '*'];
                } else {
                    foreach ($changed as $col) {
                        $out[] = [$k, $col];
                    }
                }
                foreach (self::PARENTS[$table] ?? [] as $col => [$parent, $mark]) {
                    if ($changed !== null && !in_array($col, $changed, true)) {
                        continue;
                    }
                    foreach ([$was, $now] as $row) {
                        if ($row !== null && !empty($row[$col])) {
                            $out[] = [self::keyOf($parent, ['id' => $row[$col]]), $mark];
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Columns that keep the app's own accounts, not anything a person
     * changed or would undo: when a row was last written, a calendar's sync
     * token and poll state, when an address was looked up. Never put back,
     * and never a reason an undo is stale.
     */
    private const BOOKKEEPING = ['updated_at', 'synctoken', 'last_polled_at', 'last_poll_status', 'last_poll_error',
        'content_changed_at', 'last_ok_at', 'google_sync_token', 'score', 'scored_at'];

    /**
     * Columns holding an object of separate settings (a calendar's: its
     * reminders, a plugin's station, whether its name follows them): each
     * key is a field of its own, for what a change touched and what its
     * undo puts back.
     */
    private const OBJECT_COLUMNS = ['calendars' => ['settings_json']];

    /**
     * Columns that are one field: an address and where it was found on the
     * map. A change to any of them touches all of them, and its undo puts
     * all of them back, so undoing a location doesn't leave the old place's
     * pin, nor a moved pin overwrite a newer address.
     */
    private const TOGETHER = ['events' => [['location', 'location_lat', 'location_lng', 'location_source', 'location_provider', 'location_placed_at', 'geocoded_at']]];

    /** What differs between two versions of a row: its columns, and an object column's keys as "column.key". Pure. */
    public static function fieldsChanged(string $table, array $was, array $now): array
    {
        $out = [];
        foreach (self::changedColumns($was, $now) as $col) {
            if (!in_array($col, self::OBJECT_COLUMNS[$table] ?? [], true)) {
                $out[] = $col;
                continue;
            }
            [$a, $b] = [self::object($was[$col] ?? null), self::object($now[$col] ?? null)];
            foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
                if (!array_key_exists($key, $a) || !array_key_exists($key, $b) || $a[$key] !== $b[$key]) {
                    $out[] = $col . '.' . $key;
                }
            }
        }
        return $out;
    }

    private static function object(mixed $json): array
    {
        $v = is_string($json) ? json_decode($json, true) : $json;
        return is_array($v) ? $v : [];
    }

    /** The columns that differ between two versions of a row, bookkeeping aside. Pure. */
    public static function changedColumns(array $was, array $now): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($was), array_keys($now))) as $col) {
            if (!in_array($col, self::BOOKKEEPING, true) && !self::same($was[$col] ?? null, $now[$col] ?? null)) {
                $out[] = (string) $col;
            }
        }
        return $out;
    }

    /**
     * One stored value, as two reads of it can come back: the same text
     * (the driver may answer 1 or "1"), or both NULL. Not loose equality:
     * "01" and "1", or "" and NULL, are different values. Pure.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        $text = static fn(mixed $v): string => is_bool($v) ? ($v ? '1' : '0') : (is_scalar($v) ? (string) $v : (string) json_encode($v));
        return $text($a) === $text($b);
    }

    /** @param list<array{0:string,1:string}> $touches */
    private function recordTouches(int $mutationId, array $touches): void
    {
        if ($mutationId <= 0 || $touches === []) {
            return;
        }
        $unique = [];
        foreach ($touches as [$row, $col]) {
            $unique[mb_substr($row, 0, 160) . "\n" . mb_substr($col, 0, 64)] = [mb_substr($row, 0, 160), mb_substr($col, 0, 64)];
        }
        // In batches: a calendar of thousands of events deleted at once
        // wrote a row at a time, ten times slower.
        foreach (array_chunk(array_values($unique), 200) as $chunk) {
            $params = [];
            foreach ($chunk as [$row, $col]) {
                array_push($params, $mutationId, $row, $col);
            }
            $this->db->run($this->db->insertIgnore() . ' INTO mutation_touches (mutation_id, row_key, col) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?)')), $params);
        }
    }

    /**
     * Whether a later change, not undone, changed the ground this one
     * stands on: anything that conflicts with what it touched.
     */
    private function staleFor(array $mutation): bool
    {
        $id = (int) $mutation['id'];
        // A page of what it touched at a time: a calendar of tens of
        // thousands of events deleted touched each one (#191).
        [$lastRow, $lastCol] = ['', ''];
        do {
            $page = $this->db->all(
                'SELECT row_key, col FROM mutation_touches WHERE mutation_id = ? AND (row_key > ? OR (row_key = ? AND col > ?))
                 ORDER BY row_key, col LIMIT 2000',
                [$id, $lastRow, $lastRow, $lastCol]
            );
            if ($page === []) {
                break;
            }
            [$lastRow, $lastCol] = [(string) $page[count($page) - 1]['row_key'], (string) $page[count($page) - 1]['col']];
            $cols = [];
            foreach ($page as $t) {
                // A row joined or left (an event added to a calendar): nothing
                // later takes that ground away (conflicts).
                if ((string) $t['col'] !== '+') {
                    $cols[(string) $t['row_key']][(string) $t['col']] = true;
                }
            }
            foreach (array_chunk(array_keys($cols), 200) as $rows) {
                [$in, $params] = Db::in($rows);
                $later = $this->db->all(
                    "SELECT DISTINCT t.row_key, t.col FROM mutation_touches t JOIN mutations m ON m.id = t.mutation_id
                     WHERE t.row_key IN $in AND m.user_id = ? AND m.id > ? AND m.undone = 0",
                    array_merge($params, [(int) $mutation['user_id'], $id])
                );
                foreach ($later as $t) {
                    foreach (array_keys($cols[(string) $t['row_key']] ?? []) as $mine) {
                        if (self::conflicts((string) $mine, (string) $t['col'], strstr((string) $t['row_key'], ':', true) ?: '')) {
                            return true;
                        }
                    }
                }
            }
        } while (count($page) === 2000);
        return false;
    }

    /**
     * A series' times: each date of it is found by them (an edited date by
     * its original time, a skipped one too). Undoing a time change after a
     * date was skipped, split off or edited on its own would lose track of
     * that date, so they stand on each other; a rule change and a skipped
     * date don't (each restores only itself).
     */
    private const SERIES_TIMES = ['start_utc', 'end_utc', 'tzid', 'all_day'];
    private const SERIES_DATES = ['rrule', 'exdates_json'];

    /**
     * Whether a later change that marked a row of $table $later makes
     * undoing an earlier one that marked it $mine stale. "*" is the row
     * made or removed, "+" a row joining or leaving it (PARENTS), "~" a date
     * of its series edited on its own, "col^" one-way on col (an older
     * change to col stands on it, it stands on nothing later), anything
     * else a field changed. Pure.
     * - Undoing a row's making or removal stands on everything done to it
     *   since: a calendar made, then an event added to it.
     * - A row joining or leaving something changes nothing of that thing:
     *   an event added to a calendar doesn't make its rename stale, and a
     *   calendar deleted, then its folder, still comes back (without that
     *   filing, apply).
     * - A date edited on its own stands on the series' times, and the
     *   other way round; not on its rule, the dates skipped or its title,
     *   and two dates of one series don't.
     * - Otherwise, the same field; or a series' times and its times, rule
     *   or skipped dates.
     */
    public static function conflicts(string $mine, string $later, string $table = ''): bool
    {
        if ($mine === '*') {
            return true;
        }
        if ($mine === '+' || $later === '+') {
            return false;
        }
        if ($later === '*') {
            return true;
        }
        // "col^": a change an older change to col stands on, that stands on
        // nothing later itself (a split carrying the dates skipped).
        if (str_ends_with($mine, '^')) {
            return false;
        }
        if (str_ends_with($later, '^')) {
            return $mine === substr($later, 0, -1);
        }
        $times = $table === 'events' ? self::SERIES_TIMES : [];
        $dates = $table === 'events' ? self::SERIES_DATES : [];
        if ($mine === '~' || $later === '~') {
            return in_array($mine === '~' ? $later : $mine, $times, true);
        }
        $timed = static fn(string $f) => in_array($f, $times, true);
        $dated = static fn(string $f) => in_array($f, $dates, true);
        return $mine === $later
            || ($timed($mine) && ($timed($later) || $dated($later)))
            || ($timed($later) && $dated($mine));
    }

    /**
     * The calendars a change is about: each event's, and each calendar it
     * made, removed or changed beyond what's Better-Cal's own
     * (LOCAL_CALENDAR_COLUMNS). Pure.
     *
     * @return list<int>
     */
    private static function calendarIdsOf(?array $before, ?array $after): array
    {
        $ids = [];
        $sides = [[], []];
        foreach ([$before, $after] as $i => $tables) {
            foreach ($tables['events'] ?? [] as $row) {
                if (is_array($row) && isset($row['calendar_id'])) {
                    $ids[] = (int) $row['calendar_id'];
                }
            }
            foreach ($tables['calendars'] ?? [] as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $sides[$i][(int) $row['id']] = $row;
                }
            }
        }
        [$was, $now] = $sides;
        foreach ($was + $now as $id => $_) {
            if (isset($was[$id], $now[$id]) && array_diff(self::changedColumns($was[$id], $now[$id]), self::LOCAL_CALENDAR_COLUMNS) === []) {
                continue;
            }
            $ids[] = $id;
        }
        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }

    /**
     * Whether a change made, removed or changed (beyond what's local) a
     * Google calendar, read from its own rows: one deleted is no longer
     * there to look up. Pure.
     */
    private static function googleInSnapshots(?array $before, ?array $after): bool
    {
        $ids = self::calendarIdsOf($before, $after);
        foreach ([$before, $after] as $tables) {
            foreach ($tables['calendars'] ?? [] as $row) {
                if (is_array($row) && in_array((int) ($row['id'] ?? 0), $ids, true)
                    && ($row['kind'] ?? '') === 'subscribed' && ($row['provider'] ?? '') === 'google') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The calendars an entry refers to; true when one is a Google calendar's,
     * whose entries keep no snapshots (emptied here).
     *
     * @param list<int> $explicitCalendarIds
     */
    private function recordCalendarRefs(int $mutationId, ?array $beforeTables, ?array $afterTables, array $explicitCalendarIds = []): bool
    {
        $ids = array_values(array_unique(array_filter(
            array_merge(array_map('intval', $explicitCalendarIds), self::calendarIdsOf($beforeTables, $afterTables)),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return false;
        }
        foreach ($ids as $calendarId) {
            // Ignored when already there: rows added to an entry later
            // (recordRemoved) name its calendars again.
            $this->db->run($this->db->insertIgnore() . ' INTO mutation_calendar_refs (mutation_id, calendar_id) VALUES (?, ?)', [$mutationId, $calendarId]);
        }
        [$in, $params] = Db::in($ids);
        if (self::googleInSnapshots($beforeTables, $afterTables)
            || $this->db->scalar("SELECT id FROM calendars WHERE id IN $in AND kind = 'subscribed' AND provider = 'google' LIMIT 1", $params) !== null) {
            // Google-backed edits are activity-only by design. Make
            // that permanent so adopt-back-to-local cannot revive a
            // provider-cache snapshot created after the move.
            $this->db->update('mutations', ['before_json' => null, 'after_json' => null], 'id = ?', [$mutationId]);
            $this->db->run('DELETE FROM mutation_rows WHERE mutation_id = ?', [$mutationId]);
            return true;
        }
        return false;
    }

    /**
     * Human line for the activity feed when the caller didn't provide one:
     * "Added event 'Dinner at Luna' (Aug 14)". Pure.
     */
    public static function defaultSummary(string $entity, string $op, ?array $before, ?array $after, ?string $homeTzid = null): string
    {
        $verb = ['create' => 'Added', 'update' => 'Updated', 'delete' => 'Deleted'][$op] ?? ucfirst($op);
        $noun = str_replace('_', ' ', $entity);
        $tableByEntity = ['event' => 'events', 'calendar' => 'calendars', 'folder' => 'folders',
            'filter' => 'filters', 'saved_view' => 'saved_views', 'person' => 'people', 'availability' => 'availability'];
        $table = $tableByEntity[$entity] ?? null;
        $row = null;
        foreach ([$after, $before] as $side) {
            if ($table !== null && isset($side[$table][0]) && is_array($side[$table][0])) {
                $row = $side[$table][0];
                break;
            }
        }
        $name = is_array($row) ? (string) ($row['title'] ?? $row['name'] ?? '') : '';
        $line = $verb . ' ' . $noun . ($name !== '' ? " '" . $name . "'" : '');
        if ($entity === 'event' && is_array($row) && isset($row['start_utc'])) {
            try {
                // An all-day event's date is its UTC midnight; a timed one's
                // is the day on its clock (the owner's, for a UTC-stored one).
                $start = new \DateTimeImmutable((string) $row['start_utc'], Time::utc());
                if ((int) ($row['all_day'] ?? 0) !== 1) {
                    $start = $start->setTimezone(Time::displayZone((string) ($row['tzid'] ?? 'UTC'), $homeTzid));
                }
                $line .= ' (' . $start->format('M j') . ')';
            } catch (\Exception) {
            }
        }
        return $line;
    }

    /**
     * Undo the last change the person made themselves: in the app (quick
     * add and review decisions included) or, asked with an API key,
     * through the API. Changes the app made on its own (feeds, mail,
     * plugins) are passed over. When that last change can't be undone (a
     * change sent to Google, say), that's the answer: an older one behind
     * it isn't undone instead.
     *
     * @return array{entity:string,op:string}
     */
    public function undoLatest(int $userId, ?RequestAuthority $authority = null): array
    {
        [$in, $params] = Db::in(ActivityContext::get() === 'api' ? ['api'] : self::OWN_SOURCES);
        $last = $this->db->one(
            "SELECT id, summary, before_json IS NULL AND after_json IS NULL AS log_only FROM mutations
             WHERE user_id = ? AND undone = 0 AND source IN $in ORDER BY id DESC LIMIT 1",
            array_merge([$userId], $params)
        );
        if ($last === null) {
            throw HttpError::notFound('Nothing to undo', 'nothing_to_undo');
        }
        if ((int) $last['log_only'] === 1) {
            throw HttpError::badRequest("The last change can't be undone here: " . $last['summary'], 'not_undoable');
        }
        return $this->undoById($userId, (int) $last['id'], false, $authority);
    }

    /**
     * Undo one specific activity entry (docs: 7-day window). Refuses
     * log-only rows (no snapshots, or snapshots already pruned) and, unless
     * $force, rows a later change has changed the ground of (staleFor):
     * undoing under those would silently clobber later edits.
     *
     * @return array{entity:string,op:string}
     */
    public function undoById(int $userId, int $mutationId, bool $force = false, ?RequestAuthority $authority = null): array
    {
        $mutation = $this->db->one(
            'SELECT * FROM mutations WHERE id = ? AND user_id = ?',
            [$mutationId, $userId]
        );
        if ($mutation === null) {
            throw HttpError::notFound('No such activity entry');
        }
        if ((int) $mutation['undone'] === 1) {
            throw HttpError::badRequest('Already undone', 'already_undone');
        }
        if ($mutation['before_json'] === null && $mutation['after_json'] === null) {
            throw HttpError::badRequest('This entry is log-only and cannot be undone (automated or older than the 7-day undo window)', 'not_undoable');
        }
        if ((string) $mutation['entity'] === 'plugin_edit') {
            // Sends to the plugin's source first: not inside a transaction.
            if (!$force && $this->staleFor($mutation)) {
                throw HttpError::badRequest('Newer changes exist for this item; undoing would overwrite them', 'stale_undo');
            }
            return $this->undoPluginEdit($mutation, $authority);
        }
        return $this->apply($mutation, $authority, $force);
    }

    /**
     * Undo every mutation a plugin run made, newest first. In strict mode,
     * each entry keeps the stale-undo guard: newer entries from this same run
     * have already been marked undone, while a later owner edit remains live
     * and aborts the group instead of being overwritten.
     * Strict mode lets a caller wrap the whole group in one outer transaction:
     * the first non-undoable entry aborts instead of committing a partial undo.
     *
     * Not strict, an entry the trip rules refuse (undo_conflict) is listed
     * in `refused` with its code and stays to undo; the rest go on.
     *
     * @return array{undone:int,skipped:int,refused:list<array{id:int,code:string,message:string}>}
     */
    public function undoRun(int $userId, string $runId, bool $strict = false, ?RequestAuthority $authority = null): array
    {
        $rows = $this->db->all(
            'SELECT id FROM mutations WHERE user_id = ? AND run_id = ? AND undone = 0 ORDER BY id DESC',
            [$userId, $runId]
        );
        if ($strict && $rows === []) {
            throw HttpError::badRequest(
                'This run no longer has undo records',
                'not_undoable'
            );
        }
        $undone = 0;
        $skipped = 0;
        $refused = [];
        foreach ($rows as $row) {
            try {
                $this->undoById($userId, (int) $row['id'], !$strict, $authority);
                $undone++;
            } catch (\Throwable $e) {
                // A revoked credential stops the whole run, not just one entry.
                if ($strict || ($e instanceof HttpError && in_array($e->errorCode, ['session_revoked', 'token_revoked'], true))) {
                    throw $e;
                }
                if ($e instanceof HttpError && $e->errorCode === 'undo_conflict') {
                    $refused[] = ['id' => (int) $row['id'], 'code' => $e->errorCode, 'message' => $e->getMessage()];
                    continue;
                }
                $skipped++; // log-only entries and already-undone rows
            }
        }
        return ['undone' => $undone, 'skipped' => $skipped, 'refused' => $refused];
    }

    /**
     * Undo a time change a plugin wrote back to its source (Events'
     * plugin write-through): send the earlier time to the source the same
     * way, then store what it confirms. No local snapshot to restore: the
     * row is the source's.
     *
     * @return array{entity:string,op:string}
     */
    private function undoPluginEdit(array $mutation, ?RequestAuthority $authority = null): array
    {
        $details = json_decode((string) ($mutation['details_json'] ?? ''), true);
        $span = is_array($details) ? ($details['span'] ?? null) : null;
        $pluginId = is_array($details) ? (string) ($details['pluginId'] ?? '') : '';
        $userId = (int) $mutation['user_id'];
        $event = $this->db->one('SELECT * FROM events WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [(int) $mutation['entity_id'], $userId]);
        if (!is_array($span) || $pluginId === '' || $event === null) {
            throw HttpError::badRequest('That event is no longer here to move back', 'not_undoable');
        }
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ?', [(int) $event['calendar_id']]);
        $plugins = $this->plugins ?? new Plugins($this->db, null, config());
        if ($calendar === null || (string) ($calendar['plugin_id'] ?? '') !== $pluginId || !$plugins->calendarReschedulable($calendar)) {
            throw HttpError::badRequest('That event can no longer be moved back from here', 'not_undoable');
        }
        $prefix = 'plg-' . $pluginId . '-';
        $uid = (string) $event['uid'];
        // As for the move itself: the credential is checked just before the
        // source is changed and again when the result is saved here.
        $authority?->assertValid($this->db, false);
        $result = $plugins->applyEdit($userId, $pluginId, [
            'sourceKey' => str_starts_with($uid, $prefix) ? substr($uid, strlen($prefix)) : $uid,
            'eventId' => (int) $event['id'],
            'calendarId' => (int) $event['calendar_id'],
            'title' => (string) $event['title'],
            'before' => Events::pluginSpan((string) $event['start_utc'], (string) $event['end_utc'], (int) $event['all_day'] === 1),
            'after' => $span,
        ]);
        // A time the plugin confirms with no offset is the owner's local
        // time (the Home zone in Settings; UTC until one is set), read as
        // the move itself reads it (Events::pluginReschedule).
        [$startUtc, $endUtc, $allDay] = \BetterCal\Plugin\PluginHost::spanToUtc($result, Time::zone(Settings::homeTzid($this->db, $userId)));
        $this->write($userId, function () use ($event, $startUtc, $endUtc, $allDay, $mutation, $authority): void {
            $authority?->assertValid($this->db);
            $this->db->update('events', [
                'start_utc' => $startUtc,
                'end_utc' => $endUtc,
                'all_day' => $allDay ? 1 : 0,
                'tzid' => 'UTC',
                'updated_at' => \BetterCal\Support\Time::nowDb(),
            ], 'id = ?', [(int) $event['id']]);
            ChangeLog::record($this->db, (int) $event['calendar_id'], (string) $event['uid'], ChangeLog::OP_MODIFY);
            $this->db->update('mutations', ['undone' => 1], 'id = ?', [(int) $mutation['id']]);
        });
        return ['entity' => 'event', 'op' => 'update'];
    }

    /** What an event's time is, for a change that keeps no snapshot of it (a plugin's, Google's). */
    public static function timeTouches(int $eventId): array
    {
        return array_map(static fn(string $col) => [self::keyOf('events', ['id' => $eventId]), $col], ['start_utc', 'end_utc', 'all_day', 'tzid']);
    }

    private function apply(array $mutation, ?RequestAuthority $authority = null, bool $force = false): array
    {
        $before = self::tables($mutation['before_json']);
        $after = self::tables($mutation['after_json']);
        $calendarIds = self::calendarIdsOf($before, $after);
        $mutationId = (int) $mutation['id'];
        // The tables it keeps rows of in pieces (recordRemoved), by side:
        // those rows are read a piece at a time, never all at once.
        $pieces = [];

        // Run afresh if the database breaks a deadlock by rolling this back:
        // a change waiting on a row this holds while holding one this needs.
        $this->db->txRetrying(function () use ($mutation, $mutationId, $before, $after, $calendarIds, $authority, $force, &$pieces): void {
            // The account first, as every change takes it (write()); then the
            // credential that asked for the undo must still be valid as it's saved.
            $this->lockAccount((int) $mutation['user_id']);
            $authority?->assertValid($this->db);
            // The calendars it named when recorded, as well as those its rows
            // show: a trip link's, or one deleted since.
            foreach ($this->db->all('SELECT calendar_id FROM mutation_calendar_refs WHERE mutation_id = ?', [$mutation['id']]) as $ref) {
                $calendarIds[] = (int) $ref['calendar_id'];
            }
            CalendarMoveGuard::lockAndAssertMutable($this->db, $calendarIds);
            if (self::googleInSnapshots($before, $after)) {
                throw HttpError::badRequest('Google calendar changes cannot be undone here', 'not_undoable');
            }
            foreach (array_values(array_unique($calendarIds)) as $calendarId) {
                $calendar = CalendarMoveGuard::lockCalendar($this->db, $calendarId);
                if ($calendar !== null && (string) $calendar['kind'] === 'subscribed'
                    && (string) ($calendar['provider'] ?? '') === 'google') {
                    // Google-backed rows are a provider cache. Restoring any
                    // old full-row snapshot locally would be overwritten on
                    // poll and can resurrect the pre-move source/provider
                    // state, including cross-calendar trip snapshots that a
                    // cutover could not find by mutation.entity_id alone.
                    throw HttpError::badRequest('Google calendar changes cannot be undone here', 'not_undoable');
                }
            }
            // GoogleMove finalization clears undo snapshots under the same
            // calendar lock. Re-read after acquiring it so an undo request
            // that began just before cutover cannot apply its stale copy to
            // the newly Google-backed cache.
            $current = $this->db->one(
                'SELECT before_json, after_json, undone FROM mutations WHERE id = ?',
                [$mutation['id']]
            );
            if ($current === null || (int) $current['undone'] === 1
                || ($current['before_json'] === null && $current['after_json'] === null)
                || $current['before_json'] !== $mutation['before_json']
                || $current['after_json'] !== $mutation['after_json']) {
                throw HttpError::badRequest('This entry can no longer be undone', 'not_undoable');
            }
            // The rows it puts back, held until it's done (after the same
            // locks as any other change takes, in the same order), then the
            // check: a change committed meanwhile is seen, and one that
            // comes after waits for this.
            $this->lockRows($before, $after);
            // The events it touches, as rows or as the ends of a link: what
            // the trip rules are checked over once it's done.
            $eventIds = array_merge(self::eventIdsOf($before), self::eventIdsOf($after));
            $pieces = $this->pieceTables($mutationId);
            foreach ($pieces as $side => $tables) {
                foreach ($tables as $table) {
                    foreach ($this->piecesOf($mutationId, $side, $table) as $rows) {
                        $this->lockRows([$table => $rows], []);
                        $eventIds = array_merge($eventIds, self::eventIdsOf([$table => $rows]));
                    }
                }
            }
            if (!$force && $this->staleFor($mutation)) {
                throw HttpError::badRequest('Newer changes exist for this item; undoing would overwrite them', 'stale_undo');
            }
            $flaggedBefore = Trips::flaggedAmong($this->db, $eventIds);
            // Delete rows created by the mutation (present in after, absent from before).
            foreach (array_reverse(self::TABLE_ORDER) as $table) {
                $beforeKeys = [];
                foreach ($before[$table] ?? [] as $row) {
                    $beforeKeys[self::keyOf($table, $row)] = true;
                }
                foreach ($after[$table] ?? [] as $row) {
                    if (!isset($beforeKeys[self::keyOf($table, $row)])) {
                        $this->deleteByPk($table, $row);
                    }
                }
                // Rows it made kept in pieces: each one made, none in before.
                if (in_array($table, $pieces['after'] ?? [], true)) {
                    foreach ($this->piecesOf($mutationId, 'after', $table) as $rows) {
                        if ((self::TABLE_PK[$table] ?? ['id']) === ['id']) {
                            foreach (array_chunk(array_column($rows, 'id'), 500) as $ids) {
                                [$in, $params] = Db::in($ids);
                                $this->db->run("DELETE FROM `$table` WHERE `id` IN $in", $params);
                            }
                            continue;
                        }
                        foreach ($rows as $row) {
                            $this->deleteByPk($table, $row);
                        }
                    }
                }
            }
            // Restore what it changed: rows it removed come back whole, and a
            // row it kept gets back only the columns it changed. A row only
            // carried along is left as it is now.
            $afterByKey = [];
            foreach ($after as $table => $rows) {
                foreach ($rows as $row) {
                    $afterByKey[$table][self::keyOf($table, $row)] = $row;
                }
            }
            // Rows it removed and puts back whole: a calendar deleted, a folder.
            $whole = [];
            foreach ($before as $table => $rows) {
                foreach ($rows as $row) {
                    if (!isset($afterByKey[$table][self::keyOf($table, $row)])) {
                        $whole[self::keyOf($table, $row)] = true;
                    }
                }
            }
            // Edited days it moved onto each other's old days, put back one
            // at a time, would meet on the unique key (calendar, uid,
            // occurrence) while the other still holds it: each one whose key
            // goes back leaves its key first and takes its own as restored.
            $parked = self::rekeyedEvents($before['events'] ?? [], $afterByKey['events'] ?? []);
            Events::parkInstanceKeys($this->db, array_keys($parked));
            foreach (self::TABLE_ORDER as $table) {
                foreach ($before[$table] ?? [] as $row) {
                    $kept = $afterByKey[$table][self::keyOf($table, $row)] ?? null;
                    $changed = null;
                    if ($kept !== null) {
                        $changed = self::changedColumns($row, $kept);
                        if ($changed === []) {
                            continue;
                        }
                        if ($table === 'events' && isset($parked[(int) $row['id']]) && !in_array('recurrence_instance_utc', $changed, true)) {
                            $changed[] = 'recurrence_instance_utc';
                        }
                        foreach (self::TOGETHER[$table] ?? [] as $group) {
                            if (array_intersect($changed, $group) !== []) {
                                $changed = array_values(array_unique(array_merge($changed, array_intersect($group, array_keys($row)))));
                            }
                        }
                        // An object column: just the keys it changed, into what's there now.
                        foreach (array_intersect(self::OBJECT_COLUMNS[$table] ?? [], $changed) as $col) {
                            $now = $this->db->scalar("SELECT `$col` FROM `$table` WHERE " . implode(' AND ', array_map(static fn($c) => "`$c` = ?", self::TABLE_PK[$table])),
                                array_map(static fn($c) => $row[$c] ?? null, self::TABLE_PK[$table]));
                            $merged = self::object($now);
                            [$was, $set] = [self::object($row[$col] ?? null), self::object($kept[$col] ?? null)];
                            foreach (array_unique(array_merge(array_keys($was), array_keys($set))) as $key) {
                                if (array_key_exists($key, $was) === array_key_exists($key, $set) && ($was[$key] ?? null) === ($set[$key] ?? null)) {
                                    continue;
                                }
                                if (array_key_exists($key, $was)) {
                                    $merged[$key] = $was[$key];
                                } else {
                                    unset($merged[$key]);
                                }
                            }
                            $row[$col] = $merged === [] ? null : json_encode($merged);
                        }
                    }
                    if ($table === 'calendars') {
                        $live = $this->db->one('SELECT * FROM calendars WHERE id = ?', [(int) $row['id']]);
                        if ($live !== null) {
                            foreach (['subscription_authority', 'created_by_token_id', 'created_by_session_hash'] as $key) {
                                if (array_key_exists($key, $live)) $row[$key] = $live[$key];
                            }
                            if (($row['kind'] ?? '') === 'subscribed' && (($live['kind'] ?? '') !== 'subscribed' || ($row['source_url'] ?? null) !== ($live['source_url'] ?? null))) {
                                throw HttpError::badRequest('Subscription source changes cannot be restored from an older undo snapshot. Review this calendar instead.', 'not_undoable');
                            }
                        } elseif (($row['kind'] ?? '') === 'subscribed') {
                            if (!empty($row['created_by_session_hash'])) $row['subscription_authority'] = 'session_review';
                            if (!empty($row['created_by_token_id']) && !ApiTokens::stillValid($this->db, (int) $row['created_by_token_id'])) $row['created_by_token_id'] = null;
                        }
                    } elseif ($table === 'out_feeds') {
                        $live = $this->db->one('SELECT * FROM out_feeds WHERE id = ?', [(int) $row['id']]);
                        if ($live === null) {
                            throw HttpError::badRequest('A deleted public feed cannot be restored with an old URL. Create a new feed instead.', 'not_undoable');
                        }
                        foreach (['token', 'token_sealed', 'created_by_token_id'] as $key) {
                            if (array_key_exists($key, $live)) $row[$key] = $live[$key];
                        }
                    }
                    if (!$this->restoreRow($table, $row, $changed) && !self::carriedLink($table, $row, $whole)) {
                        throw HttpError::badRequest("Something this change belonged to is gone since, so it can't be put back", 'not_undoable');
                    }
                }
                // Rows it removed kept in pieces: each one back whole. A
                // table's rows come back before what belongs to them
                // (TABLE_ORDER), so a link finds its event already whole.
                if (in_array($table, $pieces['before'] ?? [], true)) {
                    foreach ($this->piecesOf($mutationId, 'before', $table) as $rows) {
                        $this->restoreWhole($table, $rows, $whole);
                    }
                }
            }
            // Settings it changed on the way (where new events go, for a
            // calendar deleted), as they were: each one still as it left it.
            $details = json_decode((string) ($mutation['details_json'] ?? ''), true);
            if (is_array($details) && is_array($details['settings'] ?? null)) {
                Settings::restore($this->db, (int) $mutation['user_id'], $details['settings']);
            }
            // What it put back passes the trip rules as it now stands, under
            // force too: a refusal throws, rolling all of it back, and the
            // entry stays to undo once the conflict is gone.
            Trips::assertRestored($this->db, $eventIds, $flaggedBefore);
            $this->db->run('UPDATE mutations SET undone = 1 WHERE id = ?', [$mutation['id']]);
        });

        // CalDAV journal: an undone event mutation must reach DAV clients too.
        // Recording MODIFY is enough — sync clients drop uris that 404.
        // Each uid once per calendar, and a calendar's all at once: one at a
        // time, a large calendar's took half a minute.
        $uids = [];
        $note = static function (array $rows) use (&$uids): void {
            foreach ($rows as $row) {
                if (isset($row['calendar_id'], $row['uid'])) {
                    $uids[(int) $row['calendar_id']][(string) $row['uid']] = true;
                }
            }
        };
        $note($before['events'] ?? []);
        $note($after['events'] ?? []);
        foreach ($pieces as $side => $tables) {
            if (in_array('events', $tables, true)) {
                foreach ($this->piecesOf($mutationId, $side, 'events') as $rows) {
                    $note($rows);
                }
            }
        }
        foreach ($uids as $calendarId => $ofCalendar) {
            ChangeLog::recordMany($this->db, (int) $calendarId, array_map('strval', array_keys($ofCalendar)), ChangeLog::OP_MODIFY);
        }

        return ['entity' => (string) $mutation['entity'], 'op' => (string) $mutation['op']];
    }

    /**
     * Rows a change removed, back whole: a couple of hundred to a statement
     * (one each, a large calendar's events took most of a minute), and one
     * at a time for a batch that can't all go back, where a link whose
     * other end is gone since is left out as restoreRow leaves it. Each
     * row's key goes into $whole for what belongs to it (carriedLink).
     *
     * @param list<array> $rows
     * @param array<string,true> $whole
     */
    private function restoreWhole(string $table, array $rows, array &$whole): void
    {
        $isParent = false;
        foreach (self::PARENTS as $cols) {
            foreach ($cols as [$parent]) {
                $isParent = $isParent || $parent === $table;
            }
        }
        $batches = [];
        foreach ($rows as $row) {
            if ($isParent) {
                $whole[self::keyOf($table, $row)] = true;
            }
            $batches[implode(',', array_keys($row))][] = $row;
        }
        foreach ($batches as $same) {
            foreach (array_chunk($same, 200) as $batch) {
                try {
                    $this->db->upsertMany($table, $batch);
                    continue;
                } catch (\PDOException $e) {
                    if (!Db::isForeignKeyViolation($e)) {
                        throw $e;
                    }
                }
                foreach ($batch as $row) {
                    if (!$this->restoreRow($table, $row, null) && !self::carriedLink($table, $row, $whole)) {
                        throw HttpError::badRequest("Something this change belonged to is gone since, so it can't be put back", 'not_undoable');
                    }
                }
            }
        }
    }

    /**
     * Whether a link that can't go back (what it links to is gone since)
     * was only carried along with something this undo brings back whole:
     * a calendar deleted, then the folder it was in, comes back without
     * that filing. A link that was the change itself (a calendar moved out
     * of a folder deleted since) can't be undone without it. Pure.
     *
     * @param array<string,true> $whole
     */
    private static function carriedLink(string $table, array $row, array $whole): bool
    {
        if (!in_array($table, self::LINK_TABLES, true)) {
            return false;
        }
        foreach (self::PARENTS[$table] ?? [] as $col => [$parent]) {
            if (!empty($row[$col]) && isset($whole[self::keyOf($parent, ['id' => $row[$col]])])) {
                return true;
            }
        }
        return false;
    }

    /**
     * The edited rows (an occurrence key before) that this change kept and
     * gave another calendar, uid or occurrence: what an undo puts back on
     * a key another row may hold until it is put back too. Pure.
     *
     * @param list<array> $before
     * @param array<string,array> $afterByKey
     * @return array<int,true> by id
     */
    private static function rekeyedEvents(array $before, array $afterByKey): array
    {
        $out = [];
        foreach ($before as $row) {
            $kept = $afterByKey[self::keyOf('events', $row)] ?? null;
            if ($kept === null || ($row['recurrence_instance_utc'] ?? null) === null) {
                continue;
            }
            foreach (['calendar_id', 'uid', 'recurrence_instance_utc'] as $col) {
                if (!self::same($row[$col] ?? null, $kept[$col] ?? null)) {
                    $out[(int) $row['id']] = true;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * The event ids in snapshot tables: each event row's, and both ends of
     * each trip link. Pure.
     *
     * @param array<string,list<array>> $tables
     * @return list<int>
     */
    private static function eventIdsOf(array $tables): array
    {
        $ids = [];
        foreach ($tables['events'] ?? [] as $row) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
        foreach ($tables['event_links'] ?? [] as $row) {
            $ids[] = (int) ($row['container_id'] ?? 0);
            $ids[] = (int) ($row['event_id'] ?? 0);
        }
        return array_values(array_filter(array_unique($ids)));
    }

    private static function tables(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) && isset($decoded['tables']) && is_array($decoded['tables']) ? $decoded['tables'] : [];
    }

    /**
     * Lock the rows an undo puts back, so a change to them waits for it: a
     * few queries per table, by the first column of its key (a calendar's
     * filings, all of an event's tags). Locks only on MySQL (forUpdate);
     * SQLite's transactions hold the whole database already.
     */
    private function lockRows(array $before, array $after): void
    {
        if ($this->db->forUpdate() === '') {
            return;
        }
        foreach (self::TABLE_ORDER as $table) {
            $col = (self::TABLE_PK[$table] ?? ['id'])[0];
            $values = [];
            foreach ([$before[$table] ?? [], $after[$table] ?? []] as $rows) {
                foreach ($rows as $row) {
                    if (isset($row[$col])) {
                        $values[(string) $row[$col]] = $row[$col];
                    }
                }
            }
            foreach (array_chunk(array_values($values), 500) as $chunk) {
                [$in, $params] = Db::in($chunk);
                $this->db->all("SELECT 1 FROM `$table` WHERE `$col` IN $in" . $this->db->forUpdate(), $params);
            }
        }
    }

    /**
     * Put a row back: whole for a row the change removed ($changed null),
     * else just the columns it changed. False when it can't: something it
     * belongs to is gone since (a deleted folder, a calendar), or the row
     * it changed is.
     *
     * @param list<string>|null $changed
     */
    private function restoreRow(string $table, array $row, ?array $changed): bool
    {
        $conds = [];
        $params = [];
        foreach (self::TABLE_PK[$table] ?? ['id'] as $col) {
            $conds[] = "`$col` = ?";
            $params[] = $row[$col] ?? null;
        }
        $where = implode(' AND ', $conds);
        try {
            if ($changed !== null) {
                // A row it kept and changed, gone since: what it changed has
                // nowhere to go back to (it isn't brought back for that).
                if ($this->db->scalar("SELECT 1 FROM `$table` WHERE $where", $params) === null) {
                    return false;
                }
                $sets = implode(', ', array_map(static fn($c) => "`$c` = ?", $changed));
                $this->db->run("UPDATE `$table` SET $sets WHERE $where", array_merge(array_map(static fn($c) => $row[$c] ?? null, $changed), $params));
                return true;
            }
            $this->db->upsert($table, $row);
            return true;
        } catch (\PDOException $e) {
            if (Db::isForeignKeyViolation($e)) {
                return false;
            }
            throw $e;
        }
    }

    private function deleteByPk(string $table, array $row): void
    {
        if (!in_array($table, self::TABLE_ORDER, true)) {
            return;
        }
        $conds = [];
        $params = [];
        foreach (self::TABLE_PK[$table] ?? ['id'] as $col) {
            $conds[] = "`$col` = ?";
            $params[] = $row[$col] ?? null;
        }
        $this->db->run("DELETE FROM `$table` WHERE " . implode(' AND ', $conds), $params);
    }
}
