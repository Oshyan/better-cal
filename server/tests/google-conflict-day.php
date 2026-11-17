<?php

declare(strict_types=1);

// The day table of the conflict choice (docs/design/google-conflict-choice.md, the page "Slice 2: What a Change to One
// Day of a Repeating Event Promises", (b), (c) and (f)). Included by run.php, after google-conflict-threeway.php.
//
// Under test: where a day's base comes from (GoogleSeries::dayStored), where its "now" comes from (GoogleSeries::dayNow),
// the comparison over them (GoogleConflict::threeWay) and where a change was made (GoogleConflict::from). Every
// expectation comes from the reference below, written from the page's sentences, which calls nothing in the product:
//
//   base: an edited day's own fields from its row, a field it does not own from the repeating event's row, an ordinary
//         day the repeating event at that day (its fields, the day's start, the event's length).
//   now:  the day's record in the read when Google Calendar holds one, else the repeating event's values at that day
//         as the read has them.
//   outcome: the slice 1 page's rule (ThreeWayReference, google-conflict-threeway.php, written from that page).
//   from: `series` when the repeating event's own value at that day differs between before and after and the day now
//         shows the new value; `day` otherwise.
//
// The stored rows and the Google resources are built here by hand, one field varied at a time. Invented data.

use BetterCal\Domain\GoogleConflict;
use BetterCal\Domain\GoogleSeries;

if (!function_exists('check') || !class_exists('ThreeWayReference')) {
    fwrite(STDERR, "Run through server/tests/run.php\n");
    exit(1);
}

(static function (): void {
    $key = '2027-03-10 22:00:00'; // the day: the second Wednesday of a weekly event that starts 2027-03-03 22:00 UTC
    $time = static fn(string $s, string $e): array => ['start_utc' => $s, 'end_utc' => $e, 'all_day' => 0];
    // Each field: S the repeating event's value as stored, S2 its value in Google Calendar when it changed there, R the
    // day's own value as stored, G another value a record can hold, T a value a request can set (for a field of two
    // values, the one that is not the base).
    $V = [
        'title' => ['S' => 'Team sync', 'S2' => 'Standup', 'R' => 'Planning day', 'G' => 'Review day', 'T' => 'Weekly sync'],
        'description' => ['S' => 'Agenda for the week', 'S2' => 'Agenda and the slides', 'R' => 'Bring the notes', 'G' => 'Bring the laptop', 'T' => 'Bring nothing'],
        'location' => ['S' => 'Room 2', 'S2' => 'Sample Hall', 'R' => 'Room 5', 'G' => 'Room 7', 'T' => 'Room 9'],
        'url' => ['S' => 'https://example.test/sync', 'S2' => 'https://example.test/standup', 'R' => 'https://example.test/day', 'G' => 'https://example.test/review', 'T' => 'https://example.test/weekly'],
        'status' => ['S' => 'confirmed', 'S2' => 'tentative', 'R' => 'tentative', 'G' => 'tentative', 'T' => null],
        'showAs' => ['S' => 'busy', 'S2' => 'free', 'R' => 'free', 'G' => 'free', 'T' => null],
        // The repeating event's time at the day is the day's start and the event's length: an hour as stored, two when
        // its length changed in Google Calendar. A day's own time, a record's and a request's are other hours.
        'time' => ['S' => $time('2027-03-10 22:00:00', '2027-03-10 23:00:00'), 'S2' => $time('2027-03-10 22:00:00', '2027-03-11 00:00:00'),
            'R' => $time('2027-03-10 20:00:00', '2027-03-10 21:00:00'), 'G' => $time('2027-03-10 18:00:00', '2027-03-10 19:00:00'), 'T' => $time('2027-03-11 22:00:00', '2027-03-11 23:00:00')],
    ];
    $column = ['title' => 'title', 'description' => 'description', 'location' => 'location', 'url' => 'url', 'status' => 'status', 'showAs' => 'show_as'];
    $other = static fn(string $f, mixed $v): mixed => $f === 'status' ? ($v === 'tentative' ? 'confirmed' : 'tentative') : ($v === 'free' ? 'busy' : 'free');

    // A stored row with field $f at $value over $row.
    $with = static function (array $row, string $f, mixed $value) use ($column): array {
        return $f === 'time' ? array_merge($row, $value) : array_merge($row, [$column[$f] => $value]);
    };
    $masterRow = static function (array $values) use ($with): array {
        $row = ['id' => 1, 'uid' => 'smplrep1@google.com', 'google_event_id' => 'smplrep1', 'rrule' => 'FREQ=WEEKLY;COUNT=6', 'exdates_json' => null, 'tzid' => 'UTC',
            'recurrence_parent_id' => null, 'recurrence_instance_utc' => null, 'override_fields' => null];
        foreach ($values as $f => $v) {
            // The repeating event's own time is its first day's: a week before the day, the same clock and length.
            $row = $with($row, $f, $f === 'time' ? ['start_utc' => '2027-03-03 22:00:00', 'end_utc' => $v['end_utc'] === '2027-03-11 00:00:00' ? '2027-03-04 00:00:00' : '2027-03-03 23:00:00', 'all_day' => 0] : $v);
        }
        return $row;
    };
    // A Google resource holding $values (by field), as the master or as the day's record.
    $resource = static function (array $values, bool $record) use ($key): array {
        $iso = static fn(string $db): string => str_replace(' ', 'T', $db) . '+00:00';
        $t = $values['time'];
        $r = ['kind' => 'calendar#event', 'id' => 'smplrep1', 'iCalUID' => 'smplrep1@google.com', 'etag' => '"e1"',
            'summary' => $values['title'], 'description' => $values['description'], 'location' => $values['location'], 'source' => ['url' => $values['url'], 'title' => 'link'],
            'status' => $values['status'], 'transparency' => $values['showAs'] === 'free' ? 'transparent' : 'opaque'];
        if ($record) {
            return ['id' => 'smplrep1_20270310T220000Z', 'recurringEventId' => 'smplrep1', 'originalStartTime' => ['dateTime' => $iso($key), 'timeZone' => 'UTC'],
                'start' => ['dateTime' => $iso($t['start_utc']), 'timeZone' => 'UTC'], 'end' => ['dateTime' => $iso($t['end_utc']), 'timeZone' => 'UTC']] + $r;
        }
        $twoHours = $t['end_utc'] === '2027-03-11 00:00:00';
        return ['start' => ['dateTime' => '2027-03-03T22:00:00+00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => $twoHours ? '2027-03-04T00:00:00+00:00' : '2027-03-03T23:00:00+00:00', 'timeZone' => 'UTC'],
            'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']] + $r;
    };
    $deleted = array_fill_keys(ThreeWayReference::FIELDS, GoogleConflict::DELETE);
    $refDeleted = array_fill_keys(ThreeWayReference::FIELDS, new \stdClass());

    $rows = 0;
    $failed = [];
    foreach (array_keys($V) as $f) {
        $S = array_map(static fn(array $v): mixed => $v['S'], $V);
        foreach (['owns the field', 'has a row that owns another field', 'has no row'] as $owns) {
            foreach ([false, true] as $seriesChanged) {
                foreach (['none', 'its base value', 'another value', "the repeating event's value now"] as $record) {
                    // The page's reading, by hand. Base: the day's own value where it owns the field, else the event's.
                    $baseValue = $owns === 'owns the field' ? $V[$f]['R'] : $V[$f]['S'];
                    $seriesNow = $seriesChanged ? $V[$f]['S2'] : $V[$f]['S'];
                    $nowValue = match ($record) {
                        'none' => $seriesNow,
                        'its base value' => $baseValue,
                        'another value' => $V[$f]['G'],
                        default => $seriesNow,
                    };
                    // A day that owns a field Google holds has a record there; the table still asks what "now" is without one.
                    $another = $f === 'title' ? 'location' : 'title';
                    $rowValues = $owns === 'owns the field' ? [$f => $V[$f]['R']] + $S : ($owns === 'has no row' ? null : [$another => $V[$another]['R']] + $S);
                    $master = $masterRow($S);
                    $row = $rowValues === null ? null : ['id' => 2, 'recurrence_parent_id' => 1, 'recurrence_instance_utc' => $key, 'tzid' => 'UTC', 'uid' => 'smplrep1@google.com',
                        'google_event_id' => 'smplrep1_20270310T220000Z', 'rrule' => null, 'override_fields' => json_encode([$owns === 'owns the field' ? $f : $another])];
                    foreach ((array) $rowValues as $g => $v) {
                        $row = $with($row, $g, $v);
                    }
                    $baseRef = [$f => $baseValue] + ($owns === 'has a row that owns another field' ? [$another => $V[$another]['R']] : []) + $S;
                    $googleMaster = [$f => $seriesNow] + $S;
                    $items = [$resource($googleMaster, false)];
                    $nowRef = [$f => $nowValue] + $googleMaster;
                    if ($record !== 'none') {
                        // The record holds the event's values now but for this field (and what the day owns beside it).
                        $recordValues = [$f => $nowValue] + ($owns === 'has a row that owns another field' ? [$another => $V[$another]['R']] : []) + $googleMaster;
                        $items[] = $resource($recordValues, true);
                        $nowRef = $recordValues;
                    }
                    $third = $V[$f]['T'] ?? $other($f, $baseValue);
                    foreach (['absent' => [], 'at its base value' => [$f => $baseValue], 'another value' => [$f => $third], 'a delete' => null] as $request => $wanted) {
                        $rows++;
                        $label = "$f: the day $owns; the repeating event " . ($seriesChanged ? 'changed' : 'unchanged') . " in Google Calendar; the day's record: $record; the request: $request";
                        // The slice 1 page: a field sent at its base value is not wanted, so the reference is given none.
                        $expect = ThreeWayReference::outcomes($baseRef, $nowRef, $request === 'at its base value' ? [] : ($wanted ?? $refDeleted));
                        $expectFrom = !ThreeWayReference::eq($f, $V[$f]['S'], $seriesNow) && ThreeWayReference::eq($f, $nowValue, $seriesNow) ? 'series' : 'day';
                        $base = GoogleConflict::mapOf(GoogleSeries::dayStored($master, $row, $key));
                        $nowRow = GoogleSeries::dayNow($items, 'smplrep1', $key);
                        $now = $nowRow === null ? null : GoogleConflict::mapOf($nowRow);
                        $got = $now === null ? 'no such day in the read' : GoogleConflict::threeWay($base, $now, $wanted ?? $deleted);
                        $gotFrom = $now === null ? null : GoogleConflict::from($f, GoogleConflict::mapOf(GoogleSeries::dayStored($master, null, $key)),
                            GoogleConflict::mapOf(GoogleSeries::dayStored($masterRow($googleMaster), null, $key)), $now);
                        if ($got !== $expect || $gotFrom !== $expectFrom) {
                            $failed[] = $label . ': expected ' . json_encode([$expect[$f], $expectFrom]) . ', got ' . json_encode([is_array($got) ? $got[$f] : $got, $gotFrom])
                                . (is_array($got) && array_diff_assoc($got, $expect) !== [] ? ' (differs in ' . implode(', ', array_keys(array_diff_assoc($got, $expect))) . ')' : '');
                        }
                    }
                }
            }
        }
    }
    checkEq("Conflict choice, the day table: $rows rows, each field by who owns it, what changed in Google Calendar and what the request sets", [], array_slice($failed, 0, 12));

    // Rows by hand, with their literal outcomes, so a mistake in the reference shows as its own failure.
    $S = array_map(static fn(array $v): mixed => $v['S'], $V);
    $ordinary = GoogleConflict::mapOf(GoogleSeries::dayStored($masterRow($S), null, $key));
    checkEq('Day table, by hand: an ordinary day\'s base is the repeating event at that day, its start and the event\'s length',
        ['Team sync', 'Room 2', ['start_utc' => '2027-03-10 22:00:00', 'end_utc' => '2027-03-10 23:00:00', 'all_day' => 0]], [$ordinary['title'], $ordinary['location'], $ordinary['time']]);
    $renamed = [$resource(['title' => 'Standup'] + $S, false)];
    $now = GoogleConflict::mapOf((array) GoogleSeries::dayNow($renamed, 'smplrep1', $key));
    checkEq('Day table, by hand: the repeating event renamed, an ordinary day dragged: the title kept, the time sent', ['kept', 'send'],
        [GoogleConflict::threeWay($ordinary, $now, ['time' => $V['time']['T']])['title'], GoogleConflict::threeWay($ordinary, $now, ['time' => $V['time']['T']])['time']]);
    checkEq('Day table, by hand: the repeating event renamed, an ordinary day renamed: asked, from series', ['ask', 'series'],
        [GoogleConflict::threeWay($ordinary, $now, ['title' => 'Weekly sync'])['title'],
            GoogleConflict::from('title', $ordinary, GoogleConflict::mapOf(GoogleSeries::dayStored($masterRow(['title' => 'Standup'] + $S), null, $key)), $now)]);
    // Another day's record changed: this day's "now" is the repeating event's, unchanged.
    $otherDay = ['id' => 'smplrep1_20270317T220000Z', 'originalStartTime' => ['dateTime' => '2027-03-17T22:00:00+00:00', 'timeZone' => 'UTC'], 'summary' => 'Offsite',
        'start' => ['dateTime' => '2027-03-17T22:00:00+00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2027-03-17T23:00:00+00:00', 'timeZone' => 'UTC']] + $resource($S, true);
    $now = GoogleConflict::mapOf((array) GoogleSeries::dayNow([$resource($S, false), $otherDay], 'smplrep1', $key));
    checkEq('Day table, by hand: another day renamed in Google Calendar leaves every field of this day the same, and a delete of this day goes',
        [array_fill_keys(GoogleConflict::FIELDS, 'same'), array_fill_keys(GoogleConflict::FIELDS, 'send')],
        [GoogleConflict::threeWay($ordinary, $now, []), GoogleConflict::threeWay($ordinary, $now, $deleted)]);
    // The day skipped in Google Calendar, or no day of the event as read: no "now".
    $cancelled = ['status' => 'cancelled'] + $resource($S, true);
    checkEq('Day table, by hand: a day skipped in Google Calendar, and a day the read\'s rule does not give, have no now', [null, null],
        [GoogleSeries::dayNow([$resource($S, false), $cancelled], 'smplrep1', $key), GoogleSeries::dayNow([$resource($S, false)], 'smplrep1', '2027-03-11 22:00:00')]);

    // ---- Time forms, by hand ------------------------------------------------------------------------------------------
    $plain = ['title' => 'Team day', 'description' => null, 'location' => null, 'url' => null, 'status' => 'confirmed', 'show_as' => 'busy'];
    $timeOfMap = static fn(?array $row): ?array => $row === null ? null : GoogleConflict::mapOf($row)['time'];
    $gPlain = ['kind' => 'calendar#event', 'etag' => '"e1"', 'summary' => 'Team day', 'status' => 'confirmed'];

    // An all-day repeating event, Wednesdays from 3 March 2027: a day is its date and the day after, all-day.
    $allDayMaster = $plain + ['id' => 1, 'uid' => 'smplday1@google.com', 'google_event_id' => 'smplday1', 'rrule' => 'FREQ=WEEKLY;COUNT=6', 'exdates_json' => null,
        'start_utc' => '2027-03-03 00:00:00', 'end_utc' => '2027-03-04 00:00:00', 'all_day' => 1, 'tzid' => 'UTC'];
    $gAllDay = $gPlain + ['id' => 'smplday1', 'iCalUID' => 'smplday1@google.com', 'start' => ['date' => '2027-03-03'], 'end' => ['date' => '2027-03-04'], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']];
    $dayKey = '2027-03-10 00:00:00';
    $allDayBase = ['start_utc' => '2027-03-10 00:00:00', 'end_utc' => '2027-03-11 00:00:00', 'all_day' => 1];
    checkEq('Day table, all-day: an ordinary day\'s base and its now are its date and the day after, all-day', [$allDayBase, $allDayBase],
        [$timeOfMap(GoogleSeries::dayStored($allDayMaster, null, $dayKey)), $timeOfMap(GoogleSeries::dayNow([$gAllDay], 'smplday1', $dayKey))]);
    // The day moved to the next date in Google Calendar: a record of the event's own form.
    $movedDay = $gPlain + ['id' => 'smplday1_20270310', 'iCalUID' => 'smplday1@google.com', 'recurringEventId' => 'smplday1', 'originalStartTime' => ['date' => '2027-03-10'],
        'start' => ['date' => '2027-03-11'], 'end' => ['date' => '2027-03-12']];
    $movedNow = GoogleConflict::mapOf((array) GoogleSeries::dayNow([$gAllDay, $movedDay], 'smplday1', $dayKey));
    $base = GoogleConflict::mapOf(GoogleSeries::dayStored($allDayMaster, null, $dayKey));
    $dragged = ['start_utc' => '2027-03-12 00:00:00', 'end_utc' => '2027-03-13 00:00:00', 'all_day' => 1];
    checkEq('Day table, all-day: the day moved in Google Calendar: its now is the new date; a drag elsewhere asks, a rename keeps the time, the same drag is sent',
        [['start_utc' => '2027-03-11 00:00:00', 'end_utc' => '2027-03-12 00:00:00', 'all_day' => 1], 'ask', 'kept', 'send'],
        [$movedNow['time'], GoogleConflict::threeWay($base, $movedNow, ['time' => $dragged])['time'], GoogleConflict::threeWay($base, $movedNow, ['title' => 'Planning day'])['time'],
            GoogleConflict::threeWay($base, $movedNow, ['time' => $movedNow['time']])['time']]);
    // A day of the other form: made timed in Google Calendar (a day may take the other form there).
    $timedDay = ['start' => ['dateTime' => '2027-03-10T10:00:00+00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2027-03-10T11:00:00+00:00', 'timeZone' => 'UTC']] + $movedDay;
    $timedNow = GoogleConflict::mapOf((array) GoogleSeries::dayNow([$gAllDay, $timedDay], 'smplday1', $dayKey));
    checkEq('Day table, all-day: a day made timed in Google Calendar: its now is timed; kept when the time is not wanted, asked about on a drag of the dates',
        [['start_utc' => '2027-03-10 10:00:00', 'end_utc' => '2027-03-10 11:00:00', 'all_day' => 0], 'kept', 'ask'],
        [$timedNow['time'], GoogleConflict::threeWay($base, $timedNow, [])['time'], GoogleConflict::threeWay($base, $timedNow, ['time' => $dragged])['time']]);
    // The same day stored here timed, as its own (the row owns its all-day setting and time): the base is the row's.
    $timedRow = $plain + ['id' => 2, 'uid' => 'smplday1@google.com', 'google_event_id' => 'smplday1_20270310', 'rrule' => null, 'recurrence_parent_id' => 1, 'recurrence_instance_utc' => $dayKey,
        'start_utc' => '2027-03-10 10:00:00', 'end_utc' => '2027-03-10 11:00:00', 'all_day' => 0, 'tzid' => 'UTC', 'override_fields' => json_encode(['allDay', 'time', 'length'])];
    checkEq('Day table, all-day: a day stored timed as its own has its row\'s time as base, and nothing differs from the read', [['start_utc' => '2027-03-10 10:00:00', 'end_utc' => '2027-03-10 11:00:00', 'all_day' => 0], 'same'],
        [$timeOfMap(GoogleSeries::dayStored($allDayMaster, $timedRow, $dayKey)), GoogleConflict::threeWay(GoogleConflict::mapOf(GoogleSeries::dayStored($allDayMaster, $timedRow, $dayKey)), $timedNow, [])['time']]);

    // A zone with a clock change: Sundays at 01:00 for half an hour in America/Los_Angeles from 7 March 2027; the clocks
    // go forward at 02:00 on 14 March. 01:00 is 09:00 UTC on the 7th and the 14th (standard time), 08:00 UTC on the 21st.
    $zoneMaster = $plain + ['id' => 1, 'uid' => 'smplnight1@google.com', 'google_event_id' => 'smplnight1', 'rrule' => 'FREQ=WEEKLY;COUNT=4', 'exdates_json' => null,
        'start_utc' => '2027-03-07 09:00:00', 'end_utc' => '2027-03-07 09:30:00', 'all_day' => 0, 'tzid' => 'America/Los_Angeles'];
    $gZone = $gPlain + ['id' => 'smplnight1', 'iCalUID' => 'smplnight1@google.com', 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4'],
        'start' => ['dateTime' => '2027-03-07T01:00:00-08:00', 'timeZone' => 'America/Los_Angeles'], 'end' => ['dateTime' => '2027-03-07T01:30:00-08:00', 'timeZone' => 'America/Los_Angeles']];
    foreach (['the week before' => '2027-03-07 09:00:00', 'the night of the change' => '2027-03-14 09:00:00', 'the week after' => '2027-03-21 08:00:00'] as $label => $k) {
        $want = ['start_utc' => $k, 'end_utc' => substr($k, 0, 14) . '30:00', 'all_day' => 0];
        checkEq("Day table, a zone with a clock change, $label: the day's base and its now are its own instant and the event's half hour", [$want, $want],
            [$timeOfMap(GoogleSeries::dayStored($zoneMaster, null, $k)), $timeOfMap(GoogleSeries::dayNow([$gZone], 'smplnight1', $k))]);
    }
    // An instant the event's clock does not give on that date is no day of it: 01:00 daylight time on the 21st is one, 01:00 standard time is not.
    checkEq('Day table, a zone with a clock change: the week after, the standard-time instant is no day of the event', null, GoogleSeries::dayNow([$gZone], 'smplnight1', '2027-03-21 09:00:00'));
})();
