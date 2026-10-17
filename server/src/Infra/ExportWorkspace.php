<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Http\HttpError;
use BetterCal\Support\SettingsFile;

/** One export at a time, from creation through sending and cleanup. */
final class ExportWorkspace
{
    private mixed $lock = null;
    private array $paths = [];
    public readonly string $dir;

    public function __construct(string $appRoot)
    {
        if (is_link($appRoot) || ($root = realpath($appRoot)) === false) {
            throw new \RuntimeException('The export application folder is not a real directory.');
        }
        $cache = $root . '/.runtime-cache';
        PrivatePath::directory($cache, true);
        $lockPath = $cache . '/export-lock.php';
        PrivatePath::check($lockPath, false, true);
        $mask = umask(0077);
        try {
            $new = @fopen($lockPath, 'xb');
            if ($new !== false) {
                fwrite($new, SettingsFile::GUARD_PREFIX . "\n");
                fclose($new);
            }
            PrivatePath::check($lockPath, false);
            $lock = @fopen($lockPath, 'rb');
        } finally {
            umask($mask);
        }
        if ($lock === false) {
            throw new \RuntimeException('Could not open the export lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new HttpError('export_busy', 'An export is already running. Let it finish, then try again.', 409);
        }
        $this->lock = $lock;
        register_shutdown_function($this->close(...));
        $this->dir = $cache . '/exports';
        try {
            PrivatePath::directory($this->dir, true);
            // The lock proves no older request still owns these files. No
            // age-based guesses, and no cleanup of global temporary storage.
            foreach (scandir($this->dir) ?: [] as $name) {
                if (preg_match('/^[a-f0-9]{32}\.(ics|zip)(\.[a-zA-Z0-9]+(?:\.part)?)?$/D', $name)) {
                    $path = $this->dir . '/' . $name;
                    PrivatePath::check($path, false);
                    if (!unlink($path)) {
                        throw new \RuntimeException('Could not remove an abandoned export.');
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    /** A private, empty file (ZIP uses OVERWRITE on this owned placeholder). */
    public function file(string $extension): string
    {
        if (!in_array($extension, ['ics', 'zip'], true)) {
            throw new \InvalidArgumentException('Unknown export file type.');
        }
        $path = $this->dir . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $mask = umask(0077);
        try {
            $fh = fopen($path, 'xb');
        } finally {
            umask($mask);
        }
        if ($fh === false) {
            throw new \RuntimeException('Could not create the export file.');
        }
        $this->paths[] = $path;
        fclose($fh);
        return $path;
    }

    public function close(): void
    {
        if ($this->lock === null) {
            return;
        }
        $owned = array_fill_keys(array_map('basename', $this->paths), true);
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        // libzip can leave a private sibling temporary file when close
        // fails. Only names derived from this request's random path.
        foreach (is_dir($this->dir) ? (scandir($this->dir) ?: []) : [] as $name) {
            if (preg_match('/^([a-f0-9]{32}\.zip)\.[a-zA-Z0-9]+(?:\.part)?$/D', $name, $m) && isset($owned[$m[1]])) {
                @unlink($this->dir . '/' . $name);
            }
        }
        $this->paths = [];
        flock($this->lock, LOCK_UN);
        fclose($this->lock);
        $this->lock = null;
    }
}
