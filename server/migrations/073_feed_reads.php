<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * What the last read of each ICS subscription left (Feeds::poll): the
 * feed's ETag and Last-Modified, sent back as If-None-Match and
 * If-Modified-Since, and a hash of the body, so an answer that says nothing
 * changed (a 304, or the same body) is taken without parsing or syncing.
 * The row belongs to one address, named by a keyed hash (url_key), never
 * the address itself, which may carry credentials; a row for another
 * address is never used. parse_key is what the body was read with besides
 * itself (the parser's version, the app's, the Home zone); full_at is the
 * last read in full, at least one a day. A cache: it goes with its
 * calendar, and an empty table only means the next poll reads in full.
 * Skipped when already there.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $db->run('CREATE TABLE IF NOT EXISTS feed_reads (
            calendar_id INTEGER PRIMARY KEY REFERENCES calendars(id) ON DELETE CASCADE,
            url_key TEXT NOT NULL,
            parse_key TEXT NOT NULL,
            etag TEXT NULL,
            last_modified TEXT NULL,
            body_hash TEXT NOT NULL,
            event_count INTEGER NOT NULL DEFAULT 0,
            warning TEXT NULL,
            full_at TEXT NOT NULL
        )');
        return 'feed_reads ready';
    }
    if ((int) $db->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['feed_reads']) > 0) {
        return 'feed_reads already present';
    }
    $db->run('CREATE TABLE feed_reads (
        calendar_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        url_key CHAR(64) NOT NULL,
        parse_key VARCHAR(255) NOT NULL,
        etag VARCHAR(512) NULL DEFAULT NULL,
        last_modified VARCHAR(512) NULL DEFAULT NULL,
        body_hash CHAR(64) NOT NULL,
        event_count INT NOT NULL DEFAULT 0,
        warning TEXT NULL,
        full_at DATETIME NOT NULL,
        CONSTRAINT fk_feed_reads_calendar FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    return 'created feed_reads';
};
