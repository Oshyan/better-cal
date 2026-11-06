<?php

declare(strict_types=1);

// #202, comment after the fifth review: duplicate decisions (a pair linked
// or dismissed) name a row and were cascade-deleted with it. Asked for: a
// row one of them names keeps its row when it would rejoin its series, as a
// day in a trip does; and (1.5.1's notes) undoing a delete of an edited day
// brings its duplicate decisions back.
//
// The pair is written as the background duplicate scan writes one, then
// marked dismissed as the owner's word on it does.

return ['id' => '202c', 'issue' => 202, 'title' => 'An edited day a duplicate decision names keeps it through a delete and its Undo', 'run' => function (Api $api, string $v): array {
    $tz = 'America/Los_Angeles';
    $checks = [];
    $setup = function (string $name) use ($api, $tz): array {
        $id = $api->create(['title' => $name, 'start' => '2027-01-05T18:00:00-08:00', 'end' => '2027-01-05T19:00:00-08:00', 'tzid' => $tz,
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU'])['eventId'];
        $j19 = theOne(array_values(array_filter($api->window('2027-01-18T00:00:00-08:00', '2027-01-21T00:00:00-08:00'),
            static fn(array $o): bool => (int) $o['eventId'] === (int) $id)), '2027-01-19', $tz);
        ok($api->call('PATCH', "/events/$id", ['scope' => 'this', 'instanceStart' => $j19['start'], 'title' => "$name, open night"]), 'edit 19 Jan');
        $edited = (int) $api->db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND user_id = ?', [$id, $api->userId]);
        $other = $api->create(['title' => "$name, open night", 'start' => '2027-01-19T18:00:00-08:00', 'end' => '2027-01-19T19:00:00-08:00', 'tzid' => $tz])['eventId'];
        $api->db->run("INSERT INTO event_duplicates (user_id, event_a, event_b, status, basis, decided_at) VALUES (?, ?, ?, 'dismissed', 'title', NOW())",
            [$api->userId, min($edited, (int) $other), max($edited, (int) $other)]);
        return [$id, $edited, (int) $other];
    };
    $pairOf = fn(int $other): ?array => $api->db->one('SELECT d.status, e.title FROM event_duplicates d JOIN events e ON e.id = IF(d.event_a = ?, d.event_b, d.event_a) WHERE ? IN (d.event_a, d.event_b)', [$other, $other]);

    [$id, $edited, $other] = $setup('Pottery');
    $d = $api->call('DELETE', "/events/$edited", []);
    $gone = $pairOf($other);
    $api->undoLatest();
    $back = $pairOf($other);
    $checks[] = chk('Undo of the edited day\'s delete brings its dismissed pair back', $d[0] < 300 && $back !== null && $back['status'] === 'dismissed',
        "delete answered {$d[0]}; pair after delete: " . ($gone === null ? 'gone' : $gone['status']) . '; after Undo: ' . ($back === null ? 'gone' : $back['status'] . " with '{$back['title']}'"));

    [$id, $edited, $other] = $setup('Pottery II');
    if (!$api->plans) {
        $checks[] = chk('a day a pair names keeps its row when it would rejoin', null, 'no rejoining before 1.5.0: an edited day keeps its row through every series change');
    } else {
        // Its only own field is its title; giving it the series' new title leaves it nothing of its own.
        $r = $api->change('PATCH', "/events/$id", ['scope' => 'all', 'title' => 'Pottery II, open night'], ['ownTitle' => 'give']);
        $row = $api->db->one('SELECT id FROM events WHERE id = ?', [$edited]);
        $pair = $pairOf($other);
        $checks[] = chk('a day a pair names keeps its row when it would rejoin', $r[0] < 300 && $row !== null && $pair !== null,
            "answered {$r[0]}; edited row " . ($row === null ? 'deleted' : 'kept') . '; pair ' . ($pair === null ? 'gone' : $pair['status']));
    }
    return $checks;
}];
