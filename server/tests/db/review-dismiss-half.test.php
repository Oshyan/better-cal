<?php

declare(strict_types=1);

// A change to a repeating event left half done at Google is a Review notice like any state (ReviewQueue::holdState,
// the one rule): dismissed, it stays out of Review while the change stays half done; a change refused because of it
// opens it again; Finish or Put back completing the change ends it; a dismiss changes nothing but the item (the
// change's stored record and every row of the series, each column, compared before and after). Whatever the item, the
// event's details say the change stopped part way (Events::halfDoneLine). Expectations follow from that rule and from
// the state the test set. Invented data.

use BetterCal\Domain\Events;
use BetterCal\Domain\Proposals;
use BetterCal\Domain\RequestAuthority;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Http\Controllers\ReviewController;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $weekly = ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    // A change to all events stopped part way: its second write goes nowhere.
    $halfDone = static function () use ($db, $weekly): object {
        $t = H::make($db, $weekly);
        H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
        $t->s->knobs->netAt = $t->s->knobs->writes + 2;
        [, $error] = H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
        $t->s->knobs->down = false;
        check('the change stopped half done', str_contains((string) $error, 'half done'), (string) $error);
        return $t;
    };
    $controller = static fn(object $t): ReviewController => new ReviewController(new ReviewQueue($db, $t->e),
        new Proposals($db, $t->e, new BetterCal\Domain\Trips($db, $t->d->undo)), $t->d->calendars, null, null, null, $t->e);
    $request = static function (object $t, string $method, string $path): Request {
        $req = new Request($method, $path);
        $req->user = ['id' => $t->u];
        $req->authMethod = 'session';
        return $req;
    };
    $halfItem = static function (object $t) use ($controller, $request): ?array {
        foreach (json_decode($controller($t)->index($request($t, 'GET', '/review'))->body, true)['items'] as $i) {
            if ($i['kind'] === 'google_half') {
                return $i;
            }
        }
        return null;
    };
    $dismiss = static function (object $t, array $item) use ($db): void {
        $path = (string) (array_values(array_filter($item['actions'], static fn(array $a): bool => $a['name'] === 'dismiss'))[0]['path'] ?? '');
        preg_match('#/(\d+)/dismiss$#', $path, $m);
        (new ReviewQueue($db, $t->e))->dismissNotice($t->u, (int) ($m[1] ?? 0));
    };
    $pending = static fn(object $t): array => RequestAuthority::asServer(fn(): array => $db->journal()->all('SELECT * FROM google_pending WHERE user_id = ? ORDER BY id', [$t->u]));
    $series = static fn(object $t): array => $db->all('SELECT * FROM events WHERE calendar_id = ? ORDER BY id', [$t->s->calendarId]);
    $line = static fn(object $t): ?array => Events::halfDoneLine($db, $t->e, $db->one('SELECT * FROM events WHERE id = ?', [$t->id]));
    $noticeStatus = static fn(object $t): array => array_column($db->all("SELECT status FROM review_items WHERE user_id = ? AND kind = 'google_half' ORDER BY id", [$t->u]), 'status');

    scenario('google half dismiss: Dismiss beside Finish and Put back; dismissed it stays out, the change and the series untouched; the event still says so; a refused change opens it again; Finish ends it', function () use ($db, $halfDone, $halfItem, $dismiss, $pending, $series, $line, $noticeStatus, $controller, $request): void {
        $t = $halfDone();
        try {
            $item = $halfItem($t);
            checkEq('listed with Finish, Put back and Dismiss', ['Finish', 'Put back', 'Dismiss'], array_column($item['actions'] ?? [], 'label'));
            [$pendingBefore, $seriesBefore] = [$pending($t), $series($t)];
            $dismiss($t, $item);
            checkEq('dismissed: not listed; the change\'s record and every row of the series, each column, as before', [null, $pendingBefore, $seriesBefore],
                [$halfItem($t), $pending($t), $series($t)]);
            $l = $line($t);
            checkEq('the event still says a change stopped part way, the notice dismissed, Finish and Put back possible', [true, false, null], [$l !== null, $l['open'] ?? null, array_key_exists('unrecoverable', $l ?? []) ? $l['unrecoverable'] : 'x']);
            $code = null;
            try {
                H::this($t, '2027-03-22T10:00:00-07:00', ['title' => 'Sample series (later)']);
            } catch (HttpError $e) {
                $code = $e->errorCode;
            }
            checkEq('a change refused because of it: the item listed again, the event\'s line says open', ['google_pending', true, true], [$code, $halfItem($t) !== null, $line($t)['open'] ?? null]);
            $dismiss($t, $halfItem($t));
            $id = (int) $pendingBefore[0]['id'];
            // Finish as Review runs it (the controller's route asks for a signed-in session, which the suite has none of).
            $t->e->googleFinish($t->u, $id);
            $t->e->throwDeferred();
            $halfItem($t);
            checkEq('Finish completes it: the notice ended, no line on the event', [['gone'], null], [$noticeStatus($t), $line($t)]);
        } finally {
            H::done($t);
        }
    });

    scenario('google half dismiss: Put back completing the change ends its notice, dismissed or not', function () use ($halfDone, $halfItem, $dismiss, $pending, $line, $noticeStatus, $controller, $request): void {
        $t = $halfDone();
        try {
            $dismiss($t, $halfItem($t));
            $id = (int) $pending($t)[0]['id'];
            $t->e->googlePutBack($t->u, $id);
            $t->e->throwDeferred();
            $halfItem($t);
            checkEq('put back: the notice ended, no line on the event', [['gone'], null], [$noticeStatus($t), $line($t)]);
        } finally {
            H::done($t);
        }
    });

    scenario('google half dismiss: when neither Finish nor Put back can run (the calendar read-only at Google), Dismiss is the one action, and the event says why in the item\'s own sentence', function () use ($halfDone, $halfItem, $line): void {
        $t = $halfDone();
        try {
            $t->s->fake->role = 'reader';
            $t->s->poll();
            $item = $halfItem($t);
            $l = $line($t);
            checkEq('Dismiss alone; the event\'s line carries the sentence the item ends with', [['Dismiss'], true],
                [array_column($item['actions'] ?? [], 'label'), $l !== null && $l['unrecoverable'] !== null && str_ends_with((string) $item['summary'], (string) $l['unrecoverable'])]);
        } finally {
            H::done($t);
        }
    });
};
