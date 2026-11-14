<?php

declare(strict_types=1);

// The exhaustive table for GoogleConflict::threeWay (docs/design/google-conflict-choice.md, the page "1.5.7, Slice 1",
// sections (a) and threeWay; the model's section 7). Run on its own: php server/tests/unit/google-conflict-threeway.test.php
//
// The only call into server/src is threeWay itself, the thing under test. Every expectation comes from the reference
// below, written from the page's sentences and its table of equalities, and calls nothing in the product. A readable
// subset of rows is also listed by hand with its literal outcomes, and the reference is checked against those, so a
// mistake in the reference shows as its own failure.
//
// The shapes the table assumes, where the page does not spell them out (reported as "page undefined"): $base and $now
// are keyed by the six field names; `time` is one array of its columns {start_utc, end_utc, all_day} with the zone
// beside them as tzid (the zone is not compared); the answer is keyed by field name, each entry the outcome string or
// an array whose `outcome` is it; a delete passes every field as wanted with GoogleConflict::DELETED when the class
// defines one, else an object no map holds. All values invented.

use BetterCal\Domain\GoogleConflict;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

if (!function_exists('check')) {
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
}

/**
 * The page's rule, written from its sentences alone (nothing in server/src is called):
 *
 *   (a) title: trimmed strings equal, "(No title)" equal to empty. description: Sanitize::description of each equal,
 *       null equal to empty (the table uses plain text only, which that sanitizer leaves as it is, so the reference
 *       compares the strings with null as empty). location: trimmed strings equal. url: null equal to empty (valid
 *       https links only, which Ics::webUrl leaves as they are). status: both tentative or both not. time: start_utc,
 *       end_utc and all_day all equal; the zone is not compared.
 *   threeWay: not wanted and now = base: same; not wanted and now != base: kept; wanted and (now = base or now =
 *       wanted): send; wanted and now != base and now != wanted: ask. A delete wants every field with a value no map
 *       can hold.
 */
final class ThreeWayReference
{
    public const FIELDS = ['title', 'description', 'location', 'url', 'status', 'time'];

    public static function eq(string $field, mixed $a, mixed $b): bool
    {
        if (is_object($a) || is_object($b)) {
            return $a === $b; // the delete's value: equal to nothing a map holds
        }
        switch ($field) {
            case 'title':
                $t = static function (mixed $v): string {
                    $s = trim((string) $v);
                    return $s === '(No title)' ? '' : $s;
                };
                return $t($a) === $t($b);
            case 'description':
            case 'url':
                return (string) ($a ?? '') === (string) ($b ?? '');
            case 'location':
                return trim((string) ($a ?? '')) === trim((string) ($b ?? ''));
            case 'status':
                return ($a === 'tentative') === ($b === 'tentative');
            case 'time':
                return [(string) $a['start_utc'], (string) $a['end_utc'], (int) $a['all_day']]
                    === [(string) $b['start_utc'], (string) $b['end_utc'], (int) $b['all_day']];
        }
        throw new \LogicException("no field $field");
    }

    /** @return array<string,string> field => outcome */
    public static function outcomes(array $base, array $now, array $wanted): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            if (!array_key_exists($f, $wanted)) {
                $out[$f] = self::eq($f, $now[$f], $base[$f]) ? 'same' : 'kept';
            } else {
                $out[$f] = self::eq($f, $now[$f], $base[$f]) || self::eq($f, $now[$f], $wanted[$f]) ? 'send' : 'ask';
            }
        }
        return $out;
    }
}

$time = static fn(string $s, string $e, int $allDay = 0, string $tz = 'America/Los_Angeles'): array => ['start_utc' => $s, 'end_utc' => $e, 'all_day' => $allDay, 'tzid' => $tz];

// Each field's values: B (base), Bs (another spelling the page calls the same as B, or null), G (Google's other value),
// Gs (another spelling of G, or null), T (a third value, or null where the field has only two).
$V = [
    'title' => ['B' => 'Team sync', 'Bs' => ' Team sync ', 'G' => 'Standup', 'Gs' => "Standup  ", 'T' => 'Weekly sync'],
    'description' => ['B' => 'Agenda for the week', 'Bs' => null, 'G' => 'Agenda and the slides', 'Gs' => null, 'T' => 'Bring the notes'],
    'location' => ['B' => 'Room 2', 'Bs' => 'Room 2 ', 'G' => 'Sample Hall', 'Gs' => ' Sample Hall', 'T' => 'Room 5'],
    'url' => ['B' => 'https://example.test/sync', 'Bs' => null, 'G' => 'https://example.test/standup', 'Gs' => null, 'T' => 'https://example.test/weekly'],
    'status' => ['B' => 'confirmed', 'Bs' => null, 'G' => 'tentative', 'Gs' => null, 'T' => null],
    'time' => [
        'B' => $time('2027-03-03 22:00:00', '2027-03-03 23:00:00'),
        'Bs' => $time('2027-03-03 22:00:00', '2027-03-03 23:00:00', 0, 'UTC'),
        'G' => $time('2027-03-03 23:00:00', '2027-03-04 00:00:00'),
        'Gs' => $time('2027-03-03 23:00:00', '2027-03-04 00:00:00', 0, 'Europe/Paris'),
        'T' => $time('2027-03-04 22:00:00', '2027-03-04 23:00:00'),
    ],
];
// The empty spellings the page names as the same: "(No title)" and empty, null and empty, not tentative in two forms.
$empties = ['title' => ['(No title)', ''], 'description' => [null, ''], 'url' => [null, ''], 'location' => ['', '   '], 'status' => ['confirmed', null]];

$defaults = array_map(static fn(array $v): mixed => $v['B'], $V);
$deleted = defined(GoogleConflict::class . '::DELETED') ? constant(GoogleConflict::class . '::DELETED') : new \stdClass();

/** @var list<array{0:string,1:array,2:array,3:array}> label, base, now, wanted */
$rows = [];
$add = static function (string $label, array $b, array $g, array $w) use (&$rows, $defaults): void {
    $rows[] = [$label, $b + $defaults, $g + $defaults, $w];
};
$show = static fn(mixed $v): string => is_object($v) ? 'DELETED' : json_encode($v, JSON_UNESCAPED_SLASHES);

// One field at a time: every relation that matters.
foreach ($V as $f => $v) {
    $baseNow = [[$v['B'], $v['B']]];
    if ($v['Bs'] !== null) {
        $baseNow[] = [$v['B'], $v['Bs']];
        $baseNow[] = [$v['Bs'], $v['B']];
    }
    foreach ($baseNow as [$b, $g]) {
        $add("$f: now = base ({$show($b)} / {$show($g)}), not wanted", [$f => $b], [$f => $g], []);
        $add("$f: now = base ({$show($b)} / {$show($g)}), wanted {$show($v['T'] ?? $v['G'])}", [$f => $b], [$f => $g], [$f => $v['T'] ?? $v['G']]);
        $add("$f: now = base ({$show($b)} / {$show($g)}), delete", [$f => $b], [$f => $g], array_fill_keys(ThreeWayReference::FIELDS, $deleted));
    }
    $gs = [$v['G']];
    if ($v['Gs'] !== null) {
        $gs[] = $v['Gs'];
    }
    foreach ($gs as $g) {
        $add("$f: now {$show($g)} != base, not wanted", [$f => $v['B']], [$f => $g], []);
        $add("$f: now {$show($g)} != base, wanted the same exactly", [$f => $v['B']], [$f => $g], [$f => $g]);
        foreach ($gs as $w) {
            if ($w !== $g) {
                $add("$f: now {$show($g)} != base, wanted {$show($w)}, the same spelled otherwise", [$f => $v['B']], [$f => $g], [$f => $w]);
            }
        }
        if ($v['T'] !== null) {
            $add("$f: now {$show($g)} != base, wanted a third value {$show($v['T'])}", [$f => $v['B']], [$f => $g], [$f => $v['T']]);
        }
        $add("$f: now {$show($g)} != base, delete", [$f => $v['B']], [$f => $g], array_fill_keys(ThreeWayReference::FIELDS, $deleted));
    }
    if ($v['Bs'] !== null && $v['Gs'] !== null) {
        // Base spelled one way, Google's change and the wanted value each spelled another.
        $add("$f: base {$show($v['Bs'])}, now {$show($v['G'])}, wanted {$show($v['Gs'])}", [$f => $v['Bs']], [$f => $v['G']], [$f => $v['Gs']]);
        $add("$f: base {$show($v['Bs'])}, now {$show($v['Gs'])}, not wanted", [$f => $v['Bs']], [$f => $v['Gs']], []);
    }
}
// The empty spellings: the same when both are empty; a value against either empty is a difference.
foreach ($empties as $f => [$e1, $e2]) {
    foreach ([[$e1, $e1], [$e1, $e2], [$e2, $e1]] as [$b, $g]) {
        $add("$f: empty base {$show($b)}, now {$show($g)}, not wanted", [$f => $b], [$f => $g], []);
        if ($f !== 'status') {
            $add("$f: empty base {$show($b)}, now {$show($g)}, wanted {$show($V[$f]['T'])}", [$f => $b], [$f => $g], [$f => $V[$f]['T']]);
        }
    }
    $full = $V[$f]['B'];
    // Removed at Google, and removed in the change too, spelled the other way: no question.
    $add("$f: base {$show($full)}, removed at Google as {$show($e1)}, removed here as {$show($e2)}", [$f => $full], [$f => $e1], [$f => $e2]);
    // Filled at Google from empty, not touched here: kept.
    $add("$f: base empty {$show($e2)}, filled at Google {$show($full)}, not wanted", [$f => $e2], [$f => $full], []);
    if ($f !== 'status') {
        // Removed at Google, changed here to a third value: a question.
        $add("$f: base {$show($full)}, removed at Google as {$show($e1)}, wanted {$show($V[$f]['T'])}", [$f => $full], [$f => $e1], [$f => $V[$f]['T']]);
    }
}
// The time, one column at a time: each column is the whole value, never one part alone.
$tB = $V['time']['B'];
$timeParts = [
    'the start alone moved' => $time('2027-03-03 21:00:00', '2027-03-03 23:00:00'),
    'the end alone moved' => $time('2027-03-03 22:00:00', '2027-03-03 23:30:00'),
    'all-day alone switched' => $time('2027-03-03 22:00:00', '2027-03-03 23:00:00', 1),
    'the zone alone changed' => $time('2027-03-03 22:00:00', '2027-03-03 23:00:00', 0, 'Asia/Tokyo'),
];
foreach ($timeParts as $gl => $g) {
    $add("time: at Google $gl, not wanted", ['time' => $tB], ['time' => $g], []);
    foreach ($timeParts as $wl => $w) {
        if ($wl !== 'the zone alone changed') {
            $add("time: at Google $gl, here $wl", ['time' => $tB], ['time' => $g], ['time' => $w]);
        }
    }
}

// Every pair of fields, each in each of its states, so the fields are seen to be independent.
$states = static function (string $f) use ($V): array {
    $v = $V[$f];
    $s = [
        'same' => [$v['B'], $v['B'], false, null],
        'kept' => [$v['B'], $v['G'], false, null],
        'send over unchanged' => [$v['B'], $v['B'], true, $v['T'] ?? $v['G']],
        'send, both made it' => [$v['B'], $v['G'], true, $v['G']],
    ];
    if ($v['T'] !== null) {
        $s['ask'] = [$v['B'], $v['G'], true, $v['T']];
    }
    return $s;
};
$fields = ThreeWayReference::FIELDS;
foreach ($fields as $i => $f1) {
    foreach (array_slice($fields, $i + 1) as $f2) {
        foreach ($states($f1) as $l1 => [$b1, $g1, $has1, $w1]) {
            foreach ($states($f2) as $l2 => [$b2, $g2, $has2, $w2]) {
                $w = ($has1 ? [$f1 => $w1] : []) + ($has2 ? [$f2 => $w2] : []);
                $add("pair $f1 $l1, $f2 $l2", [$f1 => $b1, $f2 => $b2], [$f1 => $g1, $f2 => $g2], $w);
            }
        }
    }
}
// All six at once, mixed.
$add('six at once: title asks, description same, location kept, url both made it, status kept, time sent',
    [], ['title' => 'Standup', 'location' => 'Sample Hall', 'url' => $V['url']['G'], 'status' => 'tentative'],
    ['title' => 'Weekly sync', 'url' => $V['url']['G'], 'time' => $V['time']['T']]);
$add('six at once: every field changed at Google, none wanted', [], array_map(static fn(array $v): mixed => $v['G'], $V), []);
$add('six at once: every field changed at Google and wanted at a third value where one exists',
    [], array_map(static fn(array $v): mixed => $v['G'], $V), array_map(static fn(array $v): mixed => $v['T'] ?? $v['G'], $V));
$add('six at once: a delete over nothing changed', [], [], array_fill_keys($fields, $deleted));
$add('six at once: a delete over a place and a time changed', [], ['location' => 'Sample Hall', 'time' => $V['time']['G']], array_fill_keys($fields, $deleted));

// Hand-listed rows with their literal outcomes (the page's own examples where it has them). Each is checked against
// the reference as well as against the product, so a mistake in the reference shows here.
$literal = [
    ['the page\'s example: title asked, place kept', ['title' => 'Team sync', 'location' => 'Room 1'], ['title' => 'Standup', 'location' => 'Room 2'], ['title' => 'Weekly sync'],
        ['title' => 'ask', 'location' => 'kept']],
    ['nothing changed at Google, title wanted', [], [], ['title' => 'Weekly sync'], ['title' => 'send']],
    ['time changed at Google, title changed here', [], ['time' => $V['time']['G']], ['title' => 'Weekly sync'], ['title' => 'send', 'time' => 'kept']],
    ['both made the same title', [], ['title' => 'Weekly sync'], ['title' => 'Weekly sync'], ['title' => 'send']],
    ['both made the same title, Google\'s with spaces around it', [], ['title' => '  Weekly sync '], ['title' => 'Weekly sync'], ['title' => 'send']],
    ['"(No title)" at Google over an empty base is no change', ['title' => ''], ['title' => '(No title)'], [], []],
    ['null notes and empty notes are the same', ['description' => null], ['description' => ''], [], []],
    ['null link and empty link are the same', ['url' => ''], ['url' => null], [], []],
    ['Maybe here over Maybe at Google: nothing to ask', [], ['status' => 'tentative'], ['status' => 'tentative'], ['status' => 'send']],
    ['Maybe here over a title change at Google', [], ['title' => 'Standup'], ['status' => 'tentative'], ['status' => 'send', 'title' => 'kept']],
    ['Planned here, Google set it Maybe: kept', [], ['status' => 'tentative'], [], ['status' => 'kept']],
    ['a zone-only change at Google is no change', [], ['time' => $time('2027-03-03 22:00:00', '2027-03-03 23:00:00', 0, 'UTC')], [], []],
    ['a resize of the end over a start moved at Google asks', [], ['time' => $time('2027-03-03 21:00:00', '2027-03-03 23:00:00')],
        ['time' => $time('2027-03-03 22:00:00', '2027-03-03 23:30:00')], ['time' => 'ask']],
    ['all-day at Google, a drag here to another time asks', [], ['time' => $time('2027-03-03 00:00:00', '2027-03-04 00:00:00', 1, 'UTC')],
        ['time' => $V['time']['T']], ['time' => 'ask']],
    ['place removed at Google, changed here', [], ['location' => ''], ['location' => 'Room 5'], ['location' => 'ask']],
    ['place removed at Google and here', [], ['location' => ''], ['location' => '  '], ['location' => 'send']],
    ['a delete of an unchanged event sends', [], [], 'delete', array_fill_keys($fields, 'send')],
    ['a delete over a place change asks about the place alone', [], ['location' => 'Sample Hall'], 'delete', ['location' => 'ask'] + array_fill_keys($fields, 'send')],
    ['a delete over notes spelled null where they were empty sends', ['description' => ''], ['description' => null], 'delete', array_fill_keys($fields, 'send')],
    ['notes changed at Google, title changed here, place sent at a third value over its change', [], ['description' => 'Agenda and the slides', 'location' => 'Sample Hall'],
        ['title' => 'Weekly sync', 'location' => 'Room 5'], ['title' => 'send', 'description' => 'kept', 'location' => 'ask']],
];

if (!class_exists(GoogleConflict::class) || !method_exists(GoogleConflict::class, 'threeWay')) {
    check('GoogleConflict::threeWay exists (server/src/Domain/GoogleConflict.php)', false, 'the class or the method is missing: the table of ' . (count($rows) + count($literal)) . ' rows did not run');
    echo "\n{$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed\n";
    exit(1);
}

$outcomeOf = static function (mixed $entry): mixed {
    if (is_string($entry)) {
        return $entry;
    }
    return is_array($entry) ? ($entry['outcome'] ?? $entry[0] ?? null) : null;
};
$product = static function (array $base, array $now, array $wanted) use ($outcomeOf): array {
    $got = GoogleConflict::threeWay($base, $now, $wanted);
    $out = [];
    foreach ($got as $f => $entry) {
        $out[$f] = $outcomeOf($entry);
    }
    ksort($out);
    return $out;
};

foreach ($literal as [$label, $b, $g, $w, $expect]) {
    $base = $b + $defaults;
    $now = $g + $defaults;
    $wanted = $w === 'delete' ? array_fill_keys($fields, $deleted) : $w;
    $want = $expect + array_fill_keys($fields, 'same');
    ksort($want);
    $ref = ThreeWayReference::outcomes($base, $now, $wanted);
    ksort($ref);
    check("reference agrees with the hand-listed row: $label", $ref === $want, 'reference ' . json_encode($ref) . ', listed ' . json_encode($want));
    try {
        $got = $product($base, $now, $wanted);
        check("threeWay, listed: $label", $got === $want, 'expected ' . json_encode($want) . ', got ' . json_encode($got));
    } catch (\Throwable $e) {
        check("threeWay, listed: $label", false, get_class($e) . ': ' . $e->getMessage());
    }
}

foreach ($rows as [$label, $base, $now, $wanted]) {
    $want = ThreeWayReference::outcomes($base, $now, $wanted);
    ksort($want);
    try {
        $got = $product($base, $now, $wanted);
        check("threeWay: $label", $got === $want, 'expected ' . json_encode($want) . ', got ' . json_encode($got));
    } catch (\Throwable $e) {
        check("threeWay: $label", false, get_class($e) . ': ' . $e->getMessage());
    }
    // Pure: the same inputs give the same answer again.
}
check('threeWay is pure: the same row twice gives the same answer', $product($rows[0][1], $rows[0][2], $rows[0][3]) === $product($rows[0][1], $rows[0][2], $rows[0][3]));

echo count($rows) . ' generated rows, ' . count($literal) . " listed rows\n";
echo "\n{$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed\n";
exit($GLOBALS['__fail'] === 0 ? 0 : 1);
