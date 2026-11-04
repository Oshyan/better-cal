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
        // Each day's words in two parts beside the sentence (the dialog's two
        // lines), every date with its weekday, the skipped day's too.
        $byKey = array_column($plan['days'], null, 'key');
        $yr = (int) gmdate('Y') === 2027 ? '' : ' 2027';
        $parts = static fn(?array $x): array => [$x['head'] ?? null, $x['outcomeWords'] ?? null, $x['words'] ?? null];
        checkEq('a day\'s head and outcome: the edited day, the one at its own date, the skipped one', [
            ["'Rehearsal' Tue 13 Apr$yr", "moves to Wed 14 Apr$yr, keeps its title and place", "'Rehearsal' Tue 13 Apr$yr moves to Wed 14 Apr$yr, keeps its title and place"],
            ["Tue 23 Mar$yr, moved to Fri 26 Mar$yr", 'stays there', "Tue 23 Mar$yr, moved to Fri 26 Mar$yr: stays there"],
            ["Tue 30 Mar$yr", "moves to Wed 31 Mar$yr", "Skipped Tue 30 Mar$yr moves to Wed 31 Mar$yr"],
        ], [$parts($byKey['2027-04-13T18:00:00Z'] ?? null), $parts($byKey['2027-03-23T18:00:00Z'] ?? null), $parts($byKey['2027-03-30T18:00:00Z'] ?? null)]);
        // The plan as data, for the client's own sentences: the rule before
        // and after with its start and zone, the bound before and after,
        // the counts, whether the series goes on past Next, each day's dates.
        checkEq('the plan carries its data: rules, bounds, counts, the day dates and the moved day\'s option dates', [
            ['FREQ=WEEKLY;BYDAY=TU', '2027-01-05', false], ['FREQ=WEEKLY;BYDAY=WE', '2027-01-06', false],
            ['none', null, 'none'], ['events' => null, 'edited' => 2, 'skipped' => 1], true,
            ['2027-04-13', '2027-04-14'], ['2027-03-26', '2027-03-26'], ['2027-03-30', '2027-03-31'],
            ['2027-03-26', '2027-03-27'], 1,
        ], [
            [$plan['rule']['from']['rrule'], substr($plan['rule']['from']['start'], 0, 10), $plan['rule']['from']['allDay']],
            [$plan['rule']['to']['rrule'], substr($plan['rule']['to']['start'], 0, 10), $plan['rule']['to']['allDay']],
            [$plan['bound']['kind'], $plan['bound']['events'], $plan['bound']['from']['kind']], $plan['counts'], $plan['nextMore'],
            [$byKey['2027-04-13T18:00:00Z']['shows'] ?? null, $byKey['2027-04-13T18:00:00Z']['to'] ?? null],
            [$byKey['2027-03-23T18:00:00Z']['shows'] ?? null, $byKey['2027-03-23T18:00:00Z']['to'] ?? null],
            [$byKey['2027-03-30T18:00:00Z']['shows'] ?? null, $byKey['2027-03-30T18:00:00Z']['to'] ?? null],
            array_column($plan['groups'][0]['options'] ?? [], 'date'), $plan['groups'][0]['count'] ?? null,
        ]);
        // The day the edit started from, when it has something of its own.
        $fromRehearsal = $d->events->previewPatch($u, $choir, ['scope' => 'all', 'instanceStart' => '2027-04-13T18:00:00+00:00', 'start' => '2027-04-14T18:00:00+00:00', 'end' => '2027-04-14T19:00:00+00:00', 'preview' => true]);
        checkEq('the plan names the day the edit started from when it has fields of its own, and no day otherwise', [true, 'Rehearsal', '2027-04-13', ['title', 'location']],
            [array_key_exists('acted', $plan) && $plan['acted'] === null, $fromRehearsal['acted']['title'] ?? null, $fromRehearsal['acted']['shows'] ?? null, $fromRehearsal['acted']['kept'] ?? null]);
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

    scenario('series plans: direct field edits, refusals', function () use ($d, $u, $db, $rows, $answer, $weekly, $home): void {
        $plain = $weekly('Swim');
        $d->events->patch($u, $plain, ['scope' => 'this', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'start' => '2027-01-19T19:00:00+00:00', 'end' => '2027-01-19T20:00:00+00:00']);
        $d->events->patch($u, $plain, ['scope' => 'all', 'title' => 'Swim practice']);
        checkEq('a rename with no edited day owning its title is direct: applied at once, the edited day takes the new title', ['Swim practice', 'Swim practice'], [
            $db->scalar('SELECT title FROM events WHERE id = ?', [$plain]), $db->scalar('SELECT title FROM events WHERE recurrence_parent_id = ?', [$plain]),
        ]);
        $owned = (int) $d->events->create($u, eventAt($home, 'Choir', '2027-01-05', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU', 'tagNames' => ['sample']]))['eventId'];
        $d->events->patch($u, $owned, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Rehearsal']);
        [$code, $plan] = $answer(fn() => $d->events->patch($u, $owned, ['scope' => 'all', 'title' => 'Chorus']));
        checkEq('the same rename where a day owns its title is 409 series_plan_required, the group to give it the title too', ['409 series_plan_required', ['ownTitle']], [$code, array_column($plan['groups'] ?? [], 'id')]);
        // Its title was all it owned: given the series' title, every value is
        // the series' and the day is an ordinary occurrence again, its row
        // deleted (Own Fields); Undo puts the row and its tags back as they were.
        $tagRows = static fn(): array => $db->all('SELECT t.event_id, t.tag_id FROM event_tags t JOIN events e ON e.id = t.event_id WHERE e.recurrence_parent_id = ? ORDER BY t.event_id, t.tag_id', [$owned]);
        $beforeGive = [$rows($owned), $tagRows()];
        $plan = planned($d->events, $u, $owned, ['scope' => 'all', 'title' => 'Chorus'], ['ownTitle' => 'give']);
        checkEq('"Give it the new title too" to a day that owned only its title: its row is deleted and the plan says it goes back to the series', [
            'Chorus', 0, ['rejoins'], ['2027-01-12T18:00:00Z'],
        ], [
            $db->scalar('SELECT title FROM events WHERE id = ?', [$owned]),
            (int) $db->scalar('SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ?', [$owned]),
            array_column(array_filter($plan['days'], static fn(array $x): bool => $x['kind'] === 'edited'), 'outcome'),
            array_column(array_filter($plan['days'], static fn(array $x): bool => $x['kind'] === 'edited'), 'newKey'),
        ]);
        $yr = (int) gmdate('Y') === 2027 ? '' : ' 2027';
        $rejoined = array_values(array_filter($plan['days'], static fn(array $x): bool => $x['kind'] === 'edited'))[0] ?? [];
        checkEq('the day going back to the series: its head, then where it shows', ["'Rehearsal' Tue 12 Jan$yr", "goes back to the series, on Tue 12 Jan$yr"],
            [$rejoined['head'] ?? null, $rejoined['outcomeWords'] ?? null]);
        $d->undo->undoLatest($u);
        checkEq('undone, the edited day\'s row and its tags are byte-identical to before', $beforeGive, [$rows($owned), $tagRows()]);
        $weekno = (int) $d->events->create($u, eventAt($home, 'Kickoff', '2027-01-04', ['rrule' => 'FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO']))['eventId'];
        $before = $db->one('SELECT * FROM events WHERE id = ?', [$weekno]);
        [$code, $plan] = $answer(fn() => $d->events->patch($u, $weekno, ['scope' => 'all', 'instanceStart' => '2027-01-04T18:00:00+00:00', 'start' => '2027-01-05T18:00:00+00:00', 'end' => '2027-01-05T19:00:00+00:00']));
        checkEq('a refused edit is 422 series_refused with its reason; nothing written', ['422 series_refused', 'weekno', $before], [$code, $plan['refusals'][0]['code'] ?? null, $db->one('SELECT * FROM events WHERE id = ?', [$weekno])]);
        $gym = (int) $d->events->create($u, eventAt($home, 'Gym', '2027-01-04', ['rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR']))['eventId'];
        $edit = ['scope' => 'all', 'instanceStart' => '2027-03-10T18:00:00+00:00', 'start' => '2027-03-12T18:00:00+00:00', 'end' => '2027-03-12T19:00:00+00:00'];
        $plan = $d->events->previewPatch($u, $gym, $edit);
        [$code] = $answer(fn() => $d->events->patch($u, $gym, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => ['reading' => 'member']]]));
        checkEq('naming a disabled reading is 422 series_refused', '422 series_refused', $code);
        // A reading's dates run from the day acted on until they differ from
        // the other reading's; the one that also changes earlier days says from when.
        $swap = (int) $d->events->create($u, eventAt($home, 'Book swap', '2027-01-31', ['rrule' => 'FREQ=MONTHLY;BYMONTHDAY=31']))['eventId'];
        $readings = $d->events->previewPatch($u, $swap, ['scope' => 'all', 'instanceStart' => '2027-03-31T18:00:00+00:00', 'start' => '2027-03-30T18:00:00+00:00', 'end' => '2027-03-30T19:00:00+00:00']);
        $opts = array_column(array_values(array_filter($readings['groups'], static fn(array $g): bool => $g['id'] === 'reading'))[0]['options'] ?? [], null, 'id');
        checkEq('reading dates: from the day acted on, three, then the last shared date and the first that differs; both change earlier days from 30 Jan, against the series as it is today', [
            '2027-03-30', 3, ['2028-01-30', '2028-03-30'], ['2028-01-30', '2028-02-29'], '2027-01-30', '2027-01-30',
        ], [
            substr((string) ($opts['skip']['dates'][0] ?? ''), 0, 10), count($opts['skip']['dates'] ?? []),
            array_map(static fn(string $x): string => substr($x, 0, 10), $opts['skip']['then'] ?? []),
            array_map(static fn(string $x): string => substr($x, 0, 10), $opts['clamp']['then'] ?? []),
            array_key_exists('earlier', $opts['skip'] ?? []) ? $opts['skip']['earlier'] : 'missing', $opts['clamp']['earlier'] ?? null,
        ]);
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

    scenario('a first day in a skipped hour: stored from the day before, the rows agree with the plan', function () use ($d, $u, $db, $rows, $home): void {
        $la = 'America/Los_Angeles';
        $night = (int) $d->events->create($u, ['calendarId' => $home, 'title' => 'Night shift', 'start' => '2027-03-13T02:30:00-08:00', 'end' => '2027-03-13T03:30:00-08:00',
            'allDay' => false, 'tzid' => $la, 'rrule' => 'FREQ=MONTHLY'])['eventId'];
        $d->events->patch($u, $night, ['scope' => 'this', 'instanceStart' => '2027-06-13T02:30:00-07:00', 'title' => 'Night shift, inventory']);
        $before = $rows($night);
        $drag = ['scope' => 'all', 'instanceStart' => '2027-05-13T02:30:00-07:00', 'start' => '2027-05-14T02:30:00-07:00', 'end' => '2027-05-14T03:30:00-07:00'];
        $plan = planned($d->events, $u, $night, $drag);
        $master = $db->one('SELECT rrule, start_utc, exdates_json FROM events WHERE id = ?', [$night]);
        checkEq('stored from 14 Feb at 02:30, that day skipped, the rule as before', ['FREQ=MONTHLY', '2027-02-14 10:30:00', ['2027-02-14 10:30:00']],
            [$master['rrule'], $master['start_utc'], json_decode((string) $master['exdates_json'], true)]);
        $window = $d->events->window($u, new \DateTimeImmutable('2027-02-01T00:00:00Z'), new \DateTimeImmutable('2027-09-01T00:00:00Z'), [$home], null, false);
        $shown = array_values(array_map(static fn(array $o): string => (string) $o['start'], array_filter($window, static fn(array $o): bool => str_starts_with((string) $o['title'], 'Night shift'))));
        $local = array_map(static fn(string $s): string => BetterCal\Support\Time::parseIso($s)->setTimezone(new DateTimeZone($la))->format('Y-m-d H:i'), $shown);
        checkEq('the window shows 14 Mar at 03:30 (no 02:30 that day), then 02:30, the edited day once', [
            '2027-03-14 03:30', '2027-04-14 02:30', '2027-05-14 02:30', '2027-06-14 02:30', '2027-07-14 02:30', '2027-08-14 02:30',
        ], $local);
        $next = array_map(static fn(array $n): string => BetterCal\Support\Time::parseIso((string) $n['start'])->setTimezone(new DateTimeZone($la))->format('Y-m-d H:i'), $plan['next']);
        checkEq('the plan\'s next is what the rows show', array_slice($local, 2, 4), $next);
        checkEq('the edited day is keyed on its 02:30 occurrence', '2027-06-14 09:30:00',
            $db->scalar("SELECT recurrence_instance_utc FROM events WHERE recurrence_parent_id = ? AND title = 'Night shift, inventory'", [$night]));
        $d->undo->undoLatest($u);
        checkEq('undone: every row as before', $before, $rows($night));
    });

    // Re-expected for the structure rework: there is no routing flag any
    // more. An edit naming a day by its key acts on that day as it shows,
    // whichever row id it came through, so from the master the day acted
    // on takes the new title, as it does from its own row (F6).
    scenario('edited days: one delta whichever row the request names (structure review F3, F6, F8)', function () use ($d, $u, $db, $weekly, $answer, $home): void {
        $own = static fn(int $id): array => json_decode((string) $db->scalar('SELECT override_fields FROM events WHERE id = ?', [$id]), true) ?? [];
        // F6: 9 Feb owns its title and its time, 17:00; the series is at 18:00 UTC.
        $choir = $weekly('Sample choir');
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-02-09T18:00:00+00:00', 'title' => 'Sample concert', 'start' => '2027-02-09T17:00:00+00:00', 'end' => '2027-02-09T18:00:00+00:00']);
        $day = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$choir]);
        $edit = ['title' => 'Sample chorus', 'start' => '2027-02-09T18:00:00+00:00', 'end' => '2027-02-09T19:00:00+00:00'];
        $fromRow = $d->events->previewPatch($u, $day, ['scope' => 'all'] + $edit);
        $fromMaster = $d->events->previewPatch($u, $choir, ['scope' => 'all', 'instanceStart' => '2027-02-09T18:00:00+00:00'] + $edit);
        checkEq('F6: an hour later and renamed, from the day\'s own row and from the master naming its key: the same plan', $fromRow, $fromMaster);
        [$code] = $answer(fn() => $d->events->patch($u, $choir, ['scope' => 'all', 'instanceStart' => '2027-02-09T18:00:00+00:00', 'title' => 'Sample chorus']));
        checkEq('F6: a plan-less rename naming that day is 409 series_plan_required (the day owns its title)', '409 series_plan_required', $code);
        planned($d->events, $u, $choir, ['scope' => 'all', 'instanceStart' => '2027-02-09T18:00:00+00:00', '_fromEditedDay' => true] + $edit);
        checkEq('F6: committed from the master (a stray _fromEditedDay in the body is nothing): the series at 19:00, the day at 18:00 with the new title, its time its own and its title not', [
            '2027-01-05 19:00:00', 'Sample chorus', '2027-02-09 18:00:00', ['time'],
        ], [$db->scalar('SELECT start_utc FROM events WHERE id = ?', [$choir]), $db->scalar('SELECT title FROM events WHERE id = ?', [$day]), $db->scalar('SELECT start_utc FROM events WHERE id = ?', [$day]), $own($day)]);

        // F3: an all-day edited day of a timed series, dragged a day later from its row.
        foreach (['dates only' => [], 'with allDay: true' => ['allDay' => true]] as $how => $more) {
            $s = $weekly('Sample rehearsal');
            $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-02-09T18:00:00+00:00', 'allDay' => true, 'start' => '2027-02-09', 'end' => '2027-02-10']);
            $row = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$s]);
            planned($d->events, $u, $row, ['scope' => 'all', 'start' => '2027-02-10', 'end' => '2027-02-11'] + $more);
            checkEq("F3 ($how): every event a day later at 18:00, still timed; the day all-day on 10 Feb", [
                'FREQ=WEEKLY;BYDAY=WE', '2027-01-06 18:00:00', 0, ['2027-02-10 00:00:00', 1],
            ], [
                $db->scalar('SELECT rrule FROM events WHERE id = ?', [$s]), $db->scalar('SELECT start_utc FROM events WHERE id = ?', [$s]), (int) $db->scalar('SELECT all_day FROM events WHERE id = ?', [$s]),
                [$db->scalar('SELECT start_utc FROM events WHERE id = ?', [$row]), (int) $db->scalar('SELECT all_day FROM events WHERE id = ?', [$row])],
            ]);
        }

        // F8: a day at its own time in Berlin; its zone sent back with a rename.
        $s = $weekly('Sample call');
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-02-09T18:00:00+00:00', 'tzid' => 'Europe/Berlin', 'start' => '2027-02-09T19:00:00+01:00', 'end' => '2027-02-09T20:00:00+01:00']);
        $row = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$s]);
        $plan = planned($d->events, $u, $row, ['scope' => 'all', 'tzid' => 'Europe/Berlin', 'title' => 'Sample briefing']);
        checkEq('F8: no zone group, and the series stays in UTC at 18:00', [false, 'UTC', '2027-01-05 18:00:00'],
            [in_array('zone', array_column($plan['groups'], 'id'), true), $db->scalar('SELECT tzid FROM events WHERE id = ?', [$s]), $db->scalar('SELECT start_utc FROM events WHERE id = ?', [$s])]);

        // F2: a rule the expander can't read, renamed: its edited day stays attached.
        $odd = $weekly('Sample supper', 'FREQ=WEEKLY;BYDAY=TU;COUNT=10');
        $d->events->patch($u, $odd, ['scope' => 'this', 'instanceStart' => '2027-02-09T18:00:00+00:00', 'location' => 'Sample Hall']);
        $db->run("UPDATE events SET rrule = 'FREQ=WEEKLY;BYDAY=TU;COUNT=10;RSCALE=GREGORIAN' WHERE id = ?", [$odd]);
        $d->events->patch($u, $odd, ['scope' => 'all', 'title' => 'Sample dinner']);
        checkEq('F2: renamed with no plan (direct): the edited day is still the series\' own, on its key, with the new title', [1, '2027-02-09 18:00:00', 'Sample dinner', 0], [
            (int) $db->scalar("SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ? AND location = 'Sample Hall' AND deleted_at IS NULL", [$odd]),
            $db->scalar("SELECT recurrence_instance_utc FROM events WHERE recurrence_parent_id = ? AND location = 'Sample Hall'", [$odd]),
            $db->scalar("SELECT title FROM events WHERE recurrence_parent_id = ? AND location = 'Sample Hall'", [$odd]),
            (int) $db->scalar("SELECT COUNT(*) FROM events WHERE user_id = ? AND title = 'Sample supper' AND rrule IS NULL AND recurrence_parent_id IS NULL AND deleted_at IS NULL", [$u]),
        ]);

        // F11: a new series whose first day falls in the spring gap.
        $call = (int) $d->events->create($u, ['calendarId' => $home, 'title' => 'Sample early call', 'start' => '2027-03-13T02:30:00-08:00', 'end' => '2027-03-13T03:30:00-08:00',
            'allDay' => false, 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;BYDAY=SU;COUNT=4'])['eventId'];
        checkEq('F11: typed on Sat 13 Mar at 02:30, every Sunday: stored from 7 Mar at 02:30, skipped, COUNT one higher', [
            '2027-03-07 10:30:00', 'FREQ=WEEKLY;BYDAY=SU;COUNT=5', ['2027-03-07 10:30:00'],
        ], [$db->scalar('SELECT start_utc FROM events WHERE id = ?', [$call]), $db->scalar('SELECT rrule FROM events WHERE id = ?', [$call]), json_decode((string) $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$call]), true)]);
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

    scenario('the trip flag for all events: on and off, as the editor sends it', function () use ($d, $u, $db, $weekly, $answer): void {
        $flags = static fn(int $id): array => array_map('intval', array_column($db->all('SELECT is_container FROM events WHERE id = ? OR recurrence_parent_id = ? ORDER BY id', [$id, $id]), 'is_container'));
        $trip = $weekly('Ski weekend');
        $d->events->patch($u, $trip, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Ski weekend, lessons']);
        $on = ['scope' => 'all', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'isContainer' => true];
        $plan = $d->events->previewPatch($u, $trip, $on + ['preview' => true]);
        checkEq('preview: direct, and the trip line among the changed fields', [true, ['Trip: no, now yes']], [$plan['direct'], array_column($plan['fields'], 'words')]);
        planned($d->events, $u, $trip, $on);
        checkEq('"This is a trip" for all events: the series and its edited day are a trip', [1, 1], $flags($trip));
        $d->events->patch($u, $trip, ['scope' => 'all', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'isContainer' => false]);
        checkEq('turned off for all events, sent with no plan (direct): no row is a trip', [0, 0], $flags($trip));
        $d->undo->undoLatest($u);
        checkEq('undone: a trip again', [1, 1], $flags($trip));
        // From the edited day's own row, with all events: the series' flag.
        $day = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$trip]);
        planned($d->events, $u, $day, ['scope' => 'all', 'isContainer' => false]);
        checkEq('off from the edited day\'s own row with all events: no row is a trip', [0, 0], $flags($trip));
        planned($d->events, $u, $day, ['scope' => 'all', 'isContainer' => true]);
        checkEq('and on again from there', [1, 1], $flags($trip));
        // Review R2: the flag differs by day and the request names the value
        // the master already has. One value for the whole series: every row
        // that differs takes it, in both directions, from either row.
        $d->events->patch($u, $day, ['isContainer' => false]);
        checkEq('the edited day alone turned off: the flags differ', [1, 0], $flags($trip));
        $plan = $d->events->previewPatch($u, $trip, $on + ['preview' => true]);
        checkEq('preview from the master, on for all events: not direct, the words say the days differed', [false, ['Trip: yes on some days, now yes for every event']], [$plan['direct'], array_column($plan['fields'], 'words')]);
        planned($d->events, $u, $trip, $on);
        checkEq('on for all events with the master already on: the edited day is a trip too', [1, 1], $flags($trip));
        $d->undo->undoLatest($u);
        checkEq('undone: the flags differ again', [1, 0], $flags($trip));
        planned($d->events, $u, $day, ['scope' => 'all', 'isContainer' => true]);
        checkEq('the same from the edited day\'s own row', [1, 1], $flags($trip));
        planned($d->events, $u, $trip, ['scope' => 'all', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'isContainer' => false]);
        $d->events->patch($u, $day, ['isContainer' => true]);
        checkEq('the other way: only the edited day is a trip', [0, 1], $flags($trip));
        planned($d->events, $u, $day, ['scope' => 'all', 'isContainer' => false]);
        checkEq('off for all events from the edited day with the master already off: no row is a trip', [0, 0], $flags($trip));
        $d->undo->undoLatest($u);
        checkEq('undone: only the edited day is a trip again', [0, 1], $flags($trip));
        planned($d->events, $u, $trip, ['scope' => 'all', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'isContainer' => false]);
        checkEq('off for all events from the master: no row is a trip', [0, 0], $flags($trip));
        planned($d->events, $u, $trip, $on);
        checkEq('on again for every row', [1, 1], $flags($trip));
        // A member attached to the edited day: clearing is refused and nothing changes.
        $member = (int) $d->events->create($u, eventAt((int) $db->scalar('SELECT calendar_id FROM events WHERE id = ?', [$trip]), 'Ski rental pickup', '2027-01-16'))['eventId'];
        (new BetterCal\Domain\Trips($db, $d->undo))->attach($u, $day, $member);
        [$code] = $answer(fn() => planned($d->events, $u, $trip, ['scope' => 'all', 'isContainer' => false]));
        checkEq('a member on an edited day of the trip: clearing for all events is 400 trip_has_members, nothing written', ['400 trip_has_members', [1, 1]], [$code, $flags($trip)]);
        // Review F2: a trip never contains another trip. Setting the flag is
        // refused on every row it reaches that is in a trip: the edited day
        // alone, the series from either row, and a standalone event.
        $trips = new BetterCal\Domain\Trips($db, $d->undo);
        $trips->detach($u, $day, $member);
        planned($d->events, $u, $trip, ['scope' => 'all', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'isContainer' => false]);
        $outer = (int) $d->events->create($u, eventAt((int) $db->scalar('SELECT calendar_id FROM events WHERE id = ?', [$trip]), 'Sample outer trip', '2027-01-10', ['isContainer' => true]))['eventId'];
        $trips->attach($u, $outer, $day);
        checkEq('the edited day, no trip itself, is in a trip', [[0, 0], 1], [$flags($trip), count($trips->listMembers($u, $outer))]);
        [$code] = $answer(fn() => $d->events->patch($u, $day, ['isContainer' => true]));
        checkEq('the edited day alone made a trip: 400 trip_nested', '400 trip_nested', $code);
        [$code] = $answer(fn() => planned($d->events, $u, $trip, $on));
        [$code2] = $answer(fn() => planned($d->events, $u, $day, ['scope' => 'all', 'isContainer' => true]));
        checkEq('the series made a trip from the master or from that day: 400 trip_nested, nothing written, the link stands', ['400 trip_nested', '400 trip_nested', [0, 0], 1], [$code, $code2, $flags($trip), count($trips->listMembers($u, $outer))]);
        $trips->detach($u, $outer, $day);
        planned($d->events, $u, $trip, $on);
        checkEq('out of the trip, the series becomes one', [1, 1], $flags($trip));
        $trips->attach($u, $outer, $member);
        [$code] = $answer(fn() => $d->events->patch($u, $member, ['isContainer' => true]));
        $d->events->patch($u, $outer, ['title' => 'Sample outer trip, renamed']);
        checkEq('a standalone event in a trip can\'t become one; its trip is unchanged', ['400 trip_nested', 0, 1], [$code, (int) $db->scalar('SELECT is_container FROM events WHERE id = ?', [$member]), count($trips->listMembers($u, $outer))]);
    });

    scenario('"Use series value" for one of date, time, length, all-day: the zone goes with the time it keeps', function () use ($d, $u, $db, $home): void {
        $ny = 'America/New_York';
        $series = static function (string $title, string $tzid = 'UTC') use ($d, $u, $home): int {
            $o = $tzid === 'UTC' ? '+00:00' : '-05:00';
            return (int) $d->events->create($u, ['calendarId' => $home, 'title' => $title, 'start' => '2027-01-05T18:00:00' . $o, 'end' => '2027-01-05T19:00:00' . $o,
                'allDay' => false, 'tzid' => $tzid, 'rrule' => 'FREQ=WEEKLY;BYDAY=TU'])['eventId'];
        };
        $day = static fn(int $id): array => $db->one('SELECT start_utc, end_utc, all_day, tzid, override_fields FROM events WHERE recurrence_parent_id = ?', [$id]) ?? [];
        $local = static function (array $row): string {
            $tz = (int) $row['all_day'] === 1 ? 'UTC' : (string) $row['tzid'];
            return BetterCal\Support\Time::fromDb((string) $row['start_utc'])->setTimezone(new DateTimeZone($tz))->format('D Y-m-d H:i') . ' ' . $tz;
        };
        $length = static fn(array $row): int => BetterCal\Support\Time::fromDb((string) $row['end_utc'])->getTimestamp() - BetterCal\Support\Time::fromDb((string) $row['start_utc'])->getTimestamp();
        // The whole series 91 days later, across the spring clock change.
        $later = static function (int $id, string $tzid = 'UTC') use ($d, $u): void {
            [$o, $o2] = $tzid === 'UTC' ? ['+00:00', '+00:00'] : ['-05:00', '-04:00'];
            planned($d->events, $u, $id, ['scope' => 'all', 'instanceStart' => '2027-01-05T18:00:00' . $o, 'start' => '2027-04-06T18:00:00' . $o2, 'end' => '2027-04-06T19:00:00' . $o2], ['movedDays' => 'move']);
        };
        $own = static fn(array $row): array => json_decode((string) ($row['override_fields'] ?? 'null'), true) ?? [];

        // Length: the day keeps its 18:00 in New York.
        $a = $series('Sample lecture');
        $d->events->patch($u, $a, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'start' => '2027-01-12T18:00:00-05:00', 'end' => '2027-01-12T20:00:00-05:00', 'tzid' => $ny, 'title' => 'Sample lecture, guest']);
        $d->events->patch($u, $a, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'useSeries' => ['length']]);
        $row = $day($a);
        checkEq('length to the series\': an hour, still its own 18:00 in New York', [['time', 'title'], 'Tue 2027-01-12 18:00 ' . $ny, 3600], [$own($row), $local($row), $length($row)]);
        $later($a);
        checkEq('then the series 91 days later: its own 18:00 New York time on Tue 13 Apr', 'Tue 2027-04-13 18:00 ' . $ny, $local($day($a)));

        // Date: back on its occurrence's day, at its own time and zone.
        $b = $series('Sample seminar');
        $d->events->patch($u, $b, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'start' => '2027-01-14T18:00:00-05:00', 'end' => '2027-01-14T20:00:00-05:00', 'tzid' => $ny]);
        $d->events->patch($u, $b, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'useSeries' => ['date']]);
        $row = $day($b);
        checkEq('date to the series\': Tue 12 Jan at its own 18:00 in New York, two hours', [['time', 'length'], 'Tue 2027-01-12 18:00 ' . $ny, 7200], [$own($row), $local($row), $length($row)]);
        $later($b);
        checkEq('then the series 91 days later: its own 18:00 New York time on Tue 13 Apr', 'Tue 2027-04-13 18:00 ' . $ny, $local($day($b)));

        // Time: its own date stays, the clock and zone are the series'.
        $c = $series('Sample workshop');
        $d->events->patch($u, $c, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'start' => '2027-01-14T18:00:00-05:00', 'end' => '2027-01-14T20:00:00-05:00', 'tzid' => $ny]);
        $d->events->patch($u, $c, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'useSeries' => ['time']]);
        $row = $day($c);
        checkEq('time to the series\': Thu 14 Jan at 18:00 UTC, its own two hours', [['date', 'length'], 'Thu 2027-01-14 18:00 UTC', 7200], [$own($row), $local($row), $length($row)]);
        $later($c);
        checkEq('then the series 91 days later, its own date moved with it: the series\' 18:00 UTC', 'Thu 2027-04-15 18:00 UTC', $local($day($c)));

        // All-day: a day made all-day in a New York series goes back to the series' 18:00 there.
        $e = $series('Sample clinic', $ny);
        $d->events->patch($u, $e, ['scope' => 'this', 'instanceStart' => '2027-01-12T23:00:00+00:00', 'allDay' => true, 'start' => '2027-01-12', 'end' => '2027-01-13', 'title' => 'Sample clinic, open day']);
        $before = $own($day($e));
        $d->events->patch($u, $e, ['scope' => 'this', 'instanceStart' => '2027-01-12T23:00:00+00:00', 'useSeries' => ['allDay']]);
        $row = $day($e);
        checkEq('all-day to the series\': timed again at the series\' 18:00 in New York, an hour', [true, false, 'Tue 2027-01-12 18:00 ' . $ny, 3600], [
            in_array('allDay', $before, true), in_array('allDay', $own($row), true), $local($row), $length($row)]);
        $later($e, $ny);
        checkEq('then the series 91 days later: 18:00 New York time on Tue 13 Apr', 'Tue 2027-04-13 18:00 ' . $ny, $local($day($e)));

        // All-day the other way: a timed day in an all-day series, made all-day again.
        $f = (int) $d->events->create($u, ['calendarId' => $home, 'title' => 'Sample fair', 'start' => '2027-01-05', 'end' => '2027-01-06', 'allDay' => true, 'rrule' => 'FREQ=WEEKLY;BYDAY=TU'])['eventId'];
        $d->events->patch($u, $f, ['scope' => 'this', 'instanceStart' => '2027-01-12', 'allDay' => false, 'start' => '2027-01-12T09:00:00-05:00', 'end' => '2027-01-12T11:00:00-05:00', 'tzid' => $ny, 'title' => 'Sample fair, talks']);
        $d->events->patch($u, $f, ['scope' => 'this', 'instanceStart' => '2027-01-12', 'useSeries' => ['allDay']]);
        $row = $day($f);
        checkEq('a timed day in an all-day series given the series\' all-day: all day on Tue 12 Jan, one day, its clock and length gone with its timing', [
            1, '2027-01-12 00:00:00', '2027-01-13 00:00:00', 'UTC', ['title'],
        ], [(int) $row['all_day'], $row['start_utc'], $row['end_utc'], $row['tzid'], $own($row)]);
        $g = (int) $d->events->create($u, ['calendarId' => $home, 'title' => 'Sample market', 'start' => '2027-01-05', 'end' => '2027-01-06', 'allDay' => true, 'rrule' => 'FREQ=WEEKLY;BYDAY=TU'])['eventId'];
        $d->events->patch($u, $g, ['scope' => 'this', 'instanceStart' => '2027-01-12', 'allDay' => false, 'start' => '2027-01-12T09:00:00-05:00', 'end' => '2027-01-12T11:00:00-05:00', 'tzid' => $ny]);
        $d->events->patch($u, $g, ['scope' => 'this', 'instanceStart' => '2027-01-12', 'useSeries' => ['allDay']]);
        checkEq('the same with nothing else its own: an occurrence again, its row gone', 0, (int) $db->scalar('SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ?', [$g]));
    });

    scenario('own fields from elsewhere: date, time with its zone, and length, each compared on its own', function () use ($d, $u, $db, $home, $weekly): void {
        $token = (new BetterCal\Domain\ApiTokens($db))->create($u, 'Sample tablet');
        $dav = new BetterCal\Dav\CalendarBackend($db, $d->undo, fn() => BetterCal\Domain\RequestAuthority::credential($u, (int) $token['id'], null));
        $ownOf = static fn(int $id): array => json_decode((string) $db->scalar('SELECT override_fields FROM events WHERE recurrence_parent_id = ?', [$id]), true) ?? [];
        $shown = static fn(int $id): string => (string) $db->scalar('SELECT start_utc FROM events WHERE recurrence_parent_id = ?', [$id]);
        // An edited day owning its time and length, the series then given the same: own values equal to the master's.
        $equal = static function (string $title) use ($d, $u, $db, $weekly, $ownOf): int {
            $id = $weekly($title);
            $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'start' => '2027-01-12T19:00:00+00:00', 'end' => '2027-01-12T20:30:00+00:00']);
            planned($d->events, $u, $id, ['scope' => 'all', 'instanceStart' => '2027-01-05T18:00:00+00:00', 'start' => '2027-01-05T19:00:00+00:00', 'end' => '2027-01-05T20:30:00+00:00']);
            checkEq("$title: the edited day still owns its time and length, now the series' too", [['time', 'length'], '2027-01-12 19:00:00', '2027-01-05 19:00:00'],
                [$ownOf($id), (string) $db->scalar('SELECT start_utc FROM events WHERE recurrence_parent_id = ?', [$id]), (string) $db->scalar('SELECT start_utc FROM events WHERE id = ?', [$id])]);
            return $id;
        };
        // The object as a client reads it, its edited day's start and end replaced, PUT back.
        $put = static function (int $id, ?string $start, ?string $end) use ($dav, $db, $home): void {
            $uri = BetterCal\Dav\DavIcs::objectUri((string) $db->scalar('SELECT uid FROM events WHERE id = ?', [$id]));
            $parts = explode('BEGIN:VEVENT', (string) $dav->getCalendarObject($home, $uri)['calendardata']);
            foreach ($parts as $i => $part) {
                if (str_contains($part, 'RECURRENCE-ID')) {
                    $part = $start !== null ? (string) preg_replace('/^DTSTART[^\r\n]*/m', $start, $part) : $part;
                    $parts[$i] = $end !== null ? (string) preg_replace('/^DTEND[^\r\n]*/m', $end, $part) : $part;
                }
            }
            $dav->updateCalendarObject($home, $uri, implode('BEGIN:VEVENT', $parts));
        };
        $at20 = static fn(int $id) => planned($d->events, $u, $id, ['scope' => 'all', 'instanceStart' => '2027-01-05T19:00:00+00:00', 'start' => '2027-01-05T20:00:00+00:00', 'end' => '2027-01-05T21:30:00+00:00']);

        $echo = $equal('Sample choir');
        $put($echo, null, null);
        checkEq('PUT echoed unchanged: it keeps its own time and length', ['time', 'length'], $ownOf($echo));
        $date = $equal('Sample band');
        $put($date, 'DTSTART:20270114T190000Z', 'DTEND:20270114T203000Z');
        checkEq('PUT moving only its date: its own date, and it keeps its own time and length', ['date', 'time', 'length'], $ownOf($date));
        $at20($date);
        checkEq('then the series at 20:00: Thu 14 Jan stays at its own 19:00', '2027-01-14 19:00:00', $shown($date));
        $time = $equal('Sample quartet');
        $put($time, 'DTSTART:20270112T193000Z', 'DTEND:20270112T210000Z');
        checkEq('PUT changing only its clock: its own time, and it keeps its own length', ['time', 'length'], $ownOf($time));
        $at20($time);
        checkEq('then the series at 20:00: it stays at 19:30 for 90 minutes', ['2027-01-12 19:30:00', '2027-01-12 21:00:00'],
            array_values($db->one('SELECT start_utc, end_utc FROM events WHERE recurrence_parent_id = ?', [$time])));
        $zone = $equal('Sample trio');
        $put($zone, 'DTSTART;TZID=America/New_York:20270112T190000', 'DTEND;TZID=America/New_York:20270112T203000');
        checkEq('PUT changing only its zone (the same clock in New York): its own time, and it keeps its own length', ['time', 'length'], $ownOf($zone));
        $length = $equal('Sample duo');
        $put($length, null, 'DTEND:20270112T210000Z');
        checkEq('PUT changing only its length: its own length, and it keeps its own time', ['time', 'length'], $ownOf($length));
        $at20($length);
        checkEq('then the series at 20:00: it stays at its own 19:00', '2027-01-12 19:00:00', $shown($length));

        // The feed path (and Google's, which goes through it).
        $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample events feed', 'kind' => 'subscribed', 'color' => '#336699']);
        $feeds = new BetterCal\Domain\Feeds($db);
        $calendar = $db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]);
        $ev = static fn(string $uid, ?string $key, string $start, string $end, string $tzid = 'UTC'): array => ['uid' => $uid, 'title' => 'Sample recital', 'description' => null,
            'location' => null, 'url' => null, 'start_utc' => $start, 'end_utc' => $end, 'all_day' => 0, 'tzid' => $tzid,
            'rrule' => $key === null ? 'FREQ=WEEKLY;BYDAY=TU' : null, 'exdates' => [], 'status' => 'confirmed', 'recurrence_instance_utc' => $key];
        $feedOwn = static fn(string $uid) => json_decode((string) $db->scalar('SELECT override_fields FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_parent_id IS NOT NULL', [$calendarId, $uid]), true);
        $master = static fn(string $uid): array => $ev($uid, null, '2027-01-05 19:00:00', '2027-01-05 20:30:00');
        $feedDay = static function (string $uid, string $start, string $end, string $tzid = 'UTC') use ($feeds, $calendar, $ev, $master): void {
            $feeds->sync($calendar, [$master($uid), $ev($uid, '2027-01-12 18:00:00', $start, $end, $tzid)]);
        };
        // Its own 19:00 and 90 minutes; then the series takes the same, the day echoed.
        $feedEqual = static function (string $uid) use ($feeds, $calendar, $ev, $feedDay): void {
            $feeds->sync($calendar, [$ev($uid, null, '2027-01-05 18:00:00', '2027-01-05 19:00:00'), $ev($uid, '2027-01-12 18:00:00', '2027-01-12 19:00:00', '2027-01-12 20:30:00')]);
            $feedDay($uid, '2027-01-12 19:00:00', '2027-01-12 20:30:00');
        };
        $feedEqual('feed-echo@example.test');
        checkEq('feed: own time and length kept when the series takes the same values', ['time', 'length'], $feedOwn('feed-echo@example.test'));
        $feedDay('feed-echo@example.test', '2027-01-12 19:00:00', '2027-01-12 20:30:00');
        checkEq('feed: echoed again, unchanged', ['time', 'length'], $feedOwn('feed-echo@example.test'));
        $feedEqual('feed-date@example.test');
        $feedDay('feed-date@example.test', '2027-01-14 19:00:00', '2027-01-14 20:30:00');
        checkEq('feed: only its date moved there: its own date, and it keeps its own time and length', ['date', 'time', 'length'], $feedOwn('feed-date@example.test'));
        $feedEqual('feed-time@example.test');
        $feedDay('feed-time@example.test', '2027-01-12 19:30:00', '2027-01-12 21:00:00');
        checkEq('feed: only its clock changed there: its own time, and it keeps its own length', ['time', 'length'], $feedOwn('feed-time@example.test'));
        $feedEqual('feed-zone@example.test');
        $feedDay('feed-zone@example.test', '2027-01-13 00:00:00', '2027-01-13 01:30:00', 'America/New_York');
        checkEq('feed: only its zone changed there: its own time, and it keeps its own length', ['time', 'length'], $feedOwn('feed-zone@example.test'));
        $feedEqual('feed-length@example.test');
        $feedDay('feed-length@example.test', '2027-01-12 19:00:00', '2027-01-12 21:00:00');
        checkEq('feed: only its length changed there: its own length, and it keeps its own time', ['time', 'length'], $feedOwn('feed-length@example.test'));
        $feedDay('feed-length@example.test', '2027-01-12 19:00:00', '2027-01-12 20:30:00');
        checkEq('feed: its length put back to the series\' there: inherited, its time still its own', ['time'], $feedOwn('feed-length@example.test'));
    });

    scenario('a day re-keyed onto the key of an edited day the plan deletes: the leaving row lets go of its key first', function () use ($d, $u, $db, $rows, $home): void {
        $daily = (int) $d->events->create($u, eventAt($home, 'Sample walk', '2027-01-01', ['rrule' => 'FREQ=DAILY']))['eventId'];
        // 4 Jan on a date of its own (6 Jan); 11 Jan renamed.
        $d->events->patch($u, $daily, ['scope' => 'this', 'instanceStart' => '2027-01-04T18:00:00+00:00', 'start' => '2027-01-06T18:00:00+00:00', 'end' => '2027-01-06T19:00:00+00:00']);
        $d->events->patch($u, $daily, ['scope' => 'this', 'instanceStart' => '2027-01-11T18:00:00+00:00', 'title' => 'Sample walk, long']);
        $before = $rows($daily);
        // Every day a week later, nine of them: 4 Jan's occurrence goes to 11 Jan, the key 11 Jan's row holds until it is deleted.
        planned($d->events, $u, $daily, ['scope' => 'all', 'instanceStart' => '2027-01-01T18:00:00+00:00', 'start' => '2027-01-08T18:00:00+00:00', 'end' => '2027-01-08T19:00:00+00:00', 'rrule' => 'FREQ=DAILY;COUNT=9'],
            ['outside' => 'delete', 'movedDays' => 'move']);
        checkEq('committed: nine days from 8 Jan, 4 Jan\'s row on the 11 Jan key, the renamed day past the end deleted', [
            'FREQ=DAILY;COUNT=9', '2027-01-08 18:00:00', [['2027-01-11 18:00:00', '2027-01-13 18:00:00', 'Sample walk']],
        ], [
            $db->scalar('SELECT rrule FROM events WHERE id = ?', [$daily]), $db->scalar('SELECT start_utc FROM events WHERE id = ?', [$daily]),
            array_map(static fn(array $r): array => array_values($r), $db->all('SELECT recurrence_instance_utc, start_utc, title FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$daily])),
        ]);
        $d->undo->undoLatest($u);
        checkEq('undone: every row of the series as before', $before, $rows($daily));
    });

    scenario('series plans: acceptance cases 8, 11 and 12 committed and undone', function () use ($d, $u, $db, $rows, $home): void {
        $col = static fn(int $id, string $c): mixed => $db->scalar("SELECT $c FROM events WHERE id = ?", [$id]);
        // Acceptance 8: sessions 1 and 2 removed, the start moved to session 3's date (no day named).
        $course = (int) $d->events->create($u, eventAt($home, 'Pottery class', '2027-01-04', ['rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=8']))['eventId'];
        $d->events->deleteEvent($u, $course, 'this', '2027-01-04T18:00:00+00:00');
        $d->events->deleteEvent($u, $course, 'this', '2027-01-11T18:00:00+00:00');
        $before = $rows($course);
        $plan = planned($d->events, $u, $course, ['scope' => 'all', 'start' => '2027-01-18T18:00:00+00:00', 'end' => '2027-01-18T19:00:00+00:00', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=8']);
        checkEq('Acceptance 8: the plan drops both removed days before the new start and counts none', [[['dropped', 'start'], ['dropped', 'start']], 0],
            [array_map(static fn(array $x): array => [$x['outcome'], $x['side'] ?? null], $plan['days']), $plan['counts']['skipped']]);
        checkEq('Acceptance 8 committed: COUNT=8 from 18 Jan, no skips remain', ['FREQ=WEEKLY;BYDAY=MO;COUNT=8', '2027-01-18 18:00:00', null],
            [$col($course, 'rrule'), $col($course, 'start_utc'), $col($course, 'exdates_json')]);
        $d->undo->undoLatest($u);
        checkEq('Acceptance 8 undone: every row byte-identical, both skips back', $before, $rows($course));

        // Acceptance 11: a day moved on its own to a Friday, then Monday to Wednesday, each choice.
        $wheel = (int) $d->events->create($u, eventAt($home, 'Pottery', '2027-01-04', ['rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=8']))['eventId'];
        $d->events->patch($u, $wheel, ['scope' => 'this', 'instanceStart' => '2027-01-18T18:00:00+00:00', 'start' => '2027-01-22T18:00:00+00:00', 'end' => '2027-01-22T19:00:00+00:00']);
        $before = $rows($wheel);
        $drag = ['scope' => 'all', 'instanceStart' => '2027-01-11T18:00:00+00:00', 'start' => '2027-01-13T18:00:00+00:00', 'end' => '2027-01-13T19:00:00+00:00'];
        $day = static fn() => array_values($db->one('SELECT recurrence_instance_utc, start_utc FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$wheel]) ?? []);
        foreach (['keep' => '2027-01-22 18:00:00', 'move' => '2027-01-24 18:00:00'] as $choice => $shows) {
            planned($d->events, $u, $wheel, $drag, ['movedDays' => $choice]);
            checkEq("Acceptance 11, $choice: weekly on Wednesday, the moved day keyed Wed 20 Jan, one row, shown on $shows", ['FREQ=WEEKLY;BYDAY=WE;COUNT=8', ['2027-01-20 18:00:00', $shows], 1],
                [$col($wheel, 'rrule'), $day(), (int) $db->scalar('SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$wheel])]);
            $d->undo->undoLatest($u);
            checkEq("Acceptance 11, $choice undone: byte-identical", $before, $rows($wheel));
        }

        // Acceptance 12: all events from an edited day's own row, renamed and moved a day.
        $choir = (int) $d->events->create($u, eventAt($home, 'Choir', '2027-01-05', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU']))['eventId'];
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-03-09T18:00:00+00:00', 'title' => 'Choir, sectionals', 'start' => '2027-03-09T17:00:00+00:00', 'end' => '2027-03-09T18:00:00+00:00']);
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-03-23T18:00:00+00:00', 'title' => 'Choir, concert']);
        $sectionals = (int) $db->scalar("SELECT id FROM events WHERE recurrence_parent_id = ? AND title = 'Choir, sectionals'", [$choir]);
        $before = $rows($choir);
        $plan = planned($d->events, $u, $sectionals, ['scope' => 'all', 'start' => '2027-03-10T17:00:00+00:00', 'end' => '2027-03-10T18:00:00+00:00', 'title' => 'Chorus']);
        checkEq('Acceptance 12: started from the edited day, the series on Wednesday as Chorus, the day at its own 17:00, the concert keeps its title', [
            'Choir, sectionals', 'FREQ=WEEKLY;BYDAY=WE', 'Chorus',
            ['2027-03-10 18:00:00', '2027-03-10 17:00:00', 'Chorus', ['time']], ['2027-03-24 18:00:00', 'Choir, concert'],
        ], [
            $plan['acted']['title'] ?? null, $col($choir, 'rrule'), $col($choir, 'title'),
            [$col($sectionals, 'recurrence_instance_utc'), $col($sectionals, 'start_utc'), $col($sectionals, 'title'), json_decode((string) $col($sectionals, 'override_fields'), true)],
            array_values($db->one("SELECT recurrence_instance_utc, title FROM events WHERE recurrence_parent_id = ? AND title = 'Choir, concert'", [$choir]) ?? []),
        ]);
        $d->undo->undoLatest($u);
        checkEq('Acceptance 12 undone: byte-identical', $before, $rows($choir));
    });

    scenario('a stale plan: tags, people and columns of the edited days all count',function () use ($d, $u, $db, $weekly, $answer): void {
        $tagsOf = static fn(int $id): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$id]), 'name');
        $peopleOf = static fn(int $id): array => array_column($db->all('SELECT p.name FROM event_people ep JOIN people p ON p.id = ep.person_id WHERE ep.event_id = ? ORDER BY p.name', [$id]), 'name');
        $s = $weekly('Sample club');
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'tagNames' => ['Original tag'], 'personNames' => ['Alex Example']]);
        $day = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$s]);
        // The model's choices sent with each preview and with the stale commit.
        $stale = static function (array $edit, string $group, callable $meanwhile) use ($d, $u, $s, $answer): array {
            $plan = $d->events->previewPatch($u, $s, $edit + ['plan' => ['choices' => [$group => 'give']]]);
            $meanwhile();
            $again = $d->events->previewPatch($u, $s, $edit + ['plan' => ['choices' => [$group => 'give']]]);
            [$code] = $answer(fn() => $d->events->patch($u, $s, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => [$group => 'give']]]));
            return [$again['hash'] !== $plan['hash'], $code];
        };
        $edit = ['scope' => 'all', 'instanceStart' => '2027-01-05T18:00:00+00:00', 'tagNames' => ['Proposed series tag']];
        $result = $stale($edit, 'ownTags', fn() => $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'tagNames' => ['Newer tag']]));
        checkEq('a tag-only change to an edited day already owning its tags: a new hash, the old commit 409 series_plan_changed, the newer tag kept', [
            true, '409 series_plan_changed', ['Newer tag'],
        ], [...$result, $tagsOf($day)]);
        $edit = ['scope' => 'all', 'instanceStart' => '2027-01-05T18:00:00+00:00', 'personNames' => ['Sam Sample']];
        $result = $stale($edit, 'ownPeople', fn() => $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'personNames' => ['Newer person']]));
        checkEq('a people-only change to an edited day already owning its people: a new hash, the old commit 409, the newer person kept', [
            true, '409 series_plan_changed', ['Newer person'],
        ], [...$result, $peopleOf($day)]);
        // An ordinary column, its timestamp left as it was (a write in the same second).
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'description' => 'Original notes']);
        $edit = ['scope' => 'all', 'instanceStart' => '2027-01-05T18:00:00+00:00', 'description' => 'Proposed series notes'];
        $result = $stale($edit, 'ownDescription', fn() => $db->run('UPDATE events SET description = ? WHERE id = ?', ['Newer notes', $day]));
        checkEq('a column changed within the same second: a new hash, the old commit 409, the newer notes kept', [true, '409 series_plan_changed', 'Newer notes'],
            [...$result, $db->scalar('SELECT description FROM events WHERE id = ?', [$day])]);
        planned($d->events, $u, $s, $edit, ['ownDescription' => 'give']);
        checkEq('the fresh plan commits', 'Proposed series notes', $db->scalar('SELECT description FROM events WHERE id = ?', [$day]));
    });

    // Review F7: an instant given stays that instant. 02:30 on 31 Oct 2027
    // in Berlin is 00:30Z and again 01:30Z; written, committed and undone.
    scenario('a repeated hour: edited days keep their instant, the day acted on lands on the one it was put on (review F7)', function () use ($d, $u, $db, $rows, $home): void {
        $berlin = static fn(string $start, string $end, array $more = []): array => ['calendarId' => $home, 'title' => 'Sample fold walk', 'start' => $start, 'end' => $end, 'allDay' => false, 'tzid' => 'Europe/Berlin'] + $more;
        $at = static fn(int $id, string $key): ?array => $db->one('SELECT start_utc, end_utc, override_fields FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ?', [$id, $key]);
        // Variant A: the series stored at the second instant, its first day's own title; a rename leaves the day there.
        $walk = (int) $d->events->create($u, $berlin('2027-10-31T02:30:00+01:00', '2027-10-31T03:30:00+01:00', ['rrule' => 'FREQ=WEEKLY;COUNT=3']))['eventId'];
        $d->events->patch($u, $walk, ['scope' => 'this', 'instanceStart' => '2027-10-31T01:30:00Z', 'title' => 'Sample fold walk, own']);
        $before = $rows($walk);
        planned($d->events, $u, $walk, ['scope' => 'all', 'instanceStart' => '2027-11-07T01:30:00Z', 'title' => 'Sample fold walk, renamed'], ['ownTitle' => 'keep']);
        checkEq('variant A: renamed from 7 Nov, the edited day keeps its instant, 01:30Z', ['start_utc' => '2027-10-31 01:30:00', 'end_utc' => '2027-10-31 02:30:00', 'override_fields' => '["title"]'], $at($walk, '2027-10-31 01:30:00'));
        $d->undo->undoLatest($u);
        checkEq('variant A undone: byte-identical', $before, $rows($walk));
        // Variant B: the 2027 day put on the first instant where its image's key is the second.
        $landing = (int) $d->events->create($u, $berlin('2026-10-21T02:30:00+02:00', '2026-10-21T03:30:00+02:00', ['rrule' => 'FREQ=YEARLY;UNTIL=20281021T003000Z']))['eventId'];
        $before = $rows($landing);
        planned($d->events, $u, $landing, ['scope' => 'all', 'instanceStart' => '2027-10-21T02:30:00+02:00', 'start' => '2027-10-31T02:30:00+02:00', 'end' => '2027-10-31T02:15:00+01:00', 'status' => 'tentative']);
        $master = $db->one('SELECT start_utc, end_utc FROM events WHERE id = ?', [$landing]);
        checkEq('variant B: the day shows at 00:30Z for its 45 minutes, its time its own, keyed on its image; the series 45 minutes long', [
            ['start_utc' => '2027-10-31 00:30:00', 'end_utc' => '2027-10-31 01:15:00', 'override_fields' => '["time"]'], 2700,
        ], [$at($landing, '2027-10-31 01:30:00'), strtotime($master['end_utc'] . ' UTC') - strtotime($master['start_utc'] . ' UTC')]);
        $d->undo->undoLatest($u);
        checkEq('variant B undone: byte-identical, no edited row left', $before, $rows($landing));
        // "Use series value" for the time puts a day back on its key, never the other instant.
        $first = (int) $d->events->create($u, $berlin('2027-10-31T02:30:00+02:00', '2027-10-31T03:30:00+02:00', ['rrule' => 'FREQ=WEEKLY;COUNT=3']))['eventId'];
        $d->events->patch($u, $first, ['scope' => 'this', 'instanceStart' => '2027-10-31T00:30:00Z', 'title' => 'Sample fold walk, own', 'start' => '2027-10-31T02:30:00+01:00', 'end' => '2027-10-31T03:30:00+01:00']);
        checkEq('a day moved to the second instant of its repeated hour owns its time', ['start_utc' => '2027-10-31 01:30:00', 'end_utc' => '2027-10-31 02:30:00', 'override_fields' => '["time","title"]'], $at($first, '2027-10-31 00:30:00'));
        $d->events->patch($u, $first, ['scope' => 'this', 'instanceStart' => '2027-10-31T00:30:00Z', 'useSeries' => ['time']]);
        checkEq('"Use series value" for its time: back on its key, 00:30Z', ['start_utc' => '2027-10-31 00:30:00', 'end_utc' => '2027-10-31 01:30:00', 'override_fields' => '["title"]'], $at($first, '2027-10-31 00:30:00'));
    });

    // Review F1: a row also exists for what refers to it. An edited day in a
    // trip (a trip's own day with a stop in it, or a day that is a stop of
    // another trip) owns its trip, so taking the series' title leaves it a
    // row: it keeps its trip, and never rejoins while a link names it.
    scenario('an edited day in a trip keeps its row and its links, in both directions (review F1)', function () use ($d, $u, $db, $rows, $home): void {
        $trips = new BetterCal\Domain\Trips($db, $d->undo);
        $links = static fn(array $ids): array => $db->all('SELECT * FROM event_links WHERE event_id IN (' . implode(',', $ids) . ') OR container_id IN (' . implode(',', $ids) . ') ORDER BY id');
        $cases = [];
        // The edited day is a trip with a stop in it.
        $tripSeries = (int) $d->events->create($u, eventAt($home, 'Sample trip series', '2027-01-05', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU', 'isContainer' => true]))['eventId'];
        $d->events->patch($u, $tripSeries, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Sample trip day']);
        $tripDay = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$tripSeries]);
        $stop = (int) $d->events->create($u, eventAt($home, 'Sample stop', '2027-01-12'))['eventId'];
        $trips->attach($u, $tripDay, $stop);
        $cases['the day is the trip'] = [$tripSeries, $tripDay, $stop];
        // The edited day is a stop of another trip.
        $stopSeries = (int) $d->events->create($u, eventAt($home, 'Sample stop series', '2027-01-05', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU']))['eventId'];
        $d->events->patch($u, $stopSeries, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Sample stop day']);
        $stopDay = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$stopSeries]);
        $trip = (int) $d->events->create($u, eventAt($home, 'Sample trip', '2027-01-12', ['isContainer' => true]))['eventId'];
        $trips->attach($u, $trip, $stopDay);
        $cases['the day is a stop'] = [$stopSeries, $stopDay, $trip];
        foreach ($cases as $name => [$series, $day, $other]) {
            $before = [$rows($series), $rows($other), $links([$day, $other])];
            $plan = planned($d->events, $u, $series, ['scope' => 'all', 'title' => 'Renamed sample series'], ['ownTitle' => 'give']);
            $planned = array_values(array_filter($plan['days'] ?? [], static fn(array $x): bool => $x['kind'] === 'edited'))[0] ?? [];
            checkEq("$name: given the new title, the day keeps its row, owns its trip and stays, its link as it was", [
                'Renamed sample series', '[]', $before[2], 'stays', ['trip'], "stays, keeps its trip, takes 'Renamed sample series'",
            ], [
                $db->scalar('SELECT title FROM events WHERE id = ?', [$day]), $db->scalar('SELECT override_fields FROM events WHERE id = ?', [$day]),
                $links([$day, $other]), $planned['outcome'] ?? null, $planned['kept'] ?? null, $planned['outcomeWords'] ?? null,
            ]);
            $d->undo->undoLatest($u);
            checkEq("$name: undone, the series, the other event and the link are byte-identical", $before, [$rows($series), $rows($other), $links([$day, $other])]);
        }
        // "Use series value" keeps the same rule: the day's only own field
        // given back, its trip keeps the row.
        [$series, $day, $other] = $cases['the day is a stop'];
        $before = [$rows($series), $links([$day, $other])];
        $d->events->patch($u, $series, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'useSeries' => ['title']]);
        checkEq('"Use series value" on a stop\'s only own field: the row stays with no field of its own listed, its link kept', [
            'Sample stop series', '[]', $before[1],
        ], [$db->scalar('SELECT title FROM events WHERE id = ?', [$day]), $db->scalar('SELECT override_fields FROM events WHERE id = ?', [$day]), $links([$day, $other])]);
        $d->undo->undoLatest($u);
        checkEq('undone: the stop day and its link as before', $before, [$rows($series), $links([$day, $other])]);
        // Once nothing refers to it, the same rename rejoins it.
        $trips->detach($u, $other, $day);
        $plan = planned($d->events, $u, $series, ['scope' => 'all', 'title' => 'Renamed sample series'], ['ownTitle' => 'give']);
        checkEq('taken out of the trip, the day has nothing of its own left and goes back to the series', [0, ['rejoins']], [
            (int) $db->scalar('SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ?', [$series]),
            array_column(array_filter($plan['days'] ?? [], static fn(array $x): bool => $x['kind'] === 'edited'), 'outcome'),
        ]);
    });
};
