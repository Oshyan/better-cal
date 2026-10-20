<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * Encrypted backups (docs/design-private-files.md, #142): a backup someone
 * downloads is useless to them, whatever the web server serves.
 *
 * The key is derived from BETTERCAL_SESSION_SECRET, which a restore needs
 * anyway (Google tokens and feed credentials are sealed with it), so there is
 * no new key to keep or lose. libsodium's secretstream (XChaCha20-Poly1305)
 * encrypts in chunks, so a backup never has to fit in memory.
 *
 * File: "BCBK1\n", an 8-byte key id (so a restore with other settings says so
 * plainly), the plaintext chunk size (uint32), the secretstream header; then
 * each chunk as a uint32 length and its ciphertext, the last one tagged
 * FINAL. The fixed preface is authenticated as additional data with every
 * chunk, and nothing may follow the final chunk. Files end in .bcbackup, not
 * .sql.gz, so nobody tries to import one as it is.
 */
final class BackupCipher
{
    public const MAGIC = "BCBK1\n";
    public const EXTENSION = '.bcbackup';
    private const CHUNK = 65536;

    /** @var resource */
    private $fh;
    private string $state;
    private string $ad;
    private string $buffer = '';
    private bool $closed = false;

    public static function keyFrom(string $sessionSecret): string
    {
        if ($sessionSecret === '') {
            throw new \RuntimeException('Backups need BETTERCAL_SESSION_SECRET to be set.');
        }
        $master = sodium_crypto_generichash($sessionSecret, '', SODIUM_CRYPTO_KDF_KEYBYTES);
        return sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 1, 'bcbackup', $master);
    }

    public static function keyId(string $key): string
    {
        return substr(sodium_crypto_generichash($key, '', 16), 0, 8);
    }

    /** @param string|resource $source a path, or an open handle (rewound, left open) */
    public static function isEncrypted(mixed $source): bool
    {
        $fh = is_resource($source) ? $source : @fopen($source, 'rb');
        if ($fh === false) {
            return false;
        }
        if (is_resource($source)) rewind($fh);
        $head = (string) fread($fh, strlen(self::MAGIC));
        if (!is_resource($source)) fclose($fh);
        return $head === self::MAGIC;
    }

    /** Start writing an encrypted file at $path (which must not exist yet). */
    public function __construct(string $path, string $key)
    {
        $fh = @fopen($path, 'xb');
        if ($fh === false) {
            throw new \RuntimeException("Could not create the backup at $path");
        }
        $this->fh = $fh;
        [$this->state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $this->ad = self::MAGIC . self::keyId($key) . pack('N', self::CHUNK);
        $this->put($this->ad . $header);
    }

    public function write(string $bytes): void
    {
        $this->buffer .= $bytes;
        while (strlen($this->buffer) >= self::CHUNK) {
            $this->chunk(substr($this->buffer, 0, self::CHUNK), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            $this->buffer = substr($this->buffer, self::CHUNK);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->chunk($this->buffer, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        $this->buffer = '';
        $this->closed = true;
        if (!fclose($this->fh)) {
            throw new \RuntimeException('Writing the backup failed (is the disk full?)');
        }
    }

    private function chunk(string $plain, int $tag): void
    {
        $c = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, $this->ad, $tag);
        $this->put(pack('N', strlen($c)) . $c);
    }

    private function put(string $bytes): void
    {
        if (fwrite($this->fh, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Writing the backup failed (is the disk full?)');
        }
    }

    /**
     * Decrypt $path chunk by chunk, handing each plaintext chunk to $onPlain.
     * Throws before handing anything over if the file isn't one of ours or was
     * made with other settings, and throws as soon as a chunk fails to verify,
     * the stream ends without its final chunk, or anything follows it. Callers
     * that act on the contents run it once to the end first (DbBackup::restore).
     *
     * $source is a path, or an open handle, which is rewound and left open.
     *
     * @param string|resource $source
     * @param callable(string):void $onPlain
     */
    public static function decrypt(mixed $source, string $key, callable $onPlain): void
    {
        $path = is_resource($source) ? 'The backup' : $source;
        $fh = is_resource($source) ? $source : @fopen($source, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Could not read $path");
        }
        if (is_resource($source)) rewind($fh);
        try {
            $preface = (string) fread($fh, strlen(self::MAGIC) + 8 + 4);
            if (strlen($preface) !== strlen(self::MAGIC) + 12 || !str_starts_with($preface, self::MAGIC)) {
                throw new \RuntimeException(basename($path) . " isn't an encrypted Better-Cal backup.");
            }
            if (!hash_equals(self::keyId($key), substr($preface, strlen(self::MAGIC), 8))) {
                throw new \RuntimeException(basename($path) . ' was made with different settings (another BETTERCAL_SESSION_SECRET), so these settings can\'t open it.');
            }
            // Read before anything is authenticated, so never trusted beyond
            // the size this code writes: a crafted file can't make it read 4 GB.
            $chunkSize = min(unpack('N', substr($preface, -4))[1], self::CHUNK);
            $header = (string) fread($fh, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new \RuntimeException(basename($path) . ' is cut short.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $max = $chunkSize + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            while (true) {
                $len = (string) fread($fh, 4);
                if (strlen($len) !== 4) {
                    throw new \RuntimeException(basename($path) . ' is cut short (no final part).');
                }
                $n = unpack('N', $len)[1];
                if ($n < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $n > $max) {
                    throw new \RuntimeException(basename($path) . ' is damaged.');
                }
                $c = '';
                while (strlen($c) < $n && !feof($fh)) {
                    $c .= (string) fread($fh, $n - strlen($c));
                }
                $out = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $c, $preface);
                if ($out === false) {
                    throw new \RuntimeException(basename($path) . ' is damaged or was changed.');
                }
                [$plain, $tag] = $out;
                $onPlain($plain);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if (fread($fh, 1) !== '') {
                        throw new \RuntimeException(basename($path) . ' has data after its end.');
                    }
                    return;
                }
            }
        } finally {
            if (!is_resource($source)) fclose($fh);
        }
    }
}
