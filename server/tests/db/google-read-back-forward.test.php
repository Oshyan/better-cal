<?php

declare(strict_types=1);

// Probes of an independent read of 586b15f7..b4aaf63d (the read-back build), the forward path: a change to all events
// over things a series can hold that the build's own check (google-series-read-back) does not give it: a day in the
// other form (an all-day day of a timed series), a day with its own place, a deleted day Google holds as an EXDATE
// line beside one it holds as a cancelled record. The rule (docs/design/google-series-read-back.md, "The Rule"): the
// plan says what each day should be; after the series' write every day not as planned is sent; at the end Google and
// the rows here are the plan, whatever Google did to the kept records at the series' write, and nothing is said.
//
// The plan is worked out here by date arithmetic from what was asked (docs/design/series-model.md: a field for all
// events reaches every day that has none of its own; a day's own form and place stay; a deleted day stays deleted; a
// day follows its occurrence when the series moves along its pattern). Google is read from the stand-in's own store
// by the harness's expander, a GET at a time; the calendar here from the app's window. Nothing in server/src says
// what is expected. The stand-in's effects are a test's own (FakeGoogle::$masterEffect), never a claim about Google.
// Invented data.

use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $z = new DateTimeZone($la);
    $utc = new DateTimeZone('UTC');
    $first = '2027-01-04T10:00:00-08:00';
    // Six Mondays from 4 January 2027, 10:00 to 11:00 Los Angeles. 11 January is an all-day day (D), 18 January has its
    // own place (P), 25 January is deleted here (X: a cancelled record), 1 February was deleted by another app (E: an
    // EXDATE line).
    $build = static function () use ($db, $la, $first): object {
        $t = H::make($db, ['start' => $first, 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6', 'exdates' => ['2027-02-01 18:00:00']]);
        H::this($t, '2027-01-11T10:00:00-08:00', ['start' => '2027-01-11', 'end' => '2027-01-12', 'allDay' => true]);
        H::this($t, '2027-01-18T10:00:00-08:00', ['location' => 'Own room']);
        H::skip($t, '2027-01-25T10:00:00-08:00');
        return $t;
    };
    /** The plan by date: $dates the series' Mondays, $clock and $minutes its time, $title; $shift the weeks its days moved. */
    $plan = static function (array $dates, string $clock, int $minutes, string $title, int $shift = 0): array {
        $own = [];
        foreach (['D' => '2027-01-11', 'P' => '2027-01-18', 'X' => '2027-01-25', 'E' => '2027-02-01'] as $h => $date) {
            $own[(new DateTimeImmutable($date))->modify("+$shift weeks")->format('Y-m-d')] = $h;
        }
        $out = [];
        foreach ($dates as $date) {
            $h = $own[$date] ?? null;
            if ($h === 'X' || $h === 'E') {
                continue;
            }
            $end = (new DateTimeImmutable("$date $clock"))->modify("+$minutes minutes")->format('H:i');
            $out[] = $date . '|' . ($h === 'D' ? 'all-day' : "$clock-$end") . '|' . $title . '|' . ($h === 'P' ? 'Own room' : '');
        }
        return $out;
    };
    $mondays = static fn(string $from): array => array_map(static fn(int $i): string => (new DateTimeImmutable($from))->modify("+$i weeks")->format('Y-m-d'), range(0, 5));
    $line = static function (array $i) use ($z): string {
        if (isset($i['start']['date'])) {
            return $i['start']['date'] . '|all-day|' . ($i['summary'] ?? '') . '|' . ($i['location'] ?? '');
        }
        $s = (new DateTimeImmutable((string) $i['start']['dateTime']))->setTimezone($z);
        return $s->format('Y-m-d') . '|' . $s->format('H:i') . '-' . (new DateTimeImmutable((string) $i['end']['dateTime']))->setTimezone($z)->format('H:i') . '|' . ($i['summary'] ?? '') . '|' . ($i['location'] ?? '');
    };
    /** Every day Google shows of the series: each occurrence of the master as stored, read by its id; cancelled or excluded, no day. */
    $google = static function (object $t) use ($line, $utc): array {
        $m = $t->s->fake->events[$t->gid] ?? null;
        if ($m === null || ($m['status'] ?? '') === 'cancelled') {
            return ['the series is gone'];
        }
        $excluded = [];
        foreach ((array) ($m['recurrence'] ?? []) as $rl) {
            if (str_starts_with((string) $rl, 'EXDATE')) {
                foreach (explode(',', substr((string) $rl, (int) strpos((string) $rl, ':') + 1)) as $v) {
                    $excluded[substr(trim($v), 0, 8)] = true;
                }
            }
        }
        $allDay = isset($m['start']['date']);
        $out = [];
        foreach (GoogleStand::expand($m) as $at) {
            $key = $allDay ? $at->format('Ymd') : $at->setTimezone($utc)->format('Ymd\THis\Z');
            [$status, $i] = H::atGoogle($t, 'GET', '/events/' . rawurlencode($t->gid . '_' . $key));
            // An EXDATE line names the occurrence's start in the series' zone or UTC: by its local date here (one occurrence a day).
            $local = $allDay ? $at->format('Ymd') : $at->setTimezone(new DateTimeZone((string) ($m['start']['timeZone'] ?? 'UTC')))->format('Ymd');
            if ($status === 200 && ($i['status'] ?? '') !== 'cancelled' && !isset($excluded[substr($key, 0, 8)]) && !isset($excluded[$local])) {
                $out[] = $line($i);
            }
        }
        sort($out);
        return $out;
    };
    $here = static function (object $t) use ($z, $utc): array {
        $out = [];
        foreach ($t->e->window($t->u, new DateTimeImmutable('2026-12-28 00:00:00', $utc), new DateTimeImmutable('2027-04-01 00:00:00', $utc), [$t->s->calendarId], null, true) as $x) {
            if (!empty($x['allDay'])) {
                $out[] = substr((string) $x['start'], 0, 10) . '|all-day|' . ($x['title'] ?? '') . '|' . ($x['location'] ?? '');
                continue;
            }
            $s = (new DateTimeImmutable((string) $x['start']))->setTimezone($z);
            $out[] = $s->format('Y-m-d') . '|' . $s->format('H:i') . '-' . (new DateTimeImmutable((string) $x['end']))->setTimezone($z)->format('H:i') . '|' . ($x['title'] ?? '') . '|' . ($x['location'] ?? '');
        }
        sort($out);
        return $out;
    };
    $review = static fn(object $t): array => array_map(static fn(array $i): string => $i['summary'] . ' ' . $i['payload_json'], $db->all('SELECT summary, payload_json FROM review_items WHERE user_id = ? ORDER BY id', [$t->u]));

    $kinds = [
        'the title' => [['title' => 'Renamed series'], $plan($mondays('2027-01-04'), '10:00', 60, 'Renamed series')],
        'the time, an hour later' => [['instanceStart' => $first, 'start' => '2027-01-04T11:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00'], $plan($mondays('2027-01-04'), '11:00', 60, 'Sample series')],
        'the length, an hour longer' => [['instanceStart' => $first, 'start' => $first, 'end' => '2027-01-04T12:00:00-08:00'], $plan($mondays('2027-01-04'), '10:00', 120, 'Sample series')],
        // 11:00 Denver is 10:00 Los Angeles: no day's moment changes, so by Los Angeles clock the plan is the calendar as it was.
        'the zone, at the same moments' => [['instanceStart' => $first, 'tzid' => 'America/Denver', 'start' => '2027-01-04T11:00:00-07:00', 'end' => '2027-01-04T12:00:00-07:00'], $plan($mondays('2027-01-04'), '10:00', 60, 'Sample series')],
        'the first day a week later, along the pattern' => [['instanceStart' => $first, 'start' => '2027-01-11T10:00:00-08:00', 'end' => '2027-01-11T11:00:00-08:00'], $plan($mondays('2027-01-11'), '10:00', 60, 'Sample series', 1)],
    ];
    $effects = [
        'none of the test\'s own' => null,
        'start on its occurrence, end left where it was' => static function (array $r, array $before, array $m): array {
            if (($r['status'] ?? '') === 'cancelled' || !isset($r['start']['dateTime'], $r['originalStartTime']['dateTime'], $before['end']['dateTime'])) {
                return $r;
            }
            $tz = (string) ($r['start']['timeZone'] ?? 'UTC');
            $r['start'] = ['dateTime' => $r['originalStartTime']['dateTime'], 'timeZone' => $tz];
            $r['end'] = ['dateTime' => $before['end']['dateTime'], 'timeZone' => $tz];
            return $r;
        },
        'the place cleared (never seen)' => static function (array $r, array $before, array $m): array {
            unset($r['location']);
            return $r;
        },
        'a deleted day live again, a live one deleted (never seen)' => static fn(array $r, array $before, array $m): array => ['status' => ($r['status'] ?? 'confirmed') === 'cancelled' ? 'confirmed' : 'cancelled'] + $r,
    ];

    scenario('forward probe, as built: the series holds an all-day day, a day with its own place, and two deleted days', function () use ($build, $google, $here, $plan, $mondays): void {
        $t = $build();
        try {
            $want = $plan($mondays('2027-01-04'), '10:00', 60, 'Sample series');
            checkEq('as built: Google and the calendar here, by date', [$want, $want], [$google($t), $here($t)]);
            check('as built: Google holds 1 February as an EXDATE line and 25 January as a cancelled record',
                array_filter((array) $t->s->fake->events[$t->gid]['recurrence'], static fn(string $l): bool => str_starts_with($l, 'EXDATE')) !== []
                && ($t->s->fake->events[$t->gid . '_20270125T180000Z']['status'] ?? '') === 'cancelled', json_encode($t->s->fake->events[$t->gid]['recurrence']));
        } finally {
            H::done($t);
        }
    });

    foreach ($kinds as $kind => [$body, $want]) {
        foreach ($effects as $effect => $apply) {
            $name = "forward probe: $kind, for all events; the stand-in's own effect: $effect";
            scenario($name, function () use ($name, $body, $want, $apply, $build, $google, $here, $review): void {
                $t = $build();
                try {
                    $t->s->fake->masterEffect = $apply;
                    [, $error] = H::tryChange($t, ['scope' => 'all'] + $body);
                    $t->s->fake->masterEffect = null;
                    check("$name: the change goes through, nothing half done", $error === null && H::halfDone($t) === [], (string) $error);
                    if ($error !== null) {
                        return;
                    }
                    $g = $google($t);
                    check("$name: Google holds every day as asked", $g === $want, "\n   asked not held: " . implode(' ; ', array_diff($want, $g)) . "\n   held not asked: " . implode(' ; ', array_diff($g, $want)) . "\n   google:\n     " . implode("\n     ", H::stored($t)));
                    $h = $here($t);
                    check("$name: the calendar here shows every day as asked", $h === $want, "\n   asked not shown: " . implode(' ; ', array_diff($want, $h)) . "\n   shown not asked: " . implode(' ; ', array_diff($h, $want)) . "\n   rows:\n     " . implode("\n     ", H::rows($t)));
                    checkEq("$name: Review says nothing", [], $review($t));
                    checkEq("$name: a poll changes no row", [], H::pollChanges($t));
                } finally {
                    $t->s->fake->masterEffect = null;
                    H::done($t);
                }
            });
        }
    }
};
