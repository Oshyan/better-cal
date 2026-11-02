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
        [smOptions($p, 'reading'), array_map(static fn(string $s): string => substr($s, 0, 10), smGroup($p, 'reading')['options'][1]['next'] ?? [])]);

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
    $nexts = array_map(static fn(array $o): array => array_map(static fn(string $s): string => substr($s, 0, 10), $o['next'] ?? []), smGroup($p, 'bound')['options'] ?? []);
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
    checkEq('the ownership sentence names where the day shows', "One edited day becomes an event of its own: 'Rehearsal' on 16 Apr.", $p['plan']['ownership'] ?? null);
    checkEq('day names stay capitalized in the summary', true, str_contains($p['plan']['summary'], 'weekly on Tuesday at 19:00, now weekly on Monday and Thursday'));
    $created = BetterCal\Domain\Events::creationPlan(['rrule' => 'FREQ=WEEKLY;BYDAY=TU', 'start_utc' => smAt('2027-03-02 09:00'), 'end_utc' => smAt('2027-03-02 10:00'), 'all_day' => 0, 'tzid' => 'America/Los_Angeles'],
        ['start_utc' => smAt('2027-02-26 09:00')], 'America/Los_Angeles');
    checkEq('the creation line says "on or after"', true, str_contains((string) ($created['startWords'] ?? ''), 'the first Tuesday on or after the date you typed'));

}

if (!empty($GLOBALS['__standalone'])) {
    echo "\n{$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed\n";
    exit($GLOBALS['__fail'] === 0 ? 0 : 1);
}
