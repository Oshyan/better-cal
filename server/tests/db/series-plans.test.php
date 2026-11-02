<?php

declare(strict_types=1);

// Whole-series edits on the series model (1.5.0, docs/design/series-model.md,
// The API): a preview writes nothing, the commit writes the plan it showed,
// a stale plan or a missing one is a 409 with the new plan, a refusal a 422,
// Undo puts every row back. Edited days' own fields (migration 063) and the
// writers that keep them.

use BetterCal\Domain\Settings;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $home = (int) Settings::defaultCalendarId($db, $u);

    // Every row of a series as stored, without the timestamps a write touches.
    $rows = static function (int $id) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT * FROM events WHERE id = ? OR recurrence_parent_id = ? ORDER BY id', [$id, $id]) as $row) {
            unset($row['updated_at']);
            $out[] = $row;
        }
        return $out;
    };
    $answer = static function (callable $fn): array {
        try {
            $fn();
            return [null, null];
        } catch (HttpError $e) {
            return [$e->status . ' ' . $e->errorCode, $e->extra['plan'] ?? null];
        }
    };
    $weekly = static function (string $title, string $rrule = 'FREQ=WEEKLY;BYDAY=TU') use ($d, $u, $home): int {
        return (int) $d->events->create($u, eventAt($home, $title, '2027-01-05', ['rrule' => $rrule]))['eventId'];
    };
    $entries = static fn(): int => (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u]);

    scenario('series plans: preview, commit, stale and missing plans', function () use ($d, $u, $db, $rows, $answer, $weekly, $entries): void {
        $choir = $weekly('Choir');
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-04-13T18:00:00+00:00', 'title' => 'Rehearsal', 'location' => 'Sample Hall']);
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-03-23T18:00:00+00:00', 'start' => '2027-03-26T18:00:00+00:00', 'end' => '2027-03-26T19:00:00+00:00']);
        $d->events->deleteEvent($u, $choir, 'this', '2027-03-30T18:00:00+00:00');
        $drag = ['scope' => 'all', 'instanceStart' => '2027-03-09T18:00:00+00:00', 'start' => '2027-03-10T18:00:00+00:00', 'end' => '2027-03-10T19:00:00+00:00'];
        $before = $rows($choir);
        $journal = $entries();
        $plan = $d->events->previewPatch($u, $choir, $drag + ['preview' => true]);
        checkEq('a preview writes nothing and journals nothing', [$before, $journal], [$rows($choir), $entries()]);
        checkEq('the preview is the plan: the rule, the moved-days group, Rehearsal\'s own title and place kept', [
            'FREQ=WEEKLY;BYDAY=WE', ['movedDays'], ['title', 'location'],
        ], [$plan['rule']['after'], array_column($plan['groups'], 'id'),
            json_decode((string) $db->scalar("SELECT override_fields FROM events WHERE recurrence_parent_id = ? AND title = 'Rehearsal'", [$choir]), true)]);
        [$code, $again] = $answer(fn() => $d->events->patch($u, $choir, $drag));
        checkEq('a drag sent without a plan is 409 series_plan_required with the plan, nothing written', ['409 series_plan_required', $plan['hash'], $before],
            [$code, $again['hash'] ?? null, $rows($choir)]);
        [$code] = $answer(fn() => $d->events->patch($u, $choir, $drag + ['plan' => ['hash' => $plan['hash'], 'choices' => []]]));
        checkEq('a commit leaving a group out (counting on its preselected option) is 409 again', '409 series_plan_required', $code);
        // Another change to the series since the preview: the plan is stale.
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-04-20T18:00:00+00:00', 'title' => 'Sectionals']);
        $stale = $rows($choir);
        [$code, $fresh] = $answer(fn() => $d->events->patch($u, $choir, $drag + ['plan' => ['hash' => $plan['hash'], 'choices' => ['movedDays' => 'keep']]]));
        checkEq('a commit after another change to the series is 409 series_plan_changed with the new plan; nothing written', ['409 series_plan_changed', true, $stale],
            [$code, ($fresh['hash'] ?? $plan['hash']) !== $plan['hash'], $rows($choir)]);
        $d->events->patch($u, $choir, $drag + ['plan' => ['hash' => $fresh['hash'], 'choices' => ['movedDays' => 'keep']]]);
        $master = $db->one('SELECT rrule, start_utc, exdates_json FROM events WHERE id = ?', [$choir]);
        $keys = array_column($db->all('SELECT recurrence_instance_utc FROM events WHERE recurrence_parent_id = ? ORDER BY recurrence_instance_utc', [$choir]), 'recurrence_instance_utc');
        $planned = array_values(array_filter(array_map(static fn(array $x): ?string => $x['kind'] === 'edited' ? str_replace(['T', 'Z'], [' ', ''], (string) $x['newKey']) : null, $fresh['days'])));
        sort($planned);
        checkEq('the commit writes what the plan showed: rule, start, the skip and every edited day on its new key', [
            'FREQ=WEEKLY;BYDAY=WE', '2027-01-06 18:00:00', ['2027-03-31 18:00:00'], $planned,
        ], [$master['rrule'], $master['start_utc'], json_decode((string) $master['exdates_json'], true), $keys]);
        $d->undo->undoLatest($u);
        checkEq('undone, every row of the series is as before the commit', $stale, $rows($choir));
    });

    scenario('series plans: direct field edits, refusals', function () use ($d, $u, $db, $answer, $weekly, $home): void {
        $plain = $weekly('Swim');
        $d->events->patch($u, $plain, ['scope' => 'this', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'start' => '2027-01-19T19:00:00+00:00', 'end' => '2027-01-19T20:00:00+00:00']);
        $d->events->patch($u, $plain, ['scope' => 'all', 'title' => 'Swim practice']);
        checkEq('a rename with no edited day owning its title is direct: applied at once, the edited day takes the new title', ['Swim practice', 'Swim practice'], [
            $db->scalar('SELECT title FROM events WHERE id = ?', [$plain]), $db->scalar('SELECT title FROM events WHERE recurrence_parent_id = ?', [$plain]),
        ]);
        $owned = $weekly('Choir');
        $d->events->patch($u, $owned, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Rehearsal']);
        [$code, $plan] = $answer(fn() => $d->events->patch($u, $owned, ['scope' => 'all', 'title' => 'Chorus']));
        checkEq('the same rename where a day owns its title is 409 series_plan_required, the group to give it the title too', ['409 series_plan_required', ['ownTitle']], [$code, array_column($plan['groups'] ?? [], 'id')]);
        planned($d->events, $u, $owned, ['scope' => 'all', 'title' => 'Chorus'], ['ownTitle' => 'give']);
        checkEq('"Give it the new title too": the day takes it and no longer owns its title', ['Chorus', []], [
            $db->scalar('SELECT title FROM events WHERE recurrence_parent_id = ?', [$owned]),
            json_decode((string) $db->scalar('SELECT override_fields FROM events WHERE recurrence_parent_id = ?', [$owned]), true),
        ]);
        $weekno = (int) $d->events->create($u, eventAt($home, 'Kickoff', '2027-01-04', ['rrule' => 'FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO']))['eventId'];
        $before = $db->one('SELECT * FROM events WHERE id = ?', [$weekno]);
        [$code, $plan] = $answer(fn() => $d->events->patch($u, $weekno, ['scope' => 'all', 'instanceStart' => '2027-01-04T18:00:00+00:00', 'start' => '2027-01-05T18:00:00+00:00', 'end' => '2027-01-05T19:00:00+00:00']));
        checkEq('a refused edit is 422 series_refused with its reason; nothing written', ['422 series_refused', 'weekno', $before], [$code, $plan['refusals'][0]['code'] ?? null, $db->one('SELECT * FROM events WHERE id = ?', [$weekno])]);
        $gym = (int) $d->events->create($u, eventAt($home, 'Gym', '2027-01-04', ['rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR']))['eventId'];
        $edit = ['scope' => 'all', 'instanceStart' => '2027-03-10T18:00:00+00:00', 'start' => '2027-03-12T18:00:00+00:00', 'end' => '2027-03-12T19:00:00+00:00'];
        $plan = $d->events->previewPatch($u, $gym, $edit);
        [$code] = $answer(fn() => $d->events->patch($u, $gym, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => ['reading' => 'member']]]));
        checkEq('naming a disabled reading is 422 series_refused', '422 series_refused', $code);
        [$code] = $answer(fn() => $d->events->patch($u, $gym, $edit + ['preview' => true]));
        checkEq('patch() never applies a preview', '400 preview_unavailable', $code);
    });

    scenario('series plans: the acceptance cases committed and undone', function () use ($d, $u, $db, $rows, $home): void {
        // Acceptance 1: Cello lesson, keep 6 + this one on its own.
        $cello = (int) $d->events->create($u, eventAt($home, 'Cello lesson', '2027-01-31', ['rrule' => 'FREQ=MONTHLY;BYMONTHDAY=31;COUNT=6']))['eventId'];
        $before = $rows($cello);
        $count = (int) $db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ?', [$u]);
        $plan = planned($d->events, $u, $cello, ['scope' => 'all', 'instanceStart' => '2027-10-31T18:00:00+00:00', 'start' => '2027-11-01T18:00:00+00:00', 'end' => '2027-11-01T19:00:00+00:00'], ['bound' => 'count']);
        checkEq('Cello lesson, keep 6 + this one: COUNT=6 from 1 Feb and an event of its own on 1 Nov', ['FREQ=MONTHLY;BYMONTHDAY=1;COUNT=6', '2027-02-01 18:00:00', 1, 'Cello lesson'], [
            $db->scalar('SELECT rrule FROM events WHERE id = ?', [$cello]), $db->scalar('SELECT start_utc FROM events WHERE id = ?', [$cello]),
            (int) $db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ?', [$u]) - $count,
            $db->scalar("SELECT title FROM events WHERE user_id = ? AND start_utc = '2027-11-01 18:00:00' AND rrule IS NULL AND recurrence_parent_id IS NULL", [$u]),
        ]);
        $d->undo->undoLatest($u);
        checkEq('undone: the 31st series back, the separate event gone', [$before, $count], [$rows($cello), (int) $db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ? AND deleted_at IS NULL', [$u])]);
        // Acceptance 2: Pottery, the gained Fri 8 Jan left out (preselected).
        $pottery = (int) $d->events->create($u, eventAt($home, 'Pottery', '2027-01-06', ['rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=5']))['eventId'];
        $before = $rows($pottery);
        planned($d->events, $u, $pottery, ['scope' => 'all', 'instanceStart' => '2027-01-11T18:00:00+00:00', 'start' => '2027-01-15T18:00:00+00:00', 'end' => '2027-01-15T19:00:00+00:00']);
        checkEq('Pottery, Mondays to Fridays: COUNT=6 with Fri 8 Jan skipped', ['FREQ=WEEKLY;BYDAY=WE,FR;COUNT=6', ['2027-01-08 18:00:00']], [
            $db->scalar('SELECT rrule FROM events WHERE id = ?', [$pottery]), json_decode((string) $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$pottery]), true),
        ]);
        $first = $rows($pottery);
        planned($d->events, $u, $pottery, ['scope' => 'all', 'instanceStart' => '2027-01-15T18:00:00+00:00', 'start' => '2027-01-14T18:00:00+00:00', 'end' => '2027-01-14T19:00:00+00:00']);
        checkEq('then every Friday to Thursday: the skip maps by its member to Thu 7 Jan', ['FREQ=WEEKLY;BYDAY=WE,TH;COUNT=6', ['2027-01-07 18:00:00']], [
            $db->scalar('SELECT rrule FROM events WHERE id = ?', [$pottery]), json_decode((string) $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$pottery]), true),
        ]);
        $d->undo->undoLatest($u);
        checkEq('undone: the Friday series with its skip on 8 Jan', $first, $rows($pottery));
        $d->undo->undoLatest($u);
        checkEq('undone again: Monday and Wednesday, COUNT=5, no skip', $before, $rows($pottery));
        // A new rule: edited days it doesn't produce become events of their own.
        $choir = (int) $d->events->create($u, eventAt($home, 'Choir', '2027-01-05', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU']))['eventId'];
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-04-13T18:00:00+00:00', 'title' => 'Rehearsal, spring']);
        $before = $rows($choir);
        planned($d->events, $u, $choir, ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO,TH']);
        checkEq('Choir to Monday and Thursday: Rehearsal becomes an event of its own on 13 Apr', [null, null, '2027-04-13 18:00:00'], array_values(
            $db->one("SELECT recurrence_parent_id, override_fields, start_utc FROM events WHERE title = 'Rehearsal, spring' AND user_id = ?", [$u])));
        $d->undo->undoLatest($u);
        checkEq('undone: Rehearsal is the series\' edited day again', $before, $rows($choir));
    });

    scenario('own fields: what each writer keeps', function () use ($d, $u, $db, $weekly): void {
        $own = static fn(int $master, string $key) => json_decode((string) $db->scalar('SELECT override_fields FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ?', [$master, $key]), true);
        $s = $weekly('Choir');
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Rehearsal']);
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'start' => '2027-01-21T18:00:00+00:00', 'end' => '2027-01-21T19:00:00+00:00']);
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-26T18:00:00+00:00', 'title' => 'Choir']);
        checkEq('"this event" edits own what they changed (a title typed equal to the series\' is still the day\'s own)', [['title'], ['date'], ['title']], [
            $own($s, '2027-01-12 18:00:00'), $own($s, '2027-01-19 18:00:00'), $own($s, '2027-01-26 18:00:00'),
        ]);
        $d->events->setAttendance($u, $s, 'going', 'this', '2027-02-02T18:00:00+00:00');
        $d->events->setAttendance($u, $s, 'going', 'this', '2027-01-12T18:00:00+00:00');
        checkEq('an answer for one day is that day\'s own', [['attendance'], ['title', 'attendance']], [$own($s, '2027-02-02 18:00:00'), $own($s, '2027-01-12 18:00:00')]);
        $d->events->setAttendance($u, $s, 'interested', 'all');
        checkEq('the series\' answer reaches every day: none owns its answer', [[], ['title']], [$own($s, '2027-02-02 18:00:00'), $own($s, '2027-01-12 18:00:00')]);
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'useSeries' => ['title']]);
        checkEq('"Use series value" on the only own field: the row goes, the day is an occurrence again', 0,
            (int) $db->scalar("SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-01-12 18:00:00'", [$s]));
        $d->undo->undoLatest($u);
        checkEq('undone: the row and its own list are back', ['title'], $own($s, '2027-01-12 18:00:00'));
        $d->events->patch($u, $s, ['scope' => 'following', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'title' => 'Choir, spring term']);
        $split = (int) $db->scalar("SELECT id FROM events WHERE title = 'Choir, spring term' AND recurrence_parent_id IS NULL AND user_id = ?", [$u]);
        checkEq('"this and following" carries the moved day with its own list', ['date'], $own($split, '2027-01-19 18:00:00'));
        // The migration's backfill: a row with no list, filled by equality.
        $db->run("UPDATE events SET override_fields = NULL WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-01-12 18:00:00'", [$s]);
        $shown = $db->one("SELECT start_utc, end_utc, title FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-01-12 18:00:00'", [$s]);
        // A feed's poll (and Google's, which goes through it): ownership from what changed.
        $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample feed', 'kind' => 'subscribed', 'color' => '#336699']);
        $feeds = new BetterCal\Domain\Feeds($db);
        $calendar = $db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]);
        $ev = static fn(string $title, ?string $key, string $start, string $loc = 'Sample Hall'): array => ['uid' => 'sample-feed-series', 'title' => $title, 'description' => null,
            'location' => $loc, 'url' => null, 'start_utc' => $start, 'end_utc' => substr($start, 0, 11) . '19:00:00', 'all_day' => 0, 'tzid' => 'UTC',
            'rrule' => $key === null ? 'FREQ=WEEKLY;BYDAY=TU' : null, 'exdates' => [], 'status' => 'confirmed', 'recurrence_instance_utc' => $key];
        $feedOwn = static fn() => json_decode((string) $db->scalar("SELECT override_fields FROM events WHERE calendar_id = ? AND recurrence_instance_utc = '2027-01-12 18:00:00'", [$calendarId]), true);
        $feeds->sync($calendar, [$ev('Choir', null, '2027-01-05 18:00:00'), $ev('Rehearsal', '2027-01-12 18:00:00', '2027-01-12 18:00:00')]);
        checkEq('sync: an edited day never seen, by equality', ['title'], $feedOwn());
        $feeds->sync($calendar, [$ev('Rehearsal', null, '2027-01-05 18:00:00'), $ev('Rehearsal', '2027-01-12 18:00:00', '2027-01-12 18:00:00')]);
        checkEq('sync: echoed unchanged after the series took the same title, it keeps its own title', ['title'], $feedOwn());
        $feeds->sync($calendar, [$ev('Choir', null, '2027-01-05 18:00:00'), $ev('Rehearsal', '2027-01-12 18:00:00', '2027-01-12 18:00:00', 'Sample Annex')]);
        checkEq('sync: its place changed there to one the master doesn\'t have is its own', ['title', 'location'], $feedOwn());
        $feeds->sync($calendar, [$ev('Choir', null, '2027-01-05 18:00:00'), $ev('Choir', '2027-01-12 18:00:00', '2027-01-12 18:00:00', 'Sample Annex')]);
        checkEq('sync: its title put back to the series\' there is inherited', ['location'], $feedOwn());
        $migration = require dirname(__DIR__, 2) . '/migrations/063_override_fields.php';
        $said = $migration($db);
        checkEq('migration 063 run again fills only the rows still without a list, changing no value', [true, ['title'], $shown], [
            str_contains($said, 'filled 1 edited day'), $own($s, '2027-01-12 18:00:00'),
            $db->one("SELECT start_utc, end_utc, title FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-01-12 18:00:00'", [$s]),
        ]);
    });
    scenario('own fields: imported edited days compare every field, in both import orders', function () use ($d, $u, $db, $home, $answer): void {
        $importer = new BetterCal\Domain\CalendarImport($db, $d->calendars);
        $vevent = static fn(string $uid, string $start, string $summary, ?string $rid = null, string $more = '', bool $rule = false): string => "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20261101T000000Z\r\n"
            . ($rid !== null ? "RECURRENCE-ID:$rid\r\n" : '') . "DTSTART:$start\r\nDTEND:" . substr($start, 0, 9) . "190000Z\r\nSUMMARY:$summary\r\n"
            . ($rule ? "RRULE:FREQ=WEEKLY;BYDAY=TU\r\n" : '') . $more . "END:VEVENT\r\n";
        $file = static fn(string ...$events): string => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\n" . implode('', $events) . "END:VCALENDAR\r\n";
        // The override first in the file, its master after it; it differs only in its notes.
        $ics = $file(
            $vevent('choir-import@example.test', '20270309T180000Z', 'Choir I', '20270309T180000Z', "DESCRIPTION:Only this day: bring the folder\r\n"),
            $vevent('choir-import@example.test', '20270105T180000Z', 'Choir I', null, "DESCRIPTION:Weekly practice\r\n", true),
        );
        $cal = (int) $importer->importIcs($u, $ics, 'Imported choir')['calendarId'];
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_parent_id IS NULL', [$cal]);
        checkEq('import: an edited day differing only in its notes owns its notes', ['description'],
            json_decode((string) $db->scalar('SELECT override_fields FROM events WHERE recurrence_parent_id = ?', [$master]), true));
        [$code, $plan] = $answer(fn() => $d->events->patch($u, $master, ['scope' => 'all', 'description' => 'New series notes']));
        checkEq('a series notes change then asks about that day (not direct), and writes nothing', ['409 series_plan_required', ['ownDescription'], 'Only this day: bring the folder'], [
            $code, array_column($plan['groups'] ?? [], 'id'), $db->scalar('SELECT description FROM events WHERE recurrence_parent_id = ?', [$master]),
        ]);
        // Into a calendar that already holds the master: the override alone comes in.
        $importer->importIcs($u, $file($vevent('band-import@example.test', '20270105T180000Z', 'Band', null, '', true)), 'ignored', null, $home);
        $importer->importIcs($u, $file(
            $vevent('band-import@example.test', '20270105T180000Z', 'Band', null, '', true),
            $vevent('band-import@example.test', '20270112T180000Z', 'Band, sectionals', '20270112T180000Z', "LOCATION:Sample Hall\r\n"),
        ), 'ignored', null, $home);
        checkEq('import into a calendar holding the master: the edited day gets its list by equality, never null', ['title', 'location'],
            json_decode((string) $db->scalar("SELECT override_fields FROM events WHERE calendar_id = ? AND uid = 'band-import@example.test' AND recurrence_parent_id IS NOT NULL", [$home]), true));
    });

    scenario('own fields: a CalDAV client\'s PUT', function () use ($d, $u, $db, $home): void {
        $token = (new BetterCal\Domain\ApiTokens($db))->create($u, 'Sample phone');
        $dav = new BetterCal\Dav\CalendarBackend($db, $d->undo, fn() => BetterCal\Domain\RequestAuthority::credential($u, (int) $token['id'], null));
        $object = static fn(string $series, string $day, string $place = 'Sample Hall'): string => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:dav-series@example.test\r\nDTSTAMP:20261101T000000Z\r\nDTSTART:20270105T180000Z\r\nDTEND:20270105T190000Z\r\nSUMMARY:$series\r\nRRULE:FREQ=WEEKLY;BYDAY=TU\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:dav-series@example.test\r\nDTSTAMP:20261101T000000Z\r\nRECURRENCE-ID:20270112T180000Z\r\nDTSTART:20270112T180000Z\r\nDTEND:20270112T190000Z\r\nSUMMARY:$day\r\nLOCATION:$place\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
        $own = static fn() => json_decode((string) $db->scalar("SELECT override_fields FROM events WHERE calendar_id = ? AND uid = 'dav-series@example.test' AND recurrence_parent_id IS NOT NULL", [$home]), true);
        $dav->createCalendarObject($home, 'dav-series@example.test.ics', $object('Choir', 'Rehearsal'));
        checkEq('PUT: a new edited day, by equality (its title and place differ)', ['title', 'location'], $own());
        $dav->updateCalendarObject($home, 'dav-series@example.test.ics', $object('Rehearsal', 'Rehearsal'));
        checkEq('PUT: echoed unchanged after the series took the same title, it keeps its own title', ['title', 'location'], $own());
        $dav->updateCalendarObject($home, 'dav-series@example.test.ics', $object('Choir', 'Choir'));
        checkEq('PUT: its title changed there to the series\' is inherited', ['location'], $own());
        $dav->updateCalendarObject($home, 'dav-series@example.test.ics', $object('Choir', 'Choir', 'Sample Annex'));
        checkEq('PUT: its place changed there to another the master doesn\'t have stays its own', ['location'], $own());
    });

    scenario('edited days: all events from the day\'s own row, and its series on the occurrence', function () use ($d, $u, $db, $weekly, $answer): void {
        $choir = $weekly('Choir');
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-03-16T18:00:00+00:00', 'title' => 'Rehearsal', 'location' => 'Sample Hall']);
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-03-23T18:00:00+00:00', 'start' => '2027-03-25T18:00:00+00:00', 'end' => '2027-03-25T19:00:00+00:00']);
        $rehearsal = (int) $db->scalar("SELECT id FROM events WHERE recurrence_parent_id = ? AND title = 'Rehearsal'", [$choir]);
        $moved = (int) $db->scalar("SELECT id FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-03-23 18:00:00'", [$choir]);
        $occ = $d->events->serializeSingle($db->one('SELECT * FROM events WHERE id = ?', [$rehearsal]));
        checkEq('an edited day\'s occurrence names its series: seriesId, seriesTitle, seriesKey', [$choir, 'Choir', '2027-03-16T18:00:00Z'],
            [$occ['seriesId'] ?? null, $occ['seriesTitle'] ?? null, $occ['seriesKey'] ?? null]);
        // A drag of the edited day with all events: the series' drag, from its key.
        $drag = ['scope' => 'all', 'start' => '2027-03-17T18:00:00+00:00', 'end' => '2027-03-17T19:00:00+00:00'];
        $plan = $d->events->previewPatch($u, $rehearsal, $drag);
        checkEq('preview from the edited day\'s own row: weekly on Wednesday, the day acted on is Rehearsal\'s', ['FREQ=WEEKLY;BYDAY=WE', '2027-03-17'],
            [$plan['rule']['after'], substr((string) ($plan['next'][0]['start'] ?? ''), 0, 10)]);
        planned($d->events, $u, $rehearsal, $drag);
        checkEq('committed: Wednesdays, Rehearsal keyed and shown on Wed 17 Mar with its own title, the moved day still on its own Thursday', [
            'FREQ=WEEKLY;BYDAY=WE', ['2027-03-17 18:00:00', '2027-03-17 18:00:00', 'Rehearsal'], '2027-03-25 18:00:00',
        ], [
            $db->scalar('SELECT rrule FROM events WHERE id = ?', [$choir]),
            array_values($db->one('SELECT recurrence_instance_utc, start_utc, title FROM events WHERE id = ?', [$rehearsal])),
            $db->scalar('SELECT start_utc FROM events WHERE id = ?', [$moved]),
        ]);
        // The day on its own date dragged on a day: the series moves a day, it lands where dropped.
        $editor = ['scope' => 'all', 'start' => '2027-03-26T18:00:00+00:00', 'end' => '2027-03-26T19:00:00+00:00'];
        planned($d->events, $u, $moved, $editor);
        checkEq('the moved day dragged a day with all events: Thursdays, and it shows on Fri 26 Mar', ['FREQ=WEEKLY;BYDAY=TH', '2027-03-26 18:00:00'],
            [$db->scalar('SELECT rrule FROM events WHERE id = ?', [$choir]), $db->scalar('SELECT start_utc FROM events WHERE id = ?', [$moved])]);
        // "Use series value" sent to the day's own row, and to the master by its key.
        $d->events->patch($u, $rehearsal, ['scope' => 'this', 'useSeries' => ['title']]);
        checkEq('useSeries on the edited day\'s own row: its title is the series\' and no longer its own', ['Choir', ['location']], [
            $db->scalar('SELECT title FROM events WHERE id = ?', [$rehearsal]), json_decode((string) $db->scalar('SELECT override_fields FROM events WHERE id = ?', [$rehearsal]), true),
        ]);
        $occ = $d->events->serializeSingle($db->one('SELECT * FROM events WHERE id = ?', [$rehearsal]));
        $d->events->patch($u, (int) $occ['seriesId'], ['scope' => 'this', 'instanceStart' => $occ['seriesKey'], 'useSeries' => ['location']]);
        checkEq('useSeries to the master with seriesKey: the last own field goes, and the row with it', 0, (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id = ?', [$rehearsal]));
        $d->undo->undoLatest($u);
        checkEq('undone: the row is back with its place its own', ['location'], json_decode((string) $db->scalar('SELECT override_fields FROM events WHERE id = ?', [$rehearsal]), true));
        [$code] = $answer(fn() => $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-03-26T18:00:00+00:00', 'useSeries' => ['date']]));
        checkEq('an occurrence key sent as a day\'s shown start (its own date) is still 422', '422 invalid_recurrence_instance', $code);
    });

    scenario('a rule the expander can\'t read: only its details change', function () use ($d, $u, $db, $home, $answer): void {
        $id = (int) $d->events->create($u, eventAt($home, 'Holiday dinner', '2027-04-22'))['eventId'];
        $db->run("UPDATE events SET rrule = 'RSCALE=HEBREW;FREQ=YEARLY;BYMONTH=5L' WHERE id = ?", [$id]);
        $before = $db->one('SELECT * FROM events WHERE id = ?', [$id]);
        $why = BetterCal\Domain\Series\Model::REASONS['unreadable'];
        $said = static function (callable $fn): array {
            try {
                $fn();
                return [null, null];
            } catch (BetterCal\Http\HttpError $e) {
                return [$e->status . ' ' . $e->errorCode, $e->getMessage()];
            }
        };
        checkEq('ends after 3: 422 series_refused with the design\'s words', ['422 series_refused', $why],
            $said(fn() => planned($d->events, $u, $id, ['scope' => 'all', 'rrule' => 'RSCALE=HEBREW;FREQ=YEARLY;BYMONTH=5L;COUNT=3'])));
        checkEq('renamed with this and following: refused the same way', ['422 series_refused', $why],
            $said(fn() => $d->events->patch($u, $id, ['scope' => 'following', 'instanceStart' => '2027-04-22T18:00:00+00:00', 'title' => 'Holiday supper'])));
        checkEq('delete following: refused the same way', ['422 series_refused', $why],
            $said(fn() => $d->events->deleteEvent($u, $id, 'following', '2027-04-22T18:00:00+00:00')));
        checkEq('nothing was written', $before, $db->one('SELECT * FROM events WHERE id = ?', [$id]));
        $d->events->patch($u, $id, ['scope' => 'all', 'title' => 'Holiday supper', 'rrule' => 'RSCALE=HEBREW;FREQ=YEARLY;BYMONTH=5L']);
        checkEq('renamed for all events, the rule sent back as it is: applied, direct', ['Holiday supper', 'RSCALE=HEBREW;FREQ=YEARLY;BYMONTH=5L'],
            array_values($db->one('SELECT title, rrule FROM events WHERE id = ?', [$id])));
        unset($answer);
    });

    scenario('migration 063 keeps updated_at, so no CalDAV ETag changes', function () use ($d, $u, $db, $weekly): void {
        $s = $weekly('Choir');
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Rehearsal']);
        $db->run("UPDATE events SET override_fields = NULL, updated_at = '2026-10-01 12:00:00' WHERE recurrence_parent_id = ?", [$s]);
        $migration = require dirname(__DIR__, 2) . '/migrations/063_override_fields.php';
        $migration($db);
        checkEq('the list filled, updated_at as it was', [['title'], '2026-10-01 12:00:00'], [
            json_decode((string) $db->scalar('SELECT override_fields FROM events WHERE recurrence_parent_id = ?', [$s]), true),
            $db->scalar('SELECT updated_at FROM events WHERE recurrence_parent_id = ?', [$s]),
        ]);
    });
};
