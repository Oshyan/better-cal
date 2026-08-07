<?php

declare(strict_types=1);

use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

/**
 * Visit Intents
 *
 * Keeps a wishlist of places you mean to go, and each run works out where a
 * genuinely free stretch of your calendar overlaps the place's open hours for
 * long enough to actually go. Those overlaps are drawn as overlay bands.
 * Nothing is ever booked; the band links out.
 */
return new class implements PluginInterface {

    /** Mo=0 .. Su=6, matching DateTime 'N' minus one. */
    private const DAYS = ['mo' => 0, 'tu' => 1, 'we' => 2, 'th' => 3, 'fr' => 4, 'sa' => 5, 'su' => 6];

    private const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /** At most this many bands on one day, so the week stays readable. */
    private const MAX_PER_DAY = 3;

    /**
     * The host silently truncates a `text` setting to 500 characters on save
     * (verified: 501 in, 500 out, HTTP 200, no error). There is no multi-line
     * or list field type, so the wishlist is split across four boxes and
     * stitched back together here. Ugly, and entirely the cap's fault.
     */
    private const SETTING_TEXT_CAP = 500;

    private const WISHLIST_KEYS = ['wishlist', 'wishlist2', 'wishlist3', 'wishlist4'];

    // ------------------------------------------------------------------ settings

    /**
     * NOTE: $values holds ONLY the keys being saved, not the merged settings —
     * a PATCH of one field arrives here as a one-key array while the store
     * merges it over everything else. So every check must be guarded on the
     * key being present, or changing one field trips a required-field error on
     * a field the user never touched.
     */
    public function validateSettings(array $values): array
    {
        $errors = [];

        foreach (self::WISHLIST_KEYS as $k) {
            if (!array_key_exists($k, $values)) {
                continue;
            }
            $raw = trim((string)$values[$k]);
            if ($raw === '') {
                continue;   // an empty continuation box is normal
            }
            if (mb_strlen($raw) > self::SETTING_TEXT_CAP) {
                // The host truncates to 500 silently. Refuse instead, so the
                // user finds out now rather than by losing half their list.
                $errors[$k] = 'Too long by ' . (mb_strlen($raw) - self::SETTING_TEXT_CAP)
                    . ' characters — this box holds ' . self::SETTING_TEXT_CAP
                    . '. Move the overflow to the next box.';
                continue;
            }
            $parsed = $this->parseWishlist($raw);
            if (!$parsed['places']) {
                $first = $parsed['errors'][0] ?? 'no usable lines';
                $errors[$k] = 'Nothing usable in that box — ' . $first;
            }
            // Individual bad lines are NOT a save-blocker: they come back
            // as warnings on the ops page after the next run.
        }

        if (array_key_exists('color', $values)) {
            $color = trim((string)$values['color']);
            if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $errors['color'] = 'Use a #rrggbb hex colour, e.g. #8a6fd4';
            }
        }

        // Cross-field rules can only be checked when both sides are in the
        // same save. Anything else is enforced again in runJob.
        if (array_key_exists('dayStart', $values) && array_key_exists('dayEnd', $values)
            && (int)$values['dayEnd'] <= (int)$values['dayStart']) {
            $errors['dayEnd'] = 'Has to be later than the earliest hour';
        }

        return $errors;
    }

    // --------------------------------------------------------------------- job

    public function runJob(PluginHost $host, string $jobId): void
    {
        if ($jobId !== 'scan') {
            $host->log("ignoring unknown job '{$jobId}'");
            return;
        }

        $tz = $host->timezone();
        $s  = $host->settings();

        $horizon  = $this->clampInt($s['horizonDays']   ?? 21, 3, 60,  21);
        $dayStart = $this->clampInt($s['dayStart']      ?? 9,  0, 23,  9);
        $dayEnd   = $this->clampInt($s['dayEnd']        ?? 21, 1, 24,  21);
        $lead     = $this->clampInt($s['minLeadHours']  ?? 12, 0, 168, 12);
        $buffer   = $this->clampInt($s['bufferMinutes'] ?? 30, 0, 120, 30);
        $maxPer   = $this->clampInt($s['maxPerPlace']   ?? 2,  1, 10,  2);
        $proposeN = $this->clampInt($s['proposeTop']    ?? 1,  0, 5,   1);
        $policy   = (string)($s['allDayPolicy'] ?? 'away');
        if (!in_array($policy, ['away', 'all', 'none'], true)) {
            $policy = 'away';
        }
        $weekOnly  = !empty($s['weekendsOnly']);
        $lookup    = !array_key_exists('lookupHours', $s) || !empty($s['lookupHours']);
        $notifyNew = !array_key_exists('notifyNew', $s) || !empty($s['notifyNew']);
        $color     = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($s['color'] ?? ''))
            ? strtolower((string)$s['color'])
            : '#8a6fd4';
        if ($dayEnd <= $dayStart) {
            $dayStart = 9;
            $dayEnd   = 21;
        }

        $warnings = [];

        // ---- 1. the wishlist -------------------------------------------------
        $blob = [];
        foreach (self::WISHLIST_KEYS as $k) {
            $part = trim((string)($s[$k] ?? ''));
            if ($part !== '') {
                $blob[] = $part;
            }
        }
        $parsed = $this->parseWishlist(implode("\n", $blob));
        $places = $parsed['places'];
        foreach (array_slice($parsed['errors'], 0, 10) as $e) {
            $warnings[] = [
                'message'  => 'Visit list: ' . $e,
                'severity' => 'warn',
                'fix'      => 'One place per line: Name | Where | minutes | hours | before:YYYY-MM-DD | url:https://…',
            ];
        }
        if (!$places) {
            $host->replaceRanges([]);
            $host->replaceWarnings($warnings);
            $host->log('no usable places in the visit list — nothing to do');
            return;
        }

        // ---- 2. fill in missing hours ---------------------------------------
        $places = $this->resolveHours($host, $places, $lookup, $warnings);

        $usable = array_values(array_filter($places, static fn(array $p): bool => $p['hours'] !== null));
        if (!$usable) {
            $host->replaceRanges([]);
            $host->replaceWarnings($warnings);
            $host->log(count($places) . ' places, none with usable hours');
            return;
        }

        // ---- 3. the window ---------------------------------------------------
        $now       = new DateTimeImmutable('now', $tz);
        $winStart  = $now->setTime(0, 0);
        $winEnd    = $now->modify('+' . $horizon . ' days')->setTime(23, 59, 59);
        $earliest  = $now->getTimestamp() + $lead * 3600;

        $occurrences = $host->eventsWindow(
            $winStart->format(DateTimeInterface::ATOM),
            $winEnd->format(DateTimeInterface::ATOM)
        );

        // Which calendars do not count as "busy"?
        $skipCal = [];
        $calNames = [];
        foreach ($host->calendars() as $cal) {
            $cid = (int)($cal['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $calNames[$cid] = (string)($cal['name'] ?? '');
            if (($cal['kind'] ?? '') === 'plugin') {
                $skipCal[$cid] = 'plugin-owned';
                continue;
            }
            $cs = $host->calendarSettings($cid);
            if (!empty($cs['ignore'])) {
                $skipCal[$cid] = 'user-excluded';
            }
        }

        // Events the user has told us do not really block time.
        $notBusy = [];
        foreach ($host->eventsWithData('notBusy') as $eid => $v) {
            if (!empty($v)) {
                $notBusy[(int)$eid] = true;
            }
        }

        // ---- 4. busy intervals ----------------------------------------------
        $busy         = [];
        $blockedDates = [];
        $blockers     = [];
        $matchWant    = [];
        $matchedDates = [];   // placeKey => [Y-m-d => true]
        $skippedPlugin = 0;
        $skippedUser   = 0;
        $skippedNotBusy = 0;

        foreach ($occurrences as $o) {
            $cid = (int)($o['calendarId'] ?? 0);
            $eid = (int)($o['eventId'] ?? 0);

            // Match against the wishlist BEFORE any skipping, but only on real
            // calendars — a plugin's own band is not evidence that you are going.
            if (!isset($skipCal[$cid])) {
                $hay = $this->normalise(((string)($o['title'] ?? '')) . ' ¦ ' . ((string)($o['location'] ?? '')));
                foreach ($usable as $p) {
                    if ($p['needle'] !== '' && str_contains($hay, $p['needle'])) {
                        $matchWant[$eid] = $p['name'];
                        $d = $o['allDay']
                            ? substr((string)$o['start'], 0, 10)
                            : (new DateTimeImmutable((string)$o['start']))->setTimezone($tz)->format('Y-m-d');
                        $matchedDates[$p['key']][$d] = true;
                        break;
                    }
                }
            }

            if (isset($skipCal[$cid])) {
                $skipCal[$cid] === 'plugin-owned' ? $skippedPlugin++ : $skippedUser++;
                continue;
            }
            if (isset($notBusy[$eid])) {
                $skippedNotBusy++;
                continue;
            }

            if (!empty($o['allDay'])) {
                if ($policy === 'none') {
                    continue;
                }
                // All-day timestamps are literal calendar dates pinned at +00:00.
                // Slicing the string is the ONLY safe read; converting zones
                // slides Saturday into Friday west of UTC.
                $d0 = substr((string)$o['start'], 0, 10);
                $d1 = substr((string)($o['end'] ?? ''), 0, 10);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d0)) {
                    continue;
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) {
                    $d1 = $d0;
                }
                $spanDays = max(1, (int)round((strtotime($d1 . ' 00:00:00 UTC') - strtotime($d0 . ' 00:00:00 UTC')) / 86400));
                $isAway   = !empty($o['isContainer']) || $spanDays >= 2;
                if ($policy === 'away' && !$isAway) {
                    // A one-day all-day row is a chore, a birthday, a weather
                    // tile — not "I am away". Do not let it eat the day.
                    continue;
                }
                $span = min($spanDays, 90);
                for ($i = 0; $i < $span; $i++) {
                    $blockedDates[(new DateTimeImmutable($d0 . ' 00:00:00', new DateTimeZone('UTC')))
                        ->modify('+' . $i . ' days')->format('Y-m-d')] = true;
                }
                if ($spanDays >= 2) {
                    // Big blockers deserve to be named — otherwise a nine-day
                    // house guest silently removes a third of the horizon and
                    // the user never learns why.
                    $blockers[$eid] = [
                        'title' => (string)($o['title'] ?? 'Untitled'),
                        'days'  => $span,
                        'from'  => $d0,
                        'cal'   => $calNames[$cid] ?? '',
                    ];
                }
                continue;
            }

            $st = strtotime((string)($o['start'] ?? ''));
            $en = strtotime((string)($o['end'] ?? ''));
            if ($st === false) {
                continue;
            }
            if ($en === false || $en < $st) {
                $en = $st;
            }
            $busy[] = [$st - $buffer * 60, $en + $buffer * 60];
        }

        $busy = $this->mergeIntervals($busy);

        // ---- 5. free stretches, day by day ----------------------------------
        $freeByDate = [];
        $cursor = $winStart;
        for ($i = 0; $i <= $horizon; $i++) {
            $date = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');

            if (isset($blockedDates[$date])) {
                continue;
            }
            $dow = (int)(new DateTimeImmutable($date . ' 12:00:00', $tz))->format('N') - 1;
            if ($weekOnly && $dow < 5) {
                continue;
            }

            $base = new DateTimeImmutable($date . ' 00:00:00', $tz);
            $ws = $base->setTime($dayStart, 0)->getTimestamp();
            $we = $dayEnd >= 24
                ? $base->modify('+1 day')->setTime(0, 0)->getTimestamp()
                : $base->setTime($dayEnd, 0)->getTimestamp();
            $ws = max($ws, $earliest);
            if ($ws >= $we) {
                continue;
            }
            $free = $this->subtract([$ws, $we], $busy);
            if ($free) {
                $freeByDate[$date] = ['dow' => $dow, 'base' => $base, 'free' => $free];
            }
        }

        // ---- 6. opportunities ------------------------------------------------
        $opps = [];
        $today = $now->format('Y-m-d');
        foreach ($usable as $p) {
            if ($p['before'] !== null && $p['before'] < $today) {
                $warnings[] = [
                    'message'  => $p['name'] . ': the "before ' . $p['before'] . '" date has passed, so it is off the list.',
                    'severity' => 'info',
                    'fix'      => 'Delete the line, or move the before: date if it is still on.',
                ];
                continue;
            }
            // Already going there at some point in the window? Then this is a
            // standing intent that is currently satisfied. Offer at most one
            // alternative and rank it well below everything else, rather than
            // pushing a place the user has clearly already dealt with.
            $booked = !empty($matchedDates[$p['key']]);
            $cap    = $booked ? 1 : $maxPer;

            $found = 0;
            foreach ($freeByDate as $date => $d) {
                if ($found >= $cap) {
                    break;
                }
                if (isset($matchedDates[$p['key']][$date])) {
                    continue;   // already going there that day
                }
                if ($p['before'] !== null && $date > $p['before']) {
                    continue;
                }
                $spans = $p['hours'][$d['dow']] ?? [];
                if (!$spans) {
                    continue;
                }
                $need = $p['minMinutes'] * 60;
                $best = null;
                foreach ($d['free'] as [$fs, $fe]) {
                    foreach ($spans as [$oms, $ome]) {
                        $os = $this->minsToTs($d['base'], $oms);
                        $oe = $this->minsToTs($d['base'], $ome);
                        $cs = max($fs, $os);
                        $ce = min($fe, $oe);
                        if ($ce - $cs < $need) {
                            continue;
                        }
                        $cs = (int)(ceil($cs / 900) * 900);   // tidy 15-minute start
                        if ($ce - $cs < $need) {
                            continue;
                        }
                        $cand = [
                            'start'   => $cs,
                            'end'     => $cs + $p['durMinutes'] * 60,
                            'openEnd' => $ce,
                            'slack'   => (int)round(($ce - $cs - $need) / 60),
                        ];
                        if ($cand['end'] > $ce) {
                            $cand['end'] = $ce;
                        }
                        if ($best === null || $cand['start'] < $best['start']) {
                            $best = $cand;
                        }
                    }
                }
                if ($best === null) {
                    continue;
                }
                $daysOut  = (int)floor(($best['start'] - $now->getTimestamp()) / 86400);
                $urgency  = 0;
                if ($p['before'] !== null) {
                    $left = (int)floor((strtotime($p['before'] . ' 23:59:59 UTC') - $now->getTimestamp()) / 86400);
                    $urgency = max(0, 30 - $left);
                }
                // A place open 168 hours a week can wait; one open on three
                // afternoons is the whole reason this plugin exists.
                $scarcity = (1.0 - min(1.0, $this->weeklyOpenHours($p) / 84.0)) * 8.0;

                $score = $urgency * 2.0
                    + ($d['dow'] >= 5 ? 5.0 : 0.0)
                    + $scarcity
                    + min(3.0, $best['slack'] / 60)
                    + max(0.0, 14 - $daysOut) * 0.5
                    - ($booked ? 12.0 : 0.0);

                $opps[] = [
                    'place' => $p,
                    'date'  => $date,
                    'dow'   => $d['dow'],
                    'start' => $best['start'],
                    'end'   => $best['end'],
                    'slack' => $best['slack'],
                    'score' => $score,
                    'key'   => 'visit-' . $p['key'] . '-' . $date,
                ];
                $found++;
            }

            if ($found === 0 && $p['before'] !== null) {
                $warnings[] = [
                    'message'  => $p['name'] . ' has to happen before ' . $p['before']
                        . ' and there is no free stretch of ' . $this->humanMinutes($p['minMinutes'])
                        . ' inside its open hours between now and then.',
                    'severity' => 'warn',
                    'fix'      => 'Widen your day, cut the breathing room, or drop the deadline.',
                ];
            }
        }

        usort($opps, static function (array $a, array $b): int {
            return ($b['score'] <=> $a['score']) ?: ($a['start'] <=> $b['start']);
        });

        // Keep any one day readable: best few only.
        $perDay = [];
        $opps = array_values(array_filter($opps, static function (array $o) use (&$perDay): bool {
            $n = ($perDay[$o['date']] ?? 0) + 1;
            $perDay[$o['date']] = $n;
            return $n <= self::MAX_PER_DAY;
        }));

        // ---- 7. bands --------------------------------------------------------
        $ranges = [];
        foreach ($opps as $op) {
            $ranges[] = [
                'sourceKey'  => $op['key'],
                'start'      => $this->iso($op['start'], $tz),
                'end'        => $this->iso($op['end'], $tz),
                'label'      => 'Go: ' . $op['place']['name'],
                'color'      => $color,
                'detailHtml' => $this->detail($op, $tz),
            ];
        }
        usort($ranges, static fn(array $a, array $b): int => strcmp($a['start'], $b['start']));
        $rangeCount = $host->replaceRanges($ranges);

        // ---- 8. per-event "you are already going" marks -----------------------
        $marked = 0;
        $cleared = 0;
        $existing = $host->eventsWithData('matched');
        foreach ($matchWant as $eid => $name) {
            if ((string)($existing[$eid] ?? '') !== $name) {
                $host->setEventData((int)$eid, 'matched', $name);
                $marked++;
            }
        }
        foreach ($existing as $eid => $_v) {
            if (!isset($matchWant[(int)$eid])) {
                $host->setEventData((int)$eid, 'matched', null);
                $cleared++;
            }
        }

        // ---- 9. informational warnings ---------------------------------------
        foreach ($usable as $p) {
            if (!empty($matchedDates[$p['key']])) {
                $days = array_keys($matchedDates[$p['key']]);
                sort($days);
                $warnings[] = [
                    'message'  => $p['name'] . ' is already on your calendar '
                        . $this->prettyDate($days[0], $tz)
                        . (count($days) > 1 ? ' (+' . (count($days) - 1) . ' more)' : '')
                        . ' — not suggesting that day.',
                    'severity' => 'info',
                    'fix'      => null,
                ];
            }
        }
        foreach ($blockers as $b) {
            $warnings[] = [
                'message'  => '"' . mb_substr($b['title'], 0, 90) . '" runs ' . $b['days']
                    . ' days from ' . $this->prettyDate($b['from'], $tz)
                    . ', so those days are treated as time away and nothing is suggested in them.',
                'severity' => 'info',
                'fix'      => 'If you are actually around, open that event and tick "This event does not really block my time".',
            ];
        }
        if (!$opps) {
            $warnings[] = [
                'message'  => 'No openings in the next ' . $horizon . ' days for any of your '
                    . count($usable) . ' places. ' . count($freeByDate) . ' of those days had any free time at all.',
                'severity' => 'info',
                'fix'      => 'Lower the breathing room, widen your hours, or shorten a visit.',
            ];
        }
        $warnCount = $host->replaceWarnings($warnings);

        // ---- 10. quiet unless something changed -------------------------------
        // Two different questions, two different keys.
        //   $keys    — "which place on which day", for notification dedupe.
        //              A slot moving 90 minutes is not worth pinging about.
        //   $shape   — the same plus the actual times, for "did anything move".
        //              If it did, an open proposal should be refreshed in place.
        $keys = array_map(static fn(array $o): string => $o['key'], $opps);
        sort($keys);
        $shape = array_map(static fn(array $o): string => $o['key'] . '@' . $o['start'] . '-' . $o['end'], $opps);
        sort($shape);
        $fp   = md5(json_encode($shape) . '|' . count($usable));
        $prev = (string)($host->kvGet('fp') ?? '');
        $changed = $fp !== $prev;

        $announced = $host->kvGet('announced');
        $announced = is_array($announced) ? $announced : [];
        $fresh = array_values(array_diff($keys, $announced));

        // Both are COMMITTED AT THE END OF THE RUN, not here. A run that throws
        // keeps whatever it already wrote — there is no rollback — so writing
        // "I have announced these" before actually announcing them would lose a
        // notification permanently on any later failure.

        // ---- 11. proposals ----------------------------------------------------
        $proposed = 0;
        $stale = 0;
        if ($proposeN > 0 && $opps) {
            $decided = [];
            $open    = [];
            foreach ($host->myProposals(null) as $pr) {
                $k = (string)($pr['sourceKey'] ?? '');
                if (($pr['status'] ?? '') === 'open') {
                    $open[$k] = true;
                } else {
                    $decided[$k] = true;
                }
            }

            // Always offer the best N, so a place with a closing deadline can
            // displace yesterday's pick. Anything previously offered that is
            // no longer in that set just sits there: the host gives a plugin
            // no way to withdraw its own open proposal, only to replace one by
            // re-using its sourceKey. Count them so the ops page shows the
            // drift rather than hiding it. Keeping proposeTop low is the only
            // real defence.
            $pick = [];
            foreach ($opps as $op) {
                if (count($pick) >= $proposeN) {
                    break;
                }
                if (isset($decided[$op['key']])) {
                    continue;   // they already said yes or no; do not ask again
                }
                $pick[$op['key']] = $op;
            }
            foreach ($open as $k => $_) {
                if (!isset($pick[$k])) {
                    $stale++;
                }
            }

            foreach ($pick as $op) {
                if (!$changed && isset($open[$op['key']])) {
                    continue;   // nothing moved, do not churn the proposal
                }
                $host->propose([
                    'sourceKey'     => $op['key'],
                    'title'         => 'Go to ' . $op['place']['name'] . ' on ' . $this->prettyDate($op['date'], $tz),
                    'summary'       => $this->humanMinutes((int)round(($op['end'] - $op['start']) / 60))
                        . ' free from ' . $this->clock($op['start'], $tz) . '. '
                        . self::DAY_NAMES[$op['dow']] . ' hours: '
                        . $this->describeHours($op['place'], $op['dow']) . '.',
                    'rationaleHtml' => $this->detail($op, $tz),
                    'plan'          => [
                        'events' => [[
                            'title'    => 'Visit ' . $op['place']['name'],
                            'start'    => $this->iso($op['start'], $tz),
                            'end'      => $this->iso($op['end'], $tz),
                            'location' => $op['place']['where'] !== '' ? $op['place']['where'] : $op['place']['name'],
                        ]],
                    ],
                ]);
                $proposed++;
            }
        }

        // ---- 12. notify, at most once per genuinely new thing ------------------
        $notified = 0;
        if ($notifyNew && $fresh && $changed) {
            $top = null;
            foreach ($opps as $op) {
                if (in_array($op['key'], $fresh, true)) {
                    $top = $op;
                    break;
                }
            }
            if ($top !== null) {
                $extra = count($fresh) - 1;
                $host->notify(
                    'A window opened for ' . $top['place']['name'],
                    $this->prettyDate($top['date'], $tz) . ' at ' . $this->clock($top['start'], $tz)
                    . ' — ' . $this->humanMinutes((int)round(($top['end'] - $top['start']) / 60)) . ' free'
                    . ($extra > 0 ? ', plus ' . $extra . ' other new opening' . ($extra === 1 ? '' : 's') . '.' : '.'),
                    '/'
                );
                $notified = 1;
            }
        }

        // ---- 13. only now is it safe to remember what we did ------------------
        $host->kvSet('fp', $fp);
        $host->kvSet('announced', $keys);

        $host->log(sprintf(
            '%d places (%d with hours), %d occurrences [%d plugin, %d excluded, %d marked not-busy], '
            . '%d days with free time, %d openings → %d bands, %d warnings, %d proposals (%d stale, cannot withdraw), %d notify; '
            . 'event marks +%d -%d; %s',
            count($places),
            count($usable),
            count($occurrences),
            $skippedPlugin,
            $skippedUser,
            $skippedNotBusy,
            count($freeByDate),
            count($opps),
            $rangeCount,
            $warnCount,
            $proposed,
            $stale,
            $notified,
            $marked,
            $cleared,
            $changed ? 'changed since last run' : 'unchanged, stayed quiet'
        ));
    }

    // ------------------------------------------------------------- wishlist

    /** @return array{places: array<int,array>, errors: array<int,string>} */
    private function parseWishlist(string $raw): array
    {
        $raw = trim($raw);
        $places = [];
        $errors = [];

        $records = [];
        if ($raw !== '' && $raw[0] === '[') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                foreach ($j as $row) {
                    if (is_string($row)) {
                        $records[] = $row;
                    } elseif (is_array($row)) {
                        $records[] = implode(' | ', [
                            (string)($row['name'] ?? ''),
                            (string)($row['where'] ?? $row['location'] ?? ''),
                            (string)($row['minutes'] ?? $row['duration'] ?? ''),
                            (string)($row['hours'] ?? ''),
                            isset($row['before']) ? 'before:' . $row['before'] : '',
                            isset($row['url']) ? 'url:' . $row['url'] : '',
                            isset($row['note']) ? 'note:' . $row['note'] : '',
                        ]);
                    }
                }
            } else {
                $errors[] = 'that looked like JSON but would not parse';
            }
        }
        if (!$records) {
            // Newlines if the field gives us any; ";;" as an escape hatch for a
            // single-line input box.
            $records = preg_split('/\r\n|\r|\n|;;/', $raw) ?: [];
        }

        $line = 0;
        foreach ($records as $rec) {
            $line++;
            $rec = trim((string)$rec);
            if ($rec === '' || $rec[0] === '#') {
                continue;
            }
            $f = array_map('trim', explode('|', $rec));
            $name = $f[0] ?? '';
            if ($name === '') {
                $errors[] = "line {$line} has no name";
                continue;
            }
            $where = $f[1] ?? '';
            $mins  = 90;
            if (isset($f[2]) && $f[2] !== '') {
                if (!preg_match('/^\d+$/', $f[2])) {
                    $errors[] = "line {$line} (" . $name . '): "' . mb_substr($f[2], 0, 40) . '" is not a number of minutes';
                    continue;
                }
                $mins = max(15, min(600, (int)$f[2]));
            }
            $hoursRaw = $f[3] ?? '';

            $before = null;
            $url    = '';
            $note   = '';
            $minM   = null;
            for ($i = 4; $i < count($f); $i++) {
                $x = $f[$i];
                if ($x === '') {
                    continue;
                }
                if (stripos($x, 'http://') === 0 || stripos($x, 'https://') === 0) {
                    $url = $x;
                    continue;
                }
                $pos = strpos($x, ':');
                if ($pos === false) {
                    $note = $note === '' ? $x : $note . ' ' . $x;
                    continue;
                }
                $k = strtolower(trim(substr($x, 0, $pos)));
                $v = trim(substr($x, $pos + 1));
                switch ($k) {
                    case 'before':
                    case 'by':
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                            $before = $v;
                        } else {
                            $errors[] = "line {$line} ({$name}): before: needs YYYY-MM-DD, got \"" . mb_substr($v, 0, 20) . '"';
                        }
                        break;
                    case 'url':
                    case 'link':
                        $url = $v;
                        break;
                    case 'min':
                        if (preg_match('/^\d+$/', $v)) {
                            $minM = max(15, min(600, (int)$v));
                        }
                        break;
                    case 'note':
                        $note = $v;
                        break;
                    default:
                        $note = $note === '' ? $x : $note . ' ' . $x;
                }
            }
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                $url = 'https://' . $url;
            }

            $key = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
            $key = trim($key, '-');
            if ($key === '') {
                $key = substr(md5($name), 0, 10);
            }

            $places[] = [
                'key'        => $key,
                'name'       => mb_substr($name, 0, 120),
                'where'      => mb_substr($where, 0, 200),
                'durMinutes' => $mins,
                'minMinutes' => $minM ?? $mins,
                'hoursRaw'   => $hoursRaw,
                'hours'      => $hoursRaw === '' ? null : $this->parseHours($hoursRaw),
                'hoursFrom'  => $hoursRaw === '' ? 'missing' : 'you',
                'before'     => $before,
                'url'        => $url,
                'note'       => mb_substr($note, 0, 300),
                // Too-short names ("Bar", "Zoo") would match half the calendar.
                'needle'     => mb_strlen($this->normalise($name)) >= 6 ? $this->normalise($name) : '',
                'line'       => $line,
            ];
        }

        // De-duplicate on key, first wins.
        $seen = [];
        $out  = [];
        foreach ($places as $p) {
            if (isset($seen[$p['key']])) {
                continue;
            }
            $seen[$p['key']] = true;
            $out[] = $p;
        }

        return ['places' => $out, 'errors' => $errors];
    }

    /**
     * Fill in hours we do not have: cache → OpenStreetMap → the model → give up
     * loudly. The user's own hours always win; nothing here overwrites them.
     */
    private function resolveHours(PluginHost $host, array $places, bool $lookup, array &$warnings): array
    {
        $httpTries = 0;
        $llmTries  = 0;

        foreach ($places as $i => $p) {
            if ($p['hours'] !== null) {
                continue;
            }

            $cacheKey = 'hours-' . substr(md5($p['name'] . '|' . $p['where'] . '|' . $p['hoursRaw']), 0, 16);
            $cached = $host->kvGet($cacheKey);
            $missAge = PHP_INT_MAX;
            if (is_array($cached) && isset($cached['spec'])) {
                if ($cached['spec'] === '') {
                    // Remembered miss. kv has no TTL, so the age is in the value.
                    $missAge = time() - (int)($cached['at'] ?? 0);
                } else {
                    $h = $this->parseHours((string)$cached['spec']);
                    if ($h !== null) {
                        $places[$i]['hours']     = $h;
                        $places[$i]['hoursRaw']  = (string)$cached['spec'];
                        $places[$i]['hoursFrom'] = (string)($cached['from'] ?? 'remembered');
                        continue;
                    }
                }
            }
            $recentMiss = $missAge < 7 * 86400;

            // (a) user typed something we could not read → ask the model to
            //     rewrite it in opening_hours syntax. One call per run, tops.
            if ($p['hoursRaw'] !== '' && !$recentMiss && $llmTries === 0 && $this->budgetOk($host, 48)) {
                $llmTries++;
                $spec = $this->llmHours($host, $p['hoursRaw']);
                if ($spec !== null) {
                    $h = $this->parseHours($spec);
                    if ($h !== null) {
                        $host->kvSet($cacheKey, ['spec' => $spec, 'from' => 'your own wording', 'at' => time()]);
                        $places[$i]['hours']     = $h;
                        $places[$i]['hoursRaw']  = $spec;
                        $places[$i]['hoursFrom'] = 'read back from your wording';
                        $warnings[] = [
                            'message'  => $p['name'] . ': read "' . mb_substr($p['hoursRaw'], 0, 60) . '" as "' . $spec . '". Check that is right.',
                            'severity' => 'info',
                            'fix'      => 'Paste that back into the line to make it exact.',
                        ];
                        continue;
                    }
                }
            }

            // (b) nothing typed at all → try OpenStreetMap.
            // One lookup per run, and only with real budget left: the free
            // Overpass endpoints regularly take 20 s or 504 outright, and the
            // whole job only gets 60 s.
            if ($p['hoursRaw'] === '' && $lookup && !$recentMiss && $httpTries === 0 && $this->budgetOk($host, 30)) {
                $httpTries++;
                $spec = $this->osmHours($host, $p);
                $host->kvSet($cacheKey, ['spec' => $spec ?? '', 'from' => 'OpenStreetMap', 'at' => time()]);
                if ($spec !== null) {
                    $h = $this->parseHours($spec);
                    if ($h !== null) {
                        $places[$i]['hours']     = $h;
                        $places[$i]['hoursRaw']  = $spec;
                        $places[$i]['hoursFrom'] = 'OpenStreetMap';
                        $warnings[] = [
                            'message'  => $p['name'] . ': hours came from OpenStreetMap ("' . $spec . '"). Crowd-sourced, so treat as a hint.',
                            'severity' => 'info',
                            'fix'      => 'Type the real hours into the line to stop the lookup.',
                        ];
                        continue;
                    }
                }
            }

            $warnings[] = [
                'message'  => $p['name'] . ': ' . ($p['hoursRaw'] === ''
                        ? 'no opening hours on the line, and OpenStreetMap has none for it either, so it is skipped.'
                        : 'could not read the opening hours "' . mb_substr($p['hoursRaw'], 0, 60)
                          . '", so it is skipped.'),
                'severity' => 'warn',
                'fix'      => 'Add hours in the 4th field, e.g. "Tu-Su 11:00-17:00; Th 11:00-20:00", or "24/7".',
            ];
        }

        return $places;
    }

    /**
     * Hours from OpenStreetMap via Overpass (free, no key, no signup).
     *
     * Two routes, because the host geocoder returns null more often than not:
     * if we get coordinates we search a radius around them, otherwise we fall
     * back to searching inside the named city. Crowd-sourced data, so the
     * result is always reported to the user as a hint, never as fact.
     */
    private function osmHours(PluginHost $host, array $p): ?string
    {
        $needle = trim(preg_replace('/[^A-Za-z0-9 ]+/', '.', $p['name']) ?? '');
        if ($needle === '') {
            return null;
        }

        $lat = null;
        $lng = null;
        foreach ($this->geoQueries($p) as $q) {
            try {
                $geo = $host->geocode($q);
            } catch (\Throwable $e) {
                $host->log('geocode threw for "' . $q . '": ' . mb_substr($e->getMessage(), 0, 100));
                continue;
            }
            if (isset($geo['lat'], $geo['lng']) && ((float)$geo['lat'] !== 0.0 || (float)$geo['lng'] !== 0.0)) {
                $lat = (float)$geo['lat'];
                $lng = (float)$geo['lng'];
                $host->log('geocode "' . $q . '" → ' . $lat . ',' . $lng);
                break;
            }
            $host->log('geocode "' . $q . '" → ' . json_encode($geo));
        }

        if ($lat !== null) {
            $ql = sprintf(
                '[out:json][timeout:20];nwr(around:400,%.5f,%.5f)["opening_hours"]["name"~"%s",i];out tags 3;',
                $lat,
                $lng,
                $needle
            );
        } else {
            $city = $this->cityOf($p['where']);
            if ($city === '') {
                return null;
            }
            $ql = sprintf(
                '[out:json][timeout:20];area["name"="%s"]["boundary"="administrative"]->.a;'
                . 'nwr(area.a)["opening_hours"]["name"~"%s",i];out tags 3;',
                $city,
                $needle
            );
            $host->log('no coordinates, searching OSM inside "' . $city . '" for "' . $needle . '"');
        }

        try {
            $json = $host->http()->getJson('https://overpass-api.de/api/interpreter?data=' . rawurlencode($ql));
        } catch (\Throwable $e) {
            $host->log('overpass failed for ' . $p['name'] . ': ' . mb_substr($e->getMessage(), 0, 140));
            return null;
        }
        $els = is_array($json) ? ($json['elements'] ?? []) : [];
        $host->log('overpass → ' . count($els) . ' hit(s) for "' . $needle . '"');
        foreach ($els as $el) {
            $oh = $el['tags']['opening_hours'] ?? null;
            if (is_string($oh) && trim($oh) !== '') {
                return mb_substr(trim($oh), 0, 200);
            }
        }
        return null;
    }

    /** @return array<int,string> */
    private function geoQueries(array $p): array
    {
        $out = [];
        if ($p['where'] !== '') {
            $out[] = $p['name'] . ', ' . $p['where'];
            $out[] = $p['where'];
        }
        $out[] = $p['name'];
        return array_values(array_unique($out));
    }

    private function cityOf(string $where): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $where))));
        if (!$parts) {
            return '';
        }
        $last = end($parts);
        // "San Francisco CA" / "Oakland, CA" → "San Francisco" / "Oakland"
        $last = trim(preg_replace('/\s+[A-Z]{2}$/', '', $last) ?? $last);
        if ($last === '' || preg_match('/^\d/', $last)) {
            return count($parts) > 1 ? trim($parts[count($parts) - 2]) : '';
        }
        return $last;
    }

    private function llmHours(PluginHost $host, string $raw): ?string
    {
        try {
            $out = $host->llmJson(
                "Rewrite this description of a venue's opening hours into OpenStreetMap opening_hours syntax.\n"
                . "Use two-letter day codes Mo Tu We Th Fr Sa Su, ranges with '-', lists with ',', 24-hour HH:MM times, "
                . "rules separated by '; '. Example output: \"Tu-Su 11:00-17:00; Th 11:00-20:00\". Use \"24/7\" if always open.\n"
                . "If you cannot tell, return an empty string.\n\nInput: " . mb_substr($raw, 0, 300),
                ['spec' => 'string, OSM opening_hours syntax, or empty string if unclear']
            );
            if (is_array($out)) {
                $spec = $out['spec'] ?? ($out['opening_hours'] ?? null);
                if (is_string($spec) && trim($spec) !== '') {
                    return mb_substr(trim($spec), 0, 200);
                }
            }
        } catch (\Throwable $e) {
            $host->log('hours normalisation via model failed: ' . mb_substr($e->getMessage(), 0, 120));
        }
        return null;
    }

    // --------------------------------------------------------- opening_hours

    /**
     * A deliberate subset of OSM opening_hours: day selectors, time spans,
     * "off", "24/7", multiple rules. Anything with a month/week/holiday
     * selector returns null rather than being silently half-understood.
     *
     * @return array<int,array<int,array{0:int,1:int}>>|null  dow(0=Mo) => spans in minutes
     */
    private function parseHours(string $spec): ?array
    {
        $spec = trim($spec);
        if ($spec === '') {
            return null;
        }
        $norm = strtolower(preg_replace('/\s+/', ' ', $spec) ?? '');
        $norm = str_replace(['–', '—', '−'], '-', $norm);

        if ($norm === '24/7' || $norm === '24x7' || $norm === 'always') {
            $all = [];
            for ($d = 0; $d < 7; $d++) {
                $all[$d] = [[0, 1440]];
            }
            return $all;
        }

        $out = [];
        foreach (explode(';', $norm) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if (preg_match('/^(ph|sh)\b/', $rule)) {
                continue;   // public/school holidays: ignore, do not fail
            }
            if (preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|week|easter|sunrise|sunset|dusk|dawn)\b/', $rule)) {
                return null;   // out of subset — say so instead of guessing
            }

            // Leading day selector, if any.
            $days = null;
            if (preg_match('/^((?:mo|tu|we|th|fr|sa|su)(?:\s*-\s*(?:mo|tu|we|th|fr|sa|su))?(?:\s*,\s*(?:mo|tu|we|th|fr|sa|su)(?:\s*-\s*(?:mo|tu|we|th|fr|sa|su))?)*)\s*(.*)$/', $rule, $m)) {
                $days = $this->expandDays($m[1]);
                $rest = trim($m[2]);
            } else {
                $rest = $rule;
            }
            if ($days === null) {
                $days = [0, 1, 2, 3, 4, 5, 6];
            }
            if ($days === []) {
                return null;
            }

            if ($rest === '' || $rest === 'off' || $rest === 'closed') {
                foreach ($days as $d) {
                    $out[$d] = [];
                }
                continue;
            }
            if ($rest === '24/7' || $rest === '00:00-24:00') {
                foreach ($days as $d) {
                    $out[$d] = [[0, 1440]];
                }
                continue;
            }

            $spans = [];
            foreach (explode(',', $rest) as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') {
                    continue;
                }
                if (!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*-\s*(\d{1,2})(?::(\d{2}))?$/', $chunk, $t)) {
                    return null;
                }
                $a = ((int)$t[1]) * 60 + (int)($t[2] ?? 0);
                $b = ((int)$t[3]) * 60 + (int)($t[4] ?? 0);
                if ($b <= $a) {
                    $b = 1440;   // crosses midnight: clamp, we never suggest past bedtime anyway
                }
                $a = max(0, min(1440, $a));
                $b = max(0, min(1440, $b));
                if ($b > $a) {
                    $spans[] = [$a, $b];
                }
            }
            if (!$spans) {
                return null;
            }
            foreach ($days as $d) {
                $out[$d] = $spans;   // later rules override, as OSM does
            }
        }

        $any = false;
        foreach ($out as $spans) {
            if ($spans) {
                $any = true;
                break;
            }
        }
        return $any ? $out : null;
    }

    /** @return array<int,int>|null */
    private function expandDays(string $sel): ?array
    {
        $days = [];
        foreach (explode(',', $sel) as $part) {
            $part = trim($part);
            if (preg_match('/^(mo|tu|we|th|fr|sa|su)\s*-\s*(mo|tu|we|th|fr|sa|su)$/', $part, $m)) {
                $a = self::DAYS[$m[1]];
                $b = self::DAYS[$m[2]];
                for ($i = 0; $i < 7; $i++) {
                    $d = ($a + $i) % 7;
                    $days[$d] = $d;
                    if ($d === $b) {
                        break;
                    }
                }
            } elseif (isset(self::DAYS[$part])) {
                $days[self::DAYS[$part]] = self::DAYS[$part];
            } else {
                return null;
            }
        }
        return array_values($days);
    }

    private function weeklyOpenHours(array $p): float
    {
        $mins = 0;
        foreach (($p['hours'] ?? []) as $spans) {
            foreach ($spans as [$a, $b]) {
                $mins += ($b - $a);
            }
        }
        return $mins / 60.0;
    }

    private function closingMinute(array $p, int $dow): int
    {
        $spans = $p['hours'][$dow] ?? [];
        $max = 0;
        foreach ($spans as [$a, $b]) {
            $max = max($max, $b);
        }
        return $max ?: 1440;
    }

    private function describeHours(array $p, int $dow): string
    {
        $spans = $p['hours'][$dow] ?? [];
        if (!$spans) {
            return 'closed';
        }
        if (count($spans) === 1 && $spans[0][0] === 0 && $spans[0][1] === 1440) {
            return 'open all day';
        }
        $bits = [];
        foreach ($spans as [$a, $b]) {
            $bits[] = $this->hm($a) . '–' . $this->hm($b);
        }
        return implode(', ', $bits);
    }

    private function hm(int $mins): string
    {
        $mins = min(1439, max(0, $mins));
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        $ap = $h >= 12 ? 'pm' : 'am';
        $h12 = $h % 12 === 0 ? 12 : $h % 12;
        return $m === 0 ? ($h12 . $ap) : sprintf('%d:%02d%s', $h12, $m, $ap);
    }

    // ------------------------------------------------------------ interval math

    /** @param array<int,array{0:int,1:int}> $in */
    private function mergeIntervals(array $in): array
    {
        if (!$in) {
            return [];
        }
        usort($in, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [];
        $cur = $in[0];
        foreach (array_slice($in, 1) as $iv) {
            if ($iv[0] <= $cur[1]) {
                $cur[1] = max($cur[1], $iv[1]);
            } else {
                $out[] = $cur;
                $cur = $iv;
            }
        }
        $out[] = $cur;
        return $out;
    }

    /** @param array{0:int,1:int} $win */
    private function subtract(array $win, array $busy): array
    {
        $free = [];
        $cur = $win[0];
        foreach ($busy as [$bs, $be]) {
            if ($be <= $cur) {
                continue;
            }
            if ($bs >= $win[1]) {
                break;
            }
            if ($bs > $cur) {
                $free[] = [$cur, min($bs, $win[1])];
            }
            $cur = max($cur, $be);
            if ($cur >= $win[1]) {
                break;
            }
        }
        if ($cur < $win[1]) {
            $free[] = [$cur, $win[1]];
        }
        return array_values(array_filter($free, static fn(array $f): bool => $f[1] > $f[0]));
    }

    private function minsToTs(DateTimeImmutable $base, int $mins): int
    {
        if ($mins >= 1440) {
            return $base->modify('+1 day')->setTime(0, 0)->getTimestamp();
        }
        return $base->setTime(intdiv($mins, 60), $mins % 60)->getTimestamp();
    }

    // ---------------------------------------------------------------- output

    private function detail(array $op, DateTimeZone $tz): string
    {
        $p = $op['place'];
        $e = static fn(string $x): string => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');

        $len = (int)round(($op['end'] - $op['start']) / 60);
        $h  = '<p><strong>' . $e($p['name']) . '</strong> — '
            . $e($this->humanMinutes($len)) . ', '
            . $e($this->clock($op['start'], $tz)) . '–' . $e($this->clock($op['end'], $tz))
            . ' on ' . $e($this->prettyDate($op['date'], $tz)) . '.</p>';

        $why = [];
        $why[] = 'You are free here — no events, and it clears your '
            . $this->humanMinutes($p['minMinutes']) . ' minimum.';
        $why[] = self::DAY_NAMES[$op['dow']] . ' hours are ' . $this->describeHours($p, $op['dow'])
            . ($op['slack'] > 0 ? ', so there is ' . $this->humanMinutes($op['slack']) . ' of slack either side.' : '.');
        $wk = (int)round($this->weeklyOpenHours($p));
        if ($wk > 0 && $wk <= 45) {
            $why[] = 'They are only open about ' . $wk . ' hours a week, which is why this is worth catching.';
        }
        if ($p['before'] !== null) {
            $why[] = 'You wanted this before ' . $this->prettyDate($p['before'], $tz) . '.';
        }
        if ($op['dow'] >= 5) {
            $why[] = 'It is a weekend.';
        }
        $h .= '<p>' . $e(implode(' ', $why)) . '</p>';

        $li = [];
        if ($p['where'] !== '') {
            $li[] = 'Where: ' . $e($p['where']);
        }
        if ($p['note'] !== '') {
            $li[] = $e($p['note']);
        }
        if ($p['hoursFrom'] !== 'you') {
            $li[] = 'Hours from ' . $e($p['hoursFrom']) . ' — worth a check.';
        }
        if ($li) {
            $h .= '<ul><li>' . implode('</li><li>', $li) . '</li></ul>';
        }

        $links = [];
        if ($p['url'] !== '') {
            $links[] = '<a href="' . $e($p['url']) . '" target="_blank" rel="noopener">Booking / info</a>';
        }
        $mapQ = $p['where'] !== '' ? ($p['name'] . ', ' . $p['where']) : $p['name'];
        $links[] = '<a href="https://www.google.com/maps/search/?api=1&amp;query='
            . $e(rawurlencode($mapQ)) . '" target="_blank" rel="noopener">Map</a>';
        $h .= '<p>' . implode(' &middot; ', $links) . '</p>';


        $h .= '<p><em>An opening, not a booking. Nothing is reserved and nothing is on your calendar.</em></p>';
        return $h;
    }

    // ---------------------------------------------------------------- helpers

    private function iso(int $ts, DateTimeZone $tz): string
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format(DateTimeInterface::ATOM);
    }

    private function clock(int $ts, DateTimeZone $tz): string
    {
        $d = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
        return $d->format('i') === '00' ? $d->format('ga') : $d->format('g:ia');
    }

    private function prettyDate(string $ymd, DateTimeZone $tz): string
    {
        return (new DateTimeImmutable($ymd . ' 12:00:00', $tz))->format('D j M');
    }

    private function humanMinutes(int $m): string
    {
        if ($m < 60) {
            return $m . ' min';
        }
        $h = intdiv($m, 60);
        $r = $m % 60;
        return $r === 0 ? ($h . 'h') : ($h . 'h ' . $r . 'm');
    }

    private function normalise(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    private function clampInt(mixed $v, int $lo, int $hi, int $def): int
    {
        if (!is_numeric($v)) {
            return $def;
        }
        return max($lo, min($hi, (int)$v));
    }

    private function budgetOk(PluginHost $host, float $need): bool
    {
        try {
            $left = $host->budgetRemaining();
        } catch (\Throwable $e) {
            return false;
        }
        return is_numeric($left) && (float)$left >= $need;
    }
};
