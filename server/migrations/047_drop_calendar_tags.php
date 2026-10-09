<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

// Calendar tags are removed: they were accepted and stored but nothing used
// them (#111). The table goes only when it is empty; an install that set
// some through the API keeps them where they are, unused, rather than lose
// them in an upgrade.
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $exists = $driver === 'sqlite'
        ? (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'calendar_tags'") > 0
        : (int) $db->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_tags'") > 0;
    if (!$exists) {
        return 'calendar tags already gone';
    }
    $rows = (int) $db->scalar('SELECT COUNT(*) FROM calendar_tags');
    if ($rows > 0) {
        return "calendar tags are no longer used; kept the {$rows} existing row(s) in calendar_tags instead of deleting them";
    }
    $db->run('DROP TABLE calendar_tags');
    return 'dropped the unused calendar_tags table';
};
