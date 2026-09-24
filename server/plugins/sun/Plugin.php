<?php

declare(strict_types=1);

use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

/**
 * Sun: sunrise and sunset for one place, computed locally with PHP's solar
 * math (date_sun_info), so it needs no network permission at all. Each is a
 * zero-length moment in a plugin-owned "Sun" calendar, which is a context
 * calendar by default: the day's header shows a sunset icon and the time, and
 * the week and day views draw a hairline at the minute.
 */
return new class implements PluginInterface {
    private const CAL_NAME = 'Sun';
    private const CAL_COLOR = '#e0a33a';

    /**
     * Sunrise and sunset instants for each local day, pure and unit-tested.
     * The solar noon of each date is estimated from longitude, which picks the
     * right day's events anywhere on Earth. Polar days and nights, where the
     * sun does not rise or set, yield nothing for that day.
     *
     * @return list<array{kind:string,at:int,date:string}>
     */
    public static function sunTimes(float $lat, float $lng, string $firstDate, int $days): array
    {
        $out = [];
        $base = (int) strtotime($firstDate . ' 12:00:00 UTC') - (int) round($lng / 15 * 3600);
        for ($i = 0; $i < $days; $i++) {
            $noon = $base + $i * 86400;
            $info = date_sun_info($noon, $lat, $lng);
            $date = gmdate('Y-m-d', $noon + (int) round($lng / 15 * 3600));
            foreach (['sunrise', 'sunset'] as $kind) {
                $at = $info[$kind] ?? null;
                if (is_int($at)) { // true/false mean "always up" / "never up" that day
                    $out[] = ['kind' => $kind, 'at' => $at, 'date' => $date];
                }
            }
        }
        return $out;
    }

    public function validateSettings(array $values): array
    {
        if (array_key_exists('location', $values) && $values['location'] === null) {
            return ['location' => 'a location is required'];
        }
        return [];
    }

    public function runJob(PluginHost $host, string $jobId): void
    {
        $s = $host->settings();
        $loc = $s['location'] ?? null;
        if (!is_array($loc) || !isset($loc['lat'], $loc['lng'])) {
            $host->log('no location configured; nothing to do');
            return;
        }
        $days = max(1, min(60, (int) ($s['days'] ?? 21)));
        $withSunrise = (bool) ($s['sunrise'] ?? true);
        $place = (string) ($loc['name'] ?? 'your location');
        $events = [];
        foreach (self::sunTimes((float) $loc['lat'], (float) $loc['lng'], gmdate('Y-m-d', time() - 86400), $days + 1) as $t) {
            if ($t['kind'] === 'sunrise' && !$withSunrise) {
                continue;
            }
            $iso = gmdate('Y-m-d\TH:i:s\Z', $t['at']);
            $word = $t['kind'] === 'sunrise' ? 'Sunrise' : 'Sunset';
            $events[] = [
                'sourceKey' => $t['kind'] . '-' . $t['date'],
                'title' => $word,
                'start' => $iso,
                'end' => $iso,
                'icon' => $t['kind'],
                'description' => $word . ' at ' . $place . ', computed from its coordinates.',
            ];
        }
        $calId = $host->ensureCalendar(self::CAL_NAME, self::CAL_COLOR);
        [$a, $u, $r] = $host->syncEvents($calId, $events);
        $host->log($place . ': ' . count($events) . " sun moments (+{$a} ~{$u} -{$r})");
    }
};
