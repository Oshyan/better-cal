<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Search matches each field on its own (docs/design/search-ranking.md): the
 * title and the description each get a FULLTEXT index, since InnoDB's MATCH
 * must name exactly one index's columns. The combined index over title,
 * description and location goes; the address is no longer searched.
 *
 * One ALTER per index, each skipped when already done, so a run cut off
 * part way finishes on the next. InnoDB builds a FULLTEXT index in place
 * but holds writes to the table while it does; at tens of thousands of
 * events that is seconds.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return 'Search indexes are MySQL and MariaDB only.';
    }
    $has = static fn(string $index): bool => (int) $db->scalar(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
        ['events', $index]
    ) > 0;
    if (!$has('ft_events_title')) {
        $db->run('ALTER TABLE events ADD FULLTEXT KEY ft_events_title (title)');
    }
    if (!$has('ft_events_description')) {
        $db->run('ALTER TABLE events ADD FULLTEXT KEY ft_events_description (description)');
    }
    if ($has('ft_events')) {
        $db->run('ALTER TABLE events DROP INDEX ft_events');
    }
    return 'Search matches the title and the description each on its own index; the address is no longer searched.';
};
