<?php

declare(strict_types=1);

// #202, "Onto the model": "this and following", delete this and following
// and an answer from a day on are splits previewed and confirmed like any
// other change (a split sent with no plan is refused with the plan, so
// nothing is done unsaid), and one Undo puts the whole split back.

return ['id' => '202a', 'issue' => 202, 'title' => 'Splits say what they will do first, and one Undo puts them back', 'run' => function (Api $api, string $v): array {
    $tz = 'America/Los_Angeles';
    $checks = [];
    $actions = [
        'edit following' => ['PATCH', '', ['title' => 'Choir, new hall']],
        'delete following' => ['DELETE', '', []],
        'maybe from this day on' => ['POST', '/relationship', ['relationship' => 'maybe']],
    ];
    $n = 0;
    foreach ($actions as $what => [$method, $tail, $fields]) {
        $name = 'Choir ' . ++$n;
        $id = $api->create(['title' => $name, 'start' => '2027-01-05T18:00:00-08:00', 'end' => '2027-01-05T19:00:00-08:00', 'tzid' => $tz,
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU'])['eventId'];
        $w = fn(): array => array_values(array_filter($api->window('2027-01-01T00:00:00-08:00', '2027-03-01T00:00:00-08:00'),
            static fn(array $o): bool => str_starts_with((string) $o['title'], $name) || str_starts_with((string) $o['title'], 'Choir, new hall')));
        $occ = $w();
        ok($api->call('PATCH', "/events/$id", ['scope' => 'this', 'instanceStart' => theOne($occ, '2027-01-26', $tz)['start'], 'location' => 'Side room']), 'edit 26 Jan');
        $rowsBefore = count($api->rows());
        $before = array_map(static fn(array $o): string => clockIn($o['start'], 'America/Los_Angeles') . '|' . $o['title'] . '|' . ($o['relationship'] ?? '') . '|' . ($o['location'] ?? ''), $w());
        $body = ['scope' => 'following', 'instanceStart' => theOne($occ, '2027-01-19', $tz)['start']] + $fields;
        // As sent by a client that doesn't ask first.
        [$status, $answer] = $api->call($method, "/events/$id$tail", $body);
        $refused = $status === 409 && code($answer) === 'series_plan_required' && isset($answer['error']['plan']['split']);
        $checks[] = chk("$what: sent with no plan, refused with the split's plan", $refused,
            "answered $status " . (code($answer) ?: 'ok, applied with nothing shown first'));
        if (!$refused) {
            if ($status < 300) {
                $api->undoLatest();
            }
        }
        $now = array_map(static fn(array $o): string => clockIn($o['start'], 'America/Los_Angeles') . '|' . $o['title'] . '|' . ($o['relationship'] ?? '') . '|' . ($o['location'] ?? ''), $w());
        if ($now !== $before) {
            $checks[] = chk("$what: one Undo puts the split back", false, 'the first try could not be undone to a clean start');
            continue;
        }
        $r = $api->change($method, "/events/$id$tail", $body);
        if ($r[0] >= 300) {
            $checks[] = chk("$what: one Undo puts the split back", false, "the split answered {$r[0]} " . code($r[1]));
            continue;
        }
        $api->undoLatest();
        $back = array_map(static fn(array $o): string => clockIn($o['start'], 'America/Los_Angeles') . '|' . $o['title'] . '|' . ($o['relationship'] ?? '') . '|' . ($o['location'] ?? ''), $w());
        $rowsBack = count($api->rows());
        $checks[] = chk("$what: one Undo puts the split back", $back === $before && $rowsBack === $rowsBefore,
            $back === $before ? "events as before; rows $rowsBefore then $rowsBack" : 'differs: ' . implode(', ', array_slice(array_diff($back, $before) ?: array_diff($before, $back), 0, 3)));
    }
    return $checks;
}];
