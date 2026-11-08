<?php

declare(strict_types=1);

// An ICS subscription's poll sends back the validators its last read left
// (If-None-Match, If-Modified-Since) and takes an answer that says nothing
// changed (a 304, or the same body) without parsing or syncing: the poll's
// bookkeeping is done, its rows are left alone. A changed body, a new Home
// zone, a new address, or a day without a full read reads in full. The
// feed is a stand-in transport answering for an invented host; each poll's
// request is recorded. A row changed here by hand shows whether a sync ran:
// a sync puts the feed's title back.

use BetterCal\Domain\Feeds;
use BetterCal\Infra\Db;
use BetterCal\Infra\FeedCredentials;

return static function (Db $db): void {
    $secret = str_repeat('t', 32);
    $u = freshUser($db);
    $d = domains($db);
    $db->run('UPDATE users SET settings_json = ? WHERE id = ?', [json_encode(['tz' => 'America/New_York']), $u]);
    $feedUrl = 'https://feeds.example.test/club.ics?key=invented-key';
    $calendar = (int) $d->calendars->create($u, ['name' => 'Club feed'], 'subscribed', $feedUrl)['id'];

    $body = static fn(string $title): string => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:meetup-1@example.test\r\nDTSTAMP:20261101T000000Z\r\nDTSTART:20261215T180000\r\nDTEND:20261215T190000\r\nSUMMARY:$title\r\nEND:VEVENT\r\n"
        . "END:VCALENDAR\r\n";
    // The stand-in: its body and headers; a 304 when the request's
    // If-None-Match is its ETag, or always when stuck. What it was asked.
    $server = (object) [
        'body' => $body('Monthly meetup'),
        'headers' => ['ETag' => '"v1"', 'Last-Modified' => 'Tue, 01 Dec 2026 10:00:00 GMT'],
        'stuck' => false,
        'asked' => [],
    ];
    $requestHeaders = static function (array $lines): array {
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = trim($value);
        }
        return $headers;
    };
    $transport = static function (string $method, string $url, array $headers, ?string $sent) use ($server, $requestHeaders): array {
        $server->asked[] = ['url' => $url, 'headers' => $requestHeaders($headers)];
        $match = ($requestHeaders($headers)['if-none-match'] ?? null);
        if ($server->stuck || ($match !== null && $match === ($server->headers['ETag'] ?? null))) {
            return ['status' => 304, 'body' => '', 'headers' => []];
        }
        return ['status' => 200, 'body' => $server->body, 'headers' => $server->headers];
    };
    $resolver = static fn(string $host, int $ms): array => ['93.184.216.34'];
    $feeds = new Feeds($db, null, ['session_secret' => $secret, 'version' => '9.9.9'], $transport, $resolver);

    $lastAsked = static fn(): array => end($server->asked)['headers'];
    $title = static fn(): string => (string) $db->scalar('SELECT title FROM events WHERE calendar_id = ? AND uid = ?', [$calendar, 'meetup-1@example.test']);
    $touchByHand = static fn() => $db->run('UPDATE events SET title = ? WHERE calendar_id = ? AND uid = ?', ['Changed here', $calendar, 'meetup-1@example.test']);

    scenario('feed reads: the first poll asks for the whole feed and keeps its validators, not its address', function () use ($db, $feeds, $calendar, $lastAsked, $title, $feedUrl): void {
        $feeds->poll($calendar);
        $asked = $lastAsked();
        checkEq('feed reads: the first poll sends no validator', [false, false], [isset($asked['if-none-match']), isset($asked['if-modified-since'])]);
        checkEq('feed reads: the event is synced', 'Monthly meetup', $title());
        $kept = $db->one('SELECT * FROM feed_reads WHERE calendar_id = ?', [$calendar]);
        checkEq('feed reads: the ETag, Last-Modified and count are kept', ['"v1"', 'Tue, 01 Dec 2026 10:00:00 GMT', 1], [$kept['etag'], $kept['last_modified'], (int) $kept['event_count']]);
        check('feed reads: the address is not stored with them, in any form it could be read back from',
            !str_contains((string) json_encode($kept), 'example.test') && !str_contains((string) json_encode($kept), 'invented-key')
            && $kept['url_key'] !== hash('sha256', $feedUrl));
    });

    scenario('feed reads: a 304 to the validators skips the sync and still counts as a poll that worked', function () use ($feeds, $calendar, $lastAsked, $title, $touchByHand, $db): void {
        $touchByHand();
        $db->run("UPDATE calendars SET last_polled_at = '2000-01-01 00:00:00', last_poll_status = 'error' WHERE id = ?", [$calendar]);
        $upserts = $feeds->poll($calendar);
        $asked = $lastAsked();
        checkEq('feed reads: the second poll sends If-None-Match and If-Modified-Since', ['"v1"', 'Tue, 01 Dec 2026 10:00:00 GMT'], [$asked['if-none-match'] ?? null, $asked['if-modified-since'] ?? null]);
        checkEq('feed reads: nothing synced (the hand change stands), none upserted', ['Changed here', 0], [$title(), $upserts]);
        $state = $db->one('SELECT last_poll_status, last_polled_at FROM calendars WHERE id = ?', [$calendar]);
        checkEq('feed reads: the poll is recorded as ok, now', ['ok', true], [$state['last_poll_status'], (string) $state['last_polled_at'] > '2000-01-01 00:00:00']);
        checkEq('feed reads: a 304 without validators of its own keeps the ones sent', '"v1"', $db->scalar('SELECT etag FROM feed_reads WHERE calendar_id = ?', [$calendar]));
        checkEq('feed reads: the day\'s stats keep the last read\'s count', 1, (int) $db->scalar('SELECT raw_count FROM feed_stats WHERE calendar_id = ? ORDER BY poll_date DESC LIMIT 1', [$calendar]));
    });

    scenario('feed reads: the same body without validators is matched by its hash and not parsed', function () use ($feeds, $server, $calendar, $lastAsked, $title, $db): void {
        // The server stops sending validators: the next answer is a 200 of the same body.
        $server->headers = [];
        $feeds->poll($calendar);
        checkEq('feed reads: an answer without validators leaves none kept', [null, null], array_values($db->one('SELECT etag, last_modified FROM feed_reads WHERE calendar_id = ?', [$calendar])));
        $feeds->poll($calendar);
        $asked = $lastAsked();
        checkEq('feed reads: with none kept, none is sent', [false, false], [isset($asked['if-none-match']), isset($asked['if-modified-since'])]);
        checkEq('feed reads: the same body is not synced (the hand change stands)', 'Changed here', $title());
    });

    scenario('feed reads: a changed body is synced', function () use ($feeds, $server, $calendar, $title): void {
        $server->body = str_replace('Monthly meetup', 'Monthly meetup, new room', $server->body);
        $feeds->poll($calendar);
        checkEq('feed reads: the new body is parsed and synced', 'Monthly meetup, new room', $title());
    });

    scenario('feed reads: a new Home zone reads the same body again', function () use ($feeds, $server, $calendar, $lastAsked, $title, $touchByHand, $db, $u): void {
        $server->headers = ['ETag' => '"v2"'];
        $feeds->poll($calendar); // the same body: matched, "v2" kept
        $feeds->poll($calendar);
        checkEq('feed reads: "v2" is sent and answered 304', '"v2"', $lastAsked()['if-none-match'] ?? null);
        $touchByHand();
        $start = (string) $db->scalar('SELECT start_utc FROM events WHERE calendar_id = ? AND uid = ?', [$calendar, 'meetup-1@example.test']);
        $db->run('UPDATE users SET settings_json = ? WHERE id = ?', [json_encode(['tz' => 'Europe/Berlin']), $u]);
        $feeds->poll($calendar);
        checkEq('feed reads: after the zone changes, no validator is sent', false, isset($lastAsked()['if-none-match']));
        checkEq('feed reads: the body is parsed and synced again', 'Monthly meetup, new room', $title());
        check('feed reads: the time with no zone is read in the new Home zone', $start !== (string) $db->scalar('SELECT start_utc FROM events WHERE calendar_id = ? AND uid = ?', [$calendar, 'meetup-1@example.test']));
    });

    scenario('feed reads: a new address sends no validator kept for the old one', function () use ($feeds, $server, $calendar, $lastAsked, $title, $touchByHand, $db, $secret): void {
        $server->headers = ['ETag' => '"v3"'];
        $server->body = str_replace('new room', 'old room', $server->body);
        $feeds->poll($calendar);
        checkEq('feed reads: "v3" kept for the old address', '"v3"', $db->scalar('SELECT etag FROM feed_reads WHERE calendar_id = ?', [$calendar]));
        $touchByHand();
        $db->run('UPDATE calendars SET source_url = ? WHERE id = ?', [FeedCredentials::sealSourceUrl('https://elsewhere.example.test/club.ics', $secret), $calendar]);
        $feeds->poll($calendar);
        $last = end($server->asked);
        checkEq('feed reads: the new address is asked, with no validator', ['https://elsewhere.example.test/club.ics', false], [$last['url'], isset($last['headers']['if-none-match'])]);
        checkEq('feed reads: its body is read in full', 'Monthly meetup, old room', $title());
    });

    scenario('feed reads: once a day the feed is read in full, whatever the validators', function () use ($feeds, $calendar, $lastAsked, $title, $touchByHand, $db): void {
        $feeds->poll($calendar);
        checkEq('feed reads: the validator is sent on the next poll', '"v3"', $lastAsked()['if-none-match'] ?? null);
        $touchByHand();
        $db->run("UPDATE feed_reads SET full_at = '2000-01-01 00:00:00' WHERE calendar_id = ?", [$calendar]);
        $feeds->poll($calendar);
        checkEq('feed reads: past a day, no validator is sent', false, isset($lastAsked()['if-none-match']));
        checkEq('feed reads: and the answer is synced', 'Monthly meetup, old room', $title());
        check('feed reads: the full read starts the day again', (string) $db->scalar('SELECT full_at FROM feed_reads WHERE calendar_id = ?', [$calendar]) > '2000-01-01 00:00:00');
    });

    scenario('feed reads: a 304 to a request that sent no validator is a failed poll', function () use ($feeds, $server, $calendar, $db): void {
        $db->run('DELETE FROM feed_reads WHERE calendar_id = ?', [$calendar]);
        $server->stuck = true;
        $failed = null;
        try {
            $feeds->poll($calendar);
        } catch (\RuntimeException $e) {
            $failed = $e->getMessage();
        }
        $server->stuck = false;
        check('feed reads: the poll fails, saying why', is_string($failed) && str_contains($failed, '304'), (string) $failed);
        checkEq('feed reads: and records the failure', 'error', $db->scalar('SELECT last_poll_status FROM calendars WHERE id = ?', [$calendar]));
    });

    scenario('feed reads: a limit the parse reads, changed in configuration, reads the feed in full', function () use ($feeds, $calendar, $lastAsked, $title, $touchByHand): void {
        $feeds->poll($calendar);
        $feeds->poll($calendar);
        checkEq('feed reads: with the limits as they were, the validator is sent', '"v3"', $lastAsked()['if-none-match'] ?? null);
        $touchByHand();
        $old = \BetterCal\Support\Limits::get('DESCRIPTION_CHARS');
        \BetterCal\Support\Limits::configure(['DESCRIPTION_CHARS' => $old - 1]);
        try {
            $feeds->poll($calendar);
            checkEq('feed reads: a new description limit sends no validator and syncs the answer', [false, 'Monthly meetup, old room'], [isset($lastAsked()['if-none-match']), $title()]);
        } finally {
            \BetterCal\Support\Limits::configure(['DESCRIPTION_CHARS' => $old]);
        }
    });

    scenario('feed reads: a kept read goes with its calendar', function () use ($db, $feeds, $calendar): void {
        $feeds->poll($calendar);
        checkEq('feed reads: a row is kept', 1, (int) $db->scalar('SELECT COUNT(*) FROM feed_reads WHERE calendar_id = ?', [$calendar]));
        $db->run('DELETE FROM events WHERE calendar_id = ?', [$calendar]);
        $db->run('DELETE FROM calendars WHERE id = ?', [$calendar]);
        checkEq('feed reads: no row is left once the calendar is gone', 0, (int) $db->scalar('SELECT COUNT(*) FROM feed_reads WHERE calendar_id = ?', [$calendar]));
    });
};
