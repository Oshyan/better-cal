<?php

declare(strict_types=1);

// #271: a switch between timed and all-day left half done before 1.5.6 refused it, its series' own write not yet
// sent, is listed in Review saying up front that Finish can't complete it, why, and that Put back is the way out;
// Finish is not offered. 1.5.6's planner refuses such a switch before anything is sent, so no run of this tree
// leaves one half done: the pending row is written here as 1.5.5 left it (google_pending has no format number;
// the targets are GoogleSeries::targetOf's, the shape 1.5.5 journaled), one day's write sent and the series' own
// not. The rule the expectations come from: Review's sentence is the one Finish gives, so Finish, pressed anyway
// through the API, refuses with the same words. Invented data.

use BetterCal\Domain\GoogleSeries;
use BetterCal\Domain\Proposals;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Http\Controllers\ReviewController;
use BetterCal\Http\Request;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $tokyo = 'Asia/Tokyo';
    // Weekly at 09:00 Tokyo from Mon 4 Jan 2027: 00:00 UTC the same day, so each day's timed and all-day instants meet.
    $winter = ['start' => '2027-01-04T09:00:00+09:00', 'end' => '2027-01-04T10:00:00+09:00', 'tzid' => $tokyo, 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
    $controller = static fn(object $t): ReviewController => new ReviewController(new ReviewQueue($db, $t->e),
        new Proposals($db, $t->e, new BetterCal\Domain\Trips($db, $t->d->undo)), $t->d->calendars, null, null, null, $t->e);
    $halfItem = static function (object $t) use ($controller): ?array {
        $req = new Request('GET', '/review');
        $req->user = ['id' => $t->u];
        $req->authMethod = 'session';
        foreach (json_decode($controller($t)->index($req)->body, true)['items'] as $i) {
            if ($i['kind'] === 'google_half') {
                return $i;
            }
        }
        return null;
    };
    // The pending row 1.5.5 left: the series made all-day, steps as given.
    $leftBy155 = static function (object $t, array $steps) use ($db): int {
        $master = $db->one('SELECT * FROM events WHERE id = ?', [$t->id]);
        $rows = $db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$t->id]);
        $target = GoogleSeries::targetOf($master, $rows, ['master' => ['all_day' => 1, 'tzid' => 'UTC', 'start_utc' => '2027-01-04 00:00:00', 'end_utc' => '2027-01-05 00:00:00']]);
        return $db->insert('google_pending', [
            'user_id' => $t->u, 'calendar_id' => $t->s->calendarId, 'event_id' => $t->id, 'title' => 'Sample series',
            'summary' => 'Changed Sample series (all events): made all-day.', 'status' => 'half',
            'targets_json' => json_encode([$target]), 'befores_json' => json_encode([GoogleSeries::targetOf($master, $rows, [])]),
            'local_json' => '[]', 'plan_json' => null, 'steps_json' => json_encode($steps),
            'created_at' => Time::nowDb(), 'updated_at' => Time::nowDb(),
        ]);
    };
    $daySent = ['series' => 0, 'kind' => 'day', 'id' => 'x', 'key' => '2027-01-11 00:00:00', 'etag' => null, 'state' => 'done', 'back' => false, 'at' => '2027-01-01 00:00:00'];

    scenario('half-done switch to all-day that Finish would refuse: Review says so first, offers Put back alone, and Finish gives the same sentence', function () use ($db, $winter, $halfItem, $leftBy155, $daySent): void {
        $t = H::make($db, $winter);
        try {
            H::this($t, '2027-01-11T09:00:00+09:00', ['title' => 'Sample series (own)']);
            $leftBy155($t, [$daySent]);
            $item = $halfItem($t);
            $words = 'Google Calendar has a fault here: if this event became all-day at its current time, one of its days (Mon 11 Jan 2027) would stop showing there, though nothing deleted it. Put the change back instead; afterwards, move the event to another time first, then make it all-day.';
            checkEq('listed with Put back and Dismiss, no Finish', ['Put back', 'Dismiss'], array_column($item['actions'] ?? [], 'label'));
            check('its sentence ends with why Finish would be refused, and Put back as the way out', str_ends_with((string) ($item['summary'] ?? ''), ' ' . $words), (string) ($item['summary'] ?? ''));
            checkEq('the list Review reads carries the same sentence', $words, H::halfDone($t)[0]['finishRefused'] ?? null);
            [, $finish] = H::recover($t, 'finish');
            check('Finish, pressed through the API, is refused with that sentence', $finish !== null && str_contains($finish, 'google_hidden_days') && str_contains($finish, $words), (string) $finish);
        } finally {
            H::done($t);
        }
    });

    scenario('control: the same switch with nothing Google would hide, or with the series\' own write sent, offers Finish', function () use ($db, $winter, $halfItem, $leftBy155, $daySent): void {
        $t = H::make($db, $winter);
        try {
            $leftBy155($t, [$daySent]);
            checkEq('no edited or deleted day: Finish, Put back and Dismiss', ['Finish', 'Put back', 'Dismiss'], array_column($halfItem($t)['actions'] ?? [], 'label'));
        } finally {
            H::done($t);
        }
        $t = H::make($db, $winter);
        try {
            H::this($t, '2027-01-11T09:00:00+09:00', ['title' => 'Sample series (own)']);
            $leftBy155($t, [['kind' => 'master', 'id' => $t->gid, 'key' => null] + $daySent]);
            checkEq('the series\' own write sent: left to Finish, which reads Google', ['Finish', 'Put back', 'Dismiss'], array_column($halfItem($t)['actions'] ?? [], 'label'));
        } finally {
            H::done($t);
        }
    });
};
