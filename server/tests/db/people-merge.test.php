<?php

declare(strict_types=1);

// Renaming a person onto another's name merges the two, and everything
// connected to either name is the merged person's: both rows compared column
// by column (notes joined, shown on the calendar when either was, the
// earlier start), every row that refers to either moved, and the undo puts
// both people back as they were. The columns and the referring tables are
// read from the schema, so a new one fails here until the merge has a rule
// for it. The sibling, a tag renamed onto another's name, is checked the
// same way. Invented data.

use BetterCal\Domain\People;
use BetterCal\Domain\Tags;
use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $columnsOf = static fn(string $table): array => array_column($db->all(
        'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY c', [$table]), 'c');
    $referring = static function (string $table) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? ORDER BY t', [$table]) as $r) {
            $out[(string) $r['t']] = (string) $r['c'];
        }
        return $out;
    };

    scenario('people merge: the merge has a rule for every column and every table that refers to a person', function () use ($columnsOf, $referring): void {
        $covered = array_merge(['id', 'user_id', 'name'], array_keys(People::MERGE_COLUMNS));
        sort($covered);
        checkEq('people merge: every column of people is the identity or has a merge rule', $covered, $columnsOf('people'));
        $refs = People::REFERRING;
        ksort($refs);
        checkEq('people merge: every table referring to people is moved', $refs, $referring('people'));
        // The sibling: a tag's row is its identity alone, and one table refers to it.
        checkEq('tags merge: a tag row holds nothing but its identity', ['id', 'name', 'user_id'], $columnsOf('tags'));
        checkEq('tags merge: every table referring to tags is moved', ['event_tags' => 'tag_id'], $referring('tags'));
    });

    $setup = static function () use ($db): object {
        $u = freshUser($db);
        $d = domains($db);
        $home = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
        $people = new People($db);
        $kept = $people->create($u, ['name' => 'Alex Example', 'notes' => 'Likes tea.'])['id'];
        $renamed = $people->create($u, ['name' => 'Alex', 'notes' => 'Met at the sample meetup.'])['id'];
        // The survivor hidden from the calendar, the renamed one shown, and started a year earlier.
        $people->update($u, $kept, ['showOnCalendar' => false]);
        $db->run('UPDATE people SET created_at = ? WHERE id = ?', ['2025-06-01 09:00:00', $renamed]);
        $both = (int) $d->events->create($u, eventAt($home, 'Team sync', '2026-12-08') + ['personIds' => [$kept, $renamed]])['eventId'];
        $onlyRenamed = (int) $d->events->create($u, eventAt($home, 'Sample lunch', '2026-12-09') + ['personIds' => [$renamed]])['eventId'];
        $onlyKept = (int) $d->events->create($u, eventAt($home, 'Sample dinner', '2026-12-10') + ['personIds' => [$kept]])['eventId'];
        $people->addSpan($u, $kept, ['start' => '2026-12-20T00:00:00Z', 'end' => '2026-12-24T00:00:00Z', 'kind' => 'away']);
        $people->addSpan($u, $renamed, ['start' => '2026-12-27T00:00:00Z', 'end' => '2026-12-28T00:00:00Z', 'kind' => 'busy', 'note' => 'Sample trip']);
        return (object) compact('u', 'people', 'kept', 'renamed', 'both', 'onlyRenamed', 'onlyKept');
    };
    $state = static fn(int $u): array => [
        'people' => $db->all('SELECT * FROM people WHERE user_id = ? ORDER BY id', [$u]),
        'event_people' => $db->all('SELECT ep.* FROM event_people ep JOIN people p ON p.id = ep.person_id WHERE p.user_id = ? ORDER BY ep.event_id, ep.person_id', [$u]),
        'availability' => $db->all('SELECT a.* FROM availability a JOIN people p ON p.id = a.person_id WHERE p.user_id = ? ORDER BY a.id', [$u]),
    ];

    scenario('people merge: a rename onto another person keeps everything either had, and undoes back', function () use ($db, $setup, $state): void {
        $s = $setup();
        $before = $state($s->u);
        $spans = count($before['availability']);
        $out = $s->people->update($s->u, $s->renamed, ['name' => 'Alex Example']);
        checkEq('people merge: the survivor is answered', $s->kept, $out['id']);
        $row = $db->one('SELECT * FROM people WHERE id = ?', [$s->kept]);
        checkEq('people merge: one person left', 1, (int) $db->scalar('SELECT COUNT(*) FROM people WHERE user_id = ?', [$s->u]));
        checkEq('people merge: both notes kept, the survivor\'s first', "Likes tea.\n\nMet at the sample meetup.", $row['notes']);
        checkEq('people merge: shown on the calendar when either was', 1, (int) $row['show_on_calendar']);
        checkEq('people merge: the earlier start kept', '2025-06-01 09:00:00', (string) $row['created_at']);
        checkEq('people merge: every event either was on', [$s->both, $s->onlyRenamed, $s->onlyKept],
            array_map('intval', array_column($db->all('SELECT event_id FROM event_people WHERE person_id = ? ORDER BY event_id', [$s->kept]), 'event_id')));
        checkEq('people merge: every away and busy time either had, with its note', [$spans, 'Sample trip'],
            [(int) $db->scalar('SELECT COUNT(*) FROM availability WHERE person_id = ?', [$s->kept]),
             $db->scalar("SELECT note FROM availability WHERE person_id = ? AND kind = 'busy'", [$s->kept])]);
        (new Undo($db))->undoById($s->u, lastEntry($db, $s->u));
        checkEq('people merge: undo puts both people back as they were, with their links and times', $before, $state($s->u));
    });

    scenario('people merge: the same notes once, one side\'s notes when the other has none', function () use ($db, $setup): void {
        $s = $setup();
        $s->people->update($s->u, $s->renamed, ['notes' => 'Likes tea.']);
        $s->people->update($s->u, $s->renamed, ['name' => 'Alex Example']);
        checkEq('people merge: equal notes are kept once', 'Likes tea.', $db->scalar('SELECT notes FROM people WHERE id = ?', [$s->kept]));
        checkEq('people merge: notes joined', [null, 'b', 'a', "a\n\nb"],
            [People::joinedNotes(null, ''), People::joinedNotes(null, 'b'), People::joinedNotes('a', '  '), People::joinedNotes('a', 'b')]);
    });

    scenario('tags merge: a rename onto another tag keeps every event either was on, and undoes back', function () use ($db): void {
        $u = freshUser($db);
        $d = domains($db);
        $home = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
        $one = (int) $d->events->create($u, eventAt($home, 'Team sync', '2026-12-08') + ['tagNames' => ['work', 'office']])['eventId'];
        $two = (int) $d->events->create($u, eventAt($home, 'Sample review', '2026-12-09') + ['tagNames' => ['office']])['eventId'];
        $three = (int) $d->events->create($u, eventAt($home, 'Sample plan', '2026-12-10') + ['tagNames' => ['work']])['eventId'];
        $state = static fn(): array => [$db->all('SELECT * FROM tags WHERE user_id = ? ORDER BY id', [$u]),
            $db->all('SELECT et.* FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE t.user_id = ? ORDER BY et.event_id, et.tag_id', [$u])];
        $before = $state();
        $office = (int) $db->scalar("SELECT id FROM tags WHERE user_id = ? AND name = 'office'", [$u]);
        (new Tags($db))->rename($u, $office, 'work');
        checkEq('tags merge: every event either tag was on', [$one, $two, $three],
            array_map('intval', array_column($db->all("SELECT et.event_id FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE t.user_id = ? AND t.name = 'work' ORDER BY et.event_id", [$u]), 'event_id')));
        (new Undo($db))->undoById($u, lastEntry($db, $u));
        checkEq('tags merge: undo puts both tags back with their events', $before, $state());
    });
};
