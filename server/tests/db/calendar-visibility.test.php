<?php

declare(strict_types=1);

// Many calendars shown or hidden in one request (#190): Only on a calendar,
// a folder's All or None. One entry in Activity, whose undo puts every flag
// back; what the entry says; and what the batch refuses. Each block is a
// scenario(): a throw fails that block, and the next still runs.

use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $home = (int) Settings::defaultCalendarId($db, $u);
    $d->calendars->patch($u, $home, ['name' => 'Home']);
    $names = ['Work', 'Errands', 'Club', 'Garden', 'Reading'];
    $ids = array_map(static fn(string $name): int => (int) $d->calendars->create($u, ['name' => $name])['id'], $names);
    $work = (int) $d->folders->create($u, ['name' => 'Work things'])['id'];
    $d->folders->place($u, $work, $ids[0], 0);
    $d->folders->place($u, $work, $ids[1], 1);
    $visible = static fn() => array_map('intval', array_column($db->all('SELECT id FROM calendars WHERE user_id = ? AND visible = 1 ORDER BY id', [$u]), 'id'));
    $entries = static fn() => (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u]);
    $summary = static fn() => (string) $db->scalar('SELECT summary FROM mutations WHERE id = ?', [lastEntry($db, $u)]);
    $batch = static fn(array $want, ?int $folderId = null) => $d->calendars->setVisibility($u, ['calendars' => array_map(
        static fn(int $id, bool $v): array => ['id' => $id, 'visible' => $v],
        array_keys($want),
        array_values($want)
    )] + ($folderId !== null ? ['folderId' => $folderId] : []));

    scenario('visibility: five calendars hidden in one request, one entry, undone as one', function () use ($db, $u, $d, $home, $ids, $visible, $entries, $summary, $batch): void {
        $before = $entries();
        $answer = $batch(array_fill_keys($ids, false));
        checkEq('visibility: the batch answers its calendars, in the order sent, as they are now',
            [$ids, array_fill(0, 5, false)], [array_column($answer, 'id'), array_column($answer, 'visible')]);
        checkEq('visibility: five hidden in one request leave one entry, and only the sixth shows', [1, [$home]], [$entries() - $before, $visible()]);
        checkEq('visibility: the entry says what was done', "Hid every calendar but 'Home'", $summary());
        $entry = lastEntry($db, $u);
        $d->undo->undoById($u, $entry);
        checkEq('visibility: undone, all five show again, the one entry undone and no other written',
            [array_merge([$home], $ids), 1, 1],
            [$visible(), (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [$entry]), $entries() - $before]);
    });

    scenario('visibility: what the entry says', function () use ($u, $d, $home, $ids, $work, $visible, $entries, $summary, $batch): void {
        $batch([$ids[0] => false, $ids[1] => false], $work);
        checkEq("visibility: a folder's None names the folder", "Hid 2 calendars in folder 'Work things'", $summary());
        $batch([$ids[0] => true, $ids[1] => true], $work);
        checkEq("visibility: a folder's All names the folder", "Showed 2 calendars in folder 'Work things'", $summary());
        $batch([$ids[2] => false]);
        checkEq('visibility: one calendar is named', "Hid calendar 'Club'", $summary());
        $batch([$ids[2] => true, $ids[3] => false, $ids[4] => false]);
        checkEq('visibility: shown and hidden together', "Showed calendar 'Club' and hid 2 calendars", $summary());
        // Only on Club, which already shows: the rest go.
        $batch([$ids[2] => true] + array_fill_keys(array_merge([$home], $ids), false));
        checkEq("visibility: Only hides every calendar but the one", ["Hid every calendar but 'Club'", [$ids[2]]], [$summary(), $visible()]);
        $before = $entries();
        $batch(array_fill_keys(array_merge([$home], $ids), true));
        checkEq('visibility: showing every calendar counts the ones that were off', ['Showed 5 calendars', 1], [$summary(), $entries() - $before]);
        $batch(array_fill_keys(array_merge([$home], $ids), false));
        checkEq('visibility: hiding every calendar says so', 'Hid every calendar', $summary());
        $batch(array_fill_keys(array_merge([$home], $ids), true));
    });

    scenario('visibility: a batch that changes nothing, or asks for what is not yours, writes nothing', function () use ($db, $u, $d, $ids, $visible, $entries, $batch): void {
        $before = $entries();
        $batch(array_fill_keys($ids, true));
        checkEq('visibility: already as asked, nothing is written', 0, $entries() - $before);
        $other = freshUser($db);
        $theirs = (int) domains($db)->calendars->create($other, ['name' => 'Not mine'])['id'];
        $theirFolder = (int) domains($db)->folders->create($other, ['name' => 'Theirs'])['id'];
        checkEq("visibility: another account's calendar in the batch is not found, and none of the batch is written",
            ['not_found', 0, true],
            [refusal(fn() => $batch([$ids[0] => false, $theirs => false])), $entries() - $before, in_array($ids[0], $visible(), true)]);
        checkEq("visibility: another account's folder is not found", ['not_found', 0],
            [refusal(fn() => $batch([$ids[0] => false], $theirFolder)), $entries() - $before]);
        checkEq('visibility: a batch needs a list of {id, visible}', ['invalid_request', 'invalid_request', 'invalid_request'], [
            refusal(fn() => $d->calendars->setVisibility($u, ['calendars' => []])),
            refusal(fn() => $d->calendars->setVisibility($u, ['calendars' => [['id' => $ids[0]]]])),
            refusal(fn() => $d->calendars->setVisibility($u, ['calendars' => [['id' => 'x', 'visible' => true]]])),
        ]);
    });
};
