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

    scenario('floating: without a device zone, a floating event reads on the Home clock', function () use ($ev, $u, $walkId): void {
        $ev->viewFrom(null);
        $one = $ev->serializeSingle($ev->get($u, $walkId));
        checkEq('floating: 9:00 at Home', '2027-03-04T09:00:00-08:00', $one['start']);
    });
};
