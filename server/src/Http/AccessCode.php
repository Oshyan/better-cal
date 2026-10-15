<?php

declare(strict_types=1);

namespace BetterCal\Http;

/**
 * Proof that whoever is at the browser controls the server's files, for the
 * two pages that run before anyone can sign in: setup in the browser (no
 * .env yet, #138) and the database update after new files were uploaded.
 *
 * The first visit writes a code to better-cal-code.php in the app folder;
 * the page asks for it. Like the settings file it starts with a guard line
 * (Support\SettingsFile), so a web server shows it as a blank page even where
 * the app folder sits inside a web root; a file manager shows the code. Anyone
 * who can read that file could already change the app, so a stranger who
 * finds the page first gets nowhere. The code is 60 bits, expires after an
 * hour, and is replaced after 5 wrong tries. Entering it starts a session
 * held in an HttpOnly, SameSite=Strict cookie; forms also carry a token
 * derived from it. Used once, the files are removed.
 */
final class AccessCode
{
    public const FILE = 'better-cal-code.php';
    /** The old name, removed wherever it's found. */
    private const OLD_FILE = 'better-cal-code.txt';
    public const COOKIE = 'bc_access';
    private const TTL = 3600;
    private const MAX_TRIES = 5;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly string $appRoot, private readonly string $purpose)
    {
    }

    private function statePath(): string
    {
        return $this->appRoot . '/.runtime-cache/access-code.php';
    }

    public function codePath(): string
    {
        return $this->appRoot . '/' . self::FILE;
    }

    /** Whether this PHP can write the files the code needs (the app folder). */
    public function writable(): bool
    {
        $dir = dirname($this->statePath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0700);
        }
        return is_dir($dir) && is_writable($dir) && is_writable($this->appRoot);
    }

    /**
     * Make sure a current code exists, writing a fresh one when there's none,
     * it expired, it belongs to the other purpose, or too many tries used it up.
     */
    public function ensure(): void
    {
        $s = $this->state();
        if ($s !== null && $s['purpose'] === $this->purpose && $s['expires'] > time() && $s['tries'] < self::MAX_TRIES && is_file($this->codePath())) {
            return;
        }
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $display = implode('-', str_split($code, 4));
        $this->save(['purpose' => $this->purpose, 'hash' => hash('sha256', $code), 'expires' => time() + self::TTL, 'tries' => 0, 'session' => null]);
        $what = $this->purpose === 'setup' ? 'setup' : 'database update';
        @unlink($this->appRoot . '/' . self::OLD_FILE);
        \BetterCal\Support\SettingsFile::writeAtomic($this->codePath(), \BetterCal\Support\SettingsFile::GUARD_PREFIX . " // Better-Cal code file: the line below is the code.\n"
            . "Better-Cal $what code: $display\n\n"
            . "Enter it on the page that asked for it. It works for an hour and only once;\n"
            . "the page writes a new one when needed. This file is safe to delete.\n", 0600);
    }

    /** Check a typed code. On success, returns the session token to set as a cookie. */
    public function verify(string $typed): ?string
    {
        $s = $this->state();
        if ($s === null || $s['purpose'] !== $this->purpose || $s['expires'] <= time() || $s['tries'] >= self::MAX_TRIES) {
            return null;
        }
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $typed));
        if (!hash_equals($s['hash'], hash('sha256', $clean))) {
            $s['tries']++;
            $this->save($s);
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $s['session'] = hash('sha256', $token);
        $s['tries'] = 0;
        $this->save($s);
        return $token;
    }

    public function triesLeft(): int
    {
        $s = $this->state();
        return $s === null ? self::MAX_TRIES : max(0, self::MAX_TRIES - $s['tries']);
    }

    /** Whether this cookie value is the session a correct code started, still in time. */
    public function hasSession(?string $token): bool
    {
        $s = $this->state();
        return $token !== null && $token !== '' && $s !== null && $s['purpose'] === $this->purpose
            && $s['session'] !== null && $s['expires'] > time() && hash_equals($s['session'], hash('sha256', $token));
    }

    /** The token a form carries to show it came from this session's own page. */
    public static function formToken(string $token): string
    {
        return hash_hmac('sha256', 'form', $token);
    }

    /** Used: remove the code file and the state, so neither can be used again. */
    public function finish(): void
    {
        @unlink($this->codePath());
        @unlink($this->appRoot . '/' . self::OLD_FILE);
        @unlink($this->statePath());
        @unlink(dirname($this->statePath()) . '/access-code.json');
    }

    /** @return ?array{purpose:string,hash:string,expires:int,tries:int,session:?string} */
    private function state(): ?array
    {
        $raw = @file_get_contents($this->statePath());
        $nl = $raw === false ? false : strpos($raw, "\n");
        $s = $raw === false || $nl === false ? null : json_decode(substr($raw, $nl + 1), true);
        return is_array($s) && isset($s['purpose'], $s['hash'], $s['expires']) ? $s + ['tries' => 0, 'session' => null] : null;
    }

    private function save(array $s): void
    {
        $dir = dirname($this->statePath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0700);
        }
        $ok = \BetterCal\Support\SettingsFile::writeAtomic($this->statePath(), \BetterCal\Support\SettingsFile::GUARD_PREFIX . "\n" . json_encode($s), 0600);
        if (!$ok) {
            throw new \RuntimeException('Could not save the access code state in ' . $dir);
        }
    }
}
