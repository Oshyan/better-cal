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
// The occurrence mutations wrap the app's rule iterator (every expansion reads it: the model's and the window's), so
// they hold whatever its own stepping becomes: the iterator's next() is renamed and a new one calls it, then breaks
// what it yields one way. $extra adds members beside it.
$iterator = 'server/src/Domain/Series/RuleIterator.php';
$wrapNext = static fn(string $body, string $extra = ''): array => [[
    "    #[\\ReturnTypeWillChange]\n    public function next()\n    {\n",
    $extra . "    #[\\ReturnTypeWillChange]\n    public function next()\n    {\n" . $body . "\n    }\n\n    private function nextUnmutated()\n    {\n",
]];

$undoPaths = ['undoLatest', 'undoById', 'undoById force', 'undoRun strict', 'undoRun'];
$undoCase = static fn(string $case, array $undoes): array => array_merge(
    array_map(static fn(string $p): string => "$case ($p): refused with undo_conflict, nothing written (every row, link and entry as before), the entry still to undo", $undoPaths),
    array_map(static fn(string $p): string => "$case ($p): once the conflict is gone, the entry undoes", $undoes),
    ["$case: the refusal names the rows and the rule"],
);

// The assertion labels of undo-form-day.test.php the two mutations of an undo's day must fail: its cases are named by
// zone, direction and where the change was made, as the test names them.
$undoDay = (static function (): array {
    $sent = "Google is sent one write, to that day's id as the series stands";
    $read = [];
    $stale = [];
    $redone = static fn(string $case, bool $delete): array => array_merge(
        ["$case, a tag given and undone, then redone: the tag is on 6 January"],
        ...array_map(static fn(string $what): array => ["$case, $what: $sent", "$case, $what: the notes are on 6 January"], ['notes given and undone, then redone', 'notes given back to the series, then that undone']),
        ...($delete ? [["$case, the day deleted and that undone, then the delete redone: $sent", "$case, the day deleted and that undone, then the delete redone: 6 January is skipped, and no other day"]] : []),
    );
    $newer = static fn(string $case): array => array_map(static fn(string $label): string => "$case, notes given and undone, newer notes given to the same day, then the first redone: $label",
        ['it is refused as stale, a newer change of the same thing standing', 'refused before anything: no request to Google of any kind, and every table as it was', 'the newer notes stand on 6 January']);
    foreach (['19:00 New York' => ['made all-day', 'made timed'], '07:00 Tokyo' => ['made all-day'], '10:00 Los Angeles' => ['made timed']] as $zone => $directions) {
        foreach ($directions as $direction) {
            foreach (['here', 'at Google'] as $where) {
                // A redo of a delete after Google made the series all-day is refused (the record does not say the day), mutated or not.
                $read = array_merge($read, $redone("$zone $direction $where", !($where === 'at Google' && $direction === 'made all-day')));
                if ($zone === '19:00 New York') {
                    $other = $direction === 'made all-day' ? 7 : 5;
                    $case = "$zone $direction $where, notes given and undone, notes given to $other January whose new key is the old key of the 6th, then the first redone";
                    $place = "$case: the first notes are on 6 January and the other day's on $other January";
                    $read = array_merge($read, ["$case: $sent", $place]);
                    $stale = array_merge($stale, ["$case: it goes through", "$case: $sent", $place]);
                }
            }
        }
    }
    // Where the day is found either way, only staleness can tell the newer change: every zone but 00:00 UTC, where the keys are one.
    foreach (['19:00 New York', '07:00 Tokyo', '10:00 Los Angeles'] as $zone) {
        foreach (['made all-day', 'made timed'] as $direction) {
            foreach (['here', 'at Google'] as $where) {
                $stale = array_merge($stale, $newer("$zone $direction $where"));
            }
        }
    }
    foreach (['here', 'at Google'] as $where) {
        $read = array_merge($read, $redone("19:00 New York moved to 19:00 Tokyo $where", $where === 'here'));
        $stale = array_merge($stale, $newer("19:00 New York moved to 19:00 Tokyo $where"));
    }
    foreach ([
        'a day given a time and a zone of its own and that undone, the series moved to Tokyo here, then redone' => "the row with the time of its own is 6 January's",
        'a day made all-day in a timed series and that undone, the series moved to Tokyo here, then redone' => "the all-day row is 6 January's",
        'a day moved to the 8th and that undone, the series moved to Tokyo here, then redone' => "the row moved to the 8th is 6 January's",
        'notes undone on a tagged day, the clock moved here, the tag given back, made all-day at Google, then the notes redone' => 'the notes are on 6 January',
    ] as $case => $label) {
        $read = array_merge($read, ["$case: $sent", "$case: $label"]);
    }
    $read[] = 'a tag given and undone, made all-day at Google, then made 19:00 Tokyo at Google, then redone: the tag is on 6 January';
    $read[] = 'a tag given and undone, made all-day here with the journal\'s word of it removed by hand, then redone: the tag is on 6 January';
    foreach (['made all-day', 'made timed'] as $direction) {
        $read = array_merge($read, ["an own calendar $direction: forced, the tag is back on 6 January and on no other day", "an own calendar $direction: and its row is in the series' form now, at 6 January's key"]);
    }
    return ['read' => $read, 'stale' => $stale];
})();

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
        'id' => 'acted-created-always-moves', 'file' => $model, 'checks' => ['c-placement'],
        'what' => 'the day acted on whose row the commit creates said to move whatever its instant does, instead of by the comparison every row uses',
        'patch' => [["'row' => 'created', 'outcome' => \$moved ? 'moves' : 'stays'", "'row' => 'created', 'outcome' => 'moves'"]],
        'cases' => ['zone-same-moment-from-a-gap-day-keeps-its-instant-and-stays'],
    ],
    [
        'id' => 'refused-default-stays-selected', 'file' => $model, 'checks' => ['i-google'],
        'what' => 'a group the mapping picks keeps its default when that option\'s plan is refused at Google, so the change is refused though another option holds',
        'patch' => [["if ((\$this->hold && !\$probe) || \$out === null || \$out['writes'] !== null) {", "if (true || \$out === null || \$out['writes'] !== null) {"]],
        'cases' => ['google-a-bound-keeping-a-timed-day-is-disabled-and-the-end-selected'],
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
        'patch' => [["        if (\$separate !== null) {\n            // The day acted on, where it shows on its own.", "        if (false && \$separate !== null) {\n            // The day acted on, where it shows on its own."]],
        'cases' => ['mutation-acted-day-on-its-own'],
    ],
    [
        'id' => 'next-drops-unplaced-acted', 'file' => $model, 'checks' => ['c-next'],
        'what' => 'the day acted on, kept on its own past an UNTIL sent alone (no placement), dropped from coming up',
        'patch' => [["                if (\$id === \$this->actedRowId && !\$newRule) {\n", "                if (false && \$id === \$this->actedRowId && !\$newRule) {\n"]],
        'cases' => ['next-an-until-sent-alone-cuts-off-an-edited-day-kept', 'next-an-until-sent-alone-cuts-off-an-edited-day-deleted'],
    ],
    [
        'id' => 'no-rejoin-after-give', 'file' => $model, 'checks' => ['5-edited'],
        'what' => 'an edited day given the new value of the only field it owned keeps its row instead of rejoining the series',
        'patch' => [["            if (OwnFields::normalize(\$ownAfter) === []) {\n", "            if (false && OwnFields::normalize(\$ownAfter) === []) {\n"]],
        'cases' => ['following-a-day-given-the-only-field-it-owned-rejoins'],
    ],
    [
        // Left behind on the old part, the row also stands past that part's end (h-past-end).
        'id' => 'split-drops-a-far-edited-day', 'file' => 'server/src/Domain/Series/Split.php', 'checks' => ['5-edited', 'h-past-end'],
        'what' => 'a split leaves out of the part an edited day keyed more than 740 days after the day acted on, past any window a reader looks through',
        'patch' => [["                \$partRows[] = \$row;\n", "                if (Time::fromDb((string) \$row['recurrence_instance_utc'])->getTimestamp() < Time::fromDb(\$acted)->getTimestamp() + 740 * 86400) {\n                    \$partRows[] = \$row;\n                }\n"]],
        'cases' => ['following-a-new-pattern-keeps-an-edited-day-years-on'],
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
        // At the plan's output, so it holds whatever the note code becomes: the overlap note's at put on the first of the
        // wall clock's instants that day, its fold and daylight left as the model wrote them.
        'id' => 'note-at-first-instant', 'file' => $model, 'checks' => ['c-placement'],
        'what' => 'an overlap note pinned to the first instant of the repeated clock, whichever the series is at',
        'patch' => [["            'notes' => \$this->notes,\n", "            'notes' => array_map(static fn(array \$n): array => (\$n['kind'] === 'overlap' ? ['at' => self::inZoneOut(Calc::instantsAt(Cal::dn(...array_map('intval', explode('-', \$n['date']))), array_map('intval', explode(':', substr(\$n['at'], 11, 8))), \$n['tz'])[0], \$n['tz'])] : []) + \$n, \$this->notes),\n"]],
        'cases' => ['note-a-clock-at-the-second-of-its-two-instants'],
    ],
    // The occurrence checks: c-occurrences (the window's expansion against the harness's own evaluator) and, since the
    // model reads the same iterator, the model's comparisons against it (c-next's coming up, c-placement's bound.last).
    [
        // The window's expander alone (the model reads Calc, not this), so only the window's checks see it.
        'id' => 'window-truncated-by-its-start', 'file' => 'server/src/Domain/Recurrence.php', 'checks' => ['c-occurrences', 'c-window'],
        'what' => 'the window\'s expansion ends 400 days after the window\'s own start, so a wide window shows less than its parts',
        'patch' => [["            if (\$at >= \$to->getTimestamp()) {\n                break;\n            }", "            if (\$at >= \$to->getTimestamp() || \$at - \$from->getTimestamp() > 400 * 86400) {\n                break;\n            }"]],
        'cases' => ['mutation-occurrences-months-apart'],
    ],
    [
        'id' => 'occurrences-count-cap', 'file' => $iterator, 'checks' => ['c-occurrences', 'c-next'],
        'what' => 'premature termination: the walk ends when the next occurrence is more than 100 days on',
        'patch' => $wrapNext('        $prev = $this->currentDate === null ? null : $this->currentDate->getTimestamp();
        $this->nextUnmutated();
        if ($prev !== null && $this->currentDate !== null && $this->currentDate->getTimestamp() - $prev > 100 * 86400) {
            $this->currentDate = null;
        }'),
        'cases' => ['mutation-occurrences-months-apart'],
    ],
    [
        'id' => 'occurrences-missing', 'file' => $iterator, 'checks' => ['c-occurrences', 'c-placement', 'c-next'],
        'what' => 'a missing occurrence: the third is stepped over and not counted',
        'patch' => $wrapNext('        $this->nextUnmutated();
        if ($this->counter === 2) {
            $this->nextUnmutated();
            --$this->counter;
        }'),
        'cases' => ['next-trailing-skipped-occurrences'],
    ],
    [
        'id' => 'occurrences-extra', 'file' => $iterator, 'checks' => ['c-occurrences', 'c-next'],
        'what' => 'an extra occurrence: an hour before the second, not counted',
        'patch' => $wrapNext('        if ($this->counter === 0) {
            $this->mutHeld = null;
        }
        if ($this->mutHeld !== null) {
            $this->currentDate = $this->mutHeld;
            $this->mutHeld = null;
            return;
        }
        $this->nextUnmutated();
        if ($this->counter === 1 && $this->currentDate !== null) {
            $this->mutHeld = $this->currentDate;
            $this->currentDate = (clone $this->currentDate)->setTimestamp($this->currentDate->getTimestamp() - 3600);
        }', "    private mixed \$mutHeld = null;\n\n"),
        'cases' => ['next-trailing-skipped-occurrences'],
    ],
    [
        'id' => 'occurrences-count-off-by-one', 'file' => $iterator, 'checks' => ['c-occurrences', 'c-placement', 'c-next'],
        'what' => 'COUNT read as one more than it says',
        'patch' => $wrapNext('        $this->nextUnmutated();', "    #[\\ReturnTypeWillChange]\n    public function valid()\n    {\n        if (\$this->count === null) {\n            return parent::valid();\n        }\n        \$this->count++;\n        \$v = parent::valid();\n        \$this->count--;\n        return \$v;\n    }\n\n"),
        'cases' => ['next-trailing-skipped-occurrences'],
    ],
    [
        'id' => 'occurrences-second-instant', 'file' => $iterator, 'checks' => ['c-occurrences', 'c-next', 'c-placement'],
        'what' => 'an occurrence in a repeated hour put at its second instant',
        'patch' => $wrapNext('        $this->nextUnmutated();
        if ($this->currentDate !== null) {
            foreach ([3600, 1800] as $d) {
                $t = (clone $this->currentDate)->setTimestamp($this->currentDate->getTimestamp() + $d);
                if ($t->format(\'Y-m-d H:i:s\') === $this->currentDate->format(\'Y-m-d H:i:s\')) {
                    $this->currentDate = $t;
                    break;
                }
            }
        }'),
        'cases' => ['mutation-occurrences-in-a-repeated-hour'],
    ],
    [
        // In the expander's weekly period: the week's days BYDAY admits, cut to the first.
        'id' => 'occurrences-weekly-byday-unread', 'file' => $iterator, 'checks' => ['c-occurrences', 'c-placement', 'c-next'],
        'what' => 'one part\'s evaluation removed: a weekly rule\'s BYDAY list, so it repeats on the first of its weekdays alone',
        'patch' => [["                return \$out;\n            case 'MONTHLY':", "                return array_slice(\$out, 0, 1);\n            case 'MONTHLY':"]],
        'cases' => ['mutation-occurrences-on-a-weekday-list'],
    ],
    [
        // The evaluator's old date-frequency reading of BYSETPOS, a position taken among the nominal clocks before they
        // resolve, as it shows on the gap day of DAILY;BYHOUR=2,3;BYMINUTE=0,30;BYSETPOS=3: the third clock (03:00) is an
        // occurrence there though the day's four clocks are two instants. Emulated around next(): between two occurrences
        // across a spring change, the instant the change lands on is one more occurrence, counted.
        'id' => 'occurrences-set-position-among-clocks', 'file' => $iterator, 'checks' => ['c-occurrences', 'c-placement', 'c-next'],
        'what' => 'BYSETPOS counted among the nominal clocks, not the period\'s instants: a position on the gap day that the merged clocks no longer have is an occurrence',
        'patch' => $wrapNext('        if ($this->counter === 0) {
            $this->mutHeld = null;
        }
        if ($this->mutHeld !== null) {
            $this->currentDate = $this->mutHeld;
            $this->mutHeld = null;
            return;
        }
        $prev = $this->currentDate === null ? null : $this->currentDate->getTimestamp();
        $this->nextUnmutated();
        if ($prev !== null && $this->currentDate !== null) {
            $was = null;
            foreach ($this->currentDate->getTimezone()->getTransitions($prev, $this->currentDate->getTimestamp()) ?: [] as $i => $t) {
                if ($i > 0 && $was !== null && $t[\'offset\'] > $was && $t[\'ts\'] > $prev && $t[\'ts\'] < $this->currentDate->getTimestamp()) {
                    $this->mutHeld = $this->currentDate;
                    $this->currentDate = (clone $this->currentDate)->setTimestamp($t[\'ts\']);
                    ++$this->counter;
                    break;
                }
                $was = $t[\'offset\'];
            }
        }', "    private mixed \$mutHeld = null;\n\n"),
        'cases' => ['occurrences-a-set-position-the-gap-day-lacks'],
    ],
    [
        'id' => 'zone-option-start-from-the-plan', 'file' => $model, 'checks' => ['c-placement'],
        'what' => 'every zone option\'s start taken from the plan chosen, not from that option\'s own',
        'patch' => [["\$to = (string) \$alt['plan']['rule']['to']['start'];", "\$to = (string) \$plan['rule']['to']['start'];"]],
        'cases' => ['reading-earlier-by-instant-across-zones', 'mutation-earlier-across-zones'],
    ],
    [
        // Where the other option's series starts at the clock its own rule names (a new rule with BYHOUR and BYMINUTE).
        'id' => 'zone-option-start-an-hour-off', 'file' => $model, 'checks' => ['c-placement'],
        'what' => 'the zone option not chosen advertises a start an hour after the one its series is written with',
        'patch' => [["? self::inZoneOut(Time::parseIso(\$to), \$plan['zone']) : \$to;", "? self::inZoneOut(Time::parseIso(\$to)->modify(\$option['id'] !== \$group['selected'] ? '+1 hour' : '+0 hours'), \$plan['zone']) : \$to;"]],
        'cases' => ['zone-option-start-under-a-new-rule-with-its-own-clock'],
    ],
    [
        // The window's expander alone, so only c-window sees it: a narrow window's walk starts too late.
        'id' => 'window-narrow-seeks-from-its-end', 'file' => 'server/src/Domain/Recurrence.php', 'checks' => ['c-window'],
        'what' => 'a window under an hour seeks from its end, so it misses an occurrence its whole read has',
        'patch' => [["        \$it->jumpTo(\$from->getTimestamp() - \$length);", "        \$it->jumpTo(\$to->getTimestamp() - \$from->getTimestamp() < 3600 ? \$to->getTimestamp() : \$from->getTimestamp() - \$length);"]],
        'cases' => ['occurrences-a-set-position-the-gap-day-lacks'],
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
    // The preselection check (PlanPreselect): the design's table, not the plan's order.
    [
        'id' => 'preselect-new-rule-keeps-keys', 'file' => $model, 'checks' => ['c-preselect-design'],
        'what' => 'a new rule that is the old one shifted preselects keeping the keys, not moving the days as a drag would',
        'patch' => [["            array_unshift(\$out, [\n                'id' => 'map',", "            array_push(\$out, [\n                'id' => 'map',"]],
        'cases' => ['zone-option-start-under-a-new-rule-with-its-own-clock'],
    ],
    // Every option committed from the baseline: an option no case's own commit picks is wrong, and only the commit of
    // every option sees it (the case commits the preselected "keep").
    [
        'id' => 'moved-days-move-ignored', 'file' => $model, 'checks' => ['b-placed', 'c-placement'],
        'what' => '"Move" for a day moved on its own commits it kept on its own date',
        'patch' => [["(\$one !== null ? ['day' => self::keyOut((string) \$one['recurrence_instance_utc'])] : [])) === 'move';", "(\$one !== null ? ['day' => self::keyOut((string) \$one['recurrence_instance_utc'])] : [])) === 'move' && false;"]],
        'cases' => ['mutation-drag-with-a-day-at-its-own-date'],
    ],
    // The wall clocks typed (Driver, the case's typed share): a typed length from a day of its own length is adopted first.
    [
        'id' => 'typed-length-not-adopted-first', 'file' => $model, 'checks' => ['c-preselect-design'],
        'what' => 'a typed length from a day with its own length preselects the same way, as a resize does',
        'patch' => [["(\$row['preselect'] === 'typed' && \$this->typed)", "(\$row['preselect'] === 'typed' && false)"]],
        'cases' => ['mutation-typed-length-from-a-day-of-its-own-length'],
    ],
    // The sequences' own checks: an Undo after several steps puts back the step before, and the shown events agree.
    [
        'id' => 'undo-latest-takes-the-one-before', 'file' => 'server/src/Domain/Undo.php', 'checks' => ['s-undo', 's-step'],
        'what' => 'Undo of the last change undoes the change before it instead',
        'patch' => [["source IN \$in ORDER BY id DESC LIMIT 1\",", "source IN \$in ORDER BY id DESC LIMIT 1 OFFSET 1\","]],
        'cases' => ['weekly-list-member-rename-own-title-count-undo'],
    ],
    // The 1.5.1 sequences, one mutation each, and the pairs of the commit of every option.
    [
        'id' => 'own-time-in-the-series-zone', 'file' => $model, 'checks' => ['s-step', 'b-placed', 'c-untouched'],
        'what' => 'a day at its own time keeps its clock in the series\' new zone, not its own zone\'s instant, under a zone change',
        'patch' => [["            \$tz = (string) \$row['tzid'];\n            \$clock = self::clockOf(\$shown);", "            \$tz = \$this->tz1;\n            \$clock = self::clockOf(\$shown);"]],
        'cases' => ['weekly-own-time-zone-moment-drag-split-from-the-first-day-undo-edit'],
    ],
    [
        'id' => 'undo-trip-refusal-code', 'file' => 'server/src/Domain/Trips.php', 'checks' => ['s-undo'],
        'what' => 'an Undo that would put a trip inside a trip refused with the edit\'s code (trip_nested), not undo_conflict',
        'patch' => [["self::BY_UNDO => ['nested' => 'undo_conflict',", "self::BY_UNDO => ['nested' => 'trip_nested',"]],
        'cases' => ['weekly-trip-clear-attach-refused-set-detach-set-undo-by-entry'],
    ],
    [
        'id' => 'undo-entry-takes-the-latest', 'file' => 'server/src/Domain/Undo.php', 'checks' => ['s-undo', 's-step'],
        'what' => 'Undo of an entry by its id undoes the latest change still standing instead',
        'patch' => [["'SELECT * FROM mutations WHERE id = ? AND user_id = ?',", "'SELECT * FROM mutations WHERE id >= ? AND user_id = ? AND undone = 0 ORDER BY id DESC LIMIT 1',"]],
        'cases' => ['weekly-count-from-an-edited-day-rename-undo-the-count-by-entry'],
    ],
    [
        'id' => 'plan-hash-without-edited-rows', 'file' => $model, 'checks' => ['s-stale'],
        'what' => 'the plan\'s hash leaves out the edited rows, so a day given its own title after the preview goes unnoticed',
        'patch' => [["            \$rows, \$this->edit, \$selected, \$this->google,", "            [], \$this->edit, \$selected, \$this->google,"]],
        'cases' => ['daily-stale-plan-then-re-preview-commit-undo'],
    ],
    [
        'id' => 'moved-days-keep-ignored', 'file' => $model, 'checks' => ['s-step', 'b-placed', 'c-placement', 'c-untouched'],
        'what' => '"Keep" for a day moved on its own commits it moved with its events',
        'patch' => [["(\$one !== null ? ['day' => self::keyOut((string) \$one['recurrence_instance_utc'])] : [])) === 'move';", "(\$one !== null ? ['day' => self::keyOut((string) \$one['recurrence_instance_utc'])] : [])) !== 'never';"]],
        'cases' => ['weekly-own-date-then-new-weekday-keep-and-move-undo'],
    ],
    [
        // Only the pair commits both: the case's own commit takes the preselected clock and keep, each option alone the other's preselection.
        'id' => 'moved-days-move-ignored-under-the-same-moment', 'file' => $model, 'checks' => ['b-placed', 'c-placement'],
        'what' => '"Move" for a day moved on its own commits it kept when the zone change keeps the same moment',
        'patch' => [["(\$one !== null ? ['day' => self::keyOut((string) \$one['recurrence_instance_utc'])] : [])) === 'move';", "(\$one !== null ? ['day' => self::keyOut((string) \$one['recurrence_instance_utc'])] : [])) === 'move' && (\$this->choices['zone'] ?? 'clock') !== 'moment';"]],
        'cases' => ['mutation-zone-and-drag-with-a-day-at-its-own-date'],
    ],
    // The trip rule's two mutations patch its query (Trips::assertRules, which every writer and Undo call).
    [
        'id' => 'cycle-one-period-short', 'file' => $model, 'checks' => ['c-placement'],
        'what' => 'one whole cycle of the rule\'s periods read one period short, so a loss or gain class\'s rate is counted over less than a cycle',
        'patch' => [['        $end = $r->periodFirst($r->periodPlus($p, $counted * $r->interval)) - 1;', '        $end = $r->periodFirst($r->periodPlus($p, ($counted - 1) * $r->interval)) - 1;']],
        'cases' => ['mutation-count-sent-on-a-daily-series-with-no-end', 'mutation-end-sent-on-a-monthly-list-with-no-end-dragged'],
    ],
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
            $undoCase('a trip deleted, then its stop flagged', array_slice($undoPaths, 1)),
            ['a trip deleted, then its stop flagged: undone once the stop is no trip, the trip is back around it'],
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
                // Not named: 'out of its trip, the same edit ...' is the refusal's positive half. Under this mutation the refused
                // edit already went through, so the same edit again changes nothing and sends nothing, and the assertion's
                // calls are the first edit's (read, PATCH): it holds whatever the trip rule says, so no gate mutation can fail it.
            ],
        ),
    ],
    // The journal format gate (rule 3 of 1.5.5): Finish of a pending undo gets its entry as it did before the one
    // loader, the live row else the plan's copy, with no look at the format. Every Finish row of the table fails in
    // both variants (Google is written to and the pending row changes), with the reviewer's probe and the open case;
    // the backstops refuse after Google's writes, and a copy without `format` reaching one is a journaling misuse.
    [
        'id' => 'journal-gate-finish-bypass', 'file' => 'server/src/Domain/SeriesPlans.php', 'dbtest' => 'journal-gate',
        'what' => 'Review\'s Finish of a pending undo loads the entry it undoes without the one loader (Undo::entryToTakeBack)',
        'patch' => [["                \$undone = GoogleUndo::entryToFinish(\$this->db, \$userId, \$plan);",
            "                \$undone = \$this->db->one('SELECT * FROM mutations WHERE id = ? AND user_id = ?', [(int) \$plan['undo'], \$userId]) ?? (is_array(\$plan['entry'] ?? null) ? \$plan['entry'] + ['user_id' => \$userId] : ['id' => (int) \$plan['undo'], 'user_id' => \$userId, 'entity_id' => 0, 'summary' => '', 'before_json' => null, 'after_json' => null, 'details_json' => null, 'format' => Undo::JOURNAL_FORMAT]);"]],
        'checks' => array_merge(
            array_merge(...array_map(static fn(string $variant): array => array_merge(...array_map(static fn(string $row): array => array_map(
                static fn(string $what): string => "journal gate [$variant]: SeriesPlans::googleFinish$row: $what",
                ['refused with the code and words', 'no request to Google of any kind', 'nothing written (every table as it was)'],
            ), [' of a pending undo', ' of a pending redo', ', entry gone, its copy older', ', entry gone, its copy without format', ', entry gone, no copy', ', a redo, entry gone, its copy without format'])),
                ['077 run (snapshots dropped)', 'snapshots kept'])),
            [
                'after the upgrade: Finish refused (409 not_undoable, the words), no request to Google, the pending row exactly as it was, the tags as the stop left them, Google\'s title as the stop left it',
                'Finish refuses with its words, no request to Google; the pending row stays half done with Put back\'s words',
                'the pending row is exactly as Put back\'s refusal left it',
                'every change to undoable data was journaled in Undo::write',
            ],
        ),
    ],
    // Provider identity (rule 1 of 1.5.5): what a master write leaves at Google is predicted by each record's id under
    // the old master's form (GoogleSeries::afterMaster, S1). Here the id is read in the new master's form instead, so
    // the form term is always true and a record or skip on a key the new form also has is predicted kept, as before
    // the fix (review R1). Every after-write assertion of the coinciding-key scenarios fails, with the harness's own
    // forward-edit checks on seed 911 cases 193 and 879 that the test runs through the driver; the plan and asked-state
    // assertions and the two controls at 10:00 Los Angeles hold.
    [
        'id' => 'google-after-master-form-term', 'file' => 'server/src/Domain/GoogleSeries.php', 'dbtest' => 'google-series-identity',
        'what' => 'a record\'s identity through a master write read in the new master\'s form, so a change between timed and all-day keeps what shares a key',
        'patch' => [["        \$id = GoogleWriter::instanceId('', \$key, \$was);", "        \$id = GoogleWriter::instanceId('', \$key, \$allDay);"]],
        'checks' => [
            'timed 00:00 UTC to all-day (edit, then skip): Google lists nothing on the skipped day',
            'timed 00:00 UTC to all-day (edit, then skip): a write sent to Google carries the edited day\'s notes and place',
            'timed 00:00 UTC to all-day (edit, then skip): the window shows the edited day with its place and notes',
            'timed 00:00 UTC to all-day (edit, then skip): the window shows nothing on the skipped day',
            'timed 00:00 UTC to all-day (skip, then edit): Google lists nothing on the skipped day',
            'timed 00:00 UTC to all-day (skip, then edit): a write sent to Google carries the edited day\'s notes and place',
            'timed 00:00 UTC to all-day (skip, then edit): the window shows the edited day with its place and notes',
            'timed 00:00 UTC to all-day (skip, then edit): the window shows nothing on the skipped day',
            'timed 00:00 UTC to all-day (from the last day): Google lists nothing on the skipped day',
            'timed 00:00 UTC to all-day (from the last day): a write sent to Google carries the edited day\'s notes and place',
            'timed 00:00 UTC to all-day (from the last day): the window shows the edited day with its place and notes',
            'timed 00:00 UTC to all-day (from the last day): the window shows nothing on the skipped day',
            'all-day to timed 00:00 UTC (edit, then skip): Google lists nothing on the skipped day',
            'all-day to timed 00:00 UTC (edit, then skip): a write sent to Google carries the edited day\'s notes and place',
            'all-day to timed 00:00 UTC (edit, then skip): the window shows the edited day with its place and notes',
            'all-day to timed 00:00 UTC (edit, then skip): the window shows nothing on the skipped day',
            'all-day to timed 00:00 UTC (skip, then edit): Google lists nothing on the skipped day',
            'all-day to timed 00:00 UTC (skip, then edit): a write sent to Google carries the edited day\'s notes and place',
            'all-day to timed 00:00 UTC (skip, then edit): the window shows the edited day with its place and notes',
            'all-day to timed 00:00 UTC (skip, then edit): the window shows nothing on the skipped day',
            'harness seed 911 case 193: no forward-edit check fails',
            'harness seed 911 case 879: no forward-edit check fails',
            'timed 00:00 UTC to all-day (edit, then skip): Google holds the edited day\'s record under its id in the series\' form, with its notes and place',
            'timed 00:00 UTC to all-day (edit, then skip): Google lists the edited day on its date with its notes and place',
            'timed 00:00 UTC to all-day (edit, then skip): the edited day\'s row is on its date with its own notes and place, in the series\' kind',
            'timed 00:00 UTC to all-day (edit, then skip): Google holds the skipped day as skipped under its id in the series\' form',
            'timed 00:00 UTC to all-day (edit, then skip): the skipped day is skipped here, and nothing else is',
            'timed 00:00 UTC to all-day (skip, then edit): Google holds the edited day\'s record under its id in the series\' form, with its notes and place',
            'timed 00:00 UTC to all-day (skip, then edit): Google lists the edited day on its date with its notes and place',
            'timed 00:00 UTC to all-day (skip, then edit): the edited day\'s row is on its date with its own notes and place, in the series\' kind',
            'timed 00:00 UTC to all-day (skip, then edit): Google holds the skipped day as skipped under its id in the series\' form',
            'timed 00:00 UTC to all-day (skip, then edit): the skipped day is skipped here, and nothing else is',
            'timed 00:00 UTC to all-day (from the last day): Google holds the edited day\'s record under its id in the series\' form, with its notes and place',
            'timed 00:00 UTC to all-day (from the last day): Google lists the edited day on its date with its notes and place',
            'timed 00:00 UTC to all-day (from the last day): the edited day\'s row is on its date with its own notes and place, in the series\' kind',
            'timed 00:00 UTC to all-day (from the last day): Google holds the skipped day as skipped under its id in the series\' form',
            'timed 00:00 UTC to all-day (from the last day): the skipped day is skipped here, and nothing else is',
            'all-day to timed 00:00 UTC (edit, then skip): Google holds the edited day\'s record under its id in the series\' form, with its notes and place',
            'all-day to timed 00:00 UTC (edit, then skip): Google lists the edited day on its date with its notes and place',
            'all-day to timed 00:00 UTC (edit, then skip): the edited day\'s row is on its date with its own notes and place, in the series\' kind',
            'all-day to timed 00:00 UTC (edit, then skip): Google holds the skipped day as skipped under its id in the series\' form',
            'all-day to timed 00:00 UTC (edit, then skip): the skipped day is skipped here, and nothing else is',
            'all-day to timed 00:00 UTC (skip, then edit): Google holds the edited day\'s record under its id in the series\' form, with its notes and place',
            'all-day to timed 00:00 UTC (skip, then edit): Google lists the edited day on its date with its notes and place',
            'all-day to timed 00:00 UTC (skip, then edit): the edited day\'s row is on its date with its own notes and place, in the series\' kind',
            'all-day to timed 00:00 UTC (skip, then edit): Google holds the skipped day as skipped under its id in the series\' form',
            'all-day to timed 00:00 UTC (skip, then edit): the skipped day is skipped here, and nothing else is',
            'timed 10:00 Los Angeles to all-day, stopped after the master step, then Put back: Put back goes through',
            'daily 19:00 New York to all-day: Google holds the skipped day as skipped under its id in the series\' form',
            'daily 19:00 New York to all-day: Google lists nothing on the skipped day',
            'daily 19:00 New York to all-day: the skipped day is skipped here, and nothing else is',
            'daily 19:00 New York to all-day: the window shows nothing on the skipped day',
            'daily all-day to 19:00 New York: Google holds the edited day\'s record under its id in the series\' form, with its notes and place',
            'daily all-day to 19:00 New York: Google lists the edited day on its date with its notes and place',
            'daily all-day to 19:00 New York: the edited day\'s row is on its date with its own notes and place, in the series\' kind',
            'daily all-day to 19:00 New York: the window shows the edited day with its place and notes',
            'all-day to 00:00 UTC to all-day, after the first flip: Google holds the skipped day as skipped under its id in the series\' form',
            'all-day to 00:00 UTC to all-day, after the first flip: Google lists nothing on the skipped day',
            'all-day to 00:00 UTC to all-day, after the first flip: the skipped day is skipped here, and nothing else is',
            'all-day to 00:00 UTC to all-day, after the first flip: the window shows nothing on the skipped day',
            'all-day to 00:00 UTC to all-day, second flip: the plan keeps the skipped day skipped on its date',
            'all-day to 00:00 UTC to all-day, after the second flip: Google holds the skipped day as skipped under its id in the series\' form',
            'all-day to 00:00 UTC to all-day, after the second flip: the skipped day is skipped here, and nothing else is',
            'all-day to 00:00 UTC to all-day, after the second flip: the window shows nothing on the skipped day',
        ],
    ],
    // Provider identity across a change of form that has not reached Google (GoogleSeries::rebase): the guard that
    // names a change made at Google as not kept is gone, so what Google holds in the old form is carried by its key
    // again. West of UTC a rename or a deletion lands on the next day; made timed, it goes to an id that is no day and
    // is lost; a day given its own time is sent as a clock to an all-day instance and Finish fails. Every run of the
    // test fails on its words; the New York and all-day runs on what Google and the rows hold too.
    [
        'id' => 'google-rebase-carries-by-key-across-forms', 'file' => 'server/src/Domain/GoogleSeries.php', 'dbtest' => 'google-series-form-finish',
        'what' => 'a change made at Google while a change between timed and all-day waits for its master write is carried by its key instead of named as not kept',
        'patch' => [["        \$why = \$found ? self::rekeyed(\$now['master'], \$master) : null;", "        \$why = null;"]],
        'checks' => [
            'daily 19:00 New York made all-day, a day renamed at Google: Finish names the change made at Google once, by the day\'s own date, as not kept',
            'daily 19:00 New York made all-day, a day renamed at Google: Finish writes the master and the plan\'s edited day under its new id, nothing to an old id or to a day the series does not have',
            'daily 19:00 New York made all-day, a day renamed at Google: Google holds the series in its new kind with the plan\'s edited day alone, on its own date with its notes',
            'daily 19:00 New York made all-day, a day renamed at Google: the rows here hold the same: the edited day\'s row on its own date with its notes, no other day, no skip',
            'daily 19:00 New York made all-day, a day deleted at Google: Finish names the change made at Google once, by the day\'s own date, as not kept',
            'daily 19:00 New York made all-day, a day deleted at Google: Finish writes the master and the plan\'s edited day under its new id, nothing to an old id or to a day the series does not have',
            'daily 19:00 New York made all-day, a day deleted at Google: Google holds the series in its new kind with the plan\'s edited day alone, on its own date with its notes',
            'daily 19:00 New York made all-day, a day deleted at Google: the rows here hold the same: the edited day\'s row on its own date with its notes, no other day, no skip',
            'daily 19:00 New York made all-day, a day given another time at Google: Finish completes and the change is settled',
            'daily 19:00 New York made all-day, a day given another time at Google: Finish names the change made at Google once, by the day\'s own date, as not kept',
            'daily 19:00 New York made all-day, a day given another time at Google: Finish writes the master and the plan\'s edited day under its new id, nothing to an old id or to a day the series does not have',
            'daily 19:00 New York made all-day, the plan\'s edited day given other notes at Google: Finish names the change made at Google once, by the day\'s own date, as not kept',
            'daily 19:00 New York made all-day, the plan\'s edited day given other notes at Google: Finish writes the master and the plan\'s edited day under its new id, nothing to an old id or to a day the series does not have',
            'daily 19:00 New York made all-day, the plan\'s edited day given other notes at Google: Google holds the series in its new kind with the plan\'s edited day alone, on its own date with its notes',
            'daily 19:00 New York made all-day, the plan\'s edited day given other notes at Google: the rows here hold the same: the edited day\'s row on its own date with its notes, no other day, no skip',
            'weekly 10:00 Los Angeles made all-day, a day renamed at Google: Finish names the change made at Google once, by the day\'s own date, as not kept',
            'weekly 10:00 Los Angeles made all-day, a day renamed at Google: Finish writes the master and the plan\'s edited day under its new id, nothing to an old id or to a day the series does not have',
            'weekly 10:00 Los Angeles made all-day, a day renamed at Google: Google holds the series in its new kind with the plan\'s edited day alone, on its own date with its notes',
            'weekly 10:00 Los Angeles made all-day, a day renamed at Google: the rows here hold the same: the edited day\'s row on its own date with its notes, no other day, no skip',
            'weekly 10:00 Los Angeles made all-day, a day deleted at Google: Finish names the change made at Google once, by the day\'s own date, as not kept',
            'weekly 10:00 Los Angeles made all-day, a day deleted at Google: Finish writes the master and the plan\'s edited day under its new id, nothing to an old id or to a day the series does not have',
            'weekly 10:00 Los Angeles made all-day, a day deleted at Google: Google holds the series in its new kind with the plan\'s edited day alone, on its own date with its notes',
            'weekly 10:00 Los Angeles made all-day, a day deleted at Google: the rows here hold the same: the edited day\'s row on its own date with its notes, no other day, no skip',
            'weekly 10:00 Los Angeles made all-day, a day given another time at Google: Finish completes and the change is settled',
            'weekly 10:00 Los Angeles made all-day, a day given another time at Google: Finish names the change made at Google once, by the day\'s own date, as not kept',
            'weekly 10:00 Los Angeles made all-day, a day given another time at Google: Finish writes the master and the plan\'s edited day under its new id, nothing to an old id or to a day the series does not have',
            'weekly 10:00 Los Angeles made all-day, the plan\'s edited day given other notes at Google: Finish names the change made at Google once, by the day\'s own date, as not kept',
            'weekly 10:00 Los Angeles made all-day, the plan\'s edited day given other notes at Google: Finish writes the master and the plan\'s edited day under its new id, nothing to an old id or to a day the series does not have',
            'weekly 10:00 Los Angeles made all-day, the plan\'s edited day given other notes at Google: Google holds the series in its new kind with the plan\'s edited day alone, on its own date with its notes',
            'weekly 10:00 Los Angeles made all-day, the plan\'s edited day given other notes at Google: the rows here hold the same: the edited day\'s row on its own date with its notes, no other day, no skip',
            'weekly all-day made 10:00 Los Angeles, a day renamed at Google: Finish names the change made at Google once, by the day\'s own date, as not kept',
            'weekly all-day made 10:00 Los Angeles, a day deleted at Google: Finish names the change made at Google once, by the day\'s own date, as not kept',
            'weekly all-day made 10:00 Los Angeles, a day given another time at Google: Finish names the change made at Google once, by the day\'s own date, as not kept',
            'weekly all-day made 10:00 Los Angeles, the plan\'s edited day given other notes at Google: Finish names the change made at Google once, by the day\'s own date, as not kept',
        ],
    ],
    // The same guard narrowed back to a change of form alone, as it first was: a new clock time whose master write has
    // not reached Google carries what changed at Google by its key again, to an id the series no longer has (404).
    [
        'id' => 'google-rebase-rekey-guard-form-only', 'file' => 'server/src/Domain/GoogleSeries.php', 'dbtest' => 'google-finish-first-write',
        'what' => 'the guard that names a change made at Google as not kept reads a change of form only, so a new clock time carries by key again',
        'patch' => [["        \$why = \$found ? self::rekeyed(\$now['master'], \$master) : null;", "        \$why = \$found && (int) \$now['master']['all_day'] !== (int) \$master['all_day'] ? self::rekeyed(\$now['master'], \$master) : null;"]],
        'checks' => [
            'F2 (master write sent and not taken (the network down); Fri 8 Jan deleted at Google): the change made at Google is named once, by its date, as not kept',
            'F2 (master write sent and not taken (the network down); Fri 8 Jan renamed at Google): the change made at Google is named once, by its date, as not kept',
            'F2 (master write sent and not taken (the network down); Wed 6 Jan (the day with its own notes) given other notes at Google): the change made at Google is named once, by its date, as not kept',
        ],
    ],
    // What the condition believes a record holds of a field its day does not own (GoogleSeries::sameAsStored): the
    // link read as the series' value alone, as before. A series given a link then reads as changed at Google wherever
    // Google left a record's link as it was, and every later write to it is refused.
    [
        'id' => 'google-condition-unowned-link-as-series', 'file' => 'server/src/Domain/GoogleSeries.php', 'dbtest' => 'google-link-unproven',
        'what' => 'the condition reads a day\'s unowned link as the series\' alone, so a record Google left without the series\' new link reads as changed at Google',
        'patch' => [["                if (!in_array(\$field, \$owned, true) && self::fieldsOf([\$col =>", "                if (false && !in_array(\$field, \$owned, true) && self::fieldsOf([\$col =>"]],
        'checks' => [
            'F1 sibling: an ordinary day can be renamed here after the poll',
            'F1 sibling: the edited day can be renamed here after the poll',
            'F1 (second write refused): Finish completes the change',
            'F1 (second write refused): Finish completes after Put back was refused',
            'F1 (second write refused): Finish completes after a poll',
            'F1 (second write refused): Review lists nothing half done once Finish or Put back has run',
            'F1 (second write refused): a day of the series can be renamed afterwards',
            'F1 (second write refused): the series can be deleted afterwards',
            'F1 (second write the network down): Finish completes the change',
            'F1 (second write the network down): Finish completes after Put back was refused',
            'F1 (second write the network down): Finish completes after a poll',
            'F1 (second write the network down): Review lists nothing half done once Finish or Put back has run',
            'F1 (second write the network down): a day of the series can be renamed afterwards',
            'F1 (second write the network down): the series can be deleted afterwards',
        ],
    ],
    // The interleaving mode (README, Interleaving), each on a product file the Google paths share.
    [
        'id' => 'google-body-sends-color', 'file' => 'server/src/Domain/GoogleWriter.php', 'checks' => ['x-write-asked'], 'interleave' => true,
        'what' => 'every body sent to Google carries a colorId, a field only Google holds that the app never sends',
        // Where every body leaves for Google (GoogleWriter::call), after the allowed-list builder, so the check sees what Google would.
        'patch' => [["        \$http = new HttpClient(requestBudget: 10, userAgent: HttpClient::userAgentFor('google-connector'));", "        if (\$payload !== null) {\n            \$payload['colorId'] = '1';\n        }\n        \$http = new HttpClient(requestBudget: 10, userAgent: HttpClient::userAgentFor('google-connector'));"]],
        'cases' => ['a-color-at-google-survives-writes-here'],
    ],
    [
        'id' => 'bare-undo-takes-an-undo', 'file' => 'server/src/Domain/Undo.php', 'checks' => ['x-undo-bare', 'x-undo-fields'], 'interleave' => true,
        'what' => 'the bare Undo takes the newest entry not undone, an undo\'s own included, so it redoes on a Google calendar',
        'patch' => [['} while ($this->takesBack($below) || ', '} while (false || ']],
        'cases' => ['two-changes-then-the-bare-undo-twice'],
    ],
    // The link rule (server/tests/db/lib.php, linkViolations), hung after every block of the database suite: a writer
    // that makes a day's row without its series' tags and people must fail the block that ran it, and nothing else.
    [
        'id' => 'answer-day-without-series-links', 'file' => 'server/src/Domain/Events.php', 'dbtest' => 'link-rule',
        'what' => 'the row an answer for one day makes (Events::ensureOverride) is not followed to its series\' tags and people (OwnFields::follow)',
        'patch' => [[
            "            // Owning nothing, it holds the series' tags and people (OwnFields::follow).\n            OwnFieldsList::follow(\$this->db, \$masterId, [\$id]);\n",
            "            // mutated: the series' tags and people are not followed\n",
        ]],
        'checks' => ["an answer for one day of a tagged series with a person: every live day row holds its own tags and people, or exactly its series'"],
    ],
    // The same rule in the series harness (l-links, read by the database suite's raw SQL after every step): the row a
    // "this event" edit makes (Events::patchThis) starts without its series' tags and people. f-undo trips too: Undo
    // composes the day's links from its series (Undo::followLinks), so the rows after Undo are not the broken ones before;
    // a-fields and c-untouched trip too: the whole-series edit after it ends by following the series (OwnFields::follow),
    // which puts the broken day's tags and people right, so they change where the edit's request did not name them.
    [
        'id' => 'this-day-without-series-links', 'file' => 'server/src/Domain/Events.php', 'checks' => ['l-links', 'f-undo', 'a-fields', 'c-untouched'],
        'what' => 'the row a "this event" edit makes (Events::patchThis) is not followed to its series\' tags and people (OwnFields::follow)',
        'patch' => [[
            "            // this edit sends are its own, set next.\n            OwnFieldsList::follow(\$this->db, \$masterId, [\$id]);\n",
            "            // this edit sends are its own, set next (mutated: not followed).\n",
        ]],
        'cases' => ['edited-day-rejoins-the-series'],
    ],
    // A day row's stored Google id is an identity (audit-155-identity, fix list items 6 and 7): derived whenever the
    // row is placed, and an undo addresses the day by its id now. The database cases make a series timed at 00:00 UTC
    // all-day (each day keeps its key, every id changes form) over a day holding only a tag. The change made here is
    // placed by the plan's writer as well as by the store's sync, so the guard in ofSeries alone shows on the change
    // made at Google, which a poll alone places.
    [
        'id' => 'ofseries-id-only-on-key-move', 'file' => 'server/src/Domain/Feeds.php', 'dbtest' => 'google-day-identity',
        'what' => 'Feeds::ofSeries derives a placed day\'s Google id only when its key moves, as before 1.5.5',
        'patch' => [['        return $placed($target, $at);', '        return $at !== $key ? $placed($target, $at) : $target;']],
        'checks' => [
            'made all-day at Google: after the poll the series is all-day and the tagged day keeps its key and takes the date-form id',
            'made all-day at Google: I1 holds after the poll',
            'made all-day at Google: the next rename goes through',
            'made all-day at Google: the next rename goes to the date-form id',
            'made all-day at Google: I1 holds at the end',
            // The identity rule the suite checks after every block (lib.php, identityViolations) names the block too.
            "google day identity: a series made all-day at Google, read by a poll, leaves a day holding only a tag on the date-form id: every live day row's Google id is its key's id under its series' form",
        ],
    ],
    [
        'id' => 'dayat-journaled-id', 'file' => 'server/src/Domain/GoogleUndo.php', 'dbtest' => 'google-day-identity',
        'what' => 'GoogleUndo::dayAt returns the id the entry journaled when the day\'s key is the same now, whatever form the series has since',
        'patch' => [["                return ['googleId' => GoogleWriter::instanceId(\$of['series'], \$at, (int) \$master['all_day'] === 1), 'key' => \$at];",
            "                return ['googleId' => \$at === (string) \$key ? \$googleId : GoogleWriter::instanceId(\$of['series'], \$at, (int) \$master['all_day'] === 1), 'key' => \$at];"]],
        'checks' => ['tag undo across a flip: the undo goes through', 'tag undo across a flip: the day no longer holds the tag',
            // The undo of a rename across the same flip, pinned to one PATCH to the date-form id since the prediction fix.
            'undo across a flip: the undo goes through', 'undo across a flip: it goes to the date-form id alone',
            "undo across a flip: the day is back to the series' title, here and at the stand-in"],
    ],
    // Provider identity (server/tests/db/lib.php, identityViolations: I1), hung after every block of the database suite:
    // the one function that names an instance at Google builds the all-day form's suffix as the timed form's, so the row
    // a tag given to one ordinary day of an all-day series makes stores a timed id under an all-day master. The block
    // sends Google nothing, so only its identity check can see it.
    [
        'id' => 'all-day-id-in-timed-form', 'file' => 'server/src/Domain/GoogleWriter.php', 'dbtest' => 'identity-rule',
        'what' => 'GoogleWriter::instanceId builds an all-day instance\'s id in the timed form (<series id>_<date>T000000Z)',
        'patch' => [['$allDay ? \'Ymd\' : \'Ymd\THis\Z\'', '$allDay ? \'Ymd\THis\Z\' : \'Ymd\THis\Z\'']],
        'checks' => ["a tag given to one ordinary day of an all-day Google series: every live day row's Google id is its key's id under its series' form"],
    ],
    // The merge of a Google list (rule 1 of 1.5.5): a dropped item acts by its key again, whatever form its id names,
    // so it is taken for the resource of the series' form at that key. The checks are pure (server/tests/google-merge.php),
    // run through the database suite's file of the same name, which is how this runner judges by label. Each fails in
    // some order of its list or in all of them: a record or a row of the series' form removed, a skip of it cleared.
    [
        'id' => 'merge-dropped-by-key', 'file' => 'server/src/Domain/GoogleSync.php', 'dbtest' => 'google-merge',
        'what' => 'a dropped item of a Google list is related to a row or a skip by its key alone, not by the form its id names',
        'patch' => [["\$f = isset(\$ev['instance_all_day']) ? self::form((bool) \$ev['instance_all_day'])", "\$f = isset(\$ev['instance_all_day']) && empty(\$ev['dropped']) ? self::form((bool) \$ev['instance_all_day'])"]],
        'checks' => array_merge(
            array_merge(...array_map(static fn(string $form): array => array_map(static fn(string $what): string => "merge ($form): $what", [
                'a record of the series\' form stands beside the other form\'s dropped item on its key',
                'a read-back with the new master, a record and a skip of its form and the other form\'s dropped items on the same keys',
                'a row stored under the other form\'s id is its master\'s form, and the other form\'s dropped item leaves it',
                'a delta that carries the master keeps the stored skips, less the one a dropped item of its form names and the one a record brings back, plus a new cancelled record',
                'a delta that does not carry the master keeps the stored skips, less the one a dropped item of its form names and the one a record brings back, plus a new cancelled record',
                'a delta that changes no skipped day leaves the stored skips in their stored order',
                'a delta that skips two more days writes the skips in key order, whatever order it lists them in',
                'a stored row with no master goes with the dropped item of its own id, not with the other form\'s',
            ]), ['date', 'time'])),
            ['merge: an all-day day of a timed series is a row under its timed master'],
        ),
    ],
    // Rule 1, the sync's match (Feeds::standing): a record of a series whose form the list changes is for the row whose day
    // its key is, not the row at an equal key. Back at the equal key, the records west of UTC take the neighbouring day's
    // row (a Google poll, delta and whole; an ICS feed, both directions); the controls at UTC and east of it, the changes
    // of form with no record, and the CalDAV PUT (its own caller of the same function) do not fail.
    [
        'id' => 'sync-match-by-equal-key', 'file' => 'server/src/Domain/Feeds.php', 'dbtest' => 'sync-form-match',
        'what' => 'the sync gives a record to the stored row at an equal key, whatever form the row\'s series was stored in',
        'patch' => [['                $current = $rowFor[$key] ?? null;', '                $current = $existingByKey[$key] ?? null;']],
        'checks' => [
            'each of the four days is the row it was, at its own day\'s key in the new form: the notes on 5 and 8 January, the tag on 6 January, the reminder on 7 January',
            'the series is in its new form here, with no skipped day, no row left deleted and no day row without a key',
            'after: the same two rows on the same two days, as all-day days: the tag on 7 January, the record\'s title on 8 January',
            'after: the same two rows on the same two days, at 19:00: the record\'s title on 6 January, the tag on 7 January',
            // No row is at the record's key, so a new row is made and both of the day's rows merge into it.
            'one row for 4 January: the first of the two, with the record\'s title and both tags; the second is gone and no row is without a key',
        ],
    ],
    // The same patch where the list is a recovery's read-back (GoogleSeries::store after a change of form made here
    // stopped part way, the rows here still in the old form): with the edited day's record taken by Google in the new
    // form, the record goes to the tagged day's row, and Put back then finds the rows changed and is refused. The stops
    // right after the master write hold no record and do not fail, and Finish puts every row right from its snapshot.
    [
        'id' => 'sync-match-by-equal-key-in-a-store', 'file' => 'server/src/Domain/Feeds.php', 'dbtest' => 'sync-form-recovery',
        'what' => 'the sync gives a record to the stored row at an equal key when it stores what Google holds after a change of form stopped part way',
        'patch' => [['                $current = $rowFor[$key] ?? null;', '                $current = $existingByKey[$key] ?? null;']],
        'checks' => array_merge(...array_map(static fn(string $what): array => array_merge(...array_map(static fn(string $then): array => array_map(
            static fn(string $label): string => "$what, stopped with the notes' write taken and its answer lost, $then: $label",
            array_merge([
                'after the stop the tag is on its own day at the new form\'s key, with no notes, and no row is without a key',
                'after the stop the notes are on their own day, with no tag',
                'after the stop no other row holds the tag',
            ], $then === 'Finish' ? [] : [
                'Put back goes through, nothing left to settle and no row without a key',
                'after Put back Google holds the series as it was, the notes\' record under its old id',
                'after Put back the rows here are as they were: the tag and the notes each on their own day in the old form',
                'and they are the rows they were',
            ]),
        ), ['Finish', 'Put back'])), ['19:00 New York made all-day, a tag on 6 January and notes on the 7th', 'all-day made 19:00 New York, notes on 7 January and a tag on the 8th'])),
    ],
    // An undo's day (Undo::dayNow): a key names a day only under the series it was written in. Read under the series
    // as it stands, a redo of a day that had no row or skip when the series was made all-day or timed, or moved to
    // another zone, acts on the neighbouring day wherever the key's UTC date is not its local one: 19:00 New York both
    // ways, 07:00 Tokyo made all-day, 10:00 Los Angeles made timed, the zone change, and the forced undo on an own
    // calendar. The cases where the two readings agree, the days carried by a recorded move, and the refusals do not fail.
    [
        'id' => 'undo-day-read-as-the-series-stands', 'file' => 'server/src/Domain/Undo.php', 'dbtest' => 'undo-form-day',
        'what' => 'the day an undo acts on is its entry\'s key read under the series as it stands, not under the form and zone the key was written in',
        'patch' => [[', null, $series, Calc::dayOf($now, $frame[0], $frame[1]));', ', null, $series);']],
        'checks' => $undoDay['read'],
    ],
    // Staleness over the same day (Undo::staleFor): two grounds of days of a series re-formed between them compared
    // by their keys as written. A redo then overwrites newer notes on the same day where the day's key changed, and is
    // refused over a newer change of the neighbouring day whose new key is the old key's string.
    [
        'id' => 'undo-stale-by-key-across-a-reform', 'file' => 'server/src/Domain/Undo.php', 'dbtest' => 'undo-form-day',
        'what' => 'two day grounds of a series made all-day or timed, or moved to another zone, between them are compared by their keys, not by the day each names',
        'patch' => [["                \$hit = self::conflicts(self::atKey(\$mine, '#' . Calc::dayOf((string) self::dayKeyOf(\$mine), \$frame[0], \$frame[1])),\n                    self::atKey(\$later, '#' . Calc::dayOf((string) self::dayKeyOf(\$later), \$f[0], \$f[1])), 'events');",
            "                \$hit = self::conflicts(\$mine, \$later, 'events');"]],
        'checks' => $undoDay['stale'],
    ],
];
