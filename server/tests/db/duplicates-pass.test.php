<?php

declare(strict_types=1);

// A calendar connected whose events another calendar already holds (#317):
// the same UIDs, back many years. Every pair a person can see is shown
// once, a hidden calendar's pairs too, no health line is recorded, the pass
// ends in a bounded number of jobs, and a new duplicate elsewhere is still
// linked. An install whose 1.5.7 cap was full, with its health line
// failing, recovers by itself on the next pass. What a pass really leaves
// out is said in words, once, however many passes find it. At a size that
// runs in seconds: the limits are lowered, not the events multiplied.

use BetterCal\Domain\Duplicates;
use BetterCal\Domain\SystemHealth;
use BetterCal\Infra\Db;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $health = new SystemHealth($db);
    $subject = Duplicates::HEALTH_SUBJECT;
    $kept = (int) $d->calendars->create($u, ['name' => 'Imported sample'])['id'];
    $connect = static function (string $name, bool $visible = true) use ($db, $d, $u): int {
        $id = (int) $d->calendars->create($u, ['name' => $name])['id'];
        $db->update('calendars', ['kind' => 'subscribed', 'provider' => 'google', 'visible' => $visible ? 1 : 0], 'id = ?', [$id]);
        return $id;
    };
    // One event made as the app makes it, copied: each copy its own uid and day.
    $templateId = (int) $d->events->create($u, eventAt($kept, 'Sample template', '2011-01-05'))['eventId'];
    $template = $db->one('SELECT * FROM events WHERE id = ?', [$templateId]);
    unset($template['id']);
    $copy = static function (int $calendarId, string $uid, string $title, DateTimeImmutable $start, bool $feed) use ($db, $template): int {
        $row = ['calendar_id' => $calendarId, 'uid' => $uid, 'title' => $title,
            'start_utc' => $start->format('Y-m-d H:i:s'), 'end_utc' => $start->modify('+1 hour')->format('Y-m-d H:i:s'),
            'source' => $feed ? 'feed' : 'local', 'created_via' => $feed ? 'feed' : 'import'] + $template;
        return $db->insert('events', $row);
    };
    // The same events on two calendars, the second connected later (higher
    // ids): most years back, a few in the coming weeks.
    $holdTwice = static function (int $first, int $second, string $prefix, int $n) use ($copy): array {
        $days = [];
        for ($i = 0; $i < $n; $i++) {
            $days[$i] = $i % 5 === 4
                ? Time::nowUtc()->setTime(17, 0)->modify('+' . (3 + 2 * $i) . ' days')
                : (new DateTimeImmutable('2012-03-01 17:00:00 UTC'))->modify('+' . (130 * $i) . ' days');
        }
        $ids = [];
        foreach ($days as $i => $day) {
            $ids[$i] = $copy($first, "$prefix-$i@example.test", "Sample meeting $prefix $i", $day, false);
        }
        foreach ($days as $i => $day) {
            $copy($second, "$prefix-$i@example.test", "Sample meeting $prefix $i", $day, true);
        }
        return $ids;
    };
    // Every pair on these calendars is linked, so each event shows once.
    $unlinked = static function (array $firstIds, int $second) use ($db): int {
        $links = (new Duplicates($db))->linkedFor($firstIds);
        return count(array_filter($firstIds, static fn(int $id): bool => !in_array($second, array_column($links[$id] ?? [], 'calendarId'), true)));
    };
    $healthRow = static fn() => $db->one('SELECT status, consecutive_failures, last_error, last_failed_at, first_failed_at, alerted_at, label FROM system_health WHERE subject = ?', [$subject]);
    $journaled = static fn(): int => (int) $db->scalar("SELECT COUNT(*) FROM mutations WHERE summary LIKE 'Duplicate check:%'");
    // One pass as the worker runs it, job after job, starting at this account.
    $pass = static function () use ($db, $u, $health): array {
        $dups = new Duplicates($db);
        $payload = ['cursor' => ['userId' => $u, 'phase' => 'uid', 'afterId' => 0]];
        for ($jobs = 1; $jobs <= 400; $jobs++) {
            $run = $dups->workerSlice($payload, $health);
            if ($run['next'] === null) {
                return ['jobs' => $jobs] + $run;
            }
            $payload = json_decode((string) json_encode($run['next']), true); // as a job payload carries it
        }
        throw new RuntimeException('the pass did not end in 400 jobs');
    };
    // The jobs a pass may take: every event a slice, at most as many as a
    // slice's rows and pairs allow, then the window's events the same way.
    $bound = static function () use ($db, $u): int {
        $rows = (int) $db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ? AND deleted_at IS NULL', [$u]);
        return 2 * (intdiv($rows, min(Limits::get('DUPLICATE_SCAN_ROWS'), Limits::get('DUPLICATE_PAIRS'))) + 2);
    };

    try {
        // A slice's work is small, so the pass takes many jobs. 1.5.7's cap on
        // linked pairs is set low as the install had it full; it is gone, and
        // the name is ignored.
        Limits::configure(['DUPLICATE_SCAN_ROWS' => 8, 'DUPLICATE_PAIRS' => 6, 'DUPLICATE_LINKED_EDGES' => 10]);
        $connected = $connect('Connected sample');
        $keptIds = $holdTwice($kept, $connected, 'held', 40);

        scenario('duplicates pass: an install with the old cap full and its line failing recovers on the next pass', function () use ($db, $u, $health, $subject, $kept, $connected, $keptIds, $unlinked, $healthRow, $journaled, $pass, $bound): void {
            // As 1.5.7 left it: the first ten pairs linked, the line failing
            // for many passes, and its email sent.
            $twins = $db->all('SELECT a.id AS a, b.id AS b FROM events a JOIN events b ON b.uid = a.uid AND b.calendar_id = ? WHERE a.calendar_id = ? ORDER BY a.id LIMIT 10', [$connected, $kept]);
            foreach ($twins as $t) {
                $db->insert('event_duplicates', ['user_id' => $u, 'event_a' => (int) $t['a'], 'event_b' => (int) $t['b'], 'status' => 'linked', 'basis' => 'uid']);
            }
            $health->recordFailure($subject, 'job', null, 'Duplicate detection backlog', 'One scan cycle reached its safety budget; duplicate suggestions were bounded or deferred.');
            $db->update('system_health', ['consecutive_failures' => 300, 'alerted_at' => Time::nowDb()], 'subject = ?', [$subject]);
            checkEq('duplicates pass: before, thirty of the forty show twice', 30, $unlinked($keptIds, $connected));
            $before = $journaled();
            $run = $pass();
            checkEq('duplicates pass: after one pass every pair is linked, years back and in the coming weeks', 0, $unlinked($keptIds, $connected));
            checkEq('duplicates pass: one stored link per extra copy', 40, (int) $db->scalar("SELECT COUNT(*) FROM event_duplicates WHERE user_id = ? AND status = 'linked'", [$u]));
            check('duplicates pass: the pass ends in a bounded number of jobs', $run['jobs'] <= $bound(), $run['jobs'] . ' jobs, bound ' . $bound());
            checkEq('duplicates pass: nothing was left out, though slices were full', [[], null], [$run['skipped'], $run['words']]);
            $row = $healthRow();
            checkEq('duplicates pass: the line is ok again under its new name, its email owed a recovery', ['ok', 0, 'Duplicate check', true],
                [$row['status'], (int) $row['consecutive_failures'], $row['label'], $row['alerted_at'] !== null]);
            checkEq('duplicates pass: the recovery is one Activity line', 1, $journaled() - $before);
            $mid = $journaled();
            $again = $pass();
            checkEq('duplicates pass: a second pass finds nothing new and writes no line', [0, 0, []], [$again['slice']['linked'], $journaled() - $mid, $again['skipped']]);
        });

        scenario('duplicates pass: a hidden calendar holding the same events is linked too, so showing it shows each once', function () use ($db, $u, $kept, $connect, $holdTwice, $unlinked, $healthRow, $pass, $bound): void {
            $hiddenTwin = $connect('Hidden sample', false);
            $other = (int) domains($db)->calendars->create($u, ['name' => 'Second imported sample'])['id'];
            $otherIds = $holdTwice($other, $hiddenTwin, 'hidden', 20);
            $run = $pass();
            check('duplicates pass: with a hidden side, the pass ends in a bounded number of jobs', $run['jobs'] <= $bound(), $run['jobs'] . ' jobs');
            checkEq('duplicates pass: every pair with a hidden side is linked', 0, $unlinked($otherIds, $hiddenTwin));
            checkEq('duplicates pass: no line is recorded', 'ok', $healthRow()['status']);
            // Shown again: the links are there already, nothing waits on a scan.
            $db->update('calendars', ['visible' => 1], 'id = ?', [$hiddenTwin]);
            checkEq('duplicates pass: shown again, each pair is still linked with no scan', 0, $unlinked($otherIds, $hiddenTwin));
            // One reminder for a pair, the hidden side's copy included.
            $soon = $otherIds[4];
            $twin = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = (SELECT uid FROM events WHERE id = ?)', [$hiddenTwin, $soon]);
            $rows = [];
            foreach ($db->all('SELECT * FROM events WHERE id IN (?, ?)', [$soon, $twin]) as $r) {
                $rows[(int) $r['id']] = $r;
            }
            $cals = [$other => ['provider' => 'ics', 'kind' => 'local'], $hiddenTwin => ['provider' => 'google', 'kind' => 'subscribed']];
            checkEq('duplicates pass: one copy of the pair reminds', 1, count((new Duplicates($db))->silenced($rows, static fn(array $_r): bool => true, $cals)));
        });

        scenario('duplicates pass: a new duplicate elsewhere is still linked', function () use ($db, $u, $connect, $copy, $pass): void {
            $mine = (int) domains($db)->calendars->create($u, ['name' => 'Third sample'])['id'];
            $theirs = $connect('Third connected sample');
            $day = Time::nowUtc()->setTime(15, 30)->modify('+9 days');
            $a = $copy($mine, 'fresh@example.test', 'Sample planning', $day, false);
            $b = $copy($theirs, 'fresh@example.test', 'Sample planning', $day, true);
            $pass();
            checkEq('duplicates pass: the new pair is linked', 'linked', $db->scalar('SELECT status FROM event_duplicates WHERE event_a = ? AND event_b = ?', [min($a, $b), max($a, $b)]));
        });

        scenario('duplicates pass: what a pass leaves out is said in words, once, and cleared when it no longer holds', function () use ($kept, $copy, $healthRow, $journaled, $pass): void {
            // Four events at one moment, each with three neighbours, compared
            // with only two of them.
            Limits::configure(['DUPLICATE_CANDIDATES_PER_EVENT' => 2]);
            $busy = Time::nowUtc()->setTime(19, 0)->modify('+150 days');
            foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $i => $word) {
                $copy($kept, "busy-$i@example.test", "Sample $word review", $busy, false);
            }
            $before = $journaled();
            $run = $pass();
            $row = $healthRow();
            checkEq('duplicates pass: the line says which calendar, how many, what shows and what to do', [
                'failing', 1,
                '4 events on busy days in Imported sample were compared with only 2 of the events within a day and a half, so a copy on another calendar may show twice. Setting BETTERCAL_LIMIT_DUPLICATE_CANDIDATES_PER_EVENT above 2 checks them all.',
            ], [$row['status'], (int) $row['consecutive_failures'], $row['last_error']]);
            checkEq('duplicates pass: the words are the pass\'s', $row['last_error'], $run['words']);
            checkEq('duplicates pass: recorded as one Activity line', 1, $journaled() - $before);
            $pass();
            $pass();
            checkEq('duplicates pass: two more passes finding the same change nothing', [$row, 1], [$healthRow(), $journaled() - $before]);
            Limits::configure(['DUPLICATE_CANDIDATES_PER_EVENT' => 100]);
            $pass();
            checkEq('duplicates pass: compared in full, the line clears with one more Activity line', ['ok', 2], [$healthRow()['status'], $journaled() - $before]);
        });
    } finally {
        Limits::reset();
    }
};
