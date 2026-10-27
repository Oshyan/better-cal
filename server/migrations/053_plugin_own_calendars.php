<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Tides, Sun and Weather now take their settings per calendar (one station or
 * place each). A setup from before keeps working: its one calendar gets the
 * plugin's old settings as its own, so it keeps its events and fills as
 * before. Tides used to show every high and low, so both stay on.
 */
return static function (Db $db): string {
    $map = [
        'tides' => static fn(array $s): array => array_filter([
            'station' => is_string($s['station'] ?? null) && trim($s['station']) !== '' ? trim($s['station']) : null,
            'days' => isset($s['days']) ? max(1, min(365, (int) $s['days'])) : null,
        ], static fn($v) => $v !== null) + ['lows' => true, 'highs' => true],
        'sun' => static fn(array $s): array => array_filter([
            'location' => is_array($s['location'] ?? null) ? $s['location'] : null,
            'days' => isset($s['days']) ? (int) $s['days'] : null,
            'sunrise' => isset($s['sunrise']) ? (bool) $s['sunrise'] : null,
        ], static fn($v) => $v !== null),
        'weather' => static fn(array $s): array => array_filter([
            'location' => is_array($s['location'] ?? null) ? $s['location'] : null,
            'units' => isset($s['units']) ? (string) $s['units'] : null,
            'days' => isset($s['days']) ? (int) $s['days'] : null,
            'airQuality' => isset($s['airQuality']) ? (bool) $s['airQuality'] : null,
        ], static fn($v) => $v !== null),
    ];
    $moved = 0;
    foreach ($map as $pluginId => $toOwn) {
        $raw = $db->scalar('SELECT settings_json FROM plugins WHERE id = ?', [$pluginId]);
        $old = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $own = $toOwn(is_array($old) ? $old : []);
        foreach ($db->all("SELECT id, settings_json FROM calendars WHERE kind = 'plugin' AND plugin_id = ?", [$pluginId]) as $cal) {
            $settings = is_string($cal['settings_json']) ? (json_decode($cal['settings_json'], true) ?: []) : [];
            if (isset($settings['own'])) {
                continue;
            }
            $settings['own'] = $own;
            $db->run('UPDATE calendars SET settings_json = ? WHERE id = ?', [json_encode($settings), (int) $cal['id']]);
            $moved++;
        }
    }
    return 'Tides, Sun and Weather calendars keep their station or place as their own settings (calendars moved: ' . $moved . ').';
};
