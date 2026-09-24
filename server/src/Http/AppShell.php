<?php

declare(strict_types=1);

namespace BetterCal\Http;

/**
 * The two pieces of the frontend that depend on every other file in it,
 * filled in by the server when they are requested:
 *
 * - the <link rel="modulepreload"> block in web/index.html. The app ships
 *   unbundled ES modules (no build step), so without it the browser discovers
 *   the 11-level import graph one level at a time; with it, the whole graph
 *   is fetched in parallel. Measured cold, that was ~610 ms of a ~1.4 s start.
 * - the service worker's VERSION and SHELL list in web/sw.js. The worker
 *   serves the app's files cache-first, so a new VERSION is the only thing
 *   that makes browsers take a deploy, and all of a version's files are
 *   swapped in together (a mix of old and new modules can break the app).
 *   VERSION is a hash of every file in the shell, so any change produces a
 *   new one and an unchanged deploy invalidates nothing.
 *
 * These used to be generated into the committed files by a script at deploy
 * time, which left the working tree modified after every deploy and the
 * release tags out of step with what shipped. Here, the committed files stay
 * templates and never change on their own.
 *
 * Cost: the result is cached in a file (the system temp directory) together
 * with a signature of every file under web/ (size, mtime, ctime, inode), so a
 * request checks about 150 stat()s and reads one small file; any change
 * recomputes it once. If the temp directory is not writable, every request
 * recomputes it, which takes a few milliseconds.
 *
 * The raw template (served without this, e.g. a web server sending web/sw.js
 * from disk) carries VERSION = 'bc-unversioned', and the worker then leaves
 * the app's files to the network rather than risk pinning stale ones.
 */
final class AppShell
{
    public const UNVERSIONED = 'bc-unversioned';
    public const PRELOAD_START = '  <!-- modulepreload:start (filled in by the server: server/src/Http/AppShell.php) -->';
    public const PRELOAD_END = '  <!-- modulepreload:end -->';
    public const SW_START = '// @generated-shell:start (filled in by the server: server/src/Http/AppShell.php)';
    public const SW_END = '// @generated-shell:end';
    private const ENTRY = 'src/app/main.js';

    /**
     * Everything a cold start or an offline open needs beyond the module
     * graph: the document, the stylesheet, the PWA files, and the two lazily
     * loaded vendor libraries with their assets (loaded by <script>, so not
     * in the graph). Entries missing on disk are left out and logged.
     */
    public const EXTRA = [
        '/', '/assets/styles/app.css', '/assets/manifest.webmanifest',
        '/assets/icons/icon.svg', '/assets/icons/icon-maskable.svg', '/assets/icons/icon-192.png', '/assets/icons/icon-512.png',
        '/assets/vendor/leaflet/leaflet.js', '/assets/vendor/leaflet/leaflet.css',
        '/assets/vendor/leaflet/images/marker-icon.png', '/assets/vendor/leaflet/images/marker-icon-2x.png', '/assets/vendor/leaflet/images/marker-shadow.png',
        '/assets/vendor/squire/purify.min.js', '/assets/vendor/squire/squire-raw.js',
    ];

    /** True when the last state() had to compute rather than use the cache (tests). */
    public bool $computed = false;

    /** @param list<string> $extra */
    public function __construct(
        private readonly string $webRoot,
        private readonly ?string $cacheFile = null,
        private readonly array $extra = self::EXTRA,
    ) {
    }

    /** The production instance: cached per web root, so two installs on one host never share a cache. */
    public static function forWebRoot(string $webRoot): self
    {
        return new self($webRoot, rtrim(sys_get_temp_dir(), '/\\') . '/bettercal-shell-' . substr(sha1($webRoot), 0, 12) . '.json');
    }

    /** web/index.html with the preload block filled in. */
    public function renderIndex(): string
    {
        return self::fillIndex($this->read('index.html'), $this->state()['modules']);
    }

    /** web/sw.js with VERSION and SHELL filled in. */
    public function renderServiceWorker(): string
    {
        $s = $this->state();
        return self::fillWorker($this->read('sw.js'), $s['version'], $s['shell']);
    }

    /** The current version, e.g. "bc-3f2a9c01d4e7" (also the ETag of both rendered files). */
    public function version(): string
    {
        return $this->state()['version'];
    }

    /** @return array{version:string, modules:list<string>, shell:list<string>} */
    public function state(): array
    {
        $this->computed = false;
        $sig = $this->signature();
        if ($this->cacheFile !== null && is_file($this->cacheFile)) {
            $cached = json_decode((string) @file_get_contents($this->cacheFile), true);
            if (is_array($cached) && ($cached['sig'] ?? null) === $sig
                && is_string($cached['version'] ?? null) && is_array($cached['modules'] ?? null) && is_array($cached['shell'] ?? null)) {
                return ['version' => $cached['version'], 'modules' => $cached['modules'], 'shell' => $cached['shell']];
            }
        }
        $this->computed = true;
        $state = $this->compute();
        if ($this->cacheFile !== null) {
            // Write then rename, so a concurrent request never reads half a file.
            $tmp = $this->cacheFile . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode(['sig' => $sig] + $state, JSON_UNESCAPED_SLASHES)) !== false) {
                if (!@rename($tmp, $this->cacheFile)) {
                    @unlink($tmp);
                }
            }
        }
        return $state;
    }

    /** @return array{version:string, modules:list<string>, shell:list<string>} */
    private function compute(): array
    {
        $graph = $this->graph();
        $all = array_map(fn(string $rel): string => '/assets/' . $rel, $graph);
        // main.js has its own <script> tag; preloading it too is wasted bytes.
        $modules = array_values(array_filter($all, static fn(string $u): bool => !str_ends_with($u, '/src/app/main.js')));
        $shell = $this->extra;
        foreach ($all as $u) {
            if (!in_array($u, $shell, true)) {
                $shell[] = $u;
            }
        }
        $missing = array_values(array_filter($shell, fn(string $u): bool => !is_file($this->localPath($u))));
        if ($missing !== []) {
            error_log('app shell: entries missing on disk, left out: ' . implode(', ', $missing));
            $shell = array_values(array_diff($shell, $missing));
            $modules = array_values(array_diff($modules, $missing));
        }
        $hash = hash_init('sha256');
        hash_update($hash, self::fillIndex($this->read('index.html'), $modules));
        foreach ($shell as $u) {
            if ($u !== '/') {
                hash_update($hash, (string) file_get_contents($this->localPath($u)));
            }
        }
        // The worker's own code, without the block this fills in.
        hash_update($hash, self::replaceBlock($this->read('sw.js'), self::SW_START, self::SW_END, ''));
        return ['version' => 'bc-' . substr(hash_final($hash), 0, 12), 'modules' => $modules, 'shell' => $shell];
    }

    /**
     * The static import graph from src/app/main.js, breadth-first so the
     * order roughly matches the order the browser would discover it in.
     * Static imports only: a dynamic import() is deferred on purpose, and
     * preloading it would undo that. Paths relative to the web root.
     *
     * @return list<string>
     */
    public function graph(): array
    {
        $seen = [];
        $order = [];
        $queue = [self::ENTRY];
        while ($queue !== []) {
            $rel = array_shift($queue);
            if (isset($seen[$rel])) {
                continue;
            }
            $seen[$rel] = true;
            $order[] = $rel;
            $src = @file_get_contents($this->webRoot . '/' . $rel);
            if ($src === false) {
                continue;
            }
            $specs = [];
            if (preg_match_all('~(?:^|\n)\s*(?:import|export)[^\'"\n]*?from\s+\'([^\']+)\'~', $src, $m)) {
                $specs = array_merge($specs, $m[1]);
            }
            if (preg_match_all('~(?:^|\n)\s*import\s+\'([^\']+)\'~', $src, $m)) {
                $specs = array_merge($specs, $m[1]);
            }
            foreach ($specs as $spec) {
                if (!str_starts_with($spec, '.')) {
                    continue;
                }
                $next = self::normalize(dirname($rel) . '/' . $spec);
                if ($next !== null) {
                    $queue[] = $next;
                }
            }
        }
        return $order;
    }

    public static function fillIndex(string $html, array $modules): string
    {
        $block = implode("\n", array_merge(
            [self::PRELOAD_START],
            array_map(static fn(string $u): string => '  <link rel="modulepreload" href="' . htmlspecialchars($u, ENT_QUOTES) . '">', $modules),
            [self::PRELOAD_END]
        ));
        return self::replaceBlock($html, self::PRELOAD_START, self::PRELOAD_END, $block);
    }

    public static function fillWorker(string $js, string $version, array $shell): string
    {
        $block = implode("\n", array_merge(
            [self::SW_START, 'const VERSION = ' . json_encode($version) . ';', 'const SHELL = ['],
            array_map(static fn(string $u): string => '  ' . json_encode($u, JSON_UNESCAPED_SLASHES) . ',', $shell),
            ['];', self::SW_END]
        ));
        return self::replaceBlock($js, self::SW_START, self::SW_END, $block);
    }

    /** Replace from $start through $end (inclusive); unchanged when the markers are missing. */
    private static function replaceBlock(string $text, string $start, string $end, string $with): string
    {
        $a = strpos($text, $start);
        $b = $a === false ? false : strpos($text, $end, $a);
        if ($a === false || $b === false) {
            return $text;
        }
        return substr($text, 0, $a) . $with . substr($text, $b + strlen($end));
    }

    /** "src/app/../lib/x.js" -> "src/lib/x.js"; null if it climbs out of the web root. */
    private static function normalize(string $path): ?string
    {
        $out = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($out === []) {
                    return null;
                }
                array_pop($out);
                continue;
            }
            $out[] = $part;
        }
        return implode('/', $out);
    }

    private function localPath(string $url): string
    {
        return $url === '/' ? $this->webRoot . '/index.html' : $this->webRoot . '/' . substr($url, strlen('/assets/'));
    }

    private function read(string $rel): string
    {
        return (string) @file_get_contents($this->webRoot . '/' . $rel);
    }

    /**
     * Everything under web/ that could change the result, except the tests.
     * ctime and inode are in it as well as size and mtime, so a file replaced
     * with the same size and an old timestamp (rsync keeps mtimes) still
     * counts as changed.
     */
    private function signature(): string
    {
        $h = hash_init('sha1');
        $walk = function (string $dir, string $rel) use (&$walk, $h): void {
            $names = @scandir($dir);
            if ($names === false) {
                return;
            }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..' || ($rel === '' && $name === 'tests')) {
                    continue;
                }
                $path = $dir . '/' . $name;
                if (is_dir($path)) {
                    $walk($path, $rel . $name . '/');
                    continue;
                }
                $st = @stat($path);
                if ($st !== false) {
                    hash_update($h, $rel . $name . '|' . $st['size'] . '|' . $st['mtime'] . '|' . $st['ctime'] . '|' . $st['ino'] . "\n");
                }
            }
        };
        $walk($this->webRoot, '');
        return hash_final($h);
    }
}
