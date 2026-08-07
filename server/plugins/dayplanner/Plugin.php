<?php

declare(strict_types=1);

use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

/**
 * Day Planner demo plugin: the proposal shape. Finds the soonest weekend day
 * that is genuinely open and offers a small outing for it.
 *
 * It declares no events:write and calls no write API — propose() is the only
 * thing it creates with, and a proposal is inert until the user accepts it.
 * That is what makes a planner safe to run every day: it may be wrong, change
 * its mind, or repeat itself, and the calendar still only moves when the user
 * says so.
 */
return new class implements PluginInterface {
    /** ISO8601 with an explicit offset; never a bare local time. */
    private const ISO = 'Y-m-d\TH:i:sP';
    private const MAX_ACTIVITIES = 3;

    /**
     * The plan used whenever the model gives us nothing usable. Shaped exactly
     * like a validated model row so the plan builder has one code path.
     */
    private const FALLBACK = [
        ['title' => 'Morning walk', 'start' => '09:30', 'minutes' => 60, 'location' => ''],
        ['title' => 'Lunch out', 'start' => '12:30', 'minutes' => 75, 'location' => ''],
        ['title' => 'Afternoon outing', 'start' => '15:00', 'minutes' => 120, 'location' => ''],
    ];

    public function validateSettings(array $values): array
    {
        $errs = [];
        // The schema already enforces type and range; whole days and whole
        // events are the part it cannot express.
        foreach (['horizonDays', 'busyThreshold'] as $k) {
            if (array_key_exists($k, $values) && (float) $values[$k] !== floor((float) $values[$k])) {
                $errs[$k] = 'must be a whole number';
            }
        }
        if (array_key_exists('area', $values) && mb_strlen(trim((string) $values['area'])) > 120) {
            $errs['area'] = 'keep it short: a city or neighborhood, not an itinerary';
        }
        return $errs;
    }

    public function runJob(PluginHost $host, string $jobId): void
    {
        $s = $host->settings();
        $horizon = max(7, min(60, (int) ($s['horizonDays'] ?? 21)));
        // "Free" means fewer than this many timed events; at 0 nothing ever
        // qualifies, which is what the label promises.
        $threshold = max(0, min(10, (int) ($s['busyThreshold'] ?? 2)));
        $weekdaysToo = (bool) ($s['weekdaysToo'] ?? false);
        $area = trim((string) ($s['area'] ?? ''));

        // Every datetime this plugin emits carries an explicit offset for the
        // user's own zone, DST and all. A bare local time would leave the
        // host guessing a zone for an instant the user will read as "3 PM".
        // The USER's zone, not the server's. Building "9 AM Saturday" in the
        // host machine's zone put the whole plan at midnight for a Pacific
        // user running on a Berlin box.
        $zone = $host->timezone();
        $first = (new DateTimeImmutable('now', $zone))->setTime(0, 0)->modify('+1 day');
        $last = $first->modify('+' . ($horizon - 1) . ' days');

        // Plugin calendars are information, not commitments: the weather
        // plugin puts an all-day event on EVERY day, which would otherwise
        // block every candidate and leave this one permanently silent.
        $ignore = [];
        foreach ($host->calendars() as $cal) {
            if ($cal['kind'] === 'plugin') {
                $ignore[$cal['id']] = true;
                continue;
            }
            // Recurring all-day chores ("change sheets", "card payment due")
            // are not what "this day is taken" means, but they are all-day
            // events and there is no way to tell them apart from a trip. So
            // the user marks those calendars here; without an opt-out one
            // chore list silently makes every day look occupied.
            if (!empty($host->calendarSettings($cal['id'])['ignore'])) {
                $ignore[$cal['id']] = true;
            }
        }

        [$busy, $blocked] = $this->readDays(
            $host->eventsWindow($first->format(self::ISO), $last->modify('+1 day')->format(self::ISO)),
            $zone,
            $ignore
        );

        // What we have already offered. A day the user accepted or rejected is
        // settled: re-proposing it would either duplicate their trip or nag
        // them, and it would spend a model call to do it.
        $status = [];
        foreach ($host->myProposals(null) as $p) {
            $status[(string) $p['sourceKey']] = (string) $p['status'];
        }

        $chosen = null;
        for ($d = $first; $d <= $last; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            if (!$weekdaysToo && (int) $d->format('N') < 6) {
                continue;
            }
            // An all-day event or a container means the day already has a
            // shape — a trip, a visit, a holiday. Open hours are not the same
            // thing as an open day.
            if (isset($blocked[$key]) || ($busy[$key] ?? 0) >= $threshold) {
                continue;
            }
            if (($status['day-' . $key] ?? 'open') !== 'open') {
                continue;
            }
            $chosen = $d;
            break;
        }
        if ($chosen === null) {
            $host->log('no open ' . ($weekdaysToo ? 'day' : 'weekend day') . ' in the next ' . $horizon . ' days');
            return;
        }

        $dayKey = $chosen->format('Y-m-d');
        // Stable per date, so a re-run REPLACES this day's open offer instead
        // of stacking a new one beside it every morning.
        $sourceKey = 'day-' . $dayKey;
        $busyCount = $busy[$dayKey] ?? 0;

        // Availability is color, not a veto. Someone being away is a fine
        // reason for the user to reject a day; it is not a reason for the
        // plugin to stay quiet about one.
        $here = [];
        $away = [];
        foreach ($host->availability($chosen->format(self::ISO), $chosen->modify('+1 day')->format(self::ISO)) as $a) {
            if ($a['kind'] === 'here') {
                $here[(string) $a['name']] = true;
            } elseif ($a['kind'] === 'away') {
                $away[(string) $a['name']] = true;
            }
        }
        $here = array_keys($here);
        $away = array_keys($away);

        // Everything that would justify saying something different. When it
        // matches what is already sitting open for this day there is nothing
        // new to offer, so we stop BEFORE spending a model call to rewrite the
        // same suggestion in slightly different words. myProposals() reports
        // status but not content, so the content check is ours to keep.
        $fingerprint = md5((string) json_encode([$dayKey, $busyCount, $here, $away, $area, $threshold, $weekdaysToo]));
        if (($status[$sourceKey] ?? null) === 'open' && $host->kvGet('fp-' . $sourceKey) === $fingerprint) {
            $host->log('skipped ' . $dayKey . ': the open proposal for it is still current');
            return;
        }

        $activities = $this->modelIdeas($host, $chosen, $area);
        $origin = $activities === [] ? 'built-in' : 'model';
        if ($activities === []) {
            $activities = self::FALLBACK;
        }

        $events = [];
        foreach ($activities as $a) {
            [$h, $m] = array_map('intval', explode(':', $a['start']));
            $start = $chosen->setTime($h, $m);
            $end = $start->modify('+' . $a['minutes'] . ' minutes');
            $ev = [
                'title' => $a['title'],
                'start' => $start->format(self::ISO),
                'end' => $end->format(self::ISO),
            ];
            if ($a['location'] !== '') {
                $ev['location'] = $a['location'];
            }
            $events[] = $ev;
        }

        $when = $chosen->format('l M j');
        $summary = ($busyCount === 0
                ? 'Nothing on ' . $when . ' yet.'
                : ($busyCount === 1 ? 'One thing on ' : $busyCount . ' things on ') . $when . '.')
            . ' ' . count($events) . ' ideas: ' . implode(', ', array_column($activities, 'title')) . '.'
            . ($here !== [] ? ' ' . $this->phrase($here) . ' around that day.' : '')
            . ($away !== [] ? ' ' . $this->phrase($away) . ' away.' : '');

        $items = '';
        foreach ($events as $ev) {
            $items .= '<li>' . substr($ev['start'], 11, 5) . '–' . substr($ev['end'], 11, 5) . ' '
                . htmlspecialchars($ev['title'], ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $rationale = '<p><strong>' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '</strong> is the soonest '
            . ($weekdaysToo ? 'day' : 'weekend day') . ' in the next ' . $horizon . ' days with '
            . ($busyCount === 0 ? 'nothing' : 'almost nothing') . ' on it'
            . ($area !== '' ? ', and the ideas are for ' . htmlspecialchars($area, ENT_QUOTES, 'UTF-8') : '')
            . '.</p><ul>' . $items . '</ul><p>'
            . ($origin === 'model' ? 'Ideas from the model' : 'Built-in plan (no model configured)')
            . '. Nothing lands on your calendar until you accept.</p>';

        $host->propose([
            'sourceKey' => $sourceKey,
            'title' => 'A day out on ' . $when,
            'summary' => $summary,
            'rationaleHtml' => $rationale,
            'plan' => [
                'trip' => [
                    'title' => 'Open day: ' . $when,
                    'start' => $dayKey,
                    'end' => $chosen->modify('+1 day')->format('Y-m-d'),
                ],
                'events' => $events,
            ],
        ]);
        $host->kvSet('fp-' . $sourceKey, $fingerprint);
        $host->log('proposed ' . count($events) . ' ' . $origin . ' ideas for ' . $dayKey
            . ' (' . $busyCount . ' timed events that day'
            . ($here !== [] ? ', ' . count($here) . ' around' : '') . ')');
    }

    /**
     * Bucket a window of occurrences by local date: how many timed events sit
     * on each day, and which days are spoken for outright.
     *
     * @param list<array<string,mixed>> $occs
     * @param array<int,bool> $ignore calendar ids that say nothing about being busy
     * @return array{0:array<string,int>,1:array<string,bool>} [timed counts, blocked days]
     */
    private function readDays(array $occs, DateTimeZone $zone, array $ignore = []): array
    {
        $busy = [];
        $blocked = [];
        foreach ($occs as $o) {
            if (isset($ignore[$o['calendarId'] ?? 0])) {
                continue;
            }
            if (!empty($o['allDay']) || !empty($o['isContainer'])) {
                foreach ($this->daysCovered($o, $zone) as $day) {
                    $blocked[$day] = true;
                }
                continue;
            }
            $day = (new DateTimeImmutable((string) $o['start']))->setTimezone($zone)->format('Y-m-d');
            $busy[$day] = ($busy[$day] ?? 0) + 1;
        }
        return [$busy, $blocked];
    }

    /**
     * Every local date an occurrence touches.
     *
     * All-day occurrences are calendar dates the host pins at a fixed +00:00
     * midnight, so their date is read literally — converting one into a zone
     * would slide a Saturday back into Friday. Timed spans (a container that
     * runs from Friday evening to Sunday, say) are real instants and do get
     * converted.
     *
     * @param array<string,mixed> $occ
     * @return list<string>
     */
    private function daysCovered(array $occ, DateTimeZone $zone): array
    {
        $startRaw = (string) ($occ['start'] ?? '');
        $endRaw = (string) ($occ['end'] ?? '');
        if ($startRaw === '') {
            return [];
        }
        if (!empty($occ['allDay'])) {
            $cursor = new DateTimeImmutable(substr($startRaw, 0, 10), $zone);
            $stop = new DateTimeImmutable(substr($endRaw !== '' ? $endRaw : $startRaw, 0, 10), $zone);
            if ($stop <= $cursor) {
                $stop = $cursor->modify('+1 day'); // all-day ends are exclusive
            }
        } else {
            $cursor = (new DateTimeImmutable($startRaw))->setTimezone($zone)->setTime(0, 0);
            $stop = (new DateTimeImmutable($endRaw !== '' ? $endRaw : $startRaw))
                ->setTimezone($zone)->setTime(0, 0)->modify('+1 day');
        }
        $days = [];
        // The horizon caps at 60 days; a span longer than that is someone's
        // year-long placeholder and only its first stretch can matter here.
        for ($i = 0; $i < 70 && $cursor < $stop; $i++) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        return $days;
    }

    /**
     * Outing ideas from the model, validated into this plugin's own shape.
     *
     * Returns [] for every failure mode, which is the important part:
     * llmJson() answers null identically for "no model configured" and "the
     * call failed", so a plugin that leans on it must behave the same either
     * way. Anything these checks cannot fully vouch for is dropped and the
     * caller falls back to FALLBACK, so a daily job always produces a whole,
     * sane day instead of an empty or half-built one.
     *
     * @return list<array{title:string,start:string,minutes:int,location:string}>
     */
    private function modelIdeas(PluginHost $host, DateTimeImmutable $day, string $area): array
    {
        $where = $area !== '' ? 'in or near ' . $area : 'close to home';
        $reply = $host->llmJson(
            'Suggest 2-3 simple, low-effort local outing activities for an open '
            . $day->format('l') . ', ' . $day->format('F j, Y') . ', ' . $where . '. '
            . 'Keep them ordinary and easy: a walk, a meal, a market, a museum. '
            . 'Spread them across the day between 08:00 and 20:00 local time, no overlaps, '
            . 'each with a start time and a duration in minutes.',
            ['activities' => [[
                'title' => 'short activity name',
                'start' => 'HH:MM, 24-hour local time',
                'durationMinutes' => 'whole number, 30-240',
                'location' => 'place name, or empty string',
            ]]]
        );
        if ($reply === null || !is_array($reply['activities'] ?? null)) {
            return [];
        }

        $rows = [];
        foreach ($reply['activities'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $start = trim((string) ($row['start'] ?? ''));
            if ($title === '' || preg_match('/^(\d{1,2}):([0-5]\d)$/', $start, $m) !== 1 || (int) $m[1] > 23) {
                continue;
            }
            // A good idea with a garbage duration is still a good idea; the
            // host makes the same call for a missing end (start plus an hour).
            $raw = $row['durationMinutes'] ?? null;
            $minutes = max(15, min(240, is_numeric($raw) ? (int) round((float) $raw) : 90));
            $rows[] = [
                'title' => mb_substr($title, 0, 120),
                'start' => sprintf('%02d:%02d', (int) $m[1], (int) $m[2]),
                'minutes' => $minutes,
                'location' => mb_substr(trim((string) ($row['location'] ?? '')), 0, 200),
                'from' => (int) $m[1] * 60 + (int) $m[2],
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $a['from'] <=> $b['from']);

        $out = [];
        $freeFrom = 0;
        foreach ($rows as $r) {
            // A model that double-books the day, or runs an activity past
            // midnight, loses the offending entry rather than the whole plan.
            if (count($out) >= self::MAX_ACTIVITIES || $r['from'] < $freeFrom || $r['from'] + $r['minutes'] > 1440) {
                continue;
            }
            $freeFrom = $r['from'] + $r['minutes'];
            unset($r['from']);
            $out[] = $r;
        }
        return $out;
    }

    /** "Marcus is" / "Marcus and Lin are"; long lists get counted, not printed. */
    private function phrase(array $names): string
    {
        $shown = array_slice($names, 0, 3);
        $rest = count($names) - count($shown);
        if ($rest > 0) {
            $shown[] = $rest . ' more';
        }
        $verb = count($names) === 1 ? ' is' : ' are';
        if (count($shown) === 1) {
            return $shown[0] . $verb;
        }
        $lastName = array_pop($shown);
        return implode(', ', $shown) . ' and ' . $lastName . $verb;
    }
};
