<?php

declare(strict_types=1);

// An undo's refusal names a day by its key read under the repeating event it
// was written for (Time::keyDay): a timed day east or west of UTC by its own
// date, never its key's UTC date (#246). The other refusals that name a day,
// a cause or a lost name are pinned beside their cases (undo-day-gate,
// undo-form-day, google-series). Expectations written by hand. Invented data.

use BetterCal\Infra\Db;

return static function (Db $db): void {
    scenario('undo refusal day: a count raise undone after a later edit of a day it added names that day by its New York date', function () use ($db): void {
        $u = freshUser($db);
        $d = domains($db);
        $home = (int) \BetterCal\Domain\Settings::defaultCalendarId($db, $u);
        // Daily at 19:00 New York from Mon 1 Feb 2027: Sat 13 Feb is 2027-02-14 00:00:00 UTC.
        $s = (int) $d->events->create($u, ['calendarId' => $home, 'title' => 'Sample stretch', 'start' => '2027-02-01T19:00:00-05:00', 'end' => '2027-02-01T20:00:00-05:00',
            'tzid' => 'America/New_York', 'rrule' => 'FREQ=DAILY;COUNT=7'])['eventId'];
        planned($d->events, $u, $s, ['scope' => 'all', 'instanceStart' => '2027-02-01T19:00:00-05:00', 'rrule' => 'FREQ=DAILY;COUNT=14']);
        $count = lastEntry($db, $u);
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-02-13T19:00:00-05:00', 'title' => 'Sample day title']);
        checkEq('(setup) the edited day is keyed at its UTC instant, the next date in UTC', '2027-02-14 00:00:00',
            (string) $db->scalar('SELECT recurrence_instance_utc FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s]));
        $caught = null;
        try {
            $d->undo->undoById($u, $count);
        } catch (BetterCal\Http\HttpError $e) {
            $caught = $e;
        }
        checkEq('stale, naming the day by its date in New York, never its key\'s UTC date', ['stale_undo',
            'Newer changes exist for this item: Sat 13 Feb 2027 exists because of this change and was changed since, so undoing it would remove that day.'],
            [$caught?->errorCode, $caught?->getMessage()]);
    });
};
