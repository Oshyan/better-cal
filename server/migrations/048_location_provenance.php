<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Where an event's coordinates came from, and the geocoder behind each
 * cached lookup. Providers' terms differ on keeping results, so stored
 * coordinates need to say whose they are:
 *
 * - events.location_source: 'picked' (a person chose it: a suggestion in the
 *   location box, or coordinates sent with the event), 'lookup' (Better-Cal's
 *   geocoder resolved the location text), or 'source' (the calendar data
 *   carried it). NULL with coordinates present means unknown: everything
 *   placed before this migration.
 * - events.location_provider: the geocoder that answered ('photon',
 *   'open-meteo', 'airports', ...), NULL when none did or it isn't known.
 * - events.location_placed_at: when the coordinates were set.
 * - geocode_cache.provider: the geocoder behind a cached answer.
 *
 * Existing rows are left unknown rather than guessed.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $hasColumn = static function (string $table, string $column) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            return in_array($column, array_column($db->all('PRAGMA table_info(`' . $table . '`)'), 'name'), true);
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    };
    $added = [];
    $columns = [
        ['events', 'location_source', $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(10) NULL DEFAULT NULL'],
        ['events', 'location_provider', $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(32) NULL DEFAULT NULL'],
        ['events', 'location_placed_at', $driver === 'sqlite' ? 'TEXT NULL' : 'DATETIME NULL DEFAULT NULL'],
        ['geocode_cache', 'provider', $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(32) NULL DEFAULT NULL'],
    ];
    $hasTable = static function (string $table) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            return (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) > 0;
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    };
    foreach ($columns as [$table, $column, $type]) {
        if ($hasTable($table) && !$hasColumn($table, $column)) {
            $db->run("ALTER TABLE `$table` ADD COLUMN `$column` $type");
            $added[] = "$table.$column";
        }
    }
    return $added === [] ? 'location provenance already present' : 'added ' . implode(', ', $added);
};
