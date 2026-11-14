<?php

declare(strict_types=1);

// The check of docs/design/google-series-read-back.md: after a change to all events of a repeating event on a Google
// calendar, every day is as the person asked, at Google and here, whatever Google did to the days it kept when it took
// the series' own write, and Review says nothing.
//
// A generator: each kind of change to all events, over one series that holds an ordinary day renamed, a day with its
// own time and length, a day with its own notes and a deleted day, crossed with an effect the stand-in applies to
// every record the master write keeps or revives (FakeGoogle::$masterEffect). Some effects are ones real Google was
// seen to have (the start put on its occurrence with the end left: probes 3.17 and 3.67; the notes cleared: 3.52 to
// 3.57 and 3.68; the series' title written in: 3.12, 3.55), on kinds of change where it was seen and where it was not;
// two are ones nobody has seen (the end an hour later; a deleted day live again and a live one deleted). The check does
// not know which effect it is given: it compares the end with the plan.
//
// The plan is worked out here by date arithmetic from what was asked (the rules of docs/design/series-model.md: a
// field for all events reaches every day that has none of its own; a day's own title, notes and clock time stay; a
// day follows its occurrence when the series moves; a day that inherits "all-day" follows the series). Nothing here
// calls server/src to say what is expected: Google is read from the stand-in's own store, a GET at a time, and the
// calendar here from the app's window and rows.
//
// Then the same for a change stopped at its first day write (refused, its answer lost, the network down), followed by
// Finish (the plan) and, apart, by Put back (the calendar as before the change), the effect still in force; for a
// series write outside a change to all events (an answer to a repeating invitation, and its undo), whose plan is
// every day as it was; and for the series itself changed by another client right after its own write, where the last
// write stands and the record must say what landed.
//
// Written before the change it checks: on the tree before it, 70 of the 100 cases fail (the model, "The Check").
//
// READBACK_ONLY=<text> runs only the cases whose name contains it; READBACK_TABLE=1 prints, per case, the day writes
// sent and the days not as asked right after the master write. Invented data.

use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $denver = 'America/Denver';
    $utc = new DateTimeZone('UTC');
    $only = (string) getenv('READBACK_ONLY');
    $table = getenv('READBACK_TABLE') === '1';
    $first = '2027-01-04T10:00:00-08:00';

    // ---- the plan, by date arithmetic --------------------------------------------------------------------------

    /** $n dates a week apart from $from (Y-m-d). */
    $weekly = static function (string $from, int $n) use ($utc): array {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = (new DateTimeImmutable($from, $utc))->modify("+$i weeks")->format('Y-m-d');
        }
        return $out;
    };
    $both = static function (array ...$lists): array {
        $all = array_merge(...$lists);
        sort($all);
        return $all;
    };
    /** A series as a person sees it: its days (dates in its own zone), its clock time, length, zone or all-day, title and notes. */
    $series = static fn(array $over = []): array => $over + ['dates' => $weekly('2027-01-04', 8), 'clock' => '10:00', 'minutes' => 60, 'zone' => $la, 'allDay' => false, 'title' => 'Sample series', 'notes' => ''];
    // What the series holds, each on its own Monday, counted in weeks from the first: an ordinary day renamed (R), a
    // day with its own time and length, 13:00 to 14:30 Los Angeles (T), a day with its own notes (N), a deleted day (X).
    $weeks = ['R' => 1, 'T' => 2, 'N' => 3, 'X' => 4];
    $dayOf = static fn(string $h, int $shift = 0): string => (new DateTimeImmutable('2027-01-04', $utc))->modify('+' . ($weeks[$h] + $shift) . ' weeks')->format('Y-m-d');
    $stamp = static fn(DateTimeImmutable $at): string => $at->setTimezone($utc)->format('Y-m-d H:i');
    /**
     * Every day of the series as it should show, by its date: "start to end|title|notes" (UTC), or "date all-day to
     * date|title|notes". $held: which of R, T, N, X the series holds; $shift: the weeks its days moved with the series.
     */
    $plan = static function (array $s, array $held, int $shift = 0) use ($dayOf, $stamp, $la): array {
        $own = [];
        foreach ($held as $h) {
            $own[$dayOf($h, $shift)] = $h;
        }
        $out = [];
        foreach ($s['dates'] as $date) {
            $h = $own[$date] ?? null;
            if ($h === 'X') {
                continue;
            }
            if ($s['allDay']) {
                $when = $date . ' all-day to ' . (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
            } else {
                // A day's own time is that clock time in the zone it was set in; every other day has the series'.
                [$clock, $minutes, $zone] = $h === 'T' ? ['13:00', 90, $la] : [$s['clock'], $s['minutes'], $s['zone']];
                $start = new DateTimeImmutable("$date $clock", new DateTimeZone($zone));
                $when = $stamp($start) . ' to ' . $stamp($start->modify("+$minutes minutes"));
            }
            $out[$date] = $when . '|' . ($h === 'R' ? 'Own title' : $s['title']) . '|' . ($h === 'N' ? 'Own notes' : $s['notes']);
        }
        ksort($out);
        return $out;
    };

    // ---- what Google holds and what the calendar here shows, read as they are ------------------------------------

    $lineOf = static function (array $i) use ($stamp): string {
        $when = isset($i['start']['date'])
            ? $i['start']['date'] . ' all-day to ' . ($i['end']['date'] ?? '?')
            : $stamp(new DateTimeImmutable((string) $i['start']['dateTime'])) . ' to ' . $stamp(new DateTimeImmutable((string) $i['end']['dateTime']));
        return $when . '|' . ($i['summary'] ?? '') . '|' . ($i['description'] ?? '');
    };
    /**
     * Each day Google shows of the series, by the date of its occurrence in the zone $s names: every occurrence of the
     * master as the stand-in holds it (the harness's own expander), read by its id as another client reads it; a
     * cancelled or unreadable one is no day. A live record at a key the series no longer has is listed as a stray.
     */
    $google = static function (object $t, array $s) use ($utc, $lineOf): array {
        $m = $t->s->fake->events[$t->gid] ?? null;
        if ($m === null || ($m['status'] ?? '') === 'cancelled') {
            return ['the series' => 'gone'];
        }
        $allDay = isset($m['start']['date']);
        $out = [];
        $seen = [];
        foreach (GoogleStand::expand($m) as $at) {
            $id = $t->gid . '_' . ($allDay ? $at->format('Ymd') : $at->setTimezone($utc)->format('Ymd\THis\Z'));
            $seen[$id] = true;
            [$status, $i] = H::atGoogle($t, 'GET', '/events/' . rawurlencode($id));
            if ($status === 200 && ($i['status'] ?? '') !== 'cancelled') {
                $out[$allDay ? $at->format('Y-m-d') : $at->setTimezone(new DateTimeZone($s['zone']))->format('Y-m-d')] = $lineOf($i);
            }
        }
        foreach ($t->s->fake->events as $id => $e) {
            if (($e['recurringEventId'] ?? '') === $t->gid && isset($e['originalStartTime']) && ($e['status'] ?? '') !== 'cancelled' && !isset($seen[$id])) {
                $out['a record at no day of the series, ' . $id] = $lineOf($e);
            }
        }
        ksort($out);
        return $out;
    };
    /** Each day the calendar here shows, from the app's window, its notes from the row the window names. */
    $here = static function (object $t) use ($db, $utc, $stamp): array {
        $out = [];
        foreach ($t->e->window($t->u, new DateTimeImmutable('2026-12-28 00:00:00', $utc), new DateTimeImmutable('2027-05-03 00:00:00', $utc), [$t->s->calendarId], null, true) as $x) {
            $when = !empty($x['allDay'])
                ? substr((string) $x['start'], 0, 10) . ' all-day to ' . substr((string) $x['end'], 0, 10)
                : $stamp(new DateTimeImmutable((string) $x['start'])) . ' to ' . $stamp(new DateTimeImmutable((string) $x['end']));
            $out[] = $when . '|' . ($x['title'] ?? '') . '|' . (string) ($db->scalar('SELECT description FROM events WHERE id = ?', [(int) $x['eventId']]) ?? '');
        }
        sort($out);
        return $out;
    };
    $review = static function (object $t) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT summary, payload_json FROM review_items WHERE user_id = ? ORDER BY id', [$t->u]) as $item) {
            $out[] = $item['summary'] . ' ' . json_encode(json_decode((string) $item['payload_json'], true)['lines'] ?? []);
        }
        return $out;
    };
    /** The app's own requests since $from: [lists, reads of one event, writes to the series' master, writes to one day, any other write]. */
    $requests = static function (object $t, int $from): array {
        $n = ['lists' => 0, 'gets' => 0, 'master' => 0, 'days' => 0, 'other' => 0];
        foreach (array_slice($t->s->fake->requests, $from) as $r) {
            if (in_array('X-Harness: remote', $r['headers'], true)) {
                continue;
            }
            $id = rawurldecode(basename($r['path']));
            if ($r['method'] === 'GET') {
                $n[str_ends_with($r['path'], '/events') ? 'lists' : 'gets']++;
            } elseif ($r['method'] === 'PATCH' && $id === $t->gid) {
                $n['master']++;
            } elseif ($r['method'] === 'PATCH' && str_starts_with($id, $t->gid . '_')) {
                $n['days']++;
            } else {
                $n['other']++;
            }
        }
        return $n;
    };
    $differing = static function (array $want, array $got): array {
        $out = [];
        foreach (array_unique(array_merge(array_keys($want), array_keys($got))) as $day) {
            if (($want[$day] ?? 'no day') !== ($got[$day] ?? 'no day')) {
                $out[] = $day . ': asked ' . ($want[$day] ?? 'no day') . ', is ' . ($got[$day] ?? 'no day');
            }
        }
        sort($out);
        return $out;
    };

    // ---- the kinds of change, each with what it asks and the series it should leave --------------------------------

    $wed = static fn(array $s): array => ['dates' => $both($s['dates'], $weekly('2027-01-06', 8))] + $s;
    $toDenver = ['instanceStart' => $first, 'tzid' => $denver, 'start' => '2027-01-04T11:00:00-07:00', 'end' => '2027-01-04T12:00:00-07:00'];
    $allDay = ['instanceStart' => $first, 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true];
    // name => [the series' notes before, what it holds, the changes (body, choices) in order, the series after, weeks its days moved]
    $kinds = [
        'the title' => ['', ['R', 'T', 'N', 'X'], [[['title' => 'Renamed series'], ['ownTitle' => 'keep']]], $series(['title' => 'Renamed series']), 0],
        'notes set' => ['', ['R', 'T', 'N', 'X'], [[['description' => 'Series notes'], ['ownDescription' => 'keep']]], $series(['notes' => 'Series notes']), 0],
        'notes cleared' => ['Series notes', ['R', 'T', 'N', 'X'], [[['description' => ''], []]], $series(), 0],
        'the time, an hour later' => ['', ['R', 'T', 'N', 'X'], [[['instanceStart' => $first, 'start' => '2027-01-04T11:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00'], []]], $series(['clock' => '11:00']), 0],
        'the length, an hour longer' => ['', ['R', 'T', 'N', 'X'], [[['instanceStart' => $first, 'start' => $first, 'end' => '2027-01-04T12:00:00-08:00'], []]], $series(['minutes' => 120]), 0],
        // 11:00 Denver is 10:00 Los Angeles: no day's moment changes.
        'the zone, at the same moments' => ['', ['R', 'T', 'N', 'X'], [[$toDenver, ['zone' => 'clock']]], $series(['zone' => $denver, 'clock' => '11:00']), 0],
        'the zone at the same moments and a weekday added' => ['', ['R', 'T', 'N', 'X'], [[$toDenver + ['rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=16'], ['zone' => 'clock']]], $wed($series(['zone' => $denver, 'clock' => '11:00'])), 0],
        'the first day a week later, along the pattern' => ['', ['R', 'T', 'N', 'X'], [[['instanceStart' => $first, 'start' => '2027-01-11T10:00:00-08:00', 'end' => '2027-01-11T11:00:00-08:00'], []]], $series(['dates' => $weekly('2027-01-11', 8)]), 1],
        'a weekday added' => ['', ['R', 'T', 'N', 'X'], [[['rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=16'], []]], $wed($series()), 0],
        // 10:00 Los Angeles is 18:00 UTC: no day of either form sits where a record of the other does (the refused times are those at 00:00 UTC).
        'all-day' => ['', ['R', 'T', 'N', 'X'], [[$allDay, []]], $series(['allDay' => true]), 0],
        // Without the day at its own time: the written rule does not say what clock such a day has after being all-day.
        'all-day and back to 10:00' => ['', ['R', 'N', 'X'], [[$allDay, []], [['instanceStart' => '2027-01-04', 'start' => $first, 'end' => '2027-01-04T11:00:00-08:00', 'allDay' => false, 'tzid' => $la], []]], $series(), 0],
    ];

    // ---- what the stand-in does to each record a master write keeps or revives ------------------------------------

    $effects = [
        'as the stand-in has Google' => null,
        'start on its occurrence, end left where it was' => static function (array $r, array $before, array $m): array {
            if (($r['status'] ?? '') === 'cancelled' || !isset($r['start']['dateTime'], $r['originalStartTime']['dateTime'], $before['end']['dateTime'])) {
                return $r;
            }
            if ((new DateTimeImmutable((string) $before['end']['dateTime'])) <= (new DateTimeImmutable((string) $r['originalStartTime']['dateTime']))) {
                return $r;
            }
            $tz = (string) ($r['start']['timeZone'] ?? 'UTC');
            $r['start'] = ['dateTime' => $r['originalStartTime']['dateTime'], 'timeZone' => $tz];
            $r['end'] = ['dateTime' => $before['end']['dateTime'], 'timeZone' => $tz];
            return $r;
        },
        'notes cleared' => static function (array $r, array $before, array $m): array {
            unset($r['description']);
            return $r;
        },
        "the series' title written in" => static function (array $r, array $before, array $m): array {
            return ['summary' => (string) ($m['summary'] ?? '')] + $r;
        },
        'end an hour later (never seen)' => static function (array $r, array $before, array $m): array {
            if (isset($r['end']['dateTime'])) {
                $r['end']['dateTime'] = (new DateTimeImmutable((string) $r['end']['dateTime']))->modify('+1 hour')->format('Y-m-d\TH:i:sP');
            } elseif (isset($r['end']['date'])) {
                $r['end']['date'] = (new DateTimeImmutable((string) $r['end']['date']))->modify('+1 day')->format('Y-m-d');
            }
            return $r;
        },
        'a deleted day live again, a live one deleted (never seen)' => static function (array $r, array $before, array $m): array {
            return ['status' => ($r['status'] ?? 'confirmed') === 'cancelled' ? 'confirmed' : 'cancelled'] + $r;
        },
    ];

    /** The series with what it holds. */
    $build = static function (string $notes, array $held) use ($db, $first, $la): object {
        $t = H::make($db, ['start' => $first, 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=8'] + ($notes !== '' ? ['description' => $notes] : []));
        if (in_array('R', $held, true)) {
            H::this($t, '2027-01-11T10:00:00-08:00', ['title' => 'Own title']);
        }
        if (in_array('T', $held, true)) {
            H::this($t, '2027-01-18T10:00:00-08:00', ['start' => '2027-01-18T13:00:00-08:00', 'end' => '2027-01-18T14:30:00-08:00']);
        }
        if (in_array('N', $held, true)) {
            H::this($t, '2027-01-25T10:00:00-08:00', ['description' => 'Own notes']);
        }
        if (in_array('X', $held, true)) {
            H::skip($t, '2027-02-01T10:00:00-08:00');
        }
        return $t;
    };
    $act = static function (object $t, array $changes): ?string {
        foreach ($changes as [$body, $choices]) {
            [, $error] = H::tryChange($t, ['scope' => 'all'] + $body, $choices);
            if ($error !== null) {
                return $error;
            }
        }
        return null;
    };
    /** The end every case is held to: Google and the calendar here as $want, Review silent, and a poll changing nothing. */
    $ends = static function (object $t, string $name, array $s, array $want) use ($google, $here, $review, $differing): void {
        check("$name: Google holds every day as asked", $google($t, $s) === $want, "\n   " . implode("\n   ", $differing($want, $google($t, $s))) . "\n   google:\n     " . implode("\n     ", H::stored($t)));
        $shown = $here($t);
        $asked = array_values($want);
        sort($asked);
        check("$name: the calendar here shows every day as asked", $shown === $asked,
            "\n   missing: " . implode("\n            ", array_diff($asked, $shown)) . "\n   extra:   " . implode("\n            ", array_diff($shown, $asked)) . "\n   rows:\n     " . implode("\n     ", H::rows($t)));
        checkEq("$name: Review says nothing (every day is as asked, and nobody else changed anything)", [], $review($t));
        checkEq("$name: a poll changes no row", [], H::pollChanges($t));
    };

    $report = [];
    foreach ($kinds as $kind => [$notes, $held, $changes, $after, $shift]) {
        $before = $plan($series(['notes' => $notes]), $held);
        $want = $plan($after, $held, $shift);
        // The days that have, or had, something of their own: no other day is ever written.
        $ownDays = count(array_unique(array_merge(array_map(static fn(string $h): string => $dayOf($h), $held), array_map(static fn(string $h): string => $dayOf($h, $shift), $held))));
        foreach ($effects as $effect => $apply) {
            $name = "read-back: $kind, for all events; Google's own effect: $effect";
            if ($only !== '' && !str_contains($name, $only)) {
                continue;
            }
            scenario($name, function () use ($name, $kind, $effect, $notes, $held, $changes, $after, $before, $want, $ownDays, $apply, $build, $act, $google, $here, $requests, $differing, $ends, $series, &$report): void {
                $t = $build($notes, $held);
                try {
                    if ($apply === null) {
                        $asBuilt = array_values($before);
                        sort($asBuilt);
                        checkEq("$name: before the change, Google and the calendar here are as built", [$before, $asBuilt], [$google($t, $series()), $here($t)]);
                    }
                    $t->s->fake->masterEffect = $apply;
                    // Right after the series' own write, with the effect applied: what Google holds then, for the count below.
                    $then = null;
                    $t->s->knobs->race = static function () use (&$then, $google, $t, $after): void {
                        $then = $google($t, $after);
                    };
                    $from = count($t->s->fake->requests);
                    $error = $act($t, $changes);
                    $t->s->fake->masterEffect = null;
                    $t->s->knobs->race = null;
                    $n = $requests($t, $from);
                    check("$name: the change goes through, nothing half done", $error === null && H::halfDone($t) === [], (string) $error . ' ' . json_encode(H::halfDone($t)));
                    $ends($t, $name, $after, $want);
                    $steps = count($changes);
                    $notAsAsked = $then === null ? null : count($differing($want, $then));
                    // No slower: one read, the series' write, one read back, the days, one last read; no day read on its
                    // own; at most one write for each day that has or had something of its own, and (one change) at least
                    // one for each day that was not as asked after the series' write.
                    checkEq("$name: per change one read, one write to the series, a read back and a last read, with no read of a single day and no other write",
                        [3 * $steps, 0, $steps, 0], [$n['lists'], $n['gets'], $n['master'], $n['other']]);
                    check("$name: no more day writes than days that have or had something of their own", $n['days'] <= $ownDays * $steps, $n['days'] . " day writes, $ownDays such days");
                    if ($steps === 1 && $notAsAsked !== null) {
                        check("$name: a day write for every day that was not as asked after the series' write", $n['days'] >= $notAsAsked, $n['days'] . " day writes, $notAsAsked days not as asked:\n   " . implode("\n   ", $differing($want, $then)));
                    }
                    $report[] = sprintf('%-50s | %-58s | %d day writes | %s not as asked after the write', $kind, $effect, $n['days'], $notAsAsked === null || $steps > 1 ? '-' : (string) $notAsAsked);
                } finally {
                    $t->s->fake->masterEffect = null;
                    $t->s->knobs->race = null;
                    H::done($t);
                }
            });
        }
    }

    // ---- stopped at the first day write, then Finish and, apart, Put back ---------------------------------------------

    $stops = ['beforeAt' => 'refused', 'loseAt' => 'taken, its answer lost', 'netAt' => 'the network down as it is sent'];
    foreach (['the zone, at the same moments', 'the first day a week later, along the pattern'] as $kind) {
        [$notes, $held, $changes, $after, $shift] = $kinds[$kind];
        $before = $plan($series(['notes' => $notes]), $held);
        $want = $plan($after, $held, $shift);
        foreach (['as the stand-in has Google', 'end an hour later (never seen)'] as $effect) {
            foreach ($stops as $knob => $how) {
                foreach (['finish' => 'Finish', 'putback' => 'Put back'] as $recover => $word) {
                    $name = "read-back: $kind, stopped at its first day write ($how), then $word; Google's own effect: $effect";
                    if ($only !== '' && !str_contains($name, $only)) {
                        continue;
                    }
                    scenario($name, function () use ($name, $notes, $held, $changes, $after, $before, $want, $effect, $effects, $knob, $recover, $word, $build, $act, $ends, $series): void {
                        $t = $build($notes, $held);
                        try {
                            $t->s->fake->masterEffect = $effects[$effect];
                            $t->s->knobs->writes = 0;
                            $t->s->knobs->$knob = 2;
                            $stopped = $act($t, $changes);
                            $t->s->knobs->beforeAt = $t->s->knobs->loseAt = $t->s->knobs->netAt = null;
                            $t->s->knobs->down = false;
                            check("$name: the change stops at its first day write and is listed half done", $stopped !== null && count(H::halfDone($t)) === 1,
                                ($stopped ?? 'it went through') . ', half done: ' . count(H::halfDone($t)) . ', writes: ' . $t->s->knobs->writes);
                            if ($stopped === null || count(H::halfDone($t)) !== 1) {
                                return;
                            }
                            [, $refused] = H::recover($t, $recover);
                            check("$name: $word completes (nothing changed at Google since the stop)", $refused === null && H::halfDone($t) === [], (string) $refused);
                            if ($refused !== null) {
                                return;
                            }
                            $recover === 'finish' ? $ends($t, $name, $after, $want) : $ends($t, $name, $series(), $before);
                        } finally {
                            $t->s->fake->masterEffect = null;
                            $t->s->knobs->beforeAt = $t->s->knobs->loseAt = $t->s->knobs->netAt = null;
                            $t->s->knobs->down = false;
                            H::done($t);
                        }
                    });
                }
            }
        }
    }

    // ---- a master write outside a change to all events: an answer to a repeating invitation, and its undo ---------------

    // A weekly invitation from someone else, 10:00 to 10:30 Los Angeles, six Mondays from 4 January; 11 January has
    // notes of its own and 18 January a time of its own (13:00 to 13:30), both the organizer's. An answer asks nothing
    // of any day, so the plan is every day as it was: worked out here from that description.
    $invited = static function (string $notes) use ($weekly, $stamp, $la): array {
        $out = [];
        foreach ($weekly('2027-01-04', 6) as $date) {
            $start = new DateTimeImmutable($date . ($date === '2027-01-18' ? ' 13:00' : ' 10:00'), new DateTimeZone($la));
            $out[$date] = $stamp($start) . ' to ' . $stamp($start->modify('+30 minutes')) . '|Sample standup|' . ($date === '2027-01-11' ? 'Own notes for the day' : $notes);
        }
        return $out;
    };
    $answers = [
        // With notes of its own the stand-in clears nothing (3.58 to 3.60), so nothing is to put back: the cost of an answer as it is today.
        ['Series notes', 'as the stand-in has Google', 0],
        // With none, the series' write clears the day's own notes, as every master write was seen to (3.52 to 3.57, 3.68; a write of the guest list alone was not probed).
        ['', 'as the stand-in has Google', null],
        ['Series notes', 'start on its occurrence, end left where it was', null],
        ['Series notes', 'end an hour later (never seen)', null],
    ];
    foreach ($answers as [$notes, $effect, $dayWrites]) {
        $name = 'read-back: an answer to a repeating invitation for all events, then its undo, the series with ' . ($notes === '' ? 'no notes' : 'notes') . "; Google's own effect: $effect";
        if ($only !== '' && !str_contains($name, $only)) {
            continue;
        }
        scenario($name, function () use ($db, $name, $notes, $effect, $effects, $dayWrites, $invited, $google, $requests, $ends, $series, $la): void {
            // The stand needs an event to be made with: one far outside the days looked at.
            $t = H::make($db, ['start' => '2027-06-01T10:00:00-07:00', 'end' => '2027-06-01T11:00:00-07:00', 'tzid' => $la]);
            try {
                $account = (string) $db->scalar('SELECT email FROM google_accounts WHERE user_id = ?', [$t->u]);
                $when = static fn(string $local): array => ['dateTime' => (new DateTimeImmutable($local, new DateTimeZone($la)))->format('Y-m-d\TH:i:sP'), 'timeZone' => $la];
                $guests = ['organizer' => ['email' => 'host@example.test'], 'attendees' => [['email' => 'host@example.test', 'responseStatus' => 'accepted'], ['email' => $account, 'self' => true, 'responseStatus' => 'needsAction']]];
                $common = ['iCalUID' => 'smplinv@google.com', 'summary' => 'Sample standup'] + ($notes !== '' ? ['description' => $notes] : []) + $guests;
                $t->s->fake->seed(['id' => 'smplinv', 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6'], 'start' => $when('2027-01-04 10:00'), 'end' => $when('2027-01-04 10:30')] + $common);
                $t->s->fake->seed(['id' => 'smplinv_20270111T180000Z', 'recurringEventId' => 'smplinv', 'description' => 'Own notes for the day',
                    'originalStartTime' => $when('2027-01-11 10:00'), 'start' => $when('2027-01-11 10:00'), 'end' => $when('2027-01-11 10:30')] + $common);
                $t->s->fake->seed(['id' => 'smplinv_20270118T180000Z', 'recurringEventId' => 'smplinv',
                    'originalStartTime' => $when('2027-01-18 10:00'), 'start' => $when('2027-01-18 13:00'), 'end' => $when('2027-01-18 13:30')] + $common);
                $t->s->poll();
                $inv = clone $t;
                $inv->gid = 'smplinv';
                $inv->id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$t->s->calendarId, 'smplinv']);
                $want = $invited($notes);
                checkEq("$name: before the answer, Google holds the invitation as built", $want, $google($inv, $series()));
                $mine = static fn(): ?string => array_values(array_filter($t->s->fake->events['smplinv']['attendees'] ?? [], static fn(array $a): bool => !empty($a['self'])))[0]['responseStatus'] ?? null;
                $token = bin2hex(random_bytes(16));
                $db->insert('sessions', ['token_hash' => hash('sha256', $token), 'user_id' => $t->u, 'csrf' => 'x', 'authenticated_at' => BetterCal\Support\Time::nowDb(), 'expires_at' => '2030-01-01 00:00:00']);
                $t->s->fake->masterEffect = $effects[$effect];

                $from = count($t->s->fake->requests);
                $error = null;
                try {
                    (new BetterCal\Domain\Rsvp($db, new BetterCal\Infra\EmailSender([]), [], $t->s->writer))->answer($t->u, $inv->id, 'accepted', $token);
                } catch (\Throwable $e) {
                    $error = get_class($e) . ': ' . $e->getMessage();
                }
                $n = $requests($inv, $from);
                $entry = lastEntry($db, $t->u); // the answer's own entry, before any poll journals one
                checkEq("$name: the answer is taken, and Google has it", [null, 'accepted'], [$error, $mine()]);
                $ends($inv, "$name, after the answer", $series(), $want);
                if ($dayWrites !== null) {
                    // No slower where nothing is to put back: the read of the event, the read of its series, the write, one read after.
                    checkEq("$name: with no day to put back the answer costs what it did (two lists, one read of the event, one write, no day write)",
                        [2, 1, 1, $dayWrites, 0], [$n['lists'], $n['gets'], $n['master'], $n['days'], $n['other']]);
                }

                $error = null;
                try {
                    (new BetterCal\Domain\Undo($db, null, $t->s->writer))->undoById($t->u, $entry, false);
                    $t->e->throwDeferred();
                } catch (\Throwable $e) {
                    $error = get_class($e) . ': ' . $e->getMessage();
                }
                checkEq("$name: the undo of the answer is taken, and Google has the answer as it was", [null, 'needsAction'], [$error, $mine()]);
                $ends($inv, "$name, after the undo of the answer", $series(), $want);
            } finally {
                $t->s->fake->masterEffect = null;
                H::done($t);
            }
        });
    }

    // ---- the record of a change is what landed: the series itself changed right after its own write -------------------

    // The owner's rule (2026-11-14): the last write wins, and the record is made accurate from the read the change ends
    // with. A weekly series of four from 1 March 2027 at 10:00 Los Angeles, 8 March renamed, 15 March deleted; the time
    // changed to 11:00 for all events; and right after the series' own write another client changes the series itself
    // (its end, its start day, its time, its form, its title, or deletes it). That write was the last one to the series
    // and stands: the series is not written again. At the end the rows here are what Google holds, and nothing said of
    // the change is false of that end: no cause is claimed (nobody can know who did it), a day the change deleted that
    // shows nowhere at the end is as asked and is not spoken of, and a value that landed in place of the one asked for
    // is named. The words are read as text, so this part can only catch what it names; the comparison's own data is
    // checked where it is built (the model, "The Record").
    $at = static fn(string $local): array => ['dateTime' => (new DateTimeImmutable($local, new DateTimeZone($la)))->format('Y-m-d\TH:i:sP'), 'timeZone' => $la];
    // what the other client does => [method, body, a value that then holds where another was asked for (or none),
    // and the days left at the end, [date, clock time or none for all-day, title]]: the series as the other client's write
    // left it (a new time, start day or form drops every record, probes 3.1, 3.4 and 3.19, so each day is the series'
    // own), with each day of the plan that is still a day of it as the plan has it (8 March its own title, 15 March
    // deleted), since a day not as planned is sent.
    $others = [
        'cuts it to two events' => ['PATCH', ['recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=2']], null,
            [['2027-03-01', '11:00', 'Sample series'], ['2027-03-08', '11:00', 'Own title']]],
        'moves it a day later, to Tuesdays' => ['PATCH', ['start' => $at('2027-03-02 11:00'), 'end' => $at('2027-03-02 12:00')], null,
            [['2027-03-02', '11:00', 'Sample series'], ['2027-03-09', '11:00', 'Sample series'], ['2027-03-16', '11:00', 'Sample series'], ['2027-03-23', '11:00', 'Sample series']]],
        'moves it to 15:00' => ['PATCH', ['start' => $at('2027-03-01 15:00'), 'end' => $at('2027-03-01 16:00')], '15:00',
            [['2027-03-01', '15:00', 'Sample series'], ['2027-03-08', '15:00', 'Sample series'], ['2027-03-15', '15:00', 'Sample series'], ['2027-03-22', '15:00', 'Sample series']]],
        'makes it all-day' => ['PATCH', BetterCal\GoogleProbe\FakeGoogle::asSet(['start' => ['date' => '2027-03-01'], 'end' => ['date' => '2027-03-02']]), null,
            [['2027-03-01', null, 'Sample series'], ['2027-03-08', null, 'Sample series'], ['2027-03-15', null, 'Sample series'], ['2027-03-22', null, 'Sample series']]],
        'renames it' => ['PATCH', ['summary' => 'Zebra crossing'], 'Zebra crossing',
            [['2027-03-01', '11:00', 'Zebra crossing'], ['2027-03-08', '11:00', 'Own title'], ['2027-03-22', '11:00', 'Zebra crossing']]],
        'deletes it' => ['DELETE', null, null, []],
    ];
    foreach ($others as $what => [$method, $body, $landed, $left]) {
        $leaves = [];
        foreach ($left as [$date, $clock, $title]) {
            $start = new DateTimeImmutable($date . ' ' . ($clock ?? '00:00'), new DateTimeZone($la));
            $leaves[$date] = ($clock === null ? $date . ' all-day to ' . $start->modify('+1 day')->format('Y-m-d') : $stamp($start) . ' to ' . $stamp($start->modify('+60 minutes'))) . '|' . $title . '|';
        }
        $name = "read-back: the time changed for all events, and right after the series' own write another client $what";
        if ($only !== '' && !str_contains($name, $only)) {
            continue;
        }
        scenario($name, function () use ($db, $name, $la, $method, $body, $landed, $leaves, $google, $here, $review, $requests, $series, $differing): void {
            $t = H::make($db, ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=4']);
            try {
                H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Own title']);
                H::skip($t, '2027-03-15T10:00:00-07:00');
                $t->s->knobs->race = static function () use ($t, $method, $body): void {
                    H::atGoogle($t, $method, '/events/' . rawurlencode($t->gid), $body);
                };
                $from = count($t->s->fake->requests);
                [, $error] = H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
                $t->s->knobs->race = null;
                $n = $requests($t, $from);
                check("$name: the change ends (the last write won), nothing half done", $error === null && H::halfDone($t) === [], (string) $error . ' ' . json_encode(array_column(H::halfDone($t), 'error')));
                checkEq("$name: the series is written once, and not again over the other client's write", 1, $n['master']);
                $end = $google($t, $series());
                $end = $end === ['the series' => 'gone'] ? [] : $end;
                check("$name: Google holds the series as the last write to it left it, and each day of the plan still in it as the plan has it", $end === $leaves,
                    "\n   " . implode("\n   ", $differing($leaves, $end)) . "\n   google:\n     " . implode("\n     ", H::stored($t)));
                $holds = array_values($end);
                sort($holds);
                check("$name: the rows here are what Google holds at the end", $here($t) === $holds,
                    "\n   here:   " . implode("\n           ", array_diff($here($t), $holds)) . "\n   google: " . implode("\n           ", array_diff($holds, $here($t))) . "\n   rows:\n     " . implode("\n     ", H::rows($t)) . "\n   google:\n     " . implode("\n     ", H::stored($t)));
                checkEq("$name: a poll changes no row", [], H::pollChanges($t));
                // What is said of the change beyond what was asked: Review's lines, and the Activity entry's text and lines.
                $said = $review($t);
                $entry = $db->one("SELECT summary, details_json FROM mutations WHERE user_id = ? AND summary LIKE '%Sample series%' ORDER BY id DESC LIMIT 1", [$t->u]);
                $said = array_merge($said, [(string) ($entry['summary'] ?? '')], (array) (json_decode((string) ($entry['details_json'] ?? ''), true)['google']['kept'] ?? []));
                checkEq("$name: nothing said claims a cause (who changed what, or when)", [], array_values(array_filter($said,
                    static fn(string $line): bool => preg_match('/was \w+( \w+){0,3} in Google Calendar|while this change was|[Ss]omeone|another (app|client|person)/', $line) === 1)));
                if (!isset($end['2027-03-15'])) {
                    // Deleted by the change, and nothing shows there at the end: as asked.
                    checkEq("$name: nothing is said of 15 March, which the change deleted and which shows nowhere", [], array_values(array_filter($said, static fn(string $line): bool => str_contains($line, '15 Mar'))));
                }
                if ($landed !== null) {
                    check("$name: what is said names what landed ($landed)", str_contains(implode("\n", $said), $landed), implode("\n", $said));
                }
            } finally {
                $t->s->knobs->race = null;
                H::done($t);
            }
        });
    }

    if ($table && $report !== []) {
        echo "  read-back, day writes per case:\n    " . implode("\n    ", $report) . "\n";
    }
};
