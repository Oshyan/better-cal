<?php

declare(strict_types=1);

use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

/**
 * Weather demo plugin (docs/plugins/prd-v1.md): the materialized-events shape.
 * One Open-Meteo call per refresh, one all-day event per forecast day in a
 * plugin-owned "Weather" calendar, warnings for severe days.
 */
return new class implements PluginInterface {
    private const CAL_NAME = 'Weather';
    private const CAL_COLOR = '#5b8dd9';

    /** WMO weather codes -> glyph + words. */
    public static function describe(int $code): array
    {
        return match (true) {
            $code === 0 => ['☀️', 'Clear'],
            $code <= 2 => ['🌤', 'Mostly clear'],
            $code === 3 => ['☁️', 'Overcast'],
            $code <= 49 => ['🌫', 'Fog'],
            $code <= 57 => ['🌦', 'Drizzle'],
            $code <= 67 => ['🌧', 'Rain'],
            $code <= 77 => ['🌨', 'Snow'],
            $code <= 82 => ['🌧', 'Showers'],
            $code <= 86 => ['🌨', 'Snow showers'],
            default => ['⛈', 'Thunderstorm'],
        };
    }

    public function validateSettings(array $values): array
    {
        if (array_key_exists('location', $values) && $values['location'] === null) {
            return ['location' => 'a forecast location is required'];
        }
        return [];
    }

    public function runJob(PluginHost $host, string $jobId): void
    {
        $s = $host->settings();
        $loc = $s['location'] ?? null;
        if (!is_array($loc) || !isset($loc['lat'], $loc['lng'])) {
            $host->log('no forecast location configured; nothing to do');
            $host->replaceWarnings([[
                'severity' => 'info',
                'message' => 'Weather has no forecast location yet. Set one in Manage → Plugins → Weather.',
            ]]);
            return;
        }
        $unitParam = ($s['units'] ?? 'F') === 'C' ? 'celsius' : 'fahrenheit';
        $days = max(1, min(14, (int) ($s['days'] ?? 10)));
        $data = $host->http()->getJson(
            'https://api.open-meteo.com/v1/forecast?' . http_build_query([
                'latitude' => $loc['lat'],
                'longitude' => $loc['lng'],
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                'timezone' => 'auto',
                'forecast_days' => $days,
                'temperature_unit' => $unitParam,
            ])
        );
        $daily = $data['daily'] ?? [];
        $dates = $daily['time'] ?? [];
        $codes = $daily['weather_code'] ?? [];
        $his = $daily['temperature_2m_max'] ?? [];
        $los = $daily['temperature_2m_min'] ?? [];
        $rain = $daily['precipitation_probability_max'] ?? [];

        $events = [];
        $warnings = [];
        foreach ($dates as $i => $date) {
            $code = (int) ($codes[$i] ?? 0);
            [$glyph, $words] = self::describe($code);
            $hi = isset($his[$i]) ? (string) round((float) $his[$i]) : '?';
            $lo = isset($los[$i]) ? (string) round((float) $los[$i]) : '?';
            $p = isset($rain[$i]) ? (int) $rain[$i] : null;
            $events[] = [
                'sourceKey' => 'day-' . $date,
                'title' => $glyph . ' ' . $hi . '°/' . $lo . '°',
                'start' => (string) $date,
                'allDay' => true,
                'description' => $words . '. High ' . $hi . '°, low ' . $lo . '°'
                    . ($p !== null ? ', ' . $p . '% chance of precipitation' : '')
                    . '. Forecast for ' . ($loc['name'] ?? 'your location') . ' via Open-Meteo.',
            ];
            if ($code >= 95) {
                $warnings[] = [
                    'severity' => 'warn',
                    'message' => 'Severe weather (' . $words . ') forecast for ' . $date . ' at ' . ($loc['name'] ?? 'your location') . '.',
                ];
            }
        }
        $calId = $host->ensureCalendar(self::CAL_NAME, self::CAL_COLOR);
        [$a, $u, $r] = $host->syncEvents($calId, $events);
        $host->replaceWarnings($warnings);
        $host->log('forecast ' . count($events) . ' days for ' . ($loc['name'] ?? '?') . ": +{$a} ~{$u} -{$r}, " . count($warnings) . ' severe');
    }
};
