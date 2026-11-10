<?php

declare(strict_types=1);

// Rule 1, provider identity, the pure part: GoogleSync::applyChanges merges a
// list of Google items into the stored rows, and Google gives no order for a
// list (a delta's least of all), so the result must be a function of the set
// of items. Each case below runs over every order of its list and must give
// one result, the expected one. The expectations are the table of the rule 1
// audit (workfolder/reviews/audit-155-identity.md, "The model for
// applyChanges"): a dropped item speaks only about the resource with its id,
// only the final master's form counts, and within that form each key has one
// item. Google names an instance by series id, form and key:
// `<id>_<YYYYMMDD>` all-day, `<id>_<UTC basic time>Z` timed. The 10:00 Los
// Angeles cases are controls where no key coincides. No database. Required
// from run.php, or run alone: php server/tests/google-identity.php.
// Invented data.

use BetterCal\Domain\GoogleSync;

if (!function_exists('check')) {
    require dirname(__DIR__) . '/src/bootstrap.php';
    $GLOBALS['__pass'] = 0;
    $GLOBALS['__fail'] = 0;
    function check(string $name, bool $cond, string $detail = ''): void
    {
        if ($cond) {
            $GLOBALS['__pass']++;
            return;
        }
        $GLOBALS['__fail']++;
        echo "FAIL: $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
    function checkEq(string $name, mixed $expected, mixed $actual): void
    {
        check($name, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    $GLOBALS['__standalone'] = true;
}

(static function (): void {
    $uid = 'abcde@google.com';
    $base = ['uid' => $uid, 'title' => 'Sample weekly', 'description' => null, 'location' => null, 'url' => null, 'rrule' => 'FREQ=WEEKLY;COUNT=4', 'exdates' => [], 'status' => 'confirmed',
        'recurrence_instance_utc' => null, 'reminders' => [], 'google_event_id' => 'abcde'];
    // Weekly, four occurrences from Monday 4 January 2027: at 00:00 UTC, all-day, or at 10:00 Los Angeles (18:00 UTC in January).
    $timed = ['start_utc' => '2027-01-04 00:00:00', 'end_utc' => '2027-01-04 01:00:00', 'all_day' => 0, 'tzid' => 'UTC'] + $base;
    $day = ['start_utc' => '2027-01-04 00:00:00', 'end_utc' => '2027-01-05 00:00:00', 'all_day' => 1, 'tzid' => 'UTC'] + $base;
    $la = ['start_utc' => '2027-01-04 18:00:00', 'end_utc' => '2027-01-04 19:00:00', 'all_day' => 0, 'tzid' => 'America/Los_Angeles'] + $base;
    $K2 = '2027-01-11 00:00:00';
    $K3 = '2027-01-18 00:00:00';
    $K3la = '2027-01-18 18:00:00';
    $rowOf = static fn(array $m, string $id, string $key): array => ['recurrence_instance_utc' => $key, 'google_event_id' => $id, 'start_utc' => $key, 'rrule' => null,
        'description' => 'Bring the sample checklist', 'end_utc' => (int) $m['all_day'] === 1 ? '2027-01-12 00:00:00' : '2027-01-11 01:00:00'] + $m;
    $item = static fn(array $r): ?array => GoogleSync::toParsed(['iCalUID' => $uid] + $r);
    $cancelled = static fn(string $suffix, array $orig): ?array => $item(['id' => 'abcde_' . $suffix, 'status' => 'cancelled', 'recurringEventId' => 'abcde', 'originalStartTime' => $orig]);
    // A dropped item: cancelled, under its old id, with no originalStartTime (probe 3.1).
    $dropped = static fn(string $suffix): ?array => $item(['id' => 'abcde_' . $suffix, 'status' => 'cancelled']);
    $dateSkip = $cancelled('20270118', ['date' => '2027-01-18']);
    $timedSkip = $cancelled('20270118T000000Z', ['dateTime' => '2027-01-18T00:00:00Z', 'timeZone' => 'UTC']);
    $laSkip = $cancelled('20270118T180000Z', ['dateTime' => '2027-01-18T10:00:00-08:00', 'timeZone' => 'America/Los_Angeles']);

    // What the merge stored for the series: its skips, and the Google ids of its day rows.
    $read = static function (array $list) use ($uid): array {
        $out = ['skips' => null, 'rows' => []];
        foreach ($list as $ev) {
            if (($ev['uid'] ?? null) !== $uid) {
                continue;
            }
            if ($ev['recurrence_instance_utc'] === null) {
                $skips = array_values($ev['exdates'] ?? []);
                sort($skips);
                $out['skips'] = $skips;
            } else {
                $out['rows'][] = (string) $ev['google_event_id'];
            }
        }
        sort($out['rows']);
        return $out;
    };
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
    $case = static function (string $name, array $snapshot, array $changes, bool $delta, array $want) use ($read, $permutations): void {
        $seen = [];
        foreach ($permutations($changes) as $order) {
            $seen[json_encode($read(GoogleSync::applyChanges($snapshot, $order, !$delta)))] = true;
        }
        check("google identity, applyChanges: $name (every order of the list gives " . json_encode($want) . ')', array_keys($seen) === [json_encode($want)],
            'got ' . implode(' | ', array_keys($seen)));
    };

    foreach ([['a read-back or whole read', false], ['a delta', true]] as [$how, $delta]) {
        // The app flipped the series and sent the skip again: the list holds the new master, the skip in the new form and the old skip's dropped item.
        $case("$how, timed to all-day at 00:00 UTC: the new-form skip stands beside the old form's dropped item", $delta ? [$day] : [], [$day, $dateSkip, $dropped('20270118T000000Z')], $delta,
            ['skips' => [$K3], 'rows' => []]);
        $case("$how, all-day to timed at 00:00 UTC: the new-form skip stands beside the old form's dropped item", $delta ? [$timed] : [], [$timed, $timedSkip, $dropped('20270118')], $delta,
            ['skips' => [$K3], 'rows' => []]);
        // Controls: at 10:00 Los Angeles no key coincides.
        $case("$how, timed 10:00 Los Angeles to all-day (control): the new-form skip stands", $delta ? [$day] : [], [$day, $dateSkip, $dropped('20270118T180000Z')], $delta,
            ['skips' => [$K3], 'rows' => []]);
        $case("$how, all-day to timed 10:00 Los Angeles (control): the new-form skip stands", $delta ? [$la] : [], [$la, $laSkip, $dropped('20270118')], $delta,
            ['skips' => [$K3la], 'rows' => []]);
    }
    // A later delta lists an old-form dropped item alone: the new-form row or skip at the same key is not its resource.
    $case('a delta, an old-form dropped item alone leaves the new-form row', [$day, $rowOf($day, 'abcde_20270111', $K2)], [$dropped('20270111T000000Z')], true,
        ['skips' => [], 'rows' => ['abcde_20270111']]);
    $case('a delta, an old-form dropped item alone leaves the new-form skip', [['exdates' => [$K3]] + $day], [$dropped('20270118T000000Z')], true,
        ['skips' => [$K3], 'rows' => []]);
    // The same items in the row's own form still do their work.
    $case('a delta, a dropped item of the row\'s own form removes it', [$day, $rowOf($day, 'abcde_20270111', $K2)], [$dropped('20270111')], true,
        ['skips' => [], 'rows' => []]);
    $case('a delta, a dropped item of the skip\'s own form clears it', [['exdates' => [$K3]] + $day], [$dropped('20270118')], true,
        ['skips' => [], 'rows' => []]);
    // The stale-local case: the flip was made elsewhere; here the series is still timed with an edited day and a skip.
    $stale = [['exdates' => [$K3]] + $timed, $rowOf($timed, 'abcde_20270111T000000Z', $K2)];
    $case('a delta, a flip made elsewhere, dropped items listed: the old-form row goes and the old skip clears', $stale, [$day, $dropped('20270111T000000Z'), $dropped('20270118T000000Z')], true,
        ['skips' => [], 'rows' => []]);
    $case('a delta, a flip made elsewhere, no dropped item listed: the old-form row goes and the old skip clears', $stale, [$day], true,
        ['skips' => [], 'rows' => []]);
})();

if (!empty($GLOBALS['__standalone'])) {
    echo $GLOBALS['__pass'] . ' passed, ' . $GLOBALS['__fail'] . " failed\n";
    exit($GLOBALS['__fail'] === 0 ? 0 : 1);
}
