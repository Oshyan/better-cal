<?php

declare(strict_types=1);

// #189 (fixed in 1.5.0 and 1.5.1 by the changelog; the issue is still open):
// "All events in series" from a date edited on its own changed only that
// date. Asked for: apply what changed to the whole series, keeping this
// date's own edits, and the same for a title change, a time change. 1.5.1's
// changelog adds delete, an answer or a relationship for all events from an
// edited day, and the choice to make that day's moved date the series' own.

return ['id' => '189', 'issue' => 189, 'title' => '"All events" from a date edited on its own changes the series', 'run' => function (Api $api, string $v): array {
    $tz = 'America/Los_Angeles';
    $n = 0;
    $series = function (array $ownEdit = []) use ($api, $tz, &$n): array {
        $name = 'Book club ' . ++$n;
        $id = $api->create(['title' => $name, 'start' => '2027-01-05T18:00:00-08:00', 'end' => '2027-01-05T19:00:00-08:00', 'tzid' => $tz,
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU'])['eventId'];
        $w = fn(): array => array_values(array_filter($api->window('2027-01-01T00:00:00-08:00', '2027-03-01T00:00:00-08:00'),
            static fn(array $o): bool => str_starts_with((string) $o['title'], $name)));
        $j19 = theOne($w(), '2027-01-19', $tz);
        ok($api->call('PATCH', "/events/$id", ['scope' => 'this', 'instanceStart' => $j19['start'], 'title' => "$name, guest night"] + $ownEdit), 'edit 19 Jan');
        $own = array_values(array_filter($w(), static fn(array $o): bool => (int) $o['eventId'] !== (int) $id));
        if (count($own) !== 1) {
            throw new \RuntimeException('expected one edited day with its own row, got ' . count($own));
        }
        return [$id, $own[0], $w, $name];
    };
    $checks = [];

    // A title change from the edited day, all events.
    [$id, $own, $w, $name] = $series();
    $r = $api->change('PATCH', "/events/{$own['eventId']}", ['scope' => 'all', 'instanceStart' => $own['start'], 'title' => "$name renamed"]);
    $others = array_merge(onDay($w(), '2027-01-12', $tz), onDay($w(), '2027-01-26', $tz));
    $checks[] = chk('a title change reaches the other days', $r[0] < 300 && count($others) === 2 && $others[0]['title'] === "$name renamed" && $others[1]['title'] === "$name renamed",
        "answered {$r[0]}; 12 and 26 Jan: " . brief($others, $tz));

    // A time change from the edited day, all events; the day keeps its own title.
    [$id, $own, $w, $name] = $series();
    $r = $api->change('PATCH', "/events/{$own['eventId']}", ['scope' => 'all', 'instanceStart' => $own['start'],
        'start' => '2027-01-19T19:00:00-08:00', 'end' => '2027-01-19T20:00:00-08:00']);
    $after = $w();
    $others = array_merge(onDay($after, '2027-01-12', $tz), onDay($after, '2027-01-26', $tz));
    $j19 = onDay($after, '2027-01-19', $tz);
    $checks[] = chk('a time change moves the other days, the edited day keeps its title',
        $r[0] < 300 && count($others) === 2 && substr(clockIn($others[0]['start'], $tz), 11, 5) === '19:00' && substr(clockIn($others[1]['start'], $tz), 11, 5) === '19:00'
            && count($j19) === 1 && $j19[0]['title'] === "$name, guest night",
        "answered {$r[0]}; 12, 19 and 26 Jan: " . brief(array_merge(onDay($after, '2027-01-12', $tz), $j19, onDay($after, '2027-01-26', $tz)), $tz));

    // Delete all events from the edited day.
    [$id, $own, $w, $name] = $series();
    $r = $api->change('DELETE', "/events/{$own['eventId']}", ['scope' => 'all']);
    $left = $w();
    $checks[] = chk('delete all events from the edited day deletes the series', $r[0] < 300 && $left === [],
        "answered {$r[0]}; left: " . (brief($left, $tz) ?: 'nothing'));

    // A relationship for all events from the edited day.
    [$id, $own, $w, $name] = $series();
    $r = $api->change('POST', "/events/{$own['eventId']}/relationship", ['scope' => 'all', 'relationship' => 'maybe']);
    $others = array_merge(onDay($w(), '2027-01-12', $tz), onDay($w(), '2027-01-26', $tz));
    $rel = array_map(static fn(array $o): string => (string) ($o['relationship'] ?? ''), $others);
    $checks[] = chk('maybe for all events from the edited day reaches the other days', $r[0] < 300 && $rel === ['maybe', 'maybe'],
        "answered {$r[0]}; 12 and 26 Jan relationship: " . implode(', ', $rel));

    // From a day moved to its own date, the choice to make that date the series' own (1.5.1).
    [$id, $own, $w, $name] = $series(['start' => '2027-01-20T18:00:00-08:00', 'end' => '2027-01-20T19:00:00-08:00']);
    if (!$api->plans) {
        $checks[] = chk("a moved day's date offered as the series' own", null, 'no series plan before 1.5.0: the choice is a plan group, so there is nothing to offer it in');
    } else {
        [$status, $body] = $api->call('PATCH', "/events/{$own['eventId']}", ['scope' => 'all', 'instanceStart' => $own['start'],
            'start' => '2027-01-21T18:00:00-08:00', 'end' => '2027-01-21T19:00:00-08:00', 'preview' => true, 'plan' => ['choices' => []]]);
        $day = array_values(array_filter($body['plan']['groups'] ?? [], static fn(array $g): bool => $g['id'] === 'day'))[0] ?? null;
        $opts = $day === null ? [] : array_column($day['options'], 'id');
        $checks[] = chk("a moved day's date offered as the series' own", $status === 200 && in_array('adopt', $opts, true) && in_array('same', $opts, true),
            "preview $status; groups: " . implode(', ', array_column($body['plan']['groups'] ?? [], 'id')) . '; day options: ' . implode(', ', $opts));
    }
    return $checks;
}];
