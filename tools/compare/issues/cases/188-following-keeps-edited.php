<?php

declare(strict_types=1);

// #188: "This and following" deleted the dates after the split that had been
// edited on their own (and skipped ones came back). Asked for: carry them into
// the new series when the split leaves their times; when it moves the times,
// shift them or keep them, never drop them; and Undo putting them all back.

return ['id' => '188', 'issue' => 188, 'title' => '"This and following" keeps the later edited and skipped dates', 'run' => function (Api $api, string $v): array {
    $tz = 'America/Los_Angeles';
    $checks = [];
    $n = 0;
    foreach (['a split that keeps the times (new location)' => ['location' => 'Hall B'],
        'a split that moves the times (18:00 to 19:00)' => ['start' => '2027-01-12T19:00:00-08:00', 'end' => '2027-01-12T20:00:00-08:00']] as $what => $fields) {
        $name = 'Practice ' . ++$n;
        $id = $api->create(['title' => $name, 'start' => '2027-01-05T18:00:00-08:00', 'end' => '2027-01-05T19:00:00-08:00', 'tzid' => $tz,
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU'])['eventId'];
        $w = fn(): array => array_values(array_filter($api->window('2027-01-01T00:00:00-08:00', '2027-03-01T00:00:00-08:00'),
            static fn(array $o): bool => str_starts_with((string) $o['title'], $name)));
        $occ = $w();
        ok($api->call('PATCH', "/events/$id", ['scope' => 'this', 'instanceStart' => theOne($occ, '2027-01-26', $tz)['start'], 'title' => "$name, long set"]), 'edit 26 Jan');
        ok($api->call('DELETE', "/events/$id", ['scope' => 'this', 'instanceStart' => theOne($occ, '2027-02-02', $tz)['start']]), 'skip 2 Feb');
        $before = $w();
        $r = $api->change('PATCH', "/events/$id", ['scope' => 'following', 'instanceStart' => theOne($occ, '2027-01-12', $tz)['start']] + $fields);
        if ($r[0] >= 300) {
            $checks[] = chk("$what: the split is made", false, "answered {$r[0]} " . code($r[1]));
            continue;
        }
        $after = $w();
        $j26 = onDay($after, '2027-01-26', $tz);
        $checks[] = chk("$what: 26 Jan shows once with its own title", count($j26) === 1 && $j26[0]['title'] === "$name, long set",
            '26 Jan after: ' . (brief($j26, $tz) ?: 'nothing'));
        $f2 = onDay($after, '2027-02-02', $tz);
        $checks[] = chk("$what: skipped 2 Feb stays skipped", $f2 === [], '2 Feb after: ' . (brief($f2, $tz) ?: 'nothing'));
        $api->undoLatest();
        $back = $w();
        $checks[] = chk("$what: one Undo puts the series back as it was", brief($back, $tz, 99) === brief($before, $tz, 99),
            'after Undo: ' . brief(array_slice($back, 0, 6), $tz));
    }
    return $checks;
}];
