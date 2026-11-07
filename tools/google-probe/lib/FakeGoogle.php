<?php
// A stand-in for the Calendar API, for test.php only: enough of it to prove
// the probe's own plumbing (ids, instance ids, etags that change on every
// write, If-Match, sync tokens, cancelled instances, paging, a 404 while a
// new event isn't readable yet). It models none of Google's series
// behaviour that the probe is there to find out: a rule is expanded
// crudely (FREQ, INTERVAL, COUNT, UNTIL; every BY part ignored) and an
// exception record is simply kept. Never a source of findings.
declare(strict_types=1);

namespace BetterCal\GoogleProbe;

final class FakeGoogle
{
    /** @var array<string,array> masters, single events and exception records by id */
    public array $events = [];
    /** @var list<array{method:string, path:string, query:array, headers:array}> */
    public array $requests = [];
    /** Each new event answers 404 to this many GETs before it is readable. */
    public int $lagGets = 0;
    /** The request with this number (1-based) answers 401 once. */
    public ?int $unauthorizedAt = null;
    /** After this many requests the network is down. */
    public ?int $failAfter = null;
    public bool $primary = false;
    public string $role = 'owner';
    public string $zone = 'America/Los_Angeles';
    public string $owner = 'owner@example.com';

    private int $seq = 0;
    private int $etags = 0;
    private int $ids = 0;
    /** @var array<string,int> id => change sequence */
    private array $changed = [];
    /** @var array<string,int> id => GETs left that answer 404 */
    private array $lagLeft = [];

    public function __construct(public readonly string $calendarId)
    {
    }

    public function transport(): \Closure
    {
        return fn(string $method, string $url, array $headers, ?array $payload): array => $this->handle($method, $url, $headers, $payload);
    }

    /** An event put straight into the store, as if made elsewhere. */
    public function seed(array $resource): void
    {
        $id = (string) $resource['id'];
        $this->events[$id] = $resource + ['iCalUID' => $id . '@google.com', 'etag' => $this->etag(), 'status' => 'confirmed'];
        $this->touch($id);
    }

    private function handle(string $method, string $url, array $headers, ?array $payload): array
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $path = substr((string) $parts['path'], strlen('/calendar/v3'));
        $this->requests[] = ['method' => $method, 'path' => $path, 'query' => $query, 'headers' => $headers];
        $n = count($this->requests);
        if ($this->failAfter !== null && $n > $this->failAfter) {
            throw new \RuntimeException('HTTP error for the fake: network down');
        }
        $auth = array_values(array_filter($headers, static fn(string $h) => str_starts_with($h, 'Authorization: Bearer fake-token')));
        if ($auth === [] || $n === $this->unauthorizedAt) {
            return self::error(401, 'Invalid Credentials');
        }
        if ($path === '/users/me/calendarList/' . rawurlencode($this->calendarId)) {
            return self::ok(['kind' => 'calendar#calendarListEntry', 'id' => $this->calendarId, 'summary' => 'Probe calendar', 'accessRole' => $this->role, 'primary' => $this->primary]);
        }
        $prefix = '/calendars/' . rawurlencode($this->calendarId);
        if (!str_starts_with($path, $prefix)) {
            return self::error(404, 'Not Found');
        }
        $rest = substr($path, strlen($prefix));
        $ifMatch = null;
        foreach ($headers as $h) {
            if (stripos($h, 'If-Match:') === 0) {
                $ifMatch = trim(substr($h, 9));
            }
        }
        if ($rest === '') {
            return self::ok(['kind' => 'calendar#calendar', 'id' => $this->calendarId, 'summary' => 'Probe calendar', 'timeZone' => $this->zone]);
        }
        if ($rest === '/events') {
            return $method === 'POST' ? $this->insert($payload ?? []) : $this->list($query);
        }
        if (preg_match('#^/events/([^/]+)/instances$#', $rest, $m) === 1) {
            return $this->instancesList(rawurldecode($m[1]), $query);
        }
        if (preg_match('#^/events/([^/]+)$#', $rest, $m) === 1) {
            $id = rawurldecode($m[1]);
            return match ($method) {
                'GET' => $this->get($id),
                'PATCH' => $this->patch($id, $payload ?? [], $ifMatch),
                'DELETE' => $this->delete($id, $ifMatch),
                default => self::error(405, 'Method not allowed'),
            };
        }
        return self::error(404, 'Not Found');
    }

    private function insert(array $body): array
    {
        foreach ($body['recurrence'] ?? [] as $line) {
            if (preg_match('/^(RRULE|EXDATE|RDATE)[:;]/', (string) $line) !== 1) {
                return self::error(400, 'Invalid recurrence rule.');
            }
        }
        if (!isset($body['start'], $body['end'])) {
            return self::error(400, 'Missing time.');
        }
        $id = isset($body['id']) ? (string) $body['id'] : 'fake' . str_pad((string) ++$this->ids, 6, '0', STR_PAD_LEFT);
        if (preg_match('/^[a-v0-9]{5,1024}$/', $id) !== 1) {
            return self::error(400, 'Invalid resource id value.');
        }
        if (isset($this->events[$id])) {
            return self::error(409, 'The requested identifier already exists.');
        }
        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $this->events[$id] = $body + [
            'kind' => 'calendar#event',
            'id' => $id,
            'iCalUID' => $id . '@google.com',
            'status' => 'confirmed',
            'htmlLink' => 'https://www.google.com/calendar/event?eid=' . base64_encode($id . ' ' . $this->calendarId),
            'created' => $now,
            'updated' => $now,
            'sequence' => 0,
            'organizer' => ['email' => $this->calendarId, 'displayName' => 'Probe calendar', 'self' => true],
            'creator' => ['email' => $this->owner],
        ];
        $this->events[$id]['etag'] = $this->etag();
        $this->lagLeft[$id] = $this->lagGets;
        $this->touch($id);
        return self::ok($this->events[$id]);
    }

    private function get(string $id): array
    {
        if (($this->lagLeft[$id] ?? 0) > 0) {
            $this->lagLeft[$id]--;
            return self::error(404, 'Not Found');
        }
        $e = $this->find($id);
        return $e === null ? self::error(404, 'Not Found') : self::ok($e);
    }

    private function patch(string $id, array $body, ?string $ifMatch): array
    {
        $e = $this->find($id);
        if ($e === null) {
            return self::error(404, 'Not Found');
        }
        if ($ifMatch !== null && $ifMatch !== '*' && $ifMatch !== $e['etag']) {
            return self::error(412, 'Precondition Failed');
        }
        $e = array_replace($e, $body);
        if (!empty($e['recurringEventId'])) {
            unset($e['recurrence']); // an instance never carries a rule
        }
        $e['etag'] = $this->etag();
        $e['updated'] = gmdate('Y-m-d\TH:i:s.000\Z');
        $this->events[$id] = $e;
        $this->touch($id);
        return self::ok($e);
    }

    private function delete(string $id, ?string $ifMatch): array
    {
        $e = $this->find($id);
        if ($e === null) {
            return self::error(404, 'Not Found');
        }
        if ($ifMatch !== null && $ifMatch !== '*' && $ifMatch !== $e['etag']) {
            return self::error(412, 'Precondition Failed');
        }
        if (($e['status'] ?? '') === 'cancelled') {
            return self::error(410, 'Resource has been deleted');
        }
        $e['status'] = 'cancelled';
        $e['etag'] = $this->etag();
        $this->events[$id] = $e;
        $this->touch($id);
        return ['status' => 204, 'body' => ''];
    }

    private function list(array $q): array
    {
        $single = ($q['singleEvents'] ?? 'false') === 'true';
        $deleted = ($q['showDeleted'] ?? 'false') === 'true';
        if (isset($q['syncToken'])) {
            if (preg_match('/^fake-sync-(\d+)$/', (string) $q['syncToken'], $m) !== 1) {
                return self::error(410, 'Sync token is no longer valid, a full sync is required.');
            }
            $since = (int) $m[1];
            $items = [];
            foreach ($this->events as $id => $e) {
                if (($this->changed[$id] ?? 0) > $since) {
                    $items = array_merge($items, $single && !empty($e['recurrence']) ? $this->instances($e, true) : [$e]);
                }
            }
            return $this->page($items, $q);
        }
        $items = [];
        foreach ($this->events as $e) {
            if (isset($q['iCalUID']) && ($e['iCalUID'] ?? '') !== $q['iCalUID']) {
                continue;
            }
            if ($single) {
                if (!empty($e['recurringEventId'])) {
                    continue; // comes in through its master's instances
                }
                if (!empty($e['recurrence'])) {
                    if (($e['status'] ?? '') !== 'cancelled') {
                        $items = array_merge($items, $this->instances($e, $deleted));
                    }
                    continue;
                }
            }
            if (!$deleted && ($e['status'] ?? '') === 'cancelled') {
                continue;
            }
            $items[] = $e;
        }
        return $this->page($items, $q);
    }

    private function instancesList(string $id, array $q): array
    {
        $master = $this->events[$id] ?? null;
        if ($master === null || empty($master['recurrence'])) {
            return self::error(404, 'Not Found');
        }
        return $this->page($this->instances($master, ($q['showDeleted'] ?? 'false') === 'true'), $q);
    }

    private function page(array $items, array $q): array
    {
        $size = min(2500, (int) ($q['maxResults'] ?? 250));
        $offset = (int) ($q['pageToken'] ?? 0);
        $slice = array_slice($items, $offset, $size);
        $out = ['kind' => 'calendar#events', 'timeZone' => $this->zone, 'items' => $slice];
        if ($offset + $size < count($items)) {
            $out['nextPageToken'] = (string) ($offset + $size);
        } else {
            $out['nextSyncToken'] = 'fake-sync-' . $this->seq;
        }
        return self::ok($out);
    }

    /** A stored event, or an occurrence of a stored series made on the fly. */
    private function find(string $id): ?array
    {
        if (isset($this->events[$id])) {
            return $this->events[$id];
        }
        $cut = strrpos($id, '_');
        if ($cut === false || !isset($this->events[substr($id, 0, $cut)])) {
            return null;
        }
        foreach ($this->generate($this->events[substr($id, 0, $cut)]) as $inst) {
            if ($inst['id'] === $id) {
                return $inst;
            }
        }
        return null;
    }

    /** @return list<array> the occurrences with exception records laid over them */
    private function instances(array $master, bool $deleted): array
    {
        $out = [];
        $seen = [];
        foreach ($this->generate($master) as $inst) {
            $e = $this->events[$inst['id']] ?? $inst;
            $seen[$inst['id']] = true;
            if ($deleted || ($e['status'] ?? '') !== 'cancelled') {
                $out[] = $e;
            }
        }
        foreach ($this->events as $id => $e) {
            if (($e['recurringEventId'] ?? '') === $master['id'] && !isset($seen[$id]) && ($deleted || ($e['status'] ?? '') !== 'cancelled')) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** @return list<array> */
    private function generate(array $master): array
    {
        $rule = null;
        foreach ($master['recurrence'] ?? [] as $line) {
            if (str_starts_with((string) $line, 'RRULE:')) {
                parse_str(str_replace(';', '&', substr((string) $line, 6)), $rule);
            }
        }
        if ($rule === null) {
            return [];
        }
        $allDay = isset($master['start']['date']);
        $tz = new \DateTimeZone((string) ($master['start']['timeZone'] ?? $this->zone));
        $start = $allDay ? new \DateTimeImmutable($master['start']['date'], $tz) : (new \DateTimeImmutable($master['start']['dateTime']))->setTimezone($tz);
        $end = $allDay ? new \DateTimeImmutable($master['end']['date'], $tz) : (new \DateTimeImmutable($master['end']['dateTime']))->setTimezone($tz);
        $length = $end->getTimestamp() - $start->getTimestamp();
        $unit = ['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year', 'HOURLY' => 'hour'][$rule['FREQ'] ?? 'DAILY'] ?? 'day';
        $interval = max(1, (int) ($rule['INTERVAL'] ?? 1));
        $count = min(730, (int) ($rule['COUNT'] ?? 730));
        $until = null;
        if (isset($rule['UNTIL'])) {
            $u = (string) $rule['UNTIL'];
            $until = str_ends_with($u, 'Z') ? new \DateTimeImmutable($u) : new \DateTimeImmutable(strlen($u) === 8 ? $u . 'T235959' : $u, $tz);
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $s = $start->modify('+' . ($i * $interval) . ' ' . $unit);
            if ($until !== null && $s > $until) {
                break;
            }
            $e = $s->modify('+' . $length . ' seconds');
            $when = $allDay
                ? [['date' => $s->format('Y-m-d')], ['date' => $e->format('Y-m-d')]]
                : [['dateTime' => $s->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz->getName()], ['dateTime' => $e->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz->getName()]];
            $id = $master['id'] . '_' . ($allDay ? $s->format('Ymd') : $s->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
            $inst = $master;
            unset($inst['recurrence']);
            $out[] = array_replace($inst, [
                'id' => $id,
                'recurringEventId' => $master['id'],
                'originalStartTime' => $when[0],
                'start' => $when[0],
                'end' => $when[1],
            ]);
        }
        return $out;
    }

    private function etag(): string
    {
        return '"' . (3000000000000000 + ++$this->etags) . '"';
    }

    private function touch(string $id): void
    {
        $this->changed[$id] = ++$this->seq;
    }

    private static function ok(array $body): array
    {
        return ['status' => 200, 'body' => (string) json_encode($body, JSON_UNESCAPED_SLASHES)];
    }

    private static function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => (string) json_encode(['error' => ['code' => $status, 'message' => $message, 'errors' => [['reason' => 'fake', 'message' => $message]]]])];
    }
}
