<?php

declare(strict_types=1);

// Folders and the calendar list (#187): each folder's own order or A to Z,
// a calendar dragged between folders or moved in the list, and the undo of
// each, with what a later change leaves alone.

use BetterCal\Domain\Folders;
use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $one = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
    $d->calendars->patch($u, $one, ['name' => 'One']);
    $two = (int) $d->calendars->create($u, ['name' => 'Two'])['id'];
    $alpha = (int) $d->folders->create($u, ['name' => 'Alpha'])['id'];
    $beta = (int) $d->folders->create($u, ['name' => 'Beta'])['id'];
    $elsewhere = (int) domains($db)->folders->create(freshUser($db), ['name' => 'Someone else'])['id'];
    $d->folders->place($u, $alpha, $one, 0);
    $d->folders->place($u, $alpha, $two, 1);
    $order = static fn() => [Folders::order($db, $alpha), Folders::order($db, $beta)];
    $latest = static fn() => lastEntry($db, $u);
    $calendar = static fn(int $id) => $db->one('SELECT name, color FROM calendars WHERE id = ?', [$id]);
    $list = static fn() => array_column($d->calendars->listAll($u)['calendars'], 'id');
    $foldersOf = static fn(int $cal) => array_map('intval', array_column($db->all('SELECT folder_id FROM calendar_folders WHERE calendar_id = ? ORDER BY folder_id', [$cal]), 'folder_id'));

    $listed = array_column(array_map(static fn($f) => ['k' => $f['id'], 'v' => [$f['calendarIds'], $f['sort']]], $d->calendars->listAll($u)['folders']), 'v', 'k');
    checkEq('folders: listed with their calendars in their own order, manual unless set', [$alpha => [[$one, $two], 'manual'], $beta => [[], 'manual']], $listed);
    $d->folders->place($u, $alpha, $two, 0);
    checkEq('folders: a calendar moves to a new place in its folder', [[$two, $one], []], $order());
    $d->folders->place($u, $beta, $one, 0, $alpha);
    checkEq('folders: dragged to another folder, it leaves the first', [[[$two], [$one]], [$beta]], [$order(), $foldersOf($one)]);
    $d->folders->place($u, $beta, $two, 5);
    checkEq('folders: added to another as well, it stays where it was (a place past the end is the end)', [[$two], [$one, $two]], $order());
    checkEq('folders: the activity entry names the calendar and the folder', ['calendar', $two, "Added 'Two' to folder 'Beta'"],
        array_values(array_map(static fn($v) => is_numeric($v) ? (int) $v : $v, $db->one('SELECT entity, entity_id, summary FROM mutations WHERE id = ?', [$latest()]))));
    $d->undo->undoLatest($u);
    checkEq('folders: undone, it is out of the folder it was added to and still where it was', [[$two], [$one]], $order());

    // Undo puts back only the calendar it moved: a later change to the
    // folder it came from stays.
    $d->folders->place($u, $beta, $two, 0);
    $d->folders->place($u, $alpha, $one, 0, $beta);
    $moved = $latest();
    $d->folders->unfile($u, $beta, $two);
    checkEq('folders: before the undo, One moved to Alpha and Two taken out of Beta', [[$one, $two], []], $order());
    $d->undo->undoById($u, $moved);
    checkEq('folders: undoing the older move puts One back in Beta; Two stays out of it', [[$two], [$one]], $order());
    $d->folders->place($u, $alpha, $one, 0, $beta);
    $first = $latest();
    $d->folders->place($u, $beta, $one, 0, $alpha);
    checkEq('folders: an older move of a calendar moved again since is stale', 'stale_undo', refusal(fn() => $d->undo->undoById($u, $first)));
    // Filed again from its settings since: stale too; renamed since: not.
    $d->folders->place($u, $alpha, $one, 0, $beta);
    $settingsMove = $latest();
    $d->calendars->patch($u, $one, ['folderIds' => [$alpha, $beta]]);
    checkEq('folders: a move filed again from its settings since is stale, and nothing changes', ['stale_undo', [[$one, $two], [$one]]],
        [refusal(fn() => $d->undo->undoById($u, $settingsMove)), $order()]);
    $d->calendars->patch($u, $one, ['folderIds' => [$beta]]);
    $d->folders->place($u, $alpha, $one, 0, $beta);
    $renamedMove = $latest();
    $d->calendars->patch($u, $one, ['name' => 'One, renamed']);
    $d->undo->undoById($u, $renamedMove);
    checkEq('folders: a move renamed since still undoes, keeping the name', [[[$two], [$one]], 'One, renamed'], [$order(), $calendar($one)['name']]);

    $d->folders->place($u, $alpha, $one, 0, $beta);
    $d->folders->place($u, $beta, $two, 0);
    $d->folders->unfile($u, $alpha, $two);
    checkEq('folders: taken out of one folder, it stays in the other', [[$one], [$two]], $order());
    $d->calendars->patch($u, $one, ['folderIds' => [$beta, $alpha]]);
    checkEq("folders: filed from its settings, it keeps its place where it was and goes last where it's new", [[$one], [$two, $one]], $order());
    $d->folders->patch($u, $beta, ['sort' => 'az']);
    checkEq('folders: a folder can list A to Z', 'az', $db->scalar('SELECT sort FROM folders WHERE id = ?', [$beta]));
    checkEq('folders: an unknown sort is refused', 'invalid_request', refusal(fn() => $d->folders->patch($u, $beta, ['sort' => 'random'])));
    checkEq("folders: someone else's folder can't be filed into", 'not_found', refusal(fn() => $d->folders->place($u, $elsewhere, $one, 0)));

    // The calendar list's own order.
    $d->calendars->placeInList($u, $two, 0);
    checkEq('calendar list: a calendar moves to a new place in the list', [$two, $one], $list());
    $reorder = $latest();
    $d->calendars->patch($u, $one, ['name' => 'One, renamed again']);
    $d->undo->undoById($u, $reorder);
    checkEq("calendar list: undoing an older move puts it back and keeps another calendar's later rename", [[$one, $two], 'One, renamed again'], [$list(), $calendar($one)['name']]);

    // Undo puts back what a change changed, and only that.
    $work = (int) $d->folders->create($u, ['name' => 'Work'])['id'];
    $home = (int) $d->folders->create($u, ['name' => 'Home'])['id'];
    $temp = (int) $d->folders->create($u, ['name' => 'Temp'])['id'];
    $d->calendars->patch($u, $two, ['folderIds' => [$work]]);
    $d->calendars->patch($u, $two, ['name' => 'Two, renamed']);
    $rename = $latest();
    $d->folders->place($u, $home, $two, 0, $work);
    $d->undo->undoById($u, $rename);
    checkEq('undo: a rename undone after a later drag puts back the name and leaves the drag', ['Two', [$home]], [$calendar($two)['name'], $foldersOf($two)]);
    $was = $calendar($two)['color'];
    $d->calendars->patch($u, $two, ['color' => '#112244']);
    $color = $latest();
    $d->calendars->placeInList($u, $two, 0);
    $d->undo->undoById($u, $color);
    checkEq('undo: a colour undone after a later move in the list puts back the colour and leaves the move', [$was, $two], [$calendar($two)['color'], $list()[0]]);
    $d->folders->place($u, $temp, $two, 0, $home);
    $d->calendars->patch($u, $two, ['color' => '#223355']);
    $color = $latest();
    $d->folders->unfile($u, $temp, $two);
    $d->folders->delete($u, $temp);
    $d->undo->undoById($u, $color);
    checkEq('undo: a colour undone after its folder was emptied and deleted still undoes', [$was, []], [$calendar($two)['color'], $foldersOf($two)]);
    $d->calendars->patch($u, $one, ['folderIds' => []]);
    $d->folders->place($u, $work, $one, 0);
    $filed = $latest();
    $d->calendars->placeInList($u, $one, 5);
    $d->undo->undoById($u, $filed);
    $ordered = $list();
    checkEq('undo: filing undone after a later move in the list, of the same calendar: not stale, and the move stays', [[], $one], [$foldersOf($one), end($ordered)]);

    // A Google calendar's folders and place in the list are Better-Cal's
    // own: undoable like any calendar's, from its settings or a drag. Its
    // name, which is Google's, isn't, and Undo says so.
    $db->run("UPDATE calendars SET kind = 'subscribed', provider = 'google' WHERE id = ?", [$one]);
    $d->folders->place($u, $home, $one, 0);
    $d->folders->place($u, $work, $one, 0, $home);
    $googleMove = $latest();
    $d->calendars->patch($u, $one, ['folderIds' => [$work, $home]]);
    checkEq('folders: a Google calendar filed again from its settings since: stale, and nothing changes', ['stale_undo', [$work, $home]],
        [refusal(fn() => $d->undo->undoById($u, $googleMove)), $foldersOf($one)]);
    $d->undo->undoLatest($u);
    checkEq("undo: a Google calendar's folders set from its settings undo, as a drag's do", [$work], $foldersOf($one));
    $d->undo->undoLatest($u);
    checkEq('undo: and then its drag from one folder to another', [$home], $foldersOf($one));
    $d->calendars->placeInList($u, $one, 0);
    $d->undo->undoLatest($u);
    $ordered = $list();
    checkEq("undo: a Google calendar's move in the list undoes", $one, end($ordered));
    $d->calendars->patch($u, $one, ['visible' => false]);
    $d->undo->undoLatest($u);
    checkEq("undo: so does hiding it", 1, (int) $db->scalar('SELECT visible FROM calendars WHERE id = ?', [$one]));
    $d->calendars->patch($u, $one, ['name' => 'Renamed at Google']);
    checkEq("undo: a Google calendar's rename can't be undone here, and undo says so", 'not_undoable', refusal(fn() => $d->undo->undoLatest($u)));

    // One save in several steps: one entry, as before the first and after
    // the last; a save that fails records nothing.
    $grouped = new Undo($db);
    $before = $latest();
    $grouped->group(function () use ($grouped, $u, $two): void {
        $grouped->record($u, 'calendar', $two, 'update', ['calendars' => [['id' => $two, 'name' => 'A', 'color' => '#000000']]], ['calendars' => [['id' => $two, 'name' => 'B', 'color' => '#000000']]]);
        $grouped->record($u, 'calendar', $two, 'update', ['calendars' => [['id' => $two, 'name' => 'B', 'color' => '#000000']]], ['calendars' => [['id' => $two, 'name' => 'C', 'color' => '#111111']]]);
    }, 'Two steps');
    $entry = $db->one('SELECT id, summary, before_json, after_json FROM mutations WHERE user_id = ? AND id > ?', [$u, $before]);
    checkEq('undo group: one entry, before the first step and after the last, and what it changed', [1, 'Two steps', 'A', 'C', [["calendars:$two", 'color'], ["calendars:$two", 'name']]], [
        (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ? AND id > ?', [$u, $before]), $entry['summary'],
        json_decode($entry['before_json'], true)['tables']['calendars'][0]['name'], json_decode($entry['after_json'], true)['tables']['calendars'][0]['name'],
        array_map(static fn($r) => [$r['row_key'], $r['col']], $db->all('SELECT row_key, col FROM mutation_touches WHERE mutation_id = ? ORDER BY col', [(int) $entry['id']])),
    ]);
    $before = $latest();
    try {
        $db->tx(fn() => $grouped->group(function () use ($grouped, $u, $two): void {
            $grouped->record($u, 'calendar', $two, 'update', ['calendars' => [['id' => $two, 'name' => 'A']]], ['calendars' => [['id' => $two, 'name' => 'B']]]);
            throw new RuntimeException('a later step fails');
        }, 'Fails'));
    } catch (RuntimeException) {
    }
    checkEq('undo group: a save that fails records nothing', $before, $latest());
    // Many rows at once (a calendar of events deleted): every one recorded.
    $many = array_map(static fn(int $i) => ['id' => 100000 + $i, 'calendar_id' => $two, 'title' => 'x'], range(1, 450));
    $grouped->record($u, 'event', 100001, 'delete', ['events' => $many], []);
    checkEq('undo: a change of 450 rows records what it changed for each, and the calendar they were in', 451,
        (int) $db->scalar('SELECT COUNT(*) FROM mutation_touches WHERE mutation_id = ?', [$latest()]));
};
