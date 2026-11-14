<?php

declare(strict_types=1);

// From the second independent read of the 1.5.6 fixes (its probe of finding F1, made a regular test): a day with a
// time of its own, and a day moved to another date, when the first day of a weekly series is dragged a week later
// for all events (the rule kept). The master write moves the series' start onto its second occurrence, so the keys
// of the later days stay days of the series and Google keeps their records, each with its start put back on its
// occurrence and its end left where it was (probe 3.5 of 2026-11-07, 3.17 of 2026-11-10, and 3.67 of 2026-11-14,
// which shows the end left on this very change), as the stand-in does.
//
// Expected, by the claim that a day ends as the person left it: the day with its own time is at 15:00 to 16:00 on the
// Monday it lands on (25 January: every day moves a week), the moved day stays on Tuesday 26 January 10:00 to 11:00
// (the plan's preselected choice keeps moved days), Monday 18 January is an ordinary day of an hour, and Review has
// nothing to say. Before the read-back rule (docs/design/google-series-read-back.md) the ordinary day came out six
// hours long and the own-time day 25 hours, written by the app's own requests, and Review said the time "was changed
// in Google Calendar while this change was being sent". Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $at = '2027-01-04T10:00:00-08:00';
    $utc = new DateTimeZone('UTC');
    $build = static function () use ($db, $la, $at): object {
        $t = H::make($db, ['start' => $at, 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6']);
        // Mon 18 Jan at its own time (15:00 to 16:00); Mon 25 Jan moved a day later (Tue 26 Jan, 10:00 to 11:00).
        H::this($t, '2027-01-18T10:00:00-08:00', ['title' => 'Own time', 'start' => '2027-01-18T15:00:00-08:00', 'end' => '2027-01-18T16:00:00-08:00']);
        H::this($t, '2027-01-25T10:00:00-08:00', ['title' => 'Moved', 'start' => '2027-01-26T10:00:00-08:00', 'end' => '2027-01-26T11:00:00-08:00']);
        return $t;
    };
    // Every day of the series from 18 January to 2 February, here (the window, with each row's end) and at Google (each
    // instance read by its id), as "start to end title", in UTC.
    $days = static function (object $t) use ($db, $utc): array {
        $here = [];
        foreach ($t->e->window($t->u, new DateTimeImmutable('2027-01-18 00:00:00', $utc), new DateTimeImmutable('2027-02-02 00:00:00', $utc), [$t->s->calendarId], null, true) as $x) {
            $here[] = (new DateTimeImmutable((string) $x['start']))->setTimezone($utc)->format('m-d H:i') . ' to ' . (new DateTimeImmutable((string) $x['end']))->setTimezone($utc)->format('m-d H:i') . ' ' . $x['title'];
        }
        sort($here);
        $there = [];
        foreach (['20270118T180000Z', '20270125T180000Z', '20270201T180000Z'] as $key) {
            [$status, $i] = H::atGoogle($t, 'GET', '/events/' . rawurlencode($t->gid . '_' . $key));
            if ($status === 200 && ($i['status'] ?? '') !== 'cancelled') {
                $there[] = (new DateTimeImmutable((string) $i['start']['dateTime']))->setTimezone($utc)->format('m-d H:i') . ' to ' . (new DateTimeImmutable((string) $i['end']['dateTime']))->setTimezone($utc)->format('m-d H:i') . ' ' . ($i['summary'] ?? '');
            }
        }
        sort($there);
        return ['here' => $here, 'google' => $there];
    };
    // 10:00 Los Angeles is 18:00 UTC; 15:00 to 16:00 is 23:00 to 00:00.
    $list = ['01-18 18:00 to 01-18 19:00 Sample series', '01-25 23:00 to 01-26 00:00 Own time', '01-26 18:00 to 01-26 19:00 Moved'];
    $want = ['here' => $list, 'google' => $list];
    $change = ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-11T10:00:00-08:00', 'end' => '2027-01-11T11:00:00-08:00'];

    scenario('the first day dragged a week later for all events, over a day at its own time and a day moved to another date', function () use ($db, $build, $days, $want, $change): void {
        $t = $build();
        try {
            [, $error] = H::tryChange($t, $change);
            $told = [];
            foreach ($db->all('SELECT summary, payload_json FROM review_items WHERE user_id = ? ORDER BY id', [$t->u]) as $item) {
                $told[] = $item['summary'] . ' ' . json_encode(json_decode((string) $item['payload_json'], true)['lines'] ?? []);
            }
            checkEq('the change goes through, nothing half done, and Review has nothing to say', [null, [], []], [$error, H::halfDone($t), $told]);
            checkEq('the day with its own time keeps it, the moved day stays moved, and 18 January is an ordinary hour, here and at Google', $want, $days($t));
        } finally {
            H::done($t);
        }
    });
};
