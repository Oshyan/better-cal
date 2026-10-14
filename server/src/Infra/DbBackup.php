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
 */
final class DbBackup
{
    private const ROWS_PER_INSERT = 200;
    private const BYTES_PER_INSERT = 1_000_000;

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array{tables:int,rows:int,bytes:int} */
    public function dump(string $path, string $note = ''): array
    {
        $pdo = $this->db->pdo();
        $tables = [];
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM) as $row) {
            $tables[] = (string) $row[0];
        }
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException("Could not write the backup to $path");
        }
        $rows = 0;
        try {
            $write = static function (string $line) use ($gz): void {
                if (gzwrite($gz, $line . "\n") === false) {
                    throw new \RuntimeException('Writing the backup failed (is the disk full?)');
                }
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
        } finally {
            gzclose($gz);
        }
        return ['tables' => count($tables), 'rows' => $rows, 'bytes' => (int) filesize($path)];
    }

    /**
     * Put the database back as the backup has it. Tables the backup doesn't
     * list (made by a migration that ran after it) are dropped, so the
     * schema matches the code being restored. Refuses a file that doesn't
     * end the way dump() ends one: a cut-off backup must not half-restore.
     */
    public function restore(string $path): array
    {
        // First pass: is it whole? (Read as a stream; a backup can be large.)
        $head = [];
        $last = null;
        $this->eachLine($path, static function (string $line) use (&$head, &$last): void {
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
        $this->eachLine($path, static function (string $line) use ($pdo, &$statements): void {
            if ($line === '' || str_starts_with($line, '-- ')) {
                return;
            }
            $pdo->exec($line);
            $statements++;
        });
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        return ['tables' => count($listed), 'statements' => $statements, 'dropped' => $dropped];
    }

    /** @param callable(string):void $fn */
    private function eachLine(string $path, callable $fn): void
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException("Could not read $path");
        }
        try {
            $partial = '';
            while (!gzeof($gz)) {
                $chunk = gzgets($gz, 1 << 20);
                if ($chunk === false) {
                    break;
                }
                $partial .= $chunk;
                if (str_ends_with($partial, "\n")) {
                    $fn(rtrim($partial, "\n"));
                    $partial = '';
                }
            }
            if ($partial !== '') {
                $fn($partial);
            }
        } finally {
            gzclose($gz);
        }
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
