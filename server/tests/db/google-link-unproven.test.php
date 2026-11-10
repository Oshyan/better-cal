<?php

declare(strict_types=1);

// A link given to a series, and what the app believes Google's records hold of it (finding F1 of the hunt of
// 20f77fe9; decisions.md, "What a master write leaves at Google is predicted kept only by identity and proof").
// The expectations are the hunter's, unchanged.
//
// A link given to all events of a series that has an edited day, stopped after Google took the master write
// (Google unreachable for the next step). The contract (google-calendar.md, What Writes Send): such a change "is
// listed in Review as Half done at Google, with Finish (the rest of it, then its part here) and Put back"; Put back
// may be refused after a master write of a link ("predicted dropped until a live probe shows Google keeps them",
// series-model.md and decisions.md), and then "Finish is the way to settle it". So from the contract: Finish
// completes, after which every day shows the link, the edited day keeps its own notes on its own date, and the
// series takes other changes again.
//
// Each expectation below is written from that contract by date; nothing in server/src computes one. Google is the
// edit harness's stand-in with its default settings. Whole-series undo is off (the 1.5.5 default). Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $ny = 'America/New_York';
    $link = 'https://example.test/sample';
    $build = static function () use ($db, $ny): object {
        $t = H::make($db, ['start' => '2027-01-04T19:00:00-05:00', 'end' => '2027-01-04T20:00:00-05:00', 'tzid' => $ny, 'rrule' => 'FREQ=DAILY;COUNT=5']);
        H::this($t, '2027-01-06T19:00:00-05:00', ['description' => 'Notes A']);
        return $t;
    };
    $change = ['scope' => 'all', 'instanceStart' => '2027-01-04T19:00:00-05:00', 'url' => $link];
    $linkOf = static fn(object $t, string $id): ?string => $t->s->fake->events[$id]['source']['url'] ?? null;

    // The control: the change uninterrupted. The series and the edited day's record both carry the link at Google.
    scenario('F1 control: a link for all events, uninterrupted', function () use ($build, $change, $linkOf, $ny, $link): void {
        $t = $build();
        try {
            [, $error] = H::tryChange($t, $change);
            checkEq('F1 control: the change goes through', null, $error);
            checkEq('F1 control: the series has the link at Google', $link, $linkOf($t, $t->gid));
            checkEq('F1 control: the edited day (Wed 6 Jan) has the link at Google', $link, $linkOf($t, $t->gid . '_20270107T000000Z'));
            H::settled($t, 'F1 control', $ny, '2027-01-01', '2027-01-15');
        } finally {
            H::done($t);
        }
    });

    // The sibling outside a stop (the sync's side of the same state): the series' link set at Google by another client,
    // read by a poll. The contract: after a poll the rows are what Google holds, so a change made here goes through.
    scenario('F1 sibling: the series given a link at Google, a poll, then a day renamed here', function () use ($build, $link, $ny): void {
        $name = 'F1 sibling';
        $t = $build();
        try {
            $t->s->poll();
            [$status] = H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid), ['source' => ['url' => $link, 'title' => 'example.test']]);
            checkEq("$name: the stand-in takes the link", 200, $status);
            checkEq("$name: the first poll after it goes through", [], array_values(array_filter(H::pollChanges($t), static fn(string $d): bool => false)));
            checkEq("$name: a second poll changes no row", [], H::pollChanges($t));
            foreach (['2027-01-05T19:00:00-05:00' => 'an ordinary day', '2027-01-06T19:00:00-05:00' => 'the edited day'] as $at => $which) {
                $error = null;
                try {
                    H::this($t, $at, ['title' => 'Renamed day']);
                } catch (\Throwable $e) {
                    $error = ($e instanceof \BetterCal\Http\HttpError ? $e->errorCode . ': ' : '') . $e->getMessage();
                }
                check("$name: $which can be renamed here after the poll", $error === null, (string) $error);
            }
        } finally {
            H::done($t);
        }
    });

    foreach (['refused' => 'beforeAt', 'the network down' => 'netAt'] as $how => $knob) {
        scenario("F1: a link for all events, its second write $how, then Finish", function () use ($db, $build, $change, $linkOf, $ny, $link, $how, $knob): void {
            $name = "F1 (second write $how)";
            $t = $build();
            try {
                $t->s->knobs->writes = 0;
                $t->s->knobs->$knob = 2; // the master write is taken; the edited day's write after it does not get through
                [, $stopped] = H::tryChange($t, $change);
                $t->s->knobs->$knob = null;
                $t->s->knobs->down = false;
                check("$name: the change stops half done", $stopped !== null && count(H::halfDone($t)) === 1, (string) $stopped);
                checkEq("$name: Google took the master write (the series has the link there)", $link, $linkOf($t, $t->gid));

                [, $finish1] = H::recover($t, 'finish');
                check("$name: Finish completes the change", $finish1 === null, (string) $finish1);

                [, $putback] = H::recover($t, 'putback');
                if (H::halfDone($t) !== []) {
                    // The contract's way out when Put back is refused.
                    [, $finish2] = H::recover($t, 'finish');
                    check("$name: Finish completes after Put back was refused", $finish2 === null, (string) $finish2);
                    // A poll changes nothing about it, and Finish is still refused after one.
                    $t->s->poll();
                    [, $finish3] = H::recover($t, 'finish');
                    check("$name: Finish completes after a poll", $finish3 === null, (string) $finish3);
                }
                check("$name: Review lists nothing half done once Finish or Put back has run", H::halfDone($t) === [], json_encode(array_map(static fn(array $h): array => array_intersect_key($h, array_flip(['id', 'title', 'error'])), H::halfDone($t))));
                // The series takes other changes again: a rename of one day, and deleting the series.
                $rename = null;
                try {
                    H::this($t, '2027-01-05T19:00:00-05:00', ['title' => 'Renamed day']);
                } catch (\Throwable $e) {
                    $rename = ($e instanceof \BetterCal\Http\HttpError ? $e->errorCode . ': ' : '') . $e->getMessage();
                }
                check("$name: a day of the series can be renamed afterwards", $rename === null, (string) $rename);
                $delete = null;
                try {
                    $t->e->deleteEvent($t->u, $t->id, 'all', null);
                    $t->e->throwDeferred();
                } catch (\Throwable $e) {
                    $delete = ($e instanceof \BetterCal\Http\HttpError ? $e->errorCode . ': ' : '') . $e->getMessage();
                }
                check("$name: the series can be deleted afterwards", $delete === null, (string) $delete);
            } finally {
                H::done($t);
            }
        });
    }
};
