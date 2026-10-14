<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * Applies server/migrations/*.sql and *.php in filename order, tracked in
 * schema_migrations. Idempotent: already-applied files are skipped. A .php
 * migration returns a function taking the Db, for changes SQL can't make
 * (data that needs the application's own code, like the HTML sanitizer).
 *
 * Shared by bin/migrate.php, bin/install.php and bin/update.php.
 */
final class Migrator
{
    public function __construct(
        private readonly Db $db,
        private readonly string $dir,
    ) {
    }

    /** Migration files not yet applied, in the order they would run. @return list<string> */
    public function pending(): array
    {
        $this->ensureTable();
        $applied = array_fill_keys(array_column($this->db->all('SELECT filename FROM schema_migrations'), 'filename'), true);
        return array_values(array_filter($this->files(), static fn(string $f): bool => !isset($applied[basename($f)])));
    }

    /**
     * Apply everything pending. $log gets one line per file. Throws on the
     * first failure, naming the file; later files are left unapplied.
     *
     * @param callable(string):void $log
     * @return int how many were applied
     */
    public function run(callable $log): int
    {
        $ran = 0;
        foreach ($this->pending() as $file) {
            $name = basename($file);
            try {
                if (str_ends_with($name, '.php')) {
                    $fn = require $file;
                    $note = $fn($this->db);
                } else {
                    $sql = file_get_contents($file);
                    if ($sql === false) {
                        throw new \RuntimeException("cannot read $file");
                    }
                    foreach (self::splitStatements($sql) as $statement) {
                        $this->db->pdo()->exec($statement);
                    }
                    $note = null;
                }
                $this->db->run('INSERT INTO schema_migrations (filename) VALUES (?)', [$name]);
            } catch (\Throwable $e) {
                $log("Applying $name ... FAILED");
                throw new \RuntimeException("Migration $name failed: " . $e->getMessage(), 0, $e);
            }
            $log("Applying $name ... ok" . (is_string($note) && $note !== '' ? " ($note)" : ''));
            $ran++;
        }
        return $ran;
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = array_merge(glob($this->dir . '/*.sql') ?: [], glob($this->dir . '/*.php') ?: []);
        sort($files, SORT_STRING);
        return $files;
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                filename VARCHAR(255) PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Split an SQL file into statements on semicolons, respecting quotes and
     * line comments. Sufficient for DDL migration files.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $inString = null;
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($inString !== null) {
                $current .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $current .= $sql[++$i];
                } elseif ($ch === $inString) {
                    $inString = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inString = $ch;
                $current .= $ch;
                continue;
            }
            if (($ch === '-' && ($sql[$i + 1] ?? '') === '-') || $ch === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                $current .= "\n";
                continue;
            }
            if ($ch === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }
        return $statements;
    }
}
