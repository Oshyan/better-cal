<?php

declare(strict_types=1);

// Every change to what undo knows (the tables an undo restores) is in the
// journal: for each operation below, every row it made, removed or changed,
// and each column it changed, appears in what its entries touched (Undo's
// touches). A writer that forgets fails here, rather than an undo later
// silently overwriting or deleting newer work.

use BetterCal\Domain\CalendarImport;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\Ics;
use BetterCal\Domain\People;
use BetterCal\Domain\SavedViews;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Trips;
use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Plugin\PluginHost;

return static function (Db $db): void {
    // The tables undo restores, read whole: row key => row.
    $tables = ['folders', 'tags', 'people', 'calendars', 'calendar_folders', 'events', 'event_links', 'event_tags',
        'event_people', 'out_feeds', 'filters', 'saved_views', 'availability', 'review_items'];
    $read = static function () use ($db, $tables): array {
        $out = [];
        foreach ($tables as $table) {
            foreach ($db->all("SELECT * FROM `$table`") as $row) {
                $out[Undo::keyOf($table, $row)] = $row;
            }
        }
        return $out;
    };
    // What differs between two reads of a row, decided here rather than by
    // Undo's own helper (a mistake shared by both would pass): a column
    // whose text changed or that went to or from NULL, a calendar's settings
    // by key. Bookkeeping columns undo neither keeps nor restores.
    $bookkeeping = ['updated_at', 'synctoken', 'last_polled_at', 'last_poll_status', 'last_poll_error',
        'content_changed_at', 'last_ok_at', 'google_sync_token', 'score', 'scored_at'];
    $differs = static function (string $table, array $was, array $now) use ($bookkeeping): array {
        $out = [];
        foreach (array_diff(array_keys($was + $now), $bookkeeping) as $col) {
            [$a, $b] = [$was[$col] ?? null, $now[$col] ?? null];
            if ($a === null ? $b === null : ($b !== null && (string) $a === (string) $b)) {
                continue;
            }
            if ($table === 'calendars' && $col === 'settings_json') {
                [$x, $y] = [json_decode((string) $a, true) ?: [], json_decode((string) $b, true) ?: []];
                foreach (array_keys($x + $y) as $k) {
                    if (json_encode($x[$k] ?? null) !== json_encode($y[$k] ?? null) || array_key_exists($k, $x) !== array_key_exists($k, $y)) {
                        $out[] = "$col.$k";
                    }
                }
                continue;
            }
            $out[] = (string) $col;
        }
        return $out;
    };
    // What an operation changed but its entries don't say.
    $unjournaled = static function (callable $op, bool $vocabulary) use ($db, $read, $differs): array {
        $since = (int) $db->scalar('SELECT COALESCE(MAX(id), 0) FROM mutations');
        $before = $read();
        $op();
        $after = $read();
        $said = [];
        foreach ($db->all('SELECT row_key, col FROM mutation_touches WHERE mutation_id > ?', [$since]) as $t) {
            $said[$t['row_key']][$t['col']] = true;
        }
        $missing = [];
        foreach ($before + $after as $key => $_) {
            $was = $before[$key] ?? null;
            $now = $after[$key] ?? null;
            if ($was === null || $now === null) {
                // A tag, or a person named on an event, is a name: made the
                // first time it's used and left when that's undone (another
                // event may have it by then).
                if ($was === null && $vocabulary && (str_starts_with($key, 'tags:') || str_starts_with($key, 'people:'))) {
                    continue;
                }
                if (!isset($said[$key]['*'])) {
                    $missing[] = $key . ' ' . ($was === null ? 'made' : 'removed');
                }
                continue;
            }
            foreach ($differs((string) strstr($key, ":", true), $was, $now) as $col) {
                if (!isset($said[$key]['*']) && !isset($said[$key][$col])) {
                    $missing[] = "$key.$col";
                }
            }
        }
        return $missing;
    };
    // $vocabulary false: the operation is making the person itself.
    $journaled = static function (string $what, callable $op, bool $vocabulary = true) use ($unjournaled): void {
        $missing = $unjournaled($op, $vocabulary);
        check("journal: $what says everything it changed", $missing === [], implode(', ', array_slice($missing, 0, 12)));
    };

    $u = freshUser($db);
    $d = domains($db);
    $home = (int) Settings::defaultCalendarId($db, $u);
    $people = new People($db);
    $trips = new Trips($db, $d->undo);
    $views = new SavedViews($db, $d->undo);

    // Calendars and folders.
    $work = 0;
    $journaled('making a calendar', function () use ($d, $u, &$work): void {
        $work = (int) $d->calendars->create($u, ['name' => 'Work'])['id'];
    });
    $folder = 0;
    $journaled('making a folder', function () use ($d, $u, &$folder): void {
        $folder = (int) $d->folders->create($u, ['name' => 'Projects'])['id'];
    });
    $journaled("a calendar's settings", fn() => $d->calendars->patch($u, $work, ['name' => 'Work things', 'color' => '#336699', 'visible' => false, 'role' => 'context', 'folderIds' => [$folder]]));
    $journaled('a calendar filed and moved in its folder', function () use ($d, $u, $folder, $home): void {
        $d->folders->place($u, $folder, $home, 0);
        $d->folders->place($u, $folder, $home, 5);
    });
    $journaled('a calendar moved in the list', fn() => $d->calendars->placeInList($u, $work, 0));
    $journaled("a folder's name and order", fn() => $d->folders->patch($u, $folder, ['name' => 'Projects, renamed', 'sort' => 'az']));
    $journaled('a calendar taken out of a folder', fn() => $d->folders->unfile($u, $folder, $home));
    $journaled('a calendar made in a folder', fn() => $d->calendars->create($u, ['name' => 'Filed at once', 'folderIds' => [$folder]]));

    // Events, alone and in series.
    $event = 0;
    $journaled('making an event with tags and people', function () use ($d, $u, $home, &$event): void {
        $event = (int) $d->events->create($u, eventAt($home, 'Dinner', '2026-12-10', ['tagNames' => ['food'], 'personNames' => ['Alex Example']]))['eventId'];
    });
    $journaled('an event edited', fn() => $d->events->patch($u, $event, ['title' => 'Dinner out', 'location' => 'Sample Bistro', 'tagNames' => ['food', 'friends']]));
    $journaled('an event moved to another calendar', fn() => $d->events->patch($u, $event, ['calendarId' => $work]));
    $journaled('an event marked going', fn() => $d->events->setAttendance($u, $event, 'going'));
    $series = 0;
    $journaled('making a series', function () use ($d, $u, $home, &$series): void {
        $series = (int) $d->events->create($u, eventAt($home, 'Standup', '2026-11-02', ['rrule' => 'FREQ=DAILY;COUNT=20', 'tagNames' => ['work']]))['eventId'];
    });
    $journaled('one date of a series edited', fn() => $d->events->patch($u, $series, ['scope' => 'this', 'instanceStart' => '2026-11-04T18:00:00+00:00', 'title' => 'Standup and demo']));
    $journaled('one date of a series marked going', fn() => $d->events->setAttendance($u, $series, 'going', 'this', '2026-11-05T18:00:00+00:00'));
    $journaled('one date of a series deleted', fn() => $d->events->deleteEvent($u, $series, 'this', '2026-11-06T18:00:00+00:00'));
    $journaled('a date edited on its own, deleted', function () use ($d, $u, $db, $series): void {
        $edited = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ?', [$series, '2026-11-04 18:00:00']);
        $d->events->deleteEvent($u, $edited, null, null);
    });
    $journaled('one date moved off to another calendar', fn() => $d->events->patch($u, $series, ['scope' => 'this', 'instanceStart' => '2026-11-07T18:00:00+00:00', 'calendarId' => $work]));
    $journaled('a whole series changed', fn() => $d->events->patch($u, $series, ['scope' => 'all', 'instanceStart' => '2026-11-02T18:00:00+00:00', 'start' => '2026-11-02T19:00:00+00:00', 'end' => '2026-11-02T20:00:00+00:00']));
    $journaled('a series split from a date on', fn() => $d->events->patch($u, $series, ['scope' => 'following', 'instanceStart' => '2026-11-12T19:00:00+00:00', 'title' => 'Standup, later']));
    $journaled('a series ended from a date on', fn() => $d->events->deleteEvent($u, $series, 'following', '2026-11-10T19:00:00+00:00'));
    $journaled('a series marked going, all of it', fn() => $d->events->setAttendance($u, $series, 'interested'));
    $journaled('an event copied to another calendar', fn() => $d->events->copyTo($u, $event, $home));
    $journaled('an event deleted', fn() => $d->events->deleteEvent($u, $event, null, null));
    $journaled('a series deleted', fn() => $d->events->deleteEvent($u, $series, 'all', null));

    // Trips.
    $trip = (int) $d->events->create($u, eventAt($home, 'Trip away', '2026-12-20', ['isContainer' => true]))['eventId'];
    $stop = (int) $d->events->create($u, eventAt($home, 'Museum', '2026-12-21'))['eventId'];
    $journaled('an event added to a trip', fn() => $trips->attach($u, $trip, $stop));
    $journaled('a trip moved with its events', fn() => $d->events->patch($u, $trip, ['start' => '2026-12-27T18:00:00+00:00', 'end' => '2026-12-27T19:00:00+00:00', 'moveMembers' => true]));
    $journaled('an event taken out of a trip', fn() => $trips->detach($u, $trip, $stop));
    $trips->attach($u, $trip, $stop);
    $journaled('a calendar deleted, with its events, tags and trip links', fn() => $d->calendars->delete($u, $work));

    // People and their away times.
    $person = 0;
    $journaled('a person added', function () use ($people, $u, &$person): void {
        $person = (int) $people->create($u, ['name' => 'Sam Sample'])['id'];
    }, false);
    $span = 0;
    $journaled('an away time added, moved and removed', function () use ($people, $u, $person, &$span): void {
        $span = (int) $people->addSpan($u, $person, ['start' => '2026-12-01T00:00:00Z', 'end' => '2026-12-05T00:00:00Z'])['id'];
        $people->updateSpan($u, $person, $span, ['end' => '2026-12-06T00:00:00Z']);
        $people->deleteSpan($u, $person, $span);
    });
    $journaled('a person renamed', fn() => $people->update($u, $person, ['name' => 'Sam Sampler', 'notes' => 'Neighbor']));
    $other = (int) $people->create($u, ['name' => 'Alex Example'])['id'];
    $people->addSpan($u, $other, ['start' => '2026-12-08T00:00:00Z', 'end' => '2026-12-09T00:00:00Z']);
    $d->events->create($u, eventAt($home, 'Coffee', '2026-12-11', ['personNames' => ['Alex Example']]));
    $journaled('a person renamed onto another (merged)', fn() => $people->update($u, $other, ['name' => 'Sam Sampler']));
    $journaled('a person deleted', fn() => $people->delete($u, $person));

    // Saved views and filters.
    $journaled('a saved view made, changed and deleted', function () use ($views, $u): void {
        $id = (int) $views->create($u, ['name' => 'Week', 'config' => ['view' => 'week']])['id'];
        $views->patch($u, $id, ['name' => 'This week']);
        $views->delete($u, $id);
    });

    $filters = new \BetterCal\Domain\Filters($db, $d->undo, new \BetterCal\Infra\JobQueue($db));
    $journaled('a filter made, changed and deleted', function () use ($filters, $u): void {
        $id = (int) $filters->create($u, ['name' => 'Quiet', 'scope' => 'global', 'type' => 'keyword', 'action' => 'dim', 'config' => ['pattern' => 'webinar']])['id'];
        $filters->patch($u, $id, ['action' => 'hide']);
        $filters->delete($u, $id);
    });

    // Quick add, and a phone's or desktop app's calendar (CalDAV).
    $quick = new \BetterCal\Domain\QuickAdd($db, new \BetterCal\Infra\LlmGateway([]), $d->events, new Settings($db));
    $journaled('an event made with quick add', fn() => $quick->run($u, 'Lunch with Alex Example tomorrow at noon', 'UTC', true, $home));
    $token = (new \BetterCal\Domain\ApiTokens($db))->create($u, 'Phone');
    $dav = new \BetterCal\Dav\CalendarBackend($db, $d->undo, fn() => \BetterCal\Domain\RequestAuthority::credential($u, (int) $token['id'], null));
    $davEvent = static fn(string $title) => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\nBEGIN:VEVENT\r\nUID:dav-1@example.test\r\nDTSTAMP:20261101T000000Z\r\n"
        . "DTSTART:20261218T180000Z\r\nDTEND:20261218T190000Z\r\nSUMMARY:$title\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    $journaled('an event made from a phone', fn() => $dav->createCalendarObject($home, 'dav-1@example.test.ics', $davEvent('From the phone')));
    $journaled('an event changed from a phone', fn() => $dav->updateCalendarObject($home, 'dav-1@example.test.ics', $davEvent('From the phone, changed')));
    $journaled('an event deleted from a phone', fn() => $dav->deleteCalendarObject($home, 'dav-1@example.test.ics'));
    $journaled('a calendar renamed from a phone', function () use ($dav, $home): void {
        $patch = new \Sabre\DAV\PropPatch(['{DAV:}displayname' => 'Renamed on the phone']);
        $dav->updateCalendar($home, $patch);
        $patch->commit();
    });

    // What comes in from outside: a file, a feed, a plugin.
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:imported-1@example.test\r\nDTSTAMP:20261101T000000Z\r\nDTSTART:20261215T180000Z\r\nDTEND:20261215T190000Z\r\nSUMMARY:Imported one\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nUID:imported-2@example.test\r\nDTSTAMP:20261101T000000Z\r\nDTSTART:20261216T180000Z\r\nDTEND:20261216T190000Z\r\nSUMMARY:Imported two\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nEND:VEVENT\r\n"
        . "END:VCALENDAR\r\n";
    $importer = new CalendarImport($db, $d->calendars);
    $journaled('a file imported into a calendar', fn() => $importer->importIcs($u, $ics, 'ignored', null, $home));
    $journaled('a file imported as a new calendar', fn() => $importer->importIcs($u, $ics, 'Imported calendar'));

    $feed = (int) $d->calendars->create($u, ['name' => 'Club feed'], 'subscribed', 'https://feeds.example.test/club.ics')['id'];
    $feeds = new Feeds($db, null, ['session_secret' => str_repeat('t', 32)]);
    $calendarRow = static fn() => $db->one('SELECT * FROM calendars WHERE id = ?', [$feed]);
    $feeds->sync($calendarRow(), Ics::parse($ics));
    $fed = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ?', [$feed, 'imported-1@example.test']);
    $d->events->setAttendance($u, $fed, 'going');
    $changedFeed = str_replace(['SUMMARY:Imported one', "BEGIN:VEVENT\r\nUID:imported-2"], ['SUMMARY:Imported one, renamed', "BEGIN:VEVENT\r\nUID:gone-2"], $ics);
    $journaled("a feed's poll: an event changed, one gone, one new", fn() => $feeds->sync($calendarRow(), Ics::parse($changedFeed)));

    $db->run("INSERT INTO plugins (id, version, enabled) VALUES ('sample', '1.0.0', 1)");
    $host = new PluginHost($db, 'sample', $u, new HttpClient(), [], []);
    $pluginCal = 0;
    $journaled('a plugin making its calendar', function () use ($host, &$pluginCal): void {
        $pluginCal = $host->ensureCalendar('Sample items', '#557799');
    });
    $journaled("a plugin's sync: events added", fn() => $host->syncEvents($pluginCal, [['sourceKey' => 'a', 'title' => 'First', 'start' => '2026-12-01', 'allDay' => true], ['sourceKey' => 'b', 'title' => 'Second', 'start' => '2026-12-02', 'allDay' => true]]));
    $journaled("a plugin's sync: one changed, one gone", fn() => (new PluginHost($db, 'sample', $u, new HttpClient(), [], []))->syncEvents($pluginCal, [['sourceKey' => 'a', 'title' => 'First, renamed', 'start' => '2026-12-01', 'allDay' => true]]));
    $journaled('a subscription kept as a calendar of its own', fn() => $d->calendars->adopt($u, $feed));
};
