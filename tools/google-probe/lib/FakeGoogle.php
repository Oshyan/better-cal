<?php
// A stand-in for the Calendar API, behaving as the probe of 2026-11-07
// found Google behaves (docs/design/research/google-series/probe-2026-11-07.md,
// and the model's "Google, as a Contract"): ids and instance ids, one etag
// per series that changes on every write to any of its rows, If-Match with
// 412 when stale, sync tokens whose delta lists every row a master change
// touched, cancelled instances as records, a dropped exception as a
// cancelled item with no originalStartTime, the four kinds of master change
// that drop every exception (clock, zone, start day, rule form), a field or
// status change written into every exception, a length change followed by
// every exception's end, a bound change keeping them, the split's trimmed
// series losing the exceptions past its end, an insert with a chosen id and
// 409 on the same id again, 400 for a local-time UNTIL, HOURLY, and a clock
// time on an all-day series' instance, and a GET that can lag.
//
// Occurrences come from $expand when the caller gives one (the harness's
// own evaluator; the database suite's tests the product's expander, since
// what is checked there is the carrying, not the expansion), else from a
// built-in reading of FREQ, INTERVAL, COUNT, UNTIL and a weekly BYDAY.
// Used by test.php (the probe's own plumbing), the database suite and the
// edit harness. Never a source of findings about Google itself.
declare(strict_types=1);

namespace BetterCal\GoogleProbe;

final class FakeGoogle
{
    /** @var array<string,array> masters, single events, exception records and tombstones by id */
    public array $events = [];
    /** @var list<array{method:string, path:string, query:array, headers:array, body:?array}> */
    public array $requests = [];
    /** Each new event answers 404 to this many GETs before it is readable. */
    public int $lagGets = 0;
    /** The request with this number (1-based) answers 401 once. */
    public ?int $unauthorizedAt = null;
    /** After this many requests the network is down. */
    public ?int $failAfter = null;
    /** The request with this number is handled, and its answer is lost on the way back (the network drops it once). */
    public ?int $loseAnswerAt = null;
    public bool $primary = false;
    public string $role = 'owner';
    public string $zone = 'America/Los_Angeles';
    public string $owner = 'owner@example.com';
    /** The bearer token accepted; null accepts any. */
    public ?string $token = 'fake-token';
    /** fn(array $master): list<\DateTimeImmutable>, the starts of a master's occurrences in its zone, 730 at most; null uses the built-in reading. */
    public ?\Closure $expand = null;

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

    /** The probe's Api transport: (method, url, headers, payload). */
    public function transport(): \Closure
    {
        return fn(string $method, string $url, array $headers, ?array $payload): array => $this->handle($method, $url, $headers, $payload);
    }

    /** GoogleWriter's jsonTransport: (method, url, payload, headers). */
    public function writerTransport(): \Closure
    {
        return fn(string $method, string $url, ?array $payload, array $headers = []): array => $this->handle($method, $url, $headers, $payload);
    }

    /** GoogleSync's transport: (url, headers), a GET. */
    public function syncTransport(): \Closure
    {
        return fn(string $url, array $headers): array => $this->handle('GET', $url, $headers, null);
    }

    /** An event put straight into the store, as if made elsewhere. */
    public function seed(array $resource): void
    {
        $id = (string) $resource['id'];
        $this->events[$id] = $resource + ['iCalUID' => $id . '@google.com', 'status' => 'confirmed'];
        $this->bump($this->seriesIdOf($id));
    }

    /** The exception records (cancelled ones included) of a master, by originalStartTime key. @return array<string,array> */
    public function records(string $masterId): array
    {
        $out = [];
        foreach ($this->events as $id => $e) {
            if (($e['recurringEventId'] ?? '') === $masterId && isset($e['originalStartTime'])) {
                $out[self::keyOf($e['originalStartTime'], $e['start']['timeZone'] ?? $this->zone)] = $e;
            }
        }
        ksort($out);
        return $out;
    }

    private function handle(string $method, string $url, array $headers, ?array $payload): array
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $path = substr((string) $parts['path'], strlen('/calendar/v3'));
        $this->requests[] = ['method' => $method, 'path' => $path, 'query' => $query, 'headers' => $headers, 'body' => $payload];
        $n = count($this->requests);
        if ($this->failAfter !== null && $n > $this->failAfter) {
            throw new \RuntimeException('HTTP error for the fake: network down');
        }
        $auth = array_values(array_filter($headers, fn(string $h) => str_starts_with($h, 'Authorization: Bearer ' . ($this->token ?? ''))));
        if ($auth === [] || $n === $this->unauthorizedAt) {
            return self::error(401, 'Invalid Credentials');
        }
        $answer = $this->route($method, $path, $query, $headers, $payload);
        if ($n === $this->loseAnswerAt) {
            throw new \RuntimeException('HTTP error for the fake: the answer was lost');
        }
        return $answer;
    }

    private function route(string $method, string $path, array $query, array $headers, ?array $payload): array
    {
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

    // ---- what Google refuses ------------------------------------------------

    /** The 400 a recurrence gets, or null: a line that isn't a rule, a local-time UNTIL, HOURLY (probe, group 1). */
    private static function recurrenceError(array $lines, bool $allDay): ?string
    {
        foreach ($lines as $line) {
            $line = (string) $line;
            if (preg_match('/^(RRULE|EXDATE|RDATE|EXRULE)[:;]/', $line) !== 1) {
                return 'Invalid recurrence rule.';
            }
            if (str_starts_with($line, 'RRULE:')) {
                parse_str(str_replace(';', '&', substr($line, 6)), $rule);
                if (($rule['FREQ'] ?? '') === 'HOURLY' || ($rule['FREQ'] ?? '') === 'MINUTELY' || ($rule['FREQ'] ?? '') === 'SECONDLY') {
                    return 'Invalid recurrence rule.';
                }
                $until = (string) ($rule['UNTIL'] ?? '');
                if ($until !== '' && preg_match($allDay ? '/^\d{8}$/' : '/^\d{8}T\d{6}Z$/', $until) !== 1) {
                    return 'Invalid recurrence rule.';
                }
            }
        }
        return null;
    }

    private function insert(array $body): array
    {
        if (!isset($body['start'], $body['end'])) {
            return self::error(400, 'Missing time.');
        }
        $why = self::recurrenceError((array) ($body['recurrence'] ?? []), isset($body['start']['date']));
        if ($why !== null) {
            return self::error(400, $why);
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
        $this->lagLeft[$id] = $this->lagGets;
        $this->bump($id);
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
        $seriesId = $this->seriesIdOf($id);
        if (!empty($e['recurrence'])) {
            return $this->patchMaster($e, $body);
        }
        $master = $seriesId !== $id ? ($this->events[$seriesId] ?? null) : null;
        if ($master !== null) {
            // An instance of an all-day series can't be given a clock time (probe 2.2a).
            if (isset($master['start']['date']) && isset($body['start']['dateTime'])) {
                return self::error(400, 'Invalid start time.');
            }
            unset($body['recurrence']); // an instance never carries a rule
        } elseif (isset($body['recurrence'])) {
            $why = self::recurrenceError((array) $body['recurrence'], isset(($body['start'] ?? $e['start'])['date']));
            if ($why !== null) {
                return self::error(400, $why);
            }
        }
        $e = array_replace($e, $body);
        $e['updated'] = gmdate('Y-m-d\TH:i:s.000\Z');
        $this->events[$id] = $e;
        $this->bump($seriesId);
        return self::ok($this->events[$id]);
    }

    /**
     * A master changed: what Google does to its exception records (probe,
     * group 3). A record is kept only while its originalStartTime is an
     * occurrence of the master as changed (so a clock, zone, start-day or
     * pattern change drops them all, a bound change drops those past the
     * end) and while no EXDATE line names it (3.16). A kept record follows a
     * changed start onto the pattern's time (3.5), takes a field or status
     * set on the master (3.12 to 3.15, a cancelled record un-cancelled by a
     * status), and its end follows a changed length (3.2); a pattern change
     * drops the cancelled records it would keep (3.7). A dropped record
     * stays readable as a cancelled item with no originalStartTime, which
     * the next delta lists (3.1).
     */
    private function patchMaster(array $old, array $body): array
    {
        $new = array_replace($old, $body);
        $allDay = isset($new['start']['date']);
        if (isset($body['recurrence'])) {
            $why = self::recurrenceError((array) $body['recurrence'], $allDay);
            if ($why !== null) {
                return self::error(400, $why);
            }
        }
        $id = (string) $old['id'];
        $oldLen = self::lengthOf($old);
        $newLen = self::lengthOf($new);
        $startChanged = json_encode($old['start']) !== json_encode($new['start']);
        $patternChanged = self::pattern($old) !== self::pattern($new);
        $fields = array_intersect_key($body, array_flip(['summary', 'description', 'location', 'status']));
        $exdates = self::exdateKeys($new);
        $occ = [];
        foreach ($this->occurrences($new) as $start) {
            $occ[self::keyOfStart($start, $allDay)] = $start;
        }
        foreach ($this->events as $rid => $r) {
            if (($r['recurringEventId'] ?? '') !== $id || !isset($r['originalStartTime'])) {
                continue;
            }
            $key = self::keyOf($r['originalStartTime'], $r['start']['timeZone'] ?? $this->zone);
            $cancelled = ($r['status'] ?? '') === 'cancelled';
            if (!isset($occ[$key]) || isset($exdates[$key]) || ($patternChanged && $cancelled)) {
                // Gone: a cancelled item under its id, no originalStartTime.
                unset($r['recurringEventId'], $r['originalStartTime']);
                $r['status'] = 'cancelled';
                $r['sequence'] = (int) ($r['sequence'] ?? 0) + 1;
                $this->events[$rid] = $r;
                continue;
            }
            if ($startChanged) {
                [$r['start'], $r['end']] = $this->span($occ[$key], $newLen, $allDay, (string) ($new['start']['timeZone'] ?? $this->zone));
            } elseif ($newLen !== $oldLen) {
                // In the record's own form: an all-day day of a timed series keeps whole days.
                $r['end'] = $this->endOf($r['start'], $newLen, isset($r['start']['date']));
            }
            foreach ($fields as $k => $v) {
                $r[$k] = $v;
            }
            $this->events[$rid] = $r;
        }
        if ($startChanged || $patternChanged || isset($fields['status'])) {
            $new['sequence'] = (int) ($old['sequence'] ?? 0) + 1;
        }
        $new['updated'] = gmdate('Y-m-d\TH:i:s.000\Z');
        $this->events[$id] = $new;
        $this->bump($id);
        return self::ok($this->events[$id]);
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
        $this->events[$id] = $e;
        $this->bump($this->seriesIdOf($id));
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

    /**
     * A stored event, or an occurrence of a stored series made on the fly.
     * An instance id names the master's occurrence: a dropped record left
     * under it (a cancelled item with no originalStartTime) is no longer
     * what the id addresses.
     */
    private function find(string $id): ?array
    {
        $masterId = $this->seriesIdOf($id);
        if (isset($this->events[$id]) && ($masterId === $id || isset($this->events[$id]['originalStartTime']))) {
            return $this->events[$id];
        }
        if ($masterId === $id || !isset($this->events[$masterId])) {
            return null;
        }
        foreach ($this->generate($this->events[$masterId]) as $inst) {
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

    /** @return list<array> every occurrence of a master as an instance resource */
    private function generate(array $master): array
    {
        $allDay = isset($master['start']['date']);
        $tz = (string) ($master['start']['timeZone'] ?? $this->zone);
        $length = self::lengthOf($master);
        $exdates = self::exdateKeys($master);
        $out = [];
        foreach ($this->occurrences($master) as $s) {
            $key = self::keyOfStart($s, $allDay);
            if (isset($exdates[$key])) {
                continue;
            }
            [$start, $end] = $this->span($s, $length, $allDay, $tz);
            $inst = $master;
            unset($inst['recurrence']);
            $out[] = array_replace($inst, [
                'id' => $master['id'] . '_' . $key,
                'recurringEventId' => $master['id'],
                'originalStartTime' => $start,
                'start' => $start,
                'end' => $end,
            ]);
        }
        return $out;
    }

    /** @return list<\DateTimeImmutable> starts in the master's zone */
    private function occurrences(array $master): array
    {
        if ($this->expand !== null) {
            return array_slice(($this->expand)($master), 0, 730);
        }
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
        $unit = ['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year'][$rule['FREQ'] ?? 'DAILY'] ?? 'day';
        $interval = max(1, (int) ($rule['INTERVAL'] ?? 1));
        $count = min(730, (int) ($rule['COUNT'] ?? 730));
        $until = null;
        if (isset($rule['UNTIL'])) {
            $u = (string) $rule['UNTIL'];
            $until = str_ends_with($u, 'Z') ? new \DateTimeImmutable($u) : new \DateTimeImmutable(strlen($u) === 8 ? $u . 'T235959' : $u, $tz);
        }
        // A weekly list of days: each week's days from the start's week on, the start itself first.
        $days = [];
        if ($unit === 'week' && isset($rule['BYDAY'])) {
            $codes = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
            foreach (explode(',', (string) $rule['BYDAY']) as $c) {
                if (isset($codes[$c])) {
                    $days[] = $codes[$c];
                }
            }
            sort($days);
        }
        $out = [];
        for ($i = 0; count($out) < $count && $i < 2000; $i++) {
            $period = $start->modify('+' . ($i * $interval) . ' ' . $unit);
            $candidates = [$period];
            if ($days !== []) {
                $weekStart = $period->modify('monday this week');
                $candidates = [];
                foreach ($days as $d) {
                    $candidates[] = $weekStart->modify('+' . ($d - 1) . ' days')->setTime((int) $start->format('G'), (int) $start->format('i'), (int) $start->format('s'));
                }
            }
            foreach ($candidates as $s) {
                if ($s < $start) {
                    continue;
                }
                if ($until !== null && $s > $until) {
                    return $out;
                }
                $out[] = $s;
                if (count($out) >= $count) {
                    break;
                }
            }
        }
        return $out;
    }

    /** @return array{0:array,1:array} a start and end resource */
    private function span(\DateTimeImmutable $s, int $length, bool $allDay, string $tz): array
    {
        $start = $allDay ? ['date' => $s->format('Y-m-d')] : ['dateTime' => $s->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
        return [$start, $this->endOf($start, $length, $allDay)];
    }

    private function endOf(array $start, int $length, bool $allDay): array
    {
        if ($allDay) {
            return ['date' => (new \DateTimeImmutable((string) $start['date']))->modify('+' . max(1, intdiv($length, 86400)) . ' days')->format('Y-m-d')];
        }
        $tz = (string) ($start['timeZone'] ?? $this->zone);
        $e = (new \DateTimeImmutable((string) $start['dateTime']))->setTimezone(new \DateTimeZone($tz))->modify('+' . $length . ' seconds');
        return ['dateTime' => $e->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
    }

    private static function lengthOf(array $e): int
    {
        if (isset($e['start']['date'])) {
            return max(86400, (new \DateTimeImmutable((string) $e['end']['date']))->getTimestamp() - (new \DateTimeImmutable((string) $e['start']['date']))->getTimestamp());
        }
        return max(0, (new \DateTimeImmutable((string) $e['end']['dateTime']))->getTimestamp() - (new \DateTimeImmutable((string) $e['start']['dateTime']))->getTimestamp());
    }

    /** The rule without its bound, as a set of parts: what decides the days. */
    private static function pattern(array $e): string
    {
        $parts = [];
        foreach ((array) ($e['recurrence'] ?? []) as $line) {
            if (str_starts_with((string) $line, 'RRULE:')) {
                parse_str(str_replace(';', '&', substr((string) $line, 6)), $rule);
                unset($rule['COUNT'], $rule['UNTIL']);
                ksort($rule);
                $parts[] = $rule;
            }
        }
        return json_encode($parts);
    }

    /** The occurrence keys an EXDATE line names. @return array<string,true> */
    private static function exdateKeys(array $master): array
    {
        $out = [];
        $allDay = isset($master['start']['date']);
        $tz = new \DateTimeZone((string) ($master['start']['timeZone'] ?? 'UTC'));
        foreach ((array) ($master['recurrence'] ?? []) as $line) {
            $line = (string) $line;
            if (!str_starts_with($line, 'EXDATE')) {
                continue;
            }
            $colon = strpos($line, ':');
            $head = substr($line, 0, (int) $colon);
            $zone = preg_match('/TZID=([^;:]+)/', $head, $m) === 1 ? new \DateTimeZone($m[1]) : $tz;
            foreach (explode(',', substr($line, (int) $colon + 1)) as $v) {
                $v = trim($v);
                if ($v === '') {
                    continue;
                }
                $at = str_ends_with($v, 'Z') ? new \DateTimeImmutable($v) : new \DateTimeImmutable(strlen($v) === 8 ? $v . 'T000000' : $v, $zone);
                $out[self::keyOfStart($at, $allDay)] = true;
            }
        }
        return $out;
    }

    /** An occurrence's key, as its instance id's suffix: UTC basic time for a timed series, the date in the zone for an all-day one. */
    private static function keyOfStart(\DateTimeImmutable $s, bool $allDay): string
    {
        return $allDay ? $s->format('Ymd') : $s->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /** A start or originalStartTime resource's key. */
    private static function keyOf(array $when, string $tz): string
    {
        if (isset($when['date'])) {
            return str_replace('-', '', (string) $when['date']);
        }
        return self::keyOfStart((new \DateTimeImmutable((string) $when['dateTime']))->setTimezone(new \DateTimeZone((string) ($when['timeZone'] ?? $tz))), false);
    }

    /** The series an id belongs to: an instance id's master, else the id itself. */
    private function seriesIdOf(string $id): string
    {
        $cut = strrpos($id, '_');
        if ($cut !== false && preg_match('/^\d{8}(T\d{6}Z)?$/', substr($id, $cut + 1)) === 1 && isset($this->events[substr($id, 0, $cut)])) {
            return substr($id, 0, $cut);
        }
        return $id;
    }

    /**
     * A write to a series: a new etag for its master and every one of its
     * records (probe 5.2, 5.3: one value for all of them), each listed by
     * the next delta (7.2); a single event's its own.
     */
    private function bump(string $seriesId): void
    {
        $etag = '"' . (3000000000000000 + ++$this->etags) . '"';
        $seq = ++$this->seq;
        foreach ($this->events as $id => $e) {
            if ($id === $seriesId || ($e['recurringEventId'] ?? '') === $seriesId || ($e['iCalUID'] ?? '') === ($this->events[$seriesId]['iCalUID'] ?? "\0")) {
                $this->events[$id]['etag'] = $etag;
                $this->changed[$id] = $seq;
            }
        }
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
