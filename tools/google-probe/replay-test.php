<?php
// The trace-and-replay tool, against the stand-in only (README.md,
// "Replaying the App's Requests"): traces recorded from the probe's own
// groups and from a hand-made scenario replay on a fresh stand-in with no
// divergence; a stand-in with one behaviour altered diverges at the request
// the trace says it must; a tampered answer diverges there; a list in
// another order does not; ids, instance ids, etags and sync tokens map; an
// outside change that only moved etags is followed and one that changed an
// event is refused; every event the replay makes is marked and deleted,
// also after an error; and the command line gives the same. It proves
// nothing about Google.
//
//   php tools/google-probe/replay-test.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

// The probe's group 7 lists an unbounded series to its 730-occurrence cap, many times: its trace runs to megabytes.
ini_set('memory_limit', '1024M');
$root = dirname(__DIR__, 2);
require $root . '/server/src/bootstrap.php';
require_once __DIR__ . '/lib/Api.php';
require_once __DIR__ . '/lib/Report.php';
require_once __DIR__ . '/lib/Probe.php';
require_once __DIR__ . '/lib/FakeGoogle.php';
require_once __DIR__ . '/lib/Trace.php';
require_once __DIR__ . '/lib/Replay.php';

use BetterCal\GoogleProbe\Api;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\GoogleProbe\Probe;
use BetterCal\GoogleProbe\Replay;
use BetterCal\GoogleProbe\ReplayTarget;
use BetterCal\GoogleProbe\Report;
use BetterCal\GoogleProbe\TraceRecorder;

$pass = 0;
$fail = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        return;
    }
    $fail++;
    echo "FAIL: $name" . ($detail !== '' ? ": $detail" : '') . "\n";
}

const CAL = 'samplecal0example@group.calendar.google.com';
const LA = 'America/Los_Angeles';

$tmp = sys_get_temp_dir() . '/google-replay-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/traces', 0700, true);
$nap = static function (float $s): void {
};

/** A probe run of $groups on a fresh stand-in, recorded. */
function recordProbe(string $name, array $groups, string $dir, \Closure $nap, ?\Closure $expand = null): array
{
    $fake = new FakeGoogle(CAL);
    $label = 'built-in';
    if ($expand !== null) {
        $fake->expand = $expand;
        $label = 'edit-harness';
    }
    $rec = new TraceRecorder($fake, "$dir/traces/$name.json", $name, 'probe group ' . implode(',', $groups), $label);
    $api = new Api($rec->transport(), static fn(): string => 'fake-token', CAL, $nap, 0.0);
    (new Probe($api, new Report("$dir/probe-$name"), $nap, ['only' => $groups]))->run();
    return json_decode((string) file_get_contents($rec->file), true);
}

/**
 * A trace replayed on a fresh stand-in set as ReplayTarget sets one; $alter changes it before the replay, $wrap the
 * transport. @return array{0:array,1:FakeGoogle}
 */
function replayOn(array $trace, ?\Closure $alter = null, ?\Closure $wrap = null, string $root = '', int $readTries = 1): array
{
    $fake = ReplayTarget::fake($trace, $root);
    if ($alter !== null) {
        $alter($fake);
    }
    $transport = $fake->transport();
    if ($wrap !== null) {
        $transport = $wrap($transport);
    }
    $nap = static function (float $s): void {
    };
    $api = new Api($transport, static fn(): string => 'replay-token', $fake->calendarId, $nap, 0.0);
    return [(new Replay($api, $nap, $readTries, 0.0))->scenario($trace), $fake];
}

function requests(array $trace): int
{
    return count(array_filter($trace['steps'], static fn(array $s): bool => $s['kind'] === 'request'));
}

/** Live events on the stand-in a replay made (marked by it). */
function liveReplayMarked(FakeGoogle $fake): array
{
    return array_keys(array_filter($fake->events, static fn(array $e): bool => str_starts_with((string) ($e['extendedProperties']['private'][Replay::MARKER_KEY] ?? ''), Replay::MARKER_PREFIX)
        && empty($e['recurringEventId']) && ($e['status'] ?? '') !== 'cancelled'));
}

// ---- 1. Every probe group, recorded and replayed on a fresh stand-in: no divergence -------------------------------
$traces = [];
foreach (['1', '2', '3', '4', '5', '6', '7'] as $g) {
    $traces[$g] = recordProbe("probe-group-$g", [$g], $tmp, $nap);
    $t = $traces[$g];
    check("record group $g: the trace has requests and nothing changed outside one", requests($t) > 10 && array_filter($t['steps'], static fn(array $s): bool => $s['kind'] === 'outside') === []);
    check("record group $g: no bearer token is written", !str_contains((string) file_get_contents("$tmp/traces/probe-group-$g.json"), 'fake-token'));
    [$r, $fake] = replayOn($t);
    check("replay group $g on a fresh stand-in: every answer as recorded", $r['status'] === 'same', json_encode([$r['status'], $r['at'], $r['reason'], $r['divergence']['difference'] ?? null, $r['divergence']['request'] ?? null]));
    check("replay group $g: every recorded request was sent", $r['steps'] === requests($t), "{$r['steps']} of " . requests($t));
    check("replay group $g: nothing it made is left live", liveReplayMarked($fake) === [], implode(', ', liveReplayMarked($fake)));
    check("replay group $g: no mapping note (every etag and token was one the stand-in gave)", $r['notes'] === [] || $g === '5', json_encode($r['notes']));
}

// ---- 2. A stand-in that leaves dropped records out of its lists diverges where the trace lists the first one ---------
// The expected request comes from the trace alone: the first answer listing a cancelled item with no originalStartTime
// under an instance id.
$expectAt = null;
foreach ($traces['3']['steps'] as $s) {
    if ($s['kind'] !== 'request' || !is_array($s['response'])) {
        continue;
    }
    foreach ((array) ($s['response']['items'] ?? []) as $i) {
        if (($i['status'] ?? '') === 'cancelled' && !isset($i['originalStartTime']) && empty($i['recurrence']) && preg_match('/_\d{8}(T\d{6}Z)?$/', (string) $i['id']) === 1) {
            $expectAt = $s['n'];
            break 2;
        }
    }
}
check('altered: group 3\'s trace lists a dropped record somewhere', $expectAt !== null);
[$r, $fake] = replayOn($traces['3'], static function (FakeGoogle $f): void {
    $f->listDropped = false;
});
check('altered: a stand-in that omits dropped records diverges', $r['status'] === 'diverged', $r['status']);
check('altered: at the first request whose recorded answer lists one', $r['at'] === $expectAt, "at {$r['at']}, expected $expectAt");
$what = array_column($r['divergence']['difference'] ?? [], 'what');
check('altered: the difference names the items only the stand-in listed', in_array('items only the stand-in listed', $what, true), json_encode($what));
check('altered: it stopped there (no request after it was sent)', $r['steps'] === count(array_filter($traces['3']['steps'], static fn(array $s): bool => $s['kind'] === 'request' && $s['n'] <= $expectAt)));
check('altered: and still deleted what it made', liveReplayMarked($fake) === [], implode(', ', liveReplayMarked($fake)));

// A second behaviour altered: the zone alone dropping every record (Q2), on the hand-made scenario below.

// ---- 3. A list in another order is the same list -----------------------------------------------------------------
foreach (['reversed', 'shuffled'] as $order) {
    [$r] = replayOn($traces['7'], static function (FakeGoogle $f) use ($order): void {
        $f->order = $order;
        $f->orderSeed = 11;
    });
    check("order: group 7 replayed on a stand-in listing $order: the same", $r['status'] === 'same', json_encode([$r['at'], $r['divergence']['difference'] ?? null]));
}

// ---- 4. A tampered recorded answer diverges at that request, naming the field ----------------------------------------
$tampered = $traces['3'];
$at = null;
foreach ($tampered['steps'] as $i => $s) {
    if ($s['kind'] === 'request' && $s['method'] === 'PATCH' && $s['status'] === 200 && isset($s['response']['summary'])) {
        $tampered['steps'][$i]['response']['summary'] = 'Tampered title';
        $at = $s['n'];
        break;
    }
}
[$r] = replayOn($tampered);
check('tampered: diverges at the tampered answer', $r['status'] === 'diverged' && $r['at'] === $at, "{$r['status']} at {$r['at']}, expected $at");
check('tampered: the difference is the summary', in_array('summary', array_column($r['divergence']['difference'] ?? [], 'field'), true), json_encode($r['divergence']['difference'] ?? null));
$errAt = null;
$wrongError = $traces['5'];
foreach ($wrongError['steps'] as $i => $s) {
    if ($s['kind'] === 'request' && $s['status'] === 412) {
        $wrongError['steps'][$i]['response']['error']['message'] = 'Some other reason';
        $errAt = $s['n'];
        break;
    }
}
[$r] = replayOn($wrongError);
check('tampered: a refusal with another message diverges there', $errAt !== null && $r['status'] === 'diverged' && $r['at'] === $errAt && ($r['divergence']['difference'][0]['what'] ?? '') === 'error message', json_encode([$r['at'], $errAt, $r['divergence']['difference'] ?? null]));

// ---- 5. A hand-made scenario: the app's kinds of write, chosen ids, instance ids of both forms, errors, outside steps -
$fake = new FakeGoogle(CAL);
$rec = new TraceRecorder($fake, "$tmp/traces/hand-made.json", 'hand-made', 'replay-test', 'built-in');
$api = new Api($rec->transport(), static fn(): string => 'fake-token', CAL, $nap, 0.0);
$etag = static fn(string $id): string => 'If-Match: ' . $fake->events[$id]['etag'];
$poll = static function (?string $token) use ($api): ?string {
    $r = $api->call('GET', '/events', ['maxResults' => '2500', 'showDeleted' => 'true', 'singleEvents' => 'false'] + ($token !== null ? ['syncToken' => $token] : []));
    return $r['response']['nextSyncToken'] ?? null;
};
$tok = $poll(null);
// Weekly from Monday 2027-03-01 10:00 in Los Angeles, six times: 18:00Z before the clocks change on 03-14, 17:00Z after.
$series = ['id' => 'sample0001', 'summary' => 'Sample weekly', 'location' => 'Sample Hall',
    'start' => ['dateTime' => '2027-03-01T10:00:00-08:00', 'timeZone' => LA], 'end' => ['dateTime' => '2027-03-01T11:00:00-08:00', 'timeZone' => LA],
    'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']];
$api->call('POST', '/events', [], $series);
$fake->seed($fake->events['sample0001']); // the harness's adopt: the same resource stored again, a new etag, sent next unread
$api->call('PATCH', '/events/sample0001', [], ['summary' => 'Sample weekly renamed'], [$etag('sample0001')]);
$api->call('GET', '/events/sample0001');
$tok = $poll($tok);
$api->call('PATCH', '/events/sample0001_20270315T170000Z', [], ['summary' => 'Sample one day'], [$etag('sample0001')]);
$api->call('DELETE', '/events/sample0001_20270322T170000Z', [], null, [$etag('sample0001')]);
$tok = $poll($tok);
// The zone alone (Denver keeps the same moments, 11:00 there), with an edited and a deleted day held.
$api->call('PATCH', '/events/sample0001', [], ['start' => ['timeZone' => 'America/Denver'], 'end' => ['timeZone' => 'America/Denver']], [$etag('sample0001')]);
$tok = $poll($tok);
// The time: 12:00 in Denver, 19:00Z before 03-14 and 18:00Z after.
$api->call('PATCH', '/events/sample0001', [], ['start' => ['dateTime' => '2027-03-01T12:00:00-07:00'], 'end' => ['dateTime' => '2027-03-01T13:00:00-07:00']], [$etag('sample0001')]);
$tok = $poll($tok);
$api->call('PATCH', '/events/sample0001_20270308T190000Z', [], ['location' => 'Sample room 2'], [$etag('sample0001')]);
$api->call('DELETE', '/events/sample0001_20270329T180000Z', [], null, [$etag('sample0001')]);
$tok = $poll($tok);
$api->call('PATCH', '/events/sample0001', [], ['start' => ['date' => '2027-03-01', 'dateTime' => null, 'timeZone' => null], 'end' => ['date' => '2027-03-02', 'dateTime' => null, 'timeZone' => null]], [$etag('sample0001')]);
$tok = $poll($tok);
$api->call('PATCH', '/events/sample0001_20270308', [], ['summary' => 'Sample all-day day'], [$etag('sample0001')]);
$api->call('PATCH', '/events/sample0001', [], ['recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=3']], [$etag('sample0001')]);
$api->call('POST', '/events', [], ['summary' => 'Sample weekly, from the fourth', 'start' => ['date' => '2027-03-22'], 'end' => ['date' => '2027-03-23'], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=3']]);
$tok = $poll($tok);
$api->call('PATCH', '/events/sample0001', [], ['recurrence' => ['RRULE:FREQ=DAILY;COUNT=3']], [$etag('sample0001')]);
$api->call('PATCH', '/events/sample0001', [], ['summary' => 'Stale'], ['If-Match: "2999999999999999"']); // 412
$api->call('POST', '/events', [], $series); // 409: the id again
$api->call('PATCH', '/events/sample0001_20270301', [], ['start' => ['dateTime' => '2027-03-01T09:00:00-08:00'], 'end' => ['dateTime' => '2027-03-01T10:00:00-08:00']], [$etag('sample0001')]); // 400
$tok = $poll($tok);
$single = $api->call('POST', '/events', [], ['summary' => 'Sample single', 'start' => ['dateTime' => '2027-04-02T09:00:00-07:00', 'timeZone' => LA], 'end' => ['dateTime' => '2027-04-02T10:00:00-07:00', 'timeZone' => LA]]);
$sid = (string) $single['response']['id'];
$api->call('PATCH', "/events/$sid", [], ['summary' => 'Sample single renamed'], [$etag($sid)]);
$tok = $poll($tok);
$api->call('DELETE', "/events/$sid", [], null, [$etag($sid)]);
$api->call('DELETE', '/events/sample0001', [], null, [$etag('sample0001')]);
$tok = $poll($tok);
$api->call('GET', "/events/$sid");
$hand = json_decode((string) file_get_contents($rec->file), true);
$statuses = array_column(array_filter($hand['steps'], static fn(array $s): bool => $s['kind'] === 'request'), 'status');
check('hand-made: the stand-in refused what it should (409, 412, 400)', in_array(409, $statuses, true) && in_array(412, $statuses, true) && in_array(400, $statuses, true), json_encode(array_count_values($statuses)));
$outside = array_values(array_filter($hand['steps'], static fn(array $s): bool => $s['kind'] === 'outside'));
check('hand-made: the etag-only change is recorded as an outside step', count($outside) === 1 && $outside[0]['changed'] === [] && isset($outside[0]['etags']['sample0001']), json_encode($outside));
$kinds = TraceRecorder::kinds($hand);
foreach (['insert a repeating event', 'insert a single event', 'all events: field change', 'all events: time change', 'all events: zone change', 'all events: timed to all-day',
    'all events: timed to all-day, with an edited day', 'all events: timed to all-day, with a deleted day', 'all events: bound change', 'all events: repeat-rule change',
    'this and following (bound, then a new series)', 'edit one day', 'delete one day', 'delete a whole repeating event', 'change a single event', 'delete a single event',
    'poll with a sync token', 'whole read', 'refused write (409)', 'refused write (412)', 'refused write (400)'] as $k) {
    check("kinds: the hand-made trace holds '$k'", ($kinds[$k] ?? 0) > 0, json_encode($kinds));
}
[$r, $target] = replayOn($hand);
check('hand-made: replayed on a fresh stand-in, every answer as recorded', $r['status'] === 'same', json_encode([$r['status'], $r['at'], $r['reason'], $r['divergence']['difference'] ?? null, $r['divergence']['sent'] ?? null]));
check('hand-made: every request sent', $r['steps'] === requests($hand));
check('hand-made: one mapping note, for the stale etag the scenario made up', count($r['notes']) === 1 && str_contains($r['notes'][0], 'an etag the stand-in never gave'), json_encode($r['notes']));
$failed = array_filter($hand['steps'], static fn(array $s): bool => $s['kind'] === 'request' && in_array($s['status'], [404, 410], true));
check('hand-made: every instance id it used named a day (no 404 or 410 in the trace)', $failed === [], json_encode(array_column($failed, 'path')));
check('hand-made: the chosen id was replaced by a fresh one', !isset($target->events['sample0001']) && count(array_filter(array_keys($target->events), static fn($id): bool => str_starts_with((string) $id, 'rp'))) > 0);
$made = array_filter($target->events, static fn(array $e): bool => empty($e['recurringEventId']) && !preg_match('/_\d{8}/', (string) $e['id']));
check('hand-made: every event the replay made is marked', $made !== [] && array_filter($made, static fn(array $e): bool => !str_starts_with((string) ($e['extendedProperties']['private'][Replay::MARKER_KEY] ?? ''), Replay::MARKER_PREFIX)) === []);
check('hand-made: the marker is in the private properties, never the description', array_filter($made, static fn(array $e): bool => str_contains((string) ($e['description'] ?? ''), 'replay')) === []);
check('hand-made: everything it made is deleted', liveReplayMarked($target) === [], implode(', ', liveReplayMarked($target)));
$sent = array_values(array_filter($target->requests, static fn(array $q): bool => $q['method'] !== 'GET'));
check('hand-made: every write it sent carried sendUpdates=none', array_filter($sent, static fn(array $q): bool => ($q['query']['sendUpdates'] ?? null) !== 'none') === []);
$ifMatch = array_values(array_filter($target->requests, static fn(array $q): bool => preg_grep('/^If-Match:/', $q['headers']) !== []));
check('hand-made: If-Match values sent are the target\'s own etags', $ifMatch !== [] && array_filter($ifMatch, static fn(array $q): bool => preg_grep('/^If-Match: "29/', $q['headers']) !== [] && $q['status'] !== 412) === []);

// The zone alone, altered: a stand-in that drops every record on a zone change diverges at the zone change's poll.
[$r] = replayOn($hand, static function (FakeGoogle $f): void {
    $f->zoneAlone = 'dropped';
});
$zoneAt = null;
foreach ($hand['steps'] as $s) {
    if ($s['kind'] === 'request' && $s['method'] === 'PATCH' && isset($s['body']['start']['timeZone']) && !isset($s['body']['start']['dateTime'])) {
        $zoneAt = $s['n'];
    }
}
check('altered zone: diverges at the zone change or the poll after it', $r['status'] === 'diverged' && $r['at'] !== null && $r['at'] >= $zoneAt && $r['at'] <= $zoneAt + 1, json_encode([$r['status'], $r['at'], $zoneAt]));

// A calendar with other events on it (another scenario's, live and deleted): they are left out of every list, and counted.
[$r, $target] = replayOn($hand, static function (FakeGoogle $f): void {
    $f->seed(['id' => 'other00001', 'summary' => 'Another scenario\'s', 'start' => ['date' => '2027-03-03'], 'end' => ['date' => '2027-03-04']]);
    $f->seed(['id' => 'other00002', 'summary' => 'Another scenario\'s, deleted', 'status' => 'cancelled', 'start' => ['date' => '2027-03-03'], 'end' => ['date' => '2027-03-04']]);
});
check('other events: the same, with their items left out and counted', $r['status'] === 'same' && $r['ignored'] > 0, json_encode([$r['status'], $r['ignored'], $r['divergence']['difference'] ?? null]));
check('other events: never touched', ($target->events['other00001']['status'] ?? '') === 'confirmed');
// A target that splits lists into pages of two where the stand-in gave one: read to the end and compared as one.
[$r] = replayOn($hand, null, static fn(\Closure $inner): \Closure => static fn(string $method, string $url, array $headers, ?array $payload): array
    => $inner($method, (string) preg_replace('/maxResults=\d+/', 'maxResults=2', $url), $headers, $payload));
check('pages: a list split by the target is read to its end and the same', $r['status'] === 'same' && preg_grep('/pages where the stand-in gave one/', $r['notes']) !== [], json_encode([$r['status'], $r['at'], $r['notes']]));
// A target whose reads lag behind its writes (each new event answers 404 twice): with three tries, the same; with one, not.
[$r] = replayOn($hand, static function (FakeGoogle $f): void {
    $f->lagGets = 2;
}, null, '', 3);
check('lag: reads asked again until they answer as recorded', $r['status'] === 'same', json_encode([$r['status'], $r['at'], $r['divergence']['difference'] ?? null]));
[$r] = replayOn($hand, static function (FakeGoogle $f): void {
    $f->lagGets = 2;
}, null, '', 1);
check('lag: with one try the lagging read diverges', $r['status'] === 'diverged' && ($r['divergence']['request']['method'] ?? '') === 'GET', json_encode([$r['status'], $r['at']]));

// A target whose resources carry etags of their own (as Google's can, 2026-11-13): here every cancelled item lists another.
// A write to a day with no record of its own goes on its series' etag, as the app sends it, never another day's.
[$r] = replayOn($hand, null, static fn(\Closure $inner): \Closure => static function (string $method, string $url, array $headers, ?array $payload) use ($inner): array {
    $answer = $inner($method, $url, $headers, $payload);
    $body = json_decode((string) $answer['body'], true);
    if (is_array($body) && isset($body['items'])) {
        foreach ($body['items'] as $i => $item) {
            if (($item['status'] ?? '') === 'cancelled') {
                $body['items'][$i]['etag'] = '"999"';
            }
        }
        $answer['body'] = json_encode($body);
    }
    return $answer;
});
check('etags: a day with no record of its own is written on its series\' etag, not a listed day\'s', $r['status'] === 'same', json_encode([$r['status'], $r['at'], $r['divergence']['difference'] ?? null, $r['divergence']['sent']['headers'] ?? null]));

// ---- The recorder's other two shapes (GoogleWriter's and GoogleSync's, as GoogleStand hands them over) and GOOGLE_TRACE --
putenv("GOOGLE_TRACE=$tmp/env/app.json");
$fake = new FakeGoogle(CAL);
$first = TraceRecorder::fromEnv($fake, 'edit-harness', 'edit-harness');
$second = TraceRecorder::fromEnv(new FakeGoogle(CAL), 'edit-harness', 'edit-harness');
putenv('GOOGLE_TRACE');
$url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(CAL) . '/events';
($first->writerTransport())('POST', $url . '?sendUpdates=none', ['summary' => 'Sample', 'start' => ['date' => '2027-06-01'], 'end' => ['date' => '2027-06-02']], ['Authorization: Bearer fake-token']);
($first->writerTransport())('PATCH', $url . '/fake000001?sendUpdates=none', ['summary' => 'Sample renamed'], ['Authorization: Bearer fake-token', 'If-Match: ' . $fake->events['fake000001']['etag']]);
($first->syncTransport())($url . '?maxResults=2500&showDeleted=true&singleEvents=false', ['Authorization: Bearer fake-token']);
$t = json_decode((string) file_get_contents("$tmp/env/app.json"), true);
check('env: GOOGLE_TRACE names the file, and a second stand-in in the process writes beside it', $first->file === "$tmp/env/app.json" && $second->file === "$tmp/env/app-2.json" && is_file("$tmp/env/app-2.json"));
check('env: the writer and sync shapes record path, query, If-Match and body, never the token',
    array_column($t['steps'], 'path') === ['/events', '/events/fake000001', '/events'] && ($t['steps'][2]['query']['showDeleted'] ?? null) === 'true'
    && count($t['steps'][1]['headers']) === 1 && str_starts_with($t['steps'][1]['headers'][0], 'If-Match: ') && $t['steps'][1]['body'] === ['summary' => 'Sample renamed']
    && !str_contains((string) file_get_contents("$tmp/env/app.json"), 'fake-token') && $t['expand'] === 'edit-harness', json_encode($t['steps']));
check('mail: every write in a trace that keeps quiet passes', TraceRecorder::mailing($t) === [] && TraceRecorder::mailing($hand) === []);
$loud = $t;
$loud['steps'][1]['query'] = []; // the PATCH, as if sent without sendUpdates
$loud['steps'][] = ['kind' => 'request', 'n' => 4, 'method' => 'DELETE', 'path' => '/events/fake000001', 'query' => ['sendUpdates' => 'all'], 'headers' => [], 'body' => null, 'status' => 204, 'response' => ''];
$loud['steps'][] = ['kind' => 'request', 'n' => 5, 'method' => 'PUT', 'path' => '/events/fake000001', 'query' => [], 'headers' => [], 'body' => [], 'status' => 200, 'response' => []];
check('mail: a write to an event without sendUpdates=none is named, each one, a GET never',
    TraceRecorder::mailing($loud) === ['request 2: PATCH /events/fake000001', 'request 4: DELETE /events/fake000001', 'request 5: PUT /events/fake000001'], json_encode(TraceRecorder::mailing($loud)));
[$r] = replayOn($t, null, null, $root);
check('env: and it replays', $r['status'] === 'same', json_encode([$r['at'], $r['divergence']['difference'] ?? null]));

// ---- 6. An outside change to an event can't be replayed, and says so -------------------------------------------------
// The edit harness's adopt: a rule written straight into a series it just made, before any exception record. Replayed as
// a setup PATCH of the fields changed, so the target holds what the stand-in held.
$fake = new FakeGoogle(CAL);
$rec = new TraceRecorder($fake, "$tmp/traces/adopt.json", 'adopt', 'replay-test');
$api = new Api($rec->transport(), static fn(): string => 'fake-token', CAL, $nap, 0.0);
$api->call('POST', '/events', [], ['id' => 'sample0003', 'summary' => 'Sample', 'start' => ['dateTime' => '2027-05-03T09:00:00-07:00', 'timeZone' => LA],
    'end' => ['dateTime' => '2027-05-03T10:00:00-07:00', 'timeZone' => LA], 'recurrence' => ['RRULE:FREQ=WEEKLY']]);
$fake->events['sample0003'] = array_replace($fake->events['sample0003'], ['recurrence' => ['RRULE:FREQ=WEEKLY;UNTIL=20270601T160000Z'], 'start' => ['dateTime' => '2027-05-03T08:00:00-07:00', 'timeZone' => LA]]);
$fake->seed($fake->events['sample0003']);
$api->call('GET', '/events', ['showDeleted' => 'true', 'singleEvents' => 'false']);
$api->call('PATCH', '/events/sample0003_20270510T150000Z', [], ['summary' => 'Sample one day'], ['If-Match: ' . $fake->events['sample0003']['etag']]);
$api->call('GET', '/events/sample0003/instances', ['showDeleted' => 'true']);
$adopt = json_decode((string) file_get_contents($rec->file), true);
$step = array_values(array_filter($adopt['steps'], static fn(array $s): bool => $s['kind'] === 'outside'))[0] ?? [];
check('adopt: the outside step holds the fields and a setup body, the start sent whole', ($step['setup']['sample0003']['recurrence'] ?? null) === ['RRULE:FREQ=WEEKLY;UNTIL=20270601T160000Z']
    && array_key_exists('date', $step['setup']['sample0003']['start'] ?? []) && isset($step['fields']['sample0003']['start']), json_encode($step));
[$r, $target] = replayOn($adopt);
check('adopt: replayed with one setup PATCH, every answer as recorded', $r['status'] === 'same' && $r['setups'] === 1, json_encode([$r['status'], $r['at'], $r['reason'], $r['divergence']['difference'] ?? null]));
check('adopt: and what it made is deleted', liveReplayMarked($target) === []);
[$r] = replayOn($adopt, null, static fn(\Closure $inner): \Closure => static function (string $method, string $url, array $headers, ?array $payload) use ($inner): array {
    return $method === 'PATCH' && isset($payload['recurrence']) ? ['status' => 400, 'body' => json_encode(['error' => ['code' => 400, 'message' => 'Invalid recurrence rule.']])] : $inner($method, $url, $headers, $payload);
});
check('adopt: a setup the target refuses makes the scenario unreplayable, with the target\'s reason', $r['status'] === 'unreplayable' && str_contains((string) $r['reason'], 'Invalid recurrence rule.'), json_encode([$r['status'], $r['reason']]));

// A change no request can repeat: an event seeded outside any request, or a series changed after it has records.
$fake = new FakeGoogle(CAL);
$rec = new TraceRecorder($fake, "$tmp/traces/outside.json", 'outside', 'replay-test');
$api = new Api($rec->transport(), static fn(): string => 'fake-token', CAL, $nap, 0.0);
$api->call('POST', '/events', [], ['id' => 'sample0002', 'summary' => 'Sample', 'start' => ['date' => '2027-05-01'], 'end' => ['date' => '2027-05-02'], 'recurrence' => ['RRULE:FREQ=DAILY;COUNT=3']]);
$api->call('PATCH', '/events/sample0002_20270502', [], ['summary' => 'Sample day'], ['If-Match: ' . $fake->events['sample0002']['etag']]);
$fake->events['sample0002']['summary'] = 'Changed outside';
$api->call('GET', '/events/sample0002');
[$r, $target] = replayOn(json_decode((string) file_get_contents($rec->file), true));
check('outside: a series changed outside a request after it has records is unreplayable there', $r['status'] === 'unreplayable' && $r['at'] === 3 && str_contains((string) $r['reason'], 'sample0002'), json_encode([$r['status'], $r['at'], $r['reason']]));
check('outside: and what it made is deleted', liveReplayMarked($target) === []);
$fake = new FakeGoogle(CAL);
$fake->seed(['id' => 'seeded0001', 'summary' => 'Seeded', 'start' => ['date' => '2027-05-01'], 'end' => ['date' => '2027-05-02']]);
$rec = new TraceRecorder($fake, "$tmp/seeded.json", 'seeded', 'replay-test');
$fake->seed(['id' => 'seeded0002', 'summary' => 'Seeded later', 'start' => ['date' => '2027-05-01'], 'end' => ['date' => '2027-05-02']]);
(new Api($rec->transport(), static fn(): string => 'fake-token', CAL, $nap, 0.0))->call('GET', '/events/seeded0002');
[$r] = replayOn(json_decode((string) file_get_contents($rec->file), true));
check('outside: an event seeded outside a request is unreplayable', $r['status'] === 'unreplayable' && $r['at'] === 1, json_encode([$r['status'], $r['at'], $r['reason']]));

// ---- 7. An error part way: the scenario stops as an error and what it made is still deleted ---------------------------
$calls = 0;
[$r, $target] = replayOn($hand, null, static function (\Closure $inner) use (&$calls): \Closure {
    return static function (string $method, string $url, array $headers, ?array $payload) use ($inner, &$calls): array {
        if (++$calls === 6) {
            throw new \RuntimeException('HTTP error for the stand-in: network down');
        }
        return $inner($method, $url, $headers, $payload);
    };
});
check('error: a transport failure stops the scenario as an error', $r['status'] === 'error' && str_contains((string) $r['reason'], 'network down'), json_encode([$r['status'], $r['reason']]));
check('error: what it made before is deleted', liveReplayMarked($target) === [] && str_contains((string) $r['cleanup'], 'deleted 1 of 1'), (string) $r['cleanup']);

// ---- 8. Normalizing: what legitimately differs is left out, nothing else -----------------------------------------------
$a = ['id' => 'x1', 'etag' => '"1"', 'updated' => 'a', 'created' => 'b', 'sequence' => 0, 'htmlLink' => 'l', 'creator' => ['email' => 'a@example.com'], 'organizer' => ['email' => 'b@example.com'], 'kind' => 'calendar#event',
    'start' => ['dateTime' => '2027-03-01T10:00:00-08:00', 'timeZone' => LA], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=3;BYDAY=MO'], 'summary' => 'S'];
$b = ['summary' => 'S', 'recurrence' => ['RRULE:BYDAY=MO;FREQ=WEEKLY;COUNT=3'], 'start' => ['timeZone' => LA, 'dateTime' => '2027-03-01T10:00:00-08:00'], 'id' => 'y1', 'etag' => '"9"', 'sequence' => 2,
    'kind' => 'calendar#event', 'extendedProperties' => ['private' => [Replay::MARKER_KEY => 'replay-abc']]];
check('normalize: ids mapped back, volatile fields, the marker and rule order left out', Replay::normalize($a, null, null) === Replay::normalize($b, ['y1' => 'x1'], []), json_encode([Replay::normalize($a, null, null), Replay::normalize($b, ['y1' => 'x1'], [])]));
check('normalize: an instance id maps through its series, both forms', Replay::backId('y1_20270301', ['y1' => 'x1']) === 'x1_20270301' && Replay::backId('y1_20270301T180000Z', ['y1' => 'x1']) === 'x1_20270301T180000Z' && Replay::backId('z9_20270301', ['y1' => 'x1']) === null);
check('normalize: a different summary still differs', Replay::normalize($a, null, null) !== Replay::normalize(['summary' => 'T'] + $b, ['y1' => 'x1'], []));
// What Google writes on its own is the stand-in's to answer alike (replay-2026-11-13.md), so it counts here.
check('normalize: reminders, eventType, an empty description and another offset for the same instant all still count',
    Replay::normalize($a, null, null) !== Replay::normalize(['reminders' => ['useDefault' => true]] + $b, ['y1' => 'x1'], [])
    && Replay::normalize($a, null, null) !== Replay::normalize(['eventType' => 'default'] + $b, ['y1' => 'x1'], [])
    && Replay::normalize($a, null, null) !== Replay::normalize(['description' => ''] + $b, ['y1' => 'x1'], [])
    && Replay::normalize($a, null, null) !== Replay::normalize(['start' => ['timeZone' => LA, 'dateTime' => '2027-03-01T18:00:00Z']] + $b, ['y1' => 'x1'], []));

// ---- The stand-in's answers are shaped as Google's (replay-2026-11-13.md) ------------------------------------------------
$fake = new FakeGoogle(CAL);
$api = new Api($fake->transport(), static fn(): string => 'fake-token', CAL, $nap, 0.0);
$timed = $api->call('POST', '/events', [], ['summary' => 'Sample', 'description' => '', 'location' => '', 'recurrence' => [], 'source' => null,
    'start' => ['dateTime' => '2027-05-03T18:00:00+00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2027-05-03T19:00:00+00:00', 'timeZone' => 'UTC']])['response'];
$day = $api->call('POST', '/events', [], ['summary' => 'Sample day', 'start' => ['date' => '2027-05-04'], 'end' => ['date' => '2027-05-05'], 'recurrence' => ['RRULE:FREQ=DAILY;COUNT=2']])['response'];
check('shape: an empty description, location and recurrence and a null field are left out', !array_key_exists('description', $timed) && !array_key_exists('location', $timed) && !array_key_exists('recurrence', $timed) && !array_key_exists('source', $timed), json_encode($timed));
check('shape: a dateTime is written with the calendar\'s offset, the event\'s zone kept', $timed['start'] === ['dateTime' => '2027-05-03T11:00:00-07:00', 'timeZone' => 'UTC'], json_encode($timed['start']));
check('shape: reminders default on a timed event, not on an all-day one; eventType default', $timed['reminders'] === ['useDefault' => true] && $day['reminders'] === ['useDefault' => false] && $timed['eventType'] === 'default');
$items = $api->call('GET', '/events/' . $day['id'] . '/instances')['response']['items'] ?? [];
check('shape: list items and instances shaped too', $items !== [] && ($items[0]['reminders'] ?? null) === ['useDefault' => false] && ($items[0]['eventType'] ?? null) === 'default');
$stale = $api->call('PATCH', '/events/' . $timed['id'], [], ['summary' => 'x'], ['If-Match: "1"']);
check('shape: a PATCH\'s 412 in Google\'s words', $stale['status'] === 412 && ($stale['response']['error']['message'] ?? '') === 'Precondition check failed.' && ($stale['response']['error']['errors'][0]['reason'] ?? '') === 'failedPrecondition');
$gone = $api->call('GET', '/events/nosuchevent1');
check('shape: a 404 with Google\'s reason', ($gone['response']['error']['errors'][0]['reason'] ?? '') === 'notFound' && ($gone['response']['error']['message'] ?? '') === 'Not Found');
$series = $api->call('POST', '/events', [], ['summary' => 'Sample weekly', 'start' => ['dateTime' => '2027-05-03T09:00:00-07:00', 'timeZone' => LA],
    'end' => ['dateTime' => '2027-05-03T10:00:00-07:00', 'timeZone' => LA], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=3']])['response'];
$own = $api->call('PATCH', '/events/' . $series['id'] . '_20270510T160000Z', [], ['start' => ['dateTime' => '2027-05-10T13:00:00+09:00', 'timeZone' => 'Asia/Tokyo'],
    'end' => ['dateTime' => '2027-05-10T14:00:00+09:00', 'timeZone' => 'Asia/Tokyo']])['response'];
$allDay = $api->call('PATCH', '/events/' . $series['id'] . '_20270517T160000Z', [], ['start' => ['date' => '2027-05-17', 'dateTime' => null, 'timeZone' => null],
    'end' => ['date' => '2027-05-18', 'dateTime' => null, 'timeZone' => null]])['response'];
check('shape: originalStartTime names the zone of the record\'s own start, none when that start is a date',
    ($own['originalStartTime'] ?? null) === ['dateTime' => '2027-05-10T09:00:00-07:00', 'timeZone' => 'Asia/Tokyo']
    && ($allDay['originalStartTime'] ?? null) === ['dateTime' => '2027-05-17T09:00:00-07:00'], json_encode([$own['originalStartTime'] ?? null, $allDay['originalStartTime'] ?? null]));
check('shape: the store is left as sent', ($fake->events[$timed['id']]['description'] ?? null) === '');
$c = Replay::compare(['path' => '/events', 'status' => 200, 'response' => ['items' => [['id' => 'x1_20270301', 'status' => 'cancelled', 'summary' => 'S']]]],
    ['status' => 200, 'response' => ['items' => [['id' => 'y1_20270301', 'status' => 'cancelled']]]], ['y1' => 'x1'], []);
check('compare: a cancelled item\'s extra field is a shape note, not a divergence', $c['differs'] === [] && count($c['shape']) === 1 && $c['shape'][0]['field'] === 'summary', json_encode($c));
$c = Replay::compare(['path' => '/events', 'status' => 200, 'response' => ['items' => [['id' => 'x1_20270301', 'status' => 'cancelled']]]],
    ['status' => 200, 'response' => ['items' => [['id' => 'y1_20270301', 'status' => 'cancelled', 'originalStartTime' => ['date' => '2027-03-01']]]]], ['y1' => 'x1'], []);
check('compare: a cancelled item\'s originalStartTime on one side only is a divergence (issue 256\'s kind)', $c['differs'] !== [], json_encode($c));

// ---- 9. The edit harness's occurrences: a trace recorded with them replays on a stand-in set the same way ---------------
require_once $root . '/tools/edit-harness/Base.php';
require_once $root . '/tools/edit-harness/Shape.php';
require_once $root . '/tools/edit-harness/Rfc.php';
require_once $root . '/tools/edit-harness/Truth.php';
require_once $root . '/tools/edit-harness/GoogleStand.php';
$t = recordProbe('probe-group-2-harness', ['2'], $tmp, $nap, static fn(array $m): array => \BetterCal\EditHarness\GoogleStand::expand($m));
check('harness expand: the trace says so', $t['expand'] === 'edit-harness');
[$r] = replayOn($t, null, null, $root);
check('harness expand: replayed on a stand-in with the same occurrences, every answer as recorded', $r['status'] === 'same', json_encode([$r['at'], $r['divergence']['difference'] ?? null]));

// ---- 10. The command line, on the stand-in -------------------------------------------------------------------------------
$cmd = static fn(string $traces, string $report): array => [exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/replay.php') . ' --target fake --trace ' . escapeshellarg($traces) . ' --report ' . escapeshellarg($report) . ' 2>&1', $o, $code), $code, implode("\n", $o)];
[, $code, $out] = $cmd("$tmp/traces", "$tmp/report");
$md = glob("$tmp/report/replay-*.md") ?: [];
$total = array_sum(array_map(static fn(string $f): int => requests(json_decode((string) file_get_contents($f), true)), glob("$tmp/traces/*.json") ?: []));
check('cli: the trace folder replays on fresh stand-ins with exit 0 except the outside one', $code === 1 && str_contains($out, '1 unreplayable') && !str_contains($out, 'diverged'), $out);
check('cli: the report is written and lists each scenario', count($md) === 1 && substr_count((string) file_get_contents($md[0]), '| same |') === count(glob("$tmp/traces/*.json")) - 1);
check('cli: the report never holds the stand-in\'s address or a sync token', $md !== [] && !str_contains((string) file_get_contents($md[0]) . file_get_contents(str_replace('.md', '.json', $md[0])), 'fake-sync-')
    && !str_contains((string) file_get_contents(str_replace('.md', '.json', $md[0])), 'owner@example.com'));
mkdir("$tmp/one");
file_put_contents("$tmp/one/t.json", json_encode($tampered));
[, $code, $out] = $cmd("$tmp/one", "$tmp/report2");
check('cli: a divergence exits 1 and is named', $code === 1 && str_contains($out, '1 diverged'), $out);
echo "trace set of this test: " . count(glob("$tmp/traces/*.json")) . " scenarios, $total requests\n";

// tidy up
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($tmp);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
