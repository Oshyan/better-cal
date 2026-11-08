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

    $setup = static function () use ($db, $cfg, $single): object {
        $u = freshUser($db);
        $d = domains($db);
        $fake = new FakeGoogle('local@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->seed($single('Sample review'));
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
};
