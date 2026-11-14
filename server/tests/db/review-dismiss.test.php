<?php

declare(strict_types=1);

// Every Review item can be dismissed (the owner: "The dismiss is not, I've dealt with it necessarily. It's, I don't care
// about it or I acknowledge this."). For a notice that reports a state, one rule: dismissed, it stays dismissed while the
// state it reports stays the same; it comes back when that state changes, or when an action the person takes is refused
// because of that state; dismissing changes nothing but the item. Each expectation follows from that rule and from the
// state the test itself set. Invented data.

use BetterCal\Domain\Proposals;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Http\Controllers\ReviewController;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    // What Review lists for the account, by key, through the controller as the app reads it.
    $listed = static function (int $u, object $d, $events) use ($db): array {
        $controller = new ReviewController(new ReviewQueue($db, $events), new Proposals($db, $events, new BetterCal\Domain\Trips($db, $d->undo)), $d->calendars);
        $req = new Request('GET', '/review');
        $req->user = ['id' => $u];
        $req->authMethod = 'session';
        $out = [];
        foreach (json_decode($controller->index($req)->body, true)['items'] as $i) {
            $out[$i['key']] = $i;
        }
        return $out;
    };
    $dismiss = static function (array $item, int $u, $events) use ($db): void {
        $path = (string) (array_values(array_filter($item['actions'], static fn(array $a): bool => $a['name'] === 'dismiss'))[0]['path'] ?? '');
        preg_match('#/(\d+)/dismiss$#', $path, $m);
        (new ReviewQueue($db, $events))->dismissNotice($u, (int) ($m[1] ?? 0));
    };

    scenario('review dismiss, a paused feed: Dismiss offered; dismissed it stays out while paused for the same reason, and the feed stays paused; it comes back when the reason changes and when a refresh is refused; it ends when the feed is no longer paused', function () use ($db, $listed, $dismiss): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample feed', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'ics',
            'source_url' => 'https://feeds.example.test/sample.ics', 'subscription_authority' => 'legacy_review',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $key = 'subscription:' . $cal;
        $authority = static fn(): string => (string) $db->scalar('SELECT subscription_authority FROM calendars WHERE id = ?', [$cal]);
        $item = $listed($u, $d, $d->events)[$key] ?? null;
        check('paused: listed with Dismiss beside its decisions', in_array('Dismiss', array_column($item['actions'] ?? [], 'label'), true), json_encode($item['actions'] ?? null));
        $dismiss($item, $u, $d->events);
        checkEq('dismissed: not listed again while paused for the same reason; the feed still paused (dismissing changed nothing but the item)', [false, 'legacy_review'],
            [isset($listed($u, $d, $d->events)[$key]), $authority()]);
        $db->run("UPDATE calendars SET subscription_authority = 'session_review' WHERE id = ?", [$cal]);
        checkEq('paused for another reason: listed again', true, isset($listed($u, $d, $d->events)[$key]));
        $dismiss($listed($u, $d, $d->events)[$key], $u, $d->events);
        $controller = new \BetterCal\Http\Controllers\CalendarsController($db, $d->calendars, new BetterCal\Domain\Feeds($db));
        $req = new Request('POST', '/calendars/' . $cal . '/refresh');
        $req->user = ['id' => $u];
        $req->authMethod = 'session';
        $code = null;
        try {
            $controller->refresh($req, ['id' => $cal]);
        } catch (HttpError $e) {
            $code = $e->errorCode;
        }
        checkEq('a refresh refused because the feed is paused brings the dismissed notice back', ['subscription_authorization_revoked', true], [$code, isset($listed($u, $d, $d->events)[$key])]);
        $db->run("UPDATE calendars SET subscription_authority = 'owner' WHERE id = ?", [$cal]);
        checkEq('no longer paused: not listed, its notice ended', [false, ['gone']],
            [isset($listed($u, $d, $d->events)[$key]), array_values(array_unique(array_column($db->all('SELECT status FROM review_items WHERE user_id = ? AND source_key = ?', [$u, $key]), 'status')))]);
    });

    scenario('review dismiss, a Google calendar listing nothing: dismissed, the next empty read does not bring it back; a read that lists events ends it; an empty read after that is a new notice', function () use ($db): void {
        $t = H::make($db, ['start' => '2027-03-02T09:00:00-08:00', 'end' => '2027-03-02T10:00:00-08:00', 'tzid' => 'America/Los_Angeles']);
        try {
            $open = static fn(): array => array_column($db->all("SELECT status FROM review_items WHERE user_id = ? AND kind = 'google_empty' AND status IN ('open', 'dismissed') ORDER BY id", [$t->u]), 'status');
            $emptyRead = static function () use ($db, $t): void {
                $db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$t->s->calendarId]); // a whole read
                $t->s->knobs->emptyNext = true;
                try {
                    $t->s->poll();
                } catch (\RuntimeException) {
                }
            };
            $emptyRead();
            $id = (int) $db->scalar("SELECT id FROM review_items WHERE user_id = ? AND kind = 'google_empty' AND status = 'open'", [$t->u]);
            (new ReviewQueue($db, $t->e))->dismissNotice($t->u, $id);
            $emptyRead();
            checkEq('dismissed, another empty read: still the one notice, dismissed', [['dismissed']], [$open()]);
            $db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$t->s->calendarId]);
            $t->s->poll();
            checkEq('a whole read that lists the event ends it', [[]], [$open()]);
            $emptyRead();
            checkEq('empty again: a new notice, open', [['open']], [$open()]);
        } finally {
            H::done($t);
        }
    });

    scenario('review dismiss, a Google calendar read-only at Google: dismissed, a change refused because of it brings it back', function () use ($db): void {
        $t = H::make($db, ['start' => '2027-03-02T09:00:00-08:00', 'end' => '2027-03-02T10:00:00-08:00', 'tzid' => 'America/Los_Angeles']);
        try {
            $status = static fn(): array => array_column($db->all("SELECT status FROM review_items WHERE user_id = ? AND source_key = ? AND status IN ('open', 'dismissed')", [$t->u, 'google-access:' . $t->s->calendarId]), 'status');
            $t->s->fake->role = 'reader';
            $t->s->poll();
            $id = (int) $db->scalar("SELECT id FROM review_items WHERE user_id = ? AND kind = 'google_read_only' AND status = 'open'", [$t->u]);
            (new ReviewQueue($db, $t->e))->dismissNotice($t->u, $id);
            $t->s->poll();
            checkEq('dismissed, another poll at the same level: still dismissed', ['dismissed'], $status());
            try {
                $t->e->patch($t->u, $t->id, ['title' => 'Sample, renamed']);
            } catch (HttpError) {
            }
            checkEq('a change refused because the calendar is read-only: the notice open again', ['open'], $status());
        } finally {
            H::done($t);
        }
    });
};
