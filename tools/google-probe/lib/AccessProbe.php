<?php
// The access probe: what Google answers the connected account at each step
// of a shared calendar's access being lowered and taken away. One run is one
// stage (a free label the manager gives: writer, reader, freebusy, unshared,
// restored, deleted, cleanup) and sends the same fixed set of requests:
//
//   1  GET /users/me/calendarList/<id>             status, accessRole
//   2  GET /calendars/<id>                         status
//   3  GET events?maxResults=1                     status, accessRole, nextSyncToken given
//   4  GET events?showDeleted&singleEvents=false   status, accessRole, items, nextSyncToken given
//   4d the same list from the sync token an earlier stage saved (when there is one)
//   5  POST a marked probe event                   status, reason
//   6  GET the single event, the series and its renamed day (and the owner's
//      own event when one was found); PATCH each one's title (the single
//      event with If-Match on its last known etag)
//   7  GET an event id that never existed          status
//
// Stage "writer" first makes what 6 reads (a single event, a weekly series
// of four with its second day renamed), after the reads and before 5; stage
// "cleanup" sends only the deletes of what the state file lists. Nothing is
// deleted on any other stage.
//
// Round 2 (--round 2, after the first live round) adds, at every stage: a
// list by iCalUID of the single event and of the series (U1, U2: item count,
// accessRole, each item's key names), the key names of the first of the
// probe's own items in request 4, and an insert under the id of the single
// event the writer stage made (I). At stage writer it also makes three sets
// of deletables (a single event and a weekly series of four with its second
// day renamed), one each for reader, freebusy and unshared; at each of those
// stages it sends, in this order, conditional on the etags stored: DELETE of
// that stage's single event (D1), of its renamed day's record (D2), of its
// series (D3), and of the owner's own event on the etag stored at writer
// (D4), each one not refused followed by a GET (status only). At stage
// restored it first PATCHes the single event's title once a second until
// Google takes it or 180 seconds pass (R0), and at the end lists what of the
// deletables still exists (R9). Cleanup deletes the deletables too.
//
// The state (event ids, their last etags, the last sync token and the stage
// it came from) lives in a JSON file beside the report, named by a hash of
// the calendar id, so each stage's run picks up where the last left off.
// The report (access-<hash>.md and .json) gains one table per stage on each
// run, scrubbed through Report::scrub(); the raw sync token stays in the
// state file alone, which is in the report folder (gitignored by default).
declare(strict_types=1);

namespace BetterCal\GoogleProbe;

final class AccessProbe
{
    /** An id of the form Google takes (base32hex) that no event has ever had. */
    public const MISSING_ID = 'accessprobe0missing0event';
    public const SINGLE_TITLE = 'Access probe single';
    public const SERIES_TITLE = 'Access probe weekly';
    public const DAY_TITLE = 'Access probe weekly (one changed)';
    private const ZONE = 'America/Los_Angeles';
    /** The page size of request 4: a scratch calendar holds a handful of events. */
    private const PAGE = 50;
    private const JSON = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    public readonly string $statePath;
    public readonly string $mdPath;
    public readonly string $jsonPath;
    /** @var array the state as loaded and as it stands */
    public array $state;
    /** @var array this run's stage record */
    private array $record = [];

    public function __construct(
        private readonly Api $api,
        private readonly Report $scrubber,
        string $dir,
        private readonly bool $allowOtherEvents = false,
        private readonly ?\DateTimeImmutable $today = null,
        private readonly bool $skipImport = false,
        private readonly int $round = 1,
        private readonly ?\Closure $sleep = null,
    ) {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot make the report folder $dir");
        }
        $hash = substr(hash('sha256', $api->calendarId), 0, 10);
        $dir = rtrim($dir, '/');
        $this->statePath = "$dir/access-state-$hash.json";
        $this->mdPath = "$dir/access-$hash.md";
        $this->jsonPath = "$dir/access-$hash.json";
        $this->state = self::loadState($this->statePath);
    }

    // ---- State ----------------------------------------------------------------

    /** @return array{single:?array, series:?array, inserted:list<string>, ownerEvent:?array, sync:?array, stages:list<string>} */
    public static function emptyState(): array
    {
        return ['single' => null, 'series' => null, 'inserted' => [], 'ownerEvent' => null, 'sync' => null, 'stages' => [], 'listreadMade' => []];
    }

    public static function loadState(string $path): array
    {
        if (!is_file($path)) {
            return self::emptyState();
        }
        $s = json_decode((string) file_get_contents($path), true);
        if (!is_array($s)) {
            throw new \RuntimeException("the state file $path is not JSON; move it aside to start over");
        }
        return $s + self::emptyState();
    }

    public function saveState(): void
    {
        $tmp = $this->statePath . '.tmp';
        file_put_contents($tmp, json_encode($this->state, self::JSON) . "\n");
        chmod($tmp, 0600);
        rename($tmp, $this->statePath);
    }

    // ---- One stage ------------------------------------------------------------

    /** Runs one stage and writes the report. @return array the stage's record, scrubbed */
    public function run(string $stage): array
    {
        $this->record = ['stage' => $stage, 'started' => Api::now(), 'rows' => [], 'notes' => [], 'exchanges' => []];
        $before = $this->api->requests;
        try {
            if ($stage === 'cleanup') {
                $this->cleanup();
            } elseif ($stage === 'listread') {
                $this->listRead();
            } else {
                $this->fixedSet($stage);
            }
        } catch (\Throwable $e) {
            $this->record['stopped'] = $e->getMessage();
        }
        $this->record['finished'] = Api::now();
        $this->record['requests'] = $this->api->requests - $before;
        $this->state['stages'][] = $stage;
        $this->saveState();
        $scrubbed = $this->scrub($this->record);
        $this->writeReport($scrubbed);
        return $scrubbed;
    }

    private function fixedSet(string $stage): void
    {
        $started = microtime(true);
        if ($this->round >= 2 && $stage === 'restored') {
            $this->patchUntilTaken($started);
        }
        $cal = $this->send('1', 'calendarList entry', 'GET', '/users/me/calendarList/{calendar}', [], null, [], static fn(array $r) => ['accessRole' => self::field($r, 'accessRole')]);
        $this->send('2', 'the calendar', 'GET', '');
        $this->send('3', 'events, maxResults=1', 'GET', '/events', ['maxResults' => 1], null, [], static fn(array $r) => [
            'accessRole' => self::field($r, 'accessRole'),
            'sync token' => isset($r['nextSyncToken']) ? 'given' : 'none',
        ]);
        $listQuery = ['showDeleted' => 'true', 'singleEvents' => 'false', 'maxResults' => self::PAGE];
        $list = $this->send('4', 'events, showDeleted, singleEvents=false', 'GET', '/events', $listQuery, null, [], fn(array $r) => $this->listFields($r));
        if (($this->state['sync'] ?? null) !== null) {
            $from = $this->state['sync'];
            $this->send('4d', 'delta from the token saved at stage ' . $from['stage'], 'GET', '/events', $listQuery + ['syncToken' => $from['token']], null, [], fn(array $r) => $this->listFields($r) + ['tokenFrom' => $from['stage']]);
        }
        if ($list['status'] === 200 && isset($list['response']['nextSyncToken'])) {
            $this->state['sync'] = ['token' => (string) $list['response']['nextSyncToken'], 'stage' => $stage, 'at' => $list['at']];
        }
        $this->noteOwnerEvent($list);

        if ($stage === 'writer') {
            $this->preflight($cal, $list);
            $this->makeEvents();
            if ($this->round >= 2) {
                $this->makeDeletables();
            }
        }

        $insert = $this->send('5', 'insert a marked probe event', 'POST', '/events', [], $this->marked([
            'summary' => "Access probe insert ($stage)",
        ] + $this->when(12, 9)), [], static fn(array $r) => ['id' => (string) ($r['id'] ?? '-')]);
        if (self::ok($insert) && isset($insert['response']['id'])) {
            $this->state['inserted'][] = (string) $insert['response']['id'];
            $this->saveState();
        }

        $this->eventRequests($stage);
        $this->send('7', 'get an event that never existed', 'GET', '/events/' . self::MISSING_ID);
        if ($this->round >= 2) {
            if ($stage === 'writer' && $this->state['ownerEvent'] !== null) {
                $this->state['ownerEtagAtWriter'] = (string) ($this->state['ownerEvent']['etag'] ?? '');
                $this->saveState();
            }
            $this->uidLists();
            $this->insertWithTakenId();
            if (in_array($stage, ['reader', 'freebusy', 'unshared'], true)) {
                $this->deletesAt($stage);
            }
            if ($stage === 'restored') {
                $this->deletablesLeft();
            }
        }
    }

    // ---- Round 2 ----------------------------------------------------------------

    /** Stage writer: one set of deletables for each lowered stage, each read back for its current etag. */
    private function makeDeletables(): void
    {
        foreach (['reader', 'freebusy', 'unshared'] as $i => $for) {
            if (isset($this->state['deletables'][$for])) {
                $this->record['notes'][] = "The deletables for $for were made by an earlier writer run; not made again.";
                continue;
            }
            $set = [];
            $x = $this->send("M$for.1", "deletables for $for: insert a single event", 'POST', '/events', [], $this->marked(['summary' => "Access probe deletable ($for)"] + $this->when(30 + $i, 9)), [], static fn(array $r) => ['id' => (string) ($r['id'] ?? '-')]);
            if (self::ok($x) && isset($x['response']['id'])) {
                $set['single'] = ['id' => (string) $x['response']['id'], 'uid' => (string) ($x['response']['iCalUID'] ?? ''), 'etag' => null];
            }
            $y = $this->send("M$for.2", "deletables for $for: insert a weekly series of four", 'POST', '/events', [], $this->marked(['summary' => "Access probe deletable weekly ($for)", 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']] + $this->when(33 + $i, 11)), [], static fn(array $r) => ['id' => (string) ($r['id'] ?? '-')]);
            if (self::ok($y) && isset($y['response']['id'])) {
                $sid = (string) $y['response']['id'];
                $set['series'] = ['id' => $sid, 'uid' => (string) ($y['response']['iCalUID'] ?? ''), 'etag' => null, 'day' => null, 'dayEtag' => null];
                $this->state['deletables'][$for] = $set;
                $this->saveState();
                $inst = $this->send("M$for.3", "deletables for $for: the series' instances", 'GET', '/events/' . rawurlencode($sid) . '/instances');
                $day = $inst['response']['items'][1]['id'] ?? null;
                if ($day !== null) {
                    $this->send("M$for.4", "deletables for $for: rename the series' second day", 'PATCH', '/events/' . rawurlencode((string) $day), [], ['summary' => "Access probe deletable weekly ($for, one changed)"]);
                    $set['series']['day'] = (string) $day;
                }
            }
            $this->state['deletables'][$for] = $set;
            $this->saveState();
            // Each read back now, for the etag a DELETE at its stage will carry (the renamed day's record its own).
            foreach ([['single', 'id', 'etag', '5', 'the single event'], ['series', 'id', 'etag', '6', 'the series'], ['series', 'day', 'dayEtag', '7', 'the renamed day']] as [$k, $idKey, $etagKey, $n, $what]) {
                $id = $set[$k][$idKey] ?? null;
                if ($id === null) {
                    continue;
                }
                $g = $this->send("M$for.$n", "deletables for $for: get $what for its etag", 'GET', '/events/' . rawurlencode((string) $id), [], null, [], static fn(array $r) => ['etag' => isset($r['etag']) ? 'given' : 'none']);
                if (self::ok($g) && isset($g['response']['etag'])) {
                    $this->state['deletables'][$for][$k][$etagKey] = (string) $g['response']['etag'];
                }
            }
            $this->saveState();
        }
    }

    /** At a lowered stage: its deletables and the owner's own event, each DELETE conditional on the etag stored; one not refused is followed by a GET. */
    private function deletesAt(string $stage): void
    {
        $set = $this->state['deletables'][$stage] ?? null;
        $targets = [];
        if ($set !== null) {
            if (isset($set['single'])) {
                $targets[] = ['D1', "this stage's single event", (string) $set['single']['id'], $set['single']['etag'] ?? null];
            }
            if (isset($set['series']['day'])) {
                $targets[] = ['D2', "this stage's renamed day's record", (string) $set['series']['day'], $set['series']['dayEtag'] ?? null];
            }
            if (isset($set['series'])) {
                $targets[] = ['D3', "this stage's series", (string) $set['series']['id'], $set['series']['etag'] ?? null];
            }
        } else {
            $this->record['notes'][] = "No deletables for $stage in the state file (made at a writer stage of round 2), so D1 to D3 were not sent.";
        }
        if ($this->state['ownerEvent'] !== null) {
            $targets[] = ['D4', "the owner's own event (the etag stored at writer)", (string) $this->state['ownerEvent']['id'], $this->state['ownerEtagAtWriter'] ?? null];
        }
        foreach ($targets as [$n, $what, $id, $etag]) {
            $x = $this->send($n, "delete $what" . ($etag !== null && $etag !== '' ? ' (If-Match)' : ''), 'DELETE', '/events/' . rawurlencode($id), [], null, $etag !== null && $etag !== '' ? ['If-Match: ' . $etag] : []);
            if (self::ok($x)) {
                $this->record['notes'][] = "$n was not refused: $what was deleted at stage $stage.";
                $this->send("$n.get", "get $what after its delete", 'GET', '/events/' . rawurlencode($id), [], null, [], static fn(array $r) => ['status field' => self::field($r, 'status')]);
            }
        }
    }

    /** Every stage: the single event and the series the writer stage made, listed by iCalUID: count, accessRole, each item's key names. */
    private function uidLists(): void
    {
        foreach ([['U1', 'the single event', 'single'], ['U2', 'the series', 'series']] as [$n, $what, $k]) {
            $uid = (string) ($this->state[$k]['uid'] ?? '');
            if ($uid === '') {
                $this->record['notes'][] = "$n not sent: the state file has no iCalUID for $what (made by a writer stage of round 2).";
                continue;
            }
            $this->send($n, "list $what by iCalUID, showDeleted, singleEvents=false", 'GET', '/events', ['iCalUID' => $uid, 'showDeleted' => 'true', 'singleEvents' => 'false'], null, [], static function (array $r): array {
                $items = (array) ($r['items'] ?? []);
                $out = ['accessRole' => self::field($r, 'accessRole'), 'item count' => (string) count($items)];
                foreach (array_values($items) as $i => $e) {
                    $keys = array_keys((array) $e);
                    sort($keys);
                    $out['item ' . ($i + 1) . ' keys'] = implode(', ', $keys);
                }
                return $out;
            });
        }
    }

    /** Every stage: an insert under the id of the single event the writer stage made. */
    private function insertWithTakenId(): void
    {
        $id = $this->state['single']['id'] ?? null;
        if ($id === null) {
            $this->record['notes'][] = 'I not sent: no single event from a writer stage in the state file.';
            return;
        }
        $x = $this->send('I', "insert under the id of the writer stage's single event", 'POST', '/events', [], $this->marked(['id' => (string) $id, 'summary' => 'Access probe insert, a taken id'] + $this->when(13, 9)), [], static fn(array $r) => ['id' => (string) ($r['id'] ?? '-')]);
        if (self::ok($x) && isset($x['response']['id']) && (string) $x['response']['id'] !== (string) $id) {
            $this->state['inserted'][] = (string) $x['response']['id'];
            $this->saveState();
        }
    }

    /** Stage restored, first of all: PATCH the single event's title once a second until Google takes it or 180 seconds pass. */
    private function patchUntilTaken(float $started): void
    {
        $id = $this->state['single']['id'] ?? null;
        if ($id === null) {
            $this->record['notes'][] = 'R0 not sent: no single event from a writer stage in the state file.';
            return;
        }
        $sleep = $this->sleep ?? static function (float $s): void {
            usleep((int) round($s * 1_000_000));
        };
        for ($try = 1; ; $try++) {
            $x = $this->send("R0.$try", "patch the single event's title, once a second until taken", 'PATCH', '/events/' . rawurlencode((string) $id), [], ['summary' => self::SINGLE_TITLE . " (restored, try $try)"]);
            $secs = microtime(true) - $started;
            if (self::ok($x)) {
                $this->record['notes'][] = sprintf('R0: the first PATCH Google took was try %d, %.1f seconds after the stage began.', $try, $secs);
                if (isset($x['response']['etag'])) {
                    $this->state['single']['etag'] = (string) $x['response']['etag'];
                }
                return;
            }
            if ($secs >= 180) {
                $this->record['notes'][] = sprintf('R0: no PATCH was taken in %d tries over %.1f seconds.', $try, $secs);
                return;
            }
            $sleep(1.0);
        }
    }

    /** Stage restored, at the end: what of the deletables still exists, by iCalUID with showDeleted. */
    private function deletablesLeft(): void
    {
        foreach ((array) ($this->state['deletables'] ?? []) as $for => $set) {
            foreach (['single' => 'single event', 'series' => 'series'] as $k => $what) {
                $uid = (string) ($set[$k]['uid'] ?? '');
                if ($uid === '') {
                    continue;
                }
                $this->send("R9.$for.$k", "list the $for deletables' $what by iCalUID, showDeleted", 'GET', '/events', ['iCalUID' => $uid, 'showDeleted' => 'true', 'singleEvents' => 'false'], null, [], static function (array $r): array {
                    $items = (array) ($r['items'] ?? []);
                    return ['item count' => (string) count($items), 'items' => implode(', ', array_map(static fn(array $e) => (empty($e['recurringEventId']) ? 'event' : 'day') . ' ' . (string) ($e['status'] ?? '?'), $items))];
                });
            }
        }
    }

    /** Request 6: the events the writer stage made, and the owner's own event. */
    private function eventRequests(string $stage): void
    {
        if ($this->state['single'] === null && $this->state['series'] === null) {
            $this->record['notes'][] = 'No events from a writer stage in the state file, so request 6 was not sent.';
            return;
        }
        // [get #, patch #, what, state key, id key, etag key, title]
        $targets = [];
        if ($this->state['single'] !== null) {
            $targets[] = ['6a', '6d', 'single event', 'single', 'id', 'etag', self::SINGLE_TITLE];
        }
        if ($this->state['series'] !== null) {
            $targets[] = ['6b', '6e', 'series', 'series', 'id', 'etag', self::SERIES_TITLE];
            if (($this->state['series']['day'] ?? null) !== null) {
                $targets[] = ['6c', '6f', 'renamed day', 'series', 'day', 'dayEtag', self::DAY_TITLE];
            }
        }
        if ($this->state['ownerEvent'] !== null) {
            $targets[] = ['6g', '6h', "owner's own event", 'ownerEvent', 'id', 'etag', 'Owner event'];
        }
        foreach ($targets as [$n, , $what, $key, $idKey, $etagKey]) {
            $x = $this->send($n, "get the $what", 'GET', '/events/' . rawurlencode((string) $this->state[$key][$idKey]), [], null, [], static fn(array $r) => [
                'status field' => self::field($r, 'status'),
                'summary' => self::field($r, 'summary'),
                'etag' => isset($r['etag']) ? 'given' : 'none',
            ]);
            if (self::ok($x) && isset($x['response']['etag'])) {
                $this->state[$key][$etagKey] = (string) $x['response']['etag'];
            }
        }
        foreach ($targets as [$n, $patch, $what, $key, $idKey, $etagKey, $title]) {
            // Only the single event's PATCH is conditional: a 412 there says the etag moved, which is itself a finding.
            $etag = (string) ($this->state[$key][$etagKey] ?? '');
            $headers = $n === '6a' && $etag !== '' ? ['If-Match: ' . $etag] : [];
            $x = $this->send($patch, "patch the $what's title" . ($headers !== [] ? ' (If-Match)' : ''), 'PATCH', '/events/' . rawurlencode((string) $this->state[$key][$idKey]), [], ['summary' => "$title ($stage)"], $headers);
            if (self::ok($x) && isset($x['response']['etag'])) {
                $this->state[$key][$etagKey] = (string) $x['response']['etag'];
            }
        }
        $this->saveState();
    }

    /** Stage writer only: refuse a calendar the account owns, one shared without edit rights, one it has not listed, or one with other events. */
    private function preflight(array $cal, array $list): void
    {
        if ($cal['status'] !== 200) {
            throw new \RuntimeException("the calendar isn't in the connected account's list (HTTP {$cal['status']}). Add it from the sharing mail in that account, then run stage writer again; nothing was written");
        }
        $role = (string) ($cal['response']['accessRole'] ?? '');
        if ($role === 'owner') {
            throw new \RuntimeException('the connected account owns this calendar; the access probe needs one made in another account and shared with it. Nothing was written');
        }
        if ($role !== 'writer') {
            throw new \RuntimeException("stage writer needs the calendar shared with \"Make changes to events\"; Google says accessRole \"$role\". Nothing was written");
        }
        if ($list['status'] !== 200) {
            throw new \RuntimeException("the event list answered HTTP {$list['status']}; nothing was written");
        }
        $others = array_filter((array) ($list['response']['items'] ?? []), static fn(array $e) => !Probe::isMarked($e) && ($e['status'] ?? '') !== 'cancelled');
        if (count($others) > 1 && !$this->allowOtherEvents) {
            throw new \RuntimeException(count($others) . ' events the probe did not make are on this calendar (one, the owner\'s own, is expected). Use a new scratch calendar, or --allow-other-events; nothing was written');
        }
    }

    /** Stage writer: the single event and the weekly series of four with its second day renamed, unless an earlier writer run made them. */
    private function makeEvents(): void
    {
        if ($this->state['single'] === null) {
            $x = $this->send('S1', 'insert the single event', 'POST', '/events', [], $this->marked(['summary' => self::SINGLE_TITLE] + $this->when(10, 15)), [], static fn(array $r) => ['id' => (string) ($r['id'] ?? '-')]);
            if (self::ok($x) && isset($x['response']['id'])) {
                $this->state['single'] = ['id' => (string) $x['response']['id'], 'etag' => (string) ($x['response']['etag'] ?? ''), 'uid' => (string) ($x['response']['iCalUID'] ?? '')];
                $this->saveState();
            }
        } else {
            $this->record['notes'][] = 'The single event was made by an earlier writer run; not made again.';
        }
        if ($this->state['series'] === null) {
            $x = $this->send('S2', 'insert the weekly series of four', 'POST', '/events', [], $this->marked(['summary' => self::SERIES_TITLE, 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']] + $this->when(14, 10)), [], static fn(array $r) => ['id' => (string) ($r['id'] ?? '-')]);
            if (!self::ok($x) || !isset($x['response']['id'])) {
                return;
            }
            $id = (string) $x['response']['id'];
            $this->state['series'] = ['id' => $id, 'etag' => (string) ($x['response']['etag'] ?? ''), 'day' => null, 'dayEtag' => null, 'uid' => (string) ($x['response']['iCalUID'] ?? '')];
            $this->saveState();
            $inst = $this->send('S3', 'list the series\' instances', 'GET', '/events/' . rawurlencode($id) . '/instances', [], null, [], static fn(array $r) => ['items' => (string) count((array) ($r['items'] ?? []))]);
            $day = $inst['response']['items'][1]['id'] ?? null;
            if ($day === null) {
                throw new \RuntimeException('the series has no second instance to rename');
            }
            $x = $this->send('S4', 'rename the series\' second day', 'PATCH', '/events/' . rawurlencode((string) $day), [], ['summary' => self::DAY_TITLE]);
            $this->state['series']['day'] = (string) $day;
            $this->state['series']['dayEtag'] = self::ok($x) ? (string) ($x['response']['etag'] ?? '') : null;
            $this->saveState();
        } else {
            $this->record['notes'][] = 'The series was made by an earlier writer run; not made again.';
        }
    }

    /** Stage cleanup: delete what the state lists, keep what could not be deleted listed. */
    private function cleanup(): void
    {
        $ids = [];
        if ($this->state['single'] !== null) {
            $ids['single event'] = (string) $this->state['single']['id'];
        }
        if ($this->state['series'] !== null) {
            $ids['series'] = (string) $this->state['series']['id'];
        }
        foreach ($this->state['inserted'] as $i => $id) {
            $ids['inserted event ' . ($i + 1)] = (string) $id;
        }
        foreach ((array) ($this->state['deletables'] ?? []) as $for => $set) {
            foreach (['single' => 'single event', 'series' => 'series'] as $k => $what) {
                if (isset($set[$k]['id'])) {
                    $ids["$for deletables' $what"] = (string) $set[$k]['id'];
                }
            }
        }
        if ($ids === []) {
            $this->record['notes'][] = 'The state file lists nothing to delete.';
        }
        $n = 0;
        $left = [];
        foreach ($ids as $what => $id) {
            $x = $this->send('C' . ++$n, "delete the $what", 'DELETE', '/events/' . rawurlencode($id));
            // 410: already deleted; either way nothing of it is left to delete.
            if (!self::ok($x) && $x['status'] !== 410) {
                $left[] = "$what (HTTP {$x['status']}" . (self::reason($x) !== '' ? ', ' . self::reason($x) : '') . ')';
                continue;
            }
            if ($what === 'single event') {
                $this->state['single'] = null;
            } elseif ($what === 'series') {
                $this->state['series'] = null;
            } elseif (str_contains($what, 'deletables')) {
                foreach ((array) ($this->state['deletables'] ?? []) as $for => $set) {
                    foreach (['single', 'series'] as $k) {
                        if ((string) ($set[$k]['id'] ?? '') === $id) {
                            unset($this->state['deletables'][$for][$k]);
                        }
                    }
                    if (($this->state['deletables'][$for] ?? []) === []) {
                        unset($this->state['deletables'][$for]);
                    }
                }
            } else {
                $this->state['inserted'] = array_values(array_diff($this->state['inserted'], [$id]));
            }
            $this->saveState();
        }
        if ($left !== []) {
            $this->record['notes'][] = 'Could not be deleted, still listed in the state file: ' . implode('; ', $left) . '.';
        } elseif ($ids !== []) {
            $this->record['notes'][] = 'Everything the state file listed is deleted.';
        }
        if ($this->state['ownerEvent'] !== null) {
            $this->record['notes'][] = "The owner's own event is left as it is.";
        }
    }

    // ---- Stage listread ---------------------------------------------------------

    /** An iCalUID no event has ever had. */
    public const MISSING_UID = 'accessprobe-never-existed@access-probe.invalid';

    /** @var list<string> events this listread run made and has not yet deleted */
    private array $made = [];

    /**
     * Stage listread: does a list by iCalUID return the same resource a GET by
     * id does, in each case the app meets? Runs on any calendar the account
     * may write (owner or writer), touches only its own marked events, and
     * deletes them at the end, also after an error. Leaves the other stages'
     * state (events, sync token, owner's event) alone.
     */
    private function listRead(): void
    {
        // What an earlier listread run left when its process died.
        foreach (array_values($this->state['listreadMade']) as $i => $id) {
            $this->send('L0.' . ($i + 1), 'delete what an earlier listread left', 'DELETE', '/events/' . rawurlencode((string) $id));
        }
        $this->state['listreadMade'] = [];
        $this->saveState();

        $cal = $this->send('L', 'calendarList entry', 'GET', '/users/me/calendarList/{calendar}', [], null, [], static fn(array $r) => ['accessRole' => self::field($r, 'accessRole')]);
        $role = (string) ($cal['response']['accessRole'] ?? '');
        if ($cal['status'] !== 200 || !in_array($role, ['owner', 'writer'], true)) {
            throw new \RuntimeException("listread needs a calendar the account may write; the list entry answered HTTP {$cal['status']}" . ($role !== '' ? ", accessRole \"$role\"" : '') . '. Nothing was written');
        }
        try {
            $cases = [
                'L1' => fn() => $this->lrSingle('L1', 'timed single, inserted', $this->when(20, 9)),
                'L2' => fn() => $this->lrSingle('L2', 'all-day single, inserted', $this->allDay(21)),
                'L3' => fn() => $this->lrImport(),
                'L5' => fn() => $this->lrDeleted(),
                'L6' => fn() => $this->lrPatched(),
                'L7' => fn() => $this->lrSeries(),
                'L8' => fn() => $this->lrMissing(),
            ];
            foreach ($cases as $n => $case) {
                try {
                    $case();
                } catch (\Throwable $e) {
                    $this->record['notes'][] = "$n stopped: " . $e->getMessage();
                }
            }
        } finally {
            $left = [];
            foreach ($this->made as $i => $id) {
                try {
                    $x = $this->send('LC.' . ($i + 1), 'delete what the stage made', 'DELETE', '/events/' . rawurlencode($id));
                    if (self::ok($x) || $x['status'] === 410) {
                        $this->forget($id);
                        continue;
                    }
                    $left[] = "$id (HTTP {$x['status']})";
                } catch (\Throwable $e) {
                    $left[] = "$id ({$e->getMessage()})";
                }
            }
            $this->record['notes'][] = $left === [] ? 'Everything the stage made is deleted.' : 'Not deleted (listed in the state file for the next listread run): ' . implode('; ', $left) . '.';
        }
    }

    private function remember(array $x): ?string
    {
        if (!self::ok($x) || !isset($x['response']['id'])) {
            return null;
        }
        $id = (string) $x['response']['id'];
        $this->made[] = $id;
        $this->state['listreadMade'][] = $id;
        $this->saveState();
        return $id;
    }

    private function forget(string $id): void
    {
        $this->made = array_values(array_diff($this->made, [$id]));
        $this->state['listreadMade'] = array_values(array_diff($this->state['listreadMade'], [$id]));
        $this->saveState();
    }

    /** 1 and 2: insert the ordinary way, then GET against both lists. */
    private function lrSingle(string $n, string $what, array $when): void
    {
        $x = $this->send("$n.insert", "$what: insert", 'POST', '/events', [], $this->marked(['summary' => "Access probe $what"] + $when), [], static fn(array $r) => ['iCalUID' => self::field($r, 'iCalUID')]);
        $id = $this->remember($x);
        if ($id === null) {
            return;
        }
        $this->getAgainstLists($n, $what, $id, (string) ($x['response']['iCalUID'] ?? ''));
    }

    /** 3 and 4: import with an iCalUID of our own, as Move to Google does (GoogleWriter::importBody: the event body plus iCalUID), then a second import with the same iCalUID and another start. */
    private function lrImport(): void
    {
        if ($this->skipImport) {
            $this->record['notes'][] = 'L3 and L4 (events.import) skipped: the stand-in has no import route.';
            return;
        }
        $uid = bin2hex(random_bytes(8)) . '@access-probe.invalid';
        $x = $this->send('L3.import', 'import with our iCalUID', 'POST', '/events/import', [], $this->marked(['summary' => 'Access probe imported', 'iCalUID' => $uid] + $this->when(22, 9)), [], static fn(array $r) => ['iCalUID' => self::field($r, 'iCalUID'), 'id' => self::field($r, 'id')]);
        $first = $this->remember($x);
        if ($first === null) {
            return;
        }
        $this->getAgainstLists('L3', 'imported single', $first, $uid);

        $y = $this->send('L4.import', 'second import, same iCalUID, another start', 'POST', '/events/import', [], $this->marked(['summary' => 'Access probe imported twice', 'iCalUID' => $uid] + $this->when(23, 9)), [], static fn(array $r) => ['iCalUID' => self::field($r, 'iCalUID'), 'id' => self::field($r, 'id')]);
        $second = $this->remember($y);
        $names = [$first => 'first import'] + ($second !== null && $second !== $first ? [$second => 'second import'] : []);
        if ($second !== null) {
            $this->record['notes'][] = 'L4: the second import ' . ($second === $first ? 'answered with the first import\'s id (it updated that event).' : 'made a second event.');
            $this->send('L4.get', 'get the second import by id', 'GET', '/events/' . rawurlencode($second), [], null, [], static fn(array $r) => ['summary' => self::field($r, 'summary'), 'start' => json_encode($r['start'] ?? null)]);
        }
        foreach (['true', 'false'] as $deleted) {
            $this->send("L4.list-$deleted", "list by the shared iCalUID, showDeleted=$deleted", 'GET', '/events', ['iCalUID' => $uid, 'showDeleted' => $deleted, 'singleEvents' => 'false'], null, [], fn(array $r) => $this->lrListFields($r) + [
                'order' => implode(', ', array_map(static fn(array $e) => $names[(string) ($e['id'] ?? '')] ?? (string) ($e['id'] ?? '?'), (array) ($r['items'] ?? []))),
            ]);
        }
    }

    /** 5: insert, delete, then the GET (status and its status field) against both lists. */
    private function lrDeleted(): void
    {
        $x = $this->send('L5.insert', 'single to delete: insert', 'POST', '/events', [], $this->marked(['summary' => 'Access probe deleted'] + $this->when(24, 9)));
        $id = $this->remember($x);
        if ($id === null) {
            return;
        }
        $d = $this->send('L5.delete', 'delete it', 'DELETE', '/events/' . rawurlencode($id));
        if (self::ok($d)) {
            $this->forget($id);
        }
        $this->getAgainstLists('L5', 'deleted single', $id, (string) ($x['response']['iCalUID'] ?? ''));
    }

    /** 6: insert, PATCH the title; does the list's etag equal the PATCH answer's, and does If-Match on the list's etag pass? */
    private function lrPatched(): void
    {
        $x = $this->send('L6.insert', 'single to patch: insert', 'POST', '/events', [], $this->marked(['summary' => 'Access probe patched'] + $this->when(25, 9)));
        $id = $this->remember($x);
        if ($id === null) {
            return;
        }
        $uid = (string) ($x['response']['iCalUID'] ?? '');
        $p = $this->send('L6.patch', 'patch the title', 'PATCH', '/events/' . rawurlencode($id), [], ['summary' => 'Access probe patched (1)'], [], static fn(array $r) => ['etag' => self::field($r, 'etag')]);
        $patchEtag = (string) ($p['response']['etag'] ?? '');
        $listEtag = '';
        $this->send('L6.list', 'list by iCalUID, showDeleted=false', 'GET', '/events', ['iCalUID' => $uid, 'showDeleted' => 'false', 'singleEvents' => 'false'], null, [], function (array $r) use ($id, $patchEtag, &$listEtag): array {
            $item = self::itemById($r, $id);
            $listEtag = (string) ($item['etag'] ?? '');
            return $this->lrListFields($r) + ['etag (PATCH answer)' => $patchEtag, 'etag (list)' => $listEtag !== '' ? $listEtag : '(absent)', 'equal' => $listEtag !== '' && $listEtag === $patchEtag ? 'yes' : 'no'];
        });
        if ($listEtag !== '') {
            $this->send('L6.patch-if-match', "patch the title with If-Match on the list's etag", 'PATCH', '/events/' . rawurlencode($id), [], ['summary' => 'Access probe patched (2)'], ['If-Match: ' . $listEtag], static fn(array $r) => ['etag' => self::field($r, 'etag')]);
        }
    }

    /** 7: a weekly series of three with one day renamed: the list by the series' iCalUID against a GET of the master and of the day. */
    private function lrSeries(): void
    {
        $x = $this->send('L7.insert', 'weekly series of three: insert', 'POST', '/events', [], $this->marked(['summary' => 'Access probe listread weekly', 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=3']] + $this->when(26, 9)));
        $id = $this->remember($x);
        if ($id === null) {
            return;
        }
        $uid = (string) ($x['response']['iCalUID'] ?? '');
        $inst = $this->send('L7.instances', 'list its instances', 'GET', '/events/' . rawurlencode($id) . '/instances');
        $day = $inst['response']['items'][1]['id'] ?? null;
        if ($day === null) {
            throw new \RuntimeException('the series has no second instance to rename');
        }
        $day = (string) $day;
        $this->send('L7.rename', 'rename its second day', 'PATCH', '/events/' . rawurlencode($day), [], ['summary' => 'Access probe listread weekly (one changed)']);
        $lists = [];
        foreach (['true', 'false'] as $deleted) {
            $l = $this->send("L7.list-$deleted", "list by the series' iCalUID, showDeleted=$deleted", 'GET', '/events', ['iCalUID' => $uid, 'showDeleted' => $deleted, 'singleEvents' => 'false'], null, [], fn(array $r) => $this->lrListFields($r) + [
                'items' => implode(', ', array_map(static fn(array $e) => ((string) ($e['id'] ?? '?') === $id ? 'master' : ((string) ($e['id'] ?? '') === $day ? 'renamed day' : (string) ($e['id'] ?? '?'))), (array) ($r['items'] ?? []))),
            ]);
            $lists[$deleted] = $l;
        }
        foreach (['master' => $id, 'renamed day' => $day] as $what => $eid) {
            $g = $this->api->call('GET', '/events/' . rawurlencode($eid));
            $this->addRow("L7.get-" . ($what === 'master' ? 'master' : 'day'), "get the $what by id", $g, $this->compareFields($g, $lists['true'], $eid) + ['against showDeleted=false' => $this->compareFields($g, $lists['false'], $eid)['differs'] ?? '-']);
        }
    }

    /** 8: an id and an iCalUID that never existed. */
    private function lrMissing(): void
    {
        $this->send('L8.get', 'get an id that never existed', 'GET', '/events/' . self::MISSING_ID);
        $this->send('L8.list', 'list by an iCalUID that never existed', 'GET', '/events', ['iCalUID' => self::MISSING_UID, 'showDeleted' => 'true', 'singleEvents' => 'false'], null, [], fn(array $r) => $this->lrListFields($r));
    }

    /** A GET by id, then both lists by iCalUID, each list compared field by field with the GET's answer. */
    private function getAgainstLists(string $n, string $what, string $id, string $uid): void
    {
        $g = $this->api->call('GET', '/events/' . rawurlencode($id));
        $lists = [];
        foreach (['true', 'false'] as $deleted) {
            $lists[$deleted] = $this->api->call('GET', '/events', ['iCalUID' => $uid, 'showDeleted' => $deleted, 'singleEvents' => 'false']);
        }
        $this->addRow("$n.get", "$what: get by id", $g, self::ok($g) ? ['status field' => self::field($g['response'], 'status'), 'etag' => self::field($g['response'], 'etag')] : []);
        foreach ($lists as $deleted => $l) {
            $this->addRow("$n.list-$deleted", "$what: list by iCalUID, showDeleted=$deleted", $l, self::ok($l) ? $this->lrListFields($l['response']) + $this->compareFields($g, $l, $id) : []);
        }
    }

    private function lrListFields(array $r): array
    {
        return ['accessRole' => self::field($r, 'accessRole'), 'item count' => (string) count((array) ($r['items'] ?? []))];
    }

    private static function itemById(array $list, string $id): ?array
    {
        foreach ((array) ($list['items'] ?? []) as $e) {
            if ((string) ($e['id'] ?? '') === $id) {
                return $e;
            }
        }
        return null;
    }

    /** Every field that differs or is missing on one side, between a GET's answer and the list item with the same id; both etags. */
    private function compareFields(array $get, array $list, string $id): array
    {
        $item = is_array($list['response']) ? self::itemById($list['response'], $id) : null;
        $res = is_array($get['response']) && self::ok($get) ? $get['response'] : null;
        $out = ['etag (GET)' => $res !== null ? self::field($res, 'etag') : "(GET {$get['status']})", 'etag (list)' => $item !== null ? self::field($item, 'etag') : '(not listed)'];
        if ($item === null || $res === null) {
            $out['differs'] = $item === null ? 'the list holds no item with this id' : 'the GET returned no event';
            return $out;
        }
        $diffs = [];
        foreach (array_unique(array_merge(array_keys($res), array_keys($item))) as $k) {
            if ($k === 'etag') {
                continue;
            }
            if (!array_key_exists($k, $item)) {
                $diffs[] = "$k: missing in the list";
            } elseif (!array_key_exists($k, $res)) {
                $diffs[] = "$k: missing in the GET";
            } elseif (self::canon($res[$k]) !== self::canon($item[$k])) {
                $diffs[] = "$k: GET " . mb_strimwidth(self::canon($res[$k]), 0, 80, '...') . ' / list ' . mb_strimwidth(self::canon($item[$k]), 0, 80, '...');
            }
        }
        $out['differs'] = $diffs === [] ? 'none' : implode('; ', $diffs);
        return $out;
    }

    private static function canon(mixed $v): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v);
            }
            return array_map($sort, $v);
        };
        return (string) json_encode($sort($v), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function allDay(int $days): array
    {
        $d = ($this->today ?? new \DateTimeImmutable('now', new \DateTimeZone(self::ZONE)))->setTimezone(new \DateTimeZone(self::ZONE))->modify("+$days days");
        return ['start' => ['date' => $d->format('Y-m-d')], 'end' => ['date' => $d->modify('+1 day')->format('Y-m-d')]];
    }

    // ---- Requests -------------------------------------------------------------

    /** A row for an exchange already sent, with fields computed by the caller. */
    private function addRow(string $n, string $label, array $x, array $fields): void
    {
        $this->record['rows'][] = ['n' => $n, 'request' => $label, 'method' => $x['method'], 'path' => $x['path'], 'status' => $x['status'], 'reason' => self::reason($x), 'message' => self::message($x), 'fields' => $fields];
        $this->record['exchanges'][] = ['n' => $n] + $x;
    }

    /** One request, recorded as a row: status, reason, message, and the named fields of a 2xx answer. */
    private function send(string $n, string $label, string $method, string $path, array $query = [], ?array $body = null, array $headers = [], ?\Closure $fields = null): array
    {
        $x = $this->api->call($method, $path, $query, $body, $headers);
        $resp = is_array($x['response']) ? $x['response'] : [];
        $this->record['rows'][] = [
            'n' => $n,
            'request' => $label,
            'method' => $method,
            'path' => $x['path'],
            'status' => $x['status'],
            'reason' => self::reason($x),
            'message' => self::message($x),
            'fields' => self::ok($x) && $fields !== null ? $fields($resp) : [],
        ];
        $this->record['exchanges'][] = ['n' => $n] + $x;
        return $x;
    }

    private function listFields(array $r): array
    {
        $items = (array) ($r['items'] ?? []);
        return [
            'accessRole' => self::field($r, 'accessRole'),
            'items' => (string) count($items),
            'not the probe\'s' => (string) count(array_filter($items, static fn(array $e) => !Probe::isMarked($e))),
            'sync token' => isset($r['nextSyncToken']) ? 'given' : 'none',
            'more pages' => isset($r['nextPageToken']) ? 'given' : 'none',
        ] + (($own = array_values(array_filter($items, static fn(array $e) => Probe::isMarked($e)))[0] ?? null) !== null
            ? ['first probe item keys' => implode(', ', (static function (array $k): array {
                sort($k);
                return $k;
            })(array_keys($own)))] : ($this->round >= 2 && $items !== [] ? ['first probe item keys' => '(none of the items carries the probe\'s marker)'] : []));
    }

    /** At the first stage that lists it, the one event on the calendar the probe did not make (the owner's own) is kept in the state. */
    private function noteOwnerEvent(array $list): void
    {
        if ($this->state['ownerEvent'] !== null || $list['status'] !== 200) {
            return;
        }
        $others = array_values(array_filter((array) ($list['response']['items'] ?? []), static fn(array $e) => !Probe::isMarked($e) && ($e['status'] ?? '') !== 'cancelled' && empty($e['recurringEventId'])));
        if (count($others) === 1 && isset($others[0]['id'])) {
            $this->state['ownerEvent'] = ['id' => (string) $others[0]['id'], 'etag' => (string) ($others[0]['etag'] ?? '')];
            $this->saveState();
        }
    }

    private function marked(array $body): array
    {
        $body['description'] = 'Made by the access probe. ' . Probe::MARKER_TEXT;
        $body['extendedProperties']['private'][Probe::MARKER_KEY] = 'access';
        return $body;
    }

    /** A one-hour start and end $days from today at $hour in Los Angeles. */
    private function when(int $days, int $hour): array
    {
        $day = ($this->today ?? new \DateTimeImmutable('now', new \DateTimeZone(self::ZONE)))->setTimezone(new \DateTimeZone(self::ZONE));
        $s = $day->setTime($hour, 0)->modify("+$days days");
        return [
            'start' => ['dateTime' => $s->format('Y-m-d\TH:i:sP'), 'timeZone' => self::ZONE],
            'end' => ['dateTime' => $s->modify('+1 hour')->format('Y-m-d\TH:i:sP'), 'timeZone' => self::ZONE],
        ];
    }

    private static function ok(array $x): bool
    {
        return $x['status'] >= 200 && $x['status'] < 300;
    }

    private static function field(array $r, string $key): string
    {
        return array_key_exists($key, $r) ? (string) (is_scalar($r[$key]) ? $r[$key] : json_encode($r[$key])) : '(absent)';
    }

    private static function reason(array $x): string
    {
        $e = is_array($x['response']) ? ($x['response']['error'] ?? null) : null;
        if (!is_array($e)) {
            return '';
        }
        $reasons = array_values(array_filter(array_map(static fn($i) => is_array($i) ? (string) ($i['reason'] ?? '') : '', (array) ($e['errors'] ?? []))));
        return implode(', ', $reasons) . (isset($e['status']) ? ($reasons !== [] ? ' / ' : '') . $e['status'] : '');
    }

    private static function message(array $x): string
    {
        $e = is_array($x['response']) ? ($x['response']['error'] ?? null) : null;
        return is_array($e) ? (string) ($e['message'] ?? '') : '';
    }

    // ---- Report ---------------------------------------------------------------

    /** Report::scrub (the account, the calendar id, links, names, tokens), then any other address left. */
    private function scrub(array $v): array
    {
        $v = $this->scrubber->scrub($v);
        array_walk_recursive($v, static function (&$s): void {
            if (is_string($s)) {
                $s = (string) preg_replace('/[A-Za-z0-9._%+-]+@(?!google\.com\b|access-probe\.invalid\b)[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '{another address}', $s);
            }
        });
        return $v;
    }

    private function writeReport(array $stage): void
    {
        $all = is_file($this->jsonPath) ? json_decode((string) file_get_contents($this->jsonPath), true) : null;
        $all = is_array($all) ? $all : ['stages' => []];
        $all['stages'][] = $stage;
        file_put_contents($this->jsonPath, json_encode($all, self::JSON) . "\n");
        file_put_contents($this->mdPath, self::markdown($all['stages']));
    }

    public static function markdown(array $stages): string
    {
        $md = "# Google Access Probe\n\n";
        $md .= "What Google answered the connected account at each stage of a shared calendar's access being lowered and taken away, one table per run, in the order run. The calendar is {calendar}; addresses, links and sync tokens are replaced by placeholders here and in the JSON beside this file, which has every exchange raw.\n\n";
        $md .= "## Across Stages\n\nStatus of each request by stage (accessRole where the answer has one).\n\n";
        $ns = [];
        foreach ($stages as $s) {
            foreach ($s['rows'] as $r) {
                $ns[$r['n']] ??= $r['request'];
            }
        }
        $md .= '| Request | ' . implode(' | ', array_map(static fn(array $s) => self::cell($s['stage']), $stages)) . " |\n";
        $md .= '|---|' . str_repeat('---|', count($stages)) . "\n";
        foreach ($ns as $n => $label) {
            $md .= '| ' . self::cell("$n $label") . ' |';
            foreach ($stages as $s) {
                $row = array_values(array_filter($s['rows'], static fn(array $r) => (string) $r['n'] === (string) $n))[0] ?? null;
                $md .= ' ' . ($row === null ? '' : self::cell($row['status'] . (isset($row['fields']['accessRole']) ? ' ' . $row['fields']['accessRole'] : ''))) . ' |';
            }
            $md .= "\n";
        }
        $md .= "\n";
        foreach ($stages as $s) {
            $md .= "## Stage {$s['stage']}\n\nRun {$s['started']} to {$s['finished']}, {$s['requests']} requests.\n\n";
            if (isset($s['stopped'])) {
                $md .= '**Stopped:** ' . self::cell($s['stopped']) . "\n\n";
            }
            foreach ($s['notes'] as $note) {
                $md .= '- ' . self::cell($note) . "\n";
            }
            if ($s['notes'] !== []) {
                $md .= "\n";
            }
            $md .= "| # | Request | Sent | Status | Reason | Message | Fields |\n|---|---|---|---|---|---|---|\n";
            foreach ($s['rows'] as $r) {
                $fields = implode('; ', array_map(static fn($k, $v) => "$k: $v", array_keys($r['fields']), $r['fields']));
                $md .= '| ' . implode(' | ', array_map([self::class, 'cell'], [$r['n'], $r['request'], $r['method'] . ' ' . $r['path'], (string) $r['status'], $r['reason'], $r['message'], $fields])) . " |\n";
            }
            $md .= "\n";
        }
        $md .= "## What a Client Can Rely On\n\nTo be filled in by hand from the tables above. Each answer names the stage and request it rests on.\n\n";
        foreach ([
            'Which request tells "may read but not write" from "may not see"? (Compare 1, 2 and 3 at reader and freebusy against unshared.)',
            'Which request tells "may not see" from "calendar deleted"? (Compare 1, 2 and 3 at unshared against deleted.) If none does, say so.',
            'Which request tells "calendar gone or unseen" from "one event missing"? (Compare 2 with 7, and 6a to 6c, at each stage.)',
            'Does accessRole appear on a plain list answer (3, 4) in each state? Its value in each?',
            'Does accessRole appear on a delta answer (4d) in each state? Does a token from a stage with more access still work, or is it refused (410 or another status)?',
            'Is a write refused with 403 when view-only and busy-only? With which reason and message? Is it the same for the insert (5), the single event (6d), the series (6e), the renamed day (6f) and the owner\'s own event (6h)?',
            'Do reads of events (6a to 6c, 6g; 3 and 4) still work when view-only? When busy-only, and what do they hold (titles, or start and end alone)?',
            'At restored: is everything back as at writer (same ids, same answers), and did the calendar need to be added to the account\'s list again?',
        ] as $q) {
            $md .= "- $q\n  - Answer: \n";
        }
        return $md;
    }

    private static function cell(string $s): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', '', ' '], mb_strimwidth($s, 0, 200, '...'));
    }
}
