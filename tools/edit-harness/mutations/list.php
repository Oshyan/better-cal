<?php
// The mutations (README, Mutations): each a small patch to a copy of one
// file, the check it must trip, and the fixed cases that show it. A patch is
// a list of [find, replace] pairs; each find must occur exactly once in the
// file, so a change to the code it names fails the runner loudly instead of
// testing nothing. `cases` are names under cases/ or mutations/cases/; a
// mutation with `dbtest` runs that database test file instead of the harness.
declare(strict_types=1);

$model = 'server/src/Domain/Series/Model.php';

// The assertion labels of undo-trips.test.php a trip-rule mutation must fail: for one of its undo cases, the refusal on
// every undo path, the undo once the conflict is gone on the paths given, and the refusal's words (checked on undoById).
$undoPaths = ['undoLatest', 'undoById', 'undoById force', 'undoRun strict', 'undoRun'];
$undoCase = static fn(string $case, array $undoes): array => array_merge(
    array_map(static fn(string $p): string => "$case ($p): refused with undo_conflict, nothing written (every row, link and entry as before), the entry still to undo", $undoPaths),
    array_map(static fn(string $p): string => "$case ($p): once the conflict is gone, the entry undoes", $undoes),
    ["$case: the refusal names the rows and the rule"],
);

return [
    [
        'id' => 'placement-zone', 'file' => $model, 'checks' => ['c-shape', 'c-placement'],
        'what' => 'the wrong zone at one placement site: an edited day\'s after written in UTC',
        'patch' => [["return \$this->placementOut(\$after, \$this->displayZone()) + (\$all", "return \$this->placementOut(\$after, 'UTC') + (\$all"]],
        'cases' => ['acted-all-day-day-of-a-timed-series-from-its-row', 'mutation-drag-with-edited-days-in-other-zones'],
    ],
    [
        'id' => 'to-from-pattern-day', 'file' => $model, 'checks' => ['c-placement'],
        'what' => 'an edited day\'s to from a pattern day (its image, a day of the new rule) beside an at from its instant',
        'patch' => [["'title' => (string) \$row['title'], 'outcome' => \$moved ? 'moves' : 'stays'] + \$sides", "'title' => (string) \$row['title'], 'outcome' => \$moved ? 'moves' : 'stays', 'to' => self::patternOut(\$calc1, (int) \$img)] + \$sides"]],
        'cases' => ['mutation-drag-with-edited-days-in-other-zones', 'mutation-drag-with-a-day-at-its-own-date'],
    ],
    [
        'id' => 'day-from-before', 'file' => $model, 'checks' => ['c-placement'],
        'what' => 'day taken from the before placement instead of the old key\'s day',
        'patch' => [["\$out = ['day' => \$this->identityOut((string) \$row['recurrence_instance_utc'])]", "\$out = ['day' => \$this->placementOut(\$row, \$this->displayZone())['date']]"]],
        'cases' => ['next-edited-days-past-the-raw-end', 'mutation-drag-with-edited-days-in-other-zones'],
    ],
    [
        'id' => 'earlier-min-across-series', 'file' => $model, 'checks' => ['c-placement'],
        // Both halves of a min across two series: where the readings differ (two zones' day numbers taken as the same day) and which
        // comes first (the smaller day number), in place of the model's instant-and-kind comparison and instant tie-break.
        'what' => 'earlier as a min across two series: two zones\' day numbers compared as days, to find where they differ and which comes first',
        'patch' => [
            ['                if ($mine !== null && $was !== null && $s[\'calc\']->instant($mine) == $this->old->instant($was) && $s[\'calc\']->allDay === $this->old->allDay) {', '                if ($mine === $was) {'],
            ['                $new = $mine !== null && ($was === null || $s[\'calc\']->instant($mine) < $this->old->instant($was));', '                $new = $mine !== null && ($was === null || $mine < $was);'],
        ],
        'cases' => ['mutation-earlier-across-zones', 'reading-earlier-by-instant-across-zones'],
    ],
    [
        'id' => 'start-as-key', 'file' => $model, 'checks' => ['c-shape'],
        'what' => 'every series\' start written as a key, the form only an anchored series\' start has',
        'patch' => [["\$startIso = \$calc1->anchor !== null ? self::keyOut(Time::toDb(\$start1)) : self::inZoneOut(\$start1, \$this->planZone());", "\$startIso = self::keyOut(Time::toDb(\$start1));"]],
        'cases' => ['next-trailing-skipped-occurrences', 'acted-timed-day-of-an-all-day-series-from-its-row'],
    ],
    [
        'id' => 'next-drops-acted', 'file' => $model, 'checks' => ['c-next'],
        'what' => 'the acted occurrence dropped from coming up',
        'patch' => [["if (isset(\$skip[\$key]) || isset(\$byKey[\$key])) {\n                continue;\n            }\n            \$plain[]", "if (isset(\$skip[\$key]) || isset(\$byKey[\$key]) || \$d === \$actedDn) {\n                continue;\n            }\n            \$plain[]"]],
        'cases' => ['next-edited-days-past-the-raw-end', 'next-a-daily-series-thirteen-years-old'],
    ],
    [
        'id' => 'next-drops-detached-acted', 'file' => $model, 'checks' => ['c-next'],
        'what' => 'the detached acted day dropped from coming up',
        'patch' => [["        if (\$separate !== null) {\n            // The day acted on, where it was put, on its own.", "        if (false && \$separate !== null) {\n            // The day acted on, where it was put, on its own."]],
        'cases' => ['mutation-acted-day-on-its-own'],
    ],
    [
        'id' => 'next-drops-own-zone-row', 'file' => $model, 'checks' => ['c-next'],
        'what' => 'a timed row of an all-day plan, in its own zone, dropped from coming up',
        'patch' => [["if (!isset(\$skip[\$key]) && \$item['dn'] >= \$fromDn) {", "if (!isset(\$skip[\$key]) && \$item['dn'] >= \$fromDn && !isset(\$item['tz'])) {"]],
        'cases' => ['acted-timed-day-of-an-all-day-series-from-its-row', 'acted-timed-day-of-an-all-day-series-through-the-series'],
    ],
    [
        'id' => 'next-empty', 'file' => $model, 'checks' => ['c-next'],
        'what' => 'an empty coming-up list',
        'patch' => [["return [\$shown, count(\$out) > self::NEXT_SHOWN, \$fromDn];", "return [[], count(\$out) > self::NEXT_SHOWN, \$fromDn];"]],
        'cases' => ['next-trailing-skipped-occurrences', 'next-several-occurrences-on-one-day'],
    ],
    [
        'id' => 'next-truncated', 'file' => $model, 'checks' => ['c-next'],
        'what' => 'a coming-up list one short',
        'patch' => [["}, array_slice(\$out, 0, self::NEXT_SHOWN));", "}, array_slice(\$out, 0, self::NEXT_SHOWN - 1));"]],
        'cases' => ['next-a-daily-series-thirteen-years-old', 'next-edited-days-past-the-raw-end'],
    ],
    [
        'id' => 'nextmore-raw-bound', 'file' => $model, 'checks' => ['c-next'],
        'what' => 'nextMore from the raw bound, not from the eligible set',
        'patch' => [["            'nextMore' => \$nextMore,", "            'nextMore' => \$boundKind === 'none',"]],
        'cases' => ['next-several-occurrences-on-one-day', 'next-trailing-skipped-occurrences'],
    ],
    [
        'id' => 'all-day-plan-timed-row-in-utc', 'file' => $model, 'checks' => ['c-placement'],
        'what' => 'the all-day plan\'s timed row written in UTC with tz "UTC" (c-shape cannot see it: a consistent instant in a named zone)',
        'patch' => [["        \$local = self::localOf(\$row, \$zone);\n        if ((int) \$row['all_day'] === 1) {\n            return ['date' => \$local->format('Y-m-d')];\n        }\n        return ['date' => \$local->format('Y-m-d'), 'at' => Time::iso(\$local)] + \$this->zoneBeside(\$zone ?? self::rowZone(\$row));",
            "        \$local = self::localOf(\$row, \$zone ?? ((int) \$row['all_day'] === 1 ? null : 'UTC'));\n        if ((int) \$row['all_day'] === 1) {\n            return ['date' => \$local->format('Y-m-d')];\n        }\n        return ['date' => \$local->format('Y-m-d'), 'at' => Time::iso(\$local)] + \$this->zoneBeside(\$zone ?? 'UTC');"]],
        'cases' => ['acted-timed-day-of-an-all-day-series-from-its-row', 'acted-timed-day-of-an-all-day-series-through-the-series'],
    ],
    [
        'id' => 'outcome-from-dates', 'file' => $model, 'checks' => ['c-placement'],
        'what' => 'a kind change\'s outcome taken from dates',
        'patch' => [["'title' => (string) \$row['title'], 'outcome' => \$moved ? 'moves' : 'stays'] + \$sides", "'title' => (string) \$row['title'], 'outcome' => \$sides['before']['date'] !== (\$sides['after']['date'] ?? null) ? 'moves' : 'stays'] + \$sides"]],
        'cases' => ['mutation-all-day-series-made-timed-with-edited-days'],
    ],
    [
        'id' => 'next-sorted-by-utc-midnight', 'file' => $model, 'checks' => ['c-next'],
        'what' => 'an all-day entry sorted by its UTC midnight, among the instants',
        'patch' => [["\$order = static fn(array \$e): array => [substr(\$e['start'], 0, 10), \$e['allDay'] ? 0 : 1, Time::parseIso(\$e['start'])->getTimestamp(), \$e['key']];", "\$order = static fn(array \$e): array => [Time::parseIso(\$e['start'])->getTimestamp(), \$e['key']];"]],
        'cases' => ['mutation-all-day-day-in-a-daily-evening-series'],
    ],
    [
        'id' => 'notes-dropped', 'file' => $model, 'checks' => ['c-placement'],
        'what' => 'every note on a clock that doesn\'t exist or happens twice dropped',
        'patch' => [["        if (\$this->allDay1 || \$calc->tz === 'UTC') {\n            return;\n        }\n        \$tz = Time::zone(\$calc->tz);", "        if (true || \$this->allDay1 || \$calc->tz === 'UTC') {\n            return;\n        }\n        \$tz = Time::zone(\$calc->tz);"]],
        'cases' => ['note-a-clock-in-the-autumn-overlap', 'note-a-clock-in-the-spring-gap', 'note-a-half-hour-repeated-clock-on-lord-howe'],
    ],
    [
        'id' => 'untouched-unrelated-column', 'file' => $model, 'checks' => ['c-untouched'],
        'what' => 'an unrelated column (the icon) changed on the series',
        'patch' => [["                'master' => \$masterCols,", "                'master' => \$masterCols + ['icon' => 'sample'],"]],
        'cases' => ['next-trailing-skipped-occurrences'],
    ],
    [
        'id' => 'untouched-control-event', 'file' => 'server/src/Domain/SeriesPlans.php', 'checks' => ['c-untouched'],
        'what' => 'a commit that writes the account\'s other events: every event of its own made before the series takes the new place',
        'patch' => [["            if (\$w['master'] !== []) {\n", "            \$this->db->run(\"UPDATE events SET location = 'Sample Annex' WHERE user_id = ? AND id < ? AND recurrence_parent_id IS NULL AND rrule IS NULL\", [\$userId, \$id]);\n            if (\$w['master'] !== []) {\n"]],
        'cases' => ['next-trailing-skipped-occurrences'],
    ],
    [
        'id' => 'untouched-own-placement-entry', 'file' => $model, 'checks' => ['c-untouched'],
        'what' => 'an edited day\'s own date dropped from its own list under a drag that keeps its placement (allowed when c-untouched went by edit kind)',
        'patch' => [["\$encoded = OwnFields::encode(\$ownAfter);", "\$encoded = OwnFields::encode(array_values(array_diff(\$ownAfter, ['date'])));"]],
        'cases' => ['mutation-drag-with-a-day-at-its-own-date'],
    ],
    [
        'id' => 'untouched-insertion', 'file' => $model, 'checks' => ['c-untouched'],
        'what' => 'an event inserted outside the set',
        'patch' => [["                'create' => \$create,", "                'create' => array_merge(\$create, [['start_utc' => '2091-06-01 10:00:00', 'end_utc' => '2091-06-01 11:00:00', 'all_day' => 0, 'tzid' => 'UTC']]),"]],
        'cases' => ['next-trailing-skipped-occurrences'],
    ],
    [
        'id' => 'untouched-extra-link', 'file' => 'server/src/Domain/SeriesPlans.php', 'checks' => ['c-untouched'],
        // Both flags stay false, so the link is dormant and the trip rule allows it; it is journaled, so Undo takes it away.
        'what' => 'a commit that links the series, as a trip, to the account\'s latest event of its own made before it',
        'patch' => [["            \$now = Time::nowDb();\n            if (\$w['master'] !== []) {\n", "            \$now = Time::nowDb();\n            \$other = \$this->db->scalar('SELECT id FROM events WHERE user_id = ? AND id < ? AND recurrence_parent_id IS NULL AND rrule IS NULL ORDER BY id DESC LIMIT 1', [\$userId, \$id]);\n            if (\$other !== null) {\n                \$this->db->run('INSERT IGNORE INTO event_links (container_id, event_id) VALUES (?, ?)', [\$id, (int) \$other]);\n            }\n            if (\$w['master'] !== []) {\n"]],
        'cases' => ['next-trailing-skipped-occurrences'],
    ],
    [
        'id' => 'untouched-created-row-tags', 'file' => 'server/src/Domain/SeriesPlans.php', 'checks' => ['c-untouched'],
        'what' => 'the event of its own a commit creates for the day acted on made without the series\' tags',
        'patch' => [["                \$created[] = \$newId;\n", "                \$this->db->run('DELETE FROM event_tags WHERE event_id = ?', [\$newId]);\n                \$created[] = \$newId;\n"]],
        'cases' => ['mutation-acted-day-on-its-own-takes-the-series-tags'],
    ],
    [
        'id' => 'untouched-extra-calendar', 'file' => 'server/src/Domain/SeriesPlans.php', 'checks' => ['c-untouched'],
        'what' => 'a commit that adds a calendar to the account',
        'patch' => [["            \$now = Time::nowDb();\n            if (\$w['master'] !== []) {\n", "            \$now = Time::nowDb();\n            \$this->db->insert('calendars', ['user_id' => \$userId, 'name' => 'Sample extra', 'color' => '#336699', 'kind' => 'local', 'position' => 999]);\n            if (\$w['master'] !== []) {\n"]],
        'cases' => ['next-trailing-skipped-occurrences'],
    ],
    // The trip rule's two mutations patch its query (Trips::assertRules, which every writer and Undo call).
    [
        'id' => 'undo-gate-clear-flag', 'file' => 'server/src/Domain/Trips.php', 'dbtest' => 'undo-trips',
        'what' => 'the shared trip rule (Trips::assertRules) lets a trip\'s flag be cleared while a live stop stands',
        'patch' => [["                        OR (c.is_container = 0 AND c.id IN \$was))", "                        OR (1 = 0 AND c.id IN \$was))"]],
        'checks' => array_merge(
            $undoCase('flag set, then a stop added', $undoPaths),
            $undoCase('the series made a trip for all events, then a stop added to a day', $undoPaths),
            ['turned off with a stop in it: 400 trip_has_members, naming both, nothing written'],
        ),
    ],
    [
        'id' => 'undo-gate-nesting', 'file' => 'server/src/Domain/Trips.php', 'dbtest' => 'undo-trips',
        'what' => 'the shared trip rule (Trips::assertRules) lets a link with both ends flagged stand',
        'patch' => [["((c.is_container = 1 AND (m.is_container = 1 OR l.container_id = l.event_id))", "((c.is_container = 1 AND (1 = 0 OR l.container_id = l.event_id))"]],
        // "Detach, then flag the stop" under "undo the last change": the undo goes through, so the check of what it put back fails instead.
        'checks' => array_merge(
            $undoCase('detach, then flag the stop', array_slice($undoPaths, 1)),
            ['detach, then flag the stop: undone, the stop is back in its trip and no trip itself'],
            $undoCase('flag cleared, then put in a trip', $undoPaths),
            $undoCase('the series turned off for all events, then a day put in a trip', $undoPaths),
            [
                'its old trip made a trip again: 400 trip_nested, naming both and the rule, nothing written',
                'the refusal names the rows and the rule',
                'then its stop made a trip: 400 trip_nested, nothing written',
                'the refusal reads the same from either end',
                'the series made a trip from the master for all events: 400 trip_nested, naming the day and its stop, nothing written',
                'the series made a trip from the edited day\'s own row for all events: 400 trip_nested, naming the day and its stop, nothing written',
                'the series made a trip the edited day alone: 400 trip_nested, naming the day and its stop, nothing written',
                'the series made a trip this and following from the first day: 400 trip_nested, naming the day and its stop, nothing written',
                'a trip attached to a trip, and a trip to itself: 400 trip_invalid each, naming the rule, nothing written',
                'a stop on a Google calendar renamed and made a trip: 400 trip_nested, no call to Google, nothing written',
                'out of its trip, the same edit: sent to Google once, renamed and a trip',
            ],
        ),
    ],
];
