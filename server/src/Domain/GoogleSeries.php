<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Domain\Series\Cal;
use BetterCal\Domain\Series\Calc;
use BetterCal\Domain\Series\OwnFields;
use BetterCal\Domain\Series\Words;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/**
 * A confirmed series plan carried to a Google calendar (#172, stage two;
 * docs/design/series-model.md, "Google, as a Contract"). The only place
 * that knows what Google does with a series' exceptions.
 *
 * A target is a series as it should be at Google: its master, every
 * exception by its occurrence key with its own list, its skipped days, and
 * the events of their own it makes (a day kept outside the series). The
 * app sends the master with If-Match, reads the series back, and sends
 * each exception, skip and stranded record the read-back shows Google
 * doesn't hold as the target has it: one request per edited or skipped
 * day, as a PATCH on its instance id (a skip is a cancelled instance, never
 * an EXDATE line). The probe's facts say which read-backs will show what
 * (a clock, zone, start-day or rule change drops every exception, a field
 * or status change overwrites every exception's value, a length or bound
 * change keeps them), but no step is taken on a prediction: each follows
 * from the read-back, so a difference Google introduces that the probe
 * didn't see is carried too. Every write to a series changes the etag of
 * every one of its rows, and every row carries the series' etag (probe
 * 5.1), so each answer's etag is the next write's If-Match, and a 412 at
 * any step is a change made elsewhere since.
 *
 * Acceptance is the read-back: the rule compared as parts, the start and
 * end, each exception by originalStartTime with its fields, each skip as a
 * cancelled record. What Google holds is what is stored here (Feeds::sync,
 * as a poll stores it); a difference goes into the Activity entry's words
 * and a Review notice.
 *
 * Recovery: a transaction can't span the requests, so a pending row
 * (google_pending, migrations 068, 070, 071, 074 and 075) records the targets,
 * the series as they were (every exception's complete state), the rows here
 * as they were with what hangs off them (tags, people, trip links: the
 * shape Undo keeps), the change's part here (its plan, for Finish), and
 * each step: written as sent before its request (with what the series
 * holds once it lands) and as done, with the etag it left, after its answer. The row is written on a
 * connection of its own (Db::journal), so it is committed before the first
 * request whatever the account's transaction does after: a failure of any
 * kind, a lost connection or a stopped process leaves it. It goes only once
 * the edit here has committed (settle); a transaction that rolls back
 * leaves it for Review, unless nothing was sent. A change Google took
 * nothing of (every step refused, or none sent) records nothing: the row
 * goes at once, nothing is stored, and its error names no pending row, so
 * the caller rolls back whole (reachedGoogle). Every step is safe to
 * retry: an insert carries an id the app chose (a 409 on retry means it
 * landed), and every other step follows from a fresh read-back. A failure
 * mid-way stops, stores what Google has, and leaves the row for Review
 * ("Half done at Google"): Finish applies the change's deltas over what
 * Google and the rows hold now (rule A: rebase), then the change's part
 * here; Put back runs the befores, all or none: only while every series'
 * etag is the one its last step left, or, a last step's answer lost, the
 * series' state (rule B: one digest of every field of every resource,
 * less what Google rewrites on its own) is the one that step journaled
 * it leaves. A row still marked running whose
 * run holds no lock (a process that stopped) is half done too. Either way
 * the rows a recovery brings back take their ids, tags, people and links
 * from the snapshot (reattach). Each store a pending change runs records
 * the rows it made (made_json); Finish removes those and only those, and
 * applies the plan over any other row the snapshot lacks (rewind, follow).
 *
 * Undo (GoogleUndo, kind series) is an ordinary series write: its target is
 * the inverse of what the undone change asked (back to its before), planned
 * by the series model over the series as stored at undo time as a forward
 * change is (inverseEdit, withDayDeltas), bound to it (withStoredIds), and
 * sent through apply like any change: the same
 * condition, writes, read-back and one rebase whose base is the series as
 * stored before this write. Applying a target state is the whole public
 * surface.
 *
 * Every PATCH and DELETE, here and through GoogleWriter, is conditional on a
 * read made just before the change's first step (condition): each series and
 * event of its own the change sends anything to is read from Google, its
 * digest compared with the rows here (a difference refuses the whole change
 * with google_conflict before anything is sent, inserts included), and that
 * read's etag is the If-Match of its first write; its later writes chain the
 * etags of the app's own answers and read-backs.
 */
final class GoogleSeries
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_HALF = 'half';
    /** A step written before its request: it may or may not have reached Google (its answer never came). */
    private const SENT = 'sent';
    /** A step Google answered. */
    private const DONE = 'done';
    /** What Review says of a change that stopped with no error to name (the process stopped, or the edit here was not saved). */
    public const STOPPED_WORDS = 'It stopped before the change was saved here.';
    /**
     * The columns a sync writes on a row (Feeds::columnsOf, the etag and own
     * list, and its identity): a row a recovery brings back takes these from
     * what Google holds, the rest (reminders, the trip flag, an icon) from
     * the snapshot.
     */
    private const SYNCED = ['user_id', 'calendar_id', 'uid', 'source', 'title', 'description', 'location', 'url', 'start_utc', 'end_utc', 'all_day', 'tzid', 'rrule',
        'exdates_json', 'status', 'recurrence_instance_utc', 'recurrence_parent_id', 'google_event_id', 'google_etag', 'invite_json', 'override_fields', 'deleted_at'];
    public const HALF_WORDS = 'Part of this change reached Google. Review lists it as half done at Google, with Finish and Put back.';
    /** Read-backs of a write not yet showing it, with backoff (seconds); never a wait for correctness. */
    private const READBACK_TRIES = 4;
    private const BACKOFF = [0.3, 0.6, 1.2];
    private const PAGES = 4;
    private const FIELDS = ['title', 'description', 'location', 'status', 'start_utc', 'end_utc', 'all_day'];

    /** @var array<int,true> pending rows whose change the transaction open now saves (settle) */
    private array $settled = [];
    /** @var array<int,int> a recovery's rows carried onto another row (relink, carryHeld): old id => new id, for withRemoved */
    private array $carried = [];
    /** @var array<int,true> pending rows a run of this process holds (open to ended): its own change, never another's (pendingNames) */
    private static array $ours = [];

    public function __construct(
        private readonly Db $db,
        private readonly GoogleWriter $writer,
        private readonly Feeds $feeds,
        /** fn(float $seconds): void, between read-back tries; the tests pass one that doesn't wait. */
        private ?\Closure $sleep = null,
    ) {
    }

    // ---- targets (pure) -------------------------------------------------------

    /** An event id the app chooses for an insert (Google's alphabet: 0-9 and a-v, 5 to 1024 characters). */
    public static function chooseId(): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuv';
        $out = '';
        foreach (str_split(random_bytes(26)) as $b) {
            $out .= $alphabet[ord($b) & 31];
        }
        return $out;
    }

    /** The Google-held columns of a row, normalized. */
    private static function cols(array $row): array
    {
        return [
            'title' => (string) ($row['title'] ?? ''),
            'description' => $row['description'] ?? null,
            'location' => $row['location'] ?? null,
            'status' => (string) ($row['status'] ?? 'confirmed') === 'tentative' ? 'tentative' : 'confirmed',
            'start_utc' => (string) $row['start_utc'],
            'end_utc' => (string) $row['end_utc'],
            'all_day' => (int) ($row['all_day'] ?? 0),
            'tzid' => (string) ($row['tzid'] ?? 'UTC'),
            'url' => $row['url'] ?? null,
        ];
    }

    /** The columns of a day (cols) each field Google holds sets. */
    private const DAY_COLUMNS = [
        'date' => ['start_utc', 'end_utc', 'all_day', 'tzid'], 'time' => ['start_utc', 'end_utc', 'all_day', 'tzid'],
        'length' => ['start_utc', 'end_utc', 'all_day', 'tzid'], 'allDay' => ['start_utc', 'end_utc', 'all_day', 'tzid'],
        'title' => ['title'], 'location' => ['location'], 'description' => ['description'], 'url' => ['url'], 'status' => ['status'],
    ];

    /**
     * A day as Google is to hold it, built from the one allowed list: the
     * fields it owns that Google holds (OwnFields::googleHeld) at its row's
     * values, every other at the series' value at its key ($master: a row,
     * or the master a plan leaves), so a field it doesn't own goes as the
     * series has it, whatever its row still shows of an older series. A
     * place showing the series' text is owned by its coordinates alone,
     * which are held here (decisions.md, a feed's change to a day). Null
     * when no such field is left (tags, people, reminders, an answer, an
     * icon, coordinates, or only something that refers to it): an ordinary
     * day to every Google write and every undo (rule D), never sent and
     * never a target's exception.
     */
    private static function exceptionOf(array $row, array $own, array $master): ?array
    {
        $fields = OwnFields::googleHeld($own);
        if (in_array('location', $fields, true) && trim((string) ($row['location'] ?? '')) === trim((string) ($master['location'] ?? ''))) {
            $fields = array_values(array_diff($fields, ['location']));
        }
        if ($fields === []) {
            return null;
        }
        $m = self::cols($master) + ['rrule' => (string) ($master['rrule'] ?? '')];
        $key = (string) $row['recurrence_instance_utc'];
        $x = self::cols(self::plainAt($m, $key, self::lengthOf($m)));
        $r = self::cols($row);
        foreach ($fields as $field) {
            foreach (self::DAY_COLUMNS[$field] as $col) {
                $x[$col] = $r[$col];
            }
        }
        return $x;
    }

    /** The series as stored: a target (the before side of a plan, or the undo stage's target); a day with only local data is an ordinary day. */
    public static function stateOf(array $master, array $rows): array
    {
        $exceptions = [];
        foreach ($rows as $row) {
            $own = OwnFields::stored($row);
            $x = self::exceptionOf($row, $own, $master);
            if ($x === null) {
                continue;
            }
            $exceptions[(string) $row['recurrence_instance_utc']] = $x + ['own' => $own, 'rowId' => (int) $row['id']];
        }
        ksort($exceptions);
        $skips = Recurrence::decodeExdates($master['exdates_json'] ?? null);
        sort($skips);
        return [
            'uid' => isset($master['uid']) ? (string) $master['uid'] : null,
            'googleId' => (string) ($master['google_event_id'] ?? ''),
            'etag' => isset($master['google_etag']) && $master['google_etag'] !== '' ? (string) $master['google_etag'] : null,
            'insert' => false,
            'remove' => false,
            'master' => self::cols($master) + ['rrule' => (string) ($master['rrule'] ?? '')],
            'exceptions' => $exceptions,
            'skips' => $skips,
            'singles' => [],
        ];
    }

    /**
     * Whether a change from $before (stateOf, the series as stored here) to
     * $target sends Google nothing: the same series in everything Google
     * holds (state: the master's fields and rule, each day as exceptionOf
     * builds it, the skips), nothing inserted, removed or kept as an event
     * of its own. Such a change (tags, people, reminders or an icon for all
     * events) is held here alone: written and journaled as a local change,
     * and undone as one. Pure.
     */
    public static function sendsNothing(array $before, array $target): bool
    {
        return empty($target['insert']) && empty($target['remove']) && ($target['singles'] ?? []) === []
            && self::digest(self::state(self::heldOf($before))) === self::digest(self::state(self::heldOf($target)));
    }

    /**
     * The target of a whole-series plan: the master with the plan's
     * columns, every row the plan keeps in the series at its new key with
     * its own list, the row it creates for the day acted on, the plan's
     * skips, and the days it keeps outside the series as events of their
     * own (each with an id chosen here).
     */
    public static function targetOf(array $master, array $rows, array $w): array
    {
        $after = array_merge($master, $w['master'] ?? []);
        $t = self::stateOf($after, []);
        $deleted = array_flip($w['delete'] ?? []);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
            $id = (int) $row['id'];
            if (isset($deleted[$id]) || isset($w['detach'][$id])) {
                continue;
            }
            $r = array_merge($row, $w['overrides'][$id] ?? []);
            $own = OwnFields::stored($r);
            $x = self::exceptionOf($r, $own, $after);
            if ($x === null) {
                continue; // the series' own day to Google
            }
            $t['exceptions'][(string) $r['recurrence_instance_utc']] = $x + ['own' => $own, 'rowId' => $id];
        }
        foreach ($w['createEdited'] ?? [] as $cols) {
            $r = array_merge($after, $cols);
            $own = OwnFields::decode($cols['override_fields'] ?? null) ?? [];
            $x = self::exceptionOf($r, $own, $after);
            if ($x !== null) {
                $t['exceptions'][(string) $cols['recurrence_instance_utc']] = $x + ['own' => $own, 'rowId' => null];
            }
        }
        ksort($t['exceptions']);
        $skips = array_values(array_unique($w['exdates'] ?? Recurrence::decodeExdates($after['exdates_json'] ?? null)));
        sort($skips);
        $t['skips'] = $skips;
        foreach ($w['detach'] ?? [] as $id => $cols) {
            if (isset($byId[$id])) {
                $t['singles'][] = ['id' => self::chooseId(), 'row' => self::cols(array_merge($byId[$id], $cols)), 'rowId' => (int) $id, 'remove' => false];
            }
        }
        foreach ($w['create'] ?? [] as $i => $placed) {
            $t['singles'][] = ['id' => self::chooseId(), 'row' => self::cols(array_merge($after, $placed)), 'createIndex' => (int) $i, 'remove' => false];
        }
        return $t;
    }

    /**
     * A split's targets (Series\Split): the old series with its new bound
     * and the exceptions before the cut, and the new series (inserted with
     * an id chosen here, its exceptions after the cut planned as a whole
     * series), or null for a delete.
     *
     * @return array{0:array,1:?array}
     */
    public static function splitTargets(array $master, array $rows, array $w): array
    {
        $part = array_flip($w['rows'] ?? []);
        $kept = array_values(array_filter($rows, static fn(array $r): bool => !isset($part[(int) $r['id']])));
        $old = self::stateOf(array_merge($master, $w['old'] ?? []), $kept);
        if (($w['part'] ?? null) === null) {
            return [$old, null];
        }
        $partRows = array_values(array_filter($rows, static fn(array $r): bool => isset($part[(int) $r['id']])));
        $newMaster = array_merge($master, $w['part'], $w['plan']['master'] ?? [], ['google_event_id' => self::chooseId(), 'google_etag' => null, 'uid' => null]);
        $new = self::targetOf($newMaster, $partRows, $w['plan']);
        $new['insert'] = true;
        $new['uid'] = null;
        return [$old, $new];
    }

    // ---- Google ------------------------------------------------------------

    /**
     * Apply targets to Google: the pending row first, then the steps, then
     * each series read back and compared. Returns what to store locally
     * (store()) and settle (settle()); on a failure mid-way the pending
     * row is left half done, what Google has is stored, and the error is
     * thrown with `pending` in its extra.
     *
     * @param list<array> $targets
     * `befores` are the series as they were before (beforeOf), the targets
     * that undo the change. $plan is the change's part here, as the caller
     * writes it once Google has the targets (its writer's inputs): kept with
     * the pending row, so Finish writes it with the same writer.
     *
     * @return array{pending:int, readbacks:array<string,list<array>>, differences:list<string>, inserted:array<string,array>, targets:list<array>, befores:list<array>}
     */
    public function apply(int $userId, array $calendar, array $targets, string $title, string $summary, ?int $eventId = null, ?\Closure $beforeSend = null, ?array $plan = null): array
    {
        // A series with changes not yet at Google takes no other change until they are finished or put back.
        $names = [];
        foreach ($targets as $t) {
            if (empty($t['insert'])) {
                array_push($names, (string) ($t['uid'] ?? ''), (string) ($t['googleId'] ?? ''));
            }
        }
        self::refusePending($this->db, $userId, (int) $calendar['id'], array_filter($names));
        $befores = [];
        foreach ($targets as $i => $t) {
            // With what this change asks of the series (`asked`): its own
            // deltas are the difference between the before and what it
            // asks, nothing it learns from Google on the way, and its undo
            // asks their inverse, planned over the series as it stands then
            // (GoogleUndo::inverseOf).
            $befores[$i] = $this->beforeOf($calendar, $t) + ['asked' => ['master' => $t['master'], 'exceptions' => $t['exceptions'] ?? [], 'skips' => $t['skips'] ?? []]];
        }
        $pendingId = $this->journaled(fn(Db $j): int => $j->insert('google_pending', [
            'user_id' => $userId,
            'calendar_id' => (int) $calendar['id'],
            'event_id' => $eventId,
            'title' => mb_substr($title, 0, 500),
            'summary' => mb_substr($summary, 0, 1000),
            'status' => self::STATUS_RUNNING,
            'targets_json' => json_encode($targets),
            'befores_json' => json_encode($befores),
            'local_json' => json_encode($this->localOf($calendar, array_merge($targets, $befores))),
            'plan_json' => $plan === null ? null : json_encode($plan),
            'steps_json' => '[]',
            'created_at' => Time::nowDb(),
            'updated_at' => Time::nowDb(),
        ]));
        $this->open($pendingId, true);
        return $this->run($calendar, $pendingId, $targets, [], $beforeSend, false, true) + ['befores' => array_values($befores)];
    }

    /**
     * The rows here a change's series are made of, with what hangs off them
     * (Undo::linksOf: tags, people, trip links, duplicate decisions, plugin
     * data): every live row of each series' uid, and every row a target
     * names (an edited day moving to another series, a day kept outside).
     * What a recovery puts back on the rows it brings back (reattach).
     *
     * @param list<array> $targets
     * @return array<string,list<array>>
     */
    private function localOf(array $calendar, array $targets): array
    {
        $uids = [];
        $ids = [];
        foreach ($targets as $t) {
            $master = empty($t['insert']) ? $this->masterRow($calendar, $t) : null;
            if ($master !== null) {
                $uids[] = (string) $master['uid'];
            }
            foreach ($t['exceptions'] ?? [] as $x) {
                if (!empty($x['rowId'])) {
                    $ids[] = (int) $x['rowId'];
                }
            }
            foreach ($t['singles'] ?? [] as $single) {
                if (!empty($single['rowId'])) {
                    $ids[] = (int) $single['rowId'];
                }
            }
        }
        $rows = [];
        $uids = array_values(array_unique($uids));
        if ($uids !== []) {
            [$in, $params] = Db::in($uids);
            foreach ($this->db->all("SELECT * FROM events WHERE calendar_id = ? AND uid IN $in AND deleted_at IS NULL", array_merge([(int) $calendar['id']], $params)) as $r) {
                $rows[(int) $r['id']] = $r;
            }
        }
        $ids = array_values(array_diff(array_unique($ids), array_keys($rows)));
        if ($ids !== []) {
            [$in, $params] = Db::in($ids);
            foreach ($this->db->all("SELECT * FROM events WHERE id IN $in AND deleted_at IS NULL", $params) as $r) {
                $rows[(int) $r['id']] = $r;
            }
        }
        ksort($rows);
        return ['events' => array_values($rows)] + Undo::linksOf($this->db, array_keys($rows));
    }

    // ---- the journal ----------------------------------------------------------

    /**
     * $fn on the journal's connection (Db::journal), as the server's own
     * record of its work at Google rather than a change made under the
     * request's credential (RequestAuthority::asServer, as a job queue's
     * state is): written whatever the account's transaction does.
     */
    private function journaled(callable $fn): mixed
    {
        return RequestAuthority::asServer(fn(): mixed => $fn($this->db->journal()));
    }

    /** The lock a run holds on its pending row, on the journal's connection: free once the run ends or its process stops. */
    private static function lockName(Db $db, int $pendingId): string
    {
        static $names = null;
        $names ??= new \WeakMap();
        $names[$db] ??= substr(sha1((string) $db->journal()->scalar('SELECT DATABASE()')), 0, 24);
        return 'bc_google_pending_' . $names[$db] . '_' . $pendingId;
    }

    private static function mysql(Db $db): bool
    {
        return $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /**
     * A run of a pending row begins: its lock taken (false when another run
     * holds it), and, inside a transaction, its end decided by how that
     * transaction ends (ended). $created: the row was made for this run.
     */
    private function open(int $pendingId, bool $created): bool
    {
        if (self::mysql($this->db) && (int) $this->db->journal()->scalar('SELECT GET_LOCK(?, 0)', [self::lockName($this->db, $pendingId)]) !== 1) {
            return false;
        }
        self::$ours[$pendingId] = true;
        if ($this->db->pdo()->inTransaction()) {
            $this->db->whenTxEnds(fn(bool $committed) => $this->ended($pendingId, $committed, $created));
        }
        return true;
    }

    /**
     * A run of a pending row is over. Its change saved here (settle, then a
     * commit): the row goes. A transaction rolled back with nothing sent to
     * Google, on a row made for it: the row goes too, as nothing happened.
     * Anything else (the edit here not saved, a step sent): the row stays,
     * half done, for Review. The lock goes either way.
     */
    private function ended(int $pendingId, bool $committed, bool $created): void
    {
        $settled = isset($this->settled[$pendingId]);
        unset($this->settled[$pendingId]);
        unset(self::$ours[$pendingId]);
        try {
            $this->journaled(function (Db $j) use ($pendingId, $committed, $created, $settled): void {
                $steps = json_decode((string) $j->scalar('SELECT steps_json FROM google_pending WHERE id = ?', [$pendingId]), true) ?: [];
                if (($committed && $settled) || (!$committed && $created && $steps === [])) {
                    $j->run('DELETE FROM google_pending WHERE id = ?', [$pendingId]);
                    return;
                }
                $j->run('UPDATE google_pending SET status = ?, error = COALESCE(error, ?), updated_at = ? WHERE id = ? AND status = ?',
                    [self::STATUS_HALF, self::STOPPED_WORDS, Time::nowDb(), $pendingId, self::STATUS_RUNNING]);
            });
        } finally {
            if (self::mysql($this->db)) {
                $this->db->journal()->scalar('SELECT RELEASE_LOCK(?)', [self::lockName($this->db, $pendingId)]);
            }
        }
    }

    /**
     * A pending row made for a run that sent Google nothing it took: gone,
     * as the change never happened (no half-done item in Review). Its lock
     * goes as any run's does (ended, now or at the transaction's end).
     */
    private function unmade(int $pendingId): void
    {
        $this->journaled(fn(Db $j) => $j->run('DELETE FROM google_pending WHERE id = ?', [$pendingId]));
        $this->endUnlessTx($pendingId, false, true);
    }

    /**
     * Whether a failed Google change left something there: its error names
     * the pending row Review lists (run). One that does not reached Google
     * with nothing, so its caller writes nothing here either: it rolls its
     * transaction back by letting the error through, where one that did
     * commits first, so the pending row and what Google holds survive.
     */
    public static function reachedGoogle(HttpError $e): bool
    {
        return isset($e->extra['pending']);
    }

    /** A run made outside any transaction, over: what a transaction's end does, now (inside one, its end does it). */
    private function endUnlessTx(int $pendingId, bool $committed, bool $created = false): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            $this->ended($pendingId, $committed, $created);
        }
    }

    /**
     * What a failed run must still store here (what Google holds), written
     * once the transaction it failed in is over, in a transaction of its
     * own (Undo::write: the account's lock, the request's check), whether
     * that transaction rolled back or committed; outside one, now. A failed
     * part of a write is undone by rolling back its whole transaction,
     * never to a savepoint (MariaDB's fulltext index asserts on a row
     * deleted under a savepoint rolled back and deleted again), so nothing
     * that must outlive the failure is written inside it. Skipped when
     * nothing reached Google (stepsDone), and when another run of the
     * pending row holds it by then: that run stores what Google holds
     * itself. A failure here is logged (Db::whenTxEnds); the pending row
     * says what happened either way.
     */
    private function keepAfterTx(array $calendar, int $pendingId, \Closure $keep): void
    {
        $this->db->whenTxEnds(function () use ($calendar, $pendingId, $keep): void {
            if ($this->stepsDone($pendingId) === 0) {
                return; // nothing reached Google: the rollback was all of it
            }
            $lock = self::lockName($this->db, $pendingId);
            if (self::mysql($this->db) && (int) $this->db->journal()->scalar('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
                return;
            }
            try {
                (new Undo($this->db))->write((int) $calendar['user_id'], $keep);
            } finally {
                if (self::mysql($this->db)) {
                    $this->db->journal()->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
                }
            }
        });
    }

    /** A Google answer that refused the write: it changed nothing there (any 4xx, the 412 of a conflict among them). */
    private static function refused(HttpError $e): bool
    {
        if ($e->errorCode === 'google_conflict') {
            return true;
        }
        $status = (int) ($e->extra['googleStatus'] ?? 0);
        return $status >= 400 && $status < 500;
    }

    /** A series' removal: the target that cancels it at Google (a series inserted, or one not here). Pure. */
    public static function removalOf(array $t, array $singles = []): array
    {
        return ['uid' => $t['uid'] ?? null, 'googleId' => (string) $t['googleId'], 'etag' => $t['etag'] ?? null, 'insert' => false, 'remove' => true,
            'master' => $t['master'], 'exceptions' => [], 'skips' => [], 'singles' => $singles];
    }

    /**
     * Targets bound to what is stored here now: each series' uid as its row
     * holds it (an insert whose answer was lost and that a refresh stored is
     * a series here). No etag is taken from a row: each write is conditional
     * on a read made just before the change's first step (condition).
     *
     * @param list<array> $targets
     * @return list<array>
     */
    public function withStoredIds(array $calendar, array $targets): array
    {
        foreach ($targets as $i => $t) {
            $master = $this->masterRow($calendar, $t);
            if ($master !== null) {
                $targets[$i]['uid'] = (string) $master['uid'];
                $targets[$i]['insert'] = false;
            }
        }
        return $targets;
    }

    /**
     * Rule 1, the condition of every PATCH and DELETE the app sends: the
     * series $googleId belongs to (or the event, when it is in none) read
     * from Google now, by its iCalendar UID with every record ($items: that
     * read, when the caller has just made it), compared with the rows here
     * by its digest (sameAsStored), and refused with google_conflict when
     * they differ: something changed at Google since the last poll, and
     * nothing is sent. The If-Match is that read's etag of $googleId; an
     * occurrence Google keeps no record of (an ordinary day, addressed by
     * its instance id) is read by its id for it. Nothing stored here for
     * $googleId (a calendar a move is filling, a series this calendar no
     * longer has) leaves the read's etag alone as the condition. $read: the
     * resource $googleId as the caller has just read it (and built its body
     * from): the same comparison is made, the series read for it when
     * $googleId is in one, and $read's etag is the If-Match.
     *
     * @return array{etag:string, items:list<array>}
     */
    public function condition(array $calendar, string $googleId, ?\Closure $beforeSend = null, ?array $items = null, ?array $read = null): array
    {
        [$master, $rows] = $this->storedOf($calendar, $googleId);
        $seriesId = $master !== null ? (string) $master['google_event_id'] : $googleId;
        if ($items === null && $read !== null && ($master === null || empty($master['rrule']))) {
            $items = [$read]; // an event in no series: the caller's read is the whole read
        }
        if ($items === null) {
            $send = fn(string $method, string $path, ?array $body, ?string $ifMatch): array
                => $this->writer->send($calendar, $method, $path, $body, [], $ifMatch, $beforeSend);
            $items = $this->readBack($calendar, $send, $master !== null && !empty($master['rrule']) ? (string) $master['uid'] : null, $seriesId, null, $beforeSend);
        }
        // Read by Google's reason, as every answer is (GoogleWriter::refusal):
        // a target Google no longer has (its read answered 404 or 410, or
        // lists it cancelled) is deleted at Google, and only a read that
        // found it is compared with the rows here.
        if ($master !== null && self::held($items, $seriesId)['master'] === null) {
            throw GoogleWriter::deleted();
        }
        if ($master !== null && !self::sameAsStored($items, $seriesId, $master, $rows)) {
            throw new HttpError('google_conflict', GoogleWriter::CONFLICT_MESSAGE, 409);
        }
        $etag = $read !== null && isset($read['etag']) && is_string($read['etag']) ? $read['etag'] : (self::held($items, $seriesId)['etags'][$googleId] ?? null);
        if ($etag === null && $googleId !== $seriesId) {
            $etag = $this->writer->send($calendar, 'GET', '/' . rawurlencode($googleId), null, [], null, $beforeSend)['etag'] ?? null;
        }
        if (!is_string($etag) || $etag === '') {
            // Not at Google: as the write itself would be answered.
            throw GoogleWriter::deleted();
        }
        return ['etag' => $etag, 'items' => $items];
    }

    /**
     * The series (or event) stored here that Google knows $googleId as part
     * of, and its edited days' rows: the row of that id or its master's, or,
     * an occurrence with no row, the master of its instance id's series.
     *
     * @return array{0:?array, 1:list<array>}
     */
    private function storedOf(array $calendar, string $googleId): array
    {
        if (!isset($calendar['id']) || $googleId === '') {
            return [null, []];
        }
        $row = $this->writer->rowByGoogleId((int) $calendar['id'], $googleId);
        if ($row !== null && $row['recurrence_parent_id'] !== null) {
            $row = $this->db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [(int) $row['recurrence_parent_id']]);
        } elseif ($row === null && ($cut = strrpos($googleId, '_')) !== false) {
            $row = $this->writer->rowByGoogleId((int) $calendar['id'], substr($googleId, 0, $cut));
        }
        if ($row === null || empty($row['google_event_id'])) {
            return [null, []];
        }
        $rows = !empty($row['rrule'])
            ? $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [(int) $row['id']])
            : [];
        return [$row, $rows];
    }

    /**
     * Whether Google holds a series (or event) as the rows here store it:
     * the digest of what was read (held, state) equal to the digest of the
     * rows (stateOf), in the fields a column holds (the rows keep none of
     * the rest). The one comparison condition() makes. Pure.
     */
    public static function sameAsStored(array $items, string $googleId, array $master, array $rows): bool
    {
        return self::digest(self::state(self::held($items, $googleId))) === self::digest(self::state(self::heldOf(self::stateOf($master, $rows))));
    }

    /** How many steps of a pending change may have reached Google, answered or not (0: nothing did). */
    public function stepsDone(int $pendingId): int
    {
        return count($this->journaled(fn(Db $j): array => json_decode((string) $j->scalar('SELECT steps_json FROM google_pending WHERE id = ?', [$pendingId]), true) ?: []));
    }

    /**
     * The series a target changes, as it is here before the change: a
     * series inserted, or one not here, has its removal as its before; an
     * event of its own the target makes is removed, one it removes is
     * made again as stored here.
     */
    private function beforeOf(array $calendar, array $t): array
    {
        $singles = [];
        foreach ($t['singles'] ?? [] as $s) {
            if (empty($s['remove'])) {
                $singles[] = ['id' => (string) $s['id'], 'remove' => true];
                continue;
            }
            $row = $this->writer->rowByGoogleId((int) $calendar['id'], (string) $s['id']);
            if ($row !== null) {
                $singles[] = ['id' => (string) $s['id'], 'row' => self::cols($row), 'rowId' => (int) $row['id'], 'remove' => false];
            }
        }
        $master = empty($t['insert']) ? $this->masterRow($calendar, $t) : null;
        if ($master === null) {
            return self::removalOf(['uid' => empty($t['insert']) ? ($t['uid'] ?? null) : null, 'etag' => null] + $t, $singles);
        }
        $rows = $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [(int) $master['id']]);
        $before = self::stateOf($master, $rows);
        $before['singles'] = $singles;
        return $before;
    }

    private function masterRow(array $calendar, array $t): ?array
    {
        if (!empty($t['uid'])) {
            $row = $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [(int) $calendar['id'], (string) $t['uid']]);
            if ($row !== null) {
                return $row;
            }
        }
        return $this->writer->rowByGoogleId((int) $calendar['id'], (string) $t['googleId']);
    }

    /**
     * @param list<array> $targets
     * @param list<array> $steps the pending row's steps so far (a retry, or a Put back after the change's)
     * @param bool $back the steps are a Put back's (the befores), told apart from the change's own
     * @param bool $made the pending row was made for this run (apply): a failure with nothing at Google takes it away
     */
    private function run(array $calendar, int $pendingId, array $targets, array $steps, ?\Closure $beforeSend, bool $back = false, bool $made = false): array
    {
        $readbacks = [];
        $differences = [];
        $inserted = [];
        $thisYear = (int) Time::nowUtc()->format('Y');
        $send = fn(string $method, string $path, ?array $body, ?string $ifMatch): array
            => $this->writer->send($calendar, $method, $path, $body, [], $ifMatch, $beforeSend);
        $save = function () use ($pendingId, &$steps): void {
            $this->journaled(fn(Db $j) => $j->update('google_pending', ['steps_json' => json_encode(array_values($steps)), 'updated_at' => Time::nowDb()], 'id = ?', [$pendingId]));
        };
        try {
            // Rule 1, before the change's first step: every series and event
            // of its own it sends anything to is read and checked against
            // the rows here (condition), so a change made at Google since the
            // last poll refuses the whole change before anything is sent.
            // That read's etag is the If-Match of its first write.
            $first = [];  // series index => etag of the read, when anything is sent to it
            $reads = [];  // series index => that read (the series as Google holds it before the change)
            $singleEtags = []; // event of its own's Google id => etag of its read
            $found = [];  // series index => the series as stored here before the change (stateOf), which that read matched
            foreach ($targets as $i => $t) {
                foreach ($t['singles'] ?? [] as $s) {
                    if (!empty($s['remove']) && !self::stepDone($steps, $i, 'single-remove', (string) $s['id'], $back)) {
                        try {
                            $singleEtags[(string) $s['id']] = $this->condition($calendar, (string) $s['id'], $beforeSend)['etag'];
                        } catch (HttpError $e) {
                            if (!GoogleWriter::gone($e)) {
                                throw $e;
                            }
                        }
                    }
                }
                if (!empty($t['insert'])) {
                    continue;
                }
                if (!empty($t['remove'])) {
                    if (!self::stepDone($steps, $i, 'remove', (string) $t['googleId'], $back)) {
                        try {
                            $first[$i] = $this->condition($calendar, (string) $t['googleId'], $beforeSend)['etag'];
                        } catch (HttpError $e) {
                            if (!GoogleWriter::gone($e)) {
                                throw $e;
                            }
                        }
                    }
                    continue;
                }
                $before = $this->masterRow($calendar, $t);
                $old = $before === null ? null : self::stateOf($before, [])['master'];
                // The series as the change finds it, for its deltas: as
                // stored here (which the read below matches), for every
                // change, an undo's or redo's included.
                if ($before !== null) {
                    $found[$i] = self::stateOf($before, $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [(int) $before['id']]));
                }
                $reads[$i] = $this->readBack($calendar, $send, !empty($t['uid']) ? (string) $t['uid'] : null, (string) $t['googleId'], null, $beforeSend);
                if (self::masterBody($old, $t['master']) !== [] || ($t['singles'] ?? []) !== [] || self::stepsFor($t, $reads[$i]) !== []) {
                    $first[$i] = $this->condition($calendar, (string) $t['googleId'], $beforeSend, $reads[$i])['etag'];
                }
            }
            foreach ($targets as $i => $t) {
                // One write to Google, in the journal as sent before it goes
                // and as done, with the etag it left, once answered. A
                // refusal (Google answered and wrote nothing) leaves no step;
                // no answer at all leaves it sent: it may have landed.
                // $check: what the series holds at Google once the write
                // lands (`expect`: the digest of its state, rule B, every
                // field of Google's resources): how Put back tells a write
                // whose answer was lost from a change made since.
                $step = function (string $kind, string $id, ?string $key, \Closure $write, array $check = []) use (&$steps, $save, $i, $back): mixed {
                    $steps[] = ['series' => $i, 'kind' => $kind, 'id' => $id, 'key' => $key, 'etag' => null, 'state' => self::SENT, 'back' => $back, 'at' => Time::nowDb()] + $check;
                    $n = array_key_last($steps);
                    $save();
                    try {
                        $resource = $write();
                    } catch (HttpError $e) {
                        if (self::refused($e)) {
                            unset($steps[$n]);
                            $steps = array_values($steps);
                            $save();
                        }
                        throw $e;
                    }
                    $steps[$n]['state'] = self::DONE;
                    $steps[$n]['etag'] = is_array($resource) && isset($resource['etag']) && is_string($resource['etag']) ? $resource['etag'] : null;
                    $save();
                    return $resource;
                };
                foreach ($t['singles'] ?? [] as $si => $s) {
                    $sid = (string) $s['id'];
                    if (!empty($s['remove'])) {
                        if (!self::stepDone($steps, $i, 'single-remove', $sid, $back)) {
                            if (isset($singleEtags[$sid])) {
                                $step('single-remove', $sid, null, fn() => $this->cancel($send, $sid, $singleEtags[$sid]));
                            }
                        }
                        continue;
                    }
                    $body = GoogleWriter::body(self::rowOf($s['row']));
                    $resource = self::stepDone($steps, $i, 'single', $sid, $back)
                        ? $this->insertChosen($calendar, $send, $sid, $body, $beforeSend)
                        : $step('single', $sid, null, fn() => $this->insertChosen($calendar, $send, $sid, $body, $beforeSend));
                    $inserted[$sid] = $resource;
                    $targets[$i]['singles'][$si]['uid'] = (string) ($resource['iCalUID'] ?? '');
                    $readbacks[(string) ($resource['iCalUID'] ?? $sid)] = [$resource];
                }
                if (!empty($t['remove'])) {
                    if (isset($first[$i])) {
                        $step('remove', (string) $t['googleId'], null, fn() => $this->cancel($send, (string) $t['googleId'], $first[$i]));
                    }
                    $uid = $t['uid'] ?? null;
                    $items = $this->readBack($calendar, $send, $uid, (string) $t['googleId'], null, $beforeSend);
                    // Each record of it still listed live goes too, so no
                    // edited day outlives its series, however Google treats
                    // a series' records when its master goes.
                    $live = array_filter($items, static fn(array $item): bool => (string) ($item['recurringEventId'] ?? '') === (string) $t['googleId'] && (string) ($item['status'] ?? '') !== 'cancelled');
                    foreach ($live as $item) {
                        // Each conditional on a read of it just before (each
                        // delete moves every etag of the series).
                        $now = $this->found($send, (string) $item['id']);
                        if ($now !== null) {
                            $step('record-remove', (string) $item['id'], null, fn() => $this->cancel($send, (string) $item['id'], isset($now['etag']) && is_string($now['etag']) ? $now['etag'] : null));
                        }
                    }
                    if ($live !== []) {
                        $items = $this->readBack($calendar, $send, $uid, (string) $t['googleId'], null, $beforeSend);
                    }
                    $readbacks[(string) ($uid ?? $t['googleId'])] = $items;
                    continue;
                }
                // The If-Match of each write: the series' first from the read
                // above, each later one from the app's own answer or read-back.
                $chain = $first[$i] ?? null;
                $read = $reads[$i] ?? null;
                if (!empty($t['insert'])) {
                    // Once it lands: the series as sent, no edited or skipped day, and no field of the resource but what it sent.
                    $resource = $step('insert', (string) $t['googleId'], null, fn() => $this->insertChosen($calendar, $send, (string) $t['googleId'], self::insertBody($t['master']), $beforeSend),
                        ['expect' => self::digest(self::state(self::heldOf(['master' => $t['master'], 'exceptions' => [], 'skips' => []]), true)), 'extras' => true]);
                    $targets[$i]['uid'] = $t['uid'] = (string) ($resource['iCalUID'] ?? $t['googleId']);
                    $targets[$i]['insert'] = false;
                    $inserted[(string) $t['googleId']] = $resource;
                    $chain = $resource['etag'] ?? null;
                    $snapshot = $targets;
                    $this->journaled(fn(Db $j) => $j->update('google_pending', ['targets_json' => json_encode($snapshot)], 'id = ?', [$pendingId]));
                } else {
                    $before = $this->masterRow($calendar, $t);
                    $old = $before === null ? null : self::stateOf($before, [])['master'];
                    $body = self::masterBody($old, $t['master']);
                    if ($body !== []) {
                        // What the series holds once this write lands (rule
                        // B): Google's resources as read just before the
                        // change (its condition), every field, with the
                        // write's own effect on the days (afterMaster).
                        $check = [];
                        if ($old !== null && !empty($t['uid']) && $read !== null) {
                            $pre = self::held($read, (string) $t['googleId']);
                            if ($pre['master'] !== null) {
                                $check = ['expect' => self::digest(self::state(self::afterMaster($pre, $old, $t['master']), true)), 'extras' => true];
                            }
                        }
                        $resource = $step('master', (string) $t['googleId'], null, fn() => $send('PATCH', '/' . rawurlencode((string) $t['googleId']), $body, $chain), $check);
                        $chain = $resource['etag'] ?? $chain;
                        $read = null;
                    }
                }
                $items = $read ?? $this->readBack($calendar, $send, $t['uid'], (string) $t['googleId'], $chain, $beforeSend);
                if ($read === null) {
                    // The master as read back is in the plan's form (timed
                    // or all-day) before any day is compared or sent: an
                    // instance's id carries its master's form, so while
                    // Google's master is in another (the write not taken,
                    // or a list that lags it) no day of the plan can be
                    // named there and a step would answer 404. The change
                    // stops here, half done, with the difference named;
                    // Finish reads again.
                    $got = self::held($items, (string) $t['googleId'])['master'];
                    if ($got !== null && (int) $got['all_day'] !== (int) $t['master']['all_day']) {
                        throw new HttpError('google_write_failed', self::startWords($got, $t['master'], $thisYear), 502);
                    }
                }
                if ($read === null && isset($found[$i])) {
                    // After its master write, the change's days go over what
                    // Google holds now as its deltas (rule A, as Finish
                    // applies them): a day changed at Google since the read
                    // keeps that change where the change doesn't touch the
                    // field, and is named where it does; never overwritten.
                    [$t, $words] = self::rebase($found[$i], $t, self::held($items, (string) $t['googleId']), $thisYear, 'while this change was sent');
                    $targets[$i] = $t;
                    $differences = array_merge($differences, $words);
                }
                $chain = self::masterEtag($items, (string) $t['googleId']) ?? $chain;
                $now = self::held($items, (string) $t['googleId']);
                $len = max(0, Time::fromDb($t['master']['end_utc'])->getTimestamp() - Time::fromDb($t['master']['start_utc'])->getTimestamp());
                foreach (self::stepsFor($t, $items) as [$kind, $id, $body, $key]) {
                    $after = self::heldAfter($now, $kind, $key, $kind === 'exception' ? $t['exceptions'][$key] : ($kind === 'plain' ? self::plainAt($t['master'], $key, $len) : null));
                    try {
                        $resource = $step($kind, $id, $key, fn() => $send('PATCH', '/' . rawurlencode($id), $body, $chain), ['expect' => self::digest(self::state($after, true)), 'extras' => true]);
                    } catch (HttpError $e) {
                        // Google lists no such instance (404): a day past the
                        // 730 occurrences an unbounded series expands to
                        // there (the contract's cap). The day stays as Google
                        // has it, and the read-back names the difference.
                        if ((int) ($e->extra['googleStatus'] ?? 0) === 404) {
                            continue;
                        }
                        throw $e;
                    }
                    $chain = $resource['etag'] ?? $chain;
                    $now = $after;
                }
                $items = $this->readBack($calendar, $send, $t['uid'], (string) $t['googleId'], $chain, $beforeSend);
                $readbacks[(string) $t['uid']] = $items;
                $differences = array_merge($differences, self::differences($t, $items, $thisYear));
            }
        } catch (\Throwable $e) {
            // Nothing reached Google (no step journaled, or each one
            // refused): the change did not happen, so it records nothing,
            // here or in the journal. The pending row goes, nothing is read
            // back or stored, and the error is the request's own, naming no
            // pending row: its caller rolls its transaction back whole.
            if ($made && $this->stepsDone($pendingId) === 0) {
                $this->unmade($pendingId);
                throw $e instanceof HttpError || !$e instanceof \RuntimeException ? $e : new HttpError('google_write_failed', 'Google could not be reached: ' . $e->getMessage(), 502);
            }
            // Half done, in the journal before anything else is tried: a
            // failure of any kind, whatever the transaction here does next.
            $snapshot = $targets;
            $this->journaled(fn(Db $j) => $j->update('google_pending', ['status' => self::STATUS_HALF, 'error' => mb_substr($e->getMessage(), 0, 2000),
                'targets_json' => json_encode($snapshot), 'updated_at' => Time::nowDb()], 'id = ?', [$pendingId]));
            if (!$e instanceof HttpError && !$e instanceof \RuntimeException) {
                $this->endUnlessTx($pendingId, false);
                throw $e;
            }
            // What Google has now is what the calendar shows: read now,
            // stored once the transaction is over (keepAfterTx); best
            // effort, the pending row says what happened either way.
            try {
                $got = $readbacks;
                foreach ($targets as $t) {
                    if (!empty($t['uid']) && !isset($got[(string) $t['uid']])) {
                        $got[(string) $t['uid']] = $this->readBack($calendar, $send, (string) $t['uid'], (string) $t['googleId'], null, $beforeSend);
                    }
                }
                if ($got !== []) {
                    $this->keepAfterTx($calendar, $pendingId, fn() => $this->store($calendar, $got, [], $pendingId));
                }
            } catch (\Throwable) {
            }
            $this->endUnlessTx($pendingId, true);
            $code = $e instanceof HttpError ? $e->errorCode : 'google_write_failed';
            $status = $e instanceof HttpError ? $e->status : 502;
            $message = $e instanceof HttpError ? $e->getMessage() : 'Google could not be reached: ' . $e->getMessage();
            throw new HttpError($code, rtrim($message, '.') . '. ' . self::HALF_WORDS, $status, ['pending' => $pendingId]);
        }
        return ['pending' => $pendingId, 'readbacks' => $readbacks, 'differences' => $differences, 'inserted' => $inserted, 'targets' => $targets];
    }

    /**
     * Whether a step of this kind on this id is done in the run under way:
     * the steps of the same side (the change's, or a Put back's) since the
     * last one of the other side, answered.
     */
    private static function stepDone(array $steps, int $series, string $kind, string $id, bool $back = false): bool
    {
        for ($n = count($steps) - 1; $n >= 0; $n--) {
            $s = $steps[$n];
            if (!empty($s['back']) !== $back) {
                return false;
            }
            if ((int) $s['series'] === $series && $s['kind'] === $kind && $s['id'] === $id && ($s['state'] ?? self::DONE) === self::DONE) {
                return true;
            }
        }
        return false;
    }

    /**
     * An insert with the id the app chose: a 409 means the id is taken, by
     * the first attempt that landed or by the event itself cancelled (an
     * undo's undo making it again), so it is read back. A cancelled one is
     * made live again by the one conditional write every PATCH goes through
     * (GoogleWriter::patch: that read compared with the rows here by
     * GoogleSeries::condition, its etag the If-Match), its body what differs
     * from that read in the allowed list (GoogleWriter::patchOf).
     */
    private function insertChosen(array $calendar, \Closure $send, string $id, array $body, ?\Closure $beforeSend): array
    {
        try {
            return $send('POST', '', ['id' => $id] + $body, null);
        } catch (HttpError $e) {
            if ((int) ($e->extra['googleStatus'] ?? 0) !== 409) {
                throw $e;
            }
            $found = $send('GET', '/' . rawurlencode($id), null, null);
            if ((string) ($found['status'] ?? '') !== 'cancelled') {
                return $found;
            }
            return $this->writer->patch($calendar, $id, GoogleWriter::patchOf($found, $body + ['status' => 'confirmed']), $beforeSend, $found, false);
        }
    }

    /** A DELETE, conditional on the etag of a read (GoogleWriter refuses one without); an event already gone (404, or 410 once deleted) is gone. */
    private function cancel(\Closure $send, string $id, ?string $etag): void
    {
        try {
            $send('DELETE', '/' . rawurlencode($id), null, $etag);
        } catch (HttpError $e) {
            if (!GoogleWriter::gone($e)) {
                throw $e;
            }
        }
    }

    /**
     * The series as Google holds it: the master and every exception record
     * (cancelled ones included), listed by its iCalendar UID with
     * singleEvents=false, never events.instances. A list made before a
     * write shows (the master's etag not yet $expectEtag) is read again, a
     * bounded number of times with backoff.
     *
     * @return list<array>
     */
    private function readBack(array $calendar, \Closure $send, ?string $uid, string $googleId, ?string $expectEtag, ?\Closure $beforeSend = null): array
    {
        for ($try = 0; ; $try++) {
            $items = [];
            if ($uid === null || $uid === '') {
                try {
                    $items = [$send('GET', '/' . rawurlencode($googleId), null, null)];
                } catch (HttpError $e) {
                    if ((int) ($e->extra['googleStatus'] ?? 0) !== 404) {
                        throw $e;
                    }
                }
            } else {
                $pageToken = null;
                $pages = 0;
                do {
                    $query = ['iCalUID' => $uid, 'singleEvents' => 'false', 'showDeleted' => 'true', 'maxResults' => '2500'];
                    if ($pageToken !== null) {
                        $query['pageToken'] = $pageToken;
                    }
                    $data = $this->writer->send($calendar, 'GET', '', null, $query, null, $beforeSend);
                    foreach ((array) ($data['items'] ?? []) as $item) {
                        if (is_array($item)) {
                            $items[] = $item;
                        }
                    }
                    $pageToken = isset($data['nextPageToken']) && is_string($data['nextPageToken']) && $data['nextPageToken'] !== '' ? $data['nextPageToken'] : null;
                } while ($pageToken !== null && ++$pages < self::PAGES);
            }
            if ($expectEtag === null || self::masterEtag($items, $googleId) === $expectEtag || $try + 1 >= self::READBACK_TRIES) {
                return $items;
            }
            ($this->sleep ??= static fn(float $s) => usleep((int) ($s * 1e6)))(self::BACKOFF[min($try, count(self::BACKOFF) - 1)]);
        }
    }

    private static function masterEtag(array $items, string $googleId): ?string
    {
        foreach ($items as $item) {
            if ((string) ($item['id'] ?? '') === $googleId && isset($item['etag']) && is_string($item['etag'])) {
                return $item['etag'];
            }
        }
        return null;
    }

    // ---- bodies (pure) -------------------------------------------------------

    /** A row array GoogleWriter::body reads, from a target's columns (no rule, no skips). */
    private static function rowOf(array $cols, ?string $rrule = null): array
    {
        return [
            'title' => $cols['title'], 'description' => $cols['description'], 'location' => $cols['location'], 'status' => $cols['status'],
            'start_utc' => $cols['start_utc'], 'end_utc' => $cols['end_utc'], 'all_day' => $cols['all_day'], 'tzid' => $cols['tzid'],
            'url' => $cols['url'] ?? null, 'rrule' => $rrule, 'exdates_json' => null,
        ];
    }

    /** A new series' body: the master with its rule alone (a skip is a cancelled instance, never an EXDATE line). */
    public static function insertBody(array $master): array
    {
        $body = GoogleWriter::body(self::rowOf($master, $master['rrule'] !== '' ? (string) $master['rrule'] : null));
        $body['recurrence'] = $master['rrule'] !== '' ? ['RRULE:' . $master['rrule']] : [];
        return $body;
    }

    /**
     * What a master PATCH carries: the fields that differ between the
     * series as last read and the target, and its rule whenever anything
     * does, as the one RRULE line (so a skip Google held as an EXDATE line
     * becomes a cancelled instance by the step that follows). Nothing when
     * nothing differs. $before null (the row unknown here) sends the whole.
     */
    public static function masterBody(?array $before, array $after): array
    {
        $body = [];
        $full = GoogleWriter::body(self::rowOf($after, $after['rrule'] !== '' ? (string) $after['rrule'] : null));
        foreach (['title' => 'summary', 'description' => 'description', 'location' => 'location', 'status' => 'status'] as $col => $field) {
            if ($before === null || (string) ($before[$col] ?? '') !== (string) ($after[$col] ?? '')) {
                $body[$field] = $full[$field];
            }
        }
        if ($before === null || $before['start_utc'] !== $after['start_utc'] || $before['end_utc'] !== $after['end_utc']
            || (int) $before['all_day'] !== (int) $after['all_day'] || Time::normalizeTzid($before['tzid']) !== Time::normalizeTzid($after['tzid'])) {
            $body['start'] = $full['start'];
            $body['end'] = $full['end'];
        }
        if ($before === null || (string) ($before['url'] ?? '') !== (string) ($after['url'] ?? '')) {
            // The link as Google's source; null clears one (a PATCH leaves out what it doesn't name).
            $body['source'] = $full['source'] ?? null;
        }
        if ($body !== [] || $before === null || (string) $before['rrule'] !== (string) $after['rrule']) {
            $body['recurrence'] = $after['rrule'] !== '' ? ['RRULE:' . $after['rrule']] : [];
        }
        return array_intersect_key($body, array_flip(GoogleWriter::allowedKeys()));
    }

    /**
     * An exception's body on its instance id: its fields and placement and
     * the link it shows (its own, or the series' it inherits: Google holds
     * values, and a record's is compared and sent as every other field is),
     * cleared when it has none.
     */
    private static function exceptionBody(array $x): array
    {
        $body = GoogleWriter::instanceBody(self::rowOf($x));
        if (!isset($body['source'])) {
            $body['source'] = null;
        }
        return array_intersect_key($body, array_flip(GoogleWriter::allowedKeys()));
    }

    // ---- what a series holds (pure; rule B) -----------------------------------------

    /**
     * What Google holds for a series, read from a read-back: the master as
     * parsed (null when not live), the live exception records and the
     * cancelled ones by occurrence key, and the skips (cancelled records
     * and EXDATE lines). A dropped record (cancelled, no originalStartTime)
     * says nothing. The master and each live record carry `extras`, the
     * every other field of the resource (extrasOf).
     *
     * A record is an instance by its identity, which is its series, its
     * form and its key, never by its key alone. Its form and its id are
     * the ones the sync reads for every instance item (GoogleSync::toParsed:
     * `instance_all_day`, `google_event_id`), the one reading of an item's
     * identity. A record in another form than the master read with it (a
     * list that lags a change between timed and all-day) is no day of that
     * master and is left out, whatever key it shares with one. `ids` keeps
     * the id Google lists each record under, by its key.
     *
     * @return array{master:?array, records:array<string,array>, cancelled:array<string,true>, skips:array<string,true>, etags:array<string,string>, ids:array<string,string>}
     */
    public static function held(array $items, string $googleId): array
    {
        $out = ['master' => null, 'records' => [], 'cancelled' => [], 'skips' => [], 'etags' => [], 'ids' => []];
        $days = [];
        foreach ($items as $item) {
            if (isset($item['id'], $item['etag']) && is_string($item['etag'])) {
                $out['etags'][(string) $item['id']] = $item['etag'];
            }
            $parsed = self::parsed($item);
            if ($parsed === null) {
                continue;
            }
            if ((string) ($item['id'] ?? '') === $googleId && empty($parsed['cancelled']) && $parsed['recurrence_instance_utc'] === null) {
                $out['master'] = $parsed;
                foreach ($parsed['exdates'] ?? [] as $ex) {
                    $out['skips'][(string) $ex] = true;
                }
                continue;
            }
            if (empty($parsed['dropped']) && $parsed['recurrence_instance_utc'] !== null) {
                $days[] = $parsed;
            }
        }
        // Each record against the master it was read with (a list gives no order, so the master is found first).
        foreach ($days as $parsed) {
            if ($out['master'] !== null && (bool) $parsed['instance_all_day'] !== ((int) $out['master']['all_day'] === 1)) {
                continue;
            }
            $key = (string) $parsed['recurrence_instance_utc'];
            $out['ids'][$key] = (string) $parsed['google_event_id'];
            if (!empty($parsed['cancelled'])) {
                $out['cancelled'][$key] = true;
                $out['skips'][$key] = true;
            } else {
                $out['records'][$key] = $parsed;
            }
        }
        return $out;
    }

    /** A Google resource as the sync reads it (GoogleSync::toParsed), with the fields Google keeps that no column holds (`extras`). */
    private static function parsed(array $item): ?array
    {
        $parsed = GoogleSync::toParsed($item);
        if ($parsed !== null && empty($parsed['cancelled'])) {
            $parsed['extras'] = self::extrasOf($item);
        }
        return $parsed;
    }

    /** Two rule texts as the same rule: the same parts with the same values, each list a set, INTERVAL=1 as none (Google reorders). */
    public static function sameRule(string $a, string $b): bool
    {
        return self::ruleParts($a) === self::ruleParts($b);
    }

    /** @return array<string,string> */
    private static function ruleParts(string $rrule): array
    {
        $parts = [];
        foreach (explode(';', strtoupper(trim(preg_replace('/^RRULE:/i', '', $rrule) ?? ''))) as $piece) {
            if ($piece === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $piece, 2), 2, '');
            if (str_contains($v, ',')) {
                $items = explode(',', $v);
                sort($items);
                $v = implode(',', $items);
            }
            $parts[$k] = $v;
        }
        if (($parts['INTERVAL'] ?? '') === '1') {
            unset($parts['INTERVAL']);
        }
        ksort($parts);
        return $parts;
    }

    /**
     * Rule B: "nothing changed since" means nothing on the Google event
     * resource changed. A series' state is every field of the master's
     * resource and of each record's, less what Google rewrites on its own
     * (VOLATILE), plus the cancelled set. The fields a column here holds are
     * compared normalized (fieldsOf: READ); every other one is compared as
     * Google gives it (`extras`): guests, colour, busy or free, visibility,
     * reminders, the guests' permissions, attachments, a call, private
     * properties, and any field Google adds later, by default.
     *
     * What Google rewrites on its own, never a change made by anyone:
     */
    private const VOLATILE = [
        'etag',              // changes on every write to any row of the series (probe 5.1)
        'updated',           // the time of the last write
        'created',           // the time of the insert
        'sequence',          // counted up by Google on a time, rule or status change
        'htmlLink',          // Google's page for the event, derived from its id
        'iCalUID',           // the series' identity, never changed
        'id',                // the resource's identity, never changed
        'kind',              // always calendar#event
        'creator',           // who made it, stamped at the insert
        'organizer',         // the calendar that owns it, stamped at the insert
        'recurringEventId',  // a record's series, part of its identity
        'originalStartTime', // a record's occurrence, its key
        'hangoutLink',       // derived by Google from conferenceData, which is compared
    ];

    /** The resource's fields a column here holds, compared normalized by fieldsOf (and a rule by its parts), not raw. */
    private const READ = ['summary', 'description', 'location', 'status', 'source', 'start', 'end', 'recurrence'];

    /** The value Google shows for a field it was given none for: equal to it is the same as absent, so an insert that set none is what it sent. */
    private const DEFAULTS = [
        'attendees' => [], 'transparency' => 'opaque', 'visibility' => 'default', 'reminders' => ['useDefault' => true],
        'guestsCanModify' => false, 'guestsCanInviteOthers' => true, 'guestsCanSeeOtherGuests' => true, 'anyoneCanAddSelf' => false,
        'attachments' => [], 'eventType' => 'default', 'endTimeUnspecified' => false, 'attendeesOmitted' => false, 'locked' => false, 'privateCopy' => false,
    ];

    /** A resource's fields beyond what a column holds (rule B), each in one canonical form, a default left out. @return array<string,mixed> */
    private static function extrasOf(array $item): array
    {
        $out = [];
        foreach ($item as $field => $value) {
            $field = (string) $field;
            if (in_array($field, self::VOLATILE, true) || in_array($field, self::READ, true) || $value === null) {
                continue;
            }
            $value = self::canonical($value);
            if (array_key_exists($field, self::DEFAULTS) && $value === self::canonical(self::DEFAULTS[$field])) {
                continue;
            }
            $out[$field] = $value;
        }
        ksort($out);
        return $out;
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonical($v);
        }
        if (!array_is_list($out)) {
            ksort($out);
        }
        return $out;
    }

    /**
     * An event's fields as Google holds them, normalized as Google gives
     * them back: the one field list every comparison of a Google series
     * reads (rule B). It is the union of what GoogleWriter::body sends and
     * what GoogleSync::toParsed reads into a column: the title, notes,
     * place, status, link (`source`), and everything that places its
     * occurrences, the zone of a timed event among them (a series' zone
     * decides where every later day falls). The zone is the string a write
     * sends (Time::normalizeTzid, as GoogleWriter::body names it and
     * GoogleSync reads it back): two spellings of one zone are two strings,
     * a difference, which errs on the side of "changed"; an all-day event
     * has no zone at Google. A rule is compared as its parts beside these
     * (state). With $extras, the fields Google keeps that no column holds
     * (extrasOf). The guest list the sync
     * reads (into invite_json) is among those, as Google holds it.
     */
    private static function fieldsOf(array $r, bool $extras = false): array
    {
        $title = trim((string) ($r['title'] ?? ''));
        $allDay = (int) ($r['all_day'] ?? 0);
        return [
            'title' => $title === '(No title)' ? '' : $title,
            'description' => Sanitize::description(isset($r['description']) && $r['description'] !== '' ? (string) $r['description'] : null) ?? '',
            'location' => trim((string) ($r['location'] ?? '')),
            'status' => (string) ($r['status'] ?? 'confirmed') === 'tentative' ? 'tentative' : 'confirmed',
            'start_utc' => (string) $r['start_utc'],
            'end_utc' => (string) $r['end_utc'],
            'all_day' => $allDay,
            'tzid' => $allDay === 1 ? '' : Time::normalizeTzid((string) ($r['tzid'] ?? 'UTC')),
            'url' => (string) (Ics::webUrl(isset($r['url']) ? (string) $r['url'] : null) ?? ''),
        ] + ($extras ? ['extras' => (array) ($r['extras'] ?? [])] : []);
    }

    /** Whether two events are the same event in the fields both sides know (fieldsOf; a target has no extras). */
    private static function sameEvent(array $a, array $b): bool
    {
        return self::fieldsOf($a) === self::fieldsOf($b);
    }

    /**
     * Whether a record holds its series' own values at its occurrence
     * (plainAt): an ordinary day, in every comparison and every send, never
     * written and never reported (rule A). With $extras, its extras are the
     * master's too.
     */
    private static function isPlain(array $record, array $master, string $key, bool $extras = false): bool
    {
        return self::fieldsOf($record, $extras) === self::fieldsOf(self::plainAt($master, $key, self::lengthOf($master)), $extras);
    }

    private static function lengthOf(array $m): int
    {
        return max(0, Time::fromDb((string) $m['end_utc'])->getTimestamp() - Time::fromDb((string) $m['start_utc'])->getTimestamp());
    }

    /**
     * A series' state at Google (rule B): the master's fields (fieldsOf)
     * and its rule as parts, each edited day's fields by its key (a record
     * at the series' own values is an ordinary day, isPlain), and the
     * skipped keys; with $extras, the extras of the master and every day.
     * Two readings are the same series when their digests are equal; an
     * etag plays no part. The one thing "nothing changed since" (a step's
     * `expect`, unchangedSince) is read from. $h is held()'s shape (or
     * heldOf()'s, or afterMaster()'s).
     *
     * @return array{master:?array, records:array<string,array>, skips:list<string>}
     */
    public static function state(array $h, bool $extras = false): array
    {
        $m = $h['master'];
        $out = ['master' => $m === null ? null : self::fieldsOf($m, $extras) + ['rule' => self::ruleParts((string) ($m['rrule'] ?? ''))], 'records' => [], 'skips' => []];
        foreach ($h['records'] as $key => $r) {
            if ($m !== null && self::isPlain($r, $m, (string) $key, $extras)) {
                continue;
            }
            $out['records'][(string) $key] = self::fieldsOf($r, $extras);
        }
        ksort($out['records']);
        $out['skips'] = array_map('strval', array_keys($h['skips']));
        sort($out['skips']);
        return $out;
    }

    /** A state's digest: what a step journals as what it leaves (`expect`), and what Put back compares Google's now against. */
    private static function digest(array $state): string
    {
        return sha1((string) json_encode($state));
    }

    /** A target (or a series as stored, stateOf) in held()'s shape: what Google holds once it holds the target (a skip is a cancelled instance). */
    private static function heldOf(array $t): array
    {
        $records = [];
        foreach ($t['exceptions'] as $key => $x) {
            $records[(string) $key] = $x;
        }
        $skips = array_fill_keys(array_map('strval', $t['skips']), true);
        return ['master' => $t['master'], 'records' => $records, 'cancelled' => $skips, 'skips' => $skips, 'etags' => []];
    }

    /**
     * What Google holds once an instance write lands: the record at $key as
     * $row, or the day skipped. A record keeps the extras it had, and one
     * made of an occurrence takes its series' (Google's instances inherit them).
     */
    private static function heldAfter(array $h, string $kind, string $key, ?array $row): array
    {
        $extras = $h['records'][$key]['extras'] ?? $h['master']['extras'] ?? [];
        unset($h['records'][$key], $h['cancelled'][$key], $h['skips'][$key]);
        if ($kind === 'skip') {
            $h['cancelled'][$key] = true;
            $h['skips'][$key] = true;
        } else {
            $h['records'][$key] = ['extras' => $extras] + (array) $row;
        }
        return $h;
    }

    /**
     * What Google holds once a master write lands, from what it held before
     * ($h, held()'s shape) and the master before and after ($old, $new: a
     * target's master): Google's own effect on a series' days, as the probe
     * found it (the contract's "Exceptions on a master change", group 3).
     * The write is masterBody($old, $new); nothing sent, nothing changes.
     *
     * A record or a skip held under the old master survives the write
     * exactly when all three hold (the model: decisions.md, "What a master
     * write leaves at Google is predicted kept only by identity and proof"):
     *
     * S1, identity. Its id under the old master's form is the id of an
     * occurrence of the new master under the new form, through the one
     * pair (GoogleWriter::instanceId). An id carries its form, so a change
     * between timed and all-day drops every record and every skip, in both
     * directions, at every clock, whatever keys coincide; with the form
     * unchanged, a key that is no longer an occurrence is dropped (a new
     * clock or start day, 3.1 and 3.4; days past a new end, 3.9 and 3.10).
     *
     * S2, Google's own resets. A cancelled record goes with any change of
     * the rule's days (3.7); a status sent makes a surviving cancelled
     * record a live one (3.15); a skip held as an EXDATE line goes with the
     * rule line the write sends alone (3.16).
     *
     * S3, proof. Everything the write changes is of a class the probe saw
     * keep records: the length (3.2), the bound (3.8 to 3.10), a weekday
     * added to a weekly rule (3.7), the start moved along its own pattern
     * (3.5), the title, notes, place or status (3.12 to 3.16). A class it
     * has not seen keep them is predicted dropped (unproven()): a prediction
     * wrong that way costs a refused Put back with Finish as the way out,
     * never a day's fields or a skip. For that to hold, rebase reads what
     * Google holds now against the other answer too: $ifKept gives the
     * series as the write leaves it should Google keep through an unproven
     * class everything S1 and S2 allow (S3 not applied), each survivor with
     * the write's effects. The two are the same state for a proven write.
     *
     * A survivor follows a changed start onto its occurrence with the new
     * length (and a changed length alone with its end), and takes every
     * title, notes, place or status the write sends. The state a master
     * step journals it leaves (rule B), and what "the change's own effect"
     * is to Finish (rebase). An undo of a series change is a forward change
     * through this same function.
     */
    public static function afterMaster(array $h, array $old, array $new, bool $ifKept = false): array
    {
        $body = self::masterBody($old, $new);
        if ($body === [] || $h['master'] === null) {
            return $h;
        }
        $zoneOf = static fn(array $m): string => (int) $m['all_day'] === 1 ? '' : Time::normalizeTzid((string) $m['tzid']);
        $startChanged = (string) $old['start_utc'] !== (string) $new['start_utc'] || $zoneOf($old) !== $zoneOf($new);
        $lenOld = self::lengthOf($old);
        $lenNew = self::lengthOf($new);
        $patternChanged = self::patternOf((string) $old['rrule']) !== self::patternOf((string) $new['rrule']);
        $proven = $ifKept || self::unproven($old, $new, $body) === [];
        $set = [];
        foreach (['summary' => 'title', 'description' => 'description', 'location' => 'location', 'status' => 'status'] as $field => $col) {
            if (array_key_exists($field, $body)) {
                $set[$col] = $new[$col] ?? null;
            }
        }
        $was = (int) $old['all_day'] === 1;
        $allDay = (int) $new['all_day'] === 1;
        $series = ['start_utc' => $new['start_utc'], 'end_utc' => $new['end_utc'], 'all_day' => (int) $new['all_day'], 'tzid' => (string) $new['tzid'], 'rrule' => (string) $new['rrule'], 'exdates_json' => null];
        // S1: the id the record has under the old master's form is an id the new master gives one of its occurrences.
        $named = static function (string $key) use ($series, $was, $allDay): bool {
            if ($series['rrule'] === '') {
                return false;
            }
            $id = GoogleWriter::instanceId('', $key, $was);
            $at = Time::fromDb($key);
            foreach (Recurrence::occurrences($series, $at->modify('-1 day'), $at->modify('+2 days'), null, 8) as $o) {
                if (GoogleWriter::instanceId('', Time::toDb($o['start']), $allDay) === $id) {
                    return true;
                }
            }
            return false;
        };
        $follow = static function (array $r, string $key) use ($startChanged, $lenOld, $lenNew, $new, $allDay, $set): array {
            if ($startChanged) {
                $at = Time::fromDb($key);
                $r['start_utc'] = Time::toDb($at);
                $r['end_utc'] = Time::toDb($allDay ? $at->modify('+' . max(1, intdiv($lenNew, 86400)) . ' days') : $at->modify('+' . $lenNew . ' seconds'));
                $r['all_day'] = (int) $new['all_day'];
                $r['tzid'] = (string) $new['tzid'];
            } elseif ($lenNew !== $lenOld) {
                $start = Time::fromDb((string) $r['start_utc']);
                $r['end_utc'] = Time::toDb((int) ($r['all_day'] ?? 0) === 1 ? $start->modify('+' . max(1, intdiv($lenNew, 86400)) . ' days') : $start->modify('+' . $lenNew . ' seconds'));
            }
            return array_merge($r, $set);
        };
        $out = $h;
        $out['master'] = ['extras' => $h['master']['extras'] ?? []] + $new;
        foreach ($h['records'] as $key => $r) {
            $key = (string) $key;
            unset($out['records'][$key]);
            if ($proven && $named($key)) {
                $out['records'][$key] = $follow($r, $key);
            }
        }
        foreach (array_keys($h['skips']) as $key) {
            $key = (string) $key;
            unset($out['cancelled'][$key], $out['skips'][$key]);
            // S2: an EXDATE line goes with the rule line sent alone, a cancelled record with any change of the rule's days.
            if (!isset($h['cancelled'][$key]) || $patternChanged || !$proven || !$named($key)) {
                continue;
            }
            if (array_key_exists('status', $set)) {
                // Un-cancelled: the day as its record held it, the series' then.
                $out['records'][$key] = $follow(['extras' => $h['master']['extras'] ?? []] + self::plainAt($old, $key, $lenOld), $key);
                continue;
            }
            $out['cancelled'][$key] = true;
            $out['skips'][$key] = true;
        }
        ksort($out['records']);
        return $out;
    }

    /** A rule's days: its parts without its bound (COUNT, UNTIL). @return array<string,string> */
    private static function patternOf(string $rrule): array
    {
        $parts = self::ruleParts($rrule);
        unset($parts['COUNT'], $parts['UNTIL']);
        return $parts;
    }

    /**
     * What a master write changes that the probe has not seen keep a
     * series' records (S3 of afterMaster), in words; none when everything
     * it changes is of a proven class. The one list of unproven classes: a
     * class moves to the proven side by leaving this function once a live
     * probe shows Google keeps records through it.
     *
     * - The zone of a timed series. A zone change that moves the instants
     *   drops every record (3.3); one that moves none (10:00 Los Angeles
     *   to 11:00 Denver) is not answered yet (3.17).
     * - The link. Google's `source` has no probe case, on the master or
     *   written into its records.
     * - The rule's days, other than weekdays added to a weekly rule (3.7).
     *   A weekday changed and the clamped month-end form dropped every
     *   record (3.6, 3.11); a change of frequency or interval that leaves
     *   some days where they were has no case.
     *
     * @return list<string>
     */
    private static function unproven(array $old, array $new, array $body): array
    {
        $out = [];
        if ((int) $old['all_day'] === 0 && (int) $new['all_day'] === 0 && Time::normalizeTzid((string) $old['tzid']) !== Time::normalizeTzid((string) $new['tzid'])) {
            $out[] = 'zone';
        }
        if (array_key_exists('source', $body)) {
            $out[] = 'link';
        }
        if (self::patternOf((string) $old['rrule']) !== self::patternOf((string) $new['rrule']) && !self::addsWeekdays($old, $new)) {
            $out[] = 'rule';
        }
        return $out;
    }

    /**
     * Whether a rule change only adds weekdays to a weekly rule (probe
     * 3.7): both weekly, every part but BYDAY the same, and every weekday
     * the old rule names still named. A rule with no BYDAY names its
     * start's weekday.
     */
    private static function addsWeekdays(array $old, array $new): bool
    {
        $days = static function (array $m): array {
            $parts = self::patternOf((string) $m['rrule']);
            if (($parts['BYDAY'] ?? '') !== '') {
                return explode(',', $parts['BYDAY']);
            }
            $zone = (int) $m['all_day'] === 1 ? 'UTC' : (string) $m['tzid'];
            return [strtoupper(substr(Time::fromDb((string) $m['start_utc'])->setTimezone(Time::zone($zone))->format('D'), 0, 2))];
        };
        $rest = static function (array $m): array {
            $parts = self::patternOf((string) $m['rrule']);
            unset($parts['BYDAY']);
            return $parts;
        };
        return ($rest($old)['FREQ'] ?? '') === 'WEEKLY' && $rest($old) === $rest($new) && array_diff($days($old), $days($new)) === [];
    }

    /**
     * The steps a read-back calls for, each a PATCH on an instance id, from
     * one comparison per day (rule A): what the target wants of the day (an
     * edited day, which is a row owning a field Google holds, rule D; a
     * skip; or an ordinary day) against what Google holds (a record, a
     * cancelled one, or none; for a day the target has no edited day for, a
     * record at the series' values is none). An edited day Google doesn't
     * hold as the target has it (dropped, reset, overwritten, or never
     * there) is sent whole, so the record the row stands on exists; a
     * skipped day not cancelled is cancelled; a day Google holds as an
     * edited or cancelled record that the target has as ordinary is put
     * back to the series' values at its occurrence. A day both have as
     * ordinary is never sent, whatever records Google keeps for it.
     *
     * Each step goes to the day's identity at Google: the id Google lists
     * the day's record under, and for a day it lists no record of, the id
     * its key has in the form of the master as read (GoogleWriter::instanceId),
     * never in the target's form: an id in a form Google's master is not in
     * names nothing there. run() sends no step while the two forms differ.
     *
     * @return list<array{0:string,1:string,2:array,3:string}> kind, instance id, body, occurrence key
     */
    public static function stepsFor(array $t, array $items): array
    {
        $held = self::held($items, (string) $t['googleId']);
        $m = $t['master'];
        $allDay = (int) ($held['master'] ?? $m)['all_day'] === 1;
        $skipSet = array_fill_keys(array_map('strval', $t['skips']), true);
        $keys = array_values(array_unique(array_map('strval', array_merge(array_keys($t['exceptions']), $t['skips'], array_keys($held['records']), array_keys($held['cancelled'])))));
        sort($keys);
        $out = ['exception' => [], 'skip' => [], 'plain' => []];
        foreach ($keys as $key) {
            $x = $t['exceptions'][$key] ?? null;
            $want = isset($skipSet[$key]) ? 'skip' : $x;
            $record = $held['records'][$key] ?? null;
            // A record the target has no edited day for is an ordinary day when it holds the series' values.
            $has = isset($held['skips'][$key]) ? 'skip' : ($record !== null && ($x !== null || !self::isPlain($record, $m, $key)) ? $record : null);
            $id = $held['ids'][$key] ?? GoogleWriter::instanceId((string) $t['googleId'], $key, $allDay);
            if ($want === 'skip') {
                if ($has !== 'skip') {
                    $out['skip'][] = ['skip', $id, ['status' => 'cancelled'], $key];
                }
            } elseif ($want === null) {
                if ($has !== null) {
                    $out['plain'][] = ['plain', $id, self::exceptionBody(self::plainAt($m, $key, self::lengthOf($m))), $key];
                }
            } elseif (!is_array($has) || !self::sameEvent($has, $want)) {
                $out['exception'][] = ['exception', $id, self::exceptionBody($want), $key];
            }
        }
        return array_merge($out['exception'], $out['skip'], $out['plain']);
    }

    /** The series' own values at an occurrence: the master's fields, the occurrence's start and the series' length. */
    private static function plainAt(array $master, string $key, int $len): array
    {
        $start = Time::fromDb($key);
        return ['own' => []] + array_merge($master, [
            'start_utc' => Time::toDb($start),
            'end_utc' => Time::toDb((int) $master['all_day'] === 1 ? $start->modify('+' . max(1, (int) round($len / 86400)) . ' days') : $start->modify('+' . $len . ' seconds')),
        ]);
    }

    /**
     * Where Google and the target differ after the steps, in words for the
     * Activity entry and the Review notice (invented examples: "Google kept
     * 'Rehearsal' on Tue 13 Apr; the plan had Wed 14 Apr."). Read from the
     * same day comparison as stepsFor: a day both have as ordinary (no
     * record, or one at the series' own values) is no difference.
     *
     * @return list<string>
     */
    public static function differences(array $t, array $items, int $thisYear): array
    {
        $held = self::held($items, (string) $t['googleId']);
        $m = $t['master'];
        $allDay = (int) $m['all_day'] === 1;
        $tz = $allDay ? 'UTC' : (string) $m['tzid'];
        $day = static fn(string $key): string => Words::date(Cal::dnOf(Time::fromDb($key)->setTimezone(Time::zone($tz))), $thisYear);
        $shown = static fn(array $r): string => self::shown($r, $tz, $thisYear);
        $out = [];
        $g = $held['master'];
        if ($g === null) {
            $out[] = "Google no longer lists '" . $m['title'] . "' as a series.";
            return $out;
        }
        if (!self::sameRule((string) ($g['rrule'] ?? ''), (string) $m['rrule'])) {
            $out[] = 'Google kept the rule ' . ($g['rrule'] ?? 'none') . '; the plan had ' . ($m['rrule'] !== '' ? $m['rrule'] : 'none') . '.';
        }
        if ((string) $g['start_utc'] !== (string) $m['start_utc'] || (string) $g['end_utc'] !== (string) $m['end_utc'] || (int) $g['all_day'] !== (int) $m['all_day']) {
            $out[] = self::startWords($g, $m, $thisYear);
        } elseif (!$allDay && self::fieldsOf($g)['tzid'] !== self::fieldsOf($m)['tzid']) {
            $out[] = "Google kept the series in the zone '" . self::fieldsOf($g)['tzid'] . "'; the plan had '" . self::fieldsOf($m)['tzid'] . "'.";
        }
        foreach (['title' => 'title', 'description' => 'notes', 'location' => 'place', 'status' => 'status', 'url' => 'link'] as $col => $word) {
            if (self::fieldsOf($g)[$col] !== self::fieldsOf($m)[$col]) {
                $out[] = "Google kept the series' $word as '" . (string) ($g[$col] ?? '') . "'; the plan had '" . (string) ($m[$col] ?? '') . "'.";
            }
        }
        $skipSet = array_fill_keys(array_map('strval', $t['skips']), true);
        $edited = static fn(?array $r, string $key): bool => $r !== null && !self::isPlain($r, $m, $key);
        foreach ($t['exceptions'] as $key => $x) {
            $key = (string) $key;
            if (isset($skipSet[$key])) {
                continue;
            }
            $record = $held['records'][$key] ?? null;
            $name = "'" . $x['title'] . "'";
            if ($record === null) {
                $out[] = 'Google kept ' . $day($key) . (isset($held['cancelled'][$key]) ? ' skipped' : ' as an ordinary day') . "; the plan had $name on " . $shown($x) . '.';
                continue;
            }
            if (self::sameEvent($record, $x)) {
                continue;
            }
            if ((string) $record['start_utc'] !== (string) $x['start_utc'] || (int) $record['all_day'] !== (int) $x['all_day']) {
                $out[] = "Google kept $name on " . $shown($record) . '; the plan had ' . $shown($x) . '.';
            } elseif (trim((string) $record['title']) !== trim((string) $x['title'])) {
                $out[] = "Google kept '" . $record['title'] . "' on " . $day($key) . "; the plan had $name.";
            } else {
                $out[] = "Google kept $name on " . $day($key) . ' with other details than the plan had.';
            }
        }
        foreach ($t['skips'] as $key) {
            if (!isset($held['skips'][$key])) {
                $out[] = 'Google kept ' . $day((string) $key) . '; the plan skipped it.';
            }
        }
        foreach ($held['records'] as $key => $record) {
            $key = (string) $key;
            if (!isset($skipSet[$key]) && !isset($t['exceptions'][$key]) && $edited($record, $key)) {
                $out[] = "Google kept '" . $record['title'] . "' on " . $day($key) . '; the plan had an ordinary day.';
            }
        }
        foreach (array_keys($held['cancelled']) as $key) {
            $key = (string) $key;
            if (!isset($skipSet[$key]) && !isset($t['exceptions'][$key])) {
                $out[] = 'Google kept ' . $day($key) . ' skipped; the plan had it.';
            }
        }
        return $out;
    }

    /** When an event is, in words: its day, and its time in its own zone ($tz for one that names none) unless it is all-day. */
    private static function shown(array $r, string $tz, int $thisYear): string
    {
        $at = Time::fromDb((string) $r['start_utc']);
        if ((int) ($r['all_day'] ?? 0) === 1) {
            return Words::date(Cal::dnOf($at), $thisYear);
        }
        $zone = Time::zone((string) ($r['tzid'] ?? $tz));
        return Words::date(Cal::dnOf($at->setTimezone($zone)), $thisYear) . ' at ' . $at->setTimezone($zone)->format('H:i');
    }

    /** The difference named when Google holds a series at another start, length or form (timed or all-day) than the plan has: $g Google's master, $m the plan's. */
    private static function startWords(array $g, array $m, int $thisYear): string
    {
        $tz = (int) $m['all_day'] === 1 ? 'UTC' : (string) $m['tzid'];
        return 'Google kept the series starting ' . self::shown($g, $tz, $thisYear) . '; the plan had ' . self::shown($m, $tz, $thisYear) . '.';
    }

    // ---- the change over what Google holds now (pure; rule A) -------------------------

    /** The groups a day's or a master's fields are compared in: each content field, and its placement as one. */
    private const GROUPS = ['title' => ['title'], 'description' => ['description'], 'location' => ['location'], 'status' => ['status'], 'url' => ['url'],
        'when' => ['start_utc', 'end_utc', 'all_day', 'tzid']];
    private const GROUP_WORDS = ['title' => 'title', 'description' => 'notes', 'location' => 'place', 'status' => 'status', 'url' => 'link', 'when' => 'time', 'rule' => 'rule'];
    /** An edited day's own field, by the group it is compared in. */
    private const OWN_GROUP = ['date' => 'when', 'time' => 'when', 'length' => 'when', 'allDay' => 'when', 'title' => 'title', 'description' => 'description',
        'location' => 'location', 'url' => 'url', 'status' => 'status'];

    /** An event's fields (fieldsOf) by group; a master's with its rule as parts. @return array<string,mixed> */
    private static function groupsOf(array $r, bool $master = false): array
    {
        $f = self::fieldsOf($r);
        $out = [];
        foreach (self::GROUPS as $g => $cols) {
            $out[$g] = count($cols) === 1 ? $f[$cols[0]] : array_intersect_key($f, array_flip($cols));
        }
        return $master ? $out + ['rule' => self::ruleParts((string) ($r['rrule'] ?? ''))] : $out;
    }

    /** A day of a series in held()'s shape, compared by group: 'skip', or its fields by group (its record's, else the series' values there). */
    private static function dayIn(array $h, string $key): string|array
    {
        if (isset($h['skips'][$key])) {
            return 'skip';
        }
        return self::groupsOf($h['records'][$key] ?? self::plainAt($h['master'], $key, self::lengthOf($h['master'])));
    }

    /** What a change changes of one value, read three ways: whether Google's value now is one the change explains, else whether the change changes it at all. */
    private static function threeWay(mixed $now, array $explained, mixed $before, mixed $after): string
    {
        if (in_array($now, $explained, true)) {
            return 'change';
        }
        return $before === $after ? 'kept' : 'conflict';
    }

    /** What a change made at Google did to a day, by the group it changed, as a sentence's verb phrase ("was renamed"); the time by the series' form. */
    private const DONE_WORDS = ['title' => 'renamed', 'description' => 'given other notes', 'location' => 'given another place', 'status' => 'given another status', 'url' => 'given another link'];

    /**
     * The changes made at Google that a change of the series between timed
     * and all-day does not keep (rebase), each in one sentence: every day
     * Google holds now ($C, held()) otherwise than the change found it
     * ($B), both read in the form the series had then, so each key is one
     * instance on both sides. The day is named by its date as it showed
     * under that form (an evening west of UTC by its own date, never its
     * key's), with what was done to it at Google. $was is the series as the
     * change found it; $allDay the form it now takes.
     *
     * @return list<string>
     */
    private static function notKept(array $B, array $C, array $was, bool $allDay, int $thisYear, string $since): array
    {
        $zone = Time::zone((int) $was['all_day'] === 1 ? 'UTC' : (string) $was['tzid']);
        $keys = [];
        foreach ([$B, $C] as $h) {
            foreach (array_merge(array_keys($h['records']), array_keys($h['skips'])) as $k) {
                $keys[(string) $k] = true;
            }
        }
        ksort($keys);
        $out = [];
        foreach (array_keys($keys) as $key) {
            $key = (string) $key;
            $b = self::dayIn($B, $key);
            $c = self::dayIn($C, $key);
            if ($b === $c) {
                continue;
            }
            $done = [];
            if ($c === 'skip') {
                $done[] = 'deleted';
            } elseif ($b === 'skip') {
                $done[] = 'brought back';
            } else {
                // Its time, by the series' form: a day of a timed series has a time, one of an all-day series a date (moved whole, or over other days).
                $long = static fn(array $when): int => Time::fromDb((string) $when['end_utc'])->getTimestamp() - Time::fromDb((string) $when['start_utc'])->getTimestamp();
                foreach (self::GROUPS as $g => $cols) {
                    if ($b[$g] !== $c[$g]) {
                        $done[] = self::DONE_WORDS[$g] ?? ((int) $was['all_day'] !== 1 ? 'given another time' : ($long($b[$g]) === $long($c[$g]) ? 'moved to another date' : 'given other dates'));
                    }
                }
            }
            $last = array_pop($done);
            $out[] = Words::date(Cal::dnOf(Time::fromDb($key)->setTimezone($zone)), $thisYear) . ' was ' . ($done === [] ? '' : implode(', ', $done) . ' and ') . $last
                . " at Google $since; that change is not kept, because the series is " . ($allDay ? 'now all-day' : 'no longer all-day') . '.';
        }
        return $out;
    }

    /**
     * Rule A: a change applied as its deltas over what Google holds now.
     * $before is the series as the change found it (stateOf, journaled),
     * $target what the change makes of it, $now what Google holds (held()).
     * A delta is what the change changes: each field group of the master,
     * and each day's own part (its own fields, or that it is skipped), from
     * $before to $target. Google's value now is the change's to set where
     * the change explains it: as it was before, as the change's own master
     * write leaves it (afterMaster: Google writes a series' fields into its
     * days, and drops them on a new time; where the write is of a class the
     * probe has not seen keep records, as it leaves it under either answer,
     * dropped or kept), or as the target has it (a step that landed). The
     * day before is the day of the same instance: the row's own before
     * where the plan carries the day's row, else the day at the same key,
     * and that only while Google holds the series in the form the change
     * found it in. Across a change between timed and all-day that has
     * landed, an equal key names another instance (west of UTC another
     * day), so a day with no row is read against what the master write
     * left there, never against the day the old form had on that key. And
     * while that change of form has not reached Google (its master write
     * sent and not taken, then Finish), nothing Google holds is carried
     * across it at all: the master write drops every record and skip, a
     * key of the old form names no day of the new one, and the new form
     * cannot hold every old value (a clock under an all-day series). The
     * plan's days stand whole, and each day changed at Google since, read
     * in the old form against the series as found, is named as not kept
     * (notKept); carrying such a change to its day by the series model's
     * image is left for later (decisions.md). Any
     * other value was changed elsewhere since: kept as
     * Google holds it where the change doesn't change that group of that
     * day, and kept and named where it does (a day deleted since, a field
     * changed since): never overwritten. So every field and day the change
     * did not change stays exactly as Google holds it. A day is an edited
     * day of the result where it differs from the series there, with its
     * own fields the target's and whatever else of its own it holds now. A
     * day the change leaves as it found it that holds no record now where
     * the change's own write would have left one (put back to the series
     * at Google since) follows the series, as a day the change has no row
     * for: its own list is what it holds now (nothing Google holds), never
     * the change's.
     *
     * @return array{0:array,1:list<string>,2:list<array{0:?string,1:string}>} the target over what Google holds now, words for what was left as Google has it (and for what was changed at Google and is not kept across a change of form), and each value left so: [day key, or null for the master; field group, or 'day' for a day as a whole]
     */
    public static function rebase(array $before, array $target, array $now, int $thisYear, string $since = 'since this change stopped', bool $force = false): array
    {
        if ($now['master'] === null || $before['master'] === null || !empty($target['remove'])) {
            return [$target, [], [], []];
        }
        $B = self::heldOf($before);
        $T = self::heldOf($target);
        $P = self::afterMaster($B, $before['master'], $target['master']);
        // The other answer where the write is of a class the probe has not seen keep records: all of them kept (P itself otherwise).
        $Q = self::afterMaster($B, $before['master'], $target['master'], true);
        $C = $now;
        $words = [];
        $conflicts = [];
        $overWords = []; // what a forced rebase put back over, apart from what it kept
        // The master, a group at a time (its own write's effect is the target's values).
        $mb = self::groupsOf($before['master'], true);
        $mt = self::groupsOf($target['master'], true);
        $mc = self::groupsOf($C['master'], true);
        $master = $target['master'];
        $left = [];
        $overwrote = [];
        foreach ($mt as $g => $t) {
            $how = self::threeWay($mc[$g], [$mb[$g], $t], $mb[$g], $t);
            if ($how === 'conflict' && $force) {
                // Forced: the change's reading over the value changed since, named.
                $overwrote[] = self::GROUP_WORDS[$g];
                $conflicts[] = [null, $g];
                continue;
            }
            if ($how === 'change') {
                continue;
            }
            foreach ($g === 'rule' ? ['rrule'] : self::GROUPS[$g] as $col) {
                $master[$col] = $col === 'rrule' ? (string) ($C['master']['rrule'] ?? '') : ($C['master'][$col] ?? null);
            }
            if ($how === 'conflict') {
                $left[] = self::GROUP_WORDS[$g];
                $conflicts[] = [null, $g];
            }
        }
        if ($left !== []) {
            $words[] = "The series' " . implode(', ', $left) . " changed at Google $since; left as Google has it.";
        }
        if ($overwrote !== []) {
            $overWords[] = "The series' " . implode(', ', $overwrote) . " (changed at Google $since).";
        }
        $allDay = (int) $master['all_day'] === 1;
        // Google still holds the series in the form the change found it in,
        // and the master write that follows changes that form (the change
        // stopped before it reached Google): that write drops every record
        // and every skip Google holds, and no id of the old form names a day
        // of the new one, so nothing Google holds now is carried by its key.
        // What changed at Google since is read where it can be, in the old
        // form, against the series as the change found it, and named as not
        // kept; the days are then the plan's, over what the master write
        // leaves (nothing).
        if ((int) $before['master']['all_day'] === (int) $now['master']['all_day'] && $allDay !== ((int) $now['master']['all_day'] === 1)) {
            $words = array_merge($words, self::notKept($B, $now, $before['master'], $allDay, $thisYear, $since));
            $C = $P;
        }
        // An instance is its form and its key: a day B holds at a key is the same instance as the one Google holds there now only in the same form.
        $sameForm = (int) $before['master']['all_day'] === (int) $C['master']['all_day'];
        $len = self::lengthOf($master);
        $dayWords = static fn(string $key): string => Words::date(Cal::dnOf(Time::fromDb($key)->setTimezone(Time::zone($allDay ? 'UTC' : (string) $master['tzid']))), $thisYear);
        // Each day: the change's own before by the row it is (an edited day may move key), else the day at the key.
        $byRow = [];
        foreach ($before['exceptions'] as $k => $x) {
            if (!empty($x['rowId'])) {
                $byRow[(int) $x['rowId']] = (string) $k;
            }
        }
        $keys = [];
        foreach ([$T, $B, $C, $P] as $h) {
            foreach (array_merge(array_keys($h['records']), array_keys($h['skips'])) as $k) {
                $keys[(string) $k] = true;
            }
        }
        ksort($keys);
        $exceptions = [];
        $skips = [];
        foreach (array_keys($keys) as $key) {
            $key = (string) $key;
            $x = $target['exceptions'][$key] ?? null;
            $byItsRow = $x !== null && isset($byRow[(int) ($x['rowId'] ?? 0)]);
            $wasKey = $byItsRow ? $byRow[(int) $x['rowId']] : $key;
            $p = self::dayIn($P, $key);
            $q = self::dayIn($Q, $key);
            $t = self::dayIn($T, $key);
            $c = self::dayIn($C, $key);
            // The day before: the row's own, or the day at the key while
            // that key names the same instance (the form Google holds the
            // series in now is the form it was found in). Otherwise the
            // instance at this key came with the master write, and its
            // before is what that write left there.
            $known = $byItsRow || $sameForm;
            $bx = $known ? ($before['exceptions'][$wasKey] ?? null) : null;
            $bOwn = $known ? self::dayIn($B, $wasKey) : $p;
            $bHere = $sameForm ? ($wasKey === $key ? $bOwn : self::dayIn($B, $key)) : $p;
            // A day the change leaves as it found it (its own part the same
            // on both sides) that Google put back to the series since: no
            // record now where the change's own write would have left one.
            // It follows the series, as a day the change has no row for.
            $follows = $x !== null && $wasKey === $key && is_array($bOwn) && is_array($t) && is_array($c)
                && isset($B['records'][$key], $P['records'][$key]) && !isset($C['records'][$key])
                && self::ownPart($bOwn, $bx) === self::ownPart($t, $x);
            if ($follows) {
                $x = null;
            }
            // What the change wants of the day over the series as it will be: its own fields, the rest the series'.
            $want = self::plainAt($master, $key, $len);
            foreach (OwnFields::normalize((array) ($x['own'] ?? [])) as $field) {
                foreach (self::GROUPS[self::OWN_GROUP[$field] ?? ''] ?? [] as $col) {
                    $want[$col] = $x[$col] ?? null;
                }
            }
            $nowRow = $C['records'][$key] ?? self::plainAt($C['master'], $key, self::lengthOf($C['master']));
            $fromNow = []; // the groups of the day taken from what Google holds now, not from the change
            if ($c === 'skip' || $t === 'skip' || $bOwn === 'skip') {
                // A day skipped on one side: the day as a whole.
                $wantsChange = self::ownPart($bOwn, $bx) !== self::ownPart($t, $x);
                $how = self::threeWay($c, [$bOwn, $bHere, $p, $q, $t], $wantsChange, false);
                if ($how === 'conflict' && $force) {
                    $conflicts[] = [$key, 'day'];
                    $overWords[] = $dayWords($key) . " (changed at Google $since).";
                    $how = 'change';
                }
                if ($how !== 'change' && $bOwn === 'skip' && $p !== 'skip' && $q !== 'skip' && $c !== 'skip') {
                    // A skipped day the change's own master write brought
                    // back (a status sent): its record returns with fields
                    // no read shows, so a live day there is that write's.
                    $how = 'change';
                }
                $result = $how === 'change' ? ($t === 'skip' ? 'skip' : $want) : ($c === 'skip' ? 'skip' : $nowRow);
                if ($how !== 'change') {
                    $fromNow = array_keys(self::GROUPS);
                }
                if ($how === 'conflict') {
                    $conflicts[] = [$key, 'day'];
                    $words[] = $c === 'skip'
                        ? $dayWords($key) . " was deleted at Google $since; it stays deleted."
                        : $dayWords($key) . " was changed at Google $since; left as Google has it.";
                }
            } elseif ($follows) {
                $result = $want;
            } else {
                $result = $want;
                $left = [];
                $forced = [];
                foreach (self::GROUPS as $g => $cols) {
                    // A skip at the destination (the day the edited day moves onto) is that day's value, explaining no field.
                    $how = self::threeWay($c[$g], [$bOwn[$g], is_array($bHere) ? $bHere[$g] : null, is_array($p) ? $p[$g] : null, is_array($q) ? $q[$g] : null, $t[$g]], $bOwn[$g], $t[$g]);
                    if ($how === 'conflict' && $force) {
                        $conflicts[] = [$key, $g];
                        $forced[] = self::GROUP_WORDS[$g];
                        continue;
                    }
                    if ($how === 'change') {
                        continue;
                    }
                    foreach ($cols as $col) {
                        $result[$col] = $nowRow[$col] ?? null;
                    }
                    $fromNow[] = $g;
                    if ($how === 'conflict') {
                        $left[] = self::GROUP_WORDS[$g];
                        $conflicts[] = [$key, $g];
                    }
                }
                if ($left !== []) {
                    $words[] = "'" . trim((string) ($nowRow['title'] ?? '')) . "' on " . $dayWords($key) . " changed at Google $since (its " . implode(', ', $left) . '); left as Google has it.';
                }
                if ($forced !== []) {
                    $overWords[] = "'" . trim((string) ($nowRow['title'] ?? '')) . "' on " . $dayWords($key) . " (its " . implode(', ', $forced) . ", changed at Google $since).";
                }
            }
            if ($result === 'skip') {
                $skips[] = $key;
                continue;
            }
            $row = self::cols($result);
            if ($x === null && self::isPlain($row, $master, $key)) {
                continue; // an ordinary day; a target's edited day stays one (its row stands on its record)
            }
            // Its own list: the change's for a day the change has, with a
            // field of its own added only where Google changed that group
            // since (taken from now above); a day the change has no row for
            // holds what Google holds of its own.
            $inferred = OwnFields::infer($row + ['recurrence_instance_utc' => $key], $master);
            if ($x !== null) {
                $inferred = array_values(array_filter($inferred, static fn(string $f): bool => in_array(self::OWN_GROUP[$f] ?? null, $fromNow, true)));
            }
            $own = OwnFields::normalize(array_merge((array) ($x['own'] ?? []), $inferred));
            $exceptions[$key] = $row + ['own' => $own, 'rowId' => $x['rowId'] ?? null];
        }
        sort($skips);
        return [array_replace($target, ['master' => $master, 'exceptions' => $exceptions, 'skips' => $skips]), $words, $conflicts, $overWords];
    }

    /**
     * The inverse of what a series change asked, as the planner's input
     * (Series\Model::setPlan): each group of the series' own fields the
     * change changed (its times, its rule, its title and the others), from
     * what it asked ($asked) back to what it found ($before), read against
     * the series as stored now ($now, stateOf's shape), set back to the
     * value it had; the planner carries every edited day and skip to its
     * place, as a forward times, zone or count change carries them. A
     * group that holds otherwise now than the change asked was changed
     * while the change ran (a later change of it made the entry stale,
     * Undo::staleFor): kept as it stands and named, or, forced, put back
     * over and named. Pure.
     *
     * @return array{0:array,1:list<string>,2:list<string>} the master's columns to set, words for what was kept, words for what was put back over
     */
    public static function inverseEdit(array $before, array $asked, array $now, bool $force = false): array
    {
        $b = self::groupsOf($before['master'], true);
        $a = self::groupsOf($asked['master'], true);
        $c = self::groupsOf($now['master'], true);
        $set = [];
        $kept = [];
        $over = [];
        foreach ($b as $g => $value) {
            if ($value === ($a[$g] ?? null)) {
                continue;
            }
            if (($c[$g] ?? null) !== ($a[$g] ?? null)) {
                if (!$force) {
                    $kept[] = self::GROUP_WORDS[$g];
                    continue;
                }
                $over[] = self::GROUP_WORDS[$g];
            }
            foreach ($g === 'rule' ? ['rrule'] : self::GROUPS[$g] as $col) {
                $set[$col] = $col === 'rrule' && (string) ($before['master']['rrule'] ?? '') === '' ? null : ($before['master'][$col] ?? null);
            }
        }
        return [$set,
            $kept === [] ? [] : ["The series' " . implode(', ', $kept) . " changed at Google since this change; left as Google has it."],
            $over === [] ? [] : ["The series' " . implode(', ', $over) . " (changed at Google since this change)."]];
    }

    /**
     * The days a series change changed beyond carrying them, put back as
     * the rest of its inverse (inverseEdit is the series' part): a day it
     * skipped or brought back, or a field of its own it gave, took or
     * changed (a Google-held group of it, or a field held here alone), on
     * a day it kept or one it dropped with a shorter count or a split.
     * A day is the same day through every state by what carried it, never
     * by its place in a count: from the series as the change found it to
     * the series as it left it by the change's own moves ($moved, the
     * entry's MOVE grounds: from => to), and on to the series as it is now
     * and to $target (the planned undo of it) by $follow, which answers,
     * for the day at its found and left keys, its key now and in $target
     * (null where that series has no such day). Each part goes back to
     * what it was, over the day as the planner leaves it in $target; every
     * other part of every day is the planner's. A part that holds otherwise
     * now than the change left it is kept and named, or, forced, put back
     * over and named. Pure.
     *
     * @param array<string,string> $moved
     * @param \Closure(?string,?string):array{0:?string,1:?string} $follow
     * @return array{0:array,1:list<string>,2:list<string>} the target, words for what was kept, words for what was put back over
     */
    public static function withDayDeltas(array $target, array $before, array $asked, array $now, int $thisYear, bool $force, array $moved, \Closure $follow): array
    {
        $daysOf = static fn(array $s): array => array_map('strval', array_merge(array_keys($s['exceptions']), $s['skips']));
        // Each day the change found or left, by its key on both sides.
        $back = array_flip($moved);
        $days = [];
        foreach ($daysOf($before) as $kb) {
            $days[$kb] = [$kb, $moved[$kb] ?? $kb];
        }
        foreach ($daysOf($asked) as $ka) {
            $kb = $back[$ka] ?? $ka;
            $days[$kb] = [$kb, $ka];
        }
        ksort($days);
        // A day of a state: whether it is there, skipped, and its parts:
        // each Google-held group of its own with its values, each field
        // it holds here alone (OwnFields::HELD_HERE) as owned.
        $dayOf = static function (array $s, ?string $key): array {
            $x = $key === null ? null : ($s['exceptions'][$key] ?? null);
            $parts = [];
            $own = (array) ($x['own'] ?? []);
            foreach (OwnFields::googleHeld($own) as $field) {
                $g = self::OWN_GROUP[$field] ?? null;
                if ($g !== null) {
                    $parts[$g] = array_map(static fn(string $col): mixed => $x[$col] ?? null, self::GROUPS[$g]);
                }
            }
            foreach (array_intersect($own, OwnFields::HELD_HERE) as $field) {
                $parts['held:' . $field] = true;
            }
            return ['there' => $key !== null, 'skip' => $key !== null && in_array($key, $s['skips'], true), 'parts' => $parts, 'own' => $own];
        };
        $kept = [];
        $over = [];
        $len = self::lengthOf($target['master']);
        foreach ($days as [$kb, $ka]) {
            [$kc, $kt] = $follow($kb, $ka);
            [$b, $a, $c] = [$dayOf($before, $kb), $dayOf($asked, $ka), $dayOf($now, $kc)];
            if ($kt === null) {
                continue;
            }
            $at = Words::date(Cal::dnOf(Time::fromDb($kt)->setTimezone(Time::zone((int) $target['master']['all_day'] === 1 ? 'UTC' : (string) $target['master']['tzid']))), $thisYear);
            // Changed since the change left it: kept and named, or forced over and named.
            $since = static function (bool $changed, string $what) use (&$kept, &$over, $force, $at): bool {
                if (!$changed) {
                    return true;
                }
                if ($force) {
                    $over[] = "$at's $what (changed at Google since this change).";
                    return true;
                }
                $kept[] = "$at's $what changed at Google since this change; left as Google has it.";
                return false;
            };
            $x = $target['exceptions'][$kt] ?? null;
            $own = (array) ($x['own'] ?? []);
            $day = $x === null ? self::cols(self::plainAt($target['master'], $kt, $len)) : array_diff_key($x, ['own' => 1, 'rowId' => 1]);
            $plain = self::cols(self::plainAt($target['master'], $kt, $len));
            $touched = false;
            foreach (array_unique(array_merge(array_keys($b['parts']), array_keys($a['parts']))) as $part) {
                if (($b['parts'][$part] ?? null) === ($a['parts'][$part] ?? null)) {
                    continue;
                }
                $held = str_starts_with($part, 'held:');
                if (!$since($c['there'] && ($c['parts'][$part] ?? null) !== ($a['parts'][$part] ?? null), $held ? substr($part, 5) : self::GROUP_WORDS[$part])) {
                    continue;
                }
                $fields = $held ? [substr($part, 5)] : array_keys(array_filter(self::OWN_GROUP, static fn(string $og): bool => $og === $part));
                $own = array_values(array_diff($own, $fields));
                if (isset($b['parts'][$part])) {
                    $own = array_merge($own, array_intersect($b['own'], $fields));
                }
                foreach ($held ? [] : self::GROUPS[$part] as $j => $col) {
                    $day[$col] = isset($b['parts'][$part]) ? $b['parts'][$part][$j] : $plain[$col];
                }
                $touched = true;
            }
            if ($b['skip'] !== $a['skip'] && $since($c['there'] && $c['skip'] !== $a['skip'], 'skip')) {
                $target['skips'] = array_values(array_diff($target['skips'], [$kt]));
                if ($b['skip']) {
                    $target['skips'][] = $kt;
                    unset($target['exceptions'][$kt]);
                    continue;
                }
            }
            if (!$touched) {
                continue;
            }
            $own = OwnFields::normalize($own);
            if (OwnFields::googleHeld($own) === []) {
                unset($target['exceptions'][$kt]);
                continue;
            }
            $target['exceptions'][$kt] = self::cols($day) + ['own' => $own, 'rowId' => $x['rowId'] ?? ($before['exceptions'][$kb]['rowId'] ?? null)];
        }
        sort($target['skips']);
        ksort($target['exceptions']);
        return [$target, $kept, $over];
    }

    /** A day's own part, for "does the change change this day": skipped, or its own fields' values (none for an ordinary day). */
    private static function ownPart(string|array $day, ?array $x): mixed
    {
        if ($day === 'skip') {
            return 'skip';
        }
        $out = [];
        foreach (OwnFields::normalize((array) ($x['own'] ?? [])) as $field) {
            $g = self::OWN_GROUP[$field] ?? null;
            if ($g !== null) {
                $out[$g] = $day[$g];
            }
        }
        ksort($out);
        return $out;
    }

    // ---- local ---------------------------------------------------------------

    /**
     * What Google holds for these series, stored here through the poll's
     * path (Feeds::sync, every other series of the calendar left alone),
     * then each exception's own list from the targets (Google holds no
     * intent, so the list is the plan's, never a guess from equal values).
     *
     * A store a pending change runs ($pendingId: its failure's, Finish's,
     * Put back's) records the rows it made in the pending row's journal
     * (made_json), so Finish removes exactly those (rewind).
     *
     * @param array<string,list<array>> $readbacks iCalendar UID => the series' resources
     * @param list<array> $targets
     */
    public function store(array $calendar, array $readbacks, array $targets, ?int $pendingId = null): void
    {
        $uids = array_fill_keys(array_map('strval', array_keys($readbacks)), true);
        $items = [];
        foreach ($readbacks as $list) {
            foreach ($list as $item) {
                $items[] = $item;
            }
        }
        $rows = $this->db->all('SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [(int) $calendar['id']]);
        $others = array_values(array_filter($rows, static fn(array $r): bool => !isset($uids[(string) $r['uid']])));
        // What each day of these series holds here alone, as the rows stand
        // before the store: its local part of the own list, kept through it,
        // found by where the target's series has the day (whereNow).
        $local = [];
        $mastersHere = [];
        foreach ($rows as $r) {
            if ($r['recurrence_instance_utc'] === null) {
                $mastersHere[(int) $r['id']] = $r;
            }
        }
        $targetOf = [];
        foreach ($targets as $t) {
            if (!empty($t['uid'])) {
                $targetOf[(string) $t['uid']] = $t;
            }
        }
        foreach ($rows as $r) {
            if (isset($uids[(string) $r['uid']]) && $r['recurrence_instance_utc'] !== null) {
                $t = $targetOf[(string) $r['uid']] ?? null;
                $key = $t === null ? (string) $r['recurrence_instance_utc'] : self::whereNow($t, (string) $r['recurrence_instance_utc'], $mastersHere[(int) ($r['recurrence_parent_id'] ?? 0)] ?? null);
                $at = (string) $r['uid'] . '|' . $key;
                $local[$at] = array_values(array_unique(array_merge($local[$at] ?? [], Feeds::heldHere(OwnFields::stored($r), $r))));
            }
        }
        $had = [];
        foreach ($rows as $r) {
            $had[(int) $r['id']] = true;
        }
        $snapshot = GoogleSync::applyChanges(GoogleSync::rowsToParsed($others), GoogleSync::toParsedList($items));
        $this->feeds->sync($calendar, $snapshot, journal: false);
        if ($pendingId !== null && $uids !== []) {
            [$in, $params] = Db::in(array_keys($uids));
            $made = [];
            foreach ($this->db->all("SELECT id FROM events WHERE calendar_id = ? AND uid IN $in AND deleted_at IS NULL", array_merge([(int) $calendar['id']], $params)) as $r) {
                if (!isset($had[(int) $r['id']])) {
                    $made[] = (int) $r['id'];
                }
            }
            $this->recordMade($pendingId, $made);
        }
        foreach ($targets as $t) {
            if (empty($t['uid'])) {
                continue;
            }
            foreach ($t['exceptions'] as $key => $x) {
                // The day's own list: the target's (the change's, and what
                // Google changed since), with what it held here alone kept,
                // a place by its coordinates only while it shows the text
                // they were picked for (Feeds::heldHere, on the row as stored;
                // read above where the target's series has its day, whereNow).
                $own = (array) ($x['own'] ?? []);
                $held = $local[(string) $t['uid'] . '|' . (string) $key] ?? [];
                if ($held !== []) {
                    $now = $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [(int) $calendar['id'], (string) $t['uid'], (string) $key]);
                    $own = array_merge($own, $now === null ? [] : Feeds::heldHere($held, $now));
                }
                $this->db->run(
                    'UPDATE events SET override_fields = ? WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL',
                    [OwnFields::encode(OwnFields::normalize($own)), (int) $calendar['id'], (string) $t['uid'], (string) $key]
                );
            }
            // The lists as the store leaves them: each day not owning its
            // tags or people holds the series' (OwnFields::follow).
            $masterId = $this->db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [(int) $calendar['id'], (string) $t['uid']]);
            if ($masterId !== null) {
                OwnFields::follow($this->db, (int) $masterId);
            }
        }
    }

    /**
     * Rows a store made for a pending change, added to its journal on the
     * journal's connection (committed whatever the account's transaction
     * does after; an id whose row was rolled back names nothing).
     *
     * @param list<int> $ids
     */
    private function recordMade(int $pendingId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->journaled(function (Db $j) use ($pendingId, $ids): void {
            $all = array_map('intval', json_decode((string) $j->scalar('SELECT made_json FROM google_pending WHERE id = ?', [$pendingId]), true) ?: []);
            $all = array_values(array_unique(array_merge($all, $ids)));
            sort($all);
            $j->update('google_pending', ['made_json' => json_encode($all)], 'id = ?', [$pendingId]);
        });
    }

    /** The rows a pending change's own stores made here, as its journal holds them now. @return array<int,true> */
    private function madeOf(int $pendingId): array
    {
        $json = $this->journaled(fn(Db $j): mixed => $j->scalar('SELECT made_json FROM google_pending WHERE id = ?', [$pendingId]));
        return array_fill_keys(array_map('intval', json_decode((string) $json, true) ?: []), true);
    }

    /**
     * The events of their own a plan made (days kept outside the series,
     * inserted at Google under chosen ids): their local rows, written by
     * the plan's writer as local events, take Google's identity so the
     * sync matches them.
     *
     * @param array<string,array> $inserted chosen id => Google's resource
     * @param list<int> $created the rows the writer created, in the plan's order
     */
    public function adoptSingles(array $target, array $inserted, array $created): void
    {
        foreach ($target['singles'] ?? [] as $s) {
            $resource = $inserted[(string) $s['id']] ?? null;
            $rowId = $s['rowId'] ?? (isset($s['createIndex']) ? ($created[(int) $s['createIndex']] ?? null) : null);
            if ($resource === null || $rowId === null) {
                continue;
            }
            $this->db->update('events', [
                'uid' => (string) ($resource['iCalUID'] ?? $s['id']),
                'google_event_id' => (string) $s['id'],
                'google_etag' => isset($resource['etag']) && is_string($resource['etag']) ? $resource['etag'] : null,
                'source' => 'feed',
            ], 'id = ?', [(int) $rowId]);
        }
    }

    /**
     * The change is saved here: its pending row goes once the transaction
     * saving it commits (a rollback leaves it half done, for Review), or now
     * outside one.
     */
    public function settle(int $pendingId): void
    {
        $this->settled[$pendingId] = true;
        $this->endUnlessTx($pendingId, true);
    }

    // ---- recovery -----------------------------------------------------------

    /**
     * The half-done changes of an account, newest first: those a run left
     * half done, and those still marked running whose run holds no lock
     * (its process stopped), read on the journal's connection (what the
     * transaction here began before can't hide them).
     *
     * @return list<array>
     */
    public static function halfDoneOf(Db $db, int $userId): array
    {
        $out = [];
        $rows = RequestAuthority::asServer(fn(): array => $db->journal()->all(
            'SELECT p.*, c.name AS calendar_name, c.provider AS cal_provider, c.google_calendar_id AS cal_google_calendar_id, c.google_account_id AS cal_google_account_id, c.google_access_role AS cal_google_access_role
             FROM google_pending p JOIN calendars c ON c.id = p.calendar_id WHERE p.user_id = ? AND p.status IN (?, ?) ORDER BY p.id DESC',
            [$userId, self::STATUS_HALF, self::STATUS_RUNNING]
        ));
        foreach ($rows as $row) {
            if ((string) $row['status'] === self::STATUS_RUNNING && !self::stopped($db, (int) $row['id'])) {
                continue;
            }
            $steps = json_decode((string) $row['steps_json'], true) ?: [];
            // A pending undo or redo whose entry Finish's loader refuses (an older format): Review says
            // so up front and offers Put back alone, never an action that will refuse.
            $plan = json_decode((string) ($row['plan_json'] ?? ''), true);
            $finishRefused = null;
            if (is_array($plan) && isset($plan['undo'])) {
                try {
                    GoogleUndo::entryToFinish($db, $userId, $plan);
                } catch (HttpError $e) {
                    if ($e->errorCode !== 'not_undoable') {
                        throw $e;
                    }
                    $finishRefused = $e->getMessage();
                }
            }
            $out[] = [
                'id' => (int) $row['id'],
                'calendarId' => (int) $row['calendar_id'],
                'calendarName' => (string) $row['calendar_name'],
                'eventId' => $row['event_id'] !== null ? (int) $row['event_id'] : null,
                'title' => (string) $row['title'],
                'summary' => (string) $row['summary'],
                'error' => (string) (($row['error'] ?? '') !== '' ? $row['error'] : self::STOPPED_WORDS),
                'stepsDone' => count($steps),
                'createdAt' => Time::dbToIso((string) $row['created_at'], 'UTC'),
                'finishRefused' => $finishRefused,
                // The calendar no longer writes to Google: neither Finish nor Put back can run, and why.
                'unrecoverable' => self::unrecoverable(['provider' => $row['cal_provider'], 'google_calendar_id' => $row['cal_google_calendar_id'],
                    'google_account_id' => $row['cal_google_account_id'], 'google_access_role' => $row['cal_google_access_role']]),
            ];
        }
        return $out;
    }

    /** The half-done changes of an account, newest first (halfDoneOf). @return list<array> */
    public function halfDone(int $userId): array
    {
        return self::halfDoneOf($this->db, $userId);
    }

    // ---- a series with changes not yet at Google ------------------------------

    /**
     * The series of a calendar with changes not yet at Google, by name:
     * each uid and Google id a pending row's change names (its series
     * before and after, and the days it keeps outside them), and the uid
     * of any row here Google knows by one of those ids, each mapped to that
     * row ({id, title}). A pending row counts while it is half done or
     * running, except a run of this process (its own change, under way).
     * Today such a change is one a failure left half done; later, one
     * queued while offline. Read on the journal's connection, so what the
     * transaction here began before can't hide it.
     *
     * The one answer to "does this series have changes not yet at Google",
     * asked by every write, undo and redo before it reads or sends anything
     * (refusePending), and by a poll before it stores a series (GoogleSync).
     *
     * @return array<string,array{id:int,title:string}>
     */
    public static function pendingNames(Db $db, int $userId, int $calendarId): array
    {
        $rows = RequestAuthority::asServer(fn(): array => $db->journal()->all(
            'SELECT id, title, targets_json, befores_json FROM google_pending WHERE user_id = ? AND calendar_id = ? AND status IN (?, ?) ORDER BY id DESC',
            [$userId, $calendarId, self::STATUS_HALF, self::STATUS_RUNNING]
        ));
        $out = [];
        $googleIds = [];
        foreach ($rows as $row) {
            if (isset(self::$ours[(int) $row['id']])) {
                continue;
            }
            $which = ['id' => (int) $row['id'], 'title' => (string) $row['title']];
            $series = array_merge(json_decode((string) $row['targets_json'], true) ?: [], json_decode((string) ($row['befores_json'] ?? ''), true) ?: []);
            foreach ($series as $t) {
                $names = [$t['uid'] ?? null, $t['googleId'] ?? null];
                foreach ($t['singles'] ?? [] as $single) {
                    array_push($names, $single['uid'] ?? null, $single['id'] ?? null);
                }
                foreach ($names as $name) {
                    if (is_string($name) && $name !== '') {
                        $out[$name] ??= $which;
                    }
                }
                foreach ([$t['googleId'] ?? null, ...array_column($t['singles'] ?? [], 'id')] as $gid) {
                    if (is_string($gid) && $gid !== '') {
                        $googleIds[$gid] = $which;
                    }
                }
            }
        }
        if ($googleIds !== []) {
            // A series Google knows by an id the change chose (an insert whose answer was lost names no uid).
            [$in, $params] = Db::in(array_keys($googleIds));
            foreach ($db->all("SELECT uid, google_event_id FROM events WHERE calendar_id = ? AND google_event_id IN $in", array_merge([$calendarId], $params)) as $r) {
                $out[(string) $r['uid']] ??= $googleIds[(string) $r['google_event_id']];
            }
        }
        return $out;
    }

    /** The names a row goes by for pendingNames: its uid (its series' too, for an edited day) and its Google id. @return list<string> */
    public static function namesOf(array $row): array
    {
        return array_values(array_filter([(string) ($row['uid'] ?? ''), (string) ($row['google_event_id'] ?? '')], static fn(string $n): bool => $n !== ''));
    }

    /**
     * A write, undo or redo touching a series with changes not yet at
     * Google (pendingNames) is refused, before it reads or sends anything:
     * 409 google_pending, naming the change (extra.pending) and pointing to
     * Review, where Finish and Put back are the way out.
     *
     * @param list<string> $names the series' names (namesOf, or a target's uid and googleId)
     */
    public static function refusePending(Db $db, int $userId, int $calendarId, array $names): void
    {
        $pending = self::pendingNames($db, $userId, $calendarId);
        if ($pending === []) {
            return;
        }
        foreach ($names as $name) {
            // A day's Google id (its series' id, then its original start: GoogleWriter::instanceId) names its series too.
            $series = GoogleWriter::instanceOf((string) $name)['series'] ?? null;
            $which = $pending[(string) $name] ?? ($series !== null ? $pending[$series] ?? null : null);
            if ($which !== null) {
                throw self::pendingRefusal($which);
            }
        }
    }

    /** The refusal of anything that waits on a half-done change: 409 google_pending, naming it and pointing to Review. @param array{id:int,title:string} $which */
    private static function pendingRefusal(array $which): HttpError
    {
        return new HttpError('google_pending',
            "A change to '" . $which['title'] . "' is not yet complete at Google. Finish it or put it back in Review, then try this again.", 409, ['pending' => $which['id']]);
    }

    /**
     * An operation on a whole calendar that would leave Finish and Put back
     * nothing to work on (the calendar kept as a calendar of its own, or
     * deleted here) is refused while any change on it is half done at
     * Google, as a write to its series is (refusePending). Read on the
     * journal's connection, like pendingNames.
     */
    public static function refusePendingOnCalendar(Db $db, int $userId, int $calendarId): void
    {
        $rows = RequestAuthority::asServer(fn(): array => $db->journal()->all(
            'SELECT id, title FROM google_pending WHERE user_id = ? AND calendar_id = ? AND status IN (?, ?) ORDER BY id DESC',
            [$userId, $calendarId, self::STATUS_HALF, self::STATUS_RUNNING]
        ));
        foreach ($rows as $row) {
            if (!isset(self::$ours[(int) $row['id']])) {
                throw self::pendingRefusal(['id' => (int) $row['id'], 'title' => (string) $row['title']]);
            }
        }
    }

    /** Why a half-done change was closed without finishing (closeNeverRunnable), said in its Activity line. */
    public const CLOSED_KEPT = 'this calendar is kept as a copy here now.';
    public const CLOSED_DELETED = 'its calendar was deleted here.';
    public const CLOSED_NOT_GOOGLE = 'this calendar no longer writes to Google from here.';

    /**
     * Close every change half done at Google on this calendar, which can
     * never run again (its calendar deleted here, or no longer a Google
     * calendar here): the pending row removed, nothing sent, the rows here
     * left as they are, and one log-only Activity line per change saying
     * what was closed and why. Inside the account's write, so the closing
     * commits with the change that made it true. A run of it still under
     * way in another process is refused (google_pending_running).
     */
    public static function closeNeverRunnable(Db $db, Undo $undo, int $userId, int $calendarId, string $why): int
    {
        $rows = RequestAuthority::asServer(fn(): array => $db->journal()->all(
            'SELECT id, title, status FROM google_pending WHERE user_id = ? AND calendar_id = ? ORDER BY id',
            [$userId, $calendarId]
        ));
        foreach ($rows as $row) {
            if ((string) $row['status'] === self::STATUS_RUNNING && !self::stopped($db, (int) $row['id']) && !isset(self::$ours[(int) $row['id']])) {
                throw HttpError::conflict('google_pending_running', 'This change is still under way.');
            }
        }
        foreach ($rows as $row) {
            $db->run('DELETE FROM google_pending WHERE id = ?', [(int) $row['id']]);
            $undo->record($userId, 'calendar', $calendarId, 'update', null, null,
                "A change to '" . (string) $row['title'] . "' that was half done at Google was closed without finishing: " . $why);
        }
        return count($rows);
    }

    /**
     * The same closing for the rows an earlier version left that can never
     * run: their calendar gone, or no longer a Google calendar (kept as a
     * copy while a change was half done). Run by the daily retention pass
     * (Activity::prune). Returns how many were closed.
     */
    public static function closeLeftBehind(Db $db): int
    {
        $rows = RequestAuthority::asServer(fn(): array => $db->journal()->all(
            "SELECT DISTINCT p.user_id, p.calendar_id, c.id AS present FROM google_pending p LEFT JOIN calendars c ON c.id = p.calendar_id
             WHERE c.id IS NULL OR c.provider <> 'google' OR c.google_calendar_id IS NULL ORDER BY p.user_id, p.calendar_id"
        ));
        $closed = 0;
        foreach ($rows as $r) {
            $undo = new Undo($db);
            $closed += (int) $undo->write((int) $r['user_id'], fn(): int => self::closeNeverRunnable($db, $undo, (int) $r['user_id'], (int) $r['calendar_id'],
                $r['present'] === null ? self::CLOSED_DELETED : self::CLOSED_NOT_GOOGLE));
        }
        return $closed;
    }

    /**
     * Why neither Finish nor Put back can run for a change on this calendar
     * now, and what would bring them back, or null when they can: they need
     * the calendar to write to Google (GoogleWriter::writable). The one
     * answer for Review's list and for the refusal of either.
     */
    public static function unrecoverable(?array $calendar): ?string
    {
        if ($calendar !== null && GoogleWriter::writable($calendar)) {
            return null;
        }
        if ($calendar === null || ($calendar['provider'] ?? '') !== 'google' || empty($calendar['google_calendar_id'])) {
            return 'This calendar no longer writes to Google from here, so Finish and Put back can no longer run for this change.';
        }
        if ($calendar['google_account_id'] === null) {
            return 'The Google account this calendar uses is disconnected. Connect it again in Settings, Connections and add this calendar there to re-attach it; then Finish and Put back are offered again.';
        }
        if (($calendar['google_access_role'] ?? null) === null) {
            return 'Google access for this calendar is paused. Reconnect the Google account in Settings, Connections; Finish and Put back are offered again after its next update.';
        }
        return 'This account can no longer edit this calendar at Google, so Finish and Put back can no longer run for this change.';
    }

    /** A pending row of the account, as the journal holds it now, or null. */
    public function pendingOf(int $userId, int $pendingId): ?array
    {
        return $this->journaled(fn(Db $j): ?array => $j->one('SELECT * FROM google_pending WHERE id = ? AND user_id = ?', [$pendingId, $userId]));
    }

    /** A row marked running whose run is no longer under way: no lock held on it (a process that stopped holds none). */
    private static function stopped(Db $db, int $pendingId): bool
    {
        return self::mysql($db) && (int) $db->journal()->scalar('SELECT IS_FREE_LOCK(?)', [self::lockName($db, $pendingId)]) === 1;
    }

    /** A half-done row, taken for a recovery run (its lock held, running again); a run under way is refused. */
    private function pendingRow(int $userId, int $pendingId): array
    {
        $row = $this->pendingOf($userId, $pendingId);
        if ($row === null) {
            throw HttpError::notFound('No such half-done Google change');
        }
        $half = (string) $row['status'] === self::STATUS_HALF || ((string) $row['status'] === self::STATUS_RUNNING && self::stopped($this->db, $pendingId));
        if (!$half || !$this->open($pendingId, false)) {
            throw HttpError::conflict('google_pending_running', 'This change is still under way.');
        }
        $this->journaled(fn(Db $j) => $j->update('google_pending', ['status' => self::STATUS_RUNNING, 'error' => null, 'updated_at' => Time::nowDb()], 'id = ?', [$pendingId]));
        return $row;
    }

    /**
     * Finish (rule A): the change's deltas applied over what Google and the
     * rows hold now. Each series is read back (and stored here, so its
     * first write is conditional on the etag Google has now), and its
     * target is rebased on that read (rebase): what the change changes is
     * set where Google's value is one the change explains, and every field
     * and day it did not change stays exactly as Google holds it; a day or
     * field changed since in what the change changes is left as it is and
     * named. Then the change's part here (its plan, kept with the pending
     * row) written by the writer the change uses, on the rows put back
     * where the snapshot has them (rewind), each of its fields held here
     * alone left out where it changed here since (named, as Google's are),
     * and what Google holds stored on top; the rows the change kept take
     * back their ids and what hung off them (reattach). A part here that
     * can't be written is reported, and the pending row stays for Review
     * with what Google holds stored.
     *
     * @param ?\Closure $commit fn(array $plan, array $out, array $local): list<string>, the change's writer; returns words for what it left as it is
     * @return array{readbacks:array<string,list<array>>, differences:list<string>, targets:list<array>}
     */
    public function finish(int $userId, array $calendar, int $pendingId, ?\Closure $beforeSend = null, ?\Closure $commit = null): array
    {
        // Finish of an undo or redo takes its entry back: the one loader is asked here, the lowest
        // public way to complete one, before the row is marked running or Google is asked, so no
        // caller can skip it (GoogleUndo::entryToFinish; Review's Finish asks it first for the entry).
        $plan = json_decode((string) ($this->pendingOf($userId, $pendingId)['plan_json'] ?? ''), true);
        if (is_array($plan) && isset($plan['undo'])) {
            GoogleUndo::entryToFinish($this->db, $userId, $plan);
        }
        $row = $this->pendingRow($userId, $pendingId);
        $steps = json_decode((string) $row['steps_json'], true) ?: [];
        $targets = json_decode((string) $row['targets_json'], true) ?: [];
        $befores = json_decode((string) $row['befores_json'], true) ?: [];
        $send = fn(string $method, string $path, ?array $body, ?string $ifMatch): array
            => $this->writer->send($calendar, $method, $path, $body, [], $ifMatch, $beforeSend);
        $this->feeds->takeDropped(); // what this recovery's stores remove is named (removedDays)
        $this->carried = [];
        $now = $this->refresh($calendar, $targets, $beforeSend, $pendingId);
        $targets = $this->withStoredIds($calendar, $targets);
        $year = (int) Time::nowUtc()->format('Y');
        $kept = [];
        foreach ($targets as $i => $t) {
            if (!empty($t['remove'])) {
                continue;
            }
            $before = $befores[$i] ?? null;
            $items = !empty($t['uid']) ? ($now[(string) $t['uid']] ?? null) : null;
            if (!empty($t['insert'])) {
                // An insert that landed (its answer lost): the change's before is the series as inserted.
                $found = $this->found($send, (string) $t['googleId']);
                $uid = is_array($found) && is_string($found['iCalUID'] ?? null) && $found['iCalUID'] !== '' ? $found['iCalUID'] : null;
                if ($uid === null) {
                    continue;
                }
                $items = $this->readBack($calendar, $send, $uid, (string) $t['googleId'], null, $beforeSend);
                $before = ['master' => $t['master'], 'exceptions' => [], 'skips' => []];
            }
            if ($items === null || !is_array($before) || empty($before['master'])) {
                continue;
            }
            [$rebased, $words] = self::rebase($before, $t, self::held($items, (string) $t['googleId']), $year);
            $targets[$i] = $rebased;
            $kept = array_merge($kept, $words);
        }
        $out = $this->run($calendar, $pendingId, $targets, $steps, $beforeSend);
        $local = json_decode((string) ($row['local_json'] ?? ''), true) ?: [];
        $plan = json_decode((string) ($row['plan_json'] ?? ''), true);
        $held = [];
        if (is_array($plan) && $commit !== null) {
            [$held, $words] = $this->commitLocal($calendar, $pendingId, $local, $plan, $out, $commit);
            $kept = array_merge($kept, $words);
        }
        $this->store($calendar, $out['readbacks'], $out['targets'], $pendingId);
        $this->reattach($calendar, $local, $out['targets']);
        $out['differences'] = array_merge($kept, $out['differences'], $this->carryHeld($calendar, $held, $out['targets']));
        $out = $this->withRemoved($out, $this->feeds->takeDropped());
        $this->settle($pendingId);
        return $out;
    }

    /**
     * A recovery's answer with the rows it removed while they held
     * something here alone (Feeds::droppedDay, decision A5): named in its
     * differences in words, and by row id and date in `removedDays`, which
     * its Activity entry carries. Decided once, at its end, against the
     * live rows: a row a store dropped that a later step of the same
     * recovery restored (reattach, bringBack, carryHeld: the same id) or
     * carried onto another row (relink, carryHeld: $this->carried) is not
     * removed.
     *
     * @param list<array> $removed
     */
    private function withRemoved(array $out, array $removed): array
    {
        $carried = $this->carried;
        $this->carried = [];
        $ids = [];
        foreach ($removed as $d) {
            $ids[] = (int) $d['id'];
            if (isset($carried[(int) $d['id']])) {
                $ids[] = $carried[(int) $d['id']];
            }
        }
        $live = [];
        if ($ids !== []) {
            [$in, $params] = Db::in(array_values(array_unique($ids)));
            foreach ($this->db->all("SELECT id FROM events WHERE id IN $in AND deleted_at IS NULL", $params) as $r) {
                $live[(int) $r['id']] = true;
            }
        }
        $removed = array_values(array_filter($removed, static fn(array $d): bool
            => !isset($live[(int) $d['id']]) && !isset($live[$carried[(int) $d['id']] ?? 0])));
        if ($removed === []) {
            return $out;
        }
        $out['differences'][] = 'Removed here: ' . Feeds::droppedWords($removed) . '.';
        $out['removedDays'] = $removed;
        return $out;
    }

    /**
     * The change's part here, once Google holds its targets: the rows put
     * back where they were (rewind), then the change's writer, all or
     * nothing: a failure rolls back the whole transaction it runs in (the
     * account's, inside one), never to a savepoint, and is thrown. The
     * pending row is marked half done with words for what stopped (the
     * journal), and what Google holds is stored once that transaction is
     * over, in one of its own (keepAfterTx).
     * Returns what rewind took off the rows it made that hold something
     * here alone (Feeds::retained), for carryHeld once Google's state is stored,
     * and the writer's words for what it left as it is.
     *
     * @return array{0:list<array>, 1:list<string>}
     */
    private function commitLocal(array $calendar, int $pendingId, array $local, array $plan, array $out, \Closure $commit): array
    {
        $made = $this->madeOf($pendingId);
        $held = [];
        $words = [];
        $write = function () use ($calendar, $local, $plan, $out, $commit, $made, &$held, &$words): void {
            [$kept, $held] = $this->rewind($calendar, $local, $out['targets'], $made);
            $words = (array) ($commit($plan, $out, $local) ?? []);
            $this->placeKept($kept);
        };
        try {
            $this->db->tx($write);
        } catch (\Throwable $e) {
            $words = 'Google has the change, but its part here (tags, people, reminders, flags) could not be saved: ' . rtrim($e->getMessage(), '.') . '.';
            $this->journaled(fn(Db $j) => $j->update('google_pending', ['status' => self::STATUS_HALF, 'error' => mb_substr($words, 0, 2000), 'updated_at' => Time::nowDb()], 'id = ?', [$pendingId]));
            $this->keepAfterTx($calendar, $pendingId, function () use ($calendar, $local, $out, $pendingId): void {
                $this->store($calendar, $out['readbacks'], $out['targets'], $pendingId);
                $this->reattach($calendar, $local, $out['targets']);
            });
            $this->endUnlessTx($pendingId, true);
            throw new HttpError('google_finish_local', $words, 409, ['pending' => $pendingId]);
        }
        return [$held, $words];
    }

    /**
     * The rows a change's series are made of put back where the snapshot
     * (localOf) has them, so the change's writer finds what it was planned
     * on: each row of those series a store of this change made ($made, from
     * its journal: an edited day at a new key, the inserted series, an
     * event of its own) goes, each snapshot row takes back its place and
     * Google's columns (SYNCED), and a row gone since comes back whole with
     * what hung off it. What a live row has of its own (reminders, flags,
     * tags, people) stays as it is now. Recovery removes only what it made:
     * any other row of those series the snapshot lacks is a change made
     * here since (a tag on an ordinary day, which makes its row), kept as
     * it stands and returned, for the plan to be applied over it (follow).
     * A row a store made that has since been given something held here
     * alone (Feeds::retained: a tag, a person, a reminder, an answer, an icon, a
     * trip link) is no longer recovery's alone: where a target names a day
     * at its key, what it holds is taken (returned, for carryHeld to put on
     * the row the change leaves there) and the row goes; otherwise it is
     * kept like any later local change.
     *
     * @param array<string,list<array>> $local
     * @param list<array> $targets
     * @param array<int,true> $made
     * @return array{0:list<int>, 1:list<array>} the rows kept that the snapshot lacks, and what was taken
     */
    private function rewind(array $calendar, array $local, array $targets, array $made): array
    {
        $snap = [];
        foreach ($local['events'] ?? [] as $r) {
            $snap[(int) $r['id']] = $r;
        }
        [$kept, $taken] = $this->dropMade($calendar, $local, $targets, $made);
        $live = [];
        if ($snap !== []) {
            [$in, $params] = Db::in(array_keys($snap));
            foreach ($this->db->all("SELECT id FROM events WHERE id IN $in AND deleted_at IS NULL", $params) as $r) {
                $live[(int) $r['id']] = true;
            }
        }
        // A key may be one another of these rows holds now.
        Events::parkInstanceKeys($this->db, array_keys($live));
        $gone = [];
        foreach ($snap as $id => $r) {
            if (isset($live[$id])) {
                $this->db->update('events', array_intersect_key($r, array_flip(self::SYNCED)), 'id = ?', [$id]);
            } else {
                $gone[] = $r;
            }
        }
        $this->bringBack($calendar, $gone, $local);
        return [$kept, $taken];
    }

    /**
     * The rows a store of the change made ($made, the pending row's
     * `made_json`) that the snapshot (localOf) lacks, among the rows of the
     * series and events of their own the snapshot and the targets name,
     * go: Recovery removes only what it made. Any other row the snapshot
     * lacks is a change made here since, kept and returned. A made row
     * given something held here alone since (Feeds::retained) is no longer
     * recovery's alone: where a target names a day at its key, what it
     * holds is taken (returned, for carryHeld) and the row goes; otherwise
     * it is kept. Finish's rewind and Put back read the list alike.
     *
     * @param array<string,list<array>> $local
     * @param list<array> $targets
     * @param array<int,true> $made
     * @return array{0:list<int>, 1:list<array>} the rows kept that the snapshot lacks, and what was taken
     */
    private function dropMade(array $calendar, array $local, array $targets, array $made): array
    {
        $calendarId = (int) $calendar['id'];
        $snap = [];
        foreach ($local['events'] ?? [] as $r) {
            $snap[(int) $r['id']] = $r;
        }
        $uids = [];
        $googleIds = [];
        foreach ($snap as $r) {
            if ((int) $r['calendar_id'] === $calendarId) {
                $uids[] = (string) $r['uid'];
            }
        }
        foreach ($targets as $t) {
            if (!empty($t['uid'])) {
                $uids[] = (string) $t['uid'];
            }
            if (!empty($t['googleId'])) {
                $googleIds[] = (string) $t['googleId'];
            }
            foreach ($t['singles'] ?? [] as $single) {
                $googleIds[] = (string) $single['id'];
                if (!empty($single['uid'])) {
                    $uids[] = (string) $single['uid'];
                }
            }
        }
        [$inUids, $uidParams] = Db::in(array_values(array_unique($uids)));
        [$inIds, $idParams] = Db::in(array_values(array_unique($googleIds)));
        $kept = [];
        $taken = [];
        foreach ($this->db->all("SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL AND (uid IN $inUids OR google_event_id IN $inIds)", array_merge([$calendarId], $uidParams, $idParams)) as $r) {
            $id = (int) $r['id'];
            if (isset($snap[$id])) {
                continue;
            }
            if (!isset($made[$id])) {
                $kept[$id] = $this->seriesOf($r);
                continue;
            }
            $held = Feeds::retained($this->db, $r);
            if ($held !== null && !$this->namesDay($targets, $r)) {
                $kept[$id] = $this->seriesOf($r);
                continue;
            }
            if ($held !== null) {
                $taken[] = $this->taken($r, $held);
            }
            $this->db->run('DELETE FROM events WHERE id = ?', [$id]);
        }
        return [$kept, $taken];
    }

    /** Whether a target has an edited day of its own on $row's day, the day found where it is now (exceptionOn). */
    private function namesDay(array $targets, array $row): bool
    {
        $series = $this->seriesOf($row);
        foreach ($targets as $t) {
            if ((string) ($t['uid'] ?? '') === (string) $row['uid'] && self::exceptionOn($t, (string) $row['recurrence_instance_utc'], $series) !== null) {
                return true;
            }
        }
        return false;
    }

    /** The series' row a day's row stands under here now (its master), or null. */
    private function seriesOf(array $row): ?array
    {
        return ($row['recurrence_parent_id'] ?? null) === null ? null
            : $this->db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [(int) $row['recurrence_parent_id']]);
    }

    /**
     * Where a day is in a target's series: the key the target's series
     * gives the day $key showed under the series it was read under ($was;
     * null, the target's own), by the one day identity the sync follows
     * (Feeds::ofSeries, skips aside: a day the target skips is still that
     * day). Recovery finds a day's row by where it is now, never by the key
     * a snapshot or an earlier store gave it, so a Finish whose rebase moved
     * the series' clock or zone matches the same days. The key itself where
     * the series can't be read or has no such day. Pure.
     */
    private static function whereNow(array $target, string $key, ?array $was): string
    {
        if (empty($target['master']) || empty($target['master']['rrule'])) {
            return $key;
        }
        $at = Feeds::ofSeries(['recurrence_instance_utc' => $key], $was === null ? null : ['exdates_json' => null] + $was, ['exdates_json' => null] + $target['master']);
        return (string) ($at['recurrence_instance_utc'] ?? $key);
    }

    /**
     * The key of the target's edited day on the same day as $key (read
     * under $was), both found where the target's series has them now
     * (whereNow): a target's days may be keyed as the change planned them
     * while its master is Google's (a Finish whose rebase kept a clock
     * changed at Google). Null when it names none. Pure.
     */
    private static function exceptionOn(array $target, string $key, ?array $was): ?string
    {
        $day = self::whereNow($target, $key, $was);
        foreach (array_keys($target['exceptions'] ?? []) as $x) {
            if (self::whereNow($target, (string) $x, null) === $day) {
                return (string) $x;
            }
        }
        return null;
    }

    /**
     * Rows of the snapshot (localOf) brought back whole where nothing stands
     * in their place, with what hung off them there; a day's series
     * columns and its master are its series' now (rule C: the master by
     * the snapshot's id, else the series' master here now by its uid). A
     * link whose other end is gone since is left out.
     *
     * @param list<array> $rows
     * @param array<string,list<array>> $local
     */
    private function bringBack(array $calendar, array $rows, array $local): void
    {
        $gone = [];
        foreach ($rows as $r) {
            if ($r['recurrence_parent_id'] !== null) {
                $master = $this->db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [(int) $r['recurrence_parent_id']])
                    ?? $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [(int) $calendar['id'], (string) $r['uid']]);
                if ($master !== null) {
                    $r['recurrence_parent_id'] = (int) $master['id'];
                    foreach (array_keys(\BetterCal\Domain\Series\Model::SERIES_COLUMNS) as $col) {
                        if (array_key_exists($col, $master)) {
                            $r[$col] = $master[$col];
                        }
                    }
                }
            }
            $this->db->upsert('events', $r);
            $gone[(int) $r['id']] = true;
        }
        if ($gone === []) {
            return;
        }
        $eventCols = ['event_tags' => ['event_id'], 'event_people' => ['event_id']];
        foreach (OwnFields::REFERENCES as [$table, , $cols]) {
            $eventCols[$table] = $cols;
        }
        foreach ($eventCols as $table => $cols) {
            foreach ($local[$table] ?? [] as $link) {
                $names = false;
                foreach ($cols as $col) {
                    $names = $names || isset($gone[(int) ($link[$col] ?? 0)]);
                }
                if (!$names) {
                    continue;
                }
                try {
                    $this->db->upsert($table, $link);
                } catch (\PDOException $e) {
                    if (!Db::isForeignKeyViolation($e)) {
                        throw $e;
                    }
                }
            }
        }
        // A day brought back holds the tags and people it doesn't own as its
        // series has them now, never the snapshot's copy (OwnFields::follow).
        $this->followSeriesOf(array_keys($gone));
    }

    /**
     * The series of each of $ids (its master, or the one it is a day of),
     * each followed whole: every day of it holds the series' tags and people
     * where its list doesn't name its own (OwnFields::follow). What a
     * recovery step calls once it has written rows or links of a series.
     *
     * @param list<int> $ids
     */
    private function followSeriesOf(array $ids): void
    {
        $series = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            [$in, $params] = Db::in($chunk);
            foreach ($this->db->all("SELECT id, recurrence_parent_id FROM events WHERE id IN $in", $params) as $r) {
                $series[(int) ($r['recurrence_parent_id'] ?? $r['id'])] = true;
            }
        }
        foreach (array_keys($series) as $masterId) {
            OwnFields::follow($this->db, $masterId);
        }
    }

    /**
     * What recovery takes off a row a store made that keeps something
     * (Feeds::retained, the one retention function the sync uses too): the
     * row and its links as they are now, with the fields it keeps, for
     * carryHeld to put on the row left for that day.
     *
     * @param array{fields:list<string>, refs:list<string>} $kept
     */
    private function taken(array $row, array $kept): array
    {
        return ['row' => $row, 'series' => $this->seriesOf($row), 'fields' => $kept['fields'], 'links' => Undo::linksOf($this->db, [(int) $row['id']])];
    }

    /**
     * What rows a store made held here alone (Feeds::retained), put on the row the
     * recovery leaves for that day: the row a target names at its key
     * (the snapshot's row, by id, or the row at that key now). Its own
     * fields there are its own on that row (a tag list replaces the row's,
     * as a "this event" edit would), and what referred to it refers to that
     * row. A day with no row left for it is put back as it was and named:
     * never dropped without a word.
     *
     * @param list<array> $held
     * @param list<array> $targets
     * @return list<string> words for the days left as they were
     */
    private function carryHeld(array $calendar, array $held, array $targets): array
    {
        $words = [];
        $calendarId = (int) $calendar['id'];
        $live = fn(int $id): ?array => $this->db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [$id]);
        foreach ($held as $h) {
            $was = $h['row'];
            $wasId = (int) $was['id'];
            $key = (string) $was['recurrence_instance_utc'];
            $dest = null;
            foreach ($targets as $t) {
                if ((string) ($t['uid'] ?? '') !== (string) $was['uid']) {
                    continue;
                }
                // The day where the target's series has it now (whereNow, exceptionOn).
                $x = self::exceptionOn($t, (string) $was['recurrence_instance_utc'], $h['series'] ?? null);
                $key = self::whereNow($t, (string) $was['recurrence_instance_utc'], $h['series'] ?? null);
                $rid = $x === null ? 0 : (int) ($t['exceptions'][$x]['rowId'] ?? 0);
                if ($rid > 0 && $rid !== $wasId) {
                    $dest = $live($rid);
                }
            }
            $dest ??= $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc = ? AND id <> ? AND deleted_at IS NULL', [$calendarId, (string) $was['uid'], $key, $wasId]);
            if ($dest === null) {
                if ($live($wasId) === null) {
                    $this->db->upsert('events', $was);
                    $this->relinks($h['links'], $wasId, $wasId, []);
                    $day = Words::date(Cal::dnOf(Time::fromDb($key)->setTimezone(Time::zone((int) $was['all_day'] === 1 ? 'UTC' : (string) $was['tzid']))), (int) Time::nowUtc()->format('Y'));
                    $words[] = "'" . (string) $was['title'] . "' on $day was changed here while the change was half done; it was left as it was.";
                }
                continue;
            }
            $destId = (int) $dest['id'];
            $patch = [];
            foreach ($h['fields'] as $field) {
                // A place held here is its coordinates (its text is Google's).
                foreach ($field === 'location' ? OwnFields::COORDINATE_COLUMNS : (OwnFields::COLUMNS[$field] ?? []) as $col) {
                    if (array_key_exists($col, $was)) {
                        $patch[$col] = $was[$col];
                    }
                }
            }
            if ($h['fields'] !== []) {
                $patch['override_fields'] = OwnFields::encode(OwnFields::normalize(array_merge(OwnFields::stored($dest), $h['fields'])));
            }
            if ($patch !== []) {
                $this->db->update('events', $patch + ['updated_at' => Time::nowDb()], 'id = ?', [$destId]);
            }
            $replace = [];
            foreach (['tags' => 'event_tags', 'people' => 'event_people'] as $field => $table) {
                if (in_array($field, $h['fields'], true)) {
                    $this->db->run("DELETE FROM `$table` WHERE event_id = ?", [$destId]);
                    $replace[$table] = true;
                }
            }
            if ($live($wasId) !== null) {
                $this->db->run('DELETE FROM events WHERE id = ?', [$wasId]);
            }
            $this->relinks($h['links'], $wasId, $destId, $replace);
            $this->carried[$wasId] = $destId;
        }
        // Each day it put back or wrote: the tags and people it doesn't own
        // are its series' as they stand (OwnFields::follow).
        $this->followSeriesOf(array_merge(array_values($this->carried), array_map(static fn(array $h): int => (int) $h['row']['id'], $held)));
        return $words;
    }

    /**
     * Links captured for row $from (Undo::linksOf's shape) written naming
     * $to; tags and people only where $replace names their table, or where
     * $from is $to. A link whose other end is gone since is left out.
     *
     * @param array<string,list<array>> $links
     * @param array<string,true> $replace
     */
    private function relinks(array $links, int $from, int $to, array $replace): void
    {
        $eventCols = ['event_tags' => ['event_id'], 'event_people' => ['event_id']];
        foreach (OwnFields::REFERENCES as [$table, , $cols]) {
            $eventCols[$table] = $cols;
        }
        foreach ($eventCols as $table => $cols) {
            if (in_array($table, ['event_tags', 'event_people'], true) && $from !== $to && !isset($replace[$table])) {
                continue;
            }
            foreach ($links[$table] ?? [] as $link) {
                foreach ($cols as $col) {
                    if ((int) ($link[$col] ?? 0) === $from) {
                        $link[$col] = $to;
                    }
                }
                try {
                    $this->db->upsert($table, $link);
                } catch (\PDOException $e) {
                    if (!Db::isForeignKeyViolation($e)) {
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * Each row rewind kept (a day changed here since the change stopped,
     * which the plan doesn't name), once the change's writer has run,
     * follows its series as written by the one composition the sync and an
     * undo use: its day is where the series has it now (Feeds::ofSeries,
     * read from the series the row stood under), its own placement read by
     * OwnFields::placed, and each field it doesn't own, tags and people
     * among them, the series' (Feeds::followSeries, over Feeds::followed: a
     * place's text and the coordinates picked for it one value). A day the
     * series as written no longer has can't be placed: the part here is
     * refused (the writer's work rolled back, the row and what hangs off it
     * kept), and Review says why.
     *
     * @param array<int,?array> $kept id => the series' row it stood under
     */
    private function placeKept(array $kept): void
    {
        foreach ($kept as $id => $was) {
            $row = $this->db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [$id]);
            $master = $row === null ? null : $this->seriesOf($row);
            if ($row === null || $master === null || $row['recurrence_instance_utc'] === null) {
                continue;
            }
            $own = $this->feeds->ownOf($row, $was ?? $master);
            $target = Feeds::ofSeries($row, $was, $master);
            if ($target === null) {
                $key = Time::fromDb((string) $row['recurrence_instance_utc']);
                $day = Words::date(Cal::dnOf($key->setTimezone(Time::zone((int) $master['all_day'] === 1 ? 'UTC' : (string) $master['tzid']))), (int) Time::nowUtc()->format('Y'));
                throw HttpError::conflict('google_finish_kept', "'" . (string) $row['title'] . "' on $day was changed here after this change stopped, and the change leaves the series without that day");
            }
            // Its day and its times: where the series has the day now (Feeds::ofSeries), its own placement read by OwnFields::placed.
            $placing = ['recurrence_instance_utc', 'google_event_id', ...array_keys(\BetterCal\Domain\Series\Model::SERIES_COLUMNS)];
            if (array_intersect(OwnFields::PLACEMENT, $own) === []) {
                $placing = array_merge($placing, ['start_utc', 'end_utc', 'all_day', 'tzid']);
            }
            $after = OwnFields::placed(array_merge($row, array_intersect_key($target, array_flip($placing))), $master, $own);
            $patch = [];
            foreach ($after as $col => $value) {
                if (array_key_exists($col, $row) && !Feeds::sameValue($col, $row[$col], $value)) {
                    $patch[$col] = $value;
                }
            }
            if ($patch !== []) {
                $this->db->update('events', $patch + ['updated_at' => Time::nowDb()], 'id = ?', [$id]);
                $row = array_merge($row, $patch);
            }
            // What it inherits, tags and people among them: the one composition (Feeds::followSeries).
            Feeds::followSeries($this->db, $row, $master, $own);
        }
    }

    /**
     * Put back: the series as they were, each one any step of the change
     * was sent for (answered or not: an insert whose answer was lost is
     * found by the id it carried), and only while nothing changed since:
     * its etag at Google is the one the last answered step left or the one
     * the change started from, or, the last step's answer lost, the series
     * is what that step leaves (unchangedSince; an event of its own made
     * with its answer lost, as sent). A change made at Google since,
     * to any series, puts nothing back: nothing is sent or stored, the
     * pending row stays for Finish, and the 409 names each series (`left`
     * in its extra). Otherwise every series is put back: an inserted series is
     * cancelled, a single made is cancelled, a dropped exception is sent
     * again from its complete before state, and the rows brought back take
     * their ids and what hung off them from the snapshot (reattach). Its
     * own steps follow the change's in the journal, so a Put back that
     * stops half way is put back again from where it got to.
     *
     * When Finish is refused too (a pending undo or redo of an entry in an
     * older format: GoogleUndo::entryToFinish), the refusal's last words say
     * so (GoogleUndo::neitherWay) in place of the sentence that points to
     * Finish. Put back itself takes no entry back.
     *
     * @return array{readbacks:array<string,list<array>>, differences:list<string>, targets:list<array>, left:list<string>}
     */
    public function putBack(int $userId, array $calendar, int $pendingId, ?\Closure $beforeSend = null): array
    {
        $row = $this->pendingRow($userId, $pendingId);
        $befores = json_decode((string) $row['befores_json'], true) ?: [];
        $targets = json_decode((string) $row['targets_json'], true) ?: [];
        $steps = json_decode((string) $row['steps_json'], true) ?: [];
        $send = fn(string $method, string $path, ?array $body, ?string $ifMatch): array
            => $this->writer->send($calendar, $method, $path, $body, [], $ifMatch, $beforeSend);
        $this->feeds->takeDropped(); // what this recovery's stores remove is named (removedDays)
        $this->carried = [];
        // Every series checked before anything is sent or stored: Put back
        // is the whole change's, or none of it (half of a split put back
        // would leave two series overlapping).
        $left = [];
        $run = [];
        foreach ($befores as $i => $b) {
            $mine = array_values(array_filter($steps, static fn(array $s): bool => (int) $s['series'] === $i));
            if ($mine === []) {
                continue; // nothing was sent for this series
            }
            $last = null;
            $lastStep = null;
            $lastDone = null;
            $made = [];
            foreach ($mine as $s) {
                if ($s['kind'] === 'single') {
                    $made[(string) $s['id']] = ($s['state'] ?? self::DONE) === self::DONE ? ($s['etag'] ?? null) : null;
                    continue;
                }
                if ($s['kind'] === 'single-remove') {
                    continue;
                }
                $lastStep = $s;
                if (($s['state'] ?? self::DONE) === self::DONE) {
                    $lastDone = $s;
                    if (($s['etag'] ?? null) !== null) {
                        $last = (string) $s['etag'];
                    }
                }
            }
            $base = $last ?? ($targets[$i]['etag'] ?? null);
            $current = self::masterEtag($this->readBack($calendar, $send, null, (string) $b['googleId'], null, $beforeSend), (string) $b['googleId']);
            // Nothing changed since, judged by content (the digest, rule B,
            // decided 2026-11-09): Google holds what the last answered step
            // left, or, the last step's answer lost, what it would leave or
            // what the answered step before it left. One reading for an
            // answered step and a lost one: an etag moves without the
            // content moving (an instance's etag whenever its master is
            // written), and the same content is no conflict. A step
            // journaled without a digest (a removal: whether it is still
            // gone; one from before rule B: its etag) is read as before.
            $answered = function (?array $step) use ($calendar, $send, $targets, $b, $i, $base, $current, $beforeSend): bool {
                if ($step === null || (!isset($step['expect']) && !in_array($step['kind'], ['remove', 'record-remove'], true))) {
                    return $base === null || $current === null || $current === $base;
                }
                $sent = empty($step['back']) ? ($targets[$i] ?? null) : $b;
                return $sent !== null && $this->unchangedSince($calendar, $send, $sent, $step, $beforeSend);
            };
            $uncertain = $lastStep !== null && ($lastStep['state'] ?? self::DONE) === self::SENT;
            if ($uncertain) {
                $sent = empty($lastStep['back']) ? ($targets[$i] ?? null) : $b;
                $safe = ($lastDone !== null ? $answered($lastDone) : ($base !== null && $current === $base))
                    || ($sent !== null && $this->unchangedSince($calendar, $send, $sent, $lastStep, $beforeSend));
            } else {
                $safe = $answered($lastDone);
            }
            if (!$safe) {
                $left[] = "'" . (string) ($b['master']['title'] ?? $row['title']) . "' changed at Google since.";
                continue;
            }
            $b['etag'] = $current;
            $sentSingles = [];
            foreach ($targets[$i]['singles'] ?? [] as $single) {
                $sentSingles[(string) $single['id']] = $single;
            }
            foreach ($b['singles'] ?? [] as $si => $single) {
                $sid = (string) $single['id'];
                if (!array_key_exists($sid, $made)) {
                    unset($b['singles'][$si]); // never sent
                    continue;
                }
                $etag = $made[$sid];
                if ($etag === null && !empty($single['remove']) && isset($sentSingles[$sid]['row'])) {
                    // Its answer lost: put back only while it is as sent.
                    $found = $this->found($send, $sid);
                    if ($found === null) {
                        unset($b['singles'][$si]); // it never landed
                        continue;
                    }
                    // Rule B: every field of the resource as sent, and none it didn't send.
                    $parsed = self::parsed($found);
                    if ($parsed === null || self::fieldsOf($parsed, true) !== self::fieldsOf($sentSingles[$sid]['row'], true)) {
                        $left[] = "'" . (string) $sentSingles[$sid]['row']['title'] . "' changed at Google since.";
                        unset($b['singles'][$si]);
                        continue;
                    }
                    $etag = isset($found['etag']) && is_string($found['etag']) ? $found['etag'] : null;
                }
                $b['singles'][$si]['etag'] = $etag;
            }
            $b['singles'] = array_values($b['singles'] ?? []);
            $run[$i] = $b;
        }
        if ($left !== []) {
            $neither = null;
            $plan = json_decode((string) ($row['plan_json'] ?? ''), true);
            if (is_array($plan) && isset($plan['undo'])) {
                try {
                    GoogleUndo::entryToFinish($this->db, $userId, $plan);
                } catch (HttpError $e) {
                    if ($e->errorCode !== 'not_undoable') {
                        throw $e;
                    }
                    $neither = GoogleUndo::neitherWay(GoogleUndo::pendingRedo($this->db, $userId, $plan));
                }
            }
            $words = implode(' ', $left) . ' ' . ($neither ?? 'Nothing was put back; Finish completes the change from what Google has now.');
            $this->journaled(fn(Db $j) => $j->update('google_pending', ['status' => self::STATUS_HALF, 'error' => mb_substr($words, 0, 2000), 'updated_at' => Time::nowDb()], 'id = ?', [$pendingId]));
            $this->endUnlessTx($pendingId, true);
            throw new HttpError('google_changed_since', $words, 409, ['pending' => $pendingId, 'left' => $left]);
        }
        $this->refresh($calendar, $befores, $beforeSend, $pendingId);
        $out = ['readbacks' => [], 'differences' => [], 'targets' => [], 'inserted' => []];
        if ($run !== []) {
            $out = $this->run($calendar, $pendingId, $run, $steps, $beforeSend, true);
            // Rows a store of the change made that hold something here
            // alone since go to the day the series goes back to: the
            // change's target names its row at their key.
            $held = [];
            $made = $this->madeOf($pendingId);
            if ($made !== []) {
                [$in, $params] = Db::in(array_keys($made));
                foreach ($this->db->all("SELECT * FROM events WHERE id IN $in AND deleted_at IS NULL", $params) as $r) {
                    $h = Feeds::retained($this->db, $r);
                    if ($h !== null) {
                        $held[] = $this->taken($r, $h);
                    }
                }
            }
            $this->store($calendar, $out['readbacks'], $out['targets'], $pendingId);
            // Every row the snapshot holds for these series is put back: one
            // the store made again takes back its id (reattach), and one
            // nothing stands in for comes back whole, a day that held only
            // local data among them (an ordinary day to Google, so no
            // target names it, dropped by a store of the change).
            $local = json_decode((string) ($row['local_json'] ?? ''), true) ?: [];
            $this->bringBack($calendar, $this->reattach($calendar, $local, $out['targets']), $local);
            $out['differences'] = array_merge($out['differences'], $this->carryHeld($calendar, $held, $targets));
            // A row the change made that the snapshot does not hold (an
            // event of its own it inserted, cancelled above) goes with it,
            // read from the made list as Finish's rewind reads it.
            [, $taken] = $this->dropMade($calendar, $local, $out['targets'], $made);
            $out['differences'] = array_merge($out['differences'], $this->carryHeld($calendar, $taken, $targets));
        }
        $out = $this->withRemoved($out, $this->feeds->takeDropped());
        $this->settle($pendingId);
        $out['targets'] = array_values($out['targets']);
        return $out + ['left' => $left];
    }

    /**
     * Before a recovery runs, what Google holds for each series it names is
     * stored here, so its steps follow from Google's state: a change that
     * stopped with its edit here rolled back (or its process stopped) left
     * the rows as they were before it, not as Google has them. Each series
     * known by its uid; an insert whose answer was lost has none, and is
     * found by the id it carried.
     *
     * @param list<array> $series
     */
    /** @return array<string,list<array>> what was read, by uid */
    private function refresh(array $calendar, array $series, ?\Closure $beforeSend, int $pendingId): array
    {
        $send = fn(string $method, string $path, ?array $body, ?string $ifMatch): array
            => $this->writer->send($calendar, $method, $path, $body, [], $ifMatch, $beforeSend);
        $got = [];
        foreach ($series as $t) {
            if (!empty($t['uid']) && empty($t['insert']) && !isset($got[(string) $t['uid']])) {
                $got[(string) $t['uid']] = $this->readBack($calendar, $send, (string) $t['uid'], (string) $t['googleId'], null, $beforeSend);
            }
        }
        if ($got !== []) {
            $this->store($calendar, $got, [], $pendingId);
        }
        return $got;
    }

    /** An event as Google holds it now by its id, or null when it is gone (404, 410, or cancelled). */
    private function found(\Closure $send, string $id): ?array
    {
        try {
            $found = $send('GET', '/' . rawurlencode($id), null, null);
        } catch (HttpError $e) {
            if (!in_array((int) ($e->extra['googleStatus'] ?? 0), [404, 410], true)) {
                throw $e;
            }
            return null;
        }
        return (string) ($found['status'] ?? '') === 'cancelled' ? null : $found;
    }

    /**
     * Whether a series whose last step's answer was lost changed only by
     * that step, so Put back may reverse it ($t is the state the step was
     * towards), rule B: an insert that never landed (nothing to reverse),
     * else the digest of what Google holds now, every field of every
     * resource of the series, equal to the one the step journaled it
     * leaves (`expect`). A removal journals none and is read by whether it
     * landed; any other step without one (journaled before rule B) can't be
     * told from a change since, and is refused.
     */
    private function unchangedSince(array $calendar, \Closure $send, array $t, array $step, ?\Closure $beforeSend): bool
    {
        if (!isset($step['expect'])) {
            return $this->landed($send, $step);
        }
        $googleId = (string) $t['googleId'];
        $uid = isset($t['uid']) && $t['uid'] !== '' ? (string) $t['uid'] : null;
        if ($step['kind'] === 'insert') {
            $found = $this->found($send, $googleId);
            if ($found === null) {
                return true;
            }
            $uid = isset($found['iCalUID']) && is_string($found['iCalUID']) && $found['iCalUID'] !== '' ? $found['iCalUID'] : null;
        }
        $now = self::state(self::held($this->readBack($calendar, $send, $uid, $googleId, null, $beforeSend), $googleId), (bool) ($step['extras'] ?? false));
        return self::digest($now) === (string) $step['expect'];
    }

    /** Whether a removal whose answer was lost landed: the resource is gone or cancelled. Any other step without an `expect` is refused. */
    private function landed(\Closure $send, array $step): bool
    {
        if (!in_array($step['kind'], ['remove', 'record-remove', 'single-remove'], true)) {
            return false;
        }
        return $this->found($send, (string) $step['id']) === null;
    }

    /**
     * After a recovery or an undo stored what Google holds: each row of the
     * snapshot it kept (the pending row's localOf, or the rows as the undone
     * change left them) for a series the targets name, and
     * each event of its own they name, that the store brought back under
     * another id (an edited day Google dropped and was sent again, an event
     * of its own made again) takes back its id, its own columns (reminders,
     * the trip flag, an icon) and what hung off it (tags, people, trip
     * links, duplicate decisions, plugin data), with anything the new row
     * had gained since. A master made again keeps its new id (its edited
     * days hang off it) and takes back what hung off the old one. Returns
     * the snapshot's days of those series with no row at their key now
     * (one gone from Google's series, or one Google holds as an ordinary
     * day: a day that held only local data), for Put back to bring back.
     *
     * @param array<string,list<array>> $local
     * @param list<array> $targets
     * @return list<array> the snapshot's rows with nothing in their place
     */
    public function reattach(array $calendar, array $local, array $targets): array
    {
        $snap = [];
        foreach ($local['events'] ?? [] as $r) {
            $snap[(int) $r['id']] = $r;
        }
        $whole = [];
        if ($snap === []) {
            return $whole;
        }
        $calendarId = (int) $calendar['id'];
        $live = fn(int $id): bool => $this->db->scalar('SELECT 1 FROM events WHERE id = ? AND deleted_at IS NULL', [$id]) !== null;
        $masters = [];
        foreach ($targets as $t) {
            if (!empty($t['remove']) || empty($t['uid'])) {
                continue;
            }
            $uid = (string) $t['uid'];
            $master = $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [$calendarId, $uid]);
            if ($master !== null) {
                $masters[] = (int) $master['id'];
                foreach ($snap as $id => $r) {
                    if ((string) $r['uid'] === $uid && $r['recurrence_instance_utc'] === null && (int) $r['calendar_id'] === $calendarId && $id !== (int) $master['id'] && !$live($id)) {
                        $this->relink($r, $master, $local, false);
                    }
                }
            }
            $snapMaster = null;
            foreach ($snap as $r) {
                if ((string) $r['uid'] === $uid && $r['recurrence_instance_utc'] === null && (int) $r['calendar_id'] === $calendarId) {
                    $snapMaster = $r;
                }
            }
            foreach ($snap as $id => $r) {
                if ((string) $r['uid'] !== $uid || (int) $r['calendar_id'] !== $calendarId || $r['recurrence_instance_utc'] === null || $live($id)) {
                    continue;
                }
                // The day where the series stored now has it (whereNow), not the snapshot's key.
                $key = $master === null ? (string) $r['recurrence_instance_utc'] : self::whereNow(['master' => $master], (string) $r['recurrence_instance_utc'], $snapMaster);
                $now = $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$calendarId, $uid, $key]);
                if ($now !== null) {
                    $this->relink($r, $now, $local, true);
                } else {
                    $whole[] = $r;
                }
            }
            foreach ($t['singles'] ?? [] as $single) {
                $rid = (int) ($single['rowId'] ?? 0);
                if (!empty($single['remove']) || !isset($snap[$rid]) || $live($rid)) {
                    continue;
                }
                $now = $this->writer->rowByGoogleId($calendarId, (string) $single['id']);
                if ($now !== null) {
                    $this->relink($snap[$rid], $now, $local, true);
                }
            }
        }
        // Each series it put back together: a day holds the tags and people
        // it doesn't own as the series has them now, a master that took back
        // its old links included (OwnFields::follow).
        foreach ($masters as $masterId) {
            OwnFields::follow($this->db, $masterId);
        }
        return $whole;
    }

    /**
     * $was (a row of the snapshot, gone) is $now (the row a store made in
     * its place): with $identity, $now takes $was's id; either way it takes
     * $was's own columns and what hung off it. A link whose other end is
     * gone since is left out.
     *
     * @param array<string,list<array>> $local
     */
    private function relink(array $was, array $now, array $local, bool $identity): void
    {
        $wasId = (int) $was['id'];
        $nowId = (int) $now['id'];
        $to = $identity ? $wasId : $nowId;
        $eventCols = ['event_tags' => ['event_id'], 'event_people' => ['event_id']];
        foreach (OwnFields::REFERENCES as [$table, , $cols]) {
            $eventCols[$table] = $cols;
        }
        $links = [];
        $gained = $identity ? Undo::linksOf($this->db, [$nowId]) : [];
        // A day's tags and people come with their ownership (Own Fields): a
        // set the snapshot's day owned is the day's own again, named in its
        // list, and is the set it had (with the new row's where that row
        // owned its own too); a set it didn't own is its series' copy,
        // never carried: the day follows its series (OwnFields::follow).
        $wasOwn = $was['recurrence_instance_utc'] !== null ? OwnFields::stored($was) : null;
        $nowOwn = OwnFields::stored($now);
        $takes = [];
        foreach (OwnFields::LINKS as $field => [$linkTable]) {
            $takes[$linkTable] = $wasOwn === null ? [true, true] : [in_array($field, $wasOwn, true), in_array($field, $nowOwn, true) || !in_array($field, $wasOwn, true)];
        }
        foreach ($eventCols as $table => $cols) {
            foreach ([[$local[$table] ?? [], $wasId, 0], [$gained[$table] ?? [], $nowId, 1]] as [$rows, $from, $side]) {
                if (isset($takes[$table]) && !$takes[$table][$side]) {
                    continue;
                }
                foreach ($rows as $link) {
                    $named = false;
                    foreach ($cols as $col) {
                        if ((int) ($link[$col] ?? 0) === $from) {
                            $link[$col] = $to;
                            $named = true;
                        }
                    }
                    if ($named) {
                        $links[$table][] = $link;
                    }
                }
            }
        }
        $own = array_diff_key($was, array_flip(self::SYNCED), ['id' => true, 'created_at' => true, 'updated_at' => true]);
        // A day's series columns (the trip and clock-time flags) are its
        // series' now, never the snapshot's: a flag set on the series while
        // the change was pending reaches the day brought back (rule C).
        if ($was['recurrence_instance_utc'] !== null && $now['recurrence_parent_id'] !== null) {
            $master = $this->db->one('SELECT * FROM events WHERE id = ?', [(int) $now['recurrence_parent_id']]);
            foreach (array_keys(\BetterCal\Domain\Series\Model::SERIES_COLUMNS) as $col) {
                if ($master !== null && array_key_exists($col, $master)) {
                    $own[$col] = $master[$col];
                }
            }
        }
        if ((string) ($was['location'] ?? '') !== (string) ($now['location'] ?? '')) {
            // Where it is placed belongs to the place Google holds now.
            $own = array_filter($own, static fn(string $col): bool => !str_starts_with($col, 'location_') && $col !== 'geocoded_at', ARRAY_FILTER_USE_KEY);
        }
        $ownedLinks = $wasOwn === null ? [] : array_values(array_intersect(array_keys(OwnFields::LINKS), $wasOwn));
        if ($ownedLinks !== [] && array_diff($ownedLinks, $nowOwn) !== []) {
            $own['override_fields'] = OwnFields::encode(OwnFields::normalize(array_merge($nowOwn, $ownedLinks)));
        }
        $this->carried[$identity ? $nowId : $wasId] = $to;
        if ($identity) {
            $this->db->run('DELETE FROM events WHERE id = ?', [$nowId]);
            $this->db->upsert('events', array_merge($now, $own, ['id' => $wasId, 'updated_at' => Time::nowDb()]));
        } elseif ($own !== []) {
            $this->db->update('events', $own + ['updated_at' => Time::nowDb()], 'id = ?', [$nowId]);
        }
        foreach ($links as $table => $rows) {
            foreach ($rows as $link) {
                try {
                    $this->db->upsert($table, $link);
                } catch (\PDOException $e) {
                    if (!Db::isForeignKeyViolation($e)) {
                        throw $e;
                    }
                }
            }
        }
    }
}
