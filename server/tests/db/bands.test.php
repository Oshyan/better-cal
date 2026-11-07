<?php

declare(strict_types=1);

// Plugin bands read while a refresh is in flight: the read is one statement
// per plugin and the refresh one transaction (PluginHost::replaceRanges), so
// a reader gets the set before the refresh or the set after it, whole, never
// part of each.

use BetterCal\Domain\Plugins;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    scenario('bands: a refresh in flight is seen whole or not at all', function () use ($db): void {
        $db->run("INSERT INTO plugins (id, version, enabled) VALUES ('sample-bands', '1.0.0', 1)");
        // One set of the plugin's bands, as a run writes them: a window a day
        // for a fortnight, each labelled with the set it belongs to.
        $write = static function (Db $on, string $set): void {
            for ($i = 1; $i <= 14; $i++) {
                $on->run(
                    "INSERT INTO plugin_ranges (plugin_id, source_key, start_utc, end_utc, label) VALUES ('sample-bands', ?, ?, ?, ?)",
                    ['win-' . $i, sprintf('2032-03-%02d 06:00:00', $i), sprintf('2032-03-%02d 08:00:00', $i), $set]
                );
            }
        };
        $write($db, 'before');
        $plugins = new Plugins($db);
        $window = [new DateTimeImmutable('2032-03-01T00:00:00Z'), new DateTimeImmutable('2032-04-01T00:00:00Z')];
        // Which sets the read holds, and how many bands.
        $read = static function () use ($plugins, $window): array {
            $ranges = $plugins->rangesForWindow(...$window)['sample-bands']['ranges'];
            return [array_values(array_unique(array_column($ranges, 'label'))), count($ranges)];
        };
        // The refresh as a run makes it, the delete and the inserts in one
        // transaction on a connection of its own: read while it holds that
        // transaction, and again once it is in.
        $other = otherConnection();
        $during = null;
        $other->tx(function () use ($other, $write, $read, &$during): void {
            $other->run("DELETE FROM plugin_ranges WHERE plugin_id = 'sample-bands'");
            $write($other, 'after');
            $during = $read();
        });
        checkEq('bands: a refresh in flight is seen whole or not at all: the set before it until it is in, then the set after', [[['before'], 14], [['after'], 14]], [$during, $read()]);
    });
};
