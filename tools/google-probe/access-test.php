<?php
// The access probe's own plumbing, against the stand-in in lib/FakeGoogle.php
// behind a gate that plays each stage: every stage name runs without error,
// the state file round-trips between runs (ids, etags, the sync token and
// the stage it came from), writer makes the single event and the series with
// one day renamed, cleanup deletes what the state lists and leaves the
// owner's event, the report holds a table per stage and the template and
// nothing private, writer refuses a calendar the account owns or may only
// read, and no write goes out without sendUpdates=none.
//
// What the gate answers in each state (403 on a write when view-only, 404
// for everything when unshared or deleted, busy-only lists stripped to their
// times) is invented: nobody has seen Google's answers yet, which is what
// the probe is for. The test proves nothing about Google.
//
//   php tools/google-probe/access-test.php
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
require __DIR__ . '/lib/AccessProbe.php';

use BetterCal\GoogleProbe\AccessProbe;
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

const CAL = 'accesscal0example@group.calendar.google.com';
const ACCOUNT = 'alex.example@example.com';
const OWNER = 'sam.sample@example.org';

/** Every request the gate saw, refused or passed on: [method, path, query]. */
$seen = [];

/** The stand-in behind a gate playing one access state (invented answers, see the file comment). */
function gated(FakeGoogle $fake, string $mode, array &$seen): \Closure
{
    $inner = $fake->transport();
    return static function (string $method, string $url, array $headers, ?array $payload) use ($fake, $mode, $inner, &$seen): array {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $path = substr((string) $parts['path'], strlen('/calendar/v3'));
        $seen[] = ['method' => $method, 'path' => $path, 'query' => $query, 'mode' => $mode];
        if (($GLOBALS['throwOnce'] ?? null) !== null && preg_match($GLOBALS['throwOnce'], $path) === 1) {
            $GLOBALS['throwOnce'] = null; // one lost connection, for the after-an-error check
            throw new \RuntimeException('HTTP error for the test: connection lost');
        }
        $err = static fn(int $code, string $reason, string $message): array => ['status' => $code, 'body' => (string) json_encode(['error' => ['code' => $code, 'message' => $message, 'errors' => [['domain' => 'global', 'reason' => $reason, 'message' => $message]]]])];
        if (in_array($mode, ['unshared', 'deleted'], true)) {
            return $err(404, 'notFound', 'Not Found');
        }
        $role = ['writer' => 'writer', 'restored' => 'writer', 'reader' => 'reader', 'freebusy' => 'freeBusyReader', 'owned' => 'owner'][$mode];
        $fake->role = $role;
        if ($method !== 'GET' && in_array($role, ['reader', 'freeBusyReader'], true)) {
            return $err(403, 'requiredAccessLevel', 'You need to have writer access to this calendar.');
        }
        $isEventGet = $method === 'GET' && preg_match('#/events/[^/]+$#', $path) === 1;
        if ($role === 'freeBusyReader' && $isEventGet) {
            return $err(404, 'notFound', 'Not Found');
        }
        $r = $inner($method, $url, $headers, $payload);
        $body = json_decode((string) $r['body'], true);
        if (is_array($body) && ($body['kind'] ?? '') === 'calendar#events') {
            $body['accessRole'] = $role;
            if ($role === 'freeBusyReader') {
                $body['items'] = array_map(static fn(array $e) => array_intersect_key($e, array_flip(['id', 'status', 'start', 'end'])), $body['items']);
            }
            $r['body'] = (string) json_encode($body);
        }
        return $r;
    };
}

function stageRun(FakeGoogle $fake, string $dir, string $mode, string $stage, array &$seen): array
{
    $api = new Api(gated($fake, $mode, $seen), static fn(): string => 'fake-token', CAL, static function (float $s): void {
    }, 0.25);
    $scrub = new Report($dir);
    $scrub->secret(ACCOUNT, '{account}');
    $scrub->secret(CAL, '{calendar}');
    $probe = new AccessProbe($api, $scrub, $dir, false, new \DateTimeImmutable('2027-03-01T08:00:00-08:00'), true);
    $before = count($seen);
    $record = $probe->run($stage);
    return [$probe, $record, array_slice($seen, $before)];
}

$tmp = sys_get_temp_dir() . '/google-access-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);

// ---- The stages in the README's order, with cleanup before the delete ------
$fake = new FakeGoogle(CAL);
$fake->owner = ACCOUNT;
$fake->seed(['id' => 'ownerevent01', 'summary' => 'Owner event', 'description' => 'Invented owner notes', 'location' => 'Example Hall', 'creator' => ['email' => OWNER], 'start' => ['dateTime' => '2027-03-05T10:00:00-08:00'], 'end' => ['dateTime' => '2027-03-05T11:00:00-08:00']]);
$dir = $tmp . '/stages';
$counts = [];
$records = [];
foreach ([['writer', 'writer'], ['reader', 'reader'], ['freebusy', 'freebusy'], ['unshared', 'unshared'], ['restored', 'restored'], ['writer', 'writer', 'writer-2'], ['restored', 'cleanup'], ['deleted', 'deleted']] as $run) {
    [$mode, $stage] = $run;
    $key = $run[2] ?? $stage;
    [$probe, $record, $sent] = stageRun($fake, $dir, $mode, $stage, $seen);
    $records[$key] = $record;
    $counts[$key] = count($sent);
    check("$key: runs without stopping", !isset($record['stopped']), (string) ($record['stopped'] ?? ''));
    check("$key: every request is a row", count($record['rows']) === count($sent), count($record['rows']) . ' rows, ' . count($sent) . ' sent');
    if ($key === 'writer') {
        $state = AccessProbe::loadState($probe->statePath);
        check('writer: the state file names the single event, the series, its renamed day and the insert',
            isset($state['single']['id'], $state['single']['etag'], $state['series']['id'], $state['series']['day']) && count($state['inserted']) === 1, json_encode($state));
        check('writer: the series is weekly, four days, its second day renamed',
            ($fake->events[$state['series']['id']]['recurrence'] ?? null) === ['RRULE:FREQ=WEEKLY;COUNT=4']
            && (array_values($fake->records($state['series']['id']))[0]['summary'] ?? '') === AccessProbe::DAY_TITLE . ' (writer)');
        check('writer: the owner\'s event is found and kept in the state', ($state['ownerEvent']['id'] ?? null) === 'ownerevent01');
        check('writer: the sync token is saved with its stage', ($state['sync']['stage'] ?? null) === 'writer' && str_starts_with((string) ($state['sync']['token'] ?? ''), 'fake-sync-'));
        check('writer: the setup comes between the reads and the insert',
            implode(',', array_column($record['rows'], 'n')) === '1,2,3,4,S1,S2,S3,S4,5,6a,6b,6c,6g,6d,6e,6f,6h,7', implode(',', array_column($record['rows'], 'n')));
        $byN = array_column($record['exchanges'], null, 'n');
        check('writer: the single event\'s PATCH carries If-Match on the etag just read',
            isset($byN['6a']['response']['etag'], $byN['6d']) && $byN['6d']['headers'] === ['If-Match: ' . $byN['6a']['response']['etag']] && $byN['6d']['status'] === 200);
        $tokenAtWriter = $state['sync']['token'];
    }
    if ($key === 'reader') {
        $delta = array_values(array_filter($sent, static fn(array $r) => isset($r['query']['syncToken'])));
        check('reader: the delta sends the token writer saved', count($delta) === 1 && $delta[0]['query']['syncToken'] === $tokenAtWriter);
        $row4d = array_values(array_filter($record['rows'], static fn(array $r) => $r['n'] === '4d'))[0] ?? [];
        // The stand-in answers as Google did at stage reader (the access probe, row 4d): a change of access
        // makes the token from before it answer 410 fullSyncRequired.
        check('reader: the delta from the token writer saved answers 410 fullSyncRequired, as Google did', ($row4d['status'] ?? null) === 410 && ($row4d['reason'] ?? null) === 'fullSyncRequired', json_encode($row4d));
        $writes = array_filter($record['rows'], static fn(array $r) => $r['method'] !== 'GET');
        check('reader: every write is recorded with its status and reason', $writes !== [] && array_filter($writes, static fn(array $r) => $r['status'] !== 403 || $r['reason'] !== 'requiredAccessLevel') === []);
        check('reader: nothing new is made', count(AccessProbe::loadState($probe->statePath)['inserted']) === 1);
    }
    if ($key === 'unshared') {
        // Google gives no sync token at busy times only (the second live round, 2026-11-14, stage freebusy, row 4), so the one saved is reader's.
        check('unshared: the token saved before is still the one from reader (busy times only gave none)', (AccessProbe::loadState($probe->statePath)['sync']['stage'] ?? null) === 'reader');
    }
    if ($key === 'writer-2') {
        // No change of access since stage restored saved its token: the delta answers (row 4d, the second run of restored).
        $row4d = array_values(array_filter($record['rows'], static fn(array $r) => $r['n'] === '4d'))[0] ?? [];
        check('writer-2: a list row says whether a sync token came (not the token itself)', ($row4d['fields']['sync token'] ?? null) === 'given', json_encode($row4d));
        check('writer-2: the delta row names the stage its token came from', ($row4d['fields']['tokenFrom'] ?? null) === 'restored', json_encode($row4d));
        check('a second writer run makes nothing again', array_filter($record['rows'], static fn(array $r) => str_starts_with($r['n'], 'S')) === [] && str_contains(implode(' ', $record['notes']), 'not made again'));
    }
    if ($key === 'cleanup') {
        $state = AccessProbe::loadState($probe->statePath);
        check('cleanup: only deletes', array_filter($sent, static fn(array $r) => $r['method'] !== 'DELETE') === []);
        check('cleanup: the state lists nothing left', $state['single'] === null && $state['series'] === null && $state['inserted'] === [], json_encode($state));
        $live = array_filter($fake->events, static fn(array $e) => Probe::isMarked($e) && empty($e['recurringEventId']) && ($e['status'] ?? '') !== 'cancelled');
        check('cleanup: nothing the probe made is live', $live === [], implode(', ', array_keys($live)));
        check('cleanup: the owner\'s event is untouched', ($fake->events['ownerevent01']['status'] ?? '') === 'confirmed' && ($fake->events['ownerevent01']['summary'] ?? '') === 'Owner event (writer)');
    }
}
check('request counts: writer 18, a later stage 15, cleanup one per event, deleted 7 (no 6 once cleanup emptied the state)',
    $counts['writer'] === 18 && $counts['reader'] === 15 && $counts['freebusy'] === 15 && $counts['unshared'] === 15 && $counts['restored'] === 15 && $counts['cleanup'] === 5 && $counts['writer-2'] === 15 && $counts['deleted'] === 7,
    json_encode($counts));

$writes = array_filter($seen, static fn(array $r) => $r['method'] !== 'GET');
check('every write the probe sent carries sendUpdates=none', $writes !== [] && array_filter($writes, static fn(array $r) => ($r['query']['sendUpdates'] ?? null) !== 'none') === []);
check('no request goes to another calendar', array_filter($seen, static fn(array $r) => !str_starts_with($r['path'], '/calendars/' . rawurlencode(CAL)) && $r['path'] !== '/users/me/calendarList/' . rawurlencode(CAL)) === []);

// ---- The state file round-trips --------------------------------------------
$state = ['single' => ['id' => 'abc0001', 'etag' => '"1"'], 'series' => ['id' => 'abc0002', 'etag' => '"2"', 'day' => 'abc0002_20270315T180000Z', 'dayEtag' => '"3"'], 'inserted' => ['abc0003'], 'ownerEvent' => null, 'sync' => ['token' => 'tok', 'stage' => 'reader', 'at' => '2027-03-01T08:00:00.000-08:00'], 'stages' => ['writer', 'reader'], 'listreadMade' => []];
$probe->state = $state;
$probe->saveState();
check('state: what is saved loads back the same', AccessProbe::loadState($probe->statePath) === $state);
check('state: the file is named by a hash of the calendar, not its id', !str_contains($probe->statePath, 'accesscal0example') && !str_contains($probe->mdPath, 'accesscal0example'));
check('state: a missing file is the empty state', AccessProbe::loadState($tmp . '/none.json') === AccessProbe::emptyState());

// ---- The report --------------------------------------------------------------
$md = (string) file_get_contents($probe->mdPath);
$json = (string) file_get_contents($probe->jsonPath);
$stages = json_decode($json, true)['stages'] ?? [];
check('report: one table per run, in order', array_column($stages, 'stage') === ['writer', 'reader', 'freebusy', 'unshared', 'restored', 'writer', 'cleanup', 'deleted']);
foreach (['writer', 'reader', 'freebusy', 'unshared', 'restored', 'cleanup', 'deleted'] as $s) {
    check("report: a section for stage $s", str_contains($md, "## Stage $s\n"));
}
check('report: the across-stages table and the template are there', str_contains($md, '## Across Stages') && str_contains($md, '## What a Client Can Rely On') && substr_count($md, '  - Answer: ') === 8);
foreach (['the account address' => ACCOUNT, 'the owner\'s address' => OWNER, 'the calendar id' => CAL, 'a sync token' => 'fake-sync-', 'the bearer token' => 'fake-token', 'an event link' => 'eid='] as $what => $needle) {
    check("report: never holds $what", !str_contains($md, $needle) && !str_contains($json, $needle));
}
// The owner's own event is the one the access probe expects not to be its own (issue 279): it is read at every stage and
// renamed at writer, and only its id and status may reach the report, rows and exchanges alike.
foreach (['Invented owner notes', 'Example Hall', '2027-03-05T10:00'] as $text) {
    check("report: the owner's event's \"$text\" is in neither file", !str_contains($md, $text) && !str_contains($json, $text));
}
$ownerShown = [];
$walk = static function (mixed $v) use (&$walk, &$ownerShown): void {
    if (is_array($v)) {
        if (($v['id'] ?? null) === 'ownerevent01') {
            $ownerShown[] = array_keys($v);
        }
        array_map($walk, $v);
    }
};
$walk(json_decode($json, true));
check('report: the owner\'s event appears (the check above is not empty), as its id and status alone', $ownerShown !== [] && array_filter($ownerShown, static fn(array $k) => array_diff($k, ['id', 'status']) !== []) === [], json_encode($ownerShown));
$g6 = array_values(array_filter($stages[0]['rows'] ?? [], static fn(array $r) => $r['n'] === '6g'))[0]['fields'] ?? [];
check('report: the owner\'s event\'s row has its status, not its title', ($g6['status field'] ?? null) === 'confirmed' && ($g6['summary'] ?? null) === '(absent)', json_encode($g6));

// ---- Stage listread ------------------------------------------------------------
// On a calendar the account owns (the probe calendar) and one it may write. The stand-in has no events.import route,
// so cases 3 and 4 are skipped against it; its answers prove nothing about Google, only that the stage runs and cleans up.
$liveMarked = static fn(FakeGoogle $f): array => array_keys(array_filter($f->events, static fn(array $e) => Probe::isMarked($e) && empty($e['recurringEventId']) && ($e['status'] ?? '') !== 'cancelled'));
foreach (['owned', 'writer'] as $mode) {
    $fake = new FakeGoogle(CAL);
    $fake->owner = ACCOUNT;
    $fake->seed(['id' => 'ownerevent01', 'summary' => 'Owner event', 'start' => ['date' => '2027-03-05'], 'end' => ['date' => '2027-03-06']]);
    $fake->seed(['id' => 'other02', 'summary' => 'Sample Hall booking', 'start' => ['date' => '2027-03-06'], 'end' => ['date' => '2027-03-07']]);
    [$probe, $record, $sent] = stageRun($fake, "$tmp/listread-$mode", $mode, 'listread', $seen);
    $ns = array_column($record['rows'], 'n');
    check("listread ($mode): runs without stopping", !isset($record['stopped']), (string) ($record['stopped'] ?? ''));
    check("listread ($mode): no case stopped", !str_contains(implode(' ', $record['notes']), 'stopped'), implode(' ', $record['notes']));
    check("listread ($mode): says the import cases were skipped", str_contains(implode(' ', $record['notes']), 'L3 and L4 (events.import) skipped'));
    check("listread ($mode): every case's rows are there", array_diff(['L', 'L1.get', 'L1.list-true', 'L1.list-false', 'L2.get', 'L5.delete', 'L5.list-true', 'L6.patch', 'L6.list', 'L6.patch-if-match', 'L7.list-true', 'L7.get-master', 'L7.get-day', 'L8.get', 'L8.list'], $ns) === [], implode(',', $ns));
    $l1 = array_values(array_filter($record['rows'], static fn(array $r) => $r['n'] === 'L1.list-true'))[0]['fields'] ?? [];
    check("listread ($mode): a list row names the differing fields, both etags, accessRole and the item count", isset($l1['differs'], $l1['etag (GET)'], $l1['etag (list)'], $l1['accessRole'], $l1['item count']), json_encode($l1));
    check("listread ($mode): 31 requests (no import against the stand-in)", count($sent) === 31, (string) count($sent));
    check("listread ($mode): everything it made is deleted", $liveMarked($fake) === [] && AccessProbe::loadState($probe->statePath)['listreadMade'] === [], implode(', ', $liveMarked($fake)));
    check("listread ($mode): events it did not make are untouched", ($fake->events['ownerevent01']['summary'] ?? '') === 'Owner event' && ($fake->events['other02']['status'] ?? '') === 'confirmed');
    check("listread ($mode): every write carries sendUpdates=none", array_filter($sent, static fn(array $r) => $r['method'] !== 'GET' && ($r['query']['sendUpdates'] ?? null) !== 'none') === []);
    check("listread ($mode): the other stages' state is left alone", ($s = AccessProbe::loadState($probe->statePath))['single'] === null && $s['sync'] === null && $s['ownerEvent'] === null);
}

// After an error: one lost connection in case 7 stops that case alone, and the stage still deletes everything.
$fake = new FakeGoogle(CAL);
$GLOBALS['throwOnce'] = '#/instances$#';
[$probe, $record] = stageRun($fake, "$tmp/listread-error", 'owned', 'listread', $seen);
check('listread after an error: the case says it stopped', str_contains(implode(' ', $record['notes']), 'L7 stopped'), implode(' ', $record['notes']));
check('listread after an error: everything it made is deleted', $liveMarked($fake) === [], implode(', ', $liveMarked($fake)));

// What a run that died left is deleted first by the next one.
$fake = new FakeGoogle(CAL);
$fake->seed(['id' => 'leftover01', 'summary' => 'Access probe left', 'extendedProperties' => ['private' => [Probe::MARKER_KEY => 'access']], 'start' => ['date' => '2027-03-05'], 'end' => ['date' => '2027-03-06']]);
$state = AccessProbe::emptyState();
$state['listreadMade'] = ['leftover01'];
@mkdir("$tmp/listread-left", 0700, true);
[$probe] = stageRun($fake, "$tmp/listread-left", 'owned', 'cleanup', $seen); // makes the state file's path
$probe->state = $state;
$probe->saveState();
[, $record] = stageRun($fake, "$tmp/listread-left", 'owned', 'listread', $seen);
check('listread: an earlier run\'s leftover is deleted first', ($record['rows'][0]['n'] ?? '') === 'L0.1' && ($fake->events['leftover01']['status'] ?? '') === 'cancelled');

// A calendar the account may only read is refused, with nothing written.
$fake = new FakeGoogle(CAL);
[, $record, $sent] = stageRun($fake, "$tmp/listread-reader", 'reader', 'listread', $seen);
check('listread: a calendar the account may only read is refused', str_contains((string) ($record['stopped'] ?? ''), 'may write') && array_filter($sent, static fn(array $r) => $r['method'] !== 'GET') === []);

// ---- Refusals at writer ------------------------------------------------------
foreach (['owned' => 'owns this calendar', 'reader' => 'Make changes to events', 'unshared' => "isn't in the connected account's list"] as $mode => $says) {
    $fake = new FakeGoogle(CAL);
    $before = count($seen);
    [, $record, $sent] = stageRun($fake, "$tmp/refuse-$mode", $mode, 'writer', $seen);
    check("refusal ($mode): writer stops and says why", str_contains((string) ($record['stopped'] ?? ''), $says), (string) ($record['stopped'] ?? ''));
    check("refusal ($mode): nothing was written", array_filter($sent, static fn(array $r) => $r['method'] !== 'GET') === []);
}
$fake = new FakeGoogle(CAL);
foreach (['other01', 'other02'] as $id) {
    $fake->seed(['id' => $id, 'summary' => 'Sample Hall booking', 'start' => ['date' => '2027-03-02'], 'end' => ['date' => '2027-03-03']]);
}
[, $record, $sent] = stageRun($fake, "$tmp/refuse-others", 'writer', 'writer', $seen);
check('refusal: a calendar with more than the owner\'s one event is refused', str_contains((string) ($record['stopped'] ?? ''), '--allow-other-events') && array_filter($sent, static fn(array $r) => $r['method'] !== 'GET') === []);

// ---- Round 2: the second live round's requests through all five stages, then cleanup ----
// As above, what the gate answers is invented; this only shows the requests run in each stage, in their order, and
// that cleanup deletes what they make. It proves nothing about Google.
function stageRun2(FakeGoogle $fake, string $dir, string $mode, string $stage, array &$seen): array
{
    $api = new Api(gated($fake, $mode, $seen), static fn(): string => 'fake-token', CAL, static function (float $s): void {
    }, 0.25);
    $scrub = new Report($dir);
    $scrub->secret(ACCOUNT, '{account}');
    $scrub->secret(CAL, '{calendar}');
    $probe = new AccessProbe($api, $scrub, $dir, false, new \DateTimeImmutable('2027-03-01T08:00:00-08:00'), true, 2, static function (float $s): void {
    });
    $before = count($seen);
    $record = $probe->run($stage);
    return [$probe, $record, array_slice($seen, $before)];
}
$fake = new FakeGoogle(CAL);
$fake->owner = ACCOUNT;
$fake->seed(['id' => 'ownerevent01', 'summary' => 'Owner event', 'creator' => ['email' => OWNER], 'start' => ['dateTime' => '2027-03-05T10:00:00-08:00'], 'end' => ['dateTime' => '2027-03-05T11:00:00-08:00']]);
$dir2 = $tmp . '/round2';
foreach (['writer', 'reader', 'freebusy', 'unshared', 'restored', 'cleanup'] as $stage) {
    [$probe, $record, $sent] = stageRun2($fake, $dir2, $stage === 'cleanup' ? 'restored' : $stage, $stage, $seen);
    $ns = array_column($record['rows'], 'n');
    check("round 2, $stage: runs without stopping", !isset($record['stopped']), (string) ($record['stopped'] ?? ''));
    check("round 2, $stage: every request is a row", count($record['rows']) === count($sent), count($record['rows']) . ' rows, ' . count($sent) . ' sent');
    if ($stage === 'cleanup') {
        $live = array_filter($fake->events, static fn(array $e) => Probe::isMarked($e) && empty($e['recurringEventId']) && ($e['status'] ?? '') !== 'cancelled');
        check('round 2, cleanup: nothing the probe made is live, the deletables included', $live === [], implode(', ', array_keys($live)));
        check('round 2, cleanup: the owner\'s event is still there', ($fake->events['ownerevent01']['status'] ?? '') === 'confirmed');
        continue;
    }
    check("round 2, $stage: U1, U2 and I are sent", array_diff(['U1', 'U2', 'I'], $ns) === [], implode(',', $ns));
    $deletes = array_values(array_filter($ns, static fn(string $n) => in_array($n, ['D1', 'D2', 'D3', 'D4'], true)));
    check("round 2, $stage: the deletes D1 to D4, in order, only at a lowered stage", $deletes === (in_array($stage, ['reader', 'freebusy', 'unshared'], true) ? ['D1', 'D2', 'D3', 'D4'] : []), implode(',', $deletes));
    if ($stage === 'writer') {
        $state = AccessProbe::loadState($probe->statePath);
        check('round 2, writer: three sets of deletables, each with its etags', array_keys($state['deletables'] ?? []) === ['reader', 'freebusy', 'unshared']
            && array_filter($state['deletables'], static fn(array $d) => !isset($d['single']['etag'], $d['series']['etag'], $d['series']['dayEtag'])) === [], json_encode($state['deletables'] ?? null));
        check('round 2, writer: the owner\'s etag at writer is stored', ($state['ownerEtagAtWriter'] ?? '') !== '');
    }
    if ($stage === 'reader') {
        $byN = array_column($record['exchanges'], null, 'n');
        $d = AccessProbe::loadState($probe->statePath)['deletables']['reader'];
        check('round 2, reader: each delete carries the etag stored for it', ($byN['D1']['headers'] ?? null) === ['If-Match: ' . $d['single']['etag']] && ($byN['D2']['headers'] ?? null) === ['If-Match: ' . $d['series']['dayEtag']] && ($byN['D3']['headers'] ?? null) === ['If-Match: ' . $d['series']['etag']]);
        $u2 = array_values(array_filter($record['rows'], static fn(array $r) => $r['n'] === 'U2'))[0]['fields'] ?? [];
        check('round 2, reader: U2 gives the item count, accessRole and each item\'s key names', isset($u2['item count'], $u2['accessRole'], $u2['item 1 keys']), json_encode($u2));
        $r4 = array_values(array_filter($record['rows'], static fn(array $r) => $r['n'] === '4'))[0]['fields'] ?? [];
        check('round 2, reader: request 4 names the first probe item\'s keys', isset($r4['first probe item keys']), json_encode($r4));
    }
    if ($stage === 'restored') {
        check('round 2, restored: R0 comes first, and the deletables are listed at the end', str_starts_with($ns[0] ?? '', 'R0.') && count(array_filter($ns, static fn(string $n) => str_starts_with($n, 'R9.'))) === 6, implode(',', $ns));
        check('round 2, restored: the note says when the PATCH was taken', str_contains(implode(' ', $record['notes']), 'R0: the first PATCH Google took was try 1'));
    }
}
check('round 2: the owner\'s event was never deleted by the stand-in (every lowered stage refused it)', ($fake->events['ownerevent01']['status'] ?? '') === 'confirmed');

// tidy up, unless ACCESS_TEST_KEEP=1 asks to keep the stand-in's report to read
if (getenv('ACCESS_TEST_KEEP') === '1') {
    echo "Kept: $tmp/stages\n";
} else {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($tmp);
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
