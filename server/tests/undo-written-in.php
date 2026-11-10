<?php

declare(strict_types=1);

// Undo::writtenIn with no database: what each kind of journal entry itself
// holds about the form (timed or all-day) and the zone its series had when
// the entry's key was written, and Calc::dayOf, the day a key names under a
// form and zone (docs/design/decisions.md, "An entry's key names a day
// under the series it was written in"). Every expectation is written out
// by hand: 19:00 in New York on 6 January 2027 is 00:00 UTC on the 7th.
// Required from run.php. The database half is tests/db/undo-form-day.test.php.

use BetterCal\Domain\Series\Calc;
use BetterCal\Domain\Undo;

$wiDay = static fn(string $key, bool $allDay, string $tz): string => gmdate('Y-m-d', Calc::dayOf($key, $allDay, $tz) * 86400);
checkEq('day of a key: a timed key is its local date in the zone, an all-day key its own date', ['2027-01-06', '2027-01-07', '2027-01-07', '2027-01-06', '2027-01-05'], [
    $wiDay('2027-01-07 00:00:00', false, 'America/New_York'), // 19:00 on the 6th
    $wiDay('2027-01-07 00:00:00', true, 'UTC'),
    $wiDay('2027-01-07 00:00:00', false, 'Asia/Tokyo'), // 09:00 on the 7th
    $wiDay('2027-01-05 22:00:00', false, 'Asia/Tokyo'), // 07:00 on the 6th
    $wiDay('2027-01-05 22:00:00', true, 'UTC'),
]);

$wiKey = '2027-01-07 00:00:00';
$wiMaster = static fn(bool $allDay, string $tz = 'America/New_York'): array => ['id' => 1, 'recurrence_instance_utc' => null, 'rrule' => 'FREQ=DAILY;COUNT=10', 'all_day' => $allDay ? 1 : 0, 'tzid' => $allDay ? 'UTC' : $tz];
$wiRow = static fn(array $own, bool $allDay = false, string $tz = 'America/New_York', string $key = '2027-01-07 00:00:00'): array
    => ['id' => 2, 'recurrence_parent_id' => 1, 'recurrence_instance_utc' => $key, 'all_day' => $allDay ? 1 : 0, 'tzid' => $allDay ? 'UTC' : $tz, 'override_fields' => json_encode($own)];
$wiEntry = static fn(?array $google, array $before = [], array $after = []): array => [
    'details_json' => $google === null ? null : json_encode(['google' => $google + ['calendarId' => 1]]),
    'before_json' => json_encode(['tables' => ['events' => $before]]), 'after_json' => json_encode(['tables' => ['events' => $after]]),
];
$wiTimedBody = ['start' => ['dateTime' => '2027-01-06T19:00:00-05:00', 'timeZone' => 'America/New_York']];
$wiIn = static fn(array $entry, string $key = '2027-01-07 00:00:00'): array => Undo::writtenIn($entry, 1, $key);
$wiSays = static fn(?bool $allDay, ?string $tz, bool $clash = false): array => ['allDay' => $allDay, 'tzid' => $tz, 'clash' => $clash];

checkEq('written in: a Google edit that made the day\'s row: the id\'s instant, the body before and the row all say timed, in New York',
    $wiSays(false, 'America/New_York'), $wiIn($wiEntry(['kind' => 'edit', 'googleId' => 'smpl_20270107T000000Z', 'before' => $wiTimedBody], [], [$wiRow(['tags'])])));
checkEq('written in: the same under an all-day series: a date id and a date body, and no zone to tell',
    $wiSays(true, null), $wiIn($wiEntry(['kind' => 'edit', 'googleId' => 'smpl_20270107', 'before' => ['start' => ['date' => '2027-01-07']]], [], [$wiRow(['tags'], true)])));
checkEq('written in: a delete of an ordinary day keeps a bare id: timed, and nothing says the zone',
    $wiSays(false, null), $wiIn($wiEntry(['kind' => 'delete', 'googleId' => 'smpl_20270107T000000Z', 'before' => null])));
checkEq('written in: the undo of a delete of an all-day day: a date, which needs no zone',
    $wiSays(true, null), $wiIn($wiEntry(['kind' => 'create', 'googleId' => 'smpl_20270107', 'before' => null])));
checkEq('written in: a day made all-day in a timed series: the id is the series\' form; the day\'s row and its body before are its own and say nothing',
    $wiSays(false, null), $wiIn($wiEntry(['kind' => 'edit', 'googleId' => 'smpl_20270107T000000Z', 'before' => ['start' => ['date' => '2027-01-06']]], [$wiRow(['allDay'], true)], [])));
checkEq('written in: a day at a time and zone of its own: timed by the id, and neither its row\'s zone nor its body\'s is the series\'',
    $wiSays(false, null), $wiIn($wiEntry(['kind' => 'edit', 'googleId' => 'smpl_20270107T000000Z', 'before' => ['start' => ['dateTime' => '2027-01-07T03:00:00+01:00', 'timeZone' => 'Europe/Berlin']]], [$wiRow(['time'], false, 'Europe/Berlin')], [])));
checkEq('written in: a day at a date of its own still has its series\' zone',
    $wiSays(false, 'America/New_York'), $wiIn($wiEntry(['kind' => 'edit', 'googleId' => 'smpl_20270107T000000Z', 'before' => ['start' => ['dateTime' => '2027-01-08T19:00:00-05:00', 'timeZone' => 'America/New_York']]], [$wiRow(['date'])], [])));
checkEq('written in: the body before an edit that gave an ordinary day its own zone is the series\' occurrence: timed, in New York',
    $wiSays(false, 'America/New_York'), $wiIn($wiEntry(['kind' => 'edit', 'googleId' => 'smpl_20270107T000000Z', 'before' => $wiTimedBody], [], [$wiRow(['time'], false, 'Europe/Berlin')])));
checkEq('written in: a snapshot entry (an ICS or own calendar): the series\' own row in it says the form and the zone',
    $wiSays(false, 'America/New_York'), $wiIn($wiEntry(null, [$wiMaster(false)], [$wiMaster(false), $wiRow(['tags'])])));
checkEq('written in: a snapshot entry holding only the day\'s row, which owns neither its form nor its time',
    $wiSays(false, 'America/New_York'), $wiIn($wiEntry(null, [$wiRow(['tags'])], [])));
// A row stored with no list owns what the one reading of a stored list says (OwnFields::stored):
// with no series row in the entry, nothing, as migration 063 wrote, so its form and zone are the series'.
checkEq('written in: a row stored before own lists, with no series row in the entry, owns nothing and speaks for the series',
    $wiSays(false, 'America/New_York'), $wiIn($wiEntry(null, [['override_fields' => null] + $wiRow([])], [])));
checkEq('written in: a change that re-formed the series itself: a row it removed is of the series before, whatever the series is after',
    $wiSays(false, 'America/New_York'), $wiIn($wiEntry(null, [$wiMaster(false), $wiRow(['tags'])], [$wiMaster(true)])));
checkEq('written in: the same entry says nothing of a key it holds no row for',
    $wiSays(null, null), $wiIn($wiEntry(null, [$wiMaster(false), $wiRow(['tags'])], [$wiMaster(true)]), '2027-01-09 00:00:00'));
checkEq('written in: a whole-series change on a Google calendar says nothing here: its keys are read by where it stands in the journal',
    $wiSays(null, null), $wiIn($wiEntry(['kind' => 'series', 'googleId' => 'smpl', 'before' => [['googleId' => 'smpl']]], [$wiMaster(false)], [$wiMaster(true)])));
checkEq('written in: an id of another day is no word about this key',
    $wiSays(null, null), $wiIn($wiEntry(['kind' => 'delete', 'googleId' => 'smpl_20270108T000000Z', 'before' => null])));
checkEq('written in: two sources that disagree (a date id, a timed series row) are a clash, and neither is believed',
    $wiSays(null, null, true), $wiIn($wiEntry(['kind' => 'edit', 'googleId' => 'smpl_20270107', 'before' => null], [$wiMaster(false)], [$wiMaster(false)])));
checkEq('written in: two zones that disagree (the row\'s, the body\'s) are a clash too',
    $wiSays(null, null, true), $wiIn($wiEntry(['kind' => 'edit', 'googleId' => 'smpl_20270107T000000Z', 'before' => ['start' => ['dateTime' => '2027-01-07T09:00:00+09:00', 'timeZone' => 'Asia/Tokyo']]], [], [$wiRow(['tags'])])));
