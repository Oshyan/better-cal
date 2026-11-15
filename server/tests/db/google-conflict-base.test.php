<?php

declare(strict_types=1);

// 1.5.7 slice 1, what a partial time request means (docs/design/google-conflict-choice.md, the page "1.5.7, Slice 1",
// (b) and (c)): a change of one event in no series on a Google calendar is read against what the person was shown, the
// request's `over`, else the row. The same request-level setup as google-conflict-choice.test.php: client-shaped
// requests through EventsController under the request's credential, `over` from the record the API serves, Google the
// project's stand-in, a change "at Google" another client's request to it. Every expectation is written here from the
// page: nothing in server/src is called to compute one. Invented data throughout.

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
    // Overwrite as the page's section (c) says the app sends it: the same request again, with, for each field in `fields`,
    // over[field] set to its `now` and the body's value for it set to its `yours` (the time whole: start, end, allDay); a
    // delete's yours is null and sets no body value.
    $overwrite = static function (array $body, array $json): array {
        foreach ((array) ($json['error']['fields'] ?? []) as $f) {
            $keys = $f['field'] === 'time' ? ['start', 'end', 'allDay'] : [$f['field']];
            foreach ($keys as $k) {
                $body['over'][$k] = $f['field'] === 'time' ? ($f['now'][$k] ?? null) : $f['now'];
                if ($f['yours'] !== null) {
                    $body[$k] = $f['field'] === 'time' ? ($f['yours'][$k] ?? null) : $f['yours'];
                }
            }
        }
        return $body;
    };
    $bodyKeys = static fn(?array $body): array => array_values(array_intersect(array_keys((array) $body), ['summary', 'description', 'location', 'source', 'status', 'start', 'end']));
    $googleTime = static fn(string $s, string $e): array => ['start' => ['dateTime' => $s, 'timeZone' => 'UTC'], 'end' => ['dateTime' => $e, 'timeZone' => 'UTC']];


    // The base of what a request means (the page, (b)): every reading of a partial time request (a drag's or a resize's
    // missing all-day setting, a start or an end alone, a typed clock, an all-day toggle, a zone) is made against what the
    // person was shown (`over`), never against a row a poll changed after they looked. Each kind of time input runs in both
    // orders: Google changed and not yet polled (the row is still what was shown), and Google changed and polled before
    // the request (the row already holds Google's change). Every expectation is worked out here from the shown state and
    // the request by the page's reading: a key the request sends is read as sent; a key it leaves out is the shown
    // value's; an all-day boundary not sent is the shown instant's date, an end on or before the start the next day.
    // The shown event: 'Team sync', 18:00 to 19:00 UTC on 3 March 2027 (or all day on 3 March, made so first).
    $iso = static fn(string $at): int => strtotime($at);
    $day = static fn(string $date): int => strtotime($date . 'T00:00:00+00:00');
    // What the stand-in holds as [start, end, allDay], instants.
    $googleHolds = static function (object $t) use ($atG, $day): array {
        $g = $atG($t);
        return isset($g['start']['date'])
            ? [$day($g['start']['date']), $day($g['end']['date']), true]
            : [strtotime($g['start']['dateTime']), strtotime($g['end']['dateTime']), false];
    };
    // The row's time as [start, end, allDay], instants.
    $rowHolds = static fn(object $t): array => (static fn(array $r): array => [strtotime($r[0] . ' UTC'), strtotime($r[1] . ' UTC'), (bool) $r[2]])($row($t)['time']);
    $timedAt = static fn(string $s, string $e): array => ['start' => ['date' => null, 'dateTime' => $s, 'timeZone' => 'UTC'], 'end' => ['date' => null, 'dateTime' => $e, 'timeZone' => 'UTC']];
    $allDayOn = static fn(string $s, string $e): array => ['start' => ['date' => $s, 'dateTime' => null, 'timeZone' => null], 'end' => ['date' => $e, 'dateTime' => null, 'timeZone' => null]];

    // [name, shown all-day?, Google's change, Google's time after it, the request (no over), yours as the page reads it].
    $cases = [
        ['drag of a timed event (start and end, no allDay) over Google making it all-day', false,
            $allDayOn('2027-03-03', '2027-03-04'), [$day('2027-03-03'), $day('2027-03-04'), true],
            ['start' => '2027-03-05T18:00:00+00:00', 'end' => '2027-03-05T19:00:00+00:00'],
            [$iso('2027-03-05T18:00:00+00:00'), $iso('2027-03-05T19:00:00+00:00'), false]],
        ['resize of a timed event (start and end, no allDay) over Google making it all-day', false,
            $allDayOn('2027-03-03', '2027-03-04'), [$day('2027-03-03'), $day('2027-03-04'), true],
            ['start' => '2027-03-03T18:00:00+00:00', 'end' => '2027-03-03T19:30:00+00:00'],
            [$iso('2027-03-03T18:00:00+00:00'), $iso('2027-03-03T19:30:00+00:00'), false]],
        ['drag of an all-day event (dates, no allDay) over Google making it timed', true,
            $timedAt('2027-03-03T20:00:00Z', '2027-03-03T21:00:00Z'), [$iso('2027-03-03T20:00:00+00:00'), $iso('2027-03-03T21:00:00+00:00'), false],
            ['start' => '2027-03-05', 'end' => '2027-03-06'],
            [$day('2027-03-05'), $day('2027-03-06'), true]],
        ['start alone over Google moving the time: the end is the shown end', false,
            $timedAt('2027-03-03T20:00:00Z', '2027-03-03T21:00:00Z'), [$iso('2027-03-03T20:00:00+00:00'), $iso('2027-03-03T21:00:00+00:00'), false],
            ['start' => '2027-03-03T18:30:00+00:00'],
            [$iso('2027-03-03T18:30:00+00:00'), $iso('2027-03-03T19:00:00+00:00'), false]],
        ['end alone over Google moving the time: the start is the shown start', false,
            $timedAt('2027-03-03T20:00:00Z', '2027-03-03T21:00:00Z'), [$iso('2027-03-03T20:00:00+00:00'), $iso('2027-03-03T21:00:00+00:00'), false],
            ['end' => '2027-03-03T19:30:00+00:00'],
            [$iso('2027-03-03T18:00:00+00:00'), $iso('2027-03-03T19:30:00+00:00'), false]],
        ['typed clock (no allDay) over Google making it all-day', false,
            $allDayOn('2027-03-03', '2027-03-04'), [$day('2027-03-03'), $day('2027-03-04'), true],
            ['start' => '2027-03-05T18:00:00+00:00', 'end' => '2027-03-05T19:00:00+00:00', 'typed' => ['start' => '2027-03-05T18:00', 'end' => '2027-03-05T19:00', 'tz' => 'UTC']],
            [$iso('2027-03-05T18:00:00+00:00'), $iso('2027-03-05T19:00:00+00:00'), false]],
        ['all-day toggle on (allDay alone) over Google moving the time to another day: the shown day', false,
            $timedAt('2027-03-07T20:00:00Z', '2027-03-07T21:00:00Z'), [$iso('2027-03-07T20:00:00+00:00'), $iso('2027-03-07T21:00:00+00:00'), false],
            ['allDay' => true],
            [$day('2027-03-03'), $day('2027-03-04'), true]],
        ['all-day toggle off (allDay alone) over Google moving it to another day: the shown day\'s bounds, timed', true,
            $allDayOn('2027-03-07', '2027-03-08'), [$day('2027-03-07'), $day('2027-03-08'), true],
            ['allDay' => false],
            [$day('2027-03-03'), $day('2027-03-04'), false]],
        ['timed to all-day, whole (allDay, start, end) over Google moving the time', false,
            $timedAt('2027-03-03T20:00:00Z', '2027-03-03T21:00:00Z'), [$iso('2027-03-03T20:00:00+00:00'), $iso('2027-03-03T21:00:00+00:00'), false],
            ['allDay' => true, 'start' => '2027-03-05', 'end' => '2027-03-06'],
            [$day('2027-03-05'), $day('2027-03-06'), true]],
        ['all-day to timed, whole (allDay, start, end) over Google moving it to another day', true,
            $allDayOn('2027-03-07', '2027-03-08'), [$day('2027-03-07'), $day('2027-03-08'), true],
            ['allDay' => false, 'start' => '2027-03-05T09:00:00+00:00', 'end' => '2027-03-05T10:00:00+00:00'],
            [$iso('2027-03-05T09:00:00+00:00'), $iso('2027-03-05T10:00:00+00:00'), false]],
        ['zone change keeping the clock (tzid, start, end, no allDay) over Google making it all-day', false,
            $allDayOn('2027-03-03', '2027-03-04'), [$day('2027-03-03'), $day('2027-03-04'), true],
            ['tzid' => 'America/New_York', 'start' => '2027-03-03T18:00:00-05:00', 'end' => '2027-03-03T19:00:00-05:00'],
            [$iso('2027-03-03T23:00:00+00:00'), $iso('2027-03-04T00:00:00+00:00'), false]],
    ];
    foreach ($cases as [$name, $shownAllDay, $change, $theirs, $request, $yours]) {
        foreach (['not yet polled' => false, 'polled before the request' => true] as $order => $polled) {
            scenario("$name, $order: asks with yours as the page reads it, and Overwrite saves exactly that", function () use ($fresh, $send, $overOf, $atGoogle, $overwrite, $instants, $googleHolds, $rowHolds, $mark, $sent, $methods, $name, $order, $shownAllDay, $change, $theirs, $request, $yours, $polled): void {
                $t = $fresh();
                if ($shownAllDay) {
                    $send($t, 'patch', ['allDay' => true, 'start' => '2027-03-03', 'end' => '2027-03-04']);
                }
                $over = $overOf($t, ['start', 'end', 'allDay']);
                $was = $instants($over);
                $atGoogle($t, $change);
                if ($polled) {
                    $t->s->poll();
                }
                $body = $request + ['over' => $over];
                $m = $mark($t);
                [$status, $json] = $send($t, 'patch', $body);
                $f = $json['error']['fields'][0] ?? [];
                checkEq("$name, $order: " . '409 google_changed about the time: was as shown, now Google\'s, yours read against the shown time; nothing sent; the row holds Google\'s time',
                    [409, 'google_changed', ['time'], $was, $theirs, $yours, ['GET'], $theirs],
                    [$status, $json['error']['code'] ?? null, array_column($json['error']['fields'] ?? [], 'field'), $instants($f['was'] ?? null), $instants($f['now'] ?? null), $instants($f['yours'] ?? null),
                        $methods($sent($t, $m)), $rowHolds($t)]);
                $m = $mark($t);
                [$status] = $send($t, 'patch', $overwrite($body, $json));
                checkEq("$name, $order: " . 'Overwrite (over = now, the body = yours): 200, one PATCH; Google and the row hold exactly what the question showed as yours',
                    [200, ['GET', 'PATCH'], $yours, $yours], [$status, $methods($sent($t, $m)), $googleHolds($t), $rowHolds($t)]);
                H::done($t);
            });
        }
    }

    // A zone alone touches no field: the time is not wanted, so Google's change to it is kept, in both orders.
    foreach (['not yet polled' => false, 'polled before the request' => true] as $order => $polled) {
        scenario("zone alone over Google moving the time, $order: no question, Google's time kept and named, the zone written", function () use ($fresh, $send, $overOf, $atGoogle, $timedAt, $rowHolds, $googleHolds, $keptOf, $row, $db, $iso, $order, $polled): void {
            $t = $fresh();
            $over = $overOf($t, ['start', 'end', 'allDay']);
            $atGoogle($t, $timedAt('2027-03-03T20:00:00Z', '2027-03-03T21:00:00Z'));
            if ($polled) {
                $t->s->poll();
            }
            [$status, $json] = $send($t, 'patch', ['tzid' => 'America/New_York', 'over' => $over]);
            $theirs = [$iso('2027-03-03T20:00:00+00:00'), $iso('2027-03-03T21:00:00+00:00'), false];
            checkEq("zone alone, $order: " . '200; kept names the time; the row and Google hold Google\'s time; the row\'s zone is the one sent',
                [200, ['time'], $theirs, $theirs, 'America/New_York'],
                [$status, array_column((array) $keptOf((array) $json), 'field'), $rowHolds($t), $googleHolds($t), $db->scalar('SELECT tzid FROM events WHERE id = ?', [$t->id])]);
            H::done($t);
        });
    }

    // Whose clock a floating event's times are on is read from the shown event too (fromViewer): a timed drag on a device
    // in another zone than the event's, over Google making it all-day. The drag's times are the device's wall clock, so
    // yours is the dragged instants as the device names them, and after Overwrite the event as served is exactly that.
    foreach (['not yet polled' => false, 'polled before the request' => true] as $order => $polled) {
        scenario("floating event dragged on a device in another zone over Google making it all-day, $order: yours is the dragged clock, and Overwrite saves it", function () use ($fresh, $send, $overOf, $atGoogle, $allDayOn, $instants, $iso, $overwrite, $order, $polled): void {
            $t = $fresh();
            $send($t, 'patch', ['floating' => true]);
            $t->s->events->viewFrom('America/New_York');
            try {
                $over = $overOf($t, ['start', 'end', 'allDay']);
                $atGoogle($t, $allDayOn('2027-03-03', '2027-03-04'));
                if ($polled) {
                    $t->s->poll();
                }
                $body = ['start' => '2027-03-05T18:00:00-05:00', 'end' => '2027-03-05T19:00:00-05:00', 'over' => $over];
                $dragged = [$iso('2027-03-05T18:00:00-05:00'), $iso('2027-03-05T19:00:00-05:00'), false];
                [$status, $json] = $send($t, 'patch', $body);
                checkEq("floating, $order: 409; yours the dragged instants on the device's clock, timed", [409, $dragged],
                    [$status, $instants($json['error']['fields'][0]['yours'] ?? null)]);
                [$status] = $send($t, 'patch', $overwrite($body, $json));
                $served = $t->s->events->serializeSingle($t->s->events->get($t->u, $t->id));
                checkEq("floating, $order: Overwrite 200; the event as served is the dragged time, still floating", [200, $dragged, true],
                    [$status, $instants($served), (bool) ($served['floating'] ?? false)]);
            } finally {
                $t->s->events->viewFrom(null);
            }
            H::done($t);
        });
    }

    // With no `over` (an API caller, a plugin, Review) the base is the row, as the page's unlikely cases say: a drag
    // after a poll stored Google's all-day change is read against the all-day row (a move of the day) and not asked
    // about; before the poll the row is what was shown, and the drag asks with the dragged time.
    scenario('no over, polled before the request: the base is the row; a timed drag of the all-day row moves its day, no question', function () use ($fresh, $send, $atGoogle, $allDayOn, $rowHolds, $googleHolds, $day): void {
        $t = $fresh();
        $atGoogle($t, $allDayOn('2027-03-03', '2027-03-04'));
        $t->s->poll();
        [$status] = $send($t, 'patch', ['start' => '2027-03-05T18:00:00+00:00', 'end' => '2027-03-05T19:00:00+00:00']);
        $moved = [$day('2027-03-05'), $day('2027-03-06'), true];
        checkEq('200; the row and Google hold all day on 5 March', [200, $moved, $moved], [$status, $rowHolds($t), $googleHolds($t)]);
        H::done($t);
    });
    scenario('no over, not yet polled: the base is the row as shown; a timed drag asks with the dragged time', function () use ($fresh, $send, $atGoogle, $allDayOn, $instants, $iso): void {
        $t = $fresh();
        $atGoogle($t, $allDayOn('2027-03-03', '2027-03-04'));
        [$status, $json] = $send($t, 'patch', ['start' => '2027-03-05T18:00:00+00:00', 'end' => '2027-03-05T19:00:00+00:00']);
        checkEq('409 google_changed; yours the dragged time, timed', [409, [$iso('2027-03-05T18:00:00+00:00'), $iso('2027-03-05T19:00:00+00:00'), false]],
            [$status, $instants($json['error']['fields'][0]['yours'] ?? null)]);
        H::done($t);
    });
};
