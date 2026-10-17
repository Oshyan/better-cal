<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require (getenv('BC_FILES_SOURCE') ?: dirname(__DIR__, 2)) . '/server/src/bootstrap.php';
$db = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
$db->run('CREATE TABLE calendars (id INT, user_id INT, name TEXT, position INT)');
$db->run('CREATE TABLE events (id INT PRIMARY KEY, user_id INT, calendar_id INT, uid TEXT, title TEXT, description TEXT, location TEXT, url TEXT,
    start_utc TEXT, end_utc TEXT, all_day INT DEFAULT 0, tzid TEXT, rrule TEXT, exdates_json TEXT, reminders_json TEXT, status TEXT,
    recurrence_instance_utc TEXT, deleted_at TEXT, is_container INT DEFAULT 0)');
$db->run('CREATE TABLE event_links (id INT, container_id INT, event_id INT)');
$db->run("INSERT INTO calendars VALUES (1,1,'Sample archive',0)");
$db->tx(static function () use ($db): void {
    for ($i = 1; $i <= 3000; $i++) {
        $db->run('INSERT INTO events (id,user_id,calendar_id,uid,title,description,start_utc,end_utc,tzid) VALUES (?,1,1,?,?,?,?,?,?)',
            [$i, "sample-$i@example.test", 'Sample event', str_repeat('Invented text. ', 700), '2026-01-01 09:00:00', '2026-01-01 10:00:00', 'UTC']);
    }
});
$export = new BetterCal\Domain\Export($db);
$count = 0;
if (method_exists($export, 'writeCalendar')) {
    $sink = static function (string $chunk) use (&$count): void { $count += substr_count($chunk, 'END:VEVENT'); };
    $export->snapshot(fn() => $export->writeCalendar(1, $export->calendar(1, 1), $sink));
} else {
    // Same workload through the old public boundary, for a red/green check.
    $count = substr_count($export->calendar(1, 1)['body'], 'END:VEVENT');
}
if ($count !== 3000) { throw new RuntimeException('The synthetic export was incomplete.'); }
echo "Complete synthetic export within the memory budget\n";
