<?php

declare(strict_types=1);

// A Google poll whose delta lists nothing: no row of the calendar is read
// into PHP and nothing is synced, while the new sync token, the poll's
// success (status, stats, health) and the binding check under the lock are
// kept. A delta with one change still goes through the whole sync. Google
// is a stand-in replaying invented answers; the statements the poll sends
// are recorded through PDO's statement class.

use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Infra\Db;

if (!class_exists('GooglePollStatementLog')) {
    /** Records every statement the connection executes while $on. */
    class GooglePollStatementLog extends \PDOStatement
    {
        public static bool $on = false;
        /** @var list<string> */
        public static array $sql = [];

        protected function __construct()
        {
        }

        public function execute(?array $params = null): bool
        {
            if (self::$on) {
                self::$sql[] = $this->queryString;
            }
            return parent::execute($params);
        }
    }
}

return static function (Db $db): void {
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $uid = 'pollquiet1a2b@google.com';
    $tz = 'America/Los_Angeles';

    // Invented resources: a weekly series with one moved day, and a single event.
    $master = static fn(string $etag, string $title = 'Sample sync-up'): array => [
        'kind' => 'calendar#event', 'id' => 'pollquiet1a2b', 'iCalUID' => $uid, 'etag' => $etag, 'status' => 'confirmed', 'summary' => $title,
        'start' => ['dateTime' => '2027-04-05T09:00:00-07:00', 'timeZone' => $tz], 'end' => ['dateTime' => '2027-04-05T09:30:00-07:00', 'timeZone' => $tz],
        'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=5'],
    ];
    $moved = static fn(string $etag): array => [
        'kind' => 'calendar#event', 'id' => 'pollquiet1a2b_20270412T160000Z', 'iCalUID' => $uid, 'etag' => $etag, 'status' => 'confirmed', 'summary' => 'Sample sync-up (moved)',
        'recurringEventId' => 'pollquiet1a2b', 'originalStartTime' => ['dateTime' => '2027-04-12T09:00:00-07:00', 'timeZone' => $tz],
        'start' => ['dateTime' => '2027-04-13T09:00:00-07:00', 'timeZone' => $tz], 'end' => ['dateTime' => '2027-04-13T09:30:00-07:00', 'timeZone' => $tz],
    ];
    $single = static fn(string $etag): array => [
        'kind' => 'calendar#event', 'id' => 'pollquiet9z8y', 'iCalUID' => 'pollquiet9z8y@google.com', 'etag' => $etag, 'status' => 'confirmed', 'summary' => 'Sample planning',
        'start' => ['dateTime' => '2027-04-07T13:00:00-07:00', 'timeZone' => $tz], 'end' => ['dateTime' => '2027-04-07T14:00:00-07:00', 'timeZone' => $tz],
    ];
    $page = static fn(array $items, string $token): array => ['status' => 200, 'body' => json_encode(['kind' => 'calendar#events', 'items' => $items, 'nextSyncToken' => $token])];

    // One account and one Google calendar, polled once in full.
    $setup = static function () use ($db, $cfg, $page, $master, $moved, $single): object {
        $u = freshUser($db);
        $google = new class {
            /** @var ?\Closure fn(array $query): array */
            public ?\Closure $pages = null;
        };
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $sync = new GoogleSync($db, $auth, new Feeds($db), static function (string $url, array $headers) use ($google): array {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            return ($google->pages)($query);
        });
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample crew', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'crew@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $t = (object) ['u' => $u, 'google' => $google, 'sync' => $sync, 'calendarId' => $calendarId];
        $google->pages = static fn(array $q): array => $page([$master('"q1"'), $moved('"q1"'), $single('"q1"')], 'sync-1');
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        return $t;
    };
    // A poll with the statements it sends recorded.
    $recorded = static function (object $t) use ($db): array {
        $calendar = $db->one('SELECT * FROM calendars WHERE id = ?', [$t->calendarId]);
        GooglePollStatementLog::$sql = [];
        $db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [GooglePollStatementLog::class, []]);
        GooglePollStatementLog::$on = true;
        try {
            $count = $t->sync->poll($calendar);
        } finally {
            GooglePollStatementLog::$on = false;
            $db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
        $norm = static fn(string $s): string => (string) preg_replace('/\s+/', ' ', trim($s));
        $sql = array_map($norm, GooglePollStatementLog::$sql);
        return [
            'count' => $count,
            'rowLoads' => count(array_filter($sql, static fn(string $s): bool => str_starts_with($s, 'SELECT * FROM events WHERE calendar_id = ?'))),
            'sizeChecks' => count(array_filter($sql, static fn(string $s): bool => str_contains($s, 'AS payload_bytes'))),
        ];
    };
    $state = static fn(object $t): array => [
        'rows' => $db->all('SELECT id, title, start_utc, exdates_json, override_fields, google_etag, updated_at FROM events WHERE calendar_id = ? ORDER BY id', [$t->calendarId]),
        'entries' => (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$t->u]),
        'synctoken' => (int) $db->scalar('SELECT synctoken FROM calendars WHERE id = ?', [$t->calendarId]),
    ];

    scenario('google poll: a delta with no items reads no row of the calendar and syncs nothing, and keeps the token, status, stats and health', function () use ($db, $setup, $recorded, $state, $page): void {
        $t = $setup();
        $before = $state($t);
        $db->update('calendars', ['last_poll_status' => 'error', 'last_poll_error' => 'Sample earlier failure'], 'id = ?', [$t->calendarId]);
        $db->run('DELETE FROM feed_stats WHERE calendar_id = ?', [$t->calendarId]);
        $t->google->pages = static fn(array $q): array => isset($q['syncToken']) && $q['syncToken'] === 'sync-1'
            ? $page([], 'sync-2') : ['status' => 500, 'body' => '{}'];
        $poll = $recorded($t);
        $calendar = $db->one('SELECT google_sync_token, last_poll_status, last_poll_error, last_ok_at FROM calendars WHERE id = ?', [$t->calendarId]);
        checkEq('no row load, only the size check made before the request, and nothing upserted', ['count' => 0, 'rowLoads' => 0, 'sizeChecks' => 1], $poll);
        checkEq('the rows, Activity and the CalDAV journal are as they were', $before, $state($t));
        checkEq('the new token is stored and the poll is a success with the stored count', [
            'sync-2', 'ok', null, true, 3, 'ok',
        ], [
            $calendar['google_sync_token'], $calendar['last_poll_status'], $calendar['last_poll_error'], $calendar['last_ok_at'] !== null,
            (int) $db->scalar('SELECT raw_count FROM feed_stats WHERE calendar_id = ?', [$t->calendarId]),
            $db->scalar('SELECT status FROM system_health WHERE subject = ?', ['feed:' . $t->calendarId]),
        ]);
    });

    scenario('google poll: a delta with no items that lands after the calendar left that Google source is discarded, its token not stored', function () use ($db, $setup, $page): void {
        $t = $setup();
        $t->google->pages = static function (array $q) use ($db, $t, $page): array {
            $db->run('UPDATE calendars SET google_binding_version = google_binding_version + 1 WHERE id = ?', [$t->calendarId]);
            return $page([], 'sync-2');
        };
        $refused = refusal(fn() => $t->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$t->calendarId])));
        checkEq('refused as revoked, the old token kept', ['subscription_authorization_revoked', 'sync-1'],
            [$refused, $db->scalar('SELECT google_sync_token FROM calendars WHERE id = ?', [$t->calendarId])]);
    });

    scenario('google poll: a delta with one change still loads the rows and syncs it', function () use ($db, $setup, $recorded, $page, $master): void {
        $t = $setup();
        $t->google->pages = static fn(array $q): array => $page([$master('"q2"', 'Sample sync-up, new room')], 'sync-2');
        $poll = $recorded($t);
        checkEq('the rows are loaded and the change is written', [
            ['count' => 1, 'rowLoads' => 2, 'sizeChecks' => 3], 'Sample sync-up, new room', 'sync-2',
            "From Google calendar 'Sample crew': 1 updated",
        ], [
            $poll,
            $db->scalar('SELECT title FROM events WHERE calendar_id = ? AND google_event_id = ?', [$t->calendarId, 'pollquiet1a2b']),
            $db->scalar('SELECT google_sync_token FROM calendars WHERE id = ?', [$t->calendarId]),
            $db->scalar('SELECT summary FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$t->u]),
        ]);
    });
};
