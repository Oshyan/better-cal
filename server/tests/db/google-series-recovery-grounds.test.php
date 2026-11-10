<?php

declare(strict_types=1);

// What the entry of a change for all events records of the series' days,
// whether the change completed in one go or was completed from Review
// (series-model.md, Own Fields, "Which day an undo acts on"; decisions.md,
// "The days a change brought into being are its ground", "Finish's ground is
// what the change it completes changed"; interleaving-invariants.md,
// x-undo-fields).
//
// The rule: a series change's entry records, as its grounds, what it did
// with each day: a move for every row and every skip whose key the change
// moved ("move:<from>><to>", none for a key that stays), and the days it
// added. One function writes them from the change's plan, for the entry the
// commit leaves and for the entry Finish leaves when it completes the same
// change, wherever the change stopped (before its master write landed, or
// after), so a day's older undo is judged alike after either.
//
// Each scenario gives one day a tag (a row here) and deletes another (a
// skip), then makes a change for all events that stops part way and is
// finished or put back from Review, then undoes the delete and the tag by
// their entries. Beside it the control: the same change completed without a
// stop. Expected values are written from the rule: the moves are worked out
// here from the dates and the clocks (never read from the product), and an
// undo lands on its own day, named by its date, with no other day changed.
// Google is the edit harness's stand-in. Invented data.

use BetterCal\Domain\Undo;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $utc = new DateTimeZone('UTC');
    /** The key of a day of a series: the UTC instant of its clock on that date in its zone, or the date at 00:00 UTC when all-day. */
    $keyOf = static function (string $date, ?string $clock, string $zone) use ($utc): string {
        return $clock === null ? $date . ' 00:00:00' : (new DateTimeImmutable("$date $clock", new DateTimeZone($zone)))->setTimezone($utc)->format('Y-m-d H:i:s');
    };
    $iso = static fn(string $date, string $clock, string $zone): string => (new DateTimeImmutable("$date $clock", new DateTimeZone($zone)))->format('Y-m-d\TH:i:sP');

    // The series a scenario starts from: daily, eight of them from 19 July 2027.
    $la = ['clock' => '17:00', 'zone' => 'America/Los_Angeles'];   // 00:00 UTC the next day
    $midnight = ['clock' => '00:00', 'zone' => 'UTC'];            // its keys are those of the same dates all-day
    // The changes for all events: the request, and the series' clock and zone after it (null clock: all-day).
    $changes = [
        'an hour later' => [static fn(array $s): array => ['start' => $iso('2027-07-19', '18:00', $s['zone']), 'end' => $iso('2027-07-19', '19:00', $s['zone'])], ['clock' => '18:00'], []],
        'made all-day' => [static fn(array $s): array => ['start' => '2027-07-19', 'end' => '2027-07-20', 'allDay' => true], ['clock' => null], []],
        'moved to Denver at the same clock time' => [static fn(array $s): array => ['tzid' => 'America/Denver', 'start' => $iso('2027-07-19', $s['clock'], 'America/Denver'), 'end' => $iso('2027-07-19', '18:00', 'America/Denver')],
            ['zone' => 'America/Denver'], ['zone' => 'clock']],
        'moved to Denver at the same moment' => [static fn(array $s): array => ['tzid' => 'America/Denver', 'start' => $iso('2027-07-19', $s['clock'], 'America/Denver'), 'end' => $iso('2027-07-19', '18:00', 'America/Denver')],
            ['clock' => '18:00', 'zone' => 'America/Denver'], ['zone' => 'moment']],
        'ten events' => [static fn(array $s): array => ['rrule' => 'FREQ=DAILY;COUNT=10'], [], []],
        'three events' => [static fn(array $s): array => ['rrule' => 'FREQ=DAILY;COUNT=3'], [], []],
        'renamed from 23 July on' => [static fn(array $s): array => ['scope' => 'following', 'instanceStart' => $iso('2027-07-23', $s['clock'], $s['zone']), 'title' => 'Sample later part'], [], []],
    ];
    $tagDay = '2027-07-22';
    $skipDay = '2027-07-24';

    /** The dates the series skips, and each day's row as date => [notes, tags], read in the series' own frame (its zone, or its dates when all-day). */
    $state = static function (int $id) use ($db, $utc): array {
        $m = $db->one('SELECT * FROM events WHERE id = ?', [$id]);
        $date = static function (string $key) use ($m, $utc): string {
            $k = new DateTimeImmutable($key, $utc);
            return (int) $m['all_day'] === 1 ? $k->format('Y-m-d') : $k->setTimezone(new DateTimeZone((string) $m['tzid']))->format('Y-m-d');
        };
        $skips = array_map($date, json_decode((string) ($m['exdates_json'] ?? '[]'), true) ?: []);
        sort($skips);
        $days = [];
        foreach ($db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$id]) as $r) {
            $tags = array_map('strval', array_column($db->all('SELECT t.name FROM event_tags e JOIN tags t ON t.id = e.tag_id WHERE e.event_id = ? ORDER BY t.name', [(int) $r['id']]), 'name'));
            $days[$date((string) $r['recurrence_instance_utc'])] = [$r['description'], $tags];
        }
        return ['allDay' => (int) $m['all_day'], 'zone' => (string) $m['tzid'], 'skips' => $skips, 'days' => $days];
    };
    /** The dates Google holds cancelled for the series, read from each cancelled record's own original start. */
    $cancelled = static function (GoogleStand $s, string $gid): array {
        $out = [];
        foreach ($s->fake->records($gid) as $r) {
            if (($r['status'] ?? '') === 'cancelled') {
                $o = $r['originalStartTime'] ?? [];
                $out[] = isset($o['date']) ? (string) $o['date'] : (new DateTimeImmutable((string) $o['dateTime']))->setTimezone(new DateTimeZone((string) ($o['timeZone'] ?? 'UTC')))->format('Y-m-d');
            }
        }
        sort($out);
        return $out;
    };
    /** An entry's grounds as it recorded them, the series' own row named `series` and any other row by the order it appears in. */
    $grounds = static function (int $entry, int $seriesId) use ($db): array {
        $other = [];
        $out = [];
        foreach ($db->all('SELECT row_key, col FROM mutation_touches WHERE mutation_id = ? ORDER BY row_key, col', [$entry]) as $t) {
            $row = (string) $t['row_key'];
            if ($row === "events:$seriesId") {
                $row = 'series';
            } elseif (str_starts_with($row, 'google_pending:')) {
                $row = 'the half-done change';
            } elseif (preg_match('/^([a-z_]+):/', $row, $m) === 1) {
                $other[$row] ??= $m[1] . '#' . (count($other) + 1);
                $row = $other[$row];
            }
            $out[] = $row . ' ' . $t['col'];
        }
        sort($out);
        return $out;
    };
    /** An undo by its entry: null when it went through, else its refusal; with the ids of the writes it sent to Google. */
    $undoOf = static function (GoogleStand $s, Undo $undo, int $u, int $entry): array {
        $s->fake->requests = [];
        $refused = null;
        try {
            $undo->undoById($u, $entry);
        } catch (HttpError $e) {
            $refused = [$e->errorCode, $e->getMessage()];
        }
        $sent = [];
        foreach ($s->fake->requests as $q) {
            if ($q['method'] !== 'GET') {
                $sent[] = rawurldecode(basename((string) $q['path']));
            }
        }
        return [$refused, $sent];
    };

    /**
     * The series $c['series'] with a tag on 22 July (unless $c['plain']: no row) and 24 July deleted, $c['before']
     * first, then $c['change'] for all events, stopped as $c['stop'] says (null: completed in one go; 'before its
     * master write lands': the network is down as the first write is sent, so Google takes nothing; 'after its master
     * write, the answer lost'; 'at its third write, the answer lost') and then finished or put back from Review; then
     * the delete undone, then the tag. Returns what the scenario's checks read.
     */
    $run = static function (array $c) use ($db, $changes, $iso, $keyOf, $tagDay, $skipDay, $state, $cancelled, $grounds, $undoOf): array {
        $u = freshUser($db);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        try {
            $e = $s->events;
            $series = $c['series'];
            $first = $iso('2027-07-19', $series['clock'], $series['zone']);
            $id = (int) $e->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample series', 'start' => $first,
                'end' => (new DateTimeImmutable($first))->modify('+1 hour')->format('Y-m-d\TH:i:sP'), 'tzid' => $series['zone'], 'rrule' => 'FREQ=DAILY;COUNT=8', 'tagNames' => ['sample']])['eventId'];
            $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
            $at = static fn(string $date): string => $iso($date, $series['clock'], $series['zone']);
            foreach ($c['before'] ?? [] as [$date, $in]) {
                $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $at($date)] + $in);
                $e->throwDeferred();
            }
            $tagEntry = null;
            if (empty($c['plain'])) {
                $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $at($tagDay), 'tagNames' => ['sample', 'own tag']]);
                $e->throwDeferred();
                $tagEntry = lastEntry($db, $u);
            }
            foreach (array_merge($c['alsoDeleted'] ?? [], [$skipDay]) as $date) {
                $e->deleteEvent($u, $id, 'this', $at($date));
                $e->throwDeferred();
            }
            $deleteEntry = lastEntry($db, $u);

            [$body, $after, $choices] = $changes[$c['change']];
            $stopped = null;
            $stop = $c['stop'] ?? null;
            if ($stop === 'before its master write lands') {
                $s->knobs->netAt = $s->knobs->writes + 1;
            } elseif ($stop !== null) {
                $s->knobs->loseAt = $s->knobs->writes + ($stop === 'at its third write, the answer lost' ? 3 : 1);
            }
            $taken = $s->knobs->taken;
            try {
                planned($e, $u, $id, $body($series) + ['scope' => 'all', 'instanceStart' => $first], $choices);
                $e->throwDeferred();
            } catch (HttpError $error) {
                $stopped = $error;
            } finally {
                $s->knobs->loseAt = $s->knobs->netAt = null;
                $s->knobs->down = false;
            }
            $took = $s->knobs->taken - $taken;
            $half = $db->all('SELECT id FROM google_pending WHERE user_id = ? ORDER BY id', [$u]);
            $recovered = null;
            $named = [];
            if (isset($c['meanwhile'])) {
                ($c['meanwhile'])($s, $gid);
            }
            if ($stop !== null) {
                $out = ($c['then'] ?? 'finish') === 'finish' ? $e->googleFinish($u, (int) ($half[0]['id'] ?? 0)) : $e->googlePutBack($u, (int) ($half[0]['id'] ?? 0));
                $e->throwDeferred();
                $recovered = $out['ok'] ?? null;
                $named = array_map('strval', (array) ($out['differences'] ?? []));
            }
            $changeEntry = lastEntry($db, $u);
            $changed = $state($id);
            // The moves the rule asks for, worked out here: each of the days' rows (the day with notes, which Google holds
            // as an edited day, and the tagged day, held here alone) and the deleted day's skip, from its key under the
            // series as it was to its key under the series the change leaves, when the two differ.
            $now = $after + $series;
            $moves = [];
            foreach (array_unique(array_merge(array_column($c['before'] ?? [], 0), empty($c['plain']) ? [$tagDay] : [], $c['alsoDeleted'] ?? [], [$skipDay])) as $date) {
                [$from, $to] = [$keyOf($date, $series['clock'], $series['zone']), $keyOf($date, $now['clock'], $now['zone'])];
                if ($from !== $to && ($c['then'] ?? 'finish') === 'finish') {
                    $moves[] = "series move:$from>$to";
                }
            }
            sort($moves);
            $all = $grounds($changeEntry, $id);
            $undo = new Undo($db, null, $s->writer);
            [$deleteRefused, $deleteSent] = $undoOf($s, $undo, $u, $deleteEntry);
            $afterDelete = $state($id);
            $cancelledAfter = $cancelled($s, $gid);
            $tagRefused = $tagSent = null;
            if ($tagEntry !== null) {
                [$tagRefused, $tagSent] = $undoOf($s, $undo, $u, $tagEntry);
            }
            // The deleted day's own id at Google under the series as it stands: the series id and the day's key in its form.
            $dayKey = new DateTimeImmutable($keyOf($skipDay, $now['clock'], $now['zone']), new DateTimeZone('UTC'));
            return ['named' => $named, 'stopped' => $stopped !== null && isset($stopped->extra['pending']), 'took' => $took, 'half' => count($half), 'left' => (int) $db->scalar('SELECT COUNT(*) FROM google_pending WHERE user_id = ?', [$u]),
                'recovered' => $recovered, 'changed' => $changed, 'grounds' => $all, 'moves' => array_values(array_filter($all, static fn(string $g): bool => str_starts_with($g, 'series move:'))),
                'wantMoves' => $moves, 'deleteRefused' => $deleteRefused, 'deleteSent' => $deleteSent, 'afterDelete' => $afterDelete, 'cancelled' => $cancelledAfter,
                'dayId' => $gid . '_' . $dayKey->format($now['clock'] === null ? 'Ymd' : 'Ymd\THis\Z'), 'tagRefused' => $tagRefused, 'tagSent' => $tagSent, 'after' => $state($id)];
        } finally {
            GoogleStand::forget($db, $u);
        }
    };

    /**
     * The undo of the delete: it lands on its own day, named by its date, and on no other, by writes to that day's own
     * id alone. An undo acts on a day only where the journal says exactly where it is (series-model.md, "Which day an
     * undo acts on": Undo::dayNow, a gate): after each change here that is the recorded move of the day's skip, or the
     * skip left standing at a key that stays, and Finish's entry holds the one and shows the other as the commit's does.
     */
    $judgeDelete = static function (string $name, array $r): void {
        checkEq("$name: the undo of the delete goes through: the day is back on its own date, here and at Google, by writes to its own id alone",
            [null, [], [], []], [$r['deleteRefused'], $r['afterDelete']['skips'], $r['cancelled'], array_values(array_diff($r['deleteSent'], [$r['dayId']]))]);
    };
    /** The undo of the tag: its row is still there, so it goes through, sends Google nothing, and takes the tag off its own day and no other. */
    $judgeTag = static function (string $name, array $r): void {
        checkEq("$name: the undo of the tag goes through, sends Google nothing, and leaves no day with a tag of its own", [null, [], []],
            [$r['tagRefused'], $r['tagSent'], array_filter(array_map(static fn(array $d): array => array_values(array_diff($d[1], ['sample'])), $r['after']['days']))]);
    };

    // Every change that places the series' days again, completed in one go (the control), stopped before its master
    // write landed and finished, and stopped after it and finished. A change between timed and all-day and a zone
    // change re-form the series: every key is another instant or a date. A series at midnight UTC made all-day, and a
    // zone moved at the same moment, keep every key, and record no move, as the commit records none.
    $cases = [
        ['an hour later', $la],
        ['made all-day', $la],
        ['made all-day', $midnight],
        ['moved to Denver at the same clock time', $la],
        ['moved to Denver at the same moment', $la],
        ['ten events', $la],
    ];
    // Beside the tagged day (a row held here alone) a day with notes of its own, which Google holds as an edited day:
    // the stores of a stopped change drop and remake its row, and recovery gives it back its id, so its move shows too.
    $noted = [['2027-07-21', ['description' => 'Bring the sample checklist']]];
    foreach ($cases as [$change, $series]) {
        $what = "a daily series at {$series['clock']} {$series['zone']} $change";
        $control = null;
        scenario("$what in one go, a day tagged and a day deleted before it: the control", function () use ($run, $change, $series, $what, $noted, $judgeDelete, $judgeTag, &$control): void {
            $control = $run(['series' => $series, 'change' => $change, 'before' => $noted]);
            checkEq("$what, in one go: the day with notes, the tagged day and the deleted day are where they were, by date", [['2027-07-24'], ['2027-07-21', '2027-07-22']], [$control['changed']['skips'], array_keys($control['changed']['days'])]);
            checkEq("$what, in one go: its entry records a move for the row and for the skip whose key it moved, and none for a key that stays", $control['wantMoves'], $control['moves']);
            if ($change === 'ten events') {
                check("$what, in one go: its entry records the days it added", in_array('series added', $control['grounds'], true), json_encode($control['grounds']));
            }
            $judgeDelete("$what, in one go", $control);
            $judgeTag("$what, in one go", $control);
        });
        foreach (['before its master write lands', 'after its master write, the answer lost'] as $stop) {
            scenario("$what, stopped $stop, finished from Review, then the delete and the tag undone", function () use ($run, $change, $series, $what, $stop, $noted, $judgeDelete, $judgeTag, &$control): void {
                $r = $run(['series' => $series, 'change' => $change, 'before' => $noted, 'stop' => $stop, 'then' => 'finish']);
                $name = "$what, stopped $stop";
                checkEq("$name: the change stops half done, Google having taken " . ($stop === 'before its master write lands' ? 'nothing' : 'its master write') . ', and Finish completes it',
                    [true, $stop === 'before its master write lands' ? 0 : 1, 1, true, 0], [$r['stopped'], $r['took'], $r['half'], $r['recovered'], $r['left']]);
                checkEq("$name: finished, the day with notes, the tagged day and the deleted day are where they were, by date", [['2027-07-24'], ['2027-07-21', '2027-07-22']], [$r['changed']['skips'], array_keys($r['changed']['days'])]);
                checkEq("$name: Finish's entry records a move for the row and for the skip whose key the change moved, and none for a key that stays", $r['wantMoves'], $r['moves']);
                checkEq("$name: Finish's entry records what the change did with the days exactly as the change's own entry does", $control['grounds'] ?? null, $r['grounds']);
                $judgeDelete($name, $r);
                $judgeTag($name, $r);
            });
        }
    }

    // Seed 82447 case 139: two days deleted, the series moved an hour later, the answer of its third write lost (the
    // master write and the first deleted day's cancellation answered); finished, the second delete undone.
    scenario('two days deleted, the series moved an hour later with the answer of its third write lost, finished, the second delete undone: the day is back, the first still deleted', function () use ($run, $la): void {
        $r = $run(['series' => $la, 'plain' => true, 'alsoDeleted' => ['2027-07-21'], 'change' => 'an hour later', 'stop' => 'at its third write, the answer lost', 'then' => 'finish']);
        checkEq('third write lost: the change stops half done, Google having taken three writes, and Finish completes it', [true, 3, 1, true, 0], [$r['stopped'], $r['took'], $r['half'], $r['recovered'], $r['left']]);
        checkEq("third write lost: Finish's entry records each skip's move", [2, $r['wantMoves']], [count($r['wantMoves']), $r['moves']]);
        checkEq('third write lost: the undo of the second delete goes through and that day is back on its date, the first still deleted, here and at Google',
            [null, ['2027-07-21'], ['2027-07-21']], [$r['deleteRefused'], $r['afterDelete']['skips'], $r['cancelled']]);
    });

    // A split ("this and following") records no move by either path: the days before its cut stay where they are, and
    // those from it on leave the series for one the entry makes whole, which the rows' own snapshots show. So the
    // deleted day, now a day of the new series, is no longer part of the series its delete was made in: the undo of
    // the delete is refused, with nothing written or sent, after the split in one go and after the split finished from
    // Review alike (`not_undoable`: the day is not where the journal can say it is).
    $controlSplit = null;
    foreach ([null, 'after its master write, the answer lost'] as $stop) {
        scenario('a daily series renamed from 23 July on ' . ($stop === null ? 'in one go' : "with the answer of the old series' write lost, finished from Review") . ', a day after the cut deleted before it', function () use ($run, $la, $stop, &$controlSplit): void {
            $r = $run(['series' => $la, 'plain' => true, 'change' => 'renamed from 23 July on'] + ($stop === null ? [] : ['stop' => $stop, 'then' => 'finish']));
            $name = 'a split, ' . ($stop ?? 'in one go');
            if ($stop === null) {
                $controlSplit = $r;
            } else {
                checkEq("$name: the change stops half done and Finish completes it", [true, 1, true, 0], [$r['stopped'], $r['half'], $r['recovered'], $r['left']]);
                checkEq("$name: Finish's entry records what the split did with the days exactly as the split's own entry does", $controlSplit['grounds'] ?? null, $r['grounds']);
            }
            checkEq("$name: its entry records no move, and the deleted day's skip leaves the old series", [[], true], [$r['moves'], in_array('series skip:2027-07-25 00:00:00', $r['grounds'], true)]);
            checkEq("$name: the undo of the delete is refused, the day having left the series it was deleted in, with nothing written here and nothing sent",
                ['not_undoable', [], $r['changed']], [$r['deleteRefused'][0] ?? null, $r['deleteSent'], $r['afterDelete']]);
        });
    }

    // The series cut to three events: the tagged day, past the new end, is kept as an event of its own or goes, as the
    // plan says, and the deleted day's skip goes with its occurrence. Whatever the plan does with them, Finish's entry
    // records it as the change's own entry does, the event of its own the change made among the rows it reads.
    $controlCut = null;
    foreach ([null, 'before its master write lands', 'after its master write, the answer lost'] as $stop) {
        scenario('a daily series cut to three events ' . ($stop === null ? 'in one go' : "stopped at its first write ($stop), finished from Review") . ', a tagged day and a deleted day past the new end', function () use ($run, $la, $noted, $stop, &$controlCut): void {
            $r = $run(['series' => $la, 'before' => $noted, 'change' => 'three events'] + ($stop === null ? [] : ['stop' => $stop, 'then' => 'finish']));
            if ($stop === null) {
                $controlCut = $r;
                check('cut to three, in one go: the series ends on 21 July, the day with notes still in it', array_keys($r['changed']['days']) === ['2027-07-21'] && $r['changed']['skips'] === [], json_encode($r['changed']));
                return;
            }
            checkEq("cut to three, $stop: the change stops half done and Finish completes it", [true, 1, true, 0], [$r['stopped'], $r['half'], $r['recovered'], $r['left']]);
            checkEq("cut to three, $stop: finished, the series is as after the change in one go", $controlCut['changed'] ?? null, $r['changed']);
            checkEq("cut to three, $stop: Finish's entry records what the change did with the days exactly as the change's own entry does", $controlCut['grounds'] ?? null, $r['grounds']);
        });
    }

    // The record is what the change did, not what its plan meant to do. A time change stops before its master write
    // lands; meanwhile another client moves the series to 20:00 at Google, which drops the cancelled day there. Finish
    // keeps Google's clock and Google's day and names both. So its entry records that skip as gone and no move of it:
    // the plan's move to 18:00 never happened, and a move recorded for it would carry a later reading of that day to a
    // key the series does not have. Nothing carried the delete's skip and it is not left standing, so its undo is
    // refused with nothing written or sent; with the false move recorded it would have been followed to that key.
    scenario('a time change stopped before its master write, the clock moved at Google meanwhile, finished: the entry records the skip gone, and no move', function () use ($run, $la): void {
        $r = $run(['series' => $la, 'plain' => true, 'change' => 'an hour later', 'stop' => 'before its master write lands', 'then' => 'finish',
            'meanwhile' => static function (GoogleStand $s, string $gid): void {
                // Made as another client makes it, through the stand-in's own handler, so Google's effect on the
                // series' days comes with it (the cancelled day's record goes). Seeding the master alone would leave
                // that record at a key the series no longer has, a state Google never holds.
                $zone = (string) $s->fake->events[$gid]['start']['timeZone'];
                $answer = $s->fake->transport()('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events/' . rawurlencode($gid),
                    ['Authorization: Bearer sample-access', 'X-Harness: remote'],
                    ['start' => ['dateTime' => '2027-07-19T20:00:00-07:00', 'timeZone' => $zone], 'end' => ['dateTime' => '2027-07-19T21:00:00-07:00', 'timeZone' => $zone]]);
                checkEq('the clock moved at Google meanwhile: the stand-in took the other client\'s change', 200, $answer['status'] ?? null);
            }]);
        checkEq('the clock moved at Google meanwhile: Finish completes, the series at Google\'s clock with the day Google kept', [true, 0, []], [$r['recovered'], $r['left'], $r['changed']['skips']]);
        check('the clock moved at Google meanwhile: Finish names the time it left as Google has it and the day Google kept',
            count(array_filter($r['named'], static fn(string $w): bool => str_contains($w, 'time changed at Google'))) === 1 && count(array_filter($r['named'], static fn(string $w): bool => str_contains($w, 'Google kept'))) === 1, json_encode($r['named']));
        checkEq("the clock moved at Google meanwhile: Finish's entry records the deleted day's skip as gone and no move", [[], true],
            [$r['moves'], in_array('series skip:2027-07-25 00:00:00', $r['grounds'], true)]);
        checkEq('the clock moved at Google meanwhile: the undo of the delete is refused, its skip neither carried nor left standing, with nothing written here and nothing sent',
            ['not_undoable', [], $r['changed']], [$r['deleteRefused'][0] ?? null, $r['deleteSent'], $r['afterDelete']]);
    });

    // Put back leaves the rows as they were before the change: its entry stands on the half-done change it closed and
    // on no row, so both undos go through as if the change had never been made.
    scenario("a daily series moved an hour later with its master write's answer lost, put back from Review, then the delete and the tag undone", function () use ($run, $la, $judgeDelete, $judgeTag): void {
        $r = $run(['series' => $la, 'change' => 'an hour later', 'stop' => 'after its master write, the answer lost', 'then' => 'putBack']);
        checkEq('put back: the change stops half done and Put back returns the series', [true, 1, true, 0, 0], [$r['stopped'], $r['half'], $r['recovered'], $r['left'], $r['changed']['allDay']]);
        checkEq('put back: its entry stands on the half-done change it closed and on no row', ['the half-done change *'], $r['grounds']);
        // Put back leaves the series at its old clock: the day's id is its old key's.
        $r['dayId'] = preg_replace('/_.*$/', '_20270725T000000Z', $r['dayId']);
        $judgeDelete('put back', $r);
        $judgeTag('put back', $r);
    });

    // A day that had notes of its own and was then deleted: its entry holds the day's row, so the entry itself says
    // which day its key names (17:00 Los Angeles on 24 July is 00:00 UTC on the 25th, the key of all-day 25 July).
    // After the series is made all-day and finished from Review, the undo brings the day back with its notes on 24
    // July, the date it was deleted on, and on no other.
    foreach ([null, 'after its master write, the answer lost'] as $stop) {
        scenario('a day with notes deleted, the series made all-day ' . ($stop === null ? 'in one go' : 'with the answer of its master write lost and finished from Review') . ', the delete undone: the day is back with its notes on its own date',
            function () use ($run, $la, $stop): void {
                $r = $run(['series' => $la, 'plain' => true, 'before' => [['2027-07-24', ['description' => 'Bring the sample checklist']]], 'change' => 'made all-day'] + ($stop === null ? [] : ['stop' => $stop, 'then' => 'finish']));
                $name = 'notes, then deleted, made all-day ' . ($stop ?? 'in one go');
                checkEq("$name: the day stays deleted on its date through the change", [1, ['2027-07-24']], [$r['changed']['allDay'], $r['changed']['skips']]);
                checkEq("$name: the undo goes through and the day is back with its notes on its own date and no other", [null, [], ['2027-07-24' => 'Bring the sample checklist']],
                    [$r['deleteRefused'], $r['afterDelete']['skips'], array_map(static fn(array $d) => $d[0], $r['afterDelete']['days'])]);
            });
    }
};
