<?php

declare(strict_types=1);

// Slice 2 of the conflict choice, the request-level scenarios (docs/design/google-conflict-choice.md, the page "Slice 2:
// What a Change to One Day of a Repeating Event Promises"): a change or delete of one day of a repeating event on a
// Google calendar, over a change made in Google Calendar since the day was shown. One scenario per promise the page
// names. Each sends client-shaped requests through EventsController, as the router does, with `over` built from the
// occurrence the API serves in the window, as the app builds it, to the id the app sends it to (the repeating event's
// for an ordinary day, the day's own row's for an edited one) with scope this and the occurrence's start. Google is the
// project's stand-in; a change "in Google Calendar" is a request to it as another client makes it. Every expectation is
// written here from the page: nothing in server/src is called to compute one. Invented data throughout.
//
// The repeating event: 'Team sync', Wednesdays 18:00 to 19:00 UTC from 3 March 2027, six times, at Room 2.

use BetterCal\Domain\Auth;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\RequestAuthority;
use BetterCal\Domain\Rsvp;
use BetterCal\Domain\Trips;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\Controllers\EventsController;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Support\Time;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $fresh = static function (bool $on = true, ?array $create = null) use ($db): object {
        $u = freshUser($db);
        $settings = json_decode((string) $db->scalar('SELECT settings_json FROM users WHERE id = ?', [$u]), true) ?: [];
        $db->update('users', ['settings_json' => json_encode(['google_conflict_choice' => $on] + $settings)], 'id = ?', [$u]);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $id = (int) $s->events->create($u, $create !== null ? ['calendarId' => $s->calendarId] + $create
            : eventAt($s->calendarId, 'Team sync', '2027-03-03', ['location' => 'Room 2', 'rrule' => 'FREQ=WEEKLY;COUNT=6']))['eventId'];
        $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
        $token = 'sample-session-' . bin2hex(random_bytes(8));
        $db->insert('sessions', ['token_hash' => hash('sha256', $token), 'user_id' => $u, 'csrf' => str_repeat('c', 32), 'authenticated_at' => Time::nowDb(), 'expires_at' => '2099-01-01 00:00:00']);
        $controller = new EventsController($s->events, new Trips($db, $d->undo), new Rsvp($db, new EmailSender([]), [], $s->writer));
        return (object) ['db' => $db, 'u' => $u, 'd' => $d, 's' => $s, 'e' => $s->events, 'id' => $id, 'gid' => $gid, 'token' => $token, 'controller' => $controller];
    };

    // One request as the router answers it: [status, decoded body].
    $send = static function (object $t, string $action, int $id, array $body): array {
        $method = ['patch' => 'PATCH', 'delete' => 'DELETE', 'relationship' => 'POST', 'rsvp' => 'POST'][$action];
        $req = new Request($method, '/api/v1/events/' . $id . ($method === 'POST' ? "/$action" : ''), [], $body, [], [Auth::COOKIE => $t->token]);
        $req->user = ['id' => $t->u];
        $req->authMethod = 'session';
        try {
            $res = RequestAuthority::during(RequestAuthority::of($req), fn() => $t->controller->$action($req, ['id' => (string) $id]), true);
            $out = [$res->status, json_decode($res->body, true)];
        } catch (HttpError $e) {
            $out = [$e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra]];
        } finally {
            \BetterCal\Domain\ActivityContext::takeEntries();
        }
        return $out;
    };

    // The occurrence the window serves for the day that starts on $date, as the grid holds it.
    $occ = static function (object $t, string $date): array {
        $utc = new DateTimeZone('UTC');
        foreach ($t->e->window($t->u, new DateTimeImmutable('2027-02-25 00:00:00', $utc), new DateTimeImmutable('2027-04-20 00:00:00', $utc), [$t->s->calendarId], null, true) as $x) {
            if (str_starts_with((string) $x['start'], $date)) {
                return $x;
            }
        }
        throw new \RuntimeException("the window shows no day on $date");
    };
    $timeKeys = ['start', 'end', 'allDay', 'tzid'];
    $allKeys = ['title', 'description', 'location', 'url', 'status', 'start', 'end', 'allDay', 'tzid'];
    $over = static fn(array $occ, array $keys): array => array_intersect_key($occ, array_flip($keys));
    // A change or delete of the day $occ shows, as the app sends it: to the occurrence's event id, scope this, its start.
    $day = static fn(object $t, string $action, array $occ, array $body): array
        => $send($t, $action, (int) $occ['eventId'], ['scope' => 'this', 'instanceStart' => $occ['start']] + $body);
    $whole = static fn(string $s, string $e): array => ['start' => $s, 'end' => $e, 'allDay' => false, 'tzid' => 'UTC'];

    // A change in Google Calendar, as another client makes it: to the repeating event, or to one day by its key.
    $dayId = static fn(object $t, string $date): string => $t->gid . '_' . str_replace('-', '', $date) . 'T180000Z';
    $atGoogle = static function (object $t, string $id, array $body, string $method = 'PATCH'): void {
        [$status] = H::atGoogle($t, $method, '/events/' . rawurlencode($id), $method === 'DELETE' ? null : $body);
        if ($status !== 200 && $status !== 204) {
            throw new \RuntimeException("the stand-in refused the change in Google Calendar: $status");
        }
    };
    $googleTime = static fn(string $s, string $e): array => ['start' => ['dateTime' => $s, 'timeZone' => 'UTC'], 'end' => ['dateTime' => $e, 'timeZone' => 'UTC']];
    $mark = static fn(object $t): int => count($t->s->fake->requests);
    // The app's writes to the stand-in since $from: [method, body], reads and the other client's requests left out.
    $writes = static fn(object $t, int $from): array => array_values(array_map(static fn(array $r): array => [$r['method'], $r['body']],
        array_filter(array_slice($t->s->fake->requests, $from), static fn(array $r): bool => $r['method'] !== 'GET' && !in_array('X-Harness: remote', $r['headers'], true))));
    $methods = static fn(array $w): array => array_column($w, 0);
    $bodyKeys = static fn(?array $body): array => array_values(array_intersect(array_keys((array) $body), ['summary', 'description', 'location', 'source', 'status', 'start', 'end', 'attendees']));
    $atG = static fn(object $t, string $id): array => $t->s->fake->events[$id] ?? [];
    // What is stored here: the repeating event's row, and a day's own row by its date (null when it has none).
    $master = static fn(object $t): array => $db->one('SELECT * FROM events WHERE id = ?', [$t->id]);
    $dayRow = static fn(object $t, string $date): ?array => $db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$t->id, $date . ' 18:00:00']);
    $tagsOf = static fn(?array $r): array => $r === null ? [] : array_column($db->all('SELECT tg.name FROM event_tags et JOIN tags tg ON tg.id = et.tag_id WHERE et.event_id = ? ORDER BY tg.name', [(int) $r['id']]), 'name');
    $entries = static fn(object $t): int => (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$t->u]);
    $lastSource = static fn(object $t): ?string => $db->scalar('SELECT source FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$t->u]);
    $instants = static fn(?array $v): ?array => $v === null ? null : [strtotime((string) ($v['start'] ?? '')), strtotime((string) ($v['end'] ?? '')), (bool) ($v['allDay'] ?? false)];
    $at = static fn(string $s, string $e): array => [strtotime($s), strtotime($e), false];
    $keptOf = static fn(array $json): mixed => array_key_exists('kept', $json) ? $json['kept'] : 'no kept in the answer';
    // Overwrite as the page's (f) says: the same request again, over[field] its `now`, the body's value its `yours`.
    $overwrite = static function (array $body, array $json): array {
        foreach ((array) ($json['error']['fields'] ?? []) as $f) {
            foreach ($f['field'] === 'time' ? ['start', 'end', 'allDay', 'tzid'] : [$f['field']] as $k) {
                $body['over'][$k] = $f['field'] === 'time' ? ($f['now'][$k] ?? null) : $f['now'];
                if ($f['yours'] !== null && array_key_exists($k, $body)) {
                    $body[$k] = $f['field'] === 'time' ? ($f['yours'][$k] ?? null) : $f['yours'];
                }
            }
        }
        return $body;
    };

    // Case 1.
    scenario('unchanged repeating event: an edit, a drag, Maybe and a delete of a day each send one write; `kept` is empty', function () use ($fresh, $day, $occ, $over, $timeKeys, $allKeys, $whole, $mark, $writes, $methods, $keptOf, $dayRow, $master): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, ['title' => 'Weekly sync', 'over' => $over($o, ['title'])]);
        checkEq('edit: 200, one PATCH, nothing kept, the day holds its title', [200, ['PATCH'], [], 'Weekly sync'], [$status, $methods($writes($t, $m)), $keptOf($json), $dayRow($t, '2027-03-10')['title'] ?? null]);
        $o = $occ($t, '2027-03-17');
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, $whole('2027-03-18T18:00:00+00:00', '2027-03-18T19:00:00+00:00') + ['over' => $over($o, $timeKeys)]);
        checkEq('drag: 200, one PATCH, nothing kept, the day at its new time', [200, ['PATCH'], [], '2027-03-18 18:00:00'], [$status, $methods($writes($t, $m)), $keptOf($json), $dayRow($t, '2027-03-17')['start_utc'] ?? null]);
        $o = $occ($t, '2027-03-24');
        $m = $mark($t);
        [$status, $json] = $day($t, 'relationship', $o, ['relationship' => 'maybe', 'over' => $over($o, ['status'])]);
        checkEq('Maybe: 200, one PATCH, nothing kept, the day tentative', [200, ['PATCH'], [], 'tentative'], [$status, $methods($writes($t, $m)), $keptOf($json), $dayRow($t, '2027-03-24')['status'] ?? null]);
        $o = $occ($t, '2027-03-31');
        $m = $mark($t);
        [$status] = $day($t, 'delete', $o, ['over' => $over($o, $allKeys)]);
        checkEq('delete: 200, one DELETE, the day skipped here', [200, ['DELETE'], true], [$status, $methods($writes($t, $m)), str_contains((string) $master($t)['exdates_json'], '2027-03-31 18:00:00')]);
        H::done($t);
    });

    // Case 2 (the refusal this slice replaces: someone renaming another Wednesday refused a drag of this one).
    scenario('another day renamed in Google Calendar: a drag of this day goes through, nothing asked, nothing kept, the other day holds its new title here', function () use ($fresh, $day, $occ, $over, $timeKeys, $whole, $atGoogle, $dayId, $mark, $writes, $methods, $bodyKeys, $keptOf, $dayRow): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-17'), ['summary' => 'Offsite']);
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, $whole('2027-03-11T18:00:00+00:00', '2027-03-11T19:00:00+00:00') + ['over' => $over($o, $timeKeys)]);
        $w = $writes($t, $m);
        checkEq('200; one PATCH of the time alone; nothing kept', [200, ['PATCH'], ['start', 'end'], []], [$status, $methods($w), $bodyKeys($w[0][1] ?? null), $keptOf($json)]);
        checkEq('this day is at its new time, and the other day holds its new title here', ['2027-03-11 18:00:00', 'Offsite'], [$dayRow($t, '2027-03-10')['start_utc'] ?? null, $dayRow($t, '2027-03-17')['title'] ?? null]);
        H::done($t);
    });

    // Case 3.
    scenario('the repeating event renamed in Google Calendar, this day dragged: the PATCH sends the time alone, `kept` names the title', function () use ($fresh, $day, $occ, $over, $timeKeys, $whole, $atGoogle, $mark, $writes, $methods, $bodyKeys, $keptOf, $dayRow, $master): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $t->gid, ['summary' => 'Standup']);
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, $whole('2027-03-11T18:00:00+00:00', '2027-03-11T19:00:00+00:00') + ['over' => $over($o, $timeKeys)]);
        $w = $writes($t, $m);
        checkEq('200; one PATCH of the time alone; `kept` the title with its value now', [200, ['PATCH'], ['start', 'end'], [['field' => 'title', 'now' => 'Standup']]],
            [$status, $methods($w), $bodyKeys($w[0][1] ?? null), $keptOf($json)]);
        checkEq('the repeating event holds its new title here, and the day its new time under that title', ['Standup', ['Standup', '2027-03-11 18:00:00']],
            [$master($t)['title'], [$dayRow($t, '2027-03-10')['title'] ?? null, $dayRow($t, '2027-03-10')['start_utc'] ?? null]]);
        H::done($t);
    });

    // Case 4, the store's journal entry, and (k): nothing of the request is written.
    scenario('the repeating event renamed, this day renamed here: 409 `google_changed` with `from` series, nothing sent, the rows hold Google Calendar\'s title', function () use ($fresh, $day, $occ, $over, $atGoogle, $mark, $writes, $instants, $at, $dayRow, $master, $entries, $lastSource): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $t->gid, ['summary' => 'Standup']);
        $n = $entries($t);
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, ['title' => 'Weekly sync', 'tagNames' => ['sample-tag'], 'over' => $over($o, ['title'])]);
        $e = $json['error'] ?? [];
        checkEq('409 google_changed: the title with was, now, yours and from series; the day as it was shown; nothing kept',
            [409, 'google_changed', [['field' => 'title', 'was' => 'Team sync', 'now' => 'Standup', 'yours' => 'Weekly sync', 'from' => 'series']], $at('2027-03-10T18:00:00+00:00', '2027-03-10T19:00:00+00:00'), []],
            [$status, $e['code'] ?? null, $e['fields'] ?? null, $instants($e['day'] ?? null), $e['kept'] ?? null]);
        checkEq('the message is whole on its own, and names the day and the whole repeating event',
            "Since Wed, Mar 10, 2027 of 'Team sync' was last shown to you, its title was changed in Google Calendar, for the whole repeating event. It is now 'Standup' there; your change makes it 'Weekly sync'. It wasn't changed; open that day to see it and make the change again.",
            $e['message'] ?? null);
        checkEq('nothing written to Google; the repeating event holds Google Calendar\'s title here; the day has no row and no tag', [[], 'Standup', null], [$writes($t, $m), $master($t)['title'], $dayRow($t, '2027-03-10')]);
        checkEq('the read was stored as a poll stores it: one journal entry, from Google', [$n + 1, 'google'], [$entries($t), $lastSource($t)]);
        H::done($t);
    });

    // Case 5, and Overwrite.
    scenario('this day moved in Google Calendar, dragged here: 409 about the time with `from` day', function () use ($fresh, $day, $occ, $over, $timeKeys, $whole, $atGoogle, $dayId, $googleTime, $mark, $writes, $instants, $at, $dayRow): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-10'), $googleTime('2027-03-10T20:00:00+00:00', '2027-03-10T21:00:00+00:00'));
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, $whole('2027-03-11T18:00:00+00:00', '2027-03-11T19:00:00+00:00') + ['over' => $over($o, $timeKeys)]);
        $e = $json['error'] ?? [];
        $f = $e['fields'][0] ?? [];
        checkEq('409 google_changed about the time alone, from day: was the time shown, now Google Calendar\'s, yours the dragged time; nothing written; the day holds Google Calendar\'s time here',
            [409, 'google_changed', ['time'], 'day', $at('2027-03-10T18:00:00+00:00', '2027-03-10T19:00:00+00:00'), $at('2027-03-10T20:00:00+00:00', '2027-03-10T21:00:00+00:00'), $at('2027-03-11T18:00:00+00:00', '2027-03-11T19:00:00+00:00'), [], '2027-03-10 20:00:00'],
            [$status, $e['code'] ?? null, array_column($e['fields'] ?? [], 'field'), $f['from'] ?? null, $instants($f['was'] ?? null), $instants($f['now'] ?? null), $instants($f['yours'] ?? null), $writes($t, $m), $dayRow($t, '2027-03-10')['start_utc'] ?? null]);
        H::done($t);
    });
    scenario('Overwrite after the question for a day: one PATCH, the rows hold your time', function () use ($fresh, $send, $occ, $over, $timeKeys, $whole, $atGoogle, $dayId, $googleTime, $mark, $writes, $methods, $keptOf, $dayRow, $atG, $overwrite): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-10'), $googleTime('2027-03-10T20:00:00+00:00', '2027-03-10T21:00:00+00:00'));
        $body = ['scope' => 'this', 'instanceStart' => $o['start']] + $whole('2027-03-11T18:00:00+00:00', '2027-03-11T19:00:00+00:00') + ['over' => $over($o, $timeKeys)];
        [$status, $json] = $send($t, 'patch', (int) $o['eventId'], $body);
        checkEq('the question first', 409, $status);
        $m = $mark($t);
        // The same request again: same path, scope and instanceStart.
        [$status, $json] = $send($t, 'patch', (int) $o['eventId'], $overwrite($body, $json));
        checkEq('Overwrite: 200, one PATCH, nothing kept; Google Calendar and the day here hold your time', [200, ['PATCH'], [], strtotime('2027-03-11T18:00:00+00:00'), '2027-03-11 18:00:00'],
            [$status, $methods($writes($t, $m)), $keptOf($json), strtotime((string) ($atG($t, $t->gid . '_20270310T180000Z')['start']['dateTime'] ?? '')), $dayRow($t, '2027-03-10')['start_utc'] ?? null]);
        H::done($t);
    });

    // Case 6.
    scenario('this day\'s place changed in Google Calendar, its title changed here: `kept` names the place', function () use ($fresh, $day, $occ, $over, $atGoogle, $dayId, $mark, $writes, $methods, $bodyKeys, $keptOf, $dayRow): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-10'), ['location' => 'Sample Hall']);
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, ['title' => 'Weekly sync', 'over' => $over($o, ['title'])]);
        $w = $writes($t, $m);
        checkEq('200; one PATCH of the title alone; `kept` the place; the day holds both', [200, ['PATCH'], ['summary'], [['field' => 'location', 'now' => 'Sample Hall']], ['Weekly sync', 'Sample Hall']],
            [$status, $methods($w), $bodyKeys($w[0][1] ?? null), $keptOf($json), [$dayRow($t, '2027-03-10')['title'] ?? null, $dayRow($t, '2027-03-10')['location'] ?? null]]);
        H::done($t);
    });

    // Case 7.
    scenario('the same title for this day in Google Calendar and here: no question', function () use ($fresh, $day, $occ, $over, $atGoogle, $dayId, $mark, $writes, $keptOf, $dayRow): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-10'), ['summary' => 'Weekly sync']);
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, ['title' => 'Weekly sync', 'over' => $over($o, ['title'])]);
        checkEq('200, nothing left to send so nothing written to Google, nothing kept, the day holds the title', [200, [], [], 'Weekly sync'],
            [$status, $writes($t, $m), $keptOf($json), $dayRow($t, '2027-03-10')['title'] ?? null]);
        H::done($t);
    });

    // Case 8.
    scenario('poll between serve and save for a day: the request echoes the served title in `over`, the save asks', function () use ($fresh, $day, $occ, $over, $atGoogle, $dayId, $mark, $writes, $dayRow): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-10'), ['summary' => 'Standup']);
        $t->s->poll();
        checkEq('the poll stored Google Calendar\'s title for the day', 'Standup', $dayRow($t, '2027-03-10')['title'] ?? null);
        $m = $mark($t);
        // The grid still holds the occurrence it was served: the repeating event's id, the day's start.
        [$status, $json] = $day($t, 'patch', $o, ['title' => 'Weekly sync', 'over' => $over($o, ['title'])]);
        $e = $json['error'] ?? [];
        checkEq('409 google_changed about the title, was the served title, from day (the poll had stored it); nothing written',
            [409, 'google_changed', [['field' => 'title', 'was' => 'Team sync', 'now' => 'Standup', 'yours' => 'Weekly sync', 'from' => 'day']], []],
            [$status, $e['code'] ?? null, $e['fields'] ?? null, $writes($t, $m)]);
        H::done($t);
    });

    // Case 9.
    scenario('delete of a day over a change to its place asks', function () use ($fresh, $day, $occ, $over, $allKeys, $atGoogle, $dayId, $mark, $writes, $dayRow, $master): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-10'), ['location' => 'Sample Hall']);
        $m = $mark($t);
        [$status, $json] = $day($t, 'delete', $o, ['over' => $over($o, $allKeys)]);
        $e = $json['error'] ?? [];
        checkEq('409 google_changed, the place alone, yours null, from day', [409, 'google_changed', [['field' => 'location', 'was' => 'Room 2', 'now' => 'Sample Hall', 'yours' => null, 'from' => 'day']]],
            [$status, $e['code'] ?? null, $e['fields'] ?? null]);
        checkEq('the message is whole on its own',
            "Since Wed, Mar 10, 2027 of 'Team sync' was last shown to you, its location was changed in Google Calendar. It was 'Room 2'; it is now 'Sample Hall'. It wasn't deleted; open that day to see it, and delete it again if you still mean to.",
            $e['message'] ?? null);
        checkEq('nothing written to Google; the day lives here with Google Calendar\'s place, and is not skipped', [[], 'Sample Hall', false],
            [$writes($t, $m), $dayRow($t, '2027-03-10')['location'] ?? null, str_contains((string) $master($t)['exdates_json'], '2027-03-10 18:00:00')]);
        H::done($t);
    });
    scenario('Delete after the question deletes the day', function () use ($fresh, $send, $occ, $over, $allKeys, $atGoogle, $dayId, $mark, $writes, $methods, $dayRow, $master, $atG, $overwrite): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-10'), ['location' => 'Sample Hall']);
        $body = ['scope' => 'this', 'instanceStart' => $o['start'], 'over' => $over($o, $allKeys)];
        [$status, $json] = $send($t, 'delete', (int) $o['eventId'], $body);
        checkEq('the question first', 409, $status);
        $m = $mark($t);
        [$status] = $send($t, 'delete', (int) $o['eventId'], $overwrite($body, $json));
        checkEq('Delete: 200, one DELETE; the day cancelled in Google Calendar, skipped here with no row', [200, ['DELETE'], 'cancelled', true, null],
            [$status, $methods($writes($t, $m)), $atG($t, $t->gid . '_20270310T180000Z')['status'] ?? 'no record', str_contains((string) $master($t)['exdates_json'], '2027-03-10 18:00:00'), $dayRow($t, '2027-03-10')]);
        H::done($t);
    });
    scenario('delete of a day when another day changed sends one DELETE', function () use ($fresh, $day, $occ, $over, $allKeys, $atGoogle, $dayId, $mark, $writes, $methods, $dayRow, $master): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-17'), ['summary' => 'Offsite']);
        $m = $mark($t);
        [$status] = $day($t, 'delete', $o, ['over' => $over($o, $allKeys)]);
        checkEq('200, one DELETE; this day skipped here, the other day with its new title', [200, ['DELETE'], true, 'Offsite'],
            [$status, $methods($writes($t, $m)), str_contains((string) $master($t)['exdates_json'], '2027-03-10 18:00:00'), $dayRow($t, '2027-03-17')['title'] ?? null]);
        H::done($t);
    });

    // Case 10.
    // Google Calendar writes a repeating event's new title into every day's record, a day's own title replaced: the day
    // shows the new value, so it is a change to this day.
    scenario('the repeating event renamed, a day that had its own title: its place changed here keeps the title, its title changed here asks', function () use ($fresh, $day, $occ, $over, $atGoogle, $mark, $writes, $methods, $bodyKeys, $keptOf, $dayRow, $master): void {
        $own = static function () use ($fresh, $day, $occ, $atGoogle): array {
            $t = $fresh();
            // Its own title and its own time (20:00), so Google Calendar's record of it stays a record of its own.
            [$status] = $day($t, 'patch', $occ($t, '2027-03-10'), ['title' => 'Planning day', 'start' => '2027-03-10T20:00:00+00:00', 'end' => '2027-03-10T21:00:00+00:00']);
            checkEq('the day is given its own title and time first', 200, $status);
            $o = $occ($t, '2027-03-10');
            $atGoogle($t, $t->gid, ['summary' => 'Standup']);
            return [$t, $o];
        };
        [$t, $o] = $own();
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, ['location' => 'Room 5', 'over' => $over($o, ['location'])]);
        $w = $writes($t, $m);
        checkEq('the place changed here: 200; one PATCH of the place alone; `kept` names the title the day now shows', [200, ['PATCH'], ['location'], [['field' => 'title', 'now' => 'Standup']], 'Standup'],
            [$status, $methods($w), $bodyKeys($w[0][1] ?? null), $keptOf($json), $master($t)['title']]);
        H::done($t);
        [$t, $o] = $own();
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, ['title' => 'Planning morning', 'over' => $over($o, ['title'])]);
        checkEq('the title changed here: 409 google_changed, was the day\'s own title as shown, now the repeating event\'s, from series; nothing written',
            [409, 'google_changed', [['field' => 'title', 'was' => 'Planning day', 'now' => 'Standup', 'yours' => 'Planning morning', 'from' => 'series']], []],
            [$status, $json['error']['code'] ?? null, $json['error']['fields'] ?? null, $writes($t, $m)]);
        H::done($t);
    });

    // Case 11.
    scenario('Maybe on a day over a rename of the repeating event: one PATCH of the status, `kept` names the title', function () use ($fresh, $day, $occ, $over, $atGoogle, $mark, $writes, $methods, $bodyKeys, $keptOf, $dayRow): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $t->gid, ['summary' => 'Standup']);
        $m = $mark($t);
        [$status, $json] = $day($t, 'relationship', $o, ['relationship' => 'maybe', 'over' => $over($o, ['status'])]);
        $w = $writes($t, $m);
        checkEq('200; one PATCH of the status alone; `kept` the title; the day tentative', [200, ['PATCH'], ['status'], [['field' => 'title', 'now' => 'Standup']], 'tentative'],
            [$status, $methods($w), $bodyKeys($w[0][1] ?? null), $keptOf($json), $dayRow($t, '2027-03-10')['status'] ?? null]);
        H::done($t);
    });

    // Case 12.
    scenario('Use series value over a change to this day\'s title in Google Calendar asks', function () use ($fresh, $send, $day, $occ, $over, $atGoogle, $dayId, $mark, $writes, $methods, $dayRow, $atG, $overwrite): void {
        $t = $fresh();
        $day($t, 'patch', $occ($t, '2027-03-10'), ['title' => 'Planning day']);
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-10'), ['summary' => 'Review day']);
        // As the app sends it: to the repeating event with the day's key, the fields named, and `over` for them.
        $body = ['scope' => 'this', 'instanceStart' => $o['seriesKey'], 'useSeries' => ['title'], 'over' => $over($o, ['title'])];
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', (int) $o['seriesId'], $body);
        $e = $json['error'] ?? [];
        checkEq('409 google_changed about the title: was the day\'s own as shown, now Google Calendar\'s, yours the repeating event\'s; nothing written; the day holds Google Calendar\'s title',
            [409, 'google_changed', [['field' => 'title', 'was' => 'Planning day', 'now' => 'Review day', 'yours' => 'Team sync', 'from' => 'day']], [], 'Review day'],
            [$status, $e['code'] ?? null, $e['fields'] ?? null, $writes($t, $m), $dayRow($t, '2027-03-10')['title'] ?? null]);
        $m = $mark($t);
        // Its Overwrite changes `over` alone: the body names no value.
        [$status] = $send($t, 'patch', (int) $o['seriesId'], $overwrite($body, $json));
        checkEq('Overwrite: 200, one PATCH; Google Calendar holds the repeating event\'s title for the day', [200, ['PATCH'], 'Team sync'],
            [$status, $methods($writes($t, $m)), $atG($t, $t->gid . '_20270310T180000Z')['summary'] ?? null]);
        H::done($t);
    });

    // Case 13: a repeating invitation from someone else, seeded in Google Calendar and polled in.
    scenario('answer to a repeating invitation over a rename in Google Calendar: one PATCH of the guest list, `kept` names the title', function () use ($fresh, $send, $db, $mark, $writes, $methods, $bodyKeys, $keptOf): void {
        $t = $fresh();
        $t->s->fake->seed(['id' => 'smplseries1', 'iCalUID' => 'smplseries1@google.com', 'summary' => 'Sample planning', 'etag' => '"i1"',
            'start' => ['dateTime' => '2027-03-05T18:00:00+00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2027-03-05T19:00:00+00:00', 'timeZone' => 'UTC'],
            'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4'], 'organizer' => ['email' => 'host@example.test'],
            'attendees' => [['email' => 'host@example.test', 'responseStatus' => 'accepted'], ['email' => 'owner@example.test', 'self' => true, 'responseStatus' => 'needsAction']]]);
        $t->s->poll();
        $id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$t->s->calendarId, 'smplseries1']);
        [$st] = H::atGoogle($t, 'PATCH', '/events/smplseries1', ['summary' => 'Sample planning, new agenda']);
        checkEq('renamed in Google Calendar', 200, $st);
        $m = $mark($t);
        [$status, $json] = $send($t, 'rsvp', $id, ['answer' => 'accepted']);
        $w = $writes($t, $m);
        $self = array_values(array_filter((array) ($t->s->fake->events['smplseries1']['attendees'] ?? []), static fn(array $a): bool => !empty($a['self'])));
        checkEq('200; one PATCH, of the guest list alone; Google Calendar holds the answer and its own title; the row holds that title; `kept` names it',
            [200, ['PATCH'], ['attendees'], 'accepted', 'Sample planning, new agenda', 'Sample planning, new agenda', [['field' => 'title', 'now' => 'Sample planning, new agenda']]],
            [$status, $methods($w), $bodyKeys($w[0][1] ?? null), $self[0]['responseStatus'] ?? null, $t->s->fake->events['smplseries1']['summary'] ?? null,
                $db->scalar('SELECT title FROM events WHERE id = ?', [$id]), $keptOf($json)]);
        H::done($t);
    });

    // Unlikely: the day is no longer a day once the read is stored.
    scenario('a day deleted in Google Calendar: an edit is refused with the day\'s sentence, the read stored', function () use ($fresh, $day, $occ, $over, $atGoogle, $dayId, $mark, $writes, $master): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-10'), [], 'DELETE');
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, ['title' => 'Weekly sync', 'over' => $over($o, ['title'])]);
        checkEq('409 google_conflict with the day\'s own sentence; nothing written to Google; the day is skipped here',
            [409, 'google_conflict', "That day is no longer one of the days of 'Team sync': the repeating event was changed in Google Calendar. Your change wasn't saved, and the calendar shows it as Google Calendar has it now.", [], true],
            [$status, $json['error']['code'] ?? null, $json['error']['message'] ?? null, $writes($t, $m), str_contains((string) $master($t)['exdates_json'], '2027-03-10 18:00:00')]);
        H::done($t);
    });
    scenario('a day deleted in Google Calendar: a delete completes, nothing sent', function () use ($fresh, $day, $occ, $over, $allKeys, $atGoogle, $dayId, $mark, $writes, $master): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-10'), [], 'DELETE');
        $m = $mark($t);
        [$status] = $day($t, 'delete', $o, ['over' => $over($o, $allKeys)]);
        checkEq('200; nothing written to Google; the day is skipped here', [200, [], true], [$status, $writes($t, $m), str_contains((string) $master($t)['exdates_json'], '2027-03-10 18:00:00')]);
        H::done($t);
    });

    // (b): a day's time is one value.
    scenario('a day\'s request carrying `over` with its time in parts is 400 client_outdated, nothing read', function () use ($fresh, $day, $occ, $over, $timeKeys, $mark): void {
        $t = $fresh();
        $o = $occ($t, '2027-03-10');
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, ['start' => '2027-03-11T18:00:00+00:00', 'end' => '2027-03-11T19:00:00+00:00', 'over' => $over($o, $timeKeys)]);
        checkEq('the body\'s time in parts: 400 client_outdated, Google not asked', [400, 'client_outdated', 0], [$status, $json['error']['code'] ?? null, $mark($t) - $m]);
        [$status, $json] = $day($t, 'patch', $o, ['title' => 'Weekly sync', 'over' => $over($o, ['title', 'start', 'end'])]);
        checkEq('`over`\'s time in parts: 400 client_outdated, Google not asked', [400, 'client_outdated', 0], [$status, $json['error']['code'] ?? null, $mark($t) - $m]);
        // A page that sends no `over` (1.5.8) is read as before: a start and an end alone move the day.
        [$status] = $day($t, 'patch', $o, ['start' => '2027-03-11T18:00:00+00:00', 'end' => '2027-03-11T19:00:00+00:00']);
        checkEq('no `over`: a time in parts is read as before', 200, $status);
        H::done($t);
    });

    // ---- Time forms (the page, (b): a day's time is one value, sent whole and served whole) --------------------------
    // Expectations by hand, from the page and the API contract (an all-day time is dates at +00:00, its zone UTC; a
    // timed one instants on its zone's clock).
    $rowAt = static fn(object $t, string $key): ?array => $db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$t->id, $key]);
    $timeOf = static fn(array $o): array => [$o['start'] ?? null, $o['end'] ?? null, $o['allDay'] ?? null, $o['tzid'] ?? null];

    scenario('an all-day repeating event: a day dragged, renamed over a rename of the whole event, and deleted; its time sent and served whole', function () use ($fresh, $day, $occ, $over, $timeKeys, $allKeys, $atGoogle, $mark, $writes, $methods, $bodyKeys, $keptOf, $rowAt, $timeOf, $master): void {
        $t = $fresh(true, ['title' => 'Team day', 'start' => '2027-03-03', 'end' => '2027-03-04', 'allDay' => true, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=6']);
        $o = $occ($t, '2027-03-10');
        checkEq('the day is served whole: its dates at +00:00, all-day, the zone UTC', ['2027-03-10T00:00:00+00:00', '2027-03-11T00:00:00+00:00', true, 'UTC'], $timeOf($o));
        $atGoogle($t, $t->gid . '_20270317', ['summary' => 'Offsite']);
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, ['start' => '2027-03-11T00:00:00+00:00', 'end' => '2027-03-12T00:00:00+00:00', 'allDay' => true, 'tzid' => 'UTC', 'over' => $over($o, $timeKeys)]);
        $w = $writes($t, $m);
        checkEq('a drag over another day\'s rename: 200, one PATCH of the dates, nothing kept; the day on its new date here and served whole', [200, ['PATCH'], ['2027-03-11', '2027-03-12'], [], '2027-03-11 00:00:00', ['2027-03-11T00:00:00+00:00', '2027-03-12T00:00:00+00:00', true, 'UTC']],
            [$status, $methods($w), [$w[0][1]['start']['date'] ?? null, $w[0][1]['end']['date'] ?? null], $keptOf($json), $rowAt($t, '2027-03-10 00:00:00')['start_utc'] ?? null, $timeOf($occ($t, '2027-03-11'))]);
        [$status, $json] = $day($t, 'patch', $o, ['start' => '2027-03-12T00:00:00+00:00', 'end' => '2027-03-13T00:00:00+00:00', 'over' => $over($o, $timeKeys)]);
        checkEq('a time in parts beside `over`: 400 client_outdated', [400, 'client_outdated'], [$status, $json['error']['code'] ?? null]);
        $o = $occ($t, '2027-03-24');
        $atGoogle($t, $t->gid, ['summary' => 'Team day out']);
        [$status, $json] = $day($t, 'patch', $o, ['title' => 'Planning day', 'over' => $over($o, ['title'])]);
        $e = $json['error'] ?? [];
        checkEq('a rename over a rename of the whole event: asked, from series, the day named by its date, whole',
            [409, [['field' => 'title', 'was' => 'Team day', 'now' => 'Team day out', 'yours' => 'Planning day', 'from' => 'series']], ['start' => '2027-03-24T00:00:00+00:00', 'end' => '2027-03-25T00:00:00+00:00', 'allDay' => true, 'tzid' => 'UTC']],
            [$status, $e['fields'] ?? null, $e['day'] ?? null]);
        checkEq('its message names the date', "Since Wed, Mar 24, 2027 of 'Team day' was last shown to you, its title was changed in Google Calendar, for the whole repeating event. It is now 'Team day out' there; your change makes it 'Planning day'. It wasn't changed; open that day to see it and make the change again.", $e['message'] ?? null);
        $o = $occ($t, '2027-03-31');
        $m = $mark($t);
        [$status] = $day($t, 'delete', $o, ['over' => $over($o, $allKeys)]);
        checkEq('a delete of an unchanged day: 200, one DELETE, the day skipped', [200, ['DELETE'], true], [$status, $methods($writes($t, $m)), str_contains((string) $master($t)['exdates_json'], '2027-03-31 00:00:00')]);
        H::done($t);
    });

    scenario('a day of the other form: a day of an all-day repeating event made timed in Google Calendar is kept as its time, then dragged timed', function () use ($fresh, $day, $occ, $over, $timeKeys, $atGoogle, $googleTime, $mark, $writes, $methods, $keptOf, $instants, $at, $rowAt, $timeOf): void {
        $t = $fresh(true, ['title' => 'Team day', 'start' => '2027-03-03', 'end' => '2027-03-04', 'allDay' => true, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=6']);
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $t->gid . '_20270310', ['start' => ['dateTime' => '2027-03-10T10:00:00+00:00', 'timeZone' => 'UTC', 'date' => null], 'end' => ['dateTime' => '2027-03-10T11:00:00+00:00', 'timeZone' => 'UTC', 'date' => null]]);
        [$status, $json] = $day($t, 'patch', $o, ['title' => 'Planning day', 'over' => $over($o, ['title'])]);
        $kept = $keptOf($json);
        checkEq('a rename over the day made timed: 200; `kept` the time, timed and whole', [200, ['time'], $at('2027-03-10T10:00:00+00:00', '2027-03-10T11:00:00+00:00'), true],
            [$status, is_array($kept) ? array_column($kept, 'field') : $kept, is_array($kept) ? $instants($kept[0]['now'] ?? null) : null, is_array($kept) && isset($kept[0]['now']['tzid'])]);
        $o = $occ($t, '2027-03-10');
        checkEq('the day is served timed, whole, with a zone', [false, true, '2027-03-10 10:00:00'], [$o['allDay'] ?? null, is_string($o['tzid'] ?? null) && $o['tzid'] !== '', $rowAt($t, '2027-03-10 00:00:00')['start_utc'] ?? null]);
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, ['start' => '2027-03-10T12:00:00+00:00', 'end' => '2027-03-10T13:00:00+00:00', 'allDay' => false, 'tzid' => $o['tzid'], 'over' => $over($o, $timeKeys)]);
        checkEq('a drag of the timed day: 200, one PATCH, nothing kept, the day at its new time', [200, ['PATCH'], [], '2027-03-10 12:00:00'],
            [$status, $methods($writes($t, $m)), $keptOf($json), $rowAt($t, '2027-03-10 00:00:00')['start_utc'] ?? null]);
        H::done($t);
    });

    // Sundays at 01:00 for half an hour in America/Los_Angeles, where the clocks go forward at 02:00 on 14 March 2027:
    // 7 March at 01:00 PST (09:00 UTC), 14 March at 01:00 PST, the night of the change (09:00 UTC), 21 March at 01:00 PDT (08:00 UTC).
    scenario('a repeating event in a zone with a clock change: a day before it, on its night and after it, each dragged two hours later over a rename', function () use ($fresh, $day, $occ, $over, $timeKeys, $atGoogle, $googleTime, $mark, $writes, $methods, $keptOf, $instants, $at, $rowAt, $timeOf): void {
        $create = ['title' => 'Night check', 'start' => '2027-03-07T01:00:00-08:00', 'end' => '2027-03-07T01:30:00-08:00', 'allDay' => false, 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
        foreach ([
            'the week before' => ['2027-03-07', '2027-03-07 09:00:00', '2027-03-07T01:00:00-08:00', '2027-03-07T01:30:00-08:00', '2027-03-07T03:00:00-08:00', '2027-03-07T03:30:00-08:00', '2027-03-07 11:00:00'],
            'the night of the change' => ['2027-03-14', '2027-03-14 09:00:00', '2027-03-14T01:00:00-08:00', '2027-03-14T01:30:00-08:00', '2027-03-14T03:00:00-07:00', '2027-03-14T03:30:00-07:00', '2027-03-14 10:00:00'],
            'the week after' => ['2027-03-21', '2027-03-21 08:00:00', '2027-03-21T01:00:00-07:00', '2027-03-21T01:30:00-07:00', '2027-03-21T03:00:00-07:00', '2027-03-21T03:30:00-07:00', '2027-03-21 10:00:00'],
        ] as $label => [$date, $key, $start, $end, $newStart, $newEnd, $storedStart]) {
            $t = $fresh(true, $create);
            $o = $occ($t, $date);
            checkEq("$label: the day is served whole, on its zone's clock", [$start, $end, false, 'America/Los_Angeles'], $timeOf($o));
            $atGoogle($t, $t->gid, ['summary' => 'Night round']);
            $m = $mark($t);
            [$status, $json] = $day($t, 'patch', $o, ['start' => $newStart, 'end' => $newEnd, 'allDay' => false, 'tzid' => 'America/Los_Angeles', 'over' => $over($o, $timeKeys)]);
            $w = $writes($t, $m);
            checkEq("$label: 200, one PATCH whose start is the dragged instant, `kept` the title; the day stored at that instant and served whole on its clock",
                [200, ['PATCH'], strtotime($newStart), [['field' => 'title', 'now' => 'Night round']], $storedStart, [$newStart, $newEnd, false, 'America/Los_Angeles']],
                [$status, $methods($w), strtotime((string) ($w[0][1]['start']['dateTime'] ?? '')), $keptOf($json), $rowAt($t, $key)['start_utc'] ?? null, $timeOf($occ($t, $date))]);
            H::done($t);
        }
        // The same field on the night of the change: the day moved in Google Calendar, dragged here.
        $t = $fresh(true, $create);
        $o = $occ($t, '2027-03-14');
        $atGoogle($t, $t->gid . '_20270314T090000Z', ['start' => ['dateTime' => '2027-03-14T04:00:00-07:00', 'timeZone' => 'America/Los_Angeles'], 'end' => ['dateTime' => '2027-03-14T04:30:00-07:00', 'timeZone' => 'America/Los_Angeles']]);
        [$status, $json] = $day($t, 'patch', $o, ['start' => '2027-03-14T03:00:00-07:00', 'end' => '2027-03-14T03:30:00-07:00', 'allDay' => false, 'tzid' => 'America/Los_Angeles', 'over' => $over($o, $timeKeys)]);
        $f = $json['error']['fields'][0] ?? [];
        checkEq('the night of the change, the same field: asked about the time, from day, each value whole with its zone',
            [409, 'time', 'day', $at('2027-03-14T01:00:00-08:00', '2027-03-14T01:30:00-08:00'), $at('2027-03-14T04:00:00-07:00', '2027-03-14T04:30:00-07:00'), $at('2027-03-14T03:00:00-07:00', '2027-03-14T03:30:00-07:00'), ['America/Los_Angeles', 'America/Los_Angeles', 'America/Los_Angeles']],
            [$status, $f['field'] ?? null, $f['from'] ?? null, $instants($f['was'] ?? null), $instants($f['now'] ?? null), $instants($f['yours'] ?? null), [$f['was']['tzid'] ?? null, $f['now']['tzid'] ?? null, $f['yours']['tzid'] ?? null]]);
        H::done($t);
    });

    // The same clock time in any zone: 09:00 for an hour, seen from a device in New York. Its times are served on
    // the device's clock and sent back on it, in the request and in `over`.
    scenario('a repeating event that keeps the same clock time in any zone: a day dragged and renamed from a device in another zone', function () use ($fresh, $day, $occ, $over, $timeKeys, $atGoogle, $mark, $writes, $methods, $keptOf, $timeOf): void {
        $t = $fresh(true, ['title' => 'Morning walk', 'start' => '2027-03-03T09:00:00+00:00', 'end' => '2027-03-03T10:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC', 'floating' => true, 'rrule' => 'FREQ=WEEKLY;COUNT=6']);
        $t->e->viewFrom('America/New_York');
        $o = $occ($t, '2027-03-10');
        checkEq('the day is served at 09:00 on the device\'s clock, whole', ['2027-03-10T09:00:00-05:00', '2027-03-10T10:00:00-05:00', false], array_slice($timeOf($o), 0, 3));
        $atGoogle($t, $t->gid . '_20270317T090000Z', ['summary' => 'Long walk']);
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, ['start' => '2027-03-10T11:00:00-05:00', 'end' => '2027-03-10T12:00:00-05:00', 'allDay' => false, 'tzid' => $o['tzid'], 'over' => $over($o, $timeKeys)]);
        checkEq('a drag to 11:00 on the device\'s clock over another day\'s rename: 200, one PATCH, nothing asked, nothing kept; served at 11:00 on the device\'s clock',
            [200, ['PATCH'], [], ['2027-03-10T11:00:00-05:00', '2027-03-10T12:00:00-05:00', false]],
            [$status, $methods($writes($t, $m)), $keptOf($json), array_slice($timeOf($occ($t, '2027-03-10')), 0, 3)]);
        [$status, $json] = $day($t, 'patch', $o, ['start' => '2027-03-10T12:00:00-05:00', 'over' => $over($o, $timeKeys)]);
        checkEq('a time in parts beside `over`: 400 client_outdated', [400, 'client_outdated'], [$status, $json['error']['code'] ?? null]);
        $o = $occ($t, '2027-03-24');
        $atGoogle($t, $t->gid, ['summary' => 'Morning run']);
        [$status, $json] = $day($t, 'delete', $o, ['over' => $over($o, array_merge(['title'], $timeKeys))]);
        $e = $json['error'] ?? [];
        checkEq('a delete over a rename of the whole event: asked about the title alone, the day named at 09:00 on the device\'s clock (daylight time by then)',
            [409, ['title'], '2027-03-24T09:00:00-04:00'], [$status, array_column($e['fields'] ?? [], 'field'), $e['day']['start'] ?? null]);
        H::done($t);
    });

    // ---- The paths that follow by sharing an entry (the page, (j)) -----------------------------------------------------

    scenario('a plain edit of an already-edited day by the repeating event\'s id and the day\'s key goes through over another day\'s rename', function () use ($fresh, $send, $day, $occ, $over, $atGoogle, $dayId, $mark, $writes, $methods, $bodyKeys, $keptOf, $dayRow): void {
        $t = $fresh();
        $day($t, 'patch', $occ($t, '2027-03-10'), ['title' => 'Planning day']);
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-17'), ['summary' => 'Offsite']);
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', $t->id, ['scope' => 'this', 'instanceStart' => '2027-03-10T18:00:00+00:00', 'location' => 'Room 5', 'over' => $over($o, ['location'])]);
        $w = $writes($t, $m);
        checkEq('200; one PATCH of the place alone; nothing kept; the day keeps its title and takes the place', [200, ['PATCH'], ['location'], [], ['Planning day', 'Room 5']],
            [$status, $methods($w), $bodyKeys($w[0][1] ?? null), $keptOf($json), [$dayRow($t, '2027-03-10')['title'] ?? null, $dayRow($t, '2027-03-10')['location'] ?? null]]);
        // The same field of that day changed in Google Calendar: asked.
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-10'), ['location' => 'Sample Hall']);
        [$status, $json] = $send($t, 'patch', $t->id, ['scope' => 'this', 'instanceStart' => '2027-03-10T18:00:00+00:00', 'location' => 'Room 9', 'over' => $over($o, ['location'])]);
        checkEq('the day\'s place changed in Google Calendar, changed here: asked, from day', [409, [['field' => 'location', 'was' => 'Room 5', 'now' => 'Sample Hall', 'yours' => 'Room 9', 'from' => 'day']]], [$status, $json['error']['fields'] ?? null]);
        H::done($t);
    });

    scenario('an answer on one day\'s row over a change to that day\'s place: the answer is sent, `kept` names the place as the day shows it', function () use ($fresh, $send, $db, $mark, $writes, $methods, $bodyKeys, $keptOf): void {
        $t = $fresh();
        $guests = [['email' => 'host@example.test', 'responseStatus' => 'accepted'], ['email' => 'owner@example.test', 'self' => true, 'responseStatus' => 'needsAction']];
        $base = ['iCalUID' => 'smplseries2@google.com', 'summary' => 'Sample planning', 'organizer' => ['email' => 'host@example.test'], 'attendees' => $guests];
        $t->s->fake->seed(['id' => 'smplseries2', 'etag' => '"i1"', 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4'],
            'start' => ['dateTime' => '2027-03-05T18:00:00+00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2027-03-05T19:00:00+00:00', 'timeZone' => 'UTC']] + $base);
        $t->s->fake->seed(['id' => 'smplseries2_20270312T180000Z', 'etag' => '"i2"', 'recurringEventId' => 'smplseries2', 'originalStartTime' => ['dateTime' => '2027-03-12T18:00:00+00:00', 'timeZone' => 'UTC'],
            'start' => ['dateTime' => '2027-03-12T20:00:00+00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2027-03-12T21:00:00+00:00', 'timeZone' => 'UTC']] + $base);
        $t->s->poll();
        $id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$t->s->calendarId, 'smplseries2_20270312T180000Z']);
        checkEq('the day has a row of its own here', true, $id > 0);
        [$st] = H::atGoogle($t, 'PATCH', '/events/smplseries2_20270312T180000Z', ['location' => 'Sample Hall']);
        [$st2] = H::atGoogle($t, 'PATCH', '/events/smplseries2', ['summary' => 'Sample planning, new agenda']);
        checkEq('the day\'s place and the whole event\'s title changed in Google Calendar', [200, 200], [$st, $st2]);
        $m = $mark($t);
        [$status, $json] = $send($t, 'rsvp', $id, ['answer' => 'accepted']);
        $w = $writes($t, $m);
        $self = array_values(array_filter((array) ($t->s->fake->events['smplseries2_20270312T180000Z']['attendees'] ?? []), static fn(array $a): bool => !empty($a['self'])));
        $kept = $keptOf($json);
        checkEq('200; one PATCH of the guest list; the day answered in Google Calendar; `kept` names the title and the place the day now shows',
            [200, ['PATCH'], ['attendees'], 'accepted', ['location' => 'Sample Hall', 'title' => 'Sample planning, new agenda']],
            [$status, $methods($w), $bodyKeys($w[0][1] ?? null), $self[0]['responseStatus'] ?? null, is_array($kept) ? (static function (array $k): array { $o = array_column($k, 'now', 'field'); ksort($o); return $o; })($kept) : $kept]);
        H::done($t);
    });

    // Review applies an invitation's update or cancellation to one day by these two calls (ReviewQueue::accept: patch
    // with scope this, the day and the fields, and deleteEvent with scope this), under the request's authority and
    // with no `over`. The calls are made here as it makes them; the mail item that leads to them is not built.
    scenario('Review\'s update and cancellation of one day: no `over`, the base is the day as stored; a change to the same field asks with the whole message', function () use ($fresh, $atGoogle, $dayId, $mark, $writes, $methods, $dayRow, $master): void {
        $t = $fresh();
        $atGoogle($t, $dayId($t, '2027-03-17'), ['summary' => 'Offsite']);
        $m = $mark($t);
        $t->e->patch($t->u, $t->id, ['scope' => 'this', 'instanceStart' => '2027-03-10T18:00:00+00:00', 'location' => 'Room 5']);
        checkEq('an update over another day\'s rename: one PATCH, the day holds the place', [['PATCH'], 'Room 5'], [$methods($writes($t, $m)), $dayRow($t, '2027-03-10')['location'] ?? null]);
        $atGoogle($t, $dayId($t, '2027-03-10'), ['location' => 'Sample Hall']);
        $got = null;
        try {
            $t->e->patch($t->u, $t->id, ['scope' => 'this', 'instanceStart' => '2027-03-10T18:00:00+00:00', 'location' => 'Room 9']);
        } catch (HttpError $e) {
            $got = [$e->errorCode, $e->getMessage()];
        }
        checkEq('an update of a field the day changed in since the last poll: 409 google_changed, the sentence whole on its own',
            ['google_changed', "Since Wed, Mar 10, 2027 of 'Team sync' was last shown to you, its location was changed in Google Calendar. It is now 'Sample Hall' there; your change makes it 'Room 9'. It wasn't changed; open that day to see it and make the change again."], $got);
        $m = $mark($t);
        $t->e->deleteEvent($t->u, $t->id, 'this', '2027-03-24T18:00:00+00:00');
        checkEq('a cancellation of an unchanged day: one DELETE, the day skipped', [['DELETE'], true], [$methods($writes($t, $m)), str_contains((string) $master($t)['exdates_json'], '2027-03-24 18:00:00')]);
        H::done($t);
    });

    scenario('a plugin\'s run changing one day: no `over`; over another day\'s rename it goes through, and it never overwrites', function () use ($fresh, $atGoogle, $dayId, $mark, $writes, $methods, $dayRow): void {
        $t = $fresh();
        $atGoogle($t, $dayId($t, '2027-03-17'), ['summary' => 'Offsite']);
        $m = $mark($t);
        \BetterCal\Domain\ActivityContext::with('plugin:sample', fn() => $t->e->patch($t->u, $t->id, ['scope' => 'this', 'instanceStart' => '2027-03-10T18:00:00+00:00', 'title' => 'Sample run']));
        checkEq('one PATCH; the day holds the run\'s title', [['PATCH'], 'Sample run'], [$methods($writes($t, $m)), $dayRow($t, '2027-03-10')['title'] ?? null]);
        $atGoogle($t, $dayId($t, '2027-03-10'), ['summary' => 'Review day']);
        $m = $mark($t);
        $code = null;
        try {
            \BetterCal\Domain\ActivityContext::with('plugin:sample', fn() => $t->e->patch($t->u, $t->id, ['scope' => 'this', 'instanceStart' => '2027-03-10T18:00:00+00:00', 'title' => 'Sample run two']));
        } catch (HttpError $e) {
            $code = $e->errorCode;
        }
        checkEq('the day renamed in Google Calendar since: 409 google_changed, nothing written, the day holds Google Calendar\'s title', ['google_changed', [], 'Review day'], [$code, $writes($t, $m), $dayRow($t, '2027-03-10')['title'] ?? null]);
        H::done($t);
    });

    scenario('an API caller with no `over`: the base is the day as stored, so a change a poll stored is not asked about, and one not yet polled is', function () use ($fresh, $send, $atGoogle, $dayId, $mark, $writes, $methods, $keptOf, $dayRow): void {
        $t = $fresh();
        $atGoogle($t, $dayId($t, '2027-03-10'), ['summary' => 'Standup']);
        $t->s->poll();
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', $t->id, ['scope' => 'this', 'instanceStart' => '2027-03-10T18:00:00+00:00', 'title' => 'Weekly sync']);
        checkEq('after the poll: 200, one PATCH, nothing kept; the day holds the caller\'s title', [200, ['PATCH'], [], 'Weekly sync'], [$status, $methods($writes($t, $m)), $keptOf($json), $dayRow($t, '2027-03-10')['title'] ?? null]);
        $atGoogle($t, $dayId($t, '2027-03-10'), ['summary' => 'Review day']);
        [$status, $json] = $send($t, 'patch', $t->id, ['scope' => 'this', 'instanceStart' => '2027-03-10T18:00:00+00:00', 'title' => 'Monthly sync']);
        checkEq('not yet polled: 409 google_changed, was the title as stored', [409, [['field' => 'title', 'was' => 'Weekly sync', 'now' => 'Review day', 'yours' => 'Monthly sync', 'from' => 'day']]], [$status, $json['error']['fields'] ?? null]);
        H::done($t);
    });

    scenario('a trip that is one day of a repeating event: moved over another day\'s rename, and asked about over its own move', function () use ($fresh, $day, $occ, $over, $timeKeys, $whole, $atGoogle, $dayId, $googleTime, $mark, $writes, $methods, $keptOf, $dayRow, $db): void {
        $t = $fresh(true, eventAt(0, 'Sample trip', '2027-03-03', ['isContainer' => true, 'rrule' => 'FREQ=WEEKLY;COUNT=6']));
        checkEq('the repeating event is a trip', 1, (int) $db->scalar('SELECT is_container FROM events WHERE id = ?', [$t->id]));
        $o = $occ($t, '2027-03-10');
        $atGoogle($t, $dayId($t, '2027-03-17'), ['summary' => 'Offsite']);
        $m = $mark($t);
        [$status, $json] = $day($t, 'patch', $o, $whole('2027-03-11T18:00:00+00:00', '2027-03-11T19:00:00+00:00') + ['over' => $over($o, $timeKeys)]);
        checkEq('200, one PATCH, nothing kept; the day moved and still a trip', [200, ['PATCH'], [], ['2027-03-11 18:00:00', 1]],
            [$status, $methods($writes($t, $m)), $keptOf($json), [$dayRow($t, '2027-03-10')['start_utc'] ?? null, (int) ($dayRow($t, '2027-03-10')['is_container'] ?? 0)]]);
        $o = $occ($t, '2027-03-24');
        $atGoogle($t, $dayId($t, '2027-03-24'), $googleTime('2027-03-24T20:00:00+00:00', '2027-03-24T21:00:00+00:00'));
        [$status, $json] = $day($t, 'patch', $o, $whole('2027-03-25T18:00:00+00:00', '2027-03-25T19:00:00+00:00') + ['over' => $over($o, $timeKeys)]);
        checkEq('its own move in Google Calendar, moved here: asked about the time', [409, ['time']], [$status, array_column($json['error']['fields'] ?? [], 'field')]);
        H::done($t);
    });

    // Case 14: the setting off (an operator's switch): the code it was.
    scenario('setting off, a day: the refusal for each of the above', function () use ($fresh, $day, $occ, $over, $timeKeys, $allKeys, $whole, $atGoogle, $dayId, $mark, $writes, $db): void {
        $cases = [
            'another day renamed, a drag' => [static fn(object $t) => $atGoogle($t, $dayId($t, '2027-03-17'), ['summary' => 'Offsite']), 'patch',
                static fn(array $o): array => $whole('2027-03-11T18:00:00+00:00', '2027-03-11T19:00:00+00:00') + ['over' => $over($o, $timeKeys)]],
            'the repeating event renamed, a rename of the day' => [static fn(object $t) => $atGoogle($t, $t->gid, ['summary' => 'Standup']), 'patch',
                static fn(array $o): array => ['title' => 'Weekly sync', 'over' => $over($o, ['title'])]],
            'the day\'s place changed, a delete' => [static fn(object $t) => $atGoogle($t, $dayId($t, '2027-03-10'), ['location' => 'Sample Hall']), 'delete',
                static fn(array $o): array => ['over' => $over($o, $allKeys)]],
            'the repeating event renamed, Maybe on a day' => [static fn(object $t) => $atGoogle($t, $t->gid, ['summary' => 'Standup']), 'relationship',
                static fn(array $o): array => ['relationship' => 'maybe', 'over' => $over($o, ['status'])]],
        ];
        foreach ($cases as $label => [$change, $action, $bodyOf]) {
            $t = $fresh(false);
            $o = $occ($t, '2027-03-10');
            $change($t);
            $rows = static fn(): array => $db->all('SELECT * FROM events WHERE calendar_id = ? ORDER BY id', [$t->s->calendarId]);
            $before = $rows();
            $m = $mark($t);
            [$status, $json] = $day($t, $action, $o, $bodyOf($o));
            checkEq("setting off, $label: 409 google_conflict with the sentence it had, nothing written to Google, no row changed, no `kept`",
                [409, 'google_conflict', GoogleWriter::CONFLICT_MESSAGE, [], $before],
                [$status, $json['error']['code'] ?? null, $json['error']['message'] ?? null, $writes($t, $m), $rows()]);
            H::done($t);
        }
    });
};
