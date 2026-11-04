<?php

declare(strict_types=1);

// The series model's pure tests (docs/design/series-model.md and its
// examples): every row of the drag table and its readings, the bound rules,
// the acceptance cases, the words. No database. Required from run.php, or
// run alone: php server/tests/series-model.php

use BetterCal\Domain\Series\Cal;
use BetterCal\Domain\Series\Model;
use BetterCal\Domain\Series\OwnFields;
use BetterCal\Domain\Series\Rule;
use BetterCal\Domain\Series\Words;
use BetterCal\Support\Time;

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
        echo "FAIL: $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
    function checkEq(string $name, mixed $expected, mixed $actual): void
    {
        check($name, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    $GLOBALS['__standalone'] = true;
}

/** UTC db string of a local wall time. */
function smAt(string $local, string $tz = 'America/Los_Angeles'): string
{
    return Time::toDb(new DateTimeImmutable($local, Time::zone($tz)));
}

/** A master row: timed in Los Angeles unless all-day (stored as UTC dates). */
function smSeries(string $title, string $rrule, string $startLocal, int $minutes = 60, bool $allDay = false, array $exdates = [], string $tz = 'America/Los_Angeles'): array
{
    $start = $allDay ? smAt(substr($startLocal, 0, 10) . ' 00:00:00', 'UTC') : smAt($startLocal, $tz);
    return [
        'id' => 1, 'title' => $title, 'rrule' => $rrule, 'start_utc' => $start,
        'end_utc' => Time::toDb(Time::fromDb($start)->modify('+' . ($allDay ? 1440 : $minutes) . ' minutes')),
        'all_day' => $allDay ? 1 : 0, 'tzid' => $allDay ? 'UTC' : $tz, 'location' => null, 'description' => null, 'url' => null,
        'status' => 'confirmed', 'icon' => null, 'attendance' => 'none', 'reminders_json' => null,
        'exdates_json' => $exdates === [] ? null : json_encode(array_map(static fn(string $l): string => $allDay ? smAt($l . ' 00:00:00', 'UTC') : smAt($l, $tz), $exdates)),
        'updated_at' => '2027-01-01 00:00:00',
    ];
}

/** An edited row of $master keyed on its occurrence $keyLocal, showing at $shownLocal. */
function smEdited(array $master, int $id, string $keyLocal, array $cols = [], ?string $shownLocal = null, ?array $own = null): array
{
    $allDay = (int) $master['all_day'] === 1;
    $tz = (string) $master['tzid'];
    $key = $allDay ? smAt(substr($keyLocal, 0, 10) . ' 00:00:00', 'UTC') : smAt($keyLocal, $tz);
    $len = Time::fromDb($master['end_utc'])->getTimestamp() - Time::fromDb($master['start_utc'])->getTimestamp();
    $start = $shownLocal === null ? $key : ($allDay ? smAt(substr($shownLocal, 0, 10) . ' 00:00:00', 'UTC') : smAt($shownLocal, $tz));
    $row = array_merge($master, [
        'id' => $id, 'rrule' => null, 'exdates_json' => null, 'recurrence_parent_id' => 1, 'recurrence_instance_utc' => $key,
        'start_utc' => $start, 'end_utc' => Time::toDb(Time::fromDb($start)->modify('+' . $len . ' seconds')),
    ], $cols);
    $row['override_fields'] = $own === null ? null : json_encode($own);
    return $row;
}

/** A whole-series drag of the occurrence at $fromLocal to $toLocal (same length). */
function smDrag(array $master, string $fromLocal, string $toLocal, array $extra = []): array
{
    $allDay = (int) $master['all_day'] === 1;
    $tz = (string) $master['tzid'];
    $len = Time::fromDb($master['end_utc'])->getTimestamp() - Time::fromDb($master['start_utc'])->getTimestamp();
    $acted = $allDay ? smAt(substr($fromLocal, 0, 10) . ' 00:00:00', 'UTC') : smAt($fromLocal, $tz);
    $start = $allDay ? smAt(substr($toLocal, 0, 10) . ' 00:00:00', 'UTC') : smAt($toLocal, $tz);
    return ['acted' => $acted, 'landed' => ['start_utc' => $start, 'end_utc' => Time::toDb(Time::fromDb($start)->modify('+' . $len . ' seconds')), 'all_day' => $allDay ? 1 : 0, 'tzid' => $tz]] + $extra;
}

function smPlan(array $master, array $rows, array $edit, array $choices = [], array $opts = []): array
{
    return Model::plan($master, $rows, $edit, $choices, $opts + ['today' => '2027-01-01']);
}

/** The local dates (Y-m-d) a plan's next list shows. */
function smNext(array $result, ?int $n = null): array
{
    $out = array_map(static fn(array $x): string => substr($x['start'], 0, 10), $result['plan']['next']);
    return $n === null ? $out : array_slice($out, 0, $n);
}

function smGroup(array $result, string $id): ?array
{
    foreach ($result['plan']['groups'] as $g) {
        if ($g['id'] === $id) {
            return $g;
        }
    }
    return null;
}

/** Option ids of a group, disabled ones marked with a trailing "!". */
function smOptions(array $result, string $id): array
{
    $g = smGroup($result, $id);
    return $g === null ? [] : array_map(static fn(array $o): string => $o['id'] . (!empty($o['disabled']) ? '!' : ''), $g['options']);
}

/** Every local date (Y-m-d) a written series shows: its rule from its start, its skips applied, through $to. */
function smShows(array $master, array $writes, string $to): array
{
    $row = array_merge($master, $writes['master']);
    $tz = (int) $row['all_day'] === 1 ? 'UTC' : (string) $row['tzid'];
    $occ = BetterCal\Domain\Recurrence::sabreExpand(['exdates_json' => null] + $row, Time::fromDb((string) $row['start_utc']), smAt($to . ' 23:59:59', $tz) === '' ? Time::nowUtc() : Time::fromDb(smAt($to . ' 23:59:59', $tz)));
    $skips = array_flip(json_decode((string) ($row['exdates_json'] ?? '[]'), true) ?: []);
    $out = [];
    foreach ($occ as $o) {
        $k = Time::toDb($o['start']);
        if (!isset($skips[$k])) {
            $out[] = $o['start']->setTimezone(Time::zone($tz))->format('Y-m-d');
        }
    }
    return $out;
}

if (class_exists(\Sabre\VObject\Component\VCalendar::class)) {
    // ---- Day arithmetic and rules ------------------------------------------
    checkEq('series model: day numbers round-trip through civil dates', ['2027-02-28', '2028-02-29', '1970-01-01', '2400-03-01'],
        array_map(static fn(array $d): string => Cal::date(Cal::dn(...$d)), [[2027, 2, 28], [2028, 2, 29], [1970, 1, 1], [2400, 3, 1]]));
    checkEq('series model: weekdays (Tue 5 Jan 2027, Thu 1 Jan 1970)', [1, 3], [Cal::wd(Cal::dn(2027, 1, 5)), Cal::wd(0)]);
    checkEq('series model: the clamped 30th gives 28 Feb 2027 and 29 Feb 2028', [28, 29, 30],
        [Rule::parse('FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1', 0)->evalMember(['t' => 'clamp', 'n' => 30], 2027, 2),
         Rule::parse('FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1', 0)->evalMember(['t' => 'clamp', 'n' => 30], 2028, 2),
         Rule::parse('FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1', 0)->evalMember(['t' => 'clamp', 'n' => 30], 2027, 3)]);
    checkEq('series model: 4MO and -1MO overlap; 1MO, 3TU and -1FR do not', [true, false],
        [Rule::nthOverlap([['n' => 4, 'wd' => 0], ['n' => -1, 'wd' => 0]]), Rule::nthOverlap([['n' => 1, 'wd' => 0], ['n' => 3, 'wd' => 1], ['n' => -1, 'wd' => 4]])]);
    checkEq('series model: rule kinds by the drag table', ['weekly', 'monthly', 'monthly', 'monthly', 'yearly', 'other', 'other', 'unreadable', 'other'], array_map(
        static fn(string $r): string => Rule::parse($r, Cal::dn(2027, 1, 4))->kind,
        ['FREQ=DAILY;BYDAY=MO,WE', 'FREQ=MONTHLY;BYDAY=1MO,3MO', 'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=1,3', 'FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1',
         'FREQ=YEARLY;BYMONTH=3;BYDAY=2TU', 'FREQ=MONTHLY;BYDAY=4MO,-1MO', 'FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO', 'RSCALE=HEBREW;FREQ=YEARLY;BYMONTH=5L', 'FREQ=DAILY;BYHOUR=9,17']));
    checkEq('series model: a sent rule equal but for INTERVAL=1, BYDAY order or a bare day is the same rule', [true, true, true], [
        Rule::parse('FREQ=WEEKLY;INTERVAL=1;BYDAY=WE,MO', 0)->comparable() === Rule::parse('FREQ=WEEKLY;BYDAY=MO,WE', 0)->comparable(),
        Rule::parse('FREQ=MONTHLY;BYMONTHDAY=15', Cal::dn(2027, 1, 15))->comparable() === Rule::parse('FREQ=MONTHLY', Cal::dn(2027, 1, 15))->comparable(),
        Rule::parse('FREQ=YEARLY;BYMONTH=3;BYMONTHDAY=15', Cal::dn(2027, 3, 15))->comparable() === Rule::parse('FREQ=YEARLY', Cal::dn(2027, 3, 15))->comparable(),
    ]);

    // ---- Words ------------------------------------------------------------
    $w = static fn(string $r, string $start = '2027-01-04', ?array $clock = null): string => Words::rule(Rule::parse($r, Cal::dn(...array_map('intval', explode('-', $start)))), $clock);
    checkEq('series words: the examples\' sentences', [
        'Weekly on Tuesday at 19:00', 'Weekly on Monday, Wednesday and Friday', 'Every 2 weeks on Saturday and Sunday',
        'Monthly on the 30th, or the last day in shorter months', 'Monthly on the 30th, skipping months without one', 'Monthly on the last day',
        'Monthly on the second-last day', 'Monthly on the 1st and 15th', 'Monthly on the second Tuesday', 'Monthly on the last Friday',
        'Monthly on the first and third Monday', 'Monthly on the first Monday and third Tuesday', 'Monthly on the first and third weekday',
        'Yearly on 28 February', 'Yearly on the last day of February', 'Yearly on 29 February, only in leap years', 'Yearly on the second Tuesday of March',
        'Every 2 days', 'Monthly on the 15th',
    ], [
        $w('FREQ=WEEKLY;BYDAY=TU', '2027-01-05', [19, 0, 0]), $w('FREQ=WEEKLY;BYDAY=MO,WE,FR'), $w('FREQ=WEEKLY;INTERVAL=2;BYDAY=SA,SU'),
        $w('FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1'), $w('FREQ=MONTHLY;BYMONTHDAY=30'), $w('FREQ=MONTHLY;BYMONTHDAY=-1'),
        $w('FREQ=MONTHLY;BYMONTHDAY=-2'), $w('FREQ=MONTHLY;BYMONTHDAY=1,15'), $w('FREQ=MONTHLY;BYDAY=2TU'), $w('FREQ=MONTHLY;BYDAY=FR;BYSETPOS=-1'),
        $w('FREQ=MONTHLY;BYDAY=1MO,3MO'), $w('FREQ=MONTHLY;BYDAY=1MO,3TU'), $w('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=1,3'),
        $w('FREQ=YEARLY', '2027-02-28'), $w('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=-1'), $w('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29'), $w('FREQ=YEARLY;BYMONTH=3;BYDAY=2TU'),
        $w('FREQ=DAILY;INTERVAL=2'), $w('FREQ=MONTHLY', '2027-01-15'),
    ]);

    // ---- A drag, start to finish (Choir) ----------------------------------
    $choir = smSeries('Choir', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 19:00', 60, false, ['2027-03-30 19:00']);
    $choirRows = [
        smEdited($choir, 2, '2027-04-13 19:00', ['title' => 'Rehearsal', 'location' => 'Sample Hall'], null, ['title', 'location']),
        smEdited($choir, 3, '2027-03-23 19:00', [], '2027-03-26 19:00', ['date']),
    ];
    $p = smPlan($choir, $choirRows, smDrag($choir, '2027-03-09 19:00', '2027-03-10 19:00'));
    checkEq('Choir dragged Tue 9 Mar to Wed 10 Mar: weekly on Wednesday from Wed 6 Jan, no end', [
        'FREQ=WEEKLY;BYDAY=WE', '2027-01-06T19:00:00-08:00', 'Weekly on Tuesday at 19:00', 'Weekly on Wednesday at 19:00', 'No end, as before',
    ], [$p['plan']['rule']['after'], $p['plan']['start'], $p['plan']['rule']['wordsBefore'], $p['plan']['rule']['words'], $p['plan']['bound']['words']]);
    checkEq('Choir: next is 10 Mar (the day acted on), 17 Mar, Fri 26 Mar (its own date), 7 Apr', [
        ['2027-03-10', 'acted'], ['2027-03-17', 'occurrence'], ['2027-03-26', 'edited'], ['2027-04-07', 'occurrence'],
    ], array_map(static fn(array $n): array => [substr($n['start'], 0, 10), $n['kind']], $p['plan']['next']));
    checkEq('Choir: one group, the day moved on its own, Keep Fri 26 Mar preselected', ['movedDays', 'keep', ['Keep Fri 26 Mar', 'Move to Sat 27 Mar']],
        [smGroup($p, 'movedDays')['id'] ?? null, smGroup($p, 'movedDays')['selected'] ?? null, array_map(static fn(array $o): string => $o['label'], smGroup($p, 'movedDays')['options'] ?? [])]);
    $byKey = [];
    foreach ($p['plan']['days'] as $d) {
        $byKey[(string) $d['key']] = $d;
    }
    checkEq('Choir: Rehearsal moves to Wed 14 Apr keeping its title and place; 23 Mar re-keyed to 24 Mar, staying on 26 Mar; the skip to 31 Mar', [
        ['2027-04-15T02:00:00Z', 'moves', "'Rehearsal' Tue 13 Apr moves to Wed 14 Apr, keeps its title and place"],
        ['2027-03-25T02:00:00Z', 'stays', 'Tue 23 Mar, moved to Fri 26 Mar: stays there'],
        ['2027-04-01T02:00:00Z', 'moves'],
    ], [
        [$byKey['2027-04-14T02:00:00Z']['newKey'] ?? null, $byKey['2027-04-14T02:00:00Z']['outcome'] ?? null, $byKey['2027-04-14T02:00:00Z']['words'] ?? null],
        [$byKey['2027-03-24T02:00:00Z']['newKey'] ?? null, $byKey['2027-03-24T02:00:00Z']['outcome'] ?? null, $byKey['2027-03-24T02:00:00Z']['words'] ?? null],
        [$byKey['2027-03-31T02:00:00Z']['newKey'] ?? null, $byKey['2027-03-31T02:00:00Z']['outcome'] ?? null],
    ]);
    checkEq('Choir: the writes (rule, start, skip, both rows re-keyed; 23 Mar still showing on Fri 26 Mar)', [
        'FREQ=WEEKLY;BYDAY=WE', '2027-01-07 03:00:00', '["2027-04-01 02:00:00"]', '2027-04-15 02:00:00', '2027-04-15 02:00:00', '2027-03-25 02:00:00', null,
    ], [
        $p['writes']['master']['rrule'] ?? null, $p['writes']['master']['start_utc'] ?? null, $p['writes']['master']['exdates_json'] ?? null,
        $p['writes']['overrides'][2]['recurrence_instance_utc'] ?? null, $p['writes']['overrides'][2]['start_utc'] ?? null,
        $p['writes']['overrides'][3]['recurrence_instance_utc'] ?? null, $p['writes']['overrides'][3]['start_utc'] ?? null,
    ]);
    $moved = smPlan($choir, $choirRows, smDrag($choir, '2027-03-09 19:00', '2027-03-10 19:00'), ['movedDays' => 'move']);
    checkEq('Choir with "Move to Sat 27 Mar": the 23 Mar row shows on Sat 27 Mar, three days after its occurrence', smAt('2027-03-27 19:00'),
        $moved['writes']['overrides'][3]['start_utc'] ?? null);
    checkEq('Choir: a preview and a commit with the same choices have one hash; another choice another', [true, false],
        [$p['plan']['hash'] === smPlan($choir, $choirRows, smDrag($choir, '2027-03-09 19:00', '2027-03-10 19:00'), ['movedDays' => 'keep'])['plan']['hash'], $p['plan']['hash'] === $moved['plan']['hash']]);
    checkEq('Choir: a commit naming every group is complete; one leaving a group out is not', [['movedDays'], []],
        [$p['missing'], smPlan($choir, $choirRows, smDrag($choir, '2027-03-09 19:00', '2027-03-10 19:00'), ['movedDays' => 'keep'])['missing']]);
    $touched = $choirRows;
    $touched[0]['updated_at'] = '2027-02-01 00:00:00';
    check('Choir: the hash changes when a row of the series changed since the preview',
        $p['plan']['hash'] !== smPlan($choir, $touched, smDrag($choir, '2027-03-09 19:00', '2027-03-10 19:00'))['plan']['hash']);
    check('Choir: a drag is never direct', $p['plan']['direct'] === false);

    // ---- A call without a plan: direct only for a field edit nobody owns ----
    $rename = smPlan($choir, $choirRows, ['columns' => ['title' => 'Chorus']]);
    checkEq('A rename of Choir where Rehearsal owns its title: not direct, a group to give it the new title', [false, ['keep', 'give'], 'keep'],
        [$rename['plan']['direct'], smOptions($rename, 'ownTitle'), smGroup($rename, 'ownTitle')['selected'] ?? null]);
    checkEq('the rename writes the master and the 23 Mar row (which inherits its title), not Rehearsal', [['title' => 'Chorus'], 'Chorus', null],
        [$rename['writes']['master'], $rename['writes']['overrides'][3]['title'] ?? null, $rename['writes']['overrides'][2]['title'] ?? null]);
    $given = smPlan($choir, $choirRows, ['columns' => ['title' => 'Chorus']], ['ownTitle' => 'give']);
    checkEq('"Give it the new title too": Rehearsal takes Chorus and its title is no longer its own', ['Chorus', '["location"]'],
        [$given['writes']['overrides'][2]['title'] ?? null, $given['writes']['overrides'][2]['override_fields'] ?? null]);
    $plain = smPlan($choir, [$choirRows[1]], ['columns' => ['title' => 'Chorus']]);
    checkEq('the same rename with no edited day owning its title is direct', [true, []], [$plain['plan']['direct'], $plain['plan']['groups']]);
    checkEq('an editor save sending the same rule back is no rule change', [true, ['title' => 'Chorus']],
        [smPlan($choir, [$choirRows[1]], ['columns' => ['title' => 'Chorus'], 'rrule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU'])['plan']['direct'],
         smPlan($choir, [$choirRows[1]], ['columns' => ['title' => 'Chorus'], 'rrule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU'])['writes']['master']]);

    // ---- Month ends --------------------------------------------------------
    $book = smSeries('Book club', 'FREQ=MONTHLY;BYMONTHDAY=15', '2027-01-15 19:00');
    $p = smPlan($book, [], smDrag($book, '2027-03-15 19:00', '2027-03-30 19:00'));
    checkEq('Book club 15 Mar to 30 Mar: clamped (preselected) and skipping', [['clamp', 'skip'], 'clamp', 'FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1'],
        [smOptions($p, 'reading'), smGroup($p, 'reading')['selected'] ?? null, $p['writes']['master']['rrule'] ?? null]);
    $labels = array_map(static fn(array $o): string => $o['label'], smGroup($p, 'reading')['options'] ?? []);
    checkEq('Book club: the readings\' words', ['On the 30th, or the last day in shorter months', 'On the 30th, skipping months without one'], $labels);
    $skip = smPlan($book, [], smDrag($book, '2027-03-15 19:00', '2027-03-30 19:00'), ['reading' => 'skip']);
    checkEq('Book club, skipping: BYMONTHDAY=30, and 15 Feb\'s event has no place', ['FREQ=MONTHLY;BYMONTHDAY=30', true],
        [$skip['writes']['master']['rrule'] ?? null, in_array('2027-02-15', array_column($skip['plan']['losses'], 'date'), true)]);
    $p = smPlan($book, [], smDrag($book, '2027-03-15 19:00', '2027-03-31 19:00'));
    checkEq('Book club 15 Mar to 31 Mar: the last day (clamped 31st merged into it) and the skipping 31st', [['last', 'skip'], 'FREQ=MONTHLY;BYMONTHDAY=-1'],
        [smOptions($p, 'reading'), $p['writes']['master']['rrule'] ?? null]);
    $p = smPlan($book, [], smDrag($book, '2027-04-15 19:00', '2027-04-30 19:00'));
    checkEq('Book club 15 Apr to 30 Apr (April\'s last day): all three', ['clamp', 'skip', 'last'], smOptions($p, 'reading'));
    $rent = smSeries('Rent due', 'FREQ=MONTHLY;BYMONTHDAY=-1', '2027-01-31', 0, true);
    $p = smPlan($rent, [], smDrag($rent, '2027-03-31', '2027-03-30'));
    checkEq('Rent due 31 Mar to 30 Mar: the second-last day first, then the clamped and skipping 30th', [['fromEnd', 'clamp', 'skip'], 'FREQ=MONTHLY;BYMONTHDAY=-2'],
        [smOptions($p, 'reading'), $p['writes']['master']['rrule'] ?? null]);
    $p = smPlan($rent, [], smDrag($rent, '2027-03-31', '2027-03-05'));
    checkEq('Rent due dragged to 5 Mar (the 27th-last day): one reading, every 5th', [[], 'FREQ=MONTHLY;BYMONTHDAY=5'], [smOptions($p, 'reading'), $p['writes']['master']['rrule'] ?? null]);
    $salon = smSeries('Salon night', 'FREQ=MONTHLY;BYMONTHDAY=31', '2027-01-31 19:00');
    $p = smPlan($salon, [], smDrag($salon, '2027-01-31 19:00', '2027-02-01 19:00'));
    checkEq('Salon night (every 31st) dragged to 1 Feb: every 1st, naming the gains 1 Mar, 1 May, 1 Jul, 1 Oct and 1 Dec', ['FREQ=MONTHLY;BYMONTHDAY=1', ['2027-03-01', '2027-05-01', '2027-07-01', '2027-10-01', '2027-12-01']],
        [$p['writes']['master']['rrule'] ?? null, array_slice(array_column($p['plan']['gains'], 'date'), 0, 5)]);
    $leap = smSeries('Leap party', 'FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=28', '2028-02-28', 0, true);
    $p = smPlan($leap, [], smDrag($leap, '2028-02-28', '2028-02-29'));
    checkEq('Leap party to 29 Feb 2028: the last day of February (preselected) or only in leap years', [['lastFeb', 'leap'], 'FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=-1'],
        [smOptions($p, 'reading'), $p['writes']['master']['rrule'] ?? null]);
    $swap = smSeries('Book swap', 'FREQ=MONTHLY;BYMONTHDAY=15;COUNT=3', '2027-03-15 19:00');
    $p = smPlan($swap, [], smDrag($swap, '2027-03-15 19:00', '2027-03-30 19:00'));
    checkEq('Book swap (3 events): the clamped and skipping 30th give the same 3 dates, so both are offered', [['clamp', 'skip'], ['2027-03-30', '2027-04-30', '2027-05-30']],
        [smOptions($p, 'reading'), array_map(static fn(array $e): string => substr($e['start'], 0, 10), smGroup($p, 'reading')['options'][1]['next'] ?? [])]);

    // ---- A count -----------------------------------------------------------
    $piano = smSeries('Piano lesson with Alex Example', 'FREQ=MONTHLY;BYMONTHDAY=31;COUNT=6', '2027-01-31 16:00');
    $recital = [smEdited($piano, 2, '2027-10-31 16:00', ['title' => 'Recital at Sample Hall'], null, ['title'])];
    $p = smPlan($piano, $recital, smDrag($piano, '2027-03-31 16:00', '2027-04-01 16:00'));
    checkEq('Piano lesson 31 Mar to 1 Apr: keep 6 (preselected) or keep the end; the recital outside under keep 6', [['count', 'end'], 'count', 'FREQ=MONTHLY;BYMONTHDAY=1;COUNT=6', ['keep', 'delete'], [2]],
        [smOptions($p, 'bound'), smGroup($p, 'bound')['selected'] ?? null, $p['writes']['master']['rrule'] ?? null, smOptions($p, 'outside'), array_keys($p['writes']['detach'])]);
    check('Piano lesson: the bound words say 6 in the series + 1 separate', str_contains($p['plan']['bound']['words'], '6 in the series + 1 separate'), $p['plan']['bound']['words']);
    $end = smPlan($piano, $recital, smDrag($piano, '2027-03-31 16:00', '2027-04-01 16:00'), ['bound' => 'end']);
    checkEq('Piano lesson, keep the end: COUNT=10, the recital moving to 1 Nov with its event', ['FREQ=MONTHLY;BYMONTHDAY=1;COUNT=10', smAt('2027-11-01 16:00'), []],
        [$end['writes']['master']['rrule'] ?? null, $end['writes']['overrides'][2]['recurrence_instance_utc'] ?? null, smOptions($end, 'outside')]);
    $del = smPlan($piano, $recital, smDrag($piano, '2027-03-31 16:00', '2027-04-01 16:00'), ['bound' => 'count', 'outside' => 'delete']);
    checkEq('Piano lesson, keep 6 and delete it: the recital deleted', [[2], []], [$del['writes']['delete'], $del['writes']['detach']]);

    // Acceptance 1: the destination stays.
    $cello = smSeries('Cello lesson', 'FREQ=MONTHLY;BYMONTHDAY=31;COUNT=6', '2027-01-31 16:00');
    $p = smPlan($cello, [], smDrag($cello, '2027-10-31 16:00', '2027-11-01 16:00'));
    checkEq('Acceptance 1 (Cello lesson 31 Oct to 1 Nov): keep the end (preselected, first, nothing split off), or keep 6 + this one on its own', [
        ['end', 'count'], 'end', ['Keep the end', 'Keep 6 events + this one on its own'], 'FREQ=MONTHLY;BYMONTHDAY=1;COUNT=10',
    ], [smOptions($p, 'bound'), smGroup($p, 'bound')['selected'] ?? null, array_map(static fn(array $o): string => $o['label'], smGroup($p, 'bound')['options'] ?? []), $p['writes']['master']['rrule'] ?? null]);
    $six = smPlan($cello, [], smDrag($cello, '2027-10-31 16:00', '2027-11-01 16:00'), ['bound' => 'count']);
    checkEq('Acceptance 1, keep 6 + this one: COUNT=6 from 1 Feb, and an event of its own on Mon 1 Nov at 16:00', [
        'FREQ=MONTHLY;BYMONTHDAY=1;COUNT=6', smAt('2027-02-01 16:00'), [['start_utc' => smAt('2027-11-01 16:00'), 'end_utc' => smAt('2027-11-01 17:00'), 'all_day' => 0, 'tzid' => 'America/Los_Angeles']],
        ['2027-02-01', '2027-03-01', '2027-04-01', '2027-05-01', '2027-06-01', '2027-07-01'],
    ], [$six['writes']['master']['rrule'] ?? null, $six['writes']['master']['start_utc'] ?? null, $six['writes']['create'], smShows($cello, $six['writes'], '2027-12-31')]);
    check('Acceptance 1: no "Delete it" for the dragged day', smOptions($six, 'outside') === []);

    // Acceptance 2: an edge gain left out.
    $pottery = smSeries('Pottery', 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=5', '2027-01-06 18:00');
    $p = smPlan($pottery, [], smDrag($pottery, '2027-01-11 18:00', '2027-01-15 18:00'));
    checkEq('Acceptance 2 (Pottery Mon 11 Jan to Fri 15 Jan): move every Monday (preselected); leaving the edge gain Fri 8 Jan out is preselected, the bound group merges', [
        ['member', 'pattern'], ['without', 'add'], 'without', [], 'FREQ=WEEKLY;BYDAY=WE,FR;COUNT=6', json_encode([smAt('2027-01-08 18:00')]), ['2027-01-06', '2027-01-13', '2027-01-15', '2027-01-20', '2027-01-22'],
    ], [smOptions($p, 'reading'), smOptions($p, 'edge'), smGroup($p, 'edge')['selected'] ?? null, smOptions($p, 'bound'), $p['writes']['master']['rrule'] ?? null, $p['writes']['master']['exdates_json'] ?? null, smShows($pottery, $p['writes'], '2027-02-28')]);
    $p = smPlan($pottery, [], smDrag($pottery, '2027-01-11 18:00', '2027-01-15 18:00'), ['edge' => 'add']);
    checkEq('Acceptance 2, add it: keep 5 (preselected) or the end; keep 5 is 6, 8, 13, 15, 20 Jan', [['count', 'end'], 'FREQ=WEEKLY;BYDAY=WE,FR;COUNT=5', ['2027-01-06', '2027-01-08', '2027-01-13', '2027-01-15', '2027-01-20']],
        [smOptions($p, 'bound'), $p['writes']['master']['rrule'] ?? null, smShows($pottery, $p['writes'], '2027-02-28')]);
    $p = smPlan($pottery, [], smDrag($pottery, '2027-01-11 18:00', '2027-01-15 18:00'), ['edge' => 'add', 'bound' => 'end']);
    checkEq('Acceptance 2, add and keep the end: COUNT=6, 6 to 22 Jan', ['FREQ=WEEKLY;BYDAY=WE,FR;COUNT=6', ['2027-01-06', '2027-01-08', '2027-01-13', '2027-01-15', '2027-01-20', '2027-01-22']],
        [$p['writes']['master']['rrule'] ?? null, smShows($pottery, $p['writes'], '2027-02-28')]);
    $without = smPlan($pottery, [], smDrag($pottery, '2027-01-11 18:00', '2027-01-15 18:00'), ['edge' => 'without']);
    checkEq('Acceptance 2, without it: the series keeps its five sessions, and the line says so (the generated skip is no event of it)', '5 events, ends 22 Jan (was 20 Jan)', $without['plan']['bound']['words']);
    $pottery13 = smSeries('Pottery', 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=5', '2027-01-06 18:00', 60, false, ['2027-01-13 18:00']);
    $p = smPlan($pottery13, [], smDrag($pottery13, '2027-01-11 18:00', '2027-01-15 18:00'), ['edge' => 'without', 'bound' => 'count']);
    checkEq('Pottery with 13 Jan already skipped, without 8 Jan: COUNT=6 skipping 8 and 13 Jan, four sessions (a skip the person made is not made up for)', [
        'FREQ=WEEKLY;BYDAY=WE,FR;COUNT=6', json_encode([smAt('2027-01-08 18:00'), smAt('2027-01-13 18:00')]), ['2027-01-06', '2027-01-15', '2027-01-20', '2027-01-22'],
    ], [$p['writes']['master']['rrule'] ?? null, $p['writes']['master']['exdates_json'] ?? null, smShows($pottery13, $p['writes'], '2027-02-28')]);
    // Afterwards: from the "without it" series, every Friday dragged to Thursday maps the skip by its member.
    $after = array_merge($pottery, $without['writes']['master']);
    $p = smPlan($after, [], smDrag($after, '2027-01-15 18:00', '2027-01-14 18:00'));
    checkEq('Acceptance 2, then every Friday to Thursday: the skip goes to Thu 7 Jan', ['FREQ=WEEKLY;BYDAY=WE,TH;COUNT=6', json_encode([smAt('2027-01-07 18:00')]), ['2027-01-06', '2027-01-13', '2027-01-14', '2027-01-20', '2027-01-21']],
        [$p['writes']['master']['rrule'] ?? null, $p['writes']['master']['exdates_json'] ?? null, smShows($after, $p['writes'], '2027-02-28')]);

    // Acceptance 3: one member, whole weeks.
    $relay = smSeries('Swim relay', 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=3', '2027-01-04 07:00');
    $trial = [smEdited($relay, 2, '2027-01-06 07:00', ['title' => 'Relay trial'], null, ['title'])];
    $p = smPlan($relay, $trial, smDrag($relay, '2027-01-11 07:00', '2027-01-18 07:00'));
    checkEq('Acceptance 3 (Swim relay Mon 11 Jan to Mon 18 Jan): two readings, never merged; the member reading starts Wed 6 Jan', [
        ['member', 'pattern'], 'member', smAt('2027-01-06 07:00'),
    ], [smOptions($p, 'reading'), smGroup($p, 'reading')['selected'] ?? null, $p['writes']['master']['start_utc'] ?? null]);
    $rows = [
        'add, keep 3 + this one' => ['edge' => 'add', 'bound' => 'count'],
        'add, keep the end' => ['edge' => 'add', 'bound' => 'end'],
        'without' => ['edge' => 'without'],
        'pattern' => ['reading' => 'pattern'],
    ];
    $got = [];
    foreach ($rows as $name => $c) {
        $x = smPlan($relay, $trial, smDrag($relay, '2027-01-11 07:00', '2027-01-18 07:00'), $c);
        $got[$name] = [$x['writes']['master']['rrule'] ?? $relay['rrule'], smShows($relay, $x['writes'], '2027-02-28'), count($x['writes']['create']), $x['writes']['overrides'][2]['start_utc'] ?? 'stays'];
    }
    checkEq('Acceptance 3: what each combination writes and shows', [
        'add, keep 3 + this one' => ['FREQ=WEEKLY;BYDAY=MO,WE;COUNT=3', ['2027-01-06', '2027-01-11', '2027-01-13'], 1, 'stays'],
        'add, keep the end' => ['FREQ=WEEKLY;BYDAY=MO,WE;COUNT=4', ['2027-01-06', '2027-01-11', '2027-01-13', '2027-01-18'], 0, 'stays'],
        'without' => ['FREQ=WEEKLY;BYDAY=MO,WE;COUNT=4', ['2027-01-06', '2027-01-11', '2027-01-18'], 0, 'stays'],
        'pattern' => ['FREQ=WEEKLY;BYDAY=MO,WE;COUNT=3', ['2027-01-11', '2027-01-13', '2027-01-18'], 0, smAt('2027-01-13 07:00')],
    ], $got);
    $third = smSeries('Swim', 'FREQ=WEEKLY;INTERVAL=3;BYDAY=MO,WE', '2027-01-04 07:00');
    $p = smPlan($third, [], smDrag($third, '2027-01-25 07:00', '2027-02-08 07:00'));
    checkEq('Acceptance 3: every third week, Mondays two weeks later is disabled; no week start holds them', ['member!', 'pattern'], smOptions($p, 'reading'));

    // Acceptance 4: still supported after saving.
    $circle = smSeries('Reading circle', 'FREQ=MONTHLY;BYDAY=1MO,3MO', '2027-01-04 19:00');
    $p = smPlan($circle, [], smDrag($circle, '2027-03-15 19:00', '2027-03-16 19:00'));
    checkEq('Acceptance 4, step 1: every third Monday to the third Tuesday', ['FREQ=MONTHLY;BYDAY=1MO,3TU', ['member', 'pattern']], [$p['writes']['master']['rrule'] ?? null, smOptions($p, 'reading')]);
    $saved = array_merge($circle, $p['writes']['master']);
    checkEq('Acceptance 4, step 2: reloaded, it is the nth-list row', 'monthly', Rule::parse((string) $saved['rrule'], Cal::dn(2027, 1, 4))->kind);
    $p2 = smPlan($saved, [], smDrag($saved, '2027-04-05 19:00', '2027-04-07 19:00'));
    checkEq('Acceptance 4, step 3: offered as the first drag was, first Monday to first Wednesday', [['member', 'pattern'], 'FREQ=MONTHLY;BYDAY=1WE,3TU', smAt('2027-01-06 19:00')],
        [smOptions($p2, 'reading'), $p2['writes']['master']['rrule'] ?? null, $p2['writes']['master']['start_utc'] ?? null]);
    $p3 = smPlan($saved, [], smDrag($saved, '2027-04-05 19:00', '2027-04-07 19:00'), ['reading' => 'pattern']);
    checkEq('Acceptance 4: shifting the whole pattern keeps each weekday\'s offset: the first Wednesday and third Thursday', 'FREQ=MONTHLY;BYDAY=1WE,3TH', $p3['writes']['master']['rrule'] ?? null);

    // Acceptance 5: Google before the probe.
    $practice = smSeries('Practice', 'FREQ=WEEKLY;BYDAY=TH', '2027-01-07 18:00');
    $practiceRows = [smEdited($practice, 2, '2027-03-04 18:00', [], '2027-03-05 18:00', ['date'])];
    $g = smPlan($practice, $practiceRows, smDrag($practice, '2027-03-11 18:00', '2027-03-11 18:30'), [], ['google' => true]);
    checkEq('Acceptance 5: 18:30 for all events on Google is offered, notes Google, claims nothing for Fri 5 Mar', [[], ['google'], [], smAt('2027-01-07 18:30')],
        [$g['plan']['refusals'], array_column($g['plan']['notes'], 'kind'), $g['plan']['days'], $g['writes']['master']['start_utc'] ?? null]);
    $g = smPlan($practice, $practiceRows, ['rrule' => 'FREQ=WEEKLY;BYDAY=TH;UNTIL=20270520T235959Z'], [], ['google' => true]);
    checkEq('Acceptance 5: ends changed on Google is offered with the same note', [[], ['google']], [$g['plan']['refusals'], array_column($g['plan']['notes'], 'kind')]);
    $g = smPlan($practice, $practiceRows, smDrag($practice, '2027-03-11 18:00', '2027-03-12 18:00'), [], ['google' => true]);
    checkEq('Acceptance 5: a drag to Friday on Google is unavailable, with the Google reason', [true, Model::REASONS['google']],
        [$g['refused'], $g['plan']['refusals'][0]['words'] ?? null]);

    // Acceptance 6: a rule the expander can't read.
    $holiday = smSeries('Holiday dinner', 'RSCALE=HEBREW;FREQ=YEARLY;BYMONTH=5L', '2027-02-10 18:00');
    $h = smPlan($holiday, [], ['columns' => ['title' => 'Holiday supper']]);
    checkEq('Acceptance 6: renamed for all events, applied and direct', [true, ['title' => 'Holiday supper']], [$h['plan']['direct'], $h['writes']['master']]);
    $h = smPlan($holiday, [], ['rrule' => 'RSCALE=HEBREW;FREQ=YEARLY;BYMONTH=5L;COUNT=3']);
    checkEq('Acceptance 6: ends after 3 refused with the reason', [true, Model::REASONS['unreadable']], [$h['refused'], $h['plan']['refusals'][0]['words'] ?? null]);
    // Its bound is read from its text all the same: a COUNT or an UNTIL in
    // a rule the expander can't read says it ends, so nothing more comes.
    $h = smPlan($holiday, [], ['columns' => ['title' => 'Holiday supper']]);
    checkEq('Acceptance 6: with no end, its bound is none and more comes after the empty list', ['none', [], true], [$h['plan']['bound']['kind'], $h['plan']['next'], $h['plan']['nextMore']]);
    foreach (['COUNT=6' => ['count', 'count', 6], 'UNTIL=20300101T000000Z' => ['until', 'until', '20300101T000000Z']] as $part => [$kind, $field, $value]) {
        $bounded = smSeries('Holiday dinner', 'RSCALE=HEBREW;FREQ=YEARLY;BYMONTH=5L;' . $part, '2027-02-10 18:00');
        $h = smPlan($bounded, [], ['columns' => ['title' => 'Holiday supper']]);
        checkEq("Acceptance 6: with $part, its bound is $kind before and after, and nothing more comes", [$kind, $value, $kind, $value, [], false, true, $bounded['rrule']],
            [$h['plan']['bound']['kind'], $h['plan']['bound'][$field] ?? null, $h['plan']['bound']['from']['kind'], $h['plan']['bound']['from'][$field] ?? null,
                $h['plan']['next'], $h['plan']['nextMore'], $h['plan']['direct'], $h['writes']['master']['rrule'] ?? $bounded['rrule']]);
    }

    // Acceptance 8 to 13, in a scope of their own.
    (static function (): void {
        // Acceptance 8: a skip belongs to the occurrence it removed. Pottery
        // class, 8 Mondays from 4 Jan, sessions 1 and 2 removed, the start
        // moved to session 3's date (a request naming no day): the days stay
        // where they are, the two removed days before the new start go with
        // their occurrences, and 8 sessions show from 18 Jan.
        $course = smSeries('Pottery class', 'FREQ=WEEKLY;BYDAY=MO;COUNT=8', '2027-01-04 18:00', 120, false, ['2027-01-04 18:00', '2027-01-11 18:00']);
        $startEdit = ['landed' => ['start_utc' => smAt('2027-01-18 18:00'), 'end_utc' => smAt('2027-01-18 20:00'), 'all_day' => 0, 'tzid' => 'America/Los_Angeles'], 'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=8'];
        $p = smPlan($course, [], $startEdit);
        checkEq('Acceptance 8 (Pottery class, the start moved to session 3): no skips remain, COUNT=8 from 18 Jan, 8 sessions', [
            null, smAt('2027-01-18 18:00'), null,
            ['2027-01-18', '2027-01-25', '2027-02-01', '2027-02-08', '2027-02-15', '2027-02-22', '2027-03-01', '2027-03-08'],
        ], [$p['writes']['master']['rrule'] ?? null, $p['writes']['master']['start_utc'] ?? null, array_key_exists('exdates_json', $p['writes']['master']) ? $p['writes']['master']['exdates_json'] : 'kept', smShows($course, $p['writes'], '2027-04-30')]);
        checkEq('Acceptance 8: the two removed days are dropped before the new start, no loss named, none counted, the count kept (preselected)', [
            [['2027-01-04', 'dropped', 'outside', 'start'], ['2027-01-11', 'dropped', 'outside', 'start']], [], 0, ['count', 'end'], 'count', 8,
        ], [array_map(static fn(array $d): array => [$d['shows'], $d['outcome'], $d['why'], $d['side'] ?? null], $p['plan']['days']), $p['plan']['losses'],
            $p['plan']['counts']['skipped'], smOptions($p, 'bound'), smGroup($p, 'bound')['selected'] ?? null, $p['plan']['bound']['events']]);
        checkEq('Acceptance 8: the start moved alone, said as a start', ['Moved Pottery class (all events): starts 18 Jan (was 4 Jan), weekly on Monday at 18:00, as before.', '2027-01-18'],
            [$p['plan']['summary'], substr($p['plan']['rule']['to']['start'], 0, 10)]);
        // The same start reached by dragging the first session shown (a day
        // named) is a drag: that day moves, and the skips go with theirs.
        $dragged = smPlan($course, [], smDrag($course, '2027-01-18 18:00', '2027-02-01 18:00'));
        checkEq('Acceptance 8: a drag of a day named still moves every occurrence, its skips with them', ['moves', 'moves'], array_column($dragged['plan']['days'], 'outcome'));
        // A start put on a day the pattern doesn't give changes the pattern: the first occurrence moves there.
        $tue = smPlan($course, [], ['landed' => ['start_utc' => smAt('2027-01-19 18:00'), 'end_utc' => smAt('2027-01-19 20:00'), 'all_day' => 0, 'tzid' => 'America/Los_Angeles']]);
        checkEq('Acceptance 8: a start on a Tuesday is the first occurrence dragged there', 'FREQ=WEEKLY;BYDAY=TU;COUNT=8', $tue['writes']['master']['rrule'] ?? null);
        // An edited day before a moved start is outside it, with the usual choice.
        $early = smEdited($course, 11, '2027-01-04 18:00', ['title' => 'Pottery, intro'], null, ['title']);
        $q = smPlan(smSeries('Pottery class', 'FREQ=WEEKLY;BYDAY=MO;COUNT=8', '2027-01-04 18:00', 120, false, ['2027-01-11 18:00']), [$early], $startEdit);
        checkEq('Acceptance 8: an edited day before the new start gets the outside choice, kept on its own (preselected)', [['outside', 'keep'], 'separate'],
            [[smGroup($q, 'outside')['id'] ?? null, smGroup($q, 'outside')['selected'] ?? null], array_values(array_filter($q['plan']['days'], static fn(array $d): bool => $d['kind'] === 'edited'))[0]['outcome'] ?? null]);

        // Acceptance 9: a zone change, both readings, every day's local time.
        // Team call, weekly Monday 09:00 Los Angeles from 4 Jan; 18 Jan at its
        // own 11:00, 15 Mar at its own 07:30, 1 Feb with its own title; moved to
        // Berlin time from Mon 11 Jan. Los Angeles changes its clocks on 14 Mar,
        // Berlin on 28 Mar.
        $call = smSeries('Team call', 'FREQ=WEEKLY;BYDAY=MO', '2027-01-04 09:00', 60);
        $callRows = [
            smEdited($call, 11, '2027-01-18 09:00', [], '2027-01-18 11:00', ['time']),
            smEdited($call, 12, '2027-03-15 09:00', [], '2027-03-15 07:30', ['time']),
            smEdited($call, 13, '2027-02-01 09:00', ['title' => 'Team call, planning'], null, ['title']),
        ];
        $toBerlin = ['acted' => smAt('2027-01-11 09:00'), 'landed' => ['start_utc' => smAt('2027-01-11 09:00', 'Europe/Berlin'), 'end_utc' => smAt('2027-01-11 10:00', 'Europe/Berlin'), 'all_day' => 0, 'tzid' => 'Europe/Berlin']];
        $clock = smPlan($call, $callRows, $toBerlin);
        $moment = smPlan($call, $callRows, $toBerlin, ['zone' => 'moment']);
        $at = static fn(string $utc, string $tz): string => Time::fromDb($utc)->setTimezone(Time::zone($tz))->format('D H:i');
        checkEq('Acceptance 9: the zone group, the clock time first and preselected, each reading in words', [
            ['clock', 'moment'], 'clock',
            'every Monday at 09:00 Berlin time; 00:00 in Los Angeles, 01:00 on 15 and 22 Mar and 1 Nov',
            'every Monday at 18:00 Berlin time; 09:00 in Los Angeles, 10:00 on 15 and 22 Mar and 1 Nov',
        ], [smOptions($clock, 'zone'), smGroup($clock, 'zone')['selected'] ?? null, smGroup($clock, 'zone')['options'][0]['words'] ?? null, smGroup($clock, 'zone')['options'][1]['words'] ?? null]);
        checkEq('Acceptance 9, the clock time: the series at 09:00 Berlin; the own times stay in Los Angeles (listed in the plan\'s zone, Berlin); the own-title day takes 09:00 Berlin', [
            'Europe/Berlin', ['2027-01-11T09:00:00+01:00', '2027-01-18T20:00:00+01:00', '2027-01-25T09:00:00+01:00', '2027-02-01T09:00:00+01:00'],
            smAt('2027-01-18 11:00'), smAt('2027-03-15 07:30'), [smAt('2027-02-01 09:00', 'Europe/Berlin'), 'Europe/Berlin'],
            'Mon 01:00', ['stays', 'stays', 'moves'],
        ], [$clock['writes']['master']['tzid'] ?? null, array_column($clock['plan']['next'], 'start'),
            $clock['writes']['overrides'][11]['start_utc'] ?? $callRows[0]['start_utc'],
            $clock['writes']['overrides'][12]['start_utc'] ?? $callRows[1]['start_utc'],
            [$clock['writes']['overrides'][13]['start_utc'] ?? null, $clock['writes']['overrides'][13]['tzid'] ?? null],
            $at(smAt('2027-03-22 09:00', 'Europe/Berlin'), 'America/Los_Angeles'), array_column($clock['plan']['days'], 'outcome')]);
        checkEq('Acceptance 9, the same moment: the series at 18:00 Berlin (09:00 in Los Angeles, 10:00 between the two clock changes); the own times stay', [
            'Europe/Berlin', null, ['2027-01-11T18:00:00+01:00', '2027-01-18T20:00:00+01:00', '2027-01-25T18:00:00+01:00', '2027-02-01T18:00:00+01:00'],
            'Mon 10:00', 'Mon 09:00', $callRows[0]['start_utc'], $callRows[1]['start_utc'], 'Europe/Berlin',
        ], [$moment['writes']['master']['tzid'] ?? null, $moment['writes']['master']['start_utc'] ?? null, array_column($moment['plan']['next'], 'start'),
            $at(smAt('2027-03-22 18:00', 'Europe/Berlin'), 'America/Los_Angeles'), $at(smAt('2027-04-05 18:00', 'Europe/Berlin'), 'America/Los_Angeles'),
            $moment['writes']['overrides'][11]['start_utc'] ?? $callRows[0]['start_utc'], $moment['writes']['overrides'][12]['start_utc'] ?? $callRows[1]['start_utc'],
            $moment['writes']['overrides'][13]['tzid'] ?? null]);

        // Acceptance 10: a time change outside Google. Study group, weekly
        // Tuesday 19:00 to 20:00 from 5 Jan; 19 Jan at its own 13:00, 2 Feb at
        // its own place. Moved to 20:00 from 12 Jan, all events.
        $study = smSeries('Study group', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 19:00', 60);
        $studyRows = [
            smEdited($study, 11, '2027-01-19 19:00', [], '2027-01-19 13:00', ['time']),
            smEdited($study, 12, '2027-02-02 19:00', ['location' => 'Sample Library'], null, ['location']),
        ];
        $later = ['acted' => smAt('2027-01-12 19:00'), 'landed' => ['start_utc' => smAt('2027-01-12 20:00'), 'end_utc' => smAt('2027-01-12 21:00'), 'all_day' => 0, 'tzid' => 'America/Los_Angeles']];
        $p = smPlan($study, $studyRows, $later);
        checkEq('Acceptance 10 (Study group to 20:00): no choice; 13:00 kept and said; the own-place day moves to 20:00', [
            [], ['2027-01-12T20:00:00-08:00', '2027-01-19T13:00:00-08:00', '2027-01-26T20:00:00-08:00', '2027-02-02T20:00:00-08:00'],
            ['stays', ['time'], [], 'Tue 19 Jan stays, keeps its own time at 13:00'], ['moves', ['location'], ['time'], smAt('2027-02-02 20:00')],
            smAt('2027-01-05 20:00'),
        ], [$p['plan']['groups'], array_column($p['plan']['next'], 'start'),
            [$p['plan']['days'][0]['outcome'], $p['plan']['days'][0]['kept'], $p['plan']['days'][0]['takes'], $p['plan']['days'][0]['words']],
            [$p['plan']['days'][1]['outcome'], $p['plan']['days'][1]['kept'], $p['plan']['days'][1]['takes'], $p['writes']['overrides'][12]['start_utc'] ?? null],
            $p['writes']['master']['start_utc'] ?? null]);
        check('Acceptance 10: the own-time day\'s row keeps its 13:00 (only its key moves)', !isset($p['writes']['overrides'][11]['start_utc']) && ($p['writes']['overrides'][11]['recurrence_instance_utc'] ?? null) === smAt('2027-01-19 20:00'));

        // Acceptance 11: a day moved on its own, then the series moved. Pottery,
        // 8 Mondays from 4 Jan, its 18 Jan moved to Fri 22 Jan; Mon 11 Jan
        // dragged to Wed 13 Jan, all events. Each occurrence shows once: the
        // moved day's key goes to Wed 20 Jan, and Wed 20 Jan shows only as that
        // row, on Fri 22 Jan (kept) or Sun 24 Jan (moved with the rest); the
        // harness's invariant h checks one row per key, every key a raw day.
        $wheel = smSeries('Pottery', 'FREQ=WEEKLY;BYDAY=MO;COUNT=8', '2027-01-04 18:00', 120);
        $moved = smEdited($wheel, 11, '2027-01-18 18:00', [], '2027-01-22 18:00', ['date']);
        $keep = smPlan($wheel, [$moved], smDrag($wheel, '2027-01-11 18:00', '2027-01-13 18:00'));
        $move = smPlan($wheel, [$moved], smDrag($wheel, '2027-01-11 18:00', '2027-01-13 18:00'), ['movedDays' => 'move']);
        checkEq('Acceptance 11 (Pottery Mon to Wed, a day on Fri 22 Jan): keep it there (preselected) or move it to Sun 24 Jan', [
            ['keep', 'move'], 'keep', ['Keep Fri 22 Jan', 'Move to Sun 24 Jan'], 'FREQ=WEEKLY;BYDAY=WE;COUNT=8',
        ], [smOptions($keep, 'movedDays'), smGroup($keep, 'movedDays')['selected'] ?? null, array_column(smGroup($keep, 'movedDays')['options'] ?? [], 'label'), $keep['writes']['master']['rrule'] ?? null]);
        checkEq('Acceptance 11: kept, the row keyed Wed 20 Jan shows on Fri 22 Jan; moved, on Sun 24 Jan; Wed 20 Jan never shows as well', [
            [smAt('2027-01-20 18:00'), null], ['2027-01-13', '2027-01-22', '2027-01-27', '2027-02-03'],
            [smAt('2027-01-20 18:00'), smAt('2027-01-24 18:00')], ['2027-01-13', '2027-01-24', '2027-01-27', '2027-02-03'],
        ], [[$keep['writes']['overrides'][11]['recurrence_instance_utc'] ?? null, $keep['writes']['overrides'][11]['start_utc'] ?? null], smNext($keep),
            [$move['writes']['overrides'][11]['recurrence_instance_utc'] ?? null, $move['writes']['overrides'][11]['start_utc'] ?? null], smNext($move)]);

        // Acceptance 12: all events from an edited day. Choir, weekly Tuesday
        // 19:00 from 5 Jan; 9 Mar 'Choir, sectionals' at its own 18:00, 23 Mar
        // 'Choir, concert', 6 Apr at its own place. From 9 Mar's own row:
        // renamed 'Chorus' and moved to Wed 10 Mar 18:00. Every delta is from
        // where 9 Mar shows: a day later, no clock change, the title from its own.
        $choir = smSeries('Choir', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 19:00', 60);
        $choirRows = [
            smEdited($choir, 11, '2027-03-09 19:00', ['title' => 'Choir, sectionals'], '2027-03-09 18:00', ['title', 'time']),
            smEdited($choir, 12, '2027-03-23 19:00', ['title' => 'Choir, concert'], null, ['title']),
            smEdited($choir, 13, '2027-04-06 19:00', ['location' => 'Sample Hall'], null, ['location']),
        ];
        $p = smPlan($choir, $choirRows, ['acted' => smAt('2027-03-09 19:00'), 'landed' => ['start_utc' => smAt('2027-03-10 18:00'), 'end_utc' => smAt('2027-03-10 19:00'), 'all_day' => 0, 'tzid' => 'America/Los_Angeles'], 'columns' => ['title' => 'Chorus']]);
        checkEq('Acceptance 12 (Choir from its 9 Mar): weekly on Wednesday at 19:00, renamed; started from \'Choir, sectionals\' on 9 Mar; one choice, the concert\'s own title', [
            ['rrule' => 'FREQ=WEEKLY;BYDAY=WE', 'start_utc' => smAt('2027-01-06 19:00'), 'end_utc' => smAt('2027-01-06 20:00'), 'title' => 'Chorus'],
            ['key' => '2027-03-10T03:00:00Z', 'title' => 'Choir, sectionals', 'day' => '2027-03-09', 'before' => ['date' => '2027-03-09', 'at' => '2027-03-09T18:00:00-08:00'], 'shows' => '2027-03-09', 'kept' => ['time', 'title']],
            ['ownTitle'], 'keep',
        ], [$p['writes']['master'], $p['plan']['acted'], array_column($p['plan']['groups'], 'id'), smGroup($p, 'ownTitle')['selected'] ?? null]);
        checkEq('Acceptance 12: 9 Mar lands at Wed 10 Mar 18:00 as Chorus, its time still its own; the concert keeps its title on Wed 24 Mar; 6 Apr moves to Wed 7 Apr and takes Chorus', [
            [smAt('2027-03-10 19:00'), smAt('2027-03-10 18:00'), 'Chorus', '["time"]'], [smAt('2027-03-24 19:00'), null],
            [smAt('2027-04-07 19:00'), 'Chorus'], [['time'], ['date', 'title']],
        ], [
            [$p['writes']['overrides'][11]['recurrence_instance_utc'] ?? null, $p['writes']['overrides'][11]['start_utc'] ?? null, $p['writes']['overrides'][11]['title'] ?? null, $p['writes']['overrides'][11]['override_fields'] ?? null],
            [$p['writes']['overrides'][12]['recurrence_instance_utc'] ?? null, $p['writes']['overrides'][12]['title'] ?? null],
            [$p['writes']['overrides'][13]['recurrence_instance_utc'] ?? null, $p['writes']['overrides'][13]['title'] ?? null],
            [$p['plan']['days'][0]['kept'], $p['plan']['days'][0]['takes']],
        ]);

        // Acceptance 13: the skip rule's other sides. Pottery class, 8 Mondays
        // from 4 Jan, 11 Jan and 15 Feb removed: the end shortened to 6 drops
        // 15 Feb past it; every other Monday has no 11 Jan and keeps 15 Feb; a
        // drag to Tuesday keeps both occurrences, so both skips move.
        $course = smSeries('Pottery class', 'FREQ=WEEKLY;BYDAY=MO;COUNT=8', '2027-01-04 18:00', 120, false, ['2027-01-11 18:00', '2027-02-15 18:00']);
        $sides = static fn(array $p): array => array_map(static fn(array $d): array => [$d['shows'], $d['outcome'], $d['why'] ?? null, $d['side'] ?? null, $d['to'] ?? null], $p['plan']['days']);
        $end = smPlan($course, [], ['acted' => smAt('2027-01-04 18:00'), 'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=6']);
        $other = smPlan($course, [], ['acted' => smAt('2027-01-04 18:00'), 'rrule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO;COUNT=8']);
        $tuesday = smPlan($course, [], smDrag($course, '2027-01-18 18:00', '2027-01-19 18:00'));
        checkEq('Acceptance 13: past a shortened end the skip is dropped, on a day the new rule lacks it is dropped, where the occurrence stays it moves with it', [
            [['2027-01-11', 'stays', null, null, '2027-01-11'], ['2027-02-15', 'dropped', 'outside', 'end', null]],
            [['2027-01-11', 'dropped', 'noday', null, null], ['2027-02-15', 'stays', null, null, '2027-02-15']],
            [['2027-01-11', 'moves', null, null, '2027-01-12'], ['2027-02-15', 'moves', null, null, '2027-02-16']],
            [json_encode([smAt('2027-01-11 18:00')]), json_encode([smAt('2027-02-15 18:00')]), json_encode([smAt('2027-01-12 18:00'), smAt('2027-02-16 18:00')])],
        ], [$sides($end), $sides($other), $sides($tuesday), [$end['writes']['master']['exdates_json'] ?? null, $other['writes']['master']['exdates_json'] ?? null, $tuesday['writes']['master']['exdates_json'] ?? null]]);
    })();

    // ---- Lists ---------------------------------------------------------------
    $gym = smSeries('Gym', 'FREQ=WEEKLY;BYDAY=MO,WE,FR', '2027-01-04 07:00');
    $p = smPlan($gym, [], smDrag($gym, '2027-03-10 07:00', '2027-03-11 07:00'));
    checkEq('Gym Wed to Thu: move every Wednesday (Mon, Thu, Fri) or shift the pattern (Tue, Thu, Sat)', [
        ['Move every Wednesday to Thursday: Monday, Thursday and Friday', 'Shift the whole weekly pattern: Tuesday, Thursday and Saturday'], 'FREQ=WEEKLY;BYDAY=MO,TH,FR',
    ], [array_map(static fn(array $o): string => $o['label'], smGroup($p, 'reading')['options'] ?? []), $p['writes']['master']['rrule'] ?? null]);
    $p = smPlan($gym, [], smDrag($gym, '2027-03-10 07:00', '2027-03-12 07:00'));
    checkEq('Gym Wed to Fri: the member reading is disabled (Friday is already one of its days)', [['member!', 'pattern'], 'Friday is already one of its days', 'FREQ=WEEKLY;BYDAY=WE,FR,SU'],
        [smOptions($p, 'reading'), smGroup($p, 'reading')['options'][0]['reason'] ?? null, $p['writes']['master']['rrule'] ?? null]);
    $swim = smSeries('Swim', 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=6', '2027-01-04 07:00');
    $p = smPlan($swim, [], smDrag($swim, '2027-01-11 07:00', '2027-01-14 07:00'));
    checkEq('Swim Mon to Thu: a member crossing another starts on Wed 6 Jan, ends Thu 21 Jan', [smAt('2027-01-06 07:00'), 'FREQ=WEEKLY;BYDAY=WE,TH;COUNT=6', ['2027-01-06', '2027-01-07', '2027-01-13', '2027-01-14', '2027-01-20', '2027-01-21']],
        [$p['writes']['master']['start_utc'] ?? null, $p['writes']['master']['rrule'] ?? null, smShows($swim, $p['writes'], '2027-02-28')]);
    $lab = smSeries('Lab', 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE', '2027-01-04 09:00');
    $p = smPlan($lab, [], smDrag($lab, '2027-03-01 09:00', '2027-02-28 09:00'));
    checkEq('Lab (every other Mon, Wed) Mon to Sun: WKST=SU, from Sun 3 Jan, each Sunday in its Wednesday\'s week', ['FREQ=WEEKLY;INTERVAL=2;BYDAY=SU,WE;WKST=SU', ['2027-01-03', '2027-01-06', '2027-01-17', '2027-01-20']],
        [$p['writes']['master']['rrule'] ?? null, array_slice(smShows($lab, $p['writes'], '2027-01-31'), 0, 4)]);
    $pair = smSeries('Weekend shift', 'FREQ=WEEKLY;INTERVAL=2;BYDAY=SA,SU', '2026-12-05 10:00');
    $p = smPlan($pair, [], smDrag($pair, '2026-12-05 10:00', '2026-12-06 10:00'));
    checkEq('Cause 4: every other Sat and Sun dragged a day, the member reading disabled, the shift BYDAY=MO,SU;WKST=TU', [['member!', 'pattern'], 'FREQ=WEEKLY;INTERVAL=2;BYDAY=SU,MO;WKST=TU', ['2026-12-06', '2026-12-07', '2026-12-20', '2026-12-21']],
        [smOptions($p, 'reading'), $p['writes']['master']['rrule'] ?? null, array_slice(smShows($pair, $p['writes'], '2026-12-31'), 0, 4)]);
    $payday = smSeries('Payday', 'FREQ=MONTHLY;BYMONTHDAY=1,15', '2027-01-01', 0, true);
    $p = smPlan($payday, [], smDrag($payday, '2027-03-15', '2027-03-16'));
    checkEq('Payday 15 Mar to 16 Mar: move every 15th (1st and 16th) or shift (2nd and 16th)', [['member', 'pattern'], 'FREQ=MONTHLY;BYMONTHDAY=1,16', ['Move every 15th to the 16th: the 1st and 16th', 'Shift the whole monthly pattern: the 2nd and 16th']],
        [smOptions($p, 'reading'), $p['writes']['master']['rrule'] ?? null, array_map(static fn(array $o): string => $o['label'], smGroup($p, 'reading')['options'] ?? [])]);
    $bonus = [smEdited($payday, 2, '2027-04-15', ['title' => 'Payday, bonus'], null, ['title'])];
    $p = smPlan($payday, $bonus, smDrag($payday, '2027-03-01', '2027-03-20'));
    checkEq('Payday 1 Mar to 20 Mar: every 1st to the 20th, from 15 Jan; bonus stays on 15 Apr; the shift disabled', [
        'FREQ=MONTHLY;BYMONTHDAY=15,20', smAt('2027-01-15 00:00', 'UTC'), ['member', 'pattern!'], 'the 15th would move past the end of its month', smAt('2027-04-15 00:00', 'UTC'),
    ], [$p['writes']['master']['rrule'] ?? null, $p['writes']['master']['start_utc'] ?? null, smOptions($p, 'reading'), smGroup($p, 'reading')['options'][1]['reason'] ?? null,
        $p['writes']['overrides'][2]['recurrence_instance_utc'] ?? smAt('2027-04-15 00:00', 'UTC')]);
    $first = smPlan($payday, [smEdited($payday, 2, '2027-01-15', ['title' => 'Payday, bonus'], null, ['title'])], smDrag($payday, '2027-01-01', '2027-01-02'));
    checkEq('Round one, finding 3: the 1st to the 2nd keeps the edited 15th on the 15th; the shift moves it to the 16th', [
        'FREQ=MONTHLY;BYMONTHDAY=2,15', null, smAt('2027-01-16 00:00', 'UTC'),
    ], [$first['writes']['master']['rrule'] ?? null, $first['writes']['overrides'][2]['recurrence_instance_utc'] ?? null,
        smPlan($payday, [smEdited($payday, 2, '2027-01-15', ['title' => 'Payday, bonus'], null, ['title'])], smDrag($payday, '2027-01-01', '2027-01-02'), ['reading' => 'pattern'])['writes']['overrides'][2]['recurrence_instance_utc'] ?? null]);

    // ---- Nth weekdays, yearly ----------------------------------------------
    $board = smSeries('Board meeting', 'FREQ=MONTHLY;BYDAY=2TU', '2027-01-12 18:00');
    $p = smPlan($board, [], smDrag($board, '2027-02-09 18:00', '2027-02-10 18:00'));
    checkEq('Board meeting second Tuesday to Wednesday: monthly on the second Wednesday', ['FREQ=MONTHLY;BYDAY=2WE', ['2027-02-10', '2027-03-10', '2027-04-14']],
        [$p['writes']['master']['rrule'] ?? null, smNext($p, 3)]);
    $dinner = smSeries('Dinner club', 'FREQ=MONTHLY;BYDAY=4TH', '2027-01-28 19:00');
    $p = smPlan($dinner, [], smDrag($dinner, '2027-01-28 19:00', '2027-01-29 19:00'));
    checkEq('Dinner club fourth Thursday to Fri 29 Jan: the last Friday', ['FREQ=MONTHLY;BYDAY=-1FR', ['2027-01-29', '2027-02-26', '2027-03-26']], [$p['writes']['master']['rrule'] ?? null, smNext($p, 3)]);
    $march = smSeries('Spring meeting', 'FREQ=YEARLY;BYMONTH=3;BYDAY=2TU', '2027-03-09 18:00');
    $p = smPlan($march, [], smDrag($march, '2027-03-09 18:00', '2027-03-10 18:00'));
    checkEq('Yearly second Tuesday of March dragged a day: 2WE from 10 Mar 2027', ['FREQ=YEARLY;BYMONTH=3;BYDAY=2WE', smAt('2027-03-10 18:00')], [$p['writes']['master']['rrule'] ?? null, $p['writes']['master']['start_utc'] ?? null]);
    $nov = smSeries('Harvest', 'FREQ=YEARLY;BYMONTH=11;BYDAY=3SU;COUNT=15', '2026-11-15', 0, true);
    $p = smPlan($nov, [], smDrag($nov, '2028-11-19', '2028-12-17'));
    checkEq('Cause 7: yearly third Sunday of November dragged to 17 Dec: start 20 Dec 2026, fifteen of them', ['FREQ=YEARLY;BYMONTH=12;BYDAY=3SU;COUNT=15', smAt('2026-12-20 00:00', 'UTC')],
        [$p['writes']['master']['rrule'] ?? null, $p['writes']['master']['start_utc'] ?? null]);
    $months = smSeries('Term start', 'FREQ=YEARLY;BYMONTH=3,9;BYMONTHDAY=15', '2027-03-15', 0, true);
    $p = smPlan($months, [], smDrag($months, '2027-03-15', '2027-04-16'));
    checkEq('Cause 3: BYMONTH=3,9 from 15 Mar to 16 Apr: every 16 April and October', 'FREQ=YEARLY;BYMONTH=4,10;BYMONTHDAY=16', $p['writes']['master']['rrule'] ?? null);
    $p = smPlan($months, [], smDrag($months, '2027-09-15', '2028-01-15'));
    checkEq('A month list crossing the year is refused', [true, 'year_cross'], [$p['refused'], $p['plan']['refusals'][0]['code'] ?? null]);

    // ---- The reviewers' cases -----------------------------------------------
    $statement = smSeries('Statement', 'FREQ=MONTHLY;BYMONTHDAY=30', '2027-01-30 18:00');
    $rows = [smEdited($statement, 2, '2027-04-30 18:00', ['title' => 'Statement, April'], null, ['title'])];
    $statement['exdates_json'] = json_encode([smAt('2027-06-30 18:00')]);
    $p = smPlan($statement, $rows, smDrag($statement, '2027-01-30 18:00', '2027-02-01 18:00'));
    checkEq('External F1: every 30th from 30 Jan to 1 Feb is every 1st; the edit on 1 May, the skip on 1 Jul; the gain 1 Mar named', [
        'FREQ=MONTHLY;BYMONTHDAY=1', smAt('2027-05-01 18:00'), json_encode([smAt('2027-07-01 18:00')]), '2027-03-01',
    ], [$p['writes']['master']['rrule'] ?? null, $p['writes']['overrides'][2]['recurrence_instance_utc'] ?? null, $p['writes']['master']['exdates_json'] ?? null, $p['plan']['gains'][0]['date'] ?? null]);
    $plainMonthly = smSeries('Statement', 'FREQ=MONTHLY', '2027-01-30 18:00');
    $p = smPlan($plainMonthly, [], smDrag($plainMonthly, '2027-04-30 18:00', '2027-05-01 18:00'));
    checkEq('Cause 2: a bare monthly from 30 Jan, 30 Apr to 1 May: every 1st from 1 Feb, written bare', ['FREQ=MONTHLY', smAt('2027-02-01 18:00'), ['2027-02-01', '2027-03-01', '2027-04-01', '2027-05-01']],
        [$p['writes']['master']['rrule'] ?? 'FREQ=MONTHLY', $p['writes']['master']['start_utc'] ?? null, array_slice(smShows($plainMonthly, $p['writes'], '2027-05-31'), 0, 4)]);
    $lastDay = smSeries('Month end', 'FREQ=MONTHLY;BYMONTHDAY=-1', '2027-01-31 18:00');
    $rows = [smEdited($lastDay, 2, '2027-03-31 18:00', ['title' => 'Quarter end'], null, ['title'])];
    $lastDay['exdates_json'] = json_encode([smAt('2027-04-30 18:00')]);
    $p = smPlan($lastDay, $rows, smDrag($lastDay, '2027-01-31 18:00', '2027-02-01 18:00'));
    checkEq('Round one, finding 1: every last day to 1 Feb is every 1st from 1 Feb; 31 Mar\'s edit on 1 Apr, 30 Apr\'s skip on 1 May', [
        'FREQ=MONTHLY;BYMONTHDAY=1', smAt('2027-02-01 18:00'), smAt('2027-04-01 18:00'), json_encode([smAt('2027-05-01 18:00')]),
    ], [$p['writes']['master']['rrule'] ?? null, $p['writes']['master']['start_utc'] ?? null, $p['writes']['overrides'][2]['recurrence_instance_utc'] ?? null, $p['writes']['master']['exdates_json'] ?? null]);
    $twentyEighth = smSeries('Rent', 'FREQ=MONTHLY;BYMONTHDAY=28;UNTIL=20270528T235959Z', '2027-01-28 09:00');
    $p = smPlan($twentyEighth, [], smDrag($twentyEighth, '2027-02-28 09:00', '2027-03-01 09:00'));
    checkEq('Round one, finding 2: UNTIL becomes the latest image, 1 Jun, as that occurrence; no bound choice', ['FREQ=MONTHLY;BYMONTHDAY=1;UNTIL=20270601T160000Z', []],
        [$p['writes']['master']['rrule'] ?? null, smOptions($p, 'bound')]);
    $tutor = smSeries('Tutoring', 'FREQ=MONTHLY;BYMONTHDAY=15;UNTIL=20270216T075959Z', '2026-12-15 16:00');
    $p = smPlan($tutor, [], smDrag($tutor, '2027-01-15 16:00', '2027-01-30 16:00'), ['reading' => 'skip']);
    checkEq('Tutoring (an end with no image), skipping: keep the end (2 events, ends 30 Jan) or keep 3', [['end', 'count'] === smOptions($p, 'bound') || ['count', 'end'] === smOptions($p, 'bound'), 'end', 'FREQ=MONTHLY;BYMONTHDAY=30;UNTIL=20270131T000000Z'],
        [true, smGroup($p, 'bound')['selected'] ?? null, $p['writes']['master']['rrule'] ?? null]);
    $water = smSeries('Water plants', 'FREQ=DAILY;UNTIL=20301231T160000Z', '2027-01-01 08:00');
    $p = smPlan($water, [], smDrag($water, '2027-03-05 08:00', '2027-03-06 08:00'));
    checkEq('Water plants (1,461 days) moved a day: until 1 Jan 2031, still 1,461 events (no display cap)', ['FREQ=DAILY;UNTIL=20310101T160000Z', 1461],
        [$p['writes']['master']['rrule'] ?? null, count((new BetterCal\Domain\Series\Calc(Rule::parse((string) ($p['writes']['master']['rrule'] ?? ''), Cal::dn(2027, 1, 2)), Cal::dn(2027, 1, 2), 'America/Los_Angeles', false, [8, 0, 0], 3600))->all())]);

    // ---- Times, zones, all-day, bounds --------------------------------------
    $time = smPlan($choir, $choirRows, smDrag($choir, '2027-03-09 19:00', '2027-03-09 19:30'));
    checkEq('Choir to 19:30 for all events: same rule, start at 19:30, Rehearsal follows to 19:30', ['FREQ=WEEKLY;BYDAY=TU', smAt('2027-01-05 19:30'), smAt('2027-04-13 19:30'), smAt('2027-04-13 19:30')],
        [$time['writes']['master']['rrule'] ?? $choir['rrule'], $time['writes']['master']['start_utc'] ?? null, $time['writes']['overrides'][2]['recurrence_instance_utc'] ?? null, $time['writes']['overrides'][2]['start_utc'] ?? null]);
    $ownTime = [smEdited($choir, 4, '2027-04-06 19:00', [], '2027-04-06 18:00', ['time'])];
    $time = smPlan($choir, $ownTime, smDrag($choir, '2027-03-09 19:00', '2027-03-09 20:00'));
    checkEq('A day at its own 18:00 stays at 18:00 when the series moves to 20:00, re-keyed to 20:00', [smAt('2027-04-06 20:00'), null],
        [$time['writes']['overrides'][4]['recurrence_instance_utc'] ?? null, $time['writes']['overrides'][4]['start_utc'] ?? null]);
    $bounded = smSeries('Choir', 'FREQ=WEEKLY;BYDAY=TU;COUNT=10', '2027-01-05 19:00', 60, false, ['2027-03-02 19:00']);
    $rows = [smEdited($bounded, 2, '2027-02-23 19:00', ['title' => 'Rehearsal at Sample Hall'], null, ['title'])];
    $p = smPlan($bounded, $rows, ['rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=6']);
    checkEq('Ends shortened to 6: the 23 Feb edit outside (keep it, preselected), the 2 Mar skip forgotten', [['keep', 'delete'], [2], null, 'FREQ=WEEKLY;BYDAY=TU;COUNT=6'],
        [smOptions($p, 'outside'), array_keys($p['writes']['detach']), array_key_exists('exdates_json', $p['writes']['master']) ? $p['writes']['master']['exdates_json'] : 'unchanged', $p['writes']['master']['rrule'] ?? null]);
    $p = smPlan($bounded, [], ['rrule' => 'FREQ=WEEKLY;BYDAY=TU;UNTIL=20261201T000000Z']);
    checkEq('An end before the start leaves nothing: refused', [true, 'empty'], [$p['refused'], $p['plan']['refusals'][0]['code'] ?? null]);
    $studio = smSeries('Open studio', 'FREQ=WEEKLY;BYDAY=SA;UNTIL=20270327', '2027-01-02', 0, true);
    $visit = [smEdited($studio, 2, '2027-03-13', ['title' => 'Sample Hall visit', 'all_day' => 0, 'tzid' => 'America/Los_Angeles', 'start_utc' => smAt('2027-03-13 09:00'), 'end_utc' => smAt('2027-03-13 12:00')], null, ['time', 'allDay', 'length', 'title'])];
    $timed = smPlan($studio, $visit, ['acted' => smAt('2027-01-02 00:00', 'UTC'), 'landed' => ['start_utc' => smAt('2027-01-02 10:00'), 'end_utc' => smAt('2027-01-02 11:00'), 'all_day' => 0, 'tzid' => 'America/Los_Angeles']]);
    checkEq('Open studio made timed at 10:00: UNTIL becomes the last occurrence\'s instant; the visit keeps its own 09:00', [
        'FREQ=WEEKLY;BYDAY=SA;UNTIL=20270327T170000Z', smAt('2027-01-02 10:00'), smAt('2027-03-13 10:00'), null,
    ], [$timed['writes']['master']['rrule'] ?? null, $timed['writes']['master']['start_utc'] ?? null, $timed['writes']['overrides'][2]['recurrence_instance_utc'] ?? null, $timed['writes']['overrides'][2]['start_utc'] ?? null]);
    $call = smSeries('Team call', 'FREQ=WEEKLY;BYDAY=MO', '2027-03-01 18:00');
    $z = smPlan($call, [], ['acted' => smAt('2027-03-01 18:00'), 'landed' => ['start_utc' => smAt('2027-03-01 18:00', 'Europe/Berlin'), 'end_utc' => smAt('2027-03-01 19:00', 'Europe/Berlin'), 'all_day' => 0, 'tzid' => 'Europe/Berlin']]);
    checkEq('Team call to Berlin: the same clock (preselected) or the same moment (Tuesday 03:00)', [['clock', 'moment'], 'FREQ=WEEKLY;BYDAY=MO', 'Europe/Berlin'],
        [smOptions($z, 'zone'), $z['writes']['master']['rrule'] ?? $call['rrule'], $z['writes']['master']['tzid'] ?? null]);
    $zm = smPlan($call, [], ['acted' => smAt('2027-03-01 18:00'), 'landed' => ['start_utc' => smAt('2027-03-01 18:00', 'Europe/Berlin'), 'end_utc' => smAt('2027-03-01 19:00', 'Europe/Berlin'), 'all_day' => 0, 'tzid' => 'Europe/Berlin']], ['zone' => 'moment']);
    checkEq('Team call, the same moment: every Tuesday at 03:00 Berlin time, the first one at the same instant', ['FREQ=WEEKLY;BYDAY=TU', 'Europe/Berlin', false],
        [$zm['writes']['master']['rrule'] ?? null, $zm['writes']['master']['tzid'] ?? null, array_key_exists('start_utc', $zm['writes']['master'])]);
    $night = smSeries('Night shift', 'FREQ=WEEKLY;BYDAY=SU', '2027-03-07 02:30');
    $n = smPlan($night, [], ['columns' => ['title' => 'Night watch']]);
    checkEq('Night shift at 02:30: the plan notes the spring gap', ['gap'], array_column($n['plan']['notes'], 'kind'));

    // ---- A new rule ----------------------------------------------------------
    $choir2 = smSeries('Choir', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 19:00', 60, false, ['2027-04-20 19:00']);
    $rows = [smEdited($choir2, 2, '2027-04-13 19:00', ['title' => 'Rehearsal'], null, ['title']), smEdited($choir2, 3, '2027-05-11 19:00', ['title' => 'Rehearsal'], null, ['title'])];
    $p = smPlan($choir2, $rows, ['rrule' => 'FREQ=WEEKLY;BYDAY=MO,TH']);
    checkEq('Choir changed to Monday and Thursday: from Thu 7 Jan, both Rehearsals outside (keep them), the skip forgotten', [
        'FREQ=WEEKLY;BYDAY=MO,TH', smAt('2027-01-07 19:00'), ['keep', 'delete'], [2, 3], null,
    ], [$p['writes']['master']['rrule'] ?? null, $p['writes']['master']['start_utc'] ?? null, smOptions($p, 'outside'), array_keys($p['writes']['detach']), array_key_exists('exdates_json', $p['writes']['master']) ? $p['writes']['master']['exdates_json'] : 'kept']);
    checkEq('Choir changed to Monday and Thursday: the ownership sentence and the Activity summary name both days', [
        "Two edited days become events of their own: 'Rehearsal' on 13 Apr and 'Rehearsal' on 11 May.", true, ['day' => null],
    ], [$p['plan']['ownership'] ?? null, str_contains($p['plan']['summary'], "Two edited days became events of their own: 'Rehearsal' on 13 Apr and 'Rehearsal' on 11 May."),
        ['day' => smGroup($p, 'outside')['day'] ?? null]]);
    checkEq('a per-day group names its day: Choir\'s moved-days choice is drawn on 23 Mar', '2027-03-24T02:00:00Z',
        smGroup(smPlan($choir, $choirRows, smDrag($choir, '2027-03-09 19:00', '2027-03-10 19:00')), 'movedDays')['day'] ?? null);
    $p = smPlan($choir2, $rows, ['rrule' => 'FREQ=WEEKLY;BYDAY=TH']);
    checkEq('Tuesday changed to Thursday: the drag\'s mapping is offered first', [['map', 'keep'], smAt('2027-04-15 19:00')],
        [smOptions($p, 'reading'), $p['writes']['overrides'][2]['recurrence_instance_utc'] ?? null]);

    // ---- Capabilities --------------------------------------------------------
    $imported = smSeries('Standup', 'FREQ=WEEKLY;BYDAY=MO,WE;BYHOUR=9;BYMINUTE=30', '2027-01-04 09:30');
    $p = smPlan($imported, [], smDrag($imported, '2027-01-11 09:30', '2027-01-11 10:00'));
    checkEq('BYHOUR and BYMINUTE: 10:00 writes the hour and minute into the rule', 'FREQ=WEEKLY;BYDAY=MO,WE;BYHOUR=10;BYMINUTE=0', $p['writes']['master']['rrule'] ?? null);
    $weekno = smSeries('Kickoff', 'FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO;BYHOUR=9;WKST=SU', '2027-01-04 09:00');
    $p = smPlan($weekno, [], smDrag($weekno, '2027-01-04 09:00', '2027-01-04 10:00'));
    checkEq('BYWEEKNO with one BYHOUR: a new time is accepted and written', [false, 'FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO;BYHOUR=10;WKST=SU'], [$p['refused'], $p['writes']['master']['rrule'] ?? null]);
    $p = smPlan($weekno, [], smDrag($weekno, '2027-01-04 09:00', '2027-01-05 09:00'));
    checkEq('BYWEEKNO dragged a day: refused', [true, Model::REASONS['weekno']], [$p['refused'], $p['plan']['refusals'][0]['words'] ?? null]);
    $twice = smSeries('Meds', 'FREQ=DAILY;BYHOUR=9,17', '2027-03-01 09:00');
    $p = smPlan($twice, [], ['acted' => smAt('2027-03-01 09:00'), 'landed' => ['start_utc' => smAt('2027-03-01 00:00', 'UTC'), 'end_utc' => smAt('2027-03-02 00:00', 'UTC'), 'all_day' => 1, 'tzid' => 'UTC']]);
    checkEq('Twice a day made all-day: refused', [true, 'severalAllDay'], [$p['refused'], $p['plan']['refusals'][0]['code'] ?? null]);
    $p = smPlan($twice, [], ['columns' => ['title' => 'Medication']]);
    checkEq('Twice a day renamed for all events: direct', true, $p['plan']['direct']);
    $overlap = smSeries('Volunteer', 'FREQ=MONTHLY;BYDAY=4MO,-1MO', '2027-01-25 18:00');
    $p = smPlan($overlap, [], smDrag($overlap, '2027-03-22 18:00', '2027-03-23 18:00'));
    checkEq('4MO and -1MO dragged: refused (two of its days are one day in some months)', [true, 'overlap'], [$p['refused'], $p['plan']['refusals'][0]['code'] ?? null]);

    // ---- Own fields: the backfill rule ---------------------------------------
    $chorus = smSeries('Chorus', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 19:00');
    checkEq('Own fields by equality (the 063 backfill): date, time, title and place, a stale title, nothing', [
        ['date'], ['time'], ['title', 'location'], ['title'], [],
    ], [
        OwnFields::infer(smEdited($chorus, 2, '2027-03-09 19:00', [], '2027-03-11 19:00'), $chorus),
        OwnFields::infer(smEdited($chorus, 3, '2027-03-16 19:00', [], '2027-03-16 19:30'), $chorus),
        OwnFields::infer(smEdited($chorus, 4, '2027-04-13 19:00', ['title' => 'Rehearsal', 'location' => 'Sample Hall']), $chorus),
        OwnFields::infer(smEdited($chorus, 5, '2027-02-02 19:00', ['title' => 'Choir']), $chorus),
        OwnFields::infer(smEdited($chorus, 6, '2027-04-20 19:00', ['title' => 'Chorus']), $chorus),
    ]);
    $stored = smEdited($chorus, 4, '2027-04-13 19:00', ['title' => 'Rehearsal'], null, ['title']);
    $masterRehearsal = ['title' => 'Rehearsal'] + $chorus;
    checkEq('Own fields across a sync (Rows from Elsewhere)', [['title'], ['title', 'location'], [], ['time']], [
        OwnFields::afterSync($stored, $stored, $masterRehearsal),
        OwnFields::afterSync($stored, ['location' => 'Sample Annex'] + $stored, $chorus),
        OwnFields::afterSync(smEdited($chorus, 5, '2027-02-02 19:00', ['title' => 'Choir'], null, ['title']), smEdited($chorus, 5, '2027-02-02 19:00', ['title' => 'Chorus']), $chorus),
        OwnFields::afterSync(null, smEdited($chorus, 7, '2027-04-27 19:00', [], '2027-04-27 19:30'), $chorus),
    ]);
    // ---- 1.5.0 integration review ------------------------------------------
    // P1-1: an unbounded series read to a horizon has no phantom gains past it.
    $genSkips = static fn(array $p): array => array_values(array_map(static fn(array $d): string => substr((string) $d['newKey'], 0, 10),
        array_filter($p['plan']['days'], static fn(array $d): bool => $d['outcome'] === 'generated')));
    $swimW = smSeries('Swim W', 'FREQ=WEEKLY;BYDAY=MO,WE', '2027-01-04 07:00');
    $p = smPlan($swimW, [smEdited($swimW, 2, '2027-01-06 07:00', ['title' => 'Swim test'], null, ['title'])], smDrag($swimW, '2027-01-11 07:00', '2027-01-18 07:00'));
    checkEq('Prediction 22 (Swim from Mon 4 Jan, no end, Mon 11 Jan to Mon 18 Jan): two readings, no edge group, no skip written', [['member', 'pattern'], [], [], null],
        [smOptions($p, 'reading'), smOptions($p, 'edge'), $genSkips($p), $p['writes']['master']['exdates_json'] ?? null]);
    $swimWed = smSeries('Swim', 'FREQ=WEEKLY;BYDAY=MO,WE', '2027-01-06 07:00');
    $p = smPlan($swimWed, [], smDrag($swimWed, '2027-01-11 07:00', '2027-01-15 07:00'));
    checkEq('Swim from Wed 6 Jan, no end, Mondays to Fridays: the edge offers only Fri 8 Jan, labelled by its week', [["Don't add Fri 8 Jan", 'Add Fri 8 Jan'], 'First week', ['2027-01-08'],
        'Fri 8 Jan skipped, so the series starts as before'], [
        array_column(smGroup($p, 'edge')['options'] ?? [], 'label'), smGroup($p, 'edge')['label'] ?? null, $genSkips($p),
        array_values(array_filter($p['plan']['days'], static fn(array $d): bool => $d['outcome'] === 'generated'))[0]['words'] ?? null,
    ]);
    $weekdays = smSeries('Desk hours', 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', '2027-01-01 09:00');
    $p = smPlan($weekdays, [], smDrag($weekdays, '2027-03-08 09:00', '2027-03-22 09:00'));
    checkEq('MO-FR from Fri 1 Jan, Mondays two weeks later: no skip past the start (only the two Mondays no session had, 4 and 11 Jan)', ['2027-01-04', '2027-01-11'], $genSkips($p));
    $payday = smSeries('Payday', 'FREQ=MONTHLY;BYMONTHDAY=1,15', '2027-01-01', 60, true);
    $p = smPlan($payday, [], smDrag($payday, '2027-03-15', '2027-04-15'));
    checkEq('Payday 1st and 15th, every 15th a month later: no skip on a real payday (only 15 Jan, the first month\'s gain)', [['2027-01-15'], json_encode([smAt('2027-01-15 00:00', 'UTC')])],
        [$genSkips($p), $p['writes']['master']['exdates_json'] ?? null]);

    // P2-3: the bound line under a generated skip (acceptance cases 2, 3 and 7).
    $p = smPlan($pottery, [], smDrag($pottery, '2027-01-11 18:00', '2027-01-15 18:00'));
    checkEq('Acceptance 2: "Don\'t add" reads 5 events, ends 22 Jan (was 20 Jan); "Add" says what has no place', [
        '5 events, ends 22 Jan (was 20 Jan)', '5 events, ends 22 Jan (was 20 Jan)', '5 events, ends 20 Jan; Fri 22 Jan has no place',
    ], [$p['plan']['bound']['words'], smGroup($p, 'edge')['options'][0]['bound']['words'] ?? null, smGroup($p, 'edge')['options'][1]['bound']['words'] ?? null]);
    $relay = smSeries('Swim relay', 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=3', '2027-01-04 07:00');
    $p = smPlan($relay, [smEdited($relay, 2, '2027-01-06 07:00', ['title' => 'Relay trial'], null, ['title'])], smDrag($relay, '2027-01-11 07:00', '2027-01-18 07:00'));
    checkEq('Acceptance 3: "Don\'t add Wed 13 Jan" in the last week reads 3 events, ends 18 Jan (was 11 Jan)', ['Last week', '3 events, ends 18 Jan (was 11 Jan)', 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=4'],
        [smGroup($p, 'edge')['label'] ?? null, $p['plan']['bound']['words'], $p['writes']['master']['rrule'] ?? null]);
    checkEq('Acceptance 3: the readings say what sets them apart', [
        "the same 3 dates; starts Wed 6 Jan, 'Relay trial' stays on Wed 6 Jan", "the same 3 dates; starts Mon 11 Jan, 'Relay trial' moves to Wed 13 Jan",
    ], array_column(smGroup($p, 'reading')['options'] ?? [], 'words'));
    $p = smPlan($pottery13, [], smDrag($pottery13, '2027-01-11 18:00', '2027-01-15 18:00'));
    checkEq('Acceptance 7: 5 scheduled occurrences, 1 skipped, ends 22 Jan (was 20 Jan); with "Add", ends 20 Jan and 22 Jan has no place', [
        '5 scheduled occurrences, 1 skipped, ends 22 Jan (was 20 Jan)', '5 scheduled occurrences, 1 skipped, ends 20 Jan; Fri 22 Jan has no place',
    ], [$p['plan']['bound']['words'], smGroup($p, 'edge')['options'][1]['bound']['words'] ?? null]);

    // P2-4: on Google, the whole-plan refusal of a drag gives the Google reason.
    $g = smPlan($practice, $practiceRows, smDrag($practice, '2027-03-11 18:00', '2027-03-12 18:00'), [], ['google' => true]);
    checkEq('Acceptance 5: every refusal of the Google drag, the plan\'s own included, says the Google reason', [['google'], [Model::REASONS['google']]],
        [array_values(array_unique(array_column($g['plan']['refusals'], 'code'))), array_values(array_unique(array_column($g['plan']['refusals'], 'words')))]);

    // P2-5: readings and zones say their consequence.
    $book = smSeries('Book club', 'FREQ=MONTHLY;BYMONTHDAY=15', '2027-01-15 19:00');
    $p = smPlan($book, [], smDrag($book, '2027-03-15 19:00', '2027-03-30 19:00'));
    checkEq('Book club to the 30th: the clamped reading names February\'s days, the skipping one what it drops', [
        'in February: 28 Feb, and 29 Feb 2028', 'none in February: drops 15 Feb\'s event, then every February',
    ], array_column(smGroup($p, 'reading')['options'] ?? [], 'words'));
    $p = smPlan($book, [], smDrag($book, '2027-03-15 19:00', '2027-03-31 19:00'));
    checkEq('Book club to the 31st: the skipping 31st drops 5 months a year, first 15 Feb\'s', ['', 'drops 5 months a year, first 15 Feb\'s'], array_column(smGroup($p, 'reading')['options'] ?? [], 'words'));
    $p = smPlan($book, [], smDrag($book, '2027-04-15 19:00', '2027-04-30 19:00'));
    checkEq('Book club to 30 Apr: the last day says the 31st in longer months', 'the 31st in longer months', smGroup($p, 'reading')['options'][2]['words'] ?? null);
    $swap = smSeries('Book swap', 'FREQ=MONTHLY;BYMONTHDAY=15;COUNT=3', '2027-03-15 19:00');
    $p = smPlan($swap, [], smDrag($swap, '2027-03-15 19:00', '2027-03-30 19:00'));
    checkEq('Book swap: the same 3 dates, apart if extended', ['the same 3 dates; if extended, 28 or 29 Feb', 'the same 3 dates; if extended, none in February'],
        array_column(smGroup($p, 'reading')['options'] ?? [], 'words'));
    $team = smSeries('Team call', 'FREQ=WEEKLY;BYDAY=MO', '2027-03-01 18:00');
    $berlin = new DateTimeImmutable('2027-03-01 18:00', Time::zone('Europe/Berlin'));
    $p = smPlan($team, [], ['acted' => smAt('2027-03-01 18:00'), 'landed' => ['start_utc' => Time::toDb($berlin), 'end_utc' => Time::toDb($berlin->modify('+1 hour')), 'all_day' => 0, 'tzid' => 'Europe/Berlin']]);
    checkEq('Team call to Berlin: each zone option lists the occurrences whose clock in Los Angeles differs', [
        'every Monday at 18:00 Berlin time; 09:00 in Los Angeles, 10:00 on 15 and 22 Mar and 1 Nov',
        'every Tuesday at 03:00 Berlin time; 18:00 Monday in Los Angeles, 19:00 on 15 and 22 Mar and 1 Nov',
    ], array_column(smGroup($p, 'zone')['options'] ?? [], 'words'));

    // P2-6: bound options told apart by their dates; gains respect the chosen end.
    $cello = smSeries('Cello lesson', 'FREQ=MONTHLY;BYMONTHDAY=31;COUNT=6', '2027-01-31 16:00');
    $p = smPlan($cello, [], smDrag($cello, '2027-10-31 16:00', '2027-11-01 16:00'));
    $nexts = array_map(static fn(array $o): array => array_map(static fn(array $e): string => substr($e['start'], 0, 10), $o['next'] ?? []), smGroup($p, 'bound')['options'] ?? []);
    checkEq('Cello lesson: keep the end and keep 6 show their ends, not the same 1 Nov', [['2027-09-01', '2027-10-01', '2027-11-01'], ['2027-05-01', '2027-06-01', '2027-07-01']], $nexts);
    $six = smPlan($cello, [], smDrag($cello, '2027-10-31 16:00', '2027-11-01 16:00'), ['bound' => 'count']);
    checkEq('Cello lesson, keep 6: no gain past its end (1 Oct is no gain of a series ending 1 Jul)', ['2027-03-01', '2027-05-01', '2027-07-01'], array_column($six['plan']['gains'], 'date'));

    $p = smPlan($bounded, [smEdited($bounded, 2, '2027-02-23 19:00', ['title' => 'Rehearsal at Sample Hall'], null, ['title'])], ['acted' => smAt('2027-02-23 19:00'), 'rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=6']);
    checkEq('Ends shortened from the edited day itself: the day acted on has no special place, it is outside like any other', [['keep', 'delete'], [2]],
        [smOptions($p, 'outside'), array_keys($p['writes']['detach'])]);
    // P3: words, zones.
    checkEq('Lord Howe falls back half an hour: 01:45 on 4 Apr 2027 is the first one, in daylight time', '2027-04-03 14:45:00',
        Time::toDb(BetterCal\Domain\Series\Calc::at(Cal::dn(2027, 4, 4), [1, 45, 0], 'Australia/Lord_Howe')));
    checkEq('the clock follows the day, before a clause about other months', ['Monthly on the 31st at 16:00, skipping months without one', 'Monthly on the 30th at 19:00, or the last day in shorter months', 'Yearly on 29 February at 10:00, only in leap years'], [
        $w('FREQ=MONTHLY;BYMONTHDAY=31', '2027-01-31', [16, 0, 0]), $w('FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1', '2027-01-30', [19, 0, 0]), $w('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29', '2028-02-29', [10, 0, 0]),
    ]);
    $p = smPlan($choir, $choirRows, smDrag($choir, '2027-03-09 19:00', '2027-03-09 19:30'));
    $byKey = array_column($p['plan']['days'], 'words', 'key');
    checkEq('a time-only change says the time: Rehearsal moves to 19:30', "'Rehearsal' Tue 13 Apr moves to 19:30, keeps its title and place", $byKey['2027-04-14T02:00:00Z'] ?? null);
    $rem = smSeries('Choir', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 19:00') + [];
    $rem['reminders_json'] = json_encode([['minutes' => 60]]);
    $p = smPlan($rem, [], ['columns' => ['title' => 'Chorus', 'reminders_json' => json_encode([['minutes' => 30]])]]);
    checkEq('field lines give the values before and after', ['Title: Choir, now Chorus', 'Reminder: 1 hour before, now 30 minutes'], array_column($p['plan']['fields'], 'words'));
    $p = smPlan($choir, [smEdited($choir, 2, '2027-04-13 19:00', ['title' => 'Rehearsal'], '2027-04-16 19:00', ['date', 'title'])], ['rrule' => 'FREQ=WEEKLY;BYDAY=MO,TH']);
    checkEq('the ownership sentence names the day by the day it is about, then where it shows', "One edited day becomes an event of its own: 'Rehearsal' on 13 Apr, moved to 16 Apr.", $p['plan']['ownership'] ?? null);
    checkEq('day names stay capitalized in the summary', true, str_contains($p['plan']['summary'], 'weekly on Tuesday at 19:00, now weekly on Monday and Thursday'));
    $created = BetterCal\Domain\Events::creationPlan(['rrule' => 'FREQ=WEEKLY;BYDAY=TU', 'start_utc' => smAt('2027-03-02 09:00'), 'end_utc' => smAt('2027-03-02 10:00'), 'all_day' => 0, 'tzid' => 'America/Los_Angeles'],
        ['start_utc' => smAt('2027-02-26 09:00')], 'America/Los_Angeles');
    checkEq('the creation line says "on or after"', true, str_contains((string) ($created['startWords'] ?? ''), 'the first Tuesday on or after the date you typed'));

    // Keys by arithmetic agree with the expander for every other week on
    // Sunday and Wednesday from a Sunday week start, through southern spring
    // gaps (sabre carries the gap's clock across the week it skips).
    $mismatch = [];
    foreach (['Australia/Sydney', 'Australia/Adelaide', 'America/Santiago', 'America/Los_Angeles', 'Pacific/Auckland'] as $tz) {
        foreach (['FREQ=WEEKLY;INTERVAL=2;BYDAY=SU,WE;WKST=SU', 'FREQ=WEEKLY;INTERVAL=3;BYDAY=SU,SA;WKST=SU', 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH'] as $rr) {
            foreach (['2026-08-02', '2026-08-05', '2026-08-09', '2026-08-12', '2026-08-16'] as $day) {
                foreach (['00:30', '02:30', '09:00'] as $clk) {
                    $start = new DateTimeImmutable("$day $clk", Time::zone($tz));
                    $dn = Cal::dnOf($start);
                    [$h, $mi] = array_map('intval', explode(':', $clk));
                    $calc = new BetterCal\Domain\Series\Calc(Rule::parse($rr, $dn), $dn, $tz, false, [$h, $mi, 0], 3600);
                    $row = ['rrule' => $rr, 'start_utc' => Time::toDb($start), 'end_utc' => Time::toDb($start->modify('+1 hour')), 'tzid' => $tz, 'all_day' => 0, 'uid' => 'sm'];
                    $sabre = array_map(static fn(array $o): string => Time::toDb($o['start']), BetterCal\Domain\Recurrence::sabreExpand($row, $start->modify('-1 day'), $start->modify('+500 days')));
                    $mine = array_map(static fn(int $d): string => $calc->key($d), $calc->occurrences($dn + 499));
                    if (array_diff($sabre, $mine) !== [] || array_diff($mine, $sabre) !== []) {
                        $mismatch[] = "$tz $rr $day $clk";
                    }
                }
            }
        }
    }
    checkEq('every other week from a Sunday week start keys as the expander does after a spring gap, in both directions (Sydney, Adelaide, Santiago)', [], $mismatch);

    // N3, N5, own time: the lines say where a day goes and what it takes.
    $moved = [smEdited($choir, 3, '2027-03-23 19:00', [], '2027-03-26 19:00', ['date'])];
    // The edit names the occurrence by its key and says where it was put (S'), whichever row it came through.
    $p = smPlan($choir, $moved, smDrag($choir, '2027-03-23 19:00', '2027-03-27 19:00'));
    checkEq('from an edited day with its own date, dragged a day: its line says where it goes', 'Tue 23 Mar, moved to Fri 26 Mar: moves to Sat 27 Mar',
        array_column($p['plan']['days'], 'words', 'key')['2027-03-24T02:00:00Z'] ?? null);
    $p = smPlan($choir, $moved, smDrag($choir, '2027-03-23 19:00', '2027-03-26 20:00'));
    checkEq('and a clock-only drag of it says the new time', 'Tue 23 Mar, moved to Fri 26 Mar: moves to 20:00',
        array_column($p['plan']['days'], 'words', 'key')['2027-03-24T02:00:00Z'] ?? null);
    $named = [smEdited($choir, 2, '2027-03-16 19:00', ['title' => 'Rehearsal'], null, ['title'])];
    $p = smPlan($choir, $named, ['acted' => smAt('2027-03-16 19:00'), 'columns' => ['title' => 'Choir F']]);
    // Its title was all it owned: taking the series' leaves it nothing of its own, so it is an occurrence again (Own Fields).
    checkEq('an editor save from an edited day that owned only its title: it goes back to the series, its row deleted', ["'Rehearsal' Tue 16 Mar goes back to the series, on Tue 16 Mar", 'rejoins', [2], []],
        [array_column($p['plan']['days'], 'words', 'key')['2027-03-17T02:00:00Z'] ?? null, array_column($p['plan']['days'], 'outcome', 'key')['2027-03-17T02:00:00Z'] ?? null, $p['writes']['delete'], $p['writes']['overrides']]);
    $p = smPlan($choir, $named, ['columns' => ['title' => 'Chorus']], ['ownTitle' => 'give']);
    checkEq('"Give it the new title too" to a day that owned only its title: it goes back to the series', ["'Rehearsal' Tue 16 Mar goes back to the series, on Tue 16 Mar", [2]],
        [array_column($p['plan']['days'], 'words', 'key')['2027-03-17T02:00:00Z'] ?? null, $p['writes']['delete']]);
    $namedPlace = [smEdited($choir, 2, '2027-03-16 19:00', ['title' => 'Rehearsal', 'location' => 'Sample Room'], null, ['title', 'location'])];
    $p = smPlan($choir, $namedPlace, ['columns' => ['title' => 'Chorus']], ['ownTitle' => 'give']);
    checkEq('"Give it the new title too" says so on its line, a day that keeps its place staying edited', ["'Rehearsal' Tue 16 Mar stays, keeps its place, takes 'Chorus'", []],
        [array_column($p['plan']['days'], 'words', 'key')['2027-03-17T02:00:00Z'] ?? null, $p['writes']['delete']]);
    checkEq('Open studio made timed: the visit\'s line says the time it keeps, as the examples give it', "'Sample Hall visit' Sat 13 Mar stays, keeps its title and own time at 09:00",
        array_column($timed['plan']['days'], 'words', 'key')['2027-03-13T00:00:00Z'] ?? null);

    // N1: a first day in a skipped hour. Night shift, monthly on the 13th at
    // 02:30, dragged a day: the series' first day is 14 Mar 2027, when 02:30
    // doesn't exist in Los Angeles. Stored from 14 Feb, skipped, so the
    // expander keeps 02:30 (it takes every day's clock from the start).
    $stored = static function (array $master, array $writes): array {
        $m = array_merge($master, $writes['master']);
        $m['exdates_json'] = $writes['exdates'] === [] ? null : json_encode($writes['exdates']);
        return $m;
    };
    $shown = static function (array $m, int $n = 4): array {
        $skip = array_flip(BetterCal\Domain\Recurrence::decodeExdates($m['exdates_json'] ?? null));
        $out = [];
        foreach (BetterCal\Domain\Recurrence::sabreExpand($m, Time::fromDb($m['start_utc'])->modify('-1 day'), Time::fromDb($m['start_utc'])->modify('+3 years')) as $o) {
            if (!isset($skip[Time::toDb($o['start'])])) {
                $out[] = Time::iso($o['start']->setTimezone(Time::zone((string) $m['tzid'])));
            }
        }
        return array_slice($out, 0, $n);
    };
    $night = smSeries('Night shift', 'FREQ=MONTHLY', '2027-03-13 02:30');
    $nightRows = [smEdited($night, 2, '2027-06-13 02:30', ['title' => 'Night shift, inventory'], null, ['title'])];
    $p = smPlan($night, $nightRows, smDrag($night, '2027-05-13 02:30', '2027-05-14 02:30'));
    $m1 = $stored($night, $p['writes']);
    checkEq('Night shift: stored from 14 Feb at 02:30, that day skipped, the rule bare as before', ['2027-02-14 10:30:00', [smAt('2027-02-14 02:30')], 'FREQ=MONTHLY'],
        [$m1['start_utc'], $p['writes']['exdates'], $m1['rrule']]);
    checkEq('Night shift: the plan\'s next from 14 May and the stored series agree (later days at 02:30)',
        array_column($p['plan']['next'], 'start'), array_slice($shown($m1, 8), 2, 4));
    checkEq('Night shift: the gap day shows at 03:30 as the expander resolves it, then 02:30', ['2027-03-14T03:30:00-07:00', '2027-04-14T02:30:00-07:00'], array_slice($shown($m1), 0, 2));
    checkEq('Night shift: the edited day is keyed on its 02:30 occurrence, and the words name the first shown day', [smAt('2027-06-14 02:30'), true, false],
        [$p['writes']['overrides'][2]['recurrence_instance_utc'] ?? null, str_contains($p['plan']['summary'], 'from 14 Mar'), str_contains(json_encode($p['plan']), '14 Feb')]);
    checkEq('Night shift: the stored day is in the plan\'s days as an anchor, with no words', [['anchor', '']], array_values(array_map(static fn(array $d): array => [$d['outcome'], $d['words']],
        array_filter($p['plan']['days'], static fn(array $d): bool => $d['outcome'] === 'anchor'))));
    // Edited again: the anchor is read as the stored day, not an event: a rename is direct and keeps it.
    $p2 = smPlan($m1, [], ['columns' => ['title' => 'Late shift']]);
    checkEq('the stored series renamed: direct, start and skips unwritten', [true, false, false], [$p2['plan']['direct'], isset($p2['writes']['master']['start_utc']), isset($p2['writes']['master']['exdates_json'])]);
    // Dragged on a day: 15 Mar has 02:30, so the series starts there and the anchor goes.
    $p3 = smPlan($m1, [], smDrag($m1, '2027-05-14 02:30', '2027-05-15 02:30'));
    checkEq('then dragged a day later: from 15 Mar at 02:30, no skip left, nothing named before it', ['2027-03-15 09:30:00', null, false],
        [$p3['writes']['master']['start_utc'] ?? null, $p3['writes']['master']['exdates_json'] ?? null, str_contains(json_encode($p3['plan']), 'Feb')]);
    // A kept count counts the stored day: six shown, COUNT=7.
    $counted = smSeries('Night shift', 'FREQ=MONTHLY;COUNT=6', '2027-03-13 02:30');
    $p = smPlan($counted, [], smDrag($counted, '2027-05-13 02:30', '2027-05-14 02:30'));
    $m1 = $stored($counted, $p['writes']);
    checkEq('with a COUNT: stored COUNT=7 from 14 Feb, six events shown from 14 Mar to 14 Aug, the bound line as six', ['FREQ=MONTHLY;COUNT=7', 6, '2027-08-14', true],
        [$m1['rrule'], count($shown($m1, 20)), substr((string) ($shown($m1, 20)[5] ?? ''), 0, 10), str_starts_with($p['plan']['bound']['words'], '6 events')]);
    $p2 = smPlan($m1, [], ['rrule' => 'FREQ=MONTHLY;COUNT=7', 'columns' => ['title' => 'Late shift']]);
    checkEq('the editor sending the stored COUNT back is no bound change', [true, false], [$p2['plan']['direct'], isset($p2['writes']['master']['rrule'])]);
    // Auckland, harness seed 4 case 723's shape: weekly, a start on 27 Sep 2026 at 02:15.
    $nz = smSeries('Early swim', 'FREQ=WEEKLY;BYDAY=SA', '2026-09-26 02:15', 60, false, [], 'Pacific/Auckland');
    $p = smPlan($nz, [], smDrag($nz, '2026-10-03 02:15', '2026-10-04 02:15'));
    $m1 = $stored($nz, $p['writes']);
    checkEq('Auckland: Saturdays to Sundays from 27 Sep, stored from 20 Sep at 02:15, skipped; 27 Sep at 03:15, then 02:15', [
        'FREQ=WEEKLY;BYDAY=SU', '2026-09-20', ['2026-09-27T03:15:00+13:00', '2026-10-04T02:15:00+13:00'],
    ], [$m1['rrule'], Time::fromDb($m1['start_utc'])->setTimezone(Time::zone('Pacific/Auckland'))->format('Y-m-d'), array_slice($shown($m1), 0, 2)]);

    // The plan's next dates against the stored series' expansion, from the
    // plan's first one (instants compared, so offsets don't matter).
    $agrees = static function (array $plan, array $m1) use ($shown): array {
        $want = array_map(static fn(array $n): int => Time::parseIso($n['start'])->getTimestamp(), $plan['plan']['next']);
        $got = array_values(array_filter(array_map(static fn(string $s): int => Time::parseIso($s)->getTimestamp(), $shown($m1, 400)), static fn(int $t): bool => $want !== [] && $t >= $want[0]));
        return [$want, array_slice($got, 0, count($want))];
    };

    // R1: a field-only edit is an identity edit. Twice a day, 09:00 and
    // 17:00 UTC, 60 events: a rename keeps every key, shown time and skip
    // as stored (the model reads this rule by day and would have rebuilt
    // the 17:00 exception's key at 09:00).
    $twice = smSeries('Sample drills', 'FREQ=DAILY;BYHOUR=9,17;COUNT=60', '2027-03-01 09:00', 60, false, ['2027-03-20 17:00'], 'UTC');
    $evening = smEdited($twice, 2, '2027-03-15 17:00', ['title' => 'Evening drill'], null, ['title']);
    $morning = smEdited($twice, 3, '2027-03-15 09:00', ['title' => 'Morning drill'], null, ['title']);
    $p = smPlan($twice, [$evening], ['columns' => ['title' => 'Sample practice']], ['ownTitle' => 'keep']);
    checkEq('R1: twice a day, one exception at 17:00 renamed around: nothing about it is written, its key stays 17:00', [[], '2027-03-15T17:00:00Z', 'stays'],
        [$p['writes']['overrides'], $p['plan']['days'][1]['newKey'] ?? null, $p['plan']['days'][1]['outcome'] ?? null]);
    checkEq('R1: the master takes only the title; the 17:00 skip stays where it is', [['title' => 'Sample practice'], ['2027-03-20 17:00:00'], '2027-03-20T17:00:00Z'],
        [$p['writes']['master'], $p['writes']['exdates'], $p['plan']['days'][0]['newKey'] ?? null]);
    checkEq('R1: the count words keep 60 (the rule says COUNT=60, on 30 days), its skip among them', '60 scheduled occurrences, 1 skipped, ends 30 Mar', $p['plan']['bound']['words']);
    checkEq('R1: next lists both times of a day', ['2027-03-01T09:00:00+00:00', '2027-03-01T17:00:00+00:00', '2027-03-02T09:00:00+00:00', '2027-03-02T17:00:00+00:00'],
        array_column($p['plan']['next'], 'start'));
    $p = smPlan($twice, [$evening, $morning], ['columns' => ['title' => 'Sample practice']], ['ownTitle' => 'keep']);
    checkEq('R1: two exceptions on one day, renamed around: no key written, each keeps its own', [[], ['2027-03-15T17:00:00Z', '2027-03-15T09:00:00Z']],
        [$p['writes']['overrides'], array_values(array_column(array_filter($p['plan']['days'], static fn(array $d): bool => $d['kind'] === 'edited'), 'newKey'))]);
    $p = smPlan($twice, [$evening, $morning], ['columns' => ['title' => 'Sample practice']], ['ownTitle' => 'give']);
    checkEq('R1: given the new title too, both are left with nothing of their own and go back to the series on their keys', [[], [2, 3], ['rejoins', 'rejoins'], ['2027-03-15T17:00:00Z', '2027-03-15T09:00:00Z']],
        [$p['writes']['overrides'], $p['writes']['delete'], array_values(array_column(array_filter($p['plan']['days'], static fn(array $d): bool => $d['kind'] === 'edited'), 'outcome')),
            array_values(array_column(array_filter($p['plan']['days'], static fn(array $d): bool => $d['kind'] === 'edited'), 'newKey'))]);
    $p = smPlan($twice, [$evening], smDrag($twice, '2027-03-01 09:00', '2027-03-01 09:00', ['columns' => ['title' => 'Sample practice']]), ['ownTitle' => 'keep']);
    checkEq('R1: the day acted on sent back where it is (an editor save) is no time change, so not refused as several a day', [false, []], [$p['refused'], $p['writes']['overrides']]);
    $p = smPlan($twice, [$evening], ['acted' => smAt('2027-03-01 17:00', 'UTC'), 'landed' => ['start_utc' => smAt('2027-03-01 17:00', 'UTC'), 'end_utc' => smAt('2027-03-01 18:00', 'UTC'), 'all_day' => 0, 'tzid' => 'UTC'], 'columns' => ['title' => 'Sample practice']], ['ownTitle' => 'keep']);
    checkEq('R1: likewise from its 17:00 occurrence', [false, []], [$p['refused'], $p['writes']['overrides']]);
    $long = smDrag($twice, '2027-03-01 09:00', '2027-03-01 09:00');
    $long['landed']['end_utc'] = smAt('2027-03-01 10:30', 'UTC');
    $p = smPlan($twice, [$evening], $long);
    checkEq('R1: a new length reaches the 17:00 exception at 17:00, its key kept', [[2 => ['end_utc' => '2027-03-15 18:30:00']], '2027-03-01 10:30:00'],
        [$p['writes']['overrides'], $p['writes']['master']['end_utc'] ?? null]);
    $p = smPlan($twice, [$evening], ['rrule' => 'FREQ=DAILY;BYHOUR=9,17;COUNT=40']);
    checkEq('R1: a new bound keeps the keys and counts the events the rule says', [[], 'FREQ=DAILY;BYHOUR=9,17;COUNT=40', 'Changes 60 events to 40, ends 20 Mar; 40 scheduled occurrences, 1 skipped'],
        [$p['writes']['overrides'], $p['writes']['master']['rrule'] ?? null, $p['plan']['bound']['words']]);
    $p = smPlan($twice, [$evening], ['rrule' => 'FREQ=DAILY;BYHOUR=9,17;COUNT=20']);
    checkEq('R1: a bound that ends before the 17:00 exception and the skip leaves both outside, by their keys', [['keep', 'delete'], [2], []],
        [smOptions($p, 'outside'), array_keys($p['writes']['detach']), $p['writes']['exdates']]);

    // R2: DAILY with BYDAY, 02:30 in Los Angeles from Mon 1 Mar 2027. Sabre's
    // daily iterator keeps the gap-shifted 03:30 after 14 Mar; the model's
    // keys follow it, and a rename keeps them as stored.
    $mwf = smSeries('Sample run', 'FREQ=DAILY;BYDAY=MO,WE,FR;COUNT=12', '2027-03-01 02:30', 60, false, ['2027-03-19 03:30']);
    $p = smPlan($mwf, [], ['acted' => smAt('2027-03-15 03:30')] + ['columns' => ['title' => 'Sample jog']]);
    checkEq('R2: the plan reads its days as sabre does: 02:30 before the change, 03:30 after', ['2027-03-15T03:30:00-07:00', '2027-03-17T03:30:00-07:00', '2027-03-22T03:30:00-07:00', '2027-03-24T03:30:00-07:00'],
        array_column($p['plan']['next'], 'start'));
    $own = smEdited($mwf, 2, '2027-03-15 03:30', ['title' => 'Sample race'], null, ['title']);
    $p = smPlan($mwf, [$own], ['columns' => ['title' => 'Sample jog']], ['ownTitle' => 'keep']);
    checkEq('R2: renamed, its 03:30 exception keeps its key and time, the skip its key; 12 events', [[], smAt('2027-03-15 03:30'), [smAt('2027-03-19 03:30')], '12 scheduled occurrences, 1 skipped, ends 26 Mar', ['title' => 'Sample jog']],
        [$p['writes']['overrides'], str_replace(['T', 'Z'], [' ', ''], (string) ($p['plan']['days'][1]['newKey'] ?? '')), $p['writes']['exdates'], $p['plan']['bound']['words'], $p['writes']['master']]);
    // A new clock rewrites the rule and keeps it daily: the plan's next and
    // the stored series agree, the exception follows to its new key.
    $p = smPlan($mwf, [$own], smDrag($mwf, '2027-03-01 02:30', '2027-03-01 09:00'));
    $m1 = $stored($mwf, $p['writes']);
    [$want, $got] = $agrees($p, $m1);
    checkEq('R2: moved to 09:00, the plan\'s next is the stored series\' expansion', $want, $got);
    checkEq('R2: and the exception is keyed on a 09:00 occurrence of it', [smAt('2027-03-15 09:00'), 'FREQ=DAILY;BYDAY=MO,WE,FR;COUNT=12'],
        [$p['writes']['overrides'][2]['recurrence_instance_utc'] ?? null, $m1['rrule']]);
    $p = smPlan($mwf, [$own], smDrag($mwf, '2027-03-01 02:30', '2027-03-02 02:30'));
    $m1 = $stored($mwf, $p['writes']);
    [$want, $got] = $agrees($p, $m1);
    checkEq('R2: dragged a day (written as weekly now), the plan and the stored series agree', $want, $got);
    $wkday = smSeries('Sample run', 'FREQ=DAILY;BYDAY=MO', '2027-03-01 02:30');
    $p = smPlan($wkday, [], smDrag($wkday, '2027-03-08 02:30', '2027-03-15 02:30'));
    $m1 = $stored($wkday, $p['writes']);
    [$want, $got] = $agrees($p, $m1);
    // Its first day after the gap, 15 Mar, shows at 03:30 in the expansion
    // (sabre's daily clock carried past 14 Mar); the dragged day was put at
    // 02:30 there, so it owns its time and the plan creates its row.
    checkEq('R2: one day dragged a whole week stays daily as written, and agrees with its expansion after the dragged day', ['FREQ=DAILY;BYDAY=MO', array_slice($want, 1)], [$m1['rrule'], array_slice($got, 1)]);
    checkEq('R2: the dragged day keeps 02:30 where it was put, an edited day of its own time', [[smAt('2027-03-15 02:30')], '["time"]', '2027-03-15T02:30:00-07:00'],
        [array_column($p['writes']['createEdited'], 'start_utc'), $p['writes']['createEdited'][0]['override_fields'] ?? null, $p['plan']['next'][0]['start'] ?? null]);

    // R2's differential: the model's keys for DAILY with BYDAY against the
    // expander the app persists with, across northern, southern and
    // half-hour zones, starts and clocks around their changes.
    $mismatch = [];
    $combos = 0;
    foreach (['America/Los_Angeles', 'Europe/Berlin', 'America/St_Johns', 'Australia/Sydney', 'America/Santiago', 'Pacific/Auckland', 'Australia/Lord_Howe', 'Australia/Adelaide', 'UTC'] as $tz) {
        foreach (['FREQ=DAILY;BYDAY=MO,WE,FR', 'FREQ=DAILY;BYDAY=SU', 'FREQ=DAILY;BYDAY=SA,SU;COUNT=30', 'FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR;UNTIL=20271231T235959Z'] as $rr) {
            foreach (['2026-09-01', '2027-03-01', '2027-03-12', '2027-10-25'] as $day) {
                foreach (['00:30', '01:30', '02:15', '02:30', '09:00'] as $clk) {
                    $start = new DateTimeImmutable("$day $clk", Time::zone($tz));
                    if ($start->format('H:i') !== $clk) {
                        continue;
                    }
                    $combos++;
                    $dn = Cal::dnOf($start);
                    [$h, $mi] = array_map('intval', explode(':', $clk));
                    $calc = new BetterCal\Domain\Series\Calc(Rule::parse($rr, $dn), $dn, $tz, false, [$h, $mi, 0], 3600);
                    $row = ['rrule' => $rr, 'start_utc' => Time::toDb($start), 'end_utc' => Time::toDb($start->modify('+1 hour')), 'tzid' => $tz, 'all_day' => 0, 'uid' => 'sm'];
                    $sabre = array_map(static fn(array $o): string => Time::toDb($o['start']), BetterCal\Domain\Recurrence::sabreExpand($row, $start->modify('-1 day'), $start->modify('+600 days')));
                    $mine = array_map(static fn(int $d): string => $calc->key($d), $calc->occurrences($dn + 599));
                    if ($sabre !== $mine) {
                        $mismatch[] = "$tz $rr $day $clk";
                    }
                }
            }
        }
    }
    checkEq('R2: DAILY with BYDAY keys as the expander does in ' . $combos . ' rule, zone and start combinations', [], $mismatch);

    // R3: a generated reading meets the parsed rules' constraint. The 1st
    // and the last day, its 1 Jan dragged to the 28th: 28 and -1 are one
    // day in February, so that reading is disabled with its reason, and
    // with the other one refused, the drag is.
    $ends = smSeries('Sample billing', 'FREQ=MONTHLY;BYMONTHDAY=1,-1;COUNT=4', '2027-01-01 09:00', 60, false, [], 'UTC');
    $endsRows = [smEdited($ends, 2, '2027-02-01 09:00', ['title' => 'Sample first'], null, ['title']), smEdited($ends, 3, '2027-02-28 09:00', ['title' => 'Sample last'], null, ['title'])];
    foreach (['ordinary' => [], 'two edited rows' => $endsRows] as $what => $rows) {
        $p = smPlan($ends, $rows, smDrag($ends, '2027-01-01 09:00', '2027-01-28 09:00'));
        checkEq("R3 ($what): refused, nothing written, the member reading disabled for the collision", [true, null, 'the 28th and the last day would be one day in some months'],
            [$p['refused'], $p['writes'], array_column(array_filter($p['plan']['refusals'], static fn(array $r): bool => ($r['option'] ?? '') === 'member'), 'words')[0] ?? null]);
    }
    $p = smPlan($ends, $endsRows, smDrag($ends, '2027-01-01 09:00', '2027-01-02 09:00'));
    checkEq('R3: to the 2nd nothing collides, and the reading stays', [false, 'member'], [$p['refused'], smGroup($p, 'reading')['selected'] ?? 'member']);
    checkEq('R3: the check covers lists of every kind (28 and -1, 3 and -29, 4MO and -1MO, 1MO and 3TU, two Mondays)', [true, true, true, false, true], [
        (static function (): bool {
            $r = Rule::parse('FREQ=MONTHLY;BYMONTHDAY=1,-1', 0);
            $r->members = [['t' => 'day', 'n' => 28], ['t' => 'day', 'n' => -1]];
            return $r->collision() !== null;
        })(),
        (static function (): bool {
            $r = Rule::parse('FREQ=MONTHLY;BYMONTHDAY=1,-1', 0);
            $r->members = [['t' => 'day', 'n' => 3], ['t' => 'day', 'n' => -29]];
            return $r->collision() !== null;
        })(),
        (static function (): bool {
            $r = Rule::parse('FREQ=MONTHLY;BYDAY=1MO,3MO', 0);
            $r->members = [['t' => 'nth', 'n' => 4, 'wd' => 0], ['t' => 'nth', 'n' => -1, 'wd' => 0]];
            return $r->collision() !== null;
        })(),
        Rule::parse('FREQ=MONTHLY;BYDAY=1MO,3TU', 0)->collision() !== null,
        (static function (): bool {
            $r = Rule::parse('FREQ=WEEKLY;BYDAY=MO,WE', 0);
            $r->members = [0, 0];
            return $r->collision() !== null;
        })(),
    ]);

    // R4: a rule that names its clock takes the new one in every reading,
    // a drag across days included; the plan's next is the stored expansion.
    foreach ([
        'hour and minute' => ['FREQ=DAILY;BYHOUR=9;BYMINUTE=30;COUNT=4', 'FREQ=DAILY;BYHOUR=10;BYMINUTE=45;COUNT=4'],
        'hour' => ['FREQ=DAILY;BYHOUR=9;COUNT=4', 'FREQ=DAILY;BYHOUR=10;COUNT=4'],
        'minute' => ['FREQ=DAILY;BYMINUTE=30;COUNT=4', 'FREQ=DAILY;BYMINUTE=45;COUNT=4'],
    ] as $what => [$rr, $written]) {
        $clockSeries = smSeries('Sample standup', $rr, '2027-01-01 09:30', 60, false, [], 'UTC');
        foreach (['moved a day and to 10:45' => '2027-01-03 10:45', 'to 10:45 the same day' => '2027-01-02 10:45'] as $how => $to) {
            $p = smPlan($clockSeries, [], smDrag($clockSeries, '2027-01-02 09:30', $to));
            $m1 = $stored($clockSeries, $p['writes']);
            [$want, $got] = $agrees($p, $m1);
            checkEq("R4 ($what, $how): the rule says 10:45 and the plan's next is the stored expansion", [$written, 'Daily at 10:45', true, $want],
                [$m1['rrule'], $p['plan']['rule']['words'], count($want) >= 3, $got]);
        }
    }
    $weekly = smSeries('Sample standup', 'FREQ=WEEKLY;BYDAY=MO,WE;BYHOUR=9;BYMINUTE=30', '2027-01-04 09:30', 60, false, [], 'UTC');
    $p = smPlan($weekly, [], smDrag($weekly, '2027-01-06 09:30', '2027-01-07 10:45'), ['reading' => 'pattern']);
    $m1 = $stored($weekly, $p['writes']);
    [$want, $got] = $agrees($p, $m1);
    checkEq('R4: a weekly list\'s pattern reading moved a day and to 10:45 writes 10:45 too', ['FREQ=WEEKLY;BYDAY=TU,TH;BYHOUR=10;BYMINUTE=45', $want], [$m1['rrule'], $got]);

    // ---- One structure: the structure review's eleven findings ----------
    // Each a consequence of the model's structure (series-model.md, The
    // Function): one delta against the occurrence acted on as it shows, the
    // day acted on an ordinary exception, keys from the expander, `kind`
    // for words and `direct` only. Every series invented.
    $keysOk = static function (array $p): bool {
        foreach ($p['writes']['overrides'] ?? [] as $cols) {
            if (array_key_exists('recurrence_instance_utc', $cols) && (string) $cols['recurrence_instance_utc'] === '') {
                return false;
            }
        }
        foreach ($p['plan']['days'] as $d) {
            if ($d['newKey'] === null && !in_array($d['outcome'], ['separate', 'deleted', 'dropped'], true)) {
                return false;
            }
        }
        return true;
    };
    $dayOf = static fn(array $p, string $key): ?array => array_values(array_filter($p['plan']['days'], static fn(array $d): bool => $d['key'] === $key))[0] ?? null;
    $utcWeekly = smSeries('Sample choir', 'FREQ=WEEKLY;BYDAY=TU;COUNT=10', '2027-01-05 19:00', 60, false, [], 'UTC');
    $feb9 = '2027-02-09T19:00:00Z';

    // F1: a new time with a new end that leaves the day acted on outside.
    foreach (['its own title' => [[], ['title']], 'its own time' => [['start_utc' => smAt('2027-02-09 18:00', 'UTC'), 'end_utc' => smAt('2027-02-09 19:00', 'UTC')], ['title', 'time']]] as $what => [$cols, $own]) {
        $row = smEdited($utcWeekly, 2, '2027-02-09 19:00', ['title' => 'Sample concert'] + $cols, null, $own);
        $seenStart = $cols['start_utc'] ?? smAt('2027-02-09 19:00', 'UTC');
        $edit = ['acted' => smAt('2027-02-09 19:00', 'UTC'), 'rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=3',
            'landed' => ['start_utc' => $seenStart, 'end_utc' => Time::toDb(Time::fromDb($seenStart)->modify('+2 hours')), 'all_day' => 0, 'tzid' => 'UTC']];
        $p = null;
        try {
            $p = smPlan($utcWeekly, [$row], $edit);
        } catch (\Throwable $e) {
            check("F1 ($what): a new length with a count of 3 plans without an error", false, $e->getMessage());
        }
        if ($p !== null) {
            checkEq("F1 ($what): the day acted on, outside the new series, becomes an event of its own where it was put; no key is empty", [
                true, 'separate', Time::toDb(Time::fromDb($seenStart)->modify('+2 hours')), true,
            ], [$keysOk($p), $dayOf($p, $feb9)['outcome'] ?? null, $p['writes']['detach'][2]['end_utc'] ?? null, str_contains($p['plan']['bound']['words'], '+ 1 separate')]);
        }
    }
    $allDayWeekly = smSeries('Sample fair', 'FREQ=WEEKLY;BYDAY=TU;COUNT=10', '2027-01-05', 60, true);
    $p = smPlan($allDayWeekly, [smEdited($allDayWeekly, 2, '2027-02-09', ['title' => 'Sample fair, day two'], null, ['title'])],
        ['acted' => smAt('2027-02-09 00:00', 'UTC'), 'rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=3', 'landed' => ['start_utc' => smAt('2027-02-09 00:00', 'UTC'), 'end_utc' => smAt('2027-02-11 00:00', 'UTC'), 'all_day' => 1, 'tzid' => 'UTC']]);
    checkEq('F1 (all-day): two days long with a count of 3: the day becomes an event of its own, no empty key', [true, 'separate'],
        [$keysOk($p), $dayOf($p, '2027-02-09T00:00:00Z')['outcome'] ?? null]);

    // F2: a rename of a rule the expander can't read keeps every row.
    $odd = smSeries('Sample choir', 'FREQ=WEEKLY;BYDAY=TU;COUNT=10;RSCALE=GREGORIAN', '2027-01-05 19:00', 60, false, [], 'UTC');
    $oddRow = smEdited($odd, 2, '2027-02-09 19:00', ['location' => 'Sample Hall'], null, ['location']);
    $p = smPlan($odd, [$oddRow], ['columns' => ['title' => 'Sample chorus']]);
    checkEq('F2: renaming a series whose rule the expander can\'t read keeps its edited day, on its key, with no outside group', [
        [], [], 'stays', null, true,
    ], [$p['writes']['detach'], smOptions($p, 'outside'), $dayOf($p, $feb9)['outcome'] ?? null, $p['writes']['overrides'][2]['recurrence_instance_utc'] ?? null, $p['plan']['direct']]);
    checkEq('F2: anything needing keys is still refused for it (a new bound)', 'unreadable',
        smPlan($odd, [$oddRow], ['rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=4;RSCALE=GREGORIAN'])['plan']['refusals'][0]['code'] ?? null);

    // F3: an all-day edited day of a timed series dragged a day later.
    $f3 = smSeries('Sample choir', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 19:00', 60, false, [], 'UTC');
    $f3row = smEdited($f3, 2, '2027-02-09 19:00', ['all_day' => 1, 'tzid' => 'UTC', 'start_utc' => smAt('2027-02-09 00:00', 'UTC'), 'end_utc' => smAt('2027-02-10 00:00', 'UTC')], null, ['allDay', 'time', 'length']);
    $p = smPlan($f3, [$f3row], ['acted' => smAt('2027-02-09 19:00', 'UTC'), 'landed' => ['start_utc' => smAt('2027-02-10 00:00', 'UTC'), 'end_utc' => smAt('2027-02-11 00:00', 'UTC'), 'all_day' => 1, 'tzid' => 'UTC']]);
    checkEq('F3: every event a day later at 19:00, the series timed; the edited day all-day on 10 Feb, keyed on 10 Feb at 19:00', [
        'FREQ=WEEKLY;BYDAY=WE', '2027-01-06 19:00:00', false, ['2027-02-10 19:00:00', '2027-02-10 00:00:00', '2027-02-11 00:00:00'],
    ], [$p['writes']['master']['rrule'] ?? null, $p['writes']['master']['start_utc'] ?? null, isset($p['writes']['master']['all_day']), [
        $p['writes']['overrides'][2]['recurrence_instance_utc'] ?? null, $p['writes']['overrides'][2]['start_utc'] ?? null, $p['writes']['overrides'][2]['end_utc'] ?? null,
    ]]);

    // F4: from an edited day, a field set back to the series' value.
    $f4row = smEdited($utcWeekly, 2, '2027-02-09 19:00', ['title' => 'Sample concert'], null, ['title']);
    $p = smPlan($utcWeekly, [$f4row], ['acted' => smAt('2027-02-09 19:00', 'UTC'), 'columns' => ['title' => 'Sample choir']]);
    checkEq('F4: the series\' title sent from the day that owns another: the day takes it, is left with nothing of its own and goes back to the series, and the plan isn\'t direct', [
        false, [2], [], [],
    ], [$p['plan']['direct'], $p['writes']['delete'], $p['writes']['overrides'], array_keys($p['writes']['master'])]);
    $p = smPlan($utcWeekly, [smEdited($utcWeekly, 2, '2027-02-09 19:00', [], null, ['tags'])], ['acted' => smAt('2027-02-09 19:00', 'UTC'), 'tags' => ['sample']],
        [], ['masterTags' => ['sample'], 'rowTags' => [2 => ['extra']]]);
    checkEq('F4: the same for tags: the day takes the series\' tags and goes back to the series', [false, [2], null], [$p['plan']['direct'], $p['writes']['delete'], $p['writes']['tags'][2] ?? null]);

    // F5: a rule naming its hour in a zone with a spring change: the
    // model's days are the expander's (sabre steps hours and yields no 14 Mar).
    $f5 = smSeries('Sample night watch', 'FREQ=DAILY;BYHOUR=2;BYMINUTE=30;COUNT=8', '2027-03-11 02:30');
    $f5row = smEdited($f5, 2, '2027-03-15 02:30', ['title' => 'Sample night watch, inspection'], null, ['title']);
    $p = smPlan($f5, [$f5row], ['columns' => ['title' => 'Sample late watch']]);
    $f5next = array_map(static fn(array $n): string => substr($n['start'], 0, 16), smPlan($f5, [$f5row], ['acted' => smAt('2027-03-12 02:30'), 'columns' => ['title' => 'Sample late watch']])['plan']['next']);
    checkEq('F5: a rename says 8 events ending 19 Mar and lists no 14 Mar', [true, false], [str_starts_with($p['plan']['bound']['words'], '8 events, ends 19 Mar'), in_array('2027-03-14T03:30', $f5next, true)]);
    $p = smPlan($f5, [$f5row], smDrag($f5, '2027-03-12 02:30', '2027-03-11 02:30'));
    $m1 = $stored($f5, $p['writes']);
    $sabreKeys = array_map(static fn(array $o): string => Time::toDb($o['start']), BetterCal\Domain\Recurrence::sabreExpand($m1, Time::fromDb($m1['start_utc'])->modify('-1 day'), Time::fromDb($m1['start_utc'])->modify('+60 days')));
    $f5key = $p['writes']['overrides'][2]['recurrence_instance_utc'] ?? null;
    check('F5: a drag a day earlier keys the edited day on an occurrence the expander yields, or gives it an outcome', $f5key === null ? isset($p['writes']['detach'][2]) || in_array(2, $p['writes']['delete'], true) : in_array($f5key, $sabreKeys, true), json_encode([$f5key, $sabreKeys]));

    // F6: the same edit on the same day, named by its key: one plan.
    $f6 = smSeries('Sample choir', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 19:00', 60, false, [], 'UTC');
    $f6row = smEdited($f6, 2, '2027-02-09 19:00', ['title' => 'Sample concert', 'start_utc' => smAt('2027-02-09 17:00', 'UTC'), 'end_utc' => smAt('2027-02-09 18:00', 'UTC')], null, ['title', 'time']);
    $p = smPlan($f6, [$f6row], ['acted' => smAt('2027-02-09 19:00', 'UTC'), 'columns' => ['title' => 'Sample chorus'],
        'landed' => ['start_utc' => smAt('2027-02-09 18:00', 'UTC'), 'end_utc' => smAt('2027-02-09 19:00', 'UTC'), 'all_day' => 0, 'tzid' => 'UTC']]);
    checkEq('F6: an hour later and renamed, acting on the day at its own 17:00: the series at 20:00, the day takes the title and lands at 18:00', [
        '2027-01-05 20:00:00', 'Sample chorus', '2027-02-09 18:00:00',
    ], [$p['writes']['master']['start_utc'] ?? null, $p['writes']['overrides'][2]['title'] ?? null, $p['writes']['overrides'][2]['start_utc'] ?? null]);
    $p = smPlan($f6, [$f6row], ['acted' => smAt('2027-02-09 19:00', 'UTC'), 'columns' => ['title' => 'Sample chorus']]);
    checkEq('F6: a rename naming a day that owns its title is no direct edit', false, $p['plan']['direct']);

    // F7: a timed edited day of an all-day series lands where dropped.
    $fair = smSeries('Sample fair', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05', 60, true);
    $f7row = smEdited($fair, 2, '2027-02-09', ['all_day' => 0, 'tzid' => 'America/Los_Angeles', 'start_utc' => smAt('2027-02-09 09:00'), 'end_utc' => smAt('2027-02-09 10:00')], null, ['allDay', 'time']);
    $p = smPlan($fair, [$f7row], ['acted' => smAt('2027-02-09 00:00', 'UTC'), 'landed' => ['start_utc' => smAt('2027-02-10 10:00'), 'end_utc' => smAt('2027-02-10 11:00'), 'all_day' => 0, 'tzid' => 'America/Los_Angeles']]);
    checkEq('F7: the all-day series moves to Wednesday; the timed day shows on 10 Feb at 10:00 where it was dropped', ['FREQ=WEEKLY;BYDAY=WE', smAt('2027-02-10 10:00')],
        [$p['writes']['master']['rrule'] ?? null, $p['writes']['overrides'][2]['start_utc'] ?? null]);

    // F8: a zone sent back unchanged from a day in its own zone.
    $f8row = smEdited($f6, 2, '2027-02-09 19:00', ['tzid' => 'Europe/Berlin', 'start_utc' => smAt('2027-02-09 19:00', 'Europe/Berlin'), 'end_utc' => smAt('2027-02-09 20:00', 'Europe/Berlin')], null, ['time']);
    $p = smPlan($f6, [$f8row], ['acted' => smAt('2027-02-09 19:00', 'UTC'), 'columns' => ['title' => 'Sample chorus'],
        'landed' => ['start_utc' => $f8row['start_utc'], 'end_utc' => $f8row['end_utc'], 'all_day' => 0, 'tzid' => 'Europe/Berlin']]);
    checkEq('F8: the day\'s own zone sent back as it is changes no zone: no zone group, the series stays in UTC', [[], false],
        [smOptions($p, 'zone'), isset($p['writes']['master']['tzid'])]);

    // F9: the zone's "same moment" is a delta on the instant.
    $f9 = smSeries('Sample early call', 'FREQ=WEEKLY;BYDAY=SU', '2027-03-07 02:30');
    $f9edit = ['acted' => smAt('2027-03-14 03:30'), 'landed' => ['start_utc' => smAt('2027-03-14 03:30', 'Europe/Berlin'), 'end_utc' => smAt('2027-03-14 04:30', 'Europe/Berlin'), 'all_day' => 0, 'tzid' => 'Europe/Berlin']];
    $p = smPlan($f9, [], $f9edit, ['zone' => 'moment']);
    $f9at = Time::fromDb((string) ($p['writes']['master']['start_utc'] ?? $f9['start_utc']))->setTimezone(Time::zone('Europe/Berlin'))->format('H:i');
    checkEq('F9: from the gap-shifted 14 Mar, the same moment in Berlin is every Sunday at 10:30, as from any other Sunday', ['10:30', '10:30'],
        [$f9at, Time::fromDb((string) (smPlan($f9, [], ['acted' => smAt('2027-03-21 02:30'), 'landed' => ['start_utc' => smAt('2027-03-21 02:30', 'Europe/Berlin'), 'end_utc' => smAt('2027-03-21 03:30', 'Europe/Berlin'), 'all_day' => 0, 'tzid' => 'Europe/Berlin']], ['zone' => 'moment'])['writes']['master']['start_utc'] ?? ''))->setTimezone(Time::zone('Europe/Berlin'))->format('H:i')]);

    // F10: "move with their events" wherever correspondence by member exists.
    $f10 = [
        'monthly on the 15th to the 16th' => [smSeries('Sample rent', 'FREQ=MONTHLY;BYMONTHDAY=15', '2027-01-15 09:00'), '2027-03-15 09:00', 'FREQ=MONTHLY;BYMONTHDAY=16', '2027-03-16'],
        'Monday and Wednesday to Tuesday and Thursday' => [smSeries('Sample class', 'FREQ=WEEKLY;BYDAY=MO,WE', '2027-01-04 09:00'), '2027-03-10 09:00', 'FREQ=WEEKLY;BYDAY=TU,TH', '2027-03-11'],
        'Tuesday to Thursday' => [smSeries('Sample choir', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 19:00'), '2027-03-09 19:00', 'FREQ=WEEKLY;BYDAY=TH', '2027-03-11'],
    ];
    foreach ($f10 as $what => [$series, $edited, $rule, $want]) {
        $row = smEdited($series, 2, $edited, ['title' => 'Sample, edited'], null, ['title']);
        $p = smPlan($series, [$row], ['rrule' => $rule]);
        $mapped = smPlan($series, [$row], ['rrule' => $rule], ['reading' => 'map']);
        $nk = (string) ($mapped['writes']['overrides'][2]['recurrence_instance_utc'] ?? '');
        checkEq("F10 ($what): the new rule offers moving edited days with their events, and that moves the day by member", [['map', 'keep'], $want],
            [smOptions($p, 'reading'), $nk === '' ? null : Time::fromDb($nk)->setTimezone(Time::zone('America/Los_Angeles'))->format('Y-m-d')]);
    }

    // F11: a new series whose first day falls in the spring gap.
    $start = new ReflectionMethod(BetterCal\Domain\Events::class, 'seriesStart');
    $typed = ['rrule' => 'FREQ=WEEKLY;BYDAY=SU;COUNT=4', 'start_utc' => smAt('2027-03-13 02:30'), 'end_utc' => smAt('2027-03-13 03:30'), 'all_day' => 0, 'tzid' => 'America/Los_Angeles', 'exdates_json' => null];
    $aligned = array_merge($typed, $start->invoke(null, $typed));
    $words = BetterCal\Domain\Events::creationPlan($aligned, $typed);
    checkEq('F11: typed on Sat 13 Mar at 02:30, every Sunday: stored from 7 Mar, skipped, COUNT one higher; shown 14 Mar at 03:30, then 02:30; the line says 02:30', [
        smAt('2027-03-07 02:30'), 'FREQ=WEEKLY;BYDAY=SU;COUNT=5', [smAt('2027-03-07 02:30')], ['2027-03-14T03:30:00-07:00', '2027-03-21T02:30:00-07:00'], 'Weekly on Sunday at 02:30', '4 events, ends 4 Apr 2027',
    ], [$aligned['start_utc'], $aligned['rrule'], json_decode((string) $aligned['exdates_json'], true), array_slice($shown($aligned), 0, 2), $words['rule']['words'], $words['bound']['words']]);

    // ---- the harness's root causes on the rebuilt harness, each by its structural rule ----

    // Cause 1: the expander steps MINUTELY (sabre's iterator yielded the start again).
    $twelve = smSeries('Sample watch', 'FREQ=MINUTELY;INTERVAL=720;COUNT=3', '2027-01-30 09:00', 90, false, [], 'Europe/Berlin');
    $exp = array_map(static fn(array $o): string => Time::toDb($o['start']), BetterCal\Domain\Recurrence::sabreExpand($twelve, Time::fromDb($twelve['start_utc'])->modify('-1 day'), Time::fromDb($twelve['start_utc'])->modify('+5 days')));
    checkEq('C1: every 720 minutes is every 12 hours, in the window and in the model', [[smAt('2027-01-30 09:00', 'Europe/Berlin'), smAt('2027-01-30 21:00', 'Europe/Berlin'), smAt('2027-01-31 09:00', 'Europe/Berlin')], 3],
        [$exp, count(array_unique(array_column(smPlan($twelve, [], ['columns' => ['title' => 'Sample shift']])['plan']['next'], 'start')))]);

    // Cause 2: a gain at an edge is the image of an old pattern day outside the series, however far the member moved.
    $mw = smSeries('Sample relay', 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=4', '2027-01-04 18:00');
    $p = smPlan($mw, [], smDrag($mw, '2027-01-11 18:00', '2027-02-01 18:00'), ['reading' => 'member']);
    $skipDays = array_map(static fn(string $k): string => Time::fromDb($k)->setTimezone(Time::zone('America/Los_Angeles'))->format('Y-m-d'), $p['writes']['exdates']);
    checkEq('C2: Mondays moved three weeks: the new Mondays and Wednesdays with no session behind them are left out, a week with no image included', [['without', 'add'], ['2027-01-11', '2027-01-18', '2027-01-20', '2027-01-27']],
        [smOptions($p, 'edge'), $skipDays]);

    // Cause 3: made all-day, a rule drops the clock parts it named.
    $nine = smSeries('Sample standup', 'FREQ=WEEKLY;BYDAY=MO;BYHOUR=9;BYMINUTE=0', '2027-01-04 09:00');
    $p = smPlan($nine, [], ['acted' => $nine['start_utc'], 'landed' => ['start_utc' => '2027-01-04 00:00:00', 'end_utc' => '2027-01-05 00:00:00', 'all_day' => 1, 'tzid' => 'UTC']]);
    checkEq('C3: a BYHOUR rule made all-day is written without BYHOUR and BYMINUTE', 'FREQ=WEEKLY;BYDAY=MO', $p['writes']['master']['rrule'] ?? null);

    // Cause 4: on Google, a new hour written into the rule changes its text, so it isn't offered.
    $late = smSeries('Sample call', 'FREQ=WEEKLY;BYDAY=WE;BYHOUR=23;BYMINUTE=30', '2027-03-31 23:30', 60, false, [], 'Europe/Berlin');
    $p = smPlan($late, [], smDrag($late, '2027-04-07 23:30', '2027-04-07 23:00'), [], ['google' => true]);
    checkEq('C4: a Google series that names its hour refuses a time change', [true, 'google'], [$p['refused'], $p['plan']['refusals'][0]['code'] ?? null]);

    // Cause 5: on Google no exception has an outcome, the day acted on included.
    $daily = smSeries('Sample walk', 'FREQ=DAILY', '2027-01-30 21:15', 30, false, [], 'Asia/Kolkata');
    $row = smEdited($daily, 2, '2027-02-09 21:15', ['title' => 'Sample walk, edited'], null, ['title']);
    $p = smPlan($daily, [$row], smDrag($daily, '2027-02-09 21:15', '2027-02-19 21:15', ['rrule' => 'FREQ=DAILY;COUNT=2']), [], ['google' => true]);
    checkEq('C5: a Google plan whose count ends before the day acted on claims nothing for it', [[], []],
        [array_values(array_filter($p['plan']['days'], static fn(array $d): bool => $d['kind'] === 'edited')), $p['writes']['detach']]);

    // Cause 6: a list sent back in another order is the same rule, so a day-set rule still refuses the drag.
    $dayset = smSeries('Sample market', 'FREQ=WEEKLY;BYMONTH=1,2;BYDAY=MO,FR', '2027-01-04 09:00');
    $p = smPlan($dayset, [], smDrag($dayset, '2027-01-08 09:00', '2027-01-12 09:00', ['rrule' => 'FREQ=WEEKLY;BYMONTH=2,1;BYDAY=FR,MO']));
    checkEq('C6: the stored day-set rule sent back reordered is no new rule, and its drag is refused', [true, 'dayset'], [$p['refused'], $p['plan']['refusals'][0]['code'] ?? null]);
    checkEq('C6: an nth list reordered compares equal', Rule::parse('FREQ=MONTHLY;BYDAY=1MO,3MO', 0)->comparable(), Rule::parse('FREQ=MONTHLY;BYDAY=3MO,1MO', 0)->comparable());

    // Cause 7: the stored end sent back as a date with an all-day toggle is the same end.
    $timed = smSeries('Sample lesson', 'FREQ=MONTHLY;UNTIL=20270415T170000Z', '2027-01-15 10:00');
    $p = smPlan($timed, [], ['acted' => $timed['start_utc'], 'rrule' => 'FREQ=MONTHLY;UNTIL=20270415', 'landed' => ['start_utc' => '2027-01-15 00:00:00', 'end_utc' => '2027-01-16 00:00:00', 'all_day' => 1, 'tzid' => 'UTC']]);
    checkEq('C7: made all-day with its end sent as a date: no bound group, the end the same last day', [null, 'FREQ=MONTHLY;UNTIL=20270415'],
        [smGroup($p, 'bound'), $p['writes']['master']['rrule'] ?? null]);

    // Cause 8: under a new pattern the day acted on is an ordinary exception: off the rule, it is outside.
    $tue = smSeries('Sample choir', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 19:00');
    $moved = smEdited($tue, 2, '2027-01-19 19:00', ['title' => 'Sample choir, moved'], '2027-01-20 19:00', ['title', 'date']);
    $p = smPlan($tue, [$moved], ['acted' => smAt('2027-01-19 19:00'), 'rrule' => 'FREQ=WEEKLY;BYDAY=FR', 'landed' => ['start_utc' => smAt('2027-01-20 19:00'), 'end_utc' => smAt('2027-01-20 20:00'), 'all_day' => 0, 'tzid' => 'America/Los_Angeles']], ['reading' => 'keep']);
    checkEq('C8: the edited day acted on, its key off the new rule, gets the outside choice and is kept as its own event', [['keep', 'delete'], [2]],
        [smOptions($p, 'outside'), array_keys($p['writes']['detach'])]);

    // Cause 9: an occurrence is what the stored series' own expansion yields, a DAILY;BYDAY day after a gap included.
    $after = BetterCal\Domain\Series\Calc::fromRow(smSeries('Sample run', 'FREQ=DAILY;BYDAY=MO,WE,FR', '2027-03-01 02:30'));
    checkEq('C9: the stored series yields 17 Mar at its carried 03:30', [smAt('2027-03-17 03:30')], $after->keysOn($after->dnOf(smAt('2027-03-17 03:30'))));

    // Cause 10: a start in a repeated hour is expanded from the instant it is stored at.
    $nz = smSeries('Sample swim', 'FREQ=WEEKLY;BYDAY=SU', '2027-04-04 02:30', 60, false, [], 'Pacific/Auckland');
    $nz['start_utc'] = '2027-04-03 13:30:00';
    $nz['end_utc'] = '2027-04-03 14:30:00';
    $first = BetterCal\Domain\Recurrence::sabreExpand($nz, Time::fromDb('2027-04-01 00:00:00'), Time::fromDb('2027-04-20 00:00:00'))[0]['start'] ?? null;
    checkEq('C10: 02:30 on 4 Apr in Auckland, stored as its first instant, is that instant in the window too', '2027-04-03 13:30:00', $first === null ? null : Time::toDb($first));

    // Cause 11: next lists from where the request put the day when that is earlier than where it lands.
    $three = smSeries('Sample class', 'FREQ=DAILY;COUNT=3', '2027-05-31', 1440, true);
    $own = smEdited($three, 2, '2027-06-01', ['title' => 'Sample class, early'], '2027-05-29', ['title', 'date']);
    $p = smPlan($three, [$own], ['acted' => '2027-06-01 00:00:00', 'landed' => ['start_utc' => '2027-05-27 00:00:00', 'end_utc' => '2027-05-28 00:00:00', 'all_day' => 1, 'tzid' => 'UTC']]);
    checkEq('C11: dragged from its own date two days earlier: next lists its day, then the series from the first', ['2027-05-27', '2027-05-29', '2027-05-31'], smNext($p));

    // Cause 13: a 30th dragged by whole months stays the 30th, one reading.
    $thirty = smSeries('Sample rent', 'FREQ=MONTHLY;BYMONTHDAY=30', '2027-01-30 09:00');
    $p = smPlan($thirty, [], smDrag($thirty, '2027-04-30 09:00', '2027-05-30 09:00'));
    checkEq('C13: every 30th moved a month is offered no clamp and stays as written', [[], 'FREQ=MONTHLY;BYMONTHDAY=30'], [smOptions($p, 'reading'), $p['plan']['rule']['after']]);

    // A new start in a skipped hour is stored from the period before whatever
    // the bound: the anchor is the pattern's question, and an UNTIL at the
    // last day's clock doesn't change which days follow. First Saturdays and
    // last Sundays in Auckland, the first (27 Sep) in the spring gap, stored
    // from 5 Sep; every first Saturday dragged to the third Sunday.
    $nzTz = 'Pacific/Auckland';
    $nz = smSeries('Sample market', 'FREQ=MONTHLY;BYDAY=1SA,-1SU;UNTIL=20270101T133000Z', '2026-09-05 02:30', 60, false, ['2026-09-05 02:30'], $nzTz);
    $p = smPlan($nz, [], smDrag($nz, '2026-11-07 02:30', '2026-11-15 02:30'), ['reading' => 'member']);
    $shown = smShows($nz, $p['writes'], '2027-02-28');
    checkEq('a gap start under an UNTIL: stored from 20 Sep (skipped), 27 Sep first, 17 Jan the last day', [
        smAt('2026-09-20 02:30', $nzTz), true, '2026-09-27', '2027-01-17',
    ], [$p['writes']['master']['start_utc'] ?? null, in_array(smAt('2026-09-20 02:30', $nzTz), $p['writes']['exdates'], true), $shown[0] ?? null, $shown[count($shown) - 1] ?? null]);

    // A reading that leaves no events is refused on its own row, and the
    // rest of the plan stands: Mon, Tue and Thu changed to Sun, Mon and Wed
    // ending 4 Mar. Read as the old days shifted (six days later) it starts
    // on 7 Mar, after its end; kept where they are, it has 1 and 3 Mar.
    $three = smSeries('Sample class', 'FREQ=WEEKLY;BYDAY=MO,TU,TH;COUNT=11', '2027-03-01 02:30');
    $p = smPlan($three, [], ['acted' => smAt('2027-03-09 02:30'), 'rrule' => 'FREQ=WEEKLY;BYDAY=SU,MO,WE;UNTIL=20270304T235959Z']);
    checkEq('an empty reading is a disabled row, the other preselected and written', [['map!', 'keep'], 'keep', 'FREQ=WEEKLY;BYDAY=SU,MO,WE;UNTIL=20270304T235959Z', ['2027-03-01', '2027-03-03']], [
        smOptions($p, 'reading'), smGroup($p, 'reading')['selected'] ?? null, $p['writes']['master']['rrule'] ?? null, $p['writes'] === null ? null : smShows($three, $p['writes'], '2027-03-31'),
    ]);

    // A series stored at the later of two instants its wall clock names (02:30
    // on 31 Oct 2027 in Berlin, sent with +01:00) is read from the instant it
    // is stored at: its first key is the window's, and a field edit keeps it.
    $later = smSeries('Sample walk', 'FREQ=WEEKLY;BYDAY=MO,TU,TH,SA,SU', '2027-10-31 02:30', 60, false, [], 'Europe/Berlin');
    $later['start_utc'] = '2027-10-31 01:30:00';
    $later['end_utc'] = '2027-10-31 02:30:00';
    $calc = BetterCal\Domain\Series\Calc::fromRow($later);
    $window = array_map(static fn(array $o): string => Time::toDb($o['start']), BetterCal\Domain\Recurrence::sabreExpand($later, Time::fromDb('2027-10-30 00:00:00'), Time::fromDb('2027-11-03 00:00:00')));
    checkEq('a start stored at the later instant of a repeated hour: the model\'s keys are the window\'s, its first the stored one', [$window, '2027-10-31 01:30:00'],
        [array_column($calc->keys($calc->startDn + 2), 1), $calc->keysOn($calc->startDn)[0] ?? null]);
    $p = smPlan($later, [], ['columns' => ['title' => 'Sample stroll']]);
    checkEq('and a rename of it keeps its stored start', [false, ['title' => 'Sample stroll']], [isset($p['writes']['master']['start_utc']), $p['writes']['master']]);

    // A list of month days with a member counted from the end, shifted as a
    // whole: δ is the dragged member's change in its own counting, so the
    // dragged day lands where it was dropped. The 15th and the last day, its
    // 30 Jun dragged to 29 Jul (the third-last): the 13th and the third-last.
    $ends = smSeries('Sample review', 'FREQ=MONTHLY;BYMONTHDAY=15,-1;COUNT=8', '2027-04-30 09:00', 30, false, [], 'UTC');
    $p = smPlan($ends, [], smDrag($ends, '2027-06-30 09:00', '2027-07-29 09:00'), ['reading' => 'pattern']);
    checkEq('a month-day list with a last day, its whole pattern shifted from 30 Jun to 29 Jul: every member + δ = -2, the dragged day on 29 Jul, no edited row made', [
        'FREQ=MONTHLY;BYMONTHDAY=13,-3;COUNT=8', true, [],
    ], [$p['writes']['master']['rrule'] ?? null, in_array('2027-07-29', smShows($ends, $p['writes'], '2027-12-31'), true), $p['writes']['createEdited'] ?? []]);

    // One set position is the set-position row as several are: the first
    // weekday, its Fri 1 Oct dragged to Mon 4 Oct (the second weekday), is the
    // second weekday; dragged to the next month's first weekday it stays.
    $firstWd = smSeries('Sample report', 'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=1', '2027-09-01 09:00', 60, false, [], 'UTC');
    $p = smPlan($firstWd, [], smDrag($firstWd, '2027-10-01 09:00', '2027-10-04 09:00'));
    checkEq('the first weekday dragged to the second: every position moves by one', 'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=2', $p['writes']['master']['rrule'] ?? null);
    $p = smPlan($firstWd, [], smDrag($firstWd, '2027-10-01 09:00', '2027-11-01 09:00'));
    checkEq('and to the next month\'s first weekday: the rule stays, never a day by number', [null, '2027-10-01'], [$p['writes']['master']['rrule'] ?? null, smShows($firstWd, $p['writes'], '2027-10-31')[0] ?? null]);

    // The next events are the ones that show from the day acted on: an
    // edited day keyed later but kept on an earlier date of its own isn't one.
    $every2 = smSeries('Sample run', 'FREQ=DAILY;INTERVAL=2;COUNT=20', '2027-04-01 09:00', 30, false, [], 'UTC');
    $early = smEdited($every2, 2, '2027-04-11 09:00', [], '2027-04-08 09:00', ['date']);
    $p = smPlan($every2, [$early], smDrag($every2, '2027-04-01 09:00', '2027-04-21 09:00'), ['movedDays' => 'keep']);
    checkEq('next lists from where the day acted on lands, an edited day shown before it left out', ['2027-04-21', '2027-04-23', '2027-04-25', '2027-04-27'], smNext($p));

    // Review F3: MINUTELY and SECONDLY by RFC 5545 3.3.10's table, against
    // sets written out from the RFC's own examples and the review's tables,
    // never against another reading of the same iterator. Each set is read
    // three ways: the iterator alone, the window (Recurrence::sabreExpand)
    // and the model's own expansion (a plan's next dates through Calc).
    $iterate = static function (string $rrule, string $startLocal, string $tz, int $n): array {
        $it = new BetterCal\Domain\Series\RuleIterator($rrule, new DateTimeImmutable($startLocal, Time::zone($tz)));
        $out = [];
        for ($it->rewind(); $it->valid() && count($out) < $n; $it->next()) {
            $out[] = Time::toDb(DateTimeImmutable::createFromInterface($it->current()));
        }
        return $out;
    };
    $window = static function (string $rrule, string $startLocal, string $tz, int $n): array {
        $start = smAt($startLocal, $tz);
        $row = ['id' => 1, 'rrule' => $rrule, 'start_utc' => $start, 'end_utc' => Time::toDb(Time::fromDb($start)->modify('+1 second')), 'all_day' => 0, 'tzid' => $tz];
        $occ = BetterCal\Domain\Recurrence::sabreExpand($row, Time::fromDb($start), Time::fromDb($start)->modify('+800 days'));
        return array_slice(array_map(static fn(array $o): string => Time::toDb($o['start']), $occ), 0, $n);
    };
    $modelNext = static function (string $rrule, string $startLocal, string $tz): array {
        $series = smSeries('Sample timer', $rrule, $startLocal, 0, false, [], $tz);
        $series['end_utc'] = Time::toDb(Time::fromDb($series['start_utc'])->modify('+1 second'));
        $p = smPlan($series, [], ['columns' => ['title' => 'Sample timer, renamed']]);
        return array_map(static fn(array $x): string => Time::toDb(new DateTimeImmutable($x['start'])), $p['plan']['next']);
    };
    $utc = static fn(string ...$l): array => array_map(static fn(string $x): string => smAt($x, 'UTC'), $l);
    $ny = static fn(string ...$l): array => array_map(static fn(string $x): string => smAt($x, 'America/New_York'), $l);
    $twenty = [];
    foreach (['1997-09-02', '1997-09-03'] as $day) {
        foreach (range(9, 16) as $h) {
            foreach ([0, 20, 40] as $m) {
                $twenty[] = smAt(sprintf('%s %02d:%02d', $day, $h, $m), 'America/New_York');
            }
        }
    }
    $sets = [
        // The review's two tables.
        'MINUTELY, BYSECOND expands each minute' => ['FREQ=MINUTELY;BYSECOND=30,45;COUNT=4', '2027-01-01 09:00:30', 'UTC', $utc('2027-01-01 09:00:30', '2027-01-01 09:00:45', '2027-01-01 09:01:30', '2027-01-01 09:01:45')],
        'MINUTELY, BYYEARDAY limits' => ['FREQ=MINUTELY;INTERVAL=60;BYYEARDAY=1;COUNT=2', '2027-01-01 23:00', 'UTC', $utc('2027-01-01 23:00', '2028-01-01 00:00')],
        // RFC 5545 3.8.5.3's examples, from 2 Sep 1997 09:00 in New York.
        'RFC: every 15 minutes for 6 occurrences' => ['FREQ=MINUTELY;INTERVAL=15;COUNT=6', '1997-09-02 09:00', 'America/New_York',
            $ny('1997-09-02 09:00', '1997-09-02 09:15', '1997-09-02 09:30', '1997-09-02 09:45', '1997-09-02 10:00', '1997-09-02 10:15')],
        'RFC: every hour and a half for 4 occurrences' => ['FREQ=MINUTELY;INTERVAL=90;COUNT=4', '1997-09-02 09:00', 'America/New_York',
            $ny('1997-09-02 09:00', '1997-09-02 10:30', '1997-09-02 12:00', '1997-09-02 13:30')],
        'RFC: every 20 minutes from 9:00 AM to 4:40 PM every day' => ['FREQ=MINUTELY;INTERVAL=20;BYHOUR=9,10,11,12,13,14,15,16', '1997-09-02 09:00', 'America/New_York', $twenty],
        // Each other part of the table, one at a time.
        'MINUTELY, BYDAY limits' => ['FREQ=MINUTELY;INTERVAL=720;BYDAY=MO;COUNT=3', '2027-01-04 00:00', 'UTC', $utc('2027-01-04 00:00', '2027-01-04 12:00', '2027-01-11 00:00')],
        'MINUTELY, BYMONTH limits' => ['FREQ=MINUTELY;INTERVAL=1440;BYMONTH=3;COUNT=3', '2027-03-30 12:00', 'UTC', $utc('2027-03-30 12:00', '2027-03-31 12:00', '2028-03-01 12:00')],
        'MINUTELY, BYSETPOS limits each minute\'s set' => ['FREQ=MINUTELY;BYSECOND=0,20,40;BYSETPOS=-1;COUNT=3', '2027-01-01 09:00:40', 'UTC', $utc('2027-01-01 09:00:40', '2027-01-01 09:01:40', '2027-01-01 09:02:40')],
        'MINUTELY, BYMINUTE limits' => ['FREQ=MINUTELY;INTERVAL=5;BYMINUTE=0,30;COUNT=4', '2027-01-01 09:00', 'UTC', $utc('2027-01-01 09:00', '2027-01-01 09:30', '2027-01-01 10:00', '2027-01-01 10:30')],
        'MINUTELY, an UNTIL ends it' => ['FREQ=MINUTELY;INTERVAL=15;UNTIL=20270101T100000Z', '2027-01-01 09:00', 'UTC', $utc('2027-01-01 09:00', '2027-01-01 09:15', '2027-01-01 09:30', '2027-01-01 09:45', '2027-01-01 10:00')],
        'MINUTELY steps elapsed time across a clock change' => ['FREQ=MINUTELY;INTERVAL=30;COUNT=5', '2027-11-07 01:00', 'America/New_York',
            $utc('2027-11-07 05:00', '2027-11-07 05:30', '2027-11-07 06:00', '2027-11-07 06:30', '2027-11-07 07:00')],
        'SECONDLY, BYSECOND limits' => ['FREQ=SECONDLY;INTERVAL=10;BYSECOND=0,30;COUNT=4', '2027-01-01 09:00', 'UTC', $utc('2027-01-01 09:00:00', '2027-01-01 09:00:30', '2027-01-01 09:01:00', '2027-01-01 09:01:30')],
        'SECONDLY, BYMINUTE and BYSECOND limit' => ['FREQ=SECONDLY;BYMINUTE=0;BYSECOND=0;COUNT=3', '2027-01-01 10:00', 'UTC', $utc('2027-01-01 10:00', '2027-01-01 11:00', '2027-01-01 12:00')],
        'SECONDLY, BYMONTHDAY from the end and BYHOUR limit' => ['FREQ=SECONDLY;INTERVAL=3600;BYMONTHDAY=-1;BYHOUR=23;COUNT=3', '2027-01-31 23:00', 'UTC', $utc('2027-01-31 23:00', '2027-02-28 23:00', '2027-03-31 23:00')],
        'SECONDLY, BYYEARDAY from the end limits' => ['FREQ=SECONDLY;INTERVAL=86400;BYYEARDAY=-1;COUNT=2', '2027-12-31 08:00', 'UTC', $utc('2027-12-31 08:00', '2028-12-31 08:00')],
        'SECONDLY, BYSETPOS limits a set of one' => ['FREQ=SECONDLY;INTERVAL=30;BYSETPOS=1;COUNT=3', '2027-01-01 09:00', 'UTC', $utc('2027-01-01 09:00:00', '2027-01-01 09:00:30', '2027-01-01 09:01:00')],
    ];
    foreach ($sets as $name => [$rrule, $startLocal, $tz, $expected]) {
        $n = count($expected);
        checkEq("F3: $name: the iterator", $expected, $iterate($rrule, $startLocal, $tz, $n));
        checkEq("F3: $name: the window", $expected, $window($rrule, $startLocal, $tz, $n));
        checkEq("F3: $name: the model's next dates", array_slice($expected, 0, 3), array_slice($modelNext($rrule, $startLocal, $tz), 0, 3));
    }
    // What the table marks N/A for these frequencies is refused: a rule the
    // expander can't read, a 400 where one is written, field changes only
    // on one stored.
    foreach (['FREQ=MINUTELY;BYWEEKNO=1', 'FREQ=SECONDLY;INTERVAL=60;BYDAY=1MO'] as $rrule) {
        $code = null;
        try {
            BetterCal\Domain\Recurrence::validateRrule($rrule);
        } catch (BetterCal\Http\HttpError $e) {
            $code = $e->errorCode;
        }
        $stored = smSeries('Sample timer', $rrule . ';COUNT=3', '2027-01-04 09:00', 1, false, [], 'UTC');
        $drag = smPlan($stored, [], smDrag($stored, '2027-01-04 09:00', '2027-01-05 09:00'));
        $rename = smPlan($stored, [], ['columns' => ['title' => 'Sample timer, renamed']]);
        checkEq("F3: $rrule is refused: unreadable, 400 invalid_rrule when written, a drag refused saying so, a rename taken", [
            false, 'invalid_rrule', 'unreadable', Model::REASONS['unreadable'], ['title' => 'Sample timer, renamed'],
        ], [BetterCal\Domain\Series\Calc::sabreReads($rrule), $code, $drag['plan']['refusals'][0]['code'] ?? null, $drag['plan']['refusals'][0]['words'] ?? null, $rename['writes']['master'] ?? null]);
    }

    // Review F7: an instant given stays that instant. On 31 Oct 2027 Berlin's
    // 02:30 happens twice, 00:30Z (+02:00) and 01:30Z (+01:00); equal day,
    // clock and zone are not equal instants there, in placement and in
    // ownership alike, in both directions, for owned and inherited times.
    $berlin = 'Europe/Berlin';
    $foldSeries = static function (string $startUtc, string $rrule) use ($berlin): array {
        $s = smSeries('Sample fold walk', $rrule, '2027-10-31 02:30', 60, false, [], $berlin);
        $s['start_utc'] = $startUtc;
        $s['end_utc'] = Time::toDb(Time::fromDb($startUtc)->modify('+1 hour'));
        return $s;
    };
    $foldRow = static function (array $master, string $key, string $startUtc, array $own) use ($berlin): array {
        return array_merge($master, ['id' => 2, 'rrule' => null, 'exdates_json' => null, 'recurrence_parent_id' => 1, 'recurrence_instance_utc' => $key,
            'start_utc' => $startUtc, 'end_utc' => Time::toDb(Time::fromDb($startUtc)->modify('+1 hour')), 'title' => 'Sample fold walk, own',
            'override_fields' => json_encode($own), 'tzid' => $berlin]);
    };
    $shownAt = static fn(array $p, array $row): string => (string) ($p['writes']['overrides'][2]['start_utc'] ?? (in_array(2, $p['writes']['delete'], true) ? 'deleted' : $row['start_utc']));
    foreach ([
        'variant A: the key is the second instant, the time inherited' => ['2027-10-31 01:30:00', '2027-10-31 01:30:00', ['title']],
        'the key is the first instant, the time inherited' => ['2027-10-31 00:30:00', '2027-10-31 00:30:00', ['title']],
        'the key is the first instant, its own time the second' => ['2027-10-31 00:30:00', '2027-10-31 01:30:00', ['time', 'title']],
        'the key is the second instant, its own time the first' => ['2027-10-31 01:30:00', '2027-10-31 00:30:00', ['time', 'title']],
    ] as $name => [$keyUtc, $shownUtc, $own]) {
        $s = $foldSeries($keyUtc, 'FREQ=WEEKLY;COUNT=3');
        $row = $foldRow($s, $keyUtc, $shownUtc, $own);
        $p = smPlan($s, [$row], ['acted' => Time::toDb(Time::fromDb($keyUtc)->modify('+7 days')), 'columns' => ['title' => 'Sample fold walk, renamed']], ['ownTitle' => 'keep']);
        checkEq("F7 $name: a rename leaves the edited day at its instant, its own list as it was", [$shownUtc, $own], [$shownAt($p, $row), json_decode((string) ($p['writes']['overrides'][2]['override_fields'] ?? $row['override_fields']), true)]);
        // A later clock for the series: an own time stays at its instant, an inherited one moves with the series.
        $p = smPlan($s, [$row], smDrag($s, '2027-11-07 02:30', '2027-11-07 03:00'), ['ownTitle' => 'keep']);
        // 03:00 on 31 Oct is after the repeated hour: one instant, 02:00Z.
        $moved = in_array('time', $own, true) ? $shownUtc : '2027-10-31 02:00:00';
        checkEq("F7 $name: the series moved to 03:00, the day " . (in_array('time', $own, true) ? 'keeps its own instant' : 'at the series\' 03:00'), $moved, $shownAt($p, $row));
    }
    // Variant B, both directions: the day acted on put on one of the two
    // instants where its image's key is the other owns its time, at the
    // instant it was put, for the elapsed length it was given (45 minutes).
    foreach ([
        'variant B: put on the first instant, the key the second' => [
            smSeries('Sample fold landing', 'FREQ=YEARLY;UNTIL=20281021T003000Z', '2026-10-21 02:30', 60, false, [], $berlin), '2027-10-21 00:30:00',
            '2027-10-31 00:30:00', '2027-10-31 01:30:00'],
        'put on the second instant, the key the first' => [
            smSeries('Sample fold landing', 'FREQ=WEEKLY;COUNT=4', '2027-10-17 02:30', 60, false, [], $berlin), '2027-10-24 00:30:00',
            '2027-10-31 01:30:00', '2027-10-31 00:30:00'],
    ] as $name => [$s, $acted, $putUtc, $imageKey]) {
        $p = smPlan($s, [], ['acted' => $acted, 'landed' => ['start_utc' => $putUtc, 'end_utc' => Time::toDb(Time::fromDb($putUtc)->modify('+45 minutes')), 'all_day' => 0, 'tzid' => $berlin], 'columns' => ['status' => 'tentative']]);
        $created = $p['writes']['createEdited'][0] ?? [];
        checkEq("F7 $name: the day shows where it was put, its time its own, 45 minutes long, keyed on its image", [
            $imageKey, $putUtc, Time::toDb(Time::fromDb($putUtc)->modify('+45 minutes')), ['time'], Time::iso(Time::fromDb($putUtc)->setTimezone(Time::zone($berlin))),
        ], [
            $created['recurrence_instance_utc'] ?? null, $created['start_utc'] ?? null, $created['end_utc'] ?? null, json_decode((string) ($created['override_fields'] ?? 'null'), true),
            array_values(array_filter($p['plan']['next'], static fn(array $x): bool => substr($x['start'], 0, 10) === '2027-10-31'))[0]['start'] ?? null,
        ]);
    }
    // Ownership inference reads instants too: a row on its key's day at the
    // other instant of the repeated hour owns its time; at its key, not.
    $s = $foldSeries('2027-10-31 00:30:00', 'FREQ=WEEKLY;COUNT=3');
    checkEq('F7: inferred ownership tells the two instants apart', [['time'], []], [
        array_values(array_intersect(OwnFields::infer($foldRow($s, '2027-10-31 00:30:00', '2027-10-31 01:30:00', []), $s), ['time'])),
        array_values(array_intersect(OwnFields::infer($foldRow($s, '2027-10-31 00:30:00', '2027-10-31 00:30:00', []), $s), ['time'])),
    ]);

    // Review F1: a row exists for its own fields and for what refers to it.
    // A day whose only own field is its title, in a trip, given the series'
    // new title keeps its row and its trip; out of the trip, it rejoins.
    $club = smSeries('Sample club', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 18:00', 60, false, [], 'UTC');
    $inTrip = smEdited($club, 2, '2027-01-12 18:00', ['title' => 'Sample club, away'], null, ['title']);
    $rename = ['columns' => ['title' => 'Sample club night']];
    $p = smPlan($club, [$inTrip], $rename, ['ownTitle' => 'give'], ['rowLinks' => [2 => ['7']]]);
    $day = array_values(array_filter($p['plan']['days'], static fn(array $x): bool => $x['kind'] === 'edited'))[0] ?? [];
    checkEq('F1: a day in a trip given the new title stays, keeps its trip, its row written with no field of its own', [
        [], 'stays', ['trip'], "stays, keeps its trip, takes 'Sample club night'", ['title' => 'Sample club night', 'override_fields' => '[]'],
    ], [$p['writes']['delete'], $day['outcome'] ?? null, $day['kept'] ?? null, $day['outcomeWords'] ?? null, $p['writes']['overrides'][2] ?? null]);
    $p = smPlan($club, [$inTrip], $rename, ['ownTitle' => 'give']);
    $day = array_values(array_filter($p['plan']['days'], static fn(array $x): bool => $x['kind'] === 'edited'))[0] ?? [];
    checkEq('F1: the same day in no trip has nothing of its own left and rejoins', [[2], 'rejoins'], [$p['writes']['delete'], $day['outcome'] ?? null]);
    $kept = smEdited($club, 2, '2027-01-12 18:00', [], null, []);
    $p = smPlan($club, [$kept], $rename, [], ['rowLinks' => [2 => ['7']]]);
    checkEq('F1: a row with no field of its own, kept for its trip, takes a rename and stays: the plan is direct', [true, [], ['title' => 'Sample club night']],
        [$p['plan']['direct'], $p['writes']['delete'], $p['writes']['overrides'][2] ?? null]);
    checkEq('F1: the trip is never stored in the own list', ['[]', ['title']], [OwnFields::encode(['trip']), OwnFields::own(['title', 'trip'], false)]);
    $a = smPlan($club, [$inTrip], $rename, ['ownTitle' => 'give'], ['rowLinks' => [2 => ['7']]]);
    $b = smPlan($club, [$inTrip], $rename, ['ownTitle' => 'give'], ['rowLinks' => [2 => ['8']]]);
    checkEq('F1: the hash covers the links naming the rows', true, $a['plan']['hash'] !== $b['plan']['hash']);

    // Review R2: a series column (the trip flag) has one value for the whole
    // series; the request names it for every row, so it changes when any row
    // differs from it, the master's own value the same or not.
    $walk = smSeries('Sample walk', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 18:00', 60, false, [], 'UTC');
    $walk['is_container'] = 0;
    $flagged = smEdited($walk, 2, '2027-01-12 18:00', ['title' => 'Sample walk, away', 'is_container' => 1], null, ['title']);
    $p = smPlan($walk, [$flagged], ['columns' => ['is_container' => 0]]);
    checkEq('R2: clearing the trip flag for all events clears an edited day\'s when the master\'s is already clear', [
        false, ['is_container' => 0], [], 'Trip: no on some days, now no for every event',
    ], [$p['plan']['direct'], array_intersect_key($p['writes']['overrides'][2] ?? [], ['is_container' => 1]), $p['writes']['master'], $p['plan']['fields'][0]['words'] ?? null]);
    $trip = $walk;
    $trip['is_container'] = 1;
    $plain = smEdited($trip, 2, '2027-01-12 18:00', ['title' => 'Sample walk, away', 'is_container' => 0], null, ['title']);
    $p = smPlan($trip, [$plain], ['columns' => ['is_container' => 1]]);
    checkEq('R2: setting it for all events sets an edited day\'s when the master\'s is already set', [
        false, ['is_container' => 1], [], 'Trip: yes on some days, now yes for every event',
    ], [$p['plan']['direct'], array_intersect_key($p['writes']['overrides'][2] ?? [], ['is_container' => 1]), $p['writes']['master'], $p['plan']['fields'][0]['words'] ?? null]);
    $p = smPlan($walk, [$flagged], ['columns' => ['is_container' => 1]]);
    checkEq('R2: a value the master lacks and the edited day has: the master takes it, the day is unchanged, the words say the days differed', [
        ['is_container' => 1], null, 'Trip: no on some days, now yes for every event',
    ], [$p['writes']['master'], $p['writes']['overrides'][2] ?? null, $p['plan']['fields'][0]['words'] ?? null]);
    $p = smPlan($walk, [smEdited($walk, 2, '2027-01-12 18:00', ['title' => 'Sample walk, away'], null, ['title'])], ['columns' => ['is_container' => 1]]);
    checkEq('R2: one value everywhere: the change is direct and its words say before and after', [true, ['is_container' => 1], 'Trip: no, now yes'],
        [$p['plan']['direct'], $p['writes']['master'], $p['plan']['fields'][0]['words'] ?? null]);
    $p = smPlan($walk, [$flagged], ['columns' => ['is_container' => 0, 'title' => 'Sample walk']]);
    checkEq('R2: a request with the series\' values for every field it sends still changes the day that differs', [['is_container' => 0]], [array_intersect_key($p['writes']['overrides'][2] ?? [], ['is_container' => 1])]);

    // Review R1: every date and instant the plan writes is in one zone, the
    // series' after the change, so a day's date and its clock describe one
    // instant. A New York series moved to Los Angeles time keeping its clock:
    // its day at its own 00:30 New York is Monday 21:30 in Los Angeles.
    $ny = 'America/New_York';
    $la = 'America/Los_Angeles';
    $late = smSeries('Sample late call', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 01:30', 60, false, [], $ny);
    $ownTime = smEdited($late, 2, '2027-01-12 01:30', [], '2027-01-12 00:30', ['time']);
    $toLa = ['acted' => smAt('2027-01-05 01:30', $ny), 'landed' => ['start_utc' => smAt('2027-01-05 01:30', $la), 'end_utc' => smAt('2027-01-05 02:30', $la), 'all_day' => 0, 'tzid' => $la]];
    $p = smPlan($late, [$ownTime], $toLa);
    $day = array_values(array_filter($p['plan']['days'], static fn(array $x): bool => $x['kind'] === 'edited'))[0] ?? [];
    checkEq('R1: the edited day keeps its instant; its dates and its instant are written in the series\' new zone, where it is Monday evening', [
        'stays', '2027-01-11', '2027-01-11', '2027-01-11T21:30:00-08:00', '2027-01-12T09:30:00Z', $ownTime['start_utc'],
    ], [$day['outcome'] ?? null, $day['shows'] ?? null, $day['to'] ?? null, $day['at'] ?? null, $day['newKey'] ?? null, $p['writes']['overrides'][2]['start_utc'] ?? $ownTime['start_utc']]);
    checkEq('R1: the coming-up list writes that day in the same zone', ['2027-01-05T01:30:00-08:00', '2027-01-11T21:30:00-08:00', '2027-01-19T01:30:00-08:00', '2027-01-26T01:30:00-08:00'], array_column($p['plan']['next'], 'start'));
    // A Los Angeles series whose Jan 12 is at its own 00:30 New York time
    // (Monday 21:30 in Los Angeles), renamed from that day's own row: the
    // list starts where the day shows in the plan's zone, before its key's day.
    $laSeries = smSeries('Sample late call', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 01:30', 60, false, [], $la);
    $nyDay = smEdited($laSeries, 4, '2027-01-12 01:30', ['tzid' => $ny, 'start_utc' => smAt('2027-01-12 00:30', $ny), 'end_utc' => smAt('2027-01-12 01:30', $ny)], null, ['time', 'zone']);
    $p = smPlan($laSeries, [$nyDay], ['acted' => $nyDay['recurrence_instance_utc'], 'columns' => ['title' => 'Sample late call, renamed']]);
    checkEq('R1: from the edited day itself, the coming-up list starts with it where it shows', ['2027-01-11T21:30:00-08:00', '2027-01-19T01:30:00-08:00', '2027-01-26T01:30:00-08:00', '2027-02-02T01:30:00-08:00'], array_column($p['plan']['next'], 'start'));
    $ownDate = smEdited($late, 3, '2027-01-19 01:30', [], '2027-01-20 00:30', ['date', 'time']);
    $p = smPlan($late, [$ownDate], $toLa);
    $day = array_values(array_filter($p['plan']['days'], static fn(array $x): bool => $x['kind'] === 'edited'))[0] ?? [];
    checkEq('R1: a day at its own date and time the same way, before and after', ['2027-01-19', '2027-01-19', '2027-01-19T21:30:00-08:00'], [$day['shows'] ?? null, $day['to'] ?? null, $day['at'] ?? null]);
    // Review F1: the choices for a day moved on its own name the day each
    // choice comes out on, placed as the commit places it, in the plan's
    // zone: Jan 12 at its own Wed 13 Jan 00:30 New York (Tue 12 Jan 21:30
    // in Los Angeles), the series moved to Wednesday and to Los Angeles time.
    $ownWed = smEdited($late, 5, '2027-01-12 01:30', [], '2027-01-13 00:30', ['date', 'time']);
    $toWedLa = ['acted' => smAt('2027-01-05 01:30', $ny), 'landed' => ['start_utc' => smAt('2027-01-06 01:30', $la), 'end_utc' => smAt('2027-01-06 02:30', $la), 'all_day' => 0, 'tzid' => $la], 'rrule' => 'FREQ=WEEKLY;BYDAY=WE'];
    $p = smPlan($late, [$ownWed], $toWedLa);
    $g = smGroup($p, 'movedDays');
    checkEq('F1: keep names Tue 12 Jan and move names Wed 13 Jan, the days each choice comes out on in Los Angeles', [
        ['keep', '2027-01-12', 'Keep Tue 12 Jan'], ['move', '2027-01-13', 'Move to Wed 13 Jan'],
    ], array_map(static fn(array $o): array => [$o['id'], $o['date'] ?? null, $o['label']], $g['options'] ?? []));
    $day = array_values(array_filter($p['plan']['days'], static fn(array $x): bool => $x['kind'] === 'edited'))[0] ?? [];
    checkEq('F1: the row agrees with the kept choice', ['2027-01-12', '2027-01-12'], [$day['shows'] ?? null, $day['to'] ?? null]);
    $moved = smPlan($late, [$ownWed], $toWedLa, ['movedDays' => 'move']);
    $day = array_values(array_filter($moved['plan']['days'], static fn(array $x): bool => $x['kind'] === 'edited'))[0] ?? [];
    checkEq('F1: moved with the rest, the row lands on the day the choice named', ['2027-01-13', smAt('2027-01-14 00:30', $ny)], [$day['to'] ?? null, $moved['writes']['overrides'][5]['start_utc'] ?? null]);
    $allDayLate = smSeries('Sample day', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05', 60, true);
    $ownDay = smEdited($allDayLate, 2, '2027-01-12', [], '2027-01-13', ['date']);
    $p = smPlan($allDayLate, [$ownDay], ['columns' => ['title' => 'Sample day out']]);
    $day = array_values(array_filter($p['plan']['days'], static fn(array $x): bool => $x['kind'] === 'edited'))[0] ?? [];
    checkEq('R1: an all-day day is a date, written as itself', ['2027-01-13', '2027-01-13', null], [$day['shows'] ?? null, $day['to'] ?? null, $day['at'] ?? null]);

    // Coming up is read from the day the list starts on, however far that
    // is from the series' start, and nextMore says whether one more event
    // than the list shows exists: every edited row, created row and day on
    // its own counted with the occurrences, in the list's order.
    $old = smSeries('Sample practice', 'FREQ=DAILY', '2015-01-05 18:00', 60, false, [], 'UTC');
    $p = smPlan($old, [], ['acted' => smAt('2027-01-05 18:00', 'UTC'), 'columns' => ['title' => 'Sample practice, renamed']]);
    checkEq('Coming up: a daily series twelve years old lists the next four from the day acted on', [['2027-01-05', '2027-01-06', '2027-01-07', '2027-01-08'], true],
        [smNext($p), $p['plan']['nextMore']]);
    $hourly = smSeries('Sample check-in', 'FREQ=HOURLY', '2019-01-05 09:00', 15, false, [], 'UTC');
    $p = smPlan($hourly, [], ['acted' => smAt('2027-01-05 00:00', 'UTC'), 'columns' => ['title' => 'Sample check-in, renamed']]);
    checkEq('Coming up: an hourly rule eight years old lists the first four hours of the day acted on', [['2027-01-05T00:00:00+00:00', '2027-01-05T01:00:00+00:00', '2027-01-05T02:00:00+00:00', '2027-01-05T03:00:00+00:00'], true],
        [array_column($p['plan']['next'], 'start'), $p['plan']['nextMore']]);
    // Six Tuesdays; the last two at their own dates past the raw end.
    $six = smSeries('Sample practice', 'FREQ=WEEKLY;COUNT=6', '2027-01-05 18:00', 60, false, [], 'UTC');
    $late = [smEdited($six, 2, '2027-02-02 18:00', [], '2027-02-20 18:00', ['date']), smEdited($six, 3, '2027-02-09 18:00', [], '2027-02-25 18:00', ['date'])];
    $rename = static fn(string $from): array => ['acted' => smAt($from . ' 18:00', 'UTC'), 'columns' => ['status' => 'tentative']];
    $p = smPlan($six, $late, $rename('2027-01-12'));
    checkEq('Coming up: edited days past the raw end are listed, and one is left after the four', [['2027-01-12', '2027-01-19', '2027-01-26', '2027-02-20'], true], [smNext($p), $p['plan']['nextMore']]);
    $p = smPlan($six, $late, $rename('2027-01-19'));
    checkEq('Coming up: from Jan 19 the last edited day is the fourth, and nothing is left', [['2027-01-19', '2027-01-26', '2027-02-20', '2027-02-25'], false], [smNext($p), $p['plan']['nextMore']]);
    $skipped = smSeries('Sample practice', 'FREQ=WEEKLY;COUNT=6', '2027-01-05 18:00', 60, false, ['2027-02-02 18:00', '2027-02-09 18:00'], 'UTC');
    $p = smPlan($skipped, [], $rename('2027-01-05'));
    checkEq('Coming up: the occurrences after the four are skipped, so nothing more is coming', [['2027-01-05', '2027-01-12', '2027-01-19', '2027-01-26'], false], [smNext($p), $p['plan']['nextMore']]);
    $p = smPlan($six, [], $rename('2027-01-19'));
    checkEq('Coming up: exactly four events left, nothing more', [['2027-01-19', '2027-01-26', '2027-02-02', '2027-02-09'], false], [smNext($p), $p['plan']['nextMore']]);
    $p = smPlan($six, [], $rename('2027-01-12'));
    checkEq('Coming up: five events left, one more', [['2027-01-12', '2027-01-19', '2027-01-26', '2027-02-02'], true], [smNext($p), $p['plan']['nextMore']]);

    // The expander yields a monthly rule's UNTIL as an occurrence when it
    // falls on the first of a month the rule doesn't give (sabre/vobject
    // 4.6.1); no expansion in the app has it, the model's or the window's.
    $second = smSeries('Sample practice', 'FREQ=MONTHLY;BYMONTHDAY=2;UNTIL=20270601', '2027-01-02', 60, true);
    $p = smPlan($second, [], ['acted' => smAt('2027-04-02 00:00:00', 'UTC'), 'columns' => ['title' => 'Sample practice, renamed']]);
    checkEq('Phantom UNTIL: an all-day 2nd of the month ending 1 Jun has no 1 Jun, in next or as its last day', [['2027-04-02', '2027-05-02'], false, '2027-05-02', 5],
        [smNext($p), $p['plan']['nextMore'], $p['plan']['bound']['last'], $p['plan']['bound']['events']]);
    checkEq('Phantom UNTIL: the window shows no 1 Jun either', ['2027-01-02', '2027-02-02', '2027-03-02', '2027-04-02', '2027-05-02'], smShows($second, ['master' => []], '2027-06-30'));
    $monday = smSeries('Sample practice', 'FREQ=MONTHLY;BYDAY=1MO;UNTIL=20270601T170000Z', '2027-01-04 10:00', 60, false, [], 'America/Los_Angeles');
    $p = smPlan($monday, [], ['acted' => smAt('2027-04-05 10:00'), 'columns' => ['title' => 'Sample practice, renamed']]);
    checkEq('Phantom UNTIL: a timed first Monday ending at its own time on Tue 1 Jun has no 1 Jun', [['2027-04-05', '2027-05-03'], false, '2027-05-03'],
        [smNext($p), $p['plan']['nextMore'], $p['plan']['bound']['last']]);
    $first = smSeries('Sample practice', 'FREQ=MONTHLY;BYMONTHDAY=1;UNTIL=20270601', '2027-01-01', 60, true);
    checkEq('Phantom UNTIL: an UNTIL on a 1st the rule gives is its last occurrence', ['2027-04-01', '2027-05-01', '2027-06-01'], array_slice(smShows($first, ['master' => []], '2027-06-30'), -3));
    // The rule is that a monthly BYDAY or BYMONTHDAY rule yields only its
    // months' own days; every day sabre lands on otherwise is one of them,
    // a second Sunday at 02:30 Los Angeles shifted out of March's skipped
    // hour included, so with no UNTIL nothing is cut short.
    $take = static function (Sabre\VObject\Recur\RRuleIterator $it): array {
        $out = [];
        for ($i = 0; $i < 30 && $it->valid(); $i++, $it->next()) {
            $out[] = $it->current()->format('Y-m-d H:i T');
        }
        return $out;
    };
    $gapStart = new DateTimeImmutable('2027-01-10 02:30:00', Time::zone('America/Los_Angeles'));
    $gapDays = $take(new BetterCal\Domain\Series\RuleIterator('FREQ=MONTHLY;BYDAY=2SU', $gapStart));
    checkEq('Phantom UNTIL: an unbounded second Sunday at 02:30 keeps every day sabre gives, March\'s at 03:30',
        [$take(new Sabre\VObject\Recur\RRuleIterator('FREQ=MONTHLY;BYDAY=2SU', $gapStart)), 30, '2027-03-14 03:30 PDT'], [$gapDays, count($gapDays), $gapDays[2] ?? null]);

    // The zone rule (One zone): the after side decides. A series timed after
    // the edit writes every timed value of both sides in its zone and every
    // all-day value as its date; a series all-day after the edit has no
    // zone, its dates are themselves, and a timed day is written in its own
    // zone, named beside it, on both sides.
    $zoneOf = static fn(array $p): mixed => array_key_exists('zone', $p['plan']) ? $p['plan']['zone'] : 'missing';
    $edited = static fn(array $p): array => array_values(array_filter($p['plan']['days'], static fn(array $x): bool => $x['kind'] === 'edited'))[0] ?? [];
    $placed = static fn(array $d): array => [$d['outcome'] ?? null, $d['shows'] ?? null, $d['to'] ?? null, $d['at'] ?? null, $d['tz'] ?? null];
    $tokyo = 'Asia/Tokyo';
    $practice = smSeries('Sample practice', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05', 60, true);
    $evening = smEdited($practice, 2, '2027-01-12', ['all_day' => 0, 'tzid' => $la, 'start_utc' => smAt('2027-01-12 20:00', $la), 'end_utc' => smAt('2027-01-12 21:00', $la)], null, ['allDay', 'time']);
    $p = smPlan($practice, [$evening], ['columns' => ['title' => 'Sample practice, renamed']]);
    checkEq('Zone: an all-day series has no zone; its day at its own 20:00 in Los Angeles is written there, on its own date, its zone beside it', [
        null, ['stays', '2027-01-12', '2027-01-12', '2027-01-12T20:00:00-08:00', $la],
    ], [$zoneOf($p), $placed($edited($p))]);
    checkEq('Zone: coming up writes that day in its own zone too, beside the dates of the series', [
        ['2027-01-05T00:00:00+00:00', null], ['2027-01-12T20:00:00-08:00', $la], ['2027-01-19T00:00:00+00:00', null],
    ], array_map(static fn(array $n): array => [$n['start'], $n['tz'] ?? null], array_slice($p['plan']['next'], 0, 3)));

    // A series made all-day: the days before are timed, each in its own zone.
    $timed = smSeries('Sample practice', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 20:00');
    $toAllDay = ['acted' => smAt('2027-01-05 20:00', $la), 'landed' => ['start_utc' => '2027-01-05 00:00:00', 'end_utc' => '2027-01-06 00:00:00', 'all_day' => 1, 'tzid' => 'UTC']];
    $titled = smEdited($timed, 2, '2027-01-12 20:00', ['title' => 'Sample practice, Sample Hall'], null, ['title']);
    $p = smPlan($timed, [$titled], $toAllDay);
    checkEq('Zone: made all-day, a plain edited day showed on Tue 12 Jan in Los Angeles and becomes that date', [
        null, ['moves', '2027-01-12', '2027-01-12', null, null],
    ], [$zoneOf($p), $placed($edited($p))]);
    $ownTime = smEdited($timed, 2, '2027-01-12 20:00', [], '2027-01-12 21:30', ['time']);
    $p = smPlan($timed, [$ownTime], $toAllDay);
    checkEq('Zone: made all-day, a day at its own time showed on its own day and becomes that date', [
        null, ['moves', '2027-01-12', '2027-01-12', null, null],
    ], [$zoneOf($p), $placed($edited($p))]);
    $ownKind = smEdited($timed, 2, '2027-01-12 20:00', [], '2027-01-12 21:30', ['allDay', 'time']);
    $p = smPlan($timed, [$ownKind], $toAllDay);
    checkEq('Zone: made all-day, a day owning its kind and time stays timed, in its own zone, named', [
        null, ['stays', '2027-01-12', '2027-01-12', '2027-01-12T21:30:00-08:00', $la],
    ], [$zoneOf($p), $placed($edited($p))]);

    // A series made timed: both sides in its new zone.
    $toTimed = static fn(string $tz): array => ['acted' => '2027-01-05 00:00:00', 'landed' => ['start_utc' => smAt('2027-01-05 18:00', $tz), 'end_utc' => smAt('2027-01-05 19:00', $tz), 'all_day' => 0, 'tzid' => $tz]];
    $wholeDay = smEdited($practice, 2, '2027-01-12', ['title' => 'Sample practice, Sample Hall'], null, ['title', 'allDay']);
    $p = smPlan($practice, [$wholeDay], $toTimed($la));
    checkEq('Zone: made timed, a day owning its all-day flag is its date on both sides', [
        $la, ['stays', '2027-01-12', '2027-01-12', null, null],
    ], [$zoneOf($p), $placed($edited($p))]);
    $p = smPlan($practice, [$evening], $toTimed($la));
    checkEq('Zone: made timed in Los Angeles, the day at its own 20:00 there keeps its instant: both sides in the new zone, it stays', [
        $la, ['stays', '2027-01-12', '2027-01-12', '2027-01-12T20:00:00-08:00', null],
    ], [$zoneOf($p), $placed($edited($p))]);
    $p = smPlan($practice, [$evening], $toTimed($tokyo));
    checkEq('Zone: made timed in Tokyo, the same day stays, shown where it is in Tokyo on both sides', [
        $tokyo, ['stays', '2027-01-13', '2027-01-13', '2027-01-13T13:00:00+09:00', null],
    ], [$zoneOf($p), $placed($edited($p))]);

    // An all-day day in a timed series is a date, and it moves by the instant.
    $allDayRow = smEdited($timed, 2, '2027-01-12 20:00', ['all_day' => 1, 'tzid' => 'UTC', 'start_utc' => '2027-01-12 00:00:00', 'end_utc' => '2027-01-13 00:00:00'], null, ['allDay']);
    $p = smPlan($timed, [$allDayRow], ['acted' => smAt('2027-01-05 20:00', $la), 'landed' => ['start_utc' => smAt('2027-01-06 20:00', $la), 'end_utc' => smAt('2027-01-06 21:00', $la), 'all_day' => 0, 'tzid' => $la], 'rrule' => 'FREQ=WEEKLY;BYDAY=WE']);
    checkEq('Zone: a timed series moved to Wednesday, its all-day day moves from date to date', [
        $la, ['moves', '2027-01-12', '2027-01-13', null, null],
    ], [$zoneOf($p), $placed($edited($p))]);

    // The day acted on is an edited row owning its all-day flag.
    $p = smPlan($practice, [$evening], ['acted' => $evening['recurrence_instance_utc'], 'columns' => ['title' => 'Sample practice, renamed']]);
    checkEq('Zone: started from a timed day in an all-day series, the plan says where it showed, in its own zone, and coming up starts with it', [
        null, '2027-01-12', ['2027-01-12T20:00:00-08:00', $la],
    ], [$zoneOf($p), $p['plan']['acted']['shows'] ?? null, [$p['plan']['next'][0]['start'] ?? null, $p['plan']['next'][0]['tz'] ?? null]]);
    $p = smPlan($timed, [$allDayRow], ['acted' => $allDayRow['recurrence_instance_utc'], 'columns' => ['title' => 'Sample practice, renamed']]);
    checkEq('Zone: started from an all-day day in a timed series, the plan says its date', [$la, '2027-01-12', ['2027-01-12T00:00:00+00:00', null]],
        [$zoneOf($p), $p['plan']['acted']['shows'] ?? null, [$p['plan']['next'][0]['start'] ?? null, $p['plan']['next'][0]['tz'] ?? null]]);

    // The moved-day choices in an all-day plan name the days in the day's own zone.
    $ownWedEvening = smEdited($practice, 3, '2027-01-12', ['all_day' => 0, 'tzid' => $la, 'start_utc' => smAt('2027-01-13 20:00', $la), 'end_utc' => smAt('2027-01-13 21:00', $la)], null, ['date', 'allDay', 'time']);
    $p = smPlan($practice, [$ownWedEvening], ['acted' => '2027-01-05 00:00:00', 'landed' => ['start_utc' => '2027-01-06 00:00:00', 'end_utc' => '2027-01-07 00:00:00', 'all_day' => 1, 'tzid' => 'UTC'], 'rrule' => 'FREQ=WEEKLY;BYDAY=WE']);
    checkEq('Zone: an all-day series moved to Wednesday, a day at its own Wed 13 Jan 20:00 in Los Angeles: keep and move name its days there', [
        ['keep', '2027-01-13'], ['move', '2027-01-14'],
    ], array_map(static fn(array $o): array => [$o['id'], $o['date'] ?? null], smGroup($p, 'movedDays')['options'] ?? []));

    // ---- The writers: each value by its role (series-model.md, Dates) ------
    $entries = static fn(array $p): array => array_map(static fn(array $n): array => [$n['start'], $n['allDay'] ?? 'missing', $n['tz'] ?? null], $p['plan']['next']);

    // Coming up: each entry carries its kind, and its zone when that isn't the plan's.
    $p = smPlan($timed, [$allDayRow], ['columns' => ['title' => 'Sample practice, renamed']]);
    checkEq('Writers: coming up in a timed series carries each entry\'s kind, the all-day day at its date', [
        ['2027-01-05T20:00:00-08:00', false, null], ['2027-01-12T00:00:00+00:00', true, null], ['2027-01-19T20:00:00-08:00', false, null],
    ], array_slice($entries($p), 0, 3));
    $p = smPlan($practice, [$evening], ['columns' => ['title' => 'Sample practice, renamed']]);
    checkEq('Writers: coming up in an all-day series, the timed day in its own zone, named', [
        ['2027-01-05T00:00:00+00:00', true, null], ['2027-01-12T20:00:00-08:00', false, $la], ['2027-01-19T00:00:00+00:00', true, null],
    ], array_slice($entries($p), 0, 3));
    // Coming up in the order days show: by day, an all-day event first on
    // its day, then by instant (an all-day day's UTC midnight is no instant
    // of the evening before).
    $daily = smSeries('Sample practice', 'FREQ=DAILY', '2027-01-11 20:00');
    $wholeTue = smEdited($daily, 2, '2027-01-12 20:00', ['all_day' => 1, 'tzid' => 'UTC', 'start_utc' => '2027-01-12 00:00:00', 'end_utc' => '2027-01-13 00:00:00'], null, ['allDay']);
    $thuOnTue = smEdited($daily, 3, '2027-01-14 20:00', ['title' => 'Sample practice at Sample Hall'], '2027-01-12 20:00', ['date', 'title']);
    $p = smPlan($daily, [$wholeTue, $thuOnTue], ['columns' => ['location' => 'Sample Hall']]);
    checkEq('Writers: coming up sorts by the day shown, the all-day day before the evening on the same day', [
        '2027-01-11T20:00:00-08:00', '2027-01-12T00:00:00+00:00', '2027-01-12T20:00:00-08:00', '2027-01-13T20:00:00-08:00',
    ], array_column($p['plan']['next'], 'start'));
    // An option's lists are entries of the same form, each with its kind.
    $tueThu = smSeries('Sample practice', 'FREQ=WEEKLY;BYDAY=TU,TH', '2027-01-05 20:00');
    $wholeThu = smEdited($tueThu, 2, '2027-01-07 20:00', ['all_day' => 1, 'tzid' => 'UTC', 'start_utc' => '2027-01-07 00:00:00', 'end_utc' => '2027-01-08 00:00:00'], null, ['allDay']);
    $p = smPlan($tueThu, [$wholeThu], smDrag($tueThu, '2027-01-05 20:00', '2027-01-06 20:00'));
    $member = array_values(array_filter(smGroup($p, 'reading')['options'] ?? [], static fn(array $o): bool => $o['id'] === 'member'))[0] ?? [];
    checkEq('Writers: a reading\'s next events are entries, the all-day day among them at its date', [
        ['start' => '2027-01-06T20:00:00-08:00', 'allDay' => false], ['start' => '2027-01-07T00:00:00+00:00', 'allDay' => true], ['start' => '2027-01-13T20:00:00-08:00', 'allDay' => false],
    ], $member['next'] ?? null);
    checkEq('Writers: a reading\'s dates are entries of its rule, in its zone and kind', [
        ['start' => '2027-01-06T20:00:00-08:00', 'allDay' => false], ['start' => '2027-01-07T20:00:00-08:00', 'allDay' => false], ['start' => '2027-01-13T20:00:00-08:00', 'allDay' => false],
    ], $member['dates'] ?? null);
    // A reading's first earlier day that differs from the series as it is
    // today is a day of one of the two, named: the old one's when it comes
    // first, the reading's own when that does.
    $earlierOf = static function (array $p): array {
        $o = array_values(array_filter(smGroup($p, 'reading')['options'] ?? [], static fn(array $o): bool => $o['id'] === 'member'))[0] ?? [];
        return [$o['earlier'] ?? null, $o['earlierSeries'] ?? 'missing'];
    };
    checkEq('Writers: Tuesday 19 Jan dragged to Wednesday, the first earlier day that differs is the old Tue 5 Jan', ['2027-01-05', 'old'],
        $earlierOf(smPlan($tueThu, [], smDrag($tueThu, '2027-01-19 20:00', '2027-01-20 20:00'))));
    checkEq('Writers: Thursday 21 Jan dragged to Wednesday, it is the reading\'s own Wed 6 Jan', ['2027-01-06', 'new'],
        $earlierOf(smPlan($tueThu, [], smDrag($tueThu, '2027-01-21 20:00', '2027-01-20 20:00'))));
    // The day acted on, made an edited day by a drag: its day, where it
    // showed (the occurrence at its old key) and where it was put (the row created).
    $run = smSeries('Sample run', 'FREQ=DAILY;BYDAY=MO', '2027-03-01 02:30');
    $p = smPlan($run, [], smDrag($run, '2027-03-08 02:30', '2027-03-15 02:30'));
    $acted = array_values(array_filter($p['plan']['days'], static fn(array $d): bool => $d['kind'] === 'acted'))[0] ?? [];
    checkEq('Writers: the created acted day is about Mon 8 Mar, showed there and was put on Mon 15 Mar at 02:30', [
        'moves', '2027-03-08', ['date' => '2027-03-08', 'at' => '2027-03-08T02:30:00-08:00'], ['date' => '2027-03-15', 'at' => '2027-03-15T02:30:00-07:00'], '2027-03-08', '2027-03-15',
    ], [$acted['outcome'] ?? null, $acted['day'] ?? null, $acted['before'] ?? null, $acted['after'] ?? null, $acted['shows'] ?? null, $acted['to'] ?? null]);
    // An edited day's data: the day it is about, the date set on it in its
    // own zone, where it shows before and after (by the zone rule), and the
    // kind after when that changes. Here a day of a Los Angeles series at
    // its own 00:30 in New York, a date of its own there, while the series
    // moves to 19:00.
    $practiceLa = smSeries('Sample practice', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 20:00');
    $nyOwn = smEdited($practiceLa, 2, '2027-01-12 20:00', ['tzid' => $ny, 'start_utc' => smAt('2027-01-13 00:30', $ny), 'end_utc' => smAt('2027-01-13 01:30', $ny)], null, ['date', 'time']);
    $p = smPlan($practiceLa, [$nyOwn], smDrag($practiceLa, '2027-01-05 20:00', '2027-01-05 19:00'));
    $d = $edited($p);
    $there = ['date' => '2027-01-12', 'at' => '2027-01-12T21:30:00-08:00'];
    checkEq('Writers: an own-date day at its own New York time in a Los Angeles series: about Tue 12 Jan, set on Wed 13 Jan in New York, shown at 21:30 in Los Angeles before and after', [
        'stays', '2027-01-12', ['date' => '2027-01-13', 'at' => '2027-01-13T00:30:00-05:00', 'tz' => $ny], $there, $there, '2027-01-12', '2027-01-12',
    ], [$d['outcome'] ?? null, $d['day'] ?? null, $d['ownDate'] ?? null, $d['before'] ?? null, $d['after'] ?? null, $d['shows'] ?? null, $d['to'] ?? null]);
    $p = smPlan($timed, [$ownTime], $toAllDay);
    $d = $edited($p);
    checkEq('Writers: made all-day, a day at its own time shows timed in its own zone before and on its date after, its new kind named', [
        'moves', '2027-01-12', false, ['date' => '2027-01-12', 'at' => '2027-01-12T21:30:00-08:00', 'tz' => $la], ['date' => '2027-01-12', 'kind' => 'all-day'],
    ], [$d['outcome'] ?? null, $d['day'] ?? null, array_key_exists('ownDate', $d), $d['before'] ?? null, $d['after'] ?? null]);
    // Its words: the day it is about, the date set on it, and the time it
    // keeps as it shows after the change.
    $p = smPlan($practiceLa, [$nyOwn], smDrag($practiceLa, '2027-01-05 20:00', '2027-01-05 19:00'));
    $d = $edited($p);
    checkEq('Writers: the New York day is named by the day it is about and the date set on it, and keeps its time as it shows, 21:30', [
        'Tue 12 Jan, moved to Wed 13 Jan', 'stays there, keeps its own time at 21:30',
    ], [$d['head'] ?? null, $d['outcomeWords'] ?? null]);
    $choirOwn = smEdited($choir, 2, '2027-04-13 19:00', ['title' => 'Rehearsal'], '2027-04-16 19:00', ['date', 'title']);
    $p = smPlan($choir, [$choirOwn], ['rrule' => 'FREQ=WEEKLY;BYDAY=MO,TH']);
    checkEq('Writers: a day outside the new series is named by the day it is about', "'Rehearsal' Tue 13 Apr", $edited($p)['head'] ?? null);
    // The day acted on, left outside the new series: where it goes is the
    // standalone row the commit writes, not the day its occurrence lands on.
    $concert = smEdited($utcWeekly, 2, '2027-02-09 19:00', ['title' => 'Sample concert'], '2027-02-11 19:00', ['date', 'title']);
    $toFri = ['acted' => smAt('2027-02-09 19:00', 'UTC'), 'rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=3',
        'landed' => ['start_utc' => smAt('2027-02-12 19:00', 'UTC'), 'end_utc' => smAt('2027-02-12 20:00', 'UTC'), 'all_day' => 0, 'tzid' => 'UTC']];
    $p = smPlan($utcWeekly, [$concert], $toFri);
    $alone = $dayOf($p, $feb9) ?? [];
    $standalone = $p['writes']['detach'][2] ?? [];
    checkEq('Writers: the acted day on its own date, put on Fri 12 Feb outside the new series: its after is the standalone row', [
        'separate', '2027-02-09', ['date' => '2027-02-11', 'at' => '2027-02-11T19:00:00+00:00'], ['date' => '2027-02-12', 'at' => '2027-02-12T19:00:00+00:00'], '2027-02-12',
        ['date' => '2027-02-12', 'at' => '2027-02-12T19:00:00+00:00'], 'This one, Fri 12 Feb',
    ], [$alone['outcome'] ?? null, $alone['day'] ?? null, $alone['before'] ?? null, $alone['after'] ?? null, $alone['to'] ?? null,
        $standalone === [] ? null : ['date' => substr($standalone['start_utc'], 0, 10), 'at' => Time::iso(Time::fromDb($standalone['start_utc']))], $alone['head'] ?? null]);
    // The ownership sentence and the Activity entry name each day by the
    // day it is about, then where it went when that is another day.
    $p = smPlan($utcWeekly, [$concert], $toFri);
    checkEq('Writers: the day becoming an event of its own is named by the day it is about, then where it went', [
        "One edited day becomes an event of its own: 'Sample concert' on 9 Feb, moved to 12 Feb.", true,
    ], [$p['plan']['ownership'] ?? null, str_contains($p['plan']['summary'], "One edited day became an event of its own: 'Sample concert' on 9 Feb, moved to 12 Feb.")]);
    // The series' start: an all-day series' is a date, a timed one's in its
    // zone, and an anchored one's the stored start, a key.
    $p = smPlan($practice, [], ['columns' => ['title' => 'Sample practice, renamed']]);
    checkEq('Writers: an all-day series starts on a date, its rule\'s starts dates too', ['2027-01-05', '2027-01-05', '2027-01-05'],
        [$p['plan']['start'], $p['plan']['rule']['from']['start'], $p['plan']['rule']['to']['start']]);
    $p = smPlan($timed, [], ['columns' => ['title' => 'Sample practice, renamed']]);
    checkEq('Writers: a timed series starts in its zone', ['2027-01-05T20:00:00-08:00', '2027-01-05T20:00:00-08:00'], [$p['plan']['start'], $p['plan']['rule']['to']['start']]);
    $nightly = smSeries('Night shift', 'FREQ=MONTHLY', '2027-03-13 02:30');
    $p = smPlan($nightly, [], smDrag($nightly, '2027-05-13 02:30', '2027-05-14 02:30'));
    checkEq('Writers: a series anchored on the day before a skipped hour starts at its stored start, a key', smAt('2027-02-14 02:30'), str_replace(['T', 'Z'], [' ', ''], (string) $p['plan']['start']));
    checkEq('Writers: that start is written as a key', true, str_ends_with((string) $p['plan']['start'], 'Z'));
    // The day the edit started from: the day it is about and where it showed.
    $p = smPlan($practice, [$evening], ['acted' => $evening['recurrence_instance_utc'], 'columns' => ['title' => 'Sample practice, renamed']]);
    checkEq('Writers: started from the evening day of an all-day series: about Tue 12 Jan, shown at 20:00 in its own zone', [
        '2027-01-12', ['date' => '2027-01-12', 'at' => '2027-01-12T20:00:00-08:00', 'tz' => $la], '2027-01-12',
    ], [$p['plan']['acted']['day'] ?? null, $p['plan']['acted']['before'] ?? null, $p['plan']['acted']['shows'] ?? null]);

    // Outcomes: a day's instant and kind compared, never its end. A longer
    // series leaves every edited day where it starts, so each stays.
    $weekly = smSeries('Sample practice', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05 20:00');
    $named = smEdited($weekly, 2, '2027-01-12 20:00', ['title' => 'Sample practice, own'], null, ['title']);
    $longer = smDrag($weekly, '2027-01-05 20:00', '2027-01-05 20:00');
    $longer['landed']['end_utc'] = smAt('2027-01-05 21:30');
    $p = smPlan($weekly, [$named], $longer);
    $d = $edited($p);
    checkEq('Outcomes: an edited day whose length alone changes stays, and says so', [
        'stays', true, smAt('2027-01-12 21:30'),
    ], [$d['outcome'] ?? null, str_starts_with((string) ($d['outcomeWords'] ?? ''), 'stays'), $p['writes']['overrides'][2]['end_utc'] ?? null]);
    $allDayWeekly = smSeries('Sample practice', 'FREQ=WEEKLY;BYDAY=MO', '2027-01-04', 0, true);
    $namedAllDay = smEdited($allDayWeekly, 2, '2027-01-11', ['title' => 'Sample practice, own'], null, ['title']);
    $twoDays = smDrag($allDayWeekly, '2027-01-04', '2027-01-04');
    $twoDays['landed']['end_utc'] = '2027-01-06 00:00:00';
    $d = $edited(smPlan($allDayWeekly, [$namedAllDay], $twoDays));
    checkEq('Outcomes: an all-day edited day made two days long stays on its date', ['stays', ['date' => '2027-01-11']], [$d['outcome'] ?? null, $d['after'] ?? null]);

    // A reading's dates run from where the day acted on landed, however far
    // that is from the series' start: the readings are walked on past it.
    $thuFri = smSeries('Sample practice', 'FREQ=WEEKLY;BYDAY=TH,FR', '2027-01-07 20:00');
    $p = smPlan($thuFri, [], smDrag($thuFri, '2027-04-02 20:00', '2027-04-03 20:00'));
    $datesOf = static function (array $p, string $id): array {
        $o = array_values(array_filter(smGroup($p, 'reading')['options'] ?? [], static fn(array $o): bool => $o['id'] === $id))[0] ?? [];
        return array_map(static fn(array $e): string => substr($e['start'], 0, 10), $o['dates'] ?? []);
    };
    checkEq('Readings: a Friday twelve weeks on dragged to Saturday, the member reading\'s dates start there and run three and more', [
        ['2027-04-03', '2027-04-08', '2027-04-10'], ['2027-04-03', '2027-04-09', '2027-04-10'],
    ], [$datesOf($p, 'member'), $datesOf($p, 'pattern')]);
    // A reading's earlier day is found by instant: moved to Berlin, the
    // member reading's first Tuesday is Tue 5 Jan 03:00 there, Mon 4 Jan
    // 18:00 in Los Angeles, before the old Tue 5 Jan 18:00, though both are
    // numbered 5 Jan in their own zones; days read as days in two zones are
    // never compared.
    $class = smSeries('Sample class', 'FREQ=WEEKLY;BYDAY=TU,TH', '2027-01-05 18:00');
    $toFriBerlin = ['acted' => smAt('2027-01-14 18:00'), 'landed' => ['start_utc' => smAt('2027-01-15 18:00', 'Europe/Berlin'), 'end_utc' => smAt('2027-01-15 19:00', 'Europe/Berlin'), 'all_day' => 0, 'tzid' => 'Europe/Berlin']];
    $p = smPlan($class, [], $toFriBerlin, ['zone' => 'moment']);
    $member = array_values(array_filter(smGroup($p, 'reading')['options'] ?? [], static fn(array $o): bool => $o['id'] === 'member'))[0] ?? [];
    checkEq('Readings: dragged to Friday in Berlin at the same moment, the member reading\'s earlier day is its own Tue 5 Jan, where its instants first differ', ['2027-01-05', 'new'],
        [$member['earlier'] ?? null, $member['earlierSeries'] ?? null]);

    // A new pattern keeps the keys on its days: a series twice a day made
    // once a day at its later time keeps the later day's edited row on its
    // own key; the earlier time, which the new rule no longer has, is the
    // one that goes. Where the new day has no key the old one had, a key
    // keeps its place among the day's keys, and the time it had is gone.
    $twice = smSeries('Sample practice', 'FREQ=DAILY;BYHOUR=9,15;BYMINUTE=0', '2027-03-01 09:00');
    $later = smEdited($twice, 2, '2027-03-02 15:00', ['location' => 'Sample Hall'], null, ['location']);
    $earlier = smEdited($twice, 3, '2027-03-03 09:00', ['location' => 'Sample Room'], null, ['location']);
    $at15 = ['acted' => smAt('2027-03-01 09:00'), 'rrule' => 'FREQ=DAILY', 'landed' => ['start_utc' => smAt('2027-03-01 15:00'), 'end_utc' => smAt('2027-03-01 16:00'), 'all_day' => 0, 'tzid' => 'America/Los_Angeles']];
    $p = smPlan($twice, [$later, $earlier], $at15);
    $byKey = array_column(array_filter($p['plan']['days'], static fn(array $d): bool => $d['kind'] === 'edited'), null, 'key');
    $k15 = str_replace(' ', 'T', smAt('2027-03-02 15:00')) . 'Z';
    $k09 = str_replace(' ', 'T', smAt('2027-03-03 09:00')) . 'Z';
    checkEq('Keys: made once a day at 15:00, the 15:00 edited day stays on its key and the 09:00 one leaves the series', [
        [$k15, 'stays'], 'separate',
    ], [[$byKey[$k15]['newKey'] ?? 'missing', $byKey[$k15]['outcome'] ?? null], $byKey[$k09]['outcome'] ?? null]);
}

if (!empty($GLOBALS['__standalone'])) {
    echo "\n{$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed\n";
    exit($GLOBALS['__fail'] === 0 ? 0 : 1);
}
