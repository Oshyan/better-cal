<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Domain\Series\Cal;
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
 * (google_pending, migrations 068 and 070) records the targets, the series
 * as they were (every exception's complete state), the rows here as they
 * were with what hangs off them (tags, people, trip links: the shape Undo
 * keeps), and each step: written as sent before its request and as done,
 * with the etag it left, after its answer. The row is written on a
 * connection of its own (Db::journal), so it is committed before the first
 * request whatever the account's transaction does after: a failure of any
 * kind, a lost connection or a stopped process leaves it. It goes only once
 * the edit here has committed (settle); a transaction that rolls back
 * leaves it for Review, unless nothing was sent. Every step is safe to
 * retry: an insert carries an id the app chose (a 409 on retry means it
 * landed), and every other step follows from a fresh read-back. A failure
 * mid-way stops, stores what Google has, and leaves the row for Review
 * ("Half done at Google"): Finish runs the same targets from Google's
 * state, Put back runs the befores, each series only while its etag is the
 * one its last step left, or one left by a step whose answer was lost when
 * the read-back shows what that step sent. A row still marked running whose
 * run holds no lock (a process that stopped) is half done too. Either way
 * the rows a recovery brings back take their ids, tags, people and links
 * from the snapshot (reattach).
 *
 * Undo (GoogleUndo, kind series) calls the same function with the befores
 * apply() returned as the targets, each conditional on its series' etag as
 * stored at undo time (withStoredEtags): applying a target state is the
 * whole public surface.
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

    /** The series as stored: a target (the before side of a plan, or the undo stage's target). */
    public static function stateOf(array $master, array $rows): array
    {
        $exceptions = [];
        foreach ($rows as $row) {
            $own = OwnFields::decode($row['override_fields'] ?? null) ?? OwnFields::infer($row, $master);
            $exceptions[(string) $row['recurrence_instance_utc']] = self::cols($row) + ['own' => $own, 'rowId' => (int) $row['id']];
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
            $own = OwnFields::decode($r['override_fields'] ?? null) ?? OwnFields::infer($row, $master);
            $t['exceptions'][(string) $r['recurrence_instance_utc']] = self::cols($r) + ['own' => $own, 'rowId' => $id];
        }
        foreach ($w['createEdited'] ?? [] as $cols) {
            $r = array_merge($after, $cols);
            $t['exceptions'][(string) $cols['recurrence_instance_utc']] = self::cols($r) + ['own' => OwnFields::decode($cols['override_fields'] ?? null) ?? [], 'rowId' => null];
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
     * that undo the change.
     *
     * @return array{pending:int, readbacks:array<string,list<array>>, differences:list<string>, inserted:array<string,array>, targets:list<array>, befores:list<array>}
     */
    public function apply(int $userId, array $calendar, array $targets, string $title, string $summary, ?int $eventId = null, ?\Closure $beforeSend = null): array
    {
        $befores = [];
        foreach ($targets as $i => $t) {
            $befores[$i] = $this->beforeOf($calendar, $t);
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
            'steps_json' => '[]',
            'created_at' => Time::nowDb(),
            'updated_at' => Time::nowDb(),
        ]));
        $this->open($pendingId, true);
        return $this->run($calendar, $pendingId, $targets, [], $beforeSend) + ['befores' => array_values($befores)];
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

    /** A run made outside any transaction, over: what a transaction's end does, now (inside one, its end does it). */
    private function endUnlessTx(int $pendingId, bool $committed, bool $created = false): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            $this->ended($pendingId, $committed, $created);
        }
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
     * Targets made conditional on what is stored here now: each series'
     * uid and etag as its row holds them (the etag follows the app's own
     * writes and the polls, Google changing every row's etag in a series
     * on any write), each event of its own's etag the same way. A series
     * not stored here has no etag to send (null: unconditional).
     *
     * @param list<array> $targets
     * @return list<array>
     */
    public function withStoredEtags(array $calendar, array $targets): array
    {
        foreach ($targets as $i => $t) {
            $master = $this->masterRow($calendar, $t);
            $targets[$i]['etag'] = $master !== null && isset($master['google_etag']) && $master['google_etag'] !== '' ? (string) $master['google_etag'] : null;
            if ($master !== null) {
                $targets[$i]['uid'] = (string) $master['uid'];
                $targets[$i]['insert'] = false;
            }
            foreach ($t['singles'] ?? [] as $si => $s) {
                $row = $this->writer->rowByGoogleId((int) $calendar['id'], (string) $s['id']);
                $targets[$i]['singles'][$si]['etag'] = $row !== null && isset($row['google_etag']) && $row['google_etag'] !== '' ? (string) $row['google_etag'] : null;
            }
        }
        return $targets;
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
     */
    private function run(array $calendar, int $pendingId, array $targets, array $steps, ?\Closure $beforeSend, bool $back = false): array
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
            foreach ($targets as $i => $t) {
                // One write to Google, in the journal as sent before it goes
                // and as done, with the etag it left, once answered. A
                // refusal (Google answered and wrote nothing) leaves no step;
                // no answer at all leaves it sent: it may have landed.
                $step = function (string $kind, string $id, ?string $key, \Closure $write) use (&$steps, $save, $i, $back): mixed {
                    $steps[] = ['series' => $i, 'kind' => $kind, 'id' => $id, 'key' => $key, 'etag' => null, 'state' => self::SENT, 'back' => $back, 'at' => Time::nowDb()];
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
                            $step('single-remove', $sid, null, fn() => $this->cancel($send, $sid, $s['etag'] ?? null));
                        }
                        continue;
                    }
                    $body = GoogleWriter::body(self::rowOf($s['row']));
                    $resource = self::stepDone($steps, $i, 'single', $sid, $back)
                        ? $this->insertChosen($send, $sid, $body)
                        : $step('single', $sid, null, fn() => $this->insertChosen($send, $sid, $body));
                    $inserted[$sid] = $resource;
                    $targets[$i]['singles'][$si]['uid'] = (string) ($resource['iCalUID'] ?? '');
                    $readbacks[(string) ($resource['iCalUID'] ?? $sid)] = [$resource];
                }
                if (!empty($t['remove'])) {
                    if (!self::stepDone($steps, $i, 'remove', (string) $t['googleId'], $back)) {
                        $step('remove', (string) $t['googleId'], null, fn() => $this->cancel($send, (string) $t['googleId'], $t['etag'] ?? null));
                    }
                    $uid = $t['uid'] ?? null;
                    $items = $this->readBack($calendar, $send, $uid, (string) $t['googleId'], null, $beforeSend);
                    // Each record of it still listed live goes too, so no
                    // edited day outlives its series, however Google treats
                    // a series' records when its master goes.
                    $live = array_filter($items, static fn(array $item): bool => (string) ($item['recurringEventId'] ?? '') === (string) $t['googleId'] && (string) ($item['status'] ?? '') !== 'cancelled');
                    foreach ($live as $item) {
                        $step('record-remove', (string) $item['id'], null, fn() => $this->cancel($send, (string) $item['id'], null));
                    }
                    if ($live !== []) {
                        $items = $this->readBack($calendar, $send, $uid, (string) $t['googleId'], null, $beforeSend);
                    }
                    $readbacks[(string) ($uid ?? $t['googleId'])] = $items;
                    continue;
                }
                $chain = $t['etag'] ?? null;
                if (!empty($t['insert'])) {
                    $resource = $step('insert', (string) $t['googleId'], null, fn() => $this->insertChosen($send, (string) $t['googleId'], self::insertBody($t['master'])));
                    $targets[$i]['uid'] = $t['uid'] = (string) ($resource['iCalUID'] ?? $t['googleId']);
                    $targets[$i]['insert'] = false;
                    $inserted[(string) $t['googleId']] = $resource;
                    $chain = $resource['etag'] ?? null;
                    $snapshot = $targets;
                    $this->journaled(fn(Db $j) => $j->update('google_pending', ['targets_json' => json_encode($snapshot)], 'id = ?', [$pendingId]));
                } else {
                    $before = $this->masterRow($calendar, $t);
                    $body = self::masterBody($before === null ? null : self::stateOf($before, [])['master'], $t['master']);
                    if ($body !== []) {
                        $resource = $step('master', (string) $t['googleId'], null, fn() => $send('PATCH', '/' . rawurlencode((string) $t['googleId']), $body, $chain));
                        $chain = $resource['etag'] ?? $chain;
                    }
                }
                $items = $this->readBack($calendar, $send, $t['uid'], (string) $t['googleId'], $chain, $beforeSend);
                $chain = self::masterEtag($items, (string) $t['googleId']) ?? $chain;
                foreach (self::stepsFor($t, $items) as [$kind, $id, $body, $key]) {
                    try {
                        $resource = $step($kind, $id, $key, fn() => $send('PATCH', '/' . rawurlencode($id), $body, $chain));
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
                }
                $items = $this->readBack($calendar, $send, $t['uid'], (string) $t['googleId'], $chain, $beforeSend);
                $readbacks[(string) $t['uid']] = $items;
                $differences = array_merge($differences, self::differences($t, $items, $thisYear));
            }
        } catch (\Throwable $e) {
            // Half done, in the journal before anything else is tried: a
            // failure of any kind, whatever the transaction here does next.
            $snapshot = $targets;
            $this->journaled(fn(Db $j) => $j->update('google_pending', ['status' => self::STATUS_HALF, 'error' => mb_substr($e->getMessage(), 0, 2000),
                'targets_json' => json_encode($snapshot), 'updated_at' => Time::nowDb()], 'id = ?', [$pendingId]));
            if (!$e instanceof HttpError && !$e instanceof \RuntimeException) {
                $this->endUnlessTx($pendingId, false);
                throw $e;
            }
            // What Google has now is what the calendar shows: best effort,
            // the pending row says what happened either way.
            try {
                $got = $readbacks;
                foreach ($targets as $t) {
                    if (!empty($t['uid']) && !isset($got[(string) $t['uid']])) {
                        $got[(string) $t['uid']] = $this->readBack($calendar, $send, (string) $t['uid'], (string) $t['googleId'], null, $beforeSend);
                    }
                }
                if ($got !== []) {
                    $this->store($calendar, $got, []);
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
     * undo's undo making it again), so it is read back, and a cancelled
     * one is sent again as the body has it, conditional on its etag.
     */
    private function insertChosen(\Closure $send, string $id, array $body): array
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
            return $send('PATCH', '/' . rawurlencode($id), $body + ['status' => 'confirmed'], isset($found['etag']) && is_string($found['etag']) ? $found['etag'] : null);
        }
    }

    /** A DELETE, conditional when an etag is known; an event already gone (404, or 410 once deleted) is gone. */
    private function cancel(\Closure $send, string $id, ?string $etag): void
    {
        try {
            $send('DELETE', '/' . rawurlencode($id), null, $etag);
        } catch (HttpError $e) {
            if (!in_array((int) ($e->extra['googleStatus'] ?? 0), [404, 410], true)) {
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
        return $body;
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
        return $body;
    }

    /** The instance id of an occurrence key under a master. */
    public static function instanceId(string $googleId, string $key, bool $allDay): string
    {
        return GoogleWriter::instanceId($googleId, $key, $allDay, 'UTC');
    }

    // ---- the comparison (pure) --------------------------------------------------

    /**
     * What Google holds for a series, read from a read-back: the master as
     * parsed (null when not live), the live exception records and the
     * cancelled ones by occurrence key, and the skips (cancelled records
     * and EXDATE lines). A dropped record (cancelled, no originalStartTime)
     * says nothing.
     *
     * @return array{master:?array, records:array<string,array>, cancelled:array<string,true>, skips:array<string,true>, etags:array<string,string>}
     */
    public static function held(array $items, string $googleId): array
    {
        $out = ['master' => null, 'records' => [], 'cancelled' => [], 'skips' => [], 'etags' => []];
        foreach ($items as $item) {
            if (isset($item['id'], $item['etag']) && is_string($item['etag'])) {
                $out['etags'][(string) $item['id']] = $item['etag'];
            }
            $parsed = GoogleSync::toParsed($item);
            if ($parsed === null) {
                continue;
            }
            if (!empty($parsed['cancelled'])) {
                if (!empty($parsed['dropped']) || $parsed['recurrence_instance_utc'] === null) {
                    continue;
                }
                $out['cancelled'][(string) $parsed['recurrence_instance_utc']] = true;
                $out['skips'][(string) $parsed['recurrence_instance_utc']] = true;
                continue;
            }
            if ((string) ($item['id'] ?? '') === $googleId && $parsed['recurrence_instance_utc'] === null) {
                $out['master'] = $parsed;
                foreach ($parsed['exdates'] ?? [] as $ex) {
                    $out['skips'][(string) $ex] = true;
                }
                continue;
            }
            if ($parsed['recurrence_instance_utc'] !== null) {
                $out['records'][(string) $parsed['recurrence_instance_utc']] = $parsed;
            }
        }
        return $out;
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

    /** Whether two rows hold the same event in the fields Google keeps. */
    private static function sameEvent(array $a, array $b): bool
    {
        $norm = static function (array $r): array {
            $title = trim((string) ($r['title'] ?? ''));
            return [
                'title' => $title === '(No title)' ? '' : $title,
                'description' => Sanitize::description(isset($r['description']) && $r['description'] !== '' ? (string) $r['description'] : null) ?? '',
                'location' => trim((string) ($r['location'] ?? '')),
                'status' => (string) ($r['status'] ?? 'confirmed') === 'tentative' ? 'tentative' : 'confirmed',
                'start_utc' => (string) $r['start_utc'],
                'end_utc' => (string) $r['end_utc'],
                'all_day' => (int) ($r['all_day'] ?? 0),
                'url' => (string) (Ics::webUrl(isset($r['url']) ? (string) $r['url'] : null) ?? ''),
            ];
        };
        return $norm($a) === $norm($b);
    }

    /**
     * The steps a read-back calls for, each a PATCH on an instance id: an
     * exception Google doesn't hold as the target has it (dropped, reset,
     * overwritten, or never there) sent whole; a skipped day not cancelled
     * cancelled; a record the target has no exception for put back to the
     * series' values at its occurrence (a day rejoining the series, or one
     * Google kept cancelled that the target shows).
     *
     * @return list<array{0:string,1:string,2:array,3:string}> kind, instance id, body, occurrence key
     */
    public static function stepsFor(array $t, array $items): array
    {
        $held = self::held($items, (string) $t['googleId']);
        $allDay = (int) $t['master']['all_day'] === 1;
        $steps = [];
        foreach ($t['exceptions'] as $key => $x) {
            $record = $held['records'][$key] ?? null;
            if ($record === null || !self::sameEvent($record, $x)) {
                $steps[] = ['exception', self::instanceId((string) $t['googleId'], (string) $key, $allDay), self::exceptionBody($x), (string) $key];
            }
        }
        foreach ($t['skips'] as $key) {
            if (!isset($held['skips'][$key])) {
                $steps[] = ['skip', self::instanceId((string) $t['googleId'], (string) $key, $allDay), ['status' => 'cancelled'], (string) $key];
            }
        }
        $skipSet = array_flip($t['skips']);
        $len = max(0, Time::fromDb($t['master']['end_utc'])->getTimestamp() - Time::fromDb($t['master']['start_utc'])->getTimestamp());
        foreach (array_keys($held['records'] + $held['cancelled']) as $key) {
            $key = (string) $key;
            if (isset($t['exceptions'][$key]) || isset($skipSet[$key])) {
                continue;
            }
            $steps[] = ['plain', self::instanceId((string) $t['googleId'], $key, $allDay), self::exceptionBody(self::plainAt($t['master'], $key, $len)), $key];
        }
        return $steps;
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
     * 'Rehearsal' on Tue 13 Apr; the plan had Wed 14 Apr.").
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
        $shown = static function (array $r) use ($tz, $thisYear): string {
            $at = Time::fromDb((string) $r['start_utc']);
            if ((int) ($r['all_day'] ?? 0) === 1) {
                return Words::date(Cal::dnOf($at), $thisYear);
            }
            $zone = Time::zone((string) ($r['tzid'] ?? $tz));
            return Words::date(Cal::dnOf($at->setTimezone($zone)), $thisYear) . ' at ' . $at->setTimezone($zone)->format('H:i');
        };
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
            $out[] = 'Google kept the series starting ' . $shown($g) . '; the plan had ' . $shown($m) . '.';
        }
        foreach (['title' => 'title', 'description' => 'notes', 'location' => 'place', 'status' => 'status', 'url' => 'link'] as $col => $word) {
            if (!self::sameEvent(['start_utc' => $m['start_utc'], 'end_utc' => $m['end_utc'], 'all_day' => $m['all_day']] + [$col => $g[$col] ?? null] + $m, $m)) {
                $out[] = "Google kept the series' $word as '" . (string) ($g[$col] ?? '') . "'; the plan had '" . (string) ($m[$col] ?? '') . "'.";
            }
        }
        foreach ($t['exceptions'] as $key => $x) {
            $key = (string) $key;
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
        $skipSet = array_flip($t['skips']);
        foreach ($t['skips'] as $key) {
            if (!isset($held['skips'][$key])) {
                $out[] = 'Google kept ' . $day((string) $key) . '; the plan skipped it.';
            }
        }
        foreach ($held['records'] as $key => $record) {
            if (!isset($t['exceptions'][$key]) && !isset($skipSet[$key])) {
                $out[] = "Google kept '" . $record['title'] . "' on " . $day((string) $key) . '; the plan had an ordinary day.';
            }
        }
        foreach (array_keys($held['cancelled']) as $key) {
            if (!isset($t['exceptions'][(string) $key]) && !isset($skipSet[$key])) {
                $out[] = 'Google kept ' . $day((string) $key) . ' skipped; the plan had it.';
            }
        }
        return $out;
    }

    // ---- local ---------------------------------------------------------------

    /**
     * What Google holds for these series, stored here through the poll's
     * path (Feeds::sync, every other series of the calendar left alone),
     * then each exception's own list from the targets (Google holds no
     * intent, so the list is the plan's, never a guess from equal values).
     *
     * @param array<string,list<array>> $readbacks iCalendar UID => the series' resources
     * @param list<array> $targets
     */
    public function store(array $calendar, array $readbacks, array $targets): void
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
        $snapshot = GoogleSync::applyChanges(GoogleSync::rowsToParsed($others), GoogleSync::toParsedList($items));
        $this->feeds->sync($calendar, $snapshot, journal: false);
        if (!OwnFields::hasColumn($this->db)) {
            return;
        }
        foreach ($targets as $t) {
            if (empty($t['uid'])) {
                continue;
            }
            foreach ($t['exceptions'] as $key => $x) {
                $this->db->run(
                    'UPDATE events SET override_fields = ? WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL',
                    [OwnFields::encode($x['own'] ?? []), (int) $calendar['id'], (string) $t['uid'], (string) $key]
                );
            }
        }
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
            'SELECT p.*, c.name AS calendar_name FROM google_pending p JOIN calendars c ON c.id = p.calendar_id WHERE p.user_id = ? AND p.status IN (?, ?) ORDER BY p.id DESC',
            [$userId, self::STATUS_HALF, self::STATUS_RUNNING]
        ));
        foreach ($rows as $row) {
            if ((string) $row['status'] === self::STATUS_RUNNING && !self::stopped($db, (int) $row['id'])) {
                continue;
            }
            $steps = json_decode((string) $row['steps_json'], true) ?: [];
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
            ];
        }
        return $out;
    }

    /** The half-done changes of an account, newest first (halfDoneOf). @return list<array> */
    public function halfDone(int $userId): array
    {
        return self::halfDoneOf($this->db, $userId);
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
     * Finish: the same targets from Google's state, which is what is
     * stored here (the half state was written from a read-back), so each
     * series' first write is conditional on its stored etag; then the rows
     * the change kept take back their ids and what hung off them (reattach).
     *
     * @return array{readbacks:array<string,list<array>>, differences:list<string>, targets:list<array>}
     */
    public function finish(int $userId, array $calendar, int $pendingId, ?\Closure $beforeSend = null): array
    {
        $row = $this->pendingRow($userId, $pendingId);
        $steps = json_decode((string) $row['steps_json'], true) ?: [];
        $this->refresh($calendar, json_decode((string) $row['targets_json'], true) ?: [], $beforeSend);
        $targets = $this->withStoredEtags($calendar, json_decode((string) $row['targets_json'], true) ?: []);
        $out = $this->run($calendar, $pendingId, $targets, $steps, $beforeSend);
        $this->store($calendar, $out['readbacks'], $out['targets']);
        $this->reattach($calendar, json_decode((string) ($row['local_json'] ?? ''), true) ?: [], $out['targets']);
        $this->settle($pendingId);
        return $out;
    }

    /**
     * Put back: the series as they were, each one any step of the change
     * was sent for (answered or not: an insert whose answer was lost is
     * found by the id it carried), and only while its etag at Google is the
     * one the last answered step left, the one the change started from, or
     * one left by a last step whose answer was lost when the read-back
     * shows what that step sent (a change made since, at Google or here,
     * leaves the series as it is, named in `left`); an inserted series is
     * cancelled, a single made is cancelled, a dropped exception is sent
     * again from its complete before state, and the rows brought back take
     * their ids and what hung off them from the snapshot (reattach). Its
     * own steps follow the change's in the journal, so a Put back that
     * stops half way is put back again from where it got to.
     *
     * @return array{readbacks:array<string,list<array>>, differences:list<string>, targets:list<array>, left:list<string>}
     */
    public function putBack(int $userId, array $calendar, int $pendingId, ?\Closure $beforeSend = null): array
    {
        $row = $this->pendingRow($userId, $pendingId);
        $befores = json_decode((string) $row['befores_json'], true) ?: [];
        $targets = json_decode((string) $row['targets_json'], true) ?: [];
        $steps = json_decode((string) $row['steps_json'], true) ?: [];
        $this->refresh($calendar, $befores, $beforeSend);
        $send = fn(string $method, string $path, ?array $body, ?string $ifMatch): array
            => $this->writer->send($calendar, $method, $path, $body, [], $ifMatch, $beforeSend);
        $left = [];
        $run = [];
        foreach ($befores as $i => $b) {
            $mine = array_values(array_filter($steps, static fn(array $s): bool => (int) $s['series'] === $i));
            if ($mine === []) {
                continue; // nothing was sent for this series
            }
            $last = null;
            $lastStep = null;
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
                if (($s['state'] ?? self::DONE) === self::DONE && ($s['etag'] ?? null) !== null) {
                    $last = (string) $s['etag'];
                }
            }
            $base = $last ?? ($targets[$i]['etag'] ?? null);
            $current = self::masterEtag($this->readBack($calendar, $send, null, (string) $b['googleId'], null, $beforeSend), (string) $b['googleId']);
            if ($base !== null && $current !== null && $current !== $base) {
                $uncertain = $lastStep !== null && ($lastStep['state'] ?? self::DONE) === self::SENT;
                $sent = empty($lastStep['back']) ? ($targets[$i] ?? null) : $b;
                if (!$uncertain || $sent === null || !$this->landed($calendar, $send, $sent, $lastStep, $beforeSend)) {
                    $left[] = "'" . (string) ($b['master']['title'] ?? $row['title']) . "' changed at Google since, and was left as it is.";
                    continue;
                }
            }
            $b['etag'] = $current;
            foreach ($b['singles'] ?? [] as $si => $single) {
                if (!array_key_exists((string) $single['id'], $made)) {
                    unset($b['singles'][$si]); // never sent
                    continue;
                }
                $b['singles'][$si]['etag'] = $made[(string) $single['id']];
            }
            $b['singles'] = array_values($b['singles'] ?? []);
            $run[$i] = $b;
        }
        $out = ['readbacks' => [], 'differences' => [], 'targets' => [], 'inserted' => []];
        if ($run !== []) {
            $out = $this->run($calendar, $pendingId, $run, $steps, $beforeSend, true);
            $this->store($calendar, $out['readbacks'], $out['targets']);
            $this->reattach($calendar, json_decode((string) ($row['local_json'] ?? ''), true) ?: [], $out['targets']);
        }
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
    private function refresh(array $calendar, array $series, ?\Closure $beforeSend): void
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
            $this->store($calendar, $got, []);
        }
    }

    /**
     * Whether a step whose answer was lost landed: Google holds what it
     * sent for what it wrote ($t is the state it was a step towards).
     */
    private function landed(array $calendar, \Closure $send, array $t, array $step, ?\Closure $beforeSend): bool
    {
        $id = (string) $step['id'];
        if (in_array($step['kind'], ['insert', 'single', 'remove', 'record-remove', 'single-remove'], true)) {
            try {
                $found = $send('GET', '/' . rawurlencode($id), null, null);
            } catch (HttpError $e) {
                if (!in_array((int) ($e->extra['googleStatus'] ?? 0), [404, 410], true)) {
                    throw $e;
                }
                $found = null;
            }
            $live = $found !== null && (string) ($found['status'] ?? '') !== 'cancelled';
            return in_array($step['kind'], ['insert', 'single'], true) ? $live : !$live;
        }
        $googleId = (string) $t['googleId'];
        $held = self::held($this->readBack($calendar, $send, isset($t['uid']) ? (string) $t['uid'] : null, $googleId, null, $beforeSend), $googleId);
        $key = (string) ($step['key'] ?? '');
        $m = $t['master'];
        switch ($step['kind']) {
            case 'master':
                $g = $held['master'];
                return $g !== null && self::sameRule((string) ($g['rrule'] ?? ''), (string) $m['rrule']) && (int) $g['all_day'] === (int) $m['all_day'] && self::sameEvent($g, $m);
            case 'exception':
                return isset($held['records'][$key], $t['exceptions'][$key]) && self::sameEvent($held['records'][$key], $t['exceptions'][$key]);
            case 'skip':
                return isset($held['skips'][$key]);
            case 'plain':
                $len = max(0, Time::fromDb($m['end_utc'])->getTimestamp() - Time::fromDb($m['start_utc'])->getTimestamp());
                return !isset($held['cancelled'][$key]) && (!isset($held['records'][$key]) || self::sameEvent($held['records'][$key], self::plainAt($m, $key, $len)));
        }
        return false;
    }

    /**
     * After a recovery stored what Google holds: each row the targets name
     * that the store brought back under another id (an edited day Google
     * dropped and was sent again, an event of its own made again) takes
     * back its id, its own columns (reminders, the trip flag, an icon) and
     * what hung off it (tags, people, trip links, duplicate decisions,
     * plugin data) from the snapshot the pending row kept (localOf), with
     * anything the new row had gained since. A master made again keeps its
     * new id (its edited days hang off it) and takes back what hung off the
     * old one.
     *
     * @param array<string,list<array>> $local
     * @param list<array> $targets
     */
    private function reattach(array $calendar, array $local, array $targets): void
    {
        $snap = [];
        foreach ($local['events'] ?? [] as $r) {
            $snap[(int) $r['id']] = $r;
        }
        if ($snap === []) {
            return;
        }
        $calendarId = (int) $calendar['id'];
        $live = fn(int $id): bool => $this->db->scalar('SELECT 1 FROM events WHERE id = ? AND deleted_at IS NULL', [$id]) !== null;
        foreach ($targets as $t) {
            if (!empty($t['remove']) || empty($t['uid'])) {
                continue;
            }
            $uid = (string) $t['uid'];
            $master = $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [$calendarId, $uid]);
            if ($master !== null) {
                foreach ($snap as $id => $r) {
                    if ((string) $r['uid'] === $uid && $r['recurrence_instance_utc'] === null && (int) $r['calendar_id'] === $calendarId && $id !== (int) $master['id'] && !$live($id)) {
                        $this->relink($r, $master, $local, false);
                    }
                }
            }
            foreach ($t['exceptions'] ?? [] as $key => $x) {
                $rid = (int) ($x['rowId'] ?? 0);
                if (!isset($snap[$rid]) || $live($rid)) {
                    continue;
                }
                $now = $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$calendarId, $uid, (string) $key]);
                if ($now !== null) {
                    $this->relink($snap[$rid], $now, $local, true);
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
        foreach ($eventCols as $table => $cols) {
            foreach ([[$local[$table] ?? [], $wasId], [$gained[$table] ?? [], $nowId]] as [$rows, $from]) {
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
        if ((string) ($was['location'] ?? '') !== (string) ($now['location'] ?? '')) {
            // Where it is placed belongs to the place Google holds now.
            $own = array_filter($own, static fn(string $col): bool => !str_starts_with($col, 'location_') && $col !== 'geocoded_at', ARRAY_FILTER_USE_KEY);
        }
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
