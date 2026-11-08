<?php

declare(strict_types=1);

// What a Google event has here and Google doesn't hold (its local fields and
// relations), kept by every Google writer: a create keeps what the request
// set (the floating flag among them, from the request, a calendar's preset
// or quick add), and a delete's undo brings the row back under its id with
// every relation and column of its own (trip links, tags, reminders, the
// floating flag), as a local event's undo does; the undo undoes back.
// Against the probe's stand-in Google (tools/google-probe), invented data.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Trips;
use BetterCal\Domain\Undo;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Http\HttpError;
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
        $allDay = isset($master['start']['date']);
        $zone = $allDay ? 'UTC' : (string) ($master['start']['timeZone'] ?? 'UTC');
        $start = $allDay ? new DateTimeImmutable($master['start']['date'] . ' 00:00:00', new DateTimeZone('UTC')) : new DateTimeImmutable((string) $master['start']['dateTime']);
        $end = $allDay ? new DateTimeImmutable($master['end']['date'] . ' 00:00:00', new DateTimeZone('UTC')) : new DateTimeImmutable((string) $master['end']['dateTime']);
        $rrule = null;
        foreach ((array) ($master['recurrence'] ?? []) as $line) {
            if (str_starts_with((string) $line, 'RRULE:')) {
                $rrule = substr((string) $line, 6);
            }
        }
        if ($rrule === null) {
            return [];
        }
        $row = ['start_utc' => Time::toDb($start), 'end_utc' => Time::toDb($end), 'all_day' => $allDay ? 1 : 0, 'tzid' => $zone, 'rrule' => $rrule];
        return array_map(static fn(array $o): DateTimeImmutable => $o['start']->setTimezone(new DateTimeZone($zone)), Recurrence::occurrences($row, $start, $start->modify('+30 years'), null, 730));
    };

    // Invented: weekly Mondays 10:00 Los Angeles from 1 March 2027, ten of
    // them, with occurrence 5 (29 March) given a title of its own.
    $seed = static function (FakeGoogle $fake) use ($when): void {
        $fake->seed(['id' => 'smpl3weekly', 'iCalUID' => 'smpl3weekly@google.com', 'summary' => 'Sample weekly', 'location' => 'Sample room 1',
            'start' => $when('2027-03-01 10:00'), 'end' => $when('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
        $fake->seed(['id' => 'smpl3weekly_20270329T170000Z', 'iCalUID' => 'smpl3weekly@google.com', 'recurringEventId' => 'smpl3weekly', 'summary' => 'Sample weekly, special',
            'location' => 'Sample room 1', 'originalStartTime' => $when('2027-03-29 10:00'), 'start' => $when('2027-03-29 10:00'), 'end' => $when('2027-03-29 11:00')]);
    };

    $setup = static function () use ($db, $cfg, $expand, $seed): object {
        $u = freshUser($db);
        $d = domains($db);
        $fake = new FakeGoogle('field@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->expand = $expand;
        $seed($fake);
        $t = new class {
            /** @var list<array{0:string,1:string}> method, id */
            public array $calls = [];
        };
        $inner = $fake->writerTransport();
        $transport = static function (string $method, string $url, ?array $payload, array $headers = []) use ($t, $inner): array {
            $t->calls[] = [$method, rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)))];
            return $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
        };
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
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample field', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'field@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $t->calls = [];
        $local = $d->calendars->create($u, ['name' => 'Sample plans'])['id'];
        return (object) ['u' => $u, 'd' => $d, 'fake' => $fake, 't' => $t, 'sync' => $sync, 'events' => $events, 'calendarId' => $calendarId, 'local' => (int) $local,
            'writer' => $writer, 'undo' => new Undo($db, null, $writer), 'trips' => new Trips($db, $d->undo), 'labels' => new BetterCal\Domain\Labels($db)];
    };
    $byGoogleId = static fn(object $s, string $googleId): ?array => $db->one('SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, $googleId]);
    $last = static fn(object $s): array => $db->one('SELECT * FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$s->u]);
    $links = static fn(int $eventId): int => (int) $db->scalar('SELECT COUNT(*) FROM event_links WHERE event_id = ?', [$eventId]);
    $tags = static fn(int $eventId): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$eventId]), 'name');
    $methods = static fn(object $s): array => array_column($s->t->calls, 0);
    $trip = static fn(object $s): int => (int) $s->events->create($s->u, ['calendarId' => $s->local, 'title' => 'Sample trip', 'start' => '2027-03-27T09:00:00-07:00',
        'end' => '2027-04-02T19:00:00-07:00', 'tzid' => 'America/Los_Angeles', 'isContainer' => true])['eventId'];

    scenario('google local fields: an edited day in a trip, deleted and undone, comes back under its id with its link, tag and reminder; the undo undoes back', function () use ($db, $setup, $byGoogleId, $last, $links, $tags, $methods, $trip): void {
        $s = $setup();
        $dayGoogleId = 'smpl3weekly_20270329T170000Z';
        $day = $byGoogleId($s, $dayGoogleId);
        $id = (int) $day['id'];
        $s->trips->attach($s->u, $trip($s), $id);
        $s->labels->setEventTags($s->u, $id, ['sample-tag']);
        $db->run('UPDATE events SET reminders_json = ? WHERE id = ?', ['[{"minutes":30}]', $id]);
        $s->events->deleteEvent($s->u, $id, null, null);
        $entry = $last($s);
        $snapshot = json_decode((string) $entry['before_json'], true)['tables'] ?? [];
        checkEq('the delete went to Google; the row, its link and its tag are gone here and kept in the entry\'s snapshot', [
            'DELETE', null, 0, [$id], 1, 1,
        ], [$methods($s)[0] ?? null, $byGoogleId($s, $dayGoogleId), $links($id), array_map('intval', array_column($snapshot['events'] ?? [], 'id')),
            count($snapshot['event_links'] ?? []), count($snapshot['event_tags'] ?? [])]);

        $s->t->calls = [];
        $s->undo->undoById($s->u, (int) $entry['id']);
        $back = $byGoogleId($s, $dayGoogleId);
        $master = $byGoogleId($s, 'smpl3weekly');
        checkEq('undone: read, then one PATCH; the day is back under the same id, in the trip, with its tag, reminder and title, and no longer skipped', [
            ['GET', 'PATCH'], $id, 1, ['sample-tag'], '[{"minutes":30}]', 'Sample weekly, special', 'confirmed', [],
        ], [array_slice($methods($s), 0, 2), (int) ($back['id'] ?? 0), $links($id), $tags($id), $back['reminders_json'] ?? null, $back['title'] ?? null,
            $s->fake->events[$dayGoogleId]['status'] ?? null, Recurrence::decodeExdates($master['exdates_json'] ?? null)]);

        // The undo undoes back: deleted again, its parts kept again, and undone once more.
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        $again = $last($s);
        checkEq('undoing the undo deletes it again, link and all, and keeps them in its snapshot', [
            null, 0, 'cancelled', 1,
        ], [$byGoogleId($s, $dayGoogleId), $links($id), $s->fake->events[$dayGoogleId]['status'] ?? null,
            count(json_decode((string) $again['before_json'], true)['tables']['event_links'] ?? [])]);
        $s->undo->undoById($s->u, (int) $again['id']);
        checkEq('and undoing that brings it back once more under the same id, in the trip', [$id, 1, ['sample-tag']],
            [(int) ($byGoogleId($s, $dayGoogleId)['id'] ?? 0), $links($id), $tags($id)]);
    });

    scenario('google local fields: an ordinary day with only a tag and a reminder of its own, deleted and undone, keeps its row, tag and reminder', function () use ($db, $setup, $byGoogleId, $last, $tags): void {
        $s = $setup();
        $masterId = (int) $byGoogleId($s, 'smpl3weekly')['id'];
        $s->events->patch($s->u, $masterId, ['tagNames' => ['sample-tag'], 'reminders' => [['minutes' => 10]], 'scope' => 'this', 'instanceStart' => '2027-03-08T18:00:00+00:00']);
        $day = $db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$masterId, '2027-03-08 18:00:00']);
        $id = (int) ($day['id'] ?? 0);
        $s->events->deleteEvent($s->u, $id, null, null);
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        $back = $db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$masterId, '2027-03-08 18:00:00']);
        checkEq('the day had a row of its own; after the undo the same row is back with its tag and reminder', [true, $id, ['sample-tag'], '[{"minutes":10}]'],
            [$id > 0, (int) ($back['id'] ?? 0), $back !== null ? $tags((int) $back['id']) : [], $back['reminders_json'] ?? null]);
        // A poll after it keeps the row as it is.
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        checkEq('and a poll after it keeps that row', $id, (int) $db->scalar('SELECT id FROM events WHERE id = ? AND deleted_at IS NULL', [$id]));
    });

    scenario('google local fields: a day whose trip was deleted since comes back without that link, as a local event does (Undo::carriedLink)', function () use ($db, $setup, $byGoogleId, $last, $links, $trip): void {
        $s = $setup();
        $dayGoogleId = 'smpl3weekly_20270329T170000Z';
        $id = (int) $byGoogleId($s, $dayGoogleId)['id'];
        $tripId = $trip($s);
        $s->trips->attach($s->u, $tripId, $id);
        $s->events->deleteEvent($s->u, $id, null, null);
        $entry = $last($s);
        $db->run('DELETE FROM events WHERE id = ?', [$tripId]); // gone since, not through the journal
        $s->undo->undoById($s->u, (int) $entry['id'], true);
        checkEq('the day is back under its id at Google and here, with no link to the trip that is gone', [
            'confirmed', $id, 0, 1,
        ], [$s->fake->events[$dayGoogleId]['status'] ?? null, (int) ($byGoogleId($s, $dayGoogleId)['id'] ?? 0), $links($id),
            (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [(int) $entry['id']])]);
    });

    scenario('google local fields: a Google trip whose stop became a trip since refuses the undo before Google is sent a write', function () use ($db, $setup, $byGoogleId, $last, $methods, $links): void {
        $s = $setup();
        $made = $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample journey', 'start' => '2027-07-01T09:00:00-07:00', 'end' => '2027-07-05T18:00:00-07:00',
            'tzid' => 'America/Los_Angeles', 'isContainer' => true]);
        $tripId = (int) $made['eventId'];
        $googleId = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$tripId]);
        $stop = (int) $s->events->create($s->u, ['calendarId' => $s->local, 'title' => 'Sample stop', 'start' => '2027-07-02T12:00:00-07:00', 'end' => '2027-07-02T13:00:00-07:00',
            'tzid' => 'America/Los_Angeles'])['eventId'];
        $s->trips->attach($s->u, $tripId, $stop);
        $s->events->deleteEvent($s->u, $tripId, null, null);
        $entry = $last($s);
        $s->events->patch($s->u, $stop, ['isContainer' => true]); // a trip of its own since: a trip never contains another
        $s->t->calls = [];
        $caught = null;
        try {
            $s->undo->undoById($s->u, (int) $entry['id'], true);
        } catch (HttpError $e) {
            $caught = $e->status;
        }
        checkEq('made a trip on Google; its undo refused with only the read sent; the trip still deleted at Google and here; the entry still to undo', [
            1, 400, ['GET'], 'cancelled', null, 0, 0,
        ], [(int) $db->scalar('SELECT is_container FROM events WHERE id = ?', [$stop]) , $caught, $methods($s), $s->fake->events[$googleId]['status'] ?? null, $byGoogleId($s, $googleId),
            $links($stop), (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [(int) $entry['id']])]);
    });

    scenario('google local fields: a floating event made on Google keeps the flag, and a delete undone keeps it, under its id', function () use ($db, $setup, $byGoogleId, $last): void {
        $s = $setup();
        $made = $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample stretch', 'start' => '2027-06-01T10:00:00-07:00', 'end' => '2027-06-01T11:00:00-07:00',
            'tzid' => 'America/Los_Angeles', 'floating' => true, 'reminders' => [['minutes' => 15]]]);
        $id = (int) $made['eventId'];
        $row = $db->one('SELECT * FROM events WHERE id = ?', [$id]);
        checkEq('requested floating, stored floating, returned floating; the reminder kept too', [1, true, '[{"minutes":15}]'],
            [(int) $row['floating'], $made['floating'] ?? null, $row['reminders_json']]);

        $googleId = (string) $row['google_event_id'];
        $s->events->deleteEvent($s->u, $id, null, null);
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        $back = $byGoogleId($s, $googleId);
        checkEq('deleted and undone: back under the same id, floating, with its reminder', [$id, 1, '[{"minutes":15}]'],
            [(int) ($back['id'] ?? 0), (int) ($back['floating'] ?? 0), $back['reminders_json'] ?? null]);
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        checkEq('the undo undone and undone again: back once more, still floating', [$id, 1],
            [(int) ($byGoogleId($s, $googleId)['id'] ?? 0), (int) ($byGoogleId($s, $googleId)['floating'] ?? 0)]);
    });

    scenario('google local fields: a floating series made on Google, from the request and from the calendar\'s preset, keeps the flag', function () use ($db, $setup): void {
        $s = $setup();
        $series = $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample practice', 'start' => '2027-06-02T07:00:00-07:00', 'end' => '2027-06-02T07:30:00-07:00',
            'tzid' => 'America/Los_Angeles', 'floating' => true, 'rrule' => 'FREQ=DAILY;COUNT=5']);
        $s->d->calendars->patch($s->u, $s->calendarId, ['floatTimed' => true]);
        $preset = $s->events->create($s->u, $s->events->fromViewer($s->u, null, ['calendarId' => $s->calendarId, 'title' => 'Sample walk', 'allDay' => false,
            'start' => '2027-06-03T08:00:00-07:00', 'end' => '2027-06-03T09:00:00-07:00']));
        $presetSeries = $s->events->create($s->u, $s->events->fromViewer($s->u, null, ['calendarId' => $s->calendarId, 'title' => 'Sample swim', 'allDay' => false,
            'start' => '2027-06-04T06:00:00-07:00', 'end' => '2027-06-04T07:00:00-07:00', 'rrule' => 'FREQ=WEEKLY;COUNT=4']));
        $pinned = $s->events->create($s->u, $s->events->fromViewer($s->u, null, ['calendarId' => $s->calendarId, 'title' => 'Sample flight', 'allDay' => false, 'floating' => false,
            'start' => '2027-06-05T06:00:00-07:00', 'end' => '2027-06-05T07:00:00-07:00']));
        $s->d->calendars->patch($s->u, $s->calendarId, ['floatTimed' => false]);
        $flag = static fn(array $o): int => (int) $db->scalar('SELECT floating FROM events WHERE id = ?', [(int) $o['eventId']]);
        $google = static fn(array $o): bool => (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [(int) $o['eventId']]) !== '';
        checkEq('a series sent floating, an event and a series on a floating calendar float, stored and returned; one sent pinned stays pinned; each is Google\'s', [
            [1, 1, 1, 0], [true, true, true, null], [true, true, true, true],
        ], [[$flag($series), $flag($preset), $flag($presetSeries), $flag($pinned)],
            [$series['floating'] ?? null, $preset['floating'] ?? null, $presetSeries['floating'] ?? null, $pinned['floating'] ?? null],
            [$google($series), $google($preset), $google($presetSeries), $google($pinned)]]);
    });

    scenario('google local fields: a day of a floating Google series edited on its own floats with its series', function () use ($db, $setup): void {
        $s = $setup();
        $series = $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample practice', 'start' => '2027-06-07T07:00:00-07:00', 'end' => '2027-06-07T07:30:00-07:00',
            'tzid' => 'America/Los_Angeles', 'floating' => true, 'rrule' => 'FREQ=DAILY;COUNT=5']);
        $masterId = (int) $series['eventId'];
        $s->events->patch($s->u, $masterId, ['title' => 'Sample practice, longer', 'scope' => 'this', 'instanceStart' => '2027-06-09T14:00:00+00:00']);
        $day = $db->one('SELECT title, floating FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$masterId]);
        checkEq('the edited day is stored from Google\'s record with its own title and the series\' floating flag', ['Sample practice, longer', 1, 1],
            [$day['title'] ?? null, (int) ($day['floating'] ?? 0), (int) $db->scalar('SELECT floating FROM events WHERE id = ?', [$masterId])]);
    });

    scenario('google local fields: quick add onto a Google calendar keeps a floating draft, from the words and from the calendar\'s preset', function () use ($db, $setup): void {
        $s = $setup();
        $quick = new \BetterCal\Domain\QuickAdd($db, new \BetterCal\Infra\LlmGateway([]), $s->events, new Settings($db));
        // The Google calendar named in the text (/field), as quick add takes one.
        $words = $quick->run($s->u, 'Sample meditation tomorrow 7am floating /field', 'America/Los_Angeles', true, null);
        $s->d->calendars->patch($s->u, $s->calendarId, ['floatTimed' => true]);
        $preset = $quick->run($s->u, 'Sample stretch tomorrow 8am /field', 'America/Los_Angeles', true, null);
        $s->d->calendars->patch($s->u, $s->calendarId, ['floatTimed' => false]);
        $row = static fn(array $r): ?array => $db->one('SELECT calendar_id, floating, google_event_id FROM events WHERE id = ?', [(int) ($r['event']['eventId'] ?? 0)]);
        checkEq('the words\' draft floats; both were made on the Google calendar, stored floating and returned floating', [
            [true, true, true], [$s->calendarId, $s->calendarId], [1, 1], [true, true],
        ], [[(bool) ($words['draft']['floating'] ?? false), $words['event']['floating'] ?? null, $preset['event']['floating'] ?? null],
            [(int) ($row($words)['calendar_id'] ?? 0), (int) ($row($preset)['calendar_id'] ?? 0)],
            [(int) ($row($words)['floating'] ?? 0), (int) ($row($preset)['floating'] ?? 0)],
            [($row($words)['google_event_id'] ?? '') !== '', ($row($preset)['google_event_id'] ?? '') !== '']]);
    });

    scenario('google local fields: an entry recorded before 1.5.5 (a list of local parts, no snapshot) still puts its tags back', function () use ($db, $setup, $byGoogleId, $last, $tags): void {
        $s = $setup();
        $made = $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample review', 'start' => '2027-06-01T10:00:00-07:00', 'end' => '2027-06-01T11:00:00-07:00',
            'tzid' => 'America/Los_Angeles', 'tagNames' => ['sample-tag']]);
        $googleId = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [(int) $made['eventId']]);
        $tagId = (int) $db->scalar('SELECT tag_id FROM event_tags WHERE event_id = ?', [(int) $made['eventId']]);
        $s->events->deleteEvent($s->u, (int) $made['eventId'], null, null);
        $entry = $last($s);
        $details = json_decode((string) $entry['details_json'], true);
        $details['google']['local'] = ['tags' => [$tagId], 'people' => [], 'reminders' => null, 'trip' => 0, 'own' => null];
        $db->run('UPDATE mutations SET before_json = ?, details_json = ? WHERE id = ?', ['{"tables":[]}', json_encode($details), (int) $entry['id']]);
        $s->undo->undoById($s->u, (int) $entry['id']);
        $back = $byGoogleId($s, $googleId);
        checkEq('back from Google with the tag the old entry listed', ['Sample review', ['sample-tag']], [$back['title'] ?? null, $back !== null ? $tags((int) $back['id']) : []]);
    });
};
