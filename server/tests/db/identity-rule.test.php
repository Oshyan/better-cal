<?php

declare(strict_types=1);

// Provider identity's own negative controls (lib.php, identityViolations and
// dataRulesCheck): damage made by raw SQL, never by the app, must be
// reported, kind by kind, and nothing else. A day row's stored Google id set
// to the other form's id (I1, both directions), an all-day row given a zone
// (I2), and a clock in a day row's key or in a skip under an all-day series
// (I2's key companion). Then a block that leaves such damage behind must
// fail its own checks, naming the rows, and the damage is removed so the
// file ends clean. Last, the block the mutation all-day-id-in-timed-form
// runs: a tag given to one ordinary day of an all-day Google series makes
// the day's row under its Google id. Against the probe's stand-in Google
// (tools/google-probe), invented data.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Trips;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

require_once dirname(__DIR__, 3) . '/tools/google-probe/lib/FakeGoogle.php';

return static function (Db $db): void {
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $utc = new DateTimeZone('UTC');
    $expand = static function (array $master) use ($utc): array {
        $allDay = isset($master['start']['date']);
        $start = $allDay ? new DateTimeImmutable($master['start']['date'] . ' 00:00:00', $utc) : new DateTimeImmutable((string) $master['start']['dateTime']);
        $end = $allDay ? new DateTimeImmutable($master['end']['date'] . ' 00:00:00', $utc) : new DateTimeImmutable((string) $master['end']['dateTime']);
        $rrule = null;
        foreach ((array) ($master['recurrence'] ?? []) as $line) {
            if (str_starts_with((string) $line, 'RRULE:')) {
                $rrule = substr((string) $line, 6);
            }
        }
        if ($rrule === null) {
            return [];
        }
        $row = ['start_utc' => Time::toDb($start), 'end_utc' => Time::toDb($end), 'all_day' => $allDay ? 1 : 0, 'tzid' => 'UTC', 'rrule' => $rrule];
        return array_map(static fn(array $o): DateTimeImmutable => $o['start']->setTimezone($utc), Recurrence::occurrences($row, $start, $start->modify('+2 years'), null, 730));
    };

    // Invented: two weekly series of six Mondays from 4 January 2027 on one Google calendar, one all-day and one timed
    // at 00:00 UTC, so a key of either (11 Jan at 00:00:00) is a key of the other and only the form tells their ids apart.
    $setup = static function () use ($db, $cfg, $expand): object {
        $u = freshUser($db);
        $d = domains($db);
        $fake = new FakeGoogle('identity@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'UTC';
        $fake->expand = $expand;
        $fake->seed(['id' => 'smpl9allday', 'iCalUID' => 'smpl9allday@google.com', 'summary' => 'Sample all-day weekly',
            'start' => ['date' => '2027-01-04'], 'end' => ['date' => '2027-01-05'], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']]);
        $fake->seed(['id' => 'smpl9timed', 'iCalUID' => 'smpl9timed@google.com', 'summary' => 'Sample timed weekly',
            'start' => ['dateTime' => '2027-01-04T00:00:00Z', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2027-01-04T01:00:00Z', 'timeZone' => 'UTC'], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']]);
        $inner = $fake->writerTransport();
        $transport = static fn(string $method, string $url, ?array $payload, array $headers = []): array => $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
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
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample identity', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'identity@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $master = static fn(string $gid): int => (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$calendarId, $gid]);
        return (object) ['u' => $u, 'events' => $events, 'calendarId' => $calendarId, 'allDay' => $master('smpl9allday'), 'timed' => $master('smpl9timed')];
    };
    // A day row made beside a series by raw SQL: the master's row with a parent, a key, a Google id and an empty own list.
    $rawDay = static function (int $master, string $key, string $googleId) use ($db): int {
        $row = $db->one('SELECT * FROM events WHERE id = ?', [$master]);
        $length = strtotime((string) $row['end_utc']) - strtotime((string) $row['start_utc']);
        unset($row['id']);
        $row = ['recurrence_parent_id' => $master, 'recurrence_instance_utc' => $key, 'rrule' => null, 'exdates_json' => null, 'override_fields' => '[]',
            'google_event_id' => $googleId, 'google_etag' => null, 'start_utc' => $key, 'end_utc' => gmdate('Y-m-d H:i:s', strtotime($key . ' UTC') + $length)] + $row;
        return (int) $db->insert('events', $row);
    };

    scenario('identity rule controls: each kind of damage made by raw SQL is reported, and nothing else', function () use ($db, $setup, $rawDay): void {
        $s = $setup();
        $found = static fn(): array => array_keys(identityViolations($db, $s->u));
        checkEq('two Google series as a poll stores them break nothing', [], $found());

        $a = $rawDay($s->allDay, '2027-01-11 00:00:00', 'smpl9allday_20270111');
        $t = $rawDay($s->timed, '2027-01-11 00:00:00', 'smpl9timed_20270111T000000Z');
        checkEq('a day row of each series under its own form\'s id: nothing reported', [], $found());

        $db->run('UPDATE events SET google_event_id = ? WHERE id = ?', ['smpl9allday_20270111T000000Z', $a]);
        checkEq('a day row of the all-day series holding the timed form\'s id: reported as its id', ["id:$a"], $found());
        $db->run('UPDATE events SET google_event_id = ? WHERE id = ?', ['smpl9allday_20270111', $a]);
        $db->run('UPDATE events SET google_event_id = ? WHERE id = ?', ['smpl9timed_20270111', $t]);
        checkEq('a day row of the timed series holding the all-day form\'s id: reported as its id', ["id:$t"], $found());
        $db->run('UPDATE events SET google_event_id = ? WHERE id = ?', ['smpl9timed_20270118T000000Z', $t]);
        checkEq('a day row holding the id of another day of its own form: reported as its id', ["id:$t"], $found());
        $db->run('UPDATE events SET google_event_id = ? WHERE id = ?', ['smpl9timed_20270111T000000Z', $t]);

        $db->run('UPDATE events SET google_event_id = NULL WHERE id = ?', [$a]);
        checkEq('a day row with no Google id has nothing to compare: not reported', [], $found());
        $db->run('UPDATE events SET google_event_id = ?, deleted_at = NOW() WHERE id = ?', ['smpl9allday_20270111T000000Z', $a]);
        checkEq('a deleted day row holding the other form\'s id is no live day row: not reported', [], $found());
        $db->run('UPDATE events SET google_event_id = ?, deleted_at = NULL WHERE id = ?', ['smpl9allday_20270111', $a]);

        $db->run('UPDATE events SET tzid = ? WHERE id = ?', ['America/New_York', $s->allDay]);
        checkEq('the all-day series stored in a zone: reported as a zone, and nothing else', ["zone:{$s->allDay}"], $found());
        $db->run('UPDATE events SET tzid = ? WHERE id = ?', ['UTC', $s->allDay]);
        $db->run('UPDATE events SET tzid = ? WHERE id = ?', ['Asia/Tokyo', $a]);
        checkEq('an all-day day row stored in a zone: reported as a zone', ["zone:$a"], $found());
        $db->run('UPDATE events SET tzid = ? WHERE id = ?', ['UTC', $a]);
        $db->run('UPDATE events SET tzid = ? WHERE id = ?', ['America/New_York', $s->timed]);
        checkEq('a timed series in a zone is no all-day row: not reported', [], $found());
        $db->run('UPDATE events SET tzid = ? WHERE id = ?', ['UTC', $s->timed]);

        $db->run('UPDATE events SET recurrence_instance_utc = ? WHERE id = ?', ['2027-01-11 05:00:00', $a]);
        checkEq('a clock in the key of a day row of the all-day series: reported as a clock, its id still its date\'s', ["clock:$a"], $found());
        $db->run('UPDATE events SET recurrence_instance_utc = ? WHERE id = ?', ['2027-01-11 00:00:00', $a]);
        $db->run('UPDATE events SET exdates_json = ? WHERE id = ?', [json_encode(['2027-01-18 00:00:00', '2027-01-25 05:00:00']), $s->allDay]);
        checkEq('a skip with a clock beside a midnight skip of the all-day series: the clock alone reported', ["clock:skip:{$s->allDay}:2027-01-25 05:00:00"], $found());
        $db->run('UPDATE events SET exdates_json = NULL WHERE id = ?', [$s->allDay]);
        $db->run('UPDATE events SET exdates_json = ? WHERE id = ?', [json_encode(['2027-01-25 05:00:00']), $s->timed]);
        checkEq('a skip with a clock of a timed series is an instant like any other: not reported', [], $found());
        $db->run('UPDATE events SET exdates_json = NULL WHERE id = ?', [$s->timed]);

        // Leave nothing behind for the block's own check.
        $db->run('DELETE FROM events WHERE id IN (?, ?)', [$a, $t]);
        checkEq('after the damage is removed nothing is reported', [], $found());
    });

    // The hook itself: a block that leaves a day row on the other form's id, an all-day row in a zone and a clock in a
    // key under an all-day series fails its three identity checks under its own name, naming the rows; its link checks
    // pass. Its checks are taken back out of the record, so only this file's verdict on them counts.
    $s = $setup();
    dataRulesCheck('after the fixture of the hook\'s sample block', null, null);
    $before = count($GLOBALS['__checks']);
    [$pass, $fail] = [$GLOBALS['__pass'], $GLOBALS['__fail']];
    $made = ['id' => 0, 'clock' => 0];
    ob_start();
    scenario('a sample block that leaves a day on the other form\'s id, an all-day row in a zone and a clock in a key', function () use ($s, $rawDay, $db, &$made): void {
        $made['id'] = $rawDay($s->timed, '2027-01-11 00:00:00', 'smpl9timed_20270111');
        $made['clock'] = $rawDay($s->allDay, '2027-01-18 07:00:00', 'smpl9allday_20270118');
        $db->run('UPDATE events SET tzid = ? WHERE id = ?', ['America/New_York', $s->allDay]);
    });
    $printed = (string) ob_get_clean();
    $checks = array_splice($GLOBALS['__checks'], $before);
    [$GLOBALS['__pass'], $GLOBALS['__fail']] = [$pass, $fail];
    $prefix = "a sample block that leaves a day on the other form's id, an all-day row in a zone and a clock in a key: ";
    $named = static function (string $label) use ($checks, $prefix): ?array {
        $mine = array_values(array_filter($checks, static fn(array $c): bool => $c['name'] === $prefix . $label));
        return count($mine) === 1 ? $mine[0] : null;
    };
    foreach ([[IDENTITY_CHECK, "day row {$made['id']} "], [ZONE_CHECK, "all-day row {$s->allDay} "], [CLOCK_CHECK, "day row {$made['clock']} "]] as [$label, $row]) {
        $c = $named($label);
        check("a block that leaves the damage fails \"$label\" under its own name, naming the row",
            $c !== null && !$c['pass'] && str_contains($c['detail'], $row) && str_contains($printed, 'FAIL: ' . $prefix . $label), json_encode($checks));
    }
    $links = array_filter([LINKS_CHECK, LINKS_LIST_CHECK, LINKS_SERIES_CHECK], static fn(string $l): bool => ($named($l)['pass'] ?? false) !== true);
    check('and its link checks pass', $links === [], json_encode($checks));
    $db->run('DELETE FROM events WHERE id IN (?, ?)', [$made['id'], $made['clock']]);
    $db->run('UPDATE events SET tzid = ? WHERE id = ?', ['UTC', $s->allDay]);
    dataRulesCheck('after the damage is removed', null, null);

    // The block the mutation all-day-id-in-timed-form (tools/edit-harness/mutations/list.php) runs: a tag given to one
    // ordinary day of an all-day Google series sends Google nothing and makes the day's row under the occurrence's
    // Google id, which must be the all-day form's.
    scenario('a tag given to one ordinary day of an all-day Google series', function () use ($db, $setup): void {
        $s = $setup();
        $s->events->patch($s->u, $s->allDay, ['scope' => 'this', 'instanceStart' => '2027-01-11T00:00:00+00:00', 'tagNames' => ['sample-day']]);
        checkEq('the tag made the day\'s row', 1, (int) $db->scalar("SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-01-11 00:00:00' AND deleted_at IS NULL", [$s->allDay]));
    });
};
