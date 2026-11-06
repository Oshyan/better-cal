<?php
// Resource use of reading and editing repeating events, one checkout at a
// time, for the comparison of 1.5.1 with 1.3.0 and 1.4.0 (issue #203).
//
//   php tools/compare/perf/bench.php --checkout DIR --label v151 [--work DIR]
//       [--db 127.0.0.1:33308] [--size small|full] [--reps 5] [--warmup 1]
//       [--count] [--only read.week,edit.large.drag,...] [--out FILE] [--keep-db] [--no-opcache] [--idle]
//
// --idle says the run had the machine and database to itself; report.php
// shows wall times only from runs marked so. --count turns the statement
// counts on (a separate run: counting loads a patched Db, see probe.php).
// --keep-db keeps the seeded databases for the next run of the same label.
//
// The checkout is only read: its server/ is copied (without vendor/ and
// tests/) into --work/<label>, with a vendor/ of per-package links to the
// checkout's own packages, so the app's runtime files land in the copy.
// A database cmp_perf_<label> is made, migrated and seeded (seed.php) with
// that version's own code, and copied to cmp_perf_<label>_base; before each
// repetition of an edit the working database is put back from that copy.
//
// Every request is a PHP process of its own: php-cgi running the checkout's
// server/public/index.php (or dav.php), with the request as a web server
// passes it (method, URI, cookie, X-CSRF, JSON body on stdin), under
// /usr/bin/time -l for the process's maximum resident set size. probe.php,
// loaded before the entry file, records the request's wall time and peak
// memory; with --count it also reads the server's per-connection counters
// for the request's own statements and rows written (see probe.php).
// The web requests sign in with a browser session (cookie and CSRF), as the
// app does; CalDAV with Basic auth and a personal access token as password,
// which skips the password hash every request would otherwise pay.
//
// An operation is the requests the client sends for it: on 1.5.1 a planned
// change is a preview and a commit (and, if the server asks, another
// commit), on 1.3.0 and 1.4.0 the one PATCH or DELETE. Its Undo is what the
// app's toast sends: POST /undo on 1.3.0 and 1.4.0, POST /activity/:id/undo
// for each entry X-Undo-Entries names (newest first) on 1.5.1.
//
// Timing figures are valid only on a machine and database with nothing else
// running. Memory, RSS and counts don't depend on that.
//
// Output: a JSON file of every request's figures, and a summary on stdout.
// report.php puts several such files side by side.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$opts = getopt('', ['checkout:', 'label:', 'work:', 'db:', 'size:', 'reps:', 'warmup:', 'count', 'only:', 'out:', 'keep-db', 'no-opcache', 'php-cgi:', 'idle']);
foreach (['checkout', 'label'] as $k) {
    if (!isset($opts[$k])) {
        fwrite(STDERR, "--$k is required (see the header of this file)\n");
        exit(2);
    }
}
$checkout = rtrim((string) realpath((string) $opts['checkout']), '/');
$label = (string) $opts['label'];
if (!preg_match('/^[a-z0-9_]+$/', $label) || !is_file("$checkout/server/public/index.php")) {
    fwrite(STDERR, "--label must be [a-z0-9_]+ and --checkout a Better-Cal checkout\n");
    exit(2);
}
$work = rtrim((string) ($opts['work'] ?? sys_get_temp_dir() . '/bc-perf'), '/');
[$dbHost, $dbPort] = array_pad(explode(':', (string) ($opts['db'] ?? '127.0.0.1:33308'), 2), 2, '3306');
$size = (string) ($opts['size'] ?? 'full');
$reps = max(1, (int) ($opts['reps'] ?? 5));
$warmup = max(0, (int) ($opts['warmup'] ?? 1));
$count = isset($opts['count']);
$only = isset($opts['only']) ? explode(',', (string) $opts['only']) : null;
$phpCgi = (string) ($opts['php-cgi'] ?? trim((string) shell_exec('command -v php-cgi')));
$here = __DIR__;
$run = "$work/$label";
$dbName = "cmp_perf_$label";
$baseName = "cmp_perf_{$label}_base";
$out = (string) ($opts['out'] ?? "$work/$label" . ($count ? '-count' : '') . '.json');

// ---- The run copy ------------------------------------------------------------

/** Copy a tree, skipping the top-level names in $skip. */
function copyTree(string $from, string $to, array $skip = []): void
{
    if (!is_dir($to)) {
        mkdir($to, 0755, true);
    }
    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..' || in_array($name, $skip, true)) {
            continue;
        }
        $a = "$from/$name";
        $b = "$to/$name";
        if (is_link($a)) {
            @symlink((string) readlink($a), $b);
        } elseif (is_dir($a)) {
            copyTree($a, $b);
        } else {
            copy($a, $b);
        }
    }
}

if (!is_dir("$run/server/src")) {
    copyTree("$checkout/server", "$run/server", ['vendor', 'tests', 'data']);
    mkdir("$run/server/data", 0755, true);
    copy("$checkout/VERSION", "$run/VERSION");
    // vendor/: autoload.php and composer/ copied (so Composer's paths resolve
    // to this copy's src/), every package a link to the checkout's.
    $v = "$checkout/server/vendor";
    mkdir("$run/server/vendor", 0755, true);
    copy("$v/autoload.php", "$run/server/vendor/autoload.php");
    copyTree((string) realpath("$v/composer"), "$run/server/vendor/composer");
    foreach (scandir($v) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === 'composer' || $name === 'autoload.php') {
            continue;
        }
        symlink((string) realpath("$v/$name"), "$run/server/vendor/$name");
    }
}
@mkdir("$run/.runtime-cache", 0755, true);
@mkdir("$run/opcache", 0755, true);
$version = trim((string) file_get_contents("$run/VERSION"));
$plans = version_compare($version, '1.5.0', '>=');

// ---- The database -------------------------------------------------------------

$server = new PDO("mysql:host=$dbHost;port=$dbPort;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$env = [
    'BETTERCAL_DB_DSN' => "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4",
    'BETTERCAL_DB_USER' => 'root',
    'BETTERCAL_DB_PASS' => '',
    'BETTERCAL_BASE_URL' => 'http://localhost',
    // Invented, for this benchmark's throwaway databases only.
    'BETTERCAL_SESSION_SECRET' => str_repeat('5a', 32),
    'PATH' => (string) getenv('PATH'),
    'TZ' => 'UTC',
];

$fixtureFile = "$run/fixture.json";
$seeded = (bool) $server->query("SHOW DATABASES LIKE '$baseName'")->fetch();
if (!$seeded) {
    $server->exec("DROP DATABASE IF EXISTS `$dbName`");
    $server->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $t = microtime(true);
    $p = proc_open([PHP_BINARY, '-d', 'memory_limit=2G', "$here/seed.php", "$run/server", $fixtureFile, $size], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, null, $env);
    if (proc_close($p) !== 0) {
        fwrite(STDERR, "seeding failed\n");
        exit(1);
    }
    fprintf(STDERR, "[%s] seeded in %.1f s\n", $label, microtime(true) - $t);
    $server->exec("CREATE DATABASE `$baseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    copyDatabase($server, $dbName, $baseName, true);
}
$fixture = json_decode((string) file_get_contents($fixtureFile), true);

/** Every table's rows from $from into $to (made there first when $create). Foreign keys off while it runs. */
function copyDatabase(PDO $server, string $from, string $to, bool $create): void
{
    $server->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
    $tables = $server->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $server->quote($from) . " AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $cols = $server->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $server->quote($from) . " AND TABLE_NAME = " . $server->quote($t) . " AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
        $list = implode(', ', array_map(static fn($c) => "`$c`", $cols));
        if ($create) {
            $server->exec("CREATE TABLE `$to`.`$t` LIKE `$from`.`$t`");
        } else {
            $server->exec("TRUNCATE TABLE `$to`.`$t`");
        }
        $server->exec("INSERT INTO `$to`.`$t` ($list) SELECT $list FROM `$from`.`$t`");
    }
    $server->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
}

$restore = static function () use ($server, $baseName, $dbName, $run): void {
    copyDatabase($server, $baseName, $dbName, false);
    // The worker check runs every few minutes from some request; keep it out of every one measured.
    @touch("$run/.runtime-cache/worker-watch");
};

/** The account's events as stored, ids and timestamps aside, one sorted line each: equal before an edit and after its undo when the undo put everything back. */
$eventsHash = static function () use ($server, $dbName): array {
    $rows = $server->query("SELECT * FROM `$dbName`.`events`")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        unset($r['id'], $r['updated_at'], $r['created_at']);
        $out[] = json_encode($r);
    }
    sort($out);
    return $out;
};

// ---- One request ----------------------------------------------------------------

/**
 * One request in a PHP process of its own.
 *
 * @return array{status:int, headers:array<string,string>, body:string, json:mixed, ms:float, peak:int, peakUsed:int, rss:int, wallMs:float, db?:array}
 */
$request = static function (string $method, string $uri, ?array $body = null, bool $dav = false, ?string $davBody = null) use ($run, $env, $fixture, $phpCgi, $here, $count, $opts): array {
    $entry = "$run/server/public/" . ($dav ? 'dav.php' : 'index.php');
    $outFile = tempnam(sys_get_temp_dir(), 'bcperf');
    $payload = $dav ? (string) $davBody : ($body === null ? '' : json_encode($body));
    $e = $env + [
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'SERVER_SOFTWARE' => 'bc-perf',
        'SERVER_NAME' => 'localhost',
        'SERVER_PORT' => '80',
        'REMOTE_ADDR' => '127.0.0.1',
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $uri,
        'QUERY_STRING' => (string) parse_url($uri, PHP_URL_QUERY),
        'SCRIPT_FILENAME' => $entry,
        'SCRIPT_NAME' => $dav ? '/dav.php' : '/index.php',
        'DOCUMENT_ROOT' => "$run/server/public",
        'REDIRECT_STATUS' => '200',
        'HTTP_HOST' => 'localhost',
        'CONTENT_LENGTH' => (string) strlen($payload),
        'BC_PERF_OUT' => $outFile,
        'BC_PERF_COUNT' => $count ? '1' : '0',
        'BC_PERF_DB' => "$run/server/src/Infra/Db.php",
    ];
    if ($dav) {
        $e['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode($fixture['email'] . ':' . $fixture['token']);
        $e['HTTP_DEPTH'] = '1';
        $e['CONTENT_TYPE'] = 'application/xml; charset=utf-8';
    } else {
        $e['HTTP_COOKIE'] = 'bc_session=' . $fixture['session'];
        $e['HTTP_X_CSRF'] = $fixture['csrf'];
        if ($body !== null) {
            $e['CONTENT_TYPE'] = 'application/json';
        }
    }
    $ini = ['-d', "auto_prepend_file=$here/probe.php", '-d', 'memory_limit=2G', '-d', 'display_errors=0', '-d', 'log_errors=1',
        '-d', "error_log=$run/php-errors.log", '-d', 'date.timezone=UTC', '-d', 'cgi.force_redirect=1'];
    if (!isset($opts['no-opcache'])) {
        // Compiled scripts kept on disk between the processes, as a
        // long-running PHP keeps them in memory: the request runs warm.
        $ini = array_merge($ini, ['-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', '-d', "opcache.file_cache=$run/opcache", '-d', 'opcache.file_cache_only=1']);
    }
    $t = hrtime(true);
    $p = proc_open(array_merge(['/usr/bin/time', '-l', $phpCgi], $ini), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, "$run/server/public", $e);
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $raw = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    $wallMs = (hrtime(true) - $t) / 1e6;
    $m = json_decode((string) @file_get_contents($outFile), true) ?: [];
    @unlink($outFile);
    [$head, $bodyOut] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $headers = [];
    foreach (explode("\r\n", $head) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    $status = isset($headers['status']) ? (int) $headers['status'] : (int) ($m['status'] ?? 200);
    preg_match('/(\d+)\s+maximum resident set size/', $err, $rss);
    $json = json_decode($bodyOut, true);
    // What the answer carried: occurrences for a window, objects for a REPORT.
    $items = $dav ? substr_count($bodyOut, 'BEGIN:VEVENT') : (is_array($json['events'] ?? null) ? count($json['events']) : null);
    return ['status' => $status, 'headers' => $headers, 'body' => $bodyOut, 'json' => $json, 'items' => $items, 'bytes' => strlen($bodyOut),
        'ms' => (float) ($m['ms'] ?? -1), 'peak' => (int) ($m['peak'] ?? -1), 'peakUsed' => (int) ($m['peakUsed'] ?? -1),
        'rss' => (int) ($rss[1] ?? -1), 'wallMs' => $wallMs] + (isset($m['db']) ? ['db' => $m['db']] : []) + (isset($m['walkCache']) ? ['walkCache' => $m['walkCache']] : []);
};

// ---- The operations --------------------------------------------------------------

$cal = (int) $fixture['calendarId'];
$large = (int) $fixture['largeCalendarId'];
$ids = $fixture['ids'];
$q = static fn(string $s, string $e, ?string $cals = null): string => '/api/v1/events?start=' . rawurlencode($s) . '&end=' . rawurlencode($e) . ($cals !== null ? '&calendars=' . $cals : '');
$windows = [
    'week' => ['2026-11-30T00:00:00-08:00', '2026-12-07T00:00:00-08:00'],
    'month' => ['2026-11-01T00:00:00-07:00', '2026-12-01T00:00:00-08:00'],
    'year' => ['2026-11-01T00:00:00-07:00', '2027-11-01T00:00:00-07:00'],
];
$davUtc = static fn(string $iso): string => (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
$davQuery = static fn(string $s, string $e): string => '<?xml version="1.0" encoding="utf-8"?>'
    . '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop>'
    . '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:time-range start="' . $davUtc($s) . '" end="' . $davUtc($e) . '"/>'
    . '</c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
$davUri = static fn(int $calendarId): string => '/dav/calendars/' . $fixture['email'] . '/cal-' . $calendarId . '/';

/** An op: a list of steps, each [role, fn(): array result]; reads have no undo and need no restore. */
$ops = [];
foreach ($windows as $w => [$s, $e]) {
    $ops["read.$w"] = ['kind' => 'read', 'steps' => static fn() => [['read', $request('GET', $q($s, $e))]]];
}
// The whole set's year in one request is over the expansion budget (10,000
// occurrences, the same on every version): 422 event_window_too_large, and
// the client then asks in pieces. read.year.whole is that refused request;
// read.year is the year as twelve month requests.
$ops['read.year.whole'] = $ops['read.year'];
$ops['read.year'] = ['kind' => 'read', 'steps' => static function () use ($request, $q): array {
    $out = [];
    $la = new DateTimeZone('America/Los_Angeles');
    for ($m = 0; $m < 12; $m++) {
        $a = (new DateTimeImmutable('2026-11-01', $la))->modify("+$m months");
        $out[] = ['read', $request('GET', $q($a->format(DATE_ATOM), $a->modify('+1 month')->format(DATE_ATOM)))];
    }
    return $out;
}];
$ops['read.year.large'] = ['kind' => 'read', 'steps' => static fn() => [['read', $request('GET', $q(...$windows['year']) . '&calendars=' . $large)]]];
$ops['read.2031.large'] = ['kind' => 'read', 'steps' => static fn() => [['read', $request('GET', $q('2031-01-01T00:00:00-08:00', '2032-01-01T00:00:00-08:00', (string) $large))]]];
foreach ($windows as $w => [$s, $e]) {
    $ops["dav.$w"] = ['kind' => 'read', 'steps' => static fn() => [
        ['read', $request('REPORT', $davUri($cal), null, true, $davQuery($s, $e))],
        ['read', $request('REPORT', $davUri($large), null, true, $davQuery($s, $e))],
    ]];
}
$ops['dav.year.large'] = ['kind' => 'read', 'steps' => static fn() => [['read', $request('REPORT', $davUri($large), null, true, $davQuery(...$windows['year']))]]];

/** The version's own occurrence of a series on a local day (start and end as it sends them). */
$occurrence = static function (int $seriesId, int $calendarId, string $day, string $tz) use ($request, $q): array {
    $z = new DateTimeZone($tz);
    $from = (new DateTimeImmutable("$day 00:00", $z))->modify('-1 day')->format(DATE_ATOM);
    $to = (new DateTimeImmutable("$day 00:00", $z))->modify('+2 days')->format(DATE_ATOM);
    $r = $request('GET', $q($from, $to, (string) $calendarId));
    foreach ($r['json']['events'] ?? [] as $o) {
        if ((int) $o['eventId'] === $seriesId && empty($o['isException'])
            && (new DateTimeImmutable((string) $o['start']))->setTimezone($z)->format('Y-m-d') === $day) {
            return $o;
        }
    }
    throw new RuntimeException("no occurrence of $seriesId on $day (status {$r['status']})");
};

/**
 * Send a change as the client does. On 1.5.1 a planned change is a preview
 * (preview: true), then the commit with the preview's hash and every group's
 * preselected option; a server that answers the commit 409 with a plan gets
 * it committed once more (counted as commit). Otherwise one request; one
 * that 1.5.1 answers 409 series_plan_required is committed with that plan.
 */
$send = static function (string $method, string $uri, array $body, bool $planned) use ($request, $plans): array {
    $steps = [];
    $choose = static function (array $plan): array {
        $c = [];
        foreach ($plan['groups'] ?? [] as $g) {
            $c[$g['id']] = $g['selected'];
        }
        return $c;
    };
    $commitBody = $body;
    if ($plans && $planned) {
        $p = $request($method, $uri, $body + ['preview' => true]);
        $steps[] = ['preview', $p];
        $plan = $p['json']['plan'] ?? null;
        if (!is_array($plan)) {
            throw new RuntimeException("preview $method $uri: {$p['status']} " . substr($p['body'], 0, 400));
        }
        if (empty($plan['whole'])) {
            $commitBody = $body + ['plan' => ['hash' => $plan['hash'], 'choices' => (object) $choose($plan)]];
        }
    }
    for ($try = 0; $try < 3; $try++) {
        $c = $request($method, $uri, $commitBody);
        $steps[] = ['commit', $c];
        $errPlan = $c['json']['error']['plan'] ?? null;
        if ($c['status'] === 409 && is_array($errPlan)) {
            $commitBody = $body + ['plan' => ['hash' => $errPlan['hash'], 'choices' => (object) $choose($errPlan)]];
            continue;
        }
        break;
    }
    $last = end($steps)[1];
    if ($last['status'] >= 300) {
        throw new RuntimeException("$method $uri: {$last['status']} " . substr($last['body'], 0, 400));
    }
    return $steps;
};

/** Undo as the toast does: 1.5.1 the entries the write named, newest first; before it, POST /undo. */
$undoSteps = static function (array $steps) use ($request, $plans): array {
    $out = [];
    if (!$plans) {
        $r = $request('POST', '/api/v1/undo', []);
        if ($r['status'] >= 300) {
            throw new RuntimeException("undo: {$r['status']} " . substr($r['body'], 0, 300));
        }
        return [['undo', $r]];
    }
    $entries = [];
    foreach ($steps as [$role, $r]) {
        if ($role === 'commit' && $r['status'] < 300 && isset($r['headers']['x-undo-entries'])) {
            $entries = array_merge($entries, array_map('intval', explode(',', $r['headers']['x-undo-entries'])));
        }
    }
    if ($entries === []) {
        throw new RuntimeException('the commit named no undo entries');
    }
    foreach (array_reverse($entries) as $id) {
        $r = $request('POST', "/api/v1/activity/$id/undo", []);
        if ($r['status'] >= 300) {
            throw new RuntimeException("undo $id: {$r['status']} " . substr($r['body'], 0, 300));
        }
        $out[] = ['undo', $r];
    }
    return $out;
};

// The days acted on: a Tuesday, inside every window read, kept free of drawn edits by seed.php.
$targets = [
    'large' => ['id' => (int) $ids['large'], 'cal' => $large, 'tz' => 'America/Los_Angeles', 'day' => '2026-12-01', 'rule' => 'FREQ=WEEKLY;BYDAY=TU,TH'],
    'ordinary' => ['id' => (int) $ids['ordinary'], 'cal' => $cal, 'tz' => 'America/Los_Angeles', 'day' => '2026-12-01', 'rule' => 'FREQ=WEEKLY;BYDAY=TU,WE,TH'],
];
$shift = static fn(string $iso, string $by): string => (new DateTimeImmutable($iso))->modify($by)->format(DATE_ATOM);
$occCache = [];
foreach ($targets as $name => $t) {
    $tgt = $t;
    $occ = static function () use (&$occCache, $name, $tgt, $occurrence): array {
        return $occCache[$name] ??= $occurrence($tgt['id'], $tgt['cal'], $tgt['day'], $tgt['tz']);
    };
    $edit = static function (string $method, string $path, callable $body, bool $planned) use ($send, $undoSteps): array {
        return ['kind' => 'edit', 'steps' => static function () use ($method, $path, $body, $planned, $send, $undoSteps): array {
            $steps = $send($method, $path, $body(), $planned);
            return array_merge($steps, $undoSteps($steps));
        }];
    };
    $u = "/api/v1/events/{$t['id']}";
    $ops["edit.$name.drag"] = $edit('PATCH', $u, static fn() => ['scope' => 'all', 'instanceStart' => $occ()['start'],
        'start' => $shift($occ()['start'], '+1 day +1 hour'), 'end' => $shift($occ()['end'], '+1 day +1 hour')], true);
    $ops["edit.$name.zone"] = $edit('PATCH', $u, static fn() => ['scope' => 'all', 'instanceStart' => $occ()['start'],
        'start' => $occ()['start'], 'end' => $occ()['end'], 'tzid' => 'America/New_York'], true);
    $ops["edit.$name.rule"] = $edit('PATCH', $u, static fn() => ['scope' => 'all', 'instanceStart' => $occ()['start'],
        'start' => $occ()['start'], 'end' => $occ()['end'], 'rrule' => $tgt['rule']], true);
    $ops["edit.$name.following"] = $edit('PATCH', $u, static fn() => ['scope' => 'following', 'instanceStart' => $occ()['start'],
        'start' => $shift($occ()['start'], '+1 hour'), 'end' => $shift($occ()['end'], '+1 hour'), 'title' => 'Sample later part'], true);
    $ops["edit.$name.delete-following"] = $edit('DELETE', $u, static fn() => ['scope' => 'following', 'instanceStart' => $occ()['start']], true);
    foreach (['this', 'following', 'all'] as $scope) {
        $ops["edit.$name.attend-$scope"] = $edit('POST', "$u/attendance", static fn() => ['attendance' => 'going', 'scope' => $scope, 'instanceStart' => $occ()['start']], $scope === 'following');
    }
}

// ---- Run ----------------------------------------------------------------------------

$results = ['label' => $label, 'version' => $version, 'size' => $size, 'reps' => $reps, 'warmup' => $warmup, 'count' => $count,
    'opcache' => !isset($opts['no-opcache']), 'idle' => isset($opts['idle']), 'made' => $fixture['made'], 'rows' => $fixture['rows'], 'at' => date(DATE_ATOM), 'ops' => []];
$restore();
// The first request of a fresh copy writes the schema marker; not measured.
$request('GET', '/api/v1/me');
foreach ($ops as $name => $op) {
    if ($only !== null && !in_array($name, $only, true)) {
        continue;
    }
    $runs = [];
    $restored = null;
    try {
        for ($i = 0; $i < $warmup + $reps; $i++) {
            if ($op['kind'] === 'edit') {
                $restore();
                $before = $i === $warmup ? $eventsHash() : null;
            }
            $steps = ($op['steps'])();
            if ($i < $warmup) {
                continue;
            }
            if ($op['kind'] === 'edit' && $i === $warmup) {
                $after = $eventsHash();
                $restored = $after === $before;
                if (!$restored) {
                    // What differs, a few rows each way, for the report.
                    $results['undoDiff'][$name] = ['before' => array_slice(array_values(array_diff($before, $after)), 0, 3),
                        'after' => array_slice(array_values(array_diff($after, $before)), 0, 3)];
                }
            }
            $runs[] = array_map(static function (array $s): array {
                [$role, $r] = $s;
                if ($r['status'] >= 400) {
                    $r['answer'] = substr($r['body'], 0, 400);
                }
                unset($r['body'], $r['json'], $r['headers']);
                return ['role' => $role] + $r;
            }, $steps);
        }
        $results['ops'][$name] = $runs;
        if ($restored !== null) {
            $results['undoRestored'][$name] = $restored;
        }
    } catch (Throwable $e) {
        $results['ops'][$name] = ['error' => $e->getMessage()];
        fprintf(STDERR, "[%s] %s: %s\n", $label, $name, $e->getMessage());
        continue;
    }
    $first = $runs[0];
    $line = [];
    foreach (['preview', 'commit', 'undo', 'read'] as $role) {
        $mine = array_values(array_filter($first, static fn($s) => $s['role'] === $role));
        if ($mine === []) {
            continue;
        }
        $peak = max(array_column($mine, 'peak'));
        $ms = array_sum(array_column($mine, 'ms'));
        $qs = $count ? ' q=' . array_sum(array_map(static fn($s) => $s['db']['Questions'] ?? 0, $mine)) : '';
        $line[] = sprintf('%s x%d %.0fms %.1fMB%s', $role, count($mine), $ms, $peak / 1048576, $qs);
    }
    fprintf(STDERR, "[%s] %-32s %s%s\n", $label, $name, implode(' | ', $line), $restored === false ? ' | UNDO LEFT EVENTS CHANGED' : '');
}
$restore();
file_put_contents($out, json_encode($results, JSON_PRETTY_PRINT));
fprintf(STDERR, "[%s] wrote %s\n", $label, $out);
if (!isset($opts['keep-db'])) {
    $server->exec("DROP DATABASE IF EXISTS `$dbName`");
    $server->exec("DROP DATABASE IF EXISTS `$baseName`");
    @unlink($fixtureFile);
}
