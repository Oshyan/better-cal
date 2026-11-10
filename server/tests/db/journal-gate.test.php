<?php

declare(strict_types=1);

// The journal format gate over every operation that takes an entry back
// (rule 3 of 1.5.5): no path restores from, or completes on the strength of,
// an entry in an older format. One table, one row per operation. Each
// fixture is made with today's code (a local edit, its undo, a plugin run,
// an accepted proposal, a Google series change, a Google day edit, a series
// undo stopped half way, a redo stopped half way), then the account's
// entries are set to format 1, as every entry an upgrade finds is, in two
// variants: with migration 077 run (snapshots dropped) and with the
// snapshots left in place (so the gate is what refuses, not missing data).
// The pending rows are also run with the live entry deleted and only the
// copy their plan kept (plan.entry) left: older, without `format`, or no copy.
// Each row asserts the refusal's code and words, no request to Google of any
// kind, and every table the journal or a restore touches equal before and
// after. Put back is the one operation that goes through. A last check lists
// the public methods that take an entry or a pending row and fails on one the
// table doesn't name. Against the probe's stand-in Google, invented data.

use BetterCal\Domain\ActivityContext;
use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSeries;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleUndo;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Proposals;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Undo;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

require_once dirname(__DIR__, 3) . '/tools/google-probe/lib/FakeGoogle.php';

/** A class the take-back check has never seen, with a method taking a pending id: the check must report it. */
final class JournalGateSample
{
    public function completeUndo(int $userId, int $pendingId): void
    {
    }
}

return static function (Db $db): void {
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $tz = 'America/Los_Angeles';
    $la = new DateTimeZone($tz);
    $iso = static fn(string $local): string => (new DateTimeImmutable($local, $la))->format('Y-m-d\TH:i:sP');
    $when = static fn(string $local): array => ['dateTime' => $iso($local), 'timeZone' => $tz];
    $migrate = require dirname(__DIR__, 2) . '/migrations/077_journal_format.php';

    // The words, from the decisions entry (Journal entries carry their format), not from the code.
    $older = "Recorded by an earlier version of Better-Cal; it can't be undone after the upgrade.";
    $finishUndo = "The change this undoes was recorded by an earlier version of Better-Cal and can't be undone after the upgrade, so Finish can't complete this undo. Put back returns the series to how it was before the undo began, unless it changed at Google since.";
    $finishRedo = "The undo this redoes was recorded by an earlier version of Better-Cal and can't be redone after the upgrade, so Finish can't complete this redo. Put back returns the series to how it was before the redo began, unless it changed at Google since.";

    $expand = static function (array $master): array {
        $allDay = isset($master['start']['date']);
        $zone = $allDay ? 'UTC' : (string) ($master['start']['timeZone'] ?? 'UTC');
        $start = $allDay ? new DateTimeImmutable($master['start']['date'] . ' 00:00:00', new DateTimeZone('UTC')) : new DateTimeImmutable((string) $master['start']['dateTime']);
        $end = $allDay ? new DateTimeImmutable($master['end']['date'] . ' 00:00:00', new DateTimeZone('UTC')) : new DateTimeImmutable((string) $master['end']['dateTime']);
        $rrule = null;
        foreach ((array) ($master['recurrence'] ?? []) as $line) {
            if (str_starts_with((string) $line, 'RRULE:')) {
                $rrule = substr((string) $line, 6);
            }
        }
        if ($rrule === null) {
            return [];
        }
        $row = ['start_utc' => Time::toDb($start), 'end_utc' => Time::toDb($end), 'all_day' => $allDay ? 1 : 0, 'tzid' => $zone, 'rrule' => $rrule];
        return array_map(static fn(array $o): DateTimeImmutable => $o['start']->setTimezone(new DateTimeZone($zone)), Recurrence::occurrences($row, $start, $start->modify('+30 years'), null, 730));
    };

    // Invented: weekly Mondays 10:00 Los Angeles from 1 March 2027, ten of them, the fifth with its own title.
    $setup = static function () use ($db, $cfg, $expand, $when): object {
        $u = freshUser($db);
        $d = domains($db);
        $fake = new FakeGoogle('crew@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->expand = $expand;
        $fake->seed(['id' => 'smpl3weekly', 'iCalUID' => 'smpl3weekly@google.com', 'summary' => 'Sample weekly', 'description' => 'Sample notes', 'location' => 'Sample room 1',
            'start' => $when('2027-03-01 10:00'), 'end' => $when('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
        $fake->seed(['id' => 'smpl3weekly_20270329T170000Z', 'iCalUID' => 'smpl3weekly@google.com', 'recurringEventId' => 'smpl3weekly', 'summary' => 'Sample weekly, special',
            'description' => 'Sample notes', 'location' => 'Sample room 1', 'originalStartTime' => $when('2027-03-29 10:00'), 'start' => $when('2027-03-29 10:00'), 'end' => $when('2027-03-29 11:00')]);
        $t = new class {
            /** @var list<array{0:string,1:string}> method, id */
            public array $calls = [];
            public ?int $staleAt = null;
            /** Every write refused as Google refuses an account that may no longer edit the calendar. */
            public bool $forbidden = false;
            public int $writes = 0;
        };
        $inner = $fake->writerTransport();
        $transport = static function (string $method, string $url, ?array $payload, array $headers = []) use ($t, $inner): array {
            $t->calls[] = [$method, rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)))];
            if ($method !== 'GET') {
                $t->writes++;
                if ($t->forbidden) {
                    return ['status' => 403, 'body' => json_encode(['error' => ['code' => 403, 'message' => 'Forbidden', 'errors' => [['reason' => 'forbidden', 'message' => 'Forbidden']]]])];
                }
                if ($t->staleAt === $t->writes) {
                    return ['status' => 412, 'body' => json_encode(['error' => ['code' => 412, 'message' => 'Precondition Failed']])];
                }
            }
            return $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
        };
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $writer = new GoogleWriter($db, $auth, $feeds, $transport);
        $syncTransport = $fake->syncTransport();
        $sync = new GoogleSync($db, $auth, $feeds, static fn(string $url, array $headers): array => $syncTransport($url, array_merge(['Authorization: Bearer sample-access'], $headers)));
        $undo = new Undo($db, null, $writer);
        $events = new Events($db, new Recurrence(), $undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $undo, new BetterCal\Infra\JobQueue($db)), new BetterCal\Domain\Trips($db, $undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample crew', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'crew@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $t->calls = [];
        $t->writes = 0;
        return (object) ['u' => $u, 'fake' => $fake, 't' => $t, 'sync' => $sync, 'events' => $events, 'calendarId' => $calendarId, 'undo' => $undo,
            'labels' => new BetterCal\Domain\Labels($db), 'local' => Settings::defaultCalendarId($db, $u), 'calendars' => $d->calendars,
            'series' => new GoogleSeries($db, $writer, $feeds), 'account' => $account];
    };
    $masterId = static fn(object $s): int => (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, 'smpl3weekly']);
    $tagsOf = static fn(int $id): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$id]), 'name');
    $pendingRow = static fn(object $s): ?array => $db->one('SELECT * FROM google_pending WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$s->u]);

    // Every table the journal or a restore touches, per account, by table (so a failure names the table).
    $digest = static function (object $s) use ($db): array {
        $q = static fn(string $sql): string => md5(json_encode($db->all($sql, [$s->u])));
        return [
            'events' => $q('SELECT * FROM events WHERE user_id = ? ORDER BY id'),
            'event_tags' => $q('SELECT et.* FROM event_tags et JOIN events e ON e.id = et.event_id WHERE e.user_id = ? ORDER BY et.event_id, et.tag_id'),
            'event_people' => $q('SELECT ep.* FROM event_people ep JOIN events e ON e.id = ep.event_id WHERE e.user_id = ? ORDER BY ep.event_id, ep.person_id'),
            'event_links' => $q('SELECT l.* FROM event_links l JOIN events e ON e.id = l.event_id WHERE e.user_id = ? ORDER BY l.container_id, l.event_id'),
            'tags' => $q('SELECT * FROM tags WHERE user_id = ? ORDER BY id'),
            'people' => $q('SELECT * FROM people WHERE user_id = ? ORDER BY id'),
            'calendars' => $q('SELECT * FROM calendars WHERE user_id = ? ORDER BY id'),
            'mutations' => $q('SELECT * FROM mutations WHERE user_id = ? ORDER BY id'),
            'mutation_rows' => $q('SELECT r.* FROM mutation_rows r JOIN mutations m ON m.id = r.mutation_id WHERE m.user_id = ? ORDER BY r.mutation_id, r.seq'),
            'mutation_touches' => $q('SELECT t.* FROM mutation_touches t JOIN mutations m ON m.id = t.mutation_id WHERE m.user_id = ? ORDER BY 1, 2, 3'),
            'mutation_calendar_refs' => $q('SELECT c.* FROM mutation_calendar_refs c JOIN mutations m ON m.id = c.mutation_id WHERE m.user_id = ? ORDER BY 1, 2'),
            'google_pending' => $q('SELECT * FROM google_pending WHERE user_id = ? ORDER BY id'),
            'review_items' => $q('SELECT * FROM review_items WHERE user_id = ? ORDER BY id'),
            'plugin_proposals' => $q('SELECT * FROM plugin_proposals WHERE user_id = ? ORDER BY id'),
            'settings' => $q('SELECT settings_json FROM users WHERE id = ?'),
        ];
    };
    $changed = static fn(array $was, array $now): array => array_keys(array_diff_assoc($now, $was));

    // ---- fixtures, each made with today's code -------------------------------

    // A local event renamed: the entry is the rename.
    $local = static function (object $s) use ($db): array {
        $id = (int) $s->events->create($s->u, eventAt($s->local, 'Sample supper'))['eventId'];
        ActivityContext::takeEntries();
        $s->events->patch($s->u, $id, ['title' => 'Sample supper, moved']);
        return ['entry' => lastEntry($db, $s->u), 'request' => ActivityContext::takeEntries()];
    };
    // A Google day's rename undone: the entry is the undo's own (`google.undoes`), which its redo takes back.
    // (A local undo records no entry of its own: it marks the change undone.)
    $redo = static function (object $s) use ($masterId, $iso, $db): array {
        $s->events->patch($s->u, $masterId($s), ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00'), 'title' => 'Sample day title']);
        $s->events->throwDeferred();
        $s->undo->undoById($s->u, lastEntry($db, $s->u));
        $entry = lastEntry($db, $s->u);
        if ((json_decode((string) $db->scalar('SELECT details_json FROM mutations WHERE id = ?', [$entry]), true)['google']['undoes'] ?? null) === null) {
            throw new RuntimeException('the undo recorded no entry of its own');
        }
        return ['entry' => $entry];
    };
    // A plugin run that made and renamed an event, and a proposal accepted as that run.
    $run = static function (object $s) use ($db): array {
        $runId = 'samplerun' . bin2hex(random_bytes(4));
        ActivityContext::withRun('plugin:sample', $runId, function () use ($s): void {
            $id = (int) $s->events->create($s->u, eventAt($s->local, 'Sample plugin event'))['eventId'];
            $s->events->patch($s->u, $id, ['title' => 'Sample plugin event, renamed']);
        });
        $proposal = $db->insert('plugin_proposals', ['plugin_id' => 'sample', 'user_id' => $s->u, 'source_key' => 'sample-' . $runId, 'title' => 'Sample proposal',
            'plan_json' => '{}', 'status' => 'accepted', 'accepted_run_id' => $runId]);
        return ['run' => $runId, 'proposal' => $proposal, 'entries' => (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE run_id = ?', [$runId])];
    };
    // A Google series renamed for all events.
    $gseries = static function (object $s) use ($masterId, $iso, $db): array {
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'title' => 'Sample renamed']);
        return ['entry' => lastEntry($db, $s->u)];
    };
    // One day of the Google series renamed ("this event").
    $gday = static function (object $s) use ($masterId, $iso, $db): array {
        $s->events->patch($s->u, $masterId($s), ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00'), 'title' => 'Sample day title']);
        $s->events->throwDeferred();
        return ['entry' => lastEntry($db, $s->u)];
    };
    // The series' tag, then a rename with a new tag for all events, then its undo stopped after Google's first write.
    $pendingUndo = static function (object $s) use ($db, $masterId, $iso): array {
        $s->labels->setEventTags($s->u, $masterId($s), ['sample-tag']);
        $db->run('INSERT IGNORE INTO event_tags (event_id, tag_id) SELECT e.id, et.tag_id FROM events e JOIN event_tags et ON et.event_id = e.recurrence_parent_id WHERE e.recurrence_parent_id = ?', [$masterId($s)]);
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'title' => 'Sample renamed', 'tagNames' => ['Sample new tag']]);
        $entry = lastEntry($db, $s->u);
        $s->t->writes = 0;
        $s->t->staleAt = 2;
        refusal(fn() => $s->undo->undoById($s->u, $entry));
        $s->t->staleAt = null;
        $p = $db->one('SELECT id FROM google_pending WHERE user_id = ?', [$s->u]);
        if ($p === null) {
            throw new RuntimeException('the undo left no pending row');
        }
        return ['entry' => $entry, 'pending' => (int) $p['id']];
    };
    // The rename undone, then its redo stopped after Google's first write.
    $pendingRedo = static function (object $s) use ($db, $masterId, $iso): array {
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'title' => 'Sample renamed']);
        $s->undo->undoById($s->u, lastEntry($db, $s->u));
        $undoEntry = lastEntry($db, $s->u);
        $s->t->writes = 0;
        $s->t->staleAt = 2;
        refusal(fn() => $s->undo->undoById($s->u, $undoEntry));
        $s->t->staleAt = null;
        $p = $db->one('SELECT id FROM google_pending WHERE user_id = ?', [$s->u]);
        if ($p === null) {
            throw new RuntimeException('the redo left no pending row');
        }
        return ['entry' => $undoEntry, 'pending' => (int) $p['id']];
    };
    // The pending row's live entry deleted (what retention does in time), its plan's copy older, without `format`, or gone.
    $liveGone = static function (string $copy) use ($db): Closure {
        return static function (object $s, array $f) use ($db, $copy): void {
            $db->run('DELETE FROM mutations WHERE id = ?', [$f['entry']]);
            $row = $db->one('SELECT plan_json FROM google_pending WHERE id = ?', [$f['pending']]);
            $plan = json_decode((string) $row['plan_json'], true);
            if ($copy === 'older') {
                $plan['entry']['format'] = 1;
            } elseif ($copy === 'unmarked') {
                unset($plan['entry']['format']);
            } else {
                unset($plan['entry']);
            }
            $db->update('google_pending', ['plan_json' => json_encode($plan)], 'id = ?', [$f['pending']]);
        };
    };

    $words = static function (callable $fn): array {
        try {
            return ['went through', $fn()];
        } catch (HttpError $e) {
            return [$e->errorCode, $e->getMessage()];
        }
    };

    // Finish and Put back as Review's controller runs them: the answer, then the deferred error of a run that stopped.
    $asReview = static function (object $s, string $how, int $pendingId): array {
        $out = $s->events->{$how}($s->u, $pendingId);
        $s->events->throwDeferred();
        return $out;
    };

    // ---- the table: one row per operation that takes an entry back ------------
    // [name, fixture, then (after the entries are older), the operation, expected [code, words] or a closure judging the outcome]
    $table = [
        ['Undo::undoById', $local, null, fn(object $s, array $f) => $words(fn() => $s->undo->undoById($s->u, $f['entry'])), ['not_undoable', $older]],
        ['Undo::undoById forced', $local, null, fn(object $s, array $f) => $words(fn() => $s->undo->undoById($s->u, $f['entry'], true)), ['not_undoable', $older]],
        ['Undo::undoLatest', $local, null, fn(object $s, array $f) => $words(fn() => $s->undo->undoLatest($s->u)), ['nothing_to_undo', 'Nothing to undo']],
        ['toast Undo (undoById of the request\'s entries)', $local, null, fn(object $s, array $f) => $words(function () use ($s, $f): void {
            foreach (array_reverse($f['request']) as $id) {
                $s->undo->undoById($s->u, (int) $id);
            }
        }), ['not_undoable', $older]],
        ['redo (undoById of an undo\'s entry)', $redo, null, fn(object $s, array $f) => $words(fn() => $s->undo->undoById($s->u, $f['entry'])), ['not_undoable', $older]],
        ['Undo::undoRun', $run, null, fn(object $s, array $f) => $words(fn() => $s->undo->undoRun($s->u, $f['run'])),
            fn(array $out, array $f): bool => $out === ['went through', ['undone' => 0, 'skipped' => $f['entries'], 'refused' => []]] && $f['entries'] === 2],
        ['Undo::undoRun strict', $run, null, fn(object $s, array $f) => $words(fn() => $s->undo->undoRun($s->u, $f['run'], true)), ['not_undoable', $older]],
        ['Proposals::undoAccept', $run, null, fn(object $s, array $f) => $words(fn() => (new Proposals($db, $s->events, new BetterCal\Domain\Trips($db, $s->undo)))->undoAccept($s->u, $f['proposal'])),
            ['proposal_undo_incomplete', 'This proposal can no longer be completely undone. Nothing was changed; remove any events manually if needed.']],
        ['GoogleUndo::undo, a series change (undoById)', $gseries, null, fn(object $s, array $f) => $words(fn() => $s->undo->undoById($s->u, $f['entry'])), ['not_undoable', $older]],
        ['GoogleUndo::undo, a series change, forced', $gseries, null, fn(object $s, array $f) => $words(fn() => $s->undo->undoById($s->u, $f['entry'], true)), ['not_undoable', $older]],
        ['GoogleUndo::undo, a day\'s edit (undoById)', $gday, null, fn(object $s, array $f) => $words(fn() => $s->undo->undoById($s->u, $f['entry'])), ['not_undoable', $older]],
        ['SeriesPlans::googleFinish of a pending undo', $pendingUndo, null, fn(object $s, array $f) => $words(fn() => $asReview($s, "googleFinish", $f["pending"])), ['not_undoable', $finishUndo]],
        ['SeriesPlans::googleFinish of a pending redo', $pendingRedo, null, fn(object $s, array $f) => $words(fn() => $asReview($s, "googleFinish", $f["pending"])), ['not_undoable', $finishRedo]],
        ['SeriesPlans::googleFinish, entry gone, its copy older', $pendingUndo, $liveGone('older'), fn(object $s, array $f) => $words(fn() => $asReview($s, "googleFinish", $f["pending"])), ['not_undoable', $finishUndo]],
        ['SeriesPlans::googleFinish, entry gone, its copy without format', $pendingUndo, $liveGone('unmarked'), fn(object $s, array $f) => $words(fn() => $asReview($s, "googleFinish", $f["pending"])), ['not_undoable', $finishUndo]],
        ['SeriesPlans::googleFinish, entry gone, no copy', $pendingUndo, $liveGone('none'), fn(object $s, array $f) => $words(fn() => $asReview($s, "googleFinish", $f["pending"])), ['not_undoable', $finishUndo]],
        ['SeriesPlans::googleFinish, a redo, entry gone, its copy without format', $pendingRedo, $liveGone('unmarked'), fn(object $s, array $f) => $words(fn() => $asReview($s, "googleFinish", $f["pending"])), ['not_undoable', $finishRedo]],
        // The lowest public way to complete an undo, called as any other caller could, without Review's Finish around it.
        ['GoogleSeries::finish (direct)', $pendingUndo, null, fn(object $s, array $f) => $words(fn() => $s->series->finish($s->u,
            $db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]), $f['pending'])), ['not_undoable', $finishUndo]],
        ['GoogleSeries::finish (direct), entry gone, its copy without format', $pendingUndo, $liveGone('unmarked'), fn(object $s, array $f) => $words(fn() => $s->series->finish($s->u,
            $db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]), $f['pending'])), ['not_undoable', $finishUndo]],
        // Put back takes no entry back: it goes through, below.
        ['SeriesPlans::googlePutBack', $pendingUndo, null, fn(object $s, array $f) => $words(fn() => $asReview($s, "googlePutBack", $f["pending"])), 'goes through'],
        ['SeriesPlans::googlePutBack, entry gone, its copy without format', $pendingUndo, $liveGone('unmarked'), fn(object $s, array $f) => $words(fn() => $asReview($s, "googlePutBack", $f["pending"])), 'goes through'],
    ];

    foreach (['077 run (snapshots dropped)' => true, 'snapshots kept' => false] as $variant => $migrated) {
        foreach ($table as [$name, $make, $then, $op, $expect]) {
            scenario("journal gate [$variant]: $name", function () use ($db, $setup, $make, $then, $op, $expect, $migrated, $migrate, $digest, $changed, $name, $variant, $masterId, $tagsOf, $pendingRow): void {
                $s = $setup();
                $f = $make($s);
                $db->run('UPDATE mutations SET format = 1 WHERE user_id = ?', [$s->u]);
                if ($migrated) {
                    $migrate($db);
                }
                if ($then !== null) {
                    $then($s, $f);
                }
                $s->t->calls = [];
                $was = $digest($s);
                $entryWas = isset($f['entry']) ? $db->one('SELECT undone, format FROM mutations WHERE id = ?', [$f['entry']]) : null;
                $out = $op($s, $f);
                $s->events->throwDeferred();
                $label = "journal gate [$variant]: $name";
                if ($expect === 'goes through') {
                    // Put back: the series as the change left it, at Google and here (the stopped undo taken back), the
                    // pending row gone, the entry (when still there) not undone and still older, so still log only.
                    $entryNow = isset($f['entry']) ? $db->one('SELECT undone, format FROM mutations WHERE id = ?', [$f['entry']]) : null;
                    checkEq("$label: goes through, the change stands at Google and here, nothing pending, the entry as it was", [
                        'went through', true, 'Sample renamed', 'Sample renamed', ['Sample new tag'], null, $entryWas,
                    ], [$out[0], $out[1]['ok'] ?? null, $s->fake->events['smpl3weekly']['summary'], $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)]),
                        $tagsOf($masterId($s)), $pendingRow($s), $entryNow]);
                    return;
                }
                if ($expect instanceof Closure) {
                    check("$label: counted as skipped, nothing undone", $expect($out, $f), var_export($out, true));
                } else {
                    checkEq("$label: refused with the code and words", $expect, $out);
                }
                checkEq("$label: no request to Google of any kind", [], $s->t->calls);
                checkEq("$label: nothing written (every table as it was)", [], $changed($was, $digest($s)));
            });
        }
    }

    // ---- the reviewer's probe (R3 of cb2c6082..63a96467) -----------------------

    scenario('journal gate: a pending undo after the upgrade: Finish refuses before any request to Google, the pending row kept as it was; Put back still works; the control restores the original tag', function () use ($db, $setup, $pendingUndo, $migrate, $masterId, $tagsOf, $pendingRow, $finishUndo): void {
        foreach ([true, false] as $upgraded) {
            $s = $setup();
            $f = $pendingUndo($s);
            $tagsAtStop = $tagsOf($masterId($s));
            if ($upgraded) {
                $db->run('UPDATE mutations SET format = 1 WHERE user_id = ?', [$s->u]);
                $migrate($db);
            }
            seriesUndo($db, $s->u, false);
            $before = $pendingRow($s);
            $s->t->calls = [];
            $caught = null;
            $out = null;
            try {
                $out = $s->events->googleFinish($s->u, $f['pending']);
                $s->events->throwDeferred();
            } catch (HttpError $e) {
                $caught = [$e->status, $e->errorCode, $e->getMessage()];
            }
            if ($upgraded) {
                checkEq('after the upgrade: Finish refused (409 not_undoable, the words), no request to Google, the pending row exactly as it was, the tags as the stop left them, Google\'s title as the stop left it', [
                    [409, 'not_undoable', $finishUndo], [], $before, $tagsAtStop, 'Sample weekly',
                ], [$caught, $s->t->calls, $pendingRow($s), $tagsOf($masterId($s)), $s->fake->events['smpl3weekly']['summary']]);
                $s->events->googlePutBack($s->u, $f['pending']);
                $s->events->throwDeferred();
                checkEq('after the upgrade: Put back of the same pending undo goes through: the change stands at Google and here, nothing pending, the entry not undone', [
                    'Sample renamed', ['Sample new tag'], null, 0,
                ], [$s->fake->events['smpl3weekly']['summary'], $tagsOf($masterId($s)), $pendingRow($s), (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [$f['entry']])]);
            } else {
                checkEq('control, current format: Finish completes the undo: the original title at Google and the original tag here, nothing pending', [
                    null, true, 'Sample weekly', ['sample-tag'], null,
                ], [$caught, $out['ok'] ?? null, $s->fake->events['smpl3weekly']['summary'], $tagsOf($masterId($s)), $pendingRow($s)]);
            }
        }
    });

    // ---- what Review offers, and the case left open by decision -------------------

    scenario('journal gate: Review lists a pending undo of an older entry with Put back alone, saying why Finish is not offered', function () use ($db, $setup, $pendingUndo, $finishUndo): void {
        $s = $setup();
        $f = $pendingUndo($s);
        $db->run('UPDATE mutations SET format = 1 WHERE user_id = ?', [$s->u]);
        $controller = new \BetterCal\Http\Controllers\ReviewController(new \BetterCal\Domain\ReviewQueue($db, $s->events),
            new Proposals($db, $s->events, new BetterCal\Domain\Trips($db, $s->undo)), $s->calendars);
        $req = new \BetterCal\Http\Request('GET', '/review');
        $req->user = ['id' => $s->u];
        $req->authMethod = 'session';
        $item = null;
        foreach (json_decode($controller->index($req)->body, true)['items'] as $i) {
            if ($i['key'] === 'google_half:' . $f['pending']) {
                $item = $i;
            }
        }
        checkEq('Put back alone, the summary ending with why Finish can\'t complete it', [['Put back'], true],
            [array_column($item['actions'] ?? [], 'label'), str_ends_with((string) ($item['summary'] ?? ''), ' ' . $finishUndo)]);
        $db->run('UPDATE mutations SET format = 2 WHERE user_id = ?', [$s->u]);
        $item = null;
        foreach (json_decode($controller->index($req)->body, true)['items'] as $i) {
            if ($i['key'] === 'google_half:' . $f['pending']) {
                $item = $i;
            }
        }
        checkEq('control: a pending undo of a current entry offers Finish and Put back', ['Finish', 'Put back'], array_column($item['actions'] ?? [], 'label'));
    });

    scenario('journal gate: the open case: Finish refused by the format and Put back refused because Google changed since leave the series frozen, and every word said is true', function () use ($db, $setup, $pendingUndo, $masterId, $pendingRow, $finishUndo, $iso): void {
        $s = $setup();
        $f = $pendingUndo($s);
        $db->run('UPDATE mutations SET format = 1 WHERE user_id = ?', [$s->u]);
        // Another client renames the series at Google after the undo stopped.
        $s->fake->writerTransport()('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/crew%40group.calendar.google.com/events/smpl3weekly', ['summary' => 'Sample remote title'], ['Authorization: Bearer sample-access']);
        $caught = static function (callable $fn) use ($s): ?array {
            try {
                $fn();
                $s->events->throwDeferred();
                return null;
            } catch (HttpError $e) {
                return [$e->status, $e->errorCode, $e->getMessage()];
            }
        };
        $putBack = $caught(fn() => $s->events->googlePutBack($s->u, $f['pending']));
        checkEq('Put back refuses: Google changed since; its words never point to Finish', [409, 'google_changed_since',
            "'Sample renamed' changed at Google since. Nothing was put back, and Finish can't complete this undo: the change it undoes was recorded by an earlier version of Better-Cal. The series takes no other change here while this is listed in Review."],
            $putBack);
        $row = $pendingRow($s);
        $s->t->calls = [];
        checkEq('Finish refuses with its words, no request to Google; the pending row stays half done with Put back\'s words', [
            [409, 'not_undoable', $finishUndo], [], 'half', $putBack[2] ?? null,
        ], [$caught(fn() => $s->events->googleFinish($s->u, $f['pending'])), $s->t->calls, (string) $pendingRow($s)['status'], $pendingRow($s)['error']]);
        checkEq('the pending row is exactly as Put back\'s refusal left it', $row, $pendingRow($s));
        checkEq('the series takes no other change here while it is listed', 'google_pending',
            refusal(fn() => $s->events->patch($s->u, $masterId($s), ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00'), 'title' => 'Sample day title'])));
        $half = GoogleSeries::halfDoneOf($db, $s->u);
        checkEq('Review still lists it, saying why Finish is not offered', [1, $finishUndo], [count($half), $half[0]['finishRefused'] ?? null]);
    });

    // ---- every take-back operation is in the table --------------------------------

    // Every public method, in every class under server/src, that takes a pending row's id, an entry or an entry's id,
    // and in the classes that undo (Undo, GoogleUndo, Events, Proposals) every public one named undo*, *finish* or
    // *putBack* or taking a run: each is a row of the table (by the row's name) or named here with why it is not one.
    $scanned = static function (array $classes): array {
        $found = [];
        foreach ($classes as $class) {
            $r = new ReflectionClass($class);
            $short = $r->getShortName();
            $undoer = in_array($class, [Undo::class, GoogleUndo::class, Events::class, Proposals::class], true);
            foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $name = strtolower($m->getName());
                $takes = $undoer && (str_starts_with($name, 'undo') || str_contains($name, 'finish') || str_contains($name, 'putback'));
                foreach ($m->getParameters() as $p) {
                    if (in_array($p->getName(), ['pendingId', 'entryId', 'mutationId', 'mutation', 'entry'], true) || ($undoer && $p->getName() === 'runId')) {
                        $takes = true;
                    }
                }
                if ($takes) {
                    $found[] = "$short::" . $m->getName();
                }
            }
        }
        sort($found);
        return array_values(array_unique($found));
    };
    $srcClasses = static function (): array {
        $out = [];
        $root = dirname(__DIR__, 2) . '/src';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents((string) $file);
            if (preg_match('/^namespace\s+([^;]+);/m', $src, $ns) && preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $src, $c)
                && class_exists($ns[1] . '\\' . $c[1])) {
                $out[] = $ns[1] . '\\' . $c[1];
            }
        }
        return $out;
    };
    // Not an operation of their own, each with the reason.
    $notOperations = [
        'Undo::entryToTakeBack' => 'the loader the table runs through',
        'Undo::assertCurrentFormat' => 'the backstop, not an operation',
        'Undo::olderFormat' => 'pure',
        'Undo::restoreSnapshots' => 'reached only from GoogleUndo::undo and SeriesPlans::googleFinish, after the loader; its own backstop',
        'Undo::checkRestore' => 'reached only from GoogleUndo::undo, after the loader',
        'Undo::snapshotEventRows' => 'reads ids for naming, writes nothing',
        'Undo::snapshotEventIds' => 'reads ids for naming, writes nothing',
        'Undo::dayNow' => 'a reader for a restore already admitted',
        'Undo::recordRemoved' => 'records, takes nothing back',
        'Undo::recordRemovedEvents' => 'records, takes nothing back',
        'Undo::noteSettings' => 'records, takes nothing back',
        'GoogleUndo::finishRefused' => 'words only',
        'GoogleUndo::finishUndo' => 'reached only from GoogleUndo::undo and SeriesPlans::googleFinish, after the loader; its own backstop',
        'GoogleUndo::entryToFinish' => 'the loader for Finish, run by the googleFinish and GoogleSeries::finish rows',
        'GoogleUndo::refuseWhilePending' => 'a refusal, after the loader',
        'GoogleUndo::refuseLeftSeriesOf' => 'a refusal, after the loader',
        'GoogleUndo::seriesUndoOff' => 'a refusal, after the loader',
        'GoogleUndo::undoSummary' => 'words only',
        'GoogleSeries::putBack' => 'takes no entry back (reads only the pending row; asks entryToFinish for its own words); run through SeriesPlans::googlePutBack',
        'GoogleSeries::pendingOf' => 'reads a pending row, writes nothing',
        'GoogleSeries::settle' => 'closes a pending row a run completed, after the run',
        'GoogleSeries::stepsDone' => 'records a run\'s steps, takes nothing back',
        'GoogleSeries::store' => 'stores what Google read back, takes nothing back',
        'Proposals::undoRefusal' => 'whether to offer the undo, writes nothing',
        'Reminders::fireAt' => 'a reminder entry, not a journal entry',
    ];
    // The operations, each by the name of its row in the table.
    $operations = [
        'Undo::undoById' => 'Undo::undoById', 'Undo::undoLatest' => 'Undo::undoLatest', 'Undo::undoRun' => 'Undo::undoRun', 'Proposals::undoAccept' => 'Proposals::undoAccept',
        'GoogleUndo::undo' => 'GoogleUndo::undo', 'Events::googleFinish' => 'SeriesPlans::googleFinish', 'Events::googlePutBack' => 'SeriesPlans::googlePutBack',
        'GoogleSeries::finish' => 'GoogleSeries::finish (direct)',
    ];
    $missingFrom = static function (array $found) use ($table, $notOperations, $operations): array {
        $rows = array_map(static fn(array $r): string => $r[0], $table);
        return array_values(array_filter($found, static fn(string $m): bool => !isset($notOperations[$m])
            && (!isset($operations[$m]) || array_filter($rows, static fn(string $r): bool => str_starts_with($r, $operations[$m])) === [])));
    };

    scenario('journal gate: every public method in server/src that takes an entry or a pending row back is a row of the table, or named here with why it is not one', function () use ($scanned, $srcClasses, $missingFrom, $operations, $notOperations): void {
        $found = $scanned($srcClasses());
        checkEq('no take-back method is missing from the table', [], $missingFrom($found));
        checkEq('every method the table and the list name still exists', [], array_values(array_diff(array_keys($operations + $notOperations), $found)));
        // The check itself: a new public method taking a pending id, in a class it has never seen, is reported.
        checkEq('a new such method fails the check', ['JournalGateSample::completeUndo'], $missingFrom($scanned([JournalGateSample::class])));
    });
    // ---- a calendar with a change half done at Google (G1 of the sibling hunt) ----------
    // The rule ("A write to a series whose change is half done is refused") for the calendar: an operation the person
    // makes here that would leave Finish and Put back nothing to work on (the calendar kept as a calendar of its own,
    // or deleted) is refused while the change is half done; one that is not theirs to refuse (an account disconnected,
    // the compromise reset) leaves Review offering only what can work, and saying what brings Finish and Put back back.

    $halfChange = static function (object $s) use ($db, $masterId, $iso): int {
        $s->t->writes = 0;
        $s->t->staleAt = 2;
        refusal(fn() => planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'title' => 'Sample renamed']));
        refusal(fn() => $s->events->throwDeferred());
        $s->t->staleAt = null;
        $id = $db->scalar('SELECT id FROM google_pending WHERE user_id = ?', [$s->u]);
        if ($id === null) {
            throw new RuntimeException('the change left no pending row');
        }
        return (int) $id;
    };
    $reviewItem = static function (object $s, string $key) use ($db): ?array {
        $controller = new \BetterCal\Http\Controllers\ReviewController(new \BetterCal\Domain\ReviewQueue($db, $s->events),
            new Proposals($db, $s->events, new BetterCal\Domain\Trips($db, $s->undo)), $s->calendars);
        $req = new \BetterCal\Http\Request('GET', '/review', ['status' => 'all']);
        $req->user = ['id' => $s->u];
        $req->authMethod = 'session';
        foreach (json_decode($controller->index($req)->body, true)['items'] as $i) {
            if ($i['key'] === $key) {
                return $i;
            }
        }
        return null;
    };
    $caught = static function (callable $fn): ?array {
        try {
            $fn();
            return null;
        } catch (HttpError $e) {
            return [$e->status, $e->errorCode, $e->getMessage()];
        }
    };
    $pendingWords = static fn(string $title): string => "A change to '$title' is not yet complete at Google. Finish it or put it back in Review, then try this again.";

    $disconnected = 'The Google account this calendar uses is disconnected. Connect it again in Settings, Connections and add this calendar there to re-attach it; then Finish and Put back are offered again.';
    $paused = 'Google access for this calendar is paused. Reconnect the Google account in Settings, Connections; Finish and Put back are offered again after its next update.';
    $keptAsCopy = 'This calendar no longer writes to Google from here, so Finish and Put back can no longer run for this change.';
    $closedLine = static fn(string $title, string $why): string => "A change to '$title' that was half done at Google was closed without finishing: $why";
    // The closing, as Activity and the tables show it: the pending row gone, nothing sent, the newest entry the line, log only.
    $closed = static function (object $s) use ($db): array {
        $last = $db->one('SELECT summary, before_json, after_json FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$s->u]);
        return [(int) $db->scalar('SELECT COUNT(*) FROM google_pending WHERE user_id = ?', [$s->u]), $s->t->calls, (string) $last['summary'], $last['before_json'], $last['after_json']];
    };

    scenario('journal gate: keeping a calendar that still writes to Google as a copy is refused while a change on it is half done; after Put back it goes through', function () use ($db, $setup, $halfChange, $digest, $changed, $caught, $pendingWords, $pendingRow): void {
        $s = $setup();
        $p = $halfChange($s);
        $title = (string) $pendingRow($s)['title'];
        $s->t->calls = [];
        $was = $digest($s);
        $adopt = fn() => $s->calendars->adopt($s->u, $s->calendarId);
        checkEq('refused 409 google_pending, pointing to Review; nothing sent, nothing written', [[409, 'google_pending', $pendingWords($title)], [], []],
            [$caught($adopt), $s->t->calls, $changed($was, $digest($s))]);
        $s->events->googlePutBack($s->u, $p);
        $s->events->throwDeferred();
        checkEq('once put back, it goes through', null, $caught($adopt));
    });

    scenario('journal gate: deleting a calendar is never refused; a change half done on it is closed with it, nothing sent, one line in Activity; the delete of a Google calendar can\'t be undone, so the row never comes back', function () use ($db, $setup, $halfChange, $caught, $pendingRow, $closed, $closedLine): void {
        $s = $setup();
        $halfChange($s);
        $title = (string) $pendingRow($s)['title'];
        $s->t->calls = [];
        checkEq('the delete goes through', null, $caught(fn() => $s->calendars->delete($s->u, $s->calendarId)));
        checkEq('closed: no pending row, nothing sent, the line, log only', [0, [], $closedLine($title, 'its calendar was deleted here.'), null, null], $closed($s));
        $delete = (int) $db->scalar("SELECT MAX(id) FROM mutations WHERE user_id = ? AND entity = 'calendar' AND op = 'delete'", [$s->u]);
        checkEq('the delete\'s undo is refused (a Google calendar\'s delete keeps nothing it may restore); the calendar and the row stay gone', ['not_undoable', 0, 0],
            [$caught(fn() => $s->undo->undoById($s->u, $delete))[1] ?? null, (int) $db->scalar('SELECT COUNT(*) FROM calendars WHERE id = ?', [$s->calendarId]), (int) $db->scalar('SELECT COUNT(*) FROM google_pending WHERE user_id = ?', [$s->u])]);
    });

    scenario('journal gate: write access taken away at Google: Finish and Put back are refused there and adopt here (the calendar still looks writable); deleting the calendar ends it', function () use ($db, $setup, $halfChange, $caught, $pendingRow, $closed, $closedLine, $pendingWords): void {
        $s = $setup();
        $p = $halfChange($s);
        $title = (string) $pendingRow($s)['title'];
        $s->t->forbidden = true;
        $finish = $caught(fn() => [$s->events->googleFinish($s->u, $p), $s->events->throwDeferred()]);
        $putBack = $caught(fn() => [$s->events->googlePutBack($s->u, $p), $s->events->throwDeferred()]);
        checkEq('Finish and Put back refused by Google; the row stays half done; adopt refused with the pending words', [true, true, 'half', [409, 'google_pending', $pendingWords($title)]],
            [$finish !== null, $putBack !== null, (string) $db->scalar('SELECT status FROM google_pending WHERE id = ?', [$p]), $caught(fn() => $s->calendars->adopt($s->u, $s->calendarId))]);
        $s->t->calls = [];
        $s->calendars->delete($s->u, $s->calendarId);
        checkEq('deleted: closed, nothing sent, the line', [0, [], $closedLine($title, 'its calendar was deleted here.'), null, null], $closed($s));
    });

    scenario('journal gate: a calendar that no longer writes to Google for this account (disconnected, paused, a role that can\'t write) is listed with no action and the reason, and keeping it as a copy goes through and closes the change', function () use ($db, $setup, $halfChange, $reviewItem, $caught, $pendingRow, $closed, $closedLine, $disconnected, $paused): void {
        foreach (['disconnected' => $disconnected, 'paused' => $paused, 'reader' => 'This account can no longer edit this calendar at Google, so Finish and Put back can no longer run for this change.'] as $how => $words) {
            $s = $setup();
            $p = $halfChange($s);
            $title = (string) $pendingRow($s)['title'];
            if ($how === 'disconnected') {
                // What disconnecting does here (GoogleAuth::disconnect): the account row goes, the calendar's link to it with it.
                $db->run('DELETE FROM google_accounts WHERE id = ?', [$s->account]);
            } else {
                // The reset clears the role until a poll after reconnecting; a role that can't write is stored as such.
                $db->run('UPDATE calendars SET google_access_role = ? WHERE id = ?', [$how === 'paused' ? null : 'reader', $s->calendarId]);
            }
            $item = $reviewItem($s, 'google_half:' . $p);
            $s->t->calls = [];
            checkEq("$how: listed with no action and the reason; Finish and Put back refuse with it, nothing sent", [[], true, [409, 'google_calendar_gone', $words], [409, 'google_calendar_gone', $words], []],
                [$item['actions'] ?? null, str_ends_with((string) ($item['summary'] ?? ''), ' ' . $words),
                    $caught(fn() => $s->events->googleFinish($s->u, $p)), $caught(fn() => $s->events->googlePutBack($s->u, $p)), $s->t->calls]);
            checkEq("$how: kept as a copy, it goes through and the change is closed with the line", [null, 0, [], $closedLine($title, 'this calendar is kept as a copy here now.'), null, null],
                array_merge([$caught(fn() => $s->calendars->adopt($s->u, $s->calendarId))], $closed($s)));
        }
    });

    scenario('journal gate: disconnected, then reconnected and the calendar added again: Finish and Put back are offered again; the re-attach is not undoable', function () use ($db, $setup, $halfChange, $reviewItem, $caught): void {
        $s = $setup();
        $p = $halfChange($s);
        $db->run('DELETE FROM google_accounts WHERE id = ?', [$s->account]);
        $account = $db->insert('google_accounts', ['user_id' => $s->u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', 'sample-session-secret'), 'scopes' => GoogleAuth::SCOPES]);
        $s->undo->write($s->u, fn() => $s->calendars->reattachGoogle($s->u, $s->calendarId, $account, 'owner', 'owner@example.test'));
        $reattach = lastEntry($db, $s->u);
        checkEq('re-attached: Finish and Put back offered again', ['Finish', 'Put back'], array_column($reviewItem($s, 'google_half:' . $p)['actions'] ?? [], 'label'));
        checkEq('undoing the re-attach is refused (its entry keeps nothing to restore), and the calendar still writes to Google', [[400, 'not_undoable', 'This entry is log-only and cannot be undone (automated or older than the 7-day undo window)'], $account],
            [$caught(fn() => $s->undo->undoById($s->u, $reattach)), (int) $db->scalar('SELECT google_account_id FROM calendars WHERE id = ?', [$s->calendarId])]);
    });

    scenario('journal gate: rows an earlier version left that can never run (a calendar kept as a copy, or deleted, with a change half done) are listed with the reason until the daily retention pass closes them, each with its line', function () use ($db, $setup, $halfChange, $reviewItem, $pendingRow, $closed, $closedLine, $keptAsCopy): void {
        $s = $setup();
        $p = $halfChange($s);
        $title = (string) $pendingRow($s)['title'];
        // What adopt wrote before it closed the row: the calendar a copy here, the pending row left.
        $db->run("UPDATE calendars SET kind = 'local', provider = 'ics', google_calendar_id = NULL, google_access_role = NULL, google_account_id = NULL WHERE id = ?", [$s->calendarId]);
        $item = $reviewItem($s, 'google_half:' . $p);
        checkEq('kept as a copy: listed with no action and the reason', [[], true], [$item['actions'] ?? null, str_ends_with((string) ($item['summary'] ?? ''), ' ' . $keptAsCopy)]);
        $t = $setup();
        $halfChange($t);
        $titleT = (string) $pendingRow($t)['title'];
        // What delete did before it closed the row: the calendar gone, the pending row left (hidden from Review).
        $db->run('DELETE FROM calendars WHERE id = ?', [$t->calendarId]);
        $s->t->calls = [];
        $t->t->calls = [];
        $out = (new BetterCal\Domain\Activity($db))->prune();
        checkEq('the pass closes both, nothing sent, each with its line', [true, [0, [], $closedLine($title, 'this calendar no longer writes to Google from here.'), null, null],
            [0, [], $closedLine($titleT, 'its calendar was deleted here.'), null, null]], [($out['halfDoneClosed'] ?? 0) >= 2, $closed($s), $closed($t)]);
    });

    // ---- what Review offers on an accepted proposal (G2) ---------------------------------

    scenario('journal gate: Review offers the undo of an accepted proposal only when every entry of its run can be undone, by the test Activity lists by; otherwise it says why', function () use ($db, $setup, $run, $reviewItem, $older): void {
        $s = $setup();
        $f = $run($s);
        $activity = new BetterCal\Domain\Activity($db);
        $listed = array_filter($activity->list($s->u, [])['entries'], static fn(array $e): bool => in_array($e['id'], array_map('intval', array_column($db->all('SELECT id FROM mutations WHERE run_id = ?', [$f['run']]), 'id')), true));
        checkEq('current: the undo offered; the run counts its entries as Activity lists them', [['Undo, remove these again'], ['entries' => 2, 'undoable' => 2, 'why' => null], 2],
            [array_column($reviewItem($s, 'proposal:' . $f['proposal'])['actions'] ?? [], 'label'), $activity->runUndoability($s->u, $f['run']), count(array_filter($listed, static fn(array $e): bool => $e['undoable']))]);
        $db->run('UPDATE mutations SET format = 1 WHERE user_id = ?', [$s->u]);
        $item = $reviewItem($s, 'proposal:' . $f['proposal']);
        checkEq('accepted before the upgrade: no undo offered, the summary saying why in Activity\'s words; the run counts none', [[], true, ['entries' => 2, 'undoable' => 0, 'why' => $older]],
            [$item['actions'] ?? null, str_ends_with((string) ($item['summary'] ?? ''), 'Its events can no longer be removed by undo here. ' . $older), $activity->runUndoability($s->u, $f['run'])]);
    });
};
