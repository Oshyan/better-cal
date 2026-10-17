<?php
declare(strict_types=1);
require dirname(__DIR__) . '/release-dependencies.php';
$root = sys_get_temp_dir() . '/bc-release-security-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$count = 0;
$check = static function (bool $ok, string $name) use (&$count): void {
    if (!$ok) { throw new RuntimeException($name); }
    $count++;
};
$zip = static function (string $name, array $extra = [], string $prefix = '') use ($root): string {
    $path = "$root/$name.zip";
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (['VERSION' => "1.2.3\n", 'server/vendor/autoload.php' => '<?php', ...$extra] as $file => $bytes) {
        $z->addFromString($prefix . $file, $bytes);
    }
    $z->close();
    return $path;
};
$reject = static function (string $path) use ($check): void {
    $caught = false;
    try { releaseArchiveFiles($path); } catch (RuntimeException) { $caught = true; }
    $check($caught, 'unsafe archive rejected: ' . basename($path));
};
try {
    $flat = $zip('flat');
    $wrapped = $zip('wrapped', [], 'better-cal/');
    $check(releaseArchiveFiles($flat) === releaseArchiveFiles($wrapped), 'both legitimate layouts match');
    $check(releaseArchiveFiles($flat) !== releaseArchiveFiles($zip('changed', ['VERSION' => "9.9.9\n"])), 'changed bytes differ');
    $check(releaseArchiveFiles($flat) !== releaseArchiveFiles($zip('added', ['extra.php' => '<?php'])), 'added bytes differ');
    foreach (['../escape', '/absolute', 'a\\b', './dot', 'a//b', 'a//', "line\nescape", 'C:/drive'] as $i => $bad) {
        $reject($zip('path-' . $i, [$bad => 'bad']));
    }
    $reject($zip('collision', ['one' => 'file', 'one/two' => 'nested']));
    foreach ([0120777, 0010600] as $i => $type) {
        $path = $zip('special-' . $i, ['special' => 'target']);
        $z = new ZipArchive(); $z->open($path);
        $z->setExternalAttributesName('special', ZipArchive::OPSYS_UNIX, $type << 16);
        $z->close(); $reject($path);
    }
    $path = $zip('duplicate', ['dupeA' => 'first', 'dupeB' => 'second']);
    file_put_contents($path, str_replace('dupeB', 'dupeA', file_get_contents($path)));
    $reject($path);
    $path = $zip('expanded');
    // Synthetic central-directory declared size, no huge allocation/extraction.
    $raw = file_get_contents($path);
    $pos = strpos($raw, "PK\x01\x02");
    $raw = substr_replace($raw, pack('V', 401 * 1024 * 1024), $pos + 24, 4);
    file_put_contents($path, $raw); $reject($path);
    $path = "$root/compressed.zip";
    $f = fopen($path, 'w'); ftruncate($f, 104857601); fclose($f); $reject($path);

    // Offline fixture: the cache contains an invented repository, never a network fetch.
    $url = 'https://github.com/example/sample-library.git';
    $cache = "$root/cache";
    $repo = "$cache/" . hash('sha256', $url);
    mkdir($repo, 0700, true);
    mkdir("$root/tree/tests", 0700, true);
    $files = ['library.php' => '<?php // invented library', '.gitattributes' => "ignored.txt export-ignore\n"];
    foreach ($files + ['ignored.txt' => 'excluded by Git', 'tests/check.php' => 'excluded by assembler'] as $name => $bytes) {
        file_put_contents("$root/tree/$name", $bytes);
    }
    dependencyGit($repo, ['init', '--bare']);
    dependencyGit($repo, ['--work-tree=' . "$root/tree", 'add', '.']);
    dependencyGit($repo, ['--work-tree=' . "$root/tree", '-c', 'user.name=Sam Sample', '-c', 'user.email=sam@example.test', 'commit', '-qm', 'Invented library fixture']);
    $commit = trim(dependencyGit($repo, ['rev-parse', 'HEAD']));
    $package = ['name' => 'example/sample-library', 'source' => ['type' => 'git', 'url' => $url, 'reference' => $commit], 'dist' => ['reference' => $commit]];
    $lock = "$root/composer.lock";
    file_put_contents($lock, json_encode(['packages' => [$package]]));
    $packageZip = static function (array $content) use ($zip): string {
        $entries = [];
        foreach ($content as $name => $bytes) { $entries['server/vendor/example/sample-library/' . $name] = $bytes; }
        return $zip('library', $entries);
    };
    $check(verifyReleaseDependencies($lock, $packageZip($files), $cache) === 1, 'locked Git export matches, with both exclusion rules');
    foreach ([$files + ['injected.php' => '<?php'], array_diff_key($files, ['library.php' => true]), array_replace($files, ['library.php' => 'changed'])] as $changed) {
        $caught = false;
        try { verifyReleaseDependencies($lock, $packageZip($changed), $cache); } catch (RuntimeException) { $caught = true; }
        $check($caught, 'added, missing or changed library bytes refused');
    }
    $package['dist']['reference'] = str_repeat('0', 40);
    file_put_contents($lock, json_encode(['packages' => [$package]]));
    $caught = false;
    try { verifyReleaseDependencies($lock, $packageZip($files), $cache); } catch (RuntimeException) { $caught = true; }
    $check($caught, 'different source/distribution mapping refused');
    putenv('GIT_CONFIG_COUNT=1'); putenv('GIT_CONFIG_KEY_0=alias.environmentprobe'); putenv('GIT_CONFIG_VALUE_0=!false');
    $check(!str_contains(dependencyGit($repo, ['config', '--list']), 'environmentprobe'), 'inherited Git configuration is ignored');
    putenv('GIT_CONFIG_COUNT'); putenv('GIT_CONFIG_KEY_0'); putenv('GIT_CONFIG_VALUE_0');
    echo "Release archive and dependency security: $count checks passed.\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
