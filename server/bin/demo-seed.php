<?php

declare(strict_types=1);

// Fills an empty account with invented sample data, for trying Better-Cal out
// and for the screenshots in the README:
//   php server/bin/demo-seed.php
// A few calendars (work, family, community, a trip), repeating and one-off
// events around today, two sample people with away and busy times. Everything
// is made up and dated relative to the day it runs, so the current month
// always looks lived in.
//
// It refuses to touch an account that already has events: it is for a fresh
// install, never for a calendar someone uses.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Domain\ActivityContext;
use BetterCal\Domain\Calendars;
use BetterCal\Domain\Events;
use BetterCal\Domain\Filters;
use BetterCal\Domain\Labels;
use BetterCal\Domain\People;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Trips;
use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;

$cfg = config();
$db = new Db($cfg['db']);
$user = $db->one('SELECT id FROM users ORDER BY id LIMIT 1');
if ($user === null) {
    fwrite(STDERR, "No account yet. Create one first: php server/bin/seed.php --email=you@example.com\n");
    exit(1);
}
$userId = (int) $user['id'];
if ((int) $db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ?', [$userId]) > 0) {
    fwrite(STDERR, "This account already has events; the demo data is only for an empty one.\n");
    exit(1);
}

ActivityContext::set('demo-seed');
$undo = new Undo($db);
$labels = new Labels($db);
$calendars = new Calendars($db, $undo, $labels);
$events = new Events($db, new Recurrence(), $undo, $labels, new Filters($db, $undo, new JobQueue($db)), new Trips($db, $undo));
$people = new People($db);

$settings = json_decode((string) ($db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]) ?? '{}'), true) ?: [];
$tz = new DateTimeZone(is_string($settings['tz'] ?? null) && $settings['tz'] !== '' ? $settings['tz'] : date_default_timezone_get());
$today = new DateTimeImmutable('today', $tz);
$monday = $today->modify('monday this week');

// Day helpers: d(+3) is three days from today; wd(1, 'tue') is Tuesday of next week.
$d = static fn(int $offset): DateTimeImmutable => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
$wd = static fn(int $week, string $day): DateTimeImmutable => $monday->modify(($week >= 0 ? '+' : '') . $week . ' weeks')->modify($day . ' this week');
$at = static fn(DateTimeImmutable $day, string $hm): string => $day->format('Y-m-d') . 'T' . $hm . ':00';
$date = static fn(DateTimeImmutable $day): string => $day->format('Y-m-d');

$cal = [];
$personal = $db->one("SELECT id FROM calendars WHERE user_id = ? AND kind = 'local' ORDER BY id LIMIT 1", [$userId]);
$cal['personal'] = $personal !== null
    ? (int) $personal['id']
    : (int) $calendars->create($userId, ['name' => 'Personal', 'color' => '#4a7dff'])['id'];
foreach ([
    'work' => ['Work', '#2f9e74'],
    'family' => ['Family', '#d9822b'],
    'community' => ['Community', '#9c5bd1'],
    'travel' => ['Travel', '#d6456b'],
] as $key => [$name, $color]) {
    $cal[$key] = (int) $calendars->create($userId, ['name' => $name, 'color' => $color])['id'];
}

$n = 0;
$add = static function (string $calKey, array $in) use (&$n, $events, $userId, $cal, $tz): void {
    $events->create($userId, ['calendarId' => $cal[$calKey], 'tzid' => $tz->getName()] + $in);
    $n++;
};
$start = $wd(-5, 'monday');

// Repeating events, started a few weeks back so the past looks the same.
$add('work', ['title' => 'Team standup', 'start' => $at($start, '09:30'), 'end' => $at($start, '09:45'), 'rrule' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', 'location' => 'Video call']);
$add('work', ['title' => 'Planning', 'start' => $at($wd(-5, 'tuesday'), '14:00'), 'end' => $at($wd(-5, 'tuesday'), '15:00'), 'rrule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU']);
$add('work', ['title' => 'Focus time', 'start' => $at($wd(-5, 'wednesday'), '13:00'), 'end' => $at($wd(-5, 'wednesday'), '16:00'), 'rrule' => 'FREQ=WEEKLY;BYDAY=WE']);
$add('work', ['title' => 'Week review', 'start' => $at($wd(-5, 'friday'), '16:00'), 'end' => $at($wd(-5, 'friday'), '16:30'), 'rrule' => 'FREQ=WEEKLY;BYDAY=FR']);
$add('family', ['title' => 'Soccer practice', 'start' => $at($wd(-5, 'thursday'), '17:00'), 'end' => $at($wd(-5, 'thursday'), '18:15'), 'rrule' => 'FREQ=WEEKLY;BYDAY=TH', 'location' => 'Riverside Park field 2']);
$add('family', ['title' => 'Piano lesson', 'start' => $at($wd(-5, 'monday'), '16:30'), 'end' => $at($wd(-5, 'monday'), '17:15'), 'rrule' => 'FREQ=WEEKLY;BYDAY=MO']);
$add('personal', ['title' => 'Run club', 'start' => $at($wd(-5, 'saturday'), '08:00'), 'end' => $at($wd(-5, 'saturday'), '09:00'), 'rrule' => 'FREQ=WEEKLY;BYDAY=SA', 'location' => 'Harbor trailhead']);
$add('community', ['title' => 'Farmers market', 'start' => $at($wd(-5, 'sunday'), '10:00'), 'end' => $at($wd(-5, 'sunday'), '12:00'), 'rrule' => 'FREQ=WEEKLY;BYDAY=SU', 'location' => 'Market Square']);
$add('community', ['title' => 'Book club', 'start' => $at($wd(-5, 'thursday'), '19:00'), 'end' => $at($wd(-5, 'thursday'), '20:30'), 'rrule' => 'FREQ=MONTHLY;BYDAY=3TH', 'location' => 'Main Street Library']);
$add('personal', ['title' => 'Water the garden', 'start' => $date($wd(-5, 'wednesday')), 'allDay' => true, 'rrule' => 'FREQ=WEEKLY;BYDAY=WE']);

// One-offs around today.
$oneOffs = [
    ['personal', 'Dentist', -6, '10:00', '11:00', 'Elm Street Dental'],
    ['work', 'Quarterly kickoff', -4, '10:00', '12:00', 'Conference room B'],
    ['family', 'Parent-teacher meeting', -2, '15:30', '16:00', 'Lincoln Elementary'],
    ['personal', 'Haircut', 1, '12:30', '13:15', null],
    ['work', 'Design review', 2, '11:00', '12:00', 'Video call'],
    ['work', '1:1 with manager', 3, '15:00', '15:30', null],
    ['community', 'Volunteer shift', 5, '09:00', '12:00', 'Food bank'],
    ['family', 'Birthday dinner', 6, '18:30', '21:00', 'Luna Trattoria'],
    ['personal', 'Yoga', 8, '07:00', '08:00', 'Studio 5'],
    ['work', 'Customer workshop', 9, '13:00', '16:00', 'Downtown office'],
    ['family', 'Movie night', 11, '19:00', '21:30', null],
    ['community', 'Neighborhood cleanup', 12, '09:30', '11:30', 'Oak Avenue'],
    ['personal', 'Car service', 15, '08:30', '10:00', 'Corner Garage'],
    ['work', 'Release retro', 16, '14:00', '15:00', null],
    ['family', 'School play', 18, '18:00', '19:30', 'Lincoln Elementary'],
    ['community', 'Open studio night', 20, '18:00', '21:00', 'Arts Collective'],
    ['personal', 'Call the plumber', 22, '09:00', '09:30', null],
    ['work', 'Offsite planning', 24, '10:00', '15:00', 'Lakeside Lodge'],
    ['family', 'Grandparents visit', -9, '12:00', '15:00', null],
    ['work', 'Hiring panel', -11, '13:00', '14:30', null],
    ['community', 'Concert in the park', -13, '17:00', '19:00', 'Riverside Park bandshell'],
];
foreach ($oneOffs as [$c, $title, $off, $from, $to, $where]) {
    $day = $d($off);
    $in = ['title' => $title, 'start' => $at($day, $from), 'end' => $at($day, $to)];
    if ($where !== null) {
        $in['location'] = $where;
    }
    $add($c, $in);
}

// All-day and multi-day: a trip, a conference, a holiday weekend.
$tripStart = $d(13);
$add('travel', ['title' => 'Trip to the coast', 'start' => $date($tripStart), 'end' => $date($tripStart->modify('+4 days')), 'allDay' => true]);
$add('travel', ['title' => 'Flight out 8:10am', 'start' => $at($tripStart, '08:10'), 'end' => $at($tripStart, '09:40')]);
$add('travel', ['title' => 'Flight home 5:25pm', 'start' => $at($tripStart->modify('+3 days'), '17:25'), 'end' => $at($tripStart->modify('+3 days'), '18:55')]);
$add('work', ['title' => 'Industry conference', 'start' => $date($d(-17)), 'end' => $date($d(-14)), 'allDay' => true, 'location' => 'Convention center']);
$add('personal', ['title' => 'Library books due', 'start' => $date($d(4)), 'allDay' => true]);
$add('family', ['title' => 'No school', 'start' => $date($d(10)), 'allDay' => true]);

// People: when they are away or busy shows on the calendar.
$alex = $people->create($userId, ['name' => 'Alex Example', 'notes' => 'Sample person']);
$sam = $people->create($userId, ['name' => 'Sam Sample', 'notes' => 'Sample person']);
$span = static fn(DateTimeImmutable $from, DateTimeImmutable $to): array => [
    'start' => $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
    'end' => $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
];
$people->addSpan($userId, (int) $alex['id'], ['kind' => 'away', 'note' => 'Visiting family'] + $span($d(3), $d(8)));
$people->addSpan($userId, (int) $sam['id'], ['kind' => 'busy', 'note' => 'Exam week'] + $span($d(-3), $d(2)));
$people->addSpan($userId, (int) $sam['id'], ['kind' => 'away'] + $span($d(19), $d(23)));

echo "Added 4 calendars, $n events and 2 sample people (all invented).\n";
