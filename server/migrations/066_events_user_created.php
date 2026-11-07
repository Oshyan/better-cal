<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * The change cursor counts an account's events that arrived in the last day
 * (ChangeCursor::events, #16), read on every check an open tab makes, so
 * the count needs an index over (user_id, created_at): without one it read
 * every row of the account. Skipped when already there, so a run cut off
 * part way finishes on the next.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $db->run('CREATE INDEX IF NOT EXISTS idx_events_user_created ON events (user_id, created_at)');
        return 'The change cursor reads recent arrivals by index.';
    }
    $has = (int) $db->scalar(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
        ['events', 'idx_events_user_created']
    ) > 0;
    if (!$has) {
        $db->run('ALTER TABLE events ADD KEY idx_events_user_created (user_id, created_at)');
    }
    return 'The change cursor reads recent arrivals by index.';
};
