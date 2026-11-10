<?php

declare(strict_types=1);

// A day's tags and people (series-model.md, Own Fields): a live day row holds
// its own set where its own list names tags or people, and otherwise exactly
// its series' set as the series has it now. Every writer that makes, revives,
// re-lists or re-parents a day's row, or writes a series' links, is run here
// (local, Google, feed, CalDAV, import, plan, split, Finish, Put back, undo,
// redo), and after each one the rule is asserted over all of the account's
// data by raw SQL that calls nothing in server/src (decisions.md, "Checks
// share nothing with the product"). The last block makes a new writer of
// links or of day rows visible: it fails until the writer is named in this
// file's inventory. Invented data.

use BetterCal\Dav\CalendarBackend;
use BetterCal\Dav\DavIcs;
use BetterCal\Domain\ApiTokens;
use BetterCal\Domain\CalendarImport;
use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Ics;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\RequestAuthority;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Undo;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

require_once dirname(__DIR__, 3) . '/tools/google-probe/lib/FakeGoogle.php';

return static function (Db $db): void {
    // The rule as SQL, over one account: the suite's one definition
    // (lib.php, linkViolations: tags, people and a NULL list under a live
    // series), asserted after each step. A day left under a series this
    // step removed is a structure finding the block's own check reports at
    // its end (the "series" kind), not a link one, so it is left out here.
    $broken = static fn(int $u): array => array_values(array_map(static fn(array $v): string => $v['what'],
        array_filter(linkViolations($db, $u), static fn(array $v): bool => $v['kind'] !== 'series')));
    // Every writer the table below runs, by name, for the closing inventory.
    $ran = [];
    $holds = static function (string $writer, int $u) use ($broken, &$ran): void {
        $ran[$writer] = true;
        checkEq("link ownership: after $writer, every day holds its own set or its series' set", [], $broken($u));
    };
    $names = static fn(string $table, string $col, string $of, int $id): array => (static function () use ($db, $table, $col, $of, $id): array {
        $v = array_column($db->all("SELECT n.name FROM $table l JOIN $of n ON n.id = l.$col WHERE l.event_id = ? ORDER BY n.name", [$id]), 'name');
        return $v;
    })();
    $tagsOf = static fn(int $id): array => $names('event_tags', 'tag_id', 'tags', $id);
    $peopleOf = static fn(int $id): array => $names('event_people', 'person_id', 'people', $id);
    $dayRow = static fn(int $masterId, string $key): ?array => $db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$masterId, $key]);
    // A writer, then its undo, then its redo, the rule asserted after each.
    // A write that can't be undone (an answer sent to Google) passes $undoable false.
    $roundTrip = static function (string $writer, int $u, callable $fn, Undo $undo, bool $undoable = true) use ($holds, $db): void {
        $step = 'the write';
        try {
            $before = lastEntry($db, $u);
            $fn();
            $holds($writer, $u);
            $entry = lastEntry($db, $u);
            check("link ownership: $writer is journaled", $entry > $before);
            if (!$undoable) {
                return;
            }
            $step = 'its undo';
            $undo->undoById($u, $entry);
            $holds("undo of $writer", $u);
            // Redo is the undo's own entry undone, where the undo records
            // one (a Google calendar's); otherwise the write is made again,
            // so the next writer meets the state this one leaves.
            $step = 'its redo';
            if (lastEntry($db, $u) !== $entry) {
                $undo->undoById($u, lastEntry($db, $u));
                $holds("redo of $writer", $u);
            } else {
                $fn();
                $holds("$writer, made again", $u);
            }
        } catch (\Throwable $e) {
            check("link ownership: $writer, $step goes through", false, get_class($e) . ': ' . $e->getMessage());
        }
    };

    // Weekly on Mondays at 09:00 UTC from 4 January 2027, eight of them.
    $weekly = static fn(Events $events, int $u, int $cal, string $title = 'Sample weekly'): int => (int) $events->create($u, ['calendarId' => $cal, 'title' => $title,
        'start' => '2027-01-04T09:00:00Z', 'end' => '2027-01-04T10:00:00Z', 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=8',
        'tagNames' => ['Sample A'], 'personNames' => ['Alex Example']])['eventId'];
    $dav = static function (int $u) use ($db): CalendarBackend {
        $token = (new ApiTokens($db))->create($u, 'Sample client');
        return new CalendarBackend($db, new Undo($db), fn() => RequestAuthority::credential($u, (int) $token['id'], null));
    };
    $vevent = static fn(string $uid, string $body): string => "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20261101T000000Z\r\n$body\r\nEND:VEVENT\r\n";
    $seriesIcs = static function (string $uid, array $days = []) use ($vevent): string {
        $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\n"
            . $vevent($uid, "DTSTART:20270104T090000Z\r\nDTEND:20270104T100000Z\r\nRRULE:FREQ=WEEKLY;COUNT=8\r\nSUMMARY:Sample weekly");
        foreach ($days as $key => $title) {
            $out .= $vevent($uid, "RECURRENCE-ID:{$key}\r\nDTSTART:{$key}\r\nDTEND:" . substr($key, 0, 9) . "100000Z\r\nSUMMARY:$title");
        }
        return $out . "END:VCALENDAR\r\n";
    };

    // ---- The reviewer's probe: a CalDAV PUT makes a title-only edited day -------

    scenario('link ownership: a CalDAV PUT that renames one day of a tagged series with a person leaves the day with the series\' tags and people; its undo leaves an ordinary day', function () use ($db, $weekly, $dav, $seriesIcs, $tagsOf, $peopleOf, $dayRow, $holds): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $weekly($d->events, $u, $cal);
        $uid = (string) $db->scalar('SELECT uid FROM events WHERE id = ?', [$id]);
        $shown = static fn(): array => array_map(static fn(array $r): array => [$r['title'], $r['tags'], $r['people']],
            $d->events->window($u, new DateTimeImmutable('2027-01-11T00:00:00Z'), new DateTimeImmutable('2027-01-12T00:00:00Z'), [$cal], null, true));
        $dav($u)->updateCalendarObject($cal, DavIcs::objectUri($uid), $seriesIcs($uid, ['20270111T090000Z' => 'Sample renamed day']));
        $day = $dayRow($id, '2027-01-11 09:00:00');
        checkEq('link ownership: the PUT made the day a row owning its title alone', '["title"]', $day['override_fields'] ?? null);
        checkEq('link ownership: the day\'s row holds the series\' tag and person', [['Sample A'], ['Alex Example']], [$tagsOf((int) $day['id']), $peopleOf((int) $day['id'])]);
        $holds('a CalDAV PUT that makes a day', $u);
        $put = lastEntry($db, $u);
        $d->undo->undoById($u, $put);
        $holds('undo of a CalDAV PUT that made a day', $u);
        checkEq('link ownership: undone, the day is an ordinary occurrence again, no row', null, $dayRow($id, '2027-01-11 09:00:00'));
        checkEq('link ownership: undone, the day shows the series\' tag and person', [['Sample weekly', ['Sample A'], ['Alex Example']]], $shown());
    });

    scenario('link ownership: a day that owns its tags keeps them through a CalDAV PUT that renames it, and through its undo', function () use ($db, $weekly, $dav, $seriesIcs, $tagsOf, $peopleOf, $dayRow, $holds): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $weekly($d->events, $u, $cal);
        $uid = (string) $db->scalar('SELECT uid FROM events WHERE id = ?', [$id]);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'tagNames' => ['Sample B']]);
        $dav($u)->updateCalendarObject($cal, DavIcs::objectUri($uid), $seriesIcs($uid, ['20270111T090000Z' => 'Sample renamed day']));
        $day = $dayRow($id, '2027-01-11 09:00:00');
        checkEq('link ownership: after the PUT the day owns its title and its tags', '["title","tags"]', $day['override_fields']);
        checkEq('link ownership: the day keeps its own tag, and the series\' person', [['Sample B'], ['Alex Example']], [$tagsOf((int) $day['id']), $peopleOf((int) $day['id'])]);
        $holds('a CalDAV PUT over a day owning its tags', $u);
        $d->undo->undoById($u, lastEntry($db, $u));
        $holds('undo of a CalDAV PUT over a day owning its tags', $u);
        $day = $dayRow($id, '2027-01-11 09:00:00');
        checkEq('link ownership: undone, the day owns its tags alone and keeps them', ['["tags"]', ['Sample B'], ['Alex Example']], [$day['override_fields'], $tagsOf((int) $day['id']), $peopleOf((int) $day['id'])]);
    });

    scenario('link ownership: a CalDAV PUT that brings back a deleted series makes its days follow the series', function () use ($db, $weekly, $dav, $seriesIcs, $holds): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $weekly($d->events, $u, $cal);
        $uid = (string) $db->scalar('SELECT uid FROM events WHERE id = ?', [$id]);
        $client = $dav($u);
        $client->updateCalendarObject($cal, DavIcs::objectUri($uid), $seriesIcs($uid, ['20270111T090000Z' => 'Sample renamed day']));
        $client->deleteCalendarObject($cal, DavIcs::objectUri($uid));
        $client->createCalendarObject($cal, DavIcs::objectUri($uid), $seriesIcs($uid, ['20270111T090000Z' => 'Sample renamed day', '20270118T090000Z' => 'Sample other day']));
        $holds('a CalDAV PUT that revives a deleted series and its day', $u);
    });

    // ---- An import into a calendar that has the series already ---------------

    scenario('link ownership: an import adds an edited day under a tagged series already in the calendar; the day holds the series\' tags and people', function () use ($db, $weekly, $seriesIcs, $tagsOf, $peopleOf, $dayRow, $holds): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $weekly($d->events, $u, $cal);
        $uid = (string) $db->scalar('SELECT uid FROM events WHERE id = ?', [$id]);
        $out = (new CalendarImport($db, $d->calendars))->importIcs($u, $seriesIcs($uid, ['20270118T090000Z' => 'Sample imported day']), 'ignored', null, $cal);
        checkEq('link ownership: the series was there already, the day is new', [1, 1], [$out['imported'], $out['alreadyThere']]);
        $day = $dayRow($id, '2027-01-18 09:00:00');
        checkEq('link ownership: the imported day holds the series\' tag and person', [['Sample A'], ['Alex Example']], [$tagsOf((int) $day['id']), $peopleOf((int) $day['id'])]);
        $holds('an import into a calendar holding the series', $u);
        $d->undo->undoById($u, lastEntry($db, $u));
        $holds('undo of an import into a calendar holding the series', $u);
        checkEq('link ownership: undone, the imported day is gone', null, $dayRow($id, '2027-01-18 09:00:00'));
    });

    // ---- Every writer here: local, plan, split, use series, undo, redo ---------

    scenario('link ownership: every writer of a local series, each undone and redone', function () use ($db, $weekly, $roundTrip, $holds, $tagsOf, $dayRow): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $weekly($d->events, $u, $cal);
        $holds('a series made with tags and a person', $u);
        $roundTrip('a "this event" edit that makes a day (Events::patchThis)', $u,
            fn() => $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'title' => 'Sample renamed day']), $d->undo);
        $roundTrip('a "this event" edit of a day\'s row (Events::patchThis)', $u,
            fn() => $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'description' => 'Sample notes']), $d->undo);
        $roundTrip('a "this event" edit that gives a day its own tags and people (Events::patchThis)', $u,
            fn() => $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-18T09:00:00Z', 'tagNames' => ['Sample B'], 'personNames' => ['Sam Sample']]), $d->undo);
        $roundTrip('an answer for one day (Events::ensureOverride)', $u,
            fn() => $d->events->setAttendance($u, $id, 'going', 'this', '2027-01-25T09:00:00Z'), $d->undo);
        $roundTrip('a day\'s own row edited by its id (Events::patchAll on a day)', $u,
            fn() => $d->events->patch($u, (int) $dayRow($id, '2027-01-11 09:00:00')['id'], ['location' => 'Sample room 2']), $d->undo);
        $roundTrip('tags and people for all events (SeriesPlans::seriesPlanWriter)', $u,
            fn() => planned($d->events, $u, $id, ['scope' => 'all', 'tagNames' => ['Sample C'], 'personNames' => ['Sam Sample']]), $d->undo);
        checkEq('link ownership: a day that owned no tags took the series\' new tags', ['Sample C'], $tagsOf((int) $dayRow($id, '2027-01-11 09:00:00')['id']));
        $roundTrip('a title for all events, the series\' links untouched (SeriesPlans::seriesPlanWriter)', $u,
            fn() => planned($d->events, $u, $id, ['scope' => 'all', 'title' => 'Sample weekly, renamed']), $d->undo);
        $roundTrip('a clock change for all events (SeriesPlans::seriesPlanWriter, days re-keyed)', $u,
            fn() => planned($d->events, $u, $id, ['scope' => 'all', 'instanceStart' => '2027-01-04T09:00:00Z', 'start' => '2027-01-04T11:00:00Z', 'end' => '2027-01-04T12:00:00Z']), $d->undo);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-18T11:00:00Z', 'title' => 'Sample own day', 'tagNames' => ['Sample F']]);
        $roundTrip('the series\' tags taken back by a day (SeriesPlans::useSeriesValues)', $u,
            fn() => $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-18T11:00:00Z', 'useSeries' => ['tags']]), $d->undo);
        checkEq('link ownership: the day that took the series\' tags back holds them', ['Sample C'], $tagsOf((int) $dayRow($id, '2027-01-18 11:00:00')['id']));
        $roundTrip('a split from a day on with new tags (SeriesPlans::splitWriter)', $u,
            fn() => planned($d->events, $u, $id, ['scope' => 'following', 'instanceStart' => '2027-02-01T11:00:00Z', 'tagNames' => ['Sample D']]), $d->undo);
        // Undo of an older entry over later changes: the series' tags set, then
        // a day given its own title, then the tags undone.
        planned($d->events, $u, $id, ['scope' => 'all', 'tagNames' => ['Sample E']]);
        $tagsEntry = lastEntry($db, $u);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-25T11:00:00Z', 'title' => 'Sample later day']);
        $d->undo->undoById($u, $tagsEntry);
        $holds('undo of the series\' tags under a day made since', $u);
        $roundTrip('a day deleted (Events::deleteEvent this)', $u,
            fn() => $d->events->deleteEvent($u, $id, 'this', '2027-01-11T11:00:00Z'), $d->undo);
        $roundTrip('the series deleted whole (Events::deleteAll)', $u,
            fn() => $d->events->deleteEvent($u, $id, 'all', null), $d->undo);
    });

    // ---- A feed: a poll makes, keeps and re-parents days ---------------------

    scenario('link ownership: a feed poll that makes a day, then drops the series keeping the day, then brings the series back', function () use ($db, $holds, $seriesIcs, $tagsOf, $dayRow): void {
        $u = freshUser($db);
        $d = domains($db);
        $feeds = new Feeds($db);
        $feed = (int) $d->calendars->create($u, ['name' => 'Sample club'], 'subscribed', 'https://feeds.example.test/club.ics')['id'];
        $row = static fn() => $db->one('SELECT * FROM calendars WHERE id = ?', [$feed]);
        $uid = 'sample-club-weekly@example.test';
        $feeds->sync($row(), Ics::parse($seriesIcs($uid)));
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL', [$feed, $uid]);
        $d->events->patch($u, $master, ['scope' => 'all', 'tagNames' => ['Sample A']]);
        $holds('tags on a feed\'s series', $u);
        $feeds->sync($row(), Ics::parse($seriesIcs($uid, ['20270111T090000Z' => 'Sample feed day'])));
        $holds('a feed poll that makes a day (Feeds::sync, new row)', $u);
        checkEq('link ownership: the feed\'s new day holds the series\' tag', ['Sample A'], $tagsOf((int) $dayRow($master, '2027-01-11 09:00:00')['id']));
        // The series' record gone from the feed, its edited day still there.
        $onlyDay = str_replace("RRULE:FREQ=WEEKLY;COUNT=8\r\nSUMMARY:Sample weekly", 'SUMMARY:Sample weekly', $seriesIcs($uid, ['20270111T090000Z' => 'Sample feed day']));
        $parsed = array_values(array_filter(Ics::parse($onlyDay), static fn(array $p): bool => $p['recurrence_instance_utc'] !== null));
        $feeds->sync($row(), $parsed);
        $holds('a feed poll that drops a series and keeps its day (Feeds::sync, carried row)', $u);
        $feeds->sync($row(), Ics::parse($seriesIcs($uid, ['20270111T090000Z' => 'Sample feed day'])));
        $holds('a feed poll that brings the series back under a kept day (Feeds::sync, re-parented row)', $u);
    });

    scenario('link ownership: a poll whose series\' record is unreadable, or absent, while an edited day is readable makes the day\'s row with an own list', function () use ($db, $holds, $seriesIcs, $tagsOf, $dayRow): void {
        $nullLists = static fn(int $u): array => array_map('intval', array_column($db->all(
            'SELECT id FROM events WHERE user_id = ? AND recurrence_parent_id IS NOT NULL AND deleted_at IS NULL AND override_fields IS NULL', [$u]), 'id'));
        foreach (['unreadable, skipped with its uid' => 'uid', 'unreadable, skipped with no uid' => 'none', 'absent' => 'absent'] as $how => $skip) {
            $u = freshUser($db);
            $d = domains($db);
            $feeds = new Feeds($db);
            $feed = (int) $d->calendars->create($u, ['name' => 'Sample club'], 'subscribed', 'https://feeds.example.test/club.ics')['id'];
            $row = static fn() => $db->one('SELECT * FROM calendars WHERE id = ?', [$feed]);
            $uid = 'sample-club-weekly@example.test';
            $feeds->sync($row(), Ics::parse($seriesIcs($uid)));
            $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL', [$feed, $uid]);
            $d->events->patch($u, $master, ['scope' => 'all', 'tagNames' => ['Sample A']]);
            // The poll: the edited day alone, its series' record skipped (unreadable) or not there at all.
            $days = array_values(array_filter(Ics::parse($seriesIcs($uid, ['20270111T090000Z' => 'Sample feed day'])), static fn(array $p): bool => $p['recurrence_instance_utc'] !== null));
            $skipped = match ($skip) {
                'uid' => [['uid' => $uid, 'reason' => 'unreadable']],
                'none' => [['uid' => null, 'reason' => 'unreadable']],
                default => [],
            };
            $feeds->sync($row(), $days, true, null, $skipped);
            checkEq("link ownership: a poll with the series' record $how leaves no live day row without an own list", [], $nullLists($u));
            $day = $db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NOT NULL AND deleted_at IS NULL', [$feed, $uid]);
            check("link ownership: the poll with the series' record $how made the day's row", $day !== null);
            if ($skip !== 'absent') {
                checkEq("link ownership: under the series kept ($how), the day owns its title and holds the series' tag", ['["title"]', ['Sample A']],
                    [$day['override_fields'] ?? null, $day === null ? null : $tagsOf((int) $day['id'])]);
                $holds("a feed poll with the series' record $how (Feeds::sync, new row under a kept series)", $u);
            } else {
                checkEq('link ownership: with no series left, the day owns nothing, as 063 left such a day', '[]', $day['override_fields'] ?? null);
                // The series back: the day stands under it again and follows it.
                $feeds->sync($row(), Ics::parse($seriesIcs($uid, ['20270111T090000Z' => 'Sample feed day'])));
                checkEq('link ownership: the series back, no live day row is without an own list', [], $nullLists($u));
                $holds('a feed poll bringing back a series whose day it made while the series was away (Feeds::sync)', $u);
            }
        }
    });

    // A day's row with no own list, as a feed poll in 1.5.0 to 1.5.4 could
    // leave one (built here by hand: no writer here leaves one, as the poll
    // blocks above show), given its list by migration 078; then a change to
    // the series' tags and people for all events reaches it by its list.
    $fill = require dirname(__DIR__, 2) . '/migrations/078_day_lists_filled.php';
    scenario('link ownership: a day\'s row left with no own list is given one by the 1.5.5 upgrade, and then takes the series\' new tags and people', function () use ($db, $weekly, $tagsOf, $peopleOf, $dayRow, $fill): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $weekly($d->events, $u, $cal);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'title' => 'Sample older day']);
        $day = (int) $dayRow($id, '2027-01-11 09:00:00')['id'];
        $db->run('UPDATE events SET override_fields = NULL WHERE id = ?', [$day]);
        $fill($db);
        checkEq('link ownership: the upgrade gave the day its list by equality with its series: its title', '["title"]', $db->scalar('SELECT override_fields FROM events WHERE id = ?', [$day]));
        planned($d->events, $u, $id, ['scope' => 'all', 'tagNames' => ['Sample C'], 'personNames' => ['Sam Sample']]);
        checkEq('link ownership: the day took the series\' new tag and person, and keeps its own title', [['Sample C'], ['Sam Sample'], 'Sample older day'],
            [$tagsOf($day), $peopleOf($day), $db->scalar('SELECT title FROM events WHERE id = ?', [$day])]);
    });

    // ---- Undo follows every series its rows stand under now -------------------

    // A series with or without its tag and person, weekly from 4 January 2027.
    $series = static fn(Events $events, int $u, int $cal, array $more): int => (int) $events->create($u, ['calendarId' => $cal, 'title' => 'Sample weekly',
        'start' => '2027-01-04T09:00:00Z', 'end' => '2027-01-04T10:00:00Z', 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=8'] + $more)['eventId'];
    $personId = static fn(int $u, string $name): int => (int) $db->scalar('SELECT id FROM people WHERE user_id = ? AND name = ?', [$u, $name]);

    scenario('link ownership: undoing a person\'s delete or merge leaves every day holding its own people or exactly its series\'', function () use ($db, $series, $personId, $holds, $peopleOf, $dayRow): void {
        // Deleted, a day edited, the delete undone: the day takes the person back with its series.
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $series($d->events, $u, $cal, ['personNames' => ['Alex Example']]);
        $people = new BetterCal\Domain\People($db);
        $people->delete($u, $personId($u, 'Alex Example'));
        $entry = lastEntry($db, $u);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'title' => 'Sample renamed day']);
        $d->undo->undoById($u, $entry);
        $holds('undo of a person deleted, a day made since (People::delete)', $u);
        checkEq('link ownership: the day made since the delete holds the person brought back', ['Alex Example'], $peopleOf((int) $dayRow($id, '2027-01-11 09:00:00')['id']));
        // Merged into another, a day edited, the merge undone: the day is back with the first person.
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $series($d->events, $u, $cal, ['personNames' => ['Alex Example']]);
        $d->events->create($u, eventAt($cal, 'Sample other', '2027-02-01', ['personNames' => ['Sam Sample']]));
        $people = new BetterCal\Domain\People($db);
        $people->update($u, $personId($u, 'Alex Example'), ['name' => 'Sam Sample']);
        $entry = lastEntry($db, $u);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'title' => 'Sample renamed day']);
        $d->undo->undoById($u, $entry);
        $holds('undo of a person merged, a day made since (People::update, a rename onto a name taken)', $u);
        checkEq('link ownership: the day made since the merge holds the first person again', ['Alex Example'], $peopleOf((int) $dayRow($id, '2027-01-11 09:00:00')['id']));
        // A day owning a person, the person deleted, the day given back its series' people, the delete undone:
        // the person's link put back onto a day that no longer owns its people is the series' copy, so it holds the series' set.
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $series($d->events, $u, $cal, ['personNames' => ['Sam Sample']]);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'title' => 'Sample renamed day', 'personNames' => ['Alex Example']]);
        $people = new BetterCal\Domain\People($db);
        $people->delete($u, $personId($u, 'Alex Example'));
        $entry = lastEntry($db, $u);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'useSeries' => ['people']]);
        $d->undo->undoById($u, $entry);
        $holds('undo of a person deleted, onto a day that gave its people back to its series since', $u);
        checkEq('link ownership: the day that gave its people back holds exactly its series\' set', ['Sam Sample'], $peopleOf((int) $dayRow($id, '2027-01-11 09:00:00')['id']));
    });

    scenario('link ownership: a forced undo of a day\'s own tags or people after a split moved the day leaves it with its new series\' set', function () use ($db, $series, $holds, $tagsOf, $peopleOf, $dayRow): void {
        foreach (['tags' => ['tagNames', ['Sample A'], ['Sample B'], ['Sample C']], 'people' => ['personNames', ['Alex Example'], ['Sam Sample'], ['Pat Placeholder']]] as $field => [$key, $seriesSet, $daySet, $splitSet]) {
            $u = freshUser($db);
            $d = domains($db);
            $cal = Settings::defaultCalendarId($db, $u);
            $id = $series($d->events, $u, $cal, [$key => $seriesSet]);
            $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-25T09:00:00Z', 'title' => 'Sample own day']);
            $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-25T09:00:00Z', $key => $daySet]);
            $entry = lastEntry($db, $u);
            planned($d->events, $u, $id, ['scope' => 'following', 'instanceStart' => '2027-01-18T09:00:00Z', $key => $splitSet]);
            $d->undo->undoById($u, $entry, true);
            $holds("a forced undo of a day's own $field after a split moved it (Undo::followLinks, the series it stands under now)", $u);
            $day = (int) $db->scalar('SELECT id FROM events WHERE user_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$u, '2027-01-25 09:00:00']);
            checkEq("link ownership: the day holds its new series' $field", $splitSet, $field === 'tags' ? $tagsOf($day) : $peopleOf($day));
        }
    });

    // ---- An import that meets a deleted series of the same UID ----------------

    scenario('link ownership: importing a series and its edited day after the series was deleted brings the series back with the file\'s content; its undo deletes it again', function () use ($db, $series, $seriesIcs, $holds, $tagsOf, $dayRow): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $series($d->events, $u, $cal, ['tagNames' => ['Sample A']]);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'title' => 'Sample older day']);
        $uid = (string) $db->scalar('SELECT uid FROM events WHERE id = ?', [$id]);
        $d->events->deleteEvent($u, $id, 'all', null);
        $out = (new CalendarImport($db, $d->calendars))->importIcs($u, $seriesIcs($uid, ['20270118T090000Z' => 'Sample imported day']), 'ignored', null, $cal);
        checkEq('link ownership: the series and its day come in, the series brought back; nothing is said to be there already', [2, 1, 0], [$out['imported'], $out['broughtBack'], $out['alreadyThere']]);
        checkEq('link ownership: the series is live again under its own id, with the file\'s title, and holds its tag', [null, 'Sample weekly', ['Sample A']],
            [$db->scalar('SELECT deleted_at FROM events WHERE id = ?', [$id]), $db->scalar('SELECT title FROM events WHERE id = ?', [$id]), $tagsOf($id)]);
        $day = $dayRow($id, '2027-01-18 09:00:00');
        checkEq('link ownership: the imported day stands under the series brought back and holds its tag', ['Sample A'], $day === null ? null : $tagsOf((int) $day['id']));
        checkEq('link ownership: the deleted day the file does not carry is gone for good, as a PUT leaves it', 0, (int) $db->scalar('SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ?', [$id, '2027-01-11 09:00:00']));
        $holds('an import that brings back a deleted series (CalendarImport::importIcs)', $u);
        $d->undo->undoById($u, lastEntry($db, $u));
        $holds('undo of an import that brought back a deleted series', $u);
        checkEq('link ownership: undone, the series is deleted again and its deleted day is back as it was, deleted', [true, 1],
            [$db->scalar('SELECT deleted_at FROM events WHERE id = ?', [$id]) !== null,
             (int) $db->scalar('SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NOT NULL', [$id, '2027-01-11 09:00:00'])]);
    });

    // The hunter's case: an answer for a day left with no own list, which no
    // writer here leaves now; a row built that way by hand is given its list
    // by migration 078, which keeps the tags it owned, and an answer keeps them.
    scenario('link ownership: a day left with no own list and its own tags, given its list by the upgrade, keeps them through an answer', function () use ($db, $series, $holds, $tagsOf, $fill): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $series($d->events, $u, $cal, ['tagNames' => ['Sample A']]);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'title' => 'Sample own day', 'tagNames' => ['Sample B']]);
        $day = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$id]);
        $db->run('UPDATE events SET override_fields = NULL WHERE id = ?', [$day]);
        $fill($db);
        checkEq('link ownership: the upgrade gave the day its title and its tags', '["title","tags"]', $db->scalar('SELECT override_fields FROM events WHERE id = ?', [$day]));
        $d->events->setAttendance($u, $id, 'going', 'this', '2027-01-11T09:00:00Z');
        checkEq('link ownership: after the answer the day owns its title, its tags and its answer', '["title","attendance","tags"]', $db->scalar('SELECT override_fields FROM events WHERE id = ?', [$day]));
        $holds('an answer for a day the upgrade gave its list (Events::answerAttendance)', $u);
        planned($d->events, $u, $id, ['scope' => 'all', 'instanceStart' => '2027-01-04T09:00:00Z', 'tagNames' => ['Sample C']]);
        checkEq('link ownership: the day keeps the tags it owned', ['Sample B'], $tagsOf($day));
    });

    // ---- Google: the stand-in, a writer of each kind, Finish and Put back -----

    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $tz = 'America/Los_Angeles';
    $la = new DateTimeZone($tz);
    $iso = static fn(string $local): string => (new DateTimeImmutable($local, $la))->format('Y-m-d\TH:i:sP');
    $when = static fn(string $local): array => ['dateTime' => $iso($local), 'timeZone' => $tz];
    $expand = static function (array $master): array {
        $allDay = isset($master['start']['date']);
        $zone = $allDay ? 'UTC' : (string) ($master['start']['timeZone'] ?? 'UTC');
        $start = $allDay ? new DateTimeImmutable($master['start']['date'] . ' 00:00:00', new DateTimeZone('UTC')) : new DateTimeImmutable((string) $master['start']['dateTime']);
        $end = $allDay ? new DateTimeImmutable($master['end']['date'] . ' 00:00:00', new DateTimeZone('UTC')) : new DateTimeImmutable((string) $master['end']['dateTime']);
        $rrule = null;
        foreach ((array) ($master['recurrence'] ?? []) as $line) {
            if (str_starts_with((string) $line, 'RRULE:')) {
                $rrule = substr((string) $line, 6);
            }
        }
        if ($rrule === null) {
            return [];
        }
        $row = ['start_utc' => Time::toDb($start), 'end_utc' => Time::toDb($end), 'all_day' => $allDay ? 1 : 0, 'tzid' => $zone, 'rrule' => $rrule];
        return array_map(static fn(array $o): DateTimeImmutable => $o['start']->setTimezone(new DateTimeZone($zone)), Recurrence::occurrences($row, $start, $start->modify('+30 years'), null, 730));
    };
    // Weekly Mondays 10:00 Los Angeles from 1 March 2027, ten of them: the
    // third a day later, the fifth with its own title, the sixth skipped.
    $google = static function () use ($db, $cfg, $expand, $when): object {
        $u = freshUser($db);
        $d = domains($db);
        $fake = new FakeGoogle('sample-crew@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->expand = $expand;
        $fake->seed(['id' => 'smpl3weekly', 'iCalUID' => 'smpl3weekly@google.com', 'summary' => 'Sample weekly', 'description' => 'Sample notes',
            'start' => $when('2027-03-01 10:00'), 'end' => $when('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
        $record = static fn(string $suffix, string $orig, array $more): array => $more + ['id' => 'smpl3weekly_' . $suffix, 'iCalUID' => 'smpl3weekly@google.com', 'recurringEventId' => 'smpl3weekly',
            'summary' => 'Sample weekly', 'description' => 'Sample notes', 'originalStartTime' => $when($orig)];
        $fake->seed($record('20270315T170000Z', '2027-03-15 10:00', ['start' => $when('2027-03-16 10:00'), 'end' => $when('2027-03-16 11:00')]));
        $fake->seed($record('20270329T170000Z', '2027-03-29 10:00', ['start' => $when('2027-03-29 10:00'), 'end' => $when('2027-03-29 11:00'), 'summary' => 'Sample weekly, special']));
        $fake->seed($record('20270405T170000Z', '2027-04-05 10:00', ['start' => $when('2027-04-05 10:00'), 'end' => $when('2027-04-05 11:00'), 'status' => 'cancelled']));
        $t = new class {
            public int $writes = 0;
            public ?int $loseAt = null;
        };
        $inner = $fake->writerTransport();
        $transport = static function (string $method, string $url, ?array $payload, array $headers = []) use ($t, $inner): array {
            $n = $method !== 'GET' ? ++$t->writes : null;
            $answer = $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
            if ($n !== null && $t->loseAt === $n) {
                $t->loseAt = null;
                throw new RuntimeException('the answer was lost');
            }
            return $answer;
        };
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $writer = new GoogleWriter($db, $auth, $feeds, $transport);
        $syncTransport = $fake->syncTransport();
        $sync = new GoogleSync($db, $auth, $feeds, static fn(string $url, array $headers): array => $syncTransport($url, array_merge(['Authorization: Bearer sample-access'], $headers)));
        $undo = new Undo($db, null, $writer);
        $events = new Events($db, new Recurrence(), $undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $undo, new BetterCal\Infra\JobQueue($db)), new BetterCal\Domain\Trips($db, $undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample crew', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'sample-crew@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $t->writes = 0;
        $master = static fn(): int => (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$calendarId, 'smpl3weekly']);
        return (object) ['u' => $u, 'fake' => $fake, 't' => $t, 'sync' => $sync, 'events' => $events, 'undo' => $undo, 'calendarId' => $calendarId, 'master' => $master];
    };
    $recover = static function (object $s, string $how) use ($db): array {
        $half = (new ReviewQueue($db, $s->events))->googleHalfDone($s->u);
        $out = $how === 'finish' ? $s->events->googleFinish($s->u, (int) $half[0]['id']) : $s->events->googlePutBack($s->u, (int) $half[0]['id']);
        $s->events->throwDeferred();
        return $out;
    };
    // A write while a change is not yet at Google is refused (A6); recovery
    // still meets rows changed since, so a case makes that change out of the gate's sight.
    $unguarded = static function (object $s, callable $write) use ($db): void {
        $db->run("UPDATE google_pending SET status = 'unseen' WHERE user_id = ? AND status = 'half'", [$s->u]);
        try {
            $write();
        } finally {
            $db->run("UPDATE google_pending SET status = 'half' WHERE user_id = ? AND status = 'unseen'", [$s->u]);
        }
    };
    $halfDone = static function (object $s, callable $write): void {
        $s->t->loseAt = $s->t->writes + 1;
        try {
            $write();
        } catch (HttpError) {
        }
    };

    scenario('link ownership: every writer of a Google series (a day made, a day\'s own tags, tags for all events, a poll), each undone and redone where it is undoable', function () use ($google, $iso, $holds, $roundTrip, $tagsOf, $dayRow, $db): void {
        $s = $google();
        $holds('a Google series stored by a poll', $s->u);
        $roundTrip('tags and people for all events on Google (SeriesPlans::googlePatchAllPlanned)', $s->u,
            fn() => planned($s->events, $s->u, ($s->master)(), ['scope' => 'all', 'tagNames' => ['Sample A'], 'personNames' => ['Alex Example']]), $s->undo);
        $roundTrip('a "this event" edit on Google that makes a day (Events::googlePatchThis)', $s->u,
            fn() => $s->events->patch($s->u, ($s->master)(), ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00'), 'title' => 'Sample renamed day']), $s->undo);
        $roundTrip('a "this event" edit on Google held here alone (Events::googlePatchThis, nothing sent)', $s->u,
            fn() => $s->events->patch($s->u, ($s->master)(), ['scope' => 'this', 'instanceStart' => $iso('2027-03-22 10:00'), 'tagNames' => ['Sample B']]), $s->undo);
        $roundTrip('an answer for one day on Google (Events::ensureOverride)', $s->u,
            fn() => $s->events->setAttendance($s->u, ($s->master)(), 'going', 'this', $iso('2027-04-12 10:00')), $s->undo, false);
        $roundTrip('a title for all events on Google (SeriesPlans::googlePatchAllPlanned, links untouched)', $s->u,
            fn() => planned($s->events, $s->u, ($s->master)(), ['scope' => 'all', 'title' => 'Sample weekly, renamed']), $s->undo);
        $s->fake->seed(['id' => 'smpl3weekly_20270419T170000Z', 'iCalUID' => 'smpl3weekly@google.com', 'recurringEventId' => 'smpl3weekly', 'summary' => 'Sample changed at Google',
            'description' => 'Sample notes', 'originalStartTime' => ['dateTime' => $iso('2027-04-19 10:00'), 'timeZone' => 'America/Los_Angeles'],
            'start' => ['dateTime' => $iso('2027-04-19 10:00'), 'timeZone' => 'America/Los_Angeles'], 'end' => ['dateTime' => $iso('2027-04-19 11:00'), 'timeZone' => 'America/Los_Angeles']]);
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        $holds('a Google poll that makes a day changed at Google (Feeds::sync)', $s->u);
        $day = $dayRow(($s->master)(), Time::toDb(new DateTimeImmutable($iso('2027-04-19 10:00'))));
        checkEq('link ownership: the day changed at Google holds the series\' tag', ['Sample A'], $day === null ? null : $tagsOf((int) $day['id']));
        $roundTrip('a split on Google from a day on with new tags (SeriesPlans::googleSplitPlanned)', $s->u,
            fn() => planned($s->events, $s->u, ($s->master)(), ['scope' => 'following', 'instanceStart' => $iso('2027-04-26 10:00'), 'tagNames' => ['Sample D']]), $s->undo);
    });

    scenario('link ownership: Finish of a change whose tags and people the series changed here since leaves every day following the series', function () use ($google, $recover, $unguarded, $halfDone, $holds, $tagsOf, $peopleOf, $db): void {
        foreach (['tags' => ['tagNames' => ['Sample A']], 'people' => ['personNames' => ['Alex Example']]] as $field => $set) {
            $s = $google();
            $halfDone($s, fn() => planned($s->events, $s->u, ($s->master)(), ['scope' => 'all', 'title' => 'Sample pending rename'] + $set));
            $later = $field === 'tags' ? ['tagNames' => ['Sample B']] : ['personNames' => ['Sam Sample']];
            $unguarded($s, fn() => planned($s->events, $s->u, ($s->master)(), ['scope' => 'all'] + $later));
            $s->events->throwDeferred();
            $out = $recover($s, 'finish');
            check("link ownership: Finish ok with the series' $field changed since", $out['ok'] === true);
            $holds("Finish of a change whose $field the series changed since (SeriesPlans::googleLocalPlan)", $s->u);
            $of = $field === 'tags' ? $tagsOf : $peopleOf;
            $days = array_map('intval', array_column($db->all("SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL AND override_fields NOT LIKE ?", [($s->master)(), '%"' . $field . '"%']), 'id'));
            check("link ownership: the series has days that follow it ($field)", $days !== []);
            foreach ($days as $dayId) {
                checkEq("link ownership: after Finish a day not owning $field holds the series' as it stands", $of(($s->master)()), $of($dayId));
            }
        }
    });

    scenario('link ownership: Finish and Put back of a half-done change, a day owning its tags and one following, each shape', function () use ($google, $recover, $halfDone, $holds, $iso, $tagsOf, $db): void {
        $shapes = [
            'a rename with new tags' => fn(object $s) => planned($s->events, $s->u, ($s->master)(), ['scope' => 'all', 'title' => 'Sample pending rename', 'tagNames' => ['Sample C']]),
            'a clock change' => fn(object $s) => planned($s->events, $s->u, ($s->master)(), ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'start' => $iso('2027-03-01 11:00'), 'end' => $iso('2027-03-01 12:00')]),
            'a split with new tags' => fn(object $s) => planned($s->events, $s->u, ($s->master)(), ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample split', 'tagNames' => ['Sample D']]),
        ];
        foreach ($shapes as $shape => $write) {
            foreach (['finish', 'put back'] as $how) {
                $s = $google();
                planned($s->events, $s->u, ($s->master)(), ['scope' => 'all', 'tagNames' => ['Sample A'], 'personNames' => ['Alex Example']]);
                $s->events->patch($s->u, ($s->master)(), ['scope' => 'this', 'instanceStart' => $iso('2027-03-29 10:00'), 'tagNames' => ['Sample B']]);
                $owner = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND override_fields LIKE ? AND deleted_at IS NULL', [($s->master)(), '%"tags"%']);
                $halfDone($s, fn() => $write($s));
                $out = $recover($s, $how);
                check("link ownership: $how of $shape ok", ($out['ok'] ?? false) === true, json_encode($out));
                $holds("$how of $shape (GoogleSeries rewind, bringBack, reattach, relink, carryHeld)", $s->u);
                $owned = $db->one('SELECT id, override_fields FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL',
                    [$s->calendarId, Time::toDb(new DateTimeImmutable($how === 'put back' || $shape === 'a rename with new tags' || $shape === 'a split with new tags' ? $iso('2027-03-29 10:00') : $iso('2027-03-29 11:00')))]);
                // Owned still, its own tag kept. A day a half-done store moved
                // onto a row of its image gave that row its tags unioned
                // (decisions.md, "A feed's change to a day keeps what is held
                // here"), so the series' tag may be among them after Put back.
                checkEq("link ownership: after $how of $shape the day that owned its tags still owns them", [true, true],
                    $owned === null ? null : [str_contains((string) $owned['override_fields'], '"tags"'), in_array('Sample B', $tagsOf((int) $owned['id']), true)]);
                unset($owner);
            }
        }
    });

    // ---- The inventory: a writer not named here fails this block -------------

    scenario('link ownership: every statement that writes links or makes a row in server/src is in this file\'s inventory', function () use (&$ran): void {
        // Each place in server/src that writes event_tags or event_people,
        // calls a link setter, or inserts or upserts an events row, by file
        // and function. A new one fails here until it is named: as run by a
        // block above (a day row's writer: the rule is asserted after it),
        // or as neutral with the reason. Read as text: nothing is called.
        $inventory = [
            // Writers of day rows or of a master's links, run above.
            'Dav/CalendarBackend.php:putObject' => 'run: CalDAV PUT, new, owned, revived',
            'Domain/CalendarImport.php:importIcs' => 'run: import into a calendar holding the series',
            'Domain/Events.php:googlePatchThis' => 'run: "this event" on Google',
            'Domain/Events.php:ensureOverride' => 'run: an answer for one day',
            'Domain/Events.php:patchThis' => 'run: "this event" edits',
            'Domain/Events.php:patchAll' => 'run: a day\'s own row by its id',
            'Domain/Events.php:googlePatchAll' => 'neutral for days: a day\'s own row or a single event, its list named by ownAfterRowEdit',
            'Domain/SeriesPlans.php:seriesPlanWriter' => 'run: tags, title, clock for all events; Finish',
            'Domain/SeriesPlans.php:splitWriter' => 'run: split here and on Google',
            'Domain/Feeds.php:sync' => 'run: feed poll, Google poll',
            'Domain/Feeds.php:mergeInto' => 'a holder that owns what it took (its list names it)',
            'Domain/GoogleSeries.php:bringBack' => 'run: Put back and Finish shapes',
            'Domain/GoogleSeries.php:carryHeld' => 'run: Put back and Finish shapes',
            'Domain/GoogleSeries.php:relinks' => 'run: through carryHeld',
            'Domain/GoogleSeries.php:relink' => 'run: through reattach',
            'Domain/Undo.php:restoreRow' => 'run: every undo and redo',
            'Domain/Undo.php:restoreWhole' => 'run: every undo and redo',
            'Domain/Undo.php:putBack' => 'run: every undo and redo (removes rows an entry made)',
            'Domain/Undo.php:deleteByPk' => 'run: every undo and redo (removes rows)',
            'Domain/Series/OwnFields.php:follow' => 'the one function',
            'Domain/Labels.php:setEventTags' => 'the link setter (follows a master\'s days)',
            'Domain/Labels.php:replaceEventPeople' => 'the link setter (follows a master\'s days)',
            'Domain/Labels.php:setEventPeople' => 'the link setter by name (no caller)',
            'Domain/Events.php:applyPeoplePatch' => 'the link setter for a request\'s people',
            // Neutral: rows that are not day rows, or links of rows that are not.
            'Domain/Events.php:createLocked' => 'neutral: a new event or series has no days',
            'Domain/Events.php:googleCreate' => 'neutral: a new event or series has no days',
            'Domain/Events.php:detachToCalendar' => 'neutral: a standalone event',
            'Domain/People.php:updateLocked' => 'neutral: a person merged, the same set function on every event',
            'Plugin/PluginHost.php:syncEvents' => 'neutral: plugin events have no series',
            'Infra/Db.php:update' => 'neutral: the statement builder the writers above use',
            'Infra/Db.php:upsert' => 'neutral: the statement builder the writers above use',
            'Infra/Db.php:upsertMany' => 'neutral: the statement builder the writers above use',
        ];
        $found = [];
        $journals = [];
        $root = dirname(__DIR__, 2) . '/src';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $fn = '?';
            foreach (file($file->getPathname()) as $line) {
                if (preg_match('/function\s+(\w+)\s*\(/', $line, $m) && !preg_match('/\bfn\s*\(|static fn|=\s*function/', $line)) {
                    $fn = $m[1];
                }
                $writes = preg_match('/(INTO|DELETE FROM|UPDATE)\s+`?(event_tags|event_people|\$table)\b|(setEventTags|replaceEventPeople|setEventPeople|applyPeoplePatch)\(|->(insert|upsert|upsertMany)\(\s*(\'events\'|\$table)/', $line)
                    && !preg_match('/^\s*(\*|\/\/)/', $line) && !preg_match('/function\s+\w+\s*\(/', $line);
                if ($writes) {
                    $found[substr($file->getPathname(), strlen($root) + 1) . ':' . $fn] = true;
                }
                // The undo side: a place that reads link rows into an entry's
                // snapshots, whose undo puts them back.
                if (preg_match('/SELECT \* FROM `?(event_tags|event_people)`?\b|\'(event_tags|event_people)\' => \["event_id IN/', $line) && !preg_match('/^\s*(\*|\/\/)/', $line)) {
                    $journals[substr($file->getPathname(), strlen($root) + 1) . ':' . $fn] = true;
                }
            }
        }
        // Every place that journals link rows, with the block above that undoes
        // what it journals. An undo puts link rows back only through
        // Undo::putBack, which follows every series a restored row's event
        // stands under now (Undo::followLinks reads the link tables too), so a
        // new place is covered by construction; naming it here makes it seen
        // and asks for a block that runs its undo.
        $journalInventory = [
            'Domain/People.php:updateLocked' => 'run: undo of a person merged, a day made since',
            'Domain/People.php:delete' => 'run: undo of a person deleted, a day made since; and onto a day that gave its people back',
            'Domain/Labels.php:eventLinkRows' => 'run: every "this event" edit and plan, undone',
            'Domain/GoogleUndo.php:rowTables' => 'run: every Google writer above, undone',
            'Domain/Undo.php:linksOf' => 'run: CalDAV PUT, import, every plan, undone',
        ];
        $unjournaled = array_values(array_diff(array_keys($journals), array_keys($journalInventory)));
        sort($unjournaled);
        checkEq('link ownership: no place that journals link rows is missing from the undo inventory', [], $unjournaled);
        $journalStale = array_values(array_diff(array_keys($journalInventory), array_keys($journals)));
        sort($journalStale);
        checkEq('link ownership: the undo inventory names no place that no longer journals link rows', [], $journalStale);
        $unnamed = array_values(array_diff(array_keys($found), array_keys($inventory)));
        sort($unnamed);
        checkEq('link ownership: no statement writing links or making a row is missing from the inventory (name it, run it above, or say why it is neutral)', [], $unnamed);
        $stale = array_values(array_diff(array_keys($inventory), array_keys($found)));
        sort($stale);
        checkEq('link ownership: the inventory names no place that no longer writes', [], $stale);
        check('link ownership: the writers run above number at least the inventory\'s run entries', count($ran) >= count(array_filter($inventory, static fn(string $v): bool => str_starts_with($v, 'run:'))));
    });
};
