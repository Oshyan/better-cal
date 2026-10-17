<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Support\SettingsFile;

/**
 * The file work of bin/update.php: where backups and the previous version
 * go, checking and unpacking a release zip, carrying your own files into
 * the new copy, and the record of an update in progress that --finish and
 * --rollback read. bin/update.php does the talking.
 *
 * A zip install updates by swapping directories beside the app: the new
 * version is unpacked next to it, then the current one is renamed to
 * <name>-<old version> and the new one takes its place, so the web server
 * and cron keep pointing at the same path and the previous version stays
 * whole for a rollback. A git checkout updates in place (checkout the tag).
 */
final class Updater
{
    public const ZIP_ROOT = 'better-cal/';
    /** In each release zip: every file it ships (but the libraries), one path per line. */
    public const RELEASE_FILES = '.release-files';
    private const MAX_ENTRIES = 20000;
    private const MAX_UNPACKED_BYTES = 400 * 1024 * 1024;
    /** Never carried into the new copy, never reported as left behind. */
    private const NOT_CARRIED = ['server/vendor', '.runtime-cache', '.maintenance', '.code-changed', '.git', 'worker.log', '.backups', '.release-files',
        'better-cal-code.php', 'better-cal-code.txt'];
    /** Carried across when present: settings and per-host PHP or web-server files. */
    private const CARRIED = ['.env.php', '.env', '.user.ini', 'server/public/.user.ini', 'server/public/.well-known'];

    public function __construct(public readonly string $appRoot)
    {
    }

    public function isGit(): bool
    {
        return is_dir($this->appRoot . '/.git');
    }

    public function parent(): string
    {
        return dirname($this->appRoot);
    }

    public function name(): string
    {
        return basename($this->appRoot);
    }

    /**
     * Backups and the update record: beside the app, or inside it when the
     * folder above isn't writable (a git checkout, or a host that only lets
     * PHP write the app's own folder). Either way outside the web root.
     */
    public function backupDir(): string
    {
        $beside = $this->parent() . '/' . $this->name() . '-backups';
        if (is_dir($beside) || is_writable($this->parent())) {
            return $beside;
        }
        return $this->appRoot . '/.backups';
    }

    /**
     * The update record: JSON behind the settings file's guard line, so it
     * shows as a blank page if the folder is ever served. Versions before
     * 0.9.83 wrote update-state.json, which is still read (an update from one
     * of them is finished by this code) and removed on the next write.
     */
    public function statePath(): string
    {
        return $this->backupDir() . '/update-state.php';
    }

    private function oldStatePath(): string
    {
        return $this->backupDir() . '/update-state.json';
    }

    /**
     * When both exist, the newer one: after a rollback to a version before
     * 0.9.83, that version's updater writes update-state.json beside the
     * update-state.php this one left, and the new code finishing its update
     * must read the json.
     *
     * @return ?array<string,mixed>
     */
    public function readState(): ?array
    {
        clearstatcache();
        $new = @filemtime($this->statePath());
        $old = @filemtime($this->oldStatePath());
        if ($new !== false && ($old === false || $new >= $old)) {
            $raw = @file_get_contents($this->statePath());
            $nl = $raw === false ? false : strpos($raw, "\n");
            $raw = $nl === false ? false : substr((string) $raw, $nl + 1);
        } else {
            $raw = @file_get_contents($this->oldStatePath());
        }
        $state = $raw === false ? null : json_decode($raw, true);
        return is_array($state) ? $state : null;
    }

    /** @param array<string,mixed> $state */
    public function writeState(array $state): void
    {
        $this->ensureBackupDir();
        $text = SettingsFile::GUARD_PREFIX . " // Better-Cal update record\n" . json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (!SettingsFile::writeAtomic($this->statePath(), $text, 0600)) {
            throw new \RuntimeException('Could not write ' . $this->statePath());
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($this->statePath(), (int) fileowner($this->backupDir()));
            @chgrp($this->statePath(), (int) filegroup($this->backupDir()));
        }
        @unlink($this->oldStatePath());
    }

    /**
     * Other Better-Cal copies beside this one: previous versions kept by an
     * update (<name>-<version>), set aside by a rollback, or left by hand.
     * Anything beside the app with a VERSION file and the app's name in front.
     *
     * @return list<string>
     */
    public function siblingCopies(): array
    {
        $out = [];
        foreach (glob($this->parent() . '/' . $this->name() . '-*/VERSION') ?: [] as $version) {
            $dir = dirname($version);
            if ($dir !== $this->appRoot && is_dir($dir . '/server') && !is_link($dir)) {
                $out[] = $dir;
            }
        }
        return $out;
    }

    /**
     * The copies whose settings may be converted: ones this updater made
     * (<name>-<version>, or set aside by a rollback), when their code reads
     * .env.php or they are the update's previous version, which a rollback
     * gives its .env back. A copy someone made by hand (better-cal-old, a
     * staging copy) runs as it is and is never touched.
     *
     * @return list<string>
     */
    public function convertibleCopies(): array
    {
        $pattern = '/^' . preg_quote($this->name(), '/') . '-\d+\.\d+\.\d+(-rolled-back)?(-\d+)?$/';
        $previous = $this->readState()['previous'] ?? null;
        $out = [];
        foreach ($this->siblingCopies() as $dir) {
            if (preg_match($pattern, basename($dir)) !== 1) {
                continue;
            }
            if ($dir === $previous || str_contains((string) @file_get_contents($dir . '/server/config/config.php'), '.env.php')) {
                $out[] = $dir;
            }
        }
        return $out;
    }

    public function ensureBackupDir(): void
    {
        $dir = $this->backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new \RuntimeException("Could not create $dir");
        }
        @chmod($dir, 0700);
        self::denyWeb($dir);
    }

    /**
     * Keep a folder of backups or an old copy (with its .env) from being
     * served, should it sit inside a web root after all: a deny-all
     * .htaccess for Apache, which most shared hosts run, and an empty index.
     * Nginx ignores both; there, the app belongs outside the web root.
     */
    public static function denyWeb(string $dir): void
    {
        if (!is_file("$dir/.htaccess")) {
            @file_put_contents("$dir/.htaccess", "# Written by bin/update.php: never serve this folder.\nRequire all denied\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
        if (!file_exists("$dir/index.html")) {
            @file_put_contents("$dir/index.html", '');
        }
    }

    /**
     * Whether the app directory looks like it sits inside a web root, where
     * its backups and previous copies could be downloadable.
     */
    public function looksServed(): bool
    {
        foreach (explode('/', $this->appRoot) as $segment) {
            if (in_array(strtolower($segment), ['public_html', 'htdocs', 'httpdocs', 'www', 'wwwroot', 'html', 'web'], true)) {
                return true;
            }
        }
        return false;
    }

    /** The worker's MySQL lock (bin/worker.php), so an update can wait for a running job. */
    public static function workerLockName(string $dsn): string
    {
        preg_match('/dbname=([^;]+)/', $dsn, $m);
        return 'bettercal_worker:' . ($m[1] ?? 'default');
    }

    /**
     * The lock every update holds (bin/update.php, the database update page),
     * so two can't run at once: two terminals, or a double-click on a slow
     * button. A MySQL lock, since the update moves the app's own folder.
     */
    public static function updateLockName(string $dsn): string
    {
        preg_match('/dbname=([^;]+)/', $dsn, $m);
        return 'bettercal_update:' . ($m[1] ?? 'default');
    }

    /**
     * The release signing keys a copy of the app trusts, read from its
     * config/release-keys.php, as id => public key.
     *
     * @return array<string,string>
     */
    public static function keysAt(string $root): array
    {
        $file = $root . '/server/config/release-keys.php';
        $keys = is_file($file) ? (static fn(string $f): mixed => require $f)($file) : [];
        $out = [];
        foreach (is_array($keys) ? $keys : [] as $k) {
            if (is_array($k) && isset($k['id'], $k['publicKey'])) {
                $out[(string) $k['id']] = (string) $k['publicKey'];
            }
        }
        return $out;
    }

    /**
     * What a release changes about who may sign the next one, in words:
     * empty when nothing. Shown on every update, so a key swap is never quiet.
     *
     * @param array<string,string> $before
     * @param array<string,string> $after
     * @return list<string>
     */
    public static function keyChanges(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $id => $key) {
            if (!isset($before[$id])) {
                $out[] = "adds the signing key $id";
            } elseif ($before[$id] !== $key) {
                $out[] = "replaces the signing key $id with a different one";
            }
        }
        foreach (array_keys(array_diff_key($before, $after)) as $id) {
            $out[] = "removes the signing key $id";
        }
        return $out;
    }

    /**
     * Where an update that stopped in the middle of its swap really is. The
     * previous copy present and the unpacked one gone: both renames happened,
     * so it's "swapped". Otherwise the old code is still in place (this runs
     * from it): "backed-up". Other stages are what they say.
     *
     * @param array<string,mixed> $state
     */
    public static function settledStage(array $state): string
    {
        if (($state['stage'] ?? '') !== 'swapping') {
            return (string) ($state['stage'] ?? '');
        }
        $previous = $state['previous'] ?? null;
        $tree = $state['tree'] ?? null;
        return is_string($previous) && is_dir($previous) && (!is_string($tree) || !is_dir($tree)) ? 'swapped' : 'backed-up';
    }

    /** Delete a folder and everything in it, never following a symbolic link out of it. */
    public static function removeTree(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }

    /**
     * After an update is done: keep the newest $keep previous copies this
     * updater made and the newest $keep database backups, and remove the
     * rest, with any downloaded zips and unpacking folders a failed update
     * left. Each copy is a whole app (thousands of files), and shared hosts
     * limit how many files an account may have. The update's own previous
     * copy and backup always stay, so --rollback keeps working. Copies made
     * by hand (any other name) are never touched. Returns what went.
     *
     * @return list<string>
     */
    public function prune(int $keep = 2): array
    {
        $state = $this->readState() ?? [];
        $pattern = '/^' . preg_quote($this->name(), '/') . '-\d+\.\d+\.\d+(-rolled-back)?(-\d+)?$/';
        $copies = array_values(array_filter($this->siblingCopies(), static fn(string $d): bool => preg_match($pattern, basename($d)) === 1));
        $backupDir = $this->backupDir();
        $backups = array_values(array_filter(glob($backupDir . '/db-*') ?: [], 'is_file'));
        $removed = [];
        // Newest first by when it was set aside (renaming a folder updates its ctime).
        foreach ([[$copies, $state['previous'] ?? null], [$backups, $state['backup'] ?? null]] as [$list, $current]) {
            usort($list, static fn(string $a, string $b): int => (int) @filectime($b) <=> (int) @filectime($a));
            foreach (array_slice($list, $keep) as $path) {
                if ($path === $current) {
                    continue;
                }
                self::removeTree($path);
                $removed[] = basename($path);
            }
        }
        $leftovers = array_merge(
            glob($backupDir . '/' . $this->name() . '-*.zip') ?: [],
            glob($this->parent() . '/.' . $this->name() . '-*-unpacking*') ?: [],
        );
        foreach ($leftovers as $path) {
            self::removeTree($path);
            $removed[] = basename($path);
        }
        return $removed;
    }

    /** A path that doesn't exist yet: $base, or $base-2, $base-3 … */
    public static function freePath(string $base): string
    {
        if (!file_exists($base)) {
            return $base;
        }
        for ($i = 2; ; $i++) {
            if (!file_exists("$base-$i")) {
                return "$base-$i";
            }
        }
    }

    /** The "Operators:" line of a release's notes: what an update needs. */
    public static function operatorsLine(string $notes): ?string
    {
        foreach (preg_split('/\R/u', $notes) ?: [] as $line) {
            if (str_starts_with(trim($line), 'Operators:')) {
                // Editable notes are not signed. Keep prose, never terminal or bidi controls.
                return trim((string) preg_replace('/[\x00-\x1F\x7F]|[\x{0080}-\x{009F}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $line));
            }
        }
        return null;
    }

    /** The lowest PHP a release's composer.json asks for (">=8.4.1", "^8.4"), or null when it doesn't say. */
    public static function phpRequirement(string $composerJson): ?string
    {
        $data = json_decode($composerJson, true);
        $req = is_array($data) ? ($data['require']['php'] ?? null) : null;
        if (!is_string($req) || preg_match('/(\d+\.\d+(?:\.\d+)?)/', $req, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    /**
     * The folder a release zip keeps its files under: "better-cal/" for
     * releases up to 0.9.83, "" for later ones, whose files sit at the top
     * so a hosting file manager can unpack them straight into the folder
     * you choose. Null when it's neither.
     */
    public static function zipRoot(\ZipArchive $zip): ?string
    {
        if ($zip->locateName(self::ZIP_ROOT . 'VERSION') !== false) {
            return self::ZIP_ROOT;
        }
        return $zip->locateName('VERSION') !== false ? '' : null;
    }

    /**
     * Refuse a zip that could write anywhere but its own folder: every entry
     * under its root (zipRoot), no "..", no absolute paths or backslashes, no
     * symbolic links, and bounded in count and unpacked size (declared sizes
     * here; extraction is from a file whose digest was already checked).
     */
    public static function checkZip(\ZipArchive $zip): void
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
            throw new \RuntimeException('The zip has an unexpected number of files');
        }
        $root = self::zipRoot($zip) ?? self::ZIP_ROOT;
        $total = 0;
        $paths = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string) $stat['name'] : '';
            if ($name === '' || !str_starts_with($name, $root) || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")
                || preg_match('~^[A-Za-z]:|[\x00-\x1F\x7F]~', $name) === 1
                || str_contains($name, '//') || array_intersect(['.', '..', ''], explode('/', rtrim($name, '/'))) !== []) {
                throw new \RuntimeException('The zip holds an unexpected path');
            }
            $directory = str_ends_with($name, '/');
            $path = rtrim($name, '/');
            if (array_key_exists($path, $paths)) {
                throw new \RuntimeException('The zip repeats a path');
            }
            $paths[$path] = $directory;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX) {
                $type = ($attr >> 16) & 0170000;
                if (!in_array($type, [0, $directory ? 0040000 : 0100000], true)) {
                    throw new \RuntimeException('The zip holds a symbolic link or special file');
                }
            }
            if (($stat['size'] ?? -1) < 0 || ($directory && $stat['size'] !== 0)
                || ($stat['encryption_method'] ?? 0) !== 0) {
                throw new \RuntimeException('The zip holds an unsupported entry');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_UNPACKED_BYTES) {
                throw new \RuntimeException('The zip unpacks to more than expected');
            }
        }
        foreach ($paths as $path => $_) {
            for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) {
                if (isset($paths[$parent]) && !$paths[$parent]) {
                    throw new \RuntimeException('The zip uses a file as a directory');
                }
            }
        }
        if ($zip->locateName($root . 'VERSION') === false || $zip->locateName($root . 'server/vendor/autoload.php') === false) {
            throw new \RuntimeException("The zip isn't a Better-Cal release with its libraries");
        }
    }

    /** Unpack a checked release zip into a fresh folder beside the app. Returns the new tree's path. */
    public function unpack(string $zipPath, string $version): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Could not open the downloaded zip');
        }
        try {
            self::checkZip($zip);
            $root = (string) self::zipRoot($zip);
            $staging = self::freePath($this->parent() . '/.' . $this->name() . '-' . $version . '-unpacking');
            if (!@mkdir($staging, 0755)) {
                throw new \RuntimeException("Could not create $staging (is " . $this->parent() . ' writable?)');
            }
            // Either layout ends up as <staging>/better-cal.
            $into = $root === '' ? $staging . '/' . rtrim(self::ZIP_ROOT, '/') : $staging;
            if (($root === '' && !@mkdir($into, 0755)) || !$zip->extractTo($into)) {
                throw new \RuntimeException('Unpacking the zip failed (is the disk full?)');
            }
        } finally {
            $zip->close();
        }
        $tree = $staging . '/' . rtrim(self::ZIP_ROOT, '/');
        if (trim((string) @file_get_contents($tree . '/VERSION')) !== $version) {
            throw new \RuntimeException("The zip's VERSION isn't $version");
        }
        return $tree;
    }

    /**
     * Carry your own files from the current copy into the new one: .env,
     * per-host PHP and web-server files, and plugins you added. Returns what
     * was carried, and what else exists only in the current copy (left in
     * the previous version's folder, listed so nothing goes missing quietly).
     *
     * @return array{carried:list<string>,leftBehind:list<string>}
     */
    public function carryOver(string $newRoot): array
    {
        $carried = [];
        foreach (self::CARRIED as $rel) {
            $from = $this->appRoot . '/' . $rel;
            if (file_exists($from) && !file_exists($newRoot . '/' . $rel)) {
                self::copyTree($from, $newRoot . '/' . $rel);
                $carried[] = $rel;
            }
        }
        foreach (glob($this->appRoot . '/server/plugins/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $rel = 'server/plugins/' . basename($dir);
            if (!file_exists($newRoot . '/' . $rel)) {
                self::copyTree($dir, $newRoot . '/' . $rel);
                $carried[] = $rel;
            }
        }
        // Files the current release shipped (its .release-files list) were
        // simply removed in the new one; only files someone added count.
        $shipped = array_fill_keys(array_filter(explode("\n", (string) @file_get_contents($this->appRoot . '/' . self::RELEASE_FILES))), true);
        $leftBehind = [];
        $this->walk($this->appRoot, '', static function (string $rel) use ($newRoot, &$leftBehind, $carried, $shipped): bool {
            foreach (array_merge(self::NOT_CARRIED, $carried) as $skip) {
                if ($rel === $skip || str_starts_with($rel, $skip . '/')) {
                    return false;
                }
            }
            if (isset($shipped[$rel])) {
                return false;
            }
            if (!file_exists($newRoot . '/' . $rel)) {
                $leftBehind[] = $rel;
                return false;
            }
            return true;
        });
        foreach (['.env.php', '.env'] as $settings) {
            if (is_file($newRoot . '/' . $settings)) {
                @chmod($newRoot . '/' . $settings, 0600);
            }
        }
        return ['carried' => $carried, 'leftBehind' => $leftBehind];
    }

    /** @param callable(string):bool $visit returns whether to descend */
    private function walk(string $root, string $rel, callable $visit): void
    {
        foreach (scandir($root . ($rel === '' ? '' : '/' . $rel)) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $rel === '' ? $entry : $rel . '/' . $entry;
            if ($visit($child) && is_dir($root . '/' . $child) && !is_link($root . '/' . $child)) {
                $this->walk($root, $child, $visit);
            }
        }
    }

    /** Copy a file or folder, keeping modes; refuses symbolic links rather than following them. */
    public static function copyTree(string $from, string $to): void
    {
        if (is_link($from)) {
            throw new \RuntimeException("Not copying the symbolic link $from; move it across by hand");
        }
        if (is_dir($from)) {
            if (!is_dir($to) && !@mkdir($to, fileperms($from) & 0777, true)) {
                throw new \RuntimeException("Could not create $to");
            }
            foreach (scandir($from) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::copyTree($from . '/' . $entry, $to . '/' . $entry);
                }
            }
            return;
        }
        if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0755, true)) {
            throw new \RuntimeException('Could not create ' . dirname($to));
        }
        if (!@copy($from, $to)) {
            throw new \RuntimeException("Could not copy $from");
        }
        @chmod($to, fileperms($from) & 0777);
    }

    /** Whether this PHP may start other programs (git, composer, the new code's --finish). */
    public static function canRun(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists('proc_open') && !in_array('proc_open', $disabled, true);
    }

    /**
     * Run a program, its output passing straight through. Returns its exit code.
     *
     * @param list<string> $cmd
     */
    public static function run(array $cmd, ?string $cwd = null): int
    {
        $proc = proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Could not start ' . $cmd[0]);
        }
        return proc_close($proc);
    }

    /**
     * Run a program and capture what it prints.
     *
     * @param list<string> $cmd
     * @return array{0:int,1:string}
     */
    public static function capture(array $cmd, ?string $cwd = null): array
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($proc)) {
            return [127, ''];
        }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), (string) $out];
    }
}
