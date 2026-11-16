<?php

declare(strict_types=1);

// Whether an event takes up time (#303): one stored fact per event, events.show_as, 'busy' or 'free'. Read from every
// source through its mapping (a feed's or a file's TRANSP, a CalDAV client's TRANSP, Google's `transparency`), written
// to every outgoing form (an outgoing feed, CalDAV, Google on a write), changed in the app and undone like any field,
// compared on a Google calendar with what Google holds, the conflict choice included. An event made here on a Google
// calendar sends nothing for it, so Google's own default (busy) decides, and stores what Google answers. Google is the
// project's stand-in (tools/edit-harness/GoogleStand.php). Each block is a scenario(); every name is invented.

use BetterCal\Domain\CalendarImport;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\Ics;
use BetterCal\Domain\Labels;
use BetterCal\Domain\OutFeeds;
use BetterCal\Domain\Search;
use BetterCal\Domain\Settings;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\FeedCredentials;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $vevent = static fn(string $uid, string $title, string $day, string $transp = ''): string => "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20270101T000000Z\r\n"
        . "DTSTART;VALUE=DATE:$day\r\nDTEND;VALUE=DATE:" . date('Ymd', strtotime($day . ' +1 day')) . "\r\nSUMMARY:$title\r\n" . ($transp === '' ? '' : "TRANSP:$transp\r\n") . "END:VEVENT\r\n";
    $file = static fn(string $events): string => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Sample//Feed//EN\r\n{$events}END:VCALENDAR\r\n";
    $showAs = static fn(int $calendarId, string $uid): ?string => $db->scalar('SELECT show_as FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL', [$calendarId, $uid]);
    // What an outgoing feed of every event says of $uid: the TRANSP line in its VEVENT, or none.
    $outgoing = static function (int $u, string $uid) use ($db): ?string {
        $secret = str_repeat('s', 32);
        $token = 'sample-feed-token-' . bin2hex(random_bytes(8));
        $protected = FeedCredentials::protectOutboundToken($token, $secret);
        $db->insert('out_feeds', ['user_id' => $u, 'token' => $protected['hash'], 'token_sealed' => $protected['sealed'], 'name' => 'Sample outgoing', 'scope_json' => json_encode(['type' => 'all'])]);
        $body = (string) (new OutFeeds($db, new Search($db, new Labels($db)), ['base_url' => 'https://calendar.example.test', 'session_secret' => $secret]))->renderByToken($token);
        $unfolded = Ics::unfold($body);
        if (preg_match('/BEGIN:VEVENT\r\nUID:' . preg_quote($uid, '/') . '\r\n(.*?)END:VEVENT/s', $unfolded, $m) !== 1) {
            return 'no such event';
        }
        return preg_match('/^TRANSP:(.*)$/m', $m[1], $t) === 1 ? trim($t[1]) : null;
    };

    scenario('show as: a feed\'s TRANSP is read, and an outgoing feed passes it on', function () use ($db, $vevent, $file, $showAs, $outgoing): void {
        $u = freshUser($db);
        $cal = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample holidays', 'kind' => 'subscribed', 'color' => '#336699']);
        $sync = static fn(string $events) => (new Feeds($db))->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$cal]), Ics::parse($file($events), 'UTC'));
        $sync($vevent('sample-birthday', 'Sample birthday', '20270310', 'TRANSPARENT') . $vevent('sample-workshop', 'Sample workshop', '20270311', 'OPAQUE') . $vevent('sample-fair', 'Sample fair', '20270312'));
        checkEq('a feed\'s TRANSPARENT event is free; OPAQUE and none are busy',
            ['free', 'busy', 'busy'], [$showAs($cal, 'sample-birthday'), $showAs($cal, 'sample-workshop'), $showAs($cal, 'sample-fair')]);
        checkEq('an outgoing feed says TRANSPARENT for the free event and nothing for a busy one', ['TRANSPARENT', null], [$outgoing($u, 'sample-birthday'), $outgoing($u, 'sample-workshop')]);
        $sync($vevent('sample-birthday', 'Sample birthday', '20270310') . $vevent('sample-workshop', 'Sample workshop', '20270311', 'TRANSPARENT') . $vevent('sample-fair', 'Sample fair', '20270312'));
        checkEq('the feed changes its marking: the next poll stores it', ['busy', 'free'], [$showAs($cal, 'sample-birthday'), $showAs($cal, 'sample-workshop')]);
    });

    scenario('show as: a file imported and a CalDAV client\'s write carry it, and CalDAV serves it', function () use ($db, $vevent, $file, $showAs): void {
        $u = freshUser($db);
        $d = domains($db);
        $home = (int) Settings::defaultCalendarId($db, $u);
        (new CalendarImport($db, $d->calendars))->importIcs($u, $file($vevent('sample-imported', 'Sample day off', '20270315', 'TRANSPARENT')), 'ignored', null, $home);
        checkEq('an imported TRANSPARENT event is free', 'free', $showAs($home, 'sample-imported'));
        $token = (new BetterCal\Domain\ApiTokens($db))->create($u, 'Sample client');
        $backend = new BetterCal\Dav\CalendarBackend($db, new BetterCal\Domain\Undo($db), fn() => BetterCal\Domain\RequestAuthority::credential($u, (int) $token['id'], null));
        $backend->createCalendarObject($home, 'sample-phone.ics', $file($vevent('sample-phone', 'Sample hold', '20270316', 'TRANSPARENT')));
        $served = static fn(string $uid): string => (string) ($backend->getCalendarObject($home, $uid . '.ics')['calendardata'] ?? '');
        checkEq('a phone\'s TRANSPARENT event is free, and served back with TRANSPARENT', ['free', 1], [$showAs($home, 'sample-phone'), substr_count($served('sample-phone'), 'TRANSP:TRANSPARENT')]);
        $backend->updateCalendarObject($home, 'sample-phone.ics', $file($vevent('sample-phone', 'Sample hold', '20270316')));
        checkEq('the phone makes it busy: stored busy, served with no TRANSP', ['busy', 0], [$showAs($home, 'sample-phone'), substr_count($served('sample-phone'), 'TRANSP')]);
    });

    scenario('show as: set in the app, served by the API, undone; anything but busy or free is refused', function () use ($db, $showAs): void {
        $u = freshUser($db);
        $d = domains($db);
        $home = (int) Settings::defaultCalendarId($db, $u);
        $id = (int) $d->events->create($u, eventAt($home, 'Sample errands', '2027-03-17'))['eventId'];
        $uid = (string) $db->scalar('SELECT uid FROM events WHERE id = ?', [$id]);
        $record = static fn(): array => $d->events->serializeSingle($d->events->get($u, $id));
        checkEq('a new event is busy, and the record says so', ['busy', 'busy'], [$showAs($home, $uid), $record()['showAs'] ?? null]);
        $d->events->patch($u, $id, ['showAs' => 'free']);
        checkEq('marked free in the app: stored and served free', ['free', 'free'], [$showAs($home, $uid), $record()['showAs'] ?? null]);
        $d->undo->undoById($u, lastEntry($db, $u));
        checkEq('the change undone: busy again', 'busy', $showAs($home, $uid));
        $refused = null;
        try {
            $d->events->patch($u, $id, ['showAs' => 'tentative']);
        } catch (HttpError $e) {
            $refused = [$e->status, $e->errorCode];
        }
        checkEq('a value that is neither busy nor free is refused, nothing changed', [[400, 'invalid_show_as'], 'busy'], [$refused, $showAs($home, $uid)]);
        $made = (int) $d->events->create($u, eventAt($home, 'Sample reading', '2027-03-18', ['showAs' => 'free']))['eventId'];
        checkEq('an event made free is free', 'free', $db->scalar('SELECT show_as FROM events WHERE id = ?', [$made]));
    });

    scenario('show as on Google: read from Google, sent only when set, nothing sent for a new busy event', function () use ($db): void {
        $u = freshUser($db);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $t = (object) ['db' => $db, 'u' => $u, 'd' => $d, 's' => $s];
        $mark = static fn(): int => count($s->fake->requests);
        $sent = static fn(int $from): array => array_values(array_map(static fn(array $r): array => [$r['method'], $r['body']],
            array_filter(array_slice($s->fake->requests, $from), static fn(array $r): bool => !in_array('X-Harness: remote', $r['headers'], true))));
        $m = $mark();
        $id = (int) $s->events->create($u, eventAt($s->calendarId, 'Sample standup', '2027-03-03'))['eventId'];
        $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
        $inserts = array_values(array_filter($sent($m), static fn(array $r): bool => $r[0] === 'POST'));
        checkEq('a new busy event: the insert sends no transparency, Google holds none, the row is busy',
            [false, false, 'busy'], [array_key_exists('transparency', (array) ($inserts[0][1] ?? [])), array_key_exists('transparency', $s->fake->events[$gid]), $db->scalar('SELECT show_as FROM events WHERE id = ?', [$id])]);
        $m = $mark();
        $s->events->patch($u, $id, ['showAs' => 'free']);
        $s->events->throwDeferred();
        $patches = array_values(array_filter($sent($m), static fn(array $r): bool => $r[0] === 'PATCH'));
        checkEq('made free here: one PATCH that sends transparency alone as transparent, and Google holds it',
            [1, ['transparency' => 'transparent'], 'transparent'], [count($patches), $patches[0][1] ?? null, $s->fake->events[$gid]['transparency'] ?? null]);
        $m = $mark();
        $s->events->patch($u, $id, ['title' => 'Sample standup, moved']);
        $s->events->throwDeferred();
        $patches = array_values(array_filter($sent($m), static fn(array $r): bool => $r[0] === 'PATCH'));
        checkEq('a rename after it sends the title alone', [['summary']], array_map(static fn(array $p): array => array_keys((array) $p[1]), $patches));
        $m = $mark();
        $s->events->patch($u, $id, ['showAs' => 'busy']);
        $s->events->throwDeferred();
        $patches = array_values(array_filter($sent($m), static fn(array $r): bool => $r[0] === 'PATCH'));
        checkEq('made busy again: transparency sent as null, which clears it to Google\'s default', [['transparency' => null], false],
            [$patches[0][1] ?? null, isset($s->fake->events[$gid]['transparency'])]);
        H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($gid), ['transparency' => 'transparent']);
        $s->poll();
        checkEq('made free in Google Calendar: the next poll stores it', 'free', $db->scalar('SELECT show_as FROM events WHERE id = ?', [$id]));
        GoogleStand::forget($db, $u);
    });

    // Free or busy has two values, as Planned or Maybe does, so it never asks: a change at Google is either what the
    // person wants too (sent, no question) or one they did not touch (kept, and named in `kept`).
    scenario('show as on Google: compared with what Google holds, under the conflict choice', function () use ($db): void {
        $u = freshUser($db);
        conflictChoice($db, $u, true);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $t = (object) ['db' => $db, 'u' => $u, 'd' => $d, 's' => $s];
        $id = (int) $s->events->create($u, eventAt($s->calendarId, 'Sample sync', '2027-03-04'))['eventId'];
        $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
        $attempt = static function (array $in) use ($s, $u, $id): ?array {
            try {
                $s->events->patch($u, $id, $in);
                $s->events->throwDeferred();
            } catch (HttpError $e) {
                return [$e->status, $e->errorCode];
            }
            return null;
        };
        H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($gid), ['transparency' => 'transparent']);
        $asked = $attempt(['title' => 'Sample sync, longer', 'over' => ['title' => 'Sample sync']]);
        checkEq('made free in Google Calendar, renamed here: no question, the title sent, the free kept and named in `kept`',
            [null, 'Sample sync, longer', 'free', 'transparent', [['field' => 'showAs', 'now' => 'free']]],
            [$asked, $db->scalar('SELECT title FROM events WHERE id = ?', [$id]), $db->scalar('SELECT show_as FROM events WHERE id = ?', [$id]),
             $s->fake->events[$gid]['transparency'] ?? null, $s->events->kept()]);
        H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($gid), ['transparency' => null]);
        $asked = $attempt(['showAs' => 'busy', 'over' => ['showAs' => 'free']]);
        checkEq('shown free, made busy in Google Calendar and here: no question, busy everywhere', [null, 'busy', false],
            [$asked, $db->scalar('SELECT show_as FROM events WHERE id = ?', [$id]), isset($s->fake->events[$gid]['transparency'])]);
        GoogleStand::forget($db, $u);
    });
    // The owner's rule (2026-11-15): a new all-day event made here on a Google calendar is free, as Google Calendar's
    // own page makes it; a new timed one is busy and sends nothing; a choice made wins; elsewhere a new event is busy.
    // An existing event switched between timed and all-day keeps its value. The calendar serves the rule as newShowAs.
    scenario('show as: a new all-day event on a Google calendar is free unless the person says otherwise', function () use ($db): void {
        $u = freshUser($db);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $home = (int) Settings::defaultCalendarId($db, $u);
        $sentInsert = static function (int $from) use ($s): ?array {
            foreach (array_slice($s->fake->requests, $from) as $r) {
                if ($r['method'] === 'POST') {
                    return (array) $r['body'];
                }
            }
            return null;
        };
        $make = static function (int $calendarId, string $title, bool $allDay, array $more = []) use ($s, $u, $db, $sentInsert): array {
            $from = count($s->fake->requests);
            $in = ['calendarId' => $calendarId, 'title' => $title] + ($allDay
                ? ['start' => '2027-03-20T00:00:00+00:00', 'end' => '2027-03-21T00:00:00+00:00', 'allDay' => true, 'tzid' => 'UTC']
                : ['start' => '2027-03-20T18:00:00+00:00', 'end' => '2027-03-20T19:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC']) + $more;
            $id = (int) $s->events->create($u, $in)['eventId'];
            $insert = $sentInsert($from);
            return [$db->scalar('SELECT show_as FROM events WHERE id = ?', [$id]), $insert === null ? 'nothing sent' : ($insert['transparency'] ?? null), $id];
        };
        [$allDay, $allDaySent, $allDayId] = $make($s->calendarId, 'Sample offsite', true);
        [$timed, $timedSent] = $make($s->calendarId, 'Sample call', false);
        [$chosen, $chosenSent] = $make($s->calendarId, 'Sample conference', true, ['showAs' => 'busy']);
        [$local, $localSent] = $make($home, 'Sample holiday', true);
        checkEq('on Google: all-day free with transparent sent; timed busy with nothing; a choice of busy wins; a local all-day event busy',
            [['free', 'transparent'], ['busy', null], ['busy', null], ['busy', 'nothing sent']],
            [[$allDay, $allDaySent], [$timed, $timedSent], [$chosen, $chosenSent], [$local, $localSent]]);
        $served = [];
        foreach ($d->calendars->listAll($u)['calendars'] as $c) {
            $served[$c['id'] === $s->calendarId ? 'google' : ($c['id'] === $home ? 'home' : 'other')] = $c['newShowAs'];
        }
        checkEq('each calendar serves the rule the editor shows', [['timed' => 'busy', 'allDay' => 'free'], ['timed' => 'busy', 'allDay' => 'busy']],
            [$served['google'] ?? null, $served['home'] ?? null]);
        $s->events->patch($u, $allDayId, ['start' => '2027-03-20T18:00:00+00:00', 'end' => '2027-03-20T19:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC']);
        $s->events->throwDeferred();
        checkEq('the free all-day event made timed keeps free', 'free', $db->scalar('SELECT show_as FROM events WHERE id = ?', [$allDayId]));
        GoogleStand::forget($db, $u);
    });
    // x-agree (#303): a series' free or busy changed in Google Calendar, with an edited day Google keeps a record of;
    // a delta poll, then a whole read of the same calendar, change no row.
    scenario('show as: a delta poll and a whole read agree on a series and its edited day', function () use ($db): void {
        $t = H::make($db, ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=6']);
        H::this($t, '2027-03-08T18:00:00Z', ['title' => 'Sample series, renamed day']);
        H::this($t, '2027-03-15T17:00:00Z', ['tagNames' => ['sample-local']]);
        H::this($t, '2027-03-22T17:00:00Z', ['title' => 'Sample series, both', 'tagNames' => ['sample-both']]);
        H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid), ['transparency' => 'transparent']);
        H::pollChanges($t);
        $after = $db->all("SELECT recurrence_instance_utc AS k, show_as, override_fields FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY k", [$t->s->calendarId]);
        $db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$t->s->calendarId]);
        checkEq('a whole read after the delta poll changes no row', [], H::pollChanges($t));
        checkEq('after both reads: the series free; a day Google keeps a record of keeps that record\'s busy, and a day held here alone follows the series',
            [['k' => null, 'show_as' => 'free'], ['k' => '2027-03-08 18:00:00', 'show_as' => 'busy', 'own' => ['title']], ['k' => '2027-03-15 17:00:00', 'show_as' => 'free', 'own' => ['tags']],
             ['k' => '2027-03-22 17:00:00', 'show_as' => 'busy', 'own' => ['title', 'tags']]],
            array_map(static fn(array $r): array => ['k' => $r['k'], 'show_as' => $r['show_as']] + ($r['k'] === null ? [] : ['own' => json_decode((string) $r['override_fields'], true)]), $after));
        H::done($t);
    });
};
