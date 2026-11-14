<?php

declare(strict_types=1);

// From the independent read of the access build, d32b65b1..61be6c69). The rule, the owner's: "an extra request that buys
// certainty or prevents a loss is welcome; a request that buys nothing is not, and ordinary edits must not get slower",
// and the model's table (docs/design/google-access-detect-and-inform.md, section 2): every kind of write on a calendar
// the account may edit sends 0 extra requests. The expected sequences below were measured on the fix branch (d32b65b1)
// with this same file (PROBE_ACCESS_DUMP=<path> writes what a run sent), with one change the model states: the first
// read of an event in no series is a list by its iCalUID where it was a GET by id ('GET id' became 'GET list' in that
// one position). Nothing here is computed from server/src. Invented data.

use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $single = ['start' => '2027-03-02T09:00:00-08:00', 'end' => '2027-03-02T10:00:00-08:00', 'tzid' => $la];
    $weekly = ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
    $kind = static function (array $r): string {
        $path = (string) $r['path'];
        return $r['method'] . ' ' . (str_ends_with($path, '/events') ? 'list' : (str_ends_with($path, '/instances') ? 'instances' : 'id'));
    };
    $got = [];
    $count = static function (string $name, object $t, callable $do) use (&$got, $kind): void {
        $from = count($t->s->fake->requests);
        try {
            $do();
        } catch (\Throwable $e) {
            $got[$name] = ['threw ' . get_class($e) . ': ' . $e->getMessage()];
            return;
        }
        $got[$name] = array_map($kind, array_slice($t->s->fake->requests, $from));
    };
    $last = static fn(object $t): int => (int) $db->scalar('SELECT id FROM mutations WHERE user_id = ? AND undone = 0 ORDER BY id DESC LIMIT 1', [$t->u]);
    $undo = static fn(object $t, int $entry) => (new Undo($db, null, $t->s->writer))->undoById($t->u, $entry);

    scenario('access read, request counts: every kind of write on a calendar the account may edit, as the fix branch sent it', function () use ($db, $single, $weekly, $count, $last, $undo, &$got, $la): void {
        // An event in no series.
        $t = H::make($db, $single);
        try {
            $count('single: create', $t, fn() => $t->e->create($t->u, ['calendarId' => $t->s->calendarId, 'title' => 'Sample other'] + ['start' => '2027-03-04T09:00:00-08:00', 'end' => '2027-03-04T10:00:00-08:00', 'tzid' => $la]));
            $created = $last($t);
            $count('single: undo of the create', $t, fn() => $undo($t, $created));
            $count('single: edit the title', $t, fn() => $t->e->patch($t->u, $t->id, ['title' => 'Sample single, renamed']));
            $edited = $last($t);
            $count('single: drag to another time', $t, fn() => $t->e->patch($t->u, $t->id, ['start' => '2027-03-02T11:00:00-08:00', 'end' => '2027-03-02T12:00:00-08:00']));
            $count('single: a tag alone (held here)', $t, fn() => $t->e->patch($t->u, $t->id, ['tagNames' => ['sample-tag']]));
            $count('single: undo of the title edit', $t, fn() => $undo($t, $edited));
            $count('single: delete', $t, fn() => $t->e->deleteEvent($t->u, $t->id, null, null));
            $deleted = $last($t);
            $count('single: undo of the delete', $t, fn() => $undo($t, $deleted));
            $count('poll: a delta after changes here', $t, fn() => $t->s->poll());
            $count('poll: a delta with nothing new', $t, fn() => $t->s->poll());
        } finally {
            H::done($t);
        }
        // A repeating event: its days.
        $t = H::make($db, $weekly);
        try {
            $count('series: create', $t, fn() => $t->e->create($t->u, ['calendarId' => $t->s->calendarId, 'title' => 'Sample other series'] + ['start' => '2027-03-03T10:00:00-08:00', 'end' => '2027-03-03T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=3']));
            $count('day: edit a day with no record', $t, fn() => H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']));
            $dayEdit = $last($t);
            $count('day: edit the same day again', $t, fn() => H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own, again)']));
            $dayRow = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$t->id]);
            $count('day: use the series value for its title', $t, fn() => $t->e->patch($t->u, $dayRow, ['useSeries' => ['title']]));
            $count('day: delete a day with no record', $t, fn() => H::skip($t, '2027-03-15T10:00:00-07:00'));
            $dayDelete = $last($t);
            $count('day: undo of the day delete', $t, fn() => $undo($t, $dayDelete));
            $count('day: drag a day with no record', $t, fn() => H::this($t, '2027-03-22T10:00:00-07:00', ['start' => '2027-03-22T12:00:00-07:00', 'end' => '2027-03-22T13:00:00-07:00']));
            $dayDrag = $last($t);
            $count('day: undo of the day drag', $t, fn() => $undo($t, $dayDrag));
        } finally {
            H::done($t);
        }
        // A repeating event: changes to all events, and from a day on.
        $t = H::make($db, $weekly);
        try {
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            $count('all: the title', $t, fn() => H::change($t, ['scope' => 'all', 'title' => 'Sample series, renamed']));
            $count('all: an hour later', $t, fn() => H::change($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']));
            $count('following: the title from the fourth day', $t, fn() => H::change($t, ['scope' => 'following', 'instanceStart' => '2027-03-22T11:00:00-07:00', 'title' => 'Sample series, later part']));
            $count('following: delete from the third day', $t, function () use ($t): void {
                plannedDelete($t->e, $t->u, $t->id, '2027-03-15T11:00:00-07:00');
                $t->e->throwDeferred();
            });
            $count('all: delete', $t, function () use ($t): void {
                $t->e->deleteEvent($t->u, $t->id, 'all', null);
                $t->e->throwDeferred();
            });
        } finally {
            H::done($t);
        }
        // A change to all events that stops half done, then Finish; and the same, then Put back.
        foreach (['finish', 'putBack'] as $how) {
            $t = H::make($db, $weekly);
            try {
                H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
                $t->s->knobs->netAt = $t->s->knobs->writes + 2;
                H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
                $t->s->knobs->down = false;
                $count("half done: $how", $t, function () use ($t, $how): void {
                    [, $error] = H::recover($t, $how);
                    if ($error !== null) {
                        throw new \RuntimeException($error);
                    }
                });
            } finally {
                H::done($t);
            }
        }

        if (getenv('PROBE_ACCESS_DUMP') !== false) {
            file_put_contents((string) getenv('PROBE_ACCESS_DUMP'), json_encode($got, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $expected = require __DIR__ . '/access-read-request-counts.expected.php';
        foreach ($expected as $name => $sent) {
            checkEq("request count, $name: what the fix branch sent (the single event's first read a list, as the model says)", $sent, $got[$name] ?? null);
        }
        checkEq('request count: every kind measured has an expectation', array_keys($expected), array_keys($got));
    });
};
