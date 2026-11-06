<?php

declare(strict_types=1);

// #201: an unbounded daily weekday list at a clock the spring change skips
// showed one hour in a short window of a later month and another in a long
// one, and a day edited from the short view showed twice in the long one.
// Asked for: the same clocks whatever the window, and the edited day once.
// The closing comments add the BYHOUR=2;BYMINUTE=30 spelling.

return ['id' => '201', 'issue' => 201, 'title' => 'An unbounded daily weekday list at a gap clock reads the same in any window', 'run' => function (Api $api, string $v): array {
    $tz = 'America/Los_Angeles';
    $checks = [];
    foreach (['FREQ=DAILY;BYDAY=MO,WE,FR', 'FREQ=DAILY;BYDAY=MO,WE,FR;BYHOUR=2;BYMINUTE=30'] as $i => $rule) {
        $name = "Early shift $i";
        $id = $api->create(['title' => $name, 'start' => '2027-03-01T02:30:00-08:00', 'end' => '2027-03-01T03:30:00-08:00', 'tzid' => $tz, 'rrule' => $rule])['eventId'];
        $mine = static fn(array $list): array => array_values(array_filter($list, static fn(array $o): bool => str_starts_with((string) $o['title'], $name)));
        $april = static fn(array $list): array => array_values(array_filter($list, static fn(array $o): bool => clockIn($o['start'], 'America/Los_Angeles') >= '2028-04-03'
            && clockIn($o['start'], 'America/Los_Angeles') < '2028-04-24'));
        $short = $april($mine($api->window('2028-04-03T00:00:00-07:00', '2028-04-24T00:00:00-07:00')));
        $long = $april($mine($api->window('2028-03-01T00:00:00-08:00', '2028-04-30T00:00:00-07:00')));
        $checks[] = chk("$rule: the same April 2028 clocks in a short and a long window", brief($short, $tz, 99) === brief($long, $tz, 99) && $short !== [],
            'short: ' . brief($short, $tz, 2) . ' | long: ' . brief($long, $tz, 2));
        $day = theOne($short, '2028-04-10', $tz);
        $e = $api->call('PATCH', "/events/$id", ['scope' => 'this', 'instanceStart' => $day['start'], 'title' => "$name, cover"]);
        $longAfter = onDay($mine($api->window('2028-03-01T00:00:00-08:00', '2028-04-30T00:00:00-07:00')), '2028-04-10', $tz);
        $checks[] = chk("$rule: a day edited from the short window shows once in the long one", $e[0] < 300 && count($longAfter) === 1,
            "edit answered {$e[0]}; long window, 10 Apr 2028: " . (brief($longAfter, $tz) ?: 'nothing'));
    }
    return $checks;
}];
