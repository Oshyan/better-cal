<?php

declare(strict_types=1);

// The last remaining day of a repeating event on Google deleted on its own (issue 272). Probe 12.4 (2026-11-17): a
// weekly series of three, two days deleted one by one, then the third deleted by its instance id; Google cancelled the
// event itself. The owner's ruling: nothing is built for it, because the poll then removes the event here, which is
// what deleting every day asks for. This pins that: each delete goes through as one day's delete, Google (the stand-in,
// answering as 12.4 found) holds the event cancelled, and after the next poll nothing of the event is left here and
// nothing is half done.
//
// Expectations by date from the probe and the ruling; nothing in server/src computes one. Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    scenario('google last day: a weekly series of three, each day deleted on its own, the last one cancels the event at Google and the poll removes it here', function () use ($db): void {
        $la = 'America/Los_Angeles';
        $t = H::make($db, ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=3']);
        try {
            $live = static fn(): int => (int) $db->scalar('SELECT COUNT(*) FROM events WHERE (id = ? OR recurrence_parent_id = ?) AND deleted_at IS NULL', [$t->id, $t->id]);
            $master = static fn(): ?string => $t->s->fake->events[$t->gid]['status'] ?? null;
            H::skip($t, '2027-03-01T10:00:00-08:00');
            H::skip($t, '2027-03-08T10:00:00-08:00');
            $t->s->poll();
            checkEq('two days deleted: the event is still live here and at Google', [1, 'confirmed'], [(int) ($live() > 0), $master()]);
            $error = null;
            try {
                H::skip($t, '2027-03-15T10:00:00-07:00');
            } catch (\Throwable $e) {
                $error = get_class($e) . ': ' . $e->getMessage();
            }
            checkEq('the last day\'s delete goes through as one day\'s delete', null, $error);
            checkEq('Google holds the event cancelled (12.4)', 'cancelled', $master());
            $t->s->poll();
            checkEq('after the next poll nothing of the event is left here', 0, $live());
            checkEq('and nothing is half done', [], H::halfDone($t));
        } finally {
            H::done($t);
        }
    });
};
