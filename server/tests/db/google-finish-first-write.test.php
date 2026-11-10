<?php

declare(strict_types=1);

// Finish of a change whose first write never reached Google, after a change made at Google meanwhile (finding F2
// of the hunt of 20f77fe9; decisions.md, "A change made at Google while a change's first write waits is named, not
// carried, where the change gives the days other keys").
//
// A daily series at 10:00 Los Angeles, Mon 4 Jan to Mon 11 Jan 2027, Wed 6 Jan with its own notes. All events are
// moved to 11:00. The network goes down as the master write is sent: the app has journaled the step, Google took
// nothing, Review lists the change as half done. Before anyone presses Finish, someone changes one day at Google,
// in the series as Google still holds it (10:00): Fri 8 Jan is deleted, or renamed, or Wed 6 Jan gets other notes.
// Then Finish.
//
// What the contract says (google-calendar.md, What Writes Send; series-model.md, Recovery): a change made at
// Google since is kept and named where the change keeps the day's key. A new clock time gives every day another
// key, and which key a day of the 10:00 series has in the 11:00 one is the planner's to say, so nothing Google
// holds at 10:00 is carried by its key: after Finish the series is at 11:00 exactly as planned, no other day is
// touched, and the change made at Google is named once, by its date, with what was done and that it is not kept
// because the series' time changed. No request goes to an id Google does not hold: once the series is at 11:00,
// `<id>_20270108T180000Z` (Fri 8 Jan at 10:00) names nothing there. (The hunter's probe expected the change kept
// on its date; the restated rule names it instead, and these rows assert the sentence.)
//
// The control is the same sequence with the master write taken and only its answer lost, where the day changed at
// Google is a day of the 11:00 series: there the change at Google is kept, as the hunter wrote it.
//
// Every expected value is written from the contract by date; nothing in server/src computes one. Google is the edit
// harness's stand-in, default settings. Whole-series undo is off (the 1.5.5 default). Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    // A date as the app words one: the year only when it is not this year.
    $day = static fn(string $ymd): string => (new DateTimeImmutable($ymd))->format(substr($ymd, 0, 4) === gmdate('Y') ? 'D j M' : 'D j M Y');
    $build = static function () use ($db, $la): object {
        $t = H::make($db, ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=DAILY;COUNT=8']);
        H::this($t, '2027-01-06T10:00:00-08:00', ['description' => 'Notes A']);
        return $t;
    };
    $to11 = ['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04T11:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00'];
    // The calendar by date once the series is at 11:00 and nothing else changed.
    $plain = static function (array $over = [], array $gone = []): array {
        $out = [];
        foreach (['04', '05', '06', '07', '08', '09', '10', '11'] as $d) {
            if (in_array($d, $gone, true)) {
                continue;
            }
            $out[] = "2027-01-$d 11:00|" . ($over[$d] ?? ($d === '06' ? 'Sample series|Notes A|' : 'Sample series||'));
        }
        return $out;
    };
    $cases = [
        // [the request made at Google, the day's date, its body, the calendar by date where the change is kept (the control), what was done in words]
        'Fri 8 Jan deleted at Google' => ['DELETE', '2027-01-08', null, $plain([], ['08']), 'deleted'],
        'Fri 8 Jan renamed at Google' => ['PATCH', '2027-01-08', ['summary' => 'Renamed at Google'], $plain(['08' => 'Renamed at Google||']), 'renamed'],
        'Wed 6 Jan (the day with its own notes) given other notes at Google' => ['PATCH', '2027-01-06', ['description' => 'Notes from Google'], $plain(['06' => 'Sample series|Notes from Google|']), 'given other notes'],
    ];
    // The sibling on a split: "this and following" from Wed 6 Jan, renamed (no time changes at all). Its first write
    // (the old series' new end) is sent and not taken; Fri 8 Jan, a day of the part, is deleted at Google in the series
    // as Google still holds it; then Finish. By date: Mon 4 and Tue 5 Jan keep the old name, Wed 6 to Mon 11 Jan take
    // the new one and Wed 6 Jan keeps its notes. Fri 8 Jan is past the old series' new end, where no step can reach it,
    // and the series that follows is made whole as planned: the deletion is named as not kept, because this change split
    // the series before that day. (The hunter's probe
    // expected it kept in the new series; carrying a change from one series of a split into the other needs the two
    // read together and the planner's word on whether the part kept its keys, so it is named.)
    scenario('F2 sibling: this and following renamed, its first write sent and not taken, Fri 8 Jan deleted at Google, then Finish', function () use ($build, $la, $day): void {
        $name = 'F2 sibling (a split renamed from Wed 6 Jan on; Fri 8 Jan deleted at Google)';
        $t = $build();
        try {
            $t->s->knobs->writes = 0;
            $t->s->knobs->netAt = 1;
            [, $stopped] = H::tryChange($t, ['scope' => 'following', 'instanceStart' => '2027-01-06T10:00:00-08:00', 'title' => 'Renamed part']);
            $t->s->knobs->netAt = null;
            $t->s->knobs->down = false;
            check("$name: the change stops half done and Review lists it", $stopped !== null && count(H::halfDone($t)) === 1, (string) $stopped);
            [$status] = H::atGoogle($t, 'DELETE', '/events/' . rawurlencode($t->gid . '_20270108T180000Z'));
            checkEq("$name: the stand-in takes the deletion of Fri 8 Jan", 204, $status);
            $from = count($t->s->fake->requests);
            [$out, $refused] = H::recover($t, 'finish');
            check("$name: Finish completes", $refused === null, (string) $refused);
            $now = H::settled($t, $name, $la, '2027-01-01', '2027-01-20');
            $expect = ['2027-01-04 10:00|Sample series||', '2027-01-05 10:00|Sample series||', '2027-01-06 10:00|Renamed part|Notes A|', '2027-01-07 10:00|Renamed part||',
                '2027-01-08 10:00|Renamed part||', '2027-01-09 10:00|Renamed part||', '2027-01-10 10:00|Renamed part||', '2027-01-11 10:00|Renamed part||'];
            checkEq("$name: the deletion made at Google is named once, by its date, as not kept",
                ['While this change was waiting to be finished, ' . $day('2027-01-08') . ' was deleted in Google Calendar. That was lost when this change split the event before that day. Make it again there if you still want it.'], $out['differences'] ?? null);
            checkEq("$name: Finish sends nothing to a day past the old series' new end", [], array_values(array_filter(H::writes($t, $from), static fn(string $w): bool => str_contains($w, '_20270108T180000Z') && str_starts_with($w, 'PATCH ' . $t->gid . '_'))));
            check("$name: by date, the part is renamed and whole as planned", $now === $expect,
                "\n   missing: " . implode("\n            ", array_diff($expect, $now)) . "\n   extra:   " . implode("\n            ", array_diff($now, $expect))
                . "\n   the answer's differences: " . json_encode($out['differences'] ?? null) . "\n   Finish wrote:\n     " . implode("\n     ", array_map(static fn(string $w): string => mb_substr($w, 0, 160), H::writes($t, $from))));
            check("$name: the answer does not call the deletion made at Google the plan's", !str_contains(implode(' ', (array) ($out['differences'] ?? [])), 'this change deleted it, but Google Calendar kept it'), json_encode($out['differences'] ?? null));
        } finally {
            H::done($t);
        }
    });

    foreach (['sent and not taken (the network down)' => 'netAt', 'taken, its answer lost (the control)' => 'loseAt'] as $how => $knob) {
        foreach ($cases as $what => [$method, $date, $payload, $kept, $done]) {
            // Sent and not taken: the plan whole, the change named. Taken: the change kept, as the hunter wrote it.
            $expect = $knob === 'netAt' ? $plain() : $kept;
            scenario("F2: 10:00 to 11:00 for all events, the master write $how, $what, then Finish", function () use ($build, $to11, $la, $how, $knob, $what, $method, $date, $payload, $expect, $done, $day, $plain): void {
                $name = "F2 (master write $how; $what)";
                $t = $build();
                try {
                    $t->s->knobs->writes = 0;
                    $t->s->knobs->$knob = 1;
                    [, $stopped] = H::tryChange($t, $to11);
                    $t->s->knobs->$knob = null;
                    $t->s->knobs->down = false;
                    check("$name: the change stops half done and Review lists it", $stopped !== null && count(H::halfDone($t)) === 1, (string) $stopped);
                    $clock = isset($t->s->fake->events[$t->gid]['start']['dateTime']) ? substr((string) $t->s->fake->events[$t->gid]['start']['dateTime'], 11, 5) : '?';
                    checkEq("$name: after the stop Google holds the series at " . ($knob === 'netAt' ? '10:00 still' : '11:00'), $knob === 'netAt' ? '10:00' : '11:00', $clock);
                    // The day as another client finds it: in Google's own listing of the series, by its date in Los Angeles.
                    $target = null;
                    [, $list] = H::atGoogle($t, 'GET', '/events/' . rawurlencode($t->gid) . '/instances?maxResults=100');
                    foreach ($list['items'] ?? [] as $i) {
                        if (isset($i['start']['dateTime']) && (new DateTimeImmutable($i['start']['dateTime']))->setTimezone(new DateTimeZone($la))->format('Y-m-d') === $date) {
                            $target = (string) $i['id'];
                        }
                    }
                    [$status] = H::atGoogle($t, $method, '/events/' . rawurlencode((string) $target), $payload);
                    check("$name: the stand-in takes the change made at Google ($method $target)", in_array($status, [200, 204], true), (string) $status);
                    $from = count($t->s->fake->requests);
                    [$out, $refused] = H::recover($t, 'finish');
                    check("$name: Finish completes", $refused === null, (string) $refused);
                    $writes = H::writes($t, $from);
                    // No request to an id Google does not hold: each instance id Finish wrote to names a live occurrence of the series as Google holds it after.
                    $nowhere = [];
                    foreach (array_slice($t->s->fake->requests, $from) as $r) {
                        if ($r['method'] === 'GET' || in_array('X-Harness: remote', $r['headers'], true)) {
                            continue;
                        }
                        $id = rawurldecode(basename($r['path']));
                        if (str_contains($id, '_')) {
                            [$s] = H::atGoogle($t, 'GET', '/events/' . rawurlencode($id));
                            if ($s !== 200) {
                                $nowhere[] = $r['method'] . ' ' . $id . ' (Google answers ' . $s . ' for it)';
                            }
                        }
                    }
                    checkEq("$name: Finish sends nothing to an id Google does not hold", [], $nowhere);
                    $now = H::settled($t, $name, $la, '2027-01-01', '2027-01-20');
                    if ($knob === 'netAt') {
                        checkEq("$name: the change made at Google is named once, by its date, as not kept",
                            ['While this change was waiting to be finished, ' . $day($date) . " was $done in Google Calendar. That was lost when the event's time changed. Make it again there if you still want it."], $out['differences'] ?? null);
                    }
                    check("$name: by date, the series is at 11:00 and " . ($knob === 'netAt' ? 'exactly as planned, no other day touched' : 'the change made at Google is kept'), $now === $expect,
                        "\n   missing: " . implode("\n            ", array_diff($expect, $now)) . "\n   extra:   " . implode("\n            ", array_diff($now, $expect))
                        . "\n   the answer's differences: " . json_encode($out['differences'] ?? null) . "\n   Finish wrote:\n     " . implode("\n     ", $writes));
                    // What the answer says is true: it names no day "the plan" never had, and never says "left as Google has it" of a value it did not leave.
                    $words = implode(' ', (array) ($out['differences'] ?? []));
                    check("$name: the answer does not call the change made at Google the plan's", !str_contains($words, "this change had 'Renamed at Google'") && !str_contains($words, 'this change deleted it, but Google Calendar kept it'), $words);
                    if (str_contains($words, "Google's notes were kept")) {
                        check("$name: the answer says the notes were left as Google has them, and they were", in_array('2027-01-06 11:00|Sample series|Notes from Google|', $now, true), $words . ' / ' . json_encode(array_values(array_filter($now, static fn(string $l): bool => str_starts_with($l, '2027-01-06')))));
                    }
                } finally {
                    H::done($t);
                }
            });
        }
    }
};
