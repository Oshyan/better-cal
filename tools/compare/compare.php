<?php

declare(strict_types=1);

// Runs every scenario on the three checkouts (boot.php, one process each,
// each its own database), then every other option of every 1.5.1 plan group
// as a variant on 1.5.1, and compares the rows. Prints the table and writes
// out/report.json and out/table.md (out/ is gitignored).
//
// Usage: php compare.php --v130=<checkout> --v140=<checkout> --v151=<checkout> [--only=s01,s02] [--no-run]
// Env as boot.php (BC_TEST_DB, BC_TEST_DB_USER, BC_TEST_DB_PASS). Each
// checkout needs its server/vendor (composer install). --no-run compares
// the outputs already in out/.
//
// A cell says: same (the rows match 1.5.1's), same occurrences, rows differ
// (what shows matches, the stored rows don't, and which columns), or differs
// (occurrence placement, occurrence fields, edited day lost / left the
// series / moved, undo incomplete, refused, errored). [= 1.5.1 option ...]
// names the 1.5.1 option whose result the old version matches, which is
// what makes a difference dialog-only. The Class column is the hand
// classification in classification.json.

$opts = getopt('', ['v130:', 'v140:', 'v151:', 'only:', 'no-run']);
$versions = ['v130' => $opts['v130'] ?? null, 'v140' => $opts['v140'] ?? null, 'v151' => $opts['v151'] ?? null];
foreach ($versions as $v => $path) {
    if ($path === null || !is_dir($path . '/server/src')) {
        fwrite(STDERR, "usage: php compare.php --v130=<checkout> --v140=<checkout> --v151=<checkout> [--only=ids] [--no-run]\n");
        exit(2);
    }
}
$here = __DIR__;
$out = $here . '/out';
@mkdir($out, 0777, true);
$files = glob($here . '/scenarios/*.json') ?: [];
sort($files);
if (isset($opts['only'])) {
    $only = explode(',', $opts['only']);
    $files = array_values(array_filter($files, static function (string $f) use ($only): bool {
        foreach ($only as $o) {
            if (str_starts_with(basename($f), $o)) {
                return true;
            }
        }
        return false;
    }));
}

/** Run boot.php for each [label => [checkout, files]] at once; wait for all. */
function runAll(string $here, string $out, array $jobs): void
{
    $procs = [];
    foreach ($jobs as $label => [$checkout, $files]) {
        if ($files === []) {
            continue;
        }
        $cmd = array_merge([PHP_BINARY, $here . '/boot.php', $checkout, $label, $out], $files);
        $log = $out . '/run-' . $label . '.txt';
        $procs[$label] = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
    }
    foreach ($procs as $label => $p) {
        $code = proc_close($p);
        if ($code !== 0) {
            fwrite(STDERR, "$label exited $code; see out/run-$label.txt\n");
        }
    }
}

if (!isset($opts['no-run'])) {
    runAll($here, $out, [
        'v130' => [$versions['v130'], $files],
        'v140' => [$versions['v140'], $files],
        'v151' => [$versions['v151'], $files],
    ]);
}

// ---- variants: every other enabled option of each 1.5.1 group --------------
$scenarios = [];
foreach ($files as $f) {
    $s = json_decode((string) file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
    $scenarios[$s['id']] = $s;
}
$variantFiles = [];
$all = [];
@mkdir($out . '/variants', 0777, true);
foreach ($scenarios as $id => $s) {
    $r = load($out, 'v151', $id);
    if ($r === null) {
        continue;
    }
    foreach ($r['steps'] as $step) {
        foreach ($step['plan']['groups'] ?? [] as $g) {
            foreach ($g['options'] as $o) {
                if ($o['disabled'] || $o['id'] === ($step['plan']['chosen'][$g['id']] ?? $g['selected'])) {
                    continue;
                }
                $v = $s;
                $v['id'] = $id . '~' . $step['i'] . '~' . $g['id'] . '=' . $o['id'];
                $v['steps'][$step['i']]['choices'] = [$g['id'] => $o['id']] + ($s['steps'][$step['i']]['choices'] ?? []);
                $vf = $out . '/variants/' . $v['id'] . '.json';
                file_put_contents($vf, json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $variantFiles[$id][] = $v['id'];
                $all[] = $vf;
            }
        }
    }
}
if (!isset($opts['no-run']) && !empty($all)) {
    runAll($here, $out, ['v151-variants' => [$versions['v151'], $all]]);
}

// ---- comparison --------------------------------------------------------------

function load(string $out, string $label, string $id): ?array
{
    $f = $out . '/' . $label . '/' . $id . '.json';
    return is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
}

/** Columns only some versions have: listed apart, left out of the comparison. */
const EXTRA = ['override_fields'];

/**
 * A snapshot's rows in a form two versions can share: live rows only (a
 * deleted row is gone either way), ids replaced by what the row is, the
 * parent pointer by its parent's identity, sorted.
 */
function canonRows(array $snap): array
{
    $byRel = [];
    foreach ($snap['events'] as $e) {
        $byRel[$e['rel']] = $e;
    }
    $ident = static fn(array $e): string => $e['calendar_id'] . '|' . $e['title'] . '|' . $e['start_utc'] . '|' . ($e['rrule'] ?? '');
    $rows = [];
    foreach ($snap['events'] as $e) {
        if ($e['deleted_at'] !== null) {
            continue;
        }
        $row = $e;
        unset($row['rel'], $row['deleted_at']);
        foreach (EXTRA as $x) {
            unset($row[$x]);
        }
        if ($row['recurrence_parent_id'] !== null) {
            $p = $byRel[$row['recurrence_parent_id']] ?? null;
            $row['recurrence_parent_id'] = $p === null ? 'missing' : $ident($p) . ($p['deleted_at'] !== null ? '|deleted' : '');
        }
        $tags = [];
        foreach ($snap['tags'] as [$rel, $name]) {
            if ($rel === $e['rel']) {
                $tags[] = $name;
            }
        }
        $people = [];
        foreach ($snap['people'] as [$rel, $name]) {
            if ($rel === $e['rel']) {
                $people[] = $name;
            }
        }
        sort($tags);
        sort($people);
        $row['tags'] = $tags;
        $row['people'] = $people;
        ksort($row);
        $rows[] = $row;
    }
    usort($rows, static fn(array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
    return $rows;
}

function liveCount(array $snap): int
{
    return count(array_filter($snap['events'], static fn(array $e): bool => $e['deleted_at'] === null));
}

/** The write steps' outcome: [step => last status, error]. */
function outcomes(array $r): array
{
    $o = [];
    foreach ($r['steps'] as $s) {
        if (in_array($s['do'], ['snapshot', 'alias'], true)) {
            continue;
        }
        $last = end($s['requests']);
        $o[$s['i']] = isset($s['exception']) ? [0, 'exception'] : ($last === false ? [0, 'none'] : [$last['status'], $last['error'] ?? null]);
    }
    return $o;
}

/** How two "after" snapshots differ, in the report's words. */
function how(array $a, array $b): array
{
    $why = [];
    $starts = static fn(array $s): array => array_map(static fn(array $o): string => $o['start'] . '/' . $o['end'] . ($o['allDay'] ? '/allday' : ''), $s['occurrences']);
    if ($starts($a) !== $starts($b)) {
        $why[] = 'occurrence placement';
    } elseif ($a['occurrences'] !== $b['occurrences']) {
        $why[] = 'occurrence fields';
    }
    $edited = static function (array $s): array {
        $o = [];
        foreach ($s['events'] as $e) {
            if ($e['deleted_at'] === null && $e['recurrence_parent_id'] !== null) {
                $o[] = $e['title'] . '|' . $e['start_utc'] . '|' . $e['end_utc'] . '|' . ($e['location'] ?? '') . '|' . $e['attendance'] . '|' . $e['status'] . '|' . $e['calendar_id'];
            }
        }
        sort($o);
        return $o;
    };
    $ea = $edited($a);
    $eb = $edited($b);
    if ($ea !== $eb) {
        if (count($ea) < count($eb)) {
            // An edited day 1.5.1 keeps: does it still show, as an event of its own?
            $shows = static function (string $sig) use ($a): bool {
                [$title, $start] = explode('|', $sig);
                foreach ($a['occurrences'] as $o) {
                    if ($o['title'] === $title && $o['start'] === str_replace(' ', 'T', $start) . 'Z') {
                        return true;
                    }
                }
                return false;
            };
            $missing = array_diff($eb, $ea);
            $why[] = array_filter($missing, static fn(string $m): bool => !$shows($m)) === [] ? 'edited day left the series' : 'edited day lost';
        } else {
            $why[] = count($ea) > count($eb) ? 'edited day row where 1.5.1 has none' : 'edited day moved or changed';
        }
    }
    if ($why === []) {
        $why[] = 'rows only (same occurrences)';
    }
    return $why;
}

/** Which columns differ between two canonical row lists, paired by title and start. */
function rowDetail(array $old, array $ref): array
{
    $key = static fn(array $r): string => $r['title'] . '|' . $r['start_utc'];
    $refBy = [];
    foreach ($ref as $r) {
        $refBy[$key($r)][] = $r;
    }
    $out = [];
    $matched = [];
    foreach ($old as $r) {
        if (in_array($r, $ref, true)) {
            $matched[] = $r;
            continue;
        }
        $twin = $refBy[$key($r)][0] ?? null;
        if ($twin === null) {
            $out[] = 'extra row ' . ($r['recurrence_parent_id'] !== null ? 'edited day ' : '') . $r['start_utc'];
            continue;
        }
        $matched[] = $twin;
        $cols = [];
        foreach ($r as $k => $v) {
            if (($twin[$k] ?? null) !== $v) {
                $cols[] = $k;
            }
        }
        $out[] = 'row ' . $r['start_utc'] . ' differs in ' . implode(', ', $cols);
    }
    foreach ($ref as $r) {
        if (!in_array($r, $matched, true) && !in_array($r, $old, true)) {
            $out[] = 'missing row ' . ($r['recurrence_parent_id'] !== null ? 'edited day ' : '') . $r['start_utc'];
        }
    }
    return array_values(array_unique($out));
}

$report = [];
foreach ($scenarios as $id => $s) {
    $ref = load($out, 'v151', $id);
    $line = ['id' => $id];
    // 1.5.1 against its own design: errors, unexpected statuses, an incomplete undo.
    $refNotes = [];
    if ($ref === null || $ref['error'] !== null) {
        $refNotes[] = 'errored: ' . ($ref['error'] ?? 'no output');
    }
    foreach ($ref['steps'] ?? [] as $st) {
        foreach ($st['requests'] ?? [] as $rq) {
            if ($rq['status'] >= 500) {
                $refNotes[] = "step {$st['i']}: {$rq['status']} " . ($rq['error'] ?? '');
            }
        }
        if (isset($st['unexpected'])) {
            $refNotes[] = "step {$st['i']}: " . $st['unexpected'];
        }
    }
    $undoOk = static fn(array $r): ?bool => isset($r['snapshots']['undone'], $r['snapshots']['before'])
        ? (canonRows($r['snapshots']['undone']) === canonRows($r['snapshots']['before']) && $r['snapshots']['undone']['occurrences'] === $r['snapshots']['before']['occurrences'])
        : null;
    if ($ref !== null && $undoOk($ref) === false) {
        $refNotes[] = 'undo incomplete';
    }
    $line['v151'] = $refNotes === [] ? 'reference' : 'reference; ' . implode('; ', $refNotes);
    $line['v151plan'] = array_values(array_filter(array_map(static fn(array $st): ?array => $st['plan'] ?? null, $ref['steps'] ?? [])));
    foreach (['v130', 'v140'] as $v) {
        $r = load($out, $v, $id);
        $cell = ['verdict' => null, 'how' => [], 'rows' => [], 'dialogOnly' => null];
        if ($r === null || $ref === null) {
            $cell['verdict'] = 'not run';
        } else {
            $oo = outcomes($r);
            $or = outcomes($ref);
            foreach ($oo as $i => [$st, $err]) {
                if ($st === 0 || $st >= 500) {
                    $cell['how'][] = "errored at step $i" . ($err ? " ($err)" : '');
                } elseif ($st >= 400 && ($or[$i][0] ?? 0) < 400) {
                    $cell['how'][] = "refused at step $i ($st $err)";
                } elseif ($st < 400 && ($or[$i][0] ?? 0) >= 400) {
                    $cell['how'][] = "accepted at step $i where 1.5.1 refused ({$or[$i][0]} {$or[$i][1]})";
                }
            }
            $a = $r['snapshots']['after'] ?? null;
            $b = $ref['snapshots']['after'] ?? null;
            $sameRows = $a !== null && $b !== null && canonRows($a) === canonRows($b);
            $sameOcc = $a !== null && $b !== null && $a['occurrences'] === $b['occurrences'];
            $u = $undoOk($r);
            if ($sameRows || $a === null || $b === null) {
                $cell['verdict'] = $cell['how'] === [] && $u !== false ? 'same' : 'differs';
            } elseif ($sameOcc) {
                $cell['verdict'] = $cell['how'] === [] && $u !== false ? 'same occurrences, rows differ' : 'differs';
                $cell['rows'] = rowDetail(canonRows($a), canonRows($b));
            } else {
                $cell['verdict'] = 'differs';
                $cell['how'] = array_merge($cell['how'], how($a, $b));
                $cell['rows'] = rowDetail(canonRows($a), canonRows($b));
            }
            if ($u === false) {
                $cell['how'][] = $r['snapshots']['undone']['occurrences'] === $r['snapshots']['before']['occurrences']
                    ? 'undo incomplete (rows only)' : 'undo incomplete';
            }
            // Is the old result one of 1.5.1's other readings?
            if ($cell['verdict'] !== 'same' && $a !== null) {
                foreach ($variantFiles[$id] ?? [] as $vid) {
                    $vr = load($out, 'v151-variants', $vid);
                    if ($vr !== null && isset($vr['snapshots']['after']) && $vr['error'] === null
                        && ($vr['snapshots']['after']['occurrences'] === $a['occurrences'])) {
                        $cell['dialogOnly'] = substr($vid, strlen($id) + 1) . (canonRows($vr['snapshots']['after']) === canonRows($a) ? ' (same rows)' : ' (same occurrences)');
                        break;
                    }
                }
            }
        }
        $line[$v] = $cell;
    }
    $line['variants'] = $variantFiles[$id] ?? [];
    $report[] = $line;
}
file_put_contents($out . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// The hand classification of each difference (classification.json), by scenario.
$classes = is_file($here . '/classification.json') ? json_decode((string) file_get_contents($here . '/classification.json'), true) : [];
$cellText = static function (array $c): string {
    $t = $c['verdict'];
    if ($c['how'] !== []) {
        $t .= ': ' . implode(', ', array_unique($c['how']));
    }
    if (($c['rows'] ?? []) !== [] && $c['verdict'] === 'same occurrences, rows differ') {
        $t .= ' (' . implode('; ', $c['rows']) . ')';
    }
    if ($c['dialogOnly'] !== null) {
        $t .= ' [= 1.5.1 option ' . $c['dialogOnly'] . ']';
    }
    return $t;
};
$md = "| Scenario | 1.3.0 | 1.4.0 | 1.5.1 | Class |\n|---|---|---|---|---|\n";
foreach ($report as $l) {
    $c = $classes[$l['id']] ?? null;
    $md .= '| ' . $l['id'] . ' | ' . $cellText($l['v130']) . ' | ' . $cellText($l['v140']) . ' | ' . $l['v151'] . ' | ' . ($c === null ? '' : $c['class']) . " |\n";
}
file_put_contents($out . '/table.md', $md);
echo $md;
