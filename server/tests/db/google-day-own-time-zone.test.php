<?php

declare(strict_types=1);

// From the second independent read of the 1.5.6 fixes (its probe of finding F1, made a regular test): a day with a
// time of its own through a change of the series' zone at the same moments. Google keeps the day's record, puts its
// start back on its occurrence and leaves its end where it was (probe 3.17; 3.67 for a start moved along its
// pattern; replay rounds 2 and 5), which the stand-in does too. The day must end as the person left it: its own time
// (13:00 to 14:30 Los Angeles on 11 January, 21:00 to 22:30 UTC) at Google and here, with nothing left half done and
// nothing for Review to say. A day with only a longer length of its own (its start the series') is the control:
// Google's reset changes nothing a person sees of it. Before the read-back rule (docs/design/google-series-read-back.md)
// the first day ended 18:00 to 22:30 UTC with one write sent and Review silent. Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $at = '2027-01-04T10:00:00-08:00';
    // 11:00 Denver is 10:00 Los Angeles: the same moments.
    $zone = ['scope' => 'all', 'instanceStart' => $at, 'tzid' => 'America/Denver', 'start' => '2027-01-04T11:00:00-07:00', 'end' => '2027-01-04T12:00:00-07:00'];
    $utc = new DateTimeZone('UTC');
    $when = static function (object $t) use ($db, $utc): array {
        $r = $db->one("SELECT start_utc, end_utc, tzid FROM events WHERE recurrence_parent_id = ? AND title = 'Own title' AND deleted_at IS NULL", [$t->id]);
        $g = null;
        foreach ($t->s->fake->events as $e) {
            if (($e['summary'] ?? '') === 'Own title' && ($e['status'] ?? '') !== 'cancelled' && isset($e['originalStartTime'])) {
                $g = (new DateTimeImmutable((string) $e['start']['dateTime']))->setTimezone($utc)->format('Y-m-d H:i') . ' to ' . (new DateTimeImmutable((string) $e['end']['dateTime']))->setTimezone($utc)->format('Y-m-d H:i');
            }
        }
        return [$r === null ? null : substr((string) $r['start_utc'], 0, 16) . ' to ' . substr((string) $r['end_utc'], 0, 16), $g];
    };
    $cases = [
        'title and time of its own (13:00 to 14:30)' => [['title' => 'Own title', 'start' => '2027-01-11T13:00:00-08:00', 'end' => '2027-01-11T14:30:00-08:00'], '2027-01-11 21:00 to 2027-01-11 22:30'],
        'title and a longer length of its own (10:00 to 12:30), the control' => [['title' => 'Own title', 'start' => '2027-01-11T10:00:00-08:00', 'end' => '2027-01-11T12:30:00-08:00'], '2027-01-11 18:00 to 2027-01-11 20:30'],
    ];
    foreach ($cases as $what => [$own, $want]) {
        scenario("a day with a $what, the series' zone changed to Denver at the same moments for all events", function () use ($db, $la, $at, $zone, $own, $when, $want, $what): void {
            $t = H::make($db, ['start' => $at, 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6']);
            try {
                H::this($t, '2027-01-11T10:00:00-08:00', $own);
                checkEq("$what: before the change the day is at its own time, here and at Google", [$want, $want], $when($t));
                [, $error] = H::tryChange($t, $zone);
                checkEq("$what: the change goes through, nothing half done, and Review has nothing to say", [null, [], 0],
                    [$error, H::halfDone($t), (int) $db->scalar('SELECT COUNT(*) FROM review_items WHERE user_id = ?', [$t->u])]);
                checkEq("$what: after it the day keeps its own time, here and at Google", [$want, $want], $when($t));
            } finally {
                H::done($t);
            }
        });
    }
};
