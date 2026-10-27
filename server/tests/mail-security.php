<?php

declare(strict_types=1);

// Invented fixtures only. Also runnable directly for focused regression checks.
require_once dirname(__DIR__) . '/src/bootstrap.php';
if (!function_exists('check')) {
    $GLOBALS['__pass'] = $GLOBALS['__fail'] = 0;
    function check(string $name, bool $ok, string $detail = ''): void {
        $GLOBALS[$ok ? '__pass' : '__fail']++;
        if (!$ok) echo "FAIL: $name $detail\n";
    }
    function checkEq(string $name, mixed $expected, mixed $actual): void {
        check($name, $expected === $actual, json_encode([$expected, $actual]));
    }
}

use BetterCal\Domain\PromptEval;
use BetterCal\Domain\Ranking;
use BetterCal\Domain\{MailPolicy, MailAdmission, MailIngest, ReviewQueue, Events, ModelAdmission};
use BetterCal\Infra\{Db, MailFetcher, LlmGateway, LlmTransport};
use BetterCal\Support\{Limits, Time};

$mailDb = static function (): Db {
    $db = new Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    foreach ([
        'users' => 'id INTEGER PRIMARY KEY, settings_json TEXT',
        'events' => 'id INTEGER PRIMARY KEY, user_id INTEGER, calendar_id INTEGER, uid TEXT, title TEXT, description TEXT, location TEXT, start_utc TEXT, end_utc TEXT, tzid TEXT, all_day INTEGER, rrule TEXT, exdates_json TEXT, recurrence_parent_id INTEGER, recurrence_instance_utc TEXT, deleted_at TEXT, created_via TEXT, source TEXT, attendance TEXT, score REAL, scored_at TEXT, updated_at TEXT',
        'mail_ingest' => 'id INTEGER PRIMARY KEY, transport_key TEXT UNIQUE, message_id TEXT UNIQUE, subject TEXT, from_addr TEXT, tier TEXT, outcome TEXT, event_id INTEGER, error TEXT, processed_at TEXT DEFAULT CURRENT_TIMESTAMP',
        'mail_admissions' => 'id INTEGER PRIMARY KEY, user_id INTEGER, kind TEXT, message_key TEXT, sender_key TEXT, admitted_at TEXT, UNIQUE(user_id,kind,message_key)',
        'review_items' => "id INTEGER PRIMARY KEY, user_id INTEGER, kind TEXT, event_id INTEGER, source_key TEXT, title TEXT, summary TEXT, payload_json TEXT, status TEXT DEFAULT 'open', created_at TEXT DEFAULT CURRENT_TIMESTAMP, decided_at TEXT",
        'model_admissions' => 'id INTEGER PRIMARY KEY, user_id INTEGER, operation TEXT, principal_kind TEXT, principal_key TEXT, admitted_at TEXT, lease_until TEXT, finished_at TEXT',
        'calendars' => 'id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT',
        'feedback_signals' => 'id INTEGER PRIMARY KEY, user_id INTEGER, event_id INTEGER, kind TEXT, created_at TEXT',
    ] as $name => $columns) $db->run("CREATE TABLE $name ($columns)");
    $db->run("INSERT INTO users VALUES (1, '{}')");
    return $db;
};

checkEq('mail policy: canonical reusable list', ['sam@example.test', 'example.test', '*.shop.example.test'],
    MailPolicy::rules([' Sam@Example.test ', 'example.test', '*.shop.example.test', 'sam@example.test']));
foreach (['gmail.com', '*.gmail.com', 'outlook.com', 'icloud.com', 'yahoo.co.uk'] as $shared) {
    try { MailPolicy::rules([$shared]); check('mail policy: shared domain refused ' . $shared, false); }
    catch (BetterCal\Http\HttpError) { check('mail policy: shared domain refused ' . $shared, true); }
}
foreach ([
    [[], 'sam@example.test', false],
    [['mailEventMode' => 'any'], '', true],
    [['mailEventMode' => 'review', 'mailApprovedSenders' => ['example.test']], 'sam@example.test', false],
    [['mailApprovedSenders' => ['example.test']], 'orders@example.test', true],
    [['mailApprovedSenders' => ['example.test']], 'orders@otherexample.test', false],
    [['mailApprovedSenders' => ['example.test']], 'orders@sub.example.test', false],
    [['mailApprovedSenders' => ['*.example.test']], 'orders@sub.example.test', true],
    [['mailApprovedSenders' => ['*.example.test']], 'orders@example.test', false],
    [['mailApprovedSenders' => ['gmail.com']], 'sam@gmail.com', false],
    [['mailApprovedSenders' => ['sam@gmail.com']], 'sam@gmail.com', true],
] as [$settings, $from, $expected]) checkEq('mail policy: mode and domain boundaries', $expected, MailPolicy::addsAutomatically($settings, $from));

// The bulk editor's text (#151): one bad line never sinks the rest.
$parsed = MailPolicy::parseList(implode("\n", [
    'email,name',
    '"bookings@example.test","Example Bookings"',
    'Ticket Desk <Tickets@Shows.example.test>',
    '',
    '# venues',
    '@venue.example.test',
    '*.events.example.test',
    'mailto:host@example.test',
    'example.test; the whole domain',
    'BOOKINGS@example.test',
    'gmail.com',
    'alex.example@gmail.com',
    'not a sender',
]));
checkEq('mail list: CSV, angle brackets, @domain, wildcard and mailto read; duplicates merged', [
    'bookings@example.test', 'tickets@shows.example.test', 'venue.example.test',
    '*.events.example.test', 'host@example.test', 'example.test', 'alex.example@gmail.com',
], $parsed['rules']);
checkEq('mail list: bad lines are kept back with a reason, blanks and comments skipped', [
    ['line' => 'email,name', 'reason' => 'not an address or a domain'],
    ['line' => 'gmail.com', 'reason' => 'a shared mail provider, which can only be approved as an exact address'],
    ['line' => 'not a sender', 'reason' => 'not an address or a domain'],
], $parsed['invalid']);
checkEq('mail list: what it reads saves as is', $parsed['rules'], MailPolicy::rules($parsed['rules']));
checkEq('mail list: empty text is an empty list', ['rules' => [], 'invalid' => []], MailPolicy::parseList("\n  \n"));
try { MailPolicy::parseList(str_repeat("a@example.test\n", MailPolicy::LIST_LINES_MAX + 1)); check('mail list: too many lines refused', false); }
catch (BetterCal\Http\HttpError) { check('mail list: too many lines refused', true); }

$db = $mailDb();
foreach ([['created', 'orders@example.test'], ['updated', 'sam@gmail.com'], ['held', 'unknown@example.test'], ['error', 'broken@example.test']] as [$outcome, $sender]) {
    $db->insert('mail_ingest', ['outcome' => $outcome, 'from_addr' => $sender]);
}
$migration = require dirname(__DIR__) . '/migrations/050_mail_sender_policy.php';
$migration($db);
$settings = json_decode($db->scalar('SELECT settings_json FROM users'), true);
checkEq('mail upgrade: only successful retained senders approved, as exact addresses', ['orders@example.test', 'sam@gmail.com'], $settings['mailApprovedSenders']);
$settings['mailApprovedSenders'] = [];
$db->run('UPDATE users SET settings_json = ?', [json_encode($settings)]);
$migration($db);
checkEq('mail upgrade: rerun does not revive an approval the owner removed', [], json_decode($db->scalar('SELECT settings_json FROM users'), true)['mailApprovedSenders']);

$queue = new ReviewQueue($db, (new ReflectionClass(Events::class))->newInstanceWithoutConstructor());
$mail = new MailIngest($db, (new ReflectionClass(Events::class))->newInstanceWithoutConstructor());
$mail->beginTransport('broken-sample');
$mail->failTransport(1, 'broken-sample', ['from' => 'bookings@example.test', 'subject' => 'Sample booking'], 'Cannot decode this message.');
$mail->failTransport(1, 'broken-sample', [], 'Repeated failure');
checkEq('unreadable mail: repeated failure creates one durable notice', 1, $queue->openCount(1, ReviewQueue::KIND_MAIL_ERROR));
$db->run("UPDATE mail_ingest SET processed_at = '2000-01-01 00:00:00'");
$mail->pruneReceipts();
$notice = $queue->listFor(1)[0];
checkEq('unreadable mail: diagnosis survives receipt pruning', ['bookings@example.test', 'Sample booking'], [$notice['detail']['from'], $notice['detail']['subject']]);
checkEq('unreadable mail: receipts really pruned', 0, (int) $db->scalar('SELECT COUNT(*) FROM mail_ingest'));
$mail->beginTransport('interrupted-sample');
$mail->recoverInterrupted(1);
checkEq('unreadable mail: interrupted started record becomes visible error', ['error', 2], [$db->scalar("SELECT outcome FROM mail_ingest WHERE transport_key='interrupted-sample'"), $queue->openCount(1, ReviewQueue::KIND_MAIL_ERROR)]);
$queue->dismissMailError(1, $notice['id']);
checkEq('unreadable mail: explicit dismissal closes only that notice', 1, $queue->openCount(1, ReviewQueue::KIND_MAIL_ERROR));
for ($i = 0; $i < 205; $i++) $queue->holdMailError(1, 'sample-' . $i, 'sam@example.test', 'Sample message ' . $i, 'Unreadable');
$flood = $queue->listFor(1, true, null);
checkEq('unreadable mail: a flood shows a screenful of notices, then one counted notice', [ReviewQueue::MAIL_ERROR_NOTICES, 1], [$queue->openCount(1, ReviewQueue::KIND_MAIL_ERROR), $queue->openCount(1, ReviewQueue::KIND_MAIL_LIMIT)]);
check('unreadable mail: the counted notice says how many and names the latest', str_contains((string) $db->scalar("SELECT summary FROM review_items WHERE kind = 'mail_limit'"), '186 emails were affected') && str_contains((string) $db->scalar("SELECT summary FROM review_items WHERE kind = 'mail_limit'"), 'Sample message 204'));
checkEq('unreadable mail: a flood cannot push other Review items off the first page', 21, count($flood));

$fetcher = new MailFetcher([]);
$seen = $failures = $loads = [];
$candidates = [];
for ($i = 0; $i < 13; $i++) $candidates[] = [
    'transportKey' => 'sample-' . $i, 'size' => 100,
    'load' => static function () use ($i, &$loads): object { $loads[] = $i; throw new UnexpectedValueException('bad header'); },
    'markSeen' => static function () { throw new RuntimeException('Seen flag unavailable'); },
    'markUnseen' => static function () {},
];
$begin = static function ($key) use (&$seen) { if (isset($seen[$key])) return false; return $seen[$key] = true; };
$failed = static function ($key) use (&$failures) { $failures[] = $key; };
iterator_to_array($fetcher->processCandidates($candidates, $begin, null, null, $failed, 10));
iterator_to_array($fetcher->processCandidates($candidates, $begin, null, null, $failed, 10));
checkEq('malformed mail: a failed Seen flag cannot starve messages beyond first ten', range(0, 12), $loads);
checkEq('malformed mail: every terminal failure reaches durable notice callback', 13, count($failures));

// Recurrence semantics need sabre/vobject (the install job runs it).
if (class_exists(\Sabre\VObject\Recur\RRuleIterator::class)) {
$now = new DateTimeImmutable('2026-02-01T00:00:00Z');
$series = ['id' => 1, 'start_utc' => '2026-01-01 10:00:00', 'end_utc' => '2026-01-01 11:00:00', 'tzid' => 'UTC', 'all_day' => 0];
foreach ([['FREQ=DAILY;COUNT=3', false], ['FREQ=DAILY;COUNT=100', true], ['FREQ=DAILY;UNTIL=20260115T100000Z', false], ['FREQ=DAILY;UNTIL=20260301T100000Z', true], ['FREQ=DAILY', true]] as [$rrule, $active]) {
    checkEq('mail capacity: finite recurrence ends and open recurrence counts', $active, MailAdmission::seriesStillActive($series + ['rrule' => $rrule], [], $now));
}
check('mail capacity: moved future override keeps expired series active', MailAdmission::seriesStillActive($series + ['rrule' => 'FREQ=DAILY;COUNT=3'], [['end_utc' => '2026-03-01 11:00:00']], $now));
check('mail capacity: COUNT beyond display cap is not mistaken for exhaustion', MailAdmission::seriesStillActive(array_replace($series, ['start_utc' => '2000-01-01 10:00:00', 'end_utc' => '2000-01-01 11:00:00', 'rrule' => 'FREQ=DAILY;COUNT=10000']), [], $now));
check('mail capacity: final skipped occurrence no longer counts', !MailAdmission::seriesStillActive($series + ['rrule' => 'FREQ=DAILY;COUNT=32', 'exdates_json' => '["2026-02-01 10:00:00"]'], [], $now));
foreach (['SECONDLY', 'MINUTELY'] as $frequency) {
    check('mail capacity: finite sub-hourly series ends without an unsupported iterator', !MailAdmission::seriesStillActive($series + ['rrule' => "FREQ=$frequency;COUNT=3"], [], $now));
}
try {
    MailAdmission::seriesStillActive($series + ['rrule' => 'FREQ=HOURLY;UNTIL=20900101T000000Z'], [], $now, new BetterCal\Support\ExpansionBudget(1, 2, 10));
    check('mail capacity: historical iteration respects the shared work budget', false);
} catch (BetterCal\Support\WorkBudgetExceeded) { check('mail capacity: historical iteration respects the shared work budget', true); }
$dstSeries = ['start_utc' => '2026-03-28 23:00:00', 'end_utc' => '2026-03-29 22:00:00', 'tzid' => 'Europe/Paris', 'all_day' => 1, 'rrule' => 'FREQ=DAILY;UNTIL=20260329'];
check('mail capacity: last all-day occurrence lasts through local date across DST', MailAdmission::seriesStillActive($dstSeries, [], new DateTimeImmutable('2026-03-29T21:00:00Z')));
check('mail capacity: all-day UNTIL ends at local midnight', !MailAdmission::seriesStillActive($dstSeries, [], new DateTimeImmutable('2026-03-29T22:00:00Z')));
$db = $mailDb();
$db->insert('events', $series + ['user_id' => 1, 'created_via' => 'mail:imip', 'rrule' => 'FREQ=DAILY;COUNT=3']);
Limits::configure(['MAIL_ACTIVE_EVENTS' => 1]);
check('mail capacity: ended series releases actual account cap', (new MailAdmission($db))->eventCapacity(1, 'sam@example.test', $now)['allowed']);
Limits::reset();
}

$db = $mailDb();
$db->insert('calendars', ['id' => 1, 'user_id' => 1, 'name' => 'Sample feed']);
$db->insert('calendars', ['id' => 2, 'user_id' => 1, 'name' => 'Other sample feed']);
for ($i = 1; $i <= 102; $i++) $db->insert('events', ['id' => $i, 'user_id' => 1, 'calendar_id' => $i <= 101 ? 1 : 2, 'title' => 'Sample event', 'source' => 'feed', 'attendance' => 'none', 'start_utc' => '2090-01-01 10:00:00', 'end_utc' => '2090-01-01 11:00:00', 'tzid' => 'UTC']);
for ($i = 1; $i <= 5; $i++) $db->insert('feedback_signals', ['user_id' => 1, 'event_id' => $i, 'kind' => 'up', 'created_at' => Time::nowDb()]);
$transport = new class implements LlmTransport {
    public int $calls = 0;
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): ?string {
        $this->calls++;
        return json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode(['results' => [['eventId' => 102, 'score' => 0.75]]])]]]]]]);
    }
};
Limits::configure(['MODEL_RANKING_PER_CALENDAR_HOUR' => 1, 'MODEL_RANKING_PER_HOUR' => 2]);
$admission = new ModelAdmission($db);
$admission->reserveRanking(1, 1); // Saturate the calendar with the first hundred candidates.
$rank = new Ranking($db, new LlmGateway(['gemini' => ['key' => 'synthetic', 'model' => 'synthetic']], $transport), null, $admission);
checkEq('ranking: saturated calendar cannot hide a later calendar', [1, 1], [$rank->run(), $transport->calls]);
$rank->run();
checkEq('ranking: repeated worker runs cannot reset persistent admission', 1, $transport->calls);
$db->run("INSERT INTO users VALUES (2, '{}')");
$db->insert('calendars', ['id' => 3, 'user_id' => 2, 'name' => 'Another sample account']);
$db->insert('events', ['id' => 103, 'user_id' => 2, 'calendar_id' => 3, 'title' => 'Sample event', 'source' => 'feed', 'attendance' => 'none', 'start_utc' => '2090-01-01 10:00:00', 'end_utc' => '2090-01-01 11:00:00', 'tzid' => 'UTC']);
for ($i = 0; $i < 5; $i++) $db->insert('feedback_signals', ['user_id' => 2, 'event_id' => 103, 'kind' => 'up', 'created_at' => Time::nowDb()]);
$rank->run();
checkEq('ranking: account denial does not stop a later account', 2, $transport->calls);
Limits::reset();

checkEq('mail security: duplicate model IDs cannot choose a verdict', [], PromptEval::validateEvalResponse(['results' => [
    ['eventId' => 1, 'pass' => true, 'score' => 0.9], ['eventId' => 1, 'pass' => false, 'score' => 0.1],
]], [1]));
checkEq('mail security: fractional IDs cannot select a real event', [], Ranking::validateRankResponse(['results' => [
    ['eventId' => 1.5, 'score' => 0.9],
]], [1]));

$host = new BetterCal\Plugin\PluginHost($mailDb(), 'sample', 1, new BetterCal\Infra\HttpClient(), [], ['places' => ['lookup' => 'off']]);
checkEq('plugin lookup off: no provider or cache access is needed', [null, ''], [$host->geocode('Sample venue'), $host->logTail()]);
checkEq('plugin lookup off: plugins are told outside place lookups are off', false, $host->placeLookupAllowed());
$host = new BetterCal\Plugin\PluginHost($mailDb(), 'sample', 1, new BetterCal\Infra\HttpClient(), [], []);
checkEq('plugin lookup: a host built without the server settings fails closed', [false, null, ''], [$host->placeLookupAllowed(), $host->geocode('Sample venue'), $host->logTail()]);
$host = new BetterCal\Plugin\PluginHost($mailDb(), 'sample', 1, new BetterCal\Infra\HttpClient(), [], ['places' => ['provider' => 'photon', 'lookup' => '']]);
checkEq('plugin lookup on: the default configuration allows it', true, $host->placeLookupAllowed());
check('visit-intents asks the host before OpenStreetMap hours lookups', str_contains((string) file_get_contents(dirname(__DIR__) . '/plugins/visit-intents/Plugin.php'), '$host->placeLookupAllowed() && '));
$workerSource = file_get_contents(dirname(__DIR__) . '/bin/worker.php');
check('worker passes the real application configuration into plugin jobs', str_contains($workerSource, 'new BetterCal\Domain\Plugins($db, null, $cfg)'));

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo $GLOBALS['__pass'] . ' passed, ' . $GLOBALS['__fail'] . " failed\n";
    exit($GLOBALS['__fail'] ? 1 : 0);
}
