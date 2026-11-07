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
 * (google_pending, migration 068) records the targets, the series as they
 * were (every exception's complete state) and each step done with the etag
 * it left, before the first request. Every step is safe to retry: an
 * insert carries an id the app chose (a 409 on retry means it landed), and
 * every other step follows from a fresh read-back. A failure mid-way
 * stops, stores what Google has, and leaves the row for Review ("Half done
 * at Google"): Finish runs the same targets from Google's state, Put back
 * runs the befores, each series only while its etag is the one its last
 * step left.
 *
 * The undo stage calls the same function with the before state as the
 * target: applying a target state is the whole public surface.
 */
final class GoogleSeries
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_HALF = 'half';
    public const HALF_WORDS = 'Part of this change reached Google. Review lists it as half done at Google, with Finish and Put back.';
    /** Read-backs of a write not yet showing it, with backoff (seconds); never a wait for correctness. */
    private const READBACK_TRIES = 4;
    private const BACKOFF = [0.3, 0.6, 1.2];
    private const PAGES = 4;
    private const FIELDS = ['title', 'description', 'location', 'status', 'start_utc', 'end_utc', 'all_day'];

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
     * @return array{pending:int, readbacks:array<string,list<array>>, differences:list<string>, inserted:array<string,array>, targets:list<array>}
     */
    public function apply(int $userId, array $calendar, array $targets, string $title, string $summary, ?int $eventId = null, ?\Closure $beforeSend = null): array
    {
        $befores = [];
        foreach ($targets as $i => $t) {
            $befores[$i] = $this->beforeOf($calendar, $t);
        }
        $pendingId = (int) $this->db->insert('google_pending', [
            'user_id' => $userId,
            'calendar_id' => (int) $calendar['id'],
            'event_id' => $eventId,
            'title' => mb_substr($title, 0, 500),
            'summary' => mb_substr($summary, 0, 1000),
            'status' => self::STATUS_RUNNING,
            'targets_json' => json_encode($targets),
            'befores_json' => json_encode($befores),
            'steps_json' => '[]',
            'created_at' => Time::nowDb(),
            'updated_at' => Time::nowDb(),
        ]);
        $steps = [];
        return $this->run($calendar, $pendingId, $targets, $steps, $beforeSend);
    }

    /** The series a target changes, as it is here before the change; an inserted series' before is its removal. */
    private function beforeOf(array $calendar, array $t): array
    {
        $singles = [];
        foreach ($t['singles'] ?? [] as $s) {
            $singles[] = ['id' => (string) $s['id'], 'remove' => true];
        }
        if (!empty($t['insert'])) {
            return ['uid' => null, 'googleId' => (string) $t['googleId'], 'etag' => null, 'insert' => false, 'remove' => true, 'master' => $t['master'], 'exceptions' => [], 'skips' => [], 'singles' => $singles];
        }
        $master = $this->masterRow($calendar, $t);
        $rows = $master === null ? [] : $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [(int) $master['id']]);
        $before = $master === null ? $t : self::stateOf($master, $rows);
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
     * @param list<array> $steps done so far (a retry)
     */
    private function run(array $calendar, int $pendingId, array $targets, array $steps, ?\Closure $beforeSend): array
    {
        $readbacks = [];
        $differences = [];
        $inserted = [];
        $thisYear = (int) Time::nowUtc()->format('Y');
        $send = fn(string $method, string $path, ?array $body, ?string $ifMatch): array
            => $this->writer->send($calendar, $method, $path, $body, [], $ifMatch, $beforeSend);
        try {
            foreach ($targets as $i => $t) {
                $record = function (string $kind, string $id, ?string $etag) use ($pendingId, &$steps, $i): void {
                    $steps[] = ['series' => $i, 'kind' => $kind, 'id' => $id, 'etag' => $etag, 'at' => Time::nowDb()];
                    $this->db->update('google_pending', ['steps_json' => json_encode($steps), 'updated_at' => Time::nowDb()], 'id = ?', [$pendingId]);
                };
                foreach ($t['singles'] ?? [] as $si => $s) {
                    $sid = (string) $s['id'];
                    if (!empty($s['remove'])) {
                        if (!self::stepDone($steps, $i, 'single-remove', $sid)) {
                            $this->cancel($send, $sid, $s['etag'] ?? null);
                            $record('single-remove', $sid, null);
                        }
                        continue;
                    }
                    $resource = $this->insertChosen($send, $sid, GoogleWriter::body(self::rowOf($s['row'])));
                    $inserted[$sid] = $resource;
                    $targets[$i]['singles'][$si]['uid'] = (string) ($resource['iCalUID'] ?? '');
                    if (!self::stepDone($steps, $i, 'single', $sid)) {
                        $record('single', $sid, $resource['etag'] ?? null);
                    }
                    $readbacks[(string) ($resource['iCalUID'] ?? $sid)] = [$resource];
                }
                if (!empty($t['remove'])) {
                    if (!self::stepDone($steps, $i, 'remove', (string) $t['googleId'])) {
                        $this->cancel($send, (string) $t['googleId'], $t['etag'] ?? null);
                        $record('remove', (string) $t['googleId'], null);
                    }
                    $uid = $t['uid'] ?? null;
                    $items = $this->readBack($calendar, $send, $uid, (string) $t['googleId'], null, $beforeSend);
                    $readbacks[(string) ($uid ?? $t['googleId'])] = $items;
                    continue;
                }
                $chain = $t['etag'] ?? null;
                if (!empty($t['insert'])) {
                    $resource = $this->insertChosen($send, (string) $t['googleId'], self::insertBody($t['master']));
                    $targets[$i]['uid'] = $t['uid'] = (string) ($resource['iCalUID'] ?? $t['googleId']);
                    $targets[$i]['insert'] = false;
                    $inserted[(string) $t['googleId']] = $resource;
                    $chain = $resource['etag'] ?? null;
                    $record('insert', (string) $t['googleId'], $chain);
                    $this->db->update('google_pending', ['targets_json' => json_encode($targets)], 'id = ?', [$pendingId]);
                } else {
                    $before = $this->masterRow($calendar, $t);
                    $body = self::masterBody($before === null ? null : self::stateOf($before, [])['master'], $t['master']);
                    if ($body !== []) {
                        $resource = $send('PATCH', '/' . rawurlencode((string) $t['googleId']), $body, $chain);
                        $chain = $resource['etag'] ?? $chain;
                        $record('master', (string) $t['googleId'], $chain);
                    }
                }
                $items = $this->readBack($calendar, $send, $t['uid'], (string) $t['googleId'], $chain, $beforeSend);
                $chain = self::masterEtag($items, (string) $t['googleId']) ?? $chain;
                foreach (self::stepsFor($t, $items) as [$kind, $id, $body]) {
                    $resource = $send('PATCH', '/' . rawurlencode($id), $body, $chain);
                    $chain = $resource['etag'] ?? $chain;
                    $record($kind, $id, $chain);
                }
                $items = $this->readBack($calendar, $send, $t['uid'], (string) $t['googleId'], $chain, $beforeSend);
                $readbacks[(string) $t['uid']] = $items;
                $differences = array_merge($differences, self::differences($t, $items, $thisYear));
            }
        } catch (HttpError | \RuntimeException $e) {
            $this->db->update('google_pending', ['status' => self::STATUS_HALF, 'error' => mb_substr($e->getMessage(), 0, 2000), 'targets_json' => json_encode($targets), 'updated_at' => Time::nowDb()], 'id = ?', [$pendingId]);
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
            $code = $e instanceof HttpError ? $e->errorCode : 'google_write_failed';
            $status = $e instanceof HttpError ? $e->status : 502;
            $message = $e instanceof HttpError ? $e->getMessage() : 'Google could not be reached: ' . $e->getMessage();
            throw new HttpError($code, rtrim($message, '.') . '. ' . self::HALF_WORDS, $status, ['pending' => $pendingId]);
        }
        return ['pending' => $pendingId, 'readbacks' => $readbacks, 'differences' => $differences, 'inserted' => $inserted, 'targets' => $targets];
    }

    private static function stepDone(array $steps, int $series, string $kind, string $id): bool
    {
        foreach ($steps as $s) {
            if ((int) $s['series'] === $series && $s['kind'] === $kind && $s['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    /** An insert with the id the app chose: a 409 means the first attempt landed, so the event is read back and counts as done. */
    private function insertChosen(\Closure $send, string $id, array $body): array
    {
        try {
            return $send('POST', '', ['id' => $id] + $body, null);
        } catch (HttpError $e) {
            if ((int) ($e->extra['googleStatus'] ?? 0) !== 409) {
                throw $e;
            }
            return $send('GET', '/' . rawurlencode($id), null, null);
        }
    }

    /** A DELETE, conditional when an etag is known; an event already gone (404) is gone. */
    private function cancel(\Closure $send, string $id, ?string $etag): void
    {
        try {
            $send('DELETE', '/' . rawurlencode($id), null, $etag);
        } catch (HttpError $e) {
            if ((int) ($e->extra['googleStatus'] ?? 0) !== 404) {
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
            if (isset($full['source'])) {
                $body['source'] = $full['source'];
            }
        }
        if ($body !== [] || $before === null || (string) $before['rrule'] !== (string) $after['rrule']) {
            $body['recurrence'] = $after['rrule'] !== '' ? ['RRULE:' . $after['rrule']] : [];
        }
        return $body;
    }

    /** An exception's body on its instance id: its fields and placement, its link only when its own. */
    private static function exceptionBody(array $x): array
    {
        $row = self::rowOf($x);
        if (!in_array('url', $x['own'] ?? [], true)) {
            $row['url'] = null;
        }
        return GoogleWriter::instanceBody($row);
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
     * @return list<array{0:string,1:string,2:array}> kind, instance id, body
     */
    public static function stepsFor(array $t, array $items): array
    {
        $held = self::held($items, (string) $t['googleId']);
        $allDay = (int) $t['master']['all_day'] === 1;
        $steps = [];
        foreach ($t['exceptions'] as $key => $x) {
            $record = $held['records'][$key] ?? null;
            if ($record === null || !self::sameEvent($record, $x)) {
                $steps[] = ['exception', self::instanceId((string) $t['googleId'], (string) $key, $allDay), self::exceptionBody($x)];
            }
        }
        foreach ($t['skips'] as $key) {
            if (!isset($held['skips'][$key])) {
                $steps[] = ['skip', self::instanceId((string) $t['googleId'], (string) $key, $allDay), ['status' => 'cancelled']];
            }
        }
        $skipSet = array_flip($t['skips']);
        $len = max(0, Time::fromDb($t['master']['end_utc'])->getTimestamp() - Time::fromDb($t['master']['start_utc'])->getTimestamp());
        foreach (array_keys($held['records'] + $held['cancelled']) as $key) {
            $key = (string) $key;
            if (isset($t['exceptions'][$key]) || isset($skipSet[$key])) {
                continue;
            }
            $steps[] = ['plain', self::instanceId((string) $t['googleId'], $key, $allDay), self::exceptionBody(self::plainAt($t['master'], $key, $len))];
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
        foreach (['title' => 'title', 'description' => 'notes', 'location' => 'place', 'status' => 'status'] as $col => $word) {
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

    /** The change completed: its pending row goes. */
    public function settle(int $pendingId): void
    {
        $this->db->run('DELETE FROM google_pending WHERE id = ?', [$pendingId]);
    }

    // ---- recovery -----------------------------------------------------------

    /** The half-done changes of an account, newest first. @return list<array> */
    public function halfDone(int $userId): array
    {
        $out = [];
        foreach ($this->db->all("SELECT p.*, c.name AS calendar_name FROM google_pending p JOIN calendars c ON c.id = p.calendar_id WHERE p.user_id = ? AND p.status = 'half' ORDER BY p.id DESC", [$userId]) as $row) {
            $steps = json_decode((string) $row['steps_json'], true) ?: [];
            $out[] = [
                'id' => (int) $row['id'],
                'calendarId' => (int) $row['calendar_id'],
                'calendarName' => (string) $row['calendar_name'],
                'eventId' => $row['event_id'] !== null ? (int) $row['event_id'] : null,
                'title' => (string) $row['title'],
                'summary' => (string) $row['summary'],
                'error' => (string) ($row['error'] ?? ''),
                'stepsDone' => count($steps),
                'createdAt' => Time::dbToIso((string) $row['created_at'], 'UTC'),
            ];
        }
        return $out;
    }

    private function pendingRow(int $userId, int $pendingId): array
    {
        $row = $this->db->one('SELECT * FROM google_pending WHERE id = ? AND user_id = ?', [$pendingId, $userId]);
        if ($row === null) {
            throw HttpError::notFound('No such half-done Google change');
        }
        if ((string) $row['status'] !== self::STATUS_HALF) {
            throw HttpError::conflict('google_pending_running', 'This change is still under way.');
        }
        return $row;
    }

    /**
     * Finish: the same targets from Google's state, which is what is
     * stored here (the half state was written from a read-back), so each
     * series' first write is conditional on its stored etag.
     *
     * @return array{readbacks:array<string,list<array>>, differences:list<string>, targets:list<array>}
     */
    public function finish(int $userId, array $calendar, int $pendingId, ?\Closure $beforeSend = null): array
    {
        $row = $this->pendingRow($userId, $pendingId);
        $targets = json_decode((string) $row['targets_json'], true) ?: [];
        $steps = json_decode((string) $row['steps_json'], true) ?: [];
        foreach ($targets as $i => $t) {
            $master = $this->masterRow($calendar, $t);
            if ($master !== null) {
                $targets[$i]['uid'] = (string) $master['uid'];
                $targets[$i]['etag'] = isset($master['google_etag']) && $master['google_etag'] !== '' ? (string) $master['google_etag'] : null;
                $targets[$i]['insert'] = false;
            }
        }
        $this->db->update('google_pending', ['status' => self::STATUS_RUNNING, 'error' => null, 'updated_at' => Time::nowDb()], 'id = ?', [$pendingId]);
        $out = $this->run($calendar, $pendingId, $targets, $steps, $beforeSend);
        $this->store($calendar, $out['readbacks'], $out['targets']);
        $this->settle($pendingId);
        return $out;
    }

    /**
     * Put back: the series as they were, each only while its etag at
     * Google is the one its last step left (a change made since, at Google
     * or here, leaves it as it is, named in `left`); an inserted series is
     * cancelled, a single made is cancelled, a dropped exception is sent
     * again from its complete before state.
     *
     * @return array{readbacks:array<string,list<array>>, differences:list<string>, targets:list<array>, left:list<string>}
     */
    public function putBack(int $userId, array $calendar, int $pendingId, ?\Closure $beforeSend = null): array
    {
        $row = $this->pendingRow($userId, $pendingId);
        $befores = json_decode((string) $row['befores_json'], true) ?: [];
        $steps = json_decode((string) $row['steps_json'], true) ?: [];
        $send = fn(string $method, string $path, ?array $body, ?string $ifMatch): array
            => $this->writer->send($calendar, $method, $path, $body, [], $ifMatch, $beforeSend);
        $left = [];
        $run = [];
        foreach ($befores as $i => $b) {
            $last = null;
            $singleEtags = [];
            foreach ($steps as $s) {
                if ((int) $s['series'] !== $i) {
                    continue;
                }
                if ($s['kind'] === 'single') {
                    $singleEtags[(string) $s['id']] = $s['etag'];
                } else {
                    $last = $s['etag'] ?? $last;
                }
            }
            $touched = array_filter($steps, static fn(array $s): bool => (int) $s['series'] === $i);
            if ($touched === []) {
                continue; // nothing was done to this series
            }
            $current = self::masterEtag($this->readBack($calendar, $send, null, (string) $b['googleId'], null, $beforeSend), (string) $b['googleId']);
            if ($last !== null && $current !== null && $current !== $last) {
                $left[] = "'" . (string) ($b['master']['title'] ?? $row['title']) . "' changed at Google since, and was left as it is.";
                continue;
            }
            $b['etag'] = $current;
            foreach ($b['singles'] ?? [] as $si => $s) {
                if (!isset($singleEtags[(string) $s['id']])) {
                    unset($b['singles'][$si]); // never made
                    continue;
                }
                $b['singles'][$si]['etag'] = $singleEtags[(string) $s['id']];
            }
            $b['singles'] = array_values($b['singles'] ?? []);
            $run[] = $b;
        }
        $this->db->update('google_pending', ['status' => self::STATUS_RUNNING, 'error' => null, 'steps_json' => '[]', 'updated_at' => Time::nowDb()], 'id = ?', [$pendingId]);
        $out = ['readbacks' => [], 'differences' => [], 'targets' => [], 'inserted' => []];
        if ($run !== []) {
            $out = $this->run($calendar, $pendingId, array_values($run), [], $beforeSend);
            $this->store($calendar, $out['readbacks'], $out['targets']);
        }
        $this->settle($pendingId);
        return $out + ['left' => $left];
    }
}
