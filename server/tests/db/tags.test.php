<?php

declare(strict_types=1);

// Tags as a list to manage (#134): a tag is offered while an event that
// isn't deleted carries it, read from the links each time; rename changes
// the name on every event, and onto a name another tag has merges the two;
// delete takes it off every event. Each is one journal entry, and its undo
// puts the tags and links back as they were. A repeating event with an
// edited day that owns its tags and one that follows its series is run
// through every step; the suite checks the link rule over all data after
// each block. Invented data.

use BetterCal\Domain\Filters;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Tags;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;

return static function (Db $db): void {
    $tagsOf = static fn(int $eventId): array => array_column($db->all(
        'SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$eventId]), 'name');
    $tagId = static fn(int $u, string $name): int => (int) $db->scalar('SELECT id FROM tags WHERE user_id = ? AND name = ?', [$u, $name]);
    $offered = static fn(int $u): array => array_map(static fn(array $t): array => [$t['name'], $t['eventCount']], domains($db)->calendars->listAll($u)['tags']);
    $entries = static fn(int $u): int => (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u]);

    scenario('tags: a tag is offered while an event that is not deleted carries it, computed from the links', function () use ($db, $tagId, $offered): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $one = (int) $d->events->create($u, eventAt($cal, 'Sample lunch', '2026-12-10', ['tagNames' => ['Sample-food']]))['eventId'];
        $two = (int) $d->events->create($u, eventAt($cal, 'Sample run', '2026-12-11', ['tagNames' => ['Sample-sport', 'Sample-food']]))['eventId'];
        checkEq('tags: both offered, each with the events that carry it', [['Sample-food', 2], ['Sample-sport', 1]], $offered($u));
        $d->events->deleteEvent($u, $two, null, null);
        $deleted = lastEntry($db, $u);
        checkEq('tags: the only event carrying a tag deleted, the tag is no longer offered', [['Sample-food', 1]], $offered($u));
        $d->events->patch($u, $one, ['tagNames' => []]);
        checkEq('tags: the last tag taken off its last event, nothing is offered', [], $offered($u));
        check('tags: the rows stay, for any undo that names them', $tagId($u, 'Sample-food') > 0 && $tagId($u, 'Sample-sport') > 0);
        $d->undo->undoById($u, $deleted);
        checkEq('tags: the event brought back, its tags are offered again', [['Sample-food', 1], ['Sample-sport', 1]], $offered($u));
        checkEq('tags: GET /tags lists the same tags', ['Sample-food', 'Sample-sport'], array_column((new Tags($db))->list($u), 'name'));
    });

    scenario('tags: rename changes the name on every event, and its undo puts it back', function () use ($db, $tagsOf, $tagId, $entries): void {
        $u = freshUser($db);
        $d = domains($db);
        $tags = new Tags($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $one = (int) $d->events->create($u, eventAt($cal, 'Sample lunch', '2026-12-10', ['tagNames' => ['Sample-food']]))['eventId'];
        $two = (int) $d->events->create($u, eventAt($cal, 'Sample dinner', '2026-12-11', ['tagNames' => ['Sample-food']]))['eventId'];
        $id = $tagId($u, 'Sample-food');
        checkEq('tags: renamed, the answer names it with its events', ['id' => $id, 'name' => 'Sample-meals', 'merged' => false, 'eventCount' => 2], $tags->rename($u, $id, '  Sample-meals '));
        checkEq('tags: every event carries the new name', [['Sample-meals'], ['Sample-meals']], [$tagsOf($one), $tagsOf($two)]);
        $d->undo->undoById($u, lastEntry($db, $u));
        checkEq('tags: the rename undone, the old name is back on every event', [['Sample-food'], ['Sample-food']], [$tagsOf($one), $tagsOf($two)]);
        checkEq('tags: a change of case alone renames, it is no merge', ['id' => $id, 'name' => 'SAMPLE-FOOD', 'merged' => false, 'eventCount' => 2], $tags->rename($u, $id, 'SAMPLE-FOOD'));
        $n = $entries($u);
        $tags->rename($u, $id, 'SAMPLE-FOOD');
        checkEq('tags: a rename to the name it has writes nothing', $n, $entries($u));
        checkEq('tags: an empty name is refused', 'invalid_request', refusal(fn() => $tags->rename($u, $id, '   ')));
        checkEq('tags: a name that is not text is refused', 'invalid_request', refusal(fn() => $tags->rename($u, $id, null)));
        $other = freshUser($db);
        checkEq('tags: another account\'s tag is not found, for rename', 'not_found', refusal(fn() => $tags->rename($other, $id, 'Sample-mine')));
        checkEq('tags: another account\'s tag is not found, for delete', 'not_found', refusal(fn() => $tags->delete($other, $id)));
        checkEq('tags: nothing of the account changed', [['SAMPLE-FOOD'], ['SAMPLE-FOOD']], [$tagsOf($one), $tagsOf($two)]);
    });

    scenario('tags: a rename onto a name another tag has merges the two, and its undo parts them again', function () use ($db, $tagsOf, $tagId): void {
        $u = freshUser($db);
        $d = domains($db);
        $tags = new Tags($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $one = (int) $d->events->create($u, eventAt($cal, 'Sample lunch', '2026-12-10', ['tagNames' => ['Sample-food']]))['eventId'];
        $both = (int) $d->events->create($u, eventAt($cal, 'Sample picnic', '2026-12-11', ['tagNames' => ['Sample-food', 'Sample-meals']]))['eventId'];
        $three = (int) $d->events->create($u, eventAt($cal, 'Sample dinner', '2026-12-12', ['tagNames' => ['Sample-meals']]))['eventId'];
        $food = $tagId($u, 'Sample-food');
        $meals = $tagId($u, 'Sample-meals');
        checkEq('tags: merged into the tag of that name, case aside; it keeps its own spelling', ['id' => $meals, 'name' => 'Sample-meals', 'merged' => true, 'eventCount' => 3],
            $tags->rename($u, $food, 'sample-MEALS'));
        checkEq('tags: every event carries the survivor once', [['Sample-meals'], ['Sample-meals'], ['Sample-meals']], [$tagsOf($one), $tagsOf($both), $tagsOf($three)]);
        checkEq('tags: the merged tag is gone', 0, $tagId($u, 'Sample-food'));
        $d->undo->undoById($u, lastEntry($db, $u));
        checkEq('tags: the merge undone, each event has the tags it had', [['Sample-food'], ['Sample-food', 'Sample-meals'], ['Sample-meals']], [$tagsOf($one), $tagsOf($both), $tagsOf($three)]);
        checkEq('tags: the merged tag is back with its own id', $food, $tagId($u, 'Sample-food'));
    });

    scenario('tags: delete takes the tag off every event, and its undo puts it back', function () use ($db, $tagsOf, $tagId): void {
        $u = freshUser($db);
        $d = domains($db);
        $tags = new Tags($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $one = (int) $d->events->create($u, eventAt($cal, 'Sample lunch', '2026-12-10', ['tagNames' => ['Sample-food', 'Sample-out']]))['eventId'];
        $two = (int) $d->events->create($u, eventAt($cal, 'Sample dinner', '2026-12-11', ['tagNames' => ['Sample-food']]))['eventId'];
        $food = $tagId($u, 'Sample-food');
        checkEq('tags: deleted, the answer says how many events it was on', ['ok' => true, 'eventCount' => 2], $tags->delete($u, $food));
        checkEq('tags: the events stay, without it', [['Sample-out'], []], [$tagsOf($one), $tagsOf($two)]);
        checkEq('tags: the tag is gone', 0, $tagId($u, 'Sample-food'));
        checkEq('tags: deleting it again is not found', 'not_found', refusal(fn() => $tags->delete($u, $food)));
        $d->undo->undoById($u, lastEntry($db, $u));
        checkEq('tags: the delete undone, it is back on both events', [['Sample-food', 'Sample-out'], ['Sample-food']], [$tagsOf($one), $tagsOf($two)]);
    });

    scenario('tags: a repeating event with an edited day owning its tags and one following its series, through rename, merge and delete and each undo', function () use ($db, $tagsOf, $tagId): void {
        $u = freshUser($db);
        $d = domains($db);
        $tags = new Tags($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $series = (int) $d->events->create($u, ['calendarId' => $cal, 'title' => 'Sample weekly', 'start' => '2027-01-04T09:00:00Z', 'end' => '2027-01-04T10:00:00Z',
            'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=8', 'tagNames' => ['Sample-A']])['eventId'];
        $d->events->patch($u, $series, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'tagNames' => ['Sample-A', 'Sample-D']]);
        $d->events->patch($u, $series, ['scope' => 'this', 'instanceStart' => '2027-01-18T09:00:00Z', 'title' => 'Sample moved day']);
        $day = static fn(string $at): int => (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$series, $at]);
        $owning = $day('2027-01-11 09:00:00');
        $following = $day('2027-01-18 09:00:00');
        $state = static fn(): array => [$tagsOf($series), $tagsOf($owning), $tagsOf($following)];
        checkEq('tags: as made: the series, a day owning its tags, a day following the series', [['Sample-A'], ['Sample-A', 'Sample-D'], ['Sample-A']], $state());
        checkEq('tags: the series and its days count as one event', 1, $tags->rename($u, $tagId($u, 'Sample-A'), 'Sample-X')['eventCount']);
        checkEq('tags: renamed on the series and on both days', [['Sample-X'], ['Sample-D', 'Sample-X'], ['Sample-X']], $state());
        $renamed = lastEntry($db, $u);
        $tags->rename($u, $tagId($u, 'Sample-D'), 'Sample-X');
        checkEq('tags: the day\'s own tag merged into the series\' tag: the day holds it once', [['Sample-X'], ['Sample-X'], ['Sample-X']], $state());
        checkEq('tags: the day still owns its tags', true, in_array('tags', json_decode((string) $db->scalar('SELECT override_fields FROM events WHERE id = ?', [$owning]), true) ?? [], true));
        $merged = lastEntry($db, $u);
        $tags->delete($u, $tagId($u, 'Sample-X'));
        checkEq('tags: deleted off the series and both days', [[], [], []], $state());
        $d->undo->undoById($u, lastEntry($db, $u));
        checkEq('tags: the delete undone', [['Sample-X'], ['Sample-X'], ['Sample-X']], $state());
        $d->undo->undoById($u, $merged);
        checkEq('tags: the merge undone: the day has its own second tag again', [['Sample-X'], ['Sample-D', 'Sample-X'], ['Sample-X']], $state());
        $d->undo->undoById($u, $renamed);
        checkEq('tags: the rename undone: as made', [['Sample-A'], ['Sample-A', 'Sample-D'], ['Sample-A']], $state());
        // The series' tags changed for all events after the rename: the day following it takes them, the owning day keeps its own.
        planned($d->events, $u, $series, ['scope' => 'all', 'tagNames' => ['Sample-B']]);
        checkEq('tags: a change to the series\' tags after all this reaches the following day only', [['Sample-B'], ['Sample-A', 'Sample-D'], ['Sample-B']], $state());
    });

    scenario('tags: the list says how many filters on tags match each name; a rename leaves them as they are', function () use ($db, $tagId): void {
        $u = freshUser($db);
        $d = domains($db);
        $tags = new Tags($db);
        $filters = new Filters($db, $d->undo, new JobQueue($db));
        $cal = Settings::defaultCalendarId($db, $u);
        $d->events->create($u, eventAt($cal, 'Sample lunch', '2026-12-10', ['tagNames' => ['Sample-food', 'Sample-out']]));
        $filters->create($u, ['scope' => 'global', 'type' => 'keyword', 'config' => ['pattern' => 'food', 'fields' => ['tags']], 'action' => 'dim']);
        $filters->create($u, ['scope' => 'global', 'type' => 'regex', 'config' => ['pattern' => '^sample-f', 'fields' => ['title', 'tags']], 'action' => 'highlight', 'enabled' => false]);
        $filters->create($u, ['scope' => 'global', 'type' => 'keyword', 'config' => ['pattern' => 'food', 'fields' => ['title']], 'action' => 'hide']);
        $byName = static fn(): array => array_column($tags->list($u), 'filterCount', 'name');
        checkEq('tags: two filters on tags match one name (one turned off), none the other; one on titles alone does not count', ['Sample-food' => 2, 'Sample-out' => 0], $byName());
        $configs = $db->all('SELECT config_json FROM filters WHERE user_id = ? ORDER BY id', [$u]);
        $tags->rename($u, $tagId($u, 'Sample-food'), 'Sample-meals');
        checkEq('tags: after the rename, the filters are as they were', $configs, $db->all('SELECT config_json FROM filters WHERE user_id = ? ORDER BY id', [$u]));
        checkEq('tags: and no longer match it', ['Sample-meals' => 0, 'Sample-out' => 0], $byName());
    });

    // #310: a rename gives a tag only a name the # marker can write, so it
    // can be typed in quick add and search. A tag that already has another
    // name (the event's Tags field and the API take any text) keeps working.
    scenario('tags: a rename refuses a name the # marker cannot write, and says which characters a name may have', function () use ($db, $tagsOf, $tagId, $entries): void {
        $u = freshUser($db);
        $d = domains($db);
        $tags = new Tags($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $one = (int) $d->events->create($u, eventAt($cal, 'Sample lunch', '2026-12-10', ['tagNames' => ['Sample food']]))['eventId'];
        $id = $tagId($u, 'Sample food');
        $n = $entries($u);
        foreach (['two words' => 'Sample meals', 'a #' => 'Sample#meals', 'a dot' => 'sample.meals', 'digits only' => '2027', 'a leading hyphen' => '-meals', '51 characters' => str_repeat('m', 51)] as $what => $name) {
            checkEq("tags: a name with $what is refused", 'tag_name_unwritable', refusal(fn() => $tags->rename($u, $id, $name)));
        }
        try {
            $tags->rename($u, $id, 'Sample meals');
        } catch (BetterCal\Http\HttpError $e) {
            check('tags: the refusal says which characters a name may have', str_contains($e->getMessage(), 'letters, digits, hyphens (-) and underscores (_)'), $e->getMessage());
        }
        checkEq('tags: a refused rename writes nothing', [$n, ['Sample food']], [$entries($u), $tagsOf($one)]);
        checkEq('tags: the tag made with a space by the Tags field is still offered', ['Sample food'], array_column($d->calendars->listAll($u)['tags'], 'name'));
        checkEq('tags: it renames to a name the marker can write: letters of any script, digits, - and _, 50 at most', 'Café_2027-' . str_repeat('m', 40), $tags->rename($u, $id, 'Café_2027-' . str_repeat('m', 40))['name']);
        $space = (int) $d->events->create($u, eventAt($cal, 'Sample dinner', '2026-12-11', ['tagNames' => ['Sample out']]))['eventId'];
        checkEq('tags: a tag with a space is deleted like any other', ['ok' => true, 'eventCount' => 1], $tags->delete($u, $tagId($u, 'Sample out')));
        checkEq('tags: and is off its event', [], $tagsOf($space));
    });
};
