<?php

declare(strict_types=1);

// Floating times (#167): an event whose clock time holds in any zone. Stored
// as its wall clock read in its own zone (the Home zone here, Los Angeles),
// shown on the viewer's clock (the device the request names), and every
// write moved back to the row's own clock where it comes in. Each block is
// a scenario(): a throw fails that block, and the next still runs. Every
// name is invented.

use BetterCal\Domain\Reminders;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Recurrence;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $ev = $d->events;
    $cal = (int) Settings::defaultCalendarId($db, $u);
    $db->run('UPDATE users SET settings_json = ? WHERE id = ?', [json_encode(['tz' => 'America/Los_Angeles', 'hereTz' => 'Asia/Tokyo']), $u]);

    // A request from a device in $zone, as the controllers make it: its
    // instants moved to the row's clock, then the domain call.
    $from = static function (string $zone) use ($ev): void {
        $ev->viewFrom($zone);
    };
    $window = static function (string $zone, string $start, string $end) use ($ev, $u): array {
        $ev->viewFrom($zone);
        return $ev->window($u, new \DateTimeImmutable($start), new \DateTimeImmutable($end), null, null, false);
    };
    $titled = static fn(array $occs, string $title): array => array_values(array_filter($occs, static fn(array $o): bool => $o['title'] === $title));

    // A floating walk at 7:00 on 4 Mar 2027, made on a device at Home.
    $from('America/Los_Angeles');
    $walk = $ev->create($u, $ev->fromViewer($u, null, [
        'calendarId' => $cal, 'title' => 'Morning walk', 'allDay' => false, 'floating' => true,
        'start' => '2027-03-04T07:00:00-08:00', 'end' => '2027-03-04T08:00:00-08:00', 'tzid' => 'America/Los_Angeles',
    ]));
    $walkId = (int) $walk['eventId'];

    scenario('floating: stored as its wall clock in the Home zone', function () use ($db, $walkId, $walk): void {
        $row = $db->one('SELECT floating, tzid, start_utc, end_utc FROM events WHERE id = ?', [$walkId]);
        checkEq('floating: the row floats, in the Home zone, at 7:00 there', ['1', 'America/Los_Angeles', '2027-03-04 15:00:00', '2027-03-04 16:00:00'],
            [(string) $row['floating'], $row['tzid'], $row['start_utc'], $row['end_utc']]);
        checkEq('floating: the created event says it floats', true, $walk['floating'] ?? null);
    });

    scenario('floating: one event, a different instant for each viewer, the same clock', function () use ($window, $titled): void {
        $tokyo = $titled($window('Asia/Tokyo', '2027-03-03T00:00:00Z', '2027-03-06T00:00:00Z'), 'Morning walk');
        $berlin = $titled($window('Europe/Berlin', '2027-03-03T00:00:00Z', '2027-03-06T00:00:00Z'), 'Morning walk');
        checkEq('floating: 7:00 in Tokyo for a device there', ['2027-03-04T07:00:00+09:00'], array_column($tokyo, 'start'));
        checkEq('floating: 7:00 in Berlin for a device there', ['2027-03-04T07:00:00+01:00'], array_column($berlin, 'start'));
        check('floating: the two are different instants', strtotime($tokyo[0]['start'] ?? '') !== strtotime($berlin[0]['start'] ?? ''));
    });

    scenario('floating: the window finds it at its edges, wherever its stored instant is', function () use ($window, $titled): void {
        // 7:00 in Tokyo is 22:00 UTC the day before, 17 hours before the stored 15:00 UTC.
        checkEq('floating: a window exactly its hour in Tokyo holds it', 1, count($titled($window('Asia/Tokyo', '2027-03-03T22:00:00Z', '2027-03-03T23:00:00Z'), 'Morning walk')));
        checkEq('floating: a window ending as it starts does not', 0, count($titled($window('Asia/Tokyo', '2027-03-03T12:00:00Z', '2027-03-03T22:00:00Z'), 'Morning walk')));
        checkEq('floating: a window starting as it ends does not', 0, count($titled($window('Asia/Tokyo', '2027-03-03T23:00:00Z', '2027-03-04T23:00:00Z'), 'Morning walk')));
        // In Kiritimati (UTC+14) 7:00 is 17:00 UTC two days back from Home's evening; in Niue (UTC-11) it is 18:00 UTC.
        checkEq('floating: at the far east of the clocks too', 1, count($titled($window('Pacific/Kiritimati', '2027-03-03T17:00:00Z', '2027-03-03T17:30:00Z'), 'Morning walk')));
        checkEq('floating: and the far west', 1, count($titled($window('Pacific/Niue', '2027-03-04T18:00:00Z', '2027-03-04T18:30:00Z'), 'Morning walk')));
    });

    scenario('floating: the single-event record is on the viewer clock too', function () use ($ev, $u, $walkId, $from): void {
        $from('Asia/Tokyo');
        $one = $ev->serializeSingle($ev->get($u, $walkId));
        checkEq('floating: the record reads 7:00 to 8:00 in Tokyo', ['2027-03-04T07:00:00+09:00', '2027-03-04T08:00:00+09:00', true], [$one['start'], $one['end'], $one['floating'] ?? null]);
    });

    scenario('floating: a drag on a device elsewhere moves the wall clock', function () use ($ev, $u, $walkId, $db, $from): void {
        $from('Asia/Tokyo');
        $ev->patch($u, $walkId, $ev->fromViewer($u, $walkId, ['start' => '2027-03-04T09:00:00+09:00', 'end' => '2027-03-04T10:00:00+09:00', 'tzid' => 'Asia/Tokyo']));
        $row = $db->one('SELECT tzid, start_utc, end_utc, floating FROM events WHERE id = ?', [$walkId]);
        checkEq('floating: 9:00 in Tokyo is stored as 9:00 at Home, the zone and flag kept', ['America/Los_Angeles', '2027-03-04 17:00:00', '2027-03-04 18:00:00', '1'],
            [$row['tzid'], $row['start_utc'], $row['end_utc'], (string) $row['floating']]);
    });

    // A floating daily series at 7:00 from 1 Mar 2027, across the US change
    // (14 Mar) and the European one (28 Mar).
    $from('America/Los_Angeles');
    $daily = $ev->create($u, $ev->fromViewer($u, null, [
        'calendarId' => $cal, 'title' => 'Stretching', 'allDay' => false, 'floating' => true, 'rrule' => 'FREQ=DAILY;COUNT=40',
        'start' => '2027-03-01T07:00:00-08:00', 'end' => '2027-03-01T07:30:00-08:00',
    ]));
    $dailyId = (int) $daily['eventId'];

    scenario('floating: a daily series stays at 7:00 across both clock changes', function () use ($window, $titled): void {
        foreach (['Europe/Berlin', 'America/Los_Angeles', 'Asia/Tokyo'] as $zone) {
            $occs = $titled($window($zone, '2027-02-28T00:00:00Z', '2027-04-12T00:00:00Z'), 'Stretching');
            $clocks = array_unique(array_map(static fn(array $o): string => (new \DateTimeImmutable($o['start']))->setTimezone(new \DateTimeZone($zone))->format('H:i'), $occs));
            checkEq("floating: every one of the 40 days at 7:00 in $zone", [40, ['07:00']], [count($occs), array_values($clocks)]);
        }
    });

    scenario('floating: an edited day is keyed by its wall clock occurrence', function () use ($ev, $u, $dailyId, $db, $window, $titled, $from): void {
        // From Berlin after its change: 7:00 there on 30 Mar is the day's occurrence.
        $from('Europe/Berlin');
        $ev->patch($u, $dailyId, $ev->fromViewer($u, $dailyId, [
            'scope' => 'this', 'instanceStart' => '2027-03-30T07:00:00+02:00',
            'title' => 'Stretching, late', 'start' => '2027-03-30T08:00:00+02:00', 'end' => '2027-03-30T08:30:00+02:00',
        ]));
        $ov = $db->one('SELECT recurrence_instance_utc, start_utc, floating FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$dailyId]);
        // 7:00 in Los Angeles on 30 Mar (daylight time) is 14:00 UTC.
        checkEq('floating: the key is the day 7:00 names at Home, the day moved to 8:00 there', ['2027-03-30 14:00:00', '2027-03-30 15:00:00', '1'],
            [$ov['recurrence_instance_utc'] ?? null, $ov['start_utc'] ?? null, (string) ($ov['floating'] ?? '')]);
        $tokyo = $titled($window('Asia/Tokyo', '2027-03-29T15:00:00Z', '2027-03-31T15:00:00Z'), 'Stretching, late');
        checkEq('floating: in Tokyo the edited day is 8:00 there, in place of its occurrence', ['2027-03-30T08:00:00+09:00'], array_column($tokyo, 'start'));
        checkEq('floating: and its occurrence is not shown twice', 0, count(array_filter($titled($window('Asia/Tokyo', '2027-03-29T15:00:00Z', '2027-03-30T15:00:00Z'), 'Stretching'),
            static fn(array $o): bool => str_starts_with($o['start'], '2027-03-30'))));
        // Its seriesKey comes back on the device's clock, so "Use series value" names it again.
        $from('Asia/Tokyo');
        $edited = $ev->serializeSingle($db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$dailyId]));
        checkEq('floating: the edited day names its occurrence on the device clock', '2027-03-29T22:00:00Z', $edited['seriesKey'] ?? null);
    });

    scenario('floating: a whole-series change through the model, from a device elsewhere', function () use ($ev, $u, $dailyId, $db, $from): void {
        $from('Asia/Tokyo');
        planned($ev, $u, $dailyId, $ev->fromViewer($u, $dailyId, [
            'scope' => 'all', 'instanceStart' => '2027-03-05T07:00:00+09:00',
            'start' => '2027-03-05T06:30:00+09:00', 'end' => '2027-03-05T07:00:00+09:00',
        ]));
        $row = $db->one('SELECT start_utc, tzid, floating FROM events WHERE id = ?', [$dailyId]);
        $local = Time::fromDb((string) $row['start_utc'])->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('Y-m-d H:i');
        checkEq('floating: the series now repeats at 6:30, still in its own zone and floating', ['2027-03-01 06:30', 'America/Los_Angeles', '1'], [$local, $row['tzid'], (string) $row['floating']]);
        $ov = $db->one('SELECT start_utc FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$dailyId]);
        checkEq('floating: the edited day keeps its own time', '2027-03-30 15:00:00', $ov['start_utc'] ?? null);
    });

    scenario('floating: turned off for the series, every row pins at its wall clock, and Undo floats it again', function () use ($ev, $u, $dailyId, $db, $from): void {
        $from('America/Los_Angeles');
        planned($ev, $u, $dailyId, $ev->fromViewer($u, $dailyId, ['scope' => 'all', 'instanceStart' => '2027-03-05T06:30:00-08:00', 'floating' => false]));
        $flags = array_map('intval', array_column($db->all('SELECT floating FROM events WHERE (id = ? OR recurrence_parent_id = ?) AND deleted_at IS NULL ORDER BY id', [$dailyId, $dailyId]), 'floating'));
        checkEq('floating: the master and its edited day are pinned', [0, 0], $flags);
        $d = $db->one('SELECT start_utc FROM events WHERE id = ?', [$dailyId]);
        checkEq('floating: pinned at the clock it had', '2027-03-01 14:30:00', $d['start_utc']);
        $d2 = domains($db);
        $d2->undo->undoById($u, lastEntry($db, $u));
        $flags = array_map('intval', array_column($db->all('SELECT floating FROM events WHERE (id = ? OR recurrence_parent_id = ?) AND deleted_at IS NULL ORDER BY id', [$dailyId, $dailyId]), 'floating'));
        checkEq('floating: Undo floats them again', [1, 1], $flags);
    });

    scenario('floating: an invitation is refused the flag, with a reason', function () use ($ev, $u, $cal, $db, $from): void {
        $from('America/Los_Angeles');
        $call = $ev->create($u, eventAt($cal, 'Sample planning call', '2027-03-08'));
        $db->run('UPDATE events SET invite_json = ? WHERE id = ?', [json_encode(['organizer' => ['email' => 'alex@example.test', 'name' => 'Alex Example'], 'method' => 'REQUEST']), (int) $call['eventId']]);
        try {
            $ev->patch($u, (int) $call['eventId'], ['floating' => true]);
            check('floating: an invitation is refused', false, 'no refusal');
        } catch (HttpError $e) {
            checkEq('floating: 422 floating_refused, saying why', [422, 'floating_refused', true], [$e->status, $e->errorCode, str_contains($e->getMessage(), 'same moment')]);
        }
        checkEq('floating: and the row is unchanged', '0', (string) $db->scalar('SELECT floating FROM events WHERE id = ?', [(int) $call['eventId']]));
    });

    scenario('floating: a calendar whose new timed events float presets the flag, and only that', function () use ($ev, $u, $cal, $db, $d, $from): void {
        $d->calendars->patch($u, $cal, ['floatTimed' => true]);
        $from('America/Los_Angeles');
        $made = $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample review', 'allDay' => false,
            'start' => '2027-03-09T18:00:00-08:00', 'end' => '2027-03-09T18:30:00-08:00']));
        $pinned = $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample flight', 'allDay' => false, 'floating' => false,
            'start' => '2027-03-10T18:00:00-08:00', 'end' => '2027-03-10T19:00:00-08:00']));
        $day = $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample day off', 'allDay' => true, 'start' => '2027-03-11', 'end' => '2027-03-12']));
        $flag = static fn(array $o): string => (string) $db->scalar('SELECT floating FROM events WHERE id = ?', [(int) $o['eventId']]);
        checkEq('floating: a new timed event floats, one sent pinned stays pinned, an all-day one never floats', ['1', '0', '0'], [$flag($made), $flag($pinned), $flag($day)]);
        $d->calendars->patch($u, $cal, ['floatTimed' => false]);
    });

    scenario('floating: reminders fire on the clock of the zone the device was last seen in', function () use ($ev, $u, $cal, $db, $from): void {
        $from('America/Los_Angeles');
        $pill = $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample vitamins', 'allDay' => false, 'floating' => true,
            'start' => '2027-05-04T08:00:00-07:00', 'end' => '2027-05-04T08:15:00-07:00', 'reminders' => [['minutes' => 10]]]));
        $rc = new \ReflectionClass(Reminders::class);
        $rm = $rc->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'recurrence' => new Recurrence()] as $k => $v) {
            $rc->getProperty($k)->setValue($rm, $v);
        }
        $rc->getProperty('health')->setValue($rm, new \BetterCal\Domain\SystemHealth($db));
        $due = static fn(string $now): array => array_values(array_filter(
            $rc->getMethod('dueForUser')->invoke($rm, $u, new \DateTimeImmutable($now)),
            static fn(array $x): bool => str_starts_with($x['key'], $pill['eventId'] . ':')
        ));
        // hereTz is Tokyo: 7:50 there on 4 May is 22:50 UTC on 3 May.
        $tokyo = $due('2027-05-03T22:50:30Z');
        checkEq('floating: due at 7:50 Tokyo time', 1, count($tokyo));
        check('floating: its text reads 8:00 AM with no other zone', str_contains((string) ($tokyo[0]['payload']['body'] ?? ''), '8:00 AM') && !str_contains((string) ($tokyo[0]['payload']['body'] ?? ''), ' in '), (string) ($tokyo[0]['payload']['body'] ?? ''));
        checkEq('floating: not at 7:50 Home time', 0, count($due('2027-05-04T14:50:30Z')));
        // With no device seen, Home is the clock.
        $db->run('UPDATE users SET settings_json = ? WHERE id = ?', [json_encode(['tz' => 'America/Los_Angeles']), $u]);
        checkEq('floating: with no device zone, at 7:50 Home time', 1, count($due('2027-05-04T14:50:30Z')));
        $db->run('UPDATE users SET settings_json = ? WHERE id = ?', [json_encode(['tz' => 'America/Los_Angeles', 'hereTz' => 'Asia/Tokyo']), $u]);
    });

    // The reminder scan reads a floating series through the window's path
    // (Recurrence::expand with the viewer), its clock where the device was last seen (Tokyo here).
    $scan = static function (string $now, int $eventId) use ($db, $u): array {
        $rc = new \ReflectionClass(Reminders::class);
        $rm = $rc->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'recurrence' => new Recurrence()] as $k => $v) {
            $rc->getProperty($k)->setValue($rm, $v);
        }
        $rc->getProperty('health')->setValue($rm, new \BetterCal\Domain\SystemHealth($db));
        return array_values(array_filter(
            $rc->getMethod('dueForUser')->invoke($rm, $u, new \DateTimeImmutable($now)),
            static fn(array $x): bool => str_starts_with($x['key'], $eventId . ':')
        ));
    };

    scenario('floating: a reminder of a series is on the rule\'s clock, not the day Home skips it', function () use ($ev, $u, $cal, $from, $scan): void {
        // 02:30 every day at Home; Home skips 02:00 to 03:00 on 14 Mar 2027, Tokyo does not.
        $from('America/Los_Angeles');
        $kiln = $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample night feed', 'allDay' => false, 'floating' => true,
            'rrule' => 'FREQ=DAILY;COUNT=30', 'start' => '2027-03-01T02:30:00-08:00', 'end' => '2027-03-01T02:45:00-08:00', 'reminders' => [['minutes' => 10]]]));
        // 02:20 in Tokyo on 14 Mar is 17:20 UTC on 13 Mar.
        $due = $scan('2027-03-13T17:20:30Z', (int) $kiln['eventId']);
        checkEq('floating: due at 02:20 Tokyo time on the day Home skips 02:30', 1, count($due));
        check('floating: and it reads 2:30 AM', str_contains((string) ($due[0]['payload']['body'] ?? ''), '2:30 AM'), (string) ($due[0]['payload']['body'] ?? ''));
    });

    scenario('floating: the reminder scan reaches a frequent series in its window, as a pinned one does', function () use ($ev, $u, $cal, $from, $scan): void {
        $from('America/Los_Angeles');
        $make = static fn(bool $floats): int => (int) $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample stretch break', 'allDay' => false, 'floating' => $floats,
            'rrule' => 'FREQ=MINUTELY;INTERVAL=3', 'start' => '2027-05-01T08:00:00-07:00', 'end' => '2027-05-01T08:01:00-07:00', 'reminders' => [['minutes' => 1]]]))['eventId'];
        $floating = $make(true);
        $pinned = $make(false);
        $now = '2027-05-04T03:00:30Z';
        $f = count($scan($now, $floating));
        check('floating: a floating series every three minutes has a reminder due', $f >= 1, "$f due");
        checkEq('floating: as many as its pinned twin', count($scan($now, $pinned)), $f);
    });

    // Search (F8): over, upcoming, ordered and given a next or latest date on
    // the device's clock, before the limit, as the controller asks it.
    $search = new \BetterCal\Domain\Search($db, new \BetterCal\Domain\Labels($db));
    $found = static function (string $zone, string $q, string $when, string $now) use ($ev, $u, $search): array {
        $ev->viewFrom($zone);
        $rows = $search->search($u, $q, 50, true, ['when' => $when, 'now' => new \DateTimeImmutable($now), 'viewer' => $ev->viewerZone($u)]);
        return array_map(static fn(array $o): array => [$o['start'], $o['end']], $ev->serializeRows($rows));
    };
    $from('America/Los_Angeles');
    $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample pottery appointment', 'allDay' => false, 'floating' => true,
        'start' => '2027-01-10T09:00:00-08:00', 'end' => '2027-01-10T10:00:00-08:00']));

    scenario('floating search: over in Tokyo, east of Home, while it is still ahead at Home', function () use ($found, $search, $ev, $u): void {
        // 12:00 UTC: 21:00 in Tokyo, after its 09:00 to 10:00 there; 04:00 at Home, before it.
        checkEq('floating search: Upcoming does not list it', [], $found('Asia/Tokyo', 'pottery', 'upcoming', '2027-01-10T12:00:00Z'));
        checkEq('floating search: Past does, at 09:00 Tokyo time', [['2027-01-10T09:00:00+09:00', '2027-01-10T10:00:00+09:00']], $found('Asia/Tokyo', 'pottery', 'past', '2027-01-10T12:00:00Z'));
        checkEq('floating search: the past count agrees', 1, $search->countPast($u, 'pottery', ['now' => new \DateTimeImmutable('2027-01-10T12:00:00Z'), 'viewer' => $ev->viewerZone($u)]));
    });

    scenario('floating search: ahead in Honolulu, west of Home, while it is over at Home', function () use ($found): void {
        // 18:30 UTC: 08:30 in Honolulu, before its 09:00 there; 10:30 at Home, after it.
        checkEq('floating search: Upcoming lists it at 09:00 Honolulu time', [['2027-01-10T09:00:00-10:00', '2027-01-10T10:00:00-10:00']], $found('Pacific/Honolulu', 'pottery', 'upcoming', '2027-01-10T18:30:00Z'));
        checkEq('floating search: Past does not', [], $found('Pacific/Honolulu', 'pottery', 'past', '2027-01-10T18:30:00Z'));
    });

    $from('America/Los_Angeles');
    $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample glazing class', 'allDay' => false, 'floating' => true,
        'rrule' => 'FREQ=DAILY;COUNT=60', 'start' => '2027-01-01T09:00:00-08:00', 'end' => '2027-01-01T10:00:00-08:00']));
    $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample night feed for kilns', 'allDay' => false, 'floating' => true,
        'rrule' => 'FREQ=DAILY;COUNT=30', 'start' => '2027-03-01T02:30:00-08:00', 'end' => '2027-03-01T02:45:00-08:00']));

    scenario('floating search: a series\' next and latest dates are the viewer\'s', function () use ($found): void {
        // 01:30 UTC on 12 Jan: 10:30 in Tokyo, 12 Jan's class there just over; 17:30 on 11 Jan at Home, 11 Jan's over and 12 Jan's ahead.
        checkEq('floating search: the next is 13 Jan in Tokyo', [['2027-01-13T09:00:00+09:00', '2027-01-13T10:00:00+09:00']], $found('Asia/Tokyo', 'glazing', 'upcoming', '2027-01-12T01:30:00Z'));
        checkEq('floating search: the latest is 12 Jan in Tokyo', [['2027-01-12T09:00:00+09:00', '2027-01-12T10:00:00+09:00']], $found('Asia/Tokyo', 'glazing', 'past', '2027-01-12T01:30:00Z'));
        // A minute before it starts in Tokyo, 12 Jan's is still the next.
        checkEq('floating search: a minute before, 12 Jan\'s is next', [['2027-01-12T09:00:00+09:00', '2027-01-12T10:00:00+09:00']], $found('Asia/Tokyo', 'glazing', 'upcoming', '2027-01-11T23:59:00Z'));
        // The next date on the day Home skips 02:30 is 02:30 in Tokyo, which has it.
        checkEq('floating search: on the day Home skips the clock, the next is at 02:30 in Tokyo', [['2027-03-14T02:30:00+09:00', '2027-03-14T02:45:00+09:00']], $found('Asia/Tokyo', 'kilns', 'upcoming', '2027-03-13T17:00:00Z'));
    });

    scenario('floating search: the route reads the device clock the request names', function () use ($ev, $u, $cal, $db, $from, $search): void {
        // Two hours ahead at Home, so about fifteen hours over in Tokyo, whatever the date.
        $home = new \DateTimeZone('America/Los_Angeles');
        $ahead = (new \DateTimeImmutable('now', $home))->modify('+2 hours');
        $start = $ahead->setTime((int) $ahead->format('G'), 0);
        $from('America/Los_Angeles');
        $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample trellis repair', 'allDay' => false, 'floating' => true,
            'start' => $start->format(DATE_ATOM), 'end' => $start->modify('+1 hour')->format(DATE_ATOM)]));
        $route = new \BetterCal\Http\Controllers\SearchController($search, $ev, new \BetterCal\Domain\Filters($db, new \BetterCal\Domain\Undo($db), new \BetterCal\Infra\JobQueue($db)), new \BetterCal\Domain\Labels($db));
        $ask = static function (string $zone, string $when) use ($ev, $u, $route): array {
            $ev->viewFrom($zone);
            $req = new \BetterCal\Http\Request('GET', '/search', ['q' => 'trellis', 'when' => $when]);
            $req->user = ['id' => $u];
            return json_decode($route->search($req)->body, true);
        };
        checkEq('floating search route: from Tokyo, not upcoming', [], $ask('Asia/Tokyo', 'upcoming')['results']);
        checkEq('floating search route: from Tokyo, past, and counted so', [1, 1], [count($ask('Asia/Tokyo', 'past')['results']), $ask('Asia/Tokyo', 'upcoming')['pastCount'] ?? null]);
        checkEq('floating search route: from Home, upcoming', 1, count($ask('America/Los_Angeles', 'upcoming')['results']));
    });

    // A person's next event (GET /people nextStart) is next on the device's
    // clock, read the way search reads it: now on each event's own clock,
    // and a series' next date as it shows there.
    $people = new \BetterCal\Domain\People($db);
    $nextOf = static function (?string $zone, string $name, string $now) use ($ev, $u, $people): ?string {
        $ev->viewFrom($zone);
        $viewer = $zone === null ? null : $ev->viewerZone($u);
        foreach ($people->list($u, $viewer, new \DateTimeImmutable($now)) as $p) {
            if ($p['name'] === $name) {
                return $p['nextStart'];
            }
        }
        throw new \RuntimeException("no person $name");
    };
    $from('America/Los_Angeles');
    $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample choir practice', 'allDay' => false, 'floating' => true,
        'rrule' => 'FREQ=DAILY;COUNT=60', 'start' => '2027-02-01T09:00:00-08:00', 'end' => '2027-02-01T10:00:00-08:00', 'personNames' => ['Alex Example']]));
    $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample choir practice, pinned', 'allDay' => false, 'floating' => false,
        'rrule' => 'FREQ=DAILY;COUNT=60', 'start' => '2027-02-01T09:00:00-08:00', 'end' => '2027-02-01T10:00:00-08:00', 'tzid' => 'America/Los_Angeles', 'personNames' => ['Sam Sample']]));

    scenario('floating people: from Tokyo, today\'s 09:00 is over there while Home has it ahead, so the next is tomorrow\'s', function () use ($nextOf): void {
        // 01:30 UTC on 10 Feb: 10:30 on 10 Feb in Tokyo, its 09:00 there over; 17:30 on 9 Feb at Home, 10 Feb's ahead.
        checkEq('floating people: next is 09:00 on 11 Feb in Tokyo', '2027-02-11T00:00:00+00:00', $nextOf('Asia/Tokyo', 'Alex Example', '2027-02-10T01:30:00Z'));
        checkEq('floating people: without a device, on the Home clock, 09:00 on 10 Feb there', '2027-02-10T17:00:00+00:00', $nextOf(null, 'Alex Example', '2027-02-10T01:30:00Z'));
    });

    scenario('floating people: from Honolulu, today\'s 09:00 is still ahead there while it is over at Home', function () use ($nextOf): void {
        // 18:30 UTC on 10 Feb: 08:30 in Honolulu, before its 09:00 there; 10:30 at Home, after it.
        checkEq('floating people: next is 09:00 on 10 Feb in Honolulu', '2027-02-10T19:00:00+00:00', $nextOf('Pacific/Honolulu', 'Alex Example', '2027-02-10T18:30:00Z'));
    });

    scenario('floating people: the pinned twin is next at its own instant from anywhere', function () use ($nextOf): void {
        foreach (['Asia/Tokyo', 'Pacific/Honolulu', 'America/Los_Angeles', null] as $zone) {
            checkEq('floating people: pinned, Tokyo moment, from ' . ($zone ?? 'no device'), '2027-02-10T17:00:00+00:00', $nextOf($zone, 'Sam Sample', '2027-02-10T01:30:00Z'));
            checkEq('floating people: pinned, Honolulu moment, from ' . ($zone ?? 'no device'), '2027-02-11T17:00:00+00:00', $nextOf($zone, 'Sam Sample', '2027-02-10T18:30:00Z'));
        }
    });

    scenario('floating people: the route reads the device clock the request names', function () use ($ev, $u, $cal, $people, $from): void {
        // Two hours ahead at Home, so about fifteen hours over in Tokyo, whatever the date.
        $home = new \DateTimeZone('America/Los_Angeles');
        $ahead = (new \DateTimeImmutable('now', $home))->modify('+2 hours');
        $start = $ahead->setTime((int) $ahead->format('G'), 0);
        $from('America/Los_Angeles');
        $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => 'Sample seed swap', 'allDay' => false, 'floating' => true,
            'start' => $start->format(DATE_ATOM), 'end' => $start->modify('+1 hour')->format(DATE_ATOM), 'personNames' => ['Riley Placeholder']]));
        $route = new \BetterCal\Http\Controllers\PeopleController($people, $ev);
        $ask = static function (string $zone) use ($ev, $u, $route): ?string {
            $ev->viewFrom($zone);
            $req = new \BetterCal\Http\Request('GET', '/people', []);
            $req->user = ['id' => $u];
            foreach (json_decode($route->index($req)->body, true)['people'] as $p) {
                if ($p['name'] === 'Riley Placeholder') {
                    return $p['nextStart'];
                }
            }
            return 'missing';
        };
        checkEq('floating people route: from Tokyo, nothing next', null, $ask('Asia/Tokyo'));
        checkEq('floating people route: from Home, the swap', $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'), $ask('America/Los_Angeles'));
    });

    // A series' next date counts its edited and skipped days as the calendar
    // shows them: a moved day at its own start and never at the slot it
    // left, a skipped day not at all, an edited day for its own people once.
    // 18:00 UTC on 9 Apr is 11:00 at Home, that day's 09:00 over.
    $nineApr = '2027-04-09T18:00:00Z';
    $from('America/Los_Angeles');
    $daily = static fn(string $title, array $names): int => (int) $ev->create($u, $ev->fromViewer($u, null, ['calendarId' => $cal, 'title' => $title, 'allDay' => false,
        'rrule' => 'FREQ=DAILY;COUNT=60', 'start' => '2027-04-01T09:00:00-07:00', 'end' => '2027-04-01T10:00:00-07:00', 'tzid' => 'America/Los_Angeles', 'personNames' => $names]))['eventId'];
    $wheel = $daily('Sample wheel throwing', ['Jordan Sample']);
    $bread = $daily('Sample bread class', ['Casey Example']);
    $club = $daily('Sample book club', ['Morgan Sample']);
    $ev->patch($u, $wheel, $ev->fromViewer($u, $wheel, ['scope' => 'this', 'instanceStart' => '2027-04-10T16:00:00Z',
        'start' => '2027-04-17T13:00:00-07:00', 'end' => '2027-04-17T14:00:00-07:00']));
    $ev->deleteEvent($u, $bread, 'this', '2027-04-10T16:00:00Z');
    $ev->patch($u, $club, $ev->fromViewer($u, $club, ['scope' => 'this', 'instanceStart' => '2027-04-10T16:00:00Z', 'title' => 'Sample book club, author visit',
        'start' => '2027-04-10T10:00:00-07:00', 'end' => '2027-04-10T11:00:00-07:00', 'personIds' => [], 'personNames' => ['Quinn Placeholder']]));

    scenario('series next: a day moved to next week is not next at the slot it left', function () use ($nextOf, $found, $nineApr): void {
        checkEq('series next: the person\'s next is the day after tomorrow', '2027-04-11T16:00:00+00:00', $nextOf('America/Los_Angeles', 'Jordan Sample', $nineApr));
        $dates = array_column($found('America/Los_Angeles', 'wheel throwing', 'upcoming', $nineApr), 0);
        checkEq('series next: search gives the series the day after tomorrow, and the moved day its own date', ['2027-04-11T09:00:00-07:00', '2027-04-17T13:00:00-07:00'], $dates);
    });

    scenario('series next: a skipped day is not next', function () use ($nextOf, $found, $nineApr): void {
        checkEq('series next: the person\'s next is the following day', '2027-04-11T16:00:00+00:00', $nextOf('America/Los_Angeles', 'Casey Example', $nineApr));
        checkEq('series next: search gives the following day', [['2027-04-11T09:00:00-07:00', '2027-04-11T10:00:00-07:00']], $found('America/Los_Angeles', 'bread class', 'upcoming', $nineApr));
    });

    scenario('series next: an edited day counts for its own people, once, at its own start', function () use ($nextOf, $found, $nineApr): void {
        checkEq('series next: someone taken off the edited day is next the day after', '2027-04-11T16:00:00+00:00', $nextOf('America/Los_Angeles', 'Morgan Sample', $nineApr));
        checkEq('series next: someone on the edited day only is next at its 10:00', '2027-04-10T17:00:00+00:00', $nextOf('America/Los_Angeles', 'Quinn Placeholder', $nineApr));
        $tomorrow = array_values(array_filter($found('America/Los_Angeles', 'book club', 'upcoming', $nineApr), static fn(array $o): bool => str_starts_with($o[0], '2027-04-10')));
        checkEq('series next: search lists the edited day once, at 10:00', [['2027-04-10T10:00:00-07:00', '2027-04-10T11:00:00-07:00']], $tomorrow);
    });

    scenario('floating: without a device zone, a floating event reads on the Home clock', function () use ($ev, $u, $walkId): void {
        $ev->viewFrom(null);
        $one = $ev->serializeSingle($ev->get($u, $walkId));
        checkEq('floating: 9:00 at Home', '2027-03-04T09:00:00-08:00', $one['start']);
    });
};
