<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * A restart-safe schema change for compromise recovery. MySQL commits each
 * ALTER TABLE implicitly, so every column is checked before it is added; an
 * interrupted run can safely start again before schema_migrations is marked.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $hasColumn = static function (string $table, string $column) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            foreach ($db->all('PRAGMA table_info(`' . $table . '`)') as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    };

    $columns = [
        ['sessions', 'authenticated_at', 'DATETIME NULL', 'last_seen_at'],
        ['google_accounts', 'reauth_required_at', 'DATETIME NULL', 'status'],
        ['calendar_moves', 'cancelled_at', 'DATETIME NULL', 'status'],
    ];
    $added = 0;
    foreach ($columns as [$table, $column, $type, $after]) {
        if ($hasColumn($table, $column)) {
            continue;
        }
        $afterSql = $driver === 'sqlite' ? '' : ' AFTER `' . $after . '`';
        $db->run('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $type . $afterSql);
        $added++;
    }

    // Existing browser sessions keep their original sign-in time. Most will
    // therefore need one password confirmation, while a genuinely fresh sign-
    // in still counts without another prompt.
    $db->run('UPDATE sessions SET authenticated_at = created_at WHERE authenticated_at IS NULL');
    return 'compromise recovery columns ensured (' . $added . ' added)';
};
