<?php

declare(strict_types=1);

// A list from Google is merged by identity, whatever its order
// (GoogleSync::toParsed and applyChanges; decisions.md). An instance at
// Google is its series, its form (timed or all-day) and its key, so a timed
// occurrence at 00:00 UTC and the all-day one with the same key are two
// resources. Pure: no database, no network. Every merge here is run over
// every order of its list and must give one result, the expected one.
// Invented series: weekly on Monday, four occurrences from 4 January 2027,
// timed at 00:00 UTC or all-day, so every key coincides across the forms.
// Included by run.php, and by db/google-merge.test.php so the mutation
// runner can judge these checks by label.

use BetterCal\Domain\GoogleSync;

if (!function_exists('check')) {
    fwrite(STDERR, "Run through server/tests/run.php\n");
    exit(1);
}

(static function (): void {
    $uid = 'smplw@google.com';
    $day = static fn(int $n): string => (new \DateTimeImmutable('2027-01-04', new \DateTimeZone('UTC')))->modify('+' . (7 * ($n - 1)) . ' days')->format('Y-m-d');
    $key = static fn(int $n): string => $day($n) . ' 00:00:00';
    // A Google id of occurrence $n in a form ('date' or 'time'), written out here and not by the code under test.
    $id = static fn(string $form, int $n): string => 'smplw_' . str_replace('-', '', $day($n)) . ($form === 'time' ? 'T000000Z' : '');
    $when = static fn(string $form, string $date, string $clock = '00:00:00'): array => $form === 'date' ? ['date' => $date] : ['dateTime' => "{$date}T{$clock}Z", 'timeZone' => 'UTC'];
    $endOf = static fn(string $form, string $date): array => $form === 'date'
        ? ['date' => (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')]
        : ['dateTime' => "{$date}T01:00:00Z", 'timeZone' => 'UTC'];
    // The code under test logs what it leaves out; captured, so the run's log stays clean and the line can be checked.
    $logged = static function (callable $fn): array {
        $file = (string) tempnam(sys_get_temp_dir(), 'bc-merge-log');
        $previous = ini_set('error_log', $file);
        try {
            $result = $fn();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $log = (string) file_get_contents($file);
        @unlink($file);
        return [$result, $log];
    };
    // Google's resources, as it lists them.
    $masterItem = static fn(string $form, array $more = []): array => $more + [
        'id' => 'smplw', 'iCalUID' => $uid, 'status' => 'confirmed', 'summary' => 'Sample weekly',
        'start' => $when($form, $day(1)), 'end' => $endOf($form, $day(1)), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4'],
    ];
    $recordItem = static fn(string $form, int $n, string $title = 'Sample weekly, once'): array => [
        'id' => $id($form, $n), 'iCalUID' => $uid, 'status' => 'confirmed', 'summary' => $title, 'recurringEventId' => 'smplw',
        'originalStartTime' => $when($form, $day($n)), 'start' => $when($form, $day($n)), 'end' => $endOf($form, $day($n)),
    ];
    $skipItem = static fn(string $form, int $n): array => ['id' => $id($form, $n), 'iCalUID' => $uid, 'status' => 'cancelled', 'recurringEventId' => 'smplw', 'originalStartTime' => $when($form, $day($n))];
    $goneItem = static fn(string $form, int $n): array => ['id' => $id($form, $n), 'iCalUID' => $uid, 'status' => 'cancelled'];
    $parse = static fn(array $item): ?array => GoogleSync::toParsed($item);
    $master = static fn(string $form, array $skips = []): array => ['exdates' => $skips] + $parse($masterItem($form));
    $record = static fn(string $form, int $n, string $title = 'Sample weekly, once'): array => $parse($recordItem($form, $n, $title));
    $skip = static fn(string $form, int $n): array => $parse($skipItem($form, $n));
    $gone = static fn(string $form, int $n): array => $parse($goneItem($form, $n));
    // A day's row as the table gives it back (GoogleSync::rowsToParsed): its key and its stored Google id, no form of its own.
    $row = static function (string $form, int $n, string $title = 'Sample weekly, stored', ?string $storedId = null) use ($record, $id): array {
        $r = $record($form, $n, $title);
        unset($r['instance_all_day']);
        return ['google_event_id' => $storedId ?? $id($form, $n)] + $r;
    };
    $other = $parse(['id' => 'smpls', 'iCalUID' => 'smpls@google.com', 'status' => 'confirmed', 'summary' => 'Sample single',
        'start' => ['date' => '2027-02-01'], 'end' => ['date' => '2027-02-02']]);

    // --- toParsed: every instance item carries its Google id and its form ---
    foreach (['date' => true, 'time' => false] as $form => $allDay) {
        $live = $record($form, 2);
        checkEq("google->parsed ($form): a record carries its id, its key and its form",
            [$id($form, 2), $key(2), $allDay], [$live['google_event_id'], $live['recurrence_instance_utc'], $live['instance_all_day']]);
        $cancelled = $skip($form, 3);
        checkEq("google->parsed ($form): a cancelled record carries its id, its key and its form, and is no dropped item",
            [true, $id($form, 3), $key(3), $allDay, false],
            [$cancelled['cancelled'], $cancelled['google_event_id'], $cancelled['recurrence_instance_utc'], $cancelled['instance_all_day'], !empty($cancelled['dropped'])]);
        $dropped = $gone($form, 3);
        checkEq("google->parsed ($form): a dropped item carries its id, and its key and form from that id",
            [true, true, $id($form, 3), $key(3), $allDay],
            [$dropped['cancelled'], $dropped['dropped'], $dropped['google_event_id'], $dropped['recurrence_instance_utc'], $dropped['instance_all_day']]);
        check("google->parsed ($form): a series' own item carries no instance form", !array_key_exists('instance_all_day', $parse($masterItem($form))));
    }
    checkEq('google->parsed: the two forms of one key are one key and two identities',
        [true, false], [$gone('date', 3)['recurrence_instance_utc'] === $gone('time', 3)['recurrence_instance_utc'], $gone('date', 3)['instance_all_day'] === $gone('time', 3)['instance_all_day']]);
    $dayOfTimed = $parse(['start' => ['date' => $day(2)], 'end' => ['date' => $day(3)]] + $recordItem('time', 2));
    checkEq('google->parsed: an all-day day of a timed series has the series\' form, the shape of its originalStartTime, and its own all-day start',
        [false, 1], [$dayOfTimed['instance_all_day'], $dayOfTimed['all_day']]);
    $moved = $parse(['start' => $when('time', '2027-01-13', '15:00:00'), 'end' => $when('time', '2027-01-13', '16:00:00')] + $recordItem('time', 2));
    checkEq('google->parsed: a moved instance is named by its original start, not where it shows',
        [$key(2), '2027-01-13 15:00:00', false], [$moved['recurrence_instance_utc'], $moved['start_utc'], $moved['instance_all_day']]);
    $oddId = $parse(['id' => 'smplw_x9'] + $recordItem('date', 2));
    checkEq('google->parsed: a record under an id in another shape is named by its originalStartTime', [$key(2), true], [$oddId['recurrence_instance_utc'], $oddId['instance_all_day']]);
    checkEq('google->parsed: a cancelled series is its series, with no day', [true, null], [$parse(['id' => 'smplw', 'iCalUID' => $uid, 'status' => 'cancelled'])['cancelled'], $parse(['id' => 'smplw', 'iCalUID' => $uid, 'status' => 'cancelled'])['recurrence_instance_utc']]);
    // An instance never speaks as its series: one whose day can't be read is left out, never read as the series cancelled or as its master.
    [$unread, $unreadLog] = $logged(static fn(): array => [
        GoogleSync::toParsed(['id' => 'smplw_x9', 'iCalUID' => $uid, 'status' => 'cancelled', 'recurringEventId' => 'smplw']),
        GoogleSync::toParsed(['id' => 'smplw_x9', 'recurringEventId' => 'smplw'] + $masterItem('time')),
    ]);
    checkEq('google->parsed: an instance whose day cannot be read is left out, cancelled or live, and logged', [null, null, 2], [$unread[0], $unread[1], substr_count($unreadLog, 'could not be read')]);
    $byId = $parse(['recurringEventId' => 'smplw', 'id' => $id('time', 2)] + array_diff_key($recordItem('time', 2), ['originalStartTime' => true]));
    checkEq('google->parsed: an instance with no originalStartTime is named by its id', [$key(2), false], [$byId['recurrence_instance_utc'], $byId['instance_all_day']]);

    // --- applyChanges: one result for every order of a list ---
    $permutations = static function (array $items) use (&$permutations): array {
        if (count($items) <= 1) {
            return [$items];
        }
        $out = [];
        foreach ($items as $i => $first) {
            $rest = $items;
            unset($rest[$i]);
            foreach ($permutations(array_values($rest)) as $p) {
                $out[] = array_merge([$first], $p);
            }
        }
        return $out;
    };
    // The series as a result holds it: its skips (null with no master) and each day's row by key as [Google id, title].
    $read = static function (array $list) use ($uid): array {
        $out = ['skips' => null, 'rows' => []];
        foreach ($list as $ev) {
            if ($ev['uid'] !== $uid) {
                continue;
            }
            if ($ev['recurrence_instance_utc'] === null) {
                $out['skips'] = $ev['exdates'];
            } else {
                $out['rows'][(string) $ev['recurrence_instance_utc']] = [(string) $ev['google_event_id'], (string) $ev['title']];
            }
        }
        ksort($out['rows']);
        return $out;
    };
    // Every order of $list over $snapshot: the whole result is one list (rows, their order and the skips' order alike), and reads as $want.
    $everyOrder = static function (string $name, array $snapshot, array $list, bool $delta, array $want, ?int $max = null) use ($permutations, $read, $logged): string {
        $seen = [];
        $reads = [];
        $log = '';
        foreach ($permutations($list) as $order) {
            [$got, $said] = $logged(static function () use ($snapshot, $order, $delta, $max) {
                try {
                    return GoogleSync::applyChanges($snapshot, $order, !$delta, $max);
                } catch (\RuntimeException $e) {
                    return ['threw' => $e->getMessage()];
                }
            });
            $log = $said;
            $seen[(string) json_encode($got)] = true;
            $reads[(string) json_encode(isset($got['threw']) ? $got : $read($got))] = true;
        }
        check($name, count($seen) === 1 && array_keys($reads) === [(string) json_encode($want)],
            count($seen) . ' result(s) over the orders; want ' . json_encode($want) . ', got ' . implode(' | ', array_keys($reads)));
        return $log;
    };
    $stored = static fn(int $n, string $form, ?string $storedId = null): array => [$key($n) => [$storedId ?? $id($form, $n), 'Sample weekly, stored']];
    $listedAt = static fn(int $n, string $form, string $title = 'Sample weekly, once'): array => [$key($n) => [$id($form, $n), $title]];

    foreach (['date' => 'time', 'time' => 'date'] as $form => $old) {
        // After a change of form, the day edited again in the new form: its record is the row, whatever the old form's dropped item says and wherever it is listed.
        $everyOrder("merge ($form): a record of the series' form stands beside the other form's dropped item on its key",
            [$master($form), $row($form, 2)], [$record($form, 2), $gone($old, 2)], true, ['skips' => [], 'rows' => $listedAt(2, $form)]);
        $everyOrder("merge ($form): a read-back with the new master, a record and a skip of its form and the other form's dropped items on the same keys",
            [$other], [$master($form), $record($form, 2), $skip($form, 3), $gone($old, 2), $gone($old, 3)], false, ['skips' => [$key(3)], 'rows' => $listedAt(2, $form)]);
        // A change of form made elsewhere, the new form's days listed with it: the old rows and skips leave, the new ones stand.
        $everyOrder("merge ($form): a change of form made elsewhere with the new form's record and skip listed: the old row leaves and the old skips clear",
            [$master($old, [$key(3), $key(4)]), $row($old, 2)], [$master($form), $record($form, 3), $skip($form, 2)], true, ['skips' => [$key(2)], 'rows' => $listedAt(3, $form)]);
        // A read that lags a change of form lists the old form's records live: Google cannot hold them under the new master.
        $log = $everyOrder("merge ($form): a record and a cancelled record of the other form under the series' master are left out",
            [], [$master($form), $record($old, 2), $skip($old, 3)], false, ['skips' => [], 'rows' => []]);
        check("merge ($form): what was left out is logged once for the series, with its count", substr_count($log, 'google sync: series smplw@google.com: 2 record(s)') === 1, $log);
        // A row's identity is its key under its master's form, whatever id it was stored with: a dropped item naming a stale id of the other form is not its resource.
        $everyOrder("merge ($form): a row stored under the other form's id is its master's form, and the other form's dropped item leaves it",
            [$master($form), $row($form, 2, 'Sample weekly, stored', $id($old, 2))], [$gone($old, 2)], true, ['skips' => [], 'rows' => $stored(2, $form, $id($old, 2))]);
        // The stored skips of a master a delta carries, and of one it doesn't: each kept unless a live record or a dropped item of its form says otherwise.
        foreach (['carries' => [$master($form)], 'does not carry' => []] as $how => $own) {
            $everyOrder("merge ($form): a delta that $how the master keeps the stored skips, less the one a dropped item of its form names and the one a record brings back, plus a new cancelled record",
                [$master($form, [$key(2), $key(3), $key(4)])], array_merge($own, [$gone($form, 2), $record($form, 3), $skip($form, 1), $gone($old, 4)]), true,
                ['skips' => [$key(1), $key(4)], 'rows' => $listedAt(3, $form)]);
        }
        // The skips are a set, written in key order when the list changes it; a list that changes no skipped day leaves the stored list as it is.
        $everyOrder("merge ($form): a delta that changes no skipped day leaves the stored skips in their stored order",
            [$master($form, [$key(4), $key(2)])], [$master($form), $record($form, 3), $gone($old, 2), $skip($form, 4)], true, ['skips' => [$key(4), $key(2)], 'rows' => $listedAt(3, $form)]);
        $everyOrder("merge ($form): a delta that skips two more days writes the skips in key order, whatever order it lists them in",
            [$master($form, [$key(4)])], [$skip($form, 3), $skip($form, 1), $gone($old, 4)], true, ['skips' => [$key(1), $key(3), $key(4)], 'rows' => []]);
        // A record Google dropped for an EXDATE line the master carries (probe 3.16) stays skipped, wherever the dropped item is listed.
        $everyOrder("merge ($form): a day the master's own EXDATE line names stays skipped beside the dropped item of its record",
            [$master($form, [$key(3)])], [['exdates' => [$key(3)]] + $master($form), $gone($form, 3), $gone($old, 3)], true, ['skips' => [$key(3)], 'rows' => []]);
        // The app's own word about a day it just deleted carries a key and no form: it is of the series' form.
        $everyOrder("merge ($form): a write's tombstone with no form is of the series' form: the day's row goes and the day is skipped",
            [$master($form, [$key(4)]), $row($form, 2)], [['cancelled' => true, 'uid' => $uid, 'recurrence_instance_utc' => $key(2)]], false, ['skips' => [$key(2), $key(4)], 'rows' => []]);
        // A series cancelled, with its records listed beside it, live or not, in any order: nothing of it is left, and nothing else is touched.
        $everyOrder("merge ($form): a cancelled series leaves nothing, wherever its records are listed",
            [$master($form, [$key(4)]), $row($form, 2), $other], [['cancelled' => true, 'uid' => $uid, 'recurrence_instance_utc' => null], $record($form, 2), $record($form, 3), $skip($form, 1)], true,
            ['skips' => null, 'rows' => []]);
        // A record with no master, listed or stored: nothing is judged by form, and each item acts on its own identity.
        $everyOrder("merge ($form): a record with no master is a row, and the other form's dropped item on its key leaves it",
            [], [$record($form, 2), $gone($old, 2)], true, ['skips' => null, 'rows' => $listedAt(2, $form)]);
        $everyOrder("merge ($form): a stored row with no master goes with the dropped item of its own id, not with the other form's",
            [$row($form, 2), $row($form, 3)], [$gone($form, 2), $gone($old, 3)], true, ['skips' => null, 'rows' => $stored(3, $form)]);
    }
    // A moved instance is its original start: the dropped item of its id removes it, one for the day it shows on does not.
    $movedRow = ['start_utc' => $key(3), 'end_utc' => $day(3) . ' 01:00:00'] + $row('time', 2);
    $everyOrder('merge: a moved day\'s row goes with the dropped item of its original start', [$master('time'), $movedRow], [$gone('time', 2)], true, ['skips' => [], 'rows' => []]);
    $everyOrder('merge: a moved day\'s row stays when a dropped item names the day it shows on', [$master('time'), $movedRow], [$gone('time', 3)], true, ['skips' => [], 'rows' => $stored(2, 'time')]);
    // An all-day day of a timed series is of the timed form, so it stays a row under its timed master.
    $everyOrder('merge: an all-day day of a timed series is a row under its timed master', [$master('time')], [$dayOfTimed, $gone('date', 2)], true, ['skips' => [], 'rows' => $listedAt(2, 'time')]);
    // The limit is on the calendar after the list: one event added and another removed at the limit is the same list in either order.
    $everyOrder('merge: an event added and another removed at the limit fit in either order', [$other],
        [['cancelled' => true, 'uid' => 'smpls@google.com', 'recurrence_instance_utc' => null], $master('date')], true, ['skips' => [], 'rows' => []], 1);
    // --- Which day a row is (Feeds::ofSeries): by identity first, by the day second ---
    // A daily game at 19:00 in New York from Tuesday 5 January 2027: the game of Wednesday the 6th is the instant
    // 2027-01-07T00:00Z, which is also the key an all-day series gives Thursday the 7th.
    $gameOf = static fn(array $more): array => $more + ['title' => 'Sample game', 'description' => null, 'location' => null, 'url' => null, 'status' => 'confirmed',
        'start_utc' => '2027-01-06 00:00:00', 'end_utc' => '2027-01-06 02:00:00', 'all_day' => 0, 'tzid' => 'America/New_York', 'rrule' => 'FREQ=DAILY;COUNT=10', 'exdates_json' => null];
    $newYork = $gameOf([]);
    $wednesday = ['recurrence_instance_utc' => '2027-01-07 00:00:00'];
    $dayOf = static fn(array $was, array $series): ?string => \BetterCal\Domain\Feeds::ofSeries($wednesday, $was, $series)['recurrence_instance_utc'] ?? null;
    checkEq('which day: a zone rewritten with no instant moving (00:00 UTC) leaves the row on its own occurrence', '2027-01-07 00:00:00', $dayOf($newYork, $gameOf(['tzid' => 'UTC'])));
    checkEq('which day: the same rewritten as 09:00 Tokyo', '2027-01-07 00:00:00', $dayOf($newYork, $gameOf(['tzid' => 'Asia/Tokyo'])));
    checkEq('which day: that occurrence skipped in the rewritten series: the row\'s day is gone, not the neighbour\'s', null,
        $dayOf($newYork, $gameOf(['tzid' => 'UTC', 'exdates_json' => '["2027-01-07 00:00:00"]'])));
    checkEq('which day: a new clock time (20:00 New York) names no occurrence at the old key, so the row goes with its day, Wednesday the 6th', '2027-01-07 01:00:00',
        $dayOf($newYork, $gameOf(['start_utc' => '2027-01-06 01:00:00', 'end_utc' => '2027-01-06 03:00:00'])));
    checkEq('which day: made all-day, every name changes: the row goes to the day it showed, the 6th, though the 7th has its key', '2027-01-06 00:00:00',
        $dayOf($newYork, $gameOf(['start_utc' => '2027-01-05 00:00:00', 'end_utc' => '2027-01-06 00:00:00', 'all_day' => 1, 'tzid' => 'UTC'])));
    checkEq('which day: the start moved along its own pattern (probe 3.5) leaves the row at its instant', '2027-01-07 00:00:00',
        $dayOf($newYork, $gameOf(['start_utc' => '2027-01-07 00:00:00', 'end_utc' => '2027-01-07 02:00:00'])));

    // --- The date a removed day is named by (Feeds::droppedDay): its key under its series' form and zone ---
    $named = static fn(array $row, ?array $series): string => \BetterCal\Domain\Feeds::droppedDay(['id' => 7, 'title' => 'Sample day'] + $row, $series, [], [], 'gone')['day'];
    checkEq('day named: a whole day of a 19:00 New York series is the day its series gives the key, the 6th', '2027-01-06',
        $named(['recurrence_instance_utc' => '2027-01-07 00:00:00', 'start_utc' => '2027-01-06 00:00:00', 'all_day' => 1, 'tzid' => 'UTC'], $newYork));
    checkEq('day named: a timed day of an all-day series is its key\'s date, the 6th', '2027-01-06',
        $named(['recurrence_instance_utc' => '2027-01-06 00:00:00', 'start_utc' => '2027-01-06 19:00:00', 'all_day' => 0, 'tzid' => 'America/New_York'], ['all_day' => 1, 'tzid' => 'UTC'] + $newYork));
    checkEq('day named: an event of its own is named by its own start in its own zone', '2027-01-06',
        $named(['recurrence_instance_utc' => null, 'start_utc' => '2027-01-07 00:00:00', 'all_day' => 0, 'tzid' => 'America/New_York'], null));

    $everyOrder('merge: two events added over the limit are refused in either order', [],
        [$other, $master('date')], true, ['threw' => 'Google calendar has more than the safe limit of 1 events for this server; the existing calendar and sync position were kept unchanged.'], 1);
})();
