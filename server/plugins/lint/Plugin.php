<?php

declare(strict_types=1);

use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

/**
 * Calendar Lint demo plugin: the warnings-only shape. Reads the next 30 days
 * through the host's occurrence window (worker-side, never a request path)
 * and rewrites its findings. Per-calendar opt-out via calendar settings.
 */
return new class implements PluginInterface {
    public function validateSettings(array $values): array
    {
        if (isset($values['prefStart'], $values['prefEnd']) && $values['prefStart'] >= $values['prefEnd']) {
            return ['prefEnd' => 'must be after the day start'];
        }
        return [];
    }

    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    public function runJob(PluginHost $host, string $jobId): void
    {
        $s = $host->settings();
        $prefStart = (int) ($s['prefStart'] ?? 8);
        $prefEnd = (int) ($s['prefEnd'] ?? 22);
        $maxKm = (float) ($s['maxKm'] ?? 30);
        $checkDupes = (bool) ($s['checkDupes'] ?? true);

        $excluded = [];
        foreach ($host->calendars() as $cal) {
            // Plugin-generated calendars (tides, weather) audit themselves
            // into noise — a 2 AM high tide is not a scheduling mistake.
            if ($cal['kind'] === 'plugin') {
                $excluded[$cal['id']] = true;
                continue;
            }
            $cs = $host->calendarSettings($cal['id']);
            if (!empty($cs['exclude'])) {
                $excluded[$cal['id']] = true;
            }
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $occs = $host->eventsWindow($now->format('Y-m-d\TH:i:sP'), $now->modify('+30 days')->format('Y-m-d\TH:i:sP'));
        $occs = array_values(array_filter($occs, static fn($o) => empty($o['isContainer']) && !isset($excluded[$o['calendarId']])));

        $warnings = [];

        // 1. Impossible back-to-back travel: consecutive located events with a
        // gap too short for the straight-line distance (generous 40 km/h).
        $located = array_values(array_filter($occs, static fn($o) => !$o['allDay'] && $o['lat'] !== null && $o['lng'] !== null));
        // Sort by INSTANT, not by string: occurrence timestamps carry each
        // event's own tz offset, so "2026-09-01T09:00:00-07:00" and
        // "2026-09-01T10:00:00-04:00" are the same moment and lexical order
        // is simply wrong across zones.
        usort($located, static fn($a, $b) => strtotime((string) $a['start']) <=> strtotime((string) $b['start']));
        for ($i = 1; $i < count($located); $i++) {
            $prev = $located[$i - 1];
            $cur = $located[$i];
            $gapMin = (strtotime((string) $cur['start']) - strtotime((string) $prev['end'])) / 60;
            if ($gapMin < 0 || $gapMin > 240) {
                continue;
            }
            $km = self::km((float) $prev['lat'], (float) $prev['lng'], (float) $cur['lat'], (float) $cur['lng']);
            if ($km < $maxKm) {
                continue;
            }
            $needMin = ($km / 40.0) * 60;
            if ($needMin > $gapMin) {
                $warnings[] = [
                    'eventId' => $cur['eventId'],
                    'message' => '"' . $prev['title'] . '" → "' . $cur['title'] . '" is ' . round($km)
                        . ' km with only ' . round($gapMin) . ' min between them (' . substr((string) $cur['start'], 0, 10) . ').',
                    'fix' => 'Add travel time or move one of them.',
                ];
            }
        }

        // 2. Outside preferred hours (timed events on the user's own calendars).
        foreach ($occs as $o) {
            if ($o['allDay']) {
                continue;
            }
            $h = (int) substr((string) $o['start'], 11, 2);
            if ($h < $prefStart || $h >= $prefEnd) {
                $warnings[] = [
                    'eventId' => $o['eventId'],
                    'severity' => 'info',
                    'message' => '"' . $o['title'] . '" starts at ' . substr((string) $o['start'], 11, 5)
                        . ' on ' . substr((string) $o['start'], 0, 10) . ', outside your preferred '
                        . $prefStart . ':00–' . $prefEnd . ':00.',
                ];
            }
            if (count($warnings) > 60) {
                break; // an audit that drowns the list helps nobody
            }
        }

        // 3. Suspected duplicates: same title, same start, different events.
        if ($checkDupes) {
            $byKey = [];
            foreach ($occs as $o) {
                $key = mb_strtolower(trim((string) $o['title'])) . '|' . $o['start'];
                $byKey[$key][] = $o;
            }
            foreach ($byKey as $group) {
                $ids = array_unique(array_map(static fn($o) => $o['eventId'], $group));
                if (count($ids) > 1) {
                    $warnings[] = [
                        'eventId' => $group[0]['eventId'],
                        'message' => 'Possible duplicate: ' . count($ids) . ' events titled "' . $group[0]['title']
                            . '" at ' . str_replace('T', ' ', substr((string) $group[0]['start'], 0, 16)) . '.',
                        'fix' => 'Delete the extra copy if unintended.',
                    ];
                }
            }
        }

        $n = $host->replaceWarnings($warnings);
        $host->log('audited ' . count($occs) . ' occurrences over 30 days: ' . $n . ' findings');
    }
};
