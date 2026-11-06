<?php

declare(strict_types=1);

// #202, "Onto the model": the editor and quick add send the clock a person
// typed, so a series typed to start in a skipped hour starts at that clock.
// "Today the server receives the shifted hour and stores it." And from the
// 1.5.1 notes it closes with: such a series shows its real first day where
// it is read, and every count shown is the events the series shows.
//
// A client before 1.5.1 sent the instant the browser makes of 02:30 on the
// spring-change day (03:30 PDT); 1.5.1's sends that and `typed` beside it.
// Both are sent here, so each version reads what its own client sent. The
// range typed is 02:30 to 04:30 (an hour, as the clocks run that day): typed
// as 02:30 to 03:30, both ends are the same instant and every version
// refuses it ("end must be after start").

return ['id' => '202b', 'issue' => 202, 'title' => 'A series typed into a skipped hour repeats at the clock typed', 'run' => function (Api $api, string $v): array {
    $tz = 'America/Los_Angeles';
    $made = $api->create(['title' => 'Bakery shift', 'start' => '2027-03-14T03:30:00-07:00', 'end' => '2027-03-14T04:30:00-07:00', 'tzid' => $tz,
        'rrule' => 'FREQ=DAILY;COUNT=5', 'typed' => ['start' => '2027-03-14T02:30', 'end' => '2027-03-14T04:30', 'tz' => $tz]]);
    $occ = array_values(array_filter($api->window('2027-03-10T00:00:00-08:00', '2027-03-25T00:00:00-07:00'),
        static fn(array $o): bool => $o['title'] === 'Bakery shift'));
    $later = array_values(array_filter($occ, static fn(array $o): bool => clockIn($o['start'], 'America/Los_Angeles') >= '2027-03-15'));
    $clocks = array_unique(array_map(static fn(array $o): string => substr(clockIn($o['start'], 'America/Los_Angeles'), 11, 5), $later));
    [$s, $one] = $api->call('GET', '/events/' . $made['eventId'] . '/occurrence');
    $rrule = (string) ($one['occurrence']['rrule'] ?? '');
    return [
        chk('the days after the change repeat at 02:30', $clocks === ['02:30'], '15 to 18 Mar: ' . brief($later, $tz, 4)),
        chk('the first day read is 14 Mar', substr(clockIn($made['start'], $tz), 0, 10) === '2027-03-14', 'create answered start ' . $made['start']),
        chk('five events shown, and the rule read says COUNT=5', count($occ) === 5 && str_contains($rrule, 'COUNT=5'), count($occ) . " shown; rrule read: $rrule"),
    ];
}];
