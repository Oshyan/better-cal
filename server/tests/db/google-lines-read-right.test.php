<?php

declare(strict_types=1);

// #281: lines that were true but read wrong. A calendar whose access changed is no failing feed (one voice for one
// state: the access line and the Review notice, never "started failing", "recovered" or a toast); a half-done change's
// Review sentence names no step and ends its reason with a full stop; a poll's Activity line counts events as a
// person does, a repeating event with its edited day as one. Invented data.

use BetterCal\Domain\AccessStateException;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\Proposals;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Http\Controllers\ReviewController;
use BetterCal\Http\Request;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $weekly = ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
    $lines = static fn(object $t): array => array_map('strval', array_column($db->all('SELECT summary FROM mutations WHERE user_id = ? ORDER BY id', [$t->u]), 'summary'));

    scenario('an access change is no failing feed: no streak, no "started failing" or "recovered" line, the poll recorded as one that reached Google', function () use ($db, $weekly, $lines): void {
        foreach (['freeBusyReader', 'none'] as $role) {
            $t = H::make($db, $weekly);
            try {
                $feeds = new Feeds($db);
                $t->s->fake->role = $role;
                foreach ([1, 2, 3] as $n) {
                    $caught = null;
                    try {
                        $t->s->poll();
                    } catch (\Throwable $e) {
                        $caught = $e;
                    }
                    check("$role, poll $n: the poll says the access state, as an access state", $caught instanceof AccessStateException, $caught === null ? 'nothing thrown' : get_class($caught) . ': ' . $caught->getMessage());
                    if ($caught !== null) {
                        $feeds->pollFailed($t->s->calendar(), $caught); // what Feeds::poll does with what the sync threw
                    }
                }
                $health = $db->one('SELECT status, consecutive_failures FROM system_health WHERE subject = ?', ['feed:' . $t->s->calendarId]);
                checkEq("$role: the feed's health is ok, no streak; the calendar's last poll ok", ['ok', 0, 'ok', null],
                    [$health['status'] ?? null, (int) ($health['consecutive_failures'] ?? -1), $t->s->calendar()['last_poll_status'], $t->s->calendar()['last_poll_error']]);
                checkEq("$role: no feed line in Activity", [], array_values(array_filter($lines($t), static fn(string $l): bool => str_contains($l, 'started failing') || str_contains($l, 'recovered after'))));
                // A real failure still counts, and its recovery is said as a person would say it.
                foreach ([1, 2] as $n) {
                    $feeds->pollFailed($t->s->calendar(), new \RuntimeException('Google Calendar could not be reached: connection timed out'));
                }
                $t->s->fake->role = 'owner';
                $feeds->pollSucceeded($t->s->calendar(), 0, 0);
                $said = array_values(array_filter($lines($t), static fn(string $l): bool => str_starts_with($l, 'Feed: ')));
                checkEq("$role, then a real failure twice and a success: started failing, then recovered after two failed attempts, no time under a minute", [
                    "Feed: Sample Google calendar started failing: Google Calendar could not be reached: connection timed out",
                    'Feed: Sample Google calendar recovered after 2 failed attempts',
                ], $said);
            } finally {
                H::done($t);
            }
        }
    });

    scenario('a half-done change in Review: its reason is a sentence of its own, no step number', function () use ($db, $weekly): void {
        $t = H::make($db, $weekly);
        try {
            // A change to all events stopped part way: its second write goes nowhere.
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            $t->s->knobs->netAt = $t->s->knobs->writes + 2;
            [, $error] = H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
            $t->s->knobs->down = false;
            check('the change stopped half done', str_contains((string) $error, 'half done'), (string) $error);
            $controller = new ReviewController(new ReviewQueue($db, $t->e), new Proposals($db, $t->e, new BetterCal\Domain\Trips($db, $t->d->undo)), $t->d->calendars, null, null, null, $t->e);
            $req = new Request('GET', '/review');
            $req->user = ['id' => $t->u];
            $req->authMethod = 'session';
            $item = array_values(array_filter(json_decode($controller->index($req)->body, true)['items'], static fn(array $i): bool => $i['kind'] === 'google_half'))[0] ?? [];
            $summary = (string) ($item['summary'] ?? '');
            $reason = rtrim((string) (H::halfDone($t)[0]['error'] ?? ''), '.');
            check('no step number, and the reason ends with its full stop before what Finish and Put back do',
                !str_contains($summary, 'at step') && $reason !== '' && str_contains($summary, ' ' . $reason . '. Finish sends the rest from what Google Calendar has now; Put back reverses every step done, only while nothing changed there since.'),
                $summary);
        } finally {
            H::done($t);
        }
    });

    scenario('a poll\'s Activity line counts a repeating event and its edited day as one event', function () use ($db, $weekly, $lines): void {
        $t = H::make($db, $weekly);
        try {
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            // Another event stays, so the listing after the delete is not an empty one (which is never applied).
            $t->e->create($t->u, ['calendarId' => $t->s->calendarId, 'title' => 'Sample single', 'start' => '2027-03-03T09:00:00-08:00', 'end' => '2027-03-03T10:00:00-08:00', 'tzid' => 'America/Los_Angeles']);
            $t->e->throwDeferred();
            [$status] = H::atGoogle($t, 'DELETE', '/events/' . rawurlencode($t->gid));
            checkEq('deleted elsewhere', 204, $status);
            $t->s->poll();
            $polled = array_values(array_filter($lines($t), static fn(string $l): bool => str_starts_with($l, 'From Google calendar ')));
            checkEq('one event removed, said as one', ["From Google calendar 'Sample Google calendar': 1 removed"], $polled);
        } finally {
            H::done($t);
        }
    });
};
