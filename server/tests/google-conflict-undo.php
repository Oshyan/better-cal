<?php

declare(strict_types=1);

// The undo table of the conflict choice (docs/design/google-conflict-choice.md, the page "Slice 3a: What the Undo of One
// Edit Promises", (b)). Included by run.php, after google-conflict-day.php.
//
// An undo of an edit is a forward change back: base is what the entry left (A) for the fields it changed and the stored
// value for every other field, wanted is those fields as they were before the entry (P), now is what Google Calendar
// holds (G). Under test: GoogleConflict::threeWay over those three, and the undo's sentences' first-sentence helper.
// Every expectation is written here from the page: the outcome named in each row, checked against the reference
// (ThreeWayReference, google-conflict-threeway.php, written from the slice 1 page), which calls nothing in the product.
// Invented data.

use BetterCal\Domain\GoogleConflict;

if (!function_exists('check') || !class_exists('ThreeWayReference')) {
    fwrite(STDERR, "Run through server/tests/run.php\n");
    exit(1);
}

(static function (): void {
    $time = static fn(string $s, string $e, int $allDay = 0): array => ['start_utc' => $s, 'end_utc' => $e, 'all_day' => $allDay];
    // Each field: A (as the edit left it), P (as it was before the edit), X (a third value, changed again since; null
    // where the field has two values).
    $V = [
        'title' => ['A' => 'Weekly sync', 'P' => 'Standup', 'X' => 'Team sync'],
        'description' => ['A' => 'Agenda for the week', 'P' => 'Agenda', 'X' => 'Bring slides'],
        'location' => ['A' => 'Sample Hall', 'P' => 'Room 2', 'X' => 'Room 5'],
        'url' => ['A' => 'https://example.test/weekly', 'P' => null, 'X' => 'https://example.test/other'],
        'status' => ['A' => 'tentative', 'P' => 'confirmed', 'X' => null],
        'showAs' => ['A' => 'free', 'P' => 'busy', 'X' => null],
        'time' => ['A' => $time('2027-03-03 22:00:00', '2027-03-03 23:00:00'), 'P' => $time('2027-03-03 18:00:00', '2027-03-03 19:00:00'), 'X' => $time('2027-03-04 18:00:00', '2027-03-04 19:00:00')],
    ];
    // The time in its other forms, each as A, P, X: all-day dates; a move across the night the clocks change (the same
    // 10:00 Pacific wall clock is 18:00 UTC before 14 March 2027 and 17:00 UTC after); and an event that keeps the same
    // clock time in any zone, whose stored instants are compared like any (the flag is no part of the time).
    $forms = [
        'all-day' => ['A' => $time('2027-03-05 00:00:00', '2027-03-06 00:00:00', 1), 'P' => $time('2027-03-03 00:00:00', '2027-03-04 00:00:00', 1), 'X' => $time('2027-03-03 18:00:00', '2027-03-03 19:00:00')],
        'a clock change' => ['A' => $time('2027-03-15 17:00:00', '2027-03-15 18:00:00'), 'P' => $time('2027-03-12 18:00:00', '2027-03-12 19:00:00'), 'X' => $time('2027-03-15 18:00:00', '2027-03-15 19:00:00')],
        'same clock time in any zone' => ['A' => $time('2027-03-03 10:00:00', '2027-03-03 11:00:00'), 'P' => $time('2027-03-03 09:00:00', '2027-03-03 10:00:00'), 'X' => $time('2027-03-03 12:00:00', '2027-03-03 13:00:00')],
    ];
    // The stored event before the read, every field: what the edit left.
    $left = array_map(static fn(array $v): mixed => $v['A'], $V);
    $n = 0;
    $row = static function (string $name, array $base, array $now, array $wanted, array $expect) use (&$n): void {
        $n++;
        $got = GoogleConflict::threeWay($base, $now, $wanted);
        $named = array_intersect_key($got, $expect);
        ksort($expect);
        ksort($named);
        checkEq("Undo table: $name (the rows named)", $expect, $named);
        checkEq("Undo table: $name (every field, by the reference)", ThreeWayReference::outcomes($base, $now, $wanted), $got);
    };
    $cases = [];
    foreach ($V as $f => $v) {
        $cases[$f] = $v;
    }
    foreach ($forms as $form => $v) {
        $cases["time, $form"] = $v;
    }
    foreach ($cases as $name => $v) {
        $f = str_starts_with($name, 'time') ? 'time' : $name;
        $base = [$f => $v['A']] + $left;
        $wanted = [$f => $v['P']];
        // Case 1: nothing changed since: sent.
        $row("$name, nothing changed since", $base, $base, $wanted, [$f => 'send']);
        // Case 8: it already holds the value from before: sent (nothing left to send), never asked.
        $row("$name, already back at the value from before", $base, [$f => $v['P']] + $base, $wanted, [$f => 'send']);
        // Cases 5 to 7: changed again since, there or here (the read holds either): asked. Two-valued fields have no third value.
        if ($v['X'] !== null) {
            $row("$name, changed again since", $base, [$f => $v['X']] + $base, $wanted, [$f => 'ask']);
        }
        // Cases 2 and 3: every other field changed since and not put back: kept, and this one still sent.
        foreach ($V as $other => $o) {
            if ($other === $f) {
                continue;
            }
            $row("$name, with the $other changed since", $base, [$other => $o['P']] + $base, $wanted, [$f => 'send', $other => 'kept']);
            if ($v['X'] !== null) {
                $row("$name changed again, with the $other changed since", $base, [$f => $v['X'], $other => $o['P']] + $base, $wanted, [$f => 'ask', $other => 'kept']);
            }
        }
    }
    // An edit that changed two fields: one changed again asks, the other is still to send; nothing is sent while one asks (the caller's).
    $row('title and time, the title changed again since', $left, ['title' => 'Team sync'] + $left, ['title' => $V['title']['P'], 'time' => $V['time']['P']], ['title' => 'ask', 'time' => 'send']);
    // Undo anyway: the base of an asked field is what the question showed (`over`), so the same value at Google is sent, a newer one asks again.
    $row('Undo anyway, Google Calendar still holds what was shown', ['title' => 'Team sync'] + $left, ['title' => 'Team sync'] + $left, ['title' => 'Standup'], ['title' => 'send']);
    $row('Undo anyway, changed again before it', ['title' => 'Team sync'] + $left, ['title' => 'Retro'] + $left, ['title' => 'Standup'], ['title' => 'ask']);
    check("Undo table: every field and time form is in it ($n rows)", $n === 10 * 2 + 8 + 10 * 6 + 8 * 6 + 3);

    // The first sentence a plugin run's undo lists, and the day's refusal said as an undo's.
    checkEq('Undo words: the first sentence of one field', "After this change to 'Weekly sync', the title was changed again: it's 'Team sync' now.",
        GoogleConflict::question('Weekly sync', [['field' => 'title', 'was' => 'Weekly sync', 'now' => 'Team sync', 'yours' => 'Standup']], false, null, null, true)['first']);
    checkEq('Undo words: the first sentence of several fields', "After this change to 'Weekly sync', the title and the location were changed again.",
        GoogleConflict::question('Weekly sync', [['field' => 'title', 'was' => 'a', 'now' => 'b', 'yours' => 'c'], ['field' => 'location', 'was' => 'a', 'now' => 'b', 'yours' => 'c']], false, null, null, true)['first']);
    // What a plugin run's undo lists: a title is quoted whole there too, however much it shares with the other value.
    checkEq('Undo words: the first sentence quotes a title whole', "After this change to 'Sample check 159: single event mine', the title was changed again: it's 'Sample check 159: single event theirs' now.",
        GoogleConflict::question('Sample check 159: single event mine', [['field' => 'title', 'was' => 'Sample check 159: single event mine', 'now' => 'Sample check 159: single event theirs', 'yours' => 'Sample check 159: single event']], false, null, null, true)['first']);
    checkEq('Undo words: a day that is no longer a day',
        "That day is no longer one of the days of 'Team sync': the repeating event was changed in Google Calendar. Nothing was undone, and the calendar shows it as Google Calendar has it now.",
        GoogleConflict::dayGone('Team sync', true));
})();
