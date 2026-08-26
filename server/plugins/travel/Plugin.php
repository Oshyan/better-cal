<?php

declare(strict_types=1);

use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

/**
 * Travel Time: the leave-by layer. Walks the next three weeks of located,
 * timed occurrences and turns each back-to-back hop into either a shadow band
 * in front of the arriving event or a warning that the hop cannot be made.
 *
 * Distance is straight-line haversine; there is no routing call anywhere.
 * That is honest for the question this plugin answers first — "is 25 minutes
 * enough to cross 40 km?" is decided by an order of magnitude, and the great
 * circle only ever UNDERSTATES a real trip, so a hop flagged impossible here
 * stays impossible under any real route. It is NOT honest as an ETA, which is
 * why the band copy says so and the stored estimate is never dressed up as a
 * routed time.
 */
return new class implements PluginInterface {
    /** Door-to-door averages, not top speeds: lights, waiting and parking are already priced in. */
    private const SPEEDS_KMH = ['walk' => 5.0, 'bike' => 15.0, 'drive' => 40.0, 'transit' => 25.0];
    /** The bare mode word works on a band chip ("22 min drive"); a sentence wants the preposition form. */
    private const MODE_PHRASE = ['walk' => 'on foot', 'bike' => 'by bike', 'drive' => 'by car', 'transit' => 'by transit'];
    private const DEFAULT_MODE = 'drive';
    private const DEFAULT_COLOR = '#c96f8f';
    /** Past four hours apart a pair is not a transition, it is two separate outings. */
    private const MAX_GAP_MIN = 240;
    private const WINDOW_DAYS = 21;

    public function validateSettings(array $values): array
    {
        $errs = [];
        foreach (['buffer' => 'Buffer', 'minKm' => 'Minimum distance'] as $key => $label) {
            if (isset($values[$key]) && (float) $values[$key] < 0) {
                $errs[$key] = $label . ' cannot be negative';
            }
        }
        // The host drops an unparseable color silently, which reads to the user
        // as "my setting did nothing". Say it here instead of at render time.
        if (($values['color'] ?? '') !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $values['color']) !== 1) {
            $errs['color'] = 'must be a 6-digit hex color like ' . self::DEFAULT_COLOR;
        }
        return $errs;
    }

    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** Occurrence timestamps carry their own event's offset; parse rather than trust the string. */
    private static function at(mixed $iso): ?\DateTimeImmutable
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($iso);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Tenths under 10 km, whole kilometres above: nobody plans around 0.4 km of a 43 km drive. */
    private static function kmLabel(float $km): string
    {
        return $km < 10 ? number_format($km, 1) : (string) (int) round($km);
    }

    public function runJob(PluginHost $host, string $jobId): void
    {
        $s = $host->settings();
        $defaultMode = isset(self::SPEEDS_KMH[(string) ($s['defaultMode'] ?? '')])
            ? (string) $s['defaultMode']
            : self::DEFAULT_MODE;
        $buffer = max(0, (int) round((float) ($s['buffer'] ?? 5)));
        $minKm = max(0.0, (float) ($s['minKm'] ?? 2));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($s['color'] ?? '')) === 1
            ? (string) $s['color']
            : self::DEFAULT_COLOR;

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $occs = $host->eventsWindow(
            $now->format('Y-m-d\TH:i:sP'),
            $now->modify('+' . self::WINDOW_DAYS . ' days')->format('Y-m-d\TH:i:sP')
        );

        // Only things you physically travel to: timed, located, not a trip
        // container (a container spans its children and would pair with them).
        $stops = [];
        foreach ($occs as $o) {
            if (!empty($o['allDay']) || !empty($o['isContainer'])) {
                continue;
            }
            if (($o['lat'] ?? null) === null || ($o['lng'] ?? null) === null) {
                continue;
            }
            $start = self::at($o['start'] ?? null);
            if ($start === null) {
                continue;
            }
            // A missing or unparseable end becomes a zero-length stop rather
            // than a dropped one: the arrival still constrains what follows.
            $stops[] = ['o' => $o, 'start' => $start, 'end' => self::at($o['end'] ?? null) ?? $start];
        }
        usort($stops, static fn(array $a, array $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        $ranges = [];
        $warnings = [];
        $estimated = [];
        $modeByEvent = [];
        $impossibleSoon = 0;
        $soonTs = $now->getTimestamp() + 86400;

        for ($i = 1; $i < count($stops); $i++) {
            if ($host->overBudget()) {
                $host->log('stopped at pair ' . $i . ' of ' . count($stops) . ': out of budget');
                break;
            }
            $prevEv = $stops[$i - 1]['o'];
            $curEv = $stops[$i]['o'];
            $departAt = $stops[$i - 1]['end'];
            $arriveBy = $stops[$i]['start'];

            $gapMin = ($arriveBy->getTimestamp() - $departAt->getTimestamp()) / 60;
            if ($gapMin < 0 || $gapMin > self::MAX_GAP_MIN) {
                continue;
            }

            $km = self::km((float) $prevEv['lat'], (float) $prevEv['lng'], (float) $curEv['lat'], (float) $curEv['lng']);
            $eventId = (int) $curEv['eventId'];

            // One read per event, not per occurrence: a weekly standup expands
            // into three occurrences here and they share one mode override.
            if (!array_key_exists($eventId, $modeByEvent)) {
                $modeByEvent[$eventId] = $host->eventData($eventId)['mode'] ?? null;
            }
            $override = $modeByEvent[$eventId];
            $mode = is_string($override) && isset(self::SPEEDS_KMH[$override]) ? $override : $defaultMode;
            $speed = self::SPEEDS_KMH[$mode] ?? 0.0;
            if ($speed <= 0) {
                continue;
            }

            $travelMinutes = (int) ceil(($km / $speed) * 60) + $buffer;
            // The estimate lands on the event whether or not the hop works, so
            // the detail view can show the same number the warning argues from.
            $host->setEventData($eventId, 'estimate', [
                'minutes' => $travelMinutes,
                'km' => round($km, 1),
                'mode' => $mode,
            ]);
            $estimated[$eventId] = true;

            if ($travelMinutes > $gapMin) {
                $warnings[] = [
                    'eventId' => $eventId,
                    'severity' => 'warn',
                    'message' => '"' . $prevEv['title'] . '" to "' . $curEv['title'] . '" is ' . self::kmLabel($km)
                        . ' km ' . self::MODE_PHRASE[$mode] . ': needs about ' . $travelMinutes
                        . ' min, gap is only ' . (int) round($gapMin) . ' min (' . $arriveBy->format('Y-m-d') . ').',
                    'fix' => 'Move "' . $curEv['title'] . '" later, shorten "' . $prevEv['title']
                        . '", or set a faster mode on the event.',
                ];
                // "Soon" is measured from when you would have to leave, which is
                // the moment the conflict actually bites.
                if ($departAt->getTimestamp() <= $soonTs) {
                    $impossibleSoon++;
                }
                continue;
            }

            if ($km < $minKm || $travelMinutes <= 0) {
                continue; // same block, next room: a band here is clutter
            }
            $leaveBy = $arriveBy->sub(new DateInterval('PT' . $travelMinutes . 'M'));
            $origin = trim((string) ($prevEv['location'] ?? '')) !== ''
                ? (string) $prevEv['location']
                : (string) $prevEv['title'];
            $ranges[] = [
                'sourceKey' => 'leave-' . $eventId . '-' . $arriveBy->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THi'),
                'start' => $leaveBy->format('Y-m-d\TH:i:sP'),
                'end' => $arriveBy->format('Y-m-d\TH:i:sP'),
                'label' => $travelMinutes . ' min ' . $mode,
                'color' => $color,
                'detailHtml' => '<p><strong>Leave ' . htmlspecialchars($origin) . ' by '
                    . $leaveBy->format('g:i A') . '</strong> to reach "' . htmlspecialchars((string) $curEv['title'])
                    . '": ' . self::kmLabel($km) . ' km straight line, about ' . $travelMinutes . ' min '
                    . self::MODE_PHRASE[$mode] . ', including a ' . $buffer . ' min buffer. Roads are longer '
                    . 'than straight lines, so treat this as the latest you could possibly go, not a planned '
                    . 'departure.</p>',
            ];
        }

        // Bands and warnings are wholesale replacements; per-event estimates are
        // not, so an event that moved next door would keep yesterday's "45 min
        // drive" forever. Drop the ones this run did not recompute.
        foreach ($host->eventsWithData('estimate') as $eventId => $_) {
            if (!isset($estimated[$eventId])) {
                $host->setEventData((int) $eventId, 'estimate', null);
            }
        }

        $bandCount = $host->replaceRanges($ranges);
        $warnCount = $host->replaceWarnings($warnings);

        if (($s['notifyImpossible'] ?? true) && $impossibleSoon > 0) {
            $host->notify(
                'Tight travel in the next 24 hours',
                $impossibleSoon === 1
                    ? 'One back-to-back pair needs more travel time than the gap allows.'
                    : $impossibleSoon . ' back-to-back pairs need more travel time than their gaps allow.'
            );
        }

        $host->log(count($stops) . ' located events over ' . self::WINDOW_DAYS . ' days: bands ' . $bandCount
            . ', impossible ' . $warnCount . ' (' . $impossibleSoon . ' within 24h), estimates '
            . count($estimated));
    }
};
