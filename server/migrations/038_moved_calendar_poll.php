<?php

declare(strict_types=1);

// A calendar moved to Google (0.9.4) kept the hourly poll it had as a local
// calendar, so changes made at Google took up to an hour to show here. Every
// other Google calendar is checked every five minutes; moved ones still on
// the hourly default join them. One set to anything else was chosen and stays.

use BetterCal\Infra\Db;

return static function (Db $db): string {
    $n = $db->run(
        "UPDATE calendars SET poll_interval_minutes = 5
         WHERE provider = 'google' AND poll_interval_minutes = 60
           AND id IN (SELECT calendar_id FROM calendar_moves WHERE status = 'done')"
    )->rowCount();
    return "moved Google calendars now polled every 5 minutes: $n";
};
