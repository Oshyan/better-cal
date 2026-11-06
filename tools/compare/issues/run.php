<?php

declare(strict_types=1);

// The issues the 1.4 and 1.5 releases closed about repeating events,
// replayed on checkouts of each version (#203). Each case in cases/ creates
// the invented data its issue describes, makes the change through that
// checkout's HTTP controllers and asserts what the issue (or its comments)
// asks for, never what a version does.
//
//   php tools/compare/issues/run.php [--db=127.0.0.1:3306] [--json=out.json] \
//       v1.3.0=/path/to/checkout-1.3.0 v1.4.0=/path/to/checkout-1.4.0 v1.5.1=/path/to/checkout-1.5.1 [-- case ...]
//
// Each checkout needs its server/vendor (composer install). Each version runs
// in a process of its own on a database of its own, cmp_issue_<label>, made,
// migrated with that checkout's migrations and dropped. BC_TEST_DB_USER and
// BC_TEST_DB_PASS as for the database suite (default root, no password).
//
// A case's verdict per version: pass (the issue's expectation holds), fail
// (it doesn't: the failing check's evidence says how), na (the feature the
// issue is about is absent there; the evidence says which) or error.

$db = '127.0.0.1:3306';
$json = null;
$checkouts = [];
$cases = [];
$rest = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($rest) {
        $cases[] = $arg;
    } elseif ($arg === '--') {
        $rest = true;
    } elseif (str_starts_with($arg, '--db=')) {
        $db = substr($arg, 5);
    } elseif (str_starts_with($arg, '--json=')) {
        $json = substr($arg, 7);
    } elseif (str_contains($arg, '=')) {
        [$label, $path] = explode('=', $arg, 2);
        $checkouts[$label] = $path;
    } else {
        fwrite(STDERR, "unknown argument: $arg\n");
        exit(2);
    }
}
if ($checkouts === []) {
    fwrite(STDERR, "name at least one checkout: label=/path/to/checkout\n");
    exit(2);
}

$tmp = sys_get_temp_dir() . '/bc-cmp-issues-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$procs = [];
foreach ($checkouts as $label => $path) {
    $report = "$tmp/$label.json";
    $procs[$label] = [proc_open(array_merge([PHP_BINARY, __DIR__ . '/replay.php', $path, $label, $db, $report], $cases),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes), $pipes[1], $report];
}
$reports = [];
foreach ($procs as $label => [$proc, $out, $report]) {
    $said = stream_get_contents($out);
    fclose($out);
    $code = proc_close($proc);
    $data = is_file($report) ? json_decode((string) file_get_contents($report), true) : null;
    @unlink($report);
    if (!is_array($data)) {
        fwrite(STDERR, "$label: exited $code with no report:\n$said\n");
        exit(1);
    }
    if (trim($said) !== '') {
        echo "[$label] " . trim($said) . "\n";
    }
    $reports[$label] = $data;
}
rmdir($tmp);

// The table: one row per case, one column per version.
$byCase = [];
foreach ($reports as $label => $r) {
    foreach ($r['results'] as $row) {
        $byCase[$row['id']]['issue'] = $row['issue'];
        $byCase[$row['id']]['title'] = $row['title'];
        $byCase[$row['id']]['v'][$label] = $row;
    }
}
ksort($byCase, SORT_NATURAL);
foreach ($byCase as $id => $c) {
    echo "\n#{$c['issue']} [$id] {$c['title']}\n";
    foreach ($checkouts as $label => $_) {
        $row = $c['v'][$label] ?? null;
        if ($row === null) {
            echo "  $label: not run\n";
            continue;
        }
        echo "  $label: " . strtoupper($row['verdict']) . "\n";
        foreach ($row['checks'] as $check) {
            $mark = $check['pass'] === true ? 'ok  ' : ($check['pass'] === false ? 'FAIL' : 'n/a ');
            echo "    $mark {$check['name']}: {$check['evidence']}\n";
        }
    }
}
if ($json !== null) {
    file_put_contents($json, json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
