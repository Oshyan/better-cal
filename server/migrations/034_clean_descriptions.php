<?php

declare(strict_types=1);

// Descriptions from feeds, Google, the Takeout import and adopted feeds were
// stored as they arrived and cleaned only when shown or exported (#58). From
// now on every path stores allowlisted HTML; this cleans what is already
// there. Only rows containing markup can change. Each changed event is
// journaled for CalDAV and gets a new updated_at, so clients fetch the clean
// version instead of keeping the old one.

use BetterCal\Dav\ChangeLog;
use BetterCal\Domain\Sanitize;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

return static function (Db $db): string {
    $changed = 0;
    $lastId = 0;
    $now = Time::nowDb();
    while (true) {
        $rows = $db->all(
            "SELECT id, calendar_id, uid, description FROM events WHERE id > ? AND description LIKE '%<%' ORDER BY id LIMIT 500",
            [$lastId]
        );
        if ($rows === []) {
            break;
        }
        foreach ($rows as $row) {
            $lastId = (int) $row['id'];
            $clean = Sanitize::description((string) $row['description']);
            if ($clean === $row['description']) {
                continue;
            }
            $db->run('UPDATE events SET description = ?, updated_at = ? WHERE id = ?', [$clean, $now, $lastId]);
            ChangeLog::record($db, (int) $row['calendar_id'], (string) $row['uid'], ChangeLog::OP_MODIFY);
            $changed++;
        }
    }
    return "$changed description" . ($changed === 1 ? '' : 's') . ' cleaned';
};
