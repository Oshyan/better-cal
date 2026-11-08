<?php

declare(strict_types=1);

// Undo on a Google calendar follows the local rules. A change made here
// alone to a Google event (a trip link, Planned) keeps its snapshots and
// undoes here, touching only what is held here and sending Google nothing;
// an entry recorded before that rule (no snapshots) still answers
// not_undoable. The bare Undo steps one change further back each time, as
// on a local calendar: an undo's own entry is passed over, and redo is that
// entry undone by its id. Against the probe's stand-in Google
// (tools/google-probe), invented data.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Trips;
use BetterCal\Domain\Undo;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/google-probe/lib/FakeGoogle.php';

return static function (Db $db): void {
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $tz = 'America/Los_Angeles';
    $la = new DateTimeZone($tz);
    $when = static fn(string $local): array => ['dateTime' => (new DateTimeImmutable($local, $la))->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
    $single = static fn(string $title): array => ['id' => 'smpl4single', 'iCalUID' => 'smpl4single@google.com', 'summary' => $title,
        'location' => 'Sample room 2', 'start' => $when('2027-03-30 15:00'), 'end' => $when('2027-03-30 16:00')];

    // A weekly series, Tuesdays 09:00 from 6 April 2027, eight of them, the
    // third with a title of its own (an edited day): for "this event" edits.
    $expand = static function (array $master): array {
        $zone = (string) ($master['start']['timeZone'] ?? 'UTC');
        $start = new DateTimeImmutable((string) $master['start']['dateTime']);
        $end = new DateTimeImmutable((string) $master['end']['dateTime']);
        $rrule = null;
        foreach ((array) ($master['recurrence'] ?? []) as $line) {
            if (str_starts_with((string) $line, 'RRULE:')) {
                $rrule = substr((string) $line, 6);
            }
        }
        if ($rrule === null) {
            return [];
        }
        $row = ['start_utc' => BetterCal\Support\Time::toDb($start), 'end_utc' => BetterCal\Support\Time::toDb($end), 'all_day' => 0, 'tzid' => $zone, 'rrule' => $rrule];
        return array_map(static fn(array $o): DateTimeImmutable => $o['start']->setTimezone(new DateTimeZone($zone)),
            Recurrence::occurrences($row, $start, $start->modify('+2 years'), null, 730));
    };
    $series = static function (FakeGoogle $fake) use ($when): void {
        $fake->seed(['id' => 'smpl4weekly', 'iCalUID' => 'smpl4weekly@google.com', 'summary' => 'Sample standup', 'description' => 'Sample notes',
            'start' => $when('2027-04-06 09:00'), 'end' => $when('2027-04-06 09:30'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=8']]);
        $fake->seed(['id' => 'smpl4weekly_20270420T160000Z', 'iCalUID' => 'smpl4weekly@google.com', 'recurringEventId' => 'smpl4weekly',
            'summary' => 'Sample standup, special', 'description' => 'Sample notes', 'originalStartTime' => $when('2027-04-20 09:00'),
            'start' => $when('2027-04-20 09:00'), 'end' => $when('2027-04-20 09:30')]);
    };

    $setup = static function () use ($db, $cfg, $single, $expand, $series): object {
        $u = freshUser($db);
        $d = domains($db);
        $fake = new FakeGoogle('local@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->expand = $expand;
        $fake->seed($single('Sample review'));
        $series($fake);
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
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample shared', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'local@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $t->calls = [];
        $local = (int) $d->calendars->create($u, ['name' => 'Sample plans'])['id'];
        $id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$calendarId, 'smpl4single']);
        $trip = (int) $events->create($u, ['calendarId' => $local, 'title' => 'Sample trip', 'start' => '2027-03-28T09:00:00-07:00',
            'end' => '2027-04-02T19:00:00-07:00', 'tzid' => 'America/Los_Angeles', 'isContainer' => true])['eventId'];
        return (object) ['u' => $u, 'fake' => $fake, 't' => $t, 'sync' => $sync, 'events' => $events, 'calendarId' => $calendarId, 'id' => $id, 'trip' => $trip,
            'undo' => new Undo($db, null, $writer), 'trips' => new Trips($db, $d->undo)];
    };
    $last = static fn(object $s): array => $db->one('SELECT * FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$s->u]);
    $links = static fn(object $s): int => (int) $db->scalar('SELECT COUNT(*) FROM event_links WHERE event_id = ?', [$s->id]);
    $row = static fn(object $s): array => $db->one('SELECT * FROM events WHERE id = ?', [$s->id]);
    $undone = static fn(int $entry): int => (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [$entry]);

    scenario('google local undo: a trip link on a Google event keeps its snapshots and is undone here, the link gone and Google sent nothing', function () use ($setup, $last, $links): void {
        $s = $setup();
        $s->trips->attach($s->u, $s->trip, $s->id);
        $entry = $last($s);
        checkEq('the link is made, its entry keeps snapshots', [1, true], [$links($s), $entry['before_json'] !== null && $entry['after_json'] !== null]);
        $s->undo->undoById($s->u, (int) $entry['id']);
        checkEq('undone by its entry: the link is gone, no call to Google', [0, []], [$links($s), $s->t->calls]);

        // An unlink undoes the same way, the link back.
        $s->trips->attach($s->u, $s->trip, $s->id);
        $s->trips->detach($s->u, $s->trip, $s->id);
        $s->undo->undoLatest($s->u);
        checkEq('an unlink undone by the bare Undo: the link is back, no call to Google', [1, []], [$links($s), $s->t->calls]);
    });

    scenario('google local undo: Planned on a Google event, undone after Google renamed it, gives back the answer only and writes nothing to Google', function () use ($db, $setup, $last, $row, $single, $undone): void {
        $s = $setup();
        $s->events->setAttendance($s->u, $s->id, 'going');
        $entry = $last($s);
        checkEq('Planned: the answer is set and the entry keeps snapshots', ['going', true], [$row($s)['attendance'], $entry['before_json'] !== null]);
        // Renamed at Google meanwhile, and polled in.
        $s->fake->seed($single('Sample review, moved room'));
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        checkEq('the poll brought the new title', 'Sample review, moved room', $row($s)['title']);
        $s->undo->undoLatest($s->u);
        $now = $row($s);
        checkEq('undone: the answer is back as it was, Google\'s title stays, Google sent nothing and still holds its title', [
            'none', 'Sample review, moved room', [], 'Sample review, moved room', 1,
        ], [$now['attendance'], $now['title'], $s->t->calls, $s->fake->events['smpl4single']['summary'] ?? null, $undone((int) $entry['id'])]);
    });

    scenario('google local undo: an entry recorded before the rule, with no snapshots, still answers not_undoable with its reason', function () use ($db, $setup): void {
        $s = $setup();
        $entry = $db->insert('mutations', ['user_id' => $s->u, 'entity' => 'event_links', 'entity_id' => $s->trip, 'op' => 'create',
            'before_json' => null, 'after_json' => null, 'source' => 'web', 'summary' => "Added 'Sample review' to 'Sample trip'"]);
        $message = null;
        try {
            $s->undo->undoById($s->u, $entry);
        } catch (HttpError $e) {
            $message = [$e->errorCode, str_contains($e->getMessage(), 'log-only')];
        }
        checkEq('by its entry: not_undoable, said to be log-only', ['not_undoable', true], $message);
        checkEq('the bare Undo: not_undoable too, nothing older undone instead', 'not_undoable', refusal(fn() => $s->undo->undoLatest($s->u)));
    });

    scenario('google local undo: two changes on a Google calendar, then two bare Undos, undo both (not undo then redo)', function () use ($setup, $row, $links, $undone): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, first']);
        $s->trips->attach($s->u, $s->trip, $s->id);
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, second']);
        $s->undo->undoLatest($s->u);
        checkEq('the first bare Undo takes back the second title, here and at Google', ['Sample review, first', 'Sample review, first', 1],
            [$row($s)['title'], $s->fake->events['smpl4single']['summary'] ?? null, $links($s)]);
        $s->undo->undoLatest($s->u);
        checkEq('the second takes back the trip link, not the undo just made', ['Sample review, first', 0], [$row($s)['title'], $links($s)]);
        $s->undo->undoLatest($s->u);
        checkEq('the third takes back the first title', ['Sample review', 'Sample review'], [$row($s)['title'], $s->fake->events['smpl4single']['summary'] ?? null]);
    });

    scenario('google local undo: a bare Undo after an Undo by entry steps to the older change; redo stays reachable by the undo\'s entry', function () use ($setup, $last, $row, $undone): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, first']);
        $first = (int) $last($s)['id'];
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, second']);
        $second = (int) $last($s)['id'];
        $s->undo->undoById($s->u, $second);
        $undoOfSecond = (int) $last($s)['id'];
        $s->undo->undoLatest($s->u);
        checkEq('the bare Undo takes back the first change, not the undo of the second', ['Sample review', 'Sample review', 1, 1, 0],
            [$row($s)['title'], $s->fake->events['smpl4single']['summary'] ?? null, $undone($first), $undone($second), $undone($undoOfSecond)]);

        // Redo by entry: the first's undo undone puts the first back, and the bare Undo then takes that redo back.
        $undoOfFirst = (int) $last($s)['id'];
        $s->undo->undoById($s->u, $undoOfFirst);
        checkEq('redo by the undo\'s entry puts the first title back', 'Sample review, first', $row($s)['title']);
        $s->undo->undoLatest($s->u);
        checkEq('and the bare Undo takes the redo back', ['Sample review', 'Sample review'], [$row($s)['title'], $s->fake->events['smpl4single']['summary'] ?? null]);
    });

    $tagsOf = static fn(object $s): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$s->id]), 'name');
    $count = static fn(object $s): int => (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$s->u]);

    scenario('google local undo: a PATCH of local fields alone on a Google event is one entry with snapshots, undone by entry and by the bare Undo, Google sent nothing', function () use ($setup, $last, $row, $tagsOf, $count): void {
        $s = $setup();
        $n = $count($s);
        $s->events->patch($s->u, $s->id, ['tagNames' => ['sample-tag'], 'reminders' => [['minutes' => 15]], 'isContainer' => true]);
        $entry = $last($s);
        checkEq('one entry, with snapshots; tag, reminder and trip flag set; no call to Google', [1, true, ['sample-tag'], '[{"minutes":15}]', 1, []],
            [$count($s) - $n, $entry['before_json'] !== null && $entry['after_json'] !== null, $tagsOf($s), $row($s)['reminders_json'], (int) $row($s)['is_container'], $s->t->calls]);
        $s->undo->undoById($s->u, (int) $entry['id']);
        checkEq('undone by its entry: no tag, no reminder, no trip flag, no call to Google', [[], null, 0, []],
            [$tagsOf($s), $row($s)['reminders_json'], (int) $row($s)['is_container'], $s->t->calls]);
        $s->events->patch($s->u, $s->id, ['tagNames' => ['sample-other']]);
        $s->undo->undoLatest($s->u);
        checkEq('a second one undone by the bare Undo', [[], []], [$tagsOf($s), $s->t->calls]);
    });

    scenario('google local undo: a mixed PATCH (title and tags) is one entry; its undo puts the title back at Google and the tags back here, and redo puts both forward', function () use ($setup, $last, $row, $tagsOf, $count): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['tagNames' => ['sample-kept']]);
        $n = $count($s);
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, renamed', 'tagNames' => ['sample-new']]);
        $entry = $last($s);
        checkEq('one entry for the edit, title at Google and tag here', [1, 'Sample review, renamed', ['sample-new']],
            [$count($s) - $n, $s->fake->events['smpl4single']['summary'] ?? null, $tagsOf($s)]);
        $s->undo->undoById($s->u, (int) $entry['id']);
        checkEq('undone: one entry more (the undo\'s own), the title back here and at Google, the tag back here', [2, 'Sample review', 'Sample review', ['sample-kept']],
            [$count($s) - $n, $row($s)['title'], $s->fake->events['smpl4single']['summary'] ?? null, $tagsOf($s)]);
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        checkEq('redo by the undo\'s entry: both forward again', ['Sample review, renamed', 'Sample review, renamed', ['sample-new']],
            [$row($s)['title'], $s->fake->events['smpl4single']['summary'] ?? null, $tagsOf($s)]);
    });

    scenario('google local undo: a PATCH of local fields that changes nothing records no entry', function () use ($setup, $count): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['tagNames' => ['sample-tag']]);
        $n = $count($s);
        $s->events->patch($s->u, $s->id, ['tagNames' => ['sample-tag']]);
        checkEq('no entry, no call to Google', [0, []], [$count($s) - $n, $s->t->calls]);
    });

    // Undoing an edit sends back only the fields that edit changed, each at
    // its value before, over the event as it is now, and redo the same: a
    // later edit of another field stays, here and at Google. A later edit
    // of the same field makes the entry stale, as on a local calendar.
    $remote = static fn(object $s, string $gid): array => [$s->fake->events[$gid]['summary'] ?? null, (string) ($s->fake->events[$gid]['description'] ?? '')];
    $local = static fn(int $id): array => array_values($db->one('SELECT title, description FROM events WHERE id = ?', [$id]) ?? []);
    $rowOf = static fn(object $s, string $gid): ?array => $db->one('SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, $gid]);

    scenario('google local undo: a description edit undone after a later title edit on a Google event keeps the later title here and at Google, and redo keeps it too', function () use ($setup, $last, $remote, $local): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['description' => 'Sample agenda']);
        $entry = (int) $last($s)['id'];
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, later']);
        $s->undo->undoById($s->u, $entry);
        checkEq('undone: no description, the later title kept, here and at Google', [['Sample review, later', null], ['Sample review, later', '']],
            [$local($s->id), $remote($s, 'smpl4single')]);
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        checkEq('redo by the undo\'s entry: the description back, the later title still kept', [['Sample review, later', 'Sample agenda'], ['Sample review, later', 'Sample agenda']],
            [$local($s->id), $remote($s, 'smpl4single')]);
    });

    scenario('google local undo: the same on an edited day of a Google series: the later title stays here and at Google, through undo and redo', function () use ($setup, $last, $remote, $local, $rowOf): void {
        $s = $setup();
        $gid = 'smpl4weekly_20270420T160000Z';
        $day = $rowOf($s, $gid);
        $masterId = (int) $rowOf($s, 'smpl4weekly')['id'];
        $at = '2027-04-20T16:00:00+00:00';
        $s->events->patch($s->u, $masterId, ['scope' => 'this', 'instanceStart' => $at, 'description' => 'Sample agenda']);
        $entry = (int) $last($s)['id'];
        $s->events->patch($s->u, $masterId, ['scope' => 'this', 'instanceStart' => $at, 'title' => 'Sample standup, moved room']);
        $s->undo->undoById($s->u, $entry);
        checkEq('undone: the description back, the later title kept, on the same row here and at Google', [
            (int) $day['id'], ['Sample standup, moved room', 'Sample notes'], ['Sample standup, moved room', 'Sample notes'],
        ], [(int) ($rowOf($s, $gid)['id'] ?? 0), $local((int) $day['id']), $remote($s, $gid)]);
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        checkEq('redo: the description forward again, the later title still kept', [['Sample standup, moved room', 'Sample agenda'], ['Sample standup, moved room', 'Sample agenda']],
            [$local((int) $day['id']), $remote($s, $gid)]);
    });

    scenario('google local undo: an edit of a Google event\'s field changed again since is stale, as on a local calendar, and Google is sent nothing', function () use ($setup, $last, $remote, $local): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['description' => 'Sample agenda, first']);
        $entry = (int) $last($s)['id'];
        $s->events->patch($s->u, $s->id, ['description' => 'Sample agenda, second']);
        $s->t->calls = [];
        checkEq('refused stale, the second description kept here and at Google, no call', [
            'stale_undo', ['Sample review', 'Sample agenda, second'], ['Sample review', 'Sample agenda, second'], [],
        ], [refusal(fn() => $s->undo->undoById($s->u, $entry)), $local($s->id), $remote($s, 'smpl4single'), $s->t->calls]);
    });

    $dayTags = static fn(int $masterId, string $key): array => array_column($db->all('SELECT t.name FROM events e JOIN event_tags et ON et.event_id = e.id JOIN tags t ON t.id = et.tag_id
        WHERE e.recurrence_parent_id = ? AND e.recurrence_instance_utc = ? AND e.deleted_at IS NULL ORDER BY t.name', [$masterId, $key]), 'name');
    $dayRow = static fn(int $masterId, string $key): ?array => $db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$masterId, $key]);

    scenario('google local undo: a tag given to an ordinary day of a Google series makes its row; undone, the row and tag are gone and Google is sent nothing', function () use ($db, $setup, $last, $rowOf, $dayTags, $dayRow): void {
        $s = $setup();
        $masterId = (int) $rowOf($s, 'smpl4weekly')['id'];
        $key = '2027-04-13 16:00:00';
        $s->events->patch($s->u, $masterId, ['scope' => 'this', 'instanceStart' => '2027-04-13T16:00:00+00:00', 'tagNames' => ['sample-day']]);
        $entry = $last($s);
        checkEq('the day has a row with the tag, the entry keeps it as made, and Google was sent nothing and holds no record of the day', [true, ['sample-day'], 0, 1, [], false],
            [$dayRow($masterId, $key) !== null, $dayTags($masterId, $key), count(json_decode((string) $entry['before_json'], true)['tables']['events'] ?? []),
                count(json_decode((string) $entry['after_json'], true)['tables']['events'] ?? []), $s->t->calls, isset($s->fake->events['smpl4weekly_20270413T160000Z'])]);
        // Polls keep the row: a delta, and a read of the whole calendar (no sync token), where Google lists no record of the day.
        $id = (int) $dayRow($masterId, $key)['id'];
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        $kept = (int) ($dayRow($masterId, $key)['id'] ?? 0);
        $db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$s->calendarId]);
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        checkEq('a delta poll and a whole read both keep the row and its tag', [$id, $id, ['sample-day']],
            [$kept, (int) ($dayRow($masterId, $key)['id'] ?? 0), $dayTags($masterId, $key)]);
        $s->t->calls = [];
        $s->undo->undoById($s->u, (int) $entry['id']);
        checkEq('undone: no row, no tag, an ordinary day again, no call to Google', [null, [], 0, []],
            [$dayRow($masterId, $key), $dayTags($masterId, $key),
                (int) $db->scalar('SELECT COUNT(*) FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE t.name = ?', ['sample-day']), $s->t->calls]);
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        checkEq('a poll after it keeps the day ordinary', null, $dayRow($masterId, $key));
    });

    scenario('google local undo: a title and a tag given to an ordinary day of a Google series in one edit are undone together: Google\'s day back to the series, no row here', function () use ($db, $setup, $last, $rowOf, $dayTags, $dayRow): void {
        $s = $setup();
        $masterId = (int) $rowOf($s, 'smpl4weekly')['id'];
        $key = '2027-04-13 16:00:00';
        $gid = 'smpl4weekly_20270413T160000Z';
        $s->events->patch($s->u, $masterId, ['scope' => 'this', 'instanceStart' => '2027-04-13T16:00:00+00:00', 'title' => 'Sample standup, offsite', 'tagNames' => ['sample-day']]);
        checkEq('the day has its title here and at Google, and the tag here', ['Sample standup, offsite', 'Sample standup, offsite', ['sample-day']],
            [$dayRow($masterId, $key)['title'] ?? null, $s->fake->events[$gid]['summary'] ?? null, $dayTags($masterId, $key)]);
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        checkEq('undone: Google\'s day has the series\' title, no row here, no tag', ['Sample standup', null, []],
            [$s->fake->events[$gid]['summary'] ?? null, $dayRow($masterId, $key), $dayTags($masterId, $key)]);
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        checkEq('a poll after it keeps the day ordinary', null, $dayRow($masterId, $key));
        // Redo: the day made again, its title at Google and here, the tag back.
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        checkEq('redo by the undo\'s entry: the title here and at Google, the tag back', ['Sample standup, offsite', 'Sample standup, offsite', ['sample-day']],
            [$dayRow($masterId, $key)['title'] ?? null, $s->fake->events[$gid]['summary'] ?? null, $dayTags($masterId, $key)]);
    });

    scenario('google local undo: an ordinary day given only a tag, then deleted, is skipped at Google and here, its row gone; a whole read keeps it skipped', function () use ($db, $setup, $rowOf, $dayRow): void {
        $s = $setup();
        $masterId = (int) $rowOf($s, 'smpl4weekly')['id'];
        $key = '2027-04-13 16:00:00';
        $s->events->patch($s->u, $masterId, ['scope' => 'this', 'instanceStart' => '2027-04-13T16:00:00+00:00', 'tagNames' => ['sample-day']]);
        $s->events->deleteEvent($s->u, $masterId, 'this', '2027-04-13T16:00:00+00:00');
        $skipped = static fn(): bool => in_array($key, BetterCal\Domain\Recurrence::decodeExdates($db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$masterId])), true);
        checkEq('deleted: no row, the day skipped here and cancelled at Google', [null, true, 'cancelled'],
            [$dayRow($masterId, $key), $skipped(), $s->fake->events['smpl4weekly_20270413T160000Z']['status'] ?? null]);
        $db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$s->calendarId]);
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        checkEq('a whole read after it: still no row, still skipped', [null, true], [$dayRow($masterId, $key), $skipped()]);
    });

    scenario('google local undo: a place given to an edited day that had none is cleared by its undo, here and at Google, and redo gives it back', function () use ($db, $setup, $last, $rowOf): void {
        $s = $setup();
        $gid = 'smpl4weekly_20270420T160000Z';
        $id = (int) $rowOf($s, $gid)['id'];
        $masterId = (int) $rowOf($s, 'smpl4weekly')['id'];
        $place = static fn(): array => [$db->scalar('SELECT location FROM events WHERE id = ?', [$id]), (string) ($s->fake->events[$gid]['location'] ?? '')];
        checkEq('no place to start with', [null, ''], $place());
        $s->events->patch($s->u, $masterId, ['scope' => 'this', 'instanceStart' => '2027-04-20T16:00:00+00:00', 'location' => 'Sample hall']);
        checkEq('the place here and at Google', ['Sample hall', 'Sample hall'], $place());
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        checkEq('undone: no place, here or at Google', [null, ''], $place());
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        checkEq('redo: the place back, here and at Google', ['Sample hall', 'Sample hall'], $place());
    });
};
