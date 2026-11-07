<?php
// The probe itself (docs/design/google-series-survey.md, "The Probe"): nine
// groups of cases against one throwaway calendar. Each case makes its own
// starting state, reads it back, writes, reads back again (the master,
// events.instances, a singleEvents=false list of the series and a
// sync-token delta from a token taken before the write), then runs a
// second delta to see whether anything still moves. Findings are written
// only from what the read-back shows. Every event the probe makes carries
// a marker (a private extended property and a line in its description);
// each case deletes what it made, and a run starts and ends by deleting
// anything marked that an earlier, aborted run left.
declare(strict_types=1);

namespace BetterCal\GoogleProbe;

use BetterCal\Domain\GoogleWriter;
use BetterCal\Support\Time;

final class Probe
{
    public const MARKER_KEY = 'betterCalProbe';
    public const MARKER_TEXT = '[better-cal-probe]';
    private const LA = 'America/Los_Angeles';
    /** Read-backs retried on a 404 or an unchanged delta, with backoff. */
    private const READ_TRIES = 6;
    /** Cases in a row that may fail before the run stops. */
    private const FAILURES_IN_A_ROW = 3;

    public const GROUPS = [
        '1' => ['Rule forms', 'Each rule inserted alone, then read back: accepted or refused (with the error), and the exact text that comes back.'],
        '2' => ['Each exception kind alone', 'On a timed weekly series and on an all-day one: a moved instance, one with its own time, a title-only edit, a cancellation by DELETE and by PATCH, and one beyond a shortened end; then the exception-key checks in Pacific/Auckland and Asia/Tokyo. After each: the instance id form, the master\'s recurrence, its etag and `updated`, and the delta.'],
        '3' => ['Master changes over exceptions', 'One master change per case, each from the same starting state: a weekly 10:00 America/Los_Angeles series of ten with occurrence 3 moved a day later, occurrence 4 at its own time (15:00), occurrence 5 with its own title and occurrence 6 cancelled (DELETE).'],
        '4' => ['The split', 'Trim with UNTIL and with COUNT, then insert the new series, over the same starting state as group 3, cut at occurrence 5.'],
        '5' => ['Etags and conditional writes', 'Stability across reads, what a master or an instance write does to the other\'s etags, and If-Match on PATCH and DELETE of masters and instances.'],
        '6' => ['Deletes and their reverse', 'A deleted event read back, PATCH to confirmed on it and on a cancelled instance, and an insert with an id the client chose.'],
        '7' => ['Sync', 'The echo of a write in the next delta, cancelled instances after a master change, an unbounded series under singleEvents=true, and the occurrence cap.'],
        '8' => ['Mail', 'Writes to a series with a guest, with sendUpdates=none and once with it left out, to be checked against the guest\'s mailbox by hand.'],
        '9' => ['Two live checks from the audit of closed issues', 'Manual: they run through the app, not the API.'],
    ];

    private string $runId;
    private string $calendarZone = 'UTC';
    private ?string $syncToken = null;
    /** @var array<string,true> ids made this run and not yet deleted */
    private array $created = [];
    private int $failuresInARow = 0;

    /**
     * @param array{only?:?list<string>, guest?:?string, allowOtherEvents?:bool} $opts
     */
    public function __construct(
        private readonly Api $api,
        private readonly Report $report,
        private readonly \Closure $sleep,
        private readonly array $opts = [],
    ) {
        $this->runId = bin2hex(random_bytes(6));
    }

    public function run(): void
    {
        $groups = $this->opts['only'] ?? array_keys(self::GROUPS);
        $groups = array_values(array_filter(array_map('strval', array_keys(self::GROUPS)), static fn(string $g) => in_array($g, array_map('strval', $groups), true)));
        foreach ($groups as $g) {
            $this->report->group($g, self::GROUPS[$g][0], self::GROUPS[$g][1]);
        }
        $this->report->run = ['started' => Api::now(), 'groups' => $groups, 'runMarker' => $this->runId, 'notes' => []];
        try {
            $this->preflight();
        } catch (\Throwable $e) {
            $this->report->run['refused'] = $e->getMessage();
            $this->report->run['notes'][] = 'Refused before any write: ' . $e->getMessage();
            $this->report->write();
            throw $e;
        }
        $left = $this->sweep();
        $this->report->run['notes'][] = $left === 0 ? 'No marked events from an earlier run were found.' : "Deleted $left marked events an earlier run left.";
        $this->syncToken = $this->fullSyncToken();
        $this->report->write();
        $failure = null;
        try {
            foreach ($groups as $g) {
                $this->{'group' . $g}();
            }
        } catch (\Throwable $e) {
            $failure = $e;
            $this->report->run['notes'][] = 'The run stopped: ' . $e->getMessage();
        } finally {
            $this->finish();
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    // ---- Setup and teardown -------------------------------------------------

    private function preflight(): void
    {
        $entry = $this->api->call('GET', '/users/me/calendarList/{calendar}');
        if ($entry['status'] !== 200 || !is_array($entry['response'])) {
            throw new \RuntimeException('the account cannot see that calendar (HTTP ' . $entry['status'] . ')');
        }
        if (!empty($entry['response']['primary'])) {
            throw new \RuntimeException('that is the account\'s main calendar; the probe needs a throwaway one');
        }
        if (!in_array((string) ($entry['response']['accessRole'] ?? ''), ['writer', 'owner'], true)) {
            throw new \RuntimeException('the account may not write to that calendar');
        }
        $cal = $this->api->call('GET', '');
        $this->calendarZone = (string) (is_array($cal['response']) ? ($cal['response']['timeZone'] ?? 'UTC') : 'UTC');
        $this->report->run['calendarZone'] = $this->calendarZone;
        $others = array_filter($this->listAll(['showDeleted' => 'false', 'singleEvents' => 'false'])['items'], fn(array $e) => !self::isMarked($e));
        if ($others !== [] && empty($this->opts['allowOtherEvents'])) {
            throw new \RuntimeException('that calendar has ' . count($others) . ' events the probe did not make; use an empty calendar, or pass --allow-other-events (they are never touched)');
        }
        if ($others !== []) {
            $this->report->run['notes'][] = count($others) . ' events the probe did not make were on the calendar; they were left alone.';
        }
    }

    /** Delete every marked event on the calendar; returns how many. */
    private function sweep(): int
    {
        $n = 0;
        foreach ($this->listAll(['showDeleted' => 'false', 'singleEvents' => 'false'])['items'] as $e) {
            // An exception goes with its series; only masters and single events are deleted.
            if (self::isMarked($e) && empty($e['recurringEventId'])) {
                $r = $this->api->call('DELETE', '/events/' . rawurlencode((string) $e['id']));
                $n += in_array($r['status'], [200, 204, 410], true) ? 1 : 0;
            }
        }
        return $n;
    }

    private function finish(): void
    {
        $deleted = 0;
        $failed = 0;
        foreach (array_keys($this->created) as $id) {
            try {
                $r = $this->api->call('DELETE', '/events/' . rawurlencode($id));
                in_array($r['status'], [200, 204, 404, 410], true) ? $deleted++ : $failed++;
                unset($this->created[$id]);
            } catch (\Throwable) {
                $failed++;
            }
        }
        try {
            $swept = $this->sweep();
            $live = count(array_filter($this->listAll(['showDeleted' => 'false', 'singleEvents' => 'false'])['items'], static fn(array $e) => self::isMarked($e) && empty($e['recurringEventId'])));
            $this->report->run['cleanup'] = "each case deleted what it made; at the end $deleted more were deleted" . ($failed ? ", $failed deletes failed" : '') . ", the sweep found $swept marked events, and $live marked events are still live.";
            $this->report->run['markedLive'] = $live;
        } catch (\Throwable $e) {
            $this->report->run['cleanup'] = 'the final sweep failed (' . $e->getMessage() . '); rerun the probe to delete what is left.';
        }
        $this->report->run['finished'] = Api::now();
        $this->report->run['requests'] = $this->api->requests;
        $this->report->write();
    }

    public static function isMarked(array $e): bool
    {
        return isset($e['extendedProperties']['private'][self::MARKER_KEY])
            || str_contains((string) ($e['description'] ?? ''), self::MARKER_TEXT);
    }

    // ---- One case -------------------------------------------------------------

    private function probeCase(string $group, string $id, string $title, \Closure $fn): void
    {
        $c = new CaseRun($group, $id, $title);
        try {
            $fn($c);
            $this->failuresInARow = 0;
        } catch (\Throwable $e) {
            $c->error = get_class($e) . ': ' . $e->getMessage();
            $c->finding = $c->finding !== '' ? $c->finding : 'The case stopped: ' . $e->getMessage();
            $this->failuresInARow++;
        }
        foreach ($c->created as $made) {
            try {
                $c->step('cleanup', 'delete what the case made', $this->api->call('DELETE', '/events/' . rawurlencode($made)));
                unset($this->created[$made]);
            } catch (\Throwable) {
                // finish() and the next run's sweep try again
            }
        }
        if ($c->sent === '') {
            $c->sent = self::describeSent($c->writes());
        }
        if ($c->cameBack === '') {
            $c->cameBack = $this->describeCameBack($c);
        }
        $this->report->run['requests'] = $this->api->requests;
        $this->report->add($c);
        if ($this->failuresInARow >= self::FAILURES_IN_A_ROW) {
            throw new \RuntimeException(self::FAILURES_IN_A_ROW . ' cases in a row failed; the last: ' . $c->error);
        }
    }

    /** The usual shape: read back, settle the token, write, observe. */
    private function standard(CaseRun $c, \Closure $write): void
    {
        $c->before = $this->readBack($c, 'before', $c->series);
        $this->settle($c);
        $write($c);
        $this->observe($c);
    }

    /** After the writes: the delta (retried until it shows them), the read-back, a second delta. */
    private function observe(CaseRun $c): void
    {
        $wrote = $c->touched !== [];
        $items = [];
        $tries = 0;
        $seen = [];
        do {
            if ($tries > 0) {
                ($this->sleep)(min(8.0, 0.5 * (2 ** ($tries - 1))));
            }
            $tries++;
            $d = $this->delta($c, 'delta');
            $items = array_merge($items, $d);
            foreach ($d as $item) {
                $seen[(string) $item['id']] = true;
            }
            $missing = array_diff($c->touched, array_keys($seen));
        } while ($wrote && $missing !== [] && $tries < self::READ_TRIES);
        $c->delta = ['items' => $items, 'tries' => $tries, 'showedEveryWrite' => !$wrote || $missing === []];
        $c->after = $this->readBack($c, 'after', $c->series);
        $c->secondDelta = ['items' => $this->delta($c, 'second delta')];
    }

    /** Wait (by observing, bounded) until the setup's writes have shown in a delta. */
    private function settle(CaseRun $c): void
    {
        $tries = 0;
        $seen = [];
        do {
            if ($tries > 0) {
                ($this->sleep)(min(8.0, 0.5 * (2 ** ($tries - 1))));
            }
            $tries++;
            foreach ($this->delta($c, 'settle') as $item) {
                $seen[(string) $item['id']] = true;
            }
            $missing = array_diff($c->setupTouched, array_keys($seen));
        } while ($missing !== [] && $tries < self::READ_TRIES);
        $c->settleTries = $tries;
    }

    // ---- Requests -------------------------------------------------------------

    private function send(CaseRun $c, string $phase, string $label, string $method, string $path, array $query = [], ?array $body = null, array $headers = [], bool $sendUpdates = true): array
    {
        $x = $c->step($phase, $label, $this->api->call($method, $path, $query, $body, $headers, $sendUpdates));
        if ($method !== 'GET' && $x['status'] >= 200 && $x['status'] < 300) {
            $id = is_array($x['response']) && isset($x['response']['id']) ? (string) $x['response']['id'] : self::pathId($path);
            if ($id !== null) {
                if ($phase === 'write') {
                    $c->touched[] = $id;
                } else {
                    $c->setupTouched[] = $id;
                }
            }
        }
        return $x;
    }

    /** Insert with the probe's marker; the new id joins the case's series and is deleted after it. */
    private function insert(CaseRun $c, string $phase, string $label, array $body, bool $sendUpdates = true): array
    {
        $body['description'] = trim((string) ($body['description'] ?? 'Probe notes') . ' ' . self::MARKER_TEXT);
        $body['extendedProperties']['private'][self::MARKER_KEY] = $this->runId;
        $x = $this->send($c, $phase, $label, 'POST', '/events', [], $body, [], $sendUpdates);
        if ($x['status'] >= 200 && $x['status'] < 300 && isset($x['response']['id'])) {
            $id = (string) $x['response']['id'];
            $this->created[$id] = true;
            $c->created[] = $id;
            $c->series[] = $id;
        }
        return $x;
    }

    /** GET an event, retried on a 404 (a write not yet readable). */
    private function get(CaseRun $c, string $phase, string $id): array
    {
        for ($try = 1; ; $try++) {
            $x = $this->api->call('GET', '/events/' . rawurlencode($id));
            if ($x['status'] !== 404 || $try >= self::READ_TRIES) {
                $x['readTries'] = $try;
                return $c->step($phase, 'get ' . $id, $x);
            }
            ($this->sleep)(min(8.0, 0.5 * (2 ** ($try - 1))));
        }
    }

    /** @return array{items:list<array>, pages:int, complete:bool, status:int, readTries:int, nextSyncToken:?string} */
    private function pages(?CaseRun $c, string $phase, string $label, string $path, array $query, int $maxPages = 3): array
    {
        $items = [];
        $page = null;
        $pages = 0;
        $status = 200;
        $next = null;
        $readTries = 1;
        do {
            $q = $query + ['maxResults' => '2500'] + ($page !== null ? ['pageToken' => $page] : []);
            for ($try = 1; ; $try++) {
                $x = $this->api->call('GET', $path, $q);
                if ($x['status'] !== 404 || $try >= self::READ_TRIES) {
                    break;
                }
                ($this->sleep)(min(8.0, 0.5 * (2 ** ($try - 1))));
            }
            $readTries = max($readTries, $try);
            $x['readTries'] = $try;
            if ($c !== null) {
                $c->step($phase, $label, $x);
            }
            $pages++;
            $status = $x['status'];
            if ($status !== 200 || !is_array($x['response'])) {
                break;
            }
            $items = array_merge($items, $x['response']['items'] ?? []);
            $page = $x['response']['nextPageToken'] ?? null;
            $next = $x['response']['nextSyncToken'] ?? $next;
        } while ($page !== null && $pages < $maxPages);
        return ['items' => $items, 'pages' => $pages, 'complete' => $status === 200 && $page === null, 'status' => $status, 'readTries' => $readTries, 'nextSyncToken' => $next];
    }

    private function listAll(array $query): array
    {
        return $this->pages(null, '', '', '/events', $query, 40);
    }

    private function fullSyncToken(): ?string
    {
        return $this->pages(null, '', '', '/events', ['showDeleted' => 'true', 'singleEvents' => 'false'], 40)['nextSyncToken'];
    }

    /** One incremental list from the rolling token; the token moves on. @return list<array> */
    private function delta(CaseRun $c, string $phase): array
    {
        if ($this->syncToken === null) {
            $this->syncToken = $this->fullSyncToken();
        }
        $r = $this->pages($c, $phase, 'sync-token delta', '/events', ['showDeleted' => 'true', 'singleEvents' => 'false', 'syncToken' => $this->syncToken], 40);
        if ($r['status'] === 410) {
            $c->notes[] = 'Google answered 410 to a sync token; the probe took a new one with a full list.';
            $this->syncToken = $this->fullSyncToken();
            return [];
        }
        $this->syncToken = $r['nextSyncToken'] ?? $this->syncToken;
        return $r['items'];
    }

    /** The master, events.instances and the singleEvents=false list of each series. */
    private function readBack(CaseRun $c, string $phase, array $ids): array
    {
        $out = [];
        foreach (array_unique($ids) as $id) {
            $m = $this->get($c, $phase, $id);
            $master = is_array($m['response']) && $m['status'] === 200 ? $m['response'] : null;
            // A single event has no instances to list.
            $inst = $master !== null && !empty($master['recurrence'])
                ? $this->pages($c, $phase, 'instances of ' . $id, '/events/' . rawurlencode($id) . '/instances', ['showDeleted' => 'true'])
                : ['items' => [], 'complete' => true, 'readTries' => 1];
            $list = $master !== null && isset($master['iCalUID'])
                ? $this->pages($c, $phase, 'singleEvents=false list of ' . $id, '/events', ['iCalUID' => (string) $master['iCalUID'], 'showDeleted' => 'true', 'singleEvents' => 'false'])
                : ['items' => [], 'complete' => false, 'status' => 0, 'readTries' => 0];
            $out[$id] = [
                'status' => $m['status'],
                'master' => $master,
                'instances' => $inst['items'],
                'instancesComplete' => $inst['complete'],
                'list' => $list['items'],
                'readTries' => max($m['readTries'], $inst['readTries'], $list['readTries']),
            ];
        }
        return $out;
    }

    private static function pathId(string $path): ?string
    {
        return preg_match('#^/events/([^/?]+)$#', $path, $m) === 1 ? rawurldecode($m[1]) : null;
    }

    // ---- Bodies ---------------------------------------------------------------

    private static function timed(string $title, string $tz, string $localStart, int $minutes, array $recurrence = []): array
    {
        $s = new \DateTimeImmutable($localStart, new \DateTimeZone($tz));
        $e = $s->modify("+$minutes minutes");
        $body = [
            'summary' => $title,
            'description' => 'Probe series notes',
            'location' => 'Probe room 1',
            'start' => ['dateTime' => $s->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz],
            'end' => ['dateTime' => $e->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz],
        ];
        return $recurrence !== [] ? $body + ['recurrence' => $recurrence] : $body;
    }

    /** All-day in the calendar's zone: dates only, as GoogleWriter::body sends them. */
    private static function allDay(string $title, string $date, array $recurrence): array
    {
        $s = new \DateTimeImmutable($date);
        return [
            'summary' => $title,
            'description' => 'Probe series notes',
            'location' => 'Probe room 1',
            'start' => ['date' => $s->format('Y-m-d')],
            'end' => ['date' => $s->modify('+1 day')->format('Y-m-d')],
            'recurrence' => $recurrence,
        ];
    }

    /** {start, end} of an instance moved by a relative amount ("+1 day"). */
    private static function shifted(array $inst, string $modify): array
    {
        $out = [];
        foreach (['start', 'end'] as $k) {
            $w = $inst[$k];
            if (isset($w['date'])) {
                $out[$k] = ['date' => (new \DateTimeImmutable($w['date']))->modify($modify)->format('Y-m-d')];
            } else {
                $tz = (string) ($w['timeZone'] ?? self::LA);
                $dt = (new \DateTimeImmutable($w['dateTime']))->setTimezone(new \DateTimeZone($tz))->modify($modify);
                $out[$k] = ['dateTime' => $dt->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
            }
        }
        return $out;
    }

    /** {start, end} of an instance at its own clock time on its own date, one hour long. */
    private function retimed(array $inst, string $clock): array
    {
        $w = $inst['start'];
        $tz = (string) ($w['timeZone'] ?? (isset($w['date']) ? $this->calendarZone : self::LA));
        $day = isset($w['date']) ? $w['date'] : (new \DateTimeImmutable($w['dateTime']))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d');
        $s = new \DateTimeImmutable("$day $clock", new \DateTimeZone($tz));
        return [
            'start' => ['dateTime' => $s->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz],
            'end' => ['dateTime' => $s->modify('+1 hour')->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz],
        ];
    }

    /** The n-th (1-based) instance of a series as events.instances lists it, labelled. */
    private function nth(CaseRun $c, string $masterId, int $n, string $label): array
    {
        $items = $this->instanceItems($c, $masterId);
        $inst = $items[$n - 1] ?? throw new \RuntimeException("the series has no occurrence $n");
        $c->labels[self::key($inst)] = "occurrence $n ($label)";
        return $inst;
    }

    /** @return list<array> instances in original-start order */
    private function instanceItems(CaseRun $c, string $masterId): array
    {
        $items = $this->pages($c, 'setup', 'instances of ' . $masterId, '/events/' . rawurlencode($masterId) . '/instances', ['showDeleted' => 'true'])['items'];
        usort($items, static fn(array $a, array $b) => strcmp(self::key($a), self::key($b)));
        return $items;
    }

    /** The probe's base series: weekly, ten, Mondays 10:00 in Los Angeles from 2027-03-01 (it crosses the March change). */
    private function weekly(CaseRun $c, string $phase = 'setup', array $recurrence = ['RRULE:FREQ=WEEKLY;COUNT=10']): string
    {
        $x = $this->insert($c, $phase, 'insert the series', self::timed('Probe weekly', self::LA, '2027-03-01 10:00', 60, $recurrence));
        return (string) ($x['response']['id'] ?? throw new \RuntimeException('the base series was refused: HTTP ' . $x['status']));
    }

    private function weeklyAllDay(CaseRun $c, array $recurrence = ['RRULE:FREQ=WEEKLY;COUNT=10']): string
    {
        $x = $this->insert($c, 'setup', 'insert the series', self::allDay('Probe all-day', '2027-03-01', $recurrence));
        return (string) ($x['response']['id'] ?? throw new \RuntimeException('the base series was refused: HTTP ' . $x['status']));
    }

    /** Group 3's starting state. @return array{0:string, 1:array<string,array>} the master and its exceptions by kind */
    private function withExceptions(CaseRun $c): array
    {
        $m = $this->weekly($c);
        $items = $this->instanceItems($c, $m);
        $kinds = ['moved' => 3, 'own time' => 4, 'own title' => 5, 'cancelled' => 6];
        $out = [];
        foreach ($kinds as $kind => $n) {
            $inst = $items[$n - 1] ?? throw new \RuntimeException("the series has no occurrence $n");
            $c->labels[self::key($inst)] = "occurrence $n ($kind)";
            $path = '/events/' . rawurlencode((string) $inst['id']);
            $x = match ($kind) {
                'moved' => $this->send($c, 'setup', "move occurrence $n a day later", 'PATCH', $path, [], self::shifted($inst, '+1 day')),
                'own time' => $this->send($c, 'setup', "occurrence $n at 15:00", 'PATCH', $path, [], $this->retimed($inst, '15:00')),
                'own title' => $this->send($c, 'setup', "occurrence $n renamed", 'PATCH', $path, [], ['summary' => 'Probe weekly (one changed)']),
                'cancelled' => $this->send($c, 'setup', "cancel occurrence $n", 'DELETE', $path),
            };
            if ($x['status'] >= 300) {
                throw new \RuntimeException("setting up the $kind exception failed: HTTP {$x['status']}");
            }
            $out[$kind] = $inst;
        }
        return [$m, $out];
    }

    // ---- Reading what came back ----------------------------------------------

    /** An instance's key: its original start, UTC basic form, or the date. */
    public static function key(array $e): string
    {
        $o = $e['originalStartTime'] ?? $e['start'] ?? [];
        if (isset($o['date'])) {
            return str_replace('-', '', (string) $o['date']);
        }
        if (isset($o['dateTime'])) {
            return (new \DateTimeImmutable((string) $o['dateTime']))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        }
        return '?';
    }

    private function label(CaseRun $c, string $key): string
    {
        return $c->labels[$key] ?? $key;
    }

    /** @return array<string,array> exception records of a series by key */
    private static function exceptions(array $read): array
    {
        $out = [];
        foreach ($read['list'] ?? [] as $e) {
            if (!empty($e['recurringEventId'])) {
                $out[self::key($e)] = $e;
            }
        }
        return $out;
    }

    private static function when(?array $w): string
    {
        if ($w === null) {
            return 'none';
        }
        return isset($w['date']) ? (string) $w['date'] : (string) ($w['dateTime'] ?? '?') . (isset($w['timeZone']) ? ' ' . $w['timeZone'] : '');
    }

    /** What changed on one resource between two reads, in words. */
    private static function changes(?array $a, ?array $b, ?array $masterBefore = null): array
    {
        $out = [];
        foreach (['status', 'summary', 'description', 'location', 'start', 'end', 'recurrence'] as $f) {
            $va = $a[$f] ?? null;
            $vb = $b[$f] ?? null;
            if (in_array($f, ['start', 'end'], true)) {
                if (self::when($va) !== self::when($vb)) {
                    $out[] = "$f " . self::when($va) . ' -> ' . self::when($vb);
                }
                continue;
            }
            if ($va !== $vb) {
                $inherited = $masterBefore !== null && $f !== 'status' && $f !== 'recurrence' && $va === ($masterBefore[$f] ?? null) ? " (it had the series' value)" : '';
                $out[] = "$f " . self::short($va) . ' -> ' . self::short($vb) . $inherited;
            }
        }
        if ($a !== null && $b !== null) {
            $out[] = ($a['etag'] ?? null) === ($b['etag'] ?? null) ? 'etag unchanged' : 'etag changed';
        }
        return $out;
    }

    private static function short(mixed $v): string
    {
        if ($v === null) {
            return 'none';
        }
        $s = is_string($v) ? "'" . $v . "'" : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return mb_strlen((string) $s) > 120 ? mb_substr((string) $s, 0, 117) . '...' : (string) $s;
    }

    /** The master between the two reads. */
    private function masterStory(CaseRun $c, string $id): string
    {
        $b = $c->before[$id]['master'] ?? null;
        $a = $c->after[$id]['master'] ?? null;
        if ($a === null) {
            return 'the master could not be read back (HTTP ' . ($c->after[$id]['status'] ?? '?') . ')';
        }
        if ($b === null) {
            return 'master: recurrence ' . self::short($a['recurrence'] ?? null) . ', start ' . self::when($a['start'] ?? null);
        }
        $parts = array_filter(self::changes($b, $a), static fn(string $p) => !str_starts_with($p, 'etag'));
        $parts[] = ($b['etag'] ?? null) === ($a['etag'] ?? null) ? 'etag unchanged' : 'etag changed';
        $parts[] = ($b['updated'] ?? null) === ($a['updated'] ?? null) ? '`updated` unchanged' : '`updated` changed';
        if (($b['sequence'] ?? null) !== ($a['sequence'] ?? null)) {
            $parts[] = 'sequence ' . self::short($b['sequence'] ?? null) . ' -> ' . self::short($a['sequence'] ?? null);
        }
        return 'master: ' . implode(', ', $parts);
    }

    /** The exception records between the two reads. */
    private function exceptionStory(CaseRun $c, string $id): string
    {
        $b = self::exceptions($c->before[$id] ?? []);
        $a = self::exceptions($c->after[$id] ?? []);
        $mb = $c->before[$id]['master'] ?? null;
        $parts = [];
        foreach ($b as $key => $e) {
            $key = (string) $key; // an all-day key is all digits
            $label = $this->label($c, $key);
            if (!isset($a[$key])) {
                $parts[] = "$label: no longer in the singleEvents=false list";
                continue;
            }
            $ch = array_filter(self::changes($e, $a[$key], $mb), static fn(string $p) => $p !== 'etag unchanged');
            $parts[] = "$label: kept with its old originalStartTime" . ($ch !== [] ? ', ' . implode(', ', $ch) : ', nothing else changed');
        }
        foreach ($a as $key => $e) {
            if (!isset($b[$key])) {
                $parts[] = 'new exception record ' . $this->label($c, (string) $key) . ' (' . ($e['status'] ?? '?') . ', start ' . self::when($e['start'] ?? null) . ')';
            }
        }
        return $parts === [] ? 'no exception records before or after' : implode('; ', $parts);
    }

    private function instancesStory(CaseRun $c, string $id): string
    {
        $items = $c->after[$id]['instances'] ?? [];
        $live = array_values(array_filter($items, static fn(array $e) => ($e['status'] ?? '') !== 'cancelled'));
        if ($live === []) {
            return 'events.instances lists no live instances';
        }
        $more = ($c->after[$id]['instancesComplete'] ?? true) ? '' : ' (more pages not read)';
        return 'events.instances: ' . count($live) . ' live' . (count($items) > count($live) ? ', ' . (count($items) - count($live)) . ' cancelled' : '') . $more . ', ' . self::when($live[0]['start'] ?? null) . ' to ' . self::when($live[count($live) - 1]['start'] ?? null);
    }

    /** The delta's items that belong to this case's series, in words. */
    private function deltaStory(CaseRun $c, array $items, string $what): string
    {
        $own = array_values(array_filter($items, fn(array $e) => $this->belongs($c, $e)));
        $others = count($items) - count($own);
        if ($own === []) {
            return "$what: nothing of this case" . ($others ? " ($others items from earlier cases)" : '');
        }
        $words = [];
        foreach ($own as $e) {
            $words[] = empty($e['recurringEventId'])
                ? 'master ' . ($e['status'] ?? '?')
                : $this->label($c, self::key($e)) . ' ' . ($e['status'] ?? '?');
        }
        $shown = array_slice($words, 0, 8);
        return "$what: " . implode(', ', $shown) . (count($words) > 8 ? ' and ' . (count($words) - 8) . ' more' : '') . ($others ? " (and $others items from earlier cases)" : '');
    }

    private function belongs(CaseRun $c, array $e): bool
    {
        foreach ($c->series as $id) {
            if (($e['id'] ?? '') === $id || ($e['recurringEventId'] ?? '') === $id || str_starts_with((string) ($e['id'] ?? ''), $id . '_')) {
                return true;
            }
        }
        return false;
    }

    private function syncStory(CaseRun $c): string
    {
        $tries = $c->delta['tries'] ?? 0;
        $first = $this->deltaStory($c, $c->delta['items'] ?? [], 'delta' . ($tries > 1 ? " (showed the writes on try $tries)" : ''));
        if (!($c->delta['showedEveryWrite'] ?? true)) {
            $first .= ' (not every write showed after ' . $tries . ' tries)';
        }
        $second = $this->deltaStory($c, $c->secondDelta['items'] ?? [], 'second delta');
        return "$first; " . (str_contains($second, 'nothing of this case') ? 'second delta changed nothing' : $second);
    }

    /** The instance id form Google used, against GoogleWriter::instanceId's. */
    private function idStory(string $masterId, array $inst, ?string $zone = null): string
    {
        $id = (string) ($inst['id'] ?? '');
        $o = $inst['originalStartTime'] ?? [];
        $allDay = isset($o['date']);
        $tz = $zone ?? (string) ($o['timeZone'] ?? self::LA);
        $utc = $allDay
            ? Time::toDb(new \DateTimeImmutable($o['date'] . ' 00:00', new \DateTimeZone($tz)))
            : Time::toDb(new \DateTimeImmutable((string) ($o['dateTime'] ?? 'now')));
        $predicted = GoogleWriter::instanceId($masterId, $utc, $allDay, $tz);
        $form = str_replace($masterId, '{master}', $id);
        return "instance id $form" . ($predicted === $id ? " (GoogleWriter::instanceId predicts the same)" : ' (GoogleWriter::instanceId predicts ' . str_replace($masterId, '{master}', $predicted) . ')');
    }

    private static function status(array $x): string
    {
        $s = (int) $x['status'];
        $why = is_array($x['response']) ? (string) ($x['response']['error']['message'] ?? '') : '';
        $word = match (true) {
            $s === 412 => ' conflict',
            $s === 409 => ' conflict',
            $s === 410 => ' gone',
            $s === 404 => ' not found',
            default => '',
        };
        return $s . $word . ($why !== '' && $s >= 400 ? " ($why)" : '');
    }

    private static function describeSent(array $writes): string
    {
        if ($writes === []) {
            return 'no write (reads only)';
        }
        $out = [];
        foreach ($writes as $x) {
            $path = preg_replace('#^/calendars/\{calendar\}#', '', $x['path']);
            $headers = $x['headers'] !== [] ? ' [' . implode(', ', array_map(static fn(string $h) => preg_replace('/:.*/', '', $h), $x['headers'])) . ']' : '';
            $su = $x['method'] !== 'GET' && !isset($x['query']['sendUpdates']) ? ' (sendUpdates left out)' : '';
            $out[] = $x['method'] . ' ' . $path . $headers . $su . ($x['body'] !== null ? ' ' . self::short(self::bodyForSummary($x['body'])) : '');
        }
        return implode('; ', $out);
    }

    private static function bodyForSummary(array $body): array
    {
        unset($body['extendedProperties']);
        if (isset($body['description'])) {
            $body['description'] = trim(str_replace(self::MARKER_TEXT, '', (string) $body['description']));
        }
        return $body;
    }

    private function describeCameBack(CaseRun $c): string
    {
        $statuses = array_map(static fn(array $x) => self::status($x), $c->writes());
        $tries = 1;
        foreach ([$c->before, $c->after] as $reads) {
            foreach ($reads as $r) {
                $tries = max($tries, (int) ($r['readTries'] ?? 1));
            }
        }
        $parts = [];
        if ($statuses !== []) {
            $parts[] = 'HTTP ' . implode(', ', $statuses);
        }
        if ($tries > 1) {
            $parts[] = "a read-back took $tries tries";
        }
        if (($c->delta['tries'] ?? 0) > 0) {
            $parts[] = 'delta in ' . $c->delta['tries'] . ' ' . ($c->delta['tries'] === 1 ? 'try' : 'tries');
        }
        if ($c->error !== null) {
            $parts[] = 'error: ' . $c->error;
        }
        return implode('; ', $parts);
    }

    /** The standard finding: master, exceptions, instances, sync. */
    private function seriesFinding(CaseRun $c, string $m): string
    {
        return implode('; ', [$this->masterStory($c, $m), $this->exceptionStory($c, $m), $this->instancesStory($c, $m), $this->syncStory($c)]);
    }

    /** @return array<string,string> instance key => etag */
    private static function instanceEtags(array $read): array
    {
        $out = [];
        foreach ($read['instances'] ?? [] as $e) {
            $out[self::key($e)] = (string) ($e['etag'] ?? '');
        }
        return $out;
    }

    // ---- 1. Rule forms ----------------------------------------------------------

    private function group1(): void
    {
        $rules = [
            ['1.1', 'last day of the month', 'timed', '2027-01-31 10:00', ['RRULE:FREQ=MONTHLY;BYMONTHDAY=-1;COUNT=6']],
            ['1.2', 'clamped month end', 'timed', '2027-01-30 10:00', ['RRULE:FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1;COUNT=6']],
            ['1.3', 'last Friday', 'timed', '2027-01-29 10:00', ['RRULE:FREQ=MONTHLY;BYDAY=-1FR;COUNT=6']],
            ['1.4', 'second weekday by BYSETPOS', 'timed', '2027-01-04 10:00', ['RRULE:FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=2;COUNT=6']],
            ['1.5', 'a week start (WKST)', 'timed', '2027-03-01 10:00', ['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,SU;WKST=SU;COUNT=6']],
            ['1.6', 'COUNT', 'timed', '2027-03-01 10:00', ['RRULE:FREQ=DAILY;COUNT=5']],
            ['1.7', 'UNTIL in UTC', 'timed', '2027-03-01 10:00', ['RRULE:FREQ=WEEKLY;UNTIL=20270405T170000Z']],
            ['1.8', 'UNTIL as a local date-time', 'timed', '2027-03-01 10:00', ['RRULE:FREQ=WEEKLY;UNTIL=20270405T100000']],
            ['1.9', 'UNTIL as a date on an all-day series', 'allday', '2027-03-01', ['RRULE:FREQ=WEEKLY;UNTIL=20270405']],
            ['1.10', 'BYWEEKNO', 'timed', '2027-03-08 10:00', ['RRULE:FREQ=YEARLY;BYWEEKNO=10;BYDAY=MO;COUNT=3']],
            ['1.11', 'BYYEARDAY', 'timed', '2027-04-10 10:00', ['RRULE:FREQ=YEARLY;BYYEARDAY=100;COUNT=3']],
            ['1.12', 'HOURLY with BYHOUR', 'timed', '2027-03-01 09:00', ['RRULE:FREQ=HOURLY;BYHOUR=9,13,17;COUNT=6']],
            ['1.13', 'EXRULE', 'timed', '2027-03-01 10:00', ['RRULE:FREQ=DAILY;COUNT=10', 'EXRULE:FREQ=WEEKLY;BYDAY=SA,SU']],
            ['1.14', 'an EXDATE matching no instance', 'timed', '2027-03-01 10:00', ['RRULE:FREQ=WEEKLY;COUNT=5', 'EXDATE;TZID=America/Los_Angeles:20270302T100000']],
            ['1.15', 'different zones on start and end', 'zones', '2027-03-01 10:00', ['RRULE:FREQ=WEEKLY;COUNT=4']],
        ];
        foreach ($rules as [$id, $title, $kind, $start, $recurrence]) {
            $this->probeCase('1', $id, $title, function (CaseRun $c) use ($kind, $start, $recurrence): void {
                $body = match ($kind) {
                    'allday' => self::allDay('Probe all-day', $start, $recurrence),
                    default => self::timed('Probe rule', self::LA, $start, 60, $recurrence),
                };
                if ($kind === 'zones') {
                    // 14:00 in New York is 11:00 in Los Angeles: an hour long, each end in its own zone.
                    $e = new \DateTimeImmutable('2027-03-01 14:00', new \DateTimeZone('America/New_York'));
                    $body['end'] = ['dateTime' => $e->format('Y-m-d\TH:i:sP'), 'timeZone' => 'America/New_York'];
                }
                $this->standard($c, function (CaseRun $c) use ($body): void {
                    $this->insert($c, 'write', 'insert the rule', $body);
                });
                $c->sent = 'POST /events (' . ($kind === 'allday' ? 'all-day' : 'timed, ' . $start) . ') ' . implode(' ', $recurrence);
                $x = $c->writes()[0];
                if ($x['status'] < 200 || $x['status'] >= 300) {
                    $c->finding = 'refused: HTTP ' . self::status($x) . '; ' . $this->syncStory($c);
                    return;
                }
                $m = (string) $x['response']['id'];
                $back = $c->after[$m]['master']['recurrence'] ?? ($x['response']['recurrence'] ?? null);
                $c->finding = 'accepted; ' . self::ruleStory($recurrence, is_array($back) ? $back : []) . '; '
                    . ($kind === 'zones' ? 'end came back ' . self::when($c->after[$m]['master']['end'] ?? null) . '; ' : '')
                    . $this->instancesStory($c, $m) . '; ' . $this->syncStory($c);
            });
        }
    }

    /** The recurrence lines as sent against as read back. */
    public static function ruleStory(array $sent, array $back): string
    {
        if ($sent === $back) {
            return 'recurrence came back as sent';
        }
        $norm = static function (array $lines): array {
            $out = [];
            foreach ($lines as $l) {
                [$name, $value] = array_pad(explode(':', (string) $l, 2), 2, '');
                $parts = explode(';', $value);
                sort($parts);
                $out[] = $name . ':' . implode(';', $parts);
            }
            sort($out);
            return $out;
        };
        $how = $norm($sent) === $norm($back) ? 'came back reordered' : 'came back changed';
        return "recurrence $how: " . implode(' ', $back) . ' (sent ' . implode(' ', $sent) . ')';
    }

    // ---- 2. Each exception kind alone -------------------------------------------

    private function group2(): void
    {
        foreach (['timed', 'all-day'] as $kind) {
            $t = $kind === 'timed' ? 't' : 'a';
            $series = fn(CaseRun $c): string => $kind === 'timed' ? $this->weekly($c) : $this->weeklyAllDay($c);
            $zone = $kind === 'timed' ? null : $this->calendarZone;

            $this->probeCase('2', "2.1$t", "$kind: an instance moved to another date", function (CaseRun $c) use ($series, $zone): void {
                $m = $series($c);
                $inst = $this->nth($c, $m, 3, 'moved');
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'move occurrence 3 two days later', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [], self::shifted($inst, '+2 days')));
                $c->finding = $this->idStory($m, $inst, $zone) . '; ' . $this->seriesFinding($c, $m);
            });
            $this->probeCase('2', "2.2$t", "$kind: an instance with its own time", function (CaseRun $c) use ($series, $zone): void {
                $m = $series($c);
                $inst = $this->nth($c, $m, 4, 'own time');
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'occurrence 4 at 15:00', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [], $this->retimed($inst, '15:00')));
                $c->finding = $this->idStory($m, $inst, $zone) . '; ' . $this->seriesFinding($c, $m);
            });
            $this->probeCase('2', "2.3$t", "$kind: a title-only edit", function (CaseRun $c) use ($series, $zone): void {
                $m = $series($c);
                $inst = $this->nth($c, $m, 5, 'own title');
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'rename occurrence 5', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [], ['summary' => 'Probe weekly (one changed)']));
                $c->finding = $this->idStory($m, $inst, $zone) . '; ' . $this->seriesFinding($c, $m);
            });
            $this->probeCase('2', "2.4$t", "$kind: a cancellation by DELETE on the instance", function (CaseRun $c) use ($series, $zone): void {
                $m = $series($c);
                $inst = $this->nth($c, $m, 6, 'cancelled');
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'delete occurrence 6', 'DELETE', '/events/' . rawurlencode((string) $inst['id'])));
                $c->finding = $this->idStory($m, $inst, $zone) . '; ' . $this->exdateStory($c, $m) . '; ' . $this->seriesFinding($c, $m);
            });
            $this->probeCase('2', "2.5$t", "$kind: a cancellation by PATCH to cancelled", function (CaseRun $c) use ($series, $zone): void {
                $m = $series($c);
                $inst = $this->nth($c, $m, 6, 'cancelled');
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'occurrence 6 status cancelled', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [], ['status' => 'cancelled']));
                $c->finding = $this->idStory($m, $inst, $zone) . '; ' . $this->exdateStory($c, $m) . '; ' . $this->seriesFinding($c, $m);
            });
            $this->probeCase('2', "2.6$t", "$kind: an exception beyond a shortened end", function (CaseRun $c) use ($series, $kind): void {
                $m = $series($c);
                $inst = $this->nth($c, $m, 9, 'own title, past the new end');
                $x = $this->send($c, 'setup', 'rename occurrence 9', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [], ['summary' => 'Probe weekly (one changed)']);
                if ($x['status'] >= 300) {
                    throw new \RuntimeException('renaming occurrence 9 failed: HTTP ' . $x['status']);
                }
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'end the series after six', 'PATCH', '/events/' . rawurlencode($m), [], ['recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']]));
                $c->finding = $this->seriesFinding($c, $m);
            });
        }

        // What the app sends for "this occurrence" today: GoogleWriter::body,
        // the whole resource with recurrence: [] (code map, Q5).
        $this->probeCase('2', '2.7', 'timed: a title edit sent as the app sends it (whole body, recurrence: [])', function (CaseRun $c): void {
            $m = $this->weekly($c);
            $inst = $this->nth($c, $m, 5, 'own title');
            $s = new \DateTimeImmutable((string) $inst['start']['dateTime']);
            $e = new \DateTimeImmutable((string) $inst['end']['dateTime']);
            $body = GoogleWriter::body([
                'title' => 'Probe weekly (one changed)', 'description' => 'Probe series notes ' . self::MARKER_TEXT, 'location' => 'Probe room 1',
                'start_utc' => Time::toDb($s), 'end_utc' => Time::toDb($e), 'all_day' => 0, 'tzid' => self::LA,
                'rrule' => null, 'exdates_json' => null, 'status' => 'confirmed',
            ]);
            $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'patch occurrence 5 with GoogleWriter::body', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [], $body));
            $c->finding = $this->idStory($m, $inst) . '; ' . $this->seriesFinding($c, $m);
        });

        // The exception keys east of UTC: 08:00 local is the day before in UTC.
        foreach ([['2.8', 'Pacific/Auckland'], ['2.9', 'Asia/Tokyo']] as [$id, $tz]) {
            $this->probeCase('2', $id, "$tz: one moved and one cancelled, 08:00 local", function (CaseRun $c) use ($tz): void {
                $x = $this->insert($c, 'setup', 'insert the series', self::timed('Probe weekly', $tz, '2027-03-01 08:00', 60, ['RRULE:FREQ=WEEKLY;COUNT=6']));
                $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
                $moved = $this->nth($c, $m, 2, 'moved');
                $gone = $this->nth($c, $m, 3, 'cancelled');
                $this->standard($c, function (CaseRun $c) use ($moved, $gone): void {
                    $this->send($c, 'write', 'move occurrence 2 a day later', 'PATCH', '/events/' . rawurlencode((string) $moved['id']), [], self::shifted($moved, '+1 day'));
                    $this->send($c, 'write', 'delete occurrence 3', 'DELETE', '/events/' . rawurlencode((string) $gone['id']));
                });
                $c->finding = 'moved: ' . $this->idStory($m, $moved, $tz) . '; cancelled: ' . $this->idStory($m, $gone, $tz) . '; ' . $this->exdateStory($c, $m) . '; ' . $this->seriesFinding($c, $m);
            });
        }
    }

    private function exdateStory(CaseRun $c, string $m): string
    {
        $before = $c->before[$m]['master']['recurrence'] ?? [];
        $after = $c->after[$m]['master']['recurrence'] ?? [];
        $added = array_values(array_filter($after, static fn(string $l) => str_starts_with($l, 'EXDATE') && !in_array($l, $before, true)));
        return $added === [] ? "the master's recurrence gained no EXDATE" : "the master's recurrence gained " . implode(' ', $added);
    }

    // ---- 3. Master changes over exceptions ----------------------------------------

    private function group3(): void
    {
        $la = static fn(string $local): string => (new \DateTimeImmutable($local, new \DateTimeZone(self::LA)))->format('Y-m-d\TH:i:sP');
        $denver = static fn(string $local): string => (new \DateTimeImmutable($local, new \DateTimeZone('America/Denver')))->format('Y-m-d\TH:i:sP');
        $span = static fn(string $s, string $e, string $tz = self::LA) => ['start' => ['dateTime' => $s, 'timeZone' => $tz], 'end' => ['dateTime' => $e, 'timeZone' => $tz]];
        // UNTIL at the end of occurrence 4's day (2027-03-22) in Los Angeles, in UTC.
        $until = (new \DateTimeImmutable('2027-03-22 23:59:59', new \DateTimeZone(self::LA)))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $changes = [
            ['3.1', 'the start time of day (10:00 to 11:00)', $span($la('2027-03-01 11:00'), $la('2027-03-01 12:00'))],
            ['3.2', 'the length (an hour to ninety minutes)', $span($la('2027-03-01 10:00'), $la('2027-03-01 11:30'))],
            ['3.3', 'the zone (same clock, America/Denver)', $span($denver('2027-03-01 10:00'), $denver('2027-03-01 11:00'), 'America/Denver')],
            ['3.4', 'the start date a day later, same rule', $span($la('2027-03-02 10:00'), $la('2027-03-02 11:00'))],
            ['3.5', 'the start date onto its second occurrence, same rule', $span($la('2027-03-08 10:00'), $la('2027-03-08 11:00'))],
            ['3.6', 'the rule: a weekday changed (BYDAY=TU)', ['recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=TU;COUNT=10']]],
            ['3.7', 'the rule: a weekday added (BYDAY=MO,WE)', ['recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10']]],
            ['3.8', 'the rule: COUNT longer (12)', ['recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=12']]],
            ['3.9', 'the rule: COUNT shorter (4)', ['recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']]],
            ['3.10', 'the rule: COUNT to UNTIL (after occurrence 4)', ['recurrence' => ["RRULE:FREQ=WEEKLY;UNTIL=$until"]]],
            ['3.11', 'the rule: the clamped month-end form', ['recurrence' => ['RRULE:FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1;COUNT=4']]],
            ['3.12', 'the title', ['summary' => 'Probe weekly renamed']],
            ['3.13', 'the description', ['description' => 'Probe series notes, changed ' . self::MARKER_TEXT]],
            ['3.14', 'the location', ['location' => 'Probe room 2']],
            ['3.15', 'the status (tentative)', ['status' => 'tentative']],
        ];
        foreach ($changes as [$id, $title, $patch]) {
            $this->probeCase('3', $id, $title, function (CaseRun $c) use ($patch): void {
                [$m] = $this->withExceptions($c);
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'patch the master', 'PATCH', '/events/' . rawurlencode($m), [], $patch));
                $c->finding = $this->seriesFinding($c, $m);
            });
        }
        // What the app sends for "all events" today: the whole body, its
        // recurrence carrying an EXDATE for the cancelled day (code map, Q4).
        $this->probeCase('3', '3.16', 'the title, sent as the app sends it (whole body, EXDATE for the cancelled day)', function (CaseRun $c): void {
            [$m, $ex] = $this->withExceptions($c);
            $cancelled = Time::toDb(new \DateTimeImmutable((string) $ex['cancelled']['originalStartTime']['dateTime']));
            $body = GoogleWriter::body([
                'title' => 'Probe weekly renamed', 'description' => 'Probe series notes ' . self::MARKER_TEXT, 'location' => 'Probe room 1',
                'start_utc' => Time::toDb(new \DateTimeImmutable('2027-03-01 10:00', new \DateTimeZone(self::LA))),
                'end_utc' => Time::toDb(new \DateTimeImmutable('2027-03-01 11:00', new \DateTimeZone(self::LA))),
                'all_day' => 0, 'tzid' => self::LA, 'rrule' => 'FREQ=WEEKLY;COUNT=10',
                'exdates_json' => json_encode([$cancelled]), 'status' => 'confirmed',
            ]);
            $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'patch the master with GoogleWriter::body', 'PATCH', '/events/' . rawurlencode($m), [], $body));
            $c->finding = $this->seriesFinding($c, $m);
        });
    }

    // ---- 4. The split ---------------------------------------------------------------

    private function group4(): void
    {
        foreach ([['4.1', 'trim with UNTIL, then insert'], ['4.2', 'trim with COUNT, then insert']] as [$id, $title]) {
            $this->probeCase('4', $id, $title, function (CaseRun $c) use ($id): void {
                [$m] = $this->withExceptions($c);
                $cut = $this->instanceItems($c, $m)[4] ?? throw new \RuntimeException('no occurrence 5');
                $cutStart = new \DateTimeImmutable((string) $cut['originalStartTime']['dateTime']);
                $rule = $id === '4.1'
                    ? 'RRULE:FREQ=WEEKLY;UNTIL=' . $cutStart->modify('-1 second')->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z')
                    : 'RRULE:FREQ=WEEKLY;COUNT=4';
                $new = null;
                $this->standard($c, function (CaseRun $c) use ($m, $rule, $cutStart, &$new): void {
                    $this->send($c, 'write', 'trim the old series', 'PATCH', '/events/' . rawurlencode($m), [], ['recurrence' => [$rule]]);
                    $local = $cutStart->setTimezone(new \DateTimeZone(self::LA))->format('Y-m-d H:i');
                    $x = $this->insert($c, 'write', 'insert the new series', self::timed('Probe weekly', self::LA, $local, 60, ['RRULE:FREQ=WEEKLY;COUNT=6']));
                    $new = isset($x['response']['id']) ? (string) $x['response']['id'] : null;
                });
                $parts = ['old series: ' . $this->masterStory($c, $m), 'its exceptions: ' . $this->exceptionStory($c, $m), 'old ' . $this->instancesStory($c, $m)];
                if ($new !== null) {
                    $parts[] = 'new ' . $this->instancesStory($c, $new);
                    $parts[] = 'new series exceptions: ' . $this->exceptionStory($c, $new);
                    $a = (string) ($c->after[$m]['master']['iCalUID'] ?? '');
                    $b = (string) ($c->after[$new]['master']['iCalUID'] ?? '');
                    $parts[] = $a !== '' && $a === $b ? 'the two share an iCalUID' : 'the two have different iCalUIDs';
                } else {
                    $parts[] = 'the new series was refused: HTTP ' . self::status($c->writes()[1] ?? ['status' => 0, 'response' => null]);
                }
                $parts[] = $this->syncStory($c);
                $c->finding = implode('; ', $parts);
            });
        }
    }

    // ---- 5. Etags and conditional writes ------------------------------------------

    private function group5(): void
    {
        $this->probeCase('5', '5.1', 'stability across reads, and etags in a singleEvents=false list', function (CaseRun $c): void {
            $m = $this->weekly($c);
            $inst = $this->nth($c, $m, 5, 'own title');
            $this->send($c, 'setup', 'rename occurrence 5', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [], ['summary' => 'Probe weekly (one changed)']);
            $this->standard($c, static fn() => null);
            $b = $c->before[$m];
            $a = $c->after[$m];
            $ib = self::instanceEtags($b);
            $ia = self::instanceEtags($a);
            $same = count(array_filter(array_keys($ib), static fn(int|string $k) => ($ia[$k] ?? null) === $ib[$k]));
            $key = self::key($inst);
            $listed = self::exceptions($a)[$key] ?? null;
            $got = $this->get($c, 'after', (string) $inst['id']);
            $c->finding = 'master etag ' . (($b['master']['etag'] ?? 1) === ($a['master']['etag'] ?? 2) ? 'the same' : 'different') . ' across two reads; '
                . "$same of " . count($ib) . ' instance etags the same across two reads; the exception in the singleEvents=false list '
                . ($listed === null ? 'was not there' : (isset($listed['etag']) ? 'carries an etag, ' . (($listed['etag'] === ($ia[$key] ?? null)) ? 'the same as events.instances gives' : 'not the one events.instances gives') . ', ' . (($listed['etag'] === ($got['response']['etag'] ?? null)) ? 'the same as a get' : 'not the one a get gives') . ', ' . (($listed['etag'] === ($a['master']['etag'] ?? null)) ? "and the same as the master's" : "not the master's") : 'carries no etag'));
        });

        $this->probeCase('5', '5.2', "a master change and the instances' etags", function (CaseRun $c): void {
            $m = $this->weekly($c);
            $inst = $this->nth($c, $m, 5, 'own title');
            $this->send($c, 'setup', 'rename occurrence 5', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [], ['summary' => 'Probe weekly (one changed)']);
            $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'change the master\'s location', 'PATCH', '/events/' . rawurlencode($m), [], ['location' => 'Probe room 2']));
            $c->finding = $this->etagStory($c, $m, self::key($inst));
        });

        $this->probeCase('5', '5.3', "an instance change and the master's etag", function (CaseRun $c): void {
            $m = $this->weekly($c);
            $inst = $this->nth($c, $m, 3, 'renamed by the write');
            $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'rename occurrence 3', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [], ['summary' => 'Probe weekly (one changed)']));
            $c->finding = $this->etagStory($c, $m, self::key($inst));
        });

        foreach ([['5.4', 'PATCH', 'master'], ['5.5', 'DELETE', 'master'], ['5.6', 'PATCH', 'instance'], ['5.7', 'DELETE', 'instance']] as [$id, $method, $what]) {
            $this->probeCase('5', $id, "If-Match on $method of the $what, stale and then current", function (CaseRun $c) use ($method, $what): void {
                $m = $this->weekly($c);
                $target = $what === 'master' ? $m : (string) $this->nth($c, $m, 3, 'the target')['id'];
                $path = '/events/' . rawurlencode($target);
                $first = $this->get($c, 'setup', $target);
                $stale = (string) ($first['response']['etag'] ?? '');
                $bump = $this->send($c, 'setup', 'a change, so the first etag is stale', 'PATCH', $path, [], ['location' => 'Probe room 2']);
                $current = (string) ($bump['response']['etag'] ?? '');
                $this->standard($c, function (CaseRun $c) use ($method, $path, $stale, $current): void {
                    $body = $method === 'PATCH' ? ['summary' => 'Probe weekly (conditional)'] : null;
                    $this->send($c, 'write', 'with the stale etag', $method, $path, [], $body, ['If-Match: ' . $stale]);
                    $this->send($c, 'write', 'with the current etag', $method, $path, [], $body, ['If-Match: ' . $current]);
                });
                [$a, $b] = $c->writes() + [null, null];
                $c->finding = 'stale If-Match: ' . self::status($a) . '; current If-Match: ' . self::status($b)
                    . ($current === $stale ? ' (the setup change did not change the etag, so the "stale" one was current)' : '')
                    . '; ' . $this->syncStory($c);
            });
        }
    }

    private function etagStory(CaseRun $c, string $m, string $exceptionKey): string
    {
        $ib = self::instanceEtags($c->before[$m] ?? []);
        $ia = self::instanceEtags($c->after[$m] ?? []);
        $plain = array_diff(array_map('strval', array_keys($ib)), [$exceptionKey]);
        $changed = count(array_filter($plain, static fn(string $k) => ($ia[$k] ?? null) !== $ib[$k]));
        $mb = $c->before[$m]['master']['etag'] ?? null;
        $ma = $c->after[$m]['master']['etag'] ?? null;
        return 'master etag ' . ($mb === $ma ? 'unchanged' : 'changed') . "; $changed of " . count($plain) . " other instances' etags changed; "
            . $this->label($c, $exceptionKey) . ' etag ' . (($ib[$exceptionKey] ?? null) === ($ia[$exceptionKey] ?? null) ? 'unchanged' : 'changed') . '; ' . $this->syncStory($c);
    }

    // ---- 6. Deletes and their reverse -----------------------------------------------

    private function group6(): void
    {
        $single = static fn(): array => self::timed('Probe single', self::LA, '2027-03-03 10:00', 60);

        $this->probeCase('6', '6.1', 'a deleted event, read back', function (CaseRun $c) use ($single): void {
            $x = $this->insert($c, 'setup', 'insert a single event', $single());
            $id = (string) ($x['response']['id'] ?? throw new \RuntimeException('insert refused'));
            $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'delete it', 'DELETE', '/events/' . rawurlencode($id)));
            $c->finding = $this->deletedStory($c, $id) . '; ' . $this->syncStory($c);
        });

        $this->probeCase('6', '6.2', 'a deleted event, PATCH to confirmed', function (CaseRun $c) use ($single): void {
            $x = $this->insert($c, 'setup', 'insert a single event', $single());
            $id = (string) ($x['response']['id'] ?? throw new \RuntimeException('insert refused'));
            $this->send($c, 'setup', 'delete it', 'DELETE', '/events/' . rawurlencode($id));
            $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'status confirmed', 'PATCH', '/events/' . rawurlencode($id), [], ['status' => 'confirmed']));
            $c->finding = 'PATCH answered ' . self::status($c->writes()[0]) . '; ' . $this->deletedStory($c, $id) . '; ' . $this->syncStory($c);
        });

        $this->probeCase('6', '6.3', 'an insert with an id the client chose', function (CaseRun $c) use ($single): void {
            $chosen = 'probe' . bin2hex(random_bytes(12));
            $this->standard($c, fn(CaseRun $c) => $this->insert($c, 'write', 'insert with a chosen id', $single() + ['id' => $chosen]));
            $x = $c->writes()[0];
            $back = (string) ($x['response']['id'] ?? '');
            $c->finding = 'insert answered ' . self::status($x) . '; ' . ($back === '' ? 'no id came back' : ($back === $chosen ? 'the chosen id came back' : 'a different id came back')) . '; ' . $this->syncStory($c);
        });

        $this->probeCase('6', '6.4', 'the same chosen id inserted again after a delete', function (CaseRun $c) use ($single): void {
            $chosen = 'probe' . bin2hex(random_bytes(12));
            $this->insert($c, 'setup', 'insert with a chosen id', $single() + ['id' => $chosen]);
            $this->send($c, 'setup', 'delete it', 'DELETE', '/events/' . rawurlencode($chosen));
            $this->standard($c, fn(CaseRun $c) => $this->insert($c, 'write', 'insert the same id again', $single() + ['id' => $chosen, 'summary' => 'Probe single again']));
            $c->finding = 'the second insert answered ' . self::status($c->writes()[0]) . '; ' . $this->deletedStory($c, $chosen) . '; ' . $this->syncStory($c);
        });

        foreach ([['6.5', 'DELETE'], ['6.6', 'PATCH']] as [$id, $how]) {
            $this->probeCase('6', $id, "an instance cancelled by $how, PATCH to confirmed", function (CaseRun $c) use ($how): void {
                $m = $this->weekly($c);
                $inst = $this->nth($c, $m, 3, 'cancelled, then confirmed');
                $path = '/events/' . rawurlencode((string) $inst['id']);
                $how === 'DELETE'
                    ? $this->send($c, 'setup', 'delete occurrence 3', 'DELETE', $path)
                    : $this->send($c, 'setup', 'occurrence 3 status cancelled', 'PATCH', $path, [], ['status' => 'cancelled']);
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'occurrence 3 status confirmed', 'PATCH', $path, [], ['status' => 'confirmed']));
                $key = self::key($inst);
                $listed = array_values(array_filter($c->after[$m]['instances'] ?? [], static fn(array $e) => self::key($e) === $key));
                $c->finding = 'PATCH answered ' . self::status($c->writes()[0]) . '; events.instances lists occurrence 3 ' . ($listed === [] ? 'not at all' : 'as ' . ($listed[0]['status'] ?? '?') . ' under id ' . str_replace($m, '{master}', (string) $listed[0]['id'])) . '; ' . $this->seriesFinding($c, $m);
            });
        }
    }

    private function deletedStory(CaseRun $c, string $id): string
    {
        $r = $c->after[$id] ?? [];
        $e = $r['master'] ?? null;
        $listed = array_values(array_filter($r['list'] ?? [], static fn(array $x) => ($x['id'] ?? '') === $id));
        $get = 'a get answers ' . ($r['status'] ?? '?') . ($e !== null ? ' with status ' . ($e['status'] ?? '?') . ', the same id, summary ' . self::short($e['summary'] ?? null) : '');
        $list = $e === null ? 'no list read (no iCalUID to read by)' : 'the showDeleted list ' . ($listed === [] ? 'does not have it' : 'has it as ' . ($listed[0]['status'] ?? '?') . ' with summary ' . self::short($listed[0]['summary'] ?? null));
        return "$get; $list";
    }

    // ---- 7. Sync -------------------------------------------------------------------

    private function group7(): void
    {
        $this->probeCase('7', '7.1', 'the echo: how soon a write shows in the next delta', function (CaseRun $c): void {
            $m = $this->weekly($c);
            $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'rename the series', 'PATCH', '/events/' . rawurlencode($m), [], ['summary' => 'Probe weekly renamed']));
            $etag = $c->writes()[0]['response']['etag'] ?? null;
            $echo = array_values(array_filter($c->delta['items'], static fn(array $e) => ($e['id'] ?? '') === $m));
            $c->finding = ($echo === [] ? 'the write did not show in the delta after ' . $c->delta['tries'] . ' tries' : 'the write showed in the delta on try ' . $c->delta['tries'] . ', ' . ((($echo[count($echo) - 1]['etag'] ?? null) === $etag) ? "with the write's etag" : "with an etag other than the write's"))
                . '; ' . $this->syncStory($c);
        });

        $this->probeCase('7', '7.2', 'a master change and its cancelled instances in the delta', function (CaseRun $c): void {
            $m = $this->weekly($c);
            $moved = $this->nth($c, $m, 3, 'moved');
            $gone = $this->nth($c, $m, 6, 'cancelled');
            $this->send($c, 'setup', 'move occurrence 3 a day later', 'PATCH', '/events/' . rawurlencode((string) $moved['id']), [], self::shifted($moved, '+1 day'));
            $this->send($c, 'setup', 'delete occurrence 6', 'DELETE', '/events/' . rawurlencode((string) $gone['id']));
            $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'rename the series', 'PATCH', '/events/' . rawurlencode($m), [], ['summary' => 'Probe weekly renamed']));
            $has = fn(array $inst) => array_filter($c->delta['items'], static fn(array $e) => !empty($e['recurringEventId']) && self::key($e) === self::key($inst)) !== [];
            $c->finding = 'the delta after a master rename ' . ($has($gone) ? 'lists' : 'does not list') . ' the cancelled occurrence 6 and ' . ($has($moved) ? 'lists' : 'does not list') . ' the moved occurrence 3; ' . $this->seriesFinding($c, $m);
        });

        $this->probeCase('7', '7.3', 'an unbounded series under singleEvents=true with a sync token', function (CaseRun $c): void {
            $m = $this->weekly($c, 'setup', ['RRULE:FREQ=WEEKLY']);
            $mine = static fn(array $items) => array_values(array_filter($items, static fn(array $e) => ($e['recurringEventId'] ?? '') === $m));
            $full = $this->pages($c, 'before', 'singleEvents=true full list', '/events', ['showDeleted' => 'true', 'singleEvents' => 'true'], 20);
            $first = $mine($full['items']);
            $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'rename the series', 'PATCH', '/events/' . rawurlencode($m), [], ['summary' => 'Probe weekly renamed']));
            $delta = $full['nextSyncToken'] !== null
                ? $this->pages($c, 'after', 'singleEvents=true delta', '/events', ['showDeleted' => 'true', 'singleEvents' => 'true', 'syncToken' => $full['nextSyncToken']], 20)
                : null;
            $c->finding = 'a full singleEvents=true list (no timeMax) gave ' . count($first) . ' instances of the series over ' . $full['pages'] . ' pages'
                . ($full['complete'] ? '' : ' (stopped at the page cap)')
                . ($first !== [] ? ', the last at ' . self::when($first[count($first) - 1]['start'] ?? null) : '')
                . ', and ' . ($full['nextSyncToken'] !== null ? 'a sync token' : 'no sync token')
                . ($delta !== null ? '; after a master rename its singleEvents=true delta answered ' . $delta['status'] . ' with ' . count($mine($delta['items'])) . ' instances of the series' : '')
                . '; ' . $this->syncStory($c);
        });

        $this->probeCase('7', '7.4', 'the occurrence cap: an unbounded daily series through events.instances', function (CaseRun $c): void {
            $m = $this->weekly($c, 'setup', ['RRULE:FREQ=DAILY']);
            $this->standard($c, static fn() => null);
            $all = $this->pages($c, 'after', 'instances, every page', '/events/' . rawurlencode($m) . '/instances', ['showDeleted' => 'true'], 20);
            $c->finding = 'events.instances (no timeMax) gave ' . count($all['items']) . ' instances over ' . $all['pages'] . ' pages' . ($all['complete'] ? '' : ' (stopped at the page cap)')
                . ($all['items'] !== [] ? ', ' . self::when($all['items'][0]['start'] ?? null) . ' to ' . self::when($all['items'][count($all['items']) - 1]['start'] ?? null) : '');
        });

        $this->probeCase('7', '7.5', 'the occurrence cap: a daily series with COUNT=1000', function (CaseRun $c): void {
            $this->standard($c, fn(CaseRun $c) => $this->insert($c, 'write', 'insert the series', self::timed('Probe daily', self::LA, '2027-03-01 10:00', 60, ['RRULE:FREQ=DAILY;COUNT=1000'])));
            $x = $c->writes()[0];
            if ($x['status'] >= 300) {
                $c->finding = 'refused: HTTP ' . self::status($x);
                return;
            }
            $m = (string) $x['response']['id'];
            $all = $this->pages($c, 'after', 'instances, every page', '/events/' . rawurlencode($m) . '/instances', ['showDeleted' => 'true'], 20);
            $c->finding = 'accepted; ' . self::ruleStory(['RRULE:FREQ=DAILY;COUNT=1000'], $c->after[$m]['master']['recurrence'] ?? []) . '; events.instances gave ' . count($all['items']) . ' instances'
                . ($all['items'] !== [] ? ', the last at ' . self::when($all['items'][count($all['items']) - 1]['start'] ?? null) : '');
        });
    }

    // ---- 8. Mail --------------------------------------------------------------------

    private function group8(): void
    {
        $guest = (string) ($this->opts['guest'] ?? '');
        if ($guest === '') {
            $this->report->groupNote('8', 'skipped', 'it needs --guest with the address of a mailbox you own and can read; the probe sends to that address only, and you check it by hand.');
            return;
        }
        $cases = [
            ['8.1', 'a master patch, sendUpdates=none', 'master', ['summary' => 'Probe mail 1 renamed'], true],
            ['8.2', 'an instance patch, sendUpdates=none', 'instance', ['summary' => 'Probe mail 2 (one changed)'], true],
            ['8.3', 'a status-only change, sendUpdates=none', 'master', ['status' => 'tentative'], true],
            ['8.4', 'a master patch with sendUpdates left out (the default)', 'master', ['summary' => 'Probe mail 4 renamed'], false],
        ];
        foreach ($cases as $i => [$id, $title, $target, $patch, $none]) {
            $this->probeCase('8', $id, $title, function (CaseRun $c) use ($guest, $i, $target, $patch, $none): void {
                $name = 'Probe mail ' . ($i + 1);
                $body = self::timed($name, self::LA, '2027-03-01 10:00', 60, ['RRULE:FREQ=WEEKLY;COUNT=4']) + ['attendees' => [['email' => $guest]]];
                $ins = $this->insert($c, 'setup', 'insert the series with a guest (sendUpdates=none)', $body);
                $m = (string) ($ins['response']['id'] ?? throw new \RuntimeException('insert refused: HTTP ' . $ins['status']));
                $path = '/events/' . rawurlencode($target === 'master' ? $m : (string) $this->nth($c, $m, 2, 'patched')['id']);
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'the patch', 'PATCH', $path, [], $patch, [], $none));
                $x = $c->writes()[0];
                $c->finding = "manual: the insert went at {$ins['at']} with sendUpdates=none and the patch at {$x['at']} " . ($none ? 'with sendUpdates=none' : 'with sendUpdates left out') . ' (' . self::status($x) . "); check the guest mailbox for mail about '$name' after those times. The probe can't read it.";
            });
        }
    }

    // ---- 9. Live checks, by hand ------------------------------------------------------

    private function group9(): void
    {
        $this->report->groupNote('9', 'manual', implode("\n", [
            'Both run through the app on a dev install connected to the same Google account, not through the API. Use a second throwaway calendar for each, never the probe calendar while the probe runs.',
            '',
            '**9.1 A mirror upload that breaks, then finishes with Try again.**',
            '1. Make a local calendar with a few invented events, one of them a weekly series with one edited and one skipped day.',
            '2. Open its settings, choose Move to Google, into a new Google calendar.',
            '3. While the upload runs, cut the server\'s network (or stop the worker) so it stops partway; the calendar\'s settings should say it stopped and offer Try again.',
            '4. Restore the network and press Try again. Check: every event is at Google once (no duplicates), the series has its edited and skipped day, the calendar switched to Google only after the last event, and Activity has one move entry.',
            '',
            '**9.2 Adopt as local reversing a mirror.**',
            '1. On the calendar 9.1 moved, open its settings and choose Adopt as local.',
            '2. Check: the calendar is local with every event, tags and people kept; a change made here no longer goes to Google; a change made at Google no longer arrives; the copy at Google is left as it was.',
            '',
            'Write what happened under each step in this report by hand.',
        ]));
    }
}
