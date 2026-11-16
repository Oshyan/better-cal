<?php
// The probe itself (docs/design/google-series-survey.md, "The Probe"): ten
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
        '3' => ['Master changes over exceptions', 'One master change per case, each from the same starting state: a weekly 10:00 America/Los_Angeles series of ten with occurrence 3 moved a day later, occurrence 4 at its own time (15:00), occurrence 5 with its own title and occurrence 6 cancelled (DELETE). The form cases (3.18 on) start from a different state: a weekly series of ten with occurrence 2 renamed and occurrence 3 cancelled, timed or all-day as the case says, or a single event; 3.30 and 3.31 change only its ordinary occurrence 4, a timed day made all-day. Cases 3.32 to 3.35 are from the first starting state again: the master changes the app has no proof for (a link, a new frequency, a new interval) and two proven changes in one body (a rename with a new length). Cases 3.36 and 3.37 (issue 256) start from a weekly series of four, timed or all-day, with occurrence 2 renamed and occurrence 3 deleted, and switch every event to the other form and back, each request as the app sends it, reading every id a day has had a record under after each; 3.39 (issue 256 too) leaves a renamed day ordinary again over the record an earlier form dropped and sends the app\'s single-day edit and delete of it; 3.38 (issue 243) gives one ordinary day of an all-day series a clock time with its date nulled. Cases 3.40 and 3.41 do as 3.36 over a clock change and back and a rule change and back; 3.42 to 3.47 change the rule alone from the first starting state, to see which rule changes keep the cancelled record.'],
        '4' => ['The split', 'Trim with UNTIL and with COUNT, then insert the new series, over the same starting state as group 3, cut at occurrence 5. Case 4.3 splits as the app does (a weekly series of six with occurrence 4 renamed and 5 deleted, cut at 3) and sends the two days to the new series by their new instance ids.'],
        '5' => ['Etags and conditional writes', 'Stability across reads, what a master or an instance write does to the other\'s etags, and If-Match on PATCH and DELETE of masters and instances.'],
        '6' => ['Deletes and their reverse', 'A deleted event read back, PATCH to confirmed on it and on a cancelled instance, and an insert with an id the client chose.'],
        '7' => ['Sync', 'The echo of a write in the next delta, cancelled instances after a master change, an unbounded series under singleEvents=true, and the occurrence cap.'],
        '8' => ['Mail', 'Writes to a series with a guest, with sendUpdates=none and once with it left out, to be checked against the guest\'s mailbox by hand.'],
        '9' => ['Two live checks from the audit of closed issues', 'Manual: they run through the app, not the API.'],
        '10' => ['What an undo of a change to all events would meet', 'Each from a weekly 10:00 America/Los_Angeles series of ten with its own renamed and deleted days: whether a series stays still with no writes (10.1 takes ten minutes, it waits), a bound taken back and its days sent again, a split taken back, a deleted series made live again, a day under a deleted series, and a value only Google holds on one day (colorId). Each list is the singleEvents=false, showDeleted=true list by iCalUID, every field of every item printed.'],
        '11' => ['A list by iCalUID right after a write', 'Whether the list by an event\'s iCalUID (singleEvents=false, showDeleted=true) carries a write at once, as a first read of a single event that replaces a GET by id must: 30 single events inserted, each listed at once; one single event PATCHed 30 times in a row, each conditional on the etag the list before it gave; the same 30 for one day of a repeating event that has its own record; 10 single events deleted, each listed at once. Each: listed or not, the etag (and title) equal to the write\'s answer or not, the milliseconds between the write\'s answer and the list\'s, and for a mismatch how long until a re-list agrees (up to five, a second apart). Counts, no verdict. No attendees.'],
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
        // A cancelled day is listed even without showDeleted, in a short form with no marker (replay of 2026-11-13, A): nothing live, never another's event.
        $others = array_filter($this->listAll(['showDeleted' => 'false', 'singleEvents' => 'false'])['items'], fn(array $e) => !self::isMarked($e) && ($e['status'] ?? '') !== 'cancelled');
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
        $c = new CaseRun($this->report, $group, $id, $title);
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
    private function insert(CaseRun $c, string $phase, string $label, array $body, bool $sendUpdates = true, bool $markDescription = true): array
    {
        // $markDescription false: the description as given (an empty one, as the app sends a series without notes); the private property still marks it.
        if ($markDescription) {
            $body['description'] = trim((string) ($body['description'] ?? 'Probe notes') . ' ' . self::MARKER_TEXT);
        }
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
        // The zone argument stays: this tool also runs against installed copies of server/src older than the three-argument instanceId, and PHP ignores it on newer ones.
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
                'start_utc' => Time::toDb($s), 'end_utc' => Time::toDb($e), 'all_day' => 0, 'tzid' => self::LA, 'show_as' => 'busy',
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

        // A zone change that moves no occurrence: 10:00 in Los Angeles is 11:00 in
        // Denver all year (both change their clocks the same nights). Case 3.3
        // changes the zone and the instants together; this one changes the zone alone.
        $this->probeCase('3', '3.17', 'the zone alone (11:00 America/Denver, the same instants)', function (CaseRun $c) use ($span, $denver): void {
            [$m] = $this->withExceptions($c);
            $patch = $span($denver('2027-03-01 11:00'), $denver('2027-03-01 12:00'), 'America/Denver');
            $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'patch the master', 'PATCH', '/events/' . rawurlencode($m), [], $patch));
            $c->finding = $this->formFinding($c, $m);
        });

        // Timed to all-day and back, over a renamed occurrence 2 and a cancelled
        // occurrence 3 (the exceptions both forms can hold). Each change is sent
        // twice: as GoogleSeries::masterBody sends it (start and end in the new
        // form and the rule, nothing said about the old form's fields), and with
        // the old form's fields sent as nulls. The UTC series starts at 00:00 UTC,
        // where a timed occurrence and an all-day one fall on the same instant.
        $rule = ['RRULE:FREQ=WEEKLY;COUNT=10'];
        $dates = ['start' => ['date' => '2027-03-01'], 'end' => ['date' => '2027-03-02']];
        $clock = static function (string $tz, string $local): array {
            $s = new \DateTimeImmutable($local, new \DateTimeZone($tz));
            return ['start' => ['dateTime' => $s->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz], 'end' => ['dateTime' => $s->modify('+60 minutes')->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz]];
        };
        $nulled = static function (array $when): array {
            $other = isset($when['start']['date']) ? ['dateTime' => null, 'timeZone' => null] : ['date' => null];
            return ['start' => $when['start'] + $other, 'end' => $when['end'] + $other];
        };
        $flips = [
            ['3.18', 'timed (10:00 Los Angeles) to all-day, as the app sends it', self::LA, '2027-03-01 10:00', $dates],
            ['3.19', 'timed (10:00 Los Angeles) to all-day, the clock fields nulled', self::LA, '2027-03-01 10:00', $nulled($dates)],
            ['3.20', 'timed (00:00 UTC) to all-day, as the app sends it', 'UTC', '2027-03-01 00:00', $dates],
            ['3.21', 'timed (00:00 UTC) to all-day, the clock fields nulled', 'UTC', '2027-03-01 00:00', $nulled($dates)],
            ['3.22', 'all-day to timed (10:00 Los Angeles), as the app sends it', null, '2027-03-01', $clock(self::LA, '2027-03-01 10:00')],
            ['3.23', 'all-day to timed (10:00 Los Angeles), the date field nulled', null, '2027-03-01', $nulled($clock(self::LA, '2027-03-01 10:00'))],
            ['3.24', 'all-day to timed (00:00 UTC), as the app sends it', null, '2027-03-01', $clock('UTC', '2027-03-01 00:00')],
            ['3.25', 'all-day to timed (00:00 UTC), the date field nulled', null, '2027-03-01', $nulled($clock('UTC', '2027-03-01 00:00'))],
        ];
        foreach ($flips as [$id, $title, $tz, $start, $when]) {
            $this->probeCase('3', $id, $title, function (CaseRun $c) use ($tz, $start, $when, $rule): void {
                $m = $this->formBase($c, $tz, $start, $rule);
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'patch the master', 'PATCH', '/events/' . rawurlencode($m), [], $when + ['recurrence' => $rule]));
                $c->finding = $this->formFinding($c, $m);
            });
        }

        // The same change on an event that doesn't repeat: the whole body, as
        // GoogleWriter::body builds it for a single event, and then with the old
        // form's fields nulled.
        $single = static fn(bool $allDay, string $tz, string $local): array => GoogleWriter::body([
            'title' => 'Probe single', 'description' => 'Probe notes ' . self::MARKER_TEXT, 'location' => 'Probe room 1',
            'start_utc' => Time::toDb(new \DateTimeImmutable($local, new \DateTimeZone($tz))),
            'end_utc' => Time::toDb((new \DateTimeImmutable($local, new \DateTimeZone($tz)))->modify($allDay ? '+1 day' : '+60 minutes')),
            'all_day' => $allDay ? 1 : 0, 'tzid' => $tz, 'rrule' => null, 'exdates_json' => null, 'status' => 'confirmed',
        ]);
        $singles = [
            ['3.26', 'a single event, timed to all-day, as the app sends it', self::LA, '2027-03-01 10:00', $single(true, 'UTC', '2027-03-01 00:00'), false],
            ['3.27', 'a single event, timed to all-day, the clock fields nulled', self::LA, '2027-03-01 10:00', $single(true, 'UTC', '2027-03-01 00:00'), true],
            ['3.28', 'a single event, all-day to timed, as the app sends it', null, '2027-03-01', $single(false, self::LA, '2027-03-01 10:00'), false],
            ['3.29', 'a single event, all-day to timed, the date field nulled', null, '2027-03-01', $single(false, self::LA, '2027-03-01 10:00'), true],
        ];
        foreach ($singles as [$id, $title, $tz, $start, $body, $nulls]) {
            $this->probeCase('3', $id, $title, function (CaseRun $c) use ($tz, $start, $body, $nulls, $nulled): void {
                $m = $this->formBase($c, $tz, $start, []);
                $body = $nulls ? $nulled($body) + $body : $body;
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'patch the event', 'PATCH', '/events/' . rawurlencode($m), [], $body));
                $c->finding = $this->formFinding($c, $m);
            });
        }

        // One day of a timed series made all-day: the same body builder on an
        // instance (Events::patchBody, GoogleWriter::patchOf over the day as it
        // shows and as it is to be), an instance PATCH whose start and end are
        // dates over an instance whose start is a clock. Occurrence 4 of the
        // 10:00 Los Angeles series (22 March, 17:00 UTC) is an ordinary day.
        $dayRow = static fn(bool $allDay): array => [
            'title' => 'Probe form', 'description' => null, 'location' => null, 'rrule' => null, 'exdates_json' => null, 'status' => 'confirmed',
            'start_utc' => $allDay ? '2027-03-22 00:00:00' : '2027-03-22 17:00:00', 'end_utc' => $allDay ? '2027-03-23 00:00:00' : '2027-03-22 18:00:00',
            'all_day' => $allDay ? 1 : 0, 'tzid' => $allDay ? 'UTC' : self::LA,
        ];
        // Built by hand, as the other form cases are: the probe runs against the
        // installed release's source, which need not have the app's newer
        // builders. What GoogleWriter::patchOf gives for these two rows: the
        // start and the end, the only keys that differ.
        $asAllDay = GoogleWriter::body($dayRow(true));
        $dayBody = ['start' => $asAllDay['start'], 'end' => $asAllDay['end']];
        $days = [
            ['3.30', 'one day of a timed series made all-day, as the app sends it', $dayBody],
            ['3.31', 'one day of a timed series made all-day, the clock fields nulled', $nulled($dayBody) + $dayBody],
        ];
        foreach ($days as [$id, $title, $body]) {
            $this->probeCase('3', $id, $title, function (CaseRun $c) use ($body, $rule): void {
                $m = $this->formBase($c, self::LA, '2027-03-01 10:00', $rule);
                $inst = $this->instanceItems($c, $m)[3] ?? throw new \RuntimeException('the series has no occurrence 4');
                $c->labels[self::key($inst)] = 'occurrence 4 (made all-day)';
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'patch occurrence 4', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [], $body));
                $c->finding = $this->formFinding($c, $m);
            });
        }

        // The master changes the app's prediction has no proof for
        // (GoogleSeries::unproven predicts every exception dropped until a
        // case here shows them kept), each from the group's own starting
        // state and sent as the app sends a master change (the fields that
        // differ and the rule as its one line, GoogleSeries::masterBody): a
        // link set on the series, which Google holds as `source` (are the
        // exceptions kept, and is the link written into them as a title
        // is); a new frequency that leaves every exception on a day of the
        // new rule (weekly to daily); a new interval that leaves some of
        // them there (every second week: occurrences 3 and 5 stay days of
        // the rule, 4 and 6 do not). And two proven changes in one body, a
        // rename with a new length: whether they act together as each does
        // alone (3.12 and 3.2).
        $unproven = [
            ['3.32', 'a link set on the series (source)', ['source' => ['url' => 'https://example.test/probe-series', 'title' => 'example.test'], 'recurrence' => $rule]],
            ['3.33', 'the rule: a new frequency that leaves every exception on a day of it (FREQ=DAILY)', ['recurrence' => ['RRULE:FREQ=DAILY;COUNT=70']]],
            ['3.34', 'the rule: a new interval that leaves occurrences 3 and 5 on days of it (INTERVAL=2)', ['recurrence' => ['RRULE:FREQ=WEEKLY;INTERVAL=2;COUNT=10']]],
            ['3.35', 'the title and the length in one body (renamed, an hour to ninety minutes)',
                ['summary' => 'Probe weekly renamed'] + $span($la('2027-03-01 10:00'), $la('2027-03-01 11:30')) + ['recurrence' => $rule]],
        ];
        foreach ($unproven as [$id, $title, $patch]) {
            $this->probeCase('3', $id, $title, function (CaseRun $c) use ($id, $patch): void {
                [$m] = $this->withExceptions($c);
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'patch the master', 'PATCH', '/events/' . rawurlencode($m), [], $patch));
                $c->finding = ($id === '3.32' ? $this->sourceStory($c, $m) . '; ' : '') . $this->seriesFinding($c, $m);
            });
        }

        // Issue 256: a repeating event switched between timed and all-day for
        // all events, then back, over a renamed day and a deleted day, each
        // request as GoogleSeries::run sends it (backAgain). On 1.5.5 against
        // real Google the first switch went through and the switch back
        // stopped at its third request with a 412; Finish then completed it.
        // The finding reads, after every request, every id a day of the event
        // has had a record under, in both forms, and the showDeleted list.
        $this->probeCase('3', '3.36', 'switch back: timed to all-day to timed, a renamed and a deleted day, each request as the app sends it (issue 256)', function (CaseRun $c): void {
            $this->backAgain($c, 'timed 10:00', 'all-day');
        });
        $this->probeCase('3', '3.37', 'switch back, the mirror: all-day to timed to all-day, a renamed and a deleted day, each request as the app sends it (issue 256)', function (CaseRun $c): void {
            $this->backAgain($c, 'all-day', 'timed 10:00');
        });
        // The sites that address one day (a "this event" edit or delete, their
        // undo) meet a leftover too: a day ordinary again whose id holds the
        // record an earlier form dropped. Each sends the If-Match
        // GoogleSeries::condition gives it: the etag the series' list holds
        // under that id, else a GET's.
        $this->probeCase('3', '3.39', 'a day ordinary again over a leftover (renamed, reset by a switch to all-day, switched back to timed), then the app\'s single-day edit and delete of it (issue 256)', function (CaseRun $c): void {
            $this->leftoverDay($c);
        });

        // Issue 243, second comment: case 2.2a (one day of an all-day series
        // given a clock time, 400 "Invalid start time.") was sent without
        // clearing the day's date, the merge 3.18 to 3.30 showed refused for
        // that reason alone. The same change as the app would send it now
        // (Events::patchBody over the day, GoogleWriter::overForm): the clock
        // with the date named null.
        $this->probeCase('3', '3.38', 'one ordinary day of an all-day series given a clock time (15:00), the date field nulled (issue 243; 2.2a sent it without)', function (CaseRun $c) use ($rule, $nulled): void {
            $m = $this->formBase($c, null, '2027-03-01', $rule);
            $inst = $this->instanceItems($c, $m)[3] ?? throw new \RuntimeException('the series has no occurrence 4');
            $c->labels[self::key($inst)] = 'occurrence 4 (given 15:00)';
            $timed = $this->retimed($inst, '15:00');
            $body = $nulled($timed) + $timed;
            $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'patch occurrence 4', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [], $body));
            $c->finding = $this->formFinding($c, $m);
        });

        // Proof map, gap 1: back to old keys without a form switch. The same
        // as 3.36, over a change of every event that gives the days other
        // ids and a change back that returns them: the clock (10:00 to 11:00
        // and back), and the rule (weekly to daily and back, which keeps the
        // days' ids: whether a rule change and back drops and leaves anything).
        $this->probeCase('3', '3.40', 'back to old keys: every event 10:00 to 11:00 and back, a renamed and a deleted day, each request as the app sends it', function (CaseRun $c): void {
            $this->backAgain($c, 'timed 10:00', 'timed 11:00');
        });
        $this->probeCase('3', '3.41', 'back to old keys: every event weekly to daily and back, a renamed and a deleted day, each request as the app sends it', function (CaseRun $c): void {
            $this->backAgain($c, 'timed 10:00', 'daily 10:00');
        });

        // Proof map, gap 2: which rule changes keep a cancelled day's record
        // (the stand-in drops every cancelled record on any change of the
        // rule's days; 3.33 saw one kept from weekly to daily, 3.7 saw one
        // dropped for a weekday added). From the group's first state, each the
        // rule alone as GoogleSeries::masterBody sends it; occurrence 6
        // (2027-04-05, a Monday) is the cancelled one.
        $untilAfter7 = (new \DateTimeImmutable('2027-04-12 23:59:59', new \DateTimeZone(self::LA)))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $ruleChanges = [
            ['3.42', 'the rule: weekly to daily, the cancelled day still a day of it (3.33 again)', ['RRULE:FREQ=DAILY;COUNT=70']],
            ['3.43', 'the rule: a weekday added (BYDAY=MO,WE), the cancelled day still a day of it (3.7 again)', ['RRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=20']],
            ['3.44', 'the rule: the weekday changed (BYDAY=TU), the cancelled day no day of it', ['RRULE:FREQ=WEEKLY;BYDAY=TU;COUNT=10']],
            ['3.45', 'the rule: COUNT longer (12), the pattern the same', ['RRULE:FREQ=WEEKLY;COUNT=12']],
            ['3.46', 'the rule: COUNT to an UNTIL after occurrence 7, the pattern the same', ["RRULE:FREQ=WEEKLY;UNTIL=$untilAfter7"]],
            ['3.47', 'the rule: every fifth week (INTERVAL=5), the cancelled day on a kept week', ['RRULE:FREQ=WEEKLY;INTERVAL=5;COUNT=2']],
        ];
        foreach ($ruleChanges as [$id, $title, $recurrence]) {
            $this->probeCase('3', $id, $title, function (CaseRun $c) use ($recurrence): void {
                [$m] = $this->withExceptions($c);
                $this->standard($c, fn(CaseRun $c) => $this->send($c, 'write', 'patch the master', 'PATCH', '/events/' . rawurlencode($m), [], ['recurrence' => $recurrence]));
                $c->finding = $this->formFinding($c, $m);
            });
        }

        // Replay round 4, cause 1: where a day's timed and all-day keys meet at 00:00 UTC, a switch between the forms
        // hides an edited or deleted day at Google (3.21, 3.25). Which request, if any, brings the day back with its
        // own values, and what a person sees meanwhile.
        $this->probeCase('3', '3.48', 'midnight UTC, timed to all-day over a renamed day with notes and a deleted day: every way to write the renamed day back', function (CaseRun $c): void {
            $this->hiddenDay($c, 'UTC', '00:00', false, ['master', 'dropped', 'none', 'instance', 'insert', 'delete dropped', 'revive dropped'], true);
        });
        $this->probeCase('3', '3.49', 'midnight UTC, all-day to timed over a renamed day with notes and a deleted day: the main ways to write the renamed day back', function (CaseRun $c): void {
            $this->hiddenDay($c, 'UTC', '00:00', true, ['master', 'dropped', 'instance', 'delete dropped'], false);
        });
        $this->probeCase('3', '3.50', '09:00 Tokyo (00:00 UTC), timed to all-day over a renamed day with notes and a deleted day: the main ways to write it back', function (CaseRun $c): void {
            $this->hiddenDay($c, 'Asia/Tokyo', '09:00', false, ['master', 'dropped', 'instance', 'delete dropped'], false);
        });
        $this->probeCase('3', '3.51', 'midnight UTC, timed to all-day with no day edited or deleted: does every day survive', function (CaseRun $c): void {
            $this->hiddenDay($c, 'UTC', '00:00', false, [], true, false);
        });

        // Replay round 4, cause 2: a kept day's own notes cleared by a master change that does not send notes. Which
        // master writes clear them, and whether only under a series with no notes of its own (as the app creates one).
        $la = static fn(string $local, int $minutes = 60): array => self::clockAt(self::LA, $local, $minutes);
        $notesCases = [
            ['3.52', 'a link', false, ['source' => ['url' => 'https://example.test/probe-series', 'title' => 'example.test']]],
            ['3.53', 'the start a week later, along the pattern', false, $la('2027-03-08 10:00')],
            ['3.54', 'the zone at the same moments (11:00 Denver)', false, self::clockAt('America/Denver', '2027-03-01 11:00', 60)],
            ['3.55', 'the title', false, ['summary' => 'Probe notes series renamed']],
            ['3.56', 'the length (ninety minutes)', false, $la('2027-03-01 10:00', 90)],
            ['3.57', 'COUNT longer (8)', false, []],
            ['3.58', 'a link', true, ['source' => ['url' => 'https://example.test/probe-series', 'title' => 'example.test']]],
            ['3.59', 'the start a week later, along the pattern', true, $la('2027-03-08 10:00')],
            ['3.60', 'the zone at the same moments (11:00 Denver)', true, self::clockAt('America/Denver', '2027-03-01 11:00', 60)],
            ['3.61', 'a link, sent with the series\' own (empty) notes', false, ['source' => ['url' => 'https://example.test/probe-series', 'title' => 'example.test'], 'description' => '']],
        ];
        foreach ($notesCases as [$id, $what, $seriesNotes, $patch]) {
            $title = 'a day with its own notes, title and place; the series with ' . ($seriesNotes ? 'notes of its own' : 'no notes (as the app makes one)') . "; the master changed: $what";
            $this->probeCase('3', $id, $title, function (CaseRun $c) use ($seriesNotes, $patch, $id): void {
                $this->dayNotes($c, $seriesNotes, $patch + ['recurrence' => [$id === '3.57' ? 'RRULE:FREQ=WEEKLY;COUNT=8' : 'RRULE:FREQ=WEEKLY;COUNT=6']]);
            });
        }

        // Replay round 4, cause 3: a rule change that leaves only days already deleted.
        $this->probeCase('3', '3.62', 'a rule change that leaves only a deleted day (COUNT=1, the first day deleted), then the master PATCHed to confirmed', function (CaseRun $c): void {
            $x = $this->insert($c, 'setup', 'insert the series', self::timed('Probe only skipped', self::LA, '2027-03-01 10:00', 60, ['RRULE:FREQ=WEEKLY;COUNT=3']));
            $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
            $first = $this->nth($c, $m, 1, 'deleted, then the only day');
            $d = $this->send($c, 'setup', 'delete occurrence 1', 'DELETE', '/events/' . rawurlencode((string) $first['id']));
            if ($d['status'] >= 300) {
                throw new \RuntimeException('deleting occurrence 1 failed: HTTP ' . $d['status']);
            }
            $etag = self::etagNamer();
            $words = [];
            $this->standard($c, function (CaseRun $c) use ($m, $etag, &$words): void {
                foreach ([['the rule cut to COUNT=1', ['recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=1']]], ['the master PATCHed to confirmed', ['status' => 'confirmed', 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=1']]]] as [$what, $body]) {
                    $g = $c->step('read', 'get the master for its etag', $this->api->call('GET', '/events/' . rawurlencode($m)));
                    $ifMatch = is_array($g['response']) && is_string($g['response']['etag'] ?? null) ? $g['response']['etag'] : null;
                    $x = $this->send($c, 'write', $what, 'PATCH', '/events/' . rawurlencode($m), [], $body, $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
                    $words[] = "$what (If-Match " . $etag($ifMatch) . '): ' . self::answer($x, $etag);
                }
            });
            $c->finding = implode('; ', $words) . '; ' . $this->seriesFinding($c, $m);
        });

        // Cause 1's ways out: the refusal's sentence may offer only what works. The time moved an hour first, then the
        // switch, in both directions; the days made ordinary first (records at the series' values), then the switch and
        // the day's changes made again. Each day write with no If-Match, as the model of a change under "last write
        // wins" sends it (docs/design/google-change-under-last-write-wins.md).
        $this->probeCase('3', '3.63', 'midnight UTC, a renamed and a deleted day: every event an hour later first (01:00 UTC), then to all-day, the days sent again after each', function (CaseRun $c): void {
            $this->wayOut($c, 'hour first');
        });
        $this->probeCase('3', '3.64', 'all-day, a renamed and a deleted day: to timed at 01:00 UTC first, then an hour earlier (00:00 UTC), the days sent again after each', function (CaseRun $c): void {
            $this->wayOut($c, 'hour first, to timed');
        });
        $this->probeCase('3', '3.65', 'midnight UTC, a renamed and a deleted day: both made ordinary first (records at the series\' values), then every event to all-day, then the day\'s changes made again', function (CaseRun $c): void {
            $this->wayOut($c, 'ordinary first');
        });
        // The model's day writes: a PATCH with no If-Match in each state a change to all events can meet.
        $this->probeCase('3', '3.66', 'a day written with no If-Match: a live record, a revived record, a cancelled record made an edited day, a record put back to the series\' values, a record cancelled, an ordinary day cancelled', function (CaseRun $c): void {
            $this->unconditionalDays($c);
        });

        // The second independent read of the 1.5.6 branch, F1 and the notes: evidence, not a test of a theory. What a
        // kept record's start and end are after the start moves along its pattern (until now read from 3.5, whose
        // report named no end), and whether a master write that sends no recurrence clears a day's own notes too.
        $this->probeCase('3', '3.67', 'the first day moved a week later for all events (start along the pattern, the drag the app sends) over a day with its own time and length and an ordinary day renamed: each kept record\'s start and end before and after', function (CaseRun $c): void {
            $this->startAlongPattern($c);
        });
        $this->probeCase('3', '3.68', 'a series with no notes and a day with its own notes: the master PATCHed with one private property only, then with its title only, no recurrence in either; the day read after each', function (CaseRun $c): void {
            $this->notesWithoutRule($c);
        });
    }

    // ---- 3.67, 3.68: a kept record's end, and notes without a rule (second read of the 1.5.6 branch) ----

    /** Each exception record of $m in $read, by label: status, title, start and end, in words. */
    private function recordTimes(CaseRun $c, array $read): string
    {
        $parts = [];
        foreach (self::exceptions($read) as $key => $e) {
            $parts[] = $this->label($c, (string) $key) . ' ' . ($e['status'] ?? '?') . ' "' . ($e['summary'] ?? '') . '" start ' . self::when($e['start'] ?? null) . ', end ' . self::when($e['end'] ?? null);
        }
        return $parts === [] ? 'no exception records' : implode('; ', $parts);
    }

    /**
     * 3.67: a weekly 10:00 to 11:00 Los Angeles series of six from 2027-03-01 (an empty description, as the app makes
     * one); occurrence 2 given its own time and length (14:00 to 15:30), occurrence 3 renamed and left at the series'
     * time. Then the master PATCHed as the app sends a drag of the first day a week later for all events: start and end
     * on 2027-03-08 at 10:00 to 11:00, the rule as it was, If-Match the master's read. Both days remain days of the
     * series. The finding: every record's start and end before and after, then the standard story.
     */
    private function startAlongPattern(CaseRun $c): void
    {
        $rule = ['RRULE:FREQ=WEEKLY;COUNT=6'];
        $body = self::timed('Probe drag', self::LA, '2027-03-01 10:00', 60, $rule);
        $body['description'] = '';
        $x = $this->insert($c, 'setup', 'insert the series', $body, true, false);
        $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
        $two = $this->nth($c, $m, 2, 'own time and length');
        $three = $this->nth($c, $m, 3, 'renamed, at the series\' time');
        $setup = [
            $this->send($c, 'setup', 'occurrence 2 given its own time and length (14:00 to 15:30)', 'PATCH', '/events/' . rawurlencode((string) $two['id']), [], self::clockAt(self::LA, '2027-03-08 14:00', 90)),
            $this->send($c, 'setup', 'occurrence 3 renamed', 'PATCH', '/events/' . rawurlencode((string) $three['id']), [], ['summary' => 'Probe drag (one renamed)']),
        ];
        foreach ($setup as $s) {
            if ($s['status'] >= 300) {
                throw new \RuntimeException('setting up the days failed: HTTP ' . $s['status']);
            }
        }
        $etag = self::etagNamer();
        $answer = '';
        $this->standard($c, function (CaseRun $c) use ($m, $rule, $etag, &$answer): void {
            $g = $c->step('read', 'get the master for its etag', $this->api->call('GET', '/events/' . rawurlencode($m)));
            $im = is_array($g['response']) && is_string($g['response']['etag'] ?? null) ? $g['response']['etag'] : null;
            $x = $this->send($c, 'write', 'the first day a week later for all events', 'PATCH', '/events/' . rawurlencode($m), [], self::clockAt(self::LA, '2027-03-08 10:00', 60) + ['recurrence' => $rule], $im !== null ? ['If-Match: ' . $im] : []);
            $answer = 'the master PATCH (start and end on 2027-03-08 10:00 to 11:00, the rule as it was): ' . self::answer($x, $etag);
        });
        $c->sent = 'setup: a weekly 10:00 to 11:00 Los Angeles series of six from 2027-03-01, no notes; occurrence 2 at its own 14:00 to 15:30; occurrence 3 renamed; then the master PATCHed to start 2027-03-08 10:00 to 11:00, the rule as it was, If-Match the master\'s read';
        $c->finding = $answer . '; records before: ' . $this->recordTimes($c, $c->before[$m] ?? []) . '; records after: ' . $this->recordTimes($c, $c->after[$m] ?? []) . '; ' . $this->seriesFinding($c, $m);
    }

    /**
     * 3.68: a weekly 10:00 Los Angeles series of six with an empty description (as the app makes one); occurrence 2
     * given its own notes. Then two master PATCHes, neither with a recurrence, each If-Match the master's read and each
     * followed by a GET of occurrence 2: first one private extended property only (the probe's marker sent with it, so
     * it is kept), then the title only. No attendees, nothing that sends mail. The finding: occurrence 2's notes after
     * each.
     */
    private function notesWithoutRule(CaseRun $c): void
    {
        $body = self::timed('Probe notes no rule', self::LA, '2027-03-01 10:00', 60, ['RRULE:FREQ=WEEKLY;COUNT=6']);
        $body['description'] = '';
        $x = $this->insert($c, 'setup', 'insert the series', $body, true, false);
        $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
        $two = $this->nth($c, $m, 2, 'own notes');
        $r = $this->send($c, 'setup', 'occurrence 2 given its own notes', 'PATCH', '/events/' . rawurlencode((string) $two['id']), [], ['description' => 'Probe own notes of the day']);
        if ($r['status'] >= 300) {
            throw new \RuntimeException('setting up occurrence 2 failed: HTTP ' . $r['status']);
        }
        $etag = self::etagNamer();
        $story = [];
        $this->standard($c, function (CaseRun $c) use ($m, $two, $etag, &$story): void {
            $writes = [
                ['one private extended property only', ['extendedProperties' => ['private' => [self::MARKER_KEY => $this->runId, 'betterCalProbeExtra' => 'one']]]],
                ['the title only', ['summary' => 'Probe notes no rule, renamed']],
            ];
            foreach ($writes as [$what, $patch]) {
                $g = $c->step('read', 'get the master for its etag', $this->api->call('GET', '/events/' . rawurlencode($m)));
                $im = is_array($g['response']) && is_string($g['response']['etag'] ?? null) ? $g['response']['etag'] : null;
                $x = $this->send($c, 'write', "the master PATCHed with $what", 'PATCH', '/events/' . rawurlencode($m), [], $patch, $im !== null ? ['If-Match: ' . $im] : []);
                $d = $this->get($c, 'read', (string) $two['id']);
                $day = is_array($d['response']) ? $d['response'] : [];
                $story[] = "the master PATCHed with $what, no recurrence (If-Match " . $etag($im) . '): ' . self::answer($x, $etag)
                    . '; occurrence 2 after: HTTP ' . self::status($d) . ', ' . ($day['status'] ?? '?') . ', description ' . (array_key_exists('description', $day) ? json_encode($day['description'], JSON_UNESCAPED_UNICODE) : 'absent')
                    . ', title ' . json_encode($day['summary'] ?? null, JSON_UNESCAPED_UNICODE);
            }
        });
        $c->sent = 'setup: a weekly 10:00 Los Angeles series of six, no notes; occurrence 2 given its own notes; then the master PATCHed with one private extended property only, then with its title only, neither sending a recurrence, each If-Match the master\'s read and followed by a GET of occurrence 2';
        $c->finding = implode('; ', $story) . '; ' . $this->seriesFinding($c, $m);
    }

    // ---- 3.48 to 3.51: a day hidden where its two forms meet at 00:00 UTC (replay round 4, cause 1) ----

    private const HIDDEN_OWN = ['summary' => 'Probe hidden (one changed)', 'description' => 'Probe own notes of the day'];

    /**
     * A weekly series of six in zone $tz at $clock (or all-day when $allDayFirst), whose timed occurrences fall at
     * 00:00 UTC, so a day's timed and all-day ids name the same instant; occurrence 2 renamed with its own notes and
     * occurrence 3 deleted (when $edits); every event switched to the other form as the app sends it (the held form's
     * fields null, the rule). Then each of $attempts at writing occurrence 2's own values back, in order, each followed
     * by a read of every id of occurrences 2 and 3 in both forms, the showDeleted list and events.instances:
     *   master: PATCH its id in the new form, If-Match the master's listed etag;
     *   dropped: the same, If-Match the etag the list shows under its id in the old form (the dropped record);
     *   none: the same with no If-Match;
     *   instance: PATCH the id events.instances lists for that day, If-Match its own etag (when it lists the day);
     *   insert: POST a record naming the series (recurringEventId) and the day (originalStartTime), as a client would
     *     make an exception anew;
     *   delete dropped: DELETE the dropped record of the old form (its own etag), then the next read;
     *   revive dropped: PATCH the dropped record to status confirmed (its own etag).
     * After the switch, also what a person sees: the plain singleEvents=true list without showDeleted over the weeks.
     */
    private function hiddenDay(CaseRun $c, string $tz, string $clock, bool $allDayFirst, array $attempts, bool $personView, bool $edits = true): void
    {
        $rule = ['RRULE:FREQ=WEEKLY;COUNT=6'];
        $timedAt = static fn(string $date): array => self::clockAt($tz, "$date $clock", 60);
        $datesAt = static fn(string $date): array => ['start' => ['date' => $date], 'end' => ['date' => (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')]];
        $body = ['summary' => 'Probe hidden', 'description' => 'Probe series notes', 'location' => 'Probe room 1', 'recurrence' => $rule] + ($allDayFirst ? $datesAt('2027-03-01') : $timedAt('2027-03-01'));
        $x = $this->insert($c, 'setup', 'insert the series', $body);
        $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
        $uid = (string) ($x['response']['iCalUID'] ?? '');
        $date = static fn(int $n): string => (new \DateTimeImmutable('2027-03-01'))->modify('+' . (7 * ($n - 1)) . ' days')->format('Y-m-d');
        $ids = function (int $n) use ($m, $tz, $clock, $date): array {
            $d = $date($n);
            return ['timed' => GoogleWriter::instanceId($m, Time::toDb(new \DateTimeImmutable("$d $clock", new \DateTimeZone($tz))), false), 'all-day' => GoogleWriter::instanceId($m, "$d 00:00:00", true)];
        };
        $days = [];
        foreach ([2 => 'renamed, own notes', 3 => 'deleted'] as $n => $kind) {
            $label = "occurrence $n (" . ($edits ? $kind : 'ordinary') . ')';
            $days[$n] = ['kind' => $kind, 'label' => $label, 'date' => $date($n), 'ids' => $ids($n)];
            $c->labels[str_replace('-', '', $date($n))] = $label;
            $c->labels[substr($ids($n)['timed'], strlen($m) + 1)] = $label;
        }
        $from = $allDayFirst ? 'all-day' : 'timed';
        $to = $allDayFirst ? 'timed' : 'all-day';
        if ($edits) {
            $r = $this->send($c, 'setup', 'rename occurrence 2 with its own notes', 'PATCH', '/events/' . rawurlencode($days[2]['ids'][$from]), [], self::HIDDEN_OWN);
            $d = $this->send($c, 'setup', 'delete occurrence 3', 'DELETE', '/events/' . rawurlencode($days[3]['ids'][$from]));
            if ($r['status'] >= 300 || $d['status'] >= 300) {
                throw new \RuntimeException("setting up the days failed: HTTP {$r['status']}, {$d['status']}");
            }
        }
        $etag = self::etagNamer();
        $story = [];
        $this->standard($c, function (CaseRun $c) use ($m, $uid, $days, $from, $to, $allDayFirst, $timedAt, $datesAt, $rule, $attempts, $personView, $etag, $date, &$story): void {
            [$w, $list] = $this->idSnapshot($c, $m, $uid, $days, $etag, 'before the switch', true);
            $story[] = $w;
            $switch = self::overHeld($allDayFirst ? $timedAt('2027-03-01') : $datesAt('2027-03-01'), $allDayFirst) + ['recurrence' => $rule];
            $ifMatch = self::etagIn($list, $m);
            $x = $this->send($c, 'write', "every event to $to", 'PATCH', '/events/' . rawurlencode($m), [], $switch, $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
            $story[] = "every event to $to, PATCH master (If-Match " . $etag($ifMatch) . '): ' . self::answer($x, $etag);
            [$w, $list] = $this->idSnapshot($c, $m, $uid, $days, $etag, "after the switch to $to", true, is_string($x['response']['etag'] ?? null) ? $x['response']['etag'] : null);
            $story[] = $w;
            if ($personView) {
                $story[] = $this->personView($c, $m);
            }
            $newId = $days[2]['ids'][$to];
            $oldId = $days[2]['ids'][$from];
            $own = self::HIDDEN_OWN + ($allDayFirst ? $timedAt($date(2)) : $datesAt($date(2)));
            foreach ($attempts as $how) {
                $listedEtag = static function (array $list, string $id): ?string {
                    foreach ($list as $e) {
                        if (($e['id'] ?? '') === $id && is_string($e['etag'] ?? null)) {
                            return $e['etag'];
                        }
                    }
                    return null;
                };
                $label = '';
                $x = null;
                switch ($how) {
                    case 'master':
                    case 'dropped':
                    case 'none':
                        $im = $how === 'master' ? self::etagIn($list, $m) : ($how === 'dropped' ? $listedEtag($list, $oldId) : null);
                        $label = "PATCH occurrence 2's $to id, its own values, If-Match " . ($how === 'none' ? 'none' : ($how === 'master' ? "the master's (" . $etag($im) . ')' : "the dropped $from record's (" . $etag($im) . ')'));
                        $x = $this->send($c, 'write', $label, 'PATCH', '/events/' . rawurlencode($newId), [], $own, $im !== null ? ['If-Match: ' . $im] : []);
                        break;
                    case 'instance':
                        $inst = $this->pages($c, 'read', 'instances, for the day', '/events/' . rawurlencode($m) . '/instances', ['showDeleted' => 'true'])['items'];
                        $hit = null;
                        foreach ($inst as $e) {
                            $o = $e['originalStartTime'] ?? $e['start'] ?? [];
                            if (str_starts_with((string) ($o['date'] ?? (new \DateTimeImmutable((string) ($o['dateTime'] ?? '1970-01-01')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d')), $date(2))) {
                                $hit = $e;
                            }
                        }
                        if ($hit === null) {
                            $story[] = 'events.instances lists no instance for occurrence 2, so there is no instance id to PATCH';
                            continue 2;
                        }
                        $label = 'PATCH the id events.instances lists for occurrence 2 (' . str_replace($m, '{master}', (string) $hit['id']) . '), If-Match its own (' . $etag((string) ($hit['etag'] ?? '')) . ')';
                        $x = $this->send($c, 'write', $label, 'PATCH', '/events/' . rawurlencode((string) $hit['id']), [], $own, isset($hit['etag']) ? ['If-Match: ' . $hit['etag']] : []);
                        break;
                    case 'insert':
                        $label = "POST a record naming the series and occurrence 2 (recurringEventId, originalStartTime as a $to day)";
                        $orig = $allDayFirst ? ['dateTime' => $timedAt($date(2))['start']['dateTime'], 'timeZone' => $timedAt($date(2))['start']['timeZone']] : ['date' => $date(2)];
                        $x = $this->send($c, 'write', $label, 'POST', '/events', [], $own + ['recurringEventId' => $m, 'originalStartTime' => $orig, 'iCalUID' => $uid,
                            'extendedProperties' => ['private' => [self::MARKER_KEY => $this->runId]]]);
                        if ($x['status'] >= 200 && $x['status'] < 300 && isset($x['response']['id']) && $x['response']['id'] !== $m && empty($x['response']['recurringEventId'])) {
                            // A separate event, not a record of the series: delete it after the case.
                            $this->created[(string) $x['response']['id']] = true;
                            $c->created[] = (string) $x['response']['id'];
                        }
                        break;
                    case 'delete dropped':
                    case 'revive dropped':
                        $im = $listedEtag($list, $oldId);
                        $label = ($how === 'delete dropped' ? 'DELETE' : 'PATCH to status confirmed') . " the dropped $from record (" . str_replace($m, '{master}', $oldId) . '), If-Match its own (' . $etag($im) . ')';
                        $x = $how === 'delete dropped'
                            ? $this->send($c, 'write', $label, 'DELETE', '/events/' . rawurlencode($oldId), [], null, $im !== null ? ['If-Match: ' . $im] : [])
                            : $this->send($c, 'write', $label, 'PATCH', '/events/' . rawurlencode($oldId), [], ['status' => 'confirmed'], $im !== null ? ['If-Match: ' . $im] : []);
                        break;
                }
                $story[] = "$label: " . self::answer($x, $etag) . (is_array($x['response']) && isset($x['response']['id']) ? ', the answer\'s id ' . str_replace($m, '{master}', (string) $x['response']['id']) . ', recurringEventId ' . (isset($x['response']['recurringEventId']) ? 'set' : 'none') : '');
                [$w, $list] = $this->idSnapshot($c, $m, $uid, $days, $etag, 'after that', true);
                $story[] = $w;
            }
        });
        $c->sent = 'setup: a weekly series of six ' . ($allDayFirst ? 'all-day' : "at $clock $tz (00:00 UTC)") . ' from 2027-03-01' . ($edits ? ', occurrence 2 renamed with its own notes, occurrence 3 deleted' : '') . "; every event to $to as the app sends it; then "
            . ($attempts === [] ? 'nothing more' : 'occurrence 2 written back: ' . implode(', ', $attempts)) . '; every id of occurrences 2 and 3 in both forms, the showDeleted list and events.instances read after each request';
        $c->finding = implode('; ', $story) . '; ' . $this->syncStory($c);
    }

    /** What a person sees of the series in Google Calendar over its weeks: the plain singleEvents=true list without showDeleted. */
    private function personView(CaseRun $c, string $m): string
    {
        $v = $this->pages($c, 'read', 'what a person sees: singleEvents=true, no showDeleted', '/events', ['singleEvents' => 'true', 'showDeleted' => 'false', 'timeMin' => '2027-02-28T00:00:00Z', 'timeMax' => '2027-04-12T00:00:00Z']);
        $shown = array_values(array_filter($v['items'], static fn(array $e): bool => ($e['recurringEventId'] ?? '') === $m));
        return 'what a person sees (singleEvents=true, no showDeleted): ' . ($shown === [] ? 'no day of the series' : implode(' | ', array_map(static fn(array $e): string => (string) ($e['start']['date'] ?? substr((string) ($e['start']['dateTime'] ?? '?'), 0, 16)) . ' ' . (string) ($e['summary'] ?? ''), $shown)));
    }

    // ---- 3.63 to 3.66: ways out of a hidden day, and day writes with no If-Match ----

    /**
     * A way out of a hidden day (3.63 to 3.65). A weekly series of six from 2027-03-01 in zone UTC (at 00:00, or
     * all-day for 'hour first, to timed'), occurrence 2 renamed with its own notes, occurrence 3 deleted; then $way's
     * requests in order, each followed by a read of every id of occurrences 2 and 3 (timed at 00:00 UTC, at 01:00 UTC,
     * and all-day), the showDeleted list and events.instances; at the end, what a person sees. Master writes as the app
     * sends them (If-Match the listed etag; on a switch the held form's fields null); day writes with no If-Match.
     *   hour first: an hour later, the days sent again at 01:00; then all-day, the days sent again.
     *   hour first, to timed: to timed at 01:00, the days sent again; then an hour earlier (00:00), the days sent again.
     *   ordinary first: both days put back to the series' values (occurrence 3 made live), then all-day, then
     *     occurrence 2 given its own values again and occurrence 3 deleted again.
     */
    private function wayOut(CaseRun $c, string $way): void
    {
        $rule = ['RRULE:FREQ=WEEKLY;COUNT=6'];
        $allDayFirst = $way === 'hour first, to timed';
        $at = static fn(string $date, string $clock): array => self::clockAt('UTC', "$date $clock", 60);
        $dates = static fn(string $date): array => ['start' => ['date' => $date], 'end' => ['date' => (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')]];
        $x = $this->insert($c, 'setup', 'insert the series', ['summary' => 'Probe hidden', 'description' => 'Probe series notes', 'location' => 'Probe room 1', 'recurrence' => $rule]
            + ($allDayFirst ? $dates('2027-03-01') : $at('2027-03-01', '00:00')));
        $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
        $uid = (string) ($x['response']['iCalUID'] ?? '');
        $series = ['summary' => (string) ($x['response']['summary'] ?? ''), 'description' => (string) ($x['response']['description'] ?? ''), 'location' => (string) ($x['response']['location'] ?? '')];
        $date = static fn(int $n): string => (new \DateTimeImmutable('2027-03-01'))->modify('+' . (7 * ($n - 1)) . ' days')->format('Y-m-d');
        $days = [];
        foreach ([2 => 'renamed, own notes', 3 => 'deleted'] as $n => $kind) {
            $d = $date($n);
            $ids = ['00:00 UTC' => GoogleWriter::instanceId($m, "$d 00:00:00", false), '01:00 UTC' => GoogleWriter::instanceId($m, "$d 01:00:00", false), 'all-day' => GoogleWriter::instanceId($m, "$d 00:00:00", true)];
            $days[$n] = ['kind' => $kind, 'label' => "occurrence $n ($kind)", 'date' => $d, 'ids' => $ids];
            foreach ($ids as $id) {
                $c->labels[substr($id, strlen($m) + 1)] = "occurrence $n ($kind)";
            }
        }
        $first = $allDayFirst ? 'all-day' : '00:00 UTC';
        $r = $this->send($c, 'setup', 'rename occurrence 2 with its own notes', 'PATCH', '/events/' . rawurlencode($days[2]['ids'][$first]), [], self::HIDDEN_OWN);
        $d = $this->send($c, 'setup', 'delete occurrence 3', 'DELETE', '/events/' . rawurlencode($days[3]['ids'][$first]));
        if ($r['status'] >= 300 || $d['status'] >= 300) {
            throw new \RuntimeException("setting up the days failed: HTTP {$r['status']}, {$d['status']}");
        }
        $whenOf = static fn(int $n, string $form): array => $form === 'all-day' ? $dates($date($n)) : $at($date($n), substr($form, 0, 5));
        $own = static fn(int $n, string $form): array => self::HIDDEN_OWN + $whenOf($n, $form);
        $plain = static fn(int $n, string $form): array => $series + ['status' => 'confirmed'] + $whenOf($n, $form);
        $cancel = ['status' => 'cancelled'];
        $toAllDay = self::overHeld($dates('2027-03-01'), false) + ['recurrence' => $rule];
        $plan = match ($way) {
            'hour first' => [
                ['master', 'every event an hour later (01:00 UTC)', $at('2027-03-01', '01:00') + ['recurrence' => $rule]],
                ['day', 2, '01:00 UTC', 'occurrence 2 sent again with its own values', $own(2, '01:00 UTC')],
                ['day', 3, '01:00 UTC', 'occurrence 3 cancelled again', $cancel],
                ['master', 'every event to all-day', $toAllDay],
                ['day', 2, 'all-day', 'occurrence 2 sent again with its own values', $own(2, 'all-day')],
                ['day', 3, 'all-day', 'occurrence 3 cancelled again', $cancel],
            ],
            'hour first, to timed' => [
                ['master', 'every event to timed at 01:00 UTC', self::overHeld($at('2027-03-01', '01:00'), true) + ['recurrence' => $rule]],
                ['day', 2, '01:00 UTC', 'occurrence 2 sent again with its own values', $own(2, '01:00 UTC')],
                ['day', 3, '01:00 UTC', 'occurrence 3 cancelled again', $cancel],
                ['master', 'every event an hour earlier (00:00 UTC)', $at('2027-03-01', '00:00') + ['recurrence' => $rule]],
                ['day', 2, '00:00 UTC', 'occurrence 2 sent again with its own values', $own(2, '00:00 UTC')],
                ['day', 3, '00:00 UTC', 'occurrence 3 cancelled again', $cancel],
            ],
            'ordinary first' => [
                ['day', 2, '00:00 UTC', 'occurrence 2 put back to the series\' values', $plain(2, '00:00 UTC')],
                ['day', 3, '00:00 UTC', 'occurrence 3 brought back at the series\' values', $plain(3, '00:00 UTC')],
                ['master', 'every event to all-day', $toAllDay],
                ['day', 2, 'all-day', 'occurrence 2 given its own values again', $own(2, 'all-day')],
                ['day', 3, 'all-day', 'occurrence 3 deleted again', $cancel],
            ],
        };
        $etag = self::etagNamer();
        $story = [];
        $this->standard($c, function (CaseRun $c) use ($m, $uid, $days, $plan, $etag, &$story): void {
            [$w, $list] = $this->idSnapshot($c, $m, $uid, $days, $etag, 'before', true);
            $story[] = $w;
            foreach ($plan as $p) {
                $expect = null;
                if ($p[0] === 'master') {
                    [, $what, $body] = $p;
                    $im = self::etagIn($list, $m);
                    $x = $this->send($c, 'write', $what, 'PATCH', '/events/' . rawurlencode($m), [], $body, $im !== null ? ['If-Match: ' . $im] : []);
                    $story[] = "$what, PATCH master (If-Match " . $etag($im) . '): ' . self::answer($x, $etag);
                    $expect = is_string($x['response']['etag'] ?? null) ? $x['response']['etag'] : null;
                } else {
                    [, $n, $form, $what, $body] = $p;
                    $x = $this->send($c, 'write', $what, 'PATCH', '/events/' . rawurlencode($days[$n]['ids'][$form]), [], $body);
                    $story[] = "$what, PATCH its $form id, no If-Match: " . self::answer($x, $etag);
                }
                [$w, $list] = $this->idSnapshot($c, $m, $uid, $days, $etag, 'after that', true, $expect);
                $story[] = $w;
            }
            $story[] = $this->personView($c, $m);
        });
        $c->sent = 'setup: a weekly series of six ' . ($allDayFirst ? 'all-day' : 'at 00:00 UTC') . ' from 2027-03-01, occurrence 2 renamed with its own notes, occurrence 3 deleted; then '
            . implode('; ', array_map(static fn(array $p): string => $p[0] === 'master' ? $p[1] : "{$p[3]} ({$p[2]} id, no If-Match)", $plan))
            . '; every id of occurrences 2 and 3 in three forms, the showDeleted list and events.instances read after each request; what a person sees at the end';
        $c->finding = implode('; ', $story) . '; ' . $this->syncStory($c);
    }

    /**
     * 3.66: what Google does with a PATCH of a day's id that carries no If-Match, in each state a change to all events
     * can meet. A weekly 10:00 Los Angeles series of six: occurrence 2 given its own title, notes and place (a live
     * record); occurrence 3 deleted (a cancelled record); occurrence 4 given its own title, then dropped and revived by
     * COUNT=3 and COUNT=6 (10.2: a live record at the series' values); occurrence 5 ordinary (no record). Then, each
     * with no If-Match and each followed by a read of the four days' ids, the showDeleted list and events.instances:
     * occurrence 2 written whole with other own values; 4 written whole with own values; 3 written whole, confirmed,
     * with own values; 2 put back to the series' values; 4 cancelled; 5 cancelled.
     */
    private function unconditionalDays(CaseRun $c): void
    {
        $rule = static fn(int $n): array => ['recurrence' => ["RRULE:FREQ=WEEKLY;COUNT=$n"]];
        $x = $this->insert($c, 'setup', 'insert the series', self::timed('Probe unconditional', self::LA, '2027-03-01 10:00', 60, $rule(6)['recurrence']));
        $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
        $uid = (string) ($x['response']['iCalUID'] ?? '');
        $series = ['summary' => (string) ($x['response']['summary'] ?? ''), 'description' => (string) ($x['response']['description'] ?? ''), 'location' => (string) ($x['response']['location'] ?? '')];
        $items = $this->instanceItems($c, $m);
        $kinds = [2 => 'a live record', 3 => 'a cancelled record', 4 => 'a revived record', 5 => 'ordinary'];
        $inst = [];
        $days = [];
        foreach ($kinds as $n => $kind) {
            $inst[$n] = $items[$n - 1] ?? throw new \RuntimeException("the series has no occurrence $n");
            $c->labels[self::key($inst[$n])] = "occurrence $n ($kind)";
            $days[$n] = ['kind' => $kind, 'label' => "occurrence $n ($kind)", 'ids' => ['timed' => (string) $inst[$n]['id']]];
        }
        $path = static fn(int $n): string => '/events/' . rawurlencode((string) $inst[$n]['id']);
        $when = static fn(int $n): array => ['start' => $inst[$n]['start'], 'end' => $inst[$n]['end']];
        $own = static fn(int $n, string $title): array => ['summary' => $title, 'description' => 'Probe own notes of the day', 'location' => 'Probe own room', 'status' => 'confirmed'] + $when($n);
        $setup = [
            $this->send($c, 'setup', 'occurrence 2 given its own title, notes and place', 'PATCH', $path(2), [], $own(2, 'Probe unconditional (one changed)')),
            $this->send($c, 'setup', 'delete occurrence 3', 'DELETE', $path(3)),
            $this->send($c, 'setup', 'occurrence 4 given its own title', 'PATCH', $path(4), [], ['summary' => 'Probe unconditional (to be dropped)']),
        ];
        foreach ([['the rule cut to COUNT=3, dropping occurrence 4', $rule(3)], ['the rule back to COUNT=6, reviving occurrence 4', $rule(6)]] as [$what, $body]) {
            $g = $c->step('setup', 'get the master for its etag', $this->api->call('GET', '/events/' . rawurlencode($m)));
            $im = is_array($g['response']) && is_string($g['response']['etag'] ?? null) ? $g['response']['etag'] : null;
            $setup[] = $this->send($c, 'setup', $what, 'PATCH', '/events/' . rawurlencode($m), [], $body, $im !== null ? ['If-Match: ' . $im] : []);
        }
        foreach ($setup as $s) {
            if ($s['status'] >= 300) {
                throw new \RuntimeException('setting up the days failed: HTTP ' . $s['status']);
            }
        }
        $writes = [
            [2, 'occurrence 2 (a live record) written whole with other own values', $own(2, 'Probe unconditional (written again)')],
            [4, 'occurrence 4 (a revived record) written whole with own values', $own(4, 'Probe unconditional (revived, written)')],
            [3, 'occurrence 3 (a cancelled record) written whole, confirmed, with own values', $own(3, 'Probe unconditional (was deleted)')],
            [2, 'occurrence 2 (a live record) put back to the series\' values', $series + ['status' => 'confirmed'] + $when(2)],
            [4, 'occurrence 4 (a live record) cancelled', ['status' => 'cancelled']],
            [5, 'occurrence 5 (ordinary, no record) cancelled', ['status' => 'cancelled']],
        ];
        $etag = self::etagNamer();
        $story = [];
        $this->standard($c, function (CaseRun $c) use ($m, $uid, $days, $writes, $path, $etag, &$story): void {
            [$w] = $this->idSnapshot($c, $m, $uid, $days, $etag, 'before', true);
            $story[] = $w;
            foreach ($writes as [$n, $what, $body]) {
                $x = $this->send($c, 'write', $what, 'PATCH', $path($n), [], $body);
                $r = is_array($x['response']) ? $x['response'] : [];
                $story[] = "$what, PATCH, no If-Match: " . self::answer($x, $etag) . ($x['status'] < 300 ? ', summary ' . json_encode($r['summary'] ?? null, JSON_UNESCAPED_UNICODE) . ', description ' . json_encode($r['description'] ?? null, JSON_UNESCAPED_UNICODE) : '');
                [$w] = $this->idSnapshot($c, $m, $uid, $days, $etag, 'after that', true);
                $story[] = $w;
            }
        });
        $c->sent = 'setup: a weekly 10:00 Los Angeles series of six, occurrence 2 with its own title, notes and place, 3 deleted, 4 renamed then dropped (COUNT=3) and revived (COUNT=6), 5 ordinary; then, each PATCH with no If-Match: '
            . implode('; ', array_map(static fn(array $w): string => $w[1], $writes)) . '; the four days\' ids, the showDeleted list and events.instances read after each request';
        $c->finding = implode('; ', $story) . '; ' . $this->syncStory($c);
    }

    // ---- 3.52 to 3.61: a kept day's own notes and a master change (replay round 4, cause 2) ----

    /**
     * A weekly 10:00 Los Angeles series of six, with notes of its own or none (as the app creates one: an empty
     * description), occurrence 2 given its own title, notes and place; then the master PATCH $patch, conditional on the
     * master's read. The finding: each record's title, notes and place before and after.
     */
    private function dayNotes(CaseRun $c, bool $seriesNotes, array $patch): void
    {
        $body = self::timed('Probe notes series', self::LA, '2027-03-01 10:00', 60, ['RRULE:FREQ=WEEKLY;COUNT=6']);
        $body['description'] = $seriesNotes ? 'Probe series notes' : '';
        $x = $this->insert($c, 'setup', 'insert the series', $body, true, false);
        $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
        $inst = $this->nth($c, $m, 2, 'own title, notes and place');
        $r = $this->send($c, 'setup', 'occurrence 2 given its own title, notes and place', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [],
            ['summary' => 'Probe notes (one changed)', 'description' => 'Probe own notes of the day', 'location' => 'Probe own room']);
        if ($r['status'] >= 300) {
            throw new \RuntimeException('setting up occurrence 2 failed: HTTP ' . $r['status']);
        }
        $before = '';
        $this->standard($c, function (CaseRun $c) use ($m, $patch, &$before): void {
            $g = $c->step('read', 'get the master for its etag', $this->api->call('GET', '/events/' . rawurlencode($m)));
            $ifMatch = is_array($g['response']) && is_string($g['response']['etag'] ?? null) ? $g['response']['etag'] : null;
            $before = self::short(is_array($g['response']) ? ($g['response']['description'] ?? null) : null);
            $this->send($c, 'write', 'patch the master', 'PATCH', '/events/' . rawurlencode($m), [], $patch, $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
        });
        $c->finding = 'the series\' description before: ' . $before . '; ' . $this->seriesFinding($c, $m);
    }

    // ---- 3.36, 3.37, 3.40, 3.41: back to old keys (issue 256) ----

    private const SWITCH_RULE = ['RRULE:FREQ=WEEKLY;COUNT=4'];
    private const SWITCH_OWN_TITLE = 'Probe switch (one changed)';

    /**
     * A weekly series of four at 10:00 Los Angeles with occurrence 2
     * renamed; every event switched to all-day with no day step (the
     * change that lets edited days reset), then back to timed the same way,
     * so occurrence 2 is an ordinary day again and its timed id holds the
     * record the first switch dropped. Then that day edited (a new title)
     * and deleted as Events sends each: one PATCH or DELETE on its timed id,
     * conditional on the etag GoogleSeries::condition reads for it (the
     * showDeleted list's under that id, else a GET's). Every id of the day,
     * in both forms, read after each request.
     */
    private function leftoverDay(CaseRun $c): void
    {
        $x = $this->insert($c, 'setup', 'insert the series', self::timed('Probe switch', self::LA, '2027-03-01 10:00', 60, self::SWITCH_RULE));
        $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
        $uid = (string) ($x['response']['iCalUID'] ?? '');
        $inst = $this->instanceItems($c, $m)[1] ?? throw new \RuntimeException('the series has no occurrence 2');
        $label = 'occurrence 2 (renamed, then reset)';
        $c->labels['20270308'] = $c->labels['20270308T180000Z'] = $label;
        $days = [2 => ['kind' => 'renamed', 'label' => $label, 'date' => '2027-03-08',
            'ids' => ['timed' => GoogleWriter::instanceId($m, '2027-03-08 18:00:00', false), 'all-day' => GoogleWriter::instanceId($m, '2027-03-08 00:00:00', true)]]];
        $r = $this->send($c, 'setup', 'rename occurrence 2', 'PATCH', '/events/' . rawurlencode((string) $inst['id']), [], ['summary' => self::SWITCH_OWN_TITLE]);
        if ($r['status'] >= 300) {
            throw new \RuntimeException("setting up the renamed day failed: HTTP {$r['status']}");
        }
        $names = [];
        $etag = static function (?string $e) use (&$names): string {
            if ($e === null || $e === '') {
                return 'none';
            }
            return $names[$e] ??= 'E' . (count($names) + 1);
        };
        $story = [];
        $this->standard($c, function (CaseRun $c) use ($m, $uid, $days, $etag, &$story): void {
            [$words, $list] = $this->idSnapshot($c, $m, $uid, $days, $etag, 'before', true);
            $story[] = $words;
            $forms = [
                'to all-day' => self::overHeld(['start' => ['date' => '2027-03-01'], 'end' => ['date' => '2027-03-02']], false),
                'back to timed' => self::overHeld(self::clockAt(self::LA, '2027-03-01 10:00', 60), true),
            ];
            foreach ($forms as $how => $when) {
                $ifMatch = self::etagIn($list, $m);
                $x = $this->send($c, 'write', "every event $how, no day step", 'PATCH', '/events/' . rawurlencode($m), [], $when + ['recurrence' => self::SWITCH_RULE], $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
                $story[] = "every event $how, PATCH master (If-Match " . $etag($ifMatch) . '): ' . self::answer($x, $etag);
                [$words, $list] = $this->idSnapshot($c, $m, $uid, $days, $etag, "after $how", true, is_string($x['response']['etag'] ?? null) ? $x['response']['etag'] : null);
                $story[] = $words;
            }
            $id = $days[2]['ids']['timed'];
            foreach (['the single-day edit, PATCH {"summary"}' => ['PATCH', ['summary' => 'Probe switch (edited here)']], 'the single-day delete, DELETE' => ['DELETE', null]] as $what => [$method, $body]) {
                $ifMatch = self::etagIn($list, $id);
                $from = 'the list\'s etag under that id';
                if ($ifMatch === null) {
                    $g = $c->step('read', 'get the day for its etag', $this->api->call('GET', '/events/' . rawurlencode($id)));
                    $ifMatch = is_array($g['response']) && is_string($g['response']['etag'] ?? null) ? $g['response']['etag'] : null;
                    $from = 'a GET\'s etag (the list holds nothing under that id)';
                }
                $x = $this->send($c, 'write', $what, $method, '/events/' . rawurlencode($id), [], $body, $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
                $story[] = "$what at " . str_replace($m, '{master}', $id) . ' (If-Match ' . $etag($ifMatch) . ", $from): " . self::answer($x, $etag);
                [$words, $list] = $this->idSnapshot($c, $m, $uid, $days, $etag, 'after that', true);
                $story[] = $words;
            }
        });
        $c->sent = 'setup: a weekly series of four (timed, 10:00 America/Los_Angeles) from 2027-03-01, occurrence 2 renamed; then every event to all-day and back to timed, each a master PATCH alone [If-Match] (the old form\'s fields null, the rule); '
            . 'then occurrence 2 edited (PATCH {"summary"}) and deleted (DELETE) on its timed id, each [If-Match] the etag GoogleSeries::condition reads for it';
        $c->finding = implode('; ', $story) . '; ' . $this->syncStory($c);
    }

    /** The shapes a back-again case moves a series between: timed or all-day, its clock in Los Angeles, its rule. */
    private const SHAPES = [
        'timed 10:00' => ['allDay' => false, 'clock' => '10:00', 'rule' => self::SWITCH_RULE],
        'all-day' => ['allDay' => true, 'clock' => null, 'rule' => self::SWITCH_RULE],
        'timed 11:00' => ['allDay' => false, 'clock' => '11:00', 'rule' => self::SWITCH_RULE],
        'daily 10:00' => ['allDay' => false, 'clock' => '10:00', 'rule' => ['RRULE:FREQ=DAILY;COUNT=22']],
    ];

    /**
     * Issue 256 and its wider family (proof map, gap 1): a weekly series of
     * four from 2027-03-01 in the shape $from, occurrence 2 renamed and
     * occurrence 3 deleted (DELETE on its instance id, as a day's delete is
     * sent), then every event changed to the shape $to and back to $from,
     * each change as the app sends it (changeAll). The way back returns the
     * days to ids the first change dropped their records from. Before the
     * first change and after every request: what Google answers for the
     * master and for both days' ids in every shape the case uses
     * (idSnapshot). Etags are named E1, E2... in the order they are first
     * seen, so the If-Match a request carried can be told apart from the
     * etag its target held.
     */
    private function backAgain(CaseRun $c, string $from, string $to): void
    {
        $a = self::SHAPES[$from];
        $b = self::SHAPES[$to];
        $body = $a['allDay'] ? self::allDay('Probe switch', '2027-03-01', $a['rule']) : self::timed('Probe switch', self::LA, '2027-03-01 ' . $a['clock'], 60, $a['rule']);
        $x = $this->insert($c, 'setup', 'insert the series', $body);
        $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
        $uid = (string) ($x['response']['iCalUID'] ?? '');
        $items = $this->instanceItems($c, $m);
        $days = [];
        foreach ([2 => 'renamed', 3 => 'deleted'] as $n => $kind) {
            $inst = $items[$n - 1] ?? throw new \RuntimeException("the series has no occurrence $n");
            $date = (new \DateTimeImmutable('2027-03-01'))->modify('+' . (7 * ($n - 1)) . ' days')->format('Y-m-d');
            $label = "occurrence $n ($kind)";
            $ids = [];
            foreach ([$a, $b] as $s) {
                $c->labels[self::keyWord($s, $date)] = $label;
                $ids[self::idName($s)] = GoogleWriter::instanceId($m, self::dayKey($s, $date), $s['allDay']);
            }
            $days[$n] = ['kind' => $kind, 'label' => $label, 'date' => $date, 'ids' => $ids];
            $path = '/events/' . rawurlencode((string) $inst['id']);
            $r = $kind === 'deleted'
                ? $this->send($c, 'setup', "delete occurrence $n", 'DELETE', $path)
                : $this->send($c, 'setup', "rename occurrence $n", 'PATCH', $path, [], ['summary' => self::SWITCH_OWN_TITLE]);
            if ($r['status'] >= 300) {
                throw new \RuntimeException("setting up the $kind day failed: HTTP {$r['status']}");
            }
        }
        $etag = self::etagNamer();
        $story = [];
        $this->standard($c, function (CaseRun $c) use ($m, $uid, $days, $a, $b, $from, $to, $etag, &$story): void {
            [$words, $list] = $this->idSnapshot($c, $m, $uid, $days, $etag, 'before the first change', true);
            $story[] = $words;
            [$words, $list] = $this->changeAll($c, $m, $uid, $days, $a, $b, $list, $etag, "change 1 (to $to)");
            $story = array_merge($story, $words);
            [$words] = $this->changeAll($c, $m, $uid, $days, $b, $a, $list, $etag, "change 2 (back to $from)");
            $story = array_merge($story, $words);
        });
        $c->sent = "setup: a weekly series of four ($from" . ($a['allDay'] ? '' : ' America/Los_Angeles') . ', ' . implode(' ', $a['rule']) . ') from 2027-03-01, occurrence 2 renamed (PATCH summary), occurrence 3 deleted (DELETE); '
            . "then every event changed to $to (" . implode(' ', $b['rule']) . ') and back, each as GoogleSeries::run sends it: PATCH the master [If-Match] (the start and end when they change, said over the held form with the old form\'s fields null, and the rule), '
            . 'then PATCH occurrence 2 restored (its own title, the series\' other fields) and PATCH occurrence 3 {"status":"cancelled"}, each [If-Match] the etag the app holds then; a refused step stops the change, and the steps left go as Finish sends them';
        $c->finding = implode('; ', $story) . '; ' . $this->syncStory($c);
    }

    /** E1, E2...: an etag's name within one case, in the order first seen. */
    private static function etagNamer(): \Closure
    {
        $names = [];
        return static function (?string $e) use (&$names): string {
            if ($e === null || $e === '') {
                return 'none';
            }
            return $names[$e] ??= 'E' . (count($names) + 1);
        };
    }

    /** A day's key as stored (UTC, DB form) in a shape. */
    private static function dayKey(array $s, string $date): string
    {
        return $s['allDay'] ? "$date 00:00:00" : Time::toDb(new \DateTimeImmutable("$date {$s['clock']}", new \DateTimeZone(self::LA)));
    }

    /** A day's key as Probe::key reads it from a resource (the instance id's suffix) in a shape. */
    private static function keyWord(array $s, string $date): string
    {
        return $s['allDay'] ? str_replace('-', '', $date) : (new \DateTimeImmutable(self::dayKey($s, $date), new \DateTimeZone('UTC')))->format('Ymd\THis\Z');
    }

    /** What a shape's ids are called in the words: the form and clock (two rules on the same clock share their ids). */
    private static function idName(array $s): string
    {
        return $s['allDay'] ? 'all-day' : 'timed ' . $s['clock'];
    }

    /** A day's start and end in a shape: dates, or an hour from its clock in Los Angeles. */
    private static function shapeWhen(array $s, string $date): array
    {
        return $s['allDay']
            ? ['start' => ['date' => $date], 'end' => ['date' => (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')]]
            : self::clockAt(self::LA, "$date {$s['clock']}", 60);
    }

    /**
     * One change of every event from shape $from to shape $to, as
     * GoogleSeries::run sends it: the master PATCH over the master as $list
     * read it (GoogleSeries::masterBody: the start and end when the form or
     * clock changes, said over the held form as GoogleWriter::overForm says
     * it, and the rule as its one line), conditional on that read's master
     * etag (the change's condition); the series read back until it shows
     * that write (readBack's expected etag), its master etag the next
     * If-Match; then the day steps (daySequence).
     *
     * @return array{0:list<string>, 1:list<array>} the words, and the last showDeleted list read
     */
    private function changeAll(CaseRun $c, string $m, string $uid, array $days, array $from, array $to, array $list, \Closure $etag, string $name): array
    {
        $words = [];
        $ifMatch = self::etagIn($list, $m);
        $body = [];
        if ($from['allDay'] !== $to['allDay'] || $from['clock'] !== $to['clock']) {
            $body = self::overHeld(self::shapeWhen($to, '2027-03-01'), $from['allDay']);
        }
        $body['recurrence'] = $to['rule'];
        $x = $this->send($c, 'write', "$name: patch the master", 'PATCH', '/events/' . rawurlencode($m), [], $body, $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
        $words[] = "$name, PATCH master (If-Match " . $etag($ifMatch) . '): ' . self::answer($x, $etag);
        if ($x['status'] < 200 || $x['status'] >= 300) {
            return [$words, $list];
        }
        [$snap, $list] = $this->idSnapshot($c, $m, $uid, $days, $etag, "after $name's master PATCH", true, is_string($x['response']['etag'] ?? null) ? $x['response']['etag'] : null);
        $words[] = $snap;
        $chain = self::etagIn($list, $m) ?? (is_string($x['response']['etag'] ?? null) ? $x['response']['etag'] : null);
        [$more, $list] = $this->daySequence($c, $m, $days, $to, $list, $chain, $etag, $name,
            fn(string $at): array => $this->idSnapshot($c, $m, $uid, $days, $etag, $at));
        return [array_merge($words, $more), $list];
    }

    /**
     * The day steps of a change to all events after its master write, as
     * GoogleSeries::run sends them (stepsFor's order: edited days, then
     * skips), each conditional on the etag the app holds then ($chain, then
     * each answer's). The first refused step stops the change, as it stops
     * the app's change half done; the steps still needed then go as Finish
     * sends them (the series read again, its master etag the first If-Match,
     * each answer's the next). $snap reads every id after each request:
     * fn(string $at): array{0:string, 1:list<array>} (words, the series' list).
     *
     * @return array{0:list<string>, 1:list<array>}
     */
    private function daySequence(CaseRun $c, string $sid, array $days, array $shape, array $list, ?string $chain, \Closure $etag, string $name, \Closure $snap): array
    {
        $words = [];
        foreach (['as the change sends them' => false, 'as Finish sends them' => true] as $how => $finish) {
            if ($finish) {
                $words[] = "$name stopped there, as the app's change does; the steps still needed go $how, after a fresh read";
                [$w, $list] = $snap("$name, Finish's read");
                $words[] = $w;
                $chain = self::etagIn($list, $sid);
            }
            $steps = $this->daySteps($sid, $days, $shape, $list);
            if ($steps === []) {
                $words[] = "$name, $how: no day step needed (the read already holds both days as the change wants them)";
                break;
            }
            $stopped = false;
            foreach ($steps as [$label, $id, $stepBody]) {
                $x = $this->send($c, 'write', "$name, $how: $label", 'PATCH', '/events/' . rawurlencode($id), [], $stepBody, $chain !== null ? ['If-Match: ' . $chain] : []);
                $words[] = "$name, $how, $label at " . str_replace($sid, '{series}', $id) . ' (If-Match ' . $etag($chain) . '): ' . self::answer($x, $etag);
                [$w, $list] = $snap('after that step');
                $words[] = $w;
                if ($x['status'] < 200 || $x['status'] >= 300) {
                    $stopped = true;
                    break;
                }
                $chain = is_string($x['response']['etag'] ?? null) ? $x['response']['etag'] : $chain;
            }
            if (!$stopped || $finish) {
                break;
            }
        }
        return [$words, $list];
    }

    /**
     * The day steps GoogleSeries::stepsFor makes over a read of series $m in
     * a shape: the renamed day restored unless a live record in the
     * master's form at its key already holds its own title, the deleted day
     * cancelled unless a cancelled record there holds it. The id is the
     * record's as listed, else GoogleWriter::instanceId's in the master's
     * form; a dropped record (cancelled, no originalStartTime) is no day of
     * the series (GoogleSeries::held) and says nothing. The restored day's
     * body is GoogleSeries::exceptionBody's (its fields, its start and end,
     * the link cleared), said over the form its id's resource holds when one
     * is listed (stepsFor's $over). Compared by title alone where stepsFor
     * compares every field: here nothing else differs.
     *
     * @return list<array{0:string, 1:string, 2:array}> label, id, body
     */
    private function daySteps(string $m, array $days, array $shape, array $list): array
    {
        $master = null;
        $records = [];
        $forms = [];
        foreach ($list as $e) {
            if (($e['id'] ?? '') === $m) {
                $master = $e;
            }
            $dropped = ($e['status'] ?? '') === 'cancelled' && !isset($e['originalStartTime']);
            if (isset($e['id']) && is_array($e['start'] ?? null) && !$dropped) {
                $forms[(string) $e['id']] = isset($e['start']['date']);
            }
            if (($e['recurringEventId'] ?? '') === $m && isset($e['originalStartTime']) && isset($e['originalStartTime']['date']) === $shape['allDay']) {
                $records[self::key($e)] = $e;
            }
        }
        $out = [];
        foreach ($days as $n => $d) {
            $record = $records[self::keyWord($shape, $d['date'])] ?? null;
            $id = $record !== null ? (string) $record['id'] : GoogleWriter::instanceId($m, self::dayKey($shape, $d['date']), $shape['allDay']);
            if ($d['kind'] === 'deleted') {
                if (($record['status'] ?? '') !== 'cancelled') {
                    $out[] = ["occurrence $n cancelled (the skip step)", $id, ['status' => 'cancelled']];
                }
                continue;
            }
            if ($record !== null && ($record['status'] ?? '') !== 'cancelled' && ($record['summary'] ?? '') === self::SWITCH_OWN_TITLE) {
                continue;
            }
            $body = ['summary' => self::SWITCH_OWN_TITLE, 'description' => (string) ($master['description'] ?? ''), 'location' => (string) ($master['location'] ?? ''), 'status' => 'confirmed']
                + self::shapeWhen($shape, $d['date']) + ['source' => null];
            $out[] = ["occurrence $n restored (the edited-day step)", $id, self::overHeld($body, $forms[$id] ?? $shape['allDay'])];
        }
        return $out;
    }

    /**
     * Proof map, gap 3: a "this and following" split as the app sends it
     * (GoogleSeries::splitTargets, then run): a weekly series of six at
     * 10:00 Los Angeles from 2027-03-01, occurrence 4 renamed and occurrence
     * 5 deleted; cut at occurrence 3. The old series trimmed with UNTIL just
     * before the cut (its master PATCH, the rule alone, conditional on its
     * read); the new series inserted under an id the client chose
     * (insertChosen; the rule alone, insertBody), read back until it shows,
     * its master etag the first If-Match; then its two days by their new
     * instance ids (daySequence). Every id of both days under both series,
     * and both series' showDeleted lists, read after each request.
     */
    private function splitCarry(CaseRun $c): void
    {
        $x = $this->insert($c, 'setup', 'insert the series', self::timed('Probe split', self::LA, '2027-03-01 10:00', 60, ['RRULE:FREQ=WEEKLY;COUNT=6']));
        $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
        $uid = (string) ($x['response']['iCalUID'] ?? '');
        $items = $this->instanceItems($c, $m);
        $shape = self::SHAPES['timed 10:00'];
        $days = [];
        foreach ([4 => 'renamed', 5 => 'deleted'] as $n => $kind) {
            $inst = $items[$n - 1] ?? throw new \RuntimeException("the series has no occurrence $n");
            $date = (new \DateTimeImmutable('2027-03-01'))->modify('+' . (7 * ($n - 1)) . ' days')->format('Y-m-d');
            $label = "occurrence $n ($kind)";
            $c->labels[self::keyWord($shape, $date)] = $label;
            $days[$n] = ['kind' => $kind, 'label' => $label, 'date' => $date, 'ids' => ['old series' => GoogleWriter::instanceId($m, self::dayKey($shape, $date), false)]];
            $path = '/events/' . rawurlencode((string) $inst['id']);
            $r = $kind === 'deleted'
                ? $this->send($c, 'setup', "delete occurrence $n", 'DELETE', $path)
                : $this->send($c, 'setup', "rename occurrence $n", 'PATCH', $path, [], ['summary' => self::SWITCH_OWN_TITLE]);
            if ($r['status'] >= 300) {
                throw new \RuntimeException("setting up the $kind day failed: HTTP {$r['status']}");
            }
        }
        $etag = self::etagNamer();
        $story = [];
        $chosen = 'probe' . bin2hex(random_bytes(12));
        $this->standard($c, function (CaseRun $c) use ($m, $uid, &$days, $shape, $chosen, $etag, &$story): void {
            [$words, $list] = $this->idSnapshot($c, $m, $uid, $days, $etag, 'before the split', true);
            $story[] = $words;
            // The cut: occurrence 3, 2027-03-15 10:00 Los Angeles (17:00 UTC); UNTIL a second before it.
            $ifMatch = self::etagIn($list, $m);
            $x = $this->send($c, 'write', 'trim the old series', 'PATCH', '/events/' . rawurlencode($m), [], ['recurrence' => ['RRULE:FREQ=WEEKLY;UNTIL=20270315T165959Z']], $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
            $story[] = 'PATCH the old master, UNTIL before occurrence 3 (If-Match ' . $etag($ifMatch) . '): ' . self::answer($x, $etag);
            [$words] = $this->idSnapshot($c, $m, $uid, $days, $etag, 'after the trim', true, is_string($x['response']['etag'] ?? null) ? $x['response']['etag'] : null);
            $story[] = $words;
            $x = $this->insert($c, 'write', 'insert the new series under a chosen id', self::timed('Probe split', self::LA, '2027-03-15 10:00', 60, ['RRULE:FREQ=WEEKLY;COUNT=4']) + ['id' => $chosen]);
            $story[] = 'POST the new series under a chosen id: ' . self::answer($x, $etag) . (($x['response']['id'] ?? null) === $chosen ? ', the chosen id' : ', another id');
            if ($x['status'] < 200 || $x['status'] >= 300) {
                return;
            }
            $new = (string) $x['response']['id'];
            $newUid = (string) ($x['response']['iCalUID'] ?? '');
            foreach ($days as $n => $d) {
                $days[$n]['ids']['new series'] = GoogleWriter::instanceId($new, self::dayKey($shape, $d['date']), false);
            }
            $also = ['id' => $new, 'uid' => $newUid];
            [$words, , $newList] = $this->idSnapshot($c, $m, $uid, $days, $etag, 'after the insert', false, null, $also + ['expect' => is_string($x['response']['etag'] ?? null) ? $x['response']['etag'] : null]);
            $story[] = $words;
            $snap = function (string $at) use ($c, $m, $uid, &$days, $etag, $also): array {
                [$w, , $l] = $this->idSnapshot($c, $m, $uid, $days, $etag, $at, false, null, $also);
                return [$w, $l];
            };
            [$more] = $this->daySequence($c, $new, $days, $shape, $newList, self::etagIn($newList, $new), $etag, 'the new series\' days', $snap);
            $story = array_merge($story, $more);
        });
        $c->sent = 'setup: a weekly series of six (timed, 10:00 America/Los_Angeles) from 2027-03-01, occurrence 4 renamed, occurrence 5 deleted; then a split at occurrence 3 as the app sends it: PATCH the old master [If-Match] {"recurrence":["RRULE:FREQ=WEEKLY;UNTIL=20270315T165959Z"]}, '
            . 'POST the new series (2027-03-15 10:00, COUNT=4) under a chosen id, then PATCH its occurrence 4 restored and its occurrence 5 {"status":"cancelled"} by the new instance ids, each [If-Match] the etag the app holds then; a refused step stops, and the steps left go as Finish sends them';
        $parts = [implode('; ', $story), 'old series: ' . $this->masterStory($c, $m), 'its exceptions: ' . $this->exceptionStory($c, $m)];
        foreach (array_diff($c->series, [$m]) as $new) {
            $parts[] = 'new series exceptions: ' . $this->exceptionStory($c, $new);
            $parts[] = 'new ' . $this->instancesStory($c, $new);
        }
        $parts[] = $this->syncStory($c);
        $c->finding = implode('; ', $parts);
    }

    /**
     * What Google answers now for the series and for every id a day of it
     * has had a record under, in every shape: a GET of the master and of
     * each id (HTTP status, the `status` field, originalStartTime,
     * recurringEventId, etag; one GET, a 404 being an answer here), the
     * singleEvents=false list by iCalUID with showDeleted=true (every item
     * but the master, the same fields), and with $instances events.instances.
     * $expect: the master etag a write just answered; the list is read
     * again, a bounded number of times with backoff, until it shows it, as
     * the app's readBack does. $also: a second series (`id`, `uid`, and an
     * optional `expect`), whose list is read and named the same way, its id
     * written {new}.
     *
     * @return array{0:string, 1:list<array>, 2:list<array>} the words, the series' list, the second series' list
     */
    private function idSnapshot(CaseRun $c, string $m, string $uid, array $days, \Closure $etag, string $at, bool $instances = false, ?string $expect = null, ?array $also = null): array
    {
        $raw = static fn(mixed $v): string => $v === null ? 'none' : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $name = static fn(string $id): string => str_replace(array_values(array_filter([$m, $also['id'] ?? null])), array_slice(['{master}', '{new}'], 0, $also !== null ? 2 : 1), $id);
        $res = static fn(array $e): string => (string) ($e['status'] ?? 'no status') . ', originalStartTime ' . $raw($e['originalStartTime'] ?? null)
            . ', recurringEventId ' . (isset($e['recurringEventId']) ? 'set' : 'none') . ', etag ' . $etag(isset($e['etag']) ? (string) $e['etag'] : null);
        $parts = [];
        $g = $c->step('read', 'get the master', $this->api->call('GET', '/events/' . rawurlencode($m)));
        $parts[] = 'master: GET ' . $g['status'] . (is_array($g['response']) && $g['status'] === 200 ? ' ' . (string) ($g['response']['status'] ?? '') . ', start ' . $raw($g['response']['start'] ?? null) . ', recurrence ' . $raw($g['response']['recurrence'] ?? null) . ', etag ' . $etag(isset($g['response']['etag']) ? (string) $g['response']['etag'] : null) : '');
        foreach ($days as $d) {
            foreach ($d['ids'] as $form => $id) {
                $g = $c->step('read', "get {$d['label']}, $form id", $this->api->call('GET', '/events/' . rawurlencode($id)));
                $parts[] = "{$d['label']} $form id " . $name($id) . ': GET ' . $g['status'] . (is_array($g['response']) && $g['status'] === 200 ? ' ' . $res($g['response']) : '');
            }
        }
        $lists = [];
        foreach (array_values(array_filter([[$m, $uid, $expect, 'the showDeleted list'], $also !== null ? [$also['id'], $also['uid'], $also['expect'] ?? null, 'the new series\' showDeleted list'] : null])) as [$sid, $u, $exp, $what]) {
            for ($try = 1; ; $try++) {
                $list = $this->pages($c, 'read', "$what", '/events', ['iCalUID' => $u, 'showDeleted' => 'true', 'singleEvents' => 'false'])['items'];
                if ($exp === null || self::etagIn($list, $sid) === $exp || $try >= self::READ_TRIES) {
                    break;
                }
                ($this->sleep)(min(8.0, 0.5 * (2 ** ($try - 1))));
            }
            $others = array_values(array_filter($list, static fn(array $e): bool => ($e['id'] ?? '') !== $sid));
            $parts[] = $what . ($try > 1 ? " (read $try times until it showed the write)" : '') . ': master etag ' . $etag(self::etagIn($list, $sid)) . '; '
                . ($others === [] ? 'no other item' : implode(' | ', array_map(static fn(array $e): string => $name((string) ($e['id'] ?? '?')) . ' ' . $res($e), $others)));
            $lists[] = $list;
        }
        if ($instances) {
            $inst = $this->pages($c, 'read', 'instances, showDeleted', '/events/' . rawurlencode($m) . '/instances', ['showDeleted' => 'true']);
            $parts[] = 'events.instances (HTTP ' . $inst['status'] . '): ' . ($inst['items'] === [] ? 'none' : implode(' | ', array_map(static fn(array $e): string => $name((string) ($e['id'] ?? '?')) . ' ' . (string) ($e['status'] ?? '?') . ', start ' . $raw($e['start'] ?? null) . ', etag ' . $etag(isset($e['etag']) ? (string) $e['etag'] : null), $inst['items'])));
        }
        return ["[$at] " . implode('; ', $parts), $lists[0], $lists[1] ?? []];
    }

    /** What a write was answered with: the status, and on success the resource's `status` and etag. */
    private static function answer(array $x, \Closure $etag): string
    {
        if ($x['status'] < 200 || $x['status'] >= 300) {
            return 'HTTP ' . self::status($x);
        }
        $r = is_array($x['response']) ? $x['response'] : [];
        return 'HTTP ' . $x['status'] . ', status ' . (string) ($r['status'] ?? 'none') . ', etag ' . $etag(isset($r['etag']) ? (string) $r['etag'] : null);
    }

    /** The etag of the item with $id in a list, or null. */
    private static function etagIn(array $list, string $id): ?string
    {
        foreach ($list as $e) {
            if (($e['id'] ?? '') === $id && isset($e['etag']) && is_string($e['etag'])) {
                return $e['etag'];
            }
        }
        return null;
    }

    /** A start and end at a local clock time in a zone, as GoogleWriter::body sends a timed row. */
    private static function clockAt(string $tz, string $local, int $minutes): array
    {
        $s = new \DateTimeImmutable($local, new \DateTimeZone($tz));
        return ['start' => ['dateTime' => $s->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz], 'end' => ['dateTime' => $s->modify("+$minutes minutes")->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz]];
    }

    /**
     * GoogleWriter::overForm's rule, built here as the other form cases build
     * their bodies (the probe runs against the installed release's source):
     * a start or end in the form other than the held one names the held
     * form's fields as null.
     */
    private static function overHeld(array $body, bool $heldAllDay): array
    {
        foreach (['start', 'end'] as $k) {
            if (is_array($body[$k] ?? null) && isset($body[$k]['date']) !== $heldAllDay) {
                $body[$k] += isset($body[$k]['date']) ? ['dateTime' => null, 'timeZone' => null] : ['date' => null];
            }
        }
        return $body;
    }

    /** The link (`source`) of the master and of each exception record between the two reads: whether a link set on a series is written into its exceptions. */
    private function sourceStory(CaseRun $c, string $id): string
    {
        $url = static fn(?array $e): string => isset($e['source']['url']) ? "'" . $e['source']['url'] . "'" : 'none';
        $parts = ['master ' . $url($c->before[$id]['master'] ?? null) . ' -> ' . $url($c->after[$id]['master'] ?? null)];
        $before = self::exceptions($c->before[$id] ?? []);
        foreach (self::exceptions($c->after[$id] ?? []) as $key => $e) {
            $parts[] = $this->label($c, (string) $key) . ' ' . $url($before[$key] ?? null) . ' -> ' . $url($e);
        }
        return 'the link (source): ' . implode(', ', $parts);
    }

    /**
     * The starting state of the form cases: a weekly series of ten from
     * 2027-03-01, at a clock time in a zone or all-day ($tz null), with
     * occurrence 2 renamed and occurrence 3 cancelled; with no rule, one event.
     */
    private function formBase(CaseRun $c, ?string $tz, string $start, array $rule): string
    {
        $body = $tz === null ? self::allDay('Probe form', $start, $rule) : self::timed('Probe form', $tz, $start, 60, $rule);
        if ($rule === []) {
            unset($body['recurrence']);
        }
        $x = $this->insert($c, 'setup', $rule === [] ? 'insert the event' : 'insert the series', $body);
        $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the starting event was refused: HTTP ' . $x['status']));
        if ($rule === []) {
            return $m;
        }
        $items = $this->instanceItems($c, $m);
        foreach ([2 => 'own title', 3 => 'cancelled'] as $n => $kind) {
            $inst = $items[$n - 1] ?? throw new \RuntimeException("the series has no occurrence $n");
            $c->labels[self::key($inst)] = "occurrence $n ($kind)";
            $path = '/events/' . rawurlencode((string) $inst['id']);
            $x = $kind === 'cancelled'
                ? $this->send($c, 'setup', "cancel occurrence $n", 'DELETE', $path)
                : $this->send($c, 'setup', "occurrence $n renamed", 'PATCH', $path, [], ['summary' => 'Probe form (one changed)']);
            if ($x['status'] >= 300) {
                throw new \RuntimeException("setting up the $kind exception failed: HTTP {$x['status']}");
            }
        }
        return $m;
    }

    /**
     * The finding of a form case: what the write got, the master's start and
     * end as they read back, every other record of the series with its id's
     * suffix and its own originalStartTime, the same for the delta, then the
     * standard finding.
     */
    private function formFinding(CaseRun $c, string $m): string
    {
        $raw = static fn(mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $record = static function (array $e) use ($m, $raw): string {
            $id = (string) ($e['id'] ?? '');
            return ($id === $m ? '{master}' : str_replace($m, '{master}', $id)) . ' ' . (string) ($e['status'] ?? 'no status')
                . ', originalStartTime ' . $raw($e['originalStartTime'] ?? null)
                . (isset($e['start']) ? ', start ' . $raw($e['start']) : ', no start')
                . (isset($e['recurringEventId']) ? '' : ', no recurringEventId');
        };
        $parts = [];
        foreach ($c->writes() as $x) {
            $parts[] = 'the write: HTTP ' . self::status($x);
        }
        $master = $c->after[$m]['master'] ?? null;
        $parts[] = is_array($master)
            ? 'the master reads back start ' . $raw($master['start'] ?? null) . ', end ' . $raw($master['end'] ?? null) . ', recurrence ' . $raw($master['recurrence'] ?? null)
            : 'the master could not be read back (HTTP ' . (int) ($c->after[$m]['status'] ?? 0) . ')';
        $others = array_values(array_filter($c->after[$m]['list'] ?? [], static fn(array $e): bool => ($e['id'] ?? '') !== $m));
        $parts[] = 'records in the singleEvents=false list: ' . ($others === [] ? 'none' : implode(' | ', array_map($record, $others)));
        $delta = array_values(array_filter($c->delta['items'] ?? [], fn(array $e): bool => $this->belongs($c, $e)));
        $parts[] = 'the delta: ' . ($delta === [] ? 'nothing of this case' : implode(' | ', array_map($record, $delta)));
        $parts[] = $this->seriesFinding($c, $m);
        return implode('; ', $parts);
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
        // Proof map, gap 3: the split as the app sends it, with an edited and
        // a deleted day after the cut carried onto the new series.
        $this->probeCase('4', '4.3', 'a split as the app sends it: trim with UNTIL, insert the new series under a chosen id, then its renamed and deleted days by their new instance ids', function (CaseRun $c): void {
            $this->splitCarry($c);
        });
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

    // ---- 10. What an undo of a change to all events would meet ----------------------

    private const UNDO_TITLE = 'Probe undo';
    private const UNDO_OWN_TITLE = 'Probe undo (one changed)';
    private const UNDO_RULE = ['RRULE:FREQ=WEEKLY;COUNT=10'];

    // ---- 11. A list by iCalUID right after a write ---------------------------------

    private function group11(): void
    {
        $uidList = fn(CaseRun $c, string $uid, string $label): array => $this->pages($c, 'read', $label, '/events', ['iCalUID' => $uid, 'singleEvents' => 'false', 'showDeleted' => 'true'])['items'];
        $byId = static function (array $items, string $id): ?array {
            foreach ($items as $e) {
                if ((string) ($e['id'] ?? '') === $id) {
                    return $e;
                }
            }
            return null;
        };
        $ms = static fn(float $from): int => (int) round((microtime(true) - $from) * 1000);

        $this->probeCase('11', '11.1', 'thirty single events inserted, each listed by its iCalUID at once: listed, with the etag the insert returned', function (CaseRun $c) use ($uidList, $byId, $ms): void {
            $rows = [];
            for ($i = 1; $i <= 30; $i++) {
                $x = $this->insert($c, 'write', "insert single event $i", self::timed("Probe list $i", self::LA, '2027-06-' . sprintf('%02d', min(28, $i)) . ' 10:00', 60));
                $at = microtime(true);
                $r = is_array($x['response']) ? $x['response'] : [];
                if (!isset($r['id'], $r['iCalUID'])) {
                    $rows[] = ['write' => 'HTTP ' . $x['status']];
                    continue;
                }
                $want = fn(array $items): bool => ($e = $byId($items, (string) $r['id'])) !== null && ($e['etag'] ?? null) === ($r['etag'] ?? null);
                $rows[] = $this->listedAfter($c, $at, $ms, fn(string $l): array => $uidList($c, (string) $r['iCalUID'], $l), static fn(array $items): array => [($e = $byId($items, (string) $r['id'])) !== null, $want($items)]);
            }
            $c->sent = 'thirty times: POST a single timed event (marked), then at once GET /events?iCalUID=<its uid>&singleEvents=false&showDeleted=true';
            $c->finding = self::listedStory($rows, 'the insert', 'with the etag it returned');
        });

        $this->probeCase('11', '11.2', 'one single event PATCHed thirty times in a row, each conditional on the etag the list before it gave, each listed by its iCalUID at once: the etag and title the PATCH returned', function (CaseRun $c) use ($uidList, $byId, $ms): void {
            $x = $this->insert($c, 'setup', 'insert the single event', self::timed('Probe list again', self::LA, '2027-06-10 10:00', 60));
            $id = (string) ($x['response']['id'] ?? throw new \RuntimeException('the event was refused: HTTP ' . $x['status']));
            $uid = (string) ($x['response']['iCalUID'] ?? '');
            $etag = ($byId($uidList($c, $uid, 'list for the first etag'), $id)['etag'] ?? null) ?? ($x['response']['etag'] ?? null);
            $rows = $this->patchedInARow($c, $id, $etag, static fn(int $i): array => ['summary' => "Probe list again ($i)"], $uidList, $uid, $byId, $ms);
            $c->sent = 'one single timed event; thirty times: PATCH its title, If-Match the etag the list before gave (after a 412, a GET\'s), then at once the list by its iCalUID';
            $c->finding = self::listedStory($rows, 'the PATCH');
        });

        $this->probeCase('11', '11.3', 'one day of a repeating event that has its own record PATCHed thirty times in a row, each conditional on the etag the list before it gave, the series listed by its iCalUID at once: the day\'s etag and title as the PATCH returned', function (CaseRun $c) use ($uidList, $byId, $ms): void {
            $x = $this->insert($c, 'setup', 'insert the series', self::timed('Probe list series', self::LA, '2027-06-07 10:00', 60, ['RRULE:FREQ=WEEKLY;COUNT=4']));
            $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
            $uid = (string) ($x['response']['iCalUID'] ?? '');
            $day = (string) $this->nth($c, $m, 2, 'the day written thirty times')['id'];
            $r = $this->send($c, 'setup', 'occurrence 2 given its own title (its own record)', 'PATCH', '/events/' . rawurlencode($day), [], ['summary' => 'Probe list series (own)']);
            if ($r['status'] >= 300) {
                throw new \RuntimeException('setting up occurrence 2 failed: HTTP ' . $r['status']);
            }
            $etag = ($byId($uidList($c, $uid, 'list for the first etag'), $day)['etag'] ?? null) ?? ($r['response']['etag'] ?? null);
            $rows = $this->patchedInARow($c, $day, $etag, static fn(int $i): array => ['summary' => "Probe list series (own $i)"], $uidList, $uid, $byId, $ms);
            $c->sent = 'a weekly series of four, occurrence 2 given its own title; thirty times: PATCH that day\'s title, If-Match the etag the list before gave (after a 412, a GET\'s), then at once the series\' list by its iCalUID';
            $c->finding = self::listedStory($rows, 'the PATCH');
        });

        $this->probeCase('11', '11.4', 'ten single events deleted, each listed by its iCalUID with showDeleted at once: listed cancelled', function (CaseRun $c) use ($uidList, $byId, $ms): void {
            $rows = [];
            for ($i = 1; $i <= 10; $i++) {
                $x = $this->insert($c, 'setup', "insert single event $i", self::timed("Probe list gone $i", self::LA, '2027-06-' . sprintf('%02d', $i) . ' 15:00', 60));
                $id = (string) ($x['response']['id'] ?? throw new \RuntimeException('the event was refused: HTTP ' . $x['status']));
                $uid = (string) ($x['response']['iCalUID'] ?? '');
                $d = $this->send($c, 'write', "delete single event $i", 'DELETE', '/events/' . rawurlencode($id));
                $at = microtime(true);
                if ($d['status'] >= 300) {
                    $rows[] = ['write' => 'HTTP ' . $d['status']];
                    continue;
                }
                $rows[] = $this->listedAfter($c, $at, $ms, fn(string $l): array => $uidList($c, $uid, $l), static fn(array $items): array => [($e = $byId($items, $id)) !== null, ($e['status'] ?? null) === 'cancelled']);
            }
            $c->sent = 'ten times: POST a single timed event (marked), DELETE it, then at once the list by its iCalUID with showDeleted=true';
            $c->finding = self::listedStory($rows, 'the delete', 'cancelled');
        });
    }

    /**
     * The list right after a write, and re-lists (up to five, a second apart) until it agrees. $check(items) gives
     * [listed, agrees]. Returns listed, agree, ms (write's answer to the first list's), and for a mismatch the re-list
     * that agreed (its number and ms) or none.
     */
    private function listedAfter(CaseRun $c, float $at, \Closure $ms, \Closure $list, \Closure $check): array
    {
        $items = $list('list at once');
        $first = $ms($at);
        [$listed, $agree] = $check($items);
        $row = ['listed' => $listed, 'agree' => $agree, 'ms' => $first];
        if (!$agree) {
            $row['agreedAt'] = null;
            for ($n = 1; $n <= 5; $n++) {
                ($this->sleep)(1.0);
                [, $ok] = $check($list("re-list $n"));
                if ($ok) {
                    $row['agreedAt'] = ['n' => $n, 'ms' => $ms($at)];
                    break;
                }
            }
        }
        return $row;
    }

    /** Thirty conditional PATCHes of one id, each on the etag the list before gave (a GET's after a 412), each listed at once. */
    private function patchedInARow(CaseRun $c, string $id, ?string $etag, \Closure $body, \Closure $uidList, string $uid, \Closure $byId, \Closure $ms): array
    {
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $x = $this->send($c, 'write', "PATCH $i, If-Match the last list's etag", 'PATCH', '/events/' . rawurlencode($id), [], $body($i), $etag !== null ? ['If-Match: ' . $etag] : []);
            $at = microtime(true);
            if ($x['status'] === 412) {
                $rows[] = ['write' => 'HTTP 412'];
                $g = $this->get($c, 'read', $id);
                $etag = is_array($g['response']) && is_string($g['response']['etag'] ?? null) ? $g['response']['etag'] : null;
                continue;
            }
            $r = is_array($x['response']) ? $x['response'] : [];
            if ($x['status'] >= 300) {
                $rows[] = ['write' => 'HTTP ' . $x['status']];
                continue;
            }
            $items = [];
            $row = $this->listedAfter($c, $at, $ms, static function (string $l) use ($uidList, $c, $uid, &$items): array {
                return $items = $uidList($c, $uid, $l);
            }, static fn(array $got): array => [($e = $byId($got, $id)) !== null, $e !== null && ($e['etag'] ?? null) === ($r['etag'] ?? null) && ($e['summary'] ?? null) === ($r['summary'] ?? null)]);
            $rows[] = $row;
            // The next PATCH is conditional on what the first list gave, as the app's next edit would be.
            $etag = ($byId($items, $id)['etag'] ?? null) ?? $etag;
        }
        return $rows;
    }

    /** Counts over the rows, no verdict. */
    private static function listedStory(array $rows, string $write, string $agreeWord = 'with the etag (and title) it returned'): string
    {
        $done = array_values(array_filter($rows, static fn(array $r): bool => !isset($r['write'])));
        $refused = array_count_values(array_column(array_filter($rows, static fn(array $r): bool => isset($r['write'])), 'write'));
        $mss = array_column($done, 'ms');
        sort($mss);
        $late = array_values(array_filter($done, static fn(array $r): bool => !$r['agree']));
        $parts = [count($rows) . ' writes; ' . implode(', ', array_map(static fn(string $k, int $n): string => "$n answered $k", array_keys($refused), $refused)) . ($refused === [] ? 'every one taken' : '')];
        $parts[] = 'listed at once after ' . count(array_filter($done, static fn(array $r): bool => $r['listed'])) . ' of ' . count($done) . ' taken';
        $parts[] = 'the list agreed with ' . $write . ' (' . $agreeWord . ') at once for ' . (count($done) - count($late)) . ' of ' . count($done);
        if ($mss !== []) {
            $parts[] = 'milliseconds from the write\'s answer to the list\'s: least ' . $mss[0] . ', median ' . $mss[intdiv(count($mss), 2)] . ', most ' . $mss[count($mss) - 1];
        }
        if ($late !== []) {
            $parts[] = 'where it did not: ' . implode('; ', array_map(static fn(array $r): string => ($r['listed'] ? 'listed, not agreeing' : 'not listed') . ', '
                . ($r['agreedAt'] === null ? 'no re-list of five agreed' : 'agreed at re-list ' . $r['agreedAt']['n'] . ', ' . $r['agreedAt']['ms'] . ' ms after the write'), $late));
        }
        return implode('; ', $parts);
    }

    private function group10(): void
    {
        $this->probeCase('10', '10.1', 'does a series stay still: the list at once, after 60 seconds and after 10 minutes, no writes between', function (CaseRun $c): void {
            [, $uid] = $this->undoBase($c, [2 => 'renamed', 3 => 'deleted']);
            $lists = [];
            foreach (['at once' => 0, 'after 60 seconds' => 60, 'after 10 minutes' => 540] as $when => $wait) {
                if ($wait > 0) {
                    ($this->sleep)((float) $wait); // the question is what changes with time alone
                }
                $lists[$when] = $this->undoList($c, $uid, "the list $when");
            }
            $c->sent = 'setup: a weekly series of ten, occurrence 2 renamed, occurrence 3 deleted; then three lists (singleEvents=false, showDeleted=true), at once, after 60 seconds and after 10 minutes, no writes';
            $keys = array_keys($lists);
            $c->finding = 'at once to after 60 seconds: ' . self::listDiff($lists[$keys[0]], $lists[$keys[1]]) . '; after 60 seconds to after 10 minutes: ' . self::listDiff($lists[$keys[1]], $lists[$keys[2]])
                . '; the list at once: ' . $this->rawList($lists[$keys[0]]);
        });

        $this->probeCase('10', '10.2', 'a bound taken back: end the rule after occurrence 6, then COUNT=10 again, then occurrence 8 and 9 sent again', function (CaseRun $c): void {
            [$m, $uid] = $this->undoBase($c, [8 => 'renamed', 9 => 'deleted']);
            $etag = self::etagNamer();
            $words = [];
            $list = $this->undoList($c, $uid, 'the list before');
            foreach ([['RRULE:FREQ=WEEKLY;COUNT=6'], self::UNDO_RULE] as $i => $rule) {
                $ifMatch = self::etagIn($list, $m);
                $x = $this->send($c, 'write', $i === 0 ? 'end the rule after occurrence 6' : 'restore COUNT=10', 'PATCH', '/events/' . rawurlencode($m), [], ['recurrence' => $rule], $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
                $words[] = 'PATCH master ' . implode(' ', $rule) . ' (If-Match ' . $etag($ifMatch) . '): ' . self::answer($x, $etag);
                $list = $this->undoList($c, $uid, 'the list after');
                $words[] = 'the list after it: ' . $this->rawList($list);
            }
            $words[] = 'at the ids of occurrences 8 and 9 now: ' . $this->undoIds($c, $m, [8, 9], $etag);
            foreach ([8 => ['summary' => self::UNDO_OWN_TITLE], 9 => ['status' => 'cancelled']] as $n => $body) {
                $g = $c->step('read', 'get the master for its etag', $this->api->call('GET', '/events/' . rawurlencode($m)));
                $ifMatch = is_array($g['response']) && is_string($g['response']['etag'] ?? null) ? $g['response']['etag'] : null;
                $id = $this->undoId($m, $n);
                $x = $this->send($c, 'write', "occurrence $n sent again", 'PATCH', '/events/' . rawurlencode($id), [], $body, $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
                $words[] = "PATCH occurrence $n " . json_encode($body) . " (If-Match the master's, " . $etag($ifMatch) . '): ' . self::answer($x, $etag);
            }
            $words[] = 'the list after both: ' . $this->rawList($this->undoList($c, $uid, 'the list at the end'));
            $c->finding = implode('; ', $words);
        });

        $this->probeCase('10', '10.3', 'a split taken back: bound after occurrence 5, a second series from 6 with a renamed day; then the first rule restored, its edited day sent again, the second master deleted', function (CaseRun $c): void {
            [$m, $uid] = $this->undoBase($c, [7 => 'renamed']);
            $etag = self::etagNamer();
            $words = [];
            $list = $this->undoList($c, $uid, 'the list before');
            // Occurrence 6 is 2027-04-05 10:00 Los Angeles (17:00 UTC); UNTIL a second before it.
            $ifMatch = self::etagIn($list, $m);
            $x = $this->send($c, 'write', 'bound the first series after occurrence 5', 'PATCH', '/events/' . rawurlencode($m), [], ['recurrence' => ['RRULE:FREQ=WEEKLY;UNTIL=20270405T165959Z']], $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
            $words[] = 'PATCH the first master, UNTIL before occurrence 6 (If-Match ' . $etag($ifMatch) . '): ' . self::answer($x, $etag);
            $chosen = 'probe' . bin2hex(random_bytes(12));
            $x = $this->insert($c, 'write', 'insert the second series under a chosen id', self::timed(self::UNDO_TITLE, self::LA, '2027-04-05 10:00', 60, ['RRULE:FREQ=WEEKLY;COUNT=5']) + ['id' => $chosen]);
            $words[] = 'POST the second series from occurrence 6 under a chosen id: ' . self::answer($x, $etag);
            if ($x['status'] >= 300) {
                $c->finding = implode('; ', $words);
                return;
            }
            $newUid = (string) ($x['response']['iCalUID'] ?? '');
            $newDay = $this->undoId($chosen, 7);
            $x = $this->send($c, 'write', 'rename its day (occurrence 7)', 'PATCH', '/events/' . rawurlencode($newDay), [], ['summary' => self::UNDO_OWN_TITLE], is_string($x['response']['etag'] ?? null) ? ['If-Match: ' . $x['response']['etag']] : []);
            $words[] = 'PATCH the second series\' occurrence 7 renamed: ' . self::answer($x, $etag);
            $words[] = 'the second series\' list: ' . $this->rawList($this->undoList($c, $newUid, 'the second series\' list'));
            // The undo.
            $list = $this->undoList($c, $uid, 'the first series\' list');
            $ifMatch = self::etagIn($list, $m);
            $x = $this->send($c, 'write', 'restore the first rule', 'PATCH', '/events/' . rawurlencode($m), [], ['recurrence' => self::UNDO_RULE], $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
            $words[] = 'PATCH the first master COUNT=10 (If-Match ' . $etag($ifMatch) . '): ' . self::answer($x, $etag);
            $list = $this->undoList($c, $uid, 'the first series\' list');
            $words[] = 'the first series\' list after it: ' . $this->rawList($list);
            $ifMatch = self::etagIn($list, $m);
            $x = $this->send($c, 'write', 'the first series\' edited day sent again', 'PATCH', '/events/' . rawurlencode($this->undoId($m, 7)), [], ['summary' => self::UNDO_OWN_TITLE], $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
            $words[] = 'PATCH the first series\' occurrence 7 renamed (If-Match the master\'s, ' . $etag($ifMatch) . '): ' . self::answer($x, $etag);
            $g = $c->step('read', 'get the second master for its etag', $this->api->call('GET', '/events/' . rawurlencode($chosen)));
            $ifMatch = is_array($g['response']) && is_string($g['response']['etag'] ?? null) ? $g['response']['etag'] : null;
            $x = $this->send($c, 'write', 'delete the second master', 'DELETE', '/events/' . rawurlencode($chosen), [], null, $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
            $words[] = 'DELETE the second master (If-Match ' . $etag($ifMatch) . '): ' . self::answer($x, $etag);
            $g = $c->step('read', 'get the second series\' day record', $this->api->call('GET', '/events/' . rawurlencode($newDay)));
            $words[] = 'GET the second series\' occurrence 7: ' . self::getWords($g, $etag);
            $words[] = 'the second series\' list with showDeleted: ' . $this->rawList($this->undoList($c, $newUid, 'the second series\' list, showDeleted'));
            $words[] = 'the second series\' list without showDeleted: ' . $this->rawList($this->undoList($c, $newUid, 'the second series\' list, no showDeleted', false));
            $words[] = 'the first series\' list without showDeleted: ' . $this->rawList($this->undoList($c, $uid, 'the first series\' list, no showDeleted', false));
            $c->sent = 'setup: a weekly series of ten, occurrence 7 renamed; PATCH the master UNTIL before occurrence 6 [If-Match], POST a second series from occurrence 6 (COUNT=5) under a chosen id, PATCH its occurrence 7 renamed; then the undo: PATCH the first master COUNT=10 [If-Match], PATCH its occurrence 7 renamed [If-Match the master\'s], DELETE the second master [If-Match]';
            $c->finding = implode('; ', $words);
        });

        $this->probeCase('10', '10.4', 'a deleted series made live again: DELETE the master, then PATCH it with its whole body and status confirmed', function (CaseRun $c): void {
            [$m, $uid] = $this->undoBase($c, [2 => 'renamed', 3 => 'deleted']);
            $etag = self::etagNamer();
            $words = [];
            $list = $this->undoList($c, $uid, 'the list before the DELETE');
            $words[] = 'the list before the DELETE: ' . $this->rawList($list);
            $ifMatch = self::etagIn($list, $m);
            $x = $this->send($c, 'write', 'delete the master', 'DELETE', '/events/' . rawurlencode($m), [], null, $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
            $words[] = 'DELETE the master (If-Match ' . $etag($ifMatch) . '): ' . self::answer($x, $etag);
            $list = $this->undoList($c, $uid, 'the list after the DELETE');
            $words[] = 'the list after it: ' . $this->rawList($list);
            $g = $c->step('read', 'get the master for its etag', $this->api->call('GET', '/events/' . rawurlencode($m)));
            $ifMatch = is_array($g['response']) && is_string($g['response']['etag'] ?? null) ? $g['response']['etag'] : null;
            $body = self::timed(self::UNDO_TITLE, self::LA, '2027-03-01 10:00', 60, self::UNDO_RULE);
            $body['description'] = (string) ($g['response']['description'] ?? $body['description']);
            $body['status'] = 'confirmed';
            $x = $this->send($c, 'write', 'the master made live again', 'PATCH', '/events/' . rawurlencode($m), [], $body, $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
            $words[] = 'PATCH the master, its whole body and status confirmed (If-Match the GET\'s, ' . $etag($ifMatch) . '): ' . self::answer($x, $etag);
            $words[] = 'the list after it: ' . $this->rawList($this->undoList($c, $uid, 'the list after the PATCH'));
            $words[] = 'at the ids of occurrences 2 and 3: ' . $this->undoIds($c, $m, [2, 3], $etag);
            $c->finding = implode('; ', $words);
        });

        $this->probeCase('10', '10.5', 'a day under a deleted series: DELETE the master, then GET an edited day\'s and an ordinary day\'s instance ids', function (CaseRun $c): void {
            [$m, $uid] = $this->undoBase($c, [2 => 'renamed']);
            $etag = self::etagNamer();
            $list = $this->undoList($c, $uid, 'the list before');
            $ifMatch = self::etagIn($list, $m);
            $x = $this->send($c, 'write', 'delete the master', 'DELETE', '/events/' . rawurlencode($m), [], null, $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
            $c->finding = 'DELETE the master (If-Match ' . $etag($ifMatch) . '): ' . self::answer($x, $etag) . '; occurrence 2 is the edited day, occurrence 4 an ordinary one: ' . $this->undoIds($c, $m, [2, 4], $etag);
        });

        $this->probeCase('10', '10.6', 'a value only Google holds on one day: PATCH one day with colorId alone, then the master\'s title', function (CaseRun $c): void {
            [$m, $uid] = $this->undoBase($c, []);
            $etag = self::etagNamer();
            $words = [];
            $list = $this->undoList($c, $uid, 'the list before');
            $ifMatch = self::etagIn($list, $m);
            $day = $this->undoId($m, 4);
            $x = $this->send($c, 'write', 'occurrence 4 given a colour', 'PATCH', '/events/' . rawurlencode($day), [], ['colorId' => '5'], $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
            $words[] = 'PATCH occurrence 4 {"colorId":"5"} (If-Match the master\'s, ' . $etag($ifMatch) . '): ' . self::answer($x, $etag);
            $list = $this->undoList($c, $uid, 'the list after the colour');
            $words[] = 'the list after it: ' . $this->rawList($list) . '; ' . self::recordVsMaster($list, $m, $day);
            $ifMatch = self::etagIn($list, $m);
            $x = $this->send($c, 'write', 'the master renamed', 'PATCH', '/events/' . rawurlencode($m), [], ['summary' => 'Probe undo renamed', 'recurrence' => self::UNDO_RULE], $ifMatch !== null ? ['If-Match: ' . $ifMatch] : []);
            $words[] = 'PATCH the master\'s title and rule, as masterBody sends a rename (If-Match ' . $etag($ifMatch) . '): ' . self::answer($x, $etag);
            $list = $this->undoList($c, $uid, 'the list after the rename');
            $words[] = 'the list after it: ' . $this->rawList($list) . '; ' . self::recordVsMaster($list, $m, $day);
            $c->finding = implode('; ', $words);
        });
    }

    /** Group 10's series: weekly, ten, Mondays 10:00 Los Angeles from 2027-03-01, each listed occurrence renamed or deleted (DELETE). @return array{0:string, 1:string} the master id and its iCalUID */
    private function undoBase(CaseRun $c, array $kinds): array
    {
        $x = $this->insert($c, 'setup', 'insert the series', self::timed(self::UNDO_TITLE, self::LA, '2027-03-01 10:00', 60, self::UNDO_RULE));
        $m = (string) ($x['response']['id'] ?? throw new \RuntimeException('the series was refused: HTTP ' . $x['status']));
        foreach ($kinds as $n => $kind) {
            $c->labels[self::keyWord(self::SHAPES['timed 10:00'], self::undoDate($n))] = "occurrence $n ($kind)";
            $path = '/events/' . rawurlencode($this->undoId($m, $n));
            $r = $kind === 'deleted'
                ? $this->send($c, 'setup', "delete occurrence $n", 'DELETE', $path)
                : $this->send($c, 'setup', "rename occurrence $n", 'PATCH', $path, [], ['summary' => self::UNDO_OWN_TITLE]);
            if ($r['status'] >= 300) {
                throw new \RuntimeException("setting up occurrence $n failed: HTTP {$r['status']}");
            }
        }
        return [$m, (string) ($x['response']['iCalUID'] ?? '')];
    }

    private static function undoDate(int $n): string
    {
        return (new \DateTimeImmutable('2027-03-01'))->modify('+' . (7 * ($n - 1)) . ' days')->format('Y-m-d');
    }

    /** Occurrence $n's instance id in a timed weekly series from 2027-03-01 10:00 Los Angeles. */
    private function undoId(string $seriesId, int $n): string
    {
        return GoogleWriter::instanceId($seriesId, self::dayKey(self::SHAPES['timed 10:00'], self::undoDate($n)), false);
    }

    /** The series' singleEvents=false list by iCalUID, showDeleted unless said otherwise. */
    private function undoList(CaseRun $c, string $uid, string $label, bool $deleted = true): array
    {
        return $this->pages($c, 'read', $label, '/events', ['iCalUID' => $uid, 'showDeleted' => $deleted ? 'true' : 'false', 'singleEvents' => 'false'])['items'];
    }

    /** A list's items, every field, as raw JSON scrubbed as the report scrubs (no addresses, links or names). */
    private function rawList(array $items): string
    {
        return $items === [] ? 'no items' : (string) json_encode($this->report->scrub($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** A GET of occurrence $n's id for each, in words. */
    private function undoIds(CaseRun $c, string $m, array $ns, \Closure $etag): string
    {
        $out = [];
        foreach ($ns as $n) {
            $id = $this->undoId($m, $n);
            $g = $c->step('read', "get occurrence $n", $this->api->call('GET', '/events/' . rawurlencode($id)));
            $out[] = "occurrence $n " . str_replace($m, '{master}', $id) . ': ' . self::getWords($g, $etag);
        }
        return implode(' | ', $out);
    }

    private static function getWords(array $g, \Closure $etag): string
    {
        if (!is_array($g['response']) || $g['status'] !== 200) {
            return 'GET ' . self::status($g);
        }
        $r = $g['response'];
        return 'GET 200, status ' . (string) ($r['status'] ?? 'none') . ', summary ' . self::short($r['summary'] ?? null) . ', originalStartTime ' . self::short($r['originalStartTime'] ?? null)
            . ', recurringEventId ' . (isset($r['recurringEventId']) ? 'set' : 'none') . ', etag ' . $etag(isset($r['etag']) ? (string) $r['etag'] : null);
    }

    /** Whether the list holds a record under $day, and which of its fields differ from the master's (ids, times and etags aside). */
    private static function recordVsMaster(array $list, string $m, string $day): string
    {
        $master = null;
        $record = null;
        foreach ($list as $e) {
            if (($e['id'] ?? '') === $m) {
                $master = $e;
            } elseif (($e['id'] ?? '') === $day) {
                $record = $e;
            }
        }
        if ($record === null) {
            return 'no record under that day\'s id';
        }
        $skip = ['id', 'etag', 'start', 'end', 'originalStartTime', 'recurringEventId', 'recurrence', 'htmlLink', 'iCalUID', 'created', 'updated', 'sequence'];
        $differ = [];
        foreach (array_unique(array_merge(array_keys($record), array_keys($master ?? []))) as $k) {
            if (!in_array($k, $skip, true) && ($record[$k] ?? null) !== ($master[$k] ?? null)) {
                $differ[] = "$k " . self::short($master[$k] ?? null) . ' on the master, ' . self::short($record[$k] ?? null) . ' on the day';
            }
        }
        return 'a record under that day\'s id (' . (string) ($record['status'] ?? '?') . '); fields that differ from the master\'s: ' . ($differ === [] ? 'none' : implode(', ', $differ));
    }

    /** Every field that differs between two lists, by item id; items only in one named. */
    private static function listDiff(array $a, array $b): string
    {
        $byId = static function (array $l): array {
            $o = [];
            foreach ($l as $e) {
                $o[(string) ($e['id'] ?? '?')] = $e;
            }
            return $o;
        };
        [$a, $b] = [$byId($a), $byId($b)];
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $id) {
            if (!isset($a[$id]) || !isset($b[$id])) {
                $out[] = "item $id " . (isset($a[$id]) ? 'gone' : 'new');
                continue;
            }
            $fields = [];
            foreach (array_unique(array_merge(array_keys($a[$id]), array_keys($b[$id]))) as $k) {
                if (($a[$id][$k] ?? null) !== ($b[$id][$k] ?? null)) {
                    $fields[] = $k;
                }
            }
            if ($fields !== []) {
                $out[] = "item $id: " . implode(', ', $fields);
            }
        }
        return $out === [] ? 'no field differs' : implode('; ', $out);
    }
}

