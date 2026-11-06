<?php

declare(strict_types=1);

// #202, from the pre-release review: the following path wrote a rule out of
// step with its start: a series naming its hour (BYHOUR=13) with a start at
// 14:30, which the standard calls undefined and the expander reads as 13:30.
// Asked for: every rule written through the one writer that keeps the start
// and the rule in step. Asserted: after "this and following" moves a
// BYHOUR=13 series to 14:30, the new part's rule names no other clock and
// its days show at 14:30.

return ['id' => '202e', 'issue' => 202, 'title' => '"This and following" to a new time keeps a BYHOUR rule in step with its start', 'run' => function (Api $api, string $v): array {
    $tz = 'America/Los_Angeles';
    $id = $api->create(['title' => 'Standup', 'start' => '2027-01-05T13:00:00-08:00', 'end' => '2027-01-05T13:30:00-08:00', 'tzid' => $tz,
        'rrule' => 'FREQ=WEEKLY;BYDAY=TU;BYHOUR=13;BYMINUTE=0'])['eventId'];
    $j19 = theOne($api->window('2027-01-18T00:00:00-08:00', '2027-01-21T00:00:00-08:00'), '2027-01-19', $tz);
    $r = $api->change('PATCH', "/events/$id", ['scope' => 'following', 'instanceStart' => $j19['start'],
        'start' => '2027-01-19T14:30:00-08:00', 'end' => '2027-01-19T15:00:00-08:00']);
    $rules = array_values(array_filter(array_column($api->rows(), 'rrule')));
    $newRule = count($rules) > 1 ? end($rules) : ($rules[0] ?? '');
    $later = array_values(array_filter($api->window('2027-01-25T00:00:00-08:00', '2027-02-20T00:00:00-08:00'), static fn(array $o): bool => $o['title'] === 'Standup'));
    $clocks = array_values(array_unique(array_map(static fn(array $o): string => substr(clockIn($o['start'], 'America/Los_Angeles'), 11, 5), $later)));
    $inStep = !preg_match('/BYHOUR=(\d+)/', $newRule, $h) || ((int) $h[1] === 14 && preg_match('/BYMINUTE=30(;|$)/', $newRule));
    return [
        chk('the split goes through', $r[0] < 300, "answered {$r[0]} " . code($r[1])),
        chk("the new part's rule names no other clock", $r[0] < 300 && $inStep, "rules stored: " . implode(' | ', $rules)),
        chk('its days show at 14:30', $clocks === ['14:30'], 'from 25 Jan: ' . brief($later, $tz, 3)),
    ];
}];
