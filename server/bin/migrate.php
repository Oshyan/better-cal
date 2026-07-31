<?php

declare(strict_types=1);

// Applies server/migrations/*.sql in filename order, tracked in schema_migrations.
// Idempotent: already-applied files are skipped.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Infra\Db;

$cfg = config();
$db = new Db($cfg['db']);
$pdo = $db->pdo();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$dir = dirname(__DIR__) . '/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$applied = array_column($db->all('SELECT filename FROM schema_migrations'), 'filename');
$appliedSet = array_fill_keys($applied, true);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($appliedSet[$name])) {
        continue;
    }
    echo "Applying $name ... ";
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "cannot read $file\n");
        exit(1);
    }
    try {
        foreach (bc_split_statements($sql) as $statement) {
            $pdo->exec($statement);
        }
        $db->run('INSERT INTO schema_migrations (filename) VALUES (?)', [$name]);
        echo "ok\n";
        $ran++;
    } catch (\Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

echo $ran === 0 ? "Nothing to migrate.\n" : "Applied $ran migration(s).\n";

/**
 * Split an SQL file into statements on semicolons, respecting quotes and
 * line comments. Sufficient for DDL migration files.
 *
 * @return list<string>
 */
function bc_split_statements(string $sql): array
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
        if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            $current .= "\n";
            continue;
        }
        if ($ch === '#') {
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
