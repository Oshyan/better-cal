<?php

declare(strict_types=1);

// 1.5.7 slice 1, a time is one value (docs/design/google-conflict-choice.md, the page "1.5.7, Slice 1", (b) T1 to T4,
// and (c)). On the conflict choice's path (a change or delete of one event in no series on a Google calendar), a
// request carrying `over` sends a time whole (start, end, allDay, tzid) or not at all, `over` names it whole or not at
// all, and anything else is 400 client_outdated with nothing read from Google and nothing written; the base of the
// comparison is `over`'s time read in its own kind and zone; with no `over` the request is read against the row as in
// 1.5.6. The same request-level setup as google-conflict-choice.test.php: client-shaped requests through
// EventsController under the request's credential, `over` from the record the API serves, Google the project's
// stand-in, a change "at Google" another client's request to it.
//
// The scenarios are one table: every sender of a time on this path as the server sees it, crossed with `over` whole,
// `over`'s time in parts and no `over`; the event shown all-day or timed; Google's copy timed in a zone west of UTC,
// east of UTC, in UTC, or all-day; polled before the request or not. Every expectation is worked out here from the
// shown time, Google's time and the request by the page's reading; nothing in server/src is called to compute one.
// Invented data throughout.

use BetterCal\Domain\Auth;
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
    // The words of the refusal, from the page's (b) and the API contract.
    $outdated = 'Better-Cal was updated. Reload the page and make the change again.';

    // An account with the setting on, a Google calendar at the stand-in, and one event in no series made through the
    // app (so Google holds it): 'Team sync', 18:00 to 19:00 UTC on 3 March 2027, or all day 3 to 5 March when shown so.
    $fresh = static function (bool $shownAllDay) use ($db): object {
        $u = freshUser($db);
        conflictChoice($db, $u, true);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $more = $shownAllDay ? ['allDay' => true, 'start' => '2027-03-03', 'end' => '2027-03-06', 'tzid' => 'UTC'] : [];
        $id = (int) $s->events->create($u, array_merge(eventAt($s->calendarId, 'Team sync', '2027-03-03'), $more))['eventId'];
        $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
        $token = 'sample-session-' . bin2hex(random_bytes(8));
        $db->insert('sessions', ['token_hash' => hash('sha256', $token), 'user_id' => $u, 'csrf' => str_repeat('c', 32), 'authenticated_at' => Time::nowDb(), 'expires_at' => '2099-01-01 00:00:00']);
        $controller = new EventsController($s->events, new Trips($db, $d->undo), new Rsvp($db, new EmailSender([]), [], $s->writer));
        return (object) ['db' => $db, 'u' => $u, 'd' => $d, 's' => $s, 'id' => $id, 'gid' => $gid, 'token' => $token, 'controller' => $controller];
    };

    // One request as the router answers it: [status, decoded body].
    $send = static function (object $t, string $action, array $body): array {
        $method = ['patch' => 'PATCH', 'delete' => 'DELETE', 'relationship' => 'POST'][$action];
        $req = new Request($method, '/api/v1/events/' . $t->id . ($method === 'POST' ? "/$action" : ''), [], $body, [], [Auth::COOKIE => $t->token]);
        $req->user = ['id' => $t->u];
        $req->authMethod = 'session';
        try {
            $res = RequestAuthority::during(RequestAuthority::of($req), fn() => $t->controller->$action($req, ['id' => (string) $t->id]), true);
            $out = [$res->status, json_decode($res->body, true)];
        } catch (HttpError $e) {
            $out = [$e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra]];
        } finally {
            \BetterCal\Domain\ActivityContext::takeEntries();
        }
        return $out;
    };

    // The record the API serves for the event (GET /events/:id/occurrence).
    $served = static fn(object $t): array => $t->s->events->serializeSingle($t->s->events->get($t->u, $t->id));
    $timeKeys = ['start', 'end', 'allDay', 'tzid'];
    $allKeys = ['title', 'description', 'location', 'url', 'status', ...$timeKeys];

    $mark = static fn(object $t): int => count($t->s->fake->requests);
    // The app's requests to the stand-in since $from (their methods), the other client's left out.
    $sentSince = static fn(object $t, int $from): array => array_values(array_map(static fn(array $r): string => $r['method'],
        array_filter(array_slice($t->s->fake->requests, $from), static fn(array $r): bool => !in_array('X-Harness: remote', $r['headers'], true))));
    $atG = static fn(object $t): array => $t->s->fake->events[$t->gid];
    $entries = static fn(object $t): int => (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$t->u]);

    // A time as [start, end, allDay], instants (an all-day time's boundaries as UTC midnights of its dates).
    $day = static fn(string $date): int => (int) strtotime(substr($date, 0, 10) . 'T00:00:00+00:00');
    $timeOf = static fn(array $v): array => !empty($v['allDay'])
        ? [$day((string) $v['start']), $day((string) $v['end']), true]
        : [(int) strtotime((string) $v['start']), (int) strtotime((string) $v['end']), false];
    $instants = static fn(?array $v): ?array => $v === null ? null : $timeOf($v);
    // What the stand-in holds, and what the row holds, as the same triple; the row's zone beside it.
    $googleHolds = static function (object $t) use ($atG, $day): array {
        $g = $atG($t);
        return isset($g['start']['date'])
            ? [$day($g['start']['date']), $day($g['end']['date']), true]
            : [(int) strtotime($g['start']['dateTime']), (int) strtotime($g['end']['dateTime']), false];
    };
    $rowHolds = static function (object $t) use ($db): array {
        $r = $db->one('SELECT start_utc, end_utc, all_day, tzid, deleted_at FROM events WHERE id = ?', [$t->id]);
        return $r === null ? ['deleted'] : [(int) strtotime($r['start_utc'] . ' UTC'), (int) strtotime($r['end_utc'] . ' UTC'), (int) $r['all_day'] === 1];
    };
    $rowZone = static fn(object $t): ?string => $db->scalar('SELECT tzid FROM events WHERE id = ?', [$t->id]);

    // Google's copies: the time another client sets at Google, as the stand-in takes it, and what it is.
    $googles = [
        'Google timed west of UTC' => [['start' => ['date' => null, 'dateTime' => '2027-03-03T18:00:00-05:00', 'timeZone' => 'America/New_York'], 'end' => ['date' => null, 'dateTime' => '2027-03-03T19:00:00-05:00', 'timeZone' => 'America/New_York']],
            [(int) strtotime('2027-03-03T18:00:00-05:00'), (int) strtotime('2027-03-03T19:00:00-05:00'), false]],
        'Google timed east of UTC' => [['start' => ['date' => null, 'dateTime' => '2027-03-03T18:00:00+05:30', 'timeZone' => 'Asia/Kolkata'], 'end' => ['date' => null, 'dateTime' => '2027-03-03T19:00:00+05:30', 'timeZone' => 'Asia/Kolkata']],
            [(int) strtotime('2027-03-03T18:00:00+05:30'), (int) strtotime('2027-03-03T19:00:00+05:30'), false]],
        'Google timed in UTC' => [['start' => ['date' => null, 'dateTime' => '2027-03-03T20:00:00Z', 'timeZone' => 'UTC'], 'end' => ['date' => null, 'dateTime' => '2027-03-03T21:00:00Z', 'timeZone' => 'UTC']],
            [(int) strtotime('2027-03-03T20:00:00Z'), (int) strtotime('2027-03-03T21:00:00Z'), false]],
        'Google all-day' => [['start' => ['date' => '2027-03-07', 'dateTime' => null, 'timeZone' => null], 'end' => ['date' => '2027-03-08', 'dateTime' => null, 'timeZone' => null]],
            [$day('2027-03-07'), $day('2027-03-08'), true]],
    ];
    $atGoogle = static function (object $t, array $body): void {
        [$status] = H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid), $body);
        if ($status !== 200) {
            throw new \RuntimeException("the stand-in refused the change at Google: $status");
        }
    };

    // Moves of a served time, the way each sender makes them.
    $shift = static function (array $v, string $by): array {
        $at = static fn(string $iso): string => $v['allDay']
            ? (new \DateTimeImmutable(substr($iso, 0, 10), new \DateTimeZone('UTC')))->modify($by)->format('Y-m-d')
            : (new \DateTimeImmutable($iso))->modify($by)->format('Y-m-d\TH:i:sP');
        return ['start' => $at((string) $v['start']), 'end' => $at((string) $v['end'])] + $v;
    };
    $endBy = static function (array $v, string $by): array {
        $end = $v['allDay']
            ? (new \DateTimeImmutable(substr((string) $v['end'], 0, 10), new \DateTimeZone('UTC')))->modify($by)->format('Y-m-d')
            : (new \DateTimeImmutable((string) $v['end']))->modify($by)->format('Y-m-d\TH:i:sP');
        return ['end' => $end] + $v;
    };
    // Every sender of a time on this path, each a whole request made from the time as shown ($v, the served four keys).
    // An extend of a trip sends what a resize sends, and a trip's move what a drag sends; Overwrite's retry is each
    // asking row's second request; delete, Planned or Maybe send no time and are rows of their own below.
    $senders = [
        'drag' => static fn(array $v): array => $shift($v, '+2 days'),
        'resize or extend' => static fn(array $v): array => $endBy($v, $v['allDay'] ? '+1 day' : '+30 minutes'),
        'resize of the start' => static fn(array $v): array => ['start' => $shift($v, $v['allDay'] ? '-1 day' : '-30 minutes')['start']] + $v,
        'editor save (title and time)' => static fn(array $v): array => ['title' => 'Weekly sync'] + $shift($v, '+1 day'),
        'typed clock' => static fn(array $v): array => ['start' => '2027-03-04T09:00:00+00:00', 'end' => '2027-03-04T10:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC',
            'typed' => ['start' => '2027-03-04T09:00', 'end' => '2027-03-04T10:00', 'tz' => 'UTC']],
        'all-day switch' => static fn(array $v): array => $v['allDay']
            ? ['start' => substr((string) $v['start'], 0, 10) . 'T09:00:00+00:00', 'end' => substr((string) $v['start'], 0, 10) . 'T10:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC']
            : ['start' => substr((string) $v['start'], 0, 10), 'end' => (new \DateTimeImmutable(substr((string) $v['start'], 0, 10)))->modify('+1 day')->format('Y-m-d'), 'allDay' => true, 'tzid' => 'UTC'],
        // The same clock in another zone: an all-day time has no clock, so it is the same time.
        'zone change' => static fn(array $v): array => $v['allDay'] ? ['tzid' => 'Asia/Tokyo'] + $v
            : ['start' => substr((string) $v['start'], 0, 19) . '+09:00', 'end' => substr((string) $v['end'], 0, 19) . '+09:00', 'tzid' => 'Asia/Tokyo'] + $v,
    ];
    // What a whole request's time is, as the page reads it: the time sent, nothing from anywhere else.
    $yoursOf = static fn(array $req) => $timeOf($req);
    // A time's zone as stored: an all-day time's is UTC, a timed one's the zone sent.
    $zoneOf = static fn(array $req): string => $req['allDay'] ? 'UTC' : Time::normalizeTzid((string) $req['tzid']);

    // Overwrite as the page's (c) says the app sends it: the same request again, with over[field] set to each asked
    // field's `now` and the body's value for it to its `yours`, the time's four keys together.
    $overwrite = static function (array $body, array $json) use ($timeKeys): array {
        foreach ((array) ($json['error']['fields'] ?? []) as $f) {
            foreach ($f['field'] === 'time' ? $timeKeys : [$f['field']] as $k) {
                $body['over'][$k] = $f['field'] === 'time' ? ($f['now'][$k] ?? null) : $f['now'];
                if ($f['yours'] !== null) {
                    $body[$k] = $f['field'] === 'time' ? ($f['yours'][$k] ?? null) : $f['yours'];
                }
            }
        }
        return $body;
    };

    // The table's other two columns.
    $shownKinds = ['shown timed' => false, 'shown all-day' => true];
    $orders = ['not yet polled' => false, 'polled' => true];

    // Set up one row of the table: the event as shown (and `over` from it), Google's change, the poll if any.
    $setUp = static function (bool $shownAllDay, array $change, bool $polled) use ($fresh, $served, $atGoogle, $allKeys): array {
        $t = $fresh($shownAllDay);
        $shown = array_intersect_key($served($t), array_flip($allKeys));
        $atGoogle($t, $change);
        if ($polled) {
            $t->s->poll();
        }
        return [$t, $shown];
    };

    // A refusal: 400 client_outdated with the page's words, nothing sent to Google (not even the read), the row's time
    // and Google's as they were, no entry written.
    $refused = static function (string $name, object $t, string $action, array $body) use ($send, $mark, $sentSince, $rowHolds, $googleHolds, $entries, $outdated): void {
        [$row, $google, $count, $m] = [$rowHolds($t), $googleHolds($t), $entries($t), $mark($t)];
        [$status, $json] = $send($t, $action, $body);
        checkEq("$name: 400 client_outdated, the page's words; nothing read from Google or sent; the row, Google and Activity as they were",
            [400, 'client_outdated', $outdated, [], $row, $google, $count],
            [$status, $json['error']['code'] ?? null, $json['error']['message'] ?? null, $sentSince($t, $m), $rowHolds($t), $googleHolds($t), $entries($t)]);
    };

    // A whole request, by the three-way reading: the time compared with $base (what `over` says was shown, or the row
    // with no `over`), Google's now and yours. Asked: 409 about the time with was, now and yours, only the read sent,
    // the row holding Google's time; then Overwrite: 200, one PATCH, Google and the row holding exactly yours, in its
    // zone. Wanted and not asked: 200, Google and the row hold yours. Not wanted: 200, Google's time kept and named, unless
    // the row already holds the time sent, when nothing goes to Google and nothing is read.
    $threeWay = static function (string $name, object $t, array $body, array $base, array $theirs, array $yours, string $zone)
        use ($send, $mark, $sentSince, $rowHolds, $googleHolds, $rowZone, $instants, $overwrite, $atG): void {
        $before = $rowHolds($t);
        $m = $mark($t);
        [$status, $json] = $send($t, 'patch', $body);
        $title = isset($body['title']) ? [$body['title'], $body['title']] : null;
        if ($yours === $base && $yours === $before) {
            // Nothing for Google: the step reads nothing (it runs only for a request that sends Google something).
            checkEq("$name: the time is not wanted and the row already holds it: 200, nothing read or sent, nothing kept; Google holds its own time",
                [200, [], [], $theirs, $before], [$status, $sentSince($t, $m), array_column((array) ($json['kept'] ?? []), 'field'), $googleHolds($t), $rowHolds($t)]);
            return;
        }
        if ($yours === $base) {
            checkEq("$name: the time is not wanted: 200, Google's time kept" . ($theirs !== $base ? ' and named' : '') . ', Google and the row hold it',
                [200, $theirs !== $base ? ['time'] : [], $theirs, $theirs],
                [$status, array_column((array) ($json['kept'] ?? []), 'field'), $googleHolds($t), $rowHolds($t)]);
            return;
        }
        if ($theirs === $base || $theirs === $yours) {
            checkEq("$name: wanted, Google as the base or as yours: 200, one read and one write; Google and the row hold yours in its zone",
                [200, $theirs === $yours ? ['GET'] : ['GET', 'PATCH'], $yours, $yours, $zone],
                [$status, $sentSince($t, $m), $googleHolds($t), $rowHolds($t), $rowZone($t)]);
            return;
        }
        $f = $json['error']['fields'] ?? [];
        checkEq("$name: 409 google_changed about the time: was the base, now Google's, yours the time sent; only the read sent; the row holds Google's time",
            [409, 'google_changed', ['time'], $base, $theirs, $yours, ['GET'], $theirs],
            [$status, $json['error']['code'] ?? null, array_column($f, 'field'), $instants($f[0]['was'] ?? null), $instants($f[0]['now'] ?? null), $instants($f[0]['yours'] ?? null),
                $sentSince($t, $m), $rowHolds($t)]);
        $m = $mark($t);
        [$status] = $send($t, 'patch', $overwrite($body, $json));
        checkEq("$name: Overwrite: 200, one read and one PATCH; Google and the row hold exactly yours, in its zone" . ($title !== null ? ', and the title' : ''),
            [200, ['GET', 'PATCH'], $yours, $yours, $zone, $title],
            [$status, $sentSince($t, $m), $googleHolds($t), $rowHolds($t), $rowZone($t), $title === null ? null : [$atG($t)['summary'] ?? null, $t->db->scalar('SELECT title FROM events WHERE id = ?', [$t->id])]]);
    };

    foreach ($shownKinds as $kind => $shownAllDay) {
        foreach ($googles as $gName => [$change, $theirs]) {
            foreach ($orders as $order => $polled) {
                $cell = "$kind, $gName, $order";

                // `over` whole, every sender: asked over Google's change, Overwrite saves exactly yours.
                foreach ($senders as $sName => $make) {
                    scenario("over whole, $sName, $cell", function () use ($setUp, $shownAllDay, $change, $polled, $make, $timeKeys, $timeOf, $yoursOf, $zoneOf, $threeWay, $theirs, $sName, $cell): void {
                        [$t, $shown] = $setUp($shownAllDay, $change, $polled);
                        $time = array_intersect_key($shown, array_flip($timeKeys));
                        $req = $make($time);
                        $threeWay("over whole, $sName, $cell", $t, $req + ['over' => $time], $timeOf($time), $theirs, $yoursOf($req), $zoneOf($req));
                        H::done($t);
                    });
                }

                // `over` whole, the senders of no time: a delete asks (every field wanted) and Delete deletes; Planned or
                // Maybe keeps Google's time and names it.
                scenario("over whole, delete, $cell", function () use ($setUp, $shownAllDay, $change, $polled, $send, $overwrite, $mark, $sentSince, $rowHolds, $instants, $timeOf, $theirs, $cell): void {
                    [$t, $shown] = $setUp($shownAllDay, $change, $polled);
                    $m = $mark($t);
                    [$status, $json] = $send($t, 'delete', ['over' => $shown]);
                    $f = $json['error']['fields'] ?? [];
                    checkEq("over whole, delete, $cell: 409 about the time, was as shown, now Google's, yours null; only the read sent",
                        [409, 'google_changed', ['time'], $timeOf($shown), $theirs, null, ['GET']],
                        [$status, $json['error']['code'] ?? null, array_column($f, 'field'), $instants($f[0]['was'] ?? null), $instants($f[0]['now'] ?? null), array_key_exists('yours', $f[0] ?? []) ? $f[0]['yours'] : 'missing', $sentSince($t, $m)]);
                    [$status] = $send($t, 'delete', $overwrite(['over' => $shown], $json));
                    checkEq("over whole, delete, $cell: Delete: 200, the row gone", [200, ['deleted']], [$status, $rowHolds($t)]);
                    H::done($t);
                });
                scenario("over whole, Planned or Maybe, $cell", function () use ($setUp, $shownAllDay, $change, $polled, $send, $googleHolds, $rowHolds, $atG, $theirs, $cell): void {
                    [$t, $shown] = $setUp($shownAllDay, $change, $polled);
                    [$status, $json] = $send($t, 'relationship', ['relationship' => 'maybe', 'over' => $shown]);
                    checkEq("over whole, Maybe, $cell: 200, Google's time kept and named, Google tentative, Google and the row hold Google's time",
                        [200, ['time'], 'tentative', $theirs, $theirs],
                        [$status, array_column((array) ($json['kept'] ?? []), 'field'), $atG($t)['status'] ?? null, $googleHolds($t), $rowHolds($t)]);
                    H::done($t);
                });

                // `over`'s time in parts (the shape a tab older than the rule sends: no zone; or a start and end alone):
                // every sender, a delete and Maybe are refused with nothing read or written.
                foreach (['no zone' => ['start', 'end', 'allDay'], 'start and end alone' => ['start', 'end']] as $partName => $partKeys) {
                    foreach ($senders as $sName => $make) {
                        scenario("over in parts ($partName), $sName, $cell", function () use ($setUp, $shownAllDay, $change, $polled, $make, $timeKeys, $partKeys, $refused, $sName, $partName, $cell): void {
                            [$t, $shown] = $setUp($shownAllDay, $change, $polled);
                            $over = array_diff_key($shown, array_flip(array_diff($timeKeys, $partKeys)));
                            $refused("over in parts ($partName), $sName, $cell", $t, 'patch', $make(array_intersect_key($shown, array_flip($timeKeys))) + ['over' => $over]);
                            H::done($t);
                        });
                    }
                    scenario("over in parts ($partName), delete and Maybe, $cell", function () use ($setUp, $shownAllDay, $change, $polled, $timeKeys, $partKeys, $refused, $partName, $cell): void {
                        [$t, $shown] = $setUp($shownAllDay, $change, $polled);
                        $over = array_diff_key($shown, array_flip(array_diff($timeKeys, $partKeys)));
                        $refused("over in parts ($partName), delete, $cell", $t, 'delete', ['over' => $over]);
                        $refused("over in parts ($partName), Maybe, $cell", $t, 'relationship', ['relationship' => 'maybe', 'over' => $over]);
                        H::done($t);
                    });
                }

                // `over` whole and the body's time in parts: the shapes a tab older than the rule sends (a drag's start and
                // end, a resize's end alone, a start alone, the all-day switch alone, a zone alone, a typed clock alone,
                // a time with no zone). The second outside read's two cases (an end alone and a start alone over an
                // all-day event Google made timed west of UTC, polled) are rows here.
                scenario("body in parts, over whole, $cell", function () use ($setUp, $shownAllDay, $change, $polled, $timeKeys, $refused, $shift, $endBy, $cell): void {
                    [$t, $shown] = $setUp($shownAllDay, $change, $polled);
                    $over = $shown;
                    $time = array_intersect_key($shown, array_flip($timeKeys));
                    $moved = $shift($time, '+2 days');
                    $parts = [
                        'a drag (start and end)' => ['start' => $moved['start'], 'end' => $moved['end']],
                        'an end alone' => ['end' => $endBy($time, '+1 day')['end']],
                        'a start alone' => ['start' => $shift($time, '-1 day')['start']],
                        'the all-day switch alone' => ['allDay' => !$time['allDay']],
                        'a zone alone' => ['tzid' => 'America/New_York'],
                        'a typed clock alone' => ['typed' => ['start' => '2027-03-04T09:00', 'end' => '2027-03-04T10:00', 'tz' => 'UTC']],
                        'a time with no zone' => array_diff_key($moved, ['tzid' => true]),
                        // Whole, but no state: a timed moment, as POST /events refuses it.
                        'a timed moment (the end on the start)' => ['start' => '2027-03-04T20:00:00-05:00', 'end' => '2027-03-04T20:00:00-05:00', 'allDay' => false, 'tzid' => 'America/New_York'],
                    ];
                    foreach ($parts as $pName => $body) {
                        $refused("body in parts ($pName), over whole, $cell", $t, 'patch', $body + ['over' => $over]);
                    }
                    H::done($t);
                });

                // `over` whole that is no event state: refused the same way.
                scenario("over no state, $cell", function () use ($setUp, $shownAllDay, $change, $polled, $timeKeys, $refused, $shift, $cell): void {
                    [$t, $shown] = $setUp($shownAllDay, $change, $polled);
                    $time = array_intersect_key($shown, array_flip($timeKeys));
                    $req = $shift($time, '+2 days');
                    $bad = [
                        'an all-day time with the end on the start' => ['allDay' => true, 'start' => '2027-03-03', 'end' => '2027-03-03', 'tzid' => 'UTC'],
                        'an all-day time whose boundaries are not dates' => ['allDay' => true, 'start' => 'Wednesday', 'end' => 'Thursday', 'tzid' => 'UTC'],
                        'a timed end before its start' => ['allDay' => false, 'start' => '2027-03-03T19:00:00+00:00', 'end' => '2027-03-03T18:00:00+00:00', 'tzid' => 'UTC'],
                        'a timed moment (the end on the start)' => ['allDay' => false, 'start' => '2027-03-03T20:00:00-05:00', 'end' => '2027-03-03T20:00:00-05:00', 'tzid' => 'America/New_York'],
                        'a zone that is no zone' => ['tzid' => 'Mars/Olympus_Mons'] + $time,
                        'an all-day setting that is no boolean' => ['allDay' => 'yes'] + $time,
                    ];
                    foreach ($bad as $bName => $over) {
                        $refused("over no state ($bName), $cell", $t, 'patch', $req + ['over' => $over + $shown]);
                    }
                    H::done($t);
                });

                // No `over` (an API caller, a plugin, Review): the base is the row, as in 1.5.6. A whole request is read as
                // sent; a request in parts (a drag's start and end, a start alone, an end alone) is read against the row:
                // its kind, and its other boundary; an all-day boundary sent is the date written, an all-day end on or
                // before the start the day after it; a timed boundary sent with no offset is read in the row's zone.
                foreach ($senders as $sName => $make) {
                    scenario("no over, $sName, $cell", function () use ($setUp, $shownAllDay, $change, $polled, $make, $timeKeys, $timeOf, $yoursOf, $zoneOf, $threeWay, $theirs, $sName, $cell): void {
                        [$t, $shown] = $setUp($shownAllDay, $change, $polled);
                        $time = array_intersect_key($shown, array_flip($timeKeys));
                        $req = $make($time);
                        $threeWay("no over, $sName, $cell", $t, $req, $polled ? $theirs : $timeOf($time), $theirs, $yoursOf($req), $zoneOf($req));
                        H::done($t);
                    });
                }
                scenario("no over, in parts, $cell", function () use ($setUp, $shownAllDay, $change, $polled, $timeKeys, $threeWay, $theirs, $shift, $endBy, $db, $send, $day, $cell): void {
                    foreach (['a drag (start and end)', 'a start alone', 'an end alone'] as $pName) {
                        [$t, $shown] = $setUp($shownAllDay, $change, $polled);
                        $time = array_intersect_key($shown, array_flip($timeKeys));
                        $body = match ($pName) {
                            'a drag (start and end)' => array_intersect_key($shift($time, '+2 days'), ['start' => 1, 'end' => 1]),
                            'a start alone' => ['start' => $shift($time, '-1 day')['start']],
                            'an end alone' => ['end' => $endBy($time, '+1 day')['end']],
                        };
                        $row = $db->one('SELECT start_utc, end_utc, all_day, tzid FROM events WHERE id = ?', [$t->id]);
                        $base = [(int) strtotime($row['start_utc'] . ' UTC'), (int) strtotime($row['end_utc'] . ' UTC'), (int) $row['all_day'] === 1];
                        $read = static fn(string $s): int => $base[2] ? $day($s) : (new \DateTimeImmutable($s, new \DateTimeZone((string) $row['tzid'])))->getTimestamp();
                        $start = isset($body['start']) ? $read($body['start']) : $base[0];
                        $end = isset($body['end']) ? $read($body['end']) : $base[1];
                        if ($base[2] && $end <= $start) {
                            $end = $start + 86400;
                        }
                        if ($end < $start) {
                            [$status] = $send($t, 'patch', $body);
                            checkEq("no over, in parts ($pName), $cell: the end read before the start is refused as a bad request", 400, $status);
                        } else {
                            $threeWay("no over, in parts ($pName), $cell", $t, $body, $base, $theirs, [$start, $end, $base[2]], (string) $row['tzid']);
                        }
                        H::done($t);
                    }
                });
            }
        }
    }

    // The question's time values are the whole time, the zone with it, so Overwrite can send `over` and the body whole
    // from them: was, now and yours each name all four keys, in the served form.
    scenario('the question names the time whole: was, now and yours each carry start, end, allDay and tzid', function () use ($setUp, $googles, $send, $timeKeys): void {
        [$t, $shown] = $setUp(false, $googles['Google timed west of UTC'][0], true);
        $time = array_intersect_key($shown, array_flip($timeKeys));
        [, $json] = $send($t, 'patch', ['start' => '2027-03-05T18:00:00+00:00', 'end' => '2027-03-05T19:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC', 'over' => $time]);
        $f = $json['error']['fields'][0] ?? [];
        checkEq('was, now and yours keys; now in the zone Google set', [$timeKeys, $timeKeys, $timeKeys, 'America/New_York', 'UTC'],
            [array_keys((array) ($f['was'] ?? [])), array_keys((array) ($f['now'] ?? [])), array_keys((array) ($f['yours'] ?? [])), $f['now']['tzid'] ?? null, $f['was']['tzid'] ?? null]);
        H::done($t);
    });

    // The time is whole where it is shown: the window's occurrences (the list shape the grid holds) carry the zone beside
    // start, end and allDay, timed and all-day, so a change from the grid can send `over` whole from the copy it showed.
    scenario('the window serves the time whole: start, end, allDay and tzid on a timed and an all-day occurrence', function () use ($fresh, $timeKeys): void {
        foreach (['timed' => false, 'all-day' => true] as $kind => $allDay) {
            $t = $fresh($allDay);
            $occ = $t->s->events->window($t->u, new \DateTimeImmutable('2027-03-01T00:00:00Z'), new \DateTimeImmutable('2027-03-10T00:00:00Z'), null, null, false)[0] ?? [];
            checkEq("$kind: the four keys, the zone UTC", [$timeKeys, 'UTC'], [array_values(array_intersect($timeKeys, array_keys($occ))), $occ['tzid'] ?? null]);
            H::done($t);
        }
    });

    // A floating event (its clock time in any zone, a field of its own, not part of the time): its instants are on the
    // device's clock in `over` as in the body, and a whole drag on a device in another zone over Google making it all-day
    // asks with the dragged instants, and Overwrite saves them, still floating.
    foreach ($orders as $order => $polled) {
        scenario("floating event dragged whole on a device in another zone over Google making it all-day, $order", function () use ($fresh, $send, $served, $atGoogle, $googles, $instants, $overwrite, $timeKeys, $polled, $order): void {
            $t = $fresh(false);
            $send($t, 'patch', ['floating' => true]);
            $t->s->events->viewFrom('America/New_York');
            try {
                $time = array_intersect_key($served($t), array_flip($timeKeys));
                $atGoogle($t, $googles['Google all-day'][0]);
                if ($polled) {
                    $t->s->poll();
                }
                $body = ['start' => '2027-03-05T18:00:00-05:00', 'end' => '2027-03-05T19:00:00-05:00', 'allDay' => false, 'tzid' => $time['tzid'], 'over' => $time];
                $dragged = [(int) strtotime('2027-03-05T18:00:00-05:00'), (int) strtotime('2027-03-05T19:00:00-05:00'), false];
                [$status, $json] = $send($t, 'patch', $body);
                checkEq("floating, $order: 409; yours the dragged instants on the device's clock, timed", [409, $dragged],
                    [$status, $instants($json['error']['fields'][0]['yours'] ?? null)]);
                [$status] = $send($t, 'patch', $overwrite($body, $json));
                $after = $served($t);
                checkEq("floating, $order: Overwrite 200; the event as served is the dragged time, still floating", [200, $dragged, true],
                    [$status, $instants($after), (bool) ($after['floating'] ?? false)]);
            } finally {
                $t->s->events->viewFrom(null);
            }
            H::done($t);
        });
    }

    // Off the path a time in parts is read as before: the setting off, `over` is read by nothing and a start alone is
    // the 1.5.6 request (Google unchanged: one read, one write, the end the row's).
    scenario('setting off: over in parts and a start alone are not refused; the end is the row\'s', function () use ($fresh, $send, $served, $rowHolds, $db): void {
        $t = $fresh(false);
        conflictChoice($db, $t->u, false);
        [$status] = $send($t, 'patch', ['start' => '2027-03-03T17:30:00+00:00', 'over' => ['start' => $served($t)['start']]]);
        checkEq('200; the row 17:30 to 19:00', [200, [(int) strtotime('2027-03-03T17:30:00Z'), (int) strtotime('2027-03-03T19:00:00Z'), false]], [$status, $rowHolds($t)]);
        H::done($t);
    });
};
