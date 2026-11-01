<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

return static function (Db $db): string {
    $has = (int) $db->scalar(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['plugin_runs', 'calendar_id']
    ) > 0;
    // A run for one of a plugin's calendars (its settings saved, a calendar
    // added, Run on its card) says which; a scheduled run of the whole
    // plugin leaves it null. Only the latter counts towards when the next
    // scheduled run is due (Plugins::dueJobs).
    if (!$has) {
        $db->run('ALTER TABLE plugin_runs ADD COLUMN calendar_id BIGINT UNSIGNED NULL');
    }
    return 'A plugin run for one calendar no longer puts off the scheduled run of the rest.';
};
