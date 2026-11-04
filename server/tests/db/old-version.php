<?php

declare(strict_types=1);

// Rows and journal entries made by an older version's own code, for a
// migration's test (oldVersion() in lib.php runs this in its own process).
// The app's classes load from that version's server/src (the first
// argument), its libraries from this tree's; the steps (a JSON file, the
// second argument) go through that version's domain methods, never SQL. What
// they made, by name, is written as JSON to the third argument.
//
// Steps: {"series": name, "title", "start", "end", "tzid", "rrule"} makes a
// series; {"edit": name, "at": occurrence (UTC), "fields": {...}} edits one
// day; {"skip": name, "at": occurrence} deletes one day; {"entry": name}
// names the account's newest journal entry; {"window": name, "from", "to"}
// keeps what the calendar shows then, each occurrence's UTC start and title.

[, $oldSrc, $stepsFile, $outFile] = $argv;
require dirname(__DIR__, 2) . '/src/bootstrap.php';
// Ahead of this tree's (Composer's own goes first of all, so after it).
spl_autoload_register(static function (string $class) use ($oldSrc): void {
    if (str_starts_with($class, 'BetterCal\\')) {
        $file = rtrim($oldSrc, '/') . '/' . str_replace('\\', '/', substr($class, 10)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);
require __DIR__ . '/lib.php';

use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;

$db = new Db(['dsn' => (string) getenv('BC_TEST_DSN'), 'user' => (string) getenv('BC_TEST_DB_USER'), 'pass' => (string) getenv('BC_TEST_DB_PASS')]);
$u = freshUser($db);
$d = domains($db);
$calendar = Settings::defaultCalendarId($db, $u);
$out = ['user' => $u, 'calendar' => $calendar, 'series' => [], 'entries' => [],
    // Which code made them: the older version's, for the test to check.
    'from' => (new ReflectionClass(BetterCal\Domain\Events::class))->getFileName()];
foreach (json_decode((string) file_get_contents($stepsFile), true, 512, JSON_THROW_ON_ERROR) as $i => $step) {
    try {
        step($d, $u, $calendar, $db, $step, $out);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'step ' . $i . ' (' . json_encode($step) . '): ' . $e->getMessage() . "\n");
        exit(1);
    }
}
file_put_contents($outFile, json_encode($out));

function step(Domains $d, int $u, int $calendar, Db $db, array $step, array &$out): void
{
    if (isset($step['series'])) {
        $out['series'][$step['series']] = (int) $d->events->create($u, ['calendarId' => $calendar, 'title' => $step['title'], 'start' => $step['start'], 'end' => $step['end'],
            'allDay' => false, 'tzid' => $step['tzid'], 'rrule' => $step['rrule']])['eventId'];
    } elseif (isset($step['edit'])) {
        $d->events->patch($u, $out['series'][$step['edit']], ['scope' => 'this', 'instanceStart' => $step['at']] + $step['fields']);
    } elseif (isset($step['skip'])) {
        $d->events->deleteEvent($u, $out['series'][$step['skip']], 'this', $step['at']);
    } elseif (isset($step['entry'])) {
        $out['entries'][$step['entry']] = lastEntry($db, $u);
    } elseif (isset($step['window'])) {
        $out['windows'][$step['window']] = array_map(static fn(array $o): array => [(new \DateTimeImmutable($o['start']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $o['title']],
            $d->events->window($u, new \DateTimeImmutable($step['from']), new \DateTimeImmutable($step['to']), null, null, false));
    }
}
