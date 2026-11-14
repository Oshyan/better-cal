<?php

declare(strict_types=1);

// A deleted day Google holds as an EXDATE line on the master (issue 252,
// decisions.md "A change to all events carries the skips Google holds as
// EXDATE lines"). Google replaces the whole `recurrence` a PATCH sends
// (probe 3.16), so a master write that sends the rule alone brings such a
// day back. The rule: a change to all events never drops a skip the change
// keeps. Expectations are written from that rule and the calendar by date:
// the deleted day stays skipped at Google and here, after the change and
// after Finish of the change stopped right after its master write. A series
// gets EXDATE lines at Google two ways from here: made with a deleted day
// (Events::create, GoogleWriter::body), and moved to Google
// (GoogleWriter::importBody, every skip a line), which the stand-in has no
// import route for, so its result is put there as the import leaves it.
// Google is the edit harness's stand-in. Invented data.

use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    // Weekly Mondays 10:00 Los Angeles from 4 January 2027 (18:00 UTC in winter), six of them.
    $create = ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
    $third = '2027-01-18 18:00:00';
    $fifth = '2027-02-01 18:00:00';
    $hasExdate = static fn(array $master): bool => array_filter((array) ($master['recurrence'] ?? []), static fn($l): bool => str_starts_with((string) $l, 'EXDATE')) !== [];
    $exdatesHere = static fn(int $id): array => Recurrence::decodeExdates($db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$id]));

    /** An account, the stand-in, and a series made on it with the third day deleted, as Events makes one. */
    $begin = static function () use ($db, $create, $third): array {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $id = (int) $s->events->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample series', 'exdates' => [$third]] + $create)['eventId'];
        $s->events->throwDeferred();
        return [$s, $u, $id, (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id])];
    };

    scenario('google exdate: a series made here with a deleted day, renamed for all events: the day stays deleted at Google and here', function () use ($db, $begin, $third, $hasExdate, $exdatesHere): void {
        [$s, $u, $id, $gid] = $begin();
        checkEq('made: Google holds the deleted day as an EXDATE line, not a cancelled record', [true, [$third]],
            [$hasExdate($s->fake->events[$gid]), $s->held($gid)['skips'] ?? null]);
        planned($s->events, $u, $id, ['scope' => 'all', 'title' => 'Sample series, renamed']);
        $s->events->throwDeferred();
        checkEq('renamed for all events: the series renamed at Google, the third day still deleted there and here, nothing half done', ['Sample series, renamed', [$third], [$third], []],
            [$s->held($gid)['title'] ?? null, $s->held($gid)['skips'] ?? null, $exdatesHere($id), $db->all('SELECT id FROM google_pending WHERE user_id = ?', [$u])]);
        $before = $db->all('SELECT title, exdates_json FROM events WHERE id = ?', [$id]);
        $s->poll();
        checkEq('a poll after changes nothing', $before, $db->all('SELECT title, exdates_json FROM events WHERE id = ?', [$id]));
        GoogleStand::forget($db, $u);
    });

    scenario('google exdate: the same rename stopped right after its master write, then Finish: the day stays deleted at Google and here', function () use ($db, $begin, $third, $exdatesHere): void {
        // How many requests the rename makes up to its master write: counted on a run that goes through.
        [$s, $u, $id] = $begin();
        $base = count($s->fake->requests);
        planned($s->events, $u, $id, ['scope' => 'all', 'title' => 'Sample series, renamed']);
        $s->events->throwDeferred();
        $upTo = null;
        foreach (array_slice($s->fake->requests, $base) as $i => $r) {
            if ($r['method'] !== 'GET') {
                $upTo = $i + 1;
                break;
            }
        }
        GoogleStand::forget($db, $u);

        [$s, $u, $id, $gid] = $begin();
        $s->fake->failAfter = count($s->fake->requests) + (int) $upTo; // the master write lands; the read-back after it finds the network down
        $stopped = null;
        try {
            planned($s->events, $u, $id, ['scope' => 'all', 'title' => 'Sample series, renamed']);
            $s->events->throwDeferred();
        } catch (\Throwable $x) {
            $stopped = $x;
        }
        $s->fake->failAfter = null;
        $pending = $db->all('SELECT id, status FROM google_pending WHERE user_id = ?', [$u]);
        checkEq('the rename stops half done after its master write; Google holds the new title and still the deleted day', [true, 1, 'half', 'Sample series, renamed', [$third]],
            [$stopped !== null, count($pending), $pending[0]['status'] ?? null, $s->held($gid)['title'] ?? null, $s->held($gid)['skips'] ?? null]);
        $s->events->googleFinish($u, (int) ($pending[0]['id'] ?? 0));
        $s->events->throwDeferred();
        checkEq('Finish completes it: renamed, the third day deleted at Google and here, nothing left half done', ['Sample series, renamed', [$third], [$third], []],
            [$s->held($gid)['title'] ?? null, $s->held($gid)['skips'] ?? null, $exdatesHere($id), $db->all('SELECT id FROM google_pending WHERE user_id = ?', [$u])]);
        GoogleStand::forget($db, $u);
    });

    scenario('google exdate: a series as Move to Google leaves it (two deleted days, each an EXDATE line), renamed for all events: both stay deleted', function () use ($db, $create, $third, $fifth, $exdatesHere): void {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        // The body GoogleMove::move imports (GoogleWriter::importBody), put at the stand-in as Google takes an import.
        $row = ['uid' => 'sample-moved-series@example.test', 'title' => 'Sample moved', 'description' => null, 'location' => null, 'status' => 'confirmed', 'url' => null,
            'start_utc' => '2027-01-04 18:00:00', 'end_utc' => '2027-01-04 19:00:00', 'all_day' => 0, 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=6',
            'exdates_json' => json_encode([$third, $fifth])];
        $s->fake->seed(['id' => 'smplmoved1'] + GoogleWriter::importBody($row));
        $s->poll();
        $id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, 'smplmoved1']);
        checkEq('moved: both deleted days are EXDATE lines at Google and skips here', [[$third, $fifth], [$third, $fifth]], [$s->held('smplmoved1')['skips'] ?? null, $exdatesHere($id)]);
        planned($s->events, $u, $id, ['scope' => 'all', 'title' => 'Sample moved, renamed']);
        $s->events->throwDeferred();
        checkEq('renamed for all events: both days still deleted at Google and here', ['Sample moved, renamed', [$third, $fifth], [$third, $fifth]],
            [$s->held('smplmoved1')['title'] ?? null, $s->held('smplmoved1')['skips'] ?? null, $exdatesHere($id)]);
        GoogleStand::forget($db, $u);
    });
};
