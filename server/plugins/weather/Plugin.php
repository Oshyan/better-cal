<?php

declare(strict_types=1);

use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

/**
 * Weather demo plugin (docs/plugins/prd-v1.md): the materialized-events shape.
 * One Open-Meteo call per refresh, one all-day event per forecast day in a
 * plugin-owned "Weather" calendar, warnings for severe days. Each day carries
 * a host icon for its conditions (sun, cloud, rain, snow, storm), which the
 * day's header shows beside the high/low. With air quality on, a second call
 * adds one "AQI" event per day (the day's highest US AQI) with the air icon.
 */
return new class implements PluginInterface {
    private const CAL_NAME = 'Weather';
    private const CAL_COLOR = '#5b8dd9';

    /** WMO weather codes -> host icon name + words. */
    public static function describe(int $code): array
    {
        return match (true) {
            $code === 0 => ['sun', 'Clear'],
            $code <= 2 => ['sun', 'Mostly clear'],
            $code === 3 => ['cloud', 'Overcast'],
            $code <= 49 => ['cloud', 'Fog'],
            $code <= 57 => ['rain', 'Drizzle'],
            $code <= 67 => ['rain', 'Rain'],
            $code <= 77 => ['snow', 'Snow'],
            $code <= 82 => ['rain', 'Showers'],
            $code <= 86 => ['snow', 'Snow showers'],
            default => ['storm', 'Thunderstorm'],
        };
    }

    /** US AQI -> the EPA category name. */
    public static function aqiCategory(int $aqi): string
    {
        return match (true) {
            $aqi <= 50 => 'Good',
            $aqi <= 100 => 'Moderate',
            $aqi <= 150 => 'Unhealthy for sensitive groups',
            $aqi <= 200 => 'Unhealthy',
            $aqi <= 300 => 'Very unhealthy',
            default => 'Hazardous',
        };
    }

    /**
     * Highest US AQI per local date from Open-Meteo's hourly series.
     *
     * @param list<string> $times local "YYYY-MM-DDTHH:MM"
     * @param list<int|float|null> $values
     * @return array<string,int> date => max AQI
     */
    public static function dailyMaxAqi(array $times, array $values): array
    {
        $out = [];
        foreach ($times as $i => $t) {
            $v = $values[$i] ?? null;
            if ($v === null) {
                continue;
            }
            $d = substr((string) $t, 0, 10);
            $out[$d] = max($out[$d] ?? 0, (int) round((float) $v));
        }
        return $out;
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
        // Shared cache, TTL matched to this plugin's PT3H job interval: it can
        // never serve a forecast staler than our own refresh cadence, and a
        // second plugin wanting this same forecast pays nothing.
        $data = $host->getJsonCached(
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
            [$icon, $words] = self::describe($code);
            $hi = isset($his[$i]) ? (string) round((float) $his[$i]) : '?';
            $lo = isset($los[$i]) ? (string) round((float) $los[$i]) : '?';
            $p = isset($rain[$i]) ? (int) $rain[$i] : null;
            $events[] = [
                'sourceKey' => 'day-' . $date,
                'title' => $hi . '°/' . $lo . '° ' . strtolower($words),
                'start' => (string) $date,
                'allDay' => true,
                'icon' => $icon,
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
        // Air quality: a separate free Open-Meteo service, forecast a few days out.
        if ($s['airQuality'] ?? true) {
            try {
                $aq = $host->getJsonCached(
                    'https://air-quality-api.open-meteo.com/v1/air-quality?' . http_build_query([
                        'latitude' => $loc['lat'],
                        'longitude' => $loc['lng'],
                        'hourly' => 'us_aqi',
                        'timezone' => 'auto',
                        'forecast_days' => min($days, 5),
                    ])
                );
                foreach (self::dailyMaxAqi($aq['hourly']['time'] ?? [], $aq['hourly']['us_aqi'] ?? []) as $date => $aqi) {
                    $cat = self::aqiCategory($aqi);
                    $events[] = [
                        'sourceKey' => 'aqi-' . $date,
                        'title' => 'AQI ' . $aqi . ' (' . $cat . ')',
                        'start' => (string) $date,
                        'allDay' => true,
                        'icon' => 'air',
                        'description' => 'Air quality: highest US AQI ' . $aqi . ' (' . $cat . ') forecast for ' . ($loc['name'] ?? 'your location') . ' via Open-Meteo.',
                    ];
                    if ($aqi > 150) {
                        $warnings[] = ['severity' => 'warn', 'message' => 'Unhealthy air (AQI ' . $aqi . ') forecast for ' . $date . ' at ' . ($loc['name'] ?? 'your location') . '.'];
                    }
                }
            } catch (\Throwable $e) {
                $host->log('air quality unavailable: ' . $e->getMessage()); // the forecast still syncs
            }
        }
        $calId = $host->ensureCalendar(self::CAL_NAME, self::CAL_COLOR);
        [$a, $u, $r] = $host->syncEvents($calId, $events);
        $host->replaceWarnings($warnings);
        $host->log('forecast ' . count($events) . ' days for ' . ($loc['name'] ?? '?') . ": +{$a} ~{$u} -{$r}, " . count($warnings) . ' severe');
    }
};
