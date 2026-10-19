<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
$resourceStandalone = !function_exists('check');
if ($resourceStandalone) {
    $GLOBALS['__pass'] = $GLOBALS['__fail'] = 0;
    function check(string $name, bool $ok, string $detail = ''): void {
        $GLOBALS[$ok ? '__pass' : '__fail']++;
        if (!$ok) echo "FAIL: $name $detail\n";
    }
    function checkEq(string $name, mixed $want, mixed $got): void { check($name, $want === $got, json_encode([$want, $got])); }
}

use BetterCal\Domain\{Ics, MailIngest, CalendarImport, PushSubscriptions};
use BetterCal\Dav\SizedCalDavPlugin;
use BetterCal\Support\Limits;

$event = static fn(string $uid, string $extra = ''): string => "BEGIN:VEVENT\r\nUID:$uid\r\nSUMMARY:Sample event\r\nDTSTART:20270101T090000Z\r\nDTEND:20270101T100000Z\r\n$extra" . "END:VEVENT\r\n";
$calendar = static fn(string $events): string => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n$events" . "END:VCALENDAR\r\n";
// ICS parsing needs sabre/vobject (the install job runs it).
if (class_exists(\Sabre\VObject\Reader::class)) {
foreach (['DTSTART', 'DTEND', 'RECURRENCE-ID', 'DTSTAMP', 'CREATED', 'LAST-MODIFIED', 'DUE', 'COMPLETED', 'ACKNOWLEDGED', 'TRIGGER'] as $property) {
    $bad = $event('bad@example.test', $property . ';X-NOTE="https://example.test/a":20270101T090000Z,20270102T090000Z' . "\r\n");
    $parsed = Ics::parse($calendar($bad . $event('good@example.test')), null, $skipped);
    checkEq('singleton dates: only malformed event refused: ' . $property, ['good@example.test'], array_column($parsed, 'uid'));
    checkEq('singleton dates: rejected UID reported', ['bad@example.test'], array_column($skipped ?? [], 'uid'));
}
$ics = $calendar($event('folded@example.test', "item.DTSTART;VALUE=DATE:20270101,\r\n 20270102\r\n") . $event('good@example.test', "EXDATE:20270102T090000Z,20270103T090000Z\r\n"));
$parsed = Ics::parse($ics, null, $skipped);
checkEq('folded grouped singleton is rejected, legitimate EXDATE list kept', [1, 2, 1], [count($parsed), count($parsed[0]['exdates']), count($skipped)]);
$imip = MailIngest::parseImip($ics);
checkEq('mail uses the same accepted document and reports bad siblings', [1, 'good@example.test', 1], [count($imip['events']), $imip['events'][0]['uid'], count($imip['skipped'])]);
$sameUid = $calendar($event('series@example.test', "ORGANIZER:mailto:sam@example.test\r\n") . $event('series@example.test', "RECURRENCE-ID:20270102T090000Z,20270103T090000Z\r\nORGANIZER:mailto:other@example.test\r\n"));
$imip = MailIngest::parseImip($sameUid);
checkEq('mail never joins rejected metadata to a good same-UID event', [1, 'sam@example.test'], [count($imip['events']), $imip['events'][0]['invite']['organizer']['email']]);
foreach (['DTSTART', 'DTEND'] as $property) {
    $bad = preg_replace('/' . $property . ':[^\r]+/', $property . ':garbage', $event('broken@example.test'));
    $parsed = Ics::parse($calendar($bad . $event('good@example.test')), null, $skipped);
    checkEq('unreadable date rejects and reports only its event', [['good@example.test'], ['broken@example.test']], [array_column($parsed, 'uid'), array_column($skipped, 'uid')]);
}
$customZone = "BEGIN:VTIMEZONE\r\nTZID:Custom/Sample\r\nX-LIC-LOCATION:Asia/Kolkata\r\nEND:VTIMEZONE\r\n";
$zoned = str_replace('DTSTART:20270101T090000Z', 'DTSTART;TZID=Custom/Sample:20270101T090000', $event('zoned@example.test'));
$zoned = str_replace('DTEND:20270101T100000Z', 'DTEND;TZID=Custom/Sample:20270101T100000', $zoned);
checkEq('mail keeps the parent calendar timezone definitions', Ics::parse($calendar($customZone . $zoned))[0]['start_utc'], MailIngest::parseImip($calendar($customZone . $zoned))['events'][0]['start_utc']);
foreach (['escaped\\,uid@example.test', 'backslash\\\\name@example.test', 'line\\nname@example.test'] as $uid) {
    $goodUid = Ics::parse($calendar($event($uid)))[0]['uid'];
    Ics::parse($calendar($event($uid, "DTEND:20900101T100000Z,20900102T100000Z\r\n")), null, $skipped);
    checkEq('rejected UID unescaping matches stored identity', $goodUid, $skipped[0]['uid']);
}
}

$reject = static function (string $name, callable $fn): void {
    try { $fn(); check($name, false, 'accepted'); } catch (\Exception) { check($name, true); }
};
$resourceLimits = array_combine(Limits::names(), array_map([Limits::class, 'get'], Limits::names()));
if (!class_exists(\Sabre\CalDAV\Plugin::class)) {
    // SizedCalDavPlugin extends sabre/dav's plugin (the install job runs it).
} elseif (method_exists(SizedCalDavPlugin::class, 'readBody')) {
    Limits::configure(['DAV_OBJECT_BYTES' => 1024]);
    $stream = fopen('php://temp', 'w+'); fwrite($stream, str_repeat('x', 8192)); rewind($stream);
    $reject('CalDAV declared length refused before reading', fn() => SizedCalDavPlugin::readBody($stream, '8192'));
    checkEq('declared oversize read zero bytes', 0, ftell($stream));
    $reject('CalDAV undeclared length stops at the byte boundary', fn() => SizedCalDavPlugin::readBody($stream));
    checkEq('bounded stream consumed max plus one only', 1025, ftell($stream)); fclose($stream);
    checkEq('ordinary CalDAV body preserved', 'sample', SizedCalDavPlugin::readBody('sample', '6'));
    Limits::configure($resourceLimits);
    foreach ([['2027-01-01T09:00:00Z', '2027-01-02T09:00:00Z'], ['2027-01-01T09:00:00Z,2027-01-02T09:00:00Z']] as $dates) {
        $reject('jCal singleton list rejected before VObject', static function () use ($dates): void {
            $events = $properties = $exdates = 0;
            SizedCalDavPlugin::checkJcal(['vcalendar', [], [['vevent', [array_merge(['dtstart', new stdClass(), 'date-time'], $dates)], []]]], $events, $properties, $exdates);
        });
    }
} else check('CalDAV has pre-materialization read boundary', false);

if (class_exists(ZipArchive::class)) {
    $path = tempnam(sys_get_temp_dir(), 'bc-resource-zip-');
    try {
        $zip = new ZipArchive(); $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('ignored.txt', str_repeat('x', 2000));
        $zip->addFromString('one.ics', $calendar($event('one@example.test')));
        $zip->addFromString('two.ics', $calendar($event('two@example.test'))); $zip->close(); $zip->open($path);
        Limits::configure(['IMPORT_ZIP_ENTRIES' => 2]);
        $reject('ZIP ignored members count toward entry budget', fn() => CalendarImport::icsEntries($zip));
        Limits::configure($resourceLimits); Limits::configure(['IMPORT_ZIP_TOTAL_BYTES' => 1000]);
        $reject('ZIP ignored bytes count toward expanded budget', fn() => CalendarImport::icsEntries($zip));
        Limits::configure($resourceLimits); Limits::configure(['IMPORT_ZIP_EVENTS' => 1]);
        $entries = CalendarImport::icsEntries($zip);
        if (method_exists(CalendarImport::class, 'preflightEvents')) $reject('ZIP event budget spans all calendars before writes', fn() => CalendarImport::preflightEvents($zip, $entries));
        else check('ZIP event preflight exists', false);
        Limits::configure($resourceLimits); checkEq('ordinary multi-calendar zip admitted', 2, count(CalendarImport::icsEntries($zip))); $zip->close();
    } finally { Limits::configure($resourceLimits); unlink($path); }
}
checkEq('push fragments share one identity', PushSubscriptions::endpointHash('https://fcm.googleapis.com/sample'), PushSubscriptions::endpointHash('https://fcm.googleapis.com/sample#alias'));

$planner = require dirname(__DIR__) . '/plugins/trip-planner/Plugin.php';
$invoke = static fn(string $method, array $args): mixed => (new ReflectionMethod($planner, $method))->invokeArgs($planner, $args);
$cfg = ['recurringBlocks' => false, 'shortEventMinutes' => 240];
$occ = [];
for ($i = 0; $i < 5000; $i++) $occ[] = ['calendarId' => 1, 'title' => 'Sample ' . $i . str_repeat('x', 100), 'start' => '2027-01-02T09:00:00Z', 'end' => '2027-01-05T10:00:00Z'];
$load = $invoke('classifyDays', [$occ, [1 => ['policy' => 'soft', 'name' => 'Sample']], new DateTimeZone('UTC'), $cfg, '2027-01-01', '2027-02-01']);
$dates = []; for ($i = 1; $i <= 31; $i++) $dates[] = sprintf('2027-01-%02d', $i);
$runs = [['i' => 0, 'j' => 30, 'len' => 31, 'start' => $dates[0], 'end' => $dates[30]]];
$cands = $invoke('candidates', [$dates, $runs, $load, null, 2, 7, 0, $cfg]);
check('planner candidates retain no multiplied text maps', !isset($cands[0]['softList']) && strlen(serialize($cands)) < 200000);
check('planner daily occupancy contains no event text', strlen(serialize($load['soft'])) < 1000);
if (method_exists($planner, 'describeCandidate')) {
    $picked = $cands[0]; $invoke('describeCandidate', [&$picked, $load]);
    checkEq('planner selected explanations keep exact overlapping-day counts', [5000, 4], [count($picked['softList']), array_values($picked['softList'])[0]]);
}
$wideDates = []; for ($i = 0; $i < 366; $i++) $wideDates[] = (new DateTimeImmutable('2027-01-01'))->modify("+$i days")->format('Y-m-d');
$wideRuns = [['i' => 0, 'j' => 365, 'len' => 366, 'start' => $wideDates[0], 'end' => $wideDates[365]]];
$wideCandidates = $invoke('candidates', [$wideDates, $wideRuns, $load, null, 2, 120, 0, $cfg]);
checkEq('planner preserves its full candidate allowance', 6000, count($wideCandidates));
check('planner full candidate set retains only compact scores', strlen(serialize($wideCandidates)) < 5000000);
$response = BetterCal\Http\Controllers\AuthController::tooMany(60, 'brake');
check('global login brake offers remembered browser, not a false countdown', str_contains($response->body, 'browser or network') && !str_contains($response->body, 'Try again in'));
if ($resourceStandalone) { echo $GLOBALS['__pass'] . ' resource checks passed, ' . $GLOBALS['__fail'] . " failed\n"; exit($GLOBALS['__fail'] ? 1 : 0); }
