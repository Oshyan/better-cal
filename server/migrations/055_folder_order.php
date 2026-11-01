<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

return static function (Db $db): string {
    $has = static fn(string $table, string $column): bool => (int) $db->scalar(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    ) > 0;
    // Each folder lists its calendars in its own order (#187), or A to Z.
    if (!$has('calendar_folders', 'position')) {
        $db->run('ALTER TABLE calendar_folders ADD COLUMN position INT NOT NULL DEFAULT 0');
        // A folder starts in the order it showed before: the calendars'.
        $rows = $db->all('SELECT cf.folder_id, cf.calendar_id FROM calendar_folders cf JOIN calendars c ON c.id = cf.calendar_id ORDER BY cf.folder_id, c.position, c.id');
        $at = [];
        foreach ($rows as $r) {
            $f = (int) $r['folder_id'];
            $at[$f] = ($at[$f] ?? -1) + 1;
            $db->run('UPDATE calendar_folders SET position = ? WHERE folder_id = ? AND calendar_id = ?', [$at[$f], $f, (int) $r['calendar_id']]);
        }
    }
    if (!$has('folders', 'sort')) {
        $db->run("ALTER TABLE folders ADD COLUMN sort VARCHAR(8) NOT NULL DEFAULT 'manual'");
    }
    return 'Folders keep their own order of calendars, or list them A to Z.';
};
