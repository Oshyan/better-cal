<?php

declare(strict_types=1);

// For google-upgrade-from-155.test.php: a half-done change to all events left by an OLDER version's own code (its server/src,
// the first argument), on this suite's database, against this tree's stand-in for Google (which answers as the probe
// of 2026-11-13 found Google, so the older code stops where it stopped on real Google: issue 256). Runs in a process
// of its own; writes what it made and the stand-in's whole state to the third argument. Invented data.

[, $oldSrc, $scenario, $outFile] = $argv;
$alsoFinish = ($argv[4] ?? '') === 'finish'; // the older version presses its own Finish too, for the comparison
require dirname(__DIR__, 2) . '/src/bootstrap.php';
spl_autoload_register(static function (string $class) use ($oldSrc): void {
    if (str_starts_with($class, 'BetterCal\\')) {
        $file = rtrim($oldSrc, '/') . '/' . str_replace('\\', '/', substr($class, 10)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);
require __DIR__ . '/lib.php';
require __DIR__ . '/google-hunt-lib.php';

use BetterCal\Infra\Db;

$db = new Db(['dsn' => (string) getenv('BC_TEST_DSN'), 'user' => (string) getenv('BC_TEST_DB_USER'), 'pass' => (string) getenv('BC_TEST_DB_PASS')]);
$la = 'America/Los_Angeles';
// Weekly Mondays 10:00 Los Angeles, 4 Jan to 8 Feb 2027; 11 Jan renamed, 18 Jan deleted (a cancelled record).
$create = ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
$at = '2027-01-04T10:00:00-08:00';
$changes = [
    'clock' => ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-04T11:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00'],
    'allday' => ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true],
    'rename' => ['scope' => 'all', 'title' => 'Sample series, renamed'],
    'rename-exdate' => ['scope' => 'all', 'title' => 'Sample series, renamed'],
];
[$what, $stop] = explode(':', $scenario) + [1 => ''];
$t = H::make($db, $what === 'rename-exdate' ? $create + ['exdates' => ['2027-02-01 18:00:00']] : $create);
H::this($t, '2027-01-11T10:00:00-08:00', ['title' => 'Sample series (own)']);
H::skip($t, '2027-01-18T10:00:00-08:00');
$before = H::hereView($t, $la, '2027-01-01', '2027-03-01', true);
$beforeGoogle = H::googleView($t, $la);
// $stop: '' lets the stand-in stop it (the 412 of issue 256), 'netN' has the network down from the change's Nth write on.
$t->s->knobs->writes = 0;
if (str_starts_with($stop, 'net')) {
    $t->s->knobs->netAt = (int) substr($stop, 3);
}
$base = count($t->s->fake->requests);
[, $stopped] = H::tryChange($t, $changes[$what]);
$t->s->knobs->netAt = null;
$t->s->knobs->down = false;
$state = [];
foreach ((new ReflectionObject($t->s->fake))->getProperties() as $p) {
    $v = $p->getValue($t->s->fake);
    if (!$v instanceof Closure && $p->getName() !== 'requests') {
        $state[$p->getName()] = $v;
    }
}
$half = H::halfDone($t);
$oldFinish = null;
if ($alsoFinish && $half !== []) {
    [, $refused] = H::recover($t, 'finish');
    $oldFinish = ['refused' => $refused, 'here' => H::hereView($t, $la, '2027-01-01', '2027-03-01', true), 'google' => H::googleView($t, $la)];
}
file_put_contents($outFile, serialize([
    'from' => (new ReflectionClass(BetterCal\Domain\GoogleSeries::class))->getFileName(),
    'user' => $t->u, 'calendar' => $t->s->calendarId, 'series' => $t->id, 'gid' => $t->gid,
    'stopped' => $stopped, 'half' => $half, 'writes' => H::writes($t, $base),
    'steps' => $half === [] ? null : json_decode((string) $db->scalar('SELECT steps_json FROM google_pending WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$t->u]), true),
    'oldFinish' => $oldFinish, 'before' => $before, 'beforeGoogle' => $beforeGoogle, 'fake' => $state,
]));
