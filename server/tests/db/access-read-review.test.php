<?php

declare(strict_types=1);

// From the independent read of the access build, d32b65b1..61be6c69): what the person is told, in Review and in Activity,
// as the level moves, and what a dismiss does. Each expectation is a sentence of the model
// (docs/design/google-access-detect-and-inform.md): section 6, "Activity has one line per move between tiers, and
// exactly one Review item is open per calendar below writer", and its damages "a missing or repeated Activity line or
// Review item" and "a Review item left open after access returns"; section 3, "A dismissed item does not come back while
// the level stays in its tier ... If the level moves again ... a new item opens. A dismissed item is closed by nothing; an
// open one is closed (as gone) when the level returns to writer, when the calendar is kept as a calendar of its own, or
// when it is removed", and the sentences themselves. The owner: "a dismiss is an acknowledgement, not a fix".
// Nothing is computed from server/src. Invented data.

use BetterCal\Domain\ReviewQueue;
use BetterCal\Infra\Db;

require_once __DIR__ . '/access-read-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $single = ['title' => 'Sample single', 'start' => '2027-03-02T09:00:00-08:00', 'end' => '2027-03-02T10:00:00-08:00', 'tzid' => $la];
    $make = static function (string $role = 'owner') use ($db, $single): object {
        $s = PA::make($db);
        $s->id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId] + $single)['eventId'];
        ($s->poll)();
        if ($role !== 'owner') {
            $s->fake->role = $role;
            ($s->poll)();
        }
        return $s;
    };
    $role = static fn(object $s): ?string => PA::storedRole($db, $s->calendarId);
    $items = static fn(object $s, string $status = 'open'): array => PA::items($db, $s->u, $s->calendarId, $status);
    $lines = static fn(object $s): array => PA::lines($db, $s->u, $s->calendarId);
    $edit = static fn(object $s, string $title = 'Sample single, renamed'): ?string => PA::code(PA::caught(fn() => $s->events->patch($s->u, $s->id, ['title' => $title])));
    $name = "'" . PA::NAME . "'";
    $readOnly = "Your permissions for $name at Google are now read-only. If you have access, restore full editing there. Until then $name works read-only here: changes, and undoing past changes, are unavailable.";
    $notShown = "Google no longer shows $name to this account: its owner stopped sharing it, or deleted it. Its events here are kept, read-only, and no longer update. If it is shared with you again, it updates again; otherwise remove it, or keep it as a calendar of your own.";

    scenario('access read, access in Review and Activity: one line per move between tiers and one item per calendar below writer, by polls and by attempts alike, with the model\'s sentences', function () use ($db, $make, $role, $items, $lines, $edit, $readOnly, $notShown): void {
        $s = $make();
        $said = [];
        $moves = 0;
        // Busy times only is a state of its own (the owner, 2026-11-13: events stop updating too; the model, section 3).
        $tierOf = static fn(string $r): string => $r === 'none' ? 'none' : (in_array($r, ['writer', 'owner'], true) ? 'edit' : ($r === 'freeBusyReader' ? 'busy' : 'view'));
        $was = 'owner';
        // The level the stand-in gives, and how it is learned: a poll, or an edit attempted.
        foreach ([['reader', 'poll'], ['reader', 'edit'], ['freeBusyReader', 'edit'], ['freeBusyReader', 'poll'], ['none', 'edit'], ['none', 'poll'], ['reader', 'poll'], ['writer', 'edit'],
            ['writer', 'poll'], ['none', 'poll'], ['owner', 'poll'], ['freeBusyReader', 'edit'], ['reader', 'poll'], ['writer', 'poll']] as $n => [$level, $how]) {
            $s->fake->role = $level;
            $how === 'poll' ? ($s->poll)() : $edit($s, 'Sample single, attempt ' . $n);
            $moves += $tierOf($was) !== $tierOf($level) ? 1 : 0;
            $was = $level;
            $open = $tierOf($level) === 'edit' ? [] : [$tierOf($level) === 'none' ? 'google_not_shown' : 'google_read_only'];
            checkEq("step $n, $level learned by " . ($how === 'poll' ? 'a poll' : 'an attempt') . ": the stored level is the one the stand-in gave; the open items are the tier's one (none while editable); Activity has one line per move so far",
                [$level, $open, $moves], [$role($s), $items($s), count($lines($s))]);
        }
        $all = $lines($s);
        checkEq('the read-only line is the model\'s sentence, word for word', $readOnly, $all[0] ?? null);
        checkEq('the not-shown line is the model\'s sentence, word for word', $notShown, $all[2] ?? null); // [1] is busy times only, a state of its own
        $item = $db->one("SELECT title, summary FROM review_items WHERE user_id = ? AND kind = 'google_read_only' ORDER BY id LIMIT 1", [$s->u]);
        checkEq('the Review item says the same sentence, under the calendar\'s name', [PA::NAME, $readOnly], [$item['title'] ?? null, $item['summary'] ?? null]);
    });

    scenario('access read, access, a dismiss: it changes nothing about the calendar, the item stays dismissed while the tier stays (polls and attempts), and a new one opens only when the tier moves', function () use ($db, $make, $role, $items, $edit): void {
        $s = $make('reader');
        $id = (int) $db->scalar("SELECT id FROM review_items WHERE user_id = ? AND status = 'open' AND kind = 'google_read_only'", [$s->u]);
        $other = freshUser($db);
        $e = PA::caught(fn() => (new ReviewQueue($db, $s->events))->dismissGoogleDifferences($other, $id));
        check('another account can\'t dismiss it', $e !== null && $items($s) === ['google_read_only'], PA::code($e) ?? 'dismissed');
        (new ReviewQueue($db, $s->events))->dismissGoogleDifferences($s->u, $id);
        $cal = $s->d->calendars->serializeById($s->u, $s->calendarId);
        checkEq('dismissed: no item open, the calendar still read-only here (editable false, its level as Google gave it)', [[], ['google_read_only'], false, 'reader'],
            [$items($s), $items($s, 'dismissed'), $cal['editable'] ?? null, $cal['googleAccessRole'] ?? null]);
        // The owner's rule for every notice (decisions.md, "Every Review notice of a state can be dismissed, by one rule"):
        // a refusal because of the state brings a dismissed notice back; a poll at the same state does not; a new state
        // is a new notice; a state that ends closes its notice, dismissed or not.
        ($s->poll)();
        checkEq('dismissed, a poll at the same level: nothing opens again', [[], ['google_read_only']], [$items($s), $items($s, 'dismissed')]);
        checkEq('dismissed: an edit is still refused, after asking Google', 'google_read_only', $edit($s));
        checkEq('the refusal brings the dismissed item back', [['google_read_only'], []], [$items($s), $items($s, 'dismissed')]);
        (new ReviewQueue($db, $s->events))->dismissGoogleDifferences($s->u, $id);
        $s->fake->role = 'freeBusyReader';
        ($s->poll)();
        checkEq('busy times only, a state of its own: a new item opens', [['google_read_only'], []], [$items($s), $items($s, 'dismissed')]);
        $s->fake->role = 'none';
        $edit($s);
        checkEq('not shown: a new item opens', [['google_not_shown'], []], [$items($s), $items($s, 'dismissed')]);
        $nid = (int) $db->scalar("SELECT id FROM review_items WHERE user_id = ? AND status = 'open' AND kind = 'google_not_shown'", [$s->u]);
        (new ReviewQueue($db, $s->events))->dismissGoogleDifferences($s->u, $nid);
        $s->fake->role = 'writer';
        ($s->poll)();
        checkEq('access back: stored writer, nothing open or dismissed (the state ended)', ['writer', [], []],
            [$role($s), $items($s), $items($s, 'dismissed')]);
    });

    scenario('access read, access, an open item is closed when the calendar is kept as a calendar of its own, and when it is removed', function () use ($db, $make, $items): void {
        foreach (['reader', 'none'] as $level) {
            $s = $make($level);
            $open = $items($s);
            $s->d->calendars->adopt($s->u, $s->calendarId);
            checkEq("$level, kept as its own: the item was open, and is closed as gone", [1, [], 1], [count($open), $items($s), count($items($s, 'gone'))]);
            $s = $make($level);
            $s->d->calendars->delete($s->u, $s->calendarId);
            checkEq("$level, removed: no item of it is open", 0, (int) $db->scalar("SELECT COUNT(*) FROM review_items WHERE user_id = ? AND status = 'open' AND kind IN ('google_read_only', 'google_not_shown')", [$s->u]));
        }
    });

    // Section 1: "Nothing is stored across the compromise reset. The account is checked under its lock first." The reset
    // lands while a poll's list is out; the list then answers 404 (sharing removed meanwhile).
    scenario('access read, access, the compromise reset lands during a poll whose list answers 404: the level is not stored and nothing is said', function () use ($db, $make, $role, $items, $lines): void {
        $s = $make();
        $s->fake->role = 'none';
        $s->syncHook = static function (string $url) use ($db, $s): void {
            $db->run('UPDATE google_accounts SET reauth_required_at = ? WHERE id = ?', [\BetterCal\Support\Time::nowDb(), $s->accountId]);
        };
        ($s->poll)();
        $s->syncHook = null;
        checkEq('the stored level, the Review items and Activity\'s lines are as before the poll', ['owner', [], 0], [$role($s), $items($s), count($lines($s))]);
    });

    // The compromise reset stores no level (Auth: google_access_role = NULL, "until a successful poll after reconnect
    // learns their access role again"). What is learned next is a level like any other: section 6's two sentences hold.
    scenario('access read, access learned after the level was unknown (a calendar the compromise reset paused): the person is told as at any other time', function () use ($db, $make, $role, $items, $lines, $edit): void {
        foreach (['a poll', 'an attempt'] as $how) {
            // Editable before the pause, view only after it.
            $s = $make();
            $db->run('UPDATE calendars SET google_access_role = NULL WHERE id = ?', [$s->calendarId]);
            $s->fake->role = 'reader';
            $how === 'a poll' ? ($s->poll)() : $edit($s);
            checkEq("editable, paused, then view only, learned by $how: stored reader, and exactly one Review item is open for the calendar below writer", ['reader', ['google_read_only']], [$role($s), $items($s)]);
            // View only with its item open before the pause, editable after it.
            $s = $make('reader');
            $db->run('UPDATE calendars SET google_access_role = NULL WHERE id = ?', [$s->calendarId]);
            $s->fake->role = 'writer';
            $how === 'a poll' ? ($s->poll)() : $edit($s);
            checkEq("view only, paused, then editable, learned by $how: stored writer, and no Review item is left open saying it is read-only", ['writer', []], [$role($s), $items($s)]);
        }
    });
};
