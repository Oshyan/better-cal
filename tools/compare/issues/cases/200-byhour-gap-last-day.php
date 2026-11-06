<?php

declare(strict_types=1);

// #200 (fixed in 1.5.0 by the changelog; the issue is still open): a rule
// naming its hour at a clock the spring change skips, with a count, lost its
// last day to a field-only edit: the edited last day became an event of its
// own and showed twice. Asked for: the last day shows once after a rename.

return ['id' => '200', 'issue' => 200, 'title' => 'A BYHOUR rule at a skipped clock keeps its last day through a rename', 'run' => function (Api $api, string $v): array {
    $tz = 'America/Los_Angeles';
    $id = $api->create(['title' => 'Night check', 'start' => '2027-03-01T02:30:00-08:00', 'end' => '2027-03-01T03:00:00-08:00', 'tzid' => $tz,
        'rrule' => 'FREQ=DAILY;BYHOUR=2;BYMINUTE=30;COUNT=40'])['eventId'];
    $w = fn(): array => array_values(array_filter($api->window('2027-02-25T00:00:00-08:00', '2027-05-01T00:00:00-07:00'),
        static fn(array $o): bool => str_starts_with((string) $o['title'], 'Night check')));
    $occ = $w();
    $last = end($occ);
    $lastDay = substr(clockIn($last['start'], $tz), 0, 10);
    ok($api->call('PATCH', "/events/$id", ['scope' => 'this', 'instanceStart' => $last['start'], 'title' => 'Night check, last one']), 'edit the last day');
    $r = $api->change('PATCH', "/events/$id", ['scope' => 'all', 'title' => 'Night round']);
    $after = $w();
    $onLast = onDay($after, $lastDay, $tz);
    $standalone = array_values(array_filter($api->rows(), static fn(array $row): bool => $row['rrule'] === null && ($row['recurrence_instance_utc'] ?? null) === null));
    return [
        chk('the rename goes through', $r[0] < 300, "answered {$r[0]} " . code($r[1])),
        chk('the last day shows once after the rename', count($onLast) === 1,
            count($occ) . " events before, last $lastDay; after: " . (brief($onLast, $tz) ?: 'nothing')),
        chk('no event of its own is made', $standalone === [], count($standalone) . ' standalone rows' . ($standalone !== [] ? ': ' . $standalone[0]['title'] . ' ' . $standalone[0]['start_utc'] : '')),
    ];
}];
