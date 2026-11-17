<?php

declare(strict_types=1);

// How an event came to exist here (events.created_via) is the source of the
// write that made it, whichever calendar it is on; 'feed' is only for an
// event that arrived from outside (a feed's or Google's poll). Every path
// that makes an event row is checked: a create on a calendar here and on a
// Google calendar (the editor, quick add, the API), a new repeating event
// on Google, a day of a repeating event on Google given its own title or
// only its own tags, a copy onto Google, an undone delete, and a poll. The
// "new" pill (isNew) follows from it: an event a person makes is never new.
// Against the probe's stand-in Google (tools/google-probe), invented data.

use BetterCal\Domain\ActivityContext;
use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Trips;
use BetterCal\Domain\Undo;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

require_once dirname(__DIR__, 3) . '/tools/google-probe/lib/FakeGoogle.php';

return static function (Db $db): void {
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $tz = 'America/Los_Angeles';
    $la = new DateTimeZone($tz);
    $iso = static fn(string $local): string => (new DateTimeImmutable($local, $la))->format('Y-m-d\TH:i:sP');
    $when = static fn(string $local): array => ['dateTime' => $iso($local), 'timeZone' => $tz];
    $expand = static function (array $master): array {
        $start = new DateTimeImmutable((string) $master['start']['dateTime']);
        $end = new DateTimeImmutable((string) $master['end']['dateTime']);
        $zone = (string) ($master['start']['timeZone'] ?? 'UTC');
        $rrule = null;
        foreach ((array) ($master['recurrence'] ?? []) as $line) {
            if (str_starts_with((string) $line, 'RRULE:')) {
                $rrule = substr((string) $line, 6);
            }
        }
        if ($rrule === null) {
            return [];
        }
        $row = ['start_utc' => Time::toDb($start), 'end_utc' => Time::toDb($end), 'all_day' => 0, 'tzid' => $zone, 'rrule' => $rrule];
        return array_map(static fn(array $o): DateTimeImmutable => $o['start']->setTimezone(new DateTimeZone($zone)), Recurrence::occurrences($row, $start, $start->modify('+30 years'), null, 730));
    };

    $setup = static function () use ($db, $cfg, $expand, $when): object {
        $u = freshUser($db);
        $d = domains($db);
        $fake = new FakeGoogle('made@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->expand = $expand;
        // Invented: weekly Mondays 10:00 Los Angeles from 1 March 2027, ten of them.
        $fake->seed(['id' => 'smpl9weekly', 'iCalUID' => 'smpl9weekly@google.com', 'summary' => 'Team sync', 'location' => 'Sample room 2',
            'start' => $when('2027-03-01 10:00'), 'end' => $when('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
        $inner = $fake->writerTransport();
        $transport = static fn(string $method, string $url, ?array $payload, array $headers = []): array
            => $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $writer = new GoogleWriter($db, $auth, $feeds, $transport);
        $syncTransport = $fake->syncTransport();
        $sync = new GoogleSync($db, $auth, $feeds, static fn(string $url, array $headers): array => $syncTransport($url, array_merge(['Authorization: Bearer sample-access'], $headers)));
        $events = new Events($db, new Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new Trips($db, $d->undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample shared', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'made@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $local = (int) $d->calendars->create($u, ['name' => 'Sample plans'])['id'];
        // Both calendars a day old: what is written now is past their first
        // load, so the "new" pill is decided by how the event came here alone.
        $db->run('UPDATE calendars SET created_at = ? WHERE user_id = ?', [Time::toDb(Time::nowUtc()->modify('-1 day')), $u]);
        return (object) ['u' => $u, 'd' => $d, 'fake' => $fake, 'sync' => $sync, 'events' => $events, 'calendarId' => $calendarId, 'local' => $local,
            'undo' => new Undo($db, null, $writer), 'when' => $when];
    };
    $via = static fn(int $id): string => (string) $db->scalar('SELECT created_via FROM events WHERE id = ?', [$id]);
    // What the list serves for the event: its "new" pill.
    $servedNew = static function (object $s, int $id): ?bool {
        $utc = new DateTimeZone('UTC');
        foreach ($s->events->window($s->u, new DateTimeImmutable('2027-02-01 00:00:00', $utc), new DateTimeImmutable('2027-06-01 00:00:00', $utc), null, null, true) as $o) {
            if ((int) ($o['eventId'] ?? 0) === $id) {
                return (bool) $o['isNew'];
            }
        }
        return null;
    };
    $master = static fn(object $s): array => $db->one("SELECT * FROM events WHERE calendar_id = ? AND uid = 'smpl9weekly@google.com' AND recurrence_parent_id IS NULL AND deleted_at IS NULL", [$s->calendarId]);

    scenario('created via: an event made in the app on a Google calendar is the app\'s, and not new', function () use ($db, $setup, $via, $servedNew, $iso): void {
        $s = $setup();
        $id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample dinner', 'start' => $iso('2027-03-03 18:00'),
            'end' => $iso('2027-03-03 19:00'), 'tzid' => 'America/Los_Angeles'])['eventId'];
        checkEq('created via: made in the app on a Google calendar is web', 'web', $via($id));
        checkEq('created via: the list does not show it as new', false, $servedNew($s, $id));
        $local = (int) $s->events->create($s->u, ['calendarId' => $s->local, 'title' => 'Sample lunch', 'start' => $iso('2027-03-03 12:00'),
            'end' => $iso('2027-03-03 13:00'), 'tzid' => 'America/Los_Angeles'])['eventId'];
        checkEq('created via: made in the app on a calendar here is web', 'web', $via($local));
    });

    scenario('created via: quick add and the API on a Google calendar', function () use ($setup, $via, $servedNew, $iso): void {
        $s = $setup();
        $make = fn(string $title) => (int) $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => $title, 'start' => $iso('2027-03-04 18:00'),
            'end' => $iso('2027-03-04 19:00'), 'tzid' => 'America/Los_Angeles'])['eventId'];
        $quick = ActivityContext::with('quickadd', fn() => $make('Sample picnic'));
        checkEq('created via: quick add on a Google calendar', 'quickadd', $via($quick));
        checkEq('created via: quick add is not new', false, $servedNew($s, $quick));
        $api = ActivityContext::with('api', fn() => $make('Sample errand'));
        checkEq('created via: the API on a Google calendar', 'api', $via($api));
        checkEq('created via: an agent\'s event is new, as on a calendar here', true, $servedNew($s, $api));
        $mail = ActivityContext::with('mail:markup', fn() => $make('Sample booking'));
        checkEq('created via: mail on a Google calendar', 'mail:markup', $via($mail));
    });

    scenario('created via: a repeating event made on Google, a day given its own title, a day given only a tag', function () use ($db, $setup, $via, $master, $iso): void {
        $s = $setup();
        $id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample practice', 'start' => $iso('2027-03-02 07:00'),
            'end' => $iso('2027-03-02 08:00'), 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=4'])['eventId'];
        checkEq('created via: a repeating event made on a Google calendar', 'web', $via($id));
        $m = $master($s);
        // A day given its own title: Google answers with the day, the sync stores it.
        $s->events->patch($s->u, (int) $m['id'], ['title' => 'Team sync, moved', 'scope' => 'this', 'instanceStart' => '2027-03-08T18:00:00+00:00']);
        $titled = $db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [(int) $m['id'], '2027-03-08 18:00:00']);
        checkEq('created via: a day row made by an edit on Google is the edit\'s', 'web', $titled === null ? null : (string) $titled['created_via']);
        // A day given only a tag: nothing goes to Google, the writer makes the row.
        ActivityContext::with('api', fn() => $s->events->patch($s->u, (int) $m['id'], ['tagNames' => ['sample-tag'], 'scope' => 'this', 'instanceStart' => '2027-03-15T17:00:00+00:00']));
        $tagged = $db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [(int) $m['id'], '2027-03-15 17:00:00']);
        checkEq('created via: a day row made by a tag only edit on Google is the edit\'s', 'api', $tagged === null ? null : (string) $tagged['created_via']);
        checkEq('created via: the repeating event polled from Google stays feed', 'feed', (string) $m['created_via']);
    });

    scenario('created via: a copy onto a Google calendar, an undone delete, a poll', function () use ($db, $setup, $via, $servedNew, $iso, $when): void {
        $s = $setup();
        $local = (int) $s->events->create($s->u, ['calendarId' => $s->local, 'title' => 'Sample visit', 'start' => $iso('2027-03-05 15:00'),
            'end' => $iso('2027-03-05 16:00'), 'tzid' => 'America/Los_Angeles'])['eventId'];
        $copy = (int) $s->events->copyTo($s->u, $local, $s->calendarId)['eventId'];
        checkEq('created via: a copy onto a Google calendar is the copy\'s', 'web', $via($copy));
        // Deleted and undone: the row comes back as it was made.
        $s->events->deleteEvent($s->u, $copy, null, null);
        $s->undo->undoById($s->u, lastEntry($db, $s->u));
        $back = $db->one("SELECT * FROM events WHERE calendar_id = ? AND title = 'Sample visit' AND deleted_at IS NULL", [$s->calendarId]);
        checkEq('created via: an undone delete brings the row back as made', 'web', $back === null ? null : (string) $back['created_via']);
        // An event added at Google and polled arrived from outside: feed, and new.
        $s->fake->seed(['id' => 'smpl9arrived', 'iCalUID' => 'smpl9arrived@google.com', 'summary' => 'Sample arrival',
            'start' => $when('2027-03-06 09:00'), 'end' => $when('2027-03-06 10:00')]);
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        $arrived = (int) $db->scalar("SELECT id FROM events WHERE calendar_id = ? AND uid = 'smpl9arrived@google.com'", [$s->calendarId]);
        checkEq('created via: an event polled from Google is feed', 'feed', $via($arrived));
        checkEq('created via: an event polled from Google is new', true, $servedNew($s, $arrived));
    });
};
