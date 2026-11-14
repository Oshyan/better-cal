<?php

declare(strict_types=1);

// Probe (second independent read of the access build, 61be6c69..94befaeb): busy times only. The model
// (docs/design/google-access-detect-and-inform.md, sections 1 and 8): "A listing below reader is not applied ... every
// row here stays exactly as it was, held and Google fields alike, until a listing at reader or above comes"; "The poll
// reads its first page, stores the level, keeps no sync position (Google gives none) and applies nothing; each poll at
// that level reads one page; the first after access returns reads the calendar whole" and catches up. The second live
// round (2026-11-14, stage freebusy): a busy-only listing's items carry only times and ids, a series is listed as its
// occurrences, the listing ends with no sync token, and the delta from an earlier token answers 410. Catching up means:
// what the calendar shows here is what Google holds, events deleted, changed and made at Google meanwhile included, and
// what is held here alone stays on the events that are still there. Nothing is computed from server/src. Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/access-read-lib.php';

return static function (Db $db): void {
    $la = new DateTimeZone('America/Los_Angeles');
    $at = static fn(string $local): string => (new DateTimeImmutable($local, $la))->format('Y-m-d\TH:i:sP');
    $make = static function () use ($db, $at): object {
        $s = PA::make($db);
        $ids = [];
        foreach (['keep' => '2027-03-02 09:00', 'rename' => '2027-03-03 09:00', 'delete' => '2027-03-04 09:00'] as $name => $day) {
            $ids[$name] = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => "Sample $name", 'start' => $at($day), 'end' => $at(substr($day, 0, 11) . '10:00'), 'tzid' => 'America/Los_Angeles'])['eventId'];
        }
        foreach (['weekly' => '2027-03-01 10:00', 'gone weekly' => '2027-03-05 15:00'] as $name => $day) {
            $ids[$name] = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => "Sample $name", 'start' => $at($day), 'end' => $at(substr($day, 0, 11) . (str_starts_with($name, 'gone') ? '16:00' : '11:00')), 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=3'])['eventId'];
        }
        foreach (['keep', 'rename', 'delete', 'weekly'] as $name) {
            $s->events->patch($s->u, $ids[$name], ['tagNames' => ['sample-tag']] + ($name === 'weekly' ? ['scope' => 'all'] : []));
        }
        ($s->poll)();
        $s->ids = $ids;
        $s->gid = static fn(string $name): string => (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$ids[$name]]);
        return $s;
    };
    // What the calendar shows here in March 2027, as "local day time|title|#tags", from the app's own window.
    $shown = static function (object $s) use ($db, $la): array {
        $utc = new DateTimeZone('UTC');
        $out = [];
        foreach ($s->events->window($s->u, new DateTimeImmutable('2027-03-01 00:00:00', $utc), new DateTimeImmutable('2027-04-01 00:00:00', $utc), [$s->calendarId], null, true) as $x) {
            $tags = isset($x['eventId']) ? array_column($db->all('SELECT tg.name FROM event_tags et JOIN tags tg ON tg.id = et.tag_id WHERE et.event_id = ? ORDER BY tg.name', [(int) $x['eventId']]), 'name') : [];
            $out[] = (new DateTimeImmutable((string) $x['start']))->setTimezone($la)->format('Y-m-d H:i') . '|' . ($x['title'] ?? '') . ($tags === [] ? '' : '|#' . implode('#', $tags));
        }
        sort($out);
        return $out;
    };
    // A request to the stand-in as the calendar's owner makes it from elsewhere; the account's level put back after.
    $elsewhere = static function (object $s, array $requests): void {
        $level = $s->fake->role;
        $s->fake->role = 'owner';
        foreach ($requests as [$method, $path, $body]) {
            $answer = ($s->fake->transport())($method, 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(PA::ACCOUNT) . $path, ['Authorization: Bearer any', 'X-Harness: remote'], $body);
            if ((int) $answer['status'] >= 400) {
                throw new \RuntimeException("the probe's own request failed: $method $path " . $answer['status'] . ' ' . $answer['body']);
            }
        }
        $s->fake->role = $level;
    };
    $all = static fn(object $s): array => [$db->all('SELECT * FROM events WHERE calendar_id = ? ORDER BY id', [$s->calendarId]),
        $db->all('SELECT et.event_id, et.tag_id FROM event_tags et JOIN events e ON e.id = et.event_id WHERE e.calendar_id = ? ORDER BY 1, 2', [$s->calendarId])];
    $pollsSent = static function (object $s): array {
        $from = count($s->fake->requests);
        $error = ($s->poll)();
        return [array_map(static fn(array $r): string => 'GET ' . (isset($r['query']['syncToken']) ? 'delta' : (isset($r['query']['pageToken']) ? 'next page' : 'whole')) . ' ' . ($r['status'] ?? '?'), array_slice($s->fake->requests, $from)), $error];
    };

    foreach (['writer', 'reader'] as $back) {
        scenario("access read 2, busy times only: polls at that level apply nothing and read one page; when the level returns to $back the next poll reads the calendar whole and what is shown here is what Google holds, with what was held here alone kept", function () use ($db, $make, $shown, $elsewhere, $all, $pollsSent, $back): void {
            $s = $make();
            $before = $all($s);
            $shownBefore = $shown($s);
            $s->fake->role = 'freeBusyReader';
            [$first, $error] = $pollsSent($s);
            checkEq('the first poll at busy times only: the delta from the earlier token answers 410 (row 4d), then one page; it says the events stay as they were and do not update',
                [['GET delta 410', 'GET whole 200'], true], [$first, str_contains((string) $error, "only busy times for '" . PA::NAME . "'") && str_contains((string) $error, "don't update")]);
            // Meanwhile, at Google: an event deleted, one renamed, one made; a day of a series renamed, one deleted; a series deleted.
            $elsewhere($s, [
                ['DELETE', '/events/' . rawurlencode(($s->gid)('delete')), null],
                ['PATCH', '/events/' . rawurlencode(($s->gid)('rename')), ['summary' => 'Sample rename, renamed at Google']],
                ['POST', '/events', ['summary' => 'Sample made at Google', 'start' => ['dateTime' => '2027-03-09T09:00:00-08:00', 'timeZone' => 'America/Los_Angeles'], 'end' => ['dateTime' => '2027-03-09T10:00:00-08:00', 'timeZone' => 'America/Los_Angeles']]],
                ['PATCH', '/events/' . rawurlencode(($s->gid)('weekly') . '_20270308T180000Z'), ['summary' => 'Sample weekly, one day renamed at Google']],
                ['DELETE', '/events/' . rawurlencode(($s->gid)('weekly') . '_20270315T170000Z'), null],
                ['DELETE', '/events/' . rawurlencode(($s->gid)('gone weekly')), null],
            ]);
            [$second] = $pollsSent($s);
            [$third] = $pollsSent($s);
            checkEq('each later poll at busy times only reads one page and no more', [['GET whole 200'], ['GET whole 200']], [$second, $third]);
            checkEq('after three polls at busy times only: every column of every row and every tag as before; the calendar shows what it showed; no sync position kept',
                [$before, $shownBefore, null], [$all($s), $shown($s), $db->scalar('SELECT google_sync_token FROM calendars WHERE id = ?', [$s->calendarId])]);
            // The level returns.
            $s->fake->role = $back;
            [$sent, $error] = $pollsSent($s);
            checkEq("back to $back: the poll reads the calendar whole and succeeds", [true, null, $back],
                [$sent !== [] && array_filter($sent, static fn(string $r): bool => !str_starts_with($r, 'GET whole 200') && !str_starts_with($r, 'GET next page 200')) === [], $error, PA::storedRole($db, $s->calendarId)]);
            checkEq("back to $back: the calendar shows what Google holds now (the deleted event and series gone, the renames and the new event in), tags kept on what is still there", [
                '2027-03-01 10:00|Sample weekly|#sample-tag',
                '2027-03-02 09:00|Sample keep|#sample-tag',
                '2027-03-03 09:00|Sample rename, renamed at Google|#sample-tag',
                '2027-03-08 10:00|Sample weekly, one day renamed at Google|#sample-tag', // a day's tags follow its series (1.5.5)
                '2027-03-09 09:00|Sample made at Google',
            ], $shown($s));
            [$again] = $pollsSent($s);
            checkEq("back to $back: the poll after that is a delta again", ['GET delta 200'], $again);
        });
    }

    scenario('access read 2, busy times only: Check again costs one small list at each level, stores what Google said, and applies nothing to the events', function () use ($db, $make, $all): void {
        foreach (['freeBusyReader', 'reader', 'none', 'writer'] as $level) {
            $s = $make();
            $s->fake->role = 'freeBusyReader';
            ($s->poll)();
            $before = $all($s);
            $s->fake->role = $level;
            $from = count($s->fake->requests);
            $role = null;
            $e = PA::caught(function () use ($s, &$role): void {
                $role = $s->events->checkGoogleAccess($s->u, $s->calendarId);
            });
            checkEq("stored busy times only, Google now says $level: Check again answers that level after one small list, stores it, and no row or tag differs", [null, $level, ['GET small'], $level, $before],
                [PA::code($e), $role, PA::sent($s->fake, $from), PA::storedRole($db, $s->calendarId), $all($s)]);
        }
    });
};
