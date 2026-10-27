<?php

declare(strict_types=1);

use BetterCal\Plugin\OwnCalendarsPlugin;
use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

/**
 * Sun: sunrise and sunset, computed locally with PHP's solar math
 * (date_sun_info), so it needs no network permission at all. Each sun
 * calendar is one place (or follows another calendar's place, such as a
 * tide station). Each moment is zero-length in a context calendar: the day's
 * header shows a sunset icon and the time, and the week and day views draw
 * a hairline at the minute.
 */
return new class implements PluginInterface, OwnCalendarsPlugin {

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
        return [];
    }

    public function validateCalendarSettings(array $values): array
    {
        return empty($values['sunrise']) && empty($values['sunset']) ? ['sunrise' => 'Include sunrise, sunset or both'] : [];
    }

    public function runJob(PluginHost $host, string $jobId): void
    {
        foreach ($host->ownCalendars() as $cal) {
            $this->fill($host, $cal);
        }
    }

    /** @param array{id:int,name:string,settings:array<string,mixed>} $cal */
    private function fill(PluginHost $host, array $cal): void
    {
        $s = $cal['settings'];
        $loc = $s['location'] ?? null;
        if (!is_array($loc) || !isset($loc['lat'], $loc['lng'])) {
            // A place that follows another calendar arrives empty until that
            // calendar knows its place (a tide calendar's first run).
            $host->setCalendarStatus($cal['id'], 'No place yet. Pick one in this calendar\'s settings, or wait for the calendar it follows to fill.', 'warn');
            return;
        }
        $days = max(1, min(365, (int) ($s['days'] ?? 21)));
        $kinds = array_filter(['sunrise' => !empty($s['sunrise']), 'sunset' => !empty($s['sunset'])]);
        $place = (string) ($loc['name'] ?? '') ?: 'this place';
        $events = [];
        foreach (self::sunTimes((float) $loc['lat'], (float) $loc['lng'], gmdate('Y-m-d', time() - 86400), $days + 1) as $t) {
            if (!isset($kinds[$t['kind']])) {
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
                'location' => $place,
                'description' => $word . ' at ' . $place . ', computed from its coordinates.',
            ];
        }
        [$a, $u, $r] = $host->syncEvents($cal['id'], $events);
        $host->setCalendarStatus($cal['id'], 'Sunrise and sunset at ' . $place . ' for the next ' . $days . ' days.');
        $host->log($cal['name'] . ': ' . count($events) . " sun moments (+{$a} ~{$u} -{$r})");
    }
};
