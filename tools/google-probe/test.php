<?php
// The probe's own plumbing, against the fake in lib/FakeGoogle.php: every
// group runs, the report is written (and scrubbed), cleanup deletes what was
// made and what an aborted run left, a 412 is reported as a conflict, only
// the mail case leaves out sendUpdates, a 401 refreshes the token, a 404 on
// a new event is retried, and the probe refuses a main calendar or one with
// other events. It proves nothing about Google.
//
//   php tools/google-probe/test.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

// The whole probe runs in this one process and its report is read back whole: about 140 MB since the back-to-old-keys
// cases (3.36 on), which read every id after every request, and answers carry reminders and eventType.
ini_set('memory_limit', '512M');

$root = dirname(__DIR__, 2);
require $root . '/server/src/bootstrap.php';
require __DIR__ . '/lib/Api.php';
require __DIR__ . '/lib/Report.php';
require __DIR__ . '/lib/Probe.php';
require __DIR__ . '/lib/FakeGoogle.php';

use BetterCal\GoogleProbe\Api;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\GoogleProbe\Probe;
use BetterCal\GoogleProbe\Report;

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

const CAL = 'probecal0example@group.calendar.google.com';
const GUEST = 'guest@example.org';

$tmp = sys_get_temp_dir() . '/google-probe-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$slept = 0.0;
$sleep = static function (float $s) use (&$slept): void {
    $slept += $s;
};

/** One probe run against $fake; returns [report, error, tokens taken]. */
function probeRun(FakeGoogle $fake, string $dir, array $opts, \Closure $sleep, ?string $guest = null): array
{
    $tokens = 0;
    $api = new Api($fake->transport(), static function () use (&$tokens): string {
        $tokens++;
        return 'fake-token-' . $tokens;
    }, CAL, $sleep, 0.25, $guest !== null);
    $report = new Report($dir);
    $report->secret($fake->owner, '{account}');
    $report->secret(CAL, '{calendar}');
    if ($guest !== null) {
        $report->secret($guest, '{guest}');
    }
    $error = null;
    try {
        (new Probe($api, $report, $sleep, $opts + ['guest' => $guest]))->run();
    } catch (\Throwable $e) {
        $error = $e;
    }
    return [$report, $error, $tokens];
}

function liveMarked(FakeGoogle $fake): array
{
    return array_keys(array_filter($fake->events, static fn(array $e) => Probe::isMarked($e) && empty($e['recurringEventId']) && ($e['status'] ?? '') !== 'cancelled'));
}

function writes(FakeGoogle $fake): array
{
    return array_values(array_filter($fake->requests, static fn(array $r) => $r['method'] !== 'GET'));
}

// ---- A full run -------------------------------------------------------------
$fake = new FakeGoogle(CAL);
$fake->lagGets = 1;
$fake->unauthorizedAt = 12;
$marker = [Probe::MARKER_KEY => 'earlierrun'];
$fake->seed(['id' => 'leftover01', 'summary' => 'Probe weekly', 'description' => 'Probe series notes ' . Probe::MARKER_TEXT,
    'extendedProperties' => ['private' => $marker], 'start' => ['dateTime' => '2027-03-01T10:00:00-08:00', 'timeZone' => 'America/Los_Angeles'],
    'end' => ['dateTime' => '2027-03-01T11:00:00-08:00', 'timeZone' => 'America/Los_Angeles'], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=3']]);
$fake->seed(['id' => 'keepme01', 'summary' => 'Not the probe\'s', 'start' => ['dateTime' => '2027-03-02T10:00:00-08:00'], 'end' => ['dateTime' => '2027-03-02T11:00:00-08:00']]);
$keepEtag = $fake->events['keepme01']['etag'];

[$report, $error, $tokens] = probeRun($fake, $tmp . '/full', ['allowOtherEvents' => true], $sleep);
check('full run: finishes', $error === null, $error?->getMessage() ?? '');
$json = json_decode((string) @file_get_contents($report->jsonPath), true);
$md = (string) @file_get_contents($report->mdPath);
check('full run: the JSON report is written', is_array($json));
check('full run: the markdown report is written', str_contains($md, '# Google Probe Report'));
$cases = $json['cases'] ?? [];
$byGroup = [];
foreach ($cases as $c) {
    $byGroup[$c['group']][] = $c;
}
foreach (['1', '2', '3', '4', '5', '6', '7'] as $g) {
    check("full run: group $g ran cases", count($byGroup[$g] ?? []) > 0);
    check("full run: group $g has a section in the markdown", str_contains($md, "## $g. "));
}
check('full run: group 8 is skipped without --guest, and says why', isset($json['groups']['8']['skipped']) && !isset($byGroup['8']));
check('full run: group 9 is documented as manual', str_contains((string) ($json['groups']['9']['manual'] ?? ''), 'Try again') && str_contains($md, 'Adopt as local'));
$errors = array_map(static fn(array $c) => $c['id'] . ': ' . $c['error'], array_filter($cases, static fn(array $c) => $c['error'] !== null));
check('full run: no case stopped with an error', $errors === [], implode('; ', $errors));
$empty = array_map(static fn(array $c) => $c['id'], array_filter($cases, static fn(array $c) => trim($c['finding']) === ''));
check('full run: every case has a finding', $empty === [], implode(', ', $empty));
check('full run: every case lists what it sent', array_filter($cases, static fn(array $c) => trim($c['sent']) === '') === []);
check('full run: a markdown table row per case', substr_count($md, "\n| ") - substr_count($md, "\n| Case |") === count($cases));
check('full run: the report says it finished', isset($json['run']['finished']) && str_contains($md, 'finished'));

foreach (['the account address' => $fake->owner, 'the calendar id' => CAL, 'the bearer token' => 'fake-token', 'an event link' => 'eid=', 'a sync token' => 'fake-sync-', 'the calendar name' => 'Probe calendar'] as $what => $needle) {
    check("full run: the report never holds $what", !str_contains($md, $needle) && !str_contains((string) json_encode($json), $needle));
}

$c54 = array_values(array_filter($cases, static fn(array $c) => $c['id'] === '5.4'))[0] ?? null;
check('full run: a 412 is reported as a conflict', $c54 !== null && str_contains($c54['finding'], 'stale If-Match: 412 conflict') && str_contains($c54['finding'], 'current If-Match: 200'), $c54['finding'] ?? '');
$c57 = array_values(array_filter($cases, static fn(array $c) => $c['id'] === '5.7'))[0] ?? null;
check('full run: a 412 on an instance DELETE too', $c57 !== null && str_contains($c57['finding'], 'stale If-Match: 412 conflict'), $c57['finding'] ?? '');
check('full run: a refused rule is reported with its error', str_contains((string) (array_values(array_filter($cases, static fn(array $c) => $c['id'] === '1.12'))[0]['finding'] ?? ''), 'refused: HTTP 400'));
$c21 = array_values(array_filter($cases, static fn(array $c) => $c['id'] === '2.1t'))[0] ?? null;
check('full run: the instance id form is compared with GoogleWriter::instanceId', $c21 !== null && str_contains($c21['finding'], 'GoogleWriter::instanceId predicts the same'), $c21['finding'] ?? '');
check('full run: the exception read-back is in the finding', $c21 !== null && str_contains($c21['finding'], 'occurrence 3 (moved)'), $c21['finding'] ?? '');

$noNone = array_filter(writes($fake), static fn(array $r) => ($r['query']['sendUpdates'] ?? null) !== 'none');
check('full run: every write sends sendUpdates=none', $noNone === [], count($noNone) . ' without');
$elsewhere = array_filter($fake->requests, static fn(array $r) => !str_starts_with($r['path'], '/calendars/' . rawurlencode(CAL)) && $r['path'] !== '/users/me/calendarList/' . rawurlencode(CAL));
check('full run: no request goes to another calendar', $elsewhere === []);
check('full run: a 401 takes a fresh token and carries on', $tokens === 2, "tokens taken: $tokens");
$readTries = 0;
array_walk_recursive($cases, static function ($v, $k) use (&$readTries): void {
    if ($k === 'readTries') {
        $readTries = max($readTries, (int) $v);
    }
});
check('full run: a 404 on a new event is retried, and the tries are recorded', $readTries >= 2);
check('full run: the case table says when a read-back took more than one try', str_contains($md, 'a read-back took 2 tries'));
check('full run: pauses go through the sleep closure (no real waits)', $slept > 0);

check('cleanup: no marked event is left live', liveMarked($fake) === [], implode(', ', liveMarked($fake)));
check('cleanup: an earlier run\'s leftover is deleted', ($fake->events['leftover01']['status'] ?? '') === 'cancelled');
check('cleanup: the run notes the leftover', str_contains(implode(' ', $json['run']['notes'] ?? []), 'Deleted 1 marked events an earlier run left'));
check('cleanup: an event the probe did not make is never touched', ($fake->events['keepme01']['status'] ?? '') === 'confirmed' && $fake->events['keepme01']['etag'] === $keepEtag);
$made = array_keys(array_filter($fake->events, static fn(array $e) => Probe::isMarked($e) && empty($e['recurringEventId']) && $e['id'] !== 'leftover01'));
check('cleanup: every event the run made is deleted', $made !== [] && array_filter($made, static fn(string $id) => $fake->events[$id]['status'] !== 'cancelled') === []);
check('cleanup: the report says nothing marked is live', ($json['run']['markedLive'] ?? -1) === 0, (string) json_encode($json['run']['cleanup'] ?? null));

// ---- The mail case, with --guest -------------------------------------------
$fake = new FakeGoogle(CAL);
[$report, $error] = probeRun($fake, $tmp . '/mail', ['only' => ['8']], $sleep, GUEST);
check('mail: finishes', $error === null, $error?->getMessage() ?? '');
$json = json_decode((string) file_get_contents($report->jsonPath), true);
$mail = array_values(array_filter($json['cases'], static fn(array $c) => $c['group'] === '8'));
check('mail: four cases ran', count($mail) === 4);
check('mail: each finding is a manual check with the times', array_filter($mail, static fn(array $c) => !str_starts_with($c['finding'], 'manual:')) === []);
$omitted = array_values(array_filter(writes($fake), static fn(array $r) => !isset($r['query']['sendUpdates'])));
check('mail: exactly one write leaves out sendUpdates', count($omitted) === 1 && $omitted[0]['method'] === 'PATCH');
check('mail: the other writes send sendUpdates=none', array_filter(writes($fake), static fn(array $r) => isset($r['query']['sendUpdates']) && $r['query']['sendUpdates'] !== 'none') === []);
check('mail: the guest address never reaches the report', !str_contains((string) file_get_contents($report->jsonPath), GUEST) && !str_contains((string) file_get_contents($report->mdPath), GUEST));
check('mail: only the asked group ran', array_filter($json['cases'], static fn(array $c) => $c['group'] !== '8') === []);

// ---- Leaving out sendUpdates needs --guest ----------------------------------
$api = new Api((new FakeGoogle(CAL))->transport(), static fn(): string => 'fake-token-1', CAL, $sleep);
$refused = false;
try {
    $api->call('PATCH', '/events/fake000001', [], ['summary' => 'x'], [], false);
} catch (\LogicException) {
    $refused = true;
}
check('api: a write without sendUpdates is refused unless the mail case is allowed', $refused);

// ---- Refusals: other events, the main calendar, no write access --------------
$fake = new FakeGoogle(CAL);
$fake->seed(['id' => 'keepme01', 'summary' => 'Not the probe\'s', 'start' => ['date' => '2027-03-02'], 'end' => ['date' => '2027-03-03']]);
[$report, $error] = probeRun($fake, $tmp . '/others', [], $sleep);
check('refusal: a calendar with other events is refused without --allow-other-events', $error !== null && str_contains($error->getMessage(), '--allow-other-events'));
check('refusal: and nothing was written', writes($fake) === []);
check('refusal: the report says why', str_contains((string) file_get_contents($report->mdPath), 'Refused before any write'));

$fake = new FakeGoogle(CAL);
$fake->primary = true;
[, $error] = probeRun($fake, $tmp . '/primary', [], $sleep);
check('refusal: the main calendar is refused', $error !== null && str_contains($error->getMessage(), 'main calendar') && writes($fake) === []);

$fake = new FakeGoogle(CAL);
$fake->role = 'reader';
[, $error] = probeRun($fake, $tmp . '/reader', [], $sleep);
check('refusal: a calendar the account may only read is refused', $error !== null && writes($fake) === []);

// ---- An aborted run, then a rerun that cleans up ----------------------------
$fake = new FakeGoogle(CAL);
$fake->failAfter = 140;
[$report, $error] = probeRun($fake, $tmp . '/abort', ['only' => ['3']], $sleep);
check('abort: the run stops when the network goes', $error !== null);
$json = json_decode((string) file_get_contents($report->jsonPath), true);
check('abort: the JSON written so far is there', is_array($json) && count($json['cases'] ?? []) >= 1);
check('abort: the markdown says it did not finish', str_contains((string) file_get_contents($report->mdPath), 'not finished') || str_contains((string) file_get_contents($report->mdPath), 'The run stopped'));
$left = liveMarked($fake);
check('abort: it left marked events behind (the network was down for cleanup)', $left !== []);
$fake->failAfter = null;
[$report, $error] = probeRun($fake, $tmp . '/rerun', ['only' => ['1']], $sleep);
check('rerun: finishes', $error === null, $error?->getMessage() ?? '');
check('rerun: deletes what the aborted run left', liveMarked($fake) === [], implode(', ', liveMarked($fake)));
$json = json_decode((string) file_get_contents($report->jsonPath), true);
check('rerun: notes the leftovers it deleted', preg_match('/Deleted \d+ marked events an earlier run left/', implode(' ', $json['run']['notes'] ?? [])) === 1);

// ---- The stand-in against the live runs ---------------------------------------
// A weekly 10:00 Los Angeles series of ten with occurrence 3 moved a day later and occurrence 6 cancelled.
$standIn = static function (): FakeGoogle {
    $fake = new FakeGoogle(CAL);
    $fake->token = null;
    $la = static fn(string $local): array => ['dateTime' => (new DateTimeImmutable($local, new DateTimeZone('America/Los_Angeles')))->format('Y-m-d\TH:i:sP'), 'timeZone' => 'America/Los_Angeles'];
    $fake->seed(['id' => 'standin01', 'summary' => 'Sample weekly', 'start' => $la('2027-03-01 10:00'), 'end' => $la('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
    $fake->seed(['id' => 'standin01_20270315T170000Z', 'iCalUID' => 'standin01@google.com', 'recurringEventId' => 'standin01', 'summary' => 'Sample weekly',
        'originalStartTime' => $la('2027-03-15 10:00'), 'start' => $la('2027-03-16 10:00'), 'end' => $la('2027-03-16 11:00')]);
    $fake->seed(['id' => 'standin01_20270405T170000Z', 'iCalUID' => 'standin01@google.com', 'recurringEventId' => 'standin01', 'summary' => 'Sample weekly', 'status' => 'cancelled',
        'originalStartTime' => $la('2027-04-05 10:00'), 'start' => $la('2027-04-05 10:00'), 'end' => $la('2027-04-05 11:00')]);
    return $fake;
};
$patch = static fn(FakeGoogle $fake, array $body): array => ($fake->transport())('PATCH', Api::BASE . '/calendars/' . rawurlencode(CAL) . '/events/standin01', ['Authorization: Bearer x'], $body);

// 3.32 (2026-11-10): a link set on a series is written into every record, the cancelled one included.
$fake = $standIn();
$r = $patch($fake, ['source' => ['url' => 'https://example.test/sample', 'title' => 'example.test'], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
check('stand-in, 3.32: a link set on the series is written into the moved record and the cancelled one, both kept',
    $r['status'] === 200 && ($fake->events['standin01_20270315T170000Z']['source']['url'] ?? null) === 'https://example.test/sample'
    && ($fake->events['standin01_20270405T170000Z']['source']['url'] ?? null) === 'https://example.test/sample'
    && isset($fake->events['standin01_20270405T170000Z']['originalStartTime']), json_encode($fake->events['standin01_20270315T170000Z']));

// 3.17 (2026-11-10): the zone alone (10:00 Los Angeles is 11:00 Denver, the same instants): every record kept, its
// start reset to the pattern's in the new zone, its end where it was.
$fake = $standIn();
$den = static fn(string $local): array => ['dateTime' => (new DateTimeImmutable($local, new DateTimeZone('America/Denver')))->format('Y-m-d\TH:i:sP'), 'timeZone' => 'America/Denver'];
$r = $patch($fake, ['start' => $den('2027-03-01 11:00'), 'end' => $den('2027-03-01 12:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
$moved = $fake->events['standin01_20270315T170000Z'];
check('stand-in, 3.17: a zone change moving no instant keeps the moved record, its start reset to the pattern\'s, its end where it was',
    $r['status'] === 200 && isset($moved['originalStartTime']) && ($moved['start']['timeZone'] ?? null) === 'America/Denver'
    && (new DateTimeImmutable($moved['start']['dateTime']))->getTimestamp() === (new DateTimeImmutable('2027-03-15 10:00', new DateTimeZone('America/Los_Angeles')))->getTimestamp()
    && (new DateTimeImmutable($moved['end']['dateTime']))->getTimestamp() === (new DateTimeImmutable('2027-03-16 11:00', new DateTimeZone('America/Los_Angeles')))->getTimestamp(),
    json_encode($moved));
check('stand-in, 3.17: the cancelled record is kept too', ($fake->events['standin01_20270405T170000Z']['status'] ?? null) === 'cancelled' && isset($fake->events['standin01_20270405T170000Z']['originalStartTime']));

// ---- The stand-in against the probe of 2026-11-13 and the replay of the same day ----
// A weekly 10:00 Los Angeles series of four from 2027-03-01 (8 March is 18:00 UTC, 15 March 17:00 UTC), occurrence 2
// renamed and occurrence 3 deleted, as each switch case of the probe starts.
$call = static function (FakeGoogle $fake, string $method, string $id, ?array $body = null, array $query = []): array {
    $url = Api::BASE . '/calendars/' . rawurlencode(CAL) . '/events' . ($id !== '' ? '/' . rawurlencode($id) : '') . ($query !== [] ? '?' . http_build_query($query) : '');
    $r = ($fake->transport())($method, $url, ['Authorization: Bearer x'] + (isset($body['_ifMatch']) ? [1 => 'If-Match: ' . $body['_ifMatch']] : []), $body === null ? null : array_diff_key($body, ['_ifMatch' => 1]));
    return [(int) $r['status'], json_decode((string) $r['body'], true)];
};
$four = static function () use ($call): FakeGoogle {
    $fake = new FakeGoogle(CAL);
    $fake->token = null;
    $la = static fn(string $l): array => ['dateTime' => (new DateTimeImmutable($l, new DateTimeZone('America/Los_Angeles')))->format('Y-m-d\TH:i:sP'), 'timeZone' => 'America/Los_Angeles'];
    $fake->seed(['id' => 'four0001', 'summary' => 'Sample four', 'start' => $la('2027-03-01 10:00'), 'end' => $la('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']]);
    $call($fake, 'PATCH', 'four0001_20270308T180000Z', ['summary' => 'Sample four (own)']);
    $call($fake, 'DELETE', 'four0001_20270315T170000Z');
    return $fake;
};
$toAllDay = ['start' => ['date' => '2027-03-01', 'dateTime' => null, 'timeZone' => null], 'end' => ['date' => '2027-03-02', 'dateTime' => null, 'timeZone' => null], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']];
$toTimed = ['start' => ['dateTime' => '2027-03-01T10:00:00-08:00', 'timeZone' => 'America/Los_Angeles', 'date' => null], 'end' => ['dateTime' => '2027-03-01T11:00:00-08:00', 'timeZone' => 'America/Los_Angeles', 'date' => null], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']];

// 3.36, 3.39: a record a master change dropped reads as it is (200, cancelled, no originalStartTime) while its id names no day.
$fake = $four();
$call($fake, 'PATCH', 'four0001', $toAllDay);
[$st, $g] = $call($fake, 'GET', 'four0001_20270308T180000Z');
check('stand-in, 3.36: a dropped record is readable, cancelled, with no originalStartTime', $st === 200 && ($g['status'] ?? null) === 'cancelled' && !isset($g['originalStartTime']), "$st " . json_encode($g));
// 3.37: a first write to an ordinary day answers an etag the master does not take.
[, $w] = $call($fake, 'PATCH', 'four0001_20270308', ['summary' => 'Sample four (own)']);
check('stand-in, 3.37: a first write to an ordinary day answers an etag the master does not take', isset($w['etag']) && $w['etag'] !== $fake->events['four0001']['etag']);
// 3.36, 3.40, 10.2: a write to a day that holds a record moves its etag alone.
$masterEtag = $fake->events['four0001']['etag'];
$call($fake, 'PATCH', 'four0001_20270308', ['summary' => 'Sample four (own, again)']);
check('stand-in, 3.36: a write to a day holding a record leaves the master\'s etag as it was', $fake->events['four0001']['etag'] === $masterEtag);
// 3.36, 3.40, 10.2: back to timed, the dropped records come back live at the series' values, the deleted day included.
$call($fake, 'PATCH', 'four0001', $toTimed);
[$st, $own] = $call($fake, 'GET', 'four0001_20270308T180000Z');
[, $gone] = $call($fake, 'GET', 'four0001_20270315T170000Z');
[, $l] = $call($fake, 'GET', '', null, ['iCalUID' => 'four0001@google.com', 'singleEvents' => 'false', 'showDeleted' => 'true']);
$listed = [];
foreach ($l['items'] ?? [] as $e) {
    $listed[(string) $e['id']] = [(string) ($e['status'] ?? ''), isset($e['originalStartTime']), (string) ($e['summary'] ?? '')];
}
check('stand-in, 3.36: back to timed, the renamed day\'s record is live again at the series\' title, the deleted day live too, in a GET and in the list',
    $st === 200 && ($own['status'] ?? null) === 'confirmed' && isset($own['originalStartTime']) && ($own['summary'] ?? null) === 'Sample four' && ($gone['status'] ?? null) === 'confirmed'
    && ($listed['four0001_20270308T180000Z'] ?? null) === ['confirmed', true, 'Sample four'] && ($listed['four0001_20270315T170000Z'][0] ?? null) === 'confirmed',
    json_encode($listed));
// 3.39: the day's old all-day id answers a cancelled stub.
[$st, $stub] = $call($fake, 'GET', 'four0001_20270322');
check('stand-in, 3.39: an all-day id that was a day once answers a cancelled stub', $st === 200 && ($stub['status'] ?? null) === 'cancelled' && ($stub['summary'] ?? null) === 'CANCELLED', "$st " . json_encode($stub));
// Replay F: reminders stay false once the series has been all-day, after the switch back too.
[, $m] = $call($fake, 'GET', 'four0001');
check('stand-in, replay F: reminders useDefault false once the series has been all-day, kept after the switch back', ($m['reminders']['useDefault'] ?? null) === false, json_encode($m['reminders'] ?? null));

// 3.43 (D1): a weekday added keeps the cancelled record while it is still a day of the rule.
$fake = $four();
$call($fake, 'PATCH', 'four0001', ['recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=20']]);
check('stand-in, 3.43: a weekday added keeps the cancelled record, still a day of the rule', ($fake->records('four0001')['20270315T170000Z']['status'] ?? null) === 'cancelled');
// Replay A: a cancelled day is listed without showDeleted, short.
[, $l] = $call($fake, 'GET', '', null, ['iCalUID' => 'four0001@google.com', 'singleEvents' => 'false', 'showDeleted' => 'false']);
$short = array_values(array_filter($l['items'] ?? [], static fn(array $e): bool => ($e['status'] ?? '') === 'cancelled'));
check('stand-in, replay A: the cancelled day is listed without showDeleted, with no summary', count($short) === 1 && !isset($short[0]['summary']) && isset($short[0]['originalStartTime']), json_encode($short));

// 10.4, 10.5: a master deleted takes its records with it, and made live again brings them all back.
$fake = $four();
$call($fake, 'DELETE', 'four0001');
[, $own] = $call($fake, 'GET', 'four0001_20270308T180000Z');
[, $plain] = $call($fake, 'GET', 'four0001_20270322T170000Z');
check('stand-in, 10.5: under a deleted master an edited and an ordinary day read cancelled', ($own['status'] ?? null) === 'cancelled' && ($plain['status'] ?? null) === 'cancelled', json_encode([$own['status'] ?? null, $plain['status'] ?? null]));
$call($fake, 'PATCH', 'four0001', ['status' => 'confirmed', 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']]);
check('stand-in, 10.4: the master live again brings every record back, the deleted day included, the renamed day keeping its title',
    ($fake->records('four0001')['20270308T180000Z']['summary'] ?? null) === 'Sample four (own)' && ($fake->records('four0001')['20270315T170000Z']['status'] ?? null) === 'confirmed');

// 3.38: one day of an all-day series takes a clock time once its date is sent as null.
$fake = new FakeGoogle(CAL);
$fake->token = null;
$fake->seed(['id' => 'ad0001', 'summary' => 'Sample all-day', 'start' => ['date' => '2027-03-01'], 'end' => ['date' => '2027-03-02'], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']]);
[$st] = $call($fake, 'PATCH', 'ad0001_20270322', ['start' => ['dateTime' => '2027-03-22T15:00:00-07:00', 'timeZone' => 'America/Los_Angeles', 'date' => null], 'end' => ['dateTime' => '2027-03-22T16:00:00-07:00', 'timeZone' => 'America/Los_Angeles', 'date' => null]]);
check('stand-in, 3.38: a clock time on one day of an all-day series, its date nulled, is taken', $st === 200, (string) $st);
// 10.6: a colour on one day: a record with the colour and a label of Google's own, both kept through a master rename.
[, $c] = $call($fake, 'PATCH', 'ad0001_20270308', ['colorId' => '5']);
$call($fake, 'PATCH', 'ad0001', ['summary' => 'Sample all-day, renamed', 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']]);
$rec = $fake->records('ad0001')['20270308'] ?? [];
check('stand-in, 10.6: a colour on one day makes a record with a label Google adds, both kept through a master rename',
    isset($c['eventLabelId']) && ($rec['colorId'] ?? null) === '5' && isset($rec['eventLabelId']) && ($rec['summary'] ?? null) === 'Sample all-day, renamed', json_encode($rec));

// ---- The stand-in against the second and third runs of 2026-11-13 (3.48 to 3.66) ----
// A weekly series of six in zone UTC at 00:00 from 2027-03-01, occurrence 2 renamed with notes, 3 deleted (as 3.48 starts).
$utc = static fn(string $l): array => ['dateTime' => (new DateTimeImmutable($l, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP'), 'timeZone' => 'UTC'];
$midnight = static function (bool $edits = true, string $clock = '00:00') use ($call, $utc): FakeGoogle {
    $fake = new FakeGoogle(CAL);
    $fake->token = null;
    $fake->seed(['id' => 'mid0001', 'summary' => 'Sample midnight', 'start' => $utc("2027-03-01 $clock"), 'end' => $utc('2027-03-01 ' . sprintf('%02d', (int) $clock + 1) . ':00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']]);
    if ($edits) {
        $call($fake, 'PATCH', 'mid0001_20270308T000000Z', ['summary' => 'Sample midnight (own)', 'description' => 'Own notes']);
        $call($fake, 'DELETE', 'mid0001_20270315T000000Z');
    }
    return $fake;
};
$midAllDay = ['start' => ['date' => '2027-03-01', 'dateTime' => null, 'timeZone' => null], 'end' => ['date' => '2027-03-02', 'dateTime' => null, 'timeZone' => null], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']];
$shownDays = static function (FakeGoogle $fake) use ($call): array {
    [, $l] = $call($fake, 'GET', '', null, ['iCalUID' => 'mid0001@google.com', 'singleEvents' => 'true', 'showDeleted' => 'false']);
    return array_map(static fn(array $e): string => (string) $e['id'], $l['items'] ?? []);
};
// 3.48: after the switch the renamed and the deleted day are hidden; no write brings the renamed one back.
$fake = $midnight();
$call($fake, 'PATCH', 'mid0001', $midAllDay);
[$get] = $call($fake, 'GET', 'mid0001_20270308');
[$withEtag] = $call($fake, 'PATCH', 'mid0001_20270308', ['summary' => 'Sample midnight (own)', '_ifMatch' => $fake->events['mid0001']['etag']]);
[$without] = $call($fake, 'PATCH', 'mid0001_20270308', ['summary' => 'Sample midnight (own)']);
[$revive] = $call($fake, 'PATCH', 'mid0001_20270308T000000Z', ['status' => 'confirmed']);
[$delete] = $call($fake, 'DELETE', 'mid0001_20270308T000000Z');
$shown = $shownDays($fake);
check('stand-in, 3.48: a switch hides the renamed and the deleted day at a meeting instant: 4 of 6 shown, GET 404, a PATCH 412 with an If-Match and 404 without, the dropped record 400 to revive and 410 to delete',
    count($shown) === 4 && !in_array('mid0001_20270308', $shown, true) && [$get, $withEtag, $without, $revive, $delete] === [404, 412, 404, 400, 410], json_encode([$shown, $get, $withEtag, $without, $revive, $delete]));
// 3.51: with no record, every day survives.
$fake = $midnight(false);
$call($fake, 'PATCH', 'mid0001', $midAllDay);
check('stand-in, 3.51: with no day edited or deleted every day survives the switch', count($shownDays($fake)) === 6, json_encode($shownDays($fake)));
// 3.63: an hour later first (the records dropped), the days sent again, then the switch: nothing hidden.
$fake = $midnight();
$call($fake, 'PATCH', 'mid0001', ['start' => $utc('2027-03-01 01:00'), 'end' => $utc('2027-03-01 02:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']]);
$call($fake, 'PATCH', 'mid0001_20270308T010000Z', ['summary' => 'Sample midnight (own)']);
$call($fake, 'PATCH', 'mid0001_20270315T010000Z', ['status' => 'cancelled']);
$call($fake, 'PATCH', 'mid0001', $midAllDay);
[$w2] = $call($fake, 'PATCH', 'mid0001_20270308', ['summary' => 'Sample midnight (own)']);
check('stand-in, 3.63: the time moved an hour first, then the switch: every day listed and the renamed day written', count($shownDays($fake)) === 6 && $w2 === 200, json_encode([$shownDays($fake), $w2]));
// 3.65: the days made ordinary first (records at the series' values): still hidden.
$fake = $midnight();
$call($fake, 'PATCH', 'mid0001_20270308T000000Z', ['summary' => 'Sample midnight', 'description' => null]);
$call($fake, 'PATCH', 'mid0001_20270315T000000Z', ['status' => 'confirmed']);
$call($fake, 'PATCH', 'mid0001', $midAllDay);
[$w3] = $call($fake, 'PATCH', 'mid0001_20270308', ['summary' => 'Sample midnight (own)']);
check('stand-in, 3.65: records at the series\' values are hidden by the switch all the same', count($shownDays($fake)) === 4 && $w3 === 404, json_encode([$shownDays($fake), $w3]));

// 3.52 to 3.61: a master write that leaves out the notes clears a kept day's own notes on a series with none.
$notes = static function (?string $series) use ($call): FakeGoogle {
    $fake = new FakeGoogle(CAL);
    $fake->token = null;
    $la = static fn(string $l): array => ['dateTime' => (new DateTimeImmutable($l, new DateTimeZone('America/Los_Angeles')))->format('Y-m-d\TH:i:sP'), 'timeZone' => 'America/Los_Angeles'];
    $fake->seed(['id' => 'notes001', 'summary' => 'Sample notes', 'start' => $la('2027-03-01 10:00'), 'end' => $la('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']] + ($series !== null ? ['description' => $series] : []));
    $call($fake, 'PATCH', 'notes001_20270308T180000Z', ['summary' => 'Sample notes (own)', 'description' => 'Own notes', 'location' => 'Own room']);
    return $fake;
};
$link = ['source' => ['url' => 'https://example.test/sample', 'title' => 'example.test'], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']];
$day = static fn(FakeGoogle $f): array => $f->records('notes001')['20270308T180000Z'] ?? [];
$fake = $notes(null);
$call($fake, 'PATCH', 'notes001', $link);
$cleared = $day($fake);
$fake = $notes('Series notes');
$call($fake, 'PATCH', 'notes001', $link);
$keptWithNotes = $day($fake);
$fake = $notes(null);
$call($fake, 'PATCH', 'notes001', $link + ['description' => '']);
$keptSent = $day($fake);
check('stand-in, 3.52, 3.58, 3.61: a link set on a series with no notes clears the day\'s own notes (title and place kept); with series notes, or the empty notes sent, they stay',
    !isset($cleared['description']) && ($cleared['summary'] ?? null) === 'Sample notes (own)' && ($cleared['location'] ?? null) === 'Own room'
    && ($keptWithNotes['description'] ?? null) === 'Own notes' && ($keptSent['description'] ?? null) === 'Own notes', json_encode([$cleared, $keptWithNotes, $keptSent]));

// 3.62: a rule change leaving only a deleted day cancels the series, and a status sent after does not bring it back.
$fake = new FakeGoogle(CAL);
$fake->token = null;
$fake->seed(['id' => 'only0001', 'summary' => 'Sample only', 'start' => $utc('2027-03-01 10:00'), 'end' => $utc('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=3']]);
$call($fake, 'DELETE', 'only0001_20270301T100000Z');
[, $cut] = $call($fake, 'PATCH', 'only0001', ['recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=1']]);
[, $back] = $call($fake, 'PATCH', 'only0001', ['status' => 'confirmed', 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=1']]);
check('stand-in, 3.62: a rule change leaving only a deleted day cancels the series; status confirmed after leaves it cancelled',
    ($cut['status'] ?? null) === 'cancelled' && ($back['status'] ?? null) === 'cancelled', json_encode([$cut['status'] ?? null, $back['status'] ?? null]));

// 3.66: a day's PATCH with no If-Match is taken in every state a change to all events meets (passes on the stand-in before too).
$fake = $four();
[$a] = $call($fake, 'PATCH', 'four0001_20270308T180000Z', ['summary' => 'Sample four (again)']);
[$b] = $call($fake, 'PATCH', 'four0001_20270315T170000Z', ['status' => 'confirmed', 'summary' => 'Sample four (was deleted)']);
[$c2] = $call($fake, 'PATCH', 'four0001_20270308T180000Z', ['status' => 'cancelled']);
[$d] = $call($fake, 'PATCH', 'four0001_20270322T170000Z', ['status' => 'cancelled']);
check('stand-in, 3.66: a day\'s PATCH with no If-Match is taken on a live record, a cancelled record made live, a record cancelled and an ordinary day cancelled',
    [$a, $b, $c2, $d] === [200, 200, 200, 200] && ($fake->records('four0001')['20270315T170000Z']['status'] ?? null) === 'confirmed', json_encode([$a, $b, $c2, $d]));

// ---- The stand-in against the replay of round 5 (2026-11-14) ------------------
// Cause 5 (app-series-deleted-whole, request 13): an ordinary day deleted on a series holding a renamed day; the next
// delta lists the master, the deleted day and the renamed day, unchanged.
$fake = new FakeGoogle(CAL);
$fake->token = null;
$fake->seed(['id' => 'delta001', 'summary' => 'Sample weekly', 'start' => $utc('2027-05-03 18:00'), 'end' => $utc('2027-05-03 19:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']]);
$call($fake, 'PATCH', 'delta001_20270510T180000Z', ['summary' => 'Sample weekly, one day']);
[, $l] = $call($fake, 'GET', '', null, ['showDeleted' => 'true', 'singleEvents' => 'false']);
$call($fake, 'DELETE', 'delta001_20270517T180000Z');
[, $d] = $call($fake, 'GET', '', null, ['showDeleted' => 'true', 'singleEvents' => 'false', 'syncToken' => (string) $l['nextSyncToken']]);
$listed = [];
foreach ($d['items'] ?? [] as $e) {
    $listed[(string) $e['id']] = (string) ($e['summary'] ?? '') . '/' . (string) ($e['status'] ?? '');
}
ksort($listed);
check('stand-in, round 5 cause 5: after an ordinary day is deleted the delta lists the master, the deleted day and the renamed day unchanged',
    $listed === ['delta001' => 'Sample weekly/confirmed', 'delta001_20270510T180000Z' => 'Sample weekly, one day/confirmed', 'delta001_20270517T180000Z' => 'Sample weekly/cancelled'], json_encode($listed));

// Cause 7 (google-an-untouched-edited-day-keeps-its-own-list-after-a-zone-and-rule-change, request 16): a series weekly
// on Saturdays at 02:30 Kolkata (Fridays 13:00 Los Angeles), a day with its own 12:30 to 14:00 Los Angeles, put back
// to daily at 13:00 to 14:30 Los Angeles from an earlier date. Google moved the day's start onto the series' 13:00 and
// left its end at 14:00.
$fake = new FakeGoogle(CAL);
$fake->token = null;
$kol = static fn(string $l): array => ['dateTime' => (new DateTimeImmutable($l, new DateTimeZone('Asia/Kolkata')))->format('Y-m-d\TH:i:sP'), 'timeZone' => 'Asia/Kolkata'];
$la = static fn(string $l): array => ['dateTime' => (new DateTimeImmutable($l, new DateTimeZone('America/Los_Angeles')))->format('Y-m-d\TH:i:sP'), 'timeZone' => 'America/Los_Angeles'];
$fake->seed(['id' => 'back0001', 'summary' => 'Sample series', 'start' => $kol('2027-03-06 02:30'), 'end' => $kol('2027-03-06 04:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=SA;UNTIL=20270311T075959Z']]);
$call($fake, 'PATCH', 'back0001_20270305T210000Z', ['start' => $la('2027-03-05 12:30'), 'end' => $la('2027-03-05 14:00')]);
$call($fake, 'PATCH', 'back0001', ['start' => $la('2027-03-03 13:00'), 'end' => $la('2027-03-03 14:30'), 'recurrence' => ['RRULE:FREQ=DAILY;BYDAY=WE,TH,FR,SA;UNTIL=20270311T075959Z']]);
$own = $fake->records('back0001')['20270305T210000Z'] ?? [];
$at = static fn(array $w): string => isset($w['dateTime']) ? (new DateTimeImmutable((string) $w['dateTime']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d H:i') : '';
check('stand-in, round 5 cause 7: a series put back to another zone, rule and start date moves a kept day\'s start onto the series\' time and leaves its end',
    $at($own['start'] ?? []) === '2027-03-05 13:00' && $at($own['end'] ?? []) === '2027-03-05 14:00' && ($own['end']['timeZone'] ?? null) === 'America/Los_Angeles', json_encode($own));
// 3.5 (2026-11-07): the start onto its second occurrence; the moved day's start follows, its end stays (none reported changed).
$fake = $standIn();
$call($fake, 'PATCH', 'standin01', ['start' => $la('2027-03-08 10:00'), 'end' => $la('2027-03-08 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
$moved = $fake->records('standin01')['20270315T170000Z'] ?? [];
check('stand-in, 3.5: the start moved along the pattern moves the moved day\'s start onto its occurrence and leaves its end',
    $at($moved['start'] ?? []) === '2027-03-15 10:00' && $at($moved['end'] ?? []) === '2027-03-16 11:00', json_encode($moved));

// Cause 6 (google-a-reading-keeping-a-timed-day-under-an-all-day-series-is-offered-as-locally, request 9: a monthly
// all-day series moved from the 5th to the 6th, its 28 February at 09:00 to 10:00 Berlin, read from 00:00 UTC on its
// date to 10:00 Berlin, neither end in a zone). The same shape on a weekly all-day series (the stand-in's own reading
// of rules has no monthly days), its start moved a week along the pattern.
$fake = new FakeGoogle(CAL);
$fake->token = null;
$fake->seed(['id' => 'allday01', 'summary' => 'Sample series', 'start' => ['date' => '2027-03-01'], 'end' => ['date' => '2027-03-02'], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']]);
$call($fake, 'PATCH', 'allday01_20270315', ['start' => ['dateTime' => '2027-03-15T09:00:00+01:00', 'timeZone' => 'Europe/Berlin', 'date' => null], 'end' => ['dateTime' => '2027-03-15T10:00:00+01:00', 'timeZone' => 'Europe/Berlin', 'date' => null]]);
$call($fake, 'PATCH', 'allday01', ['start' => ['date' => '2027-03-08'], 'end' => ['date' => '2027-03-09'], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']]);
$own = $fake->records('allday01')['20270315'] ?? [];
$utcAt = static fn(array $w): string => isset($w['dateTime']) ? (new DateTimeImmutable((string) $w['dateTime']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') : '';
check('stand-in, round 5 cause 6: a timed day of an all-day series reads from 00:00 UTC on its date to its own end, neither in a zone, after the series\' start moves',
    [$utcAt($own['start'] ?? []), $utcAt($own['end'] ?? []), isset($own['start']['timeZone']) || isset($own['end']['timeZone'])] === ['2027-03-15 00:00', '2027-03-15 09:00', false], json_encode($own));

// ---- The stand-in against the probe of 2026-11-14 (3.67, 3.68) ----------------
// 3.67: weekly 10:00 to 11:00 Los Angeles, occurrence 2 at its own 14:00 to 15:30, occurrence 3 renamed; the first day
// moved a week later for all events. Google: occurrence 2 read 10:00 to 15:30 (start onto its occurrence, end left),
// occurrence 3 kept its 10:00 to 11:00 and its title.
$fake = new FakeGoogle(CAL);
$fake->token = null;
$fake->seed(['id' => 'drag0001', 'summary' => 'Sample drag', 'description' => '', 'start' => $la('2027-03-01 10:00'), 'end' => $la('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']]);
$call($fake, 'PATCH', 'drag0001_20270308T180000Z', ['start' => $la('2027-03-08 14:00'), 'end' => $la('2027-03-08 15:30')]);
$call($fake, 'PATCH', 'drag0001_20270315T170000Z', ['summary' => 'Sample drag (one renamed)']);
$call($fake, 'PATCH', 'drag0001', ['start' => $la('2027-03-08 10:00'), 'end' => $la('2027-03-08 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']]);
$two = $fake->records('drag0001')['20270308T180000Z'] ?? [];
$three = $fake->records('drag0001')['20270315T170000Z'] ?? [];
check('stand-in, 3.67: the start along the pattern: the own-time day reads 10:00 to 15:30, the renamed day keeps 10:00 to 11:00 and its title',
    [$at($two['start'] ?? []), $at($two['end'] ?? []), $at($three['start'] ?? []), $at($three['end'] ?? []), $three['summary'] ?? null]
    === ['2027-03-08 10:00', '2027-03-08 15:30', '2027-03-15 10:00', '2027-03-15 11:00', 'Sample drag (one renamed)'], json_encode([$two, $three]));
// 3.68: a series with no notes, a day with its own notes; a master PATCH with one private property and no recurrence
// clears the notes; a title-only PATCH after leaves them absent and renames the day, which held the series' title.
$fake = $notes(null);
$call($fake, 'PATCH', 'notes001_20270308T180000Z', ['summary' => 'Sample notes']);
$call($fake, 'PATCH', 'notes001', ['extendedProperties' => ['private' => ['sampleKey' => 'one']]]);
$afterProperty = $day($fake);
$call($fake, 'PATCH', 'notes001', ['summary' => 'Sample notes, renamed']);
$afterTitle = $day($fake);
check('stand-in, 3.68: a master PATCH with no recurrence clears a day\'s own notes on a series with none; a title-only PATCH after leaves them absent and renames the day',
    !array_key_exists('description', $afterProperty) && !array_key_exists('description', $afterTitle) && ($afterTitle['summary'] ?? null) === 'Sample notes, renamed', json_encode([$afterProperty, $afterTitle]));

// ---- Report scrubbing on its own ---------------------------------------------
$r = new Report($tmp . '/scrub');
$r->secret('someone@example.net', '{account}');
$scrubbed = $r->scrub(['organizer' => ['email' => 'someone@example.net', 'displayName' => 'A calendar name'], 'attendees' => [['email' => 'other@example.com']], 'htmlLink' => 'https://x/?eid=abc', 'nextSyncToken' => 'abc', 'text' => 'from someone%40example.net']);
check('scrub: the account address, names, other addresses, links and tokens are replaced',
    $scrubbed['organizer'] === ['email' => '{account}', 'displayName' => '{name}'] && $scrubbed['attendees'][0]['email'] === '{another address}'
    && $scrubbed['htmlLink'] === '{link}' && $scrubbed['nextSyncToken'] === 'sync-1' && $scrubbed['text'] === 'from {account}', json_encode($scrubbed));
check('rule story: reordered parts are named as reordered', str_contains(Probe::ruleStory(['RRULE:FREQ=WEEKLY;COUNT=3;BYDAY=MO'], ['RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=3']), 'came back reordered'));
check('rule story: the same text is "as sent"', Probe::ruleStory(['RRULE:FREQ=DAILY'], ['RRULE:FREQ=DAILY']) === 'recurrence came back as sent');

// tidy up
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($tmp);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
