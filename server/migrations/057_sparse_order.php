<?php

declare(strict_types=1);

use BetterCal\Domain\Order;
use BetterCal\Infra\Db;

return static function (Db $db): string {
    $sqlite = $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $notes = [];

    // An install that ran 056 before it kept the column changed: what it
    // recorded is a few hours of entries, which fall back to the rule for
    // older ones. The table is made again in its final shape.
    $hasTouchColumn = $sqlite
        ? in_array('touch', array_column($db->all('PRAGMA table_info(mutation_touches)'), 'name'), true)
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['mutation_touches', 'touch']) > 0;
    if ($hasTouchColumn) {
        $db->run('DROP TABLE mutation_touches');
        (require __DIR__ . '/056_mutation_touches.php')($db);
        $notes[] = 'undo records remade';
    }

    // Calendars and folder memberships in sparse order (Order::STEP apart),
    // so a move changes one row. The order itself stays as it was. Done
    // once: a list already spaced that far apart is left alone.
    $spaced = static function (array $rows, string $update, callable $params) use ($db): int {
        $n = 0;
        foreach ($rows as $i => $r) {
            $pos = ($i + 1) * Order::STEP;
            if ((int) $r['position'] !== $pos) {
                $db->run($update, $params($r, $pos));
                $n++;
            }
        }
        return $n;
    };
    $already = static fn(array $rows): bool => count($rows) < 2
        || min(array_map(static fn($a, $b) => (int) $b['position'] - (int) $a['position'], array_slice($rows, 0, -1), array_slice($rows, 1))) >= Order::STEP;
    $moved = 0;
    foreach (array_column($db->all('SELECT DISTINCT user_id FROM calendars'), 'user_id') as $userId) {
        $rows = $db->all('SELECT id, position FROM calendars WHERE user_id = ? ORDER BY position, id', [(int) $userId]);
        if (!$already($rows)) {
            $moved += $spaced($rows, 'UPDATE calendars SET position = ? WHERE id = ?', static fn($r, $p) => [$p, (int) $r['id']]);
        }
    }
    foreach (array_column($db->all('SELECT DISTINCT folder_id FROM calendar_folders'), 'folder_id') as $folderId) {
        $rows = $db->all(
            'SELECT cf.calendar_id, cf.position FROM calendar_folders cf JOIN calendars c ON c.id = cf.calendar_id WHERE cf.folder_id = ? ORDER BY cf.position, c.position, c.id',
            [(int) $folderId]
        );
        if (!$already($rows)) {
            $moved += $spaced($rows, 'UPDATE calendar_folders SET position = ? WHERE folder_id = ? AND calendar_id = ?', static fn($r, $p) => [$p, (int) $folderId, (int) $r['calendar_id']]);
        }
    }
    $notes[] = $moved . ' places spaced out';
    return 'Calendars and folders keep their order a step apart, so a move changes one row (' . implode('; ', $notes) . ').';
};
