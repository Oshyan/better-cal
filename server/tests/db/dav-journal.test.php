<?php

declare(strict_types=1);

// The CalDAV change journal (#119, #218) as sync-collection clients read it:
// an event made, edited, deleted or moved to another calendar through the
// Events domain leaves one dav_changes row per calendar touched, carrying
// the object's uri, the operation and the calendar's synctoken as it was
// before the change, and the calendar's synctoken is one higher after. A
// move is a deletion on the calendar it left and an addition on the one it
// joined. The row is written in the change's own transaction: a journal
// that can't be written fails the change, which leaves nothing behind.
// Each block is a scenario(): a throw fails that block, and the next still
// runs.

use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $home = (int) Settings::defaultCalendarId($db, $u);
    $away = (int) $d->calendars->create($u, ['name' => 'Sample second'])['id'];
    $token = static fn(int $calendar): int => (int) $db->scalar('SELECT synctoken FROM calendars WHERE id = ?', [$calendar]);
    // What a client holding $since would be told: sync-collection asks for
    // every row at or after its token (the row carries the token before the
    // bump). Operations are the journal's: 1 added, 2 modified, 3 deleted.
    $told = static fn(int $calendar, int $since): array => array_map(
        static fn(array $r): array => [(string) $r['uri'], (int) $r['operation'], (int) $r['synctoken']],
        $db->all('SELECT uri, operation, synctoken FROM dav_changes WHERE calendar_id = ? AND synctoken >= ? ORDER BY synctoken, id', [$calendar, $since])
    );
    $uri = static fn(int $id): string => (string) $db->scalar('SELECT uid FROM events WHERE id = ?', [$id]) . '.ics';

    scenario('dav journal: an event created', function () use ($d, $u, $home, $token, $told, $uri): void {
        $was = $token($home);
        $id = (int) $d->events->create($u, eventAt($home, 'Sample supper', '2027-03-04'))['eventId'];
        checkEq('dav journal: an event created is one addition row at the old token, and the token is one higher',
            [[[$uri($id), 1, $was]], $was + 1], [$told($home, $was), $token($home)]);
    });

    scenario('dav journal: an event edited', function () use ($d, $u, $home, $token, $told, $uri): void {
        $id = (int) $d->events->create($u, eventAt($home, 'Sample lunch', '2027-03-05'))['eventId'];
        $was = $token($home);
        $d->events->patch($u, $id, ['title' => 'Sample late lunch']);
        checkEq('dav journal: an event edited is one modification row at the old token, and the token is one higher',
            [[[$uri($id), 2, $was]], $was + 1], [$told($home, $was), $token($home)]);
    });

    scenario('dav journal: an event deleted', function () use ($d, $u, $home, $token, $told, $uri): void {
        $id = (int) $d->events->create($u, eventAt($home, 'Sample walk', '2027-03-06'))['eventId'];
        $object = $uri($id);
        $was = $token($home);
        $d->events->deleteEvent($u, $id, null, null);
        checkEq('dav journal: an event deleted is one deletion row at the old token, and the token is one higher',
            [[[$object, 3, $was]], $was + 1], [$told($home, $was), $token($home)]);
    });

    scenario('dav journal: an event moved to another calendar', function () use ($d, $u, $home, $away, $token, $told, $uri): void {
        $id = (int) $d->events->create($u, eventAt($home, 'Sample reading', '2027-03-07'))['eventId'];
        $object = $uri($id);
        [$wasHome, $wasAway] = [$token($home), $token($away)];
        $d->events->patch($u, $id, ['calendarId' => $away]);
        checkEq('dav journal: a move is a deletion on the calendar it left, and that token is one higher',
            [[[$object, 3, $wasHome]], $wasHome + 1], [$told($home, $wasHome), $token($home)]);
        checkEq('dav journal: a move is an addition on the calendar it joined, and that token is one higher',
            [[[$object, 1, $wasAway]], $wasAway + 1], [$told($away, $wasAway), $token($away)]);
        checkEq('dav journal: the moved event keeps its uid, so both rows name the same object', $object, $uri($id));
    });

    scenario('dav journal: a journal that cannot be written fails the change', function () use ($db, $d, $u, $home, $token): void {
        // The journal's rows refused, as a full disk or a lost table would.
        $count = static fn(): int => (int) $db->scalar("SELECT COUNT(*) FROM events WHERE user_id = ? AND title = 'Sample refused'", [$u]);
        $entries = lastEntry($db, $u);
        $was = $token($home);
        $db->run("CREATE TRIGGER refuse_dav_changes BEFORE INSERT ON dav_changes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal refused'");
        try {
            $failed = null;
            try {
                $d->events->create($u, eventAt($home, 'Sample refused', '2027-03-08'));
            } catch (\PDOException $e) {
                $failed = $e->getMessage();
            }
            check('dav journal: the create fails when its journal row cannot be written', $failed !== null && str_contains($failed, 'journal refused'), (string) $failed);
        } finally {
            $db->run('DROP TRIGGER IF EXISTS refuse_dav_changes');
        }
        checkEq('dav journal: and the create is rolled back: no event, no Activity entry, the token as it was',
            [0, $entries, $was], [$count(), lastEntry($db, $u), $token($home)]);
        $d->events->create($u, eventAt($home, 'Sample refused', '2027-03-08'));
        checkEq('dav journal: with the journal writable again, the same create is saved and journaled', [1, $was + 1], [$count(), $token($home)]);
    });
};
