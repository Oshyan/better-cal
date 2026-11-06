<?php

declare(strict_types=1);

// #202, "Onto the model": a split is a bound change on the old series plus a
// new series mapped by member; its closing note, "the split's whole or part
// count readings", and 1.5.1's notes: "this and following" with the end left
// as it was keeps the series' number of events. Asserted on a series of 10
// moved to a new time from its fifth day: 10 events in all, both when the
// request carries the rule unchanged (as the editor sends it on every save)
// and when it carries none (a drag).

return ['id' => '202g', 'issue' => 202, 'title' => '"This and following" with the end left as it was keeps the count', 'run' => function (Api $api, string $v): array {
    $tz = 'America/Los_Angeles';
    $checks = [];
    foreach (['the rule sent unchanged' => ['rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=10'], 'no rule sent' => []] as $what => $more) {
        $name = 'Course ' . (count($checks) + 1);
        $id = $api->create(['title' => $name, 'start' => '2027-01-05T18:00:00-08:00', 'end' => '2027-01-05T19:00:00-08:00', 'tzid' => $tz,
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=10'])['eventId'];
        $w = fn(): array => array_values(array_filter($api->window('2027-01-01T00:00:00-08:00', '2027-07-01T00:00:00-07:00'),
            static fn(array $o): bool => $o['title'] === $name));
        $fifth = $w()[4];
        $r = $api->change('PATCH', "/events/$id", ['scope' => 'following', 'instanceStart' => $fifth['start'],
            'start' => '2027-02-02T19:00:00-08:00', 'end' => '2027-02-02T20:00:00-08:00'] + $more);
        $after = $w();
        $checks[] = chk("$what: 10 events in all", $r[0] < 300 && count($after) === 10,
            "answered {$r[0]}; " . count($after) . ' events, last ' . (end($after) ? clockIn(end($after)['start'], $tz) : '-'));
    }
    return $checks;
}];
