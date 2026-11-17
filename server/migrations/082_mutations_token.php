<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Which API key made a change (mutations.token_id, mutations.token_name).
 * Undo::record writes both on every entry recorded under a request signed
 * in with an API key (the web API's bearer key or a CalDAV client's key),
 * the key's id and its name as it was then; an entry made by a browser
 * session, a password or the server's own work leaves them null. The name
 * is kept on the entry because revoking a key deletes its row: the entry
 * still names the key afterwards. No foreign key, for the same reason.
 *
 * Additions only: an entry already here was recorded before this and keeps
 * nulls, which Activity serves as no key (decisions.md, "Which API key made
 * a change is kept on its Activity entry"). Skipped when already there.
 */
return static function (Db $db): string {
    $sqlite = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`mutations`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['mutations']), 'name');
    if (in_array('token_id', $columns, true)) {
        return 'mutations.token_id already present';
    }
    if ($sqlite) {
        $db->run('ALTER TABLE `mutations` ADD COLUMN `token_id` INTEGER NULL');
        $db->run('ALTER TABLE `mutations` ADD COLUMN `token_name` TEXT NULL');
    } else {
        $db->run('ALTER TABLE `mutations` ADD COLUMN `token_id` BIGINT UNSIGNED NULL, ADD COLUMN `token_name` VARCHAR(120) NULL');
    }
    return 'added mutations.token_id and token_name';
};
