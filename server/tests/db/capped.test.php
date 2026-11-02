<?php

declare(strict_types=1);

// A series too frequent for one answer is named in `capped`, so the app asks
// for the rest. One a filter hides entirely is expanded and counted too, but
// the answer shows none of it: named, it stopped the app's follow-up for
// every other capped series in the answer, and the app said the hidden one
// repeats too often.

use BetterCal\Domain\Filters;
use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;

return static function (Db $db): void {
    scenario('capped: a series hidden by a filter is not named', function () use ($db): void {
        $u = freshUser($db);
        $d = domains($db);
        $home = (int) Settings::defaultCalendarId($db, $u);
        $shown = (int) $d->events->create($u, eventAt($home, 'Sample hourly check', '2027-03-01', ['rrule' => 'FREQ=HOURLY']))['eventId'];
        $hidden = (int) $d->events->create($u, eventAt($home, 'Sample hourly webinar', '2027-03-01', ['rrule' => 'FREQ=HOURLY']))['eventId'];
        (new Filters($db, $d->undo, new JobQueue($db)))->create($u, ['name' => 'No webinars', 'scope' => 'global', 'type' => 'keyword', 'action' => 'hide', 'config' => ['pattern' => 'webinar']]);

        $occurrences = $d->events->window($u, new \DateTimeImmutable('2027-03-01T00:00:00Z'), new \DateTimeImmutable('2027-05-01T00:00:00Z'), null, null, false);
        $ids = array_values(array_unique(array_map(static fn(array $o): int => (int) $o['eventId'], $occurrences)));
        checkEq('capped: the answer shows only the series not hidden', [$shown], $ids);
        $all = $d->events->cappedSeries();
        sort($all);
        checkEq('capped: both series were cut short', [$shown, $hidden], $all);
        checkEq('capped: only the one shown is named', [$shown], $d->events->cappedSeries($occurrences));
    });
};
