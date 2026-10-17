<?php

declare(strict_types=1);

// Included by run.php after its synthetic export fixture. No real install data.
(static function (BetterCal\Infra\Db $db): void {
    $root = realpath(sys_get_temp_dir()) . '/bc-files-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    mkdir($root . '/app', 0700);
    $workspace = null;
    $refuses = static function (callable $fn): bool {
        try { $fn(); return false; } catch (RuntimeException) { return true; }
    };
    try {
        $proc = proc_open([PHP_BINARY, '-d', 'memory_limit=32M', __DIR__ . '/export-memory.php'], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        $out = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check('export memory: a complete large synthetic calendar fits a small worker', proc_close($proc) === 0 && str_contains($out, 'Complete synthetic export'), $errors);
        $mask = umask(0000);
        try {
            $workspace = new BetterCal\Infra\ExportWorkspace($root . '/app');
            $path = $workspace->file('ics');
        } finally { umask($mask); }
        check('export storage: private from creation despite a loose umask', (fileperms($workspace->dir) & 0777) === 0700 && (fileperms($path) & 0777) === 0600);
        check('export storage: unpredictable file names', preg_match('/^[a-f0-9]{32}\.ics$/D', basename($path)) === 1);
        $busy = false;
        try { new BetterCal\Infra\ExportWorkspace($root . '/app'); }
        catch (BetterCal\Http\HttpError $e) { $busy = $e->status === 409 && str_contains($e->getMessage(), 'An export is already running'); }
        check('export storage: competing request fails immediately with an explanation', $busy);
        $workspace->close();
        check('export storage: explicit cleanup deletes owned spools', !file_exists($path));
        $shutdown = 'require $argv[1]; $w = new BetterCal\\Infra\\ExportWorkspace($argv[2]); echo $w->file("ics"); exit;';
        $proc = proc_open([PHP_BINARY, '-r', $shutdown, dirname(__DIR__) . '/src/bootstrap.php', $root . '/app'], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        $shutdownPath = stream_get_contents($pipes[1]); $shutdownError = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check('export storage: shutdown cleans up even when normal control flow exits', proc_close($proc) === 0 && $shutdownPath !== '' && !file_exists($shutdownPath), $shutdownError);
        file_put_contents($path, 'abandoned synthetic export');
        $workspace = new BetterCal\Infra\ExportWorkspace($root . '/app');
        check('export storage: next lock holder removes abandoned files', !file_exists($path));
        // Many batches, and an override whose master is in a different batch.
        $db->run("INSERT INTO calendars (id, user_id, name) VALUES (10, 1, 'Sample archive')");
        $db->tx(static function () use ($db): void {
            for ($i = 0; $i < 1200; $i++) {
                $db->insert('events', ['id' => 10000 + $i, 'user_id' => 1, 'calendar_id' => 10, 'uid' => "sample-$i@example.test",
                    'title' => "Sample event $i", 'description' => str_repeat('Invented text. ', 400),
                    'start_utc' => '2026-01-01 09:00:00', 'end_utc' => '2026-01-01 10:00:00', 'tzid' => 'UTC']);
            }
        });
        $db->run("UPDATE events SET uid = 'series@example.test', all_day = 1, rrule = 'FREQ=DAILY' WHERE id = 10000");
        $db->run("UPDATE events SET uid = 'series@example.test', recurrence_instance_utc = '2026-01-03 00:00:00' WHERE id = 11199");
        for ($i = 1; $i < 230; $i++) {
            $db->insert('event_links', ['container_id' => 10000, 'event_id' => 10000 + $i]);
        }
        $export = new BetterCal\Domain\Export($db);
        $eventCount = 0; $relations = 0; $tail = ''; $override = false; $maxGrowth = 0;
        $before = memory_get_usage(true);
        $sink = static function (string $bytes) use (&$eventCount, &$relations, &$tail, &$override, &$maxGrowth, $before): void {
            $eventCount += substr_count($bytes, 'END:VEVENT');
            $relations += substr_count($bytes, 'RELATED-TO');
            $override = $override || str_contains($bytes, 'RECURRENCE-ID;VALUE=DATE:20260103');
            $tail = $bytes;
            $maxGrowth = max($maxGrowth, memory_get_usage(true) - $before);
        };
        $count = $export->snapshot(fn() => $export->writeCalendar(1, $export->calendar(1, 10), $sink));
        checkEq('export streaming: all synthetic events, no cap or truncation', [1200, 1200, 229, "END:VCALENDAR\r\n", true], [$count, $eventCount, $relations, $tail, $override]);
        check('export streaming: batches do not retain the full calendar', $maxGrowth < 6 * 1024 * 1024);
        checkEq('export streaming: no data spool for a single calendar', [], array_values(array_diff(scandir($workspace->dir), ['.', '..'])));
        $req = new BetterCal\Http\Request('GET', '/export');
        $req->authMethod = 'session'; $req->user = ['id' => 1];
        $controller = new BetterCal\Http\Controllers\ExportController($export, $root . '/app');
        $response = $controller->all($req);
        check('export HTTP: busy download has readable text, not a corrupt attachment', $response->status === 409 && !isset($response->headers['Content-Disposition']) && str_contains($response->body, 'already running'));
        $zipPath = $workspace->file('zip');
        $summary = $export->snapshot(fn() => $export->zip(1, $zipPath, $workspace));
        $zip = new ZipArchive(); $zip->open($zipPath);
        $ics = $zip->getFromName('Sample archive.ics');
        check('export ZIP: native addFile keeps all events through close', $summary['events'] >= 1200 && substr_count($ics, 'END:VEVENT') === 1200 && str_ends_with($ics, "END:VCALENDAR\r\n"));
        $zip->close(); unset($ics);
        $workspace->close();
        checkEq('export ZIP: cleanup removes archive and every calendar spool', [], array_values(array_diff(scandir($workspace->dir), ['.', '..'])));
        file_put_contents($root . '/sentinel', 'untouched');
        symlink($root . '/sentinel', $path);
        check('export cleanup: never follows a planted spool link', $refuses(fn() => new BetterCal\Infra\ExportWorkspace($root . '/app')) && file_get_contents($root . '/sentinel') === 'untouched');
        unlink($path);
        $updater = new BetterCal\Infra\Updater($root . '/app');
        symlink($root, $root . '/app-backups');
        check('backup path: linked directory refused before permissions change', $refuses(fn() => $updater->ensureBackupDir()));
        unlink($root . '/app-backups');
        mkdir($root . '/app-backups', 0755);
        symlink($root . '/sentinel', $root . '/app-backups/.htaccess');
        check('backup path: protective-file link refused before chmod or overwrite', $refuses(fn() => $updater->ensureBackupDir()) && (fileperms($root . '/app-backups') & 0777) === 0755 && file_get_contents($root . '/sentinel') === 'untouched');
        unlink($root . '/app-backups/.htaccess');
        $updater->ensureBackupDir();
        check('backup path: ordinary owned directory still works', (fileperms($root . '/app-backups') & 0777) === 0700);
        $probe = 'require $argv[1]; foreach (["Infra\\\\ExportWorkspace", "Infra\\\\PrivatePath", "Infra\\\\Updater", "Support\\\\SettingsFile", "Http\\\\HttpError"] as $c) { class_exists("BetterCal\\\\" . $c); } ini_set("open_basedir", $argv[2]); $w = new BetterCal\\Infra\\ExportWorkspace($argv[2]); $w->close(); $u = new BetterCal\\Infra\\Updater($argv[2]); $u->ensureBackupDir(); echo basename($u->backupDir());';
        $proc = proc_open([PHP_BINARY, '-d', 'disable_functions=posix_geteuid', '-r', $probe, dirname(__DIR__) . '/src/bootstrap.php', $root . '/app'], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        $out = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check('private storage: app-only open_basedir supports exports and in-app backups', proc_close($proc) === 0 && $out === '.backups', $errors);
        $plain = $root . '/app-backups/db-old.sql.gz';
        file_put_contents($plain, gzencode('invented backup'));
        symlink($root . '/sentinel', $root . '/app-backups/db-old.bcbackup');
        check('conversion: planted output link is not unlinked or followed', $refuses(fn() => BetterCal\Infra\DbBackup::encryptFile($plain, BetterCal\Infra\BackupCipher::keyFrom('synthetic-secret'))) && is_link($root . '/app-backups/db-old.bcbackup') && file_exists($plain));
        unlink($root . '/app-backups/db-old.bcbackup');
        $dump = new BetterCal\Infra\DbBackup($db);
        check('backup: missing secret refuses before creating any output', $refuses(fn() => $dump->dump($root . '/missing.bcbackup')) && !file_exists($root . '/missing.bcbackup'));
        $sys = new BetterCal\Http\Controllers\SystemController(new BetterCal\Domain\SystemHealth($db), new BetterCal\Domain\PushSubscriptions($db), new BetterCal\Infra\EmailSender([]), ['app_root' => $root . '/app', 'session_secret' => '']);
        $reply = $sys->privateFiles($req); $body = json_decode($reply->body, true);
        check('Settings: missing-secret warning and repair line do not depend on a cached probe', $body['result'] === null && str_contains($body['backupWarning']['message'], 'Backups and updates are blocked') && preg_match('/^BETTERCAL_SESSION_SECRET=.+$/D', $body['backupWarning']['settingsLine']) === 1 && $reply->headers['Cache-Control'] === 'no-store');
        file_put_contents($root . '/app/.env.php', "<?php __halt_compiler();\nBETTERCAL_DB_USER=sample\nBETTERCAL_DB_PASS=invented\n");
        $snapshot = bcReadCloneSettings($root . '/app');
        file_put_contents($root . '/app/.env.php', "BETTERCAL_DB_USER=changed\n");
        checkEq('dev settings: one read keeps related values together', ['sample', 'invented'], [$snapshot['BETTERCAL_DB_USER'], $snapshot['BETTERCAL_DB_PASS']]);
        unlink($root . '/app/.env.php');
        symlink($root . '/sentinel', $root . '/app/.env.php');
        check('dev settings: symlink is refused', $refuses(fn() => bcReadCloneSettings($root . '/app')));
        check('dev isolation: read-only mode bits cannot hide ownership/chmod authority', $refuses(fn() => bcCheckProductionNotWritable($root . '/app')));
    } finally {
        $workspace?->close();
        BetterCal\Infra\Updater::removeTree($root);
    }
})($xdb);
