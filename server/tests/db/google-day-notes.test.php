<?php

declare(strict_types=1);

// A day's own notes through a change to all events on a series with no notes (decisions.md, "A day's own notes
// cleared by a master write are sent back"; probe 2026-11-13, 3.52 to 3.61; replay round 4, cause 2). Google clears
// every kept day's own notes when a master write leaves the notes out and the series has none; the stand-in does the
// same. The day keeps its notes, at Google and here, in one go and through Finish. Expectations by date. Invented data.

use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $weekly = ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
    $notes = 'Own notes for the day';
    $changes = [
        'a link set for all events' => ['scope' => 'all', 'url' => 'https://example.test/sample'],
        'renamed for all events' => ['scope' => 'all', 'title' => 'Sample series, renamed'],
        'an hour longer for all events' => ['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00'],
        'two more events' => ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;COUNT=8'],
    ];
    $atGoogle = static fn(object $t): ?string => $t->s->fake->records($t->gid)['20270111T180000Z']['description'] ?? null;
    $here = static fn(object $t): ?string => $db->scalar("SELECT description FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-01-11 18:00:00' AND deleted_at IS NULL", [$t->id]);

    foreach ($changes as $what => $body) {
        scenario("day notes: $what on a series with no notes, in one go: the day keeps its own notes at Google and here", function () use ($db, $weekly, $notes, $body, $what, $atGoogle, $here): void {
            $t = H::make($db, $weekly);
            try {
                H::this($t, '2027-01-11T10:00:00-08:00', ['description' => $notes]);
                [, $error] = H::tryChange($t, $body);
                checkEq("$what: the change goes through, the day's notes kept at Google and here, nothing half done", [null, $notes, $notes, []], [$error, $atGoogle($t), $here($t), H::halfDone($t)]);
            } finally {
                H::done($t);
            }
        });
    }

    scenario('day notes: a link set for all events, stopped after its master write, finished: the day keeps its own notes', function () use ($db, $weekly, $notes, $atGoogle, $here): void {
        $t = H::make($db, $weekly);
        try {
            H::this($t, '2027-01-11T10:00:00-08:00', ['description' => $notes]);
            $t->s->knobs->beforeAt = $t->s->knobs->writes + 2; // the master PATCH is taken, the next write refused
            [, $error] = H::tryChange($t, ['scope' => 'all', 'url' => 'https://example.test/sample']);
            $t->s->knobs->beforeAt = null;
            checkEq('stopped after the master write, Google has cleared the notes', [true, null], [$error !== null, $atGoogle($t)]);
            [, $finishError] = H::recover($t, 'finish');
            checkEq('Finish completes, the notes back at Google and here', [null, $notes, $notes, []], [$finishError, $atGoogle($t), $here($t), H::halfDone($t)]);
        } finally {
            H::done($t);
        }
    });
};
