<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/release-archive.php';

/** Run Git without user filters, replacement objects, hooks or alternate transports. */
function dependencyGit(string $repo, array $args): string
{
    $env = array_filter(getenv(), static fn(string $key): bool => !str_starts_with($key, 'GIT_'), ARRAY_FILTER_USE_KEY);
    $env += ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_NO_REPLACE_OBJECTS' => '1', 'GIT_TERMINAL_PROMPT' => '0'];
    $cmd = ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.attributesFile=/dev/null', '-c', 'fetch.fsckObjects=true',
        '-c', 'protocol.allow=never', '-c', 'protocol.https.allow=always', '-c', 'credential.helper=', '--git-dir=' . $repo, ...$args];
    $err = tmpfile();
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $err], $pipes, null, $env);
    if (!is_resource($proc)) { fclose($err); throw new RuntimeException('Cannot run dependency Git'); }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($proc);
    fclose($err);
    if ($status !== 0) { throw new RuntimeException('Dependency Git failed (' . $args[0] . '); check network access and the locked source commit'); }
    return $out;
}

/** Exact exported library bytes, using attributes committed in the locked Git tree. */
function dependencyExport(string $repo, string $commit): array
{
    // A private directory, so no other local user can swap the archive mid-read.
    $dir = sys_get_temp_dir() . '/bc-dependency-' . bin2hex(random_bytes(8));
    if (!@mkdir($dir, 0700)) { throw new RuntimeException('Cannot create a private temp directory'); }
    $tar = $dir . '/export.tar';
    try {
        // git archive writes data only; no checkout filters, hooks or package code run.
        dependencyGit($repo, ['archive', '--format=tar', '--output=' . $tar, $commit]);
        $archive = new PharData($tar);
        $files = [];
        foreach (new RecursiveIteratorIterator($archive) as $entry) {
            $name = substr($entry->getPathname(), strlen('phar://' . $tar . '/'));
            if (in_array(explode('/', $name)[0], ['bin', 'examples', 'example', 'tests', 'test', 'Tests', 'docs'], true)) { continue; }
            if (!$entry->isFile() || $entry->isLink()) { throw new RuntimeException('Dependency export has an unsupported file type'); }
            $files[$name] = hash_file('sha256', $entry->getPathname());
        }
        unset($archive);
        ksort($files, SORT_STRING);
        return $files;
    } finally { @unlink($tar); @rmdir($dir); }
}

function verifyReleaseDependencies(string $lockPath, string $zipPath, string $cache): int
{
    $lock = json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);
    $released = releaseArchiveFiles($zipPath);
    $count = 0;
    foreach ($lock['packages'] as $package) {
        $name = $package['name'];
        $source = $package['source'] ?? [];
        $commit = $source['reference'] ?? '';
        $url = $source['url'] ?? '';
        if (preg_match('~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D', $name) !== 1
            || ($source['type'] ?? '') !== 'git' || preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1
            || preg_match('~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.git$~D', $url) !== 1
            || ($package['dist']['reference'] ?? '') !== $commit) {
            throw new RuntimeException('Dependency needs review: unsupported source or distribution mapping');
        }
        $repo = $cache . '/' . hash('sha256', $url);
        if (!is_dir($repo)) {
            if (!mkdir($repo, 0700, true)) { throw new RuntimeException('Cannot create dependency cache'); }
            dependencyGit($repo, ['init', '--bare']);
        }
        try { $found = trim(dependencyGit($repo, ['rev-parse', '--verify', $commit . '^{commit}'])); }
        catch (RuntimeException) { $found = ''; }
        if ($found !== $commit) {
            dependencyGit($repo, ['fetch', '--quiet', '--depth=1', '--no-tags', '--no-recurse-submodules', $url, $commit]);
        }
        if (trim(dependencyGit($repo, ['rev-parse', '--verify', $commit . '^{commit}'])) !== $commit) {
            throw new RuntimeException('Dependency commit differs from lockfile');
        }
        // Recheck cached objects too, not just objects newly received by fetch.
        dependencyGit($repo, ['fsck', '--full', '--no-reflogs']);
        $expected = dependencyExport($repo, $commit);
        $prefix = 'server/vendor/' . $name . '/';
        $actual = [];
        foreach ($released as $path => $hash) {
            if (str_starts_with($path, $prefix) && !str_ends_with($path, '/')) {
                $actual[substr($path, strlen($prefix))] = $hash;
            }
        }
        ksort($actual, SORT_STRING);
        if ($expected !== $actual) {
            throw new RuntimeException('Dependency ' . $name . ' does not reproduce from its locked Git commit; not signing (review export rules, do not skip differences)');
        }
        $count++;
    }
    return $count;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if ($argc !== 4) { throw new RuntimeException('usage: release-dependencies.php LOCK ZIP CACHE'); }
        $count = verifyReleaseDependencies($argv[1], $argv[2], $argv[3]);
        echo "Verified $count library exports against locked Git objects.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'release-dependencies: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
