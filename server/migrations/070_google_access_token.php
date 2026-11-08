<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * The Google access token is kept on its account (google_accounts), sealed
 * like the refresh token, with when it expires: the worker is a new process
 * each minute and every web request is its own, so a token held only in
 * memory was refreshed again by each of them. Inside the sealed value is a
 * fingerprint of the refresh grant it came from, so a token from an older
 * grant is never used. Both columns start empty; the first Google call after
 * the update fills them. Skipped when already there.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqlite = $driver === 'sqlite';
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`google_accounts`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['google_accounts']), 'name');
    $added = [];
    if (!in_array('access_token_enc', $columns, true)) {
        $db->run('ALTER TABLE `google_accounts` ADD COLUMN `access_token_enc` ' . ($sqlite ? 'TEXT NULL' : 'TEXT NULL AFTER `refresh_token_enc`'));
        $added[] = 'access_token_enc';
    }
    if (!in_array('access_token_expires_at', $columns, true)) {
        $db->run('ALTER TABLE `google_accounts` ADD COLUMN `access_token_expires_at` ' . ($sqlite ? 'TEXT NULL' : 'DATETIME NULL AFTER `access_token_enc`'));
        $added[] = 'access_token_expires_at';
    }
    return $added === [] ? 'google_accounts access token columns already present' : 'added google_accounts.' . implode(', google_accounts.', $added);
};
