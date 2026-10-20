<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * A complete copy of the database, written by PHP itself so it works on any
 * host (shared hosting doesn't always offer mysqldump), and read back by
 * restore() for bin/update.php --rollback. MySQL and MariaDB only.
 *
 * The file is gzip'd SQL with one statement per line: CREATE TABLE has its
 * line breaks folded, and quoted values never contain a raw line break, so
 * restore() needs no SQL parser. It also opens in any MySQL client:
 * gunzip < file.sql.gz | mysql -u USER -p DBNAME
 *
 * It's read in one consistent snapshot, rows streamed (not held in memory),
 * and values that aren't valid UTF-8 are written as hex so nothing is
 * mangled on the way back.
 *
 * Given a key (BackupCipher::keyFrom, from the session secret), the gzip
 * stream is encrypted as it's written (a .bcbackup file, #142): a backup
 * that ends up downloadable is useless to whoever downloads it. restore()
 * reads both kinds; `php server/bin/backup.php --decrypt` turns an encrypted
 * one back into a plain .sql.gz for other tools.
 */
final class DbBackup
{
    private const ROWS_PER_INSERT = 200;
    private const BYTES_PER_INSERT = 1_000_000;

    public function __construct(private readonly Db $db, private readonly ?string $key = null)
    {
    }

    /** With the backup key these settings give (null without a session secret). */
    public static function forConfig(Db $db, array $cfg): self
    {
        $secret = (string) ($cfg['session_secret'] ?? '');
        return new self($db, $secret !== '' ? BackupCipher::keyFrom($secret) : null);
    }

    /** New backups are always encrypted; legacy plaintext remains readable. */
    public function extension(): string
    {
        return BackupCipher::EXTENSION;
    }

    /** @return array{tables:int,rows:int,bytes:int} */
    public function dump(string $path, string $note = ''): array
    {
        if ($this->key === null) {
            throw new \RuntimeException(self::missingSecretMessage());
        }
        PrivatePath::check($path, false, true);
        if (file_exists($path)) {
            throw new \RuntimeException('The backup output already exists.');
        }
        // Readable by this account alone from the moment it exists, whatever
        // folder it lands in.
        $umask = umask(0077);
        try {
            return $this->dumpTo($path, $note);
        } finally {
            umask($umask);
        }
    }

    /** @return array{tables:int,rows:int,bytes:int} */
    private function dumpTo(string $path, string $note): array
    {
        $pdo = $this->db->pdo();
        $tables = [];
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM) as $row) {
            $tables[] = (string) $row[0];
        }
        {
            $cipher = new BackupCipher($path, $this->key);
            $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
            $sink = static function (string $line) use ($cipher, $deflate): void {
                $cipher->write((string) deflate_add($deflate, $line, ZLIB_NO_FLUSH));
            };
            $finish = static function () use ($cipher, $deflate): void {
                $cipher->write((string) deflate_add($deflate, '', ZLIB_FINISH));
                $cipher->close();
            };
        }
        $rows = 0;
        $done = false;
        try {
            $write = static function (string $line) use ($sink): void {
                $sink($line . "\n");
            };
            $write('-- Better-Cal database backup' . ($note !== '' ? ", $note" : '') . ', ' . gmdate('Y-m-d\TH:i:s\Z'));
            $write('-- tables: ' . implode(',', $tables));
            $write('SET NAMES utf8mb4;');
            $write('SET FOREIGN_KEY_CHECKS=0;');
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            foreach ($tables as $table) {
                $q = '`' . str_replace('`', '``', $table) . '`';
                $create = (string) $pdo->query("SHOW CREATE TABLE $q")->fetch(\PDO::FETCH_NUM)[1];
                $write("DROP TABLE IF EXISTS $q;");
                $write(preg_replace('/\s*\R\s*/', ' ', $create) . ';');
                $buffered = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                try {
                    $stmt = $pdo->query("SELECT * FROM $q");
                    $batch = [];
                    $bytes = 0;
                    $columns = null;
                    while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                        $columns ??= '(' . implode(',', array_map(static fn($c) => '`' . str_replace('`', '``', (string) $c) . '`', array_keys($row))) . ')';
                        $values = '(' . implode(',', array_map(fn($v) => $this->literal($v), $row)) . ')';
                        $batch[] = $values;
                        $bytes += strlen($values);
                        $rows++;
                        if (count($batch) >= self::ROWS_PER_INSERT || $bytes >= self::BYTES_PER_INSERT) {
                            $write("INSERT INTO $q $columns VALUES " . implode(',', $batch) . ';');
                            $batch = [];
                            $bytes = 0;
                        }
                    }
                    $stmt->closeCursor();
                    if ($batch !== []) {
                        $write("INSERT INTO $q $columns VALUES " . implode(',', $batch) . ';');
                    }
                } finally {
                    $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
                }
            }
            $pdo->exec('COMMIT');
            $write('SET FOREIGN_KEY_CHECKS=1;');
            $write('-- end');
            $finish();
            $done = true;
        } finally {
            if (!$done) {
                try {
                    $finish();
                } catch (\Throwable) {
                }
                @unlink($path); // a half-written backup is worse than none
            }
        }
        return ['tables' => count($tables), 'rows' => $rows, 'bytes' => (int) filesize($path)];
    }

    /**
     * Put the database back as the backup has it. Tables the backup doesn't
     * list (made by a migration that ran after it) are dropped, so the
     * schema matches the code being restored. Refuses a file that doesn't
     * end the way dump() ends one: a cut-off backup must not half-restore.
     *
     * The file is opened once and every pass reads that handle, so it cannot
     * be swapped between the check and the SQL (review of 0.9.87). A legacy
     * unencrypted backup needs $allowPlain: anyone who could write the backup
     * folder could have made it.
     */
    public function restore(string $path, bool $allowPlain = false): array
    {
        $fh = PrivatePath::open($path, true);
        try {
            if (!$allowPlain && !BackupCipher::isEncrypted($fh)) {
                throw new \RuntimeException(self::plainMessage($path));
            }
            return $this->restoreFrom($fh, $path);
        } finally {
            fclose($fh);
        }
    }

    public static function plainMessage(string $path): string
    {
        return basename($path) . ' is an unencrypted backup from an older version, so it can\'t be checked for changes. Restore it only if you know where it came from; nothing was changed.';
    }

    /** @param resource $fh */
    private function restoreFrom($fh, string $path): array
    {
        // First pass: is it whole? (Read as a stream; a backup can be large.)
        $head = [];
        $last = null;
        $this->eachLine($fh, static function (string $line) use (&$head, &$last): void {
            if (count($head) < 2) {
                $head[] = $line;
            }
            $last = $line;
        });
        if (!str_starts_with($head[0] ?? '', '-- Better-Cal database backup') || $last !== '-- end') {
            throw new \RuntimeException("$path is not a complete Better-Cal backup; nothing was changed");
        }
        $listed = str_starts_with($head[1] ?? '', '-- tables: ') ? array_values(array_filter(explode(',', substr($head[1], 11)))) : [];
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $dropped = 0;
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM) as $row) {
            if (!in_array((string) $row[0], $listed, true)) {
                $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string) $row[0]) . '`');
                $dropped++;
            }
        }
        $statements = 0;
        $this->eachLine($fh, static function (string $line) use ($pdo, &$statements): void {
            if ($line === '' || str_starts_with($line, '-- ')) {
                return;
            }
            $pdo->exec($line);
            $statements++;
        });
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        return ['tables' => count($listed), 'statements' => $statements, 'dropped' => $dropped];
    }

    /**
     * @param resource $fh
     * @param callable(string):void $fn
     */
    private function eachLine($fh, callable $fn): void
    {
        $partial = '';
        $emit = static function (string $bytes) use (&$partial, $fn): void {
            $partial .= $bytes;
            while (($nl = strpos($partial, "\n")) !== false) {
                $fn(substr($partial, 0, $nl));
                $partial = substr($partial, $nl + 1);
            }
        };
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        $unpack = static function (string $chunk) use ($inflate, $emit): void {
            $out = @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);
            if ($out === false) {
                throw new \RuntimeException('The backup is damaged (it doesn\'t unpack).');
            }
            $emit($out);
        };
        if (BackupCipher::isEncrypted($fh)) {
            if ($this->key === null) {
                throw new \RuntimeException('The backup is encrypted, and these settings have no BETTERCAL_SESSION_SECRET to open it.');
            }
            BackupCipher::decrypt($fh, $this->key, $unpack);
        } else {
            // A legacy .sql.gz, read from the same handle.
            rewind($fh);
            while (!feof($fh)) {
                $chunk = fread($fh, 1 << 20);
                if ($chunk === false) {
                    throw new \RuntimeException('Could not read the backup.');
                }
                if ($chunk !== '') $unpack($chunk);
            }
        }
        if ($partial !== '') {
            $fn($partial);
        }
    }

    /**
     * A plain .sql.gz copy of an encrypted backup, for phpMyAdmin or the
     * mysql client (`php server/bin/backup.php --decrypt`). Checks the whole
     * file first, so a damaged one never yields a partial copy.
     */
    public static function decryptFile(string $path, string $key, string $out): void
    {
        if (!BackupCipher::isEncrypted($path)) {
            throw new \RuntimeException(basename($path) . " isn't an encrypted Better-Cal backup.");
        }
        BackupCipher::decrypt($path, $key, static function (string $chunk): void {});
        $old = umask(0077);
        $fh = @fopen($out, 'xb');
        umask($old);
        if ($fh === false) {
            throw new \RuntimeException("Could not create $out (does it already exist?)");
        }
        try {
            BackupCipher::decrypt($path, $key, static function (string $chunk) use ($fh): void {
                if (fwrite($fh, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Writing the plain copy failed (is the disk full?)');
                }
            });
            if (!fclose($fh)) {
                throw new \RuntimeException('Writing the plain copy failed (is the disk full?)');
            }
        } catch (\Throwable $e) {
            @fclose($fh);
            @unlink($out);
            throw $e;
        }
    }

    /**
     * Encrypt a plain backup in place (a .sql.gz from before backups were
     * encrypted): writes the .bcbackup beside it, checks it reads back, then
     * removes the plain one. The gzip bytes are encrypted as they are.
     * Returns the new path.
     */
    public static function encryptFile(string $plainPath, string $key): string
    {
        PrivatePath::check($plainPath, false);
        $out = (string) preg_replace('/\.sql\.gz$/', '', $plainPath) . BackupCipher::EXTENSION;
        PrivatePath::check($out, false, true);
        PrivatePath::directory(dirname($plainPath));
        if (is_file($out)) {
            // Left by a run that was cut short: the plain backup is still
            // here, so that copy was never checked. Start again.
            @unlink($out);
        }
        $mask = umask(0077);
        try {
            $cipher = new BackupCipher($out, $key);
        } finally {
            umask($mask);
        }
        $in = @fopen($plainPath, 'rb');
        try {
            if ($in === false) {
                throw new \RuntimeException('Could not read ' . basename($plainPath));
            }
            while (!feof($in)) {
                $cipher->write((string) fread($in, 1 << 16));
            }
            $cipher->close();
        } catch (\Throwable $e) {
            try {
                $cipher->close();
            } catch (\Throwable) {
            }
            @unlink($out);
            throw $e;
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
        $hashIn = hash_file('sha256', $plainPath);
        $ctx = hash_init('sha256');
        BackupCipher::decrypt($out, $key, static function (string $chunk) use ($ctx): void {
            hash_update($ctx, $chunk);
        });
        if (!hash_equals($hashIn, hash_final($ctx))) {
            @unlink($out);
            throw new \RuntimeException('Encrypting ' . basename($plainPath) . ' did not read back the same; it was left as it is.');
        }
        @chmod($out, 0600);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            // Run as root for a root-owned install's conversion: the copy
            // belongs to whoever owned the original, so a rollback can read it.
            @chown($out, (int) fileowner($plainPath));
            @chgrp($out, (int) filegroup($plainPath));
        }
        @unlink($plainPath);
        return $out;
    }

    public static function missingSecretMessage(): string
    {
        return 'Backups and updates are blocked because BETTERCAL_SESSION_SECRET is missing. '
            . 'In Settings, System, copy the new settings line into .env.php, or run: php server/bin/settings.php --new-secret-line';
    }

    /** A suggestion only, never persisted or applied without the owner's edit. */
    public static function newSecretLine(): string
    {
        return 'BETTERCAL_SESSION_SECRET=' . Setup::secret();
    }

    private function literal(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        $s = (string) $v;
        if (!mb_check_encoding($s, 'UTF-8')) {
            return $s === '' ? "''" : '0x' . bin2hex($s);
        }
        // PDO's quote escapes line breaks, so every statement stays on one line.
        return $this->db->pdo()->quote($s);
    }
}
