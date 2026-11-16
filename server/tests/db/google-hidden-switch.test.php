<?php

declare(strict_types=1);

// A switch between timed and all-day that Google would hide days with is refused, naming them (decisions.md; probe
// 2026-11-13, 3.48 to 3.51 and 3.63 to 3.65; replay round 4, cause 1). At Google, a day of the new form at the instant
// of a record of the old (an edited or deleted day, or a record at the series' values) is hidden by the switch and can't
// be written back; the stand-in hides it as Google does. The planner refuses by the rows here; the run refuses by its
// first read of Google, which also shows a record at the series' values. Expectations by date. Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $tokyo = 'Asia/Tokyo';
    // Weekly at 09:00 Tokyo from Mon 4 Jan 2027: 00:00 UTC the same day, so each day's timed and all-day instants meet.
    $winter = ['start' => '2027-01-04T09:00:00+09:00', 'end' => '2027-01-04T10:00:00+09:00', 'tzid' => $tokyo, 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
    $toAllDay = ['scope' => 'all', 'instanceStart' => '2027-01-04T09:00:00+09:00', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true];
    $refusalOf = static function (object $t, array $body): array {
        $plan = $t->e->previewPatch($t->u, $t->id, $body + ['plan' => ['choices' => []]]);
        $r = array_values(array_filter($plan['refusals'] ?? [], static fn(array $x): bool => ($x['code'] ?? '') === 'googleHidden'))[0] ?? null;
        return [$r['days'] ?? null, $r['toAllDay'] ?? null, $r['clock'] ?? null, (string) ($r['words'] ?? ''), $r['deleted'] ?? null];
    };

    scenario('hidden switch: timed at 09:00 Tokyo (00:00 UTC) with a renamed and a deleted day, made all-day: refused, naming both, nothing sent', function () use ($db, $winter, $toAllDay, $refusalOf): void {
        $t = H::make($db, $winter);
        try {
            H::this($t, '2027-01-11T09:00:00+09:00', ['title' => 'Sample series (own)']);
            H::skip($t, '2027-01-18T09:00:00+09:00');
            [$days, $toAll, $clock, $words, $deleted] = $refusalOf($t, $toAllDay);
            checkEq('the deleted day among them is named as deleted', ['2027-01-18'], $deleted);
            checkEq('the plan is refused with the two days as they show here, to all-day', [['2027-01-11', '2027-01-18'], true, null], [$days, $toAll, $clock]);
            check('the sentence says it is Google\'s fault, names the day nobody deleted, and says what works', str_contains($words, 'Google Calendar has a fault here: if this event became all-day at its current time, one of its days (Mon 11 Jan') && str_contains($words, 'would stop showing there') && str_contains($words, 'though nothing deleted it. Move the event to another time first, then make it all-day.'), $words);
            $before = count($t->s->fake->requests);
            [, $error] = H::tryChange($t, $toAllDay);
            checkEq('the change is refused and Google is sent nothing', [true, []], [$error !== null, H::writes($t, $before)]);
        } finally {
            H::done($t);
        }
    });

    scenario('hidden switch: all-day with a renamed and a deleted day, made timed at 09:00 Tokyo: refused, the clock named', function () use ($db, $tokyo, $refusalOf): void {
        $t = H::make($db, ['start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true, 'rrule' => 'FREQ=WEEKLY;COUNT=6']);
        try {
            H::this($t, '2027-01-11', ['title' => 'Sample series (own)']);
            H::skip($t, '2027-01-18');
            [$days, $toAll, $clock, $words] = $refusalOf($t, ['scope' => 'all', 'instanceStart' => '2027-01-04', 'start' => '2027-01-04T09:00:00+09:00', 'end' => '2027-01-04T10:00:00+09:00', 'allDay' => false, 'tzid' => $tokyo]);
            checkEq('refused with both days, to timed at 09:00', [['2027-01-11', '2027-01-18'], false, '09:00'], [$days, $toAll, $clock]);
            check('the sentence says to make it timed at another time first', str_contains($words, 'Make it timed at another time first, then change the time to 09:00.'), $words);
        } finally {
            H::done($t);
        }
    });

    scenario('hidden switch: daily at 16:00 Los Angeles (00:00 UTC the next day) with five renamed days: the next days are the ones hidden, three named and the rest counted', function () use ($db, $la, $refusalOf): void {
        $t = H::make($db, ['start' => '2027-01-04T16:00:00-08:00', 'end' => '2027-01-04T17:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=DAILY;COUNT=10']);
        try {
            foreach (['05', '06', '07', '08', '09'] as $d) {
                H::this($t, "2027-01-{$d}T16:00:00-08:00", ['title' => "Sample series ($d)"]);
            }
            [$days, , , $words] = $refusalOf($t, ['scope' => 'all', 'instanceStart' => '2027-01-04T16:00:00-08:00', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true]);
            checkEq('the five days after the renamed ones would be hidden', ['2027-01-06', '2027-01-07', '2027-01-08', '2027-01-09', '2027-01-10'], $days);
            check('three named, two counted', str_contains($words, 'some of its days (Wed 6 Jan') && str_contains($words, 'Fri 8 Jan') && str_contains($words, 'and 2 more) would stop showing there, though nothing deleted them.'), $words);
        } finally {
            H::done($t);
        }
    });

    scenario('hidden switch: controls: at 10:00 Los Angeles, or with a day holding only a tag, the switch goes through', function () use ($db, $la, $winter, $toAllDay): void {
        $t = H::make($db, ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6']);
        try {
            H::this($t, '2027-01-11T10:00:00-08:00', ['title' => 'Sample series (own)']);
            [, $error] = H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true]);
            checkEq('10:00: the switch goes through', null, $error);
        } finally {
            H::done($t);
        }
        $t = H::make($db, $winter);
        try {
            H::this($t, '2027-01-11T09:00:00+09:00', ['tagNames' => ['sample-tag']]);
            [, $error] = H::tryChange($t, $toAllDay);
            checkEq('a day holding only a tag (an ordinary day at Google): the switch goes through', null, $error);
        } finally {
            H::done($t);
        }
    });

    scenario('hidden switch: a record at the series\' values that Google holds and the rows don\'t show: the run refuses by its first read, nothing sent', function () use ($db, $winter, $toAllDay): void {
        $t = H::make($db, $winter);
        try {
            // Another client writes 11 January at the series' own values: a record at Google, an ordinary day here.
            H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid . '_20270111T000000Z'), ['summary' => 'Sample series']);
            $t->s->poll();
            $rows = (int) $db->scalar('SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$t->id]);
            $before = count($t->s->fake->requests);
            [, $error] = H::tryChange($t, $toAllDay);
            checkEq('no row here for the day; the change is refused naming Mon 11 Jan, nothing sent, nothing half done', [0, true, true, [], []],
                [$rows, str_contains((string) $error, 'google_hidden_days'), str_contains((string) $error, 'Mon 11 Jan'), H::writes($t, $before), H::halfDone($t)]);
        } finally {
            H::done($t);
        }
    });
};
