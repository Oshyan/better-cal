<?php

declare(strict_types=1);

// Edited days of a series moved onto each other's old days. The unique key
// on (calendar, uid, occurrence) holds in MariaDB, so a whole-series drag by
// the distance between two edited days, and the undo of it, must re-key the
// edited rows without one landing on a key another still holds. Each case
// runs with the edited days made in both orders, since rows are re-keyed in
// the order they're read.

use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $home = (int) Settings::defaultCalendarId($db, $u);

    // Every row of a series (and of one split off it), as stored, without
    // the timestamp a write touches.
    $rows = static function (array $ids) use ($db): array {
        [$in, $params] = Db::in($ids);
        $out = [];
        foreach ($db->all("SELECT * FROM events WHERE id IN $in OR recurrence_parent_id IN $in ORDER BY id", array_merge($params, $params)) as $row) {
            unset($row['updated_at']);
            $out[] = $row;
        }
        return $out;
    };
    // The edited days, title => occurrence key.
    $keys = static function (int $masterId) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT title, recurrence_instance_utc FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$masterId]) as $row) {
            $out[(string) $row['title']] = (string) $row['recurrence_instance_utc'];
        }
        return $out;
    };
    // A weekly Monday series from 4 Jan 2027, with the given days (titles
    // by day) edited in the order given.
    $series = static function (array $edited) use ($d, $u, $home): int {
        $id = (int) $d->events->create($u, eventAt($home, 'Weekly sample', '2027-01-04', ['rrule' => 'FREQ=WEEKLY;BYDAY=MO']))['eventId'];
        foreach ($edited as $day => $title) {
            $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => $day . 'T18:00:00+00:00', 'title' => $title]);
        }
        return $id;
    };
    $drag = static function (int $id, string $scope, string $from, string $to) use ($d, $u): ?string {
        try {
            planned($d->events, $u, $id, ['scope' => $scope, 'instanceStart' => $from . 'T18:00:00+00:00', 'start' => $to . 'T18:00:00+00:00', 'end' => $to . 'T19:00:00+00:00']);
            return null;
        } catch (\Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }
    };

    $cases = [
        'a week later, two edited days a week apart' => [['2027-01-11' => 'Day A', '2027-01-18' => 'Day B'], '2027-01-25', '2027-02-01',
            ['Day A' => '2027-01-18 18:00:00', 'Day B' => '2027-01-25 18:00:00']],
        'a week earlier, two edited days a week apart' => [['2027-01-11' => 'Day A', '2027-01-18' => 'Day B'], '2027-01-25', '2027-01-18',
            ['Day A' => '2027-01-04 18:00:00', 'Day B' => '2027-01-11 18:00:00']],
        'a week later, three edited days in a row' => [['2027-01-11' => 'Day A', '2027-01-18' => 'Day B', '2027-01-25' => 'Day C'], '2027-02-01', '2027-02-08',
            ['Day A' => '2027-01-18 18:00:00', 'Day B' => '2027-01-25 18:00:00', 'Day C' => '2027-02-01 18:00:00']],
        'a week earlier, three edited days in a row' => [['2027-01-11' => 'Day A', '2027-01-18' => 'Day B', '2027-01-25' => 'Day C'], '2027-02-01', '2027-01-25',
            ['Day A' => '2027-01-04 18:00:00', 'Day B' => '2027-01-11 18:00:00', 'Day C' => '2027-01-18 18:00:00']],
    ];
    foreach ($cases as $name => [$edited, $from, $to, $expected]) {
        foreach (['earliest edited first' => $edited, 'latest edited first' => array_reverse($edited, true)] as $order => $made) {
            scenario("series keys: whole series dragged $name ($order)", function () use ($db, $u, $series, $rows, $keys, $drag, $name, $order, $made, $from, $to, $expected): void {
                $id = $series($made);
                $before = $rows([$id]);
                $error = $drag($id, 'all', $from, $to);
                checkEq("series keys: dragged $name ($order): the drag goes through", null, $error);
                checkEq("series keys: dragged $name ($order): each edited day on its moved day", $expected, $keys($id));
                $d = domains($db);
                $undoError = null;
                try {
                    $d->undo->undoById($u, lastEntry($db, $u));
                } catch (\Throwable $e) {
                    $undoError = get_class($e) . ': ' . $e->getMessage();
                }
                checkEq("series keys: dragged $name ($order), undone: the undo goes through", null, $undoError);
                checkEq("series keys: dragged $name ($order), undone: every row as before", $before, $rows([$id]));
            });
        }
    }

    // This and following, from an edited day, by the distance between it
    // and the next: the edited rows go to the new series under a new uid,
    // so their new keys can't meet a key still held; the undo puts them
    // back under the old one.
    foreach (['earliest edited first' => ['2027-01-11' => 'Day A', '2027-01-18' => 'Day B'], 'latest edited first' => ['2027-01-18' => 'Day B', '2027-01-11' => 'Day A']] as $order => $made) {
        scenario("series keys: this and following dragged a week later ($order)", function () use ($db, $u, $series, $rows, $keys, $drag, $order, $made): void {
            $id = $series($made);
            $before = $rows([$id]);
            $error = $drag($id, 'following', '2027-01-11', '2027-01-18');
            checkEq("series keys: following dragged a week later ($order): the drag goes through", null, $error);
            $split = (int) $db->scalar('SELECT MAX(id) FROM events WHERE user_id = ? AND recurrence_parent_id IS NULL', [$u]);
            checkEq("series keys: following dragged a week later ($order): each edited day on its moved day, on the new series", [[], ['Day A' => '2027-01-18 18:00:00', 'Day B' => '2027-01-25 18:00:00']], [$keys($id), $keys($split)]);
            $d = domains($db);
            $undoError = null;
            try {
                $d->undo->undoById($u, lastEntry($db, $u));
            } catch (\Throwable $e) {
                $undoError = get_class($e) . ': ' . $e->getMessage();
            }
            checkEq("series keys: following dragged a week later ($order), undone: the undo goes through", null, $undoError);
            checkEq("series keys: following dragged a week later ($order), undone: every row as before, the new series gone", [$before, 0],
                [$rows([$id]), (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id = ? OR recurrence_parent_id = ?', [$split, $split])]);
        });
    }
};
