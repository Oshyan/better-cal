<?php
// A Google calendar for a case, served by the probe's stand-in Google
// (tools/google-probe/lib/FakeGoogle.php), which behaves as the probe of
// 2026-11-07 found Google behaves: the four kinds of master change that drop
// every exception, a field or status change written into every exception,
// a length or bound change keeping them, skips as cancelled records, one
// etag per series changing on every write, 412 when stale, a dropped
// record listed by the delta as a cancelled item with no originalStartTime.
// Its occurrences come from the harness's own evaluator (Truth), never the
// product's expander. The case's Events writes through GoogleWriter to it,
// GoogleSync polls it, and `held` reads what it stores for a series in the
// shape `stored` reads the rows here, so the two compare (README, Google).
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Undo;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Infra\Db;

require_once dirname(__DIR__) . '/google-probe/lib/FakeGoogle.php';

final class GoogleStand
{
    public const CALENDAR = 'sample-team@group.calendar.google.com';

    private function __construct(
        private readonly Db $db,
        public readonly FakeGoogle $fake,
        public readonly int $calendarId,
        public readonly GoogleWriter $writer,
        public readonly GoogleSync $sync,
        public readonly Events $events,
    ) {
    }

    /** A stand-in, an account, a Google calendar the account can edit, and the product's Google-writing Events for $userId. */
    public static function make(Db $db, Undo $undo, int $userId): self
    {
        $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
        $fake = new FakeGoogle(self::CALENDAR);
        $fake->token = null;
        $fake->zone = 'UTC';
        $fake->expand = static fn(array $master): array => self::expand($master);
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $inner = $fake->writerTransport();
        // HARNESS_GOOGLE_TRACE=1 prints every request to the stand-in on stderr.
        $trace = getenv('HARNESS_GOOGLE_TRACE') !== false;
        $writer = new GoogleWriter($db, $auth, $feeds, static function (string $method, string $url, ?array $payload, array $headers = []) use ($inner, $trace): array {
            $answer = $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
            if ($trace) {
                fwrite(STDERR, "google: $method " . rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))) . ' ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . ' -> ' . $answer['status'] . ' ' . mb_substr((string) $answer['body'], 0, 300) . "\n");
            }
            return $answer;
        });
        $list = $fake->syncTransport();
        $sync = new GoogleSync($db, $auth, $feeds, static fn(string $url, array $headers): array => $list($url, array_merge(['Authorization: Bearer sample-access'], $headers)));
        $events = new Events($db, new Recurrence(), $undo, new \BetterCal\Domain\Labels($db),
            new \BetterCal\Domain\Filters($db, $undo, new \BetterCal\Infra\JobQueue($db)), new \BetterCal\Domain\Trips($db, $undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $userId, 'email' => 'owner@example.test',
            'refresh_token_enc' => \BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = (int) $db->insert('calendars', ['user_id' => $userId, 'name' => 'Sample Google calendar', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => self::CALENDAR, 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $userId), 'role' => 'mine']);
        return new self($db, $fake, $calendarId, $writer, $sync, $events);
    }

    /** The stand-in's occurrences of a master: the harness's own evaluator over the resource read as a row, 730 at most. @return list<\DateTimeImmutable> */
    public static function expand(array $master): array
    {
        $allDay = isset($master['start']['date']);
        $zone = $allDay ? 'UTC' : (string) ($master['start']['timeZone'] ?? 'UTC');
        $start = $allDay ? new \DateTimeImmutable($master['start']['date'] . ' 00:00:00', Clock::utc()) : new \DateTimeImmutable((string) $master['start']['dateTime']);
        $end = $allDay ? new \DateTimeImmutable($master['end']['date'] . ' 00:00:00', Clock::utc()) : new \DateTimeImmutable((string) $master['end']['dateTime']);
        $rrule = null;
        foreach ((array) ($master['recurrence'] ?? []) as $line) {
            if (str_starts_with((string) $line, 'RRULE:')) {
                $rrule = substr((string) $line, 6);
            }
        }
        if ($rrule === null) {
            return [];
        }
        $row = ['start_utc' => Clock::db($start), 'end_utc' => Clock::db($end), 'all_day' => $allDay ? 1 : 0, 'tzid' => $zone, 'rrule' => $rrule, 'exdates_json' => null];
        // The evaluator's own window for an unbounded series (never 730 of a
        // yearly rule, which would run it past its horizon), 730 at most.
        $occ = array_slice(Truth::expand($row)['occ'], 0, 730);
        return array_map(static fn(array $o): \DateTimeImmutable => Clock::parse($o['key'])->setTimezone(Clock::zone($zone)), $occ);
    }

    public function calendar(): array
    {
        return $this->db->one('SELECT * FROM calendars WHERE id = ?', [$this->calendarId]);
    }

    /** A poll of the stand-in, as the worker makes one. */
    public function poll(): void
    {
        $this->sync->poll($this->calendar());
    }

    /**
     * A series written here outside the product's paths (the harness
     * writes a rule or a legacy start straight into the row) is given to
     * the stand-in the same way, as if Google had it so, and the row takes
     * the stand-in's etag.
     */
    public function adopt(array $master): void
    {
        $googleId = (string) $master['google_event_id'];
        if (!isset($this->fake->events[$googleId])) {
            return;
        }
        $body = GoogleWriter::body($master);
        $body['recurrence'] = !empty($master['rrule']) ? ['RRULE:' . $master['rrule']] : [];
        $this->fake->events[$googleId] = array_replace($this->fake->events[$googleId], $body);
        $this->fake->seed($this->fake->events[$googleId]);
        $this->db->run('UPDATE events SET google_etag = ? WHERE id = ?', [$this->fake->events[$googleId]['etag'], (int) $master['id']]);
    }

    /**
     * What the stand-in holds for a series: the rule as parts (Google
     * reorders them), the start and end as UTC db instants, the all-day
     * flag, the live records by occurrence key with their title, start,
     * end and status, and the skipped keys (cancelled records and EXDATE
     * lines), each key a UTC db instant so it compares with a row's.
     */
    public function held(string $googleId): ?array
    {
        $m = $this->fake->events[$googleId] ?? null;
        if ($m === null || ($m['status'] ?? '') === 'cancelled') {
            return null;
        }
        $allDay = isset($m['start']['date']);
        $utc = static fn(array $when): string => isset($when['date'])
            ? Clock::db(new \DateTimeImmutable($when['date'] . ' 00:00:00', Clock::utc()))
            : Clock::db(new \DateTimeImmutable((string) $when['dateTime']));
        $rule = '';
        $skips = [];
        foreach ((array) ($m['recurrence'] ?? []) as $line) {
            $line = (string) $line;
            if (str_starts_with($line, 'RRULE:')) {
                $rule = substr($line, 6);
            } elseif (str_starts_with($line, 'EXDATE')) {
                $colon = (int) strpos($line, ':');
                $tz = preg_match('/TZID=([^;:]+)/', substr($line, 0, $colon), $z) === 1 ? Clock::zone($z[1]) : Clock::utc();
                foreach (explode(',', substr($line, $colon + 1)) as $v) {
                    $v = trim($v);
                    if ($v !== '') {
                        $skips[] = Clock::db(str_ends_with($v, 'Z') ? new \DateTimeImmutable($v) : new \DateTimeImmutable(strlen($v) === 8 ? $v . 'T000000' : $v, $tz));
                    }
                }
            }
        }
        $records = [];
        foreach ($this->fake->records($googleId) as $r) {
            $k = $utc($r['originalStartTime']);
            if (($r['status'] ?? '') === 'cancelled') {
                $skips[] = $k;
                continue;
            }
            $records[$k] = [(string) ($r['summary'] ?? ''), $utc($r['start']), $utc($r['end']), (string) ($r['status'] ?? 'confirmed'), isset($r['start']['date']) ? 1 : 0];
        }
        ksort($records);
        $skips = array_values(array_unique($skips));
        sort($skips);
        $parts = Shape::parts($rule);
        foreach ($parts as $k => $v) {
            if (str_contains($v, ',')) {
                $items = explode(',', $v);
                sort($items);
                $parts[$k] = implode(',', $items);
            }
        }
        if (($parts['INTERVAL'] ?? '') === '1') {
            unset($parts['INTERVAL']);
        }
        ksort($parts);
        return ['rule' => $parts, 'start' => $utc($m['start']), 'end' => $utc($m['end']), 'allDay' => $allDay ? 1 : 0, 'title' => (string) ($m['summary'] ?? ''), 'records' => $records, 'skips' => $skips];
    }

    /** The rows here for a series, in held()'s shape. */
    public static function stored(array $master, array $rows): array
    {
        $parts = Shape::parts((string) ($master['rrule'] ?? ''));
        foreach ($parts as $k => $v) {
            if (str_contains($v, ',')) {
                $items = explode(',', $v);
                sort($items);
                $parts[$k] = implode(',', $items);
            }
        }
        if (($parts['INTERVAL'] ?? '') === '1') {
            unset($parts['INTERVAL']);
        }
        ksort($parts);
        $records = [];
        foreach ($rows as $r) {
            $records[(string) $r['recurrence_instance_utc']] = [(string) $r['title'], (string) $r['start_utc'], (string) $r['end_utc'], (string) $r['status'], (int) $r['all_day']];
        }
        ksort($records);
        $skips = Recurrence::decodeExdates($master['exdates_json'] ?? null);
        sort($skips);
        return ['rule' => $parts, 'start' => (string) $master['start_utc'], 'end' => (string) $master['end_utc'], 'allDay' => (int) $master['all_day'], 'title' => (string) $master['title'], 'records' => $records, 'skips' => $skips];
    }
}
