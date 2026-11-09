<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\FeedCredentials;
use BetterCal\Infra\HttpClient;
use BetterCal\Infra\JobQueue;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;

/** ICS feed subscription: fetch, parse, and sync by (calendar_id, uid). */
final class Feeds
{
    // Timeouts now come from HttpClient (5s connect / 20s total).
    private const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * What turns a feed's body into rows, beside the app's version (every
     * release reads each feed afresh once). Raise it for a change to
     * Ics::parse or sync() that ships without a new version, so a feed
     * whose body did not change is read again anyway.
     */
    private const PARSE_VERSION = 1;

    /**
     * How long a feed may go without a fetch that sends no validators and
     * parses and syncs whatever comes back: a server that answers 304 to
     * everything, or a body we keep matching, never hides a change for
     * longer than this.
     */
    private const FULL_READ_SECONDS = 86_400;

    /** The longest validator kept; a longer one is not sent back at all. */
    private const VALIDATOR_MAX = 512;

    public function __construct(
        private readonly Db $db,
        private readonly ?JobQueue $queue = null,
        private readonly ?array $cfg = null,
        // Tests only: HttpClient's transport and resolver for a feed's
        // fetch (a stand-in server answering for an invented host).
        private readonly ?\Closure $httpTransport = null,
        private readonly ?\Closure $httpResolver = null,
    ) {
    }

    /**
     * The rows every sync on this object removed while they held something
     * here alone (droppedDay's shape), its Activity entry's or not: a
     * recovery's store syncs without an entry of its own and names them in
     * its answer and its entry (takeDropped).
     *
     * @var list<array>
     */
    private array $dropped = [];

    /** What the syncs since the last call removed that held something here alone (droppedDay's shape), and forget it. */
    public function takeDropped(): array
    {
        $out = $this->dropped;
        $this->dropped = [];
        return $out;
    }

    /**
     * Force-poll a subscribed calendar now. Returns imported (upserted) count.
     * An ICS subscription is fetched and parsed here; a Google calendar goes
     * through GoogleSync, which produces the same parsed shape. Both share
     * sync() and the outcome bookkeeping below.
     */
    public function poll(int $calendarId): int
    {
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]);
        if ($calendar === null || $calendar['kind'] !== 'subscribed') {
            throw HttpError::badRequest('Calendar is not a feed subscription');
        }
        $google = ($calendar['provider'] ?? 'ics') === 'google';
        if (!$google && empty($calendar['source_url'])) {
            throw HttpError::badRequest('Calendar is not a feed subscription');
        }
        if (!$google) {
            // The scheduler is only an optimization. Every direct refresh and
            // already-queued job must stop here before making a network request.
            SubscriptionAuthority::assertCanPoll($this->db, $calendar);
        }
        try {
            if ($google) {
                $cfg = $this->cfg ?? config();
                return (new GoogleSync($this->db, new GoogleAuth($this->db, $cfg), $this))->poll($calendar);
            }
            $cfg = $this->cfg ?? config();
            $secret = (string) ($cfg['session_secret'] ?? '');
            $url = FeedCredentials::openSourceUrl((string) $calendar['source_url'], $secret);
            $homeTz = Settings::homeTzid($this->db, (int) $calendar['user_id']);
            // What was kept from the last read of this same address, read
            // the same way (validators, body hash): an answer that says
            // nothing changed is taken without parsing or syncing.
            $read = [
                'urlKey' => self::validatorKey($url, $secret),
                'parseKey' => self::parseKey((string) ($cfg['version'] ?? ''), $homeTz),
            ];
            $held = $this->heldRead((int) $calendar['id'], $read['urlKey'], $read['parseKey']);
            $send = [];
            if ($held !== null) {
                if ($held['etag'] !== null) {
                    $send[] = 'If-None-Match: ' . $held['etag'];
                }
                if ($held['last_modified'] !== null) {
                    $send[] = 'If-Modified-Since: ' . $held['last_modified'];
                }
            }
            $answer = $this->fetchFeed($url, $send);
            $read['etag'] = self::validator($answer['headers']['etag'] ?? null);
            $read['lastModified'] = self::validator($answer['headers']['last-modified'] ?? null);
            if ($answer['status'] === 304) {
                if ($held === null || $send === []) {
                    throw new \RuntimeException('Feed fetch failed: HTTP 304 to a request that asked for the whole feed');
                }
                // A 304 may carry the validators afresh; the ones sent stand otherwise.
                $read['etag'] ??= $held['etag'];
                $read['lastModified'] ??= $held['last_modified'];
                return $this->pollUnchanged($calendar, $held, $read);
            }
            $ics = $answer['body'];
            // Bounded before parsing, like every other ICS door (F8): the
            // refusal is recorded as this calendar's poll error, so the owner
            // sees why it stopped updating.
            $problem = Ics::budgetProblem($ics, self::MAX_BYTES, Limits::feedEventBudget());
            if ($problem !== null) {
                throw new \RuntimeException(str_replace('calendar file', 'feed', $problem) . '; it was not read');
            }
            $read['bodyHash'] = hash('sha256', $ics);
            if ($held !== null && hash_equals((string) $held['body_hash'], $read['bodyHash'])) {
                return $this->pollUnchanged($calendar, $held, $read);
            }
            $parsed = Ics::parse($ics, $homeTz, $skipped);
            $count = (new Undo($this->db))->write((int) $calendar['user_id'], function () use ($calendar, $parsed, $skipped, $read): int {
                // A token can be revoked while the remote server is answering.
                // Lock user -> token -> calendar, then reread every governing
                // field. Revocation/reset or an owner adopting the feed either
                // commits first and discards this response, or waits for sync.
                SubscriptionAuthority::lockForPoll($this->db, $calendar);
                $lock = $this->db->forUpdate();
                $current = $this->db->one('SELECT * FROM calendars WHERE id = ?' . $lock, [(int) $calendar['id']]);
                if ($current === null
                    || (string) ($current['kind'] ?? '') !== 'subscribed'
                    || (string) ($current['provider'] ?? 'ics') === 'google'
                    || empty($current['source_url'])
                ) {
                    throw HttpError::conflict('subscription_authorization_revoked', 'This calendar no longer uses this feed; the fetched response was discarded.');
                }
                SubscriptionAuthority::lockForPoll($this->db, $current);
                SubscriptionAuthority::assertCanPoll($this->db, $current);
                $count = $this->sync($current, $parsed, true, null, $skipped);
                $warning = Ics::skippedMessage($skipped);
                $this->pollSucceeded($current, count($parsed), $count, $warning);
                $this->keepRead((int) $current['id'], $read + ['eventCount' => count($parsed), 'warning' => $warning, 'fullAt' => Time::nowDb()]);
                return $count;
            });
            return $count;
        } catch (\Throwable $e) {
            if ($e instanceof HttpError && $e->errorCode === 'subscription_authorization_revoked') {
                // Paused by policy, not a broken feed: leave its last actual
                // poll health alone and let the UI show authorization state.
                throw $e;
            }
            $this->pollFailed($calendar, $e);
            throw $e;
        }
    }

    /**
     * A poll whose answer says the feed is as it was last read (a 304, or
     * the same body): the bookkeeping of a poll that worked, with the count
     * and any skipped-events warning of that read, and nothing synced.
     * Locked and rechecked as a sync is: a revocation that commits while the
     * server answers discards this answer too.
     */
    private function pollUnchanged(array $calendar, array $held, array $read): int
    {
        (new Undo($this->db))->write((int) $calendar['user_id'], function () use ($calendar, $held, $read): void {
            SubscriptionAuthority::lockForPoll($this->db, $calendar);
            $current = $this->db->one('SELECT * FROM calendars WHERE id = ?' . $this->db->forUpdate(), [(int) $calendar['id']]);
            if ($current === null
                || (string) ($current['kind'] ?? '') !== 'subscribed'
                || (string) ($current['provider'] ?? 'ics') === 'google'
                || empty($current['source_url'])
            ) {
                throw HttpError::conflict('subscription_authorization_revoked', 'This calendar no longer uses this feed; the fetched response was discarded.');
            }
            SubscriptionAuthority::lockForPoll($this->db, $current);
            SubscriptionAuthority::assertCanPoll($this->db, $current);
            $warning = $held['warning'] !== null ? (string) $held['warning'] : null;
            $this->pollSucceeded($current, (int) $held['event_count'], 0, $warning);
            $this->keepRead((int) $current['id'], $read + [
                'bodyHash' => (string) $held['body_hash'],
                'eventCount' => (int) $held['event_count'],
                'warning' => $warning,
                'fullAt' => (string) $held['full_at'],
            ]);
        });
        return 0;
    }

    /**
     * The last read of this calendar's feed, when it was of the same address
     * read the same way and recently enough read in full; null asks for the
     * whole feed (no validators sent, whatever comes back parsed and synced).
     * A row kept for another address is never used: its validators belong to
     * another server. Missing table (code ahead of migration 073): null.
     */
    private function heldRead(int $calendarId, string $urlKey, string $parseKey): ?array
    {
        try {
            $row = $this->db->one('SELECT * FROM feed_reads WHERE calendar_id = ?', [$calendarId]);
        } catch (\PDOException) {
            return null;
        }
        if ($row === null
            || !hash_equals((string) $row['url_key'], $urlKey)
            || (string) $row['parse_key'] !== $parseKey
            || Time::fromDb((string) $row['full_at'])->getTimestamp() < time() - self::FULL_READ_SECONDS
        ) {
            return null;
        }
        return $row;
    }

    /** This read, kept for the next poll in place of the last; never the address itself. */
    private function keepRead(int $calendarId, array $read): void
    {
        $row = [
            'calendar_id' => $calendarId,
            'url_key' => $read['urlKey'],
            'parse_key' => $read['parseKey'],
            'etag' => $read['etag'],
            'last_modified' => $read['lastModified'],
            'body_hash' => $read['bodyHash'],
            'event_count' => $read['eventCount'],
            'warning' => $read['warning'],
            'full_at' => $read['fullAt'],
        ];
        try {
            $this->db->run('DELETE FROM feed_reads WHERE calendar_id = ?', [$calendarId]);
            $this->db->insert('feed_reads', $row);
        } catch (\PDOException $e) {
            // Before migration 073 there is nowhere to keep it; every poll
            // reads in full, as before.
            if (str_contains($e->getMessage(), 'feed_reads')) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Which address a kept read belongs to, without keeping the address (it
     * may carry credentials): keyed with the install's secret, so a copy of
     * the database does not reveal it either. Pure.
     */
    public static function validatorKey(string $url, string $sessionSecret): string
    {
        return hash_hmac('sha256', $url, 'feed-reads|' . $sessionSecret);
    }

    /**
     * The configured limits a body's parse reads (Ics::parse and the
     * Recurrence checks it calls): a description or link cut at its limit,
     * EXDATE and list values counted against theirs. A change to one reads
     * every feed afresh, as a new version does.
     */
    private const PARSE_LIMITS = ['DESCRIPTION_CHARS', 'URL_CHARS', 'EXDATE_VALUES_PER_EVENT', 'EXDATE_VALUES_PER_INPUT', 'ICS_LIST_VALUES_PER_INPUT', 'ICS_LIST_VALUES_PER_LINE'];

    /** What a body is read with besides itself: the parser's version, the Home zone and the limits the parse reads (PARSE_LIMITS). */
    public static function parseKey(string $appVersion, ?string $homeTz): string
    {
        $limits = array_map(static fn(string $name): string => $name . '=' . Limits::get($name), self::PARSE_LIMITS);
        return self::PARSE_VERSION . '|' . $appVersion . '|' . ($homeTz ?? '') . '|' . hash('sha256', implode(';', $limits));
    }

    /** A validator header as it may be sent back: one line, of bounded length, or null. Pure. */
    public static function validator(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '' || strlen($value) > self::VALIDATOR_MAX || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }
        return $value;
    }

    /** Bookkeeping for a poll that worked: status, stats, health, follow-up jobs. */
    public function pollSucceeded(array $calendar, int $rawCount, int $upserts, ?string $warning = null): void
    {
        $calendarId = (int) $calendar['id'];
        $now = Time::nowDb();
        $this->db->update('calendars', [
            'last_polled_at' => $now,
            'last_poll_status' => 'ok',
            'last_poll_error' => $warning,
            'last_ok_at' => $now,
        ], 'id = ?', [$calendarId]);
        $this->recordStats($calendarId, $rawCount);
        (new SystemHealth($this->db))->recordOk('feed:' . $calendarId, 'feed', (int) $calendar['user_id'], 'Feed: ' . (string) $calendar['name']);
        $health = new SystemHealth($this->db);
        if ($warning !== null) $health->recordFailure('feed-input:' . $calendarId, 'feed', (int) $calendar['user_id'], 'Skipped events: ' . (string) $calendar['name'], $warning);
        else $health->recordOk('feed-input:' . $calendarId, 'feed', (int) $calendar['user_id'], 'Skipped events: ' . (string) $calendar['name'], false);
        // New/updated feed events need background prompt-filter evaluation
        // and rank scoring (mirrors the ChangeLog hook: fired per poll).
        if ($upserts > 0 && $this->queue !== null) {
            if (!$this->queue->hasPending('geocode_sweep')) {
                $this->queue->enqueue('geocode_sweep', []);
            }
            $filterHash = 'filter-calendar:' . $calendarId;
            if (!$this->queue->hasPendingWithHash('filter_eval', $filterHash)) {
                $this->queue->enqueue('filter_eval', ['calendarId' => $calendarId, 'hash' => $filterHash]);
            }
            if (!$this->queue->hasPending('rank_events')) {
                $this->queue->enqueue('rank_events', []);
            }
        }
    }

    /** Bookkeeping for a poll that failed: status with the reason, health streak. */
    public function pollFailed(array $calendar, \Throwable $e): void
    {
        $calendarId = (int) $calendar['id'];
        RequestAuthority::checkedTx($this->db, fn() => $this->db->update('calendars', [
            'last_polled_at' => Time::nowDb(),
            'last_poll_status' => 'error',
            'last_poll_error' => mb_substr($e->getMessage(), 0, 2000),
        ], 'id = ?', [$calendarId]));
        // The badge says "error" while you look; this gives it a since-when,
        // a history, and eventually an email if it stays that way.
        (new SystemHealth($this->db))->recordFailure('feed:' . $calendarId, 'feed', (int) $calendar['user_id'], 'Feed: ' . (string) $calendar['name'], $e->getMessage());
    }

    public function fetch(string $url): string
    {
        return $this->fetchFeed($url, [])['body'];
    }

    /**
     * A feed's answer to a GET with $headers (validators, on a poll): status,
     * body and the final hop's headers. A 304 comes back as it is, body
     * empty; any other answer is checked as a whole ICS calendar.
     *
     * @param list<string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private function fetchFeed(string $url, array $headers): array
    {
        if (str_starts_with($url, 'webcal://')) {
            $url = 'https://' . substr($url, strlen('webcal://'));
        }
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Feed URL must be http(s) or webcal');
        }
        // GH #21: this used to be raw cURL with CURLOPT_FOLLOWLOCATION and no
        // address check, so a subscription URL — which the user supplies and an
        // upstream server can redirect at will — could be walked to 127.0.0.1
        // or the cloud metadata endpoint. HttpClient resolves before connecting,
        // refuses private/loopback/link-local/metadata addresses, pins cURL to
        // the vetted IP against DNS rebind, and re-vets every redirect hop.
        // Feed-shaped limits, not plugin-shaped ones: an ICS subscription is
        // legitimately large, and redirect chains through calendar providers are
        // common enough to want more than the default three.
        $http = new HttpClient(
            requestBudget: 8,
            userAgent: HttpClient::userAgentFor('ics-subscriber'),
            maxBytes: self::MAX_BYTES,
            maxRedirects: 5,
            resolver: $this->httpResolver,
            transport: $this->httpTransport,
        );
        try {
            $r = $http->get($url, $headers);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Feed fetch failed: ' . $e->getMessage(), 0, $e);
        }
        $body = $r['body'];
        $status = $r['status'];
        $answerHeaders = $r['headers'] ?? [];
        if ($status === 304) {
            return ['status' => 304, 'body' => '', 'headers' => $answerHeaders];
        }
        if ($status >= 400) {
            throw new \RuntimeException("Feed fetch failed: HTTP $status");
        }
        if ($body === '') {
            throw new \RuntimeException('Feed fetch failed: empty response');
        }
        if (!str_contains($body, 'BEGIN:VCALENDAR')) {
            throw new \RuntimeException('Response is not an ICS calendar');
        }
        if (!Ics::completeCalendar($body)) {
            throw new \RuntimeException('Feed fetch failed: incomplete ICS calendar');
        }
        return ['status' => $status, 'body' => $body, 'headers' => $answerHeaders];
    }

    /**
     * Does a stored column already hold this value? Numbers come back as
     * strings, and JSON columns come back reformatted by MySQL ('["a", "b"]'),
     * so those compare by what they say. Comparing JSON as text made every
     * series with two or more skipped dates "change" on every poll. Pure.
     */
    public static function sameValue(string $col, mixed $current, mixed $value): bool
    {
        if ($current !== null && in_array($col, ['all_day', 'recurrence_parent_id'], true)) {
            $current = (int) $current;
        }
        if ($col === 'exdates_json' && $current !== null && $value !== null) {
            try {
                return Recurrence::decodeExdates($current) == Recurrence::decodeExdates($value);
            } catch (\InvalidArgumentException) {
                // A legacy over-limit row is deliberately not decoded. Treat
                // it as changed so a safe value from the feed can repair it.
                return false;
            }
        }
        if ($col === 'invite_json' && $current !== null && $value !== null
            && json_decode((string) $current, true) == json_decode((string) $value, true)) {
            return true;
        }
        return $current === $value || (string) $current === (string) $value;
    }

    /**
     * Whether a sync's changes carry a new answer to a Google-synced
     * invitation that should clear a Planned/Maybe chosen by hand, so the
     * answer decides again (Events::relationship). Pure; unit-tested.
     *
     * @param array<string,mixed> $current the stored row
     * @param array<string,mixed> $changed the columns this sync changes
     */
    public static function answerResetsChoice(array $current, array $changed): bool
    {
        return array_key_exists('invite_json', $changed)
            && in_array((string) ($current['attendance'] ?? 'none'), ['going', 'interested'], true)
            && Events::googleAnswer(['invite_json' => $current['invite_json'] ?? null]) !== Events::googleAnswer(['invite_json' => $changed['invite_json']]);
    }

    /**
     * The own fields of an edited day that no feed carries: what is held
     * here alone. The place is a field too (Own Fields: its text with its
     * coordinates): a feed carries its text, never its coordinates, so a
     * day's picked coordinates are held here alone while it shows the text
     * they were picked for (heldHere), and a new text clears them, as it
     * does on every feed row. The one list (OwnFields::HELD_HERE).
     */
    private const HELD_HERE_FIELDS = \BetterCal\Domain\Series\OwnFields::HELD_HERE;

    /** The place's columns no feed carries: its coordinates and where they came from (OwnFields::COORDINATE_COLUMNS). */
    private const COORDINATE_COLUMNS = \BetterCal\Domain\Series\OwnFields::COORDINATE_COLUMNS;

    /**
     * What an edited day holds here alone, of the fields it owns ($own),
     * as $row stands: the HELD_HERE_FIELDS it owns, and its place while it
     * owns it with coordinates (the part of the place no feed carries).
     * What refers to the row (OwnFields::references) is read apart. Pure.
     *
     * @param list<string> $own
     * @return list<string>
     */
    public static function heldHere(array $own, array $row): array
    {
        $held = array_values(array_intersect($own, self::HELD_HERE_FIELDS));
        if (in_array('location', $own, true) && ($row['location_lat'] ?? null) !== null && ($row['location_lng'] ?? null) !== null) {
            $held[] = 'location';
        }
        return \BetterCal\Domain\Series\OwnFields::normalize($held);
    }

    /**
     * What a row of a series keeps when the feed's record of its day stops
     * holding anything of its own (it changes to hold nothing, goes, or
     * never existed): the fields it owns that are held here alone, its
     * picked coordinates while it shows the place text they were picked for
     * (heldHere, as $row stands, so a row already following its series
     * reads with the series' text), and what refers to it
     * (OwnFields::references). Null when it keeps nothing: an ordinary
     * occurrence again, no row. The one retention function: the sync's
     * removal loop (a delta, a whole read and the 410 reset alike, each
     * through sync) and recovery (rewind, Finish and Put back, whose stores
     * go through sync too, and whose rows a store made are judged here)
     * call it; nothing else decides what an edited day keeps.
     *
     * @param ?list<string> $own the row's own list (default: as stored on it)
     * @return array{fields:list<string>, refs:list<string>}|null
     */
    public static function retained(Db $db, array $row, ?array $own = null): ?array
    {
        if (($row['recurrence_instance_utc'] ?? null) === null) {
            return null;
        }
        $id = (int) ($row['id'] ?? 0);
        $fields = self::heldHere($own ?? \BetterCal\Domain\Series\OwnFields::decode($row['override_fields'] ?? null) ?? [], $row);
        $refs = $id > 0 ? (\BetterCal\Domain\Series\OwnFields::references($db, [$id])[$id] ?? []) : [];
        return $fields === [] && $refs === [] ? null : ['fields' => $fields, 'refs' => $refs];
    }

    /**
     * Whether the feed's record of an edited day holds something of its
     * own: a field it owns that a feed carries, or a guest list other than
     * its series'. A place is the record's by its text alone: one showing
     * its series' text, now ($series) or before this poll ($was), is the
     * series' text with coordinates of its own, which no record carries (a
     * delta lists a stored copy of such a day, never the series' new text
     * on it). Pure.
     *
     * @param list<string> $own
     */
    public static function recordHolds(array $own, array $row, array $series, ?array $was = null): bool
    {
        $text = (string) ($row['location'] ?? '');
        foreach ($own as $field) {
            if (in_array($field, self::HELD_HERE_FIELDS, true)
                || ($field === 'location' && ($text === (string) ($series['location'] ?? '') || ($was !== null && $text === (string) ($was['location'] ?? ''))))) {
                continue;
            }
            return true;
        }
        return ($row['invite_json'] ?? null) !== null && !self::sameValue('invite_json', $series['invite_json'] ?? null, $row['invite_json']);
    }

    /** $row as it follows its series ($target, ofSeries): a new place text clears its coordinates. Pure. */
    public static function followed(array $row, array $target): array
    {
        $after = array_merge($row, $target);
        if (array_key_exists('location', $target) && (string) ($row['location'] ?? '') !== (string) ($target['location'] ?? '')) {
            foreach (self::COORDINATE_COLUMNS as $col) {
                $after[$col] = null;
            }
        }
        return $after;
    }

    /**
     * An edited row's own fields: its list, or for a row stored before the
     * list (null) the fields that differ from its series, tags and people
     * included (OwnFields::infer), so a tag given before migration 063 is
     * still the day's own.
     *
     * @return list<string>
     */
    private function ownOf(array $row, array $master): array
    {
        $known = \BetterCal\Domain\Series\OwnFields::decode($row['override_fields'] ?? null);
        if ($known !== null) {
            return $known;
        }
        $of = fn(string $table, string $col, array $r): array => array_column($this->db->all("SELECT $col FROM $table WHERE event_id = ?", [(int) ($r['id'] ?? 0)]), $col);
        return \BetterCal\Domain\Series\OwnFields::infer($row, $master, $of('event_tags', 'tag_id', $row), $of('event_tags', 'tag_id', $master),
            $of('event_people', 'person_id', $row), $of('event_people', 'person_id', $master));
    }

    /**
     * A following row whose image another row holds ($holderId) gives that
     * row what it holds here alone ($held; $source as it follows its
     * series): its tags and people are added to the holder's; its
     * reminders, answer and icon are taken where the holder owns none; its
     * coordinates where the holder owns no place and shows the same text;
     * what refers to it (trip links, duplicate decisions, plugin data) is
     * moved to the holder where that names nothing twice. The holder owns
     * what it took.
     *
     * @param list<string> $held
     */
    private function mergeInto(array $source, int $holderId, array $held, ?array $series, bool $ownColumn): void
    {
        $sourceId = (int) $source['id'];
        $holder = $this->db->one('SELECT * FROM events WHERE id = ?', [$holderId]);
        if ($holder === null) {
            return;
        }
        $own = $this->ownOf($holder, $series ?? $holder);
        $set = [];
        $taken = [];
        foreach (['tags' => ['event_tags', 'tag_id'], 'people' => ['event_people', 'person_id']] as $field => [$table, $col]) {
            if (in_array($field, $held, true)) {
                $this->db->run($this->db->insertIgnore() . " INTO $table (event_id, $col) SELECT ?, $col FROM $table WHERE event_id = ?", [$holderId, $sourceId]);
                $taken[] = $field;
            }
        }
        foreach (['reminders' => 'reminders_json', 'attendance' => 'attendance', 'icon' => 'icon'] as $field => $col) {
            if (in_array($field, $held, true) && !in_array($field, $own, true)) {
                $set[$col] = $source[$col] ?? null;
                $taken[] = $field;
            }
        }
        if (in_array('location', $held, true) && !in_array('location', $own, true) && (string) ($holder['location'] ?? '') === (string) ($source['location'] ?? '')) {
            foreach (self::COORDINATE_COLUMNS as $col) {
                $set[$col] = $source[$col] ?? null;
            }
            $taken[] = 'location';
        }
        foreach ($this->db->all('SELECT * FROM event_links WHERE event_id = ? OR container_id = ?', [$sourceId, $sourceId]) as $link) {
            $container = (int) $link['container_id'] === $sourceId ? $holderId : (int) $link['container_id'];
            $event = (int) $link['event_id'] === $sourceId ? $holderId : (int) $link['event_id'];
            if ($container !== $event && $this->db->scalar('SELECT id FROM event_links WHERE container_id = ? AND event_id = ?', [$container, $event]) === null) {
                $this->db->run('UPDATE event_links SET container_id = ?, event_id = ? WHERE id = ?', [$container, $event, (int) $link['id']]);
            }
        }
        foreach ($this->db->all("SELECT * FROM event_duplicates WHERE (event_a = ? OR event_b = ?) AND status IN ('linked', 'dismissed')", [$sourceId, $sourceId]) as $pair) {
            $other = (int) $pair['event_a'] === $sourceId ? (int) $pair['event_b'] : (int) $pair['event_a'];
            [$a, $b] = [min($holderId, $other), max($holderId, $other)];
            if ($other !== $holderId && $this->db->scalar('SELECT id FROM event_duplicates WHERE event_a = ? AND event_b = ?', [$a, $b]) === null) {
                $this->db->run('UPDATE event_duplicates SET event_a = ?, event_b = ? WHERE id = ?', [$a, $b, (int) $pair['id']]);
            }
        }
        foreach ($this->db->all('SELECT plugin_id, k FROM event_plugin_data WHERE event_id = ?', [$sourceId]) as $data) {
            if ($this->db->scalar('SELECT event_id FROM event_plugin_data WHERE event_id = ? AND plugin_id = ? AND k = ?', [$holderId, $data['plugin_id'], $data['k']]) === null) {
                $this->db->run('UPDATE event_plugin_data SET event_id = ? WHERE event_id = ? AND plugin_id = ? AND k = ?', [$holderId, $sourceId, $data['plugin_id'], $data['k']]);
            }
        }
        if ($ownColumn && $taken !== []) {
            $set['override_fields'] = \BetterCal\Domain\Series\OwnFields::encode(array_merge($own, $taken));
        }
        if ($set !== []) {
            $this->db->update('events', $set + ['updated_at' => Time::nowDb()], 'id = ?', [$holderId]);
        }
    }

    /**
     * A row of its series (an edited day the feed holds no record of its
     * own for) as the series, after this poll, has it: null for a day the
     * series no longer has, else the columns it takes from the series:
     * every field a feed carries (title, place text, notes, link, status,
     * all-day, zone, the guest list, and the time: its key and its series'
     * length from there) and every series column (Model::SERIES_COLUMNS:
     * the trip and floating flags), which no edited day owns.
     *
     * Its day is asked the way a "this event" edit asks (Events::
     * requireInstance): a key the series' own expansion yields on that day,
     * not skipped. A key that is no longer one moves to its image, the
     * series' one occurrence on the day the key showed under the series as
     * it was (a new clock time or zone for the whole series moves the day's
     * row with it); a day with none, or several and not the key, is gone.
     * A rule the expander can't read keeps the row at its key, its other
     * fields refreshed: it can't be told whether the day is still there.
     * Pure.
     *
     * @param array<string,mixed> $row the edited day's row
     * @param array<string,mixed>|null $was its series' row before this poll
     * @param array<string,mixed> $series its series' row after it
     * @return array<string,mixed>|null
     */
    public static function ofSeries(array $row, ?array $was, array $series): ?array
    {
        $key = (string) $row['recurrence_instance_utc'];
        $allDay = (int) ($series['all_day'] ?? 0) === 1;
        $target = [
            'title' => $series['title'],
            'description' => $series['description'],
            'location' => $series['location'],
            'url' => $series['url'],
            'status' => $series['status'],
            'all_day' => $allDay ? 1 : 0,
            'tzid' => $series['tzid'],
        ];
        if (array_key_exists('invite_json', $series)) {
            $target['invite_json'] = $series['invite_json'];
        }
        $target += array_intersect_key($series, \BetterCal\Domain\Series\Model::SERIES_COLUMNS);
        if (empty($series['rrule'])) {
            return null;
        }
        $calcOf = static function (?array $r): ?\BetterCal\Domain\Series\Calc {
            if ($r === null || empty($r['rrule'])) {
                return null;
            }
            try {
                $calc = \BetterCal\Domain\Series\Calc::fromRow($r);
                return $calc->expandable() ? $calc : null;
            } catch (\InvalidArgumentException | \BetterCal\Domain\Series\WalkIncomplete) {
                return null;
            }
        };
        $calc = $calcOf($series);
        if ($calc === null) {
            return $target;
        }
        try {
            $skips = Recurrence::decodeExdates($series['exdates_json'] ?? null);
        } catch (\InvalidArgumentException) {
            $skips = [];
        }
        $dn = ($calcOf($was) ?? $calc)->dnOf($key);
        try {
            $on = array_values(array_diff($calc->keysOn($dn), $skips));
        } catch (\BetterCal\Domain\Series\WalkIncomplete) {
            return $target;
        }
        $at = in_array($key, $on, true) ? $key : (count($on) === 1 ? $on[0] : null);
        if ($at === null) {
            return null;
        }
        $length = max(0, Time::fromDb((string) $series['end_utc'])->getTimestamp() - Time::fromDb((string) $series['start_utc'])->getTimestamp());
        $target['recurrence_instance_utc'] = $at;
        $target['start_utc'] = $at;
        $target['end_utc'] = Time::toDb(Time::fromDb($at)->modify('+' . $length . ' seconds'));
        // Google names the occurrence by its key: a day moved to a new key
        // is that occurrence's instance there.
        if ($at !== $key && !empty($row['google_event_id']) && !empty($series['google_event_id'])) {
            $target['google_event_id'] = GoogleWriter::instanceId((string) $series['google_event_id'], $at, $allDay, (string) $series['tzid']);
        }
        return $target;
    }

    /**
     * A row a poll or a recovery removes while it held something here
     * alone, named for its Activity entry (decision A5, the one naming of
     * a removal for the sync and recovery): its row id, the date it showed
     * (an edited day's key, an event's or series' start, in its own zone),
     * its title, what it held here ($held, and what referred to it, $refs)
     * and why it went: 'gone' (its series no longer has the day), 'merged'
     * (another row holds the day, and took what it held) or 'deleted' (the
     * event or its whole series is no longer in the calendar). Pure.
     *
     * @param list<string> $held
     * @param list<string> $refs
     * @return array{id:int,title:string,day:string,held:list<string>,why:string}
     */
    public static function droppedDay(array $row, array $held, array $refs, string $why): array
    {
        $words = ['location' => 'its coordinates', 'reminders' => 'its reminders', 'attendance' => 'its answer', 'tags' => 'its tags', 'people' => 'its people', 'icon' => 'its icon'];
        $named = array_values(array_intersect_key($words, array_flip($held)));
        if ($refs !== []) {
            $named[] = 'what refers to it';
        }
        $zone = (int) ($row['all_day'] ?? 0) === 1 ? 'UTC' : (string) ($row['tzid'] ?: 'UTC');
        $at = (string) ($row['recurrence_instance_utc'] ?? $row['start_utc']);
        return ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'day' => Time::fromDb($at)->setTimezone(Time::zone($zone))->format('Y-m-d'), 'held' => $named, 'why' => $why];
    }

    /**
     * The removed rows named in words (droppedDay's shape), grouped by why
     * they went: the days gone, the days kept on another row, the events
     * deleted. A poll's Activity entry puts them after its count; a
     * recovery's answer gives them as a difference.
     */
    public static function droppedWords(array $days): string
    {
        $groups = [];
        foreach (['gone' => ['its series no longer has that day', 'their series no longer has those days'],
            'merged' => ['kept on the row its day already has', 'kept on the rows their days already have'],
            'deleted' => ['it is no longer in the calendar', 'they are no longer in the calendar']] as $why => [$one, $many]) {
            $these = array_values(array_filter($days, static fn(array $d): bool => $d['why'] === $why));
            if ($these === []) {
                continue;
            }
            $named = array_map(static function (array $d): string {
                $held = $d['held'];
                $last = array_pop($held);
                $list = $held === [] ? (string) $last : implode(', ', $held) . ' and ' . $last;
                return "'" . $d['title'] . "' on " . $d['day'] . ($last === null ? '' : ' with ' . $list);
            }, array_slice($these, 0, 3));
            $more = count($these) - count($named);
            $groups[] = implode('; ', $named) . ($more > 0 ? '; ' . $more . ' more' : '') . ': ' . (count($these) === 1 ? $one : $many);
        }
        return implode('; ', $groups);
    }

    /**
     * What a whole event (a series' master, or an event of its own) holds
     * here alone, as it stands: the HELD_HERE_FIELDS with a value, and its
     * coordinates. What refers to it is read apart.
     *
     * @return list<string>
     */
    private function heldByEvent(array $row): array
    {
        $id = (int) $row['id'];
        $held = [];
        if (($row['reminders_json'] ?? null) !== null) {
            $held[] = 'reminders';
        }
        if ((string) ($row['attendance'] ?? 'none') !== 'none') {
            $held[] = 'attendance';
        }
        if ((string) ($row['icon'] ?? '') !== '') {
            $held[] = 'icon';
        }
        if ($this->db->scalar('SELECT 1 FROM event_tags WHERE event_id = ? LIMIT 1', [$id]) !== null) {
            $held[] = 'tags';
        }
        if ($this->db->scalar('SELECT 1 FROM event_people WHERE event_id = ? LIMIT 1', [$id]) !== null) {
            $held[] = 'people';
        }
        if (($row['location_lat'] ?? null) !== null && ($row['location_lng'] ?? null) !== null) {
            $held[] = 'location';
        }
        return \BetterCal\Domain\Series\OwnFields::normalize(array_values(array_intersect($held, array_merge(self::HELD_HERE_FIELDS, ['location']))));
    }

    /**
     * The event columns a parsed VEVENT (Ics::parse's shape, which Google's
     * sync and CalDAV produce too) writes, with $parentId as an edited
     * day's master. The one mapping from the parsed shape to a row, for the
     * sync and for a writer that makes a row the sync would have (an edited
     * day of a Google series that holds only local things). Pure.
     */
    public static function columnsOf(array $ev, ?int $parentId): array
    {
        $columns = [
            'title' => $ev['title'],
            // Cleaned on the way in like every other source (#58):
            // only allowlisted HTML is ever stored.
            'description' => Sanitize::description($ev['description']),
            'location' => $ev['location'],
            'url' => $ev['url'],
            'start_utc' => $ev['start_utc'],
            'end_utc' => $ev['end_utc'],
            'all_day' => $ev['all_day'],
            'tzid' => $ev['tzid'],
            'rrule' => $ev['rrule'],
            'exdates_json' => $ev['exdates'] === [] ? null : json_encode($ev['exdates']),
            'status' => $ev['status'],
            'recurrence_instance_utc' => $ev['recurrence_instance_utc'],
            'recurrence_parent_id' => $ev['recurrence_instance_utc'] !== null ? $parentId : null,
        ];
        if (array_key_exists('google_event_id', $ev)) {
            $columns['google_event_id'] = $ev['google_event_id'];
        }
        // A feed's time with no zone floats (#167). Google's rows carry
        // no flag, so a Google sync keeps the one set here.
        if (array_key_exists('floating', $ev)) {
            $columns['floating'] = (int) $ev['floating'];
        }
        if (array_key_exists('invite_json', $ev)) {
            $columns['invite_json'] = $ev['invite_json'];
        }
        return $columns;
    }

    /**
     * Sync parsed VEVENTs into the calendar: update changed, insert new,
     * delete events whose uid disappeared from the feed.
     *
     * @param list<array<string,mixed>> $parsed
     */
    public function sync(array $calendar, array $parsed, bool $journal = true, ?float $deadline = null, array $skipped = []): int
    {
        // Keep direct callers inside the same boundary as Ics::parse. Google
        // and tests can hand parsed shapes to this method without passing
        // through the ICS preflight first.
        Recurrence::assertExdateBatch($parsed);
        self::assertSyncDeadline($deadline);

        $calendarId = (int) $calendar['id'];
        $userId = (int) $calendar['user_id'];

        $existing = $this->db->all('SELECT * FROM events WHERE calendar_id = ?', [$calendarId]);
        self::assertSyncDeadline($deadline);
        $existingByKey = [];
        foreach ($existing as $index => $row) {
            if (($index & 127) === 0) {
                self::assertSyncDeadline($deadline);
            }
            $existingByKey[$row['uid'] . '|' . ($row['recurrence_instance_utc'] ?? '')] = $row;
        }

        // Masters first so overrides can resolve recurrence_parent_id.
        usort($parsed, static fn(array $a, array $b): int => ($a['recurrence_instance_utc'] === null ? 0 : 1) <=> ($b['recurrence_instance_utc'] === null ? 0 : 1));
        self::assertSyncDeadline($deadline);

        $masterIdByUid = [];
        foreach ($existing as $index => $row) {
            if (($index & 127) === 0) {
                self::assertSyncDeadline($deadline);
            }
            if ($row['recurrence_instance_utc'] === null) {
                $masterIdByUid[(string) $row['uid']] = (int) $row['id'];
            }
        }

        $seen = [];
        $upserts = 0;
        $changedUids = [];
        $newMasterUids = [];
        $updatedEventIds = [];
        $addedTitles = [];
        $removedCount = 0;
        $protected = array_fill_keys(array_filter(array_column($skipped, 'uid')), true);
        $unknownSkipped = count(array_filter($skipped, static fn(array $s): bool => empty($s['uid']))) > 0;
        // What it changes, for undo: a feed's poll isn't undoable (the next
        // one would redo it), but an older undo of these events (an
        // attendance, a tag) stands on them.
        $touches = [];
        // An edited day's own fields follow from what changed at the other
        // end (series-model.md, Rows from Elsewhere): its master's values as
        // this poll brings them.
        $ownColumn = \BetterCal\Domain\Series\OwnFields::hasColumn($this->db);
        $parsedMasters = [];
        foreach ($parsed as $pm) {
            if ($pm['recurrence_instance_utc'] === null) {
                $parsedMasters[(string) $pm['uid']] = ['title' => $pm['title'], 'description' => Sanitize::description($pm['description']), 'location' => $pm['location'],
                    'url' => $pm['url'], 'start_utc' => $pm['start_utc'], 'end_utc' => $pm['end_utc'], 'all_day' => $pm['all_day'], 'tzid' => $pm['tzid'], 'status' => $pm['status'],
                    'invite_json' => $pm['invite_json'] ?? null];
            }
        }
        // Edited days the feed carries that are ordinary occurrences here
        // (nothing of their own; below), by key: not stored, and a row one
        // had goes with the rows the feed no longer carries.
        $plain = [];
        $undo = new Undo($this->db);
        $undo->write($userId, function () use ($ownColumn, $parsedMasters, $parsed, $protected, $unknownSkipped, $existingByKey, &$masterIdByUid, &$seen, &$plain, &$upserts, &$changedUids, &$newMasterUids, &$updatedEventIds, &$addedTitles, &$removedCount, &$touches, $calendarId, $userId, $deadline, $undo, $journal, $calendar): void {
            foreach ($parsed as $index => $ev) {
                if (($index & 127) === 0) {
                    self::assertSyncDeadline($deadline);
                }
                $key = $ev['uid'] . '|' . ($ev['recurrence_instance_utc'] ?? '');
                if (isset($seen[$key])) {
                    continue; // duplicate VEVENT in feed
                }
                $seen[$key] = true;

                // reminders_json is deliberately not synced: reminders on feed
                // events are user-local (like tags), and feed VALARMs must not
                // overwrite them on every poll.
                $columns = self::columnsOf($ev, $ev['recurrence_instance_utc'] !== null ? ($masterIdByUid[(string) $ev['uid']] ?? null) : null);
                // Google's etag (GoogleSync::toParsed) is kept, not compared:
                // every write to a series changes every row's etag (probe,
                // group 5), and a new etag alone is no change to the event.
                $etag = isset($ev['google_etag']) && $ev['google_etag'] !== '' ? (string) $ev['google_etag'] : null;

                $current = $existingByKey[$key] ?? null;
                $feedMaster = $ev['recurrence_instance_utc'] !== null ? ($parsedMasters[(string) $ev['uid']] ?? null) : null;
                if ($ownColumn && $feedMaster !== null) {
                    $own = \BetterCal\Domain\Series\OwnFields::afterSync($current, $current === null ? $columns : array_merge($current, $columns), $feedMaster);
                    $columns['override_fields'] = \BetterCal\Domain\Series\OwnFields::encode($own);
                    // An edited day with nothing of its own is an ordinary
                    // occurrence again (series-model.md, Own Fields), from
                    // elsewhere as from a writer here: a record equal to its
                    // series at its occurrence, with no field of its own, no
                    // guest list of its own and nothing here that refers to
                    // it, is no row. Google keeps one for every day a write
                    // touched (a day put back to the series' values, a
                    // skipped day brought back), so the rule is what keeps
                    // those from becoming edited days here.
                    $ownInvite = ($columns['invite_json'] ?? null) !== null && !self::sameValue('invite_json', $feedMaster['invite_json'] ?? null, $columns['invite_json']);
                    if ($own === [] && !$ownInvite
                        && ($current === null || \BetterCal\Domain\Series\OwnFields::references($this->db, [(int) $current['id']]) === [])) {
                        $plain[$key] = true;
                        continue;
                    }
                }
                if ($current === null) {
                    // A new edited day takes its series' own columns
                    // (Model::SERIES_COLUMNS: the trip and floating flags),
                    // which no edited day owns, where its record doesn't
                    // carry them: Google's carry neither.
                    $series = $ev['recurrence_instance_utc'] !== null ? ($existingByKey[$ev['uid'] . '|'] ?? null) : null;
                    if ($series !== null) {
                        $columns += array_intersect_key($series, \BetterCal\Domain\Series\Model::SERIES_COLUMNS);
                    }
                    $id = $this->db->insert('events', $columns + ($etag !== null ? ['google_etag' => $etag] : []) + [
                        'user_id' => $userId,
                        'calendar_id' => $calendarId,
                        'uid' => $ev['uid'],
                        'source' => 'feed',
                        'created_via' => 'feed',
                    ]);
                    // The row, not its calendar: undoing the subscription
                    // still takes what its polls brought (a day's row: the
                    // fields it owns of that day, Undo::touchesOf).
                    $touches = array_merge($touches, Undo::touchesOf(null, ['events' => [$columns + ['id' => $id]]]));
                    if ($ev['recurrence_instance_utc'] === null) {
                        $masterIdByUid[(string) $ev['uid']] = $id;
                        $newMasterUids[(string) $ev['uid']] = true;
                    }
                    $changedUids[(string) $ev['uid']] = true;
                    $addedTitles[] = (string) $ev['title'];
                    $upserts++;
                    continue;
                }

                $changed = [];
                foreach ($columns as $col => $value) {
                    if (!self::sameValue($col, $current[$col], $value)) {
                        $changed[$col] = $value;
                    }
                }
                if ($current['deleted_at'] !== null) {
                    $changed['deleted_at'] = null;
                }
                // An answer changed in Google Calendar decides Planned or
                // Maybe again, as one given here does (Rsvp::answerAtGoogle).
                if (self::answerResetsChoice($current, $changed)) {
                    $changed['attendance'] = 'none';
                }
                if ($changed !== []) {
                    // A new address means the old coordinates are wrong;
                    // the geocode sweep re-resolves within the minute.
                    if (array_key_exists('location', $changed)) {
                        $changed['location_lat'] = null;
                        $changed['location_lng'] = null;
                        $changed['geocoded_at'] = null;
                        $changed['location_source'] = null;
                        $changed['location_provider'] = null;
                        $changed['location_placed_at'] = null;
                    }
                    // What a person would undo stands on, by the one reading (Undo::touchesOf).
                    $touches = array_merge($touches, Undo::touchesOf(['events' => [$current]], ['events' => [array_merge($current, $changed)]]));
                    $changed['updated_at'] = Time::nowDb();
                    if ($etag !== null && $etag !== ($current['google_etag'] ?? null)) {
                        $changed['google_etag'] = $etag;
                    }
                    $this->db->update('events', $changed, 'id = ?', [(int) $current['id']]);
                    $changedUids[(string) $current['uid']] = true;
                    $updatedEventIds[] = (int) $current['id'];
                    $upserts++;
                } elseif ($etag !== null && $etag !== ($current['google_etag'] ?? null)) {
                    // Only the etag: stored quietly, the row's updated_at
                    // (and so its CalDAV ETag) left as it was.
                    $this->db->run('UPDATE events SET google_etag = ?, updated_at = updated_at WHERE id = ?', [$etag, (int) $current['id']]);
                }
            }

            // Rows the feed no longer carries go, and every edited day of a
            // series the feed still has is brought up to its series by one
            // rule, whoever made its row (created_via is provenance only;
            // series-model.md, Rows from Elsewhere; decisions.md, "A feed's
            // change to a day keeps what is held here"):
            //
            // While the feed's record for the day holds something of its own
            // (a field: title, place text, notes, link, status, date, time,
            // length, all-day; or a guest list of its own), the record decides
            // those, as stored above. Otherwise (no record, a record equal to
            // its series, or a delta's stored copy, which says nothing new)
            // the row keeps exactly what is held here alone (heldHere: tags,
            // people, reminders, an answer, an icon, picked coordinates, and
            // what refers to it) and takes the series' values for everything
            // else (ofSeries), its key moving to its image. Then:
            //  - the series no longer has its day: the row goes, named in
            //    Activity with what it held;
            //  - another row already holds its image (the feed's record of
            //    that day, a row that stays, or another row moving there):
            //    what it holds goes to that row and it goes, named as kept
            //    on that day's row;
            //  - it holds nothing: it is an ordinary occurrence again, no row.
            // A whole read and a delta read alike.
            $removeIndex = 0;
            $newMasters = [];
            $droppedDays = [];
            $drop = [];   // id => the row, gone with nothing named unless $deleted names it
            $deleted = []; // id => [what it held, what refers to it]: an event or series no longer in the calendar
            $gone = [];   // id => [the row, what it held]: its day is gone
            $follow = []; // id => [the row, the row as it follows, what it holds, what refers to it]
            $updated = array_flip($updatedEventIds);
            foreach ($existingByKey as $key => $row) {
                if (($removeIndex++ & 127) === 0) {
                    self::assertSyncDeadline($deadline);
                }
                if ($unknownSkipped || isset($protected[$row['uid']])) {
                    continue;
                }
                $id = (int) $row['id'];
                $uid = (string) $row['uid'];
                $carried = isset($seen[$key]) && !isset($plain[$key]);
                $seriesSeen = $row['recurrence_parent_id'] !== null && isset($seen[$uid . '|']) && isset($masterIdByUid[$uid]);
                if (!$seriesSeen || $row['deleted_at'] !== null) {
                    if (!$carried) {
                        $drop[$id] = $row;
                        // An event or a series no longer in the calendar:
                        // what it held here alone is named (A5).
                        if ($row['deleted_at'] === null) {
                            $master = $row['recurrence_parent_id'] !== null ? ($existingByKey[$uid . '|'] ?? null) : null;
                            $held = $master !== null ? self::heldHere($this->ownOf($row, $master), $row) : ($row['recurrence_parent_id'] === null ? $this->heldByEvent($row) : []);
                            $refs = \BetterCal\Domain\Series\OwnFields::references($this->db, [$id])[$id] ?? [];
                            if ($held !== [] || $refs !== []) {
                                $deleted[$id] = [$held, $refs];
                            }
                        }
                    }
                    continue;
                }
                // As the loop above left it: a record the feed carries may
                // have changed it.
                $now = isset($updated[$id]) ? $this->db->one('SELECT * FROM events WHERE id = ?', [$id]) : $row;
                $newMasters[$uid] ??= $this->db->one('SELECT * FROM events WHERE id = ?', [$masterIdByUid[$uid]]);
                $series = $newMasters[$uid];
                if ($now === null || $series === null) {
                    continue;
                }
                $was = $existingByKey[$uid . '|'] ?? null;
                $own = $this->ownOf($now, $was ?? $series);
                if ($carried && self::recordHolds($own, $now, $series, $was)) {
                    continue;
                }
                $target = self::ofSeries($now, $was, $series);
                $after = $target === null ? $now : self::followed($now, $target);
                $kept = self::retained($this->db, $after, $own);
                if ($target === null) {
                    $gone[$id] = [$now, $kept['fields'] ?? [], $kept['refs'] ?? []];
                    continue;
                }
                if ($kept === null) {
                    $drop[$id] = $now;
                    continue;
                }
                $follow[$id] = [$now, $after, $kept['fields'], $kept['refs']];
            }
            self::assertSyncDeadline($deadline);

            // Who holds each day after the poll: every row left where it is,
            // then each following row at its image, the first to claim a day
            // holding it (a row whose image is its own key first, then by id);
            // a later one merges into the holder.
            $merge = []; // id => the holder's id
            if ($follow !== []) {
                $holderAt = [];
                foreach ($this->db->all('SELECT id, uid, recurrence_instance_utc FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NOT NULL', [$calendarId]) as $r) {
                    $rid = (int) $r['id'];
                    if (!isset($follow[$rid]) && !isset($drop[$rid]) && !isset($gone[$rid])) {
                        $holderAt[$r['uid'] . '|' . $r['recurrence_instance_utc']] = $rid;
                    }
                }
                foreach ([true, false] as $inPlace) {
                    foreach ($follow as $id => [$now, $after]) {
                        if (((string) $after['recurrence_instance_utc'] === (string) $now['recurrence_instance_utc']) !== $inPlace) {
                            continue;
                        }
                        $at = $now['uid'] . '|' . $after['recurrence_instance_utc'];
                        if (isset($holderAt[$at])) {
                            $merge[$id] = $holderAt[$at];
                        } else {
                            $holderAt[$at] = $id;
                        }
                    }
                }
            }

            // Keys are unique per calendar: a row moving onto a key another
            // row leaves takes it once that row has let it go.
            foreach ($follow as $id => [$now, $after]) {
                if (isset($merge[$id]) || (string) $after['recurrence_instance_utc'] !== (string) $now['recurrence_instance_utc']) {
                    $this->db->run('UPDATE events SET recurrence_instance_utc = NULL WHERE id = ?', [$id]);
                }
            }
            foreach ($drop + array_map(static fn(array $g): array => $g[0], $gone) as $id => $row) {
                if (isset($gone[$id]) && ($gone[$id][1] !== [] || $gone[$id][2] !== [])) {
                    $droppedDays[] = self::droppedDay($row, $gone[$id][1], $gone[$id][2], 'gone');
                } elseif (isset($deleted[$id])) {
                    $droppedDays[] = self::droppedDay($row, $deleted[$id][0], $deleted[$id][1], 'deleted');
                }
                $touches = array_merge($touches, Undo::touchesOf(Undo::removedRows($this->db, [$row]), null));
                $this->db->run('DELETE FROM events WHERE id = ?', [$id]);
                $changedUids[(string) $row['uid']] = true;
                $removedCount++;
            }
            foreach ($follow as $id => [$now, $after, $held]) {
                if (isset($merge[$id])) {
                    continue;
                }
                $changed = [];
                foreach ($after as $col => $value) {
                    if (array_key_exists($col, $now) && !self::sameValue($col, $now[$col], $value)) {
                        $changed[$col] = $value;
                    }
                }
                if ($ownColumn) {
                    $list = \BetterCal\Domain\Series\OwnFields::encode($held);
                    if (!self::sameValue('override_fields', $now['override_fields'] ?? null, $list)) {
                        $changed['override_fields'] = $list;
                    }
                }
                $moved = (string) $after['recurrence_instance_utc'] !== (string) $now['recurrence_instance_utc'];
                if ($changed === [] && !$moved) {
                    continue;
                }
                if ($moved) {
                    $changed['recurrence_instance_utc'] = $after['recurrence_instance_utc'];
                }
                $touches = array_merge($touches, Undo::touchesOf(['events' => [$now]], ['events' => [array_merge($now, $changed)]]));
                $changed['updated_at'] = Time::nowDb();
                $this->db->update('events', $changed, 'id = ?', [$id]);
                $changedUids[(string) $now['uid']] = true;
                if (!in_array($id, $updatedEventIds, true)) {
                    $updatedEventIds[] = $id;
                    $upserts++;
                }
            }
            foreach ($merge as $id => $holderId) {
                [$now, $after, $held, $refs] = $follow[$id];
                $before = Undo::removedRows($this->db, [$this->db->one('SELECT * FROM events WHERE id = ?', [$holderId])]);
                $this->mergeInto($after + ['id' => $id], $holderId, $held, $newMasters[(string) $now['uid']] ?? null, $ownColumn);
                $touches = array_merge($touches, Undo::touchesOf($before, Undo::removedRows($this->db, [$this->db->one('SELECT * FROM events WHERE id = ?', [$holderId])])));
                $droppedDays[] = self::droppedDay($now, $held, $refs, 'merged');
                $touches = array_merge($touches, Undo::touchesOf(Undo::removedRows($this->db, [$now]), null));
                $this->db->run('DELETE FROM events WHERE id = ?', [$id]);
                if (!in_array($holderId, $updatedEventIds, true)) {
                    $updatedEventIds[] = $holderId;
                    $upserts++;
                }
                $changedUids[(string) $now['uid']] = true;
                $removedCount++;
            }
            self::assertSyncDeadline($deadline);

            // Content changed: cached prompt-filter verdicts are stale; drop
            // them so the pending sweep re-evaluates (removed events cascade).
            if ($updatedEventIds !== []) {
                [$in, $inParams] = Db::in($updatedEventIds);
                $this->db->run("DELETE FROM filter_evals WHERE event_id IN $in", $inParams);
            }
            $this->dropped = array_merge($this->dropped, $droppedDays);
            // Activity: one roll-up entry per poll that changed anything
            // (log only; the next poll would redo an undo, so none is
            // offered). A write's own copy of Google's answer (journal
            // false) is the write's entry.
            $parts = array_filter([
                $addedTitles !== [] ? count($addedTitles) . ' added' : null,
                $updatedEventIds !== [] ? count($updatedEventIds) . ' updated' : null,
                $removedCount > 0 ? $removedCount . ' removed' . ($droppedDays !== [] ? ' (' . self::droppedWords($droppedDays) . ')' : '') : null,
            ]);
            // A Google calendar's poll is Google's change, and says so.
            $fromGoogle = ($calendar['provider'] ?? 'ics') === 'google';
            if ($journal && $parts !== []) {
                ActivityContext::with($fromGoogle ? 'google' : 'feed', fn() => $undo->record(
                    $userId, 'calendar', $calendarId, 'update', null, null,
                    ($fromGoogle ? "From Google calendar '" : "Feed '") . (string) $calendar['name'] . "': " . implode(', ', $parts),
                    ['addedTitles' => array_slice($addedTitles, 0, 20)] + ($droppedDays !== [] ? ['removedDays' => $droppedDays] : []),
                    touches: $touches
                ));
            }

            // CalDAV change journal: one entry per affected object (uid), in
            // the poll's own transaction, so the poll and what it tells
            // CalDAV clients commit or fail together.
            foreach (array_keys($changedUids) as $index => $uid) {
                if (($index & 127) === 0) {
                    self::assertSyncDeadline($deadline);
                }
                $uid = (string) $uid;
                if (isset($newMasterUids[$uid])) {
                    ChangeLog::record($this->db, $calendarId, $uid, ChangeLog::OP_ADD);
                    continue;
                }
                $masterExists = $this->db->scalar(
                    'SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND deleted_at IS NULL
                       AND recurrence_parent_id IS NULL AND recurrence_instance_utc IS NULL',
                    [$calendarId, $uid]
                );
                ChangeLog::record($this->db, $calendarId, $uid, $masterExists !== null ? ChangeLog::OP_MODIFY : ChangeLog::OP_DELETE);
            }
        });

        // The publisher touched something. This is what "stale" is judged
        // against: not whether the feed HAS events (a dead feed keeps
        // serving its old ones forever) but when it last changed any.
        if (count($addedTitles) + count($updatedEventIds) + $removedCount > 0) {
            RequestAuthority::checkedTx($this->db, fn() => $this->db->update('calendars', ['content_changed_at' => Time::nowDb()], 'id = ?', [$calendarId]));
        }

        return $upserts;
    }

    private static function assertSyncDeadline(?float $deadline): void
    {
        if ($deadline !== null && microtime(true) >= $deadline) {
            throw new \RuntimeException('Google calendar synchronization exceeded its elapsed-time safety limit; no changes were applied.');
        }
    }

    private function recordStats(int $calendarId, int $rawCount): void
    {
        $this->db->run(
            'INSERT INTO feed_stats (calendar_id, poll_date, raw_count, passing_count) VALUES (?, CURDATE(), ?, ?)
             ON DUPLICATE KEY UPDATE raw_count = VALUES(raw_count), passing_count = VALUES(passing_count)',
            [$calendarId, $rawCount, $rawCount]
        );
    }
}
