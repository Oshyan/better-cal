<?php

declare(strict_types=1);

// Every change to what undo knows (the tables an undo restores) is in the
// journal, and the journal can put it back. For each operation below:
// every row it made, removed or changed, and each column it changed,
// appears in what its entries touched (Undo's touches), so a writer that
// forgets fails here rather than an undo later silently overwriting or
// deleting newer work; and, for a change that undoes, each of its entries
// is undone and every row compared with how it was before, so a writer
// whose snapshots are wrong, or who records nothing where it should, is
// caught by the rows and not only by the touches. A writer that is log-only
// on purpose (a feed's poll, a plugin's sync, an answer that went out) is
// checked to be so. Each runs as the request that makes it would, its
// credential registered (RequestAuthority::during), so a writer that writes
// outside the write boundary is a journaling misuse; a worker's runs as
// none ($workers).
// Each group of operations below is a scenario(): a throw fails that
// group, and the next still runs.

use BetterCal\Domain\CalendarImport;
use BetterCal\Domain\Duplicates;
use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\Filters;
use BetterCal\Domain\Ics;
use BetterCal\Domain\Labels;
use BetterCal\Domain\MailAdmission;
use BetterCal\Domain\MailIngest;
use BetterCal\Domain\People;
use BetterCal\Domain\Plugins;
use BetterCal\Domain\Proposals;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\RequestAuthority;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Domain\Rsvp;
use BetterCal\Domain\SavedViews;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Trips;
use BetterCal\Domain\Undo;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Infra\HttpClient;
use BetterCal\Infra\JobQueue;
use BetterCal\Plugin\PluginHost;
use BetterCal\Support\Time;

return static function (Db $db): void {
    // The tables undo restores, in the order it puts rows back (what a row
    // belongs to before the row), and each one's key.
    $tables = ['folders', 'tags', 'people', 'calendars', 'calendar_folders', 'events', 'event_links', 'event_tags',
        'event_people', 'out_feeds', 'filters', 'saved_views', 'availability', 'review_items'];
    $keys = ['calendar_folders' => ['calendar_id', 'folder_id'], 'event_tags' => ['event_id', 'tag_id'], 'event_people' => ['event_id', 'person_id']];
    // Every row of them: row key => row. Review's notices (a mail limit, a
    // model limit, an email that couldn't be read) are information the app
    // keeps for the person and never part of a snapshot; the decisions (an
    // invitation or a change held) are, so only those count here.
    $read = static function () use ($db, $tables): array {
        $out = [];
        foreach ($tables as $table) {
            $where = $table === 'review_items' ? " WHERE kind IN ('invite_new', 'invite_change')" : '';
            foreach ($db->all("SELECT * FROM `$table`" . $where) as $row) {
                $out[Undo::keyOf($table, $row)] = $row;
            }
        }
        return $out;
    };
    // The account settings an undo may put back (where new events go, the
    // senders approved with an add), by account.
    $readSettings = static fn(): array => array_column($db->all('SELECT id, settings_json FROM users'), 'settings_json', 'id');
    // What differs between two reads of a row, decided here rather than by
    // Undo's own helper (a mistake shared by both would pass): a column
    // whose text changed or that went to or from NULL, a calendar's settings
    // by key. Bookkeeping columns undo neither keeps nor restores, nor the
    // display preferences: Activity records what the account holds, not how
    // it is shown (docs/design/decisions.md), so whether a calendar shows
    // is no change to the journal.
    $bookkeeping = ['updated_at', 'synctoken', 'last_polled_at', 'last_poll_status', 'last_poll_error',
        'content_changed_at', 'last_ok_at', 'google_sync_token', 'score', 'scored_at'];
    $preferences = ['calendars' => ['visible']];
    $differs = static function (string $table, array $was, array $now) use ($bookkeeping, $preferences): array {
        $out = [];
        foreach (array_diff(array_keys($was + $now), $bookkeeping, $preferences[$table] ?? []) as $col) {
            [$a, $b] = [$was[$col] ?? null, $now[$col] ?? null];
            if ($a === null ? $b === null : ($b !== null && (string) $a === (string) $b)) {
                continue;
            }
            if ($table === 'calendars' && $col === 'settings_json') {
                [$x, $y] = [json_decode((string) $a, true) ?: [], json_decode((string) $b, true) ?: []];
                foreach (array_keys($x + $y) as $k) {
                    if (json_encode($x[$k] ?? null) !== json_encode($y[$k] ?? null) || array_key_exists($k, $x) !== array_key_exists($k, $y)) {
                        $out[] = "$col.$k";
                    }
                }
                continue;
            }
            $out[] = (string) $col;
        }
        return $out;
    };
    // What changed between two reads, as row key => ['*'] for a row made or
    // removed (which, as a word) or the columns that differ. A tag, or a
    // person named on an event, is a name: made the first time it's used
    // and left when that's undone (another event may have it by then), so
    // one made isn't a change ($vocabulary) unless the operation is making
    // the person itself.
    $changes = static function (array $before, array $after, bool $vocabulary) use ($differs): array {
        $out = [];
        foreach ($before + $after as $key => $_) {
            $was = $before[$key] ?? null;
            $now = $after[$key] ?? null;
            if ($was === null || $now === null) {
                if ($was === null && $vocabulary && (str_starts_with($key, 'tags:') || str_starts_with($key, 'people:'))) {
                    continue;
                }
                $out[$key] = $was === null ? ['*' => 'made'] : ['*' => 'removed'];
                continue;
            }
            $cols = $differs((string) strstr($key, ':', true), $was, $now);
            if ($cols !== []) {
                $out[$key] = array_fill_keys($cols, 'changed');
            }
        }
        return $out;
    };
    // What an operation changed but its entries don't say.
    $unjournaled = static function (int $since, array $before, array $after, bool $vocabulary) use ($db, $changes): array {
        $said = [];
        foreach ($db->all('SELECT row_key, col FROM mutation_touches WHERE mutation_id > ?', [$since]) as $t) {
            $col = (string) $t['col'];
            // A skipped date's own ground (Undo::SKIP), or a skip carried (MOVE), says its series' skips.
            $said[$t['row_key']][str_starts_with($col, BetterCal\Domain\Undo::SKIP) || str_starts_with($col, BetterCal\Domain\Undo::MOVE) ? 'exdates_json' : $col] = true;
            // A day's ground is on its series' row (Undo::DAY, MOVE): it says the row at that day, before or
            // after; the series made or removed whole ("*") says every day of it.
            $days = str_starts_with($col, BetterCal\Domain\Undo::DAY) ? [explode('|', substr($col, strlen(BetterCal\Domain\Undo::DAY)), 2)[0]]
                : (str_starts_with($col, BetterCal\Domain\Undo::MOVE) ? explode('>', substr($col, strlen(BetterCal\Domain\Undo::MOVE)), 2) : []);
            foreach ($before + $after as $key => $row) {
                if (str_starts_with($key, 'events:') && 'events:' . ($row['recurrence_parent_id'] ?? '') === (string) $t['row_key']
                    && ($col === '*' || in_array((string) ($row['recurrence_instance_utc'] ?? ''), $days, true))) {
                    $said[$key]['*'] = true;
                    // A day's tags or people (day:<key>|tags) say its link rows: a link of a day is that day's field alone.
                    $field = str_starts_with($col, BetterCal\Domain\Undo::DAY) ? (explode('|', $col, 2)[1] ?? '') : '';
                    $link = ['tags' => 'event_tags:', 'people' => 'event_people:'][$field] ?? null;
                    foreach ($link === null ? [] : array_keys($before + $after) as $k2) {
                        if (str_starts_with($k2, $link . ($row['id'] ?? '') . '|')) {
                            $said[$k2]['*'] = true;
                        }
                    }
                }
            }
        }
        $missing = [];
        foreach ($changes($before, $after, $vocabulary) as $key => $cols) {
            foreach ($cols as $col => $how) {
                if (!isset($said[$key]['*']) && !isset($said[$key][$col])) {
                    $missing[] = $col === '*' ? "$key $how" : "$key.$col";
                }
            }
        }
        return $missing;
    };
    // What's still different from before after an operation's entries
    // were undone.
    $left = static function (array $before, array $now, bool $vocabulary) use ($changes): array {
        $out = [];
        foreach ($changes($before, $now, $vocabulary) as $key => $cols) {
            foreach ($cols as $col => $how) {
                $out[] = $col === '*' ? ($how === 'made' ? "$key made and still there" : "$key removed and not back") : "$key.$col";
            }
        }
        return $out;
    };
    // Put the tables back as the operation left them, and its entries back
    // in force, so the next operation builds on it as the app would: rows
    // the undo brought back go, rows it removed come back, rows it changed
    // are as the operation left them.
    $redo = static function (array $after, array $settings, array $entryIds) use ($db, $tables, $keys, $read): void {
        $now = $read();
        $table = static fn(string $key): string => (string) strstr($key, ':', true);
        foreach (array_reverse($tables) as $t) {
            foreach ($now as $key => $row) {
                if ($table($key) === $t && !isset($after[$key])) {
                    $pk = $keys[$t] ?? ['id'];
                    $db->run("DELETE FROM `$t` WHERE " . implode(' AND ', array_map(static fn($c) => "`$c` = ?", $pk)), array_map(static fn($c) => $row[$c], $pk));
                }
            }
        }
        foreach ($tables as $t) {
            $rows = array_filter($after, static fn(string $key): bool => $table($key) === $t, ARRAY_FILTER_USE_KEY);
            if ($t === 'events') {
                // A date of a series refers to its series: the series first.
                uasort($rows, static fn(array $a, array $b): int => [(int) ($a['recurrence_parent_id'] !== null), (int) $a['id']] <=> [(int) ($b['recurrence_parent_id'] !== null), (int) $b['id']]);
            }
            foreach ($rows as $row) {
                $db->upsert($t, $row);
            }
        }
        foreach ($settings as $id => $json) {
            $db->update('users', ['settings_json' => $json], 'id = ?', [(int) $id]);
        }
        if ($entryIds !== []) {
            [$in, $params] = Db::in($entryIds);
            $db->run("UPDATE mutations SET undone = 0 WHERE id IN $in", $params);
        }
    };

    // A stand-in plugin whose events can be moved from here (events:edit):
    // the source confirms whatever it's asked, and the undo of a move goes
    // back through it (Undo::undoPluginEdit). Another with calendars of its
    // own, named from a setting.
    $pluginDir = sys_get_temp_dir() . '/bc-db-plugins-' . bin2hex(random_bytes(4));
    // The directory goes when the test ends, however it ends.
    try {
        mkdir($pluginDir . '/mover', 0700, true);
        mkdir($pluginDir . '/stations', 0700, true);
        file_put_contents($pluginDir . '/mover/plugin.json', json_encode(['id' => 'mover', 'name' => 'Mover', 'version' => '1.0.0', 'permissions' => ['events:write', 'events:edit'], 'jobs' => []]));
        file_put_contents($pluginDir . '/mover/Plugin.php', '<?php
    return new class implements BetterCal\Plugin\PluginInterface, BetterCal\Plugin\EditablePlugin {
        public function runJob(BetterCal\Plugin\PluginHost $host, string $jobId): void {}
        public function validateSettings(array $values): array { return []; }
        public function applyEdit(BetterCal\Plugin\PluginHost $host, array $edit): array { return $edit["after"]; }
    };');
        file_put_contents($pluginDir . '/stations/plugin.json', json_encode([
            'id' => 'stations', 'name' => 'Stations', 'version' => '1.0.0', 'permissions' => ['events:write'], 'jobs' => [],
            'ownCalendars' => ['noun' => 'station calendar', 'name' => 'Stations, {station}', 'color' => '#336699', 'max' => 2],
            'ownCalendarSettings' => [['key' => 'station', 'type' => 'text', 'label' => 'Station', 'required' => true]],
        ]));
        file_put_contents($pluginDir . '/stations/Plugin.php', '<?php
    return new class implements BetterCal\Plugin\PluginInterface {
        public function runJob(BetterCal\Plugin\PluginHost $host, string $jobId): void {}
        public function validateSettings(array $values): array { return []; }
    };');
        $registry = new Plugins($db, $pluginDir, ['session_secret' => str_repeat('t', 32)]);
        // The undo every entry is restored with: it knows the plugins, for a
        // move written through to one.
        $restorer = new Undo($db, $registry);

        /**
         * Run an operation and check its journal. $vocabulary false: the
         * operation is making the person itself. $undoable: true, each entry
         * is undone, newest first, and the rows compared with before; false,
         * its entries are log-only on purpose (and there is at least one);
         * null, it's nothing for the journal (no entry, and nothing undo knows
         * changed). $except: changes the writer is known not to say, each one
         * a gap to close (the check fails once it's closed, so the list is
         * kept honest). It runs as a request made with $asRequest, or, for
         * one of $workers, as a worker's job, with no credential.
         */
        $asRequest = null;
        $workers = [
            "a feed's poll: an event changed, one gone, one new", 'a plugin making its calendar', "a plugin's sync: events added",
            "a plugin's sync: one changed, one gone", "a Google calendar's poll (GoogleSync's write, with Google's answer given)",
            'an emailed invitation held for Review', 'an emailed change held for Review', 'a mail limit noted in Review',
            'an emailed invitation from a sender not approved: held', 'an emailed series with a day already cancelled, from a sender not approved: both held',
            'an emailed series from a sender not approved, with a cancelled day arriving after it: both held',
            'the organizer cancels the series while its invitation waits: closed in Review with its held day',
            'a cancelled day of a series not on the calendar yet: held',
            'an emailed series from an approved sender: added, its held cancelled day becoming a cancellation of that occurrence',
            'an emailed invitation from an approved sender: added', 'an emailed change to an event you have: held', 'an emailed cancellation of it: held',
            'an emailed change from someone other than the organizer: refused', "a booking read from an email's markup: added",
            'an email admitted and its event made', 'the duplicate check: pairs found', "a plugin's proposal made", 'a proposal withdrawn',
        ];
        $journaled = static function (string $what, callable $op, bool $vocabulary = true, ?bool $undoable = true, array $except = []) use ($db, $read, $readSettings, $unjournaled, $left, $redo, $restorer, &$asRequest, $workers): void {
            $since = (int) $db->scalar('SELECT COALESCE(MAX(id), 0) FROM mutations');
            $before = $read();
            RequestAuthority::during(in_array($what, $workers, true) ? null : $asRequest, $op);
            $after = $read();
            $settingsAfter = $readSettings();
            $missing = $unjournaled($since, $before, $after, $vocabulary);
            sort($missing);
            sort($except);
            check("journal: $what says everything it changed" . ($except !== [] ? ' (but what it is known not to)' : ''), $missing === $except,
                'unsaid: ' . implode(', ', array_slice($missing, 0, 12)) . ($except !== [] ? '; expected unsaid: ' . implode(', ', $except) : ''));
            $entries = $db->all('SELECT id, user_id, before_json IS NULL AND after_json IS NULL AS log_only FROM mutations WHERE id > ? ORDER BY id DESC', [$since]);
            $logOnly = array_column(array_filter($entries, static fn(array $e): bool => (int) $e['log_only'] === 1), 'id');
            if ($undoable === null) {
                checkEq("journal: $what is nothing for the journal", [], array_column($entries, 'id'));
                return;
            }
            if (!$undoable) {
                $withSnapshots = array_diff(array_column($entries, 'id'), $logOnly);
                check("journal: $what is log-only by design", $entries !== [] && $withSnapshots === [], $entries === [] ? 'no entry made' : 'entries with snapshots: #' . implode(', #', $withSnapshots));
                return;
            }
            check("journal: $what keeps snapshots to undo", $entries !== [] && $logOnly === [], $entries === [] ? 'no entry made' : 'log-only entries: #' . implode(', #', $logOnly));
            $refused = [];
            foreach ($entries as $e) {
                if ((int) $e['log_only'] === 1) {
                    continue;
                }
                try {
                    $restorer->undoById((int) $e['user_id'], (int) $e['id']);
                } catch (HttpError $x) {
                    $refused[] = '#' . $e['id'] . ' ' . $x->errorCode . ': ' . $x->getMessage();
                }
            }
            check("undo: $what undoes", $refused === [], implode('; ', $refused));
            $still = $left($before, $read(), $vocabulary);
            check("undo: $what undone leaves every row as it was", $still === [], implode(', ', array_slice($still, 0, 12)));
            $redo($after, $settingsAfter, array_column($entries, 'id'));
        };

        $u = freshUser($db);
        $d = domains($db);
        $home = (int) Settings::defaultCalendarId($db, $u);
        // The owner's browser session, signed in: the credential every
        // request below is made with. A worker's job is made with none.
        $asRequest = requestCredentials($db, $u)['session'][0];
        $people = new People($db);
        $trips = new Trips($db, $d->undo);
        $views = new SavedViews($db, $d->undo);

        // The events below move to the Work calendar made here, the trips end
        // by deleting it, and each operation builds on what the one before
        // left: one block.
        scenario('journal: calendars, folders, events and trips', function () use ($db, $d, $u, $home, $trips, $journaled): void {
            // Calendars and folders.
            $work = 0;
            $journaled('making a calendar', function () use ($d, $u, &$work): void {
                $work = (int) $d->calendars->create($u, ['name' => 'Work'])['id'];
            });
            $folder = 0;
            $journaled('making a folder', function () use ($d, $u, &$folder): void {
                $folder = (int) $d->folders->create($u, ['name' => 'Projects'])['id'];
            });
            $journaled("a calendar's settings", fn() => $d->calendars->patch($u, $work, ['name' => 'Work things', 'color' => '#336699', 'visible' => false, 'role' => 'context', 'folderIds' => [$folder]]));
            $journaled('calendars shown and hidden together', fn() => $d->calendars->setVisibility($u, ['calendars' => [['id' => $work, 'visible' => true], ['id' => $home, 'visible' => false]]]), true, null);
            $journaled('a calendar filed and moved in its folder', function () use ($d, $u, $folder, $home): void {
                $d->folders->place($u, $folder, $home, 0);
                $d->folders->place($u, $folder, $home, 5);
            });
            $journaled('a calendar moved in the list', fn() => $d->calendars->placeInList($u, $work, 0));
            $journaled("a folder's name and order", fn() => $d->folders->patch($u, $folder, ['name' => 'Projects, renamed', 'sort' => 'az']));
            $journaled('a calendar taken out of a folder', fn() => $d->folders->unfile($u, $folder, $home));
            $journaled('a calendar made in a folder', fn() => $d->calendars->create($u, ['name' => 'Filed at once', 'folderIds' => [$folder]]));

            // Events, alone and in series.
            $event = 0;
            $journaled('making an event with tags and people', function () use ($d, $u, $home, &$event): void {
                $event = (int) $d->events->create($u, eventAt($home, 'Dinner', '2026-12-10', ['tagNames' => ['food'], 'personNames' => ['Alex Example']]))['eventId'];
            });
            $journaled('an event edited', fn() => $d->events->patch($u, $event, ['title' => 'Dinner out', 'location' => 'Sample Bistro', 'tagNames' => ['food', 'friends']]));
            $journaled('an event moved to another calendar', fn() => $d->events->patch($u, $event, ['calendarId' => $work]));
            $journaled('an event marked going', fn() => $d->events->setAttendance($u, $event, 'going'));
            $series = 0;
            $journaled('making a series', function () use ($d, $u, $home, &$series): void {
                $series = (int) $d->events->create($u, eventAt($home, 'Standup', '2026-11-02', ['rrule' => 'FREQ=DAILY;COUNT=20', 'tagNames' => ['work']]))['eventId'];
            });
            $journaled('one date of a series edited', fn() => $d->events->patch($u, $series, ['scope' => 'this', 'instanceStart' => '2026-11-04T18:00:00+00:00', 'title' => 'Standup and demo']));
            $journaled('one date of a series marked going', fn() => $d->events->setAttendance($u, $series, 'going', 'this', '2026-11-05T18:00:00+00:00'));
            $journaled('one date of a series deleted', fn() => $d->events->deleteEvent($u, $series, 'this', '2026-11-06T18:00:00+00:00'));
            $journaled('a date edited on its own, deleted', function () use ($d, $u, $db, $series): void {
                $edited = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ?', [$series, '2026-11-04 18:00:00']);
                $d->events->deleteEvent($u, $edited, null, null);
            });
            $journaled('one date moved off to another calendar', fn() => $d->events->patch($u, $series, ['scope' => 'this', 'instanceStart' => '2026-11-07T18:00:00+00:00', 'calendarId' => $work]));
            $journaled('a whole series changed', fn() => planned($d->events, $u, $series, ['scope' => 'all', 'instanceStart' => '2026-11-02T18:00:00+00:00', 'start' => '2026-11-02T19:00:00+00:00', 'end' => '2026-11-02T20:00:00+00:00']));
            $journaled('a series split from a date on', fn() => planned($d->events, $u, $series, ['scope' => 'following', 'instanceStart' => '2026-11-12T19:00:00+00:00', 'title' => 'Standup, later']));
            $journaled('a series ended from a date on', fn() => plannedDelete($d->events, $u, $series, '2026-11-10T19:00:00+00:00'));
            $journaled('a series marked going, all of it', fn() => $d->events->setAttendance($u, $series, 'interested'));
            $journaled('an event copied to another calendar', fn() => $d->events->copyTo($u, $event, $home));
            $journaled('an event deleted', fn() => $d->events->deleteEvent($u, $event, null, null));
            $journaled('a series deleted', fn() => $d->events->deleteEvent($u, $series, 'all', null));

            // Trips.
            $trip = (int) $d->events->create($u, eventAt($home, 'Trip away', '2026-12-20', ['isContainer' => true]))['eventId'];
            $stop = (int) $d->events->create($u, eventAt($home, 'Museum', '2026-12-21'))['eventId'];
            $journaled('an event added to a trip', fn() => $trips->attach($u, $trip, $stop));
            $journaled('a trip moved with its events', fn() => $d->events->patch($u, $trip, ['start' => '2026-12-27T18:00:00+00:00', 'end' => '2026-12-27T19:00:00+00:00', 'moveMembers' => true]));
            $journaled('an event taken out of a trip', fn() => $trips->detach($u, $trip, $stop));
            $trips->attach($u, $trip, $stop);
            $journaled('a calendar deleted, with its events, tags and trip links', fn() => $d->calendars->delete($u, $work));
        });

        // People and their away times.
        scenario('journal: people and their away times', function () use ($d, $u, $home, $people, $journaled): void {
            $person = 0;
            $journaled('a person added', function () use ($people, $u, &$person): void {
                $person = (int) $people->create($u, ['name' => 'Sam Sample'])['id'];
            }, false);
            $span = 0;
            $journaled('an away time added, moved and removed', function () use ($people, $u, $person, &$span): void {
                $span = (int) $people->addSpan($u, $person, ['start' => '2026-12-01T00:00:00Z', 'end' => '2026-12-05T00:00:00Z'])['id'];
                $people->updateSpan($u, $person, $span, ['end' => '2026-12-06T00:00:00Z']);
                $people->deleteSpan($u, $person, $span);
            });
            $journaled('a person renamed', fn() => $people->update($u, $person, ['name' => 'Sam Sampler', 'notes' => 'Neighbor']));
            $other = (int) $people->create($u, ['name' => 'Alex Example'])['id'];
            $people->addSpan($u, $other, ['start' => '2026-12-08T00:00:00Z', 'end' => '2026-12-09T00:00:00Z']);
            $d->events->create($u, eventAt($home, 'Coffee', '2026-12-11', ['personNames' => ['Alex Example']]));
            $journaled('a person renamed onto another (merged)', fn() => $people->update($u, $other, ['name' => 'Sam Sampler']));
            $journaled('a person deleted', fn() => $people->delete($u, $person));
        });

        // Saved views and filters.
        scenario('journal: saved views', function () use ($u, $views, $journaled): void {
            $journaled('a saved view made, changed and deleted', function () use ($views, $u): void {
                $id = (int) $views->create($u, ['name' => 'Week', 'config' => ['view' => 'week']])['id'];
                $views->patch($u, $id, ['name' => 'This week']);
                $views->delete($u, $id);
            });
        });
        scenario('journal: filters', function () use ($db, $d, $u, $journaled): void {
            $filters = new Filters($db, $d->undo, new JobQueue($db));
            $journaled('a filter made, changed and deleted', function () use ($filters, $u): void {
                $id = (int) $filters->create($u, ['name' => 'Quiet', 'scope' => 'global', 'type' => 'keyword', 'action' => 'dim', 'config' => ['pattern' => 'webinar']])['id'];
                $filters->patch($u, $id, ['action' => 'hide']);
                $filters->delete($u, $id);
            });
        });

        // Quick add, and a phone's or desktop app's calendar (CalDAV).
        scenario("journal: quick add and a phone's calendar", function () use ($db, $d, $u, $home, $journaled): void {
            $quick = new \BetterCal\Domain\QuickAdd($db, new \BetterCal\Infra\LlmGateway([]), $d->events, new Settings($db));
            $journaled('an event made with quick add', fn() => $quick->run($u, 'Lunch with Alex Example tomorrow at noon', 'UTC', true, $home));
            $token = (new \BetterCal\Domain\ApiTokens($db))->create($u, 'Phone');
            $dav = new \BetterCal\Dav\CalendarBackend($db, $d->undo, fn() => \BetterCal\Domain\RequestAuthority::credential($u, (int) $token['id'], null));
            $davEvent = static fn(string $title) => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\nBEGIN:VEVENT\r\nUID:dav-1@example.test\r\nDTSTAMP:20261101T000000Z\r\n"
                . "DTSTART:20261218T180000Z\r\nDTEND:20261218T190000Z\r\nSUMMARY:$title\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            $journaled('an event made from a phone', fn() => $dav->createCalendarObject($home, 'dav-1@example.test.ics', $davEvent('From the phone')));
            $journaled('an event changed from a phone', fn() => $dav->updateCalendarObject($home, 'dav-1@example.test.ics', $davEvent('From the phone, changed')));
            $journaled('an event deleted from a phone', fn() => $dav->deleteCalendarObject($home, 'dav-1@example.test.ics'));
            $journaled('a calendar renamed from a phone', function () use ($dav, $home): void {
                $patch = new \Sabre\DAV\PropPatch(['{DAV:}displayname' => 'Renamed on the phone']);
                $dav->updateCalendar($home, $patch);
                $patch->commit();
            });
        });

        // What comes in from outside: a file, a feed, a plugin.
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:imported-1@example.test\r\nDTSTAMP:20261101T000000Z\r\nDTSTART:20261215T180000Z\r\nDTEND:20261215T190000Z\r\nSUMMARY:Imported one\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:imported-2@example.test\r\nDTSTAMP:20261101T000000Z\r\nDTSTART:20261216T180000Z\r\nDTEND:20261216T190000Z\r\nSUMMARY:Imported two\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
        $feeds = new Feeds($db, null, ['session_secret' => str_repeat('t', 32)]);
        scenario('journal: a file imported', function () use ($db, $d, $u, $home, $ics, $journaled): void {
            $importer = new CalendarImport($db, $d->calendars);
            $journaled('a file imported into a calendar', fn() => $importer->importIcs($u, $ics, 'ignored', null, $home));
            $journaled('a file imported as a new calendar', fn() => $importer->importIcs($u, $ics, 'Imported calendar'));
            // As a file too large for one row of the journal is kept (#191):
            // the events it brought in, in pieces apart from the entry.
            $inline = Undo::$inlineBytes;
            Undo::$inlineBytes = 0;
            try {
                $journaled('a file imported into a calendar, its events kept in pieces', fn() => $importer->importIcs($u, str_replace('imported-', 'pieces-', $ics), 'ignored', null, $home));
            } finally {
                Undo::$inlineBytes = $inline;
            }
            checkEq('journal: that import kept its events in pieces', ['after:events'], array_map(static fn(array $r): string => $r['side'] . ':' . $r['table_name'],
                $db->all("SELECT DISTINCT side, table_name FROM mutation_rows WHERE mutation_id = (SELECT MAX(id) FROM mutations WHERE source = 'import')")));
        });
        scenario('journal: a feed and a plugin', function () use ($db, $d, $u, $ics, $feeds, $journaled): void {
            $feed = (int) $d->calendars->create($u, ['name' => 'Club feed'], 'subscribed', 'https://feeds.example.test/club.ics')['id'];
            $calendarRow = static fn() => $db->one('SELECT * FROM calendars WHERE id = ?', [$feed]);
            $feeds->sync($calendarRow(), Ics::parse($ics));
            $fed = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ?', [$feed, 'imported-1@example.test']);
            $d->events->setAttendance($u, $fed, 'going');
            $changedFeed = str_replace(['SUMMARY:Imported one', "BEGIN:VEVENT\r\nUID:imported-2"], ['SUMMARY:Imported one, renamed', "BEGIN:VEVENT\r\nUID:gone-2"], $ics);
            $journaled("a feed's poll: an event changed, one gone, one new", fn() => $feeds->sync($calendarRow(), Ics::parse($changedFeed)), true, false);

            $db->run("INSERT INTO plugins (id, version, enabled) VALUES ('sample', '1.0.0', 1)");
            $host = new PluginHost($db, 'sample', $u, new HttpClient(), [], []);
            $pluginCal = 0;
            $journaled('a plugin making its calendar', function () use ($host, &$pluginCal): void {
                $pluginCal = $host->ensureCalendar('Sample items', '#557799');
            }, true, false);
            $journaled("a plugin's sync: events added", fn() => $host->syncEvents($pluginCal, [['sourceKey' => 'a', 'title' => 'First', 'start' => '2026-12-01', 'allDay' => true], ['sourceKey' => 'b', 'title' => 'Second', 'start' => '2026-12-02', 'allDay' => true]]), true, false);
            $journaled("a plugin's sync: one changed, one gone", fn() => (new PluginHost($db, 'sample', $u, new HttpClient(), [], []))->syncEvents($pluginCal, [['sourceKey' => 'a', 'title' => 'First, renamed', 'start' => '2026-12-01', 'allDay' => true]]), true, false);
            $journaled('a subscription kept as a calendar of its own', fn() => $d->calendars->adopt($u, $feed), true, false);
        });

        // A Google calendar's poll ends in the write GoogleSync::poll makes
        // once Google has answered: Feeds::sync of the snapshot and the sync
        // position, inside Undo::write. The fetch itself needs Google, so the
        // oracle runs that write with the answer given. Google-backed rows are
        // a cache of Google's, so the entries are log-only by design.
        scenario("journal: a Google calendar's poll", function () use ($db, $u, $ics, $feeds, $journaled): void {
            $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test', 'refresh_token_enc' => 'sealed-sample', 'scopes' => 'calendar']);
            $googleCal = $db->insert('calendars', ['user_id' => $u, 'name' => 'Google sample', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
                'google_account_id' => $account, 'google_calendar_id' => 'sample@group.calendar.google.com', 'subscription_authority' => 'owner',
                'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
            $journaled("a Google calendar's poll (GoogleSync's write, with Google's answer given)", fn() => (new Undo($db))->write($u, function () use ($db, $feeds, $googleCal, $ics): void {
                $feeds->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$googleCal]), Ics::parse($ics), true);
                $db->update('calendars', ['google_sync_token' => 'sample-token'], 'id = ?', [$googleCal]);
            }), true, false);
        });

        // An edit of what Google doesn't hold (tags, reminders, the trip
        // flag) on an event of a Google calendar the account writes to:
        // Google is sent nothing, and the entry undoes here as a local one.
        scenario("journal: a Google event's local fields", function () use ($db, $d, $u, $ics, $feeds, $journaled): void {
            $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'writer@example.test', 'refresh_token_enc' => 'sealed-sample', 'scopes' => 'calendar']);
            $googleCal = $db->insert('calendars', ['user_id' => $u, 'name' => 'Google writable sample', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
                'google_account_id' => $account, 'google_calendar_id' => 'writable@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
                'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
            (new Undo($db))->write($u, fn() => $feeds->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$googleCal]), Ics::parse($ics), true));
            $event = (int) $db->scalar("SELECT id FROM events WHERE calendar_id = ? AND deleted_at IS NULL AND (rrule IS NULL OR rrule = '') ORDER BY id LIMIT 1", [$googleCal]);
            $journaled("a Google event's tags, reminders and trip flag", fn() => $d->events->patch($u, $event, ['tagNames' => ['sample-local'], 'reminders' => [['minutes' => 20]], 'isContainer' => true]));
        });

        // Emailed invitations: held for Review, decided there, and answered.
        // The Invitations calendar is the app's own, made once for the account
        // rather than by any one decision, so it's made here first.
        $inviteCal = MailIngest::ensureInviteCalendar($db, $u);
        $review = new ReviewQueue($db, $d->events);
        scenario('journal: emailed invitations decided in Review, and answered', function () use ($db, $u, $review, $journaled): void {
            $invite = ['method' => 'REQUEST', 'kind' => 'invitation', 'organizer' => ['email' => 'host@example.test', 'name' => 'Sample Host'],
                'attendees' => [['email' => 'owner@example.test', 'partstat' => 'NEEDS-ACTION']], 'sequence' => 0];
            $fields = ['title' => 'Sample potluck', 'start' => '2026-12-12T01:00:00+00:00', 'end' => '2026-12-12T03:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC'];
            $held = 0;
            $journaled('an emailed invitation held for Review', function () use ($review, $u, $fields, $invite, &$held): void {
                $held = $review->holdNewInvitation($u, 'potluck@example.test', $fields, $invite, 'host@example.test');
            }, true, false);
            $journaled('an invitation added from Review, its sender approved', fn() => $review->accept($u, $held, 'address'));
            $invited = (int) $db->scalar('SELECT event_id FROM review_items WHERE id = ?', [$held]);
            $change = 0;
            $moved = ['start' => '2026-12-13T01:00:00+00:00', 'end' => '2026-12-13T03:00:00+00:00'] + $fields;
            $journaled('an emailed change held for Review', function () use ($review, $db, $u, $invited, $moved, $invite, &$change): void {
                $change = (int) $review->holdInviteChange($u, $db->one('SELECT * FROM events WHERE id = ?', [$invited]), 'potluck@example.test', 'REQUEST', $moved, ['sequence' => 1] + $invite, 'host@example.test');
            }, true, false);
            $journaled('a change accepted from Review', fn() => $review->accept($u, $change));
            $second = (int) $review->holdInviteChange($u, $db->one('SELECT * FROM events WHERE id = ?', [$invited]), 'potluck@example.test', 'REQUEST', ['title' => 'Sample potluck, indoors'] + $moved, ['sequence' => 2] + $invite, 'host@example.test');
            // Dismissing closes the item without saying so. Nothing stands on a
            // dismissal (an accept's undo puts back only the items it closed or
            // rewrote itself, and a closed item can't be dismissed), so no undo
            // goes wrong; still a gap in what the journal says, kept here until
            // ReviewQueue::dismiss touches the item it closes.
            $journaled('a change dismissed in Review', fn() => $review->dismiss($u, $second), true, false, ["review_items:$second.decided_at", "review_items:$second.status"]);
            $journaled('an unanswered invitation taken out of Review', fn() => $review->dismissReply($u, $invited), true, false);
            // Review's notices are information, not changes: nothing for the journal.
            $journaled('a mail limit noted in Review', fn() => $review->holdMailLimit($u, 'event_account_day', 'Paused for today.', '', 'Sample subject', 'someone@example.test'), true, null);

            // An invitation answered: kept here when no account can send the reply
            // (undoable like any change); sent, or possibly sent, when one can
            // (log-only: the organizer may have it).
            $session = bin2hex(random_bytes(16));
            $db->insert('sessions', ['token_hash' => hash('sha256', $session), 'user_id' => $u, 'csrf' => 'x', 'authenticated_at' => Time::nowDb(), 'expires_at' => '2030-01-01 00:00:00']);
            $journaled('an invitation answered, with no account to send the reply', fn() => (new Rsvp($db, new EmailSender([]), []))->answer($u, $invited, 'accepted', $session));
            $smtp = ['smtp' => ['host' => '127.0.0.1', 'port' => 1, 'from' => 'owner@example.test']];
            $journaled('an invitation answered by mail', fn() => (new Rsvp($db, new EmailSender($smtp), $smtp))->answer($u, $invited, 'declined', $session), true, false);
        });

        // Mail read by the worker: each message as the fetcher hands it over.
        scenario('journal: mail read by the worker', function () use ($db, $d, $u, $review, $inviteCal, $journaled): void {
            $mail = new MailIngest($db, $d->events, null, new MailAdmission($db));
            $message = static fn(string $id, string $from, array $more): array => $more + ['messageId' => $id, 'subject' => 'Sample invitation', 'from' => $from, 'icsParts' => [], 'html' => null, 'text' => null];
            $imip = static fn(string $uid, string $method, int $sequence, string $title, string $day, string $organizer = 'host@example.test'): string =>
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\nMETHOD:$method\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20261101T000000Z\r\n"
                . "DTSTART:{$day}T180000Z\r\nDTEND:{$day}T190000Z\r\nSUMMARY:$title\r\nSEQUENCE:$sequence\r\nORGANIZER;CN=Sample Host:mailto:$organizer\r\n"
                . "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:owner@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            Settings::put($db, $u, ['mailApprovedSenders' => []]);
            $journaled('an emailed invitation from a sender not approved: held', fn() => $mail->ingestMessage($u, $message('m1@example.test', 'host@example.test', ['icsParts' => [$imip('mail-1@example.test', 'REQUEST', 0, 'Sample dinner', '20261219')]]), 'UTC'), true, false);
            // A repeating invitation with one day already cancelled, from that
            // sender: both wait (log-only holds); adding the series makes the
            // day a cancellation of it, inside the add's one entry.
            $seriesWithCancelledDay = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\nMETHOD:REQUEST\r\n"
                . "BEGIN:VEVENT\r\nUID:mail-series@example.test\r\nDTSTAMP:20261101T000000Z\r\nRECURRENCE-ID:20270112T180000Z\r\nDTSTART:20270112T180000Z\r\nDTEND:20270112T190000Z\r\nSUMMARY:Sample reading group\r\nSTATUS:CANCELLED\r\nSEQUENCE:0\r\nORGANIZER;CN=Sample Host:mailto:host@example.test\r\nEND:VEVENT\r\n"
                . "BEGIN:VEVENT\r\nUID:mail-series@example.test\r\nDTSTAMP:20261101T000000Z\r\nDTSTART:20270105T180000Z\r\nDTEND:20270105T190000Z\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Sample reading group\r\nSEQUENCE:0\r\nORGANIZER;CN=Sample Host:mailto:host@example.test\r\n"
                . "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:owner@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            $journaled('an emailed series with a day already cancelled, from a sender not approved: both held', fn() => $mail->ingestMessage($u, $message('m1b@example.test', 'host@example.test', ['icsParts' => [$seriesWithCancelledDay]]), 'UTC'), true, false);
            $heldSeries = (int) $db->scalar("SELECT MIN(id) FROM review_items WHERE user_id = ? AND source_key = 'mail-series@example.test' AND status = 'open'", [$u]);
            $journaled('the series added from Review, its cancelled day becoming a cancellation of that occurrence', fn() => $review->accept($u, $heldSeries));
            check('completeness: the cancelled day waits as a cancellation of the added series', (int) $db->scalar("SELECT COUNT(*) FROM review_items WHERE user_id = ? AND source_key = 'mail-series@example.test' AND kind = 'invite_change' AND status = 'open'", [$u]) === 1);
            // The organizer cancels a series whose invitation is still waiting:
            // the invitation closes with the days held before it (a note: no
            // event changes).
            $seriesOf = static fn(string $uid, string $method = 'REQUEST', int $sequence = 0, string $status = ''): string =>
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\nMETHOD:$method\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20261101T000000Z\r\nDTSTART:20270105T180000Z\r\nDTEND:20270105T190000Z\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Sample reading group\r\nSEQUENCE:$sequence\r\n"
                . ($status !== '' ? "STATUS:$status\r\n" : '') . "ORGANIZER;CN=Sample Host:mailto:host@example.test\r\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:owner@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            $cancelledDayOf = static fn(string $uid): string =>
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\nMETHOD:CANCEL\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20261101T000000Z\r\nRECURRENCE-ID:20270112T180000Z\r\nDTSTART:20270112T180000Z\r\nDTEND:20270112T190000Z\r\nSUMMARY:Sample reading group\r\nSTATUS:CANCELLED\r\nSEQUENCE:1\r\nORGANIZER;CN=Sample Host:mailto:host@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            $journaled('an emailed series from a sender not approved, with a cancelled day arriving after it: both held', function () use ($mail, $u, $message, $seriesOf, $cancelledDayOf): void {
                $mail->ingestMessage($u, $message('m1c@example.test', 'host@example.test', ['icsParts' => [$seriesOf('mail-called-off@example.test')]]), 'UTC');
                $mail->ingestMessage($u, $message('m1d@example.test', 'host@example.test', ['icsParts' => [$cancelledDayOf('mail-called-off@example.test')]]), 'UTC');
            }, true, false);
            $journaled('the organizer cancels the series while its invitation waits: closed in Review with its held day', fn() => $mail->ingestMessage($u, $message('m1e@example.test', 'host@example.test', ['icsParts' => [$seriesOf('mail-called-off@example.test', 'CANCEL', 1, 'CANCELLED')]]), 'UTC'), true, false);
            check('completeness: the cancelled invitation and its held day are closed', (int) $db->scalar("SELECT COUNT(*) FROM review_items WHERE user_id = ? AND source_key = 'mail-called-off@example.test' AND status = 'open'", [$u]) === 0);
            Settings::put($db, $u, ['mailApprovedSenders' => ['host@example.test']]);
            // A cancelled day arriving before its series, which the approved
            // sender's series then adds automatically: the add's one entry
            // carries the day's item, now a cancellation of that occurrence, and
            // undoing the add puts it back as it waited.
            $journaled('a cancelled day of a series not on the calendar yet: held', fn() => $mail->ingestMessage($u, $message('m2a@example.test', 'host@example.test', ['icsParts' => [$cancelledDayOf('mail-auto@example.test')]]), 'UTC'), true, false);
            $journaled('an emailed series from an approved sender: added, its held cancelled day becoming a cancellation of that occurrence', fn() => $mail->ingestMessage($u, $message('m2b@example.test', 'host@example.test', ['icsParts' => [$seriesOf('mail-auto@example.test')]]), 'UTC'));
            check('completeness: the cancelled day is a cancellation of the series added automatically', (int) $db->scalar("SELECT COUNT(*) FROM review_items WHERE user_id = ? AND source_key = 'mail-auto@example.test' AND kind = 'invite_change' AND status = 'open'", [$u]) === 1);
            $journaled('an emailed invitation from an approved sender: added', fn() => $mail->ingestMessage($u, $message('m2@example.test', 'host@example.test', ['icsParts' => [$imip('mail-2@example.test', 'REQUEST', 0, 'Sample lunch', '20261220')]]), 'UTC'));
            $journaled('an emailed change to an event you have: held', fn() => $mail->ingestMessage($u, $message('m3@example.test', 'host@example.test', ['icsParts' => [$imip('mail-2@example.test', 'REQUEST', 1, 'Sample lunch, moved', '20261221')]]), 'UTC'), true, false);
            $journaled('an emailed cancellation of it: held', fn() => $mail->ingestMessage($u, $message('m4@example.test', 'host@example.test', ['icsParts' => [$imip('mail-2@example.test', 'CANCEL', 2, 'Sample lunch', '20261220')]]), 'UTC'), true, false);
            $journaled('an emailed change from someone other than the organizer: refused', fn() => $mail->ingestMessage($u, $message('m5@example.test', 'other@example.test', ['icsParts' => [$imip('mail-2@example.test', 'REQUEST', 3, 'Sample lunch, taken over', '20261222', 'other@example.test')]]), 'UTC'), true, false);
            $markup = '<html><body><script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@type' => 'Event', 'name' => 'Sample workshop',
                'startDate' => '2026-12-22T10:00:00-08:00', 'endDate' => '2026-12-22T12:00:00-08:00', 'location' => ['@type' => 'Place', 'name' => 'Sample Hall']]) . '</script></body></html>';
            $journaled("a booking read from an email's markup: added", fn() => $mail->ingestMessage($u, $message('m6@example.test', 'host@example.test', ['html' => $markup]), 'UTC'));
            // The admission around a mail event, with the event it admits.
            $journaled('an email admitted and its event made', fn() => (new MailAdmission($db))->createEvent($u, 'sample-message-key', 'host@example.test', fn(): int => (int) $d->events->create($u, eventAt($inviteCal, 'Admitted', '2026-12-23'))['eventId']));
        });

        // The duplicate check and the person's word on a pair: the pairs are
        // the check's own, outside undo; Activity gets a note of each.
        scenario('journal: the duplicate check', function () use ($db, $d, $u, $home, $journaled): void {
            $twin = (int) $d->calendars->create($u, ['name' => 'Twin'])['id'];
            $d->events->create($u, eventAt($home, 'Sample recital', '2026-12-24'));
            $d->events->create($u, eventAt($twin, 'Sample recital', '2026-12-24'));
            $duplicates = new Duplicates($db);
            $journaled('the duplicate check: pairs found', fn() => $duplicates->scan($u), true, false);
            $pair = (int) $db->scalar('SELECT MAX(id) FROM event_duplicates WHERE user_id = ?', [$u]);
            check('duplicates: the check found the pair', $pair > 0);
            $journaled('a pair decided: not the same event', fn() => $duplicates->decide($u, $pair, 'dismissed'), true, false);
        });

        // A plugin's proposals: the proposal is the plugin's, outside undo;
        // accepting one makes its trip and events like any change.
        scenario("journal: a plugin's proposals", function () use ($db, $d, $u, $home, $trips, $journaled): void {
            $proposals = new Proposals($db, $d->events, $trips);
            $plan = static fn(string $title): array => ['trip' => ['title' => $title, 'start' => '2027-01-08', 'end' => '2027-01-10', 'calendarId' => $home],
                'events' => [['title' => 'Train out', 'start' => '2027-01-08T09:00:00+00:00', 'end' => '2027-01-08T11:00:00+00:00', 'calendarId' => $home],
                    ['title' => 'Train back', 'start' => '2027-01-10T16:00:00+00:00', 'end' => '2027-01-10T18:00:00+00:00', 'calendarId' => $home]]];
            $proposal = [];
            $journaled("a plugin's proposal made", function () use ($proposals, $u, $plan, &$proposal): void {
                $proposal = $proposals->upsert($u, 'sample', ['sourceKey' => 'trip-1', 'title' => 'A weekend away', 'plan' => $plan('Weekend away')]);
            }, true, null);
            $journaled('a proposal accepted: its trip and events made', fn() => $proposals->accept($u, (int) $proposal['id'], (string) $proposal['reviewToken']));
            $declined = $proposals->upsert($u, 'sample', ['sourceKey' => 'trip-2', 'title' => 'Another weekend', 'plan' => $plan('Another weekend')]);
            $journaled('a proposal rejected', fn() => $proposals->reject($u, (int) $declined['id'], (string) $declined['reviewToken']), true, null);
            $proposals->upsert($u, 'sample', ['sourceKey' => 'trip-3', 'title' => 'Never mind', 'plan' => $plan('Never mind')]);
            $journaled('a proposal withdrawn', fn() => $proposals->withdraw($u, 'sample', 'trip-3'), true, null);
        });

        // Plugins' calendars: one of a person's own, named from its settings;
        // an event moved on a plugin calendar, written through to its source;
        // a plugin uninstalled, its calendars kept or taken with it.
        scenario("journal: plugins' calendars", function () use ($db, $d, $u, $registry, $journaled): void {
            $db->run("INSERT INTO plugins (id, version, enabled) VALUES ('mover', '1.0.0', 1), ('stations', '1.0.0', 1)");
            $own = 0;
            $journaled("a plugin calendar of your own added, named from its settings", function () use ($registry, $u, &$own): void {
                $own = $registry->createOwnCalendar($u, 'stations', null, null, ['station' => 'North Point']);
            }, true, true);
            $journaled("its settings changed, and its name following them", fn() => $registry->saveOwnCalendarSettings($u, $own, 'stations', ['station' => 'South Cove']), true, false);
            $pluginUndo = new Undo($db, $registry);
            $pluginEvents = new Events($db, new Recurrence(), $pluginUndo, new Labels($db), new Filters($db, $pluginUndo, new JobQueue($db)), new Trips($db, $pluginUndo), $registry);
            $mover = new PluginHost($db, 'mover', $u, new HttpClient(), (array) $registry->manifest('mover'), []);
            $moverCal = $mover->ensureCalendar('Mover items', '#446688');
            $mover->syncEvents($moverCal, [['sourceKey' => 'item-1', 'title' => 'Movable', 'start' => '2026-12-28', 'allDay' => true]]);
            $movable = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ?', [$moverCal]);
            $journaled('an event moved on a plugin calendar, written through to its source', fn() => $pluginEvents->patch($u, $movable, ['start' => '2026-12-29', 'end' => '2026-12-30', 'allDay' => true]));
            $journaled('a plugin uninstalled, its calendars kept', fn() => $registry->uninstall($u, 'stations', false), true, false);
            $journaled('a plugin uninstalled with its calendars', fn() => $registry->uninstall($u, 'mover', true), true, false);
        });
    } finally {
        removeTree($pluginDir);
    }
};
