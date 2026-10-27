<?php

declare(strict_types=1);

use BetterCal\Plugin\OwnCalendarsPlugin;
use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

/**
 * Tides: NOAA CO-OPS tide predictions (US coasts, no API key). Each tide
 * calendar is one station with its own filters (docs/plugins/tides.md):
 * which tides, how low or high, in daylight or at times of day, on which
 * weekdays, optionally shaded on the calendar.
 *
 * Times: predictions are fetched in GMT, so each event is an exact instant
 * shown in the person's own zone. Filters ("after 7 AM", Saturdays, after
 * sunrise) are judged at the station, in its local time, because that is
 * where the tide happens. Sunrise and sunset come from the station's
 * coordinates.
 */
return new class implements PluginInterface, OwnCalendarsPlugin {
    private const STATIONS_URL = 'https://api.tidesandcurrents.noaa.gov/mdapi/prod/webapi/stations.json?type=tidepredictions';
    private const PREDICTIONS_URL = 'https://api.tidesandcurrents.noaa.gov/api/prod/datagetter';
    private const FT_PER_M = 3.28084;

    public function validateSettings(array $values): array
    {
        return [];
    }

    public function validateCalendarSettings(array $values): array
    {
        if (empty($values['lows']) && empty($values['highs'])) {
            return ['lows' => 'Include low tides, high tides or both'];
        }
        foreach (['low', 'high'] as $kind) {
            if (!empty($values[$kind . 's']) && is_array($values[$kind . 'Days'] ?? null) && $values[$kind . 'Days'] === []) {
                return [$kind . 'Days' => 'Pick at least one day'];
            }
        }
        return [];
    }

    public function runJob(PluginHost $host, string $jobId): void
    {
        $stations = $this->stations($host);
        $calendars = $host->ownCalendars();
        if ($host->targetCalendar() === null) {
            // Bands from before tide calendars had their own belong to no
            // calendar; a full run clears them.
            $host->replaceRanges([]);
        }
        foreach ($calendars as $cal) {
            if ($host->overBudget()) {
                $host->log('Out of time; the rest refresh on the next run.');
                break;
            }
            try {
                $this->fill($host, $cal, $stations);
            } catch (\Throwable $e) {
                // One station failing (NOAA hiccup, a retired station) leaves
                // the calendar's last tides in place and says so on it.
                $host->setCalendarStatus($cal['id'], 'Could not update: ' . $e->getMessage(), 'error');
                $host->log($cal['name'] . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * NOAA's tide stations by id, and the searchable list for the station
     * setting. The list changes rarely: fetched weekly, republished only
     * when it differs.
     *
     * @return array<string,array<string,mixed>>
     */
    private function stations(PluginHost $host): array
    {
        $data = $host->getJsonCached(self::STATIONS_URL, 7 * 86400);
        $byId = [];
        foreach (($data['stations'] ?? []) as $st) {
            if (!is_array($st) || !is_string($st['id'] ?? null) || preg_match('/^\d{7,8}$/', $st['id']) !== 1 || !is_numeric($st['lat'] ?? null) || !is_numeric($st['lng'] ?? null)) {
                continue;
            }
            $byId[$st['id']] = $st;
        }
        if ($byId === []) {
            throw new \RuntimeException('NOAA returned no tide stations');
        }
        $options = self::stationOptions($byId);
        $hash = md5((string) json_encode($options));
        if ($host->kvGet('stationsHash') !== $hash) {
            $host->setSettingOptions('stations', $options);
            $host->kvSet('stationsHash', $hash);
        }
        return $byId;
    }

    /** @return list<array{value:string,label:string}> "Sample Harbor, CA" style labels, sorted */
    public static function stationOptions(array $byId): array
    {
        $out = [];
        foreach ($byId as $id => $st) {
            $out[] = ['value' => (string) $id, 'label' => self::stationName($st)];
        }
        usort($out, static fn($a, $b) => strcmp($a['label'], $b['label']) ?: strcmp($a['value'], $b['value']));
        return $out;
    }

    public static function stationName(array $st): string
    {
        // Older entries put the place, or all of it, in capitals ("SAMPLE HARBOR
        // (North Pier)"); read those words as names.
        $name = (string) preg_replace_callback(
            '/\p{Lu}{3,}/u',
            static fn($m) => mb_convert_case(mb_strtolower($m[0]), MB_CASE_TITLE),
            trim((string) ($st['name'] ?? ''))
        );
        $state = trim((string) ($st['state'] ?? ''));
        return $name . ($state !== '' ? ', ' . $state : '');
    }

    /**
     * The station's local time zone. NOAA lists each station's standard
     * offset but not whether it keeps daylight saving, so: US states and
     * nearby coasts that observe it get the matching US zone, places that
     * don't (Hawaii, Puerto Rico, the Pacific islands) a fixed offset.
     */
    public static function zoneFor(array $st): \DateTimeZone
    {
        $corr = (int) ($st['timezonecorr'] ?? 0);
        $state = strtoupper(trim((string) ($st['state'] ?? '')));
        $lat = (float) ($st['lat'] ?? 0);
        $fixed = ['HI' => 'Pacific/Honolulu', 'PR' => 'America/Puerto_Rico', 'VI' => 'America/St_Thomas', 'GU' => 'Pacific/Guam', 'MP' => 'Pacific/Saipan', 'AS' => 'Pacific/Pago_Pago'];
        if (isset($fixed[$state])) {
            return new \DateTimeZone($fixed[$state]);
        }
        $us = [-5 => 'America/New_York', -6 => 'America/Chicago', -7 => 'America/Denver', -8 => 'America/Los_Angeles', -9 => 'America/Anchorage', -10 => 'America/Adak', -11 => 'America/Adak'];
        if ($state !== '' && isset($us[$corr])) {
            return new \DateTimeZone($us[$corr]);
        }
        // No state: the listed coasts around the US. West coast and Alaska
        // keep daylight saving (British Columbia, Baja); so do the East and
        // Gulf coasts north of the tropics (the Bahamas, the border).
        if (($corr === -8 || $corr === -9) || ($corr === -5 && $lat >= 24) || ($corr === -6 && $lat >= 25)) {
            return new \DateTimeZone($us[$corr]);
        }
        if ($corr === -4 && $lat > 30) {
            return new \DateTimeZone('Atlantic/Bermuda');
        }
        return new \DateTimeZone(sprintf('%+03d:00', $corr));
    }

    /** @param array{id:int,name:string,color:string,settings:array<string,mixed>} $cal */
    private function fill(PluginHost $host, array $cal, array $stations): void
    {
        $s = $cal['settings'];
        $id = (string) ($s['station'] ?? '');
        if ($id === '') {
            $host->syncEvents($cal['id'], []);
            $host->replaceRanges([], $cal['id']);
            $host->setCalendarStatus($cal['id'], 'Pick a station in this calendar\'s settings.', 'warn');
            return;
        }
        $st = $stations[$id] ?? null;
        if ($st === null) {
            $host->setCalendarStatus($cal['id'], 'Station ' . $id . ' is no longer in NOAA\'s list. Pick another.', 'error');
            return;
        }
        $place = self::stationName($st);
        $lat = (float) $st['lat'];
        $lng = (float) $st['lng'];
        $host->setCalendarPlace($cal['id'], ['name' => $place, 'lat' => $lat, 'lng' => $lng]);
        $tz = self::zoneFor($st);
        $unit = ($s['unit'] ?? 'ft') === 'm' ? 'm' : 'ft';
        $days = max(1, min(365, (int) ($s['days'] ?? 90)));
        $today = new \DateTimeImmutable('today', $tz);
        $first = $today->setTime(0, 0);
        $last = $first->modify('+' . $days . ' days');

        // GMT dates that cover the station's days; extra rows are trimmed below.
        $data = $host->getJsonCached(self::PREDICTIONS_URL . '?' . http_build_query([
            'product' => 'predictions',
            'application' => 'better-cal',
            'begin_date' => $first->setTimezone(new \DateTimeZone('UTC'))->format('Ymd'),
            'end_date' => $last->setTimezone(new \DateTimeZone('UTC'))->modify('+1 day')->format('Ymd'),
            'datum' => 'MLLW',
            'station' => $id,
            'time_zone' => 'gmt',
            'units' => $unit === 'm' ? 'metric' : 'english',
            'interval' => 'hilo',
            'format' => 'json',
        ]), 12 * 3600);
        if (isset($data['error'])) {
            throw new \RuntimeException('NOAA: ' . (string) ($data['error']['message'] ?? 'unknown error'));
        }
        $preds = $data['predictions'] ?? [];
        if (!is_array($preds) || count($preds) > ($days + 2) * 6) {
            throw new \RuntimeException('NOAA returned an unexpected list of predictions');
        }

        $events = [];
        $ranges = [];
        $counts = ['L' => 0, 'H' => 0];
        $band = max(0, min(240, (int) ($s['band'] ?? 0)));
        foreach ($preds as $p) {
            $tide = self::parsePrediction($p, $tz);
            if ($tide === null || $tide['ts'] < $first->getTimestamp() || $tide['ts'] >= $last->getTimestamp()) {
                continue;
            }
            $sun = self::sunFor($tide['local'], $lat, $lng);
            if (!self::passes($tide, $s, $sun)) {
                continue;
            }
            $counts[$tide['type']]++;
            $word = $tide['type'] === 'H' ? 'High' : 'Low';
            $height = self::height($tide['v'], $unit);
            $iso = gmdate('Y-m-d\TH:i:s\Z', $tide['ts']);
            $events[] = [
                'sourceKey' => 'tide-' . $id . '-' . gmdate('YmdHi', $tide['ts']),
                'title' => $word . ' tide ' . $height,
                'icon' => $tide['type'] === 'H' ? 'tideHigh' : 'tideLow',
                'start' => $iso,
                'end' => $iso,
                'location' => $place,
                'description' => self::describe($tide, $unit, $place, $id, $sun),
            ];
            if ($band > 0) {
                $ranges[] = [
                    'sourceKey' => 'win-' . gmdate('YmdHi', $tide['ts']),
                    'start' => gmdate('Y-m-d\TH:i:s\Z', $tide['ts'] - $band * 60),
                    'end' => gmdate('Y-m-d\TH:i:s\Z', $tide['ts'] + $band * 60),
                    'label' => $word . ' tide ' . $height,
                    'color' => $cal['color'],
                    'detailHtml' => '<p><strong>' . $word . ' tide ' . htmlspecialchars($height) . '</strong> at ' . htmlspecialchars($place)
                        . ', ' . $tide['local']->format('g:i A') . ' there. Shaded ' . $band . ' minutes either side.</p>',
                ];
            }
        }
        [$a, $u, $r] = $host->syncEvents($cal['id'], $events);
        $host->replaceRanges($ranges, $cal['id']);
        $parts = [];
        if (!empty($s['lows'])) $parts[] = $counts['L'] . ' low';
        if (!empty($s['highs'])) $parts[] = $counts['H'] . ' high';
        $host->setCalendarStatus(
            $cal['id'],
            $events === []
                ? 'No tides at ' . $place . ' match these choices in the next ' . $days . ' days.'
                : implode(' and ', $parts) . ' tides at ' . $place . ' in the next ' . $days . ' days.',
            $events === [] ? 'warn' : 'info'
        );
        $host->log($cal['name'] . ': ' . count($events) . " tides (+{$a} ~{$u} -{$r}), " . count($ranges) . ' bands');
    }

    /** @return ?array{ts:int,local:\DateTimeImmutable,type:string,v:float} one NOAA row ("2026-08-07 04:33" GMT) */
    public static function parsePrediction(mixed $p, \DateTimeZone $tz): ?array
    {
        if (!is_array($p)) {
            return null;
        }
        $t = (string) ($p['t'] ?? '');
        $type = (string) ($p['type'] ?? '');
        if (!in_array($type, ['H', 'L'], true) || !is_numeric($p['v'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $t) !== 1) {
            return null;
        }
        $utc = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $t, new \DateTimeZone('UTC'));
        if ($utc === false) {
            return null;
        }
        return ['ts' => $utc->getTimestamp(), 'local' => $utc->setTimezone($tz), 'type' => $type, 'v' => (float) $p['v']];
    }

    /**
     * Sunrise and sunset on the tide's own day at the station, as instants;
     * true when the sun doesn't set that day, false when it doesn't rise.
     *
     * @return array{rise:int|bool,set:int|bool}
     */
    public static function sunFor(\DateTimeImmutable $local, float $lat, float $lng): array
    {
        // Solar noon of the station's date, as the Sun plugin does: picks
        // that date's sunrise and sunset anywhere on Earth.
        $noon = (int) strtotime($local->format('Y-m-d') . ' 12:00:00 UTC') - (int) round($lng / 15 * 3600);
        $info = date_sun_info($noon, $lat, $lng);
        return ['rise' => $info['sunrise'], 'set' => $info['sunset']];
    }

    /** Does this tide pass its kind's filters? $s is the calendar's settings. */
    public static function passes(array $tide, array $s, array $sun): bool
    {
        $k = $tide['type'] === 'H' ? 'high' : 'low';
        if (empty($s[$k . 's'])) {
            return false;
        }
        // Heights compare in the unit asked for (NOAA returned that unit).
        $limit = $s[$k === 'low' ? 'lowMax' : 'highMin'] ?? null;
        if (is_numeric($limit) && ($k === 'low' ? $tide['v'] > (float) $limit : $tide['v'] < (float) $limit)) {
            return false;
        }
        $daysOk = $s[$k . 'Days'] ?? null;
        if (is_array($daysOk) && !in_array($tide['local']->format('N'), array_map('strval', $daysOk), true)) {
            return false;
        }
        if (!self::inClockWindow($tide['local'], $s[$k . 'From'] ?? null, $s[$k . 'Until'] ?? null)) {
            return false;
        }
        $mode = (string) ($s[$k . 'Sun'] ?? 'any');
        if ($mode === 'any') {
            return true;
        }
        // Polar days: a sun that never sets passes, one that never rises doesn't.
        if ($sun['rise'] === true || $sun['set'] === true) {
            return true;
        }
        if (!is_int($sun['rise']) || !is_int($sun['set'])) {
            return false;
        }
        if (in_array($mode, ['afterSunrise', 'daylight'], true) && $tide['ts'] < $sun['rise'] + 60 * (int) ($s[$k . 'Sunrise'] ?? 0)) {
            return false;
        }
        if (in_array($mode, ['beforeSunset', 'daylight'], true) && $tide['ts'] > $sun['set'] - 60 * (int) ($s[$k . 'Sunset'] ?? 0)) {
            return false;
        }
        return true;
    }

    /** "Not before" / "Not after" at the station; a window that ends before it starts runs across midnight. */
    public static function inClockWindow(\DateTimeImmutable $local, mixed $from, mixed $until): bool
    {
        $m = (int) $local->format('G') * 60 + (int) $local->format('i');
        $toMin = static fn($v) => is_string($v) && preg_match('/^(\d{2}):(\d{2})$/', $v, $x) === 1 ? (int) $x[1] * 60 + (int) $x[2] : null;
        $a = $toMin($from);
        $b = $toMin($until);
        if ($a !== null && $b !== null && $a > $b) {
            return $m >= $a || $m <= $b;
        }
        return ($a === null || $m >= $a) && ($b === null || $m <= $b);
    }

    public static function height(float $v, string $unit): string
    {
        return number_format($v, 1) . ' ' . $unit;
    }

    public static function describe(array $tide, string $unit, string $place, string $id, array $sun): string
    {
        $other = $unit === 'm' ? number_format($tide['v'] * self::FT_PER_M, 1) . ' ft' : number_format($tide['v'] / self::FT_PER_M, 2) . ' m';
        $lines = [
            ($tide['type'] === 'H' ? 'High' : 'Low') . ' tide of ' . self::height($tide['v'], $unit) . ' (' . $other . ') at ' . $place
                . ', ' . $tide['local']->format('g:i A') . ' there. Heights above mean lower low water.',
        ];
        if (is_int($sun['rise']) && is_int($sun['set'])) {
            $tz = $tide['local']->getTimezone();
            $fmt = static fn(int $ts) => (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('g:i A');
            $toSet = $sun['set'] - $tide['ts'];
            $lines[] = 'Sunrise ' . $fmt($sun['rise']) . ', sunset ' . $fmt($sun['set']) . ' (' . ($toSet >= 0 ? self::span($toSet) . ' before sunset' : self::span(-$toSet) . ' after sunset') . ').';
        } elseif ($sun['rise'] === true || $sun['set'] === true) {
            $lines[] = 'The sun doesn\'t set that day.';
        } else {
            $lines[] = 'The sun doesn\'t rise that day.';
        }
        $lines[] = 'NOAA station ' . $id . '.';
        return implode("\n", $lines);
    }

    /** 5400 → "1 h 30 min", 1200 → "20 min". */
    public static function span(int $seconds): string
    {
        $min = (int) round($seconds / 60);
        $h = intdiv($min, 60);
        $m = $min % 60;
        return $h > 0 ? $h . ' h' . ($m > 0 ? ' ' . $m . ' min' : '') : $m . ' min';
    }
};
