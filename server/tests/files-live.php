<?php

declare(strict_types=1);

// Disposable loopback fixture only. Never load the real install's configuration.
// BC_FILES_TEST_DSN must name the dedicated Docker test database, not an install.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$dsn = (string) getenv('BC_FILES_TEST_DSN');
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=bc_files_security$/D', $dsn)) {
    throw new RuntimeException('Use a loopback-only disposable bc_files_security database.');
}
define('BETTERCAL_WEB_ENTRY', true);
require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Http\AccessCode;
use BetterCal\Http\InstallPages;
use BetterCal\Http\Request;
use BetterCal\Http\Controllers\ExportController;
use BetterCal\Infra\Db;
use BetterCal\Infra\DbBackup;
use BetterCal\Infra\ExportWorkspace;
use BetterCal\Infra\Updater;
use BetterCal\Infra\BackupCipher;
use BetterCal\Support\Maintenance;

$dbCfg = ['dsn' => $dsn, 'user' => 'sample', 'pass' => 'synthetic-test-password'];
$db = new Db($dbCfg);
$root = (string) getenv('BC_FILES_TEST_ROOT');
if (PHP_SAPI === 'cli-server') {
    if ($root === '' || !str_starts_with(basename($root), 'bc-files-live-')) { http_response_code(404); exit; }
    $cfg = ['app_root' => $root . '/app', 'db' => $dbCfg, 'version' => '0.9.86',
        'session_secret' => is_file($root . '/no-secret') ? '' : 'synthetic-test-secret'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/update-database') { ini_set('memory_limit', '128M'); InstallPages::databaseUpdate($cfg, $path); exit; }
    if ($path === '/harness') { require dirname(__DIR__, 2) . '/tools/tz-harness/gen.php'; exit; }
    $request = new Request('GET', $path); $request->authMethod = 'session'; $request->user = ['id' => 1];
    $exports = new ExportController(new BetterCal\Domain\Export($db), $cfg['app_root']);
    if ($path === '/calendar') { $exports->calendar($request, ['id' => 1])->withHeader('X-Fixture', 'stream')->send(); exit; }
    if ($path === '/zip') { $exports->all($request)->send(); exit; }
    http_response_code(404); exit;
}

$root = realpath(sys_get_temp_dir()) . '/bc-files-live-' . bin2hex(random_bytes(6));
mkdir($root, 0700); mkdir($root . '/app', 0700); mkdir($root . '/app/server', 0700); mkdir($root . '/app/server/migrations', 0700);
$server = null; $pipes = []; $held = null;
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS: $message\n";
};
try {
    // The fixture database is created by the Docker runner and must be empty.
    $assert($db->all('SHOW TABLES') === [], 'dedicated fixture database starts empty');
    $db->run('CREATE TABLE calendars (id INT PRIMARY KEY, user_id INT, name VARCHAR(100), position INT DEFAULT 0)');
    $db->run('CREATE TABLE events (id INT PRIMARY KEY, user_id INT, calendar_id INT, uid VARCHAR(255), title TEXT, description MEDIUMTEXT, location TEXT, url TEXT,
        start_utc DATETIME, end_utc DATETIME, all_day INT DEFAULT 0, tzid VARCHAR(100), rrule TEXT, exdates_json TEXT, reminders_json TEXT, status TEXT,
        recurrence_instance_utc DATETIME, deleted_at DATETIME, is_container INT DEFAULT 0)');
    $db->run('CREATE TABLE event_links (id INT PRIMARY KEY, container_id INT, event_id INT, position INT DEFAULT 0)');
    $db->run('CREATE TABLE marker (value VARCHAR(30))'); $db->run("INSERT INTO marker VALUES ('original')");
    $db->run('CREATE TABLE app_update_state (id INT PRIMARY KEY, minimum_secure_version VARCHAR(30))');
    $db->run("INSERT INTO calendars VALUES (1,1,'Sample archive',0)");
    $db->tx(static function () use ($db): void {
        for ($i = 1; $i <= 1500; $i++) {
            $db->run('INSERT INTO events (id,user_id,calendar_id,uid,title,description,start_utc,end_utc,tzid) VALUES (?,1,1,?,?,?,?,?,?)',
                [$i, "event-$i@example.test", "Sample event $i", str_repeat('Invented description. ', 400), '2026-01-01 09:00:00', '2026-01-01 10:00:00', 'UTC']);
        }
    });
    (new BetterCal\Infra\Migrator($db, $root . '/app/server/migrations'))->pending();
    $updater = new Updater($root . '/app'); $updater->ensureBackupDir();
    $backup = $updater->backupDir() . '/db-before-0.9.86-1.bcbackup';
    DbBackup::forConfig($db, ['session_secret' => 'synthetic-test-secret'])->dump($backup);
    $assert(BackupCipher::isEncrypted($backup) && (fileperms($backup) & 0777) === 0600, 'new MySQL backup is encrypted and owner-only');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $env = getenv(); $env['BC_FILES_TEST_ROOT'] = $root;
    $server = proc_open([PHP_BINARY, '-d', 'memory_limit=32M', '-S', $address, __FILE__], [0 => ['pipe','r'], 1 => ['file',$root . '/http.log','a'], 2 => ['pipe','w']], $pipes, null, $env);
    // PHP's listening announcement is the readiness event, not a timed sleep.
    $ready = fgets($pipes[2]);
    $assert(is_string($ready) && str_contains($ready, 'started'), 'loopback PHP server is listening');
    $request = static function (string $path, ?array $post = null, ?string $token = null) use ($address): array {
        $ch = curl_init('http://' . $address . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        if ($post !== null) { curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]); }
        if ($token !== null) { curl_setopt($ch, CURLOPT_COOKIE, AccessCode::COOKIE . '=' . $token); }
        $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false) { throw new RuntimeException(curl_error($ch)); }
        unset($ch); clearstatcache(); return [$status, $body];
    };
    [$status, $body] = $request('/calendar');
    $assert($status === 200 && substr_count($body, 'END:VEVENT') === 1500 && str_ends_with($body, "END:VCALENDAR\r\n"), 'direct HTTP export is complete under a small PHP memory limit');
    unset($body);
    [$status, $body] = $request('/zip'); file_put_contents($root . '/download.zip', $body); unset($body);
    $zip = new ZipArchive(); $zip->open($root . '/download.zip');
    $assert($status === 200 && substr_count((string) $zip->getFromName('Sample archive.ics'), 'END:VEVENT') === 1500, 'HTTP native ZIP contains the complete calendar'); $zip->close();
    $exportDir = $root . '/app/.runtime-cache/exports';
    $assert(count(scandir($exportDir)) === 2, 'successful HTTP downloads leave no export data files');
    $held = new ExportWorkspace($root . '/app');
    [$status, $body] = $request('/zip');
    $assert($status === 409 && str_contains($body, 'An export is already running'), 'HTTP concurrent export returns a clear busy message');
    $held->close();
    $db->run('RENAME TABLE event_links TO fixture_links');
    [$status, $body] = $request('/zip');
    $assert($status === 500 && str_contains($body, 'The export could not be completed') && count(scandir($exportDir)) === 2, 'failed ZIP build gives a readable error and cleans every spool');
    [$status, $body] = $request('/calendar');
    $assert(!str_contains($body, 'END:VCALENDAR') && !str_contains($body, 'internal_error'), 'failed direct stream has no false completion marker or appended API JSON');
    $db->run('RENAME TABLE fixture_links TO event_links');
    [$status] = $request('/harness'); $assert($status === 404, 'timezone harness refuses HTTP execution');
    // Run the actual worker entry point against fixture configuration, never
    // the real checkout. Holding its lock is the deterministic barrier.
    foreach (['src', 'config'] as $dir) {
        $source = dirname(__DIR__) . '/' . $dir;
        mkdir($root . '/app/server/' . $dir, 0700);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $to = $root . '/app/server/' . $dir . '/' . substr($entry->getPathname(), strlen($source) + 1);
            if ($entry->isDir()) { mkdir($to, 0700); } else { copy($entry->getPathname(), $to); }
        }
    }
    mkdir($root . '/app/server/bin', 0700);
    copy(dirname(__DIR__) . '/bin/worker.php', $root . '/app/server/bin/worker.php');
    $workerEnv = getenv();
    $workerEnv['BETTERCAL_DB_DSN'] = $dsn; $workerEnv['BETTERCAL_DB_USER'] = 'sample'; $workerEnv['BETTERCAL_DB_PASS'] = 'synthetic-test-password';
    $workerEnv['BETTERCAL_SESSION_SECRET'] = 'synthetic-test-secret';
    $workerEnv['BETTERCAL_BASE_URL'] = 'https://calendar.example.test';
    $workerBarrier = new Db($dbCfg); $workerBarrier->scalar('SELECT GET_LOCK(?, 0)', [Updater::workerLockName($dsn)]);
    $db->run('DROP TABLE schema_migrations');
    $process = proc_open([PHP_BINARY, $root . '/app/server/bin/worker.php'], [1 => ['pipe','w'], 2 => ['pipe','w']], $workerPipes, null, $workerEnv);
    stream_get_contents($workerPipes[1]); stream_get_contents($workerPipes[2]); fclose($workerPipes[1]); fclose($workerPipes[2]);
    $assert(proc_close($process) === 0 && $db->scalar("SHOW TABLES LIKE 'schema_migrations'") === null, 'worker does no startup DDL while restore holds its lock');
    copy(dirname(__DIR__) . '/bin/update.php', $root . '/app/server/bin/update.php');
    mkdir($root . '/previous', 0700);
    $updater->writeState(['stage' => 'done', 'mode' => 'zip', 'from' => '0.9.85', 'to' => '0.9.86',
        'startedAt' => '2026-01-01T00:00:00Z', 'previous' => $root . '/previous', 'backup' => $backup]);
    $process = proc_open([PHP_BINARY, $root . '/app/server/bin/update.php', '--rollback', '--yes'], [1 => ['pipe','w'], 2 => ['pipe','w']], $rollbackPipes, null, $workerEnv);
    $rollbackOutput = stream_get_contents($rollbackPipes[1]); stream_get_contents($rollbackPipes[2]); fclose($rollbackPipes[1]); fclose($rollbackPipes[2]);
    $assert(proc_close($process) === 1 && str_contains($rollbackOutput, 'worker is finishing a job') && $db->scalar('SELECT value FROM marker') === 'original', 'actual CLI rollback also refuses database changes while a worker holds its lock');
    Maintenance::off($root . '/app'); // The fixture's blocked restore changed no data.
    $workerBarrier->scalar('SELECT RELEASE_LOCK(?)', [Updater::workerLockName($dsn)]);
    (new BetterCal\Infra\Migrator($db, $root . '/app/server/migrations'))->pending();
    $access = new AccessCode($root . '/app', 'update'); $access->ensure();
    $lines = explode("\n", trim(file_get_contents($access->codePath()))); $code = json_decode(end($lines), true)['code'];
    $token = $access->verify($code); $form = AccessCode::formToken($token);
    touch($root . '/no-secret');
    [$status, $body] = $request('/update-database', null, $token);
    $assert($status === 503 && str_contains($body, 'BETTERCAL_SESSION_SECRET=') && str_contains($body, 'Backups and updates are blocked'), 'database update warns and gives a repair line before backup');
    unlink($root . '/no-secret');
    $worker = new Db($dbCfg); $lock = Updater::workerLockName($dsn); $worker->scalar('SELECT GET_LOCK(?, 0)', [$lock]);
    $restore = ['step' => 'restore', 'backup' => basename($backup), 'form' => $form];
    [$status, $body] = $request('/update-database', $restore, $token);
    $assert($status === 409 && str_contains($body, 'Run the restore again') && Maintenance::active($root . '/app') && $db->scalar('SELECT value FROM marker') === 'original', 'active worker prevents restore and offers retry without database changes');
    $worker->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
    $bad = $updater->backupDir() . '/db-before-0.9.86-2.bcbackup';
    $cipher = new BackupCipher($bad, BackupCipher::keyFrom('synthetic-test-secret'));
    $cipher->write(gzencode("-- Better-Cal database backup\n-- tables: marker\nUPDATE marker SET value='partial';\nNOT VALID SQL;\n-- end\n")); $cipher->close();
    [$status, $body] = $request('/update-database', ['step' => 'restore', 'backup' => basename($bad), 'form' => $form], $token);
    $assert($status === 500 && str_contains($body, 'Run the restore again') && Maintenance::active($root . '/app') && $db->scalar('SELECT value FROM marker') === 'partial', 'partial restore failure stays closed with a working retry form');
    [$status, $body] = $request('/update-database', null, $token);
    $assert($status === 503 && str_contains($body, 'Database restore stopped') && str_contains($body, 'name="backup"'), 'reloading after a stopped restore still offers recovery');
    [$status, $body] = $request('/update-database', ['step' => 'update', 'form' => $form], $token);
    $assert($status === 503 && Maintenance::active($root . '/app') && $db->scalar('SELECT value FROM marker') === 'partial', 'a stale update form cannot reopen a partially restored site');
    [$status, $body] = $request('/update-database', $restore, $token);
    if ($status !== 200 || !str_contains($body, 'Database restored')) {
        preg_match('/<div class="err">(.*?)<\/div>/s', $body, $problem);
        throw new RuntimeException('Restore retry HTTP ' . $status . ': ' . strip_tags($problem[1] ?? $body));
    }
    $assert($status === 200 && str_contains($body, 'Database restored') && !Maintenance::active($root . '/app') && $db->scalar('SELECT value FROM marker') === 'original', 'retry restores the encrypted MySQL backup and reopens only on success');
    // Legacy plaintext remains usable, but new dumps never fall back to it.
    $legacy = $updater->backupDir() . '/db-before-0.9.85-1.sql.gz';
    file_put_contents($legacy, gzencode("-- Better-Cal database backup\n-- tables: marker\nUPDATE marker SET value='legacy';\n-- end\n"));
    [$status, $body] = $request('/update-database', ['step' => 'restore', 'backup' => basename($legacy), 'form' => $form], $token);
    $assert($status === 200 && $db->scalar('SELECT value FROM marker') === 'legacy', 'older plaintext backups remain recoverable');
} finally {
    $held?->close();
    if (is_resource($server)) { proc_terminate($server); foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } } proc_close($server); }
    Updater::removeTree($root);
}
