<?php

declare(strict_types=1);

// #202, from the 1.5.0 implementation review: the zone "same moment" choice
// with a member reading. Its invented example: a weekly Tuesday and Thursday
// class at 18:00 in Los Angeles; Thursday 14 Jan dragged to Friday 15 Jan
// 18:00 Berlin, "same moment". Reported: "every Tuesday class moves to
// Monday". The issue's own expectation is that no class leaves its weekday
// for the day before (the pattern reading gives Wednesday and Friday; the
// decision recorded keeps each instant, the drag measured on the clock it
// was made on). Asserted: no class lands on a Monday in Los Angeles.
//
// Before series plans (1.3, 1.4) there is no zone choice: the request is the
// same drag sent with the new zone, as those clients sent it.

return ['id' => '202d', 'issue' => 202, 'title' => 'A drag to another zone at "the same moment" keeps Tuesdays on Tuesday', 'run' => function (Api $api, string $v): array {
    $la = 'America/Los_Angeles';
    $id = $api->create(['title' => 'Language class', 'start' => '2027-01-05T18:00:00-08:00', 'end' => '2027-01-05T19:00:00-08:00', 'tzid' => $la,
        'rrule' => 'FREQ=WEEKLY;BYDAY=TU,TH'])['eventId'];
    $thu = theOne($api->window('2027-01-13T00:00:00-08:00', '2027-01-16T00:00:00-08:00'), '2027-01-14', $la);
    $r = $api->change('PATCH', "/events/$id", ['scope' => 'all', 'instanceStart' => $thu['start'], 'tzid' => 'Europe/Berlin',
        'start' => '2027-01-15T18:00:00+01:00', 'end' => '2027-01-15T19:00:00+01:00'], ['zone' => 'moment', 'reading' => 'member']);
    $occ = array_values(array_filter($api->window('2027-01-17T00:00:00-08:00', '2027-02-14T00:00:00-08:00'), static fn(array $o): bool => $o['title'] === 'Language class'));
    $mondays = array_values(array_filter($occ, static fn(array $o): bool => substr(clockIn($o['start'], 'America/Los_Angeles'), 17) === 'Mon'));
    return [
        chk('the drag goes through', $r[0] < 300, "answered {$r[0]} " . code($r[1]) . ($r[2] !== null ? '; reading ' . implode('/', array_column(array_values(array_filter($r[2]['groups'], static fn(array $g): bool => $g['id'] === 'reading'))[0]['options'] ?? [], 'id')) : '')),
        chk('no class lands on a Monday in Los Angeles', $r[0] < 300 && $occ !== [] && $mondays === [], 'later, in Los Angeles: ' . brief($occ, $la, 4)),
    ];
}];
