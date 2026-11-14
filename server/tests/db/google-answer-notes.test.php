<?php

declare(strict_types=1);

// From the second independent read of the 1.5.6 fixes (its probe of finding F6, made a regular test): a write to a
// series that is not a change through GoogleSeries::run, an answer to all events of a repeating invitation
// (Rsvp::answerAtGoogle sends the series' guest list alone). The series is a repeating invitation with no notes, one
// day of it with notes of its own. Any write to a series with no notes clears the notes of the days Google keeps of
// it: six kinds of write in probes 3.52 to 3.57, and a write of one private property with no rule in it in 3.68 of
// 2026-11-14 (a write of the guest list alone was not probed; under the read-back rule it need not be). Expected: the
// day keeps its notes at Google and here, after the answer and after a poll. Before the rule
// (docs/design/google-series-read-back.md) the notes were gone at Google at once and here on the next poll, with
// nothing said. Invented data.

use BetterCal\Domain\Rsvp;
use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Support\Time;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    scenario('a repeating invitation with no notes, one day with its own notes, then "Yes" for all events', function () use ($db, $la): void {
        $t = H::make($db, ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=2']);
        try {
            // A weekly invitation from someone else, no notes on the series, one day with notes of its own (the organizer's).
            $account = (string) $db->scalar('SELECT email FROM google_accounts WHERE user_id = ?', [$t->u]);
            $when = static fn(string $local): array => ['dateTime' => (new DateTimeImmutable($local, new DateTimeZone('America/Los_Angeles')))->format('Y-m-d\TH:i:sP'), 'timeZone' => 'America/Los_Angeles'];
            $guests = ['organizer' => ['email' => 'host@example.test'], 'attendees' => [['email' => 'host@example.test', 'responseStatus' => 'accepted'], ['email' => $account, 'self' => true, 'responseStatus' => 'needsAction']]];
            $t->s->fake->seed(['id' => 'smplinv', 'iCalUID' => 'smplinv@google.com', 'summary' => 'Sample standup', 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6'], 'start' => $when('2027-01-04 10:00'), 'end' => $when('2027-01-04 10:30')] + $guests);
            $t->s->fake->seed(['id' => 'smplinv_20270111T180000Z', 'iCalUID' => 'smplinv@google.com', 'summary' => 'Sample standup', 'description' => 'Own notes for the day', 'recurringEventId' => 'smplinv',
                'originalStartTime' => $when('2027-01-11 10:00'), 'start' => $when('2027-01-11 10:00'), 'end' => $when('2027-01-11 10:30')] + $guests);
            $t->s->poll();
            $inv = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$t->s->calendarId, 'smplinv']);
            $atGoogle = static fn(object $t): ?string => $t->s->fake->events['smplinv_20270111T180000Z']['description'] ?? null;
            $here = static fn(object $t): ?string => $db->scalar('SELECT description FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$inv]);
            checkEq('before the answer the day has its notes at Google and here', ['Own notes for the day', 'Own notes for the day'], [$atGoogle($t), $here($t)]);
            $token = bin2hex(random_bytes(16));
            $db->insert('sessions', ['token_hash' => hash('sha256', $token), 'user_id' => $t->u, 'csrf' => 'x', 'authenticated_at' => Time::nowDb(), 'expires_at' => '2030-01-01 00:00:00']);
            $error = null;
            try {
                (new Rsvp($db, new EmailSender([]), [], $t->s->writer))->answer($t->u, $inv, 'accepted', $token);
            } catch (\Throwable $e) {
                $error = get_class($e) . ': ' . $e->getMessage();
            }
            checkEq('the answer is taken', null, $error);
            checkEq('right after the answer the day has its own notes, at Google and here', ['Own notes for the day', 'Own notes for the day'], [$atGoogle($t), $here($t)]);
            $t->s->poll();
            checkEq('after a poll the day still has its own notes, at Google and here', ['Own notes for the day', 'Own notes for the day'], [$atGoogle($t), $here($t)]);
        } finally {
            H::done($t);
        }
    });
};
