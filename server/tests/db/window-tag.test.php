<?php

declare(strict_types=1);

// The events window's ETag (#16) is the account's change cursor: a client
// holding the window under it is answered 304 (no body, before any event is
// read) while nothing that changes the window has happened, and 200 with a
// new tag after each kind of write that does. Every path the audit found
// is here: an edit from the API, an undo, a filter, a calendar hidden, a
// feed's poll landing a row, the ranking scoring an event (a worker write
// outside any calendar's data), and an event's new mark ending with
// nothing written. Each block is a scenario(): a throw fails that block,
// and the next still runs.

use BetterCal\Domain\ChangeCursor;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\Filters;
use BetterCal\Domain\ModelAdmission;
use BetterCal\Domain\Ranking;
use BetterCal\Domain\RequestAuthority;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Trips;
use BetterCal\Http\Controllers\EventsController;
use BetterCal\Http\Request;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;
use BetterCal\Infra\LlmFailure;
use BetterCal\Infra\LlmGateway;
use BetterCal\Infra\LlmTransport;
use BetterCal\Support\Time;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $home = (int) Settings::defaultCalendarId($db, $u);
    $authority = requestCredentials($db, $u)['session'][0];
    $controller = new EventsController($d->events, new Trips($db, $d->undo), null, '1.5.2', new ChangeCursor($db));
    // GET /events for March 2031, with or without If-None-Match: [status, ETag, Cache-Control, body].
    $ask = static function (?string $tag = null) use ($controller, $u): array {
        $req = new Request('GET', '/api/v1/events', ['start' => '2031-03-01T00:00:00+00:00', 'end' => '2031-04-01T00:00:00+00:00'], [], $tag === null ? [] : ['if-none-match' => $tag]);
        $req->user = ['id' => $u];
        $res = $controller->window($req);
        return [$res->status, $res->headers['ETag'] ?? null, $res->headers['Cache-Control'] ?? null, $res->body];
    };
    // A write as the app makes it: under the request's credential, in a request that moves the marker.
    $asRequest = static fn(callable $fn): mixed => RequestAuthority::during($authority, $fn, true);

    $id = (int) $asRequest(fn(): array => $d->events->create($u, eventAt($home, 'Sample talk', '2031-03-10')))['eventId'];
    [$status, $tag, $cache, $body] = $ask();
    scenario('window tag: a window carries its tag and cache policy', function () use ($status, $tag, $cache, $body): void {
        checkEq('window tag: 200 with a weak ETag and private, no-cache', [200, true, 'private, no-cache'], [$status, is_string($tag) && str_starts_with($tag, 'W/"'), $cache]);
        check('window tag: the body holds the event', str_contains($body, 'Sample talk'));
    });
    scenario('window tag: asked again with the tag, 304 with no body and the same headers', function () use ($ask, $tag): void {
        [$status, $again, $cache, $body] = $ask($tag);
        checkEq('window tag: 304, the same tag and cache policy, no body', [304, $tag, 'private, no-cache', ''], [$status, $again, $cache, $body]);
        [$status] = $ask(substr((string) $tag, 0, -1) . '-gzip"');
        checkEq('window tag: the tag as a compressing server sent it is the same tag', 304, $status);
        [$status, $full] = $ask('W/"held-by-someone-else"');
        checkEq('window tag: another tag is answered in full, with the tag', [200, $tag], [$status, $full]);
    });

    // After each kind of write: the held tag is answered 200 with a new tag, and the new tag is 304.
    $changes = static function (string $what, callable $write) use ($ask, &$tag): void {
        scenario("window tag: $what", function () use ($what, $write, $ask, &$tag): void {
            $write();
            [$status, $next] = $ask($tag);
            check("window tag: after $what the held tag is answered 200 with a new tag", $status === 200 && is_string($next) && $next !== $tag, "status $status, tag " . var_export($next, true));
            $tag = $next;
            [$status] = $ask($tag);
            checkEq("window tag: and after $what the new tag is 304 until the next change", 304, $status);
        });
    };
    $changes('an event edited from the API', fn() => $asRequest(fn() => $d->events->patch($u, $id, ['title' => 'Sample talk, moved'])));
    $changes('an undo', fn() => $asRequest(fn() => $d->undo->undoById($u, lastEntry($db, $u))));
    $filters = new Filters($db, $d->undo, new JobQueue($db));
    $changes('a filter added', fn() => $asRequest(fn() => $filters->create($u, ['scope' => 'global', 'type' => 'keyword', 'action' => 'dim', 'config' => ['pattern' => 'talk']])));
    $changes('a calendar hidden', fn() => $asRequest(fn() => $d->calendars->patch($u, $home, ['visible' => false])));

    // Worker paths run as no request and move the cursor on their own.
    $feedCal = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample feed', 'kind' => 'subscribed', 'color' => '#336699']);
    $changes("a feed's poll landing a row", function () use ($db, $feedCal): void {
        (new Feeds($db))->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$feedCal]), [[
            'uid' => 'sample-feed-1', 'title' => 'Market day', 'description' => null, 'location' => 'Old Mill Square', 'url' => null,
            'start_utc' => '2031-03-12 10:00:00', 'end_utc' => '2031-03-12 12:00:00', 'all_day' => 0, 'tzid' => 'UTC',
            'rrule' => null, 'exdates' => [], 'status' => 'confirmed', 'recurrence_instance_utc' => null,
        ]]);
    });
    $fed = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ?', [$feedCal]);
    $changes('the ranking scoring an event', function () use ($db, $d, $u, $fed): void {
        for ($i = 0; $i < Ranking::MIN_SIGNALS; $i++) {
            $db->insert('feedback_signals', ['user_id' => $u, 'event_id' => $fed, 'kind' => $i % 2 === 0 ? 'up' : 'down']);
        }
        $fake = new class ($fed) implements LlmTransport {
            public function __construct(private readonly int $eventId)
            {
            }

            public function post(string $url, array $headers, string $body, int $timeoutSeconds): string|LlmFailure|null
            {
                return (string) json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode(['results' => [['eventId' => $this->eventId, 'score' => 0.8]]])]]]]]]);
            }
        };
        $gateway = new LlmGateway(['gemini' => ['key' => 'sample-secret-key-0123456789', 'model' => 'gemini-sample']], $fake);
        $scored = (new Ranking($db, $gateway, null, new ModelAdmission($db), new ReviewQueue($db, $d->events)))->run();
        checkEq('window tag: the ranking scored the feed event', 1, $scored);
    });
    $changes("an event's new mark ending", function () use ($db, $feedCal): void {
        $db->run('UPDATE events SET created_at = ? WHERE calendar_id = ?', [Time::toDb(Time::nowUtc()->sub(new \DateInterval('PT' . (\BetterCal\Domain\Events::NEW_WINDOW_HOURS + 1) . 'H'))), $feedCal]);
    });
};
