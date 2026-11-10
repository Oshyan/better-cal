<?php

declare(strict_types=1);

// The gate on an undo or redo of a change to one day of a series
// (docs/design/decisions.md, "An undo acts on a day only where the journal
// says exactly where it is"; Undo::dayNow). It acts only where the journal
// fixes the day: nothing re-placed the series since; or the entry's own row
// is still there; or every re-placing change since recorded a move for the
// day (or, for a delete's own skip, left it standing). Otherwise it is
// refused, forced or not, with not_undoable, nothing written and nothing
// sent. Never another day.
//
// These are the cases an independent hunt found wrong, each with its
// expected day written out by hand from the dates in its own comment.
// Nothing in server/src computes an expectation. Google is the edit
// harness's stand-in. Invented data.

use BetterCal\Domain\Feeds;
use BetterCal\Domain\Ics;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Undo;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    // The words of each refusal, written out here.
    $unplaced = static fn(string $what, bool $redo = false): string => "not_undoable/day_unplaced: This series $what since this was done, so it's no longer certain which day it was on, and it can't be "
        . ($redo ? 'redone' : 'undone') . ' here. You can make the change again on the day.';
    $outdated = static fn(string $what): string => "not_undoable/day_unplaced: This series $what since this was done, and what this change held is of the series as it was, so it can't be undone here. You can make the change again on the day.";
    $gone = 'not_undoable/day_gone: That day is no longer part of the series this change was made in (a later change ended the series before it, moved it into another series, or changed how it repeats), so this can\'t be undone.';
    $daysSince = "not_undoable/days_since: Days of this series were changed after this, and putting its time back would leave them on other days, so this can't be undone here. You can change the time back by hand.";
    $zone = 'changed its time zone';
    $form = 'changed between timed and all-day';
    $moved = 'was moved to another time or day';

    $undoOf = static function (Undo $undo, int $u, int $entry, bool $force = false): string {
        try {
            $undo->undoById($u, $entry, $force);
            return 'done';
        } catch (HttpError $e) {
            return $e->errorCode . (isset($e->extra['reason']) ? '/' . $e->extra['reason'] : '') . ': ' . $e->getMessage();
        }
    };

    // ---- A Google calendar: one account, one series, the stand-in ----
    $google = static function (string $name, array $create, callable $fn) use ($db, $undoOf): void {
        scenario('undo day gate, google: ' . $name, function () use ($db, $create, $fn, $name, $undoOf): void {
            $u = freshUser($db);
            seriesUndo($db, $u, false);
            $d = domains($db);
            $s = GoogleStand::make($db, $d->undo, $u);
            try {
                $w = new stdClass();
                $w->db = $db;
                $w->u = $u;
                $w->s = $s;
                $w->e = $s->events;
                $undo = new Undo($db, null, $s->writer);
                $w->id = (int) $s->events->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample practice', 'description' => 'Sample notes'] + $create)['eventId'];
                $w->gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$w->id]);
                $w->this1 = static function (string $at, array $in) use ($w): void {
                    $w->e->patch($w->u, $w->id, ['scope' => 'this', 'instanceStart' => $at] + $in);
                    $w->e->throwDeferred();
                };
                $w->deleteDay = static function (string $at) use ($w): void {
                    $w->e->deleteEvent($w->u, $w->id, 'this', $at);
                    $w->e->throwDeferred();
                };
                $w->series = static function (string $scope, string $at, array $in, array $choices = []) use ($w): void {
                    planned($w->e, $w->u, $w->id, ['scope' => $scope, 'instanceStart' => $at] + $in, $choices);
                    $w->e->throwDeferred();
                };
                // A change made at Google (to the series, or to one instance by its id suffix), then a poll.
                $w->remote = static function (array $body, ?string $suffix = null) use ($w): void {
                    $answer = ($w->s->fake->transport())('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events/' . rawurlencode($w->gid . ($suffix === null ? '' : '_' . $suffix)),
                        ['Authorization: Bearer sample-access', 'X-Harness: remote'], \BetterCal\GoogleProbe\FakeGoogle::asSet($body));
                    if ((int) $answer['status'] !== 200) {
                        throw new RuntimeException('the stand-in refused the change made at Google: ' . $answer['body']);
                    }
                    $w->s->poll();
                };
                $w->last = static fn(): int => lastEntry($db, $u);
                $w->undo = static function (int $entry, bool $force = false) use ($w, $undo, $undoOf): string {
                    $w->s->fake->requests = [];
                    return $undoOf($undo, $w->u, $entry, $force);
                };
                // The writes Google was sent since the last undo began: [method, the id's suffix after the series id].
                $w->sent = static function () use ($w): array {
                    $out = [];
                    foreach ($w->s->fake->requests as $r) {
                        if ($r['method'] !== 'GET') {
                            $out[] = [$r['method'], substr(basename((string) $r['path']), strlen($w->gid) + 1)];
                        }
                    }
                    return $out;
                };
                // The day rows here: key => [notes, tags].
                $w->rows = static function () use ($w): array {
                    $out = [];
                    foreach ($w->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$w->id]) as $r) {
                        $out[(string) $r['recurrence_instance_utc']] = [(string) $r['description'],
                            array_column($w->db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [(int) $r['id']]), 'name')];
                    }
                    return $out;
                };
                $w->skips = static fn(): array => json_decode((string) ($w->db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$w->id]) ?? '[]'), true) ?: [];
                // What the stand-in holds: its records that are not the series' own values (key => notes), and its skipped keys.
                $w->atGoogle = static function () use ($w): array {
                    $held = $w->s->held($w->gid);
                    return $held === null ? ['records' => null, 'skips' => null] : ['records' => array_map(static fn(array $r): string => (string) $r[5], $held['records']), 'skips' => $held['skips']];
                };
                // Everything an undo may write here, for "nothing was written".
                $w->all = static fn(): array => [
                    $w->db->all('SELECT * FROM events WHERE user_id = ? ORDER BY id', [$w->u]),
                    $w->db->all('SELECT et.* FROM event_tags et JOIN events e ON e.id = et.event_id WHERE e.user_id = ? ORDER BY et.event_id, et.tag_id', [$w->u]),
                    $w->db->all('SELECT id, undone FROM mutations WHERE user_id = ? ORDER BY id', [$w->u]),
                ];
                // A refusal: its exact words, no request to Google of any kind, and every table as it was; forced, the same.
                $w->refused = static function (string $name, int $entry, string $words) use ($w): void {
                    $was = ($w->all)();
                    $result = ($w->undo)($entry);
                    checkEq("$name: refused, in words that say what changed", $words, $result);
                    checkEq("$name: refused before anything: no request to Google of any kind, and every table as it was", [[], true], [array_column($w->s->fake->requests, 'method'), $was === ($w->all)()]);
                    $forced = ($w->undo)($entry, true);
                    checkEq("$name: forced, the same refusal, nothing sent, nothing written", [$words, [], true], [$forced, array_column($w->s->fake->requests, 'method'), $was === ($w->all)()]);
                };
                $fn($w, $name);
            } finally {
                GoogleStand::forget($db, $u);
            }
        });
    };

    // Daily, ten times, from Mon 4 Jan 2027 at 19:00 in New York (UTC-5 in January).
    //   5 Jan = 2027-01-06 00:00:00 UTC, 6 Jan = 2027-01-07 00:00:00 (K, the day acted on), 7 Jan = 2027-01-08 00:00:00.
    $ny = ['rrule' => 'FREQ=DAILY;COUNT=10', 'start' => '2027-01-04T19:00:00-05:00', 'end' => '2027-01-04T20:00:00-05:00', 'allDay' => false, 'tzid' => 'America/New_York'];
    $first = '2027-01-04T19:00:00-05:00';
    $sixth = '2027-01-06T19:00:00-05:00';
    $K = '2027-01-07 00:00:00';
    $Kid = '20270107T000000Z';

    // H1. The zone moved to Tokyo at the same moments: every occurrence of January keeps its instant, so K is still the
    // day (Thu 7 Jan 09:00 Tokyo) and its Google id is unchanged. The neighbour 2027-01-06 00:00:00 is another day.
    $toTokyo = [
        'here' => static fn(stdClass $w) => ($w->series)('all', $first, ['tzid' => 'Asia/Tokyo', 'start' => '2027-01-04T19:00:00+09:00', 'end' => '2027-01-04T20:00:00+09:00'], ['zone' => 'moment']),
        'at Google' => static fn(stdClass $w) => ($w->remote)(['start' => ['dateTime' => '2027-01-05T09:00:00+09:00', 'timeZone' => 'Asia/Tokyo'], 'end' => ['dateTime' => '2027-01-05T10:00:00+09:00', 'timeZone' => 'Asia/Tokyo']]),
    ];
    foreach ($toTokyo as $where => $move) {
        $setup = static function (stdClass $w, string $name) use ($move): void {
            $move($w);
            $m = $w->db->one('SELECT tzid, start_utc FROM events WHERE id = ?', [$w->id]);
            checkEq("$name: (setup) the series is in Tokyo and its first occurrence is still the instant it was", ['Asia/Tokyo', '2027-01-05 00:00:00'], [(string) $m['tzid'], (string) $m['start_utc']]);
        };
        $google("zone moved at the same moments $where: notes given to 6 Jan, then undone (its row is still there)", $ny, function (stdClass $w, string $name) use ($sixth, $K, $Kid, $setup): void {
            ($w->this1)($sixth, ['description' => 'Notes of the sixth']);
            $entry = ($w->last)();
            $setup($w, $name);
            checkEq("$name: (setup) the row is still at K with its notes", [$K => 'Notes of the sixth'], array_map(static fn(array $r): string => $r[0], ($w->rows)()));
            checkEq("$name: it goes through, by one write to K's id", ['done', [['PATCH', $Kid]]], [($w->undo)($entry), ($w->sent)()]);
            checkEq("$name: the notes are gone from K here and at Google, and no other day holds anything", [[], []], [($w->rows)(), ($w->atGoogle)()['records']]);
        });
        $google("zone moved at the same moments $where: a tag, then notes given to 6 Jan; the notes undone (its row is still there)", $ny, function (stdClass $w, string $name) use ($sixth, $K, $Kid, $setup): void {
            ($w->this1)($sixth, ['tagNames' => ['sample-day']]);
            ($w->this1)($sixth, ['description' => 'Notes of the sixth']);
            $entry = ($w->last)();
            $setup($w, $name);
            checkEq("$name: it goes through, by one write to K's id", ['done', [['PATCH', $Kid]]], [($w->undo)($entry), ($w->sent)()]);
            checkEq("$name: the notes are gone from K, its tag stays, and Google holds none", [[$K => ['Sample notes', ['sample-day']]], []], [($w->rows)(), ($w->atGoogle)()['records']]);
        });
        $google("zone moved at the same moments $where: notes given to 6 Jan and undone, then redone (no row, no skip)", $ny, function (stdClass $w, string $name) use ($sixth, $setup, $unplaced, $zone): void {
            ($w->this1)($sixth, ['description' => 'Notes of the sixth']);
            checkEq("$name: (setup) the undo goes through and leaves no row", ['done', []], [($w->undo)(($w->last)()), ($w->rows)()]);
            $redo = ($w->last)();
            $setup($w, $name);
            ($w->refused)($name, $redo, $unplaced($zone, true));
        });
        $google("zone moved at the same moments $where: a tag given to 6 Jan and undone, then redone (no row, no skip)", $ny, function (stdClass $w, string $name) use ($sixth, $setup, $unplaced, $zone): void {
            ($w->this1)($sixth, ['tagNames' => ['sample-day']]);
            checkEq("$name: (setup) the undo goes through and leaves no row", ['done', []], [($w->undo)(($w->last)()), ($w->rows)()]);
            $redo = ($w->last)();
            $setup($w, $name);
            ($w->refused)($name, $redo, $unplaced($zone, true));
        });
        $google("zone moved at the same moments $where: 6 Jan deleted, then that undone (its own skip still stands at K)", $ny, function (stdClass $w, string $name) use ($sixth, $K, $Kid, $setup): void {
            ($w->deleteDay)($sixth);
            $entry = ($w->last)();
            $setup($w, $name);
            checkEq("$name: (setup) K is still skipped, here and at Google", [[$K], [$K]], [($w->skips)(), ($w->atGoogle)()['skips']]);
            checkEq("$name: it goes through, by one write to K's id", ['done', [['PATCH', $Kid]]], [($w->undo)($entry), ($w->sent)()]);
            checkEq("$name: no day is skipped here or at Google", [[], []], [($w->skips)(), ($w->atGoogle)()['skips']]);
        });
        $google("zone moved at the same moments $where: 6 Jan deleted and that undone, then the delete redone (no row, no skip)", $ny, function (stdClass $w, string $name) use ($sixth, $setup, $unplaced, $zone): void {
            ($w->deleteDay)($sixth);
            checkEq("$name: (setup) the undo goes through and nothing is skipped", ['done', []], [($w->undo)(($w->last)()), ($w->skips)()]);
            $redo = ($w->last)();
            $setup($w, $name);
            ($w->refused)($name, $redo, $unplaced($zone, true));
            checkEq("$name: no day is skipped, here or at Google", [[], []], [($w->skips)(), ($w->atGoogle)()['skips']]);
        });
    }
    // The gate comes before staleness: a redo whose day can't be placed is refused as that, whatever was changed since.
    $google('zone moved at the same moments here: notes undone, newer notes given to the same occurrence, then the first redone', $ny, function (stdClass $w, string $name) use ($sixth, $K, $toTokyo, $unplaced, $zone): void {
        ($w->this1)($sixth, ['description' => 'Notes of the sixth']);
        ($w->undo)(($w->last)());
        $redo = ($w->last)();
        $toTokyo['here']($w);
        ($w->this1)('2027-01-07T09:00:00+09:00', ['description' => 'Newer notes']);
        ($w->refused)($name, $redo, $unplaced($zone, true));
        checkEq("$name: the newer notes stand on K", [$K => 'Newer notes'], array_map(static fn(array $r): string => $r[0], ($w->rows)()));
    });

    // H2. A change made here that moves the series' days. The planner carries a day that has a row (its move is
    // recorded) and the row is the entry's own; a day with nothing has no record, and is refused, never placed by date.
    $moves = [
        // name => [the series, the day acted on, the change for all events, where the planner carries the day's row: its key and id suffix]
        'daily dragged a day later' => [$ny, $sixth, ['start' => '2027-01-05T19:00:00-05:00', 'end' => '2027-01-05T20:00:00-05:00'], '2027-01-08 00:00:00', '20270108T000000Z'],
        // Weekly on Tuesday from Tue 5 Jan, moved to Wednesday: Tue 12 Jan (2027-01-13 00:00:00) becomes Wed 13 Jan (2027-01-14 00:00:00).
        'weekly Tuesday moved to Wednesday' => [['rrule' => 'FREQ=WEEKLY;COUNT=6', 'start' => '2027-01-05T19:00:00-05:00', 'end' => '2027-01-05T20:00:00-05:00', 'allDay' => false, 'tzid' => 'America/New_York'],
            '2027-01-12T19:00:00-05:00', ['start' => '2027-01-06T19:00:00-05:00', 'end' => '2027-01-06T20:00:00-05:00'], '2027-01-14 00:00:00', '20270114T000000Z'],
        // Daily at 23:00, moved three hours later: the night of 6 Jan (2027-01-07 04:00:00) is 02:00 on 7 Jan (2027-01-07 07:00:00).
        'daily 23:00 moved to 02:00 the next morning' => [['rrule' => 'FREQ=DAILY;COUNT=10', 'start' => '2027-01-04T23:00:00-05:00', 'end' => '2027-01-04T23:30:00-05:00', 'allDay' => false, 'tzid' => 'America/New_York'],
            '2027-01-06T23:00:00-05:00', ['start' => '2027-01-05T02:00:00-05:00', 'end' => '2027-01-05T02:30:00-05:00'], '2027-01-07 07:00:00', '20270107T070000Z'],
    ];
    foreach ($moves as $what => [$create, $day, $change, $keyAfter, $idAfter]) {
        $start = (string) $create['start'];
        $google("$what here, the day holding a tag (a row) when the series moves: notes given and undone, then redone", $create, function (stdClass $w, string $name) use ($day, $start, $change, $keyAfter, $idAfter): void {
            ($w->this1)($day, ['tagNames' => ['sample-day']]);
            ($w->this1)($day, ['description' => 'Notes of the day']);
            ($w->undo)(($w->last)());
            $redo = ($w->last)();
            ($w->series)('all', $start, $change);
            checkEq("$name: (setup) the planner carried the tagged day", [$keyAfter], array_keys(($w->rows)()));
            checkEq("$name: it goes through, by one write to the id of the day the planner carried it to", ['done', [['PATCH', $idAfter]]], [($w->undo)($redo), ($w->sent)()]);
            checkEq("$name: the notes are on that day, beside its tag, and on no other", [$keyAfter => ['Notes of the day', ['sample-day']]], ($w->rows)());
        });
        $google("$what here, the day holding nothing when the series moves: notes given and undone, then redone", $create, function (stdClass $w, string $name) use ($day, $start, $change, $unplaced, $moved): void {
            ($w->this1)($day, ['description' => 'Notes of the day']);
            ($w->undo)(($w->last)());
            $redo = ($w->last)();
            ($w->series)('all', $start, $change);
            ($w->refused)($name, $redo, $unplaced($moved, true));
        });
        $google("$what here: the day deleted and that undone, then the delete redone", $create, function (stdClass $w, string $name) use ($day, $start, $change, $unplaced, $moved): void {
            ($w->deleteDay)($day);
            ($w->undo)(($w->last)());
            $redo = ($w->last)();
            ($w->series)('all', $start, $change);
            ($w->refused)($name, $redo, $unplaced($moved, true));
            checkEq("$name: no day is skipped", [], ($w->skips)());
        });
        $google("$what here: the day deleted, the series moved, then the delete undone (the skip's move is recorded)", $create, function (stdClass $w, string $name) use ($day, $start, $change, $keyAfter, $idAfter): void {
            ($w->deleteDay)($day);
            $entry = ($w->last)();
            ($w->series)('all', $start, $change);
            checkEq("$name: (setup) the planner carried the skip", [$keyAfter], ($w->skips)());
            checkEq("$name: it goes through, by one write to the id of the day the skip was carried to", ['done', [['PATCH', $idAfter]]], [($w->undo)($entry), ($w->sent)()]);
            checkEq("$name: no day is skipped here or at Google", [[], []], [($w->skips)(), ($w->atGoogle)()['skips']]);
        });
    }

    // H6. A day the series no longer has: refused with words that are true of a rule change, a bound and a split, by a
    // code force does not pass. A split after the day leaves it where it was.
    $google('daily made weekly on Monday here: notes given to Wed 6 Jan and undone, then redone', $ny, function (stdClass $w, string $name) use ($sixth, $first, $gone): void {
        ($w->this1)($sixth, ['description' => 'Notes of the sixth']);
        ($w->undo)(($w->last)());
        $redo = ($w->last)();
        ($w->series)('all', $first, ['rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=10'], ['reading' => 'keep']);
        ($w->refused)($name, $redo, $gone);
    });
    $google('daily split from 7 Jan here (this and following renamed): notes given to 8 Jan and undone, then redone', $ny, function (stdClass $w, string $name) use ($gone): void {
        ($w->this1)('2027-01-08T19:00:00-05:00', ['description' => 'Notes of the eighth']);
        ($w->undo)(($w->last)());
        $redo = ($w->last)();
        ($w->series)('following', '2027-01-07T19:00:00-05:00', ['title' => 'Sample practice, later']);
        ($w->refused)($name, $redo, $gone);
    });
    $google('daily split from 8 Jan here: notes given to 6 Jan and undone, then redone (the day stays in the first series)', $ny, function (stdClass $w, string $name) use ($sixth, $K, $Kid): void {
        ($w->this1)($sixth, ['description' => 'Notes of the sixth']);
        ($w->undo)(($w->last)());
        $redo = ($w->last)();
        ($w->series)('following', '2027-01-08T19:00:00-05:00', ['title' => 'Sample practice, later']);
        checkEq("$name: it goes through, on 6 Jan", ['done', [$K], [['PATCH', $Kid]]], [($w->undo)($redo), array_keys(($w->rows)()), ($w->sent)()]);
    });
    $google('the count cut from ten to eight here: notes given to 6 Jan and undone, then redone (a bound is no re-placing)', $ny, function (stdClass $w, string $name) use ($sixth, $first, $K, $Kid): void {
        ($w->this1)($sixth, ['description' => 'Notes of the sixth']);
        ($w->undo)(($w->last)());
        $redo = ($w->last)();
        ($w->series)('all', $first, ['rrule' => 'FREQ=DAILY;COUNT=8']);
        checkEq("$name: it goes through, on 6 Jan", ['done', [$K], [['PATCH', $Kid]]], [($w->undo)($redo), array_keys(($w->rows)()), ($w->sent)()]);
    });
    $google('the length made two hours here: notes given to 6 Jan and undone, then redone (a length is no re-placing)', $ny, function (stdClass $w, string $name) use ($sixth, $first, $K, $Kid): void {
        ($w->this1)($sixth, ['description' => 'Notes of the sixth']);
        ($w->undo)(($w->last)());
        $redo = ($w->last)();
        ($w->series)('all', $first, ['start' => '2027-01-04T19:00:00-05:00', 'end' => '2027-01-04T21:00:00-05:00']);
        checkEq("$name: it goes through, on 6 Jan", ['done', [$K], [['PATCH', $Kid]]], [($w->undo)($redo), array_keys(($w->rows)()), ($w->sent)()]);
    });

    // A reading of a monthly change that carries days while every column of the series stays as it was: on the 1st and
    // 15th from Fri 1 Jan 2027, "move every 15th a month later". The 15 Feb practice becomes 15 Mar and the key of
    // 15 Feb (2027-02-16 00:00:00) is still an occurrence: the old 15 Jan's. The planner records the days it brought
    // into being by date, which is its word that it did not keep every day on its date.
    $google('monthly on the 1st and 15th, every 15th moved a month later here: notes given to 15 Feb and undone, then redone', ['rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1,15', 'start' => '2027-01-01T19:00:00-05:00', 'end' => '2027-01-01T20:00:00-05:00', 'allDay' => false, 'tzid' => 'America/New_York'],
        function (stdClass $w, string $name) use ($unplaced): void {
            ($w->this1)('2027-02-15T19:00:00-05:00', ['description' => 'Notes of the day']);
            ($w->undo)(($w->last)());
            $redo = ($w->last)();
            ($w->series)('all', '2027-01-15T19:00:00-05:00', ['start' => '2027-02-15T19:00:00-05:00', 'end' => '2027-02-15T20:00:00-05:00'], ['reading' => 'member']);
            $m = $w->db->one('SELECT start_utc, rrule FROM events WHERE id = ?', [$w->id]);
            checkEq("$name: (setup) the series' start and rule are as they were", ['2027-01-02 00:00:00', 'FREQ=MONTHLY;BYMONTHDAY=1,15'], [(string) $m['start_utc'], (string) $m['rrule']]);
            ($w->refused)($name, $redo, $unplaced('had its days moved', true));
        });

    // H7. A day's own time, redone after the series was made all-day: the forward edit's refusal, before anything is sent.
    // The day keeps a row (a tag), so the gate lets it through; in the all-day series 6 Jan is 2027-01-06 00:00:00.
    $google('6 Jan tagged, given 21:00 and that undone, the series made all-day here, then the time redone', $ny, function (stdClass $w, string $name) use ($sixth, $first): void {
        ($w->this1)($sixth, ['tagNames' => ['sample-day']]);
        ($w->this1)($sixth, ['start' => '2027-01-06T21:00:00-05:00', 'end' => '2027-01-06T22:00:00-05:00']);
        ($w->undo)(($w->last)());
        $redo = ($w->last)();
        ($w->series)('all', $first, ['allDay' => true, 'start' => '2027-01-04', 'end' => '2027-01-05', 'tzid' => 'UTC']);
        checkEq("$name: (setup) the tagged day is 6 Jan of the all-day series", ['2027-01-06 00:00:00'], array_keys(($w->rows)()));
        $was = ($w->all)();
        checkEq("$name: refused as the edit itself is, in its words", 'google_all_day: At Google, a day of an all-day series can\'t be given a clock time. Make the series timed, or keep this day all-day.', ($w->undo)($redo));
        checkEq("$name: nothing was sent and nothing written", [[], true], [($w->sent)(), $was === ($w->all)()]);
    });
    foreach (['a time and zone of its own (21:00 New York held in Berlin)' => ['start' => '2027-01-07T03:00:00+01:00', 'end' => '2027-01-07T04:00:00+01:00', 'tzid' => 'Europe/Berlin'],
        'moved to the 8th at the series clock' => ['start' => '2027-01-08T19:00:00-05:00', 'end' => '2027-01-08T20:00:00-05:00']] as $shape => $edit) {
        foreach (['here', 'at Google'] as $where) {
            $google("6 Jan given $shape and that undone, the series made all-day $where, then redone (no row, no skip)", $ny, function (stdClass $w, string $name) use ($sixth, $first, $edit, $where, $unplaced, $form): void {
                ($w->this1)($sixth, $edit);
                ($w->undo)(($w->last)());
                $redo = ($w->last)();
                $where === 'here' ? ($w->series)('all', $first, ['allDay' => true, 'start' => '2027-01-04', 'end' => '2027-01-05', 'tzid' => 'UTC'])
                    : ($w->remote)(['start' => ['date' => '2027-01-04'], 'end' => ['date' => '2027-01-05']]);
                ($w->refused)($name, $redo, $unplaced($form, true));
            });
        }
    }

    // ---- A calendar of the account's own ----
    $timed = ['title' => 'Sample practice'] + $ny;
    // Every live day row of the account: [calendar, key, title, all-day, tags].
    $dayRows = static function (int $u) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT * FROM events WHERE user_id = ? AND recurrence_instance_utc IS NOT NULL AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$u]) as $r) {
            $out[] = [(int) $r['calendar_id'], (string) $r['recurrence_instance_utc'], (string) $r['title'], (int) $r['all_day'],
                array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [(int) $r['id']]), 'name')];
        }
        return $out;
    };
    $skipsOf = static fn(int $id): array => json_decode((string) ($db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$id]) ?? '[]'), true) ?: [];
    $everything = static fn(int $u): array => [
        $db->all('SELECT * FROM events WHERE user_id = ? ORDER BY id', [$u]),
        $db->all('SELECT et.* FROM event_tags et JOIN events e ON e.id = et.event_id WHERE e.user_id = ? ORDER BY et.event_id, et.tag_id', [$u]),
        $db->all('SELECT id, undone FROM mutations WHERE user_id = ? ORDER BY id', [$u]),
    ];
    $own = static function (string $name, callable $fn) use ($db, $timed, $undoOf, $everything): void {
        scenario('undo day gate, own calendar: ' . $name, function () use ($db, $timed, $fn, $name, $undoOf, $everything): void {
            $u = freshUser($db);
            $d = domains($db);
            $home = (int) Settings::defaultCalendarId($db, $u);
            $id = (int) $d->events->create($u, ['calendarId' => $home] + $timed)['eventId'];
            // A refusal, unforced and forced: the same words, and every table as it was.
            $refused = static function (int $entry, string $words) use ($d, $u, $name, $undoOf, $everything): void {
                $was = $everything($u);
                checkEq("$name: refused, in words that say what changed (never as stale, which force would pass)", $words, $undoOf($d->undo, $u, $entry));
                checkEq("$name: forced, the same refusal", $words, $undoOf($d->undo, $u, $entry, true));
                check("$name: nothing was written", $was === $everything($u));
            };
            $fn($u, $d, $home, $id, $refused, $name);
        });
    };
    $all = static fn($d, int $u, int $id, array $in, array $choices = []) => planned($d->events, $u, $id, ['scope' => 'all', 'instanceStart' => '2027-01-04T19:00:00-05:00'] + $in, $choices);
    $tagBack = static function ($d, int $u, int $id) use ($db, $sixth): int {
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => $sixth, 'tagNames' => ['sample-day']]);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => $sixth, 'useSeries' => ['tags']]);
        return lastEntry($db, $u);
    };
    $renamedThenDeleted = static function ($d, int $u, int $id) use ($db, $sixth): int {
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => $sixth, 'title' => 'Sample practice, special']);
        $d->events->deleteEvent($u, $id, 'this', $sixth);
        return lastEntry($db, $u);
    };
    $toAllDay = ['allDay' => true, 'start' => '2027-01-04', 'end' => '2027-01-05', 'tzid' => 'UTC'];

    // H3. The series moved to another calendar of the account's: a move of calendar re-places no day, and the row the
    // undo brings back stands in the calendar its series is in now.
    foreach (['a tag given to 6 Jan and given back' => $tagBack, '6 Jan renamed, then deleted' => $renamedThenDeleted] as $what => $make) {
        $own("$what, the series moved to another calendar, then that undone", function (int $u, $d, int $home, int $id, callable $refused, string $name) use ($db, $make, $all, $dayRows, $undoOf, $K, $skipsOf): void {
            $other = (int) $d->calendars->create($u, ['name' => 'Sample other'])['id'];
            $entry = $make($d, $u, $id);
            $all($d, $u, $id, ['calendarId' => $other]);
            checkEq("$name: (setup) the series is in the other calendar", $other, (int) $db->scalar('SELECT calendar_id FROM events WHERE id = ?', [$id]));
            checkEq("$name: it goes through", 'done', $undoOf($d->undo, $u, $entry));
            checkEq("$name: the day's row is back on 6 Jan, in the calendar its series is in, and no day is skipped", [[[$other, $K]], []], [array_map(static fn(array $r): array => [$r[0], $r[1]], $dayRows($u)), $skipsOf($id)]);
            $shown = [];
            foreach ($d->events->window($u, new DateTimeImmutable('2027-01-06T12:00:00-05:00'), new DateTimeImmutable('2027-01-07T12:00:00-05:00'), null, null, true) as $occ) {
                $shown[] = (int) $occ['calendarId'];
            }
            checkEq("$name: the evening of 6 Jan shows one event, in the series' calendar", [$other], $shown);
        });
    }

    // H4. A change of the rule or the bound after which the day is no day of the series: the row is not brought back to a key the series lacks.
    foreach (['made weekly on Monday' => [['rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=10'], ['reading' => 'keep']], 'cut to two events' => [['rrule' => 'FREQ=DAILY;COUNT=2'], []]] as $what => [$change, $choices]) {
        foreach (['a tag given to 6 Jan and given back' => $tagBack, '6 Jan renamed, then deleted' => $renamedThenDeleted] as $did => $make) {
            $own("$did, the series $what, then that undone", function (int $u, $d, int $home, int $id, callable $refused, string $name) use ($make, $all, $change, $choices, $gone): void {
                $entry = $make($d, $u, $id);
                $all($d, $u, $id, $change, $choices);
                $refused($entry, $gone);
            });
        }
    }

    // H5. A snapshot is of the series as it was: once the series' time, zone or form changed, a row or a skip it holds
    // comes back nowhere, forced or not. In the all-day series 6 Jan is 2027-01-06 00:00:00; at 20:00 it is 2027-01-07 01:00:00.
    $after = [
        'the clock moved to 20:00' => [['start' => '2027-01-04T20:00:00-05:00', 'end' => '2027-01-04T21:00:00-05:00'], [], $moved],
        'moved to Tokyo at the same moments' => [['tzid' => 'Asia/Tokyo', 'start' => '2027-01-04T19:00:00+09:00', 'end' => '2027-01-04T20:00:00+09:00'], ['zone' => 'moment'], $zone],
        'moved to 19:00 Tokyo' => [['tzid' => 'Asia/Tokyo', 'start' => '2027-01-04T19:00:00+09:00', 'end' => '2027-01-04T20:00:00+09:00'], ['zone' => 'clock'], $zone],
        'made all-day' => [$toAllDay, [], $form],
    ];
    foreach ($after as $what => [$change, $choices, $said]) {
        foreach (['a tag given to 6 Jan and given back' => $tagBack, '6 Jan renamed, then deleted' => $renamedThenDeleted] as $did => $make) {
            $own("$did, the series $what, then that undone", function (int $u, $d, int $home, int $id, callable $refused, string $name) use ($make, $all, $change, $choices, $said, $outdated): void {
                $entry = $make($d, $u, $id);
                $all($d, $u, $id, $change, $choices);
                $refused($entry, $outdated($said));
            });
        }
    }
    // A day whose row is still there is its row: a tag given to 6 Jan, the clock moved (the row is carried to 20:00), the tag undone by force.
    $own('a tag given to 6 Jan, the clock moved to 20:00, then the tag undone', function (int $u, $d, int $home, int $id, callable $refused, string $name) use ($db, $sixth, $all, $dayRows, $undoOf): void {
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => $sixth, 'tagNames' => ['sample-day']]);
        $entry = lastEntry($db, $u);
        $all($d, $u, $id, ['start' => '2027-01-04T20:00:00-05:00', 'end' => '2027-01-04T21:00:00-05:00']);
        checkEq("$name: (setup) the row was carried to 20:00 on 6 Jan", [['2027-01-07 01:00:00', ['sample-day']]], array_map(static fn(array $r): array => [$r[1], $r[4]], $dayRows($u)));
        checkEq("$name: unforced it is stale, the series' time having changed since; forced it goes through", ['stale_undo: Newer changes exist for this item; undoing would overwrite them', 'done'],
            [$undoOf($d->undo, $u, $entry), $undoOf($d->undo, $u, $entry, true)]);
        checkEq("$name: no row holds the tag, and none sits at another day", [], array_values(array_filter($dayRows($u), static fn(array $r): bool => $r[4] !== [] || $r[1] !== '2027-01-07 01:00:00')));
    });
    // The undo of the change of time itself, over days changed after it: they have no recorded place in the series as it was.
    foreach (['a tag given to 6 Jan' => 'tag', '6 Jan deleted' => 'delete'] as $what => $kind) {
        $own("made all-day, then $what, then the change of form undone", function (int $u, $d, int $home, int $id, callable $refused, string $name) use ($db, $all, $toAllDay, $kind, $daysSince): void {
            $all($d, $u, $id, $toAllDay);
            $form = lastEntry($db, $u);
            $kind === 'tag' ? $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-06', 'tagNames' => ['sample-day']]) : $d->events->deleteEvent($u, $id, 'this', '2027-01-06');
            $refused($form, $daysSince);
        });
    }
    $own('made all-day, then renamed for all events, then the change of form undone (no day changed since)', function (int $u, $d, int $home, int $id, callable $refused, string $name) use ($db, $all, $toAllDay, $undoOf): void {
        $all($d, $u, $id, $toAllDay);
        $form = lastEntry($db, $u);
        planned($d->events, $u, $id, ['scope' => 'all', 'instanceStart' => '2027-01-04', 'title' => 'Sample practice, renamed']);
        checkEq("$name: it goes through", 'done', $undoOf($d->undo, $u, $form));
        $m = $db->one('SELECT all_day, start_utc, title FROM events WHERE id = ?', [$id]);
        checkEq("$name: the series is at 19:00 New York again, with its new name", [0, '2027-01-05 00:00:00', 'Sample practice, renamed'], [(int) $m['all_day'], (string) $m['start_utc'], (string) $m['title']]);
    });

    // ---- An ICS feed's calendar: a day's row there stays through a tag taken off, so its undo is by the row ----
    $feedText = static fn(string $start, string $rule): string => implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Feed//EN', 'BEGIN:VEVENT', 'UID:sample-gate-feed@example.test', 'DTSTAMP:20270101T000000Z',
        $start, 'DURATION:PT1H', 'RRULE:' . $rule, 'SUMMARY:Sample game', 'END:VEVENT', 'END:VCALENDAR', '']);
    $ny19 = 'DTSTART;TZID=America/New_York:20270104T190000';
    foreach ([
        // name => [the feed after, where 6 Jan's row is after (null: the series has no such day and the poll removed the row)]
        'the feed moves the clock to 20:00' => ['DTSTART;TZID=America/New_York:20270104T200000', 'FREQ=DAILY;COUNT=10', '2027-01-07 01:00:00'],
        'the feed changes nothing' => [$ny19, 'FREQ=DAILY;COUNT=10', '2027-01-07 00:00:00'],
    ] as $what => [$startAfter, $ruleAfter, $keyAfter]) {
        scenario("undo day gate, an ICS feed: a tag given to 6 Jan and taken off, $what, then the taking off undone", function () use ($db, $feedText, $ny19, $startAfter, $ruleAfter, $keyAfter, $what, $undoOf, $dayRows): void {
            $name = "an ICS feed, $what";
            $u = freshUser($db);
            $d = domains($db);
            $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample league', 'kind' => 'subscribed', 'color' => '#336699']);
            $feeds = new Feeds($db);
            $sync = static fn(string $body) => $feeds->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]), Ics::parse($body, 'America/New_York'));
            $sync($feedText($ny19, 'FREQ=DAILY;COUNT=10'));
            $id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL', [$calendarId]);
            $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-06T19:00:00-05:00', 'tagNames' => ['sample-day']]);
            $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-06T19:00:00-05:00', 'tagNames' => []]);
            $entry = lastEntry($db, $u);
            $sync($feedText($startAfter, $ruleAfter));
            checkEq("$name: it goes through, and the tag is back on the row of 6 Jan where the feed has it", ['done', [[$keyAfter, ['sample-day']]]],
                [$undoOf($d->undo, $u, $entry), array_map(static fn(array $r): array => [$r[1], $r[4]], $dayRows($u))]);
        });
    }
};
