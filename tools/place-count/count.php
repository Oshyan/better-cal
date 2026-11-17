<?php

declare(strict_types=1);

// Counts what the reading of a location's text (LocationText::read, #328,
// docs/design/location-reading.md) makes of every stored location on this
// install, and what the background lookup asks for each, before (the 1.5.8
// forms) and after (Geocode::forms). Read-only: it asks no service and
// writes nothing. It prints shapes and counts only: never a location's text,
// a service's answer or coordinates, and any count from 1 to 4 as "under 5".
//
//   php tools/place-count/count.php            from the install's root, with
//                                              the code of #328 or later there
//
// Columns, per result of the reading:
//   texts      distinct location texts (per account)
//   events     events carrying them; upcoming of those
//   placed     events with coordinates (any source); of those:
//   kept       pins a person placed or picked, or the event's own data gave
//              (or of unknown origin): always shown, whatever the text
//   guessed    pins a lookup placed: shown only when the text reads as a place
//   missed     events the sweep tried that have none
//   untried    events nobody has looked up yet
//   before     requests the lookup could make for those texts the first time
//              each is looked up, by the 1.5.8 forms: the text, then each
//              easier form, as many as it takes (an upper bound: it stops at
//              the first pin it keeps)
//   after      the same by the reading's forms (0 for what it does not send)
//   new        of "after", the forms this install has never asked (not in its
//              lookup cache for that account's region): what the next sweep
//              and its retries of misses may still ask, once each

// From its file, or piped to php on stdin from the install's root.
$root = is_file(dirname(__DIR__, 2) . '/server/src/bootstrap.php') ? dirname(__DIR__, 2) : (string) getcwd();
require $root . '/server/src/bootstrap.php';

use BetterCal\Domain\Geocode;
use BetterCal\Domain\GeocodeSweep;
use BetterCal\Domain\LocationText;
use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;

/**
 * The 1.5.8 forms after the text (Geocode::variants before #328), kept here
 * as they were so "before" is measured by the rule that shipped.
 *
 * @return list<string>
 */
function formsBefore(string $q): array
{
    $q = Geocode::normalize($q);
    $out = [];
    $bare = preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/u', ' ', $q) ?? $q;
    $bare = trim(preg_replace('/\s{2,}/u', ' ', $bare) ?? $bare, " ,;-\t");
    $bare = preg_replace('/\s+,/u', ',', $bare) ?? $bare;
    if ($bare !== '' && $bare !== $q) {
        $out[] = $bare;
    }
    $base = $bare !== '' ? $bare : $q;
    $parts = array_values(array_filter(array_map('trim', explode(',', $base)), static fn(string $p): bool => $p !== ''));
    $street = static fn(string $p): bool => preg_match('/^\d+[a-z]?(?:-\d+)?\s+\S/iu', $p) === 1;
    if (count($parts) > 1 && !$street($parts[0])) {
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            if ($street($parts[$i])) {
                $out[] = implode(', ', array_slice($parts, $i));
                break;
            }
        }
        $out[] = implode(', ', array_slice($parts, 1));
    }
    $seen = [mb_strtolower($q) => true];
    $result = [$q];
    foreach ($out as $v) {
        $k = mb_strtolower($v);
        if ($v !== '' && !isset($seen[$k])) {
            $seen[$k] = true;
            $result[] = $v;
        }
    }
    return $result;
}

/** The row a reading is counted under. */
function shape(array $r): string
{
    if ($r['kind'] === 'none') {
        return 'not a place: ' . $r['why'];
    }
    if ($r['kind'] !== 'place') {
        return $r['kind'];
    }
    $s = $r['address'] === null ? 'place: no address found' : ($r['opensWithAddress'] ? 'place: address first' : 'place: name, then address');
    return $s . ($r['rest'] !== null ? ', with a rest' : '');
}

function cell(int $n): string
{
    return $n > 0 && $n < 5 ? 'under 5' : (string) $n;
}

$db = new Db(config()['db']);
$settings = new Settings($db);
$now = gmdate('Y-m-d H:i:s');

$groups = []; // account + text => counts
foreach ($db->all(
    "SELECT user_id, location, tzid, location_lat, location_source, geocoded_at,
            (end_utc >= ? OR (rrule IS NOT NULL AND rrule <> '')) AS upcoming
       FROM events WHERE deleted_at IS NULL AND location IS NOT NULL AND location <> ''",
    [$now]
) as $e) {
    $key = $e['user_id'] . "\0" . GeocodeSweep::normalizeLocation((string) $e['location']);
    $g = &$groups[$key];
    $g ??= ['user' => (int) $e['user_id'], 'text' => (string) $e['location'], 'tzid' => $e['tzid'], 'events' => 0, 'upcoming' => 0, 'placed' => 0, 'kept' => 0, 'guessed' => 0, 'missed' => 0, 'untried' => 0];
    $g['events']++;
    $g['upcoming'] += (int) $e['upcoming'];
    if ($e['location_lat'] !== null) {
        $g['placed']++;
        $g[($e['location_source'] ?? null) === 'lookup' ? 'guessed' : 'kept']++;
    } elseif ($e['geocoded_at'] !== null) {
        $g['missed']++;
    } else {
        $g['untried']++;
    }
    unset($g);
}

$rows = [];
$bias = [];
foreach ($groups as $g) {
    $r = LocationText::read($g['text']);
    $shape = shape($r);
    $row = &$rows[$shape];
    $row ??= ['texts' => 0, 'events' => 0, 'upcoming' => 0, 'placed' => 0, 'kept' => 0, 'guessed' => 0, 'missed' => 0, 'untried' => 0, 'before' => 0, 'after' => 0, 'new' => 0];
    foreach (['events', 'upcoming', 'placed', 'kept', 'guessed', 'missed', 'untried'] as $k) {
        $row[$k] += $g[$k];
    }
    $row['texts']++;
    $row['before'] += count(formsBefore($g['text']));
    $after = array_map(static fn(array $f): string => $f[0], Geocode::forms($g['text']));
    $row['after'] += count($after);
    $bias[$g['user']] ??= $settings->forUser($g['user']);
    [$lat, $lng] = GeocodeSweep::biasFor($bias[$g['user']], is_string($g['tzid']) ? $g['tzid'] : null);
    foreach ($after as $f) {
        if ($db->scalar('SELECT 1 FROM geocode_cache WHERE query_hash = ?', [Geocode::queryHash($f, $lat, $lng)]) === null) {
            $row['new']++;
        }
    }
    unset($row);
}
ksort($rows);

$cols = ['texts', 'events', 'upcoming', 'placed', 'kept', 'guessed', 'missed', 'untried', 'before', 'after', 'new'];
$total = array_fill_keys($cols, 0);
printf("%-40s%s\n", 'reading', implode('', array_map(static fn(string $c): string => str_pad($c, 10, ' ', STR_PAD_LEFT), $cols)));
foreach ($rows as $shape => $row) {
    printf("%-40s%s\n", $shape, implode('', array_map(static fn(string $c): string => str_pad(cell($row[$c]), 10, ' ', STR_PAD_LEFT), $cols)));
    foreach ($cols as $c) {
        $total[$c] += $row[$c];
    }
}
printf("%-40s%s\n", 'all', implode('', array_map(static fn(string $c): string => str_pad(cell($total[$c]), 10, ' ', STR_PAD_LEFT), $cols)));
echo "\nbefore and after are upper bounds per text the first time it is looked up; a cached form costs nothing.\n";
