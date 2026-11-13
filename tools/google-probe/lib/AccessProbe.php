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
        return ['single' => null, 'series' => null, 'inserted' => [], 'ownerEvent' => null, 'sync' => null, 'stages' => []];
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
                $this->state['single'] = ['id' => (string) $x['response']['id'], 'etag' => (string) ($x['response']['etag'] ?? '')];
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
            $this->state['series'] = ['id' => $id, 'etag' => (string) ($x['response']['etag'] ?? ''), 'day' => null, 'dayEtag' => null];
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

    // ---- Requests -------------------------------------------------------------

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
        ];
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
                $s = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '{another address}', $s);
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
