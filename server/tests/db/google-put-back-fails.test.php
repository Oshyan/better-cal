<?php

declare(strict_types=1);

// The journaled plan is the change's plan, written once before its first request and never replaced by a run of
// either side (decisions.md, "The journaled plan is written once"). Until 1.5.6 a run that stopped wrote its own
// targets over it, and a Put back's targets are the series as they were before: so after a Put back that failed part
// way, Finish worked from the before state. On the tree before the read-back rule that left the half-done state as
// the end, with a day's own title lost and nothing said; the mid-change reading took it for someone else's change.
//
// Three stopped changes, each followed by a Put back that fails part way, and then, apart, by Finish (the calendar as
// the change asked) and by Put back again (the calendar as it was). The third is a Put back that makes something
// again before it fails: a deleted repeating event brought back, its days with it (probe 10.4 of 2026-11-13). Nothing
// a Put back makes needs journaling to be found again: it makes it under the ids journaled before the change's first
// request, and Finish and Put back read Google by those ids. Expectations by date, written here from what was asked;
// Google read by the stand-in's own listing. Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    // A weekly series of four Mondays from 4 January 2027 at 10:00 Los Angeles: 11 January renamed, 18 January deleted.
    $build = static function () use ($db, $la): object {
        $t = H::make($db, ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=4']);
        H::this($t, '2027-01-11T10:00:00-08:00', ['title' => 'Own title']);
        H::skip($t, '2027-01-18T10:00:00-08:00');
        return $t;
    };
    $days = static fn(string $time, string $title): array => ["2027-01-04 $time|$title||", "2027-01-11 $time|Own title||", "2027-01-25 $time|$title||"];
    $asItWas = $days('10:00', 'Sample series');
    $planTitles = static fn(): array => array_column(array_column(json_decode((string) $db->journal()->scalar('SELECT targets_json FROM google_pending ORDER BY id DESC LIMIT 1'), true) ?: [], 'master'), 'title');
    $reviewLines = static fn(object $t): array => array_map(static fn(array $i): array => json_decode((string) $i['payload_json'], true)['lines'] ?? [], $db->all('SELECT payload_json FROM review_items WHERE user_id = ?', [$t->u]));
    /** A change stopped at its first day write (refused), then a Put back whose first write goes nowhere. */
    $stopAndFailPutBack = static function (object $t, array $body, string $name) use ($planTitles): void {
        $t->s->knobs->writes = 0;
        $t->s->knobs->beforeAt = 2;
        [, $stopped] = H::tryChange($t, ['scope' => 'all'] + $body);
        $t->s->knobs->beforeAt = null;
        checkEq("$name: the change stops after the series' write and is listed half done", [true, 1], [$stopped !== null, count(H::halfDone($t))]);
        $plan = $planTitles();
        $t->s->knobs->writes = 0;
        $t->s->knobs->netAt = 1;
        [, $failed] = H::recover($t, 'putback');
        $t->s->knobs->netAt = null;
        $t->s->knobs->down = false;
        check("$name: Put back fails at its first write, and the change stays half done", $failed !== null && count(H::halfDone($t)) === 1, (string) $failed);
        checkEq("$name: the journaled plan is the change's still, not the series as it was", $plan, $planTitles());
    };
    $changes = [
        'renamed for all events' => [['title' => 'Renamed series'], $days('10:00', 'Renamed series')],
        'moved to 11:00 for all events' => [['instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04T11:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00'], $days('11:00', 'Sample series')],
    ];
    foreach ($changes as $what => [$body, $asked]) {
        foreach (['finish' => ['Finish', $asked, 'as the change asked, the renamed day keeping its own title'], 'putback' => ['Put back again', $asItWas, 'as it was before the change']] as $how => [$word, $want, $as]) {
            scenario("$what, stopped; a Put back that fails part way; then $word", function () use ($build, $stopAndFailPutBack, $reviewLines, $la, $what, $body, $how, $word, $want, $as): void {
                $t = $build();
                try {
                    $name = "$what, then a failed Put back, then $word";
                    $stopAndFailPutBack($t, $body, $name);
                    [, $error] = H::recover($t, $how);
                    checkEq("$name: it completes, nothing is left half done, and Review has nothing to say", [null, [], []], [$error, H::halfDone($t), $reviewLines($t)]);
                    checkEq("$name: the calendar is $as, by date, here and at Google", $want, H::settled($t, $name, $la, '2027-01-01', '2027-02-15'));
                } finally {
                    H::done($t);
                }
            });
        }
    }

    // The same rule for a Finish that fails part way. Its targets are its reading of the plan over what Google held when
    // it ran (here the place set in Google Calendar since the stop, which the rename does not change and so keeps), and
    // until 1.5.6 a Finish that stopped journaled that reading over the plan. The plan stays the change's; Finish again
    // completes it, keeping the place and saying what it replaced on the renamed day; Put back, apart, is refused, since
    // the place did change at Google since, and says so.
    $plan = static fn(): mixed => json_decode((string) $db->journal()->scalar('SELECT targets_json FROM google_pending ORDER BY id DESC LIMIT 1'), true);
    foreach (['finish' => 'Finish again', 'putback' => 'Put back'] as $how => $word) {
        scenario("renamed for all events, stopped; the place set in Google Calendar; a Finish that fails part way; then $word", function () use ($build, $reviewLines, $plan, $la, $how, $word): void {
            $t = $build();
            try {
                $name = "renamed, the place set at Google, then a failed Finish, then $word";
                $t->s->knobs->writes = 0;
                $t->s->knobs->beforeAt = 2;
                [, $stopped] = H::tryChange($t, ['scope' => 'all', 'title' => 'Renamed series']);
                $t->s->knobs->beforeAt = null;
                $journaled = $plan();
                [$status] = H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid), ['location' => 'Sample remote room']);
                $t->s->knobs->writes = 0;
                $t->s->knobs->netAt = 1;
                [, $failed] = H::recover($t, 'finish');
                $t->s->knobs->netAt = null;
                $t->s->knobs->down = false;
                check("$name: the change stops, the place is set at Google, Finish fails at its first write, and the change stays half done",
                    $stopped !== null && $status === 200 && $failed !== null && count(H::halfDone($t)) === 1, (string) $failed);
                checkEq("$name: the journaled plan is as it was journaled, not Finish's reading of it", $journaled, $plan());
                [, $error] = H::recover($t, $how);
                if ($how === 'putback') {
                    check("$name: Put back is refused, since the event did change at Google since, and the change stays half done",
                        $error !== null && str_contains($error, "'Sample series' changed in Google Calendar since.") && count(H::halfDone($t)) === 1, (string) $error);
                    checkEq("$name: the journaled plan is still as it was journaled", $journaled, $plan());
                    return;
                }
                $year = gmdate('Y') === '2027' ? '' : ' 2027';
                checkEq("$name: it completes, nothing is left half done, and Review says what Finish replaced on the renamed day", [null, [], [[
                    'This change had stopped part way, and Google Calendar no longer held the event as it was left.',
                    "On Mon 11 Jan$year Google Calendar held the title 'Renamed series'; Finish set the title 'Own title', as this change has it.",
                ]]], [$error, H::halfDone($t), $reviewLines($t)]);
                checkEq("$name: the calendar is as the change asked, with the place Google holds, the renamed day keeping its own title, here and at Google",
                    ['2027-01-04 10:00|Renamed series||Sample remote room', '2027-01-11 10:00|Own title||Sample remote room', '2027-01-25 10:00|Renamed series||Sample remote room'],
                    H::settled($t, $name, $la, '2027-01-01', '2027-02-15'));
            } finally {
                H::done($t);
            }
        });
    }

    // A Put back that makes something again and then fails. The change deletes the whole repeating event and stops
    // with the network down right after the series is deleted at Google. Put back brings the series back under its
    // own id (every day's record comes back with it, the deleted day live among them), and its next write, which
    // deletes 18 January again, goes nowhere. What Put back made is found by the series' id, journaled with the plan
    // and with the before from the start: Finish deletes it all again, and Put back again finishes putting it back.
    foreach (['finish' => 'Finish', 'putback' => 'Put back again'] as $how => $word) {
        scenario("the whole repeating event deleted, stopped; a Put back that brings the series back and then fails; then $word", function () use ($db, $build, $reviewLines, $la, $how, $word, $asItWas): void {
            $t = $build();
            try {
                $name = "the series deleted, then a Put back that fails after bringing it back, then $word";
                $t->s->fake->failAfter = count($t->s->fake->requests) + 2; // the read, the delete, then nothing gets through
                $stopped = null;
                try {
                    $t->e->deleteEvent($t->u, $t->id, 'all', null);
                    $t->e->throwDeferred();
                } catch (\Throwable $e) {
                    $stopped = $e->getMessage();
                }
                $t->s->fake->failAfter = null;
                checkEq("$name: the delete stops once the series is deleted at Google, and is listed half done", [true, 1, 'cancelled'],
                    [$stopped !== null, count(H::halfDone($t)), $t->s->fake->events[$t->gid]['status'] ?? null]);
                $t->s->knobs->writes = 0;
                $t->s->knobs->netAt = 2;
                [, $failed] = H::recover($t, 'putback');
                $t->s->knobs->netAt = null;
                $t->s->knobs->down = false;
                check("$name: Put back brings the series back at Google and then fails, the change still half done",
                    $failed !== null && count(H::halfDone($t)) === 1 && ($t->s->fake->events[$t->gid]['status'] ?? null) === 'confirmed', (string) $failed . ' ' . ($t->s->fake->events[$t->gid]['status'] ?? ''));
                [, $error] = H::recover($t, $how);
                checkEq("$name: it completes, nothing is left half done, and Review has nothing to say", [null, [], []], [$error, H::halfDone($t), $reviewLines($t)]);
                $live = array_keys(array_filter($t->s->fake->events, static fn(array $e): bool => ($e['status'] ?? '') !== 'cancelled' && (($e['id'] ?? '') === $t->gid || ($e['recurringEventId'] ?? '') === $t->gid)));
                if ($how === 'finish') {
                    checkEq("$name: the event is gone here, and nothing of it is left live at Google", [[], []], [H::hereView($t, $la, '2027-01-01', '2027-02-15'), $live]);
                    checkEq("$name: a poll changes no row", [], H::pollChanges($t));
                } else {
                    checkEq("$name: the calendar is as it was before the delete, by date, here and at Google", $asItWas, H::settled($t, $name, $la, '2027-01-01', '2027-02-15'));
                }
            } finally {
                $t->s->fake->failAfter = null;
                H::done($t);
            }
        });
    }

    // The mirror, with no read at all after Put back's write to the series: the network goes right after that write is
    // taken. A step's expectation is what the run last read after it (decisions.md), and here nothing was read; nor was
    // anything journaled with the write, since the series has no row here to expect from. So the next Put back has only
    // the fall-back, "is Google as it was before the change", and Google is not (the series is back, its days not yet
    // put right): it is refused, and says that it has no record of the series after its last write, never that it
    // changed in Google Calendar since. Finish completes the delete, after that refusal and without it.
    $unread = "Better-Cal has no record of 'Sample series' as Google Calendar held it after this change's last write, so it can't tell whether it changed there since.";
    foreach (['putback' => 'Put back again, refused for want of a read, then Finish', 'finish' => 'Finish'] as $how => $word) {
        scenario("the whole repeating event deleted, stopped; a Put back whose series write is taken and then nothing is read; then $word", function () use ($build, $reviewLines, $la, $how, $word, $unread): void {
            $t = $build();
            try {
                $name = "the series deleted, then a Put back with the network gone right after its series write, then $word";
                $t->s->fake->failAfter = count($t->s->fake->requests) + 2;
                try {
                    $t->e->deleteEvent($t->u, $t->id, 'all', null);
                    $t->e->throwDeferred();
                } catch (\Throwable) {
                }
                $t->s->fake->failAfter = null;
                $fake = $t->s->fake;
                $t->s->knobs->race = static function () use ($fake): void {
                    $fake->failAfter = count($fake->requests); // right after Put back's first write is taken
                };
                [, $failed] = H::recover($t, 'putback');
                $t->s->knobs->race = null;
                $fake->failAfter = null;
                $steps = json_decode((string) $t->db->journal()->scalar('SELECT steps_json FROM google_pending ORDER BY id DESC LIMIT 1'), true) ?: [];
                $last = $steps[count($steps) - 1] ?? [];
                checkEq("$name: Put back fails with the series back at Google, its series write journaled with no expectation and none read",
                    [true, 1, 'confirmed', 'master', true, false], [$failed !== null, count(H::halfDone($t)), $fake->events[$t->gid]['status'] ?? null, $last['kind'] ?? null, !empty($last['back']), isset($last['expect'])]);
                if ($how === 'putback') {
                    [, $refused] = H::recover($t, 'putback');
                    check("$name: Put back is refused, saying it has no record of the series after its last write, and not that it changed at Google since",
                        $refused !== null && str_contains($refused, $unread) && !str_contains($refused, 'changed in Google Calendar since') && count(H::halfDone($t)) === 1, (string) $refused);
                }
                [, $error] = H::recover($t, 'finish');
                $live = array_keys(array_filter($fake->events, static fn(array $e): bool => ($e['status'] ?? '') !== 'cancelled' && (($e['id'] ?? '') === $t->gid || ($e['recurringEventId'] ?? '') === $t->gid)));
                checkEq("$name: Finish completes the delete: nothing half done, the event gone here, nothing of it live at Google, Review silent",
                    [null, [], [], [], []], [$error, H::halfDone($t), H::hereView($t, $la, '2027-01-01', '2027-02-15'), $live, $reviewLines($t)]);
                checkEq("$name: a poll changes no row", [], H::pollChanges($t));
            } finally {
                $t->s->knobs->race = null;
                $t->s->fake->failAfter = null;
                H::done($t);
            }
        });
    }

    // A pending row whose steps carry no expectation, as a version before 1.5.6 could leave one (nothing in the row says
    // which version wrote it): the series renamed for all events, stopped after the series' write, and the expectations
    // then taken out of its journal. Put back has only the fall-back and is refused as an older version's row always
    // was, in the words that are true of it; Finish completes the change, and, since it can't tell what the change left
    // from a change made since, says what it replaced.
    scenario('a stopped change whose steps carry no expectation (an older version\'s row): Put back refused for want of a read, Finish completes and says what it replaced', function () use ($db, $build, $reviewLines, $la, $days, $unread): void {
        $t = $build();
        try {
            $t->s->knobs->writes = 0;
            $t->s->knobs->beforeAt = 2;
            H::tryChange($t, ['scope' => 'all', 'title' => 'Renamed series']);
            $t->s->knobs->beforeAt = null;
            $id = (int) $db->journal()->scalar('SELECT id FROM google_pending ORDER BY id DESC LIMIT 1');
            $steps = json_decode((string) $db->journal()->scalar('SELECT steps_json FROM google_pending WHERE id = ?', [$id]), true) ?: [];
            checkEq('as this version leaves it, the series step carries the expectation its read back gave it', [['master'], [true]], [array_column($steps, 'kind'), array_map(static fn(array $s): bool => isset($s['expect']), $steps)]);
            $db->journal()->update('google_pending', ['steps_json' => json_encode(array_map(static fn(array $s): array => array_diff_key($s, ['expect' => 1, 'extras' => 1]), $steps))], 'id = ?', [$id]);
            [, $refused] = H::recover($t, 'putback');
            check('Put back is refused, saying it has no record of the series after its last write, and the change stays in Review',
                $refused !== null && str_contains($refused, $unread) && count(H::halfDone($t)) === 1, (string) $refused);
            [, $error] = H::recover($t, 'finish');
            checkEq('Finish completes, nothing half done', [null, []], [$error, H::halfDone($t)]);
            checkEq('the calendar is as the change asked, the renamed day keeping its own title, here and at Google', $days('10:00', 'Renamed series'), H::settled($t, 'an older row, then Finish', $la, '2027-01-01', '2027-02-15'));
            $year = gmdate('Y') === '2027' ? '' : ' 2027';
            checkEq('and Review says what Finish replaced, with the values and no cause', [[
                'This change had stopped part way, and Google Calendar no longer held the event as it was left.',
                "On Mon 11 Jan$year Google Calendar held the title 'Renamed series'; Finish set the title 'Own title', as this change has it.",
            ]], $reviewLines($t));
        } finally {
            H::done($t);
        }
    });
};
