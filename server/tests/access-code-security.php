<?php
declare(strict_types=1);

// Separate processes exercise the real lock; stdin/stdout barriers, never sleeps.
namespace BetterCal\Http {
    function flock($stream, int $operation): bool
    {
        if ($operation === LOCK_EX && ($GLOBALS['accessWorker'] ?? false)) {
            echo "locking\n";
            flush();
        }
        return \flock($stream, $operation);
    }
}
namespace {
    $source = getenv('BETTERCAL_TEST_SOURCE') ?: dirname(__DIR__);
    require_once $source . '/src/Support/SettingsFile.php';
    require_once $source . '/src/Http/AccessCode.php';
    use BetterCal\Http\AccessCode;
    use BetterCal\Support\SettingsFile;

    if (($argv[1] ?? '') === 'worker') {
        $GLOBALS['accessWorker'] = true;
        $gate = new AccessCode($argv[2], 'setup');
        echo "ready\n";
        flush();
        fgets(STDIN);
        $out = $argv[3] === 'ensure' ? $gate->ensure() : $gate->verify($argv[3]);
        echo json_encode($out), "\n";
        exit;
    }
    $count = 0;
    $check = static function (bool $ok, string $name) use (&$count): void {
        if (!$ok) { throw new RuntimeException($name); }
        $count++;
    };
    $root = sys_get_temp_dir() . '/bc-access-security-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    $readCode = static function () use ($root): string {
        preg_match('/code: ([A-Z0-9-]+)/', (string) file_get_contents($root . '/' . AccessCode::FILE), $m);
        return $m[1] ?? '';
    };
    $remove = static function (string $dir) use (&$remove): void {
        foreach (scandir($dir) as $name) {
            if ($name === '.' || $name === '..') { continue; }
            $p = "$dir/$name";
            is_dir($p) && !is_link($p) ? $remove($p) : unlink($p);
        }
        rmdir($dir);
    };
    try {
        $needed = true;
        $gate = new AccessCode($root, 'setup', static function () use (&$needed): bool { return $needed; });
        $gate->ensure();
        $code = $readCode();
        $before = file_get_contents($gate->codePath());
        $start = hrtime(true);
        for ($i = 0; $i < 3; $i++) { $check($gate->verify('wrong') === null, 'wrong code refused'); }
        $check(hrtime(true) - $start < 2_000_000_000, 'wrong codes do not deliberately park workers');
        $check(file_get_contents($gate->codePath()) === $before, 'wrong codes leave state unchanged');
        $session = $gate->verify($code);
        $check(is_string($session) && $gate->hasSession($session), 'correct code works after wrong codes');
        $check($gate->verify($code) === null && $gate->hasSession($session), 'replay cannot replace session');
        $check($gate->wasUsed(), 'used-code result distinct from mismatch');
        $check(AccessCode::USED_MESSAGE === 'This code was already used. If that was you and the page lost your session, delete better-cal-code.php in your file manager and reload this page for a new code.', 'exact recovery wording');
        $gate->ensure();
        $check($readCode() === '', 'consumed file no longer advertises a code');
        unlink($gate->codePath());
        $check(!$gate->hasSession($session), 'file-manager reset invalidates session');
        $gate->ensure();
        $new = $gate->verify($readCode());
        $gate->finish($session);
        $check($gate->hasSession($new), 'stale finish preserves replacement session');
        $needed = false;
        $gate->finish($new);
        $check($gate->ensure() === false && !is_file($gate->codePath()), 'late ensure cannot revive completed operation');
        $needed = true;
        file_put_contents($gate->codePath(), SettingsFile::GUARD_PREFIX . "\nBetter-Cal setup code: AAAA-BBBB-CCCC\n");
        file_put_contents("$root/.runtime-cache/access-code.php", SettingsFile::GUARD_PREFIX . "\n{}\n");
        $gate->ensure();
        $check($readCode() !== 'AAAA-BBBB-CCCC' && !is_file("$root/.runtime-cache/access-code.php"), 'legacy state replaced, not imported');
        $gate->finish();

        // Hold the same lock in the parent until every child reaches lock acquisition.
        foreach (['ensure', 'redeem'] as $mode) {
            $lock = fopen("$root/.runtime-cache/access-code-lock.php", 'c');
            flock($lock, LOCK_EX);
            $children = [];
            $typed = $mode === 'ensure' ? 'ensure' : $readCode();
            for ($i = 0; $i < 3; $i++) {
                $proc = proc_open([PHP_BINARY, __FILE__, 'worker', $root, $typed], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                stream_set_timeout($pipes[1], 10);
                $check(trim(fgets($pipes[1])) === 'ready', 'child ready');
                fwrite($pipes[0], "go\n");
                $check(trim(fgets($pipes[1])) === 'locking', 'child reached actual lock');
                $r = [$pipes[1]]; $w = $e = [];
                $check(stream_select($r, $w, $e, 0) === 0, 'contention never falls back');
                $children[] = [$proc, $pipes];
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            $tokens = [];
            foreach ($children as [$proc, $pipes]) {
                $result = json_decode(trim(fgets($pipes[1])), true);
                if (is_string($result)) { $tokens[] = $result; }
                $err = stream_get_contents($pipes[2]);
                foreach ($pipes as $pipe) { fclose($pipe); }
                $check(proc_close($proc) === 0 && $err === '', 'child completed without fallback');
            }
            $check($mode === 'ensure' ? strlen($readCode()) === 14 : count($tokens) === 1 && $gate->hasSession($tokens[0]), 'concurrent issuance/consumption has one authority');
        }
        $gate->finish();
        // Some hosts disable flock entirely, rather than returning false from it.
        $proc = proc_open([PHP_BINARY, '-d', 'disable_functions=flock', __FILE__, 'worker', $root, 'ensure'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $check(trim(fgets($pipes[1])) === 'ready', 'disabled-lock child ready');
        fwrite($pipes[0], "go\n");
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) { fclose($pipe); }
        $check(proc_close($proc) === 0 && trim($out) === 'true' && str_contains($err, 'locking unavailable'), 'disabled flock continues, log only');
        // Cannot open a lock, but the state file remains writable: log only, continue.
        unlink("$root/.runtime-cache/access-code-lock.php");
        mkdir("$root/.runtime-cache/access-code-lock.php");
        ini_set('error_log', "$root/diagnostic.log");
        ob_start();
        $gate->ensure();
        $fallback = $gate->verify($readCode());
        $page = ob_get_clean();
        $check($page === '' && is_string($fallback) && $gate->hasSession($fallback), 'unsupported locking continues without page warning');
        $check(str_contains(file_get_contents("$root/diagnostic.log"), 'locking unavailable'), 'fallback logged');
        $gate->finish();
        mkdir($gate->codePath());
        $failed = false;
        try { $gate->ensure(); } catch (RuntimeException) { $failed = true; }
        $check($failed, 'write failure never grants authority');
        echo "Access-code security: $count checks passed.\n";
    } finally {
        $remove($root);
    }
}
