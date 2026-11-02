<?php

declare(strict_types=1);

// A calendar of tens of thousands of events, deleted and undone, at the
// server's default packet size (#191): the delete goes through with an
// entry to undo it from, the undo puts back every event, tag, person and
// trip link as they were, and a delete whose entry can't be written leaves
// the calendar as it was. On a connection of its own, so a dropped one
// can't take the rest of the suite with it.

use BetterCal\Domain\Activity;
use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;

return static function (Db $suiteDb): void {
    $db = otherConnection();
    $d = domains($db);
    $u = freshUser($db);
    $home = (int) Settings::defaultCalendarId($db, $u);
    $big = (int) $d->calendars->create($u, ['name' => 'Big sample'])['id'];
    $folder = (int) $d->folders->create($u, ['name' => 'Sample folder'])['id'];
    $d->calendars->patch($u, $big, ['folderIds' => [$folder]]);
    // Where new events go: the delete moves it, and its undo moves it back.
    Settings::setDefaultCalendar($db, $u, $big);

    // One event made as the app makes it, with tags and people, copied
    // 25,000 times: each copy its own uid, day, title and a paragraph of
    // notes, so the snapshot is well past 16 MB.
    $first = (int) $d->events->create($u, eventAt($big, 'Sample shift', '2026-12-01', ['tagNames' => ['shift', 'sample'], 'personNames' => ['Alex Example', 'Sam Sample']]))['eventId'];
    $template = $db->one('SELECT * FROM events WHERE id = ?', [$first]);
    unset($template['id']);
    $tags = array_map('intval', array_column($db->all('SELECT tag_id FROM event_tags WHERE event_id = ? ORDER BY tag_id', [$first]), 'tag_id'));
    $people = array_map('intval', array_column($db->all('SELECT person_id FROM event_people WHERE event_id = ? ORDER BY person_id', [$first]), 'person_id'));
    $total = 25000;
    $notes = str_repeat('Bring the sample checklist and the spare keys. ', 12);
    $cols = array_keys($template);
    $rowSql = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    for ($n = 1; $n < $total; $n += 500) {
        $params = [];
        $rows = 0;
        for ($i = $n; $i < min($n + 500, $total); $i++) {
            $day = (new DateTimeImmutable('2026-01-01 08:00:00'))->modify('+' . intdiv($i, 8) . ' days')->modify('+' . ($i % 8) . ' hours');
            $row = ['uid' => 'sample-' . $i . '@example.test', 'title' => 'Sample shift ' . $i, 'description' => $notes . $i,
                'start_utc' => $day->format('Y-m-d H:i:s'), 'end_utc' => $day->modify('+45 minutes')->format('Y-m-d H:i:s')] + $template;
            foreach ($cols as $c) {
                $params[] = $row[$c];
            }
            $rows++;
        }
        $db->run('INSERT INTO events (`' . implode('`, `', $cols) . '`) VALUES ' . implode(', ', array_fill(0, $rows, $rowSql)), $params);
    }
    $db->run('INSERT INTO event_tags (event_id, tag_id) SELECT id, ? FROM events WHERE calendar_id = ? AND id <> ?', [$tags[0], $big, $first]);
    $db->run('INSERT INTO event_tags (event_id, tag_id) SELECT id, ? FROM events WHERE calendar_id = ? AND id <> ? AND MOD(id, 3) = 0', [$tags[1], $big, $first]);
    $db->run('INSERT INTO event_people (event_id, person_id) SELECT id, ? FROM events WHERE calendar_id = ? AND id <> ? AND MOD(id, 2) = 0', [$people[0], $big, $first]);
    // A trip on another calendar holding one of its events, and a series
    // on it with a date edited on its own.
    $trip = (int) $d->events->create($u, eventAt($home, 'Sample trip', '2026-12-20', ['isContainer' => true]))['eventId'];
    $member = (int) $db->scalar('SELECT MAX(id) FROM events WHERE calendar_id = ?', [$big]);
    (new BetterCal\Domain\Trips($db, $d->undo))->attach($u, $trip, $member);
    $series = (int) $d->events->create($u, eventAt($big, 'Sample standup', '2026-11-02', ['rrule' => 'FREQ=DAILY;COUNT=10', 'tagNames' => ['shift']]))['eventId'];
    $d->events->patch($u, $series, ['scope' => 'this', 'instanceStart' => '2026-11-04T18:00:00+00:00', 'title' => 'Sample standup and demo']);

    // Everything of the calendar, read a page at a time and folded into one
    // digest per table, bookkeeping aside (a calendar's sync token moves
    // with every change to it): the rows themselves are compared, not counts.
    $digest = static function () use ($db, $big): array {
        $out = [];
        $calendar = $db->one('SELECT * FROM calendars WHERE id = ?', [$big]);
        if ($calendar !== null) {
            unset($calendar['synctoken'], $calendar['updated_at']);
        }
        $out['calendars'] = md5((string) json_encode($calendar));
        $out['calendar_folders'] = md5((string) json_encode($db->all('SELECT * FROM calendar_folders WHERE calendar_id = ? ORDER BY folder_id', [$big])));
        $queries = [
            'events' => ['SELECT * FROM events WHERE calendar_id = ? AND id > ? ORDER BY id LIMIT 2000', 'id'],
            'event_tags' => ['SELECT t.* FROM event_tags t JOIN events e ON e.id = t.event_id WHERE e.calendar_id = ? AND t.event_id > ? ORDER BY t.event_id, t.tag_id LIMIT 2000', 'event_id'],
            'event_people' => ['SELECT p.* FROM event_people p JOIN events e ON e.id = p.event_id WHERE e.calendar_id = ? AND p.event_id > ? ORDER BY p.event_id, p.person_id LIMIT 2000', 'event_id'],
            'event_links' => ['SELECT l.* FROM event_links l JOIN events e ON e.id = l.event_id WHERE e.calendar_id = ? AND l.id > ? ORDER BY l.id LIMIT 2000', 'id'],
        ];
        foreach ($queries as $table => [$sql, $cursor]) {
            $ctx = hash_init('md5');
            $rows = 0;
            $after = 0;
            do {
                $page = $db->all($sql, [$big, $after]);
                $full = count($page) === 2000;
                // A page that ends inside one event's rows: take that
                // event's rows whole, and start the next page past it.
                if ($cursor === 'event_id' && $full) {
                    $lastEvent = (int) $page[count($page) - 1]['event_id'];
                    $page = array_values(array_filter($page, static fn(array $r): bool => (int) $r['event_id'] !== $lastEvent));
                    $page = array_merge($page, $db->all(str_replace(['> ?', 'LIMIT 2000'], ['= ?', ''], $sql), [$big, $lastEvent]));
                    $after = $lastEvent;
                } elseif ($page !== []) {
                    $after = (int) $page[count($page) - 1][$cursor];
                }
                foreach ($page as $row) {
                    hash_update($ctx, json_encode($row) . "\n");
                    $rows++;
                }
            } while ($full);
            $out[$table] = $rows . ':' . hash_final($ctx);
        }
        return $out;
    };
    $count = static fn(string $sql, array $params = []): int => (int) $db->scalar($sql, $params);
    $events = $count('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$big]);
    check('large calendar: the sample is at least 25,000 events', $events >= 25000, "$events events");
    $was = $digest();
    $settingsWere = $db->scalar('SELECT settings_json FROM users WHERE id = ?', [$u]);

    scenario('large calendar: deleted, with an entry to undo it from', function () use ($db, $d, $u, $big, $count, $was, $digest, $settingsWere): void {
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $started = microtime(true);
        $d->calendars->delete($u, $big);
        $deleteMb = (memory_get_peak_usage() - $base) / 1048576;
        $deleteSeconds = microtime(true) - $started;
        checkEq('large calendar: the delete removes the calendar and its events', [0, 0],
            [$count('SELECT COUNT(*) FROM calendars WHERE id = ?', [$big]), $count('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$big])]);
        $entry = (new Activity($db))->list($u, ['limit' => 1])['entries'][0] ?? [];
        checkEq('large calendar: Activity says what it said for any calendar deleted, and offers to undo it',
            ['calendar', 'delete', "Deleted calendar 'Big sample'", true],
            [$entry['entity'] ?? null, $entry['op'] ?? null, $entry['summary'] ?? null, $entry['undoable'] ?? null]);
        // No single journal row near the packet size.
        $largest = (int) $db->scalar('SELECT GREATEST(COALESCE(MAX(LENGTH(before_json)), 0), COALESCE(MAX(LENGTH(after_json)), 0)) FROM mutations WHERE id = ?', [(int) ($entry['id'] ?? 0)]);
        $piece = (int) $db->scalar('SELECT COALESCE(MAX(LENGTH(rows_json)), 0) FROM mutation_rows WHERE mutation_id = ?', [(int) ($entry['id'] ?? 0)]);
        check('large calendar: no journal row is more than a megabyte', $largest < 1048576 && $piece < 1048576, "entry $largest bytes, largest piece $piece bytes");

        $base = memory_get_usage();
        memory_reset_peak_usage();
        $started = microtime(true);
        $d->undo->undoById($u, (int) $entry['id']);
        $undoMb = (memory_get_peak_usage() - $base) / 1048576;
        $undoSeconds = microtime(true) - $started;
        checkEq('large calendar: undone, every event, tag, person and trip link is back as it was', $was, $digest());
        checkEq('large calendar: undone, it is where new events go again', $settingsWere, $db->scalar('SELECT settings_json FROM users WHERE id = ?', [$u]));
        // A phone syncing it hears of every event once, each change with a
        // token of its own, and the calendar's token past the last.
        $journal = $db->one('SELECT COUNT(*) n, COUNT(DISTINCT uri) uris, COUNT(DISTINCT synctoken) tokens, MAX(synctoken) last FROM dav_changes WHERE calendar_id = ?', [$big]);
        $uids = $count('SELECT COUNT(DISTINCT uid) FROM events WHERE calendar_id = ?', [$big]);
        checkEq('large calendar: undone, the CalDAV journal has each event once, in order',
            [$uids, $uids, $uids, (int) $journal['last'] + 1],
            [(int) $journal['n'], (int) $journal['uris'], (int) $journal['tokens'], (int) $db->scalar('SELECT synctoken FROM calendars WHERE id = ?', [$big])]);
        check('large calendar: deleting and undoing stay under 64 MB of memory each', $deleteMb < 64 && $undoMb < 64,
            sprintf('delete %.1f MB, undo %.1f MB', $deleteMb, $undoMb));
        if (getenv('BC_TEST_VERBOSE') === '1') {
            printf("large calendar: delete %.1f MB in %.1f s, undo %.1f MB in %.1f s\n", $deleteMb, $deleteSeconds, $undoMb, $undoSeconds);
        }

        // Deleted again, and undone again: the same rows back.
        $d->calendars->delete($u, $big);
        $again = (int) $db->scalar('SELECT MAX(id) FROM mutations WHERE user_id = ?', [$u]);
        $d->undo->undoById($u, $again);
        checkEq('large calendar: deleted again and undone again, the same rows are back', $was, $digest());
    });

    scenario('large calendar: a delete whose entry cannot be written changes nothing', function () use ($db, $d, $u, $big, $count, $was, $digest): void {
        // The journal's pieces refused, as a full disk or a dropped
        // connection would: the delete fails, and the calendar stays.
        $db->run("CREATE TRIGGER refuse_journal_rows BEFORE INSERT ON mutation_rows FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal refused'");
        try {
            $refused = false;
            try {
                $d->calendars->delete($u, $big);
            } catch (\PDOException) {
                $refused = true;
            }
            check('large calendar: the delete fails when its entry cannot be written', $refused);
        } finally {
            $db->run('DROP TRIGGER IF EXISTS refuse_journal_rows');
        }
        checkEq('large calendar: and the calendar and every event are as they were', $was, $digest());
        checkEq('large calendar: and there is no entry for it', 0, $count("SELECT COUNT(*) FROM mutations WHERE user_id = ? AND entity = 'calendar' AND op = 'delete' AND undone = 0", [$u]));
    });

    scenario("large calendar: a Google calendar's delete keeps no rows to undo, but says what it removed", function () use ($db, $d, $u, $count): void {
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test', 'refresh_token_enc' => 'sealed-sample', 'scopes' => 'calendar']);
        $google = $db->insert('calendars', ['user_id' => $u, 'name' => 'Google sample', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'sample@group.calendar.google.com', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $event = $db->insert('events', ['user_id' => $u, 'calendar_id' => $google, 'uid' => 'google-sample@example.test', 'title' => 'Google sample event',
            'start_utc' => '2026-12-01 18:00:00', 'end_utc' => '2026-12-01 19:00:00', 'tzid' => 'UTC', 'source' => 'feed']);
        $d->calendars->delete($u, $google);
        $entry = (int) $db->scalar('SELECT MAX(id) FROM mutations WHERE user_id = ?', [$u]);
        checkEq('large calendar: a Google calendar deleted is log-only, with nothing kept apart, and its events are among what it touched', [1, 0, 1],
            [$count('SELECT COUNT(*) FROM mutations WHERE id = ? AND before_json IS NULL AND after_json IS NULL', [$entry]),
                $count('SELECT COUNT(*) FROM mutation_rows WHERE mutation_id = ?', [$entry]),
                $count("SELECT COUNT(*) FROM mutation_touches WHERE mutation_id = ? AND row_key = ? AND col = '*'", [$entry, 'events:' . $event])]);
    });
};
