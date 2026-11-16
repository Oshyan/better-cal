<?php

declare(strict_types=1);

// A recovery that fails without writing anything leaves what the change is compared with as it was. Written by the
// independent reader of the read-back build (586b15f7..b4aaf63d) as probes: seven of these checks failed there,
// because a run's stop read was made the expectation of a step an earlier run had journaled, so a Finish refused at
// its first write made a change someone had made in Google Calendar look like the change's own, and the next Put
// back went over it unsaid. Each expectation comes from a written rule, named at the scenario, and is stated by
// calendar date; nothing in server/src is called to say what is expected. Invented data.
//
// The rules read against:
//   R-refusal  A refusal changes nothing and says something true (the owner's requirement for recovery, ledger A5).
//   R-putback  Put back runs only while Google holds each series exactly as the change last read it; otherwise it is
//              refused, pointing to Finish (docs/design/google-series-read-back.md, "At a Stop").
//   R-named    Where Finish can't tell its own change from another, it sets the days as the change has them and names
//              each day it replaced with both values (decisions.md, "Finish sets the plan's days where it can't tell
//              its own change from another"). A day changed in Google Calendar between a stop and Finish is replaced
//              and named.
//   R-read     "Every read the run makes after a write of its own" becomes the expectation (the model, "At a Stop"):
//              a run that wrote nothing has no read that may.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $year = gmdate('Y') === '2027' ? '' : ' 2027';
    // A weekly series of four Mondays from 4 January 2027 at 10:00 Los Angeles: 11 January renamed, 18 January deleted.
    $build = static function () use ($db, $la): object {
        $t = H::make($db, ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=4']);
        H::this($t, '2027-01-11T10:00:00-08:00', ['title' => 'Own title']);
        H::skip($t, '2027-01-18T10:00:00-08:00');
        return $t;
    };
    $reviewLines = static fn(object $t): array => array_merge(...array_map(static fn(array $i): array => json_decode((string) $i['payload_json'], true)['lines'] ?? [], $db->all('SELECT payload_json FROM review_items WHERE user_id = ?', [$t->u])), ...[[]]);
    $steps = static fn(object $t): array => json_decode((string) $t->db->journal()->scalar('SELECT steps_json FROM google_pending ORDER BY id DESC LIMIT 1'), true) ?: [];
    $expects = static fn(object $t): array => array_map(static fn(array $s): string => $s['kind'] . ':' . (empty($s['back']) ? 'fwd' : 'back') . ':' . ($s['state'] ?? '') . ':' . substr((string) ($s['expect'] ?? 'none'), 0, 8), $steps($t));
    // The rename for all events, stopped at its first day write (Google refuses that write; the series' write is taken).
    $stop = static function (object $t): ?string {
        $t->s->knobs->writes = 0;
        $t->s->knobs->beforeAt = 2;
        [, $stopped] = H::tryChange($t, ['scope' => 'all', 'title' => 'Renamed series']);
        $t->s->knobs->beforeAt = null;
        return $stopped;
    };
    // A recovery whose first write Google refuses (a 403: nothing is written there), the reads around it answered.
    $refusedAtFirstWrite = static function (object $t, string $how): ?string {
        $t->s->knobs->writes = 0;
        $t->s->knobs->beforeAt = 1;
        [, $error] = H::recover($t, $how);
        $t->s->knobs->beforeAt = null;
        return $error;
    };
    $noteOn25 = static fn(object $t): int => H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid . '_20270125T180000Z'), ['description' => 'Sample remote note'])[0];
    $asked = ['2027-01-04 10:00|Renamed series||', '2027-01-11 10:00|Own title||', '2027-01-25 10:00|Renamed series||'];
    $named25 = static fn(array $lines): bool => in_array('This change had stopped part way, and Google Calendar no longer held the event as it was left.', $lines, true)
        && array_filter($lines, static fn(string $l): bool => str_contains($l, 'Mon 25 Jan') && str_contains($l, 'Sample remote note')) !== [];

    // Control, R-named as decided: stop, a note put on 25 January in Google Calendar, Finish.
    scenario('control: a rename stopped, a note put on an ordinary day in Google Calendar, then Finish: the day is replaced and named', function () use ($build, $stop, $noteOn25, $reviewLines, $named25, $asked, $la): void {
        $t = $build();
        try {
            $stopped = $stop($t);
            $status = $noteOn25($t);
            [$out, $error] = H::recover($t, 'finish');
            checkEq('control: the change stops, the note lands at Google, Finish completes', [true, 200, null, []], [$stopped !== null, $status, $error, H::halfDone($t)]);
            $lines = array_merge((array) ($out['differences'] ?? []), $reviewLines($t));
            check('control (R-named): Finish says it replaced 25 January, with the note Google held', $named25($lines), json_encode($lines));
            checkEq('control: the calendar is as the change asked, here and at Google', $asked, H::settled($t, 'control', $la, '2027-01-01', '2027-02-15'));
        } finally {
            H::done($t);
        }
    });

    // R-refusal, R-read, R-named. The same, but the first Finish is refused by Google at its first write (nothing is
    // written there). The person presses Finish again. Google is exactly as it was when the control's Finish ran, so
    // Finish must do and say exactly what the control's did: replace 25 January and name it.
    scenario('a rename stopped, a note put on a day in Google Calendar, a Finish Google refuses at its first write, then Finish again: the day replaced must be named', function () use ($build, $stop, $noteOn25, $refusedAtFirstWrite, $reviewLines, $named25, $expects, $asked, $la): void {
        $t = $build();
        try {
            $stop($t);
            $before = $expects($t);
            $noteOn25($t);
            $googleBefore = H::googleView($t, $la);
            $refused = $refusedAtFirstWrite($t, 'finish');
            check('the first Finish is refused and the change stays half done', $refused !== null && count(H::halfDone($t)) === 1, (string) $refused);
            checkEq('(R-refusal) the refused Finish wrote nothing at Google', $googleBefore, H::googleView($t, $la));
            checkEq('(R-refusal, R-read) the refused Finish, which wrote nothing, leaves what the change is compared with as it was (the journal\'s expectations)', $before, $expects($t));
            [$out, $error] = H::recover($t, 'finish');
            checkEq('the second Finish completes', [null, []], [$error, H::halfDone($t)]);
            $lines = array_merge((array) ($out['differences'] ?? []), $reviewLines($t));
            check('(R-named) the second Finish replaced 25 January\'s note, so it names the day with the note Google held', $named25($lines), 'said: ' . json_encode($lines));
            checkEq('the calendar is as the change asked, here and at Google', $asked, H::settled($t, 'refused Finish then Finish', $la, '2027-01-01', '2027-02-15'));
        } finally {
            H::done($t);
        }
    });

    // R-putback, R-refusal. Put back is refused for the note (true). A Finish is then refused by Google at its first
    // write: nothing changed at Google. Put back again must be refused exactly as before: Google holds what it held at
    // the first refusal, and a refusal changes nothing.
    scenario('a rename stopped, a note put on a day in Google Calendar: Put back refused; a Finish Google refuses at its first write; Put back again must still be refused', function () use ($build, $stop, $noteOn25, $refusedAtFirstWrite, $expects, $la): void {
        $t = $build();
        try {
            $stop($t);
            $noteOn25($t);
            [, $first] = H::recover($t, 'putback');
            check('Put back is refused, since 25 January did change at Google since', $first !== null && str_contains($first, 'changed in Google Calendar since') && count(H::halfDone($t)) === 1, (string) $first);
            $googleBefore = H::googleView($t, $la);
            $before = $expects($t);
            $refused = $refusedAtFirstWrite($t, 'finish');
            check('Finish is refused by Google at its first write and the change stays half done', $refused !== null && count(H::halfDone($t)) === 1, (string) $refused);
            checkEq('(R-refusal) the refused Finish wrote nothing at Google', $googleBefore, H::googleView($t, $la));
            checkEq('(R-refusal, R-read) and leaves the journal\'s expectations as they were', $before, $expects($t));
            [, $second] = H::recover($t, 'putback');
            check('(R-putback) Put back is refused again: Google holds what it held when Put back was first refused', $second !== null && str_contains($second, 'changed in Google Calendar since') && count(H::halfDone($t)) === 1, 'answer: ' . json_encode($second) . ' half done: ' . count(H::halfDone($t)));
            check('(R-putback) and the note someone put on 25 January is still at Google', in_array('2027-01-25 10:00|Renamed series|Sample remote note|', H::googleView($t, $la), true), json_encode(H::googleView($t, $la)));
        } finally {
            H::done($t);
        }
    });

    // The same for a change to the series itself in Google Calendar: its place set there after the stop. The build's own
    // test (google-put-back-fails, "a Finish that fails part way; then Put back") expects Put back refused after a Finish
    // that failed with the network down; the rule does not depend on how Finish failed.
    scenario('a rename stopped, the place set in Google Calendar, a Finish Google refuses at its first write, then Put back: refused, the place still at Google', function () use ($build, $stop, $refusedAtFirstWrite, $la): void {
        $t = $build();
        try {
            $stop($t);
            [$status] = H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid), ['location' => 'Sample remote room']);
            $refused = $refusedAtFirstWrite($t, 'finish');
            check('the place lands, Finish is refused at its first write, the change stays half done', $status === 200 && $refused !== null && count(H::halfDone($t)) === 1, (string) $refused);
            [, $error] = H::recover($t, 'putback');
            check('(R-putback) Put back is refused, since the event did change at Google since', $error !== null && str_contains($error, 'changed in Google Calendar since') && count(H::halfDone($t)) === 1, 'answer: ' . json_encode($error));
            check('(R-putback) the place someone set is still at Google', ($t->s->fake->events[$t->gid]['location'] ?? '') === 'Sample remote room', json_encode($t->s->fake->events[$t->gid]['location'] ?? null));
        } finally {
            H::done($t);
        }
    });

    // R-refusal for Put back itself: Google refuses Put back's first write. Nothing changed; Put back again completes and
    // the calendar is as it was; apart, Finish completes and the calendar is as asked.
    foreach (['putback' => ['Put back again', ['2027-01-04 10:00|Sample series||', '2027-01-11 10:00|Own title||', '2027-01-25 10:00|Sample series||']], 'finish' => ['Finish', $asked]] as $how => [$word, $want]) {
        scenario("a rename stopped, a Put back Google refuses at its first write, then $word", function () use ($build, $stop, $refusedAtFirstWrite, $expects, $reviewLines, $la, $how, $word, $want): void {
            $t = $build();
            try {
                $stop($t);
                $before = $expects($t);
                $googleBefore = H::googleView($t, $la);
                $refused = $refusedAtFirstWrite($t, 'putback');
                check('Put back is refused by Google at its first write and the change stays half done', $refused !== null && count(H::halfDone($t)) === 1, (string) $refused);
                checkEq('(R-refusal) nothing changed at Google, and the journal\'s expectations are as they were', [$googleBefore, $before], [H::googleView($t, $la), $expects($t)]);
                [, $error] = H::recover($t, $how);
                checkEq("$word completes, nothing half done, Review silent", [null, [], []], [$error, H::halfDone($t), $reviewLines($t)]);
                checkEq("after $word the calendar is as the rule says, here and at Google", $want, H::settled($t, "refused Put back then $word", $la, '2027-01-01', '2027-02-15'));
            } finally {
                H::done($t);
            }
        });
    }
};
