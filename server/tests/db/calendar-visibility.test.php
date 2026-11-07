<?php

declare(strict_types=1);

// Many calendars shown or hidden in one request (#190): Only on a calendar,
// a folder's All or None. Which calendars show is a display preference, as
// the settings are: the flags change and Activity records nothing, alone or
// in a batch; a patch that also changes something the account holds records
// that, with the flag left out, and its undo leaves the flag alone. And what
// the batch refuses. Each block is a scenario(): a throw fails that block,
// and the next still runs.

use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $home = (int) Settings::defaultCalendarId($db, $u);
    $d->calendars->patch($u, $home, ['name' => 'Home']);
    $names = ['Work', 'Errands', 'Club', 'Garden', 'Reading'];
    $ids = array_map(static fn(string $name): int => (int) $d->calendars->create($u, ['name' => $name])['id'], $names);
    $visible = static fn() => array_map('intval', array_column($db->all('SELECT id FROM calendars WHERE user_id = ? AND visible = 1 ORDER BY id', [$u]), 'id'));
    $entries = static fn() => (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u]);
    $batch = static fn(array $want) => $d->calendars->setVisibility($u, ['calendars' => array_map(
        static fn(int $id, bool $v): array => ['id' => $id, 'visible' => $v],
        array_keys($want),
        array_values($want)
    )]);

    scenario('visibility: five calendars hidden in one request, no entry is written; the flags change', function () use ($home, $ids, $visible, $entries, $batch): void {
        $before = $entries();
        $answer = $batch(array_fill_keys($ids, false));
        checkEq('visibility: the batch answers its calendars, in the order sent, as they are now',
            [$ids, array_fill(0, 5, false)], [array_column($answer, 'id'), array_column($answer, 'visible')]);
        checkEq('visibility: five hidden in one request write no entry, and only the sixth shows', [0, [$home]], [$entries() - $before, $visible()]);
        $batch(array_fill_keys($ids, true));
        checkEq('visibility: shown again, still no entry, and every calendar shows', [0, array_merge([$home], $ids)], [$entries() - $before, $visible()]);
    });

    scenario('visibility: one calendar shown or hidden by its own patch writes no entry', function () use ($u, $d, $ids, $visible, $entries): void {
        $before = $entries();
        $d->calendars->patch($u, $ids[2], ['visible' => false]);
        checkEq('visibility: hidden by a patch, no entry is written; the flag changes', [0, false], [$entries() - $before, in_array($ids[2], $visible(), true)]);
        $d->calendars->patch($u, $ids[2], ['visible' => true]);
        checkEq('visibility: shown by a patch, no entry is written; the flag changes', [0, true], [$entries() - $before, in_array($ids[2], $visible(), true)]);
    });

    scenario('visibility: a patch that also renames records the rename alone, and its undo leaves the flag', function () use ($db, $u, $d, $ids, $visible, $entries): void {
        $before = $entries();
        $d->calendars->patch($u, $ids[3], ['name' => 'Garden plot', 'visible' => false]);
        $entry = lastEntry($db, $u);
        $touched = array_column($db->all('SELECT col FROM mutation_touches WHERE mutation_id = ? ORDER BY col', [$entry]), 'col');
        checkEq('visibility: one entry, touching the name and not the flag', [1, ['name']], [$entries() - $before, $touched]);
        $d->undo->undoById($u, $entry);
        checkEq('visibility: undone, the name is back and the calendar stays hidden',
            ['Garden', false], [(string) $db->scalar('SELECT name FROM calendars WHERE id = ?', [$ids[3]]), in_array($ids[3], $visible(), true)]);
        $d->calendars->patch($u, $ids[3], ['visible' => true]);
    });

    scenario('visibility: a batch that changes nothing, or asks for what is not yours, writes nothing', function () use ($db, $u, $d, $ids, $visible, $entries, $batch): void {
        $before = $entries();
        $batch(array_fill_keys($ids, true));
        checkEq('visibility: already as asked, nothing is written', [0, array_merge([(int) $visible()[0]], $ids)], [$entries() - $before, $visible()]);
        $other = freshUser($db);
        $theirs = (int) domains($db)->calendars->create($other, ['name' => 'Not mine'])['id'];
        checkEq("visibility: another account's calendar in the batch is not found, and none of the batch is written",
            ['not_found', true],
            [refusal(fn() => $batch([$ids[0] => false, $theirs => false])), in_array($ids[0], $visible(), true)]);
        checkEq('visibility: a batch needs a list of {id, visible}', ['invalid_request', 'invalid_request', 'invalid_request'], [
            refusal(fn() => $d->calendars->setVisibility($u, ['calendars' => []])),
            refusal(fn() => $d->calendars->setVisibility($u, ['calendars' => [['id' => $ids[0]]]])),
            refusal(fn() => $d->calendars->setVisibility($u, ['calendars' => [['id' => 'x', 'visible' => true]]])),
        ]);
    });
};
