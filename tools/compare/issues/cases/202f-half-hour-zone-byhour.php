<?php

declare(strict_types=1);

// #202, from the 1.5.0 implementation review: a rule naming its hour in a
// zone whose clocks move by half an hour. FREQ=WEEKLY;BYDAY=FR;BYHOUR=3;
// BYMINUTE=0 on Lord Howe Island read from the series' start gave 03:30
// after the October change, read from a window after it 03:00, so an edited
// day keyed at 03:30 could sit beside a plain 03:00 occurrence. Its closing
// note: settled by 1.5.0's expander ("every rule naming its hours ...
// repeats as written") and migration 064. Asserted: November Fridays show at
// 03:00 in a short window and a long one alike, and a day edited from the
// long one shows once in the short one.

return ['id' => '202f', 'issue' => 202, 'title' => 'A BYHOUR rule in a half-hour zone reads as written in any window', 'run' => function (Api $api, string $v): array {
    $tz = 'Australia/Lord_Howe';
    $id = $api->create(['title' => 'Tide walk', 'start' => '2027-09-03T03:00:00+10:30', 'end' => '2027-09-03T04:00:00+10:30', 'tzid' => $tz,
        'rrule' => 'FREQ=WEEKLY;BYDAY=FR;BYHOUR=3;BYMINUTE=0'])['eventId'];
    $nov = static fn(array $list): array => array_values(array_filter($list, static fn(array $o): bool => $o['title'] === 'Tide walk'
        && clockIn($o['start'], 'Australia/Lord_Howe') >= '2027-11-01' && clockIn($o['start'], 'Australia/Lord_Howe') < '2027-12-01'));
    $short = $nov($api->window('2027-11-01T00:00:00+11:00', '2027-12-01T00:00:00+11:00'));
    $long = $nov($api->window('2027-09-01T00:00:00+10:30', '2027-12-01T00:00:00+11:00'));
    $clocks = array_values(array_unique(array_map(static fn(array $o): string => substr(clockIn($o['start'], 'Australia/Lord_Howe'), 11, 5), array_merge($short, $long))));
    $day = theOne($long, '2027-11-12', $tz);
    $e = $api->call('PATCH', "/events/$id", ['scope' => 'this', 'instanceStart' => $day['start'], 'title' => 'Tide walk, low tide']);
    $shortAfter = array_values(array_filter(onDay($api->window('2027-11-01T00:00:00+11:00', '2027-12-01T00:00:00+11:00'), '2027-11-12', $tz),
        static fn(array $o): bool => str_starts_with((string) $o['title'], 'Tide walk')));
    return [
        chk('November Fridays read at 03:00 in a short and a long window', $short !== [] && $clocks === ['03:00'],
            'short: ' . brief($short, $tz, 2) . ' | long: ' . brief($long, $tz, 2)),
        chk('a day edited from the long window shows once in the short one', $e[0] < 300 && count($shortAfter) === 1,
            "edit answered {$e[0]}; 12 Nov, short window: " . (brief($shortAfter, $tz) ?: 'nothing')),
    ];
}];
