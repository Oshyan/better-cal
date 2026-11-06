<?php

declare(strict_types=1);

// #186 (1.4.5), first item: moving a repeating event to another calendar
// from the editor moved only that date, because Apply to stayed on "This
// event only" and the save split the date off. Asked for: changing the
// calendar of a repeating event switches Apply to to "All events in series".
//
// The fix is the editor's: what the server does with each scope is the same
// before and after. The editor (web/src/app/EditorDrawer.js, a Preact
// component) can't be driven from PHP, so its half is read from the
// checkout's source: a calendar pick on a repeating occurrence that sets the
// scope to "all". The server's half is driven: a calendar change sent with
// the scope the editor then sends moves the whole series.

return ['id' => '186', 'issue' => 186, 'title' => 'A calendar change from the editor moves the whole series', 'run' => function (Api $api, string $v): array {
    $tz = 'America/Los_Angeles';
    $root = dirname((new \ReflectionClass(\BetterCal\Domain\Events::class))->getFileName(), 4);
    $src = (string) @file_get_contents($root . '/web/src/app/EditorDrawer.js');
    // The calendar select's handler: a function that sets calendarId and, for
    // a recurring occurrence, the scope to 'all'.
    $found = (bool) preg_match('/calendarId:\s*v\b[\s\S]{0,300}?recurring[\s\S]{0,300}?setScope\(\'all\'\)/', $src, $m);
    $line = $found ? substr_count(substr($src, 0, (int) strpos($src, $m[0])), "\n") + 1 : 0;
    $checks = [chk('picking another calendar for a repeating event sets Apply to to All events (editor source)', $found,
        $src === '' ? 'no EditorDrawer.js' : ($found ? "EditorDrawer.js:$line sets scope 'all' on a calendar pick" : "EditorDrawer.js: the calendar select only sets calendarId; Apply to stays 'this'"))];

    $calendars = new \BetterCal\Domain\Calendars($api->db, $api->undo, str_repeat('t', 32));
    $other = (int) $calendars->create($api->userId, ['name' => 'Second calendar'])['id'];
    $id = $api->create(['title' => 'Team lunch', 'start' => '2027-01-05T12:00:00-08:00', 'end' => '2027-01-05T13:00:00-08:00', 'tzid' => $tz,
        'rrule' => 'FREQ=WEEKLY;BYDAY=TU'])['eventId'];
    $occ = array_values(array_filter($api->window('2027-01-01T00:00:00-08:00', '2027-02-01T00:00:00-08:00'), static fn(array $o): bool => $o['title'] === 'Team lunch'));
    $scope = $found ? 'all' : 'this';
    $r = $api->change('PATCH', "/events/$id", ['scope' => $scope, 'instanceStart' => theOne($occ, '2027-01-19', $tz)['start'], 'calendarId' => $other]);
    $after = array_values(array_filter($api->window('2027-01-01T00:00:00-08:00', '2027-02-01T00:00:00-08:00'), static fn(array $o): bool => $o['title'] === 'Team lunch'));
    $onOther = array_values(array_filter($after, static fn(array $o): bool => (int) $o['calendarId'] === $other));
    $checks[] = chk("the save (scope '$scope', as this editor sends it) moves every date", $r[0] < 300 && count($onOther) === count($after) && count($after) === count($occ),
        "answered {$r[0]}; " . count($onOther) . ' of ' . count($after) . ' dates on the second calendar');
    return $checks;
}];
