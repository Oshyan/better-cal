<?php

declare(strict_types=1);

// The link rule's own negative controls (lib.php, linkViolations and
// dataRulesCheck): damage made by raw SQL, never by the app, must be
// reported, kind by kind, and a day that owns its tags may hold any set.
// Then a block that leaves damage behind must fail its own link check,
// naming the row, and the damage is removed so the file ends clean.

use BetterCal\Infra\Db;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $home = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ? ORDER BY id LIMIT 1', [$u]);
    // A tagged series with a person, and a day row made beside it by raw SQL: the master's row with a parent, a key and the list given.
    $series = static function (string $title) use ($d, $u, $home): int {
        return (int) $d->events->create($u, eventAt($home, $title, '2027-02-01', ['rrule' => 'FREQ=DAILY;COUNT=10', 'tagNames' => ['sample'], 'personNames' => ['Alex Example']]))['eventId'];
    };
    $rawDay = static function (int $master, string $key, ?string $own) use ($db): int {
        $row = $db->one('SELECT * FROM events WHERE id = ?', [$master]);
        unset($row['id']);
        $row = ['recurrence_parent_id' => $master, 'recurrence_instance_utc' => $key, 'rrule' => null, 'exdates_json' => null, 'override_fields' => $own,
            'start_utc' => $key, 'end_utc' => substr($key, 0, 11) . '19:00:00'] + $row;
        return (int) $db->insert('events', $row);
    };
    $copyLinks = static function (int $from, int $to) use ($db): void {
        $db->run('INSERT INTO event_tags (event_id, tag_id) SELECT ?, tag_id FROM event_tags WHERE event_id = ?', [$to, $from]);
        $db->run('INSERT INTO event_people (event_id, person_id) SELECT ?, person_id FROM event_people WHERE event_id = ?', [$to, $from]);
    };
    $found = static fn(): array => array_keys(linkViolations($db, $u));

    scenario('link rule controls: each kind of damage made by raw SQL is reported, and nothing else', function () use ($db, $u, $series, $rawDay, $copyLinks, $found): void {
        $m = $series('Sample A');
        checkEq('a tagged series alone breaks nothing', [], $found());

        $noLinks = $rawDay($m, '2027-02-02 18:00:00', '[]');
        checkEq('a day row under a tagged series with no links, owning nothing: reported for tags and for people', ["tags:$noLinks", "people:$noLinks"], $found());
        $copyLinks($m, $noLinks);
        checkEq('given its series\' tags and people, it is no longer reported', [], $found());

        $db->run('DELETE FROM event_people WHERE event_id = ?', [$noLinks]);
        checkEq('a day without its series\' person, owning nothing: reported for people alone', ["people:$noLinks"], $found());
        $db->run('UPDATE events SET override_fields = ? WHERE id = ?', ['["people"]', $noLinks]);
        checkEq('the same day owning its people: its empty set is its own, not reported', [], $found());

        $other = (int) $db->insert('tags', ['user_id' => $u, 'name' => 'sample-other']);
        $differs = $rawDay($m, '2027-02-03 18:00:00', '["title"]');
        $copyLinks($m, $differs);
        $db->run('DELETE FROM event_tags WHERE event_id = ?', [$differs]);
        $db->insert('event_tags', ['event_id' => $differs, 'tag_id' => $other]);
        checkEq('a day owning its title, not its tags, holding another tag than its series\': reported for tags', ["tags:$differs"], $found());
        $db->insert('event_tags', ['event_id' => $differs, 'tag_id' => (int) $db->scalar('SELECT tag_id FROM event_tags WHERE event_id = ?', [$m])]);
        checkEq('the series\' tag and one more, not owned: still reported (a superset is not the series\' set)', ["tags:$differs"], $found());
        $db->run('UPDATE events SET override_fields = ? WHERE id = ?', ['["tags","title"]', $differs]);
        checkEq('the same day owning its tags: any set is its own', [], $found());

        $nullList = $rawDay($m, '2027-02-04 18:00:00', null);
        $copyLinks($m, $nullList);
        checkEq('a day row with a NULL list, holding its series\' links: reported as a NULL list, not as links', ["list:$nullList"], $found());
        $db->run('UPDATE events SET override_fields = ? WHERE id = ?', ['[]', $nullList]);

        $gone = $series('Sample B');
        $orphan = $rawDay($gone, '2027-02-02 18:00:00', '[]');
        $db->run('UPDATE events SET deleted_at = NOW() WHERE id = ?', [$gone]);
        checkEq('a live day row whose series is deleted: reported as structure, not as links', ["series:$orphan"], $found());
        $db->run('DELETE FROM events WHERE id = ?', [$gone]);
        checkEq('a live day row whose series is missing: reported as structure', ["series:$orphan"], $found());
        $db->run('UPDATE events SET deleted_at = NOW() WHERE id = ?', [$orphan]);
        checkEq('a deleted day row is no day row: nothing reported', [], $found());

        // Leave nothing behind for the block's own check.
        $db->run('DELETE FROM events WHERE id IN (?, ?, ?, ?, ?)', [$noLinks, $differs, $nullList, $orphan, $m]);
    });

    // The hook itself: a block that leaves a day row without its series' tags fails its own link check, naming the row.
    // Its checks are taken back out of the record, so only this file's verdict on them counts.
    $before = count($GLOBALS['__checks']);
    [$pass, $fail] = [$GLOBALS['__pass'], $GLOBALS['__fail']];
    $m = $series('Sample C');
    $damaged = 0;
    ob_start();
    scenario('a sample block that leaves a day without its series\' tags', function () use ($m, $rawDay, $copyLinks, $db, &$damaged): void {
        $damaged = $rawDay($m, '2027-02-05 18:00:00', '[]');
        $copyLinks($m, $damaged);
        $db->run('DELETE FROM event_tags WHERE event_id = ?', [$damaged]);
    });
    $printed = (string) ob_get_clean();
    $made = array_splice($GLOBALS['__checks'], $before);
    [$GLOBALS['__pass'], $GLOBALS['__fail']] = [$pass, $fail];
    $name = "a sample block that leaves a day without its series' tags: " . LINKS_CHECK;
    $mine = array_values(array_filter($made, static fn(array $c): bool => $c['name'] === $name));
    check('a block that leaves a day row without its series\' tags fails its link check, under its own name, naming the row',
        count($mine) === 1 && !$mine[0]['pass'] && str_contains($mine[0]['detail'], "day row $damaged ") && str_contains($printed, 'FAIL: a sample block'), json_encode($made));
    $others = array_filter($made, static fn(array $c): bool => $c['name'] !== $name);
    check('and its other checks over all data pass', count($others) === 5 && array_filter($others, static fn(array $c): bool => !$c['pass']) === [], json_encode($others));
    $db->run('DELETE FROM events WHERE id IN (?, ?)', [$damaged, $m]);
    dataRulesCheck('after the damage is removed', null, null);

    // The block the mutation answer-day-without-series-links (tools/edit-harness/mutations/list.php) runs: an answer for
    // one day of a tagged series with a person makes that day's row, which must hold the series' tags and person.
    scenario('an answer for one day of a tagged series with a person', function () use ($d, $u, $series, $db): void {
        $m = $series('Sample E');
        $d->events->setAttendance($u, $m, 'going', 'this', '2027-02-03T18:00:00+00:00');
        checkEq('the answer made the day\'s row, owning its answer', ['attendance'],
            json_decode((string) $db->scalar("SELECT override_fields FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-02-03 18:00:00'", [$m]), true));
    });
};
