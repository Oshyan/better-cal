<?php

declare(strict_types=1);

namespace BetterCal\Infra;

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
    private const NOT_CARRIED = ['server/vendor', '.runtime-cache', '.maintenance', '.git', 'worker.log', '.backups', '.release-files'];
    /** Carried across when present: settings and per-host PHP or web-server files. */
    private const CARRIED = ['.env', '.user.ini', 'server/public/.user.ini', 'server/public/.well-known'];

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

    /** Backups and the update record: beside the app, or inside it (git, read-only parent). */
    public function backupDir(): string
    {
        $beside = $this->parent() . '/' . $this->name() . '-backups';
        if (is_dir($beside) || is_writable($this->parent()) || !$this->isGit()) {
            return $beside;
        }
        return $this->appRoot . '/.backups';
    }

    public function statePath(): string
    {
        return $this->backupDir() . '/update-state.json';
    }

    /** @return ?array<string,mixed> */
    public function readState(): ?array
    {
        $raw = @file_get_contents($this->statePath());
        $state = $raw === false ? null : json_decode($raw, true);
        return is_array($state) ? $state : null;
    }

    /** @param array<string,mixed> $state */
    public function writeState(array $state): void
    {
        $this->ensureBackupDir();
        if (file_put_contents($this->statePath(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
            throw new \RuntimeException('Could not write ' . $this->statePath());
        }
    }

    public function ensureBackupDir(): void
    {
        $dir = $this->backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new \RuntimeException("Could not create $dir");
        }
        @chmod($dir, 0700);
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
        foreach (preg_split('/\R/', $notes) ?: [] as $line) {
            if (str_starts_with(trim($line), 'Operators:')) {
                return trim($line);
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
     * Refuse a zip that could write anywhere but its own folder: every entry
     * under better-cal/, no "..", no absolute paths or backslashes, no
     * symbolic links, and bounded in count and unpacked size (declared sizes
     * here; extraction is from a file whose digest was already checked).
     */
    public static function checkZip(\ZipArchive $zip): void
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
            throw new \RuntimeException('The zip has an unexpected number of files');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string) $stat['name'] : '';
            if (!str_starts_with($name, self::ZIP_ROOT) || str_contains($name, '\\') || str_contains($name, "\0")
                || in_array('..', explode('/', $name), true)) {
                throw new \RuntimeException("The zip holds an unexpected path: $name");
            }
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX
                && (($attr >> 16) & 0170000) === 0120000) {
                throw new \RuntimeException("The zip holds a symbolic link: $name");
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_UNPACKED_BYTES) {
                throw new \RuntimeException('The zip unpacks to more than expected');
            }
        }
        if ($zip->locateName(self::ZIP_ROOT . 'VERSION') === false || $zip->locateName(self::ZIP_ROOT . 'server/vendor/autoload.php') === false) {
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
            $staging = self::freePath($this->parent() . '/.' . $this->name() . '-' . $version . '-unpacking');
            if (!@mkdir($staging, 0755)) {
                throw new \RuntimeException("Could not create $staging (is " . $this->parent() . ' writable?)');
            }
            if (!$zip->extractTo($staging)) {
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
        if (is_file($newRoot . '/.env')) {
            @chmod($newRoot . '/.env', 0600);
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
