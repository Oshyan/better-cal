<?php

declare(strict_types=1);

namespace BetterCal\Http;

use BetterCal\Support\SettingsFile;

/** File-manager proof for setup and database updates. Wrong codes never change state. */
final class AccessCode
{
    public const FILE = 'better-cal-code.php';
    public const COOKIE = 'bc_access';
    public const USED_MESSAGE = 'This code was already used. If that was you and the page lost your session, delete better-cal-code.php in your file manager and reload this page for a new code.';
    private const TTL = 3600;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private bool $used = false;
    private static bool $warned = false;

    /** $needed rechecks completion under the lock, including for requests already in flight. */
    public function __construct(private readonly string $appRoot, private readonly string $purpose, private readonly ?\Closure $needed = null)
    {
    }

    public function codePath(): string
    {
        return $this->appRoot . '/' . self::FILE;
    }

    public function writable(): bool
    {
        return is_writable($this->appRoot);
    }

    /** False when this operation finished while the request was in flight. */
    public function ensure(): bool
    {
        return $this->locked(function (): bool {
            if (!$this->needed()) {
                return false;
            }
            $s = $this->state();
            if ($s !== null && $s['purpose'] === $this->purpose
                && ($s['session'] !== null ? $s['sessionExpires'] > time() : $s['expires'] > time())) {
                return true;
            }
            $code = '';
            for ($i = 0; $i < 12; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            // Old split-file codes/sessions deliberately start afresh. Never import stale authority.
            $this->save(['format' => 2, 'purpose' => $this->purpose, 'code' => $code,
                'hash' => hash('sha256', $code), 'expires' => time() + self::TTL,
                'session' => null, 'sessionExpires' => 0]);
            $this->removeLegacy();
            return true;
        });
    }

    public function verify(string $typed): ?string
    {
        $this->used = false;
        return $this->locked(function () use ($typed): ?string {
            $s = $this->state();
            $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $typed));
            if (!$this->needed() || $s === null || $s['purpose'] !== $this->purpose
                || !hash_equals($s['hash'], hash('sha256', $clean))) {
                return null;
            }
            if ($s['session'] !== null) {
                $this->used = true;
                return null;
            }
            if ($s['expires'] <= time()) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $s['code'] = null;
            $s['session'] = hash('sha256', $token);
            $s['sessionExpires'] = time() + self::TTL;
            $this->save($s);
            return $token;
        });
    }

    public function wasUsed(): bool
    {
        return $this->used;
    }

    public function hasSession(?string $token): bool
    {
        $s = $this->state(); // Atomic replacement means readers cannot see half a transition.
        return $this->needed() && $token !== null && $token !== '' && $s !== null && $s['purpose'] === $this->purpose
            && $s['session'] !== null && $s['sessionExpires'] > time() && hash_equals($s['session'], hash('sha256', $token));
    }

    public static function formToken(string $token): string
    {
        return hash_hmac('sha256', 'form', $token);
    }

    public function finish(?string $token = null): void
    {
        $this->locked(function () use ($token): void {
            $s = $this->state();
            if ($token !== null && ($s === null || $s['session'] === null || !hash_equals($s['session'], hash('sha256', $token)))) {
                return; // A stale completed request must not delete a newer file-manager reset.
            }
            if (is_file($this->codePath()) && !@unlink($this->codePath())) {
                throw new \RuntimeException('Could not remove the access code state.');
            }
            $this->removeLegacy();
        });
    }

    private function needed(): bool
    {
        clearstatcache();
        return $this->needed === null || ($this->needed)();
    }

    private function removeLegacy(): void
    {
        foreach (['better-cal-code.txt', '.runtime-cache/access-code.php', '.runtime-cache/access-code.json'] as $name) {
            @unlink($this->appRoot . '/' . $name);
        }
    }

    private function state(): ?array
    {
        $raw = @file_get_contents($this->codePath());
        if (!is_string($raw) || !str_starts_with($raw, SettingsFile::GUARD_PREFIX . "\n")) {
            return null;
        }
        $s = json_decode(explode("\n", $raw, 3)[1] ?? '', true);
        if (!is_array($s) || ($s['format'] ?? null) !== 2 || !is_string($s['purpose'] ?? null)
            || !is_string($s['hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $s['hash']) !== 1
            || !is_int($s['expires'] ?? null) || !is_int($s['sessionExpires'] ?? null)
            || !array_key_exists('session', $s) || !array_key_exists('code', $s)) {
            return null;
        }
        if ($s['session'] === null) {
            if (!is_string($s['code']) || preg_match('/^[A-Z2-9]{12}$/D', $s['code']) !== 1
                || !hash_equals($s['hash'], hash('sha256', $s['code']))) {
                return null;
            }
        } elseif (!is_string($s['session']) || preg_match('/^[a-f0-9]{64}$/D', $s['session']) !== 1 || $s['code'] !== null) {
            return null;
        }
        return $s;
    }

    private function save(array $s): void
    {
        $display = $s['code'] === null ? self::USED_MESSAGE : 'Better-Cal ' . $this->purpose . ' code: ' . implode('-', str_split($s['code'], 4))
            . "\n\nEnter it on the page that asked for it. It works for an hour and only once.";
        if (!SettingsFile::writeAtomic($this->codePath(), SettingsFile::GUARD_PREFIX . "\n" . json_encode($s)
            . "\n\n" . $display . "\n", 0600)) {
            throw new \RuntimeException('Could not save the access code state.');
        }
    }

    private function locked(\Closure $action): mixed
    {
        $dir = $this->appRoot . '/.runtime-cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700);
        }
        // Stable inode: never renamed or removed, even by finish(). Contains no secret.
        $old = umask(0077);
        try {
            $fh = @fopen($dir . '/access-code-lock.php', 'c');
        } finally {
            umask($old);
        }
        $locked = false;
        try {
            if ($fh !== false && function_exists('flock')) {
                $locked = @flock($fh, LOCK_EX); // Ownership notification, not a timer or attempt budget.
            }
            if (!$locked && !self::$warned) {
                self::$warned = true;
                error_log('Better-Cal: access-code locking unavailable; continuing with atomic file writes only. Concurrent setup requests may race.');
            }
            return $action();
        } finally {
            if ($fh !== false) {
                if ($locked) {
                    flock($fh, LOCK_UN);
                }
                fclose($fh);
            }
        }
    }
}
