<?php

declare(strict_types=1);

// Probe (second independent read of the access build, 61be6c69..94befaeb): the one small list asked before a refused
// write is read (the model, docs/design/google-access-detect-and-inform.md, section 8, rule D: "A write refused 403 (not
// a rate limit), 404, 410 or 412 is read only after the one small list says the level now; below writer it is refused
// as read-only, never as Google refusing that change, the event deleted ('already removed' for a delete) or changed at
// Google. Cost: one list, paid only on such a refusal, never on a write Google takes."), and the seconds after access is
// given back (the second live round, 2026-11-14, stage restored: an insert 403 and one list by iCalUID 404 while the
// other lists answered writer). From real Google: every DELETE on a calendar no longer shared answers 404 with the event
// still there (rows D1 to D4). The plan's promise: "Where Better-Cal can't be sure, it refuses with a sentence. It does
// not guess." Nothing is computed from server/src. Invented data.

use BetterCal\Domain\GoogleSeries;
use BetterCal\Infra\Db;

require_once __DIR__ . '/access-read-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $single = ['title' => 'Sample single', 'start' => '2027-03-02T09:00:00-08:00', 'end' => '2027-03-02T10:00:00-08:00', 'tzid' => $la];
    $weekly = ['title' => 'Sample series', 'start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
    $make = static function (array $event) use ($db): object {
        $s = PA::make($db);
        $s->id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId] + $event)['eventId'];
        $s->gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$s->id]);
        $s->events->patch($s->u, $s->id, ['tagNames' => ['sample-tag']] + (isset($event['rrule']) ? ['scope' => 'all'] : []));
        return $s;
    };
    $elsewhere = static function (object $s, string $method, string $path, ?array $body = null): int {
        return (int) ($s->fake->transport())($method, 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(PA::ACCOUNT) . $path, ['Authorization: Bearer any', 'X-Harness: remote'], $body)['status'];
    };
    $state = static fn(object $s): array => PA::state($db, $s->u, $s->calendarId, $s->fake);
    // What the series is, here and at Google, in the fields a person sees: each row's title and start, each record's.
    $series = static function (object $s) use ($db): array {
        $here = $db->all('SELECT title, start_utc, recurrence_instance_utc FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY start_utc, id', [$s->calendarId]);
        $there = [];
        foreach ($s->fake->events as $id => $e) {
            if (($e['status'] ?? '') !== 'cancelled') {
                $there[$id] = [$e['summary'] ?? null, $e['start']['dateTime'] ?? ($e['start']['date'] ?? null)];
            }
        }
        ksort($there);
        return [$here, $there];
    };

    // ---- Paid only on a refusal, and a true refusal still reads true -------------------------------------------------

    scenario('access read 2, refused write: an event changed at Google between an edit\'s read and its write is still "changed at Google", and one deleted there is still deleted, each after one small list; a write Google takes asks none', function () use ($db, $make, $single, $elsewhere, $state): void {
        // Changed at Google between the read and the write: Google answers the write 412.
        $s = $make($single);
        $s->hook = static function (int $n, string $method, string $url) use ($s, $elsewhere): void {
            if ($method === 'PATCH') {
                $s->hook = null;
                $elsewhere($s, 'PATCH', '/events/' . rawurlencode($s->gid), ['summary' => 'Sample single, renamed at Google']);
            }
        };
        $before = $state($s)['rows'];
        $from = count($s->fake->requests);
        $e = PA::caught(fn() => $s->events->patch($s->u, $s->id, ['title' => 'Sample single, renamed here']));
        $sent = array_values(array_filter(PA::sent($s->fake, $from), static fn(string $r): bool => true));
        checkEq('changed at Google between read and write: refused as changed at Google, the row as it was; the small list asked once, after the refused write', ['google_conflict', $before, 1, 'Sample single, renamed at Google'],
            [PA::code($e), $state($s)['rows'], count(array_filter($sent, static fn(string $r): bool => $r === 'GET small')), $s->fake->events[$s->gid]['summary'] ?? null]);
        check('its sentence says it changed at Google', str_contains((string) $e?->getMessage(), 'changed in Google Calendar'), (string) $e?->getMessage());

        // Deleted at Google between the read and the write of an edit: refused as deleted.
        $s = $make($single);
        $s->hook = static function (int $n, string $method, string $url) use ($s, $elsewhere): void {
            if ($method === 'PATCH') {
                $s->hook = null;
                $elsewhere($s, 'DELETE', '/events/' . rawurlencode($s->gid));
            }
        };
        $e = PA::caught(fn() => $s->events->patch($s->u, $s->id, ['title' => 'Sample single, renamed here']));
        check('deleted at Google between read and write, an edit: refused, and not as read-only (the calendar still takes changes)', $e !== null && !in_array(PA::code($e), ['google_read_only', 'google_not_shown'], true), (string) PA::code($e) . ': ' . $e?->getMessage());
        checkEq('the level stored is still the one Google gives, no access item opened', ['owner', []], [PA::storedRole($db, $s->calendarId), PA::items($db, $s->u, $s->calendarId)]);

        // A write Google takes asks no small list.
        $s = $make($single);
        $from = count($s->fake->requests);
        $s->events->patch($s->u, $s->id, ['title' => 'Sample single, renamed']);
        $s->events->deleteEvent($s->u, $s->id, null, null);
        checkEq('an edit and a delete Google takes: no small list', 0, count(array_filter(PA::sent($s->fake, $from), static fn(string $r): bool => $r === 'GET small')));
    });

    // ---- The small list itself fails ------------------------------------------------------------------------------------

    scenario('access read 2, refused write: sharing removed between a delete\'s read and its write, and the small list that would say so can\'t be read: the delete is not completed on a guess', function () use ($db, $make, $single, $state): void {
        foreach (['the network drops' => 'throw', 'Google is busy (429, three times)' => 'busy'] as $what => $how) {
            $s = $make($single);
            $before = $state($s);
            $phase = 0;
            $s->hook = static function (int $n, string $method, string $url) use ($s, &$phase, $how): void {
                if ($phase === 0 && $method === 'DELETE') {
                    $phase = 1;
                    $s->fake->role = 'none'; // the DELETE answers 404, the event still there (round 2, rows D1 to D4)
                } elseif ($phase === 1 && $method === 'GET') {
                    if ($how === 'throw') {
                        throw new \RuntimeException('HTTP error for the stand-in: network down');
                    }
                    $s->fake->busyFor = 3;
                }
            };
            if ($how === 'busy' && !property_exists($s->fake, 'busyFor')) {
                continue; // the stand-in has no such knob: only the dropped network is tried
            }
            $e = PA::caught(fn() => $s->events->deleteEvent($s->u, $s->id, null, null));
            $s->hook = null;
            checkEq("$what: Google still holds the event, so it is still here, with its tag (it can't be sure, so it refuses)",
                [true, $before['rows'], $before['tags']], [($s->fake->events[$s->gid]['status'] ?? '') !== 'cancelled', $state($s)['rows'], $state($s)['tags']]);
        }
    });

    // ---- A change stopped part way by the level, then the level given back ------------------------------------------

    foreach (['reader', 'freeBusyReader', 'none'] as $level) {
        foreach (['putBack', 'finish'] as $how) {
            scenario("access read 2, refused write: a change to all events stopped by $level after its first write, then access given back: " . ($how === 'putBack' ? 'Put back leaves the series, here and at Google, as it was before the change' : 'Finish completes it, here as at Google'), function () use ($db, $make, $weekly, $series, $level, $how): void {
                $s = $make($weekly);
                $s->events->patch($s->u, $s->id, ['scope' => 'this', 'instanceStart' => '2027-03-08T10:00:00-08:00', 'title' => 'Sample series (own)']);
                $s->events->throwDeferred();
                $before = $series($s);
                $writes = 0;
                $s->hook = static function (int $n, string $method, string $url) use ($s, &$writes, $level): void {
                    if ($method !== 'GET' && ++$writes === 2) {
                        $s->fake->role = $level;
                    }
                };
                $e = PA::caught(function () use ($s): void {
                    planned($s->events, $s->u, $s->id, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
                    $s->events->throwDeferred();
                });
                $s->hook = null;
                $half = GoogleSeries::halfDoneOf($db, $s->u);
                check("$level: the change stopped half done (the probe's own step)", $e !== null && count($half) === 1, PA::code($e) . ' ' . count($half));
                $s->fake->role = 'writer';
                $r = PA::caught(function () use ($s, $half, $how): void {
                    $how === 'finish' ? $s->events->googleFinish($s->u, (int) ($half[0]['id'] ?? 0)) : $s->events->googlePutBack($s->u, (int) ($half[0]['id'] ?? 0));
                    $s->events->throwDeferred();
                });
                checkEq("$level, given back, $how: goes through, nothing left half done, the level stored writer", [null, 0, 'writer'],
                    [$r === null ? null : PA::code($r) . ': ' . $r->getMessage(), count(GoogleSeries::halfDoneOf($db, $s->u)), PA::storedRole($db, $s->calendarId)]);
                [$here, $there] = $series($s);
                if ($how === 'putBack') {
                    checkEq("$level, given back, Put back: every row's title and start here, and every record's at Google, as before the change", $before, [$here, $there]);
                } else {
                    // The change was "an hour later for all events": every day of the series is at 11:00 Los Angeles, and the
                    // edited day keeps its own title.
                    $times = array_values(array_unique(array_map(static fn(array $r): string => (new DateTimeImmutable($r['start_utc'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('H:i'), $here)));
                    $thereTimes = array_values(array_unique(array_map(static fn(array $g): string => (new DateTimeImmutable((string) $g[1]))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('H:i'), $there)));
                    checkEq("$level, given back, Finish: every row here and every record at Google is at 11:00, and the edited day keeps its title in both", [['11:00'], ['11:00'], true, true],
                        [$times, $thereTimes, in_array('Sample series (own)', array_column($here, 'title'), true), in_array('Sample series (own)', array_column($there, 0), true)]);
                }
                checkEq("$level, given back, $how: the series' tag is still on it", 1, (int) $db->scalar('SELECT COUNT(*) FROM event_tags et JOIN events e ON e.id = et.event_id WHERE e.calendar_id = ? AND e.recurrence_parent_id IS NULL AND e.deleted_at IS NULL', [$s->calendarId]));
            });
        }
    }

    // Rule D: "Whether anything was changed is known from what the change sent." The level drops between a change to all
    // events' read and its first write: Google refuses that write (403, 404), takes nothing, and the small list says why.
    // Nothing reached Google, so nothing is half done and no sentence says part of it reached Google.
    foreach (['reader', 'freeBusyReader', 'none'] as $level) {
        scenario("access read 2, refused write: a change to all events whose first write is refused because the level dropped to $level: nothing reached Google, so nothing is listed half done and the event is not frozen", function () use ($db, $make, $weekly, $state, $level): void {
            $s = $make($weekly);
            $s->events->patch($s->u, $s->id, ['scope' => 'this', 'instanceStart' => '2027-03-08T10:00:00-08:00', 'title' => 'Sample series (own)']);
            $s->events->throwDeferred();
            $before = $state($s);
            $writes = 0;
            $s->hook = static function (int $n, string $method, string $url) use ($s, &$writes, $level): void {
                if ($method !== 'GET' && ++$writes === 1) {
                    $s->fake->role = $level;
                }
            };
            $from = count($s->fake->requests);
            $e = PA::caught(function () use ($s): void {
                planned($s->events, $s->u, $s->id, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
                $s->events->throwDeferred();
            });
            $s->hook = null;
            checkEq("$level before the first write: refused, Google took nothing, and the stand-in holds what it held", [true, [], $before['google']], [$e !== null, PA::taken($s->fake, $from), $state($s)['google']]);
            checkEq("$level before the first write: no change is listed half done", 0, count(GoogleSeries::halfDoneOf($db, $s->u)));
            check("$level before the first write: the sentence does not say part of the change reached Google", !str_contains((string) $e?->getMessage(), 'reached Google'), (string) $e?->getMessage());
        });
    }

    // ---- The seconds after access is given back: one answer says otherwise --------------------------------------------

    scenario('access read 2, the seconds after access is given back: an insert refused 403 while the lists say writer is said as Google refusing that change, and nothing is stored or announced', function () use ($db, $make, $single): void {
        $s = $make($single);
        $s->hook = static function (int $n, string $method, string $url) use ($s): void {
            $s->fake->role = $method === 'POST' ? 'reader' : 'writer'; // the insert answers 403 requiredAccessLevel (round 2, restored, row 5); lists say writer
        };
        $from = count($s->fake->requests);
        $e = PA::caught(fn() => $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample new', 'start' => '2027-03-06T09:00:00-08:00', 'end' => '2027-03-06T10:00:00-08:00', 'tzid' => 'America/Los_Angeles']));
        $s->hook = null;
        checkEq('refused, one small list after the insert, the level stored writer, no access item', ['google_write_failed', ['POST list', 'GET small'], 'writer', []],
            [PA::code($e), PA::sent($s->fake, $from), PA::storedRole($db, $s->calendarId), PA::items($db, $s->u, $s->calendarId)]);
        check('its sentence says to try again in a moment', str_contains((string) $e?->getMessage(), 'try again in a moment'), (string) $e?->getMessage());
    });

    scenario('access read 2, the seconds after access is given back: a change whose own list said writer and whose write Google took, then one list answers 404: the calendar is not announced as no longer shown', function () use ($db, $make, $weekly, $state): void {
        $s = $make($weekly);
        ($s->poll)();
        // A day's edit sends the series' list, the day by id, the PATCH, then the series' list again: that last one answers 404.
        $writes = 0;
        $s->hook = static function (int $n, string $method, string $url) use ($s, &$writes): void {
            if ($method !== 'GET') {
                $writes++;
            } elseif ($writes === 1) {
                $s->fake->role = 'none';
            }
        };
        $e = PA::caught(function () use ($s): void {
            $s->events->patch($s->u, $s->id, ['scope' => 'this', 'instanceStart' => '2027-03-08T10:00:00-08:00', 'title' => 'Sample series (own)']);
            $s->events->throwDeferred();
        });
        $s->hook = null;
        $s->fake->role = 'writer'; // every later answer says writer again
        $told = [PA::storedRole($db, $s->calendarId), PA::items($db, $s->u, $s->calendarId), PA::lines($db, $s->u, $s->calendarId)];
        checkEq('the edit went through', null, PA::code($e));
        // What the person sees meanwhile, and how it corrects: said here whatever the check below finds.
        $cal = $s->d->calendars->serializeById($s->u, $s->calendarId);
        ($s->poll)();
        $after = [PA::storedRole($db, $s->calendarId), PA::items($db, $s->u, $s->calendarId), $s->d->calendars->serializeById($s->u, $s->calendarId)['editable'] ?? null];
        checkEq('the next poll (or Check again) stores writer, ends any notice and the calendar is editable', ['writer', [], true], $after);
        // Known, not built (the brief of the second read: N2, the model's section 8): for those seconds the last list
        // answer of the request is stored, so the calendar is said not shown until the next poll or Check again, which
        // the check above shows. The one live 404 of this kind (round 2, stage restored) is an open doubt the model names.
        checkEq('meanwhile (known, seconds wide): the last answer, not shown, is stored and said once, until the next poll corrects it',
            ['none', ['google_not_shown'], 1, false], [$told[0], $told[1], count($told[2]), $cal['editable'] ?? null]);
    });
};
