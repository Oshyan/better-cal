<?php

declare(strict_types=1);

// The undo of an edit to one event or one day on a Google calendar, as a forward change back
// (docs/design/google-conflict-choice.md, the page "Slice 3a"). Each scenario is one the page names: the edit and the
// undo are made as the app makes them, against the stand-in for Google, which another client changes in between. Every
// expectation is written here by hand from the page (the sentences, which fields are asked, kept or sent); nothing in
// server/src is called to compute one. Invented events only.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Trips;
use BetterCal\Domain\Undo;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/google-probe/lib/FakeGoogle.php';

return static function (Db $db): void {
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $tz = 'America/Los_Angeles';
    $la = new DateTimeZone($tz);
    $when = static fn(string $local): array => ['dateTime' => (new DateTimeImmutable($local, $la))->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
    $expand = static function (array $master): array {
        $zone = (string) ($master['start']['timeZone'] ?? 'UTC');
        $start = new DateTimeImmutable((string) ($master['start']['dateTime'] ?? $master['start']['date']));
        $end = new DateTimeImmutable((string) ($master['end']['dateTime'] ?? $master['end']['date']));
        $rrule = null;
        foreach ((array) ($master['recurrence'] ?? []) as $line) {
            $rrule = str_starts_with((string) $line, 'RRULE:') ? substr((string) $line, 6) : $rrule;
        }
        if ($rrule === null) {
            return [];
        }
        $row = ['start_utc' => BetterCal\Support\Time::toDb($start), 'end_utc' => BetterCal\Support\Time::toDb($end), 'all_day' => 0, 'tzid' => $zone, 'rrule' => $rrule];
        return array_map(static fn(array $o): DateTimeImmutable => $o['start']->setTimezone(new DateTimeZone($zone)),
            Recurrence::occurrences($row, $start, $start->modify('+2 years'), null, 730));
    };
    // $on: the setting google_conflict_choice.
    $setup = static function (bool $on = true) use ($db, $cfg, $when, $expand): object {
        $u = freshUser($db);
        $d = domains($db);
        $settings = json_decode((string) $db->scalar('SELECT settings_json FROM users WHERE id = ?', [$u]), true) ?: [];
        $db->update('users', ['settings_json' => json_encode(['google_conflict_choice' => $on] + $settings)], 'id = ?', [$u]);
        $fake = new FakeGoogle('undo@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->expand = $expand;
        $fake->seed(['id' => 'smpl5single', 'iCalUID' => 'smpl5single@google.com', 'summary' => 'Sample review', 'location' => 'Sample room 2',
            'start' => $when('2027-03-30 15:00'), 'end' => $when('2027-03-30 16:00')]);
        $fake->seed(['id' => 'smpl5allday', 'iCalUID' => 'smpl5allday@google.com', 'summary' => 'Sample offsite', 'start' => ['date' => '2027-03-31'], 'end' => ['date' => '2027-04-01']]);
        // Weekly, Tuesdays 09:00 Pacific from 6 April 2027: the US clocks are already on summer time, 16:00 UTC.
        $fake->seed(['id' => 'smpl5weekly', 'iCalUID' => 'smpl5weekly@google.com', 'summary' => 'Sample standup', 'description' => 'Sample notes',
            'start' => $when('2027-04-06 09:00'), 'end' => $when('2027-04-06 09:30'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=8']]);
        $t = new class {
            /** @var list<array{0:string,1:string}> method, id */
            public array $calls = [];
        };
        $inner = $fake->writerTransport();
        $transport = static function (string $method, string $url, ?array $payload, array $headers = []) use ($t, $inner): array {
            $t->calls[] = [$method, rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))), $payload];
            return $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
        };
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $writer = new GoogleWriter($db, $auth, $feeds, $transport);
        $syncTransport = $fake->syncTransport();
        $sync = new GoogleSync($db, $auth, $feeds, static fn(string $url, array $headers): array => $syncTransport($url, array_merge(['Authorization: Bearer sample-access'], $headers)));
        $events = new Events($db, new Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new Trips($db, $d->undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample shared', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'undo@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $poll = static fn() => $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $poll();
        $t->calls = [];
        $idOf = static fn(string $gid): int => (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$calendarId, $gid]);
        return (object) ['u' => $u, 'fake' => $fake, 't' => $t, 'poll' => $poll, 'events' => $events, 'calendarId' => $calendarId, 'id' => $idOf('smpl5single'),
            'allDay' => $idOf('smpl5allday'), 'weekly' => $idOf('smpl5weekly'), 'undo' => new Undo($db, null, $writer), 'trips' => new Trips($db, $d->undo), 'd' => $d];
    };
    $last = static fn(object $s): int => (int) $db->scalar('SELECT id FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$s->u]);
    $undone = static fn(int $entry): int => (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [$entry]);
    $row = static fn(int $id): array => $db->one('SELECT * FROM events WHERE id = ?', [$id]) ?? [];
    // The app's writes to the stand-in since the calls were cleared: [method, id, the body's keys].
    $writes = static fn(object $s): array => array_values(array_map(static fn(array $c): array => [$c[0], $c[1], array_keys((array) $c[2])],
        array_filter($s->t->calls, static fn(array $c): bool => $c[0] !== 'GET')));
    // Another client's change in Google Calendar.
    $there = static function (object $s, string $gid, array $change): void {
        $s->fake->seed(array_merge($s->fake->events[$gid], $change));
    };
    // An undo: its answer, or the refusal's code, message and extra.
    $undo = static function (object $s, int $entry, bool $force = false, array $over = [], ?string $device = null): array {
        $s->t->calls = [];
        try {
            return ['ok'] + $s->undo->undoById($s->u, $entry, $force, null, $over, $device);
        } catch (HttpError $e) {
            return [$e->errorCode, $e->getMessage(), $e->extra];
        }
    };
    $keptFields = static fn(array $answer): array => array_column($answer['kept'] ?? [['field' => 'no kept key']], 'field');
    $day = ['scope' => 'this', 'instanceStart' => '2027-04-13T16:00:00+00:00'];
    $dayGid = 'smpl5weekly_20270413T160000Z';
    $dayRow = static fn(object $s): ?array => $db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$s->weekly, '2027-04-13 16:00:00']);

    scenario('undo choice: nothing changed since: the undo of a rename, of a drag and of a Maybe each send one write of that field alone; `kept` is empty', function () use ($setup, $last, $undo, $writes, $keptFields, $row, $undone): void {
        $s = $setup();
        foreach ([
            'a rename' => [['title' => 'Sample review, renamed'], ['summary'], 'title', 'Sample review'],
            'a drag' => [['start' => '2027-03-30T17:00:00-07:00', 'end' => '2027-03-30T18:00:00-07:00'], ['start', 'end'], 'start_utc', '2027-03-30 22:00:00'],
            'a Maybe' => [['status' => 'tentative'], ['status'], 'status', 'confirmed'],
        ] as $what => [$change, $keys, $column, $was]) {
            $s->events->patch($s->u, $s->id, $change);
            $entry = $last($s);
            $a = $undo($s, $entry);
            checkEq("$what undone: one PATCH of that field alone, nothing kept, the value from before here and the entry undone",
                ['ok', [['PATCH', 'smpl5single', $keys]], [], $was, 1], [$a[0], $writes($s), $keptFields($a), (string) $row($s->id)[$column], $undone($entry)]);
        }
        // An all-day event moved a day and undone: the dates back, still all-day.
        $s->events->patch($s->u, $s->allDay, ['start' => '2027-04-02', 'end' => '2027-04-03', 'allDay' => true]);
        $a = $undo($s, $last($s));
        checkEq('an all-day move undone: one PATCH of the time, the dates from before, all-day still', ['ok', [['PATCH', 'smpl5allday', ['start', 'end']]], '2027-03-31 00:00:00', 1],
            [$a[0], $writes($s), (string) $row($s->allDay)['start_utc'], (int) $row($s->allDay)['all_day']]);
        checkEq('at Google the all-day event holds its dates from before', ['date' => '2027-03-31'], array_intersect_key($s->fake->events['smpl5allday']['start'], ['date' => 1]));
    });

    scenario('undo choice: the time changed in Google Calendar after a rename: the undo sends the title alone, the row holds Google Calendar\'s time and the old title, `kept` names the time', function () use ($setup, $last, $undo, $writes, $keptFields, $row, $there, $when): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, renamed']);
        $entry = $last($s);
        $there($s, 'smpl5single', ['start' => $when('2027-03-30 17:00'), 'end' => $when('2027-03-30 18:00')]);
        $a = $undo($s, $entry);
        checkEq('undone with the title alone sent; the time changed there is kept and named', ['ok', [['PATCH', 'smpl5single', ['summary']]], ['time'], 'Sample review', '2027-03-31 00:00:00'],
            [$a[0], $writes($s), $keptFields($a), (string) $row($s->id)['title'], (string) $row($s->id)['start_utc']]);
        checkEq('Google Calendar holds the old title and its own time', ['Sample review', '2027-03-30T17:00:00-07:00'],
            [$s->fake->events['smpl5single']['summary'], $s->fake->events['smpl5single']['start']['dateTime']]);
    });

    scenario('undo choice: the place changed here after a rename: the undo of the rename sends the title alone and keeps the place', function () use ($setup, $last, $undo, $writes, $keptFields, $row): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, renamed']);
        $entry = $last($s);
        $s->events->patch($s->u, $s->id, ['location' => 'Sample hall']);
        $a = $undo($s, $entry);
        checkEq('undone: the title alone sent, the later place kept and not named (the person made it)', ['ok', [['PATCH', 'smpl5single', ['summary']]], [], 'Sample review', 'Sample hall'],
            [$a[0], $writes($s), $keptFields($a), (string) $row($s->id)['title'], (string) $row($s->id)['location']]);
    });

    scenario('undo choice: the title changed again in Google Calendar: 409 `google_changed` with the field, nothing sent, the entry not undone, tags the edit changed not put back; Undo anyway after the question: one PATCH, the row holds the title from before the edit', function () use ($db, $setup, $last, $undo, $writes, $row, $there, $undone): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, renamed', 'tagNames' => ['sample-tag']]);
        $entry = $last($s);
        $there($s, 'smpl5single', ['summary' => 'Sample review, at Google']);
        $tags = static fn(): int => (int) $db->scalar('SELECT COUNT(*) FROM event_tags WHERE event_id = ?', [$s->id]);
        $a = $undo($s, $entry);
        checkEq('asked: the code, the sentence, the entry, the field with was, now and yours', [
            'google_changed',
            "After this change to 'Sample review, renamed', the title was changed again: it's 'Sample review, at Google' now. Undo puts back 'Sample review', replacing 'Sample review, at Google'. Nothing was undone.",
            $entry, [['field' => 'title', 'was' => 'Sample review, renamed', 'now' => 'Sample review, at Google', 'yours' => 'Sample review']], [],
        ], [$a[0], $a[1], $a[2]['entry'] ?? null, $a[2]['fields'] ?? null, $a[2]['kept'] ?? null]);
        checkEq('nothing sent, the read stored, the entry not undone, the tag the edit added still there', [[], 'Sample review, at Google', 0, 1],
            [$writes($s), (string) $row($s->id)['title'], $undone($entry), $tags()]);
        // A bare force passes no question.
        checkEq('force alone gets the same question', 'google_changed', $undo($s, $entry, true)[0]);
        // A second change before Undo anyway: the question comes back with the newer value.
        $there($s, 'smpl5single', ['summary' => 'Sample review, at Google again']);
        $a = $undo($s, $entry, false, ['title' => 'Sample review, at Google']);
        checkEq('a second change before Undo anyway asks again with the newer value', ['google_changed', 'Sample review, at Google again', []],
            [$a[0], $a[2]['fields'][0]['now'] ?? null, $writes($s)]);
        $a = $undo($s, $entry, false, ['title' => 'Sample review, at Google again']);
        checkEq('Undo anyway with what the question showed: one PATCH of the title, the title from before, the tag put back off, the entry undone',
            ['ok', [['PATCH', 'smpl5single', ['summary']]], 'Sample review', 'Sample review', 1, 0],
            [$a[0], $writes($s), (string) $row($s->id)['title'], $s->fake->events['smpl5single']['summary'], $undone($entry), $tags()]);
    });

    scenario('undo choice: the title changed again here by a later edit: the undo of the first asks with the later title as now; a poll stored a second rename: the undo asks', function () use ($setup, $last, $undo, $writes, $there): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, first']);
        $entry = $last($s);
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, second']);
        $a = $undo($s, $entry);
        checkEq('asked, not refused as stale: was the first rename, now the second, yours the original', ['google_changed', [['field' => 'title', 'was' => 'Sample review, first', 'now' => 'Sample review, second', 'yours' => 'Sample review']], []],
            [$a[0], $a[2]['fields'] ?? null, $writes($s)]);
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, first']);
        $entry = $last($s);
        $there($s, 'smpl5single', ['summary' => 'Sample review, polled']);
        ($s->poll)();
        $a = $undo($s, $entry);
        checkEq('a rename a poll already stored is asked about too', ['google_changed', 'Sample review, polled', []], [$a[0], $a[2]['fields'][0]['now'] ?? null, $writes($s)]);
    });

    scenario('undo choice: the title already back in Google Calendar: the undo completes with no write', function () use ($setup, $last, $undo, $writes, $row, $there, $undone): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, renamed']);
        $entry = $last($s);
        $there($s, 'smpl5single', ['summary' => 'Sample review']);
        $a = $undo($s, $entry);
        checkEq('no question, nothing sent, the title from before here, the entry undone', ['ok', [], 'Sample review', 1], [$a[0], $writes($s), (string) $row($s->id)['title'], $undone($entry)]);
    });

    scenario('undo choice: redo after the undo: one write; redo after the title changed again in Google Calendar asks', function () use ($setup, $last, $undo, $writes, $row, $there): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, renamed']);
        $undo($s, $last($s));
        $redo = $last($s);
        $a = $undo($s, $redo);
        checkEq('redo: one PATCH of the title, the rename back', ['ok', [['PATCH', 'smpl5single', ['summary']]], 'Sample review, renamed'], [$a[0], $writes($s), (string) $row($s->id)['title']]);
        $undo($s, $last($s));
        $redo = $last($s);
        $there($s, 'smpl5single', ['summary' => 'Sample review, at Google']);
        $a = $undo($s, $redo);
        checkEq('redo over a later change there asks: was what the undo left, yours the rename', ['google_changed', [['field' => 'title', 'was' => 'Sample review', 'now' => 'Sample review, at Google', 'yours' => 'Sample review, renamed']], []],
            [$a[0], $a[2]['fields'] ?? null, $writes($s)]);
    });

    scenario('undo choice: a day: nothing changed since; another day renamed in Google Calendar: the undo of a drag of this day goes through; the repeating event renamed in Google Calendar after this day was renamed: the undo asks with `from` series', function () use ($setup, $last, $undo, $writes, $keptFields, $there, $day, $dayGid, $dayRow, $when): void {
        // A day that keeps its row (a tag of its own), dragged an hour later, undone.
        $s = $setup();
        $s->events->patch($s->u, $s->weekly, $day + ['tagNames' => ['sample-day']]);
        $s->events->patch($s->u, $s->weekly, $day + ['start' => '2027-04-13T10:00:00-07:00', 'end' => '2027-04-13T10:30:00-07:00']);
        $entry = $last($s);
        // Another Tuesday renamed there, not polled: no change to this day.
        $s->fake->seed(['id' => 'smpl5weekly_20270420T160000Z', 'iCalUID' => 'smpl5weekly@google.com', 'recurringEventId' => 'smpl5weekly', 'summary' => 'Sample standup, special',
            'description' => 'Sample notes', 'originalStartTime' => $when('2027-04-20 09:00'), 'start' => $when('2027-04-20 09:00'), 'end' => $when('2027-04-20 09:30')]);
        $a = $undo($s, $entry);
        checkEq('the drag of this day undone: one PATCH of its time, nothing asked, nothing kept, the day back at 09:00', ['ok', [['PATCH', $dayGid, ['start', 'end']]], [], '2027-04-13 16:00:00'],
            [$a[0], $writes($s), $keptFields($a), (string) ($dayRow($s)['start_utc'] ?? '')]);

        // This day renamed here, then the whole repeating event renamed there.
        $s = $setup();
        $s->events->patch($s->u, $s->weekly, $day + ['tagNames' => ['sample-day']]);
        $s->events->patch($s->u, $s->weekly, $day + ['title' => 'Sample standup, this week']);
        $entry = $last($s);
        $there($s, 'smpl5weekly', ['summary' => 'Sample daily']);
        $there($s, $dayGid, ['summary' => 'Sample daily']); // Google writes the new title into the day's record (probe 3.12)
        $a = $undo($s, $entry);
        checkEq('asked about the title, for the whole repeating event, naming the day; nothing sent', [
            'google_changed',
            "After this change to Tue, Apr 13, 2027 of 'Sample standup, this week', the title was changed again, for the whole repeating event: it's 'Sample daily' now. Undo puts back 'Sample standup' for this day, replacing 'Sample daily'. Nothing was undone.",
            [['field' => 'title', 'was' => 'Sample standup, this week', 'now' => 'Sample daily', 'yours' => 'Sample standup', 'from' => 'series']], true, [],
        ], [$a[0], $a[1], $a[2]['fields'] ?? null, isset($a[2]['day']['start'], $a[2]['day']['tzid']), $writes($s)]);
    });

    scenario('undo choice: the event deleted in Google Calendar: the undo is refused as deleted; a day deleted in Google Calendar: its undo is refused with the day\'s sentence', function () use ($setup, $last, $undo, $writes, $there, $day, $dayGid, $undone): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, renamed']);
        $entry = $last($s);
        $there($s, 'smpl5single', ['status' => 'cancelled']);
        $a = $undo($s, $entry);
        checkEq('deleted there: refused as deleted, nothing sent, not undone', ['google_deleted', [], 0], [$a[0], $writes($s), $undone($entry)]);

        $s = $setup();
        $s->events->patch($s->u, $s->weekly, $day + ['tagNames' => ['sample-day']]);
        $s->events->patch($s->u, $s->weekly, $day + ['title' => 'Sample standup, this week']);
        $entry = $last($s);
        $there($s, $dayGid, ['status' => 'cancelled']);
        $a = $undo($s, $entry);
        checkEq('the day deleted there: the day\'s sentence, nothing sent, not undone', [
            'google_conflict',
            "That day is no longer one of the days of 'Sample standup': the repeating event was changed in Google Calendar. Nothing was undone, and the calendar shows it as Google Calendar has it now.",
            [], 0,
        ], [$a[0], $a[1], $writes($s), $undone($entry)]);
    });

    scenario('undo choice: the bare Undo over a second rename answers 409 with the entry; a plugin run\'s undo lists the entry as refused with the first sentence and undoes the rest', function () use ($db, $setup, $last, $there, $undone, $row): void {
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, renamed']);
        $entry = $last($s);
        $there($s, 'smpl5single', ['summary' => 'Sample review, at Google']);
        $caught = null;
        try {
            $s->undo->undoLatest($s->u);
        } catch (HttpError $e) {
            $caught = [$e->errorCode, $e->extra['entry'] ?? null];
        }
        checkEq('the bare Undo: the question, naming the entry it would have undone', ['google_changed', $entry], $caught);

        // A run that renamed the single event and moved the all-day one; the rename was changed again there.
        $s = $setup();
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, renamed']);
        $renamed = $last($s);
        $s->events->patch($s->u, $s->allDay, ['start' => '2027-04-02', 'end' => '2027-04-03', 'allDay' => true]);
        $moved = $last($s);
        $db->run('UPDATE mutations SET run_id = ? WHERE id IN (?, ?)', ['sample-run', $renamed, $moved]);
        $there($s, 'smpl5single', ['summary' => 'Sample review, at Google']);
        $out = $s->undo->undoRun($s->u, 'sample-run');
        checkEq('the run\'s undo: the move undone, the rename listed as refused with the first sentence, never overwritten, not counted as skipped', [
            1, 0, [['id' => $renamed, 'code' => 'google_changed', 'message' => "After this change to 'Sample review, renamed', the title was changed again: it's 'Sample review, at Google' now."]],
            0, 1, 'Sample review, at Google',
        ], [$out['undone'], $out['skipped'], $out['refused'], $undone($renamed), $undone($moved), (string) $row($s->id)['title']]);
    });

    scenario('undo choice: setting off: the undo of a rename after a change to its time in Google Calendar is refused with the sentence it had', function () use ($setup, $last, $undo, $writes, $there, $when): void {
        $s = $setup(false);
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, renamed']);
        $entry = $last($s);
        $there($s, 'smpl5single', ['start' => $when('2027-03-30 17:00'), 'end' => $when('2027-03-30 18:00')]);
        $a = $undo($s, $entry);
        checkEq('refused whole, nothing sent, no `entry` or `fields`',
            ['google_conflict', 'Newer changes exist for this item; undoing would overwrite them. This event changed in Google Calendar since you last saw it.', [], false],
            [$a[0], $a[1], $writes($s), isset($a[2]['fields'])]);
    });

    // A time is one value (the page, (b)): compared, asked about and sent back whole (start, end, all-day and zone),
    // in each form. Every value below is written by hand: the day as the edit left it (was), as another client then
    // moved it in Google Calendar (now), and as it was before the edit (yours).
    $whole = static fn(string $s, string $e, bool $allDay = false, string $tz = 'America/Los_Angeles'): array => ['start' => $s, 'end' => $e, 'allDay' => $allDay, 'tzid' => $tz];
    $instant = static fn(object $s, string $gid, string $end = 'start'): string => isset($s->fake->events[$gid][$end]['date']) ? (string) $s->fake->events[$gid][$end]['date']
        : (new DateTimeImmutable((string) $s->fake->events[$gid][$end]['dateTime']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
    // The undo of a time change asked about, then Undo anyway with what the question showed: [the asked fields, what Undo anyway sent, where Google Calendar then has the start and end].
    $askedThenAnyway = static function (object $s, int $entry, string $gid, ?string $device = null) use ($undo, $writes, $instant): array {
        $a = $undo($s, $entry, false, [], $device);
        $asked = [$a[0], $a[2]['fields'] ?? null, $writes($s)];
        $now = $a[2]['fields'][0]['now'] ?? [];
        $b = $undo($s, $entry, false, $now, $device);
        return [$asked, [$b[0], $writes($s)], [$instant($s, $gid), $instant($s, $gid, 'end')]];
    };

    scenario('undo choice, a time whole: a day before a clock change, on its night and after it, each dragged, moved again in Google Calendar and its drag undone', function () use ($db, $setup, $last, $there, $when, $whole, $askedThenAnyway): void {
        // Daily at 01:30 Pacific for half an hour from 12 March 2027; the clocks go forward at 02:00 on 14 March, so that
        // night's day ends at 03:00 daylight time, and the days after start an hour earlier in UTC.
        foreach ([
            'the day before' => ['2027-03-12', '20270312T093000Z', '-08:00', '-08:00', '02:00', '09:30', '10:00'],
            'the changeover night' => ['2027-03-14', '20270314T093000Z', '-08:00', '-07:00', '03:00', '09:30', '10:00'],
            'the day after' => ['2027-03-15', '20270315T083000Z', '-07:00', '-07:00', '02:00', '08:30', '09:00'],
        ] as $which => [$date, $key, $startOffset, $offset, $endClock, $utcStart, $utcEnd]) {
            $s = $setup();
            $s->fake->seed(['id' => 'smpl5night', 'iCalUID' => 'smpl5night@google.com', 'summary' => 'Sample night shift',
                'start' => $when('2027-03-12 01:30'), 'end' => $when('2027-03-12 02:00'), 'recurrence' => ['RRULE:FREQ=DAILY;COUNT=5']]);
            ($s->poll)();
            $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, 'smpl5night']);
            $gid = 'smpl5night_' . $key;
            // Dragged to 04:30 for half an hour here; then moved to 06:30 in Google Calendar, not polled.
            $s->events->patch($s->u, $master, ['scope' => 'this', 'instanceStart' => $date . 'T01:30:00' . $startOffset, 'start' => $date . 'T04:30:00' . $offset, 'end' => $date . 'T05:00:00' . $offset]);
            $entry = $last($s);
            $there($s, $gid, ['start' => $when($date . ' 06:30'), 'end' => $when($date . ' 07:00')]);
            checkEq("$which: asked about the time, each value whole on the event's clock; Undo anyway sends the start and end, and Google Calendar holds the day at 01:30 Pacific again", [
                ['google_changed', [['field' => 'time',
                    'was' => $whole($date . 'T04:30:00' . $offset, $date . 'T05:00:00' . $offset),
                    'now' => $whole($date . 'T06:30:00' . $offset, $date . 'T07:00:00' . $offset),
                    'yours' => $whole($date . 'T01:30:00' . $startOffset, $date . 'T' . $endClock . ':00' . $offset), 'from' => 'day']], []],
                ['ok', [['PATCH', $gid, ['start', 'end']]]],
                ["$date $utcStart", "$date $utcEnd"],
            ], $askedThenAnyway($s, $entry, $gid));
            checkEq("$which: the time sent back names its zone", 'America/Los_Angeles', $s->fake->events[$gid]['start']['timeZone'] ?? null);
        }
    });

    scenario('undo choice, a time whole: an all-day event and an all-day day, each moved, moved again in Google Calendar and the move undone', function () use ($db, $setup, $last, $there, $whole, $askedThenAnyway): void {
        $s = $setup();
        $s->events->patch($s->u, $s->allDay, ['start' => '2027-04-02', 'end' => '2027-04-03', 'allDay' => true]);
        $entry = $last($s);
        $there($s, 'smpl5allday', ['start' => ['date' => '2027-04-05'], 'end' => ['date' => '2027-04-06']]);
        checkEq('an all-day event: asked with dates whole (all-day, the zone UTC); Undo anyway sends the dates, and Google Calendar holds 31 March again', [
            ['google_changed', [['field' => 'time', 'was' => $whole('2027-04-02T00:00:00+00:00', '2027-04-03T00:00:00+00:00', true, 'UTC'),
                'now' => $whole('2027-04-05T00:00:00+00:00', '2027-04-06T00:00:00+00:00', true, 'UTC'),
                'yours' => $whole('2027-03-31T00:00:00+00:00', '2027-04-01T00:00:00+00:00', true, 'UTC')]], []],
            ['ok', [['PATCH', 'smpl5allday', ['start', 'end']]]],
            ['2027-03-31', '2027-04-01'],
        ], $askedThenAnyway($s, $entry, 'smpl5allday'));

        // An all-day repeating event, Mondays from 3 May 2027: the day of 10 May moved to the 11th here, to the 12th there.
        $s = $setup();
        $s->fake->seed(['id' => 'smpl5days', 'iCalUID' => 'smpl5days@google.com', 'summary' => 'Sample day off', 'start' => ['date' => '2027-05-03'], 'end' => ['date' => '2027-05-04'],
            'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']]);
        ($s->poll)();
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, 'smpl5days']);
        $s->events->patch($s->u, $master, ['scope' => 'this', 'instanceStart' => '2027-05-10', 'start' => '2027-05-11', 'end' => '2027-05-12', 'allDay' => true]);
        $entry = $last($s);
        $there($s, 'smpl5days_20270510', ['start' => ['date' => '2027-05-12'], 'end' => ['date' => '2027-05-13']]);
        checkEq('an all-day day: asked with dates whole, for the day; Undo anyway sends the dates, and Google Calendar holds 10 May again', [
            ['google_changed', [['field' => 'time', 'was' => $whole('2027-05-11T00:00:00+00:00', '2027-05-12T00:00:00+00:00', true, 'UTC'),
                'now' => $whole('2027-05-12T00:00:00+00:00', '2027-05-13T00:00:00+00:00', true, 'UTC'),
                'yours' => $whole('2027-05-10T00:00:00+00:00', '2027-05-11T00:00:00+00:00', true, 'UTC'), 'from' => 'day']], []],
            ['ok', [['PATCH', 'smpl5days_20270510', ['start', 'end']]]],
            ['2027-05-10', '2027-05-11'],
        ], $askedThenAnyway($s, $entry, 'smpl5days_20270510'));
    });

    scenario('undo choice, a time whole: an event that keeps the same clock time in any zone, moved and its move undone from a device in another zone', function () use ($db, $setup, $last, $there, $whole, $askedThenAnyway): void {
        // 09:00 for an hour on whatever clock it is seen on; made, moved to 11:00 and undone from a device in New York.
        $s = $setup();
        $s->events->viewFrom('America/New_York');
        $id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample walk', 'start' => '2027-03-03T09:00:00-05:00', 'end' => '2027-03-03T10:00:00-05:00',
            'allDay' => false, 'tzid' => 'America/New_York', 'floating' => true])['eventId'];
        $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
        $s->events->patch($s->u, $id, ['start' => '2027-03-03T11:00:00-05:00', 'end' => '2027-03-03T12:00:00-05:00']);
        $entry = $last($s);
        // Another client moves it to 13:00 on the clock Google Calendar holds it on (New York, where it was made: 09:00 there is 14:00 UTC).
        $there($s, $gid, ['start' => ['dateTime' => '2027-03-03T13:00:00-05:00', 'timeZone' => 'America/New_York'], 'end' => ['dateTime' => '2027-03-03T14:00:00-05:00', 'timeZone' => 'America/New_York']]);
        $zone = 'America/New_York'; // the zone it was made in, served with each time
        checkEq('asked with each value on the device\'s clock, whole; Undo anyway with those values goes through, and Google Calendar holds 09:00 New York (14:00 UTC) again', [
            ['google_changed', [['field' => 'time', 'was' => $whole('2027-03-03T11:00:00-05:00', '2027-03-03T12:00:00-05:00', false, $zone),
                'now' => $whole('2027-03-03T13:00:00-05:00', '2027-03-03T14:00:00-05:00', false, $zone),
                'yours' => $whole('2027-03-03T09:00:00-05:00', '2027-03-03T10:00:00-05:00', false, $zone)]], []],
            ['ok', [['PATCH', $gid, ['start', 'end']]]],
            ['2027-03-03 14:00', '2027-03-03 15:00'],
        ], $askedThenAnyway($s, $entry, $gid, 'America/New_York'));
    });

    // #241: one capture of "a row and its links" (Undo::linksOf). Each former site's entry holds every link table: here
    // the trip link of an event in a trip, which the two retired captures left out.
    scenario('undo choice: an entry made through each former capture site holds every link table (#241)', function () use ($db, $setup, $last, $undo): void {
        $s = $setup();
        $local = (int) $s->d->calendars->create($s->u, ['name' => 'Sample plans'])['id'];
        $trip = (int) $s->events->create($s->u, ['calendarId' => $local, 'title' => 'Sample trip', 'start' => '2027-03-28T09:00:00-07:00',
            'end' => '2027-04-02T19:00:00-07:00', 'tzid' => 'America/Los_Angeles', 'isContainer' => true])['eventId'];
        $s->trips->attach($s->u, $trip, $s->id);
        $s->events->patch($s->u, $s->id, ['tagNames' => ['sample-tag']]);
        $tables = static function (int $entry) use ($db): array {
            $m = $db->one('SELECT before_json, after_json FROM mutations WHERE id = ?', [$entry]);
            $names = static fn(?string $json): array => array_keys(array_filter(json_decode((string) $json, true)['tables'] ?? []));
            return [$names($m['before_json']), $names($m['after_json'])];
        };
        $all = ['events', 'event_tags', 'event_links'];
        // Events' site (was Labels::eventLinkRows): an edit of a Google event.
        $s->events->patch($s->u, $s->id, ['title' => 'Sample review, renamed']);
        $edit = $last($s);
        checkEq('an edit\'s entry (Events) holds the row, its tag and its trip link, before and after', [$all, $all], array_map(static fn(array $t): array => array_values(array_intersect($all, $t)), $tables($edit)));
        // GoogleUndo's site (was rowTables): the undo's own entry.
        $undo($s, $edit);
        checkEq('the undo\'s entry (GoogleUndo) holds the same, before and after', [$all, $all], array_map(static fn(array $t): array => array_values(array_intersect($all, $t)), $tables($last($s))));
        // A local event's edit, the same site in Events.
        $own = (int) $s->events->create($s->u, ['calendarId' => $local, 'title' => 'Sample dinner', 'start' => '2027-03-29T18:00:00-07:00', 'end' => '2027-03-29T19:00:00-07:00', 'tzid' => 'America/Los_Angeles'])['eventId'];
        $s->trips->attach($s->u, $trip, $own);
        $s->events->patch($s->u, $own, ['title' => 'Sample dinner, moved']);
        checkEq('a local edit\'s entry holds the row and its trip link, before and after', [['events', 'event_links'], ['events', 'event_links']],
            array_map(static fn(array $t): array => array_values(array_intersect($all, $t)), $tables($last($s))));
    });
};
