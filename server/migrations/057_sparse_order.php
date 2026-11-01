<?php

declare(strict_types=1);

use BetterCal\Domain\Order;
use BetterCal\Infra\Db;

return static function (Db $db): string {
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
    return 'Calendars and folders keep their order a step apart, so a move changes one row (' . $moved . ' places spaced out).';
};
