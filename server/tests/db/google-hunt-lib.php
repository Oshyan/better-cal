<?php

declare(strict_types=1);

// Helpers for the db tests that began as the probes of the hunt of 20f77fe9 (the app's own changes to a series on a
// Google calendar): google-link-unproven, google-finish-first-write, google-finish-drag.
// Nothing here computes an expectation from server/src: the two views below are read from the stand-in's own
// listing and from the app's window, and each probe states its expected outcome by calendar date. Invented data.

use BetterCal\Domain\ReviewQueue;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

final class H
{
    /** A series on the stand-in's calendar, whole-series undo off (the 1.5.5 default). */
    public static function make(Db $db, array $create, array $stand = []): object
    {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        foreach ($stand as $k => $v) {
            $s->fake->$k = $v;
        }
        $e = $s->events;
        $id = (int) $e->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample series'] + $create)['eventId'];
        $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
        return (object) ['db' => $db, 'u' => $u, 'd' => $d, 's' => $s, 'e' => $e, 'id' => $id, 'gid' => $gid];
    }

    public static function done(object $t): void
    {
        GoogleStand::forget($t->db, $t->u);
    }

    /** A "this event" edit. */
    public static function this(object $t, string $inst, array $fields, ?int $id = null): void
    {
        $t->e->patch($t->u, $id ?? $t->id, ['scope' => 'this', 'instanceStart' => $inst] + $fields);
        $t->e->throwDeferred();
    }

    public static function skip(object $t, string $inst, ?int $id = null): void
    {
        $t->e->deleteEvent($t->u, $id ?? $t->id, 'this', $inst);
        $t->e->throwDeferred();
    }

    /** A series change through the plan, the preselected options unless $choices names others. Returns the plan. */
    public static function change(object $t, array $body, array $choices = [], ?int $id = null): array
    {
        $plan = planned($t->e, $t->u, $id ?? $t->id, $body, $choices);
        $t->e->throwDeferred();
        return $plan;
    }

    /** The same, catching what stops it: [plan or null, error text or null]. */
    public static function tryChange(object $t, array $body, array $choices = [], ?int $id = null): array
    {
        try {
            return [self::change($t, $body, $choices, $id), null];
        } catch (HttpError $e) {
            return [null, $e->status . ' ' . $e->errorCode . ': ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return [null, get_class($e) . ': ' . $e->getMessage()];
        }
    }

    /** A request to the stand-in as another client makes it (no If-Match). */
    public static function atGoogle(object $t, string $method, string $path, ?array $body = null): array
    {
        $answer = $t->s->fake->transport()($method, 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . $path,
            ['Authorization: Bearer ' . ($t->s->fake->token ?? 'any'), 'X-Harness: remote'], $body);
        return [(int) $answer['status'], json_decode((string) $answer['body'], true)];
    }

    /**
     * What Google shows, by local date in $zone: every live instance of every live series and every live single event,
     * as "date time|title|notes|place" (time 'all-day' for a date). Each series' occurrences are the stand-in's own
     * (its expander), each read by its id as a GET answers it: a record laid over its occurrence, a cancelled record
     * no occurrence, and a dropped record (cancelled, no originalStartTime) left under an id that names an occurrence
     * again is that occurrence, as the probe found a skipped day comes back when its cancelled record goes. The
     * stand-in's singleEvents listing is not used: it hides an occurrence behind a dropped record of the same id,
     * which a GET of that id does not, and the app never asks for that listing.
     *
     * @return list<string>
     */
    public static function googleView(object $t, string $zone): array
    {
        $z = new DateTimeZone($zone);
        $out = [];
        $line = static function (array $i) use ($z): string {
            $when = isset($i['start']['date']) ? $i['start']['date'] . ' all-day' : (new DateTimeImmutable((string) $i['start']['dateTime']))->setTimezone($z)->format('Y-m-d H:i');
            return $when . '|' . ($i['summary'] ?? '') . '|' . ($i['description'] ?? '') . '|' . ($i['location'] ?? '');
        };
        foreach ($t->s->fake->events as $id => $e) {
            if (($e['status'] ?? '') === 'cancelled' || isset($e['recurringEventId']) || isset($e['originalStartTime'])) {
                continue;
            }
            if (empty($e['recurrence'])) {
                if (preg_match('/_\d{8}(T\d{6}Z)?$/', (string) $id) !== 1) {
                    $out[] = $line($e);
                }
                continue;
            }
            $allDay = isset($e['start']['date']);
            $skipped = [];
            foreach ((array) $e['recurrence'] as $rl) {
                if (str_starts_with((string) $rl, 'EXDATE')) {
                    foreach (explode(',', substr((string) $rl, (int) strpos((string) $rl, ':') + 1)) as $v) {
                        $skipped[substr(trim($v), 0, 8)] = true; // by date: the probes' series have one occurrence a day
                    }
                }
            }
            foreach (GoogleStand::expand($e) as $s) {
                $key = $allDay ? $s->format('Ymd') : $s->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
                [$status, $inst] = self::atGoogle($t, 'GET', '/events/' . rawurlencode($id . '_' . $key));
                if ($status !== 200 || ($inst['status'] ?? '') === 'cancelled' || isset($skipped[substr($key, 0, 8)])) {
                    continue;
                }
                $out[] = $line($inst);
            }
        }
        sort($out);
        return $out;
    }

    /**
     * What the calendar here shows, in the same shape, from the rows by the app's own window for the span (dates),
     * the notes read from the row the window names. Tags are appended as "#tag" when $tags.
     *
     * @return list<string>
     */
    public static function hereView(object $t, string $zone, string $from, string $to, bool $tags = false): array
    {
        $utc = new DateTimeZone('UTC');
        $z = new DateTimeZone($zone);
        $window = $t->e->window($t->u, new DateTimeImmutable($from . ' 00:00:00', $utc), new DateTimeImmutable($to . ' 00:00:00', $utc), [$t->s->calendarId], null, true);
        $out = [];
        foreach ($window as $x) {
            $allDay = !empty($x['allDay']);
            $when = $allDay ? substr((string) $x['start'], 0, 10) . ' all-day' : (new DateTimeImmutable((string) $x['start']))->setTimezone($z)->format('Y-m-d H:i');
            $notes = '';
            $rid = isset($x['eventId']) ? (int) $x['eventId'] : null;
            if ($rid !== null) {
                $notes = (string) ($t->db->scalar('SELECT description FROM events WHERE id = ?', [$rid]) ?? '');
            }
            $line = $when . '|' . ($x['title'] ?? '') . '|' . $notes . '|' . ($x['location'] ?? '');
            if ($tags && $rid !== null) {
                $names = array_column($t->db->all('SELECT tg.name FROM event_tags et JOIN tags tg ON tg.id = et.tag_id WHERE et.event_id = ? ORDER BY tg.name', [$rid]), 'name');
                $line .= $names === [] ? '' : '|#' . implode('#', $names);
            }
            $out[] = $line;
        }
        sort($out);
        return $out;
    }

    /** The rows of the account's Google calendar, compact, for a failure's detail. @return list<string> */
    public static function rows(object $t): array
    {
        $out = [];
        foreach ($t->db->all('SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id', [$t->s->calendarId]) as $r) {
            $tags = array_column($t->db->all('SELECT tg.name FROM event_tags et JOIN tags tg ON tg.id = et.tag_id WHERE et.event_id = ? ORDER BY tg.name', [(int) $r['id']]), 'name');
            $out[] = '#' . $r['id'] . ($r['recurrence_parent_id'] !== null ? ' day of #' . $r['recurrence_parent_id'] . ' key ' . $r['recurrence_instance_utc'] : ' ' . ($r['rrule'] ?? 'single'))
                . ' | ' . $r['start_utc'] . ' ' . ((int) $r['all_day'] === 1 ? 'all-day' : $r['tzid']) . ' | ' . $r['title'] . ' | ' . ($r['description'] ?? '') . ' | ' . ($r['location'] ?? '')
                . ' | own ' . ($r['override_fields'] ?? 'null') . ' | gid ' . ($r['google_event_id'] ?? 'null') . ($tags === [] ? '' : ' | #' . implode('#', $tags))
                . ($r['exdates_json'] ? ' | skips ' . $r['exdates_json'] : '');
        }
        return $out;
    }

    /** What the stand-in stores, compact: masters with their rule, and every record under them. @return list<string> */
    public static function stored(object $t): array
    {
        $out = [];
        foreach ($t->s->fake->events as $id => $e) {
            $when = $e['start']['date'] ?? $e['start']['dateTime'] ?? '?';
            $ost = isset($e['originalStartTime']) ? ' ost ' . ($e['originalStartTime']['date'] ?? $e['originalStartTime']['dateTime']) : '';
            $out[] = $id . ' | ' . ($e['status'] ?? '') . ' | ' . $when . ($e['start']['timeZone'] ?? '') . $ost . ' | ' . ($e['summary'] ?? '') . ' | ' . ($e['description'] ?? '') . ' | ' . ($e['location'] ?? '')
                . (isset($e['recurrence']) ? ' | ' . implode(' ', (array) $e['recurrence']) : '');
        }
        return $out;
    }

    /** The app's write requests since $from (an index into the stand-in's log), as "METHOD id body". @return list<string> */
    public static function writes(object $t, int $from = 0): array
    {
        $out = [];
        foreach (array_slice($t->s->fake->requests, $from) as $r) {
            if ($r['method'] === 'GET' || in_array('X-Harness: remote', $r['headers'], true)) {
                continue;
            }
            $out[] = $r['method'] . ' ' . rawurldecode(basename($r['path'])) . ' ' . json_encode($r['body'], JSON_UNESCAPED_SLASHES);
        }
        return $out;
    }

    /** The half-done changes Review lists. */
    public static function halfDone(object $t): array
    {
        return (new ReviewQueue($t->db, $t->e))->googleHalfDone($t->u);
    }

    /** Finish or Put back of the one half-done change: [answer or null, error text or null]. */
    public static function recover(object $t, string $how): array
    {
        $listed = self::halfDone($t);
        if (count($listed) !== 1) {
            return [null, 'Review lists ' . count($listed) . ' half-done changes'];
        }
        try {
            $out = $how === 'finish' ? $t->e->googleFinish($t->u, (int) $listed[0]['id']) : $t->e->googlePutBack($t->u, (int) $listed[0]['id']);
            $t->e->throwDeferred();
            return [$out, null];
        } catch (HttpError $e) {
            return [null, $e->status . ' ' . $e->errorCode . ': ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return [null, get_class($e) . ': ' . $e->getMessage()];
        }
    }

    /** A poll, and whether it changed any row of the calendar (the rows compared before and after, bookkeeping columns aside). */
    public static function pollChanges(object $t): array
    {
        $read = static fn(object $t): array => array_map(static fn(array $r): array => array_diff_key($r, array_flip(['updated_at', 'google_etag', 'created_at'])),
            $t->db->all('SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id', [$t->s->calendarId]));
        $before = $read($t);
        $t->s->poll();
        $after = $read($t);
        $diff = [];
        $byId = static function (array $rows): array {
            $o = [];
            foreach ($rows as $r) {
                $o[(int) $r['id']] = $r;
            }
            return $o;
        };
        $b = $byId($before);
        $a = $byId($after);
        foreach (array_unique(array_merge(array_keys($b), array_keys($a))) as $id) {
            if (!isset($a[$id])) {
                $diff[] = "row #$id removed";
            } elseif (!isset($b[$id])) {
                $diff[] = "row #$id added";
            } elseif ($a[$id] !== $b[$id]) {
                $cols = [];
                foreach ($a[$id] as $c => $v) {
                    if ($v !== $b[$id][$c]) {
                        $cols[] = $c . ': ' . json_encode($b[$id][$c]) . ' -> ' . json_encode($v);
                    }
                }
                $diff[] = "row #$id changed (" . implode('; ', $cols) . ')';
            }
        }
        return $diff;
    }

    /**
     * The checks every probe ends with, none read from server/src's own reasoning: the calendar here shows what Google
     * lists, day for day ($zone, the span); every stored id is one Google answers for its day; a poll changes no row;
     * and the two still agree after it. Returns the view here (with tags) for the probe's own expectations by date.
     *
     * @return list<string>
     */
    public static function settled(object $t, string $name, string $zone, string $from, string $to): array
    {
        $here = self::hereView($t, $zone, $from, $to);
        $there = self::googleView($t, $zone);
        check("$name: the calendar here shows what Google lists, day for day", $here === $there,
            "\n   here:   " . implode("\n           ", array_diff($here, $there)) . "\n   google: " . implode("\n           ", array_diff($there, $here)));
        checkEq("$name: every stored Google id is one Google answers for its day", [], self::idProblems($t));
        checkEq("$name: a poll changes no row", [], self::pollChanges($t));
        $here2 = self::hereView($t, $zone, $from, $to);
        check("$name: after the poll the calendar still shows what Google lists", $here2 === self::googleView($t, $zone), json_encode(array_values(array_diff($here2, self::googleView($t, $zone)))));
        return self::hereView($t, $zone, $from, $to, true);
    }

    /**
     * One change, uninterrupted and then stopped at each of its writes to Google in each way the stand-in can stop one
     * (refused before it is taken, taken with its answer lost, the network down as it is sent), each followed by
     * Finish and, apart, by Put back. The expectations are the contract's: the uninterrupted change leaves $expect
     * (the calendar by date, tags included; null prints it instead); Finish, with nothing changed meanwhile, leaves
     * what the uninterrupted change leaves; Put back leaves the calendar and Google as they were, or is refused with
     * nothing changed (the accepted cost of a predicted drop), after which Finish must complete.
     *
     * @param callable(): object $build the series with its days, ready for the change
     * @param callable(object): void $act the change
     * @param ?list<string> $expect
     */
    public static function matrix(string $name, callable $build, callable $act, string $zone, string $from, string $to, ?array $expect, bool $recover = true, array $kinds = ['beforeAt', 'loseAt', 'netAt']): void
    {
        $t = $build();
        $before = self::hereView($t, $zone, $from, $to, true);
        $beforeGoogle = self::googleView($t, $zone);
        $base = count($t->s->fake->requests);
        $t->s->knobs->writes = 0;
        $error = null;
        try {
            $act($t);
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
        }
        check("$name: the change goes through", $error === null, (string) $error);
        $writes = self::writes($t, $base);
        $after = self::settled($t, $name, $zone, $from, $to);
        if ($expect === null) {
            echo "  [$name] before:\n    " . implode("\n    ", $before) . "\n  after:\n    " . implode("\n    ", $after) . "\n  writes:\n    " . implode("\n    ", $writes) . "\n";
        } else {
            check("$name: the calendar shows what the contract says, by date", $after === $expect,
                "\n   missing: " . implode("\n            ", array_diff($expect, $after)) . "\n   extra:   " . implode("\n            ", array_diff($after, $expect)) . "\n   rows:\n     " . implode("\n     ", self::rows($t)) . "\n   google:\n     " . implode("\n     ", self::stored($t)) . "\n   writes:\n     " . implode("\n     ", $writes));
        }
        if ($error === null && getenv('HUNT_FOLLOW') === '1') {
            self::followUps($t, $name, $zone, $from, $to);
        }
        self::done($t);
        if (!$recover || $error !== null) {
            return;
        }
        $want = $expect ?? $after;
        for ($k = 1; $k <= count($writes); $k++) {
            foreach ($kinds as $kind) {
                foreach (['finish', 'putback'] as $how) {
                    $label = "$name, stopped at write $k ($kind), then " . ($how === 'finish' ? 'Finish' : 'Put back');
                    scenario($label, function () use ($label, $build, $act, $zone, $from, $to, $want, $before, $beforeGoogle, $k, $kind, $how): void {
                        $t = $build();
                        try {
                            $t->s->knobs->writes = 0;
                            $t->s->knobs->$kind = $k;
                            $stopped = null;
                            try {
                                $act($t);
                            } catch (\Throwable $e) {
                                $stopped = get_class($e) . ': ' . $e->getMessage();
                            }
                            $t->s->knobs->beforeAt = $t->s->knobs->loseAt = $t->s->knobs->netAt = null;
                            $t->s->knobs->down = false;
                            if ($stopped === null) {
                                check("$label: the stop fired", false, 'the change went through');
                                return;
                            }
                            if (self::halfDone($t) === []) {
                                // Google took nothing: nothing recorded, the calendar as it was.
                                checkEq("$label: nothing reached Google, so the calendar is as it was", $before, self::hereView($t, $zone, $from, $to, true));
                                checkEq("$label: nothing reached Google, so Google is as it was", $beforeGoogle, self::googleView($t, $zone));
                                return;
                            }
                            [$out, $refused] = self::recover($t, $how);
                            if ($how === 'putback' && $refused !== null) {
                                echo "    note: $label: refused ($refused)\n";
                                check("$label: a refused Put back leaves the change in Review", count(self::halfDone($t)) === 1, json_encode(self::halfDone($t)));
                                [$out, $refused] = self::recover($t, 'finish');
                                $how = 'finish';
                            }
                            check("$label: it completes", $refused === null, (string) $refused);
                            if ($refused !== null) {
                                return;
                            }
                            checkEq("$label: Review lists nothing half done after it", [], self::halfDone($t));
                            $now = self::settled($t, $label, $zone, $from, $to);
                            $target = $how === 'finish' ? $want : $before;
                            check("$label: the calendar is " . ($how === 'finish' ? 'as the uninterrupted change leaves it' : 'as it was before the change') . ', by date', $now === $target,
                                "\n   missing: " . implode("\n            ", array_diff($target, $now)) . "\n   extra:   " . implode("\n            ", array_diff($now, $target))
                                . "\n   differences named: " . json_encode($out['differences'] ?? null) . "\n   rows:\n     " . implode("\n     ", self::rows($t)) . "\n   google:\n     " . implode("\n     ", self::stored($t)));
                            if ($how === 'putback') {
                                checkEq("$label: Google is as it was before the change", $beforeGoogle, self::googleView($t, $zone));
                            }
                            if (getenv('HUNT_FOLLOW') === '1') {
                                self::followUps($t, $label, $zone, $from, $to);
                            }
                        } finally {
                            self::done($t);
                        }
                    });
                }
            }
        }
    }

    /**
     * "This event" changes right after a series change or a recovery: one edited day, one day with a tag, and one
     * ordinary day renamed, and another ordinary day deleted, each named by what the window shows for it. The
     * expectation is the calendar as it was with exactly those lines renamed or gone, by date.
     */
    public static function followUps(object $t, string $name, string $zone, string $from, string $to): void
    {
        $utc = new DateTimeZone('UTC');
        $z = new DateTimeZone($zone);
        $window = $t->e->window($t->u, new DateTimeImmutable($from . ' 00:00:00', $utc), new DateTimeImmutable($to . ' 00:00:00', $utc), [$t->s->calendarId], null, true);
        usort($window, static fn(array $a, array $b): int => strcmp((string) $a['start'], (string) $b['start']));
        $lineOf = function (array $x) use ($t, $z): string {
            $when = !empty($x['allDay']) ? substr((string) $x['start'], 0, 10) . ' all-day' : (new DateTimeImmutable((string) $x['start']))->setTimezone($z)->format('Y-m-d H:i');
            $rid = (int) $x['eventId'];
            $notes = (string) ($t->db->scalar('SELECT description FROM events WHERE id = ?', [$rid]) ?? '');
            $names = array_column($t->db->all('SELECT tg.name FROM event_tags et JOIN tags tg ON tg.id = et.tag_id WHERE et.event_id = ? ORDER BY tg.name', [$rid]), 'name');
            return $when . '|' . ($x['title'] ?? '') . '|' . $notes . '|' . ($x['location'] ?? '') . ($names === [] ? '' : '|#' . implode('#', $names));
        };
        $picks = ['edited' => null, 'tagged' => null, 'ordinary' => null, 'deleted' => null];
        foreach ($window as $x) {
            if (empty($x['recurring'])) {
                continue;
            }
            $line = $lineOf($x);
            $kind = !empty($x['isException']) ? (str_contains($line, '|#') && ($x['ownFields'] ?? []) === ['tags'] ? 'tagged' : 'edited') : 'ordinary';
            if ($kind === 'ordinary' && $picks['ordinary'] !== null) {
                $kind = 'deleted';
            }
            $picks[$kind] ??= [$x, $line];
        }
        $before = self::hereView($t, $zone, $from, $to, true);
        $expect = $before;
        foreach ($picks as $kind => $pick) {
            if ($pick === null) {
                continue;
            }
            [$x, $line] = $pick;
            $master = (int) ($x['seriesId'] ?? $x['eventId']);
            $inst = (string) ($x['seriesKey'] ?? $x['start']);
            if (!isset($x['seriesKey']) && !empty($x['allDay'])) {
                $inst = substr($inst, 0, 10);
            }
            $at = array_search($line, $expect, true);
            $error = null;
            try {
                if ($kind === 'deleted') {
                    self::skip($t, $inst, $master);
                    if ($at !== false) {
                        unset($expect[$at]);
                    }
                } else {
                    self::this($t, $inst, ['title' => "Renamed $kind day"], $master);
                    if ($at !== false) {
                        $parts = explode('|', $line);
                        $parts[1] = "Renamed $kind day";
                        $expect[$at] = implode('|', $parts);
                    }
                }
            } catch (\Throwable $e) {
                $error = ($e instanceof HttpError ? $e->errorCode . ': ' : get_class($e) . ': ') . $e->getMessage();
            }
            check("$name: then the $kind day ($line) " . ($kind === 'deleted' ? 'can be deleted' : 'can be renamed') . ' with "this event"', $error === null, (string) $error);
            if ($error !== null && $at !== false) {
                $expect[$at] = $line;
            }
        }
        $expect = array_values($expect);
        sort($expect);
        $now = self::settled($t, "$name, after the this-event changes", $zone, $from, $to);
        check("$name: after the this-event changes the calendar is as it was with exactly those days renamed or deleted, by date", $now === $expect,
            "\n   missing: " . implode("\n            ", array_diff($expect, $now)) . "\n   extra:   " . implode("\n            ", array_diff($now, $expect)));
    }

    /** I3 by the harness's own id function: every stored id is one Google answers for that day. @return list<string> */
    public static function idProblems(object $t): array
    {
        $out = [];
        foreach ($t->db->all('SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL AND rrule IS NOT NULL', [$t->s->calendarId]) as $master) {
            $rows = $t->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [(int) $master['id']]);
            foreach ($t->s->idProblems($master, $rows) as $p) {
                $out[] = $p['problem'] . ' ' . json_encode(array_diff_key($p, ['problem' => 1]));
            }
        }
        return $out;
    }
}
