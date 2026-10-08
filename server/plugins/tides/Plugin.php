<?php

declare(strict_types=1);

use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

/**
 * Tides demo plugin: exercises BOTH output classes — timed high/low events in
 * an owned calendar AND overlay range bands (daylight low-tide windows).
 * NOAA CO-OPS predictions, GMT timestamps, no API key.
 */
return new class implements PluginInterface {
    private const CAL_NAME = 'Tides';
    private const CAL_COLOR = '#2e8b8b';

    public function validateSettings(array $values): array
    {
        // Empty is allowed: it is the default, and it means "not set up yet".
        if (isset($values['station']) && trim((string) $values['station']) !== ''
            && preg_match('/^\d{7}$/', trim((string) $values['station'])) !== 1) {
            return ['station' => 'NOAA station ids are 7 digits (e.g. 8518750 for The Battery, New York)'];
        }
        return [];
    }

    public function runJob(PluginHost $host, string $jobId): void
    {
        $s = $host->settings();
        $station = trim((string) ($s['station'] ?? ''));
        if ($station === '') {
            // No default station: tides only mean something for a coast the
            // user picked. Nothing to fetch until one is set.
            $host->log('No NOAA station set; nothing to fetch.');
            return;
        }
        $name = trim((string) ($s['stationName'] ?? '')) ?: ('station ' . $station);
        $days = max(1, min(30, (int) ($s['days'] ?? 14)));
        $begin = gmdate('Ymd');
        $end = gmdate('Ymd', time() + $days * 86400);
        // Shared cache, TTL matched to this plugin's PT12H job interval.
        // Tide tables are published well ahead, so this is conservative.
        $data = $host->getJsonCached(
            'https://api.tidesandcurrents.noaa.gov/api/prod/datagetter?' . http_build_query([
                'product' => 'predictions',
                'application' => 'better-cal',
                'begin_date' => $begin,
                'end_date' => $end,
                'datum' => 'MLLW',
                'station' => $station,
                'time_zone' => 'gmt',
                'units' => 'english',
                'interval' => 'hilo',
                'format' => 'json',
            ])
        );
        if (isset($data['error'])) {
            throw new \RuntimeException('NOAA: ' . ($data['error']['message'] ?? 'unknown error') . ' (station ' . $station . ')');
        }
        $preds = $data['predictions'] ?? [];
        $tz = $host->timezone();
        $events = [];
        $ranges = [];
        foreach ($preds as $p) {
            $t = (string) ($p['t'] ?? '');          // "2026-08-07 04:33" GMT
            $v = (float) ($p['v'] ?? 0);
            $type = (string) ($p['type'] ?? '');
            if ($t === '') {
                continue;
            }
            $iso = str_replace(' ', 'T', $t) . ':00Z';
            $word = $type === 'H' ? 'High' : 'Low';
            $events[] = [
                'sourceKey' => 'tide-' . $station . '-' . preg_replace('/\D/', '', $t),
                'title' => $word . ' tide ' . number_format($v, 1) . ' ft',
                'icon' => $type === 'H' ? 'tideHigh' : 'tideLow',
                'start' => $iso,
                'end' => $iso,
                'description' => $word . ' tide of ' . number_format($v, 1) . ' ft (MLLW) at ' . $name . '. NOAA station ' . $station . '.',
            ];
            // Daylight low-tide window: a band around lows that land 8:00-18:00
            // in the station's rough local day (approximated by the user's own
            // zone, on the assumption that the station they chose is near
            // them; good enough for a planning glance, labeled as such).
            if ($type === 'L' && ($s['daylightBands'] ?? true)) {
                $ts = strtotime($iso);
                $local = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
                $localHour = (int) $local->format('G');
                if ($localHour >= 8 && $localHour <= 18) {
                    $ranges[] = [
                        'sourceKey' => 'lowwin-' . $station . '-' . gmdate('Ymd-Hi', $ts),
                        'start' => gmdate('Y-m-d\TH:i:s\Z', $ts - 5400),
                        'end' => gmdate('Y-m-d\TH:i:s\Z', $ts + 5400),
                        'label' => 'Low tide ' . number_format($v, 1) . ' ft',
                        'color' => self::CAL_COLOR,
                        'detailHtml' => '<p><strong>Daylight low tide</strong> at ' . htmlspecialchars($name) . ': '
                            . number_format($v, 1) . ' ft around ' . $local->format('g:i A')
                            . ' local. Good tidepooling / beach walking window (±90 min).</p>',
                    ];
                }
            }
        }
        $calId = $host->ensureCalendar(self::CAL_NAME, self::CAL_COLOR);
        [$a, $u, $r] = $host->syncEvents($calId, $events);
        $n = $host->replaceRanges($ranges);
        $host->log($name . ': ' . count($events) . " tide points (+{$a} ~{$u} -{$r}), {$n} daylight low bands");
    }
};
