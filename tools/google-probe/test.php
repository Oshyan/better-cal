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
