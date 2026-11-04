<?php
// The mutations (README, Mutations): each a small patch to a copy of one
// file, the check it must trip, and the fixed cases that show it. A patch is
// a list of [find, replace] pairs; each find must occur exactly once in the
// file, so a change to the code it names fails the runner loudly instead of
// testing nothing. `cases` are names under cases/ or mutations/cases/; a
// mutation with `dbtest` runs that database test file instead of the harness.
declare(strict_types=1);

$model = 'server/src/Domain/Series/Model.php';

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
        // Both halves of a min across two series: where the readings differ (day numbers as days) and which comes first (the smaller day number).
        // Today the model detects by day number (review D2), so reading-earlier-by-instant-across-zones fails unmutated; when the model compares
        // instants this text is gone and the runner fails loudly until the first pair is retargeted at its detection.
        'what' => 'earlier as a min across two series: two zones\' day numbers compared as days, to find where they differ and which comes first',
        'patch' => [["                if (\$mine !== \$was) {\n                    \$new = \$mine !== null && (\$was === null || \$s['calc']->instant(\$mine) < \$this->old->instant(\$was));", "                if (\$mine !== \$was) {\n                    \$new = \$mine !== null && (\$was === null || \$mine < \$was);"]],
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
        'patch' => [["return [\$shown, count(\$out) > self::NEXT_SHOWN];", "return [[], count(\$out) > self::NEXT_SHOWN];"]],
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
        'id' => 'undo-gate-clear-flag', 'file' => 'server/src/Domain/Trips.php', 'dbtest' => 'undo-trips',
        'what' => 'Undo\'s gate lets a trip\'s flag be cleared while it has members',
        'patch' => [["                        OR (c.is_container = 0 AND c.id IN \$was))", "                        OR (1 = 0 AND c.id IN \$was))"]],
    ],
    [
        'id' => 'undo-gate-nesting', 'file' => 'server/src/Domain/Trips.php', 'dbtest' => 'undo-trips',
        'what' => 'Undo\'s gate lets a link that nests a trip be restored',
        'patch' => [["((c.is_container = 1 AND (m.is_container = 1 OR l.container_id = l.event_id))", "((c.is_container = 1 AND (1 = 0 OR l.container_id = l.event_id))"]],
    ],
];
