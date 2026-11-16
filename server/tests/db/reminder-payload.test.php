<?php

declare(strict_types=1);

// A reminder carries the start of the event's description (#26), through the
// scan's own path: the rows it reads, the series it expands, the day edited
// on its own. Each block is a scenario(): a throw fails that block, and the
// next still runs. Every name is invented.

use BetterCal\Domain\Reminders;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Recurrence;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $ev = $d->events;
    $cal = (int) Settings::defaultCalendarId($db, $u);
    $db->run('UPDATE users SET settings_json = ? WHERE id = ?', [json_encode(['tz' => 'America/Los_Angeles', 'hereTz' => 'America/Los_Angeles']), $u]);
    $ev->viewFrom('America/Los_Angeles');

    // What the minutely scan finds due at $now for one event, by its title
    // (a day edited on its own is a row of its own, with its own id).
    $due = static function (string $now, string $title) use ($db, $u): array {
        $rc = new \ReflectionClass(Reminders::class);
        $rm = $rc->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'recurrence' => new Recurrence()] as $k => $v) {
            $rc->getProperty($k)->setValue($rm, $v);
        }
        $rc->getProperty('health')->setValue($rm, new \BetterCal\Domain\SystemHealth($db));
        return array_values(array_filter(
            $rc->getMethod('dueForUser')->invoke($rm, $u, new \DateTimeImmutable($now)),
            static fn(array $x): bool => ($x['payload']['title'] ?? '') === $title
        ));
    };
    $lines = static fn(array $hit): array => explode("\n", (string) ($hit['payload']['body'] ?? ''));

    scenario('reminder payload: an event\'s description is the second line, as plain text', function () use ($ev, $u, $cal, $due, $lines): void {
        $made = $ev->create($u, $ev->fromViewer($u, null, [
            'calendarId' => $cal, 'title' => 'Sample workshop', 'allDay' => false, 'location' => 'Example Hall',
            'description' => '<p>Bring the <b>sample kit</b> &amp; a notebook.</p><p>Doors at 9.</p>',
            'start' => '2027-04-06T10:00:00-07:00', 'end' => '2027-04-06T11:00:00-07:00', 'reminders' => [['minutes' => 10]],
        ]));
        $hit = $due('2027-04-06T16:50:30Z', 'Sample workshop');
        checkEq('reminder payload: due once', 1, count($hit));
        checkEq('reminder payload: the time and place, then the description', ['Tue, Apr 6, 10:00 AM · Example Hall', 'Bring the sample kit & a notebook. Doors at 9.'], $lines($hit[0] ?? []));
    });

    scenario('reminder payload: no description, no second line', function () use ($ev, $u, $cal, $due, $lines): void {
        $made = $ev->create($u, $ev->fromViewer($u, null, [
            'calendarId' => $cal, 'title' => 'Sample call', 'allDay' => false,
            'start' => '2027-04-07T10:00:00-07:00', 'end' => '2027-04-07T10:30:00-07:00', 'reminders' => [['minutes' => 10]],
        ]));
        $hit = $due('2027-04-07T16:50:30Z', 'Sample call');
        checkEq('reminder payload: the time alone', ['Wed, Apr 7, 10:00 AM'], $lines($hit[0] ?? []));
    });

    scenario('reminder payload: a day of a series edited on its own says its own description', function () use ($ev, $u, $cal, $due, $lines): void {
        $made = $ev->create($u, $ev->fromViewer($u, null, [
            'calendarId' => $cal, 'title' => 'Sample standup', 'allDay' => false, 'description' => 'The usual agenda',
            'rrule' => 'FREQ=DAILY;COUNT=5', 'start' => '2027-04-12T09:00:00-07:00', 'end' => '2027-04-12T09:15:00-07:00', 'reminders' => [['minutes' => 10]],
        ]));
        $id = (int) $made['eventId'];
        $ev->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-04-13T09:00:00-07:00', 'description' => 'Demo day: bring the sample build']);
        $first = $due('2027-04-12T15:50:30Z', 'Sample standup');
        $edited = $due('2027-04-13T15:50:30Z', 'Sample standup');
        checkEq('reminder payload: one reminder each day', [1, 1], [count($first), count($edited)]);
        checkEq('reminder payload: a day of the series says the series\' description', 'The usual agenda', $lines($first[0] ?? [])[1] ?? null);
        checkEq('reminder payload: the edited day says its own', 'Demo day: bring the sample build', $lines($edited[0] ?? [])[1] ?? null);
    });
};
