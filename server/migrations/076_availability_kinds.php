<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * The kinds of time a person can be marked with are the ones the code
 * accepts (People::KINDS: away, busy, here). The column was made for the
 * first two and never widened when "here" arrived, so marking someone as
 * here failed with a truncation error on every install. The column now
 * lists every kind the code knows; SQLite stores the column as text and
 * needs nothing. Skipped when already wide enough.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return 'availability.kind is text on SQLite';
    }
    $type = (string) $db->scalar(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['availability', 'kind']
    );
    $kinds = \BetterCal\Domain\People::KINDS;
    $missing = array_filter($kinds, static fn(string $k): bool => !str_contains($type, "'" . $k . "'"));
    if ($missing === []) {
        return 'availability.kind already holds every kind';
    }
    $list = implode(',', array_map(static fn(string $k): string => "'" . $k . "'", $kinds));
    $db->run('ALTER TABLE `availability` MODIFY COLUMN `kind` ENUM(' . $list . ") NOT NULL DEFAULT 'away'");
    return 'availability.kind now holds every kind the code accepts (' . implode(', ', $kinds) . ')';
};
