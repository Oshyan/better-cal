<?php

declare(strict_types=1);

// 1.5.7 slice 1, the request-level scenarios (docs/design/google-conflict-choice.md, the page "1.5.7, Slice 1"): a
// change or delete of one event in no series on a Google calendar, over a change made at Google since the event was
// shown. One scenario per promise the page names. Each sends client-shaped requests through EventsController, as the
// router does (under the request's credential; an HttpError becomes {"error": {code, message, ...extra}}), with `over`
// built from the record the API serves for the event, as the app builds it. Google is the project's stand-in
// (tools/edit-harness/GoogleStand.php over tools/google-probe/lib/FakeGoogle.php); a change "at Google" is a request to
// it as another client makes it. Every expectation is written here from the page: nothing in server/src is called to
// compute one. The setting google_conflict_choice is switched in each scenario's own setup (on, or off for the 1.5.6
// refusals). Invented data throughout.

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
    $conflictChoice = static function (int $u, bool $on) use ($db): void {
        $settings = json_decode((string) $db->scalar('SELECT settings_json FROM users WHERE id = ?', [$u]), true) ?: [];
        $db->update('users', ['settings_json' => json_encode(['google_conflict_choice' => $on] + $settings)], 'id = ?', [$u]);
    };

    // An account with the setting as asked, a Google calendar at the stand-in, and one event in no series made through
    // the app (so Google holds it): 'Team sync', 18:00 to 19:00 UTC on 3 March 2027, at Room 2.
    $fresh = static function (bool $on = true) use ($db, $conflictChoice): object {
        $u = freshUser($db);
        $conflictChoice($u, $on);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $id = (int) $s->events->create($u, eventAt($s->calendarId, 'Team sync', '2027-03-03', ['location' => 'Room 2']))['eventId'];
        $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
        $token = 'sample-session-' . bin2hex(random_bytes(8));
        $db->insert('sessions', ['token_hash' => hash('sha256', $token), 'user_id' => $u, 'csrf' => str_repeat('c', 32), 'authenticated_at' => Time::nowDb(), 'expires_at' => '2099-01-01 00:00:00']);
        $controller = new EventsController($s->events, new Trips($db, $d->undo), new Rsvp($db, new EmailSender([]), [], $s->writer));
        return (object) ['db' => $db, 'u' => $u, 'd' => $d, 's' => $s, 'id' => $id, 'gid' => $gid, 'token' => $token, 'controller' => $controller];
    };

    // One request as the router answers it: [status, decoded body].
    $send = static function (object $t, string $action, array $body, ?int $id = null): array {
        $method = ['patch' => 'PATCH', 'delete' => 'DELETE', 'relationship' => 'POST', 'rsvp' => 'POST'][$action];
        $req = new Request($method, '/api/v1/events/' . ($id ?? $t->id) . ($method === 'POST' ? "/$action" : ''), [], $body, [], [Auth::COOKIE => $t->token]);
        $req->user = ['id' => $t->u];
        $req->authMethod = 'session';
        try {
            $res = RequestAuthority::during(RequestAuthority::of($req), fn() => $t->controller->$action($req, ['id' => (string) ($id ?? $t->id)]), true);
            $out = [$res->status, json_decode($res->body, true)];
        } catch (HttpError $e) {
            $out = [$e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra]];
        } finally {
            \BetterCal\Domain\ActivityContext::takeEntries();
        }
        return $out;
    };

    // The record the API serves for the event (GET /events/:id/occurrence), and `over` from it for the keys named.
    $served = static fn(object $t): array => $t->s->events->serializeSingle($t->s->events->get($t->u, $t->id));
    $overOf = static function (object $t, array $keys) use ($served): array {
        $r = $served($t);
        return array_intersect_key($r, array_flip($keys));
    };
    $allKeys = ['title', 'description', 'location', 'url', 'status', 'start', 'end', 'allDay'];

    // A change at Google, as another client makes it.
    $atGoogle = static function (object $t, array $body): void {
        [$status] = H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid), $body);
        if ($status !== 200) {
            throw new \RuntimeException("the stand-in refused the change at Google: $status");
        }
    };
    $mark = static fn(object $t): int => count($t->s->fake->requests);
    // The app's requests to the stand-in since $from: [method, body], the other client's left out.
    $sent = static fn(object $t, int $from): array => array_values(array_map(static fn(array $r): array => [$r['method'], $r['body']],
        array_filter(array_slice($t->s->fake->requests, $from), static fn(array $r): bool => !in_array('X-Harness: remote', $r['headers'], true))));
    $methods = static fn(array $sent): array => array_column($sent, 0);
    $atG = static fn(object $t): array => $t->s->fake->events[$t->gid];
    // What the row holds, in the page's six fields, its tags and whether it is deleted (a removed row is deleted).
    $row = static function (object $t) use ($db): array {
        $r = $db->one('SELECT * FROM events WHERE id = ?', [$t->id]);
        if ($r === null) {
            return ['deleted' => true]; // a deleted Google event's row is removed
        }
        return [
            'title' => $r['title'], 'description' => $r['description'], 'location' => $r['location'], 'url' => $r['url'], 'status' => $r['status'],
            'time' => [$r['start_utc'], $r['end_utc'], (int) $r['all_day']], 'deleted' => $r['deleted_at'] !== null,
            'tags' => array_column($db->all('SELECT tg.name FROM event_tags et JOIN tags tg ON tg.id = et.tag_id WHERE et.event_id = ? ORDER BY tg.name', [$t->id]), 'name'),
        ];
    };
    $entries = static fn(object $t): int => (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$t->u]);
    $lastSource = static fn(object $t): ?string => $db->scalar('SELECT source FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$t->u]);
    // A time value in the served form, compared as instants and the all-day setting.
    $instants = static fn(?array $v): ?array => $v === null ? null : [strtotime((string) ($v['start'] ?? '')), strtotime((string) ($v['end'] ?? '')), (bool) ($v['allDay'] ?? false)];
    $keptOf = static fn(array $json): mixed => array_key_exists('kept', $json) ? $json['kept'] : 'no kept in the answer';
    $bodyKeys = static fn(?array $body): array => array_values(array_intersect(array_keys((array) $body), ['summary', 'description', 'location', 'source', 'status', 'start', 'end']));
    $googleTime = static fn(string $s, string $e): array => ['start' => ['dateTime' => $s, 'timeZone' => 'UTC'], 'end' => ['dateTime' => $e, 'timeZone' => 'UTC']];

    // Case 1.
    scenario('unchanged event: edit, drag, delete, Maybe each send one write; `kept` is empty', function () use ($fresh, $send, $overOf, $allKeys, $mark, $sent, $methods, $keptOf, $row): void {
        $t = $fresh();
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', ['title' => 'Weekly sync', 'over' => $overOf($t, ['title'])]);
        checkEq('edit: 200, one read and one PATCH, nothing kept', [200, ['GET', 'PATCH'], []], [$status, $methods($sent($t, $m)), $keptOf($json)]);
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', ['start' => '2027-03-04T18:00:00+00:00', 'end' => '2027-03-04T19:00:00+00:00', 'over' => $overOf($t, ['start', 'end', 'allDay'])]);
        checkEq('drag: 200, one read and one PATCH, nothing kept, the row at the new time', [200, ['GET', 'PATCH'], [], ['2027-03-04 18:00:00', '2027-03-04 19:00:00', 0]],
            [$status, $methods($sent($t, $m)), $keptOf($json), $row($t)['time']]);
        $m = $mark($t);
        [$status, $json] = $send($t, 'relationship', ['relationship' => 'maybe']);
        checkEq('Maybe: 200, one read and one PATCH, nothing kept, the row tentative', [200, ['GET', 'PATCH'], [], 'tentative'], [$status, $methods($sent($t, $m)), $keptOf($json), $row($t)['status']]);
        $m = $mark($t);
        [$status, $json] = $send($t, 'delete', ['over' => $overOf($t, $allKeys)]);
        checkEq('delete: 200, one read and one DELETE, nothing kept, the row deleted', [200, ['GET', 'DELETE'], [], true],
            [$status, $methods($sent($t, $m)), $json['kept'] ?? [], $row($t)['deleted']]);
        H::done($t);
    });

    // Case 2.
    scenario('time changed at Google, title changed here: PATCH sends the title alone, the row holds Google\'s time and your title, `kept` names the time with its value now', function () use ($fresh, $send, $overOf, $atGoogle, $googleTime, $mark, $sent, $methods, $keptOf, $row, $atG, $bodyKeys, $instants): void {
        $t = $fresh();
        $over = $overOf($t, ['title']);
        $atGoogle($t, $googleTime('2027-03-03T20:00:00+00:00', '2027-03-03T21:00:00+00:00'));
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', ['title' => 'Weekly sync', 'over' => $over]);
        $s = $sent($t, $m);
        checkEq('200; one read, one PATCH whose body holds the title alone', [200, ['GET', 'PATCH'], ['summary']], [$status, $methods($s), $bodyKeys($s[1][1] ?? null)]);
        checkEq('Google holds your title and its own time; the row holds the same', [['Weekly sync', '2027-03-03T20:00:00+00:00'], 'Weekly sync', ['2027-03-03 20:00:00', '2027-03-03 21:00:00', 0]],
            [[$atG($t)['summary'] ?? null, $atG($t)['start']['dateTime'] ?? null], $row($t)['title'], $row($t)['time']]);
        $kept = $keptOf($json);
        checkEq('`kept` is the time alone, with its value now', [['time'], [strtotime('2027-03-03T20:00:00+00:00'), strtotime('2027-03-03T21:00:00+00:00'), false]],
            [is_array($kept) ? array_column($kept, 'field') : $kept, is_array($kept) ? $instants($kept[0]['now'] ?? null) : null]);
        H::done($t);
    });

    // Case 3, and the store's journal entry.
    scenario('same field: 409 `google_changed` with the field, nothing sent, rows hold Google\'s value, tags in the same request not written', function () use ($fresh, $send, $overOf, $atGoogle, $mark, $sent, $methods, $row, $atG, $entries, $lastSource): void {
        $t = $fresh();
        $over = $overOf($t, ['title']);
        $atGoogle($t, ['summary' => 'Standup']);
        $before = $row($t);
        $n = $entries($t);
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', ['title' => 'Weekly sync', 'tagNames' => ['sample-tag'], 'over' => $over]);
        $e = $json['error'] ?? [];
        checkEq('409 google_changed, the title with was, now and yours, nothing kept', [409, 'google_changed', [['field' => 'title', 'was' => 'Team sync', 'now' => 'Standup', 'yours' => 'Weekly sync']], []],
            [$status, $e['code'] ?? null, $e['fields'] ?? null, $e['kept'] ?? null]);
        checkEq('the message is whole on its own', "'Team sync' wasn't changed: since it was last shown, its title was changed in Google Calendar to 'Standup'. Open the event to see it and make the change again.", $e['message'] ?? null);
        checkEq('one read and nothing written to Google; Google still holds its title', [['GET'], 'Standup'], [$methods($sent($t, $m)), $atG($t)['summary'] ?? null]);
        checkEq('the row holds Google\'s title, nothing else changed, the tags not written', ['title' => 'Standup'] + $before, $row($t));
        checkEq('the read was stored as a poll stores it: one journal entry, from Google', [$n + 1, 'google'], [$entries($t), $lastSource($t)]);
        H::done($t);
    });

    // Case 3, Overwrite; and the unlikely "second change before Overwrite asks again".
    scenario('Overwrite after the question: one PATCH, rows hold your value', function () use ($fresh, $send, $overOf, $atGoogle, $mark, $sent, $methods, $row, $atG, $keptOf): void {
        $t = $fresh();
        $over = $overOf($t, ['title']);
        $atGoogle($t, ['summary' => 'Standup']);
        $body = ['title' => 'Weekly sync', 'tagNames' => ['sample-tag'], 'over' => $over];
        [$status, $json] = $send($t, 'patch', $body);
        checkEq('the question first', 409, $status);
        $m = $mark($t);
        $field = $json['error']['fields'][0] ?? ['now' => 'Standup'];
        [$status, $json] = $send($t, 'patch', ['over' => ['title' => $field['now']] + $over] + $body);
        checkEq('Overwrite: 200, one read and one PATCH, nothing kept', [200, ['GET', 'PATCH'], []], [$status, $methods($sent($t, $m)), $keptOf($json)]);
        checkEq('Google and the row hold your title, and the tags are written', ['Weekly sync', 'Weekly sync', ['sample-tag']], [$atG($t)['summary'] ?? null, $row($t)['title'], $row($t)['tags']]);
    });
    scenario('second change before Overwrite asks again', function () use ($fresh, $send, $overOf, $atGoogle, $mark, $sent, $methods, $row): void {
        $t = $fresh();
        $over = $overOf($t, ['title']);
        $atGoogle($t, ['summary' => 'Standup']);
        $body = ['title' => 'Weekly sync', 'over' => $over];
        $send($t, 'patch', $body);
        $atGoogle($t, ['summary' => 'Daily sync']);
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', ['over' => ['title' => 'Standup']] + $body);
        $e = $json['error'] ?? [];
        checkEq('409 google_changed again, with the newer value; one read, nothing written; the row holds the newer title', [409, 'google_changed', [['field' => 'title', 'was' => 'Standup', 'now' => 'Daily sync', 'yours' => 'Weekly sync']], ['GET'], 'Daily sync'],
            [$status, $e['code'] ?? null, $e['fields'] ?? null, $methods($sent($t, $m)), $row($t)['title']]);
        H::done($t);
    });

    // Case 4.
    scenario('same value at Google and here: no question', function () use ($fresh, $send, $overOf, $atGoogle, $mark, $sent, $methods, $row, $keptOf): void {
        $t = $fresh();
        $over = $overOf($t, ['title']);
        $atGoogle($t, ['summary' => 'Weekly sync']);
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', ['title' => 'Weekly sync', 'over' => $over]);
        checkEq('200, nothing left to send so nothing written to Google, nothing kept, the row holds the title', [200, ['GET'], [], 'Weekly sync'],
            [$status, $methods($sent($t, $m)), $keptOf($json), $row($t)['title']]);
        H::done($t);
    });

    // Case 5.
    scenario('poll between serve and save: the request echoes the served title in `over`, the poll stored Google\'s title, the save asks', function () use ($fresh, $send, $overOf, $atGoogle, $mark, $sent, $methods, $row): void {
        $t = $fresh();
        $over = $overOf($t, ['title']);
        $atGoogle($t, ['summary' => 'Standup']);
        $t->s->poll();
        checkEq('the poll stored Google\'s title', 'Standup', $row($t)['title']);
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', ['title' => 'Weekly sync', 'over' => $over]);
        $e = $json['error'] ?? [];
        checkEq('409 google_changed about the title, was the served title; nothing written to Google', [409, 'google_changed', [['field' => 'title', 'was' => 'Team sync', 'now' => 'Standup', 'yours' => 'Weekly sync']], ['GET'], 'Standup'],
            [$status, $e['code'] ?? null, $e['fields'] ?? null, $methods($sent($t, $m)), $row($t)['title']]);
        H::done($t);
    });

    // Case 6.
    scenario('delete over a place change asks, lists the place with was and now', function () use ($fresh, $send, $overOf, $allKeys, $atGoogle, $mark, $sent, $methods, $row, $atG): void {
        $t = $fresh();
        $over = $overOf($t, $allKeys);
        $atGoogle($t, ['location' => 'Sample Hall']);
        $before = $row($t);
        $m = $mark($t);
        [$status, $json] = $send($t, 'delete', ['over' => $over]);
        $e = $json['error'] ?? [];
        checkEq('409 google_changed, the place alone, yours null', [409, 'google_changed', [['field' => 'location', 'was' => 'Room 2', 'now' => 'Sample Hall', 'yours' => null]]],
            [$status, $e['code'] ?? null, $e['fields'] ?? null]);
        checkEq('the message is whole on its own', "'Team sync' wasn't deleted: since it was last shown, its place was changed in Google Calendar.", $e['message'] ?? null);
        checkEq('one read, nothing written to Google; the event lives at Google and here, here with Google\'s place', [['GET'], 'confirmed', ['location' => 'Sample Hall'] + $before],
            [$methods($sent($t, $m)), $atG($t)['status'] ?? null, $row($t)]);
        H::done($t);
    });
    scenario('Delete after the question deletes', function () use ($fresh, $send, $overOf, $allKeys, $atGoogle, $mark, $sent, $methods, $row, $atG): void {
        $t = $fresh();
        $over = $overOf($t, $allKeys);
        $atGoogle($t, ['location' => 'Sample Hall']);
        [$status, $json] = $send($t, 'delete', ['over' => $over]);
        checkEq('the question first', 409, $status);
        $m = $mark($t);
        $now = $json['error']['fields'][0]['now'] ?? 'Sample Hall';
        [$status] = $send($t, 'delete', ['over' => ['location' => $now] + $over]);
        checkEq('Delete: 200, one read and one DELETE; deleted at Google and here', [200, ['GET', 'DELETE'], 'cancelled', true],
            [$status, $methods($sent($t, $m)), $atG($t)['status'] ?? 'gone', $row($t)['deleted']]);
        H::done($t);
    });
    // "delete of an unchanged event sends one DELETE" is the delete in case 1's scenario.

    // Case 7.
    scenario('Maybe over a title change at Google: one PATCH of the status, `kept` names the title', function () use ($fresh, $send, $atGoogle, $mark, $sent, $methods, $row, $atG, $keptOf, $bodyKeys): void {
        $t = $fresh();
        $atGoogle($t, ['summary' => 'Standup']);
        $m = $mark($t);
        [$status, $json] = $send($t, 'relationship', ['relationship' => 'maybe']);
        $s = $sent($t, $m);
        checkEq('200; one read, one PATCH of the status alone, tentative', [200, ['GET', 'PATCH'], ['status'], 'tentative'],
            [$status, $methods($s), $bodyKeys($s[1][1] ?? null), $s[1][1]['status'] ?? null]);
        checkEq('Google and the row: tentative, Google\'s title; `kept` the title with its value now', [['tentative', 'Standup'], 'tentative', 'Standup', [['field' => 'title', 'now' => 'Standup']]],
            [[$atG($t)['status'] ?? null, $atG($t)['summary'] ?? null], $row($t)['status'], $row($t)['title'], $keptOf($json)]);
        H::done($t);
    });
    scenario('Maybe when Google already set it tentative: no question, nothing left to send', function () use ($fresh, $send, $atGoogle, $mark, $sent, $methods, $row, $keptOf): void {
        $t = $fresh();
        $atGoogle($t, ['status' => 'tentative']);
        $m = $mark($t);
        [$status, $json] = $send($t, 'relationship', ['relationship' => 'maybe']);
        checkEq('200, one read, nothing written to Google, nothing kept, the row tentative', [200, ['GET'], [], 'tentative'],
            [$status, $methods($sent($t, $m)), $keptOf($json), $row($t)['status']]);
        H::done($t);
    });

    // Case 8: an invitation in no series, from someone else, seeded at Google and polled in.
    $invite = static function (bool $on) use ($fresh, $db): object {
        $t = $fresh($on);
        $t->s->fake->seed(['id' => 'smplinvite1', 'iCalUID' => 'smplinvite1@google.com', 'summary' => 'Sample planning', 'etag' => '"i1"',
            'start' => ['dateTime' => '2027-03-05T18:00:00+00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2027-03-05T19:00:00+00:00', 'timeZone' => 'UTC'],
            'organizer' => ['email' => 'host@example.test'],
            'attendees' => [['email' => 'host@example.test', 'responseStatus' => 'accepted'], ['email' => 'owner@example.test', 'self' => true, 'responseStatus' => 'needsAction']]]);
        $t->s->poll();
        $t->id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$t->s->calendarId, 'smplinvite1']);
        $t->gid = 'smplinvite1';
        return $t;
    };
    scenario('answer over a title change at Google: one PATCH of the guest list, the row holds Google\'s title, `kept` names it', function () use ($invite, $send, $atGoogle, $mark, $sent, $methods, $row, $atG, $keptOf, $bodyKeys): void {
        $t = $invite(true);
        $atGoogle($t, ['summary' => 'Sample planning, new agenda']);
        $m = $mark($t);
        [$status, $json] = $send($t, 'rsvp', ['answer' => 'accepted']);
        $s = $sent($t, $m);
        $writes = array_values(array_filter($s, static fn(array $r): bool => $r[0] !== 'GET'));
        checkEq('200; one PATCH, of the guest list and no field of the six', [200, ['PATCH'], [], true],
            [$status, array_column($writes, 0), $bodyKeys($writes[0][1] ?? null), isset($writes[0][1]['attendees'])]);
        $self = array_values(array_filter((array) ($atG($t)['attendees'] ?? []), static fn(array $a): bool => !empty($a['self'])));
        checkEq('Google holds the answer and its own title; the row holds Google\'s title; `kept` names the title', ['accepted', 'Sample planning, new agenda', 'Sample planning, new agenda', [['field' => 'title', 'now' => 'Sample planning, new agenda']]],
            [$self[0]['responseStatus'] ?? null, $atG($t)['summary'] ?? null, $row($t)['title'], $keptOf($json)]);
        H::done($t);
    });

    // The damage list of section 7: a field sent at its base value is not put back over Google's after the store.
    scenario('a field sent at its served value is not wanted: Google\'s change to it is kept, only the changed field is sent', function () use ($fresh, $send, $overOf, $atGoogle, $mark, $sent, $methods, $row, $atG, $keptOf, $bodyKeys): void {
        $t = $fresh();
        $over = $overOf($t, ['title', 'location']);
        $atGoogle($t, ['summary' => 'Standup']);
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', ['title' => 'Team sync', 'location' => 'Room 5', 'over' => $over]);
        $s = $sent($t, $m);
        checkEq('200; one read, one PATCH of the place alone', [200, ['GET', 'PATCH'], ['location']], [$status, $methods($s), $bodyKeys($s[1][1] ?? null)]);
        checkEq('Google and the row keep Google\'s title and take the place; `kept` names the title', [['Standup', 'Room 5'], ['Standup', 'Room 5'], [['field' => 'title', 'now' => 'Standup']]],
            [[$atG($t)['summary'] ?? null, $atG($t)['location'] ?? null], [$row($t)['title'], $row($t)['location']], $keptOf($json)]);
        H::done($t);
    });

    // The damage list: a time is the whole value, never asked about by its end alone.
    scenario('a resize of the end over a start moved at Google asks about the time', function () use ($fresh, $send, $overOf, $atGoogle, $googleTime, $mark, $sent, $methods, $row, $instants): void {
        $t = $fresh();
        $over = $overOf($t, ['start', 'end', 'allDay']);
        $atGoogle($t, $googleTime('2027-03-03T17:00:00+00:00', '2027-03-03T19:00:00+00:00'));
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', ['end' => '2027-03-03T19:30:00+00:00', 'over' => $over]);
        $e = $json['error'] ?? [];
        $f = $e['fields'][0] ?? [];
        checkEq('409 google_changed about the time: was the served time, now Google\'s, yours the resized time; nothing written; the row holds Google\'s time', [
            409, 'google_changed', ['time'],
            [strtotime('2027-03-03T18:00:00+00:00'), strtotime('2027-03-03T19:00:00+00:00'), false],
            [strtotime('2027-03-03T17:00:00+00:00'), strtotime('2027-03-03T19:00:00+00:00'), false],
            [strtotime('2027-03-03T18:00:00+00:00'), strtotime('2027-03-03T19:30:00+00:00'), false],
            ['GET'], ['2027-03-03 17:00:00', '2027-03-03 19:00:00', 0],
        ], [$status, $e['code'] ?? null, array_column($e['fields'] ?? [], 'field'), $instants($f['was'] ?? null), $instants($f['now'] ?? null), $instants($f['yours'] ?? null),
            $methods($sent($t, $m)), $row($t)['time']]);
        H::done($t);
    });

    // The damage list: Overwrite sends what the person was shown as theirs, never the drag read again over the stored row.
    scenario('a drag over an event made all-day at Google asks, and Overwrite puts it at the dragged time, timed', function () use ($fresh, $send, $overOf, $atGoogle, $mark, $sent, $methods, $row, $atG, $instants): void {
        $t = $fresh();
        $over = $overOf($t, ['start', 'end', 'allDay']);
        $atGoogle($t, ['start' => ['date' => '2027-03-03', 'dateTime' => null, 'timeZone' => null], 'end' => ['date' => '2027-03-04', 'dateTime' => null, 'timeZone' => null]]);
        $body = ['start' => '2027-03-04T18:00:00+00:00', 'end' => '2027-03-04T19:00:00+00:00', 'over' => $over];
        [$status, $json] = $send($t, 'patch', $body);
        $f = $json['error']['fields'][0] ?? [];
        checkEq('409 google_changed about the time: now all-day, yours the dragged time, timed', [409, 'google_changed', 'time', true,
            [strtotime('2027-03-04T18:00:00+00:00'), strtotime('2027-03-04T19:00:00+00:00'), false]],
            [$status, $json['error']['code'] ?? null, $f['field'] ?? null, (bool) ($f['now']['allDay'] ?? false), $instants($f['yours'] ?? null)]);
        $m = $mark($t);
        $now = $f['now'] ?? [];
        [$status] = $send($t, 'patch', ['over' => ['start' => $now['start'] ?? null, 'end' => $now['end'] ?? null, 'allDay' => $now['allDay'] ?? null]] + $body);
        checkEq('Overwrite: 200, one PATCH; Google and the row hold the dragged time, timed', [200, ['GET', 'PATCH'], '2027-03-04T18:00:00+00:00', ['2027-03-04 18:00:00', '2027-03-04 19:00:00', 0]],
            [$status, $methods($sent($t, $m)), isset($atG($t)['start']['dateTime']) ? gmdate('Y-m-d\TH:i:s+00:00', strtotime($atG($t)['start']['dateTime'])) : 'all-day', $row($t)['time']]);
        H::done($t);
    });

    // Case 9: the setting off, as shipped: exactly 1.5.6.
    scenario('setting off: the 1.5.6 refusal for each of the above', function () use ($fresh, $invite, $send, $overOf, $allKeys, $atGoogle, $googleTime, $mark, $sent, $methods, $row): void {
        $refused = [409, 'google_conflict', GoogleWriter::CONFLICT_MESSAGE, ['GET']];
        $cases = [
            'a different field' => [static fn(object $t) => $atGoogle($t, $googleTime('2027-03-03T20:00:00+00:00', '2027-03-03T21:00:00+00:00')), 'patch', static fn(object $t): array => ['title' => 'Weekly sync', 'over' => $overOf($t, ['title'])]],
            'the same field' => [static fn(object $t) => $atGoogle($t, ['summary' => 'Standup']), 'patch', static fn(object $t): array => ['title' => 'Weekly sync', 'tagNames' => ['sample-tag'], 'over' => $overOf($t, ['title'])]],
            'the same value' => [static fn(object $t) => $atGoogle($t, ['summary' => 'Weekly sync']), 'patch', static fn(object $t): array => ['title' => 'Weekly sync', 'over' => $overOf($t, ['title'])]],
            'a delete over a place change' => [static fn(object $t) => $atGoogle($t, ['location' => 'Sample Hall']), 'delete', static fn(object $t): array => ['over' => $overOf($t, $allKeys)]],
            'Maybe over a title change' => [static fn(object $t) => $atGoogle($t, ['summary' => 'Standup']), 'relationship', static fn(object $t): array => ['relationship' => 'maybe']],
        ];
        foreach ($cases as $label => [$change, $action, $bodyOf]) {
            $t = $fresh(false);
            $body = $bodyOf($t);
            $change($t);
            $before = $row($t);
            $m = $mark($t);
            [$status, $json] = $send($t, $action, $body);
            checkEq("setting off, $label: 409 google_conflict with the 1.5.6 sentence after one read, and no row changed", [$refused, $before],
                [[$status, $json['error']['code'] ?? null, $json['error']['message'] ?? null, $methods($sent($t, $m))], $row($t)]);
            H::done($t);
        }
        $t = $invite(false);
        $atGoogle($t, ['summary' => 'Sample planning, new agenda']);
        $before = $row($t);
        $m = $mark($t);
        [$status, $json] = $send($t, 'rsvp', ['answer' => 'accepted']);
        checkEq('setting off, an answer over a title change: 409 google_conflict with the 1.5.6 sentence, nothing written to Google, no row changed', [[409, 'google_conflict', GoogleWriter::CONFLICT_MESSAGE, []], $before],
            [[$status, $json['error']['code'] ?? null, $json['error']['message'] ?? null, array_values(array_diff($methods($sent($t, $m)), ['GET']))], $row($t)]);
        H::done($t);
    });
};
