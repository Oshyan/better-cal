<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Domain\Series\OwnFields;
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
    /**
     * Restore order respects FK dependencies; deletions run in reverse. The
     * tables of what refers to an event (OwnFields::REFERENCES) come right
     * after events (order()).
     */
    private const TABLE_ORDER = [
        'folders', 'tags', 'people', 'calendars', 'calendar_folders',
        'events', 'event_tags', 'event_people', 'out_feeds', 'filters', 'saved_views',
        'availability', 'review_items',
    ];

    private const TABLE_PK = [
        'folders' => ['id'], 'tags' => ['id'], 'people' => ['id'], 'calendars' => ['id'],
        'events' => ['id'], 'out_feeds' => ['id'], 'filters' => ['id'], 'saved_views' => ['id'],
        'availability' => ['id'], 'review_items' => ['id'],
        'calendar_folders' => ['calendar_id', 'folder_id'],
        'event_tags' => ['event_id', 'tag_id'],
        'event_people' => ['event_id', 'person_id'],
    ];

    /**
     * What a row belongs to, by column: the parent's table, and the mark a
     * row joining or leaving it leaves on the parent. "+" is membership (an
     * event added to a calendar, a calendar filed in a folder). A day of a
     * series is no row of its own here: its ground is on its series' row
     * (touchesOf, dayGround).
     */
    private const PARENTS = [
        'events' => ['calendar_id' => ['calendars', '+']],
        'calendar_folders' => ['calendar_id' => ['calendars', '+'], 'folder_id' => ['folders', '+']],
        'event_tags' => ['event_id' => ['events', '+'], 'tag_id' => ['tags', '+']],
        'event_people' => ['event_id' => ['events', '+'], 'person_id' => ['people', '+']],
        'availability' => ['person_id' => ['people', '+']],
    ];

    /**
     * Rows that only link two others: put back only while both are still
     * there. What refers to an event is one too (isLink()).
     */
    private const LINK_TABLES = ['calendar_folders', 'event_tags', 'event_people'];

    /**
     * Tables whose rows one change can make or remove by the tens of
     * thousands (a calendar deleted, a file imported): those rows can be
     * kept in pieces apart from the entry (mutation_rows), as one row of the
     * journal they were more than the database takes in one statement
     * (#191). Rows a change kept and changed stay in the entry.
     */
    private const PIECE_TABLES = ['events', 'event_tags', 'event_people'];

    /**
     * The tables of what refers to an event, from the one table of them
     * (OwnFields::REFERENCES): [table => [its key, the columns naming
     * events, the condition a row meets]].
     *
     * @return array<string,array{0:list<string>,1:list<string>,2:?string}>
     */
    private static function references(): array
    {
        $out = [];
        foreach (OwnFields::REFERENCES as [$table, , $eventCols, $condition, $key]) {
            $out[$table] = [$key, $eventCols, $condition];
        }
        return $out;
    }

    /** Every table an undo puts back, in restore order: the references right after events. */
    private static function order(): array
    {
        $at = array_search('events', self::TABLE_ORDER, true) + 1;
        return [...array_slice(self::TABLE_ORDER, 0, $at), ...array_keys(self::references()), ...array_slice(self::TABLE_ORDER, $at)];
    }

    /** A table's key columns. */
    private static function pk(string $table): array
    {
        return self::TABLE_PK[$table] ?? self::references()[$table][0] ?? ['id'];
    }

    /** What a row of $table belongs to, by column (PARENTS): a reference belongs to each event it names. */
    private static function parentsOf(string $table): array
    {
        if (isset(self::PARENTS[$table])) {
            return self::PARENTS[$table];
        }
        return array_fill_keys(self::references()[$table][1] ?? [], ['events', '+']);
    }

    /** Whether rows of $table only link others (LINK_TABLES, and every reference). */
    private static function isLink(string $table): bool
    {
        return in_array($table, self::LINK_TABLES, true) || isset(self::references()[$table]);
    }

    /** The tables whose rows can be kept in pieces (PIECE_TABLES, and every reference). */
    private static function pieceTableNames(): array
    {
        return [...self::PIECE_TABLES, ...array_keys(self::references())];
    }

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
     * its place in the list (whether it shows is a preference, PREFERENCES).
     * A Google calendar's change to only these is undoable like any other.
     */
    private const LOCAL_CALENDAR_COLUMNS = ['position'];

    /**
     * Columns that say how the account is shown, not what it holds: display
     * preferences kept on the server, as the settings are. Activity records
     * none of them, undo never puts one back, and none makes an undo stale.
     * A whole row brought back (a calendar deleted) comes back as it was.
     */
    private const PREFERENCES = ['calendars' => ['visible']];

    /**
     * Where a person's own changes come from, for undoing the last one
     * (undoLatest): the app, quick add, review decisions, invitation
     * answers and duplicate decisions.
     */
    private const OWN_SOURCES = ['web', 'quickadd', 'review', 'rsvp', 'dedup:owner'];

    /**
     * $plugins: the plugin registry undo uses for plugin edits; $google: the
     * writer that sends a Google edit's undo (tests pass stand-ins for both).
     */
    public function __construct(private readonly Db $db, private readonly ?Plugins $plugins = null, private readonly ?GoogleWriter $google = null)
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
            // The request's credential (RequestAuthority::during), checked
            // for every write made under a request; a worker has none.
            RequestAuthority::current()?->assertValid($this->db);
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
    /**
     * A stored state no writer here leaves, met by a reader (a day row with
     * no own list, OwnFields::stored): reported the way a journaling misuse
     * is, a failure in the suites and a log line in production.
     */
    public static function defect(string $what): void
    {
        self::misuse($what);
    }

    private static function misuse(string $what): void
    {
        if (getenv('BETTERCAL_STRICT_JOURNAL') !== '1') {
            error_log('undo: ' . $what);
            return;
        }
        $at = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file !== '' && $file !== __FILE__ && !str_ends_with($file, '/Infra/Db.php') && !str_ends_with($file, '/ActivityContext.php') && !str_ends_with($file, '/RequestAuthority.php')) {
                $at[] = basename($file) . ':' . ($frame['line'] ?? 0);
                if (count($at) === 4) {
                    break;
                }
            }
        }
        self::$misuses[$what . ' at ' . implode(' < ', $at)] = true;
    }

    /**
     * A write to a table that is not bookkeeping (RequestAuthority::BOOKKEEPING),
     * made under a request's credential outside a transaction in which it
     * was checked: a change the credential's revocation wouldn't stop.
     * Reported like any journaling mistake; the write itself still goes through.
     */
    public static function uncheckedWrite(string $verb, string $table): void
    {
        self::misuse('write (' . $verb . ' ' . $table . ') under a request credential not checked in its transaction');
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
            'format' => self::JOURNAL_FORMAT,
        ]);
        ActivityContext::noteEntry($mutationId);
        // Before the refs: an entry of a change to what Google holds has
        // its snapshots emptied there, and what it changed has to stay known.
        // Its ground: what it changed, and, an entry whose undo puts its
        // snapshots back, what that restore puts back besides; an entry
        // sent to Google is undone by a planned write (GoogleUndo), which
        // carries the days, and stands on what it changed alone.
        $this->recordTouches($mutationId, array_merge(self::changeGround($beforeTables, $afterTables, $touches),
            GoogleUndo::carries($details) ? [] : self::restoredGround($beforeTables, $afterTables)));
        // A write to Google that undo can send back keeps what to send in
        // its details (GoogleUndo): no snapshot for one resource, the rows'
        // own snapshots for a whole series, kept as any change keeps them.
        if (!$this->recordCalendarRefs($mutationId, $beforeTables, $afterTables, $calendarIds, GoogleUndo::carries($details))) {
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
            if (!in_array($table, self::pieceTableNames(), true)) {
                // Kept in a piece, they would never be put back.
                throw new \LogicException("Undo::recordRemoved can't keep $table rows, only " . implode(', ', self::pieceTableNames()));
            }
        }
        $this->recordTouches($mutationId, self::touchesOf($tables, null));
        if ($this->recordCalendarRefs($mutationId, $tables, null)
            || (int) $this->db->scalar('SELECT before_json IS NULL AND after_json IS NULL FROM mutations WHERE id = ?', [$mutationId]) === 1) {
            return; // log-only: it removes rows Google holds
        }
        $this->writePieces($mutationId, 'before', $tables);
    }

    /**
     * The events $where finds, with what hangs off them (linksOf),
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
            $tables = self::linksOf($this->db, array_map(static fn(array $e): int => (int) $e['id'], $page));
            // A trip link or a duplicate pair between two of them is found from both pages.
            foreach ($tables as $table => $rows) {
                $fresh = [];
                foreach ($rows as $row) {
                    if (!isset($links[self::keyOf($table, $row)])) {
                        $links[self::keyOf($table, $row)] = true;
                        $fresh[] = $row;
                    }
                }
                $tables[$table] = $fresh;
            }
            $this->recordRemoved($mutationId, array_filter(['events' => $page] + $tables));
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
        foreach (self::pieceTableNames() as $table) {
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
        // A whole-series change sent to Google keeps the rows' snapshots
        // beside what to send back (GoogleUndo, kind series): one such step
        // keeps its details, the other steps' snapshots merged into its, so
        // an answer from a day on (a split, then the answer here) undoes as
        // one. Any other Google write's undo is in its own details alone,
        // which a merge drops.
        $series = array_values(array_filter($records, static fn(array $r): bool
            => GoogleUndo::carries($r['details']) && ($r['details']['google']['kind'] ?? null) === GoogleUndo::SERIES));
        $kept = count($series) === 1 ? $series[0] : null;
        foreach ($records as $r) {
            if (($r['beforeTables'] === null && $r['afterTables'] === null) || (GoogleUndo::carries($r['details']) && $r !== $kept)) {
                $logOnly = true;
            }
            foreach ((array) ($r['details']['settings'] ?? []) as $name => $pair) {
                $settings[$name] = isset($settings[$name]) ? [$settings[$name][0], $pair[1] ?? null] : $pair;
            }
            $calendarIds = array_merge($calendarIds, $r['calendarIds']);
            // What each step changed, snapshots or not: a step's own
            // touches would be lost in the merge (a row changed and changed
            // back is the same in the merged snapshots).
            $touches = array_merge($touches, self::touchesOf($r['beforeTables'], $r['afterTables'], $r['touches']),
                GoogleUndo::carries($r['details']) ? [] : self::restoredGround($r['beforeTables'], $r['afterTables']), $r['touches']);
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
        $details = ($kept !== null && !$logOnly ? ['google' => $kept['details']['google']] : []) + ($settings !== [] ? ['settings' => $settings] : []);
        $this->record(
            $first['userId'], $first['entity'], $first['entityId'], 'update',
            $logOnly ? null : $list($before), $logOnly ? null : $list($after),
            $summary, $details !== [] ? $details : null, array_values(array_unique($calendarIds)), $touches
        );
        return $result;
    }

    /**
     * What hangs off these events, for a change that removes them: their
     * tags and people, and whatever refers to them by the one table of
     * that (OwnFields::REFERENCES: trip links, duplicate decisions, plugin
     * data). The database takes those with them, and undo puts them back.
     *
     * @param list<int> $eventIds
     * @return array<string,list<array>>
     */
    public static function linksOf(Db $db, array $eventIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($eventIds)), 500) as $chunk) {
            [$in, $params] = Db::in($chunk);
            $tables = [
                'event_tags' => ["event_id IN $in", $params],
                'event_people' => ["event_id IN $in", $params],
            ];
            foreach (self::references() as $table => [, $eventCols, $condition]) {
                $tables[$table] = ['(' . implode(' OR ', array_map(static fn(string $c): string => "`$c` IN $in", $eventCols)) . ')' . ($condition === null ? '' : " AND $condition"),
                    array_merge(...array_fill(0, count($eventCols), $params))];
            }
            foreach ($tables as $table => [$where, $args]) {
                foreach ($db->all("SELECT * FROM `$table` WHERE $where", $args) as $row) {
                    $out[$table][self::keyOf($table, $row)] = $row;
                }
            }
        }
        return array_map('array_values', $out);
    }

    /**
     * Events a change removes, as an entry keeps them to put back: the rows
     * whole and what hangs off them (linksOf). The one definition of what a
     * removed event takes with it, for a sync's removal and a Google
     * write's (GoogleUndo), so a column or relation added later is kept by
     * every one of them.
     *
     * @param list<array> $rows events rows as stored
     * @return array<string,list<array>>
     */
    public static function removedRows(Db $db, array $rows): array
    {
        return ['events' => array_values($rows)] + self::linksOf($db, array_map(static fn(array $r): int => (int) $r['id'], $rows));
    }

    /** A row's key: "table:id", or the parts of a composite key joined by "|". */
    public static function keyOf(string $table, array $row): string
    {
        return $table . ':' . implode('|', array_map(static fn($c) => (string) ($row[$c] ?? ''), self::pk($table)));
    }

    /**
     * What a change changed, its ground, as [row key, field] pairs (the
     * one definition of staleness, decisions.md): "*" for a row it made or
     * removed, each column that differs for a row in both snapshots
     * (updated_at aside), and, for a row made, removed or moved, the mark
     * it leaves on what it belongs to (PARENTS). A row the same in both was
     * only carried along: a rename doesn't touch the folders a calendar is
     * in. A day of a series is its series and its occurrence, whichever row
     * holds it: its ground is on its series' row, one field of that day at a
     * time (dayGround), for the fields of its own it gained, lost or changed
     * the value of, a row of it made or removed whole touching the fields it
     * owns (tags and people among them, which their links touch too). A day
     * carried to another occurrence with its own part as it was touches only
     * its move (MOVE), which a later judgement follows. A skip is its own
     * date's (SKIP); a skip a change carried to another occurrence is the
     * move its planner recorded ($recorded: the caller's own grounds, whose
     * MOVE entries say which skip went where, Series\Model's skipMoves),
     * never one paired up here. The days a series change brought into
     * being are its planner's record (addedGrounds), never read here. The own
     * list and the skips are never grounds themselves: their contents are.
     * This is a planned change's ground and a poll's; a snapshot entry adds
     * what its restore puts back besides (restoredGround). Pure.
     *
     * @param list<array{0:string,1:string}> $recorded
     * @return list<array{0:string,1:string}>
     */
    public static function touchesOf(?array $before, ?array $after, array $recorded = []): array
    {
        $carried = [];
        foreach ($recorded as [$rowKey, $col]) {
            if (str_starts_with((string) $col, self::MOVE)) {
                $carried[(string) $rowKey][] = (string) $col;
            }
        }
        $out = [];
        // Each day's row in either snapshot: its series' key and its occurrence (the later side's).
        $days = [];
        foreach ([$before['events'] ?? [], $after['events'] ?? []] as $rows) {
            foreach ($rows as $r) {
                if (is_array($r) && isset($r['id']) && ($r['recurrence_instance_utc'] ?? null) !== null && !empty($r['recurrence_parent_id'])) {
                    $days[(int) $r['id']] = [self::keyOf('events', ['id' => $r['recurrence_parent_id']]), (string) $r['recurrence_instance_utc']];
                }
            }
        }
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
                if ($table === 'events' && isset($days[(int) (($now ?? $was)['id'] ?? 0)])) {
                    $out = array_merge($out, self::dayTouches($was, $now));
                    continue;
                }
                // A tag or a person on a day is a field of that day alone (its ground below): never a row of its own,
                // so it is followed with its day through a move as every field of the day is.
                $dayLink = isset(self::LINK_FIELDS[$table]) && isset($days[(int) (($now ?? $was)['event_id'] ?? 0)]);
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
                if ($dayLink) {
                    // its day's ground only (below)
                } elseif ($changed === null) {
                    $out[] = [$k, '*'];
                } else {
                    foreach ($changed as $col) {
                        if ($table === 'events' && $col === 'exdates_json') {
                            foreach (self::skipsChanged($was, $now, $carried[$k] ?? []) as $ground) {
                                $out[] = [$k, $ground];
                            }
                            continue;
                        }
                        $out[] = [$k, $col];
                    }
                }
                foreach (self::parentsOf($table) as $col => [$parent, $mark]) {
                    if ($changed !== null && !in_array($col, $changed, true)) {
                        continue;
                    }
                    foreach ([$was, $now] as $row) {
                        if ($row === null || empty($row[$col])) {
                            continue;
                        }
                        // A tag or a person on a day is a field of that day.
                        $field = $parent === 'events' ? (self::LINK_FIELDS[$table] ?? null) : null;
                        if ($field !== null && isset($days[(int) $row[$col]])) {
                            [$series, $key] = $days[(int) $row[$col]];
                            $out[] = [$series, self::dayGround($key, $field)];
                        } else {
                            $out[] = [self::keyOf($parent, ['id' => $row[$col]]), $mark];
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** The field a link table's rows are of the day they name. */
    private const LINK_FIELDS = ['event_tags' => 'tags', 'event_people' => 'people'];

    /**
     * A day's row's ground (touchesOf), on its series' row: made or
     * removed, the fields it owns; kept, its move when the change gave it
     * another occurrence, and the fields of its own it gained, lost or gave
     * another value. A value it takes from its series is the series'
     * ground, not the day's. Pure.
     *
     * @return list<array{0:string,1:string}>
     */
    private static function dayTouches(?array $was, ?array $now): array
    {
        $whole = static fn(array $row): array => array_map(static fn(string $field): array
            => [self::keyOf('events', ['id' => $row['recurrence_parent_id']]), self::dayGround((string) $row['recurrence_instance_utc'], $field)], self::ownOf($row));
        if ($was === null || $now === null || !self::same($was['deleted_at'] ?? null, $now['deleted_at'] ?? null)) {
            return $whole($now ?? $was);
        }
        // A day that went to another series (a split): it left one and joined the other, whole.
        if (!self::same($was['recurrence_parent_id'] ?? null, $now['recurrence_parent_id'] ?? null) || !self::same($was['uid'] ?? null, $now['uid'] ?? null)) {
            return array_merge(empty($was['recurrence_parent_id']) ? [] : $whole($was), empty($now['recurrence_parent_id']) ? [] : $whole($now));
        }
        $series = self::keyOf('events', ['id' => $now['recurrence_parent_id']]);
        $out = [];
        $key = (string) $now['recurrence_instance_utc'];
        if ((string) $was['recurrence_instance_utc'] !== $key) {
            $out[] = [$series, self::MOVE . $was['recurrence_instance_utc'] . '>' . $key];
        }
        [$had, $has] = [self::ownOf($was), self::ownOf($now)];
        $fields = array_merge(array_diff($had, $has), array_diff($has, $had));
        foreach (self::changedColumns('events', $was, $now) as $col) {
            $field = self::fieldOfColumn($col);
            if ($field !== null && in_array($field, $had, true) && in_array($field, $has, true)) {
                $fields[] = $field;
            }
        }
        foreach (array_values(array_unique($fields)) as $field) {
            $out[] = [$series, self::dayGround($key, $field)];
        }
        return $out;
    }

    /** A day's row's own fields as grounds: its own list (OwnFields::stored, the one reader), what places it one field (when). Pure. */
    private static function ownOf(array $row): array
    {
        $out = [];
        foreach (OwnFields::stored($row) as $field) {
            $out[] = OwnFields::groundOf($field);
        }
        return array_values(array_unique($out));
    }

    /**
     * The ground field a column of a day's row is: its placement (when), a
     * content field, else the column itself; null for what places the row
     * in its series or keeps the app's accounts. Pure.
     */
    private static function fieldOfColumn(string $col): ?string
    {
        if (in_array($col, ['start_utc', 'end_utc', 'all_day', 'tzid'], true)) {
            return 'when';
        }
        if (in_array($col, ['override_fields', 'recurrence_instance_utc', 'recurrence_parent_id', 'uid', 'calendar_id', 'google_event_id', 'source', 'deleted_at', 'user_id', 'id'], true)
            || in_array($col, self::BOOKKEEPING, true)) {
            return null;
        }
        return OwnFields::fieldOf($col) ?? $col;
    }

    /** One field of one day of a series, as a ground on its series' row: the prefix, the occurrence key, the field. */
    public static function dayGround(string $key, string $field): string
    {
        return self::DAY . $key . '|' . $field;
    }

    /**
     * The skips a plan carried to other occurrences (Series\Model's
     * skipMoves, from => to) as the grounds its entry records: the
     * planner's own record of which skip went where. Pure.
     *
     * @param array<string,string> $moves
     * @return list<array{0:string,1:string}>
     */
    public static function skipMoveGrounds(int $seriesId, array $moves): array
    {
        $out = [];
        foreach ($moves as $from => $to) {
            $out[] = [self::keyOf('events', ['id' => $seriesId]), self::MOVE . $from . '>' . $to];
        }
        return $out;
    }

    /**
     * The days a plan brought into being (Series\Model's writes['added'])
     * as the grounds its entry records on the series: ADDED alone when the
     * plan keeps every day on its date (a day is added when the series it
     * found has no day on that date, read from the entry's own snapshots),
     * else each added day's date (ADDED:<date>) and, when every day past
     * one is added, ADDED><date>. The planner's own record of its images, never
     * worked out again from dates. Pure.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function addedGrounds(int $seriesId, ?array $added): array
    {
        if ($added === null) {
            return [];
        }
        $series = self::keyOf('events', ['id' => $seriesId]);
        if (!empty($added['keeps'])) {
            return [[$series, self::ADDED]];
        }
        $out = [];
        foreach ((array) ($added['days'] ?? []) as $key) {
            $out[] = [$series, self::ADDED . ':' . $key];
        }
        if (($added['after'] ?? null) !== null) {
            $out[] = [$series, self::ADDED . '>' . $added['after']];
        }
        return $out;
    }

    /**
     * What a planned series change did with its days that its rows'
     * snapshots can't show, as the grounds its entry records beside them
     * ($writes: Series\Model's): where each skip went, since a skip has no
     * row to follow (skipMoveGrounds), and which days the change brought
     * into being (addedGrounds). Each row's move is read from the snapshots
     * themselves (touchesOf). The one function every entry of a change for
     * all events asks, however the change ended: the commit here, the
     * commit on a Google calendar, an undo's or redo's plan, and Review's
     * Finish of a change that stopped half way, from the plan its pending
     * row kept. Pure.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function planGrounds(int $seriesId, array $writes): array
    {
        return array_merge(self::skipMoveGrounds($seriesId, (array) ($writes['skipMoves'] ?? [])), self::addedGrounds($seriesId, $writes['added'] ?? null));
    }

    /**
     * A change's ground from what it keeps: what its snapshots show it
     * changed, read with its planner's record ($recorded: touchesOf takes a
     * skip the plan carried as that move, never as two skips changed), and
     * that record itself, less any move the rows don't show (shownMoves):
     * an entry says what the change did, not what its plan meant to do.
     * What record() writes for every entry, and what a
     * log-only entry that keeps no snapshots (Review's Finish) is given, so
     * the two can't read one change two ways. Pure.
     *
     * @param list<array{0:string,1:string}> $recorded
     * @return list<array{0:string,1:string}>
     */
    public static function changeGround(?array $before, ?array $after, array $recorded = []): array
    {
        $recorded = self::shownMoves($before, $after, $recorded);
        return array_merge(self::touchesOf($before, $after, $recorded), $recorded);
    }

    /**
     * A planner's record less the moves the rows don't show: a move stands
     * in an entry only where the change carried something from that key to
     * that key, a skip (the series skipped <from> before and skips <to>
     * after) or a day's row (the same row at <from> before and at <to>
     * after). The record is the plan's, and the rows are what was stored:
     * on a Google calendar they are Google's read-back, which can keep a day
     * the plan skipped, and after Review's Finish over a clock changed at
     * Google meanwhile they are Google's series. A move that did not happen
     * would carry a later judgement of that day to a key the series does
     * not have it at; what did happen to the skip (gone, or made) is read
     * from the rows (touchesOf). A move of a series whose row is not in
     * both snapshots stands as given: there is nothing to read it against.
     * Pure.
     *
     * @param list<array{0:string,1:string}> $recorded
     * @return list<array{0:string,1:string}>
     */
    private static function shownMoves(?array $before, ?array $after, array $recorded): array
    {
        // Each series' row key => [its skips, its days' rows as id => key].
        $read = static function (?array $tables): array {
            $out = [];
            foreach ($tables['events'] ?? [] as $r) {
                if (!is_array($r) || !isset($r['id'])) {
                    continue;
                }
                if (($r['recurrence_instance_utc'] ?? null) === null) {
                    try {
                        $skips = Recurrence::decodeExdates($r['exdates_json'] ?? null);
                    } catch (\InvalidArgumentException) {
                        $skips = [];
                    }
                    $out[self::keyOf('events', $r)]['skips'] = $skips;
                } elseif (!empty($r['recurrence_parent_id'])) {
                    $out[self::keyOf('events', ['id' => $r['recurrence_parent_id']])]['days'][(int) $r['id']] = (string) $r['recurrence_instance_utc'];
                }
            }
            return $out;
        };
        [$was, $now] = [$read($before), $read($after)];
        return array_values(array_filter($recorded, static function (array $ground) use ($was, $now): bool {
            [$row, $col] = [(string) $ground[0], (string) $ground[1]];
            if (!str_starts_with($col, self::MOVE) || !isset($was[$row]['skips'], $now[$row]['skips'])) {
                return true;
            }
            [$from, $to] = explode('>', substr($col, strlen(self::MOVE)), 2) + [1 => ''];
            if (in_array($from, $was[$row]['skips'], true) && in_array($to, $now[$row]['skips'], true)) {
                return true;
            }
            foreach ($was[$row]['days'] ?? [] as $id => $key) {
                if ($key === $from && ($now[$row]['days'][$id] ?? null) === $to) {
                    return true;
                }
            }
            return false;
        }));
    }

    /** The ground of one field of one day (dayGround). */
    public const DAY = 'day:';

    /** A day carried from one occurrence to another, "move:<from>><to>": followed by a judgement, never a conflict itself. */
    public const MOVE = 'move:';

    /** Every day of a series, as a snapshot restore of its times or zone puts them back (restoredGround). */
    public const OCCURRENCES = 'occurrences';

    /**
     * The days a change to a series' rule, start, times or zone brought
     * into being: those of the series it left whose date the series it
     * found doesn't have. Read against the days a later change touches
     * (staleFor, addedDays), each such day the ground "day:<key>|*".
     */
    public const ADDED = 'added';

    /**
     * The ground a snapshot entry's restore adds to its change's
     * (touchesOf): it puts back every column it changed of every row, so a
     * value a series change wrote on a day following it (the series' new
     * title) is that day's ground too; and a series' times or zone put back
     * put every day of it back at its old occurrence, so such a change
     * stands on its occurrences (OCCURRENCES), which every day and skip of
     * that series, and its rule, stand on too. A planned undo (a Google
     * series change's, GoogleUndo) carries the days instead, and its entry
     * records none of this. Pure.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function restoredGround(?array $before, ?array $after): array
    {
        $out = [];
        $b = [];
        foreach ($before['events'] ?? [] as $r) {
            if (is_array($r) && isset($r['id'])) $b[(int) $r['id']] = $r;
        }
        foreach ($after['events'] ?? [] as $now) {
            $was = is_array($now) && isset($now['id']) ? ($b[(int) $now['id']] ?? null) : null;
            if ($was === null) {
                continue;
            }
            $changed = self::changedColumns('events', $was, $now);
            if (($now['recurrence_instance_utc'] ?? null) === null) {
                if (!empty($now['rrule']) && array_intersect($changed, self::SERIES_TIMES) !== []) {
                    $out[] = [self::keyOf('events', $now), self::OCCURRENCES];
                }
                continue;
            }
            foreach (empty($now['recurrence_parent_id']) ? [] : $changed as $col) {
                $field = self::fieldOfColumn($col);
                if ($field !== null) {
                    $out[] = [self::keyOf('events', ['id' => $now['recurrence_parent_id']]), self::dayGround((string) $now['recurrence_instance_utc'], $field)];
                }
            }
        }
        return $out;
    }

    /** The ground of one skipped date of a series (touchesOf): the prefix, then the occurrence key. */
    public const SKIP = 'skip:';

    /**
     * What a series' skips gained or lost between two versions of its row,
     * as grounds: each date skipped or brought back (SKIP), less those the
     * change carried to another occurrence ($moves, "move:<from>><to>" as
     * its planner recorded them), which are those moves alone. A move counts
     * where the rows show it (skipped at <from> before, at <to> after), so
     * skips carried in a chain, each onto the key the next one left, are
     * their moves and no skip changed, and a skip the plan carried that is
     * not there after is that skip gone. Pure.
     *
     * @param list<string> $moves
     * @return list<string>
     */
    private static function skipsChanged(?array $was, ?array $now, array $moves = []): array
    {
        $of = static function (?array $r): array {
            try {
                return Recurrence::decodeExdates($r['exdates_json'] ?? null);
            } catch (\InvalidArgumentException) {
                return [];
            }
        };
        [$before, $after] = [$of($was), $of($now)];
        // The skips the rows show carried: skipped at <from> before and at <to> after. A chain (one skip onto the key
        // the next one left) is each of its moves, so a key is judged by whether its own skip left and another came.
        [$left, $came] = [[], []];
        foreach ($moves as $move) {
            [$from, $to] = explode('>', substr($move, strlen(self::MOVE)), 2) + [1 => ''];
            if (in_array($from, $before, true) && in_array($to, $after, true)) {
                $left[$from] = true;
                $came[$to] = true;
            }
        }
        // Gone: a skip that was not carried and is no longer there, or whose key a carried skip took. Made: a skip no
        // move brought, on a key that had none or whose own skip was carried away.
        $gone = array_values(array_filter($before, static fn(string $k): bool => !isset($left[$k]) && (!in_array($k, $after, true) || isset($came[$k]))));
        $made = array_values(array_filter($after, static fn(string $k): bool => !isset($came[$k]) && (!in_array($k, $before, true) || isset($left[$k]))));
        $out = array_map(static fn(string $d): string => self::SKIP . $d, array_merge($gone, $made));
        sort($out);
        return $out;
    }

    /**
     * Columns that keep the app's own accounts, not anything a person
     * changed or would undo: when a row was last written, a calendar's sync
     * token and poll state, when an address was looked up. Never put back,
     * and never a reason an undo is stale.
     */
    private const BOOKKEEPING = ['updated_at', 'synctoken', 'last_polled_at', 'last_poll_status', 'last_poll_error',
        'content_changed_at', 'last_ok_at', 'google_sync_token', 'google_etag', 'score', 'scored_at'];

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
        foreach (self::changedColumns($table, $was, $now) as $col) {
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

    /** The columns that differ between two versions of a $table row, bookkeeping and preferences aside. Pure. */
    public static function changedColumns(string $table, array $was, array $now): array
    {
        $out = [];
        $aside = array_merge(self::BOOKKEEPING, self::PREFERENCES[$table] ?? []);
        foreach (array_unique(array_merge(array_keys($was), array_keys($now))) as $col) {
            if (!in_array($col, $aside, true) && !self::same($was[$col] ?? null, $now[$col] ?? null)) {
                $out[] = (string) $col;
            }
        }
        return $out;
    }

    /**
     * Whether two snapshots differ: a row made or removed, or a column
     * changed (changedColumns). Pure.
     */
    public static function anyChange(array $before, array $after): bool
    {
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $table) {
            $was = [];
            foreach ($before[$table] ?? [] as $row) {
                $was[self::keyOf($table, $row)] = $row;
            }
            $now = [];
            foreach ($after[$table] ?? [] as $row) {
                $now[self::keyOf($table, $row)] = $row;
            }
            foreach ($was + $now as $key => $_) {
                if (!isset($was[$key], $now[$key]) || self::changedColumns($table, $was[$key], $now[$key]) !== []) {
                    return true;
                }
            }
        }
        return false;
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
    /**
     * Grounds in their one stored form: a ground a caller names against a
     * day's own row (an answer's `invite_json`, `status`, `attendance`, or
     * `*` for the whole row) is that day's, on its series' row at its
     * occurrence (dayGround), as touchesOf writes every day's ground, so a
     * later change of the same field of that day stales it whichever path
     * wrote either. Any other ground is kept as it is.
     *
     * @param list<array{0:string,1:string}> $touches
     * @return list<array{0:string,1:string}>
     */
    private function dayForm(array $touches): array
    {
        $ids = [];
        foreach ($touches as [$row]) {
            if (preg_match('/^events:(\d+)$/', (string) $row, $m) === 1) {
                $ids[(int) $m[1]] = true;
            }
        }
        if ($ids === []) {
            return $touches;
        }
        $days = [];
        [$in, $params] = Db::in(array_keys($ids));
        foreach ($this->db->all("SELECT * FROM events WHERE id IN $in", $params) as $r) {
            if (($r['recurrence_parent_id'] ?? null) !== null && ($r['recurrence_instance_utc'] ?? null) !== null) {
                $days[self::keyOf('events', $r)] = [self::keyOf('events', ['id' => $r['recurrence_parent_id']]), (string) $r['recurrence_instance_utc']];
            }
        }
        $out = [];
        foreach ($touches as [$row, $col]) {
            $day = $days[(string) $row] ?? null;
            $field = $day === null || str_starts_with((string) $col, self::DAY) || str_starts_with((string) $col, self::MOVE) ? null
                : ((string) $col === '*' ? '*' : self::fieldOfColumn((string) $col));
            $out[] = $field === null ? [$row, $col] : [$day[0], self::dayGround($day[1], $field)];
        }
        return $out;
    }

    private function recordTouches(int $mutationId, array $touches): void
    {
        if ($mutationId <= 0 || $touches === []) {
            return;
        }
        $touches = $this->dayForm($touches);
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
     * Why a later change, not undone, makes undoing this one stale, or
     * null: anything of its ground that conflicts with what it touched.
     * One definition for every entry (decisions.md): each side's ground is
     * what its entry recorded (touchesOf, and restoredGround for a snapshot
     * entry), and a day this entry touched is followed to the occurrence
     * later changes carried it to (MOVE) before it is compared, a day being
     * its series and its occurrence. The days a change brought into being
     * (ADDED) are its ground too: each day a later change touched that the
     * series as this change found it doesn't have is "day:<key>|*" of it,
     * and is said by name, since undoing would remove that day.
     */
    private function staleFor(array $mutation): ?string
    {
        $id = (int) $mutation['id'];
        $reverts = [];
        $added = [];
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
            $mine = [];
            foreach ($page as $t) {
                if (str_starts_with((string) $t['col'], self::ADDED)) {
                    $added[(string) $t['row_key']] ??= $this->addedDays($mutation, (string) $t['row_key']);
                }
                $mine[(string) $t['row_key']][(string) $t['col']] = true;
            }
            foreach (array_chunk(array_keys($mine), 200) as $rows) {
                [$in, $params] = Db::in($rows);
                $later = $this->db->all(
                    "SELECT t.row_key, t.col, m.id FROM mutation_touches t JOIN mutations m ON m.id = t.mutation_id
                     WHERE t.row_key IN $in AND m.user_id = ? AND m.id > ? AND m.undone = 0 ORDER BY m.id",
                    array_merge($params, [(int) $mutation['user_id'], $id])
                );
                $byEntry = [];
                foreach ($later as $t) {
                    $byEntry[(int) $t['id']][] = [(string) $t['row_key'], (string) $t['col']];
                }
                foreach ($byEntry as $entry => $touches) {
                    if ($reverts[$entry] ??= $this->revertsSince($entry, $id)) {
                        continue;
                    }
                    // The days it carried first, all at once: its other grounds are where it left them.
                    $moves = [];
                    foreach ($touches as [$row, $col]) {
                        if (str_starts_with($col, self::MOVE)) {
                            $moves[$row][] = $col;
                        }
                    }
                    foreach ($moves as $row => $list) {
                        $mine[$row] = self::moved($mine[$row] ?? [], $list);
                    }
                    foreach ($touches as [$row, $col]) {
                        // A day it brought into being, touched since: its ground, named.
                        $key = self::dayKeyOf($col);
                        if ($key !== null && isset($added[$row]) && ($added[$row])($key)) {
                            $mine[$row][self::dayGround($key, '*')] = true;
                            if (self::conflicts(self::dayGround($key, '*'), $col)) {
                                return 'Newer changes exist for this item: ' . self::dayWords($key) . ' exists because of this change and was changed since, so undoing it would remove that day.';
                            }
                        }
                        foreach (array_keys($mine[$row] ?? []) as $own) {
                            if (self::conflicts((string) $own, $col, strstr($row, ':', true) ?: '')) {
                                return 'Newer changes exist for this item; undoing would overwrite them';
                            }
                        }
                    }
                }
            }
        } while (count($page) === 2000);
        return null;
    }

    /** The occurrence key a day's or skip's ground names, or null. Pure. */
    private static function dayKeyOf(string $ground): ?string
    {
        if (str_starts_with($ground, self::SKIP)) {
            return substr($ground, strlen(self::SKIP));
        }
        if (str_starts_with($ground, self::DAY)) {
            return explode('|', substr($ground, strlen(self::DAY)), 2)[0];
        }
        return null;
    }

    /**
     * Whether the day at a key is one entry $mutation brought into being on
     * the series $rowKey names, as its planner recorded them when the
     * change was made (addedGrounds): a day on a listed date, or past the
     * date after which every day is added (each read on the calendar of the
     * series the entry left); or, where the plan kept every day on
     * its date (ADDED alone), a day of the series as the entry left it on
     * whose date, read on that series' own calendar, the series as it
     * found it has none (both from the entry's own snapshots of that
     * series' row, its skips among its days). A series the expander can't
     * read brought none.
     *
     * @return \Closure(string):bool
     */
    private function addedDays(array $mutation, string $rowKey): \Closure
    {
        $listed = [];
        $after = null;
        $keeps = false;
        foreach ($this->db->all('SELECT col FROM mutation_touches WHERE mutation_id = ? AND row_key = ? AND col LIKE ?', [(int) $mutation['id'], $rowKey, self::ADDED . '%']) as $t) {
            $col = (string) $t['col'];
            if ($col === self::ADDED) {
                $keeps = true;
            } elseif (str_starts_with($col, self::ADDED . ':')) {
                $listed[substr($col, strlen(self::ADDED) + 1)] = true;
            } elseif (str_starts_with($col, self::ADDED . '>')) {
                $after = substr($col, strlen(self::ADDED) + 1);
            }
        }
        $rowOf = static function (?string $json) use ($rowKey): ?array {
            foreach (self::tables($json)['events'] ?? [] as $r) {
                if (is_array($r) && self::keyOf('events', $r) === $rowKey && ($r['recurrence_instance_utc'] ?? null) === null && !empty($r['rrule'])) {
                    return $r;
                }
            }
            return null;
        };
        [$was, $now] = [$rowOf($mutation['before_json'] ?? null), $rowOf($mutation['after_json'] ?? null)];
        try {
            [$found, $left] = [$was === null ? null : Series\Calc::fromRow(['exdates_json' => null] + $was), $now === null ? null : Series\Calc::fromRow(['exdates_json' => null] + $now)];
        } catch (\Throwable) {
            return static fn(string $key): bool => false;
        }
        return static function (string $key) use ($found, $left, $keeps, $listed, $after): bool {
            try {
                // The day's date on the calendar of the series it is a day of.
                if ($left === null || !$left->isOccurrence($dn = $left->dnOf($key))) {
                    return false;
                }
                if (!$keeps) {
                    $date = Series\Cal::date($dn);
                    return isset($listed[$date]) || ($after !== null && $date > $after);
                }
                return $found !== null && !$found->isOccurrence($dn);
            } catch (\Throwable) {
                return false;
            }
        };
    }

    /** A day named by its date, for the words of a refusal. */
    private static function dayWords(string $key): string
    {
        return Time::fromDb($key)->format('D j M Y');
    }

    /**
     * Where the day at $key of the series $master is now, for the entry
     * $entryId about it: the gate every undo or redo that acts on one day
     * asks (decisions.md, "An undo acts on a day only where the journal
     * says exactly where it is"). It answers a key only where the journal
     * fixes it exactly, and otherwise refuses, forced or not, before
     * anything is written or sent (not_undoable). Exactly is one of:
     *  1. No later change re-placed the series' days (replacing): the key
     *     is the day.
     *  2. The entry's own row for that day is still there (ownRow): the day
     *     is where that row is. The planner and the sync carry a row with
     *     its id; a zone change that keeps the instants leaves its key as
     *     it is and records no move, which is why this stands beside 3.
     *  3. Every re-placing change since recorded what it did with the day:
     *     a move from its key (MOVE), followed. Where one recorded none,
     *     only a delete's own skip anchors the day: the entry left the day
     *     skipped, nothing since was moved onto that key or touched its
     *     skip, and the skip still stands there.
     * After any of them the key must be an occurrence of the series as it
     * stands, exactly (a rule, a bound or a split may have taken the day).
     * Nothing is placed by an equal key across a re-placing change and no
     * image is worked out here: a day with no row and no skip when its
     * series was re-placed has no recorded place, and a guessed one is
     * right at some clocks and a neighbouring day at others.
     *
     * Null only for a day the entry itself left no day of (the series in
     * its after snapshot has none at that key: a count, rule or bound it
     * changed dropped it): such a day is at its own key or nowhere, and a
     * row brought back for it comes back at its own key.
     */
    public function dayNow(int $entryId, array $master, string $key): ?string
    {
        $entry = $this->db->one('SELECT id, user_id, entity_id, details_json, before_json, after_json FROM mutations WHERE id = ?', [$entryId]) ?? [];
        foreach (self::tables($entry['after_json'] ?? null)['events'] ?? [] as $left) {
            if (is_array($left) && (int) ($left['id'] ?? 0) === (int) ($master['id'] ?? -1) && ($left['recurrence_instance_utc'] ?? null) === null && !empty($left['rrule'])) {
                if (!self::occursAt(['exdates_json' => null] + $left, $key)) {
                    return null;
                }
            }
        }
        $since = $this->replacing($master, (int) ($entry['user_id'] ?? 0), $entryId);
        $now = $key;
        if ($since['entries'] !== []) {
            $row = $this->ownRow($entry, $master, $key);
            if ($row !== null) {
                return $row;
            }
            $unrecorded = null;
            foreach ($since['entries'] as $cols) {
                $to = null;
                $onto = false;
                foreach ($cols as $col) {
                    if (str_starts_with($col, self::MOVE)) {
                        [$a, $b] = explode('>', substr($col, strlen(self::MOVE)), 2) + [1 => ''];
                        $to = $a === $now ? $b : $to;
                        $onto = $onto || $b === $now;
                    }
                }
                if ($to !== null) {
                    $now = $to;
                } elseif ($onto) {
                    throw $this->unplaced($entryId, $cols);
                } else {
                    $unrecorded ??= $cols;
                }
            }
            if ($unrecorded !== null && !($this->leftSkipped($entry, $master, $key) && !isset($since['skips'][$now])
                && in_array($now, Recurrence::decodeExdates($master['exdates_json'] ?? null), true))) {
                throw $this->unplaced($entryId, $unrecorded);
            }
        }
        if (!empty($master['rrule']) && !self::occursAt(['exdates_json' => null] + $master, $now)) {
            throw self::dayGone();
        }
        return $now;
    }

    /** Whether a series has an occurrence at exactly this key (its skips aside when the caller leaves them out). A rule the expander can't read has none it can vouch for. Pure. */
    private static function occursAt(array $series, string $key): bool
    {
        try {
            $calc = Series\Calc::fromRow($series);
            return in_array($key, $calc->keysOn($calc->dnOf($key)), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The later changes, not undone, that re-placed a series' days since an
     * entry, in order, each as its grounds on the series' row (`entries`),
     * and the keys whose skip any later change touched (`skips`). A change
     * re-places when those grounds name the series' start (`start_utc`: its
     * clock or the day it starts on), its zone (`tzid`, whichever reading:
     * only the start's instant is kept, and later days follow the new
     * zone's clock), its form (`all_day`), a day it carried (MOVE), a day it
     * brought into being by date (ADDED with a date: the planner's word that
     * its reading did not keep every day on its date, as "move every 15th a
     * month later" does with every column of the series as it was), or its
     * rule without the planner's word that every day kept its date (bare
     * ADDED) or the entry's own snapshots showing the pattern as it was (a
     * bound alone). A length, a bound, a calendar and every field re-place
     * nothing. A change that only takes back one made after the entry
     * counts for nothing, as in staleFor.
     *
     * @return array{entries: array<int,list<string>>, skips: array<string,true>}
     */
    private function replacing(array $master, int $userId, int $entryId): array
    {
        $byEntry = [];
        $skips = [];
        foreach ($this->db->all(
            "SELECT t.col, m.id FROM mutation_touches t JOIN mutations m ON m.id = t.mutation_id
             WHERE t.row_key = ? AND m.user_id = ? AND m.id > ? AND m.undone = 0
               AND (t.col IN ('start_utc', 'tzid', 'all_day', 'rrule') OR t.col LIKE ? OR t.col LIKE ? OR t.col LIKE ?) ORDER BY m.id",
            [self::keyOf('events', $master), $userId, $entryId, self::MOVE . '%', self::ADDED . '%', self::SKIP . '%']
        ) as $t) {
            $byEntry[(int) $t['id']][] = (string) $t['col'];
        }
        $entries = [];
        foreach ($byEntry as $id => $cols) {
            if ($this->revertsSince($id, $entryId)) {
                continue;
            }
            foreach ($cols as $col) {
                if (str_starts_with($col, self::SKIP)) {
                    $skips[substr($col, strlen(self::SKIP))] = true;
                }
            }
            if ($this->carriesDays($id, $cols, $master)) {
                $entries[$id] = array_values(array_filter($cols, static fn(string $c): bool => !str_starts_with($c, self::SKIP)));
            }
        }
        return ['entries' => $entries, 'skips' => $skips];
    }

    /**
     * Whether a change re-placed a series' days, read from its grounds on
     * the series' row and, for its start and its rule, from the series' row
     * as the change itself kept it before and after. A start that moved
     * while the planner kept every day on its date (bare ADDED), at the
     * same clock in the same zone and form, is a bound: every day keeps its
     * key. A rule that changed while the planner kept every day on its
     * date, or whose pattern is as it was, is a bound or a rule that leaves
     * each remaining day where it was. A poll keeps no row of the series as
     * it was, so its change of start or rule is taken to re-place.
     *
     * @param list<string> $cols
     */
    private function carriesDays(int $entryId, array $cols, array $master): bool
    {
        foreach ($cols as $col) {
            if ($col === 'tzid' || $col === 'all_day' || str_starts_with($col, self::MOVE) || str_starts_with($col, self::ADDED . ':') || str_starts_with($col, self::ADDED . '>')) {
                return true;
            }
        }
        [$start, $rule, $keeps] = [in_array('start_utc', $cols, true), in_array('rrule', $cols, true), in_array(self::ADDED, $cols, true)];
        if (!$start && !$rule) {
            return false;
        }
        $kept = $this->db->one('SELECT before_json, after_json FROM mutations WHERE id = ?', [$entryId]) ?? [];
        $rowIn = static function (?string $json) use ($master): ?array {
            foreach (self::tables($json)['events'] ?? [] as $r) {
                if (is_array($r) && (int) ($r['id'] ?? 0) === (int) $master['id'] && ($r['recurrence_instance_utc'] ?? null) === null) {
                    return $r;
                }
            }
            return null;
        };
        [$was, $is] = [$rowIn($kept['before_json'] ?? null), $rowIn($kept['after_json'] ?? null)];
        if ($was === null || $is === null) {
            return $start || !$keeps;
        }
        if ($start) {
            $clock = static fn(array $r): string => Time::fromDb((string) $r['start_utc'])
                ->setTimezone((int) ($r['all_day'] ?? 0) === 1 ? Time::utc() : Time::zone((string) ($r['tzid'] ?? 'UTC')))->format('H:i:s');
            if (!$keeps || $clock($was) !== $clock($is)) {
                return true;
            }
        }
        return $rule && !$keeps && Recurrence::pattern((string) ($was['rrule'] ?? '')) !== Recurrence::pattern((string) ($is['rrule'] ?? ''));
    }

    /** An entry's grounds on one row. @return list<string> */
    private function groundsOn(int $entryId, string $rowKey): array
    {
        return array_map('strval', array_column($this->db->all('SELECT col FROM mutation_touches WHERE mutation_id = ? AND row_key = ?', [$entryId, $rowKey]), 'col'));
    }

    /**
     * The key now of the entry's own row for a day: a day row the entry
     * holds at that key in its snapshots, or the row a Google entry about
     * that one day is recorded on, that is live and still a row of this
     * series. Null when none is.
     */
    private function ownRow(array $entry, array $master, string $key): ?string
    {
        $ids = [];
        foreach (['before_json', 'after_json'] as $side) {
            foreach (self::tables($entry[$side] ?? null)['events'] ?? [] as $r) {
                if (is_array($r) && isset($r['id']) && (int) ($r['recurrence_parent_id'] ?? 0) === (int) $master['id'] && (string) ($r['recurrence_instance_utc'] ?? '') === $key) {
                    $ids[(int) $r['id']] = true;
                }
            }
        }
        $g = json_decode((string) ($entry['details_json'] ?? ''), true)['google'] ?? null;
        if (is_array($g) && (string) ($g['kind'] ?? '') !== GoogleUndo::SERIES && is_string($g['googleId'] ?? null)
            && (GoogleWriter::instanceOf($g['googleId'])['key'] ?? null) === $key && (int) ($entry['entity_id'] ?? 0) > 0) {
            $ids[(int) $entry['entity_id']] = true;
        }
        if ($ids === []) {
            return null;
        }
        [$in, $params] = Db::in(array_keys($ids));
        $keys = array_column($this->db->all(
            "SELECT DISTINCT recurrence_instance_utc FROM events WHERE id IN $in AND recurrence_parent_id = ? AND deleted_at IS NULL AND recurrence_instance_utc IS NOT NULL",
            array_merge($params, [(int) $master['id']])
        ), 'recurrence_instance_utc');
        return count($keys) === 1 ? (string) $keys[0] : null;
    }

    /**
     * Whether an entry left the day at a key skipped: a delete of that day
     * on a Google calendar, or a change whose snapshots show the series
     * without that skip before and with it after. Pure.
     */
    private function leftSkipped(array $entry, array $master, string $key): bool
    {
        $g = json_decode((string) ($entry['details_json'] ?? ''), true)['google'] ?? null;
        if (is_array($g) && (string) ($g['kind'] ?? '') === GoogleUndo::DELETE && is_string($g['googleId'] ?? null)) {
            return (GoogleWriter::instanceOf($g['googleId'])['key'] ?? null) === $key;
        }
        $skipped = static function (?string $json) use ($master, $key): ?bool {
            foreach (self::tables($json)['events'] ?? [] as $r) {
                if (is_array($r) && (int) ($r['id'] ?? 0) === (int) $master['id'] && ($r['recurrence_instance_utc'] ?? null) === null) {
                    try {
                        return in_array($key, Recurrence::decodeExdates($r['exdates_json'] ?? null), true);
                    } catch (\InvalidArgumentException) {
                        return null;
                    }
                }
            }
            return null;
        };
        return $skipped($entry['before_json'] ?? null) === false && $skipped($entry['after_json'] ?? null) === true;
    }

    /** The `reason` of a refusal where a re-placing change holds no record of the day. */
    public const DAY_UNPLACED = 'day_unplaced';

    /** The `reason` of a refusal where the series no longer has the day. */
    public const DAY_GONE = 'day_gone';

    /** The `reason` of a refusal to put a series' time back over days changed after it. */
    public const DAYS_SINCE = 'days_since';

    /** What a re-placing change did, in a person's words, from its own grounds. Pure. */
    private static function replacedWords(array $cols): string
    {
        return match (true) {
            in_array('all_day', $cols, true) => 'changed between timed and all-day',
            in_array('tzid', $cols, true) => 'changed its time zone',
            in_array('start_utc', $cols, true) => 'was moved to another time or day',
            in_array('rrule', $cols, true) => 'changed how it repeats',
            default => 'had its days moved',
        };
    }

    /**
     * The refusal where a re-placing change holds no record of the day:
     * what that change did, that the day is no longer certain, and what the
     * person can do. `not_undoable`, the code of every refusal `force` does
     * not pass: the app shows it in its own words, where it answers a
     * `stale_undo` with an offer to undo anyway.
     *
     * @param list<string> $cols the re-placing change's grounds
     */
    private function unplaced(int $entryId, array $cols): HttpError
    {
        return new HttpError('not_undoable', 'This series ' . self::replacedWords($cols) . " since this was done, so it's no longer certain which day it was on, and it can't be "
            . ($this->takesBack($entryId) ? 'redone' : 'undone') . ' here. You can make the change again on the day.', 400, ['reason' => self::DAY_UNPLACED]);
    }

    /** The refusal where the series no longer has the day, in words true of a bound, a split and a rule alike. */
    public static function dayGone(): HttpError
    {
        return new HttpError('not_undoable', 'That day is no longer part of the series this change was made in (a later change ended the series before it, moved it into another series, or changed how it repeats), so this can\'t be undone.',
            400, ['reason' => self::DAY_GONE]);
    }

    /**
     * Grounds of days (DAY, SKIP) with one change's moves
     * ("move:<from>><to>") applied together: what was the day at <from>
     * is the day at <to>. Pure.
     *
     * @param array<string,true> $grounds
     * @param list<string> $moves
     * @return array<string,true>
     */
    private static function moved(array $grounds, array $moves): array
    {
        $to = [];
        foreach ($moves as $move) {
            [$a, $b] = explode('>', substr($move, strlen(self::MOVE)), 2) + [1 => ''];
            $to[$a] = $b;
        }
        $out = [];
        foreach ($grounds as $g => $_) {
            if (str_starts_with($g, self::SKIP) && isset($to[substr($g, strlen(self::SKIP))])) {
                $g = self::SKIP . $to[substr($g, strlen(self::SKIP))];
            } elseif (str_starts_with($g, self::DAY)) {
                [$key, $field] = explode('|', substr($g, strlen(self::DAY)), 2) + [1 => ''];
                if (isset($to[$key])) {
                    $g = self::dayGround($to[$key], $field);
                }
            }
            $out[$g] = true;
        }
        return $out;
    }

    /**
     * Whether a later entry only takes back a change made after entry
     * $since: a Google calendar's undo is recorded as an entry of its own
     * (GoogleUndo, details.google.undoes names the entry it undid), and a
     * change undone with the undo's own entry beside it leaves the ground
     * as it was, so neither makes an older entry stale, as a local undo,
     * which records nothing, doesn't. An undo of an undo puts the change
     * back and stands like it; the chain is followed to the change itself
     * and counted.
     */
    private function revertsSince(int $entryId, int $since): bool
    {
        [$hops, $at] = $this->undoChain($entryId);
        return $hops % 2 === 1 && $at > $since;
    }

    /**
     * Whether an entry only takes back an earlier change: an undo's own
     * entry (an odd number of undoes down to the change). An undo of an
     * undo puts the change back and is a change like it.
     */
    private function takesBack(int $entryId): bool
    {
        return $this->undoChain($entryId)[0] % 2 === 1;
    }

    /**
     * The undoes an entry stands at the end of (details.google.undoes,
     * followed down) and the change at the start of them: [hops, change].
     *
     * @return array{0:int,1:int}
     */
    private function undoChain(int $entryId): array
    {
        $hops = 0;
        $at = $entryId;
        for ($seen = 0; $seen < 100; $seen++) {
            $details = json_decode((string) $this->db->scalar('SELECT details_json FROM mutations WHERE id = ?', [$at]), true);
            $undoes = is_array($details) && is_array($details['google'] ?? null) ? ($details['google']['undoes'] ?? null) : null;
            if (!is_int($undoes) || $undoes <= 0 || $undoes >= $at) {
                break;
            }
            $hops++;
            $at = $undoes;
        }
        return [$hops, $at];
    }

    /** A series' times: one field, whichever column of it a change wrote. */
    private const SERIES_TIMES = ['start_utc', 'end_utc', 'tzid', 'all_day'];

    /**
     * Whether a later change that marked a row of $table $later makes
     * undoing an earlier one that marked it $mine stale: whether the two
     * grounds intersect. One reading for every entry; what differs between
     * kinds of entry is the ground each recorded (touchesOf,
     * restoredGround), never how two grounds are read. "*" is the row made
     * or removed, "+" a row joining or leaving it (PARENTS),
     * "day:<key>|<field>" one field of one day of a
     * series, "skip:<key>" that day skipped or brought back (the day as a
     * whole), "occurrences" every day of a series (a snapshot restore of
     * its times), "move:" a day carried (followed, staleFor, never a
     * conflict),
     * anything else a field changed. Pure.
     * - Undoing a row's making or removal stands on everything done to it
     *   since: a calendar made, then an event added to it.
     * - A row joining or leaving something changes nothing of that thing:
     *   an event added to a calendar doesn't make its rename stale, and a
     *   calendar deleted, then its folder, still comes back (without that
     *   filing, apply).
     * - A day's field stands on the same field of that day, and on the
     *   day skipped or brought back; two days, or two fields of a day,
     *   don't stand on each other.
     * - A series' occurrences stand on every day and skip of it, its rule
     *   and its times.
     * - Otherwise, the same field (a series' times one field).
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
        if (str_starts_with($mine, self::MOVE) || str_starts_with($later, self::MOVE) || str_starts_with($mine, self::ADDED) || str_starts_with($later, self::ADDED)) {
            return false;
        }
        $times = $table === 'events' ? self::SERIES_TIMES : [];
        // Of a day: [its key, its field ("*" for the day as a whole)]; null for anything else.
        $dayOf = static function (string $g): ?array {
            if (str_starts_with($g, self::SKIP)) {
                return [substr($g, strlen(self::SKIP)), '*'];
            }
            if (str_starts_with($g, self::DAY)) {
                return explode('|', substr($g, strlen(self::DAY)), 2) + [1 => ''];
            }
            return null;
        };
        if ($mine === self::OCCURRENCES || $later === self::OCCURRENCES) {
            $other = $mine === self::OCCURRENCES ? $later : $mine;
            return $other === self::OCCURRENCES || $dayOf($other) !== null
                || $other === 'rrule' || in_array($other, $times, true);
        }
        [$d1, $d2] = [$dayOf($mine), $dayOf($later)];
        if ($d1 !== null || $d2 !== null) {
            return $d1 !== null && $d2 !== null && $d1[0] === $d2[0] && ($d1[1] === $d2[1] || $d1[1] === '*' || $d2[1] === '*');
        }
        return $mine === $later || (in_array($mine, $times, true) && in_array($later, $times, true));
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
            if (isset($was[$id], $now[$id]) && array_diff(self::changedColumns('calendars', $was[$id], $now[$id]), self::LOCAL_CALENDAR_COLUMNS) === []) {
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
    private static function googleCalendarsIn(?array $before, ?array $after): array
    {
        $ids = [];
        foreach ([$before, $after] as $tables) {
            foreach ($tables['calendars'] ?? [] as $row) {
                if (is_array($row) && ($row['kind'] ?? '') === 'subscribed' && ($row['provider'] ?? '') === 'google') {
                    $ids[] = (int) ($row['id'] ?? 0);
                }
            }
        }
        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }

    /**
     * What Google holds of a row on a Google calendar: of an event, the
     * columns its sync writes (Feeds::columnsOf, GoogleSync) and the row's
     * identity; of the calendar itself, all of it (null) but its place in
     * the list (LOCAL_CALENDAR_COLUMNS; its folders are rows of their own).
     * Every other column of an event, and every other table (tags, people,
     * trip links, duplicate decisions, plugin data), is held here alone.
     */
    private const GOOGLE_HELD = [
        'events' => [...GoogleWriter::GOOGLE_COLUMNS, 'invite_json', 'recurrence_instance_utc', 'recurrence_parent_id',
            'google_event_id', 'uid', 'calendar_id', 'source', 'deleted_at', 'user_id'],
        'calendars' => null,
    ];

    /**
     * Whether a change left alone everything Google holds of the calendars
     * $googleIds: it made or removed no event or calendar row there and
     * changed none of their GOOGLE_HELD columns. Such a change (a trip
     * link, Planned, tags, reminders) never reaches
     * Google, so its entry keeps its snapshots and undoes here as any
     * local change does. One that did is Google's: its undo is a write to
     * Google (GoogleUndo) or none. Pure.
     *
     * @param list<int> $googleIds
     */
    private static function heldHereAlone(?array $before, ?array $after, array $googleIds): bool
    {
        foreach (self::GOOGLE_HELD as $table => $held) {
            $calendarOf = $table === 'events' ? 'calendar_id' : 'id';
            $sides = [[], []];
            foreach ([$before, $after] as $i => $tables) {
                foreach ($tables[$table] ?? [] as $row) {
                    if (is_array($row) && in_array((int) ($row[$calendarOf] ?? 0), $googleIds, true)) {
                        $sides[$i][self::keyOf($table, $row)] = $row;
                    }
                }
            }
            [$was, $now] = $sides;
            foreach ($was + $now as $key => $_) {
                $changed = isset($was[$key], $now[$key]) ? self::changedColumns($table, $was[$key], $now[$key]) : null;
                if ($changed === null || ($held === null ? array_diff($changed, self::LOCAL_CALENDAR_COLUMNS) !== [] : array_intersect($changed, $held) !== [])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * The calendars an entry refers to; true when it changed something
     * Google holds of one of them (heldHereAlone) and so keeps no
     * snapshots (emptied here), unless it is a write to Google whose
     * details say what to send back ($googleBefore: GoogleUndo). A change
     * made here alone to a Google calendar's rows keeps them as any local
     * change does.
     *
     * @param list<int> $explicitCalendarIds
     */
    private function recordCalendarRefs(int $mutationId, ?array $beforeTables, ?array $afterTables, array $explicitCalendarIds = [], bool $googleBefore = false): bool
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
        if ($googleBefore) {
            return false;
        }
        [$in, $params] = Db::in($ids);
        $google = array_merge(self::googleCalendarsIn($beforeTables, $afterTables), array_map('intval', array_column(
            $this->db->all("SELECT id FROM calendars WHERE id IN $in AND kind = 'subscribed' AND provider = 'google'", $params), 'id')));
        if ($google !== [] && !self::heldHereAlone($beforeTables, $afterTables, $google)) {
            // Undone here, a change to what Google holds would be put back
            // on rows the next poll overwrites, and never at Google; and a
            // calendar adopted back as local would get a provider cache's
            // snapshot back.
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
        // The line names the row the change made or changed: a date of a
        // series edited on its own is written beside the series it belongs
        // to, which stays as it was, so the first row would name the series
        // and its first day for every date edited.
        if ($table !== null && is_array($after[$table] ?? null) && is_array($before[$table] ?? null)) {
            $was = [];
            foreach ($before[$table] as $r) {
                if (is_array($r) && isset($r['id'])) {
                    $was[(string) $r['id']] = $r;
                }
            }
            foreach ($after[$table] as $r) {
                if (is_array($r) && (!isset($r['id']) || ($was[(string) $r['id']] ?? null) != $r)) {
                    $row = $r;
                    break;
                }
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
            } catch (\DateMalformedStringException) {
                // A label: a start PHP can't read leaves the line without its date.
            }
        }
        return $line;
    }

    /**
     * Undo the last change the person made themselves: in the app (quick
     * add and review decisions included) or, asked with an API key,
     * through the API. Changes the app made on its own (feeds, mail,
     * plugins) are passed over, and so is an undo's own entry (a Google
     * calendar's, takesBack): each call steps one change further back, on
     * a Google calendar as on a local one, where an undo records nothing.
     * Redo is the undo's entry undone by its id. A line that changed no
     * row (log-only, touching nothing) is passed over too, judged by what
     * it changed, never by who wrote it. When that last change can't be
     * undone (an answer already sent, say), that's the answer: an older one
     * behind it isn't undone instead.
     *
     * @return array{entity:string,op:string}
     */
    public function undoLatest(int $userId, ?RequestAuthority $authority = null): array
    {
        [$in, $params] = Db::in(ActivityContext::get() === 'api' ? ['api'] : self::OWN_SOURCES);
        $below = PHP_INT_MAX;
        do {
            $last = $this->db->one(
                "SELECT id, user_id, summary, details_json, format, before_json IS NULL AND after_json IS NULL AS log_only FROM mutations
                 WHERE user_id = ? AND undone = 0 AND id < ? AND source IN $in ORDER BY id DESC LIMIT 1",
                array_merge([$userId, $below], $params)
            );
            if ($last === null) {
                throw HttpError::notFound('Nothing to undo', 'nothing_to_undo');
            }
            $below = (int) $last['id'];
            // Passed over: an undo's own entry, and a line that changed no
            // row (log-only and touching nothing), whoever wrote it: it is
            // no change to take back. One that changed rows it can't put
            // back is the answer, below. A whole-series change on a Google
            // calendar whose undo is held back (GoogleUndo::seriesUndoOff)
            // is passed over too, so an older change undoes, and so is one
            // recorded in an older format (JOURNAL_FORMAT), which can't be
            // undone at all.
        } while ($this->takesBack($below) || GoogleUndo::seriesUndoOff($this->db, $last) || self::olderFormat($last)
            || ((int) $last['log_only'] === 1 && $this->db->scalar('SELECT 1 FROM mutation_touches WHERE mutation_id = ? LIMIT 1', [$below]) === null));
        if ((int) $last['log_only'] === 1) {
            throw HttpError::badRequest("The last change can't be undone here: " . $last['summary'], 'not_undoable');
        }
        return $this->undoById($userId, (int) $last['id'], false, $authority);
    }

    /**
     * The format the journal is written in (mutations.format), raised
     * whenever how an entry records what it stands on (its touches, keys,
     * snapshots) changes. record() writes it; an entry from before
     * migration 077 is format 1, and that migration made it log only (its
     * snapshots dropped, details.notUndoable OLDER_FORMAT), as 058 and 064
     * did with entries they could not carry over. An entry in an older
     * format is never undone or judged against newer ones: its undo and
     * redo refuse with not_undoable, forced or not, the bare Undo passes
     * over it, and Activity lists it as not undoable with its reason.
     * Nothing reads an older entry's grounds or snapshots; it leaves the
     * journal with retention. Not compatibility code: a version.
     */
    public const JOURNAL_FORMAT = 2;

    /** What an entry in an older format (JOURNAL_FORMAT) says, from undo, redo and the Activity list alike. */
    public const OLDER_FORMAT = "Recorded by an earlier version of Better-Cal; it can't be undone after the upgrade.";

    /** Was this entry recorded in an older journal format than this code writes (JOURNAL_FORMAT)? Pure. */
    public static function olderFormat(array $mutation): bool
    {
        return (int) ($mutation['format'] ?? 1) < self::JOURNAL_FORMAT;
    }

    /**
     * The one way any code gets an entry to undo, redo or finish undoing:
     * the live row (every column, its format among them), refused with 409
     * not_undoable and its reason when it is in an older format
     * (JOURNAL_FORMAT). With the live row gone, $copy decides: the copy a
     * pending undo kept of its entry (plan.entry, GoogleUndo::undoSeries), or
     * [] for a plan that kept none; it is taken only when it carries the
     * current format, and a copy without `format` is an older plan form,
     * refused the same way, never defaulted. With no $copy, a gone entry is
     * 404. Checks nothing else: whether the entry is already undone, still
     * has snapshots or is stale is each caller's, in its own order.
     */
    public static function entryToTakeBack(Db $db, int $userId, int $entryId, ?array $copy = null): array
    {
        $entry = $db->one('SELECT * FROM mutations WHERE id = ? AND user_id = ?', [$entryId, $userId]);
        if ($entry === null) {
            if ($copy === null) {
                throw HttpError::notFound('No such activity entry');
            }
            if ((int) ($copy['format'] ?? 0) !== self::JOURNAL_FORMAT) {
                throw HttpError::conflict('not_undoable', self::OLDER_FORMAT);
            }
            return ['user_id' => $userId] + $copy;
        }
        if (self::olderFormat($entry)) {
            $details = json_decode((string) ($entry['details_json'] ?? ''), true);
            throw HttpError::conflict('not_undoable', is_string($details['notUndoable'] ?? null) ? $details['notUndoable'] : self::OLDER_FORMAT);
        }
        // An entry a 1.5.5 development build recorded over a day row that had
        // no own list yet (migration 078 gave every live one its list): its
        // snapshot would put that form back, so it is refused, before anything.
        foreach (['before_json', 'after_json'] as $side) {
            foreach (self::tables($entry[$side] ?? null)['events'] ?? [] as $row) {
                if (is_array($row) && self::listless($row)) {
                    throw HttpError::conflict('not_undoable', self::BEFORE_LISTS);
                }
            }
        }
        return $entry;
    }

    /** Why an entry holding a day row with no own list can't be taken back (entryToTakeBack, restoreWhole). */
    public const BEFORE_LISTS = "Recorded before this day's own fields were filled in by the 1.5.5 upgrade; it can't be undone.";

    /** A day's row in a snapshot with no own list, a form no row has since migration 078. Pure. */
    private static function listless(array $row): bool
    {
        return !empty($row['recurrence_parent_id']) && ($row['recurrence_instance_utc'] ?? null) !== null
            && array_key_exists('override_fields', $row) && ($row['override_fields'] === null || $row['override_fields'] === '');
    }

    /**
     * The backstop at each point an entry's content is written back here
     * (restoreSnapshots, apply, GoogleUndo::finishUndo): an entry that did
     * not come through entryToTakeBack fails closed. One without `format` is
     * a programming error (misuse) and is refused; an older one is refused
     * with its reason. Not the gate: on the recovery path these points come
     * after Google's writes; the gate is the loader, before anything.
     */
    public static function assertCurrentFormat(array $entry, string $where): void
    {
        if (!array_key_exists('format', $entry)) {
            self::misuse($where . ' with an entry that did not come through Undo::entryToTakeBack (no format)');
            throw HttpError::conflict('not_undoable', self::OLDER_FORMAT);
        }
        if (self::olderFormat($entry)) {
            throw HttpError::conflict('not_undoable', self::OLDER_FORMAT);
        }
    }

    /**
     * Undo one specific activity entry (docs: 7-day window). Refuses
     * an entry in an older journal format (olderFormat), forced or not,
     * log-only rows (no snapshots, or snapshots already pruned) and, unless
     * $force, rows a later change has changed the ground of (staleFor):
     * undoing under those would silently clobber later edits.
     *
     * @return array{entity:string,op:string}
     */
    public function undoById(int $userId, int $mutationId, bool $force = false, ?RequestAuthority $authority = null): array
    {
        // Through the one loader: an entry recorded by an earlier version
        // (JOURNAL_FORMAT) is refused here, forced or not, before anything.
        $mutation = self::entryToTakeBack($this->db, $userId, $mutationId);
        if ((int) $mutation['undone'] === 1) {
            throw HttpError::badRequest('Already undone', 'already_undone');
        }
        if ($mutation['before_json'] === null && $mutation['after_json'] === null) {
            // Made log-only for a reason of its own (migration 064), it says so.
            $details = json_decode((string) ($mutation['details_json'] ?? ''), true);
            throw HttpError::badRequest(is_string($details['notUndoable'] ?? null) ? $details['notUndoable']
                : 'This entry is log-only and cannot be undone (automated or older than the 7-day undo window)', 'not_undoable');
        }
        // Undo and redo of a whole-series change on a Google calendar wait for the setting (Settings google_series_undo).
        if (GoogleUndo::seriesUndoOff($this->db, $mutation)) {
            throw HttpError::conflict('not_undoable', GoogleUndo::SERIES_UNDO_OFF);
        }
        // A series with changes not yet at Google takes no undo or redo until they are finished or put back.
        GoogleUndo::refuseWhilePending($this->db, $this, $mutation);
        $details = json_decode((string) ($mutation['details_json'] ?? ''), true);
        if (GoogleUndo::carries(is_array($details) ? $details : null)) {
            // A write to one Google resource: its inverse goes to Google
            // first (GoogleUndo::undo), not inside a transaction. The stale
            // check here is the journal's; Google's own (If-Match) follows.
            // A day that left its series is said so first, plainly.
            GoogleUndo::refuseLeftSeriesOf($this->db, $this, $mutation);
            if (!$force && ($why = $this->staleFor($mutation)) !== null) {
                throw HttpError::badRequest($why, 'stale_undo');
            }
            return (new GoogleUndo($this->db, $this, $this->google))->undo($mutation, $authority, $force);
        }
        if ((string) $mutation['entity'] === 'plugin_edit') {
            // Sends to the plugin's source first: not inside a transaction.
            if (!$force && ($why = $this->staleFor($mutation)) !== null) {
                throw HttpError::badRequest($why, 'stale_undo');
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
            } catch (HttpError $e) {
                // An entry's own refusal (HttpError) is counted; any other
                // failure is the run's and propagates, never a silent skip.
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
        // source is changed, before every request the plugin sends for it,
        // and again when the result is saved here.
        $authority?->assertValid($this->db, false);
        $result = $plugins->applyEdit($userId, $pluginId, [
            'sourceKey' => str_starts_with($uid, $prefix) ? substr($uid, strlen($prefix)) : $uid,
            'eventId' => (int) $event['id'],
            'calendarId' => (int) $event['calendar_id'],
            'title' => (string) $event['title'],
            'before' => Events::pluginSpan((string) $event['start_utc'], (string) $event['end_utc'], (int) $event['all_day'] === 1),
            'after' => $span,
        ], $authority);
        // A time the plugin confirms with no offset is the owner's local
        // time (Settings::localZone), read as its sync and the move itself
        // read it.
        [$startUtc, $endUtc, $allDay] = \BetterCal\Plugin\PluginHost::spanToUtc($result, Settings::localZone($this->db, $userId));
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
        self::assertCurrentFormat($mutation, 'Undo::apply');
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
            ($authority ?? RequestAuthority::current())?->assertValid($this->db);
            // The calendars it named when recorded, as well as those its rows
            // show: a trip link's, or one deleted since.
            foreach ($this->db->all('SELECT calendar_id FROM mutation_calendar_refs WHERE mutation_id = ?', [$mutation['id']]) as $ref) {
                $calendarIds[] = (int) $ref['calendar_id'];
            }
            CalendarMoveGuard::lockAndAssertMutable($this->db, $calendarIds);
            // On a Google calendar (then or now) only what is held here
            // alone is put back here: Google's own rows and columns are a
            // provider cache the next poll overwrites (heldHereAlone).
            $google = self::googleCalendarsIn($before, $after);
            foreach (array_values(array_unique($calendarIds)) as $calendarId) {
                $calendar = CalendarMoveGuard::lockCalendar($this->db, $calendarId);
                if ($calendar !== null && (string) $calendar['kind'] === 'subscribed'
                    && (string) ($calendar['provider'] ?? '') === 'google') {
                    $google[] = $calendarId;
                }
            }
            if ($google !== [] && !self::heldHereAlone($before, $after, $google)) {
                throw HttpError::badRequest('Google calendar changes cannot be undone here', 'not_undoable');
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
            if ($google !== [] && array_intersect(['events', 'calendars'], array_merge([], ...array_values($pieces))) !== []) {
                // Event or calendar rows made or removed in bulk: on a Google calendar, Google's.
                throw HttpError::badRequest('Google calendar changes cannot be undone here', 'not_undoable');
            }
            foreach ($pieces as $side => $tables) {
                foreach ($tables as $table) {
                    foreach ($this->piecesOf($mutationId, $side, $table) as $rows) {
                        $this->lockRows([$table => $rows], []);
                        $eventIds = array_merge($eventIds, self::eventIdsOf([$table => $rows]));
                    }
                }
            }
            // What a day's row it brings back is, decided by provider here alone: on a calendar here the
            // snapshot as it was (snapshotOnto); on a Google calendar composed over the day as it stands
            // (overCurrent), a day skipped now left alone and named in $dropped. Worked out before the
            // stale check, since both ask the gate (dayNow): what force cannot pass is never answered as stale.
            $dropped = [];
            $restore = $google === [] ? $this->snapshotOnto($before, $after, $mutationId, $dropped) : $this->overCurrent($before, $after, $mutationId, [], $dropped);
            if (!$force && ($why = $this->staleFor($mutation)) !== null) {
                throw HttpError::badRequest($why, 'stale_undo');
            }
            $removed = array_merge($this->putBack($mutation, $restore, $after, $pieces, $eventIds), $dropped);
            $this->db->run('UPDATE mutations SET undone = 1 WHERE id = ?', [$mutation['id']]);
            // The rows the undo removed, named by row and date in a line of its own (it touches nothing).
            if ($removed !== []) {
                $this->record((int) $mutation['user_id'], (string) $mutation['entity'], (int) $mutation['entity_id'], 'update', null, null,
                    'Undo removed here: ' . Feeds::droppedWords($removed) . '.', ['removedDays' => $removed]);
            }
        });

        return ['entity' => (string) $mutation['entity'], 'op' => (string) $mutation['op']];
    }

    /**
     * Put back what an entry changed (its snapshots, and the rows kept in
     * pieces): rows it made deleted, rows it removed back whole, rows it
     * changed given back the columns it changed, the settings it changed;
     * then the trip rules over what that leaves, and CalDAV told. Inside
     * the account's write, with the rows locked (lockRows).
     *
     * @param array<string,list<string>> $pieces
     * @param list<int> $eventIds
     */
    private function putBack(array $mutation, array $before, array $after, array $pieces, array $eventIds): array
    {
        // Lists put back by their deltas over what they hold now, never whole.
        $before = $this->ownDeltas($before, $after);
        $mutationId = (int) $mutation['id'];
        $flaggedBefore = Trips::flaggedAmong($this->db, $eventIds);
        // The days' rows whose own part this undo changes: the retention rule's (below).
        $ownChanged = [];
        // Delete rows created by the mutation (present in after, absent from before).
        foreach (array_reverse(self::order()) as $table) {
            $beforeKeys = [];
            foreach ($before[$table] ?? [] as $row) {
                $beforeKeys[self::keyOf($table, $row)] = true;
            }
            foreach ($after[$table] ?? [] as $row) {
                if (!isset($beforeKeys[self::keyOf($table, $row)])) {
                    if ($table === 'events' && ($row['recurrence_instance_utc'] ?? null) !== null) {
                        // A day's row it made: the day as it was, an
                        // occurrence with nothing of its own from this entry,
                        // composed over the row as it stands; whether the
                        // row stays is the retention rule's (below).
                        $this->withoutOwn($row, $after);
                        $ownChanged[] = $row;
                        continue;
                    }
                    $this->deleteByPk($table, $row);
                }
            }
            // Rows it made kept in pieces: each one made, none in before.
            if (in_array($table, $pieces['after'] ?? [], true)) {
                foreach ($this->piecesOf($mutationId, 'after', $table) as $rows) {
                    if ((self::pk($table)) === ['id']) {
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
        foreach (self::order() as $table) {
            foreach ($before[$table] ?? [] as $row) {
                $kept = $afterByKey[$table][self::keyOf($table, $row)] ?? null;
                if ($kept !== null && $table === 'events' && ($row['recurrence_instance_utc'] ?? null) !== null
                    && $this->db->scalar('SELECT 1 FROM events WHERE id = ?', [(int) $row['id']]) === null) {
                    $kept = null; // a day's row gone since: back whole, as composed (overCurrent)
                }
                $changed = null;
                if ($kept !== null) {
                    $changed = self::changedColumns($table, $row, $kept);
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
                        $now = $this->db->scalar("SELECT `$col` FROM `$table` WHERE " . implode(' AND ', array_map(static fn($c) => "`$c` = ?", self::pk($table))),
                            array_map(static fn($c) => $row[$c] ?? null, self::pk($table)));
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
                if ($table === 'events' && $changed !== null && in_array('override_fields', $changed, true)) {
                    $ownChanged[] = $row;
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
        // Each day's row whose own part it changed stays only while it owns
        // anything or holds anything here alone: the retention rule, the
        // one every remover applies (Feeds::retained). Before the rows
        // follow their series, which gives a row the series' tags and people.
        $this->retain($ownChanged);
        // Every row of a series it put back follows that series as restored
        // (followRestored): the rows past a shortened end go, named.
        $removed = $this->followRestored($before, $after);
        // Then each series it touched, as it now stands: a day's row it
        // brought back whole, kept or changed shows the series' tags and
        // people where it doesn't own them, never the copy its snapshot held.
        $this->followLinks($before, $after);
        // What it put back passes the trip rules as it now stands, under
        // force too: a refusal throws, rolling all of it back, and the
        // entry stays to undo once the conflict is gone.
        Trips::assertRules($this->db, $eventIds, $flaggedBefore, Trips::BY_UNDO);
        // CalDAV hears of it in this transaction, the one that checked
        // the credential: the undo and its journal commit together or not
        // at all. Recording MODIFY is enough (sync clients drop uris that
        // 404), each uid once per calendar, and a calendar's all at once:
        // one at a time, a large calendar's took half a minute.
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
        return $removed;
    }

    /**
     * The fields of series $seriesId an entry changed, read from its two
     * snapshots: each content column of the master that differs, and its
     * tags or people where its links differ; none when the entry removed
     * the series whole, whose restore brings its rows back with it as
     * they were. The fields a restore of that entry puts back on the
     * series, so the ones its days follow again (followRestored). Pure.
     *
     * @return list<string>
     */
    private static function seriesFieldsChanged(int $seriesId, array $before, array $after): array
    {
        $masterOf = static function (array $tables) use ($seriesId): ?array {
            foreach ($tables['events'] ?? [] as $r) {
                if (is_array($r) && (int) ($r['id'] ?? 0) === $seriesId) {
                    return $r;
                }
            }
            return null;
        };
        [$was, $now] = [$masterOf($before), $masterOf($after)];
        if ($was === null || $now === null) {
            return [];
        }
        $fields = [];
        foreach (self::changedColumns('events', $was, $now) as $col) {
            if (($field = OwnFields::fieldOf($col)) !== null) {
                $fields[] = $field;
            }
        }
        foreach (self::LINK_FIELDS as $table => $field) {
            $set = static function (array $tables) use ($table, $seriesId): array {
                $ids = [];
                foreach ($tables[$table] ?? [] as $r) {
                    if (is_array($r) && (int) ($r['event_id'] ?? 0) === $seriesId) {
                        $ids[] = json_encode($r);
                    }
                }
                sort($ids);
                return $ids;
            };
            if ($set($before) !== $set($after)) {
                $fields[] = $field;
            }
        }
        return array_values(array_unique($fields));
    }

    /**
     * Every day's row of each series whose master the restore put back,
     * made to follow that series as it now stands, as a forward change
     * leaves them: in each field the restore puts back on the series
     * (seriesFieldsChanged) that the row doesn't own (Feeds::ownOf), tags
     * and people included, the series' value, through the one composition
     * (Feeds::followSeries), so a row made after the undone change never
     * keeps a value or a link copied from it, and a field the change left
     * alone is left alone on every row; and a row
     * whose day the series no longer has (a count or rule put back
     * shorter) removed, named by its row and date as the sync names one
     * (Feeds::droppedDay). The same on a calendar here and on a Google
     * calendar, where Google's answer is then stored on top through the
     * same functions (GoogleSeries::store). Returns the rows removed.
     *
     * @return list<array>
     */
    private function followRestored(array $before, array $after): array
    {
        $removed = [];
        $feeds = new Feeds($this->db);
        foreach ($before['events'] ?? [] as $m) {
            if (($m['recurrence_instance_utc'] ?? null) !== null || empty($m['rrule']) || !isset($m['id'])) {
                continue;
            }
            $master = $this->db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [(int) $m['id']]);
            if ($master === null || empty($master['rrule'])) {
                continue;
            }
            $fields = self::seriesFieldsChanged((int) $m['id'], $before, $after);
            foreach ($this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [(int) $master['id']]) as $row) {
                $at = Feeds::ofSeries($row, null, ['exdates_json' => null] + $master);
                $own = $feeds->ownOf($row, $master);
                if ($at === null) {
                    $removed[] = Feeds::droppedDay($row, Feeds::heldHere($own, $row), OwnFields::references($this->db, [(int) $row['id']])[(int) $row['id']] ?? [], 'gone');
                    $this->db->run('DELETE FROM events WHERE id = ?', [(int) $row['id']]);
                    continue;
                }
                Feeds::followSeries($this->db, $row, $master, $own, $fields);
            }
        }
        return $removed;
    }

    /**
     * An entry's snapshots put back here, for a Google series undo
     * (GoogleUndo) once Google holds the before state: the rows'
     * identities, tags, people and own lists, which GoogleSeries::store
     * then syncs Google's values onto. Inside write() for the entry's
     * account. Returns the days' rows it did not bring back (a day skipped
     * now, overCurrent), named (Feeds::droppedDay); the days its series
     * no longer has are named by the caller's plan (GoogleUndo::inverseOf).
     *
     * @return list<array>
     */
    public function restoreSnapshots(array $mutation, bool $hereAlone = false, array $placed = []): array
    {
        if (!isset(self::$writing[spl_object_id($this->db) . ':' . (int) $mutation['user_id']])) {
            self::misuse('Undo::restoreSnapshots outside Undo::write');
        }
        self::assertCurrentFormat($mutation, 'Undo::restoreSnapshots');
        $before = self::tables($mutation['before_json']);
        $after = self::tables($mutation['after_json']);
        if ($hereAlone) {
            $before = self::googleHeldAsAfter($before, $after);
        }
        $dropped = [];
        $before = $this->overCurrent($before, $after, (int) $mutation['id'], $placed, $dropped);
        $mutationId = (int) $mutation['id'];
        $this->lockRows($before, $after);
        $eventIds = array_merge(self::eventIdsOf($before), self::eventIdsOf($after));
        $pieces = $this->pieceTables($mutationId);
        foreach ($pieces as $side => $tables) {
            foreach ($tables as $table) {
                foreach ($this->piecesOf($mutationId, $side, $table) as $rows) {
                    $eventIds = array_merge($eventIds, self::eventIdsOf([$table => $rows]));
                }
            }
        }
        $this->putBack($mutation, $before, $after, $pieces, $eventIds);
        return $dropped;
    }

    /**
     * $before with what Google holds of each event row (GOOGLE_HELD) as it
     * is in $after, so putting it back leaves those columns to Google: an
     * edit's undo sends them to Google and puts back only the rest here
     * (GoogleUndo). A place's text and the coordinates picked for it are
     * one value: where the entry changed the coordinates, the text goes
     * back with them in the same write, so nothing after (Google's answer
     * stored on top) ever sees the text change without its coordinates and
     * drops them. Pure.
     */
    private static function googleHeldAsAfter(array $before, array $after): array
    {
        $now = [];
        foreach ($after['events'] ?? [] as $row) {
            $now[self::keyOf('events', $row)] = $row;
        }
        foreach ($before['events'] ?? [] as $i => $row) {
            $kept = $now[self::keyOf('events', $row)] ?? null;
            if ($kept !== null) {
                $placed = false;
                foreach (OwnFields::COORDINATE_COLUMNS as $col) {
                    $placed = $placed || (string) ($row[$col] ?? '') !== (string) ($kept[$col] ?? '');
                }
                foreach (self::GOOGLE_HELD['events'] as $col) {
                    if (array_key_exists($col, $kept) && !($placed && $col === 'location')) {
                        $before['events'][$i][$col] = $kept[$col];
                    }
                }
            }
        }
        return $before;
    }

    /**
     * A snapshot undo's rows on a calendar here: the snapshot, exactly as
     * it was, the local-only data with it. Its composition is the snapshot,
     * never the day as it stands (overCurrent is a Google calendar's). The
     * one thing it takes from now: where a row brings back a day (one its
     * entry removed, deleted, took out of the series or moved off that
     * day) and another row holds that day now (a later change made one
     * since), the snapshot row is that row: it takes that row's id, so
     * what refers to either names the one row and nothing meets on the
     * day's key, and every other column is the snapshot's. And a skip is
     * the day's value as it stands: a row brought back onto a day skipped
     * now that this restore leaves skipped is not brought back and is
     * named in $dropped, as on a Google calendar (skipStands), so the two
     * agree. A snapshot is of the series as it was, so it comes back only
     * where it is still of the series as it stands (the gate, dayNow's one
     * definition, asked here before staleness and under force alike): a row
     * it brings back or a skip it puts back or takes away is refused once a
     * later change re-placed its series' days (snapshotStands), never
     * composed onto another key; a row is not brought back to a key the
     * series no longer has; the undo of a change that re-placed a series is
     * refused once a day or a skip of it was changed after (noDaysSince);
     * and a row brought back stands in the calendar its series is in now.
     */
    private function snapshotOnto(array $before, array $after, int $entryId, array &$dropped = []): array
    {
        $kept = [];
        foreach ($after['events'] ?? [] as $row) {
            $kept[self::keyOf('events', $row)] = $row;
        }
        // Where this restore puts each row it puts back: one it moves off a day leaves that day.
        $restored = [];
        foreach ($before['events'] ?? [] as $row) {
            $restored[(int) ($row['id'] ?? 0)] = ($row['recurrence_instance_utc'] ?? null) === null ? null : (string) $row['recurrence_instance_utc'];
        }
        // The gate (dayNow's, the one definition): a snapshot is written as it was, so what it holds of one day comes
        // back only where nothing re-placed that day's series since, forced or not.
        $mastersBack = []; // the series this restore itself puts back, by id
        foreach ($before['events'] ?? [] as $m) {
            if (!is_array($m) || !isset($m['id']) || ($m['recurrence_instance_utc'] ?? null) !== null || empty($m['rrule'])) {
                continue;
            }
            $left = $kept[self::keyOf('events', $m)] ?? null;
            $live = $this->db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [(int) $m['id']]);
            if ($left === null || $live === null) {
                $mastersBack[(int) $m['id']] = true;
                continue;
            }
            $changed = self::changedColumns('events', $m, $left);
            if ($this->carriesDays($entryId, $this->groundsOn($entryId, self::keyOf('events', $m)), $live)) {
                // It puts the series' time, zone or form back: every day changed after it would be left where
                // the series as it was has another day, and nothing recorded where each belongs there.
                $mastersBack[(int) $m['id']] = true;
                $this->noDaysSince($entryId, $live);
                continue;
            }
            if ($changed === ['exdates_json']) {
                // The skips a change to one day puts back or takes away, each one day's (a change of the series' rule or
                // bound takes its skips with it, and they go back with it).
                $skips = static function (array $r): array {
                    try {
                        return Recurrence::decodeExdates($r['exdates_json'] ?? null);
                    } catch (\InvalidArgumentException) {
                        return [];
                    }
                };
                foreach (array_merge(array_diff($skips($m), $skips($left)), array_diff($skips($left), $skips($m))) as $key) {
                    $this->snapshotStands($entryId, $live, (string) $key);
                }
            }
        }
        foreach ($before['events'] ?? [] as $row) {
            if (($row['recurrence_instance_utc'] ?? null) === null || !isset($row['id'])) {
                continue;
            }
            $was = $kept[self::keyOf('events', $row)] ?? null;
            $onto = $was === null
                || (($was['deleted_at'] ?? null) !== null && ($row['deleted_at'] ?? null) === null)
                || !self::same($was['calendar_id'] ?? null, $row['calendar_id'] ?? null) || !self::same($was['uid'] ?? null, $row['uid'] ?? null)
                || !self::same($was['recurrence_instance_utc'] ?? null, $row['recurrence_instance_utc'] ?? null)
                || $this->db->scalar('SELECT 1 FROM events WHERE id = ?', [(int) $row['id']]) === null;
            if (!$onto) {
                continue;
            }
            $series = empty($row['recurrence_parent_id']) ? null : $this->db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [(int) $row['recurrence_parent_id']]);
            // Its series as this restore leaves it: one the restore itself puts back is the snapshot's, row and series together.
            if ($series !== null && !isset($mastersBack[(int) $series['id']])) {
                $this->snapshotStands($entryId, $series, (string) $row['recurrence_instance_utc']);
                if (!self::same($series['calendar_id'], $row['calendar_id'] ?? null)) {
                    // The row stands in the calendar its series is in now (a move of calendar re-places no day).
                    $row['calendar_id'] = $series['calendar_id'];
                    $before = self::inCalendar($before, (int) $row['id'], (int) $series['calendar_id']);
                }
            }
            $now = $this->db->one('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc = ? AND id <> ? AND deleted_at IS NULL',
                [(int) $row['calendar_id'], (string) $row['uid'], (string) $row['recurrence_instance_utc'], (int) $row['id']]);
            if ($now !== null && array_key_exists((int) $now['id'], $restored) && $restored[(int) $now['id']] !== (string) $row['recurrence_instance_utc']) {
                $now = null; // a row this restore moves off the day leaves it (Events::parkInstanceKeys)
            }
            if ($now === null) {
                if ($series !== null && $this->skipStands($entryId, $series, $before, $after, (string) $row['recurrence_instance_utc'])) {
                    $dropped[] = Feeds::droppedDay($row, Feeds::heldHere(OwnFields::stored($row), $row), [], 'gone');
                    $before = self::withoutEvent($before, (int) $row['id']);
                }
                continue; // nothing holds its day now: the snapshot row comes back as it was
            }
            $before = self::renamed($before, (int) $row['id'], (int) $now['id']);
        }
        return $before;
    }

    /**
     * The gate for what a snapshot holds of one day on a calendar here (a
     * row it brings back, a skip it puts back or takes away): refused where
     * a later change re-placed the day's series (replacing), since the
     * snapshot is of the series as it was and is written as it was; and
     * where the series no longer has the day (dayNow). Forced or not.
     */
    private function snapshotStands(int $entryId, array $series, string $key): void
    {
        $userId = (int) $this->db->scalar('SELECT user_id FROM mutations WHERE id = ?', [$entryId]);
        $since = $this->replacing($series, $userId, $entryId)['entries'];
        if ($since !== []) {
            throw new HttpError('not_undoable', 'This series ' . self::replacedWords(reset($since))
                . " since this was done, and what this change held is of the series as it was, so it can't be undone here. You can make the change again on the day.",
                400, ['reason' => self::DAY_UNPLACED]);
        }
        $this->dayNow($entryId, $series, $key);
    }

    /**
     * The gate for an undo that puts a series' time, zone or form back on a
     * calendar here: refused where a later change, not undone, touched a
     * day or a skip of that series. Those days are where the series as it
     * is has them, and nothing recorded where each belongs in the series as
     * it was; read by their keys there they are other days. Forced or not.
     */
    private function noDaysSince(int $entryId, array $series): void
    {
        $userId = (int) $this->db->scalar('SELECT user_id FROM mutations WHERE id = ?', [$entryId]);
        foreach ($this->db->all(
            "SELECT DISTINCT m.id FROM mutation_touches t JOIN mutations m ON m.id = t.mutation_id
             WHERE t.row_key = ? AND m.user_id = ? AND m.id > ? AND m.undone = 0 AND (t.col LIKE ? OR t.col LIKE ?) ORDER BY m.id",
            [self::keyOf('events', $series), $userId, $entryId, self::DAY . '%', self::SKIP . '%']
        ) as $later) {
            if (!$this->revertsSince((int) $later['id'], $entryId)) {
                throw new HttpError('not_undoable', "Days of this series were changed after this, and putting its time back would leave them on other days, so this can't be undone here. You can change the time back by hand.",
                    400, ['reason' => self::DAYS_SINCE]);
            }
        }
    }

    /** $tables with one event row's calendar set. Pure. */
    private static function inCalendar(array $tables, int $eventId, int $calendarId): array
    {
        foreach ($tables['events'] ?? [] as $i => $r) {
            if (is_array($r) && (int) ($r['id'] ?? 0) === $eventId) {
                $tables['events'][$i]['calendar_id'] = $calendarId;
            }
        }
        return $tables;
    }

    /**
     * A day's row an undo or redo brings back (in $before, and in $after
     * not at all, deleted, or gone since), composed over the day as it
     * stands now. Where a row holds that day now (a poll or a later change
     * made one since), it is that row: the row brought back takes its id,
     * so what refers to either names the one row, and nothing is ever
     * written over another row's day; what that row owns stays its own.
     * Else the day is an ordinary occurrence, and the row takes what its
     * series holds there (Feeds::ofSeries, as the sync follows a row);
     * never from the snapshot, which shows the series as it was. Over that,
     * the restored row's own part is applied last and whole, its place text
     * and coordinates as one value; on a Google calendar Google's answer to
     * the undo is stored on top. The day is its series and its occurrence:
     * one a later change carried to another occurrence (dayNow) comes back
     * there, and one a planned undo places (a Google series undo, $placed)
     * comes back where the undo's series has it. A day skipped now that the
     * restore leaves skipped has nothing to compose onto: the row is not
     * brought back, and is named in $dropped (Feeds::droppedDay). A Google
     * calendar's composition alone, in the check and the restore; a calendar
     * here puts its snapshot back (snapshotOnto).
     *
     * @param list<array> $dropped
     */
    private function overCurrent(array $before, array $after, int $entryId, array $placed = [], array &$dropped = []): array
    {
        $kept = [];
        foreach ($after['events'] ?? [] as $row) {
            $kept[self::keyOf('events', $row)] = $row;
        }
        // Where this restore puts each row it puts back: one it moves off a day leaves that day.
        $restored = [];
        foreach ($before['events'] ?? [] as $row) {
            $restored[(int) ($row['id'] ?? 0)] = ($row['recurrence_instance_utc'] ?? null) === null ? null : (string) $row['recurrence_instance_utc'];
        }
        foreach ($before['events'] ?? [] as $i => $row) {
            if (($row['recurrence_instance_utc'] ?? null) === null) {
                continue;
            }
            $was = $kept[self::keyOf('events', $row)] ?? null;
            // A row it kept off this day (deleted, or taken out of the
            // series, or moved) comes back onto the day: where a row holds
            // that day now, onto that row, as one it removed does.
            $onto = $was !== null && (($was['deleted_at'] ?? null) !== null && ($row['deleted_at'] ?? null) === null
                || !self::same($was['calendar_id'] ?? null, $row['calendar_id'] ?? null) || !self::same($was['uid'] ?? null, $row['uid'] ?? null)
                || !self::same($was['recurrence_instance_utc'] ?? null, $row['recurrence_instance_utc'] ?? null));
            if ($was !== null && !$onto) {
                // A row it kept that is gone since (a later undo took it back to an
                // occurrence): brought back whole, owning what this entry gave it alone.
                if (!isset($row['id']) || $this->db->scalar('SELECT 1 FROM events WHERE id = ?', [(int) $row['id']]) !== null) {
                    continue;
                }
                $a = OwnFields::stored($was);
                $b = OwnFields::stored($row);
                $row['override_fields'] = $before['events'][$i]['override_fields'] = OwnFields::encode(array_values(array_diff($b, $a)));
            }
            $series = empty($row['recurrence_parent_id']) ? null : $this->db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [(int) $row['recurrence_parent_id']]);
            // Where a planned undo puts the day ($placed: row id => its key in the series the undo restores), else where later changes carried it.
            $at = $placed[(int) ($row['id'] ?? 0)] ?? ($series === null ? null : $this->dayNow($entryId, $series, (string) $row['recurrence_instance_utc']));
            $key = $at ?? (string) $row['recurrence_instance_utc'];
            $now = $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc = ? AND id <> ? AND deleted_at IS NULL',
                [(int) $row['calendar_id'], (string) $row['uid'], $key, (int) $row['id']]);
            if ($now !== null && array_key_exists((int) $now['id'], $restored) && $restored[(int) $now['id']] !== $key) {
                $now = null; // a row this restore moves off the day leaves it (Events::parkInstanceKeys)
            }
            if ($now === null && $series !== null && $this->skipStands($entryId, $series, $before, $after, $key)) {
                // A skip is the day's value as it stands, and this restore
                // leaves it: nothing to compose onto, so the row is not
                // brought back (one the entry kept off the day goes with
                // the entry), and what it held is named as removed.
                $dropped[] = Feeds::droppedDay($row, Feeds::heldHere(OwnFields::stored($row), $row), [], 'gone');
                $before = self::withoutEvent($before, (int) $row['id']);
                continue;
            }
            if ($onto && $now === null) {
                continue; // nothing holds its day now: the columns it changed go back as they were
            }
            if ($series !== null && ($gid = Feeds::googleIdAt($row, $series, $key)) !== null) {
                // The occurrence it stands at, in its series' form now (Feeds::googleIdAt), on a key that moved or not.
                $row['google_event_id'] = $before['events'][$i]['google_event_id'] = $gid;
            }
            $row['recurrence_instance_utc'] = $before['events'][$i]['recurrence_instance_utc'] = $key;
            if ($now !== null) {
                $before = self::renamed($before, (int) $row['id'], (int) $now['id']);
                $row = $before['events'][$i];
            }
            $own = OwnFields::stored($row);
            $nowOwn = [];
            if ($now !== null) {
                $composed = $now;
                $nowOwn = OwnFields::stored($now);
            } else {
                $master = $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL',
                    [(int) $row['calendar_id'], (string) $row['uid']]);
                $over = $master === null ? null : Feeds::ofSeries($row, null, $master);
                if ($over === null) {
                    continue;
                }
                $composed = Feeds::followed($row, array_intersect_key($over, array_flip(GoogleWriter::GOOGLE_COLUMNS)));
            }
            // The restored row's own part, applied last and whole: each
            // field it owns at its value (a place's text with its
            // coordinates, one value).
            foreach ($own as $field) {
                foreach (OwnFields::columnsOf($field) as $col) {
                    if (array_key_exists($col, $row)) {
                        $composed[$col] = $row[$col];
                    }
                }
            }
            $composed['override_fields'] = OwnFields::encode(OwnFields::normalize(array_merge($own, $nowOwn)));
            $before['events'][$i] = $composed;
        }
        return $before;
    }

    /**
     * A day's own list put back by its delta, never by its snapshot: for a
     * row the entry kept, what the entry added to the list goes and what it
     * took off comes back, over the list as it stands now, so a field a
     * later change gave or took (tags a later change for all events set,
     * say) stays as that change left it.
     */
    private function ownDeltas(array $before, array $after): array
    {
        $kept = [];
        foreach ($after['events'] ?? [] as $row) {
            $kept[self::keyOf('events', $row)] = $row;
        }
        foreach ($before['events'] ?? [] as $i => $row) {
            $was = $kept[self::keyOf('events', $row)] ?? null;
            // A series' skips likewise: each date the entry skipped comes
            // back, each it brought back is skipped again, and a date skipped
            // since stays skipped (one skip is its own date's: touchesOf).
            if ($was !== null && array_key_exists('exdates_json', $row) && ($row['exdates_json'] ?? null) !== ($was['exdates_json'] ?? null)) {
                try {
                    $b = Recurrence::decodeExdates($row['exdates_json'] ?? null);
                    $a = Recurrence::decodeExdates($was['exdates_json'] ?? null);
                    $n = Recurrence::decodeExdates($this->db->scalar('SELECT exdates_json FROM events WHERE id = ?', [(int) $row['id']]));
                    $list = array_values(array_unique(array_merge(array_diff($n, array_diff($a, $b)), array_diff($b, $a))));
                    sort($list);
                    $sortedB = $b;
                    sort($sortedB);
                    // Nothing skipped or brought back since: the list exactly as it was.
                    if ($list !== $sortedB) {
                        $before['events'][$i]['exdates_json'] = $list === [] ? null : json_encode($list);
                    }
                } catch (\InvalidArgumentException) {
                }
            }
            if ($was === null || !array_key_exists('override_fields', $row) || ($row['recurrence_instance_utc'] ?? null) === null) {
                continue;
            }
            $b = OwnFields::stored($row);
            $a = OwnFields::stored($was);
            $now = $this->db->one('SELECT * FROM events WHERE id = ?', [(int) $row['id']]);
            if ($now === null || $b === $a) {
                continue;
            }
            $n = OwnFields::stored($now);
            $list = OwnFields::normalize(array_merge(array_diff($n, array_diff($a, $b)), array_diff($b, $a)));
            if ($list !== OwnFields::normalize($b)) {
                $before['events'][$i]['override_fields'] = OwnFields::encode($list);
            }
        }
        return $before;
    }

    /**
     * A day's row an undo takes back the making of ($made, as the entry
     * made it), composed over the row as it stands: the fields the entry
     * gave it follow its series again (the series' value at that day now),
     * and what it owns besides, given by a later change the undo is not
     * stale against, stays its own. Whether the row stays is the retention
     * rule's (retain).
     */
    private function withoutOwn(array $made, array $after): void
    {
        $now = isset($made['id']) ? $this->db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [(int) $made['id']]) : null;
        if ($now === null) {
            return;
        }
        $gave = OwnFields::stored($made);
        $owns = OwnFields::stored($now);
        $master = $this->db->one('SELECT * FROM events WHERE id = ?', [(int) ($now['recurrence_parent_id'] ?? 0)]);
        $series = $master === null ? null : Feeds::ofSeries($now, null, $master);
        $set = ['override_fields' => OwnFields::encode(array_values(array_diff($owns, $gave)))];
        foreach ($gave as $field) {
            foreach (OwnFields::columnsOf($field) as $col) {
                if ($series !== null && array_key_exists($col, $series)) {
                    $set[$col] = $series[$col];
                } elseif (in_array($col, OwnFields::COORDINATE_COLUMNS, true) && array_key_exists($col, $now)) {
                    // The series' place is its text and its coordinates, one value.
                    $set[$col] = $series === null ? null : ($master[$col] ?? null);
                }
            }
        }
        $this->db->update('events', $set, 'id = ?', [(int) $now['id']]);
    }

    /**
     * The retention rule over the day rows an undo put back or changed: a
     * row that owns nothing and holds nothing here alone (Feeds::retained,
     * the one function: the fields it owns that are held here alone, tags
     * and people among them, and what refers to it, trip links included)
     * is an ordinary occurrence, with no row, as the sync leaves one. Tags
     * and people a row has without owning them are its series' copy, never
     * a reason of their own to keep it. A row that stays takes the tags
     * and people it doesn't own from its series as it stands once the
     * undo is done (followLinks, over every series it touched), so the
     * copy an entry made with the row never leaves a kept day without its
     * series' tags; a set the row owns went back by its delta and is left
     * as it is.
     *
     * @param list<array> $rows
     */
    private function retain(array $rows): void
    {
        $seen = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id]) || ($r['recurrence_instance_utc'] ?? null) === null) {
                continue;
            }
            $seen[$id] = true;
            $now = $this->db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [$id]);
            if ($now !== null && ($now['recurrence_instance_utc'] ?? null) !== null
                && OwnFields::stored($now) === [] && Feeds::retained($this->db, $now) === null) {
                $this->db->run('DELETE FROM events WHERE id = ?', [$id]);
            }
        }
    }

    /**
     * Once an undo or redo has put back what it puts back: every series
     * that any row it touched stands under now (each event a row of its
     * snapshots names, in any table, its tags, people and references
     * included, read from the database after the restore, never from the
     * parent a snapshot names) is followed whole: every day of it takes the
     * tags and people it doesn't own from the series, through the one
     * function every writer uses (OwnFields::follow); a set a day owns is
     * left as it is. A link row put back onto a day that no longer owns
     * that field is the series' copy, so the day holds exactly the series'
     * set. An undo of a person's delete or merge is no different from one
     * of a tag's. Rows an entry kept in pieces (a calendar's events, every
     * row of a calendar deleted whole) come back whole with their series
     * and its links, as they were, and are not read again.
     */
    private function followLinks(array $before, array $after): void
    {
        // Every event a row of the entry names, in any table (its own row,
        // its tags and people, what refers to it), read where it stands now.
        $named = [];
        $cols = ['events' => ['id'], 'event_tags' => ['event_id'], 'event_people' => ['event_id']];
        foreach (OwnFields::REFERENCES as [$table, , $eventCols]) {
            $cols[$table] = $eventCols;
        }
        foreach ([$before, $after] as $tables) {
            foreach ($cols as $table => $names) {
                foreach ($tables[$table] ?? [] as $r) {
                    foreach ($names as $col) {
                        if (is_array($r) && (int) ($r[$col] ?? 0) > 0) {
                            $named[(int) $r[$col]] = true;
                        }
                    }
                }
            }
        }
        $series = [];
        foreach (array_chunk(array_keys($named), 500) as $chunk) {
            [$in, $params] = Db::in($chunk);
            foreach ($this->db->all("SELECT id, recurrence_parent_id, rrule FROM events WHERE id IN $in AND deleted_at IS NULL", $params) as $r) {
                $id = !empty($r['recurrence_parent_id']) ? (int) $r['recurrence_parent_id'] : (!empty($r['rrule']) ? (int) $r['id'] : 0);
                if ($id > 0) {
                    $series[$id] = true;
                }
            }
        }
        foreach (array_keys($series) as $masterId) {
            OwnFields::follow($this->db, $masterId);
        }
    }

    /**
     * $tables with event $from named $to: the row itself, and every row of
     * what hangs off it or refers to it (tags, people, OwnFields::REFERENCES)
     * that names it. Pure.
     */
    private static function renamed(array $tables, int $from, int $to): array
    {
        $cols = ['events' => ['id', 'recurrence_parent_id'], 'event_tags' => ['event_id'], 'event_people' => ['event_id']];
        foreach (OwnFields::REFERENCES as [$table, , $eventCols]) {
            $cols[$table] = array_merge($cols[$table] ?? [], $eventCols);
        }
        foreach ($cols as $table => $names) {
            foreach ($tables[$table] ?? [] as $i => $r) {
                foreach ($names as $col) {
                    if (isset($r[$col]) && (int) $r[$col] === $from) {
                        $tables[$table][$i][$col] = $to;
                    }
                }
            }
        }
        return $tables;
    }

    /**
     * $tables without event $id: the row itself, and every row of what
     * hangs off it or refers to it that names it (as renamed). Pure.
     */
    private static function withoutEvent(array $tables, int $id): array
    {
        $cols = ['events' => ['id'], 'event_tags' => ['event_id'], 'event_people' => ['event_id']];
        foreach (OwnFields::REFERENCES as [$table, , $eventCols]) {
            $cols[$table] = array_merge($cols[$table] ?? [], $eventCols);
        }
        foreach ($cols as $table => $names) {
            foreach ($tables[$table] ?? [] as $i => $r) {
                foreach ($names as $col) {
                    if (isset($r[$col]) && (int) $r[$col] === $id) {
                        unset($tables[$table][$i]);
                        break;
                    }
                }
            }
        }
        return $tables;
    }

    /**
     * Whether day $key of series $master (as it stands) is skipped and
     * stays skipped through this restore: skipped now, and not a skip the
     * entry itself made (which the restore takes back: its snapshots'
     * skips, ownDeltas, or its recorded skip ground, a day deleted at
     * Google whose skip a poll wrote).
     */
    private function skipStands(int $entryId, array $master, array $before, array $after, string $key): bool
    {
        if ($this->db->scalar('SELECT 1 FROM mutation_touches WHERE mutation_id = ? AND row_key = ? AND col = ?', [$entryId, self::keyOf('events', $master), self::SKIP . $key]) !== null) {
            return false;
        }
        $of = static function (array $tables) use ($master): ?array {
            foreach ($tables['events'] ?? [] as $r) {
                if ((int) ($r['id'] ?? 0) === (int) $master['id']) {
                    return $r;
                }
            }
            return null;
        };
        try {
            $now = Recurrence::decodeExdates($master['exdates_json'] ?? null);
            [$b, $a] = [$of($before), $of($after)];
            $made = $b !== null && $a !== null ? array_diff(Recurrence::decodeExdates($a['exdates_json'] ?? null), Recurrence::decodeExdates($b['exdates_json'] ?? null)) : [];
        } catch (\InvalidArgumentException) {
            return false;
        }
        return in_array($key, $now, true) && !in_array($key, $made, true);
    }

    /**
     * The snapshots put back in a write of their own that is rolled back
     * after: what putting them back would refuse (the trip rules over what
     * it leaves, a row it belongs to gone since) is refused now, for a
     * Google series undo to refuse before Google is sent anything. Inside
     * another transaction there is nothing to roll back to on its own (a
     * savepoint over the events' full-text index is not safe on MariaDB),
     * and the check is left to the restore itself. It is exactly the
     * restore it guards: the same snapshots, composed over the same rows,
     * at the keys the undo's series places them ($placed, or a function
     * giving them, worked out inside the check's own write).
     *
     * @param array<int,string>|\Closure():array<int,string> $placed
     */
    public function checkRestore(array $mutation, bool $hereAlone = false, array|\Closure $placed = []): void
    {
        if ($this->db->pdo()->inTransaction()) {
            return;
        }
        $done = new \RuntimeException('checked');
        try {
            $this->write((int) $mutation['user_id'], function () use ($mutation, $done, $hereAlone, $placed): void {
                $this->restoreSnapshots($mutation, $hereAlone, $placed instanceof \Closure ? $placed() : $placed);
                throw $done;
            });
        } catch (\RuntimeException $e) {
            if ($e !== $done) {
                throw $e;
            }
        }
    }

    /**
     * The event rows an entry's snapshots hold, as they hold them.
     *
     * @return list<array>
     */
    public function snapshotEventRows(array $mutation): array
    {
        return array_values(array_filter(array_merge(self::tables($mutation['before_json'])['events'] ?? [], self::tables($mutation['after_json'])['events'] ?? []), 'is_array'));
    }

    /**
     * The events an entry's snapshots name (its pieces too), by id.
     *
     * @return list<int>
     */
    public function snapshotEventIds(array $mutation): array
    {
        $ids = array_merge(self::eventIdsOf(self::tables($mutation['before_json'])), self::eventIdsOf(self::tables($mutation['after_json'])));
        foreach ($this->pieceTables((int) $mutation['id']) as $side => $tables) {
            if (in_array('events', $tables, true)) {
                foreach ($this->piecesOf((int) $mutation['id'], $side, 'events') as $rows) {
                    $ids = array_merge($ids, self::eventIdsOf(['events' => $rows]));
                }
            }
        }
        return array_values(array_unique($ids));
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
            if ($table === 'events' && self::listless($row)) {
                // Kept in pieces, so not read by the loader: refused here, inside the undo's transaction, which rolls back whole.
                throw HttpError::conflict('not_undoable', self::BEFORE_LISTS);
            }
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
        if (!self::isLink($table)) {
            return false;
        }
        foreach (self::parentsOf($table) as $col => [$parent]) {
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
        foreach (self::order() as $table) {
            $col = (self::pk($table))[0];
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
        foreach (self::pk($table) as $col) {
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
        if (!in_array($table, self::order(), true)) {
            return;
        }
        $conds = [];
        $params = [];
        foreach (self::pk($table) as $col) {
            $conds[] = "`$col` = ?";
            $params[] = $row[$col] ?? null;
        }
        $this->db->run("DELETE FROM `$table` WHERE " . implode(' AND ', $conds), $params);
    }
}
