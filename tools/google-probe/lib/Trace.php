<?php
// A trace of one scenario: every request a caller sent to the stand-in
// (FakeGoogle) and the stand-in's answer, in order, written as JSON so
// replay.php can send the same requests to another target and compare.
// The recorder wraps the stand-in's transports, so FakeGoogle itself is
// unchanged: hand the app (or the probe's Api) the recorder's transport()
// writerTransport() or syncTransport() in place of the stand-in's own.
//
// A change to the stand-in's store made outside a request (a test seeding
// an event, the edit harness's adopt) is recorded as an "outside" step: the
// etags it moved, the fields it changed, and, when the change was to a
// master or single event with no exception records yet (the harness's
// adopt of a series it just made), the PATCH bodies that bring a target to
// the same state ("setup"). One that only moved etags the replay follows;
// one with a setup it sends first; any other it can't replay, and says so.
//
// The file is rewritten after every exchange, so a run that stops leaves
// what it got. Format (format 1):
//   {format, scenario, source, zone, expand, settings, recorded, steps: [
//     {kind: "request", n, method, path, query, headers, body, status, response},
//     {kind: "outside", before: n, etags: {id: etag}, changed: [id...], fields: {id: {field: [before, after]}},
//      setup: {id: body} | null} ]}
// path is relative to the calendar (/events/..., '' for the calendar
// itself) or /users/me/... with {calendar} for the calendar id, as Api
// takes it. headers keeps If-Match and If-None-Match only; the bearer
// token is never written.
declare(strict_types=1);

namespace BetterCal\GoogleProbe;

require_once __DIR__ . '/Api.php';

final class TraceRecorder
{
    public const FORMAT = 1;
    private const API_ROOT = '/calendar/v3';
    private const KEPT_HEADERS = ['if-match', 'if-none-match'];

    /** @var list<array> */
    private array $steps = [];
    private int $n = 0;
    /** @var array<string,string> id => the event's JSON less its etag, after the last answer */
    private array $content = [];
    /** @var array<string,string> id => etag after the last answer */
    private array $etags = [];

    /**
     * @param string $expand what made the stand-in's occurrences: 'built-in' (FakeGoogle's own reading) or
     *        'edit-harness' (GoogleStand::expand), so a replay on a fresh stand-in uses the same
     */
    public function __construct(
        private readonly FakeGoogle $fake,
        public readonly string $file,
        public readonly string $scenario,
        private readonly string $source,
        private readonly string $expand = 'built-in',
    ) {
        [$this->content, $this->etags] = $this->state();
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot make the trace folder $dir");
        }
        $this->write();
    }

    /**
     * A recorder when GOOGLE_TRACE names a file, else null. A second
     * recorder in one process (another account's stand-in) writes beside
     * the first with -2, -3 on its name.
     */
    public static function fromEnv(FakeGoogle $fake, string $source, string $expand): ?self
    {
        static $made = 0;
        $file = (string) getenv('GOOGLE_TRACE');
        if ($file === '') {
            return null;
        }
        $made++;
        if ($made > 1) {
            $file = preg_replace('/(\.json)?$/', "-$made.json", $file, 1);
        }
        return new self($fake, $file, basename($file, '.json'), $source, $expand);
    }

    /** Api's transport: (method, url, headers, payload). */
    public function transport(): \Closure
    {
        return fn(string $method, string $url, array $headers, ?array $payload): array => $this->exchange($method, $url, $headers, $payload);
    }

    /** GoogleWriter's jsonTransport: (method, url, payload, headers). */
    public function writerTransport(): \Closure
    {
        return fn(string $method, string $url, ?array $payload, array $headers = []): array => $this->exchange($method, $url, $headers, $payload);
    }

    /** GoogleSync's transport: (url, headers), a GET. */
    public function syncTransport(): \Closure
    {
        return fn(string $url, array $headers): array => $this->exchange('GET', $url, $headers, null);
    }

    /** @return list<array> the steps so far */
    public function steps(): array
    {
        return $this->steps;
    }

    private function exchange(string $method, string $url, array $headers, ?array $payload): array
    {
        $this->noteOutside();
        $answer = ($this->fake->transport())($method, $url, $headers, $payload);
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $kept = [];
        foreach ($headers as $h) {
            $name = strtolower(trim(strstr($h, ':', true) ?: ''));
            if (in_array($name, self::KEPT_HEADERS, true)) {
                $kept[] = $h;
            }
        }
        $decoded = json_decode((string) $answer['body'], true);
        $this->steps[] = [
            'kind' => 'request',
            'n' => ++$this->n,
            'method' => strtoupper($method),
            'path' => $this->relative((string) ($parts['path'] ?? '')),
            'query' => $query,
            'headers' => $kept,
            'body' => $payload,
            'status' => (int) $answer['status'],
            'response' => is_array($decoded) ? $decoded : (string) $answer['body'],
        ];
        [$this->content, $this->etags] = $this->state();
        $this->write();
        return $answer;
    }

    /** Records what changed in the stand-in's store since the last answer, if anything. */
    private function noteOutside(): void
    {
        [$content, $etags] = $this->state();
        if ($content === $this->content && $etags === $this->etags) {
            return;
        }
        $changed = [];
        $fields = [];
        foreach ($content + $this->content as $id => $_) {
            if (($content[$id] ?? null) !== ($this->content[$id] ?? null)) {
                $changed[] = (string) $id;
                // What changed, field by field: [before, after], null for a field (or an event) not there.
                $was = json_decode($this->content[$id] ?? 'null', true) ?? [];
                $now = json_decode($content[$id] ?? 'null', true) ?? [];
                foreach (array_keys($was + $now) as $k) {
                    if (($was[$k] ?? null) !== ($now[$k] ?? null)) {
                        $fields[(string) $id][$k] = [$was[$k] ?? null, $now[$k] ?? null];
                    }
                }
            }
        }
        $moved = [];
        foreach ($etags as $id => $etag) {
            if (($this->etags[$id] ?? null) !== $etag) {
                $moved[(string) $id] = $etag;
            }
        }
        $this->steps[] = ['kind' => 'outside', 'before' => $this->n + 1, 'etags' => $moved, 'changed' => $changed, 'fields' => $fields, 'setup' => $this->setup($changed, $fields)];
        [$this->content, $this->etags] = [$content, $etags];
        $this->write();
    }

    /**
     * The outside change as PATCH bodies a replay can send to bring the target to the same state, by id, or null when
     * none can: an event that was not there before (seeded), one that is gone, a cancelled one, or a series that already
     * has an exception record (the stand-in stored the change over its records without the rules a PATCH goes through).
     * The edit harness's adopt of a series it just made (a rule or start written straight into the row) is the case
     * this serves. A start or end is sent whole, the other form's fields as null, as Google merges them.
     *
     * @return array<string,array>|null
     */
    private function setup(array $changed, array $fields): ?array
    {
        $out = [];
        foreach ($changed as $id) {
            $e = $this->fake->events[$id] ?? null;
            $was = json_decode($this->content[$id] ?? 'null', true);
            if ($e === null || !is_array($was) || ($e['status'] ?? '') === 'cancelled' || !empty($e['recurringEventId'])) {
                return null;
            }
            foreach ($this->fake->events as $r) {
                if (($r['recurringEventId'] ?? '') === $id) {
                    return null;
                }
            }
            $body = [];
            foreach ($fields[$id] ?? [] as $k => [, $now]) {
                if (in_array($k, ['start', 'end'], true) && is_array($now)) {
                    $now += ['date' => null, 'dateTime' => null, 'timeZone' => null];
                }
                $body[$k] = $now;
            }
            $out[$id] = $body;
        }
        return $out;
    }

    /** @return array{0:array<string,string>,1:array<string,string>} */
    private function state(): array
    {
        $content = [];
        $etags = [];
        foreach ($this->fake->events as $id => $e) {
            $etags[(string) $id] = (string) ($e['etag'] ?? '');
            unset($e['etag']);
            $content[(string) $id] = (string) json_encode($e);
        }
        return [$content, $etags];
    }

    /** A URL path as Api takes it: relative to the calendar, or /users/me/... with {calendar}. */
    private function relative(string $path): string
    {
        if (str_starts_with($path, self::API_ROOT)) {
            $path = substr($path, strlen(self::API_ROOT));
        }
        $enc = rawurlencode($this->fake->calendarId);
        $cal = '/calendars/' . $enc;
        if (str_starts_with($path, $cal)) {
            return substr($path, strlen($cal));
        }
        return str_replace($enc, '{calendar}', $path);
    }

    /**
     * What kinds of write a trace holds, for the coverage table (record.php, replay.php): each kind with the number
     * of times it comes. Read from the trace alone: the state each write met is the last answer that showed the event.
     *
     * @return array<string,int>
     */
    public static function kinds(array $trace): array
    {
        $held = [];
        $edited = [];
        $deleted = [];
        $kinds = [];
        $bounded = false;
        $add = static function (string $k) use (&$kinds): void {
            $kinds[$k] = ($kinds[$k] ?? 0) + 1;
        };
        $remember = static function (mixed $r) use (&$held): void {
            if (is_array($r) && isset($r['id']) && ($r['status'] ?? '') !== 'cancelled') {
                $held[(string) $r['id']] = $r;
            }
        };
        $writes = 0;
        $polledAfter = 0;
        $open = false;
        foreach ($trace['steps'] ?? [] as $s) {
            if ($s['kind'] !== 'request') {
                continue;
            }
            $id = preg_match('#^/events/([^/]+)$#', (string) $s['path'], $m) === 1 ? rawurldecode($m[1]) : null;
            $prior = $id !== null ? ($held[$id] ?? null) : null;
            $body = is_array($s['body']) ? $s['body'] : [];
            $ok = $s['status'] < 300;
            $instance = $id !== null && preg_match('/_\d{8}(T\d{6}Z)?$/', $id) === 1;
            if ($s['method'] !== 'GET') {
                $writes++;
                $open = true;
            }
            if (!$ok && $s['method'] !== 'GET') {
                $add('refused write (' . $s['status'] . ')');
            }
            if ($s['method'] === 'GET' && (string) $s['path'] === '/events') {
                if (isset($s['query']['syncToken'])) {
                    $add('poll with a sync token');
                    if ($open) {
                        $polledAfter++;
                        $open = false;
                    }
                } else {
                    $add('whole read');
                }
            } elseif ($s['method'] === 'POST' && $ok) {
                $add(!empty($body['recurrence']) ? 'insert a repeating event' : 'insert a single event');
                if (!empty($body['recurrence']) && $bounded) {
                    $add('this and following (bound, then a new series)');
                }
            } elseif ($s['method'] === 'DELETE' && $ok) {
                if ($instance) {
                    $add('delete one day');
                    $deleted[preg_replace('/_[^_]+$/', '', (string) $id)] = true;
                } else {
                    $add(!empty($prior['recurrence']) ? 'delete a whole repeating event' : 'delete a single event');
                }
            } elseif ($s['method'] === 'PATCH' && $ok) {
                if ($instance) {
                    $add('edit one day');
                    $edited[preg_replace('/_[^_]+$/', '', (string) $id)] = true;
                } elseif (empty($prior['recurrence'])) {
                    $add('change a single event');
                } else {
                    $was = isset($prior['start']['date']) ? 'all-day' : 'timed';
                    $now = isset($body['start']['date']) ? 'all-day' : (isset($body['start']['dateTime']) ? 'timed' : $was);
                    if ($was !== $now) {
                        $add("all events: $was to $now");
                        if (isset($edited[$id])) {
                            $add("all events: $was to $now, with an edited day");
                        }
                        if (isset($deleted[$id])) {
                            $add("all events: $was to $now, with a deleted day");
                        }
                    } elseif (isset($body['start']['timeZone']) && ($body['start']['timeZone'] ?? null) !== ($prior['start']['timeZone'] ?? null)) {
                        $add('all events: zone change');
                    } elseif (isset($body['start']) && json_encode($body['start']) !== json_encode(array_intersect_key($prior['start'] ?? [], (array) $body['start']))) {
                        $add('all events: time change');
                    }
                    if (isset($body['recurrence']) && $body['recurrence'] !== ($prior['recurrence'] ?? null)) {
                        $strip = static fn(array $r): string => (string) preg_replace('/;?(COUNT|UNTIL)=[^;]*/', '', implode("\n", $r));
                        if ($strip((array) $body['recurrence']) === $strip((array) ($prior['recurrence'] ?? []))) {
                            $add('all events: bound change');
                            $bounded = true;
                        } else {
                            $add('all events: repeat-rule change');
                        }
                    }
                    if (array_intersect_key($body, array_flip(['summary', 'description', 'location', 'status', 'colorId'])) !== []) {
                        $add('all events: field change');
                    }
                }
            }
            if ($ok) {
                $remember($s['response']);
                foreach ((array) ($s['response']['items'] ?? []) as $item) {
                    $remember($item);
                }
            }
        }
        if ($writes > 0) {
            $kinds['writes'] = $writes;
            $kinds['writes then a poll before the next write'] = $polledAfter;
        }
        ksort($kinds);
        return $kinds;
    }

    private function write(): void
    {
        $data = [
            'format' => self::FORMAT,
            'scenario' => $this->scenario,
            'source' => $this->source,
            'zone' => $this->fake->zone,
            'expand' => $this->expand,
            'settings' => ['order' => $this->fake->order, 'orderSeed' => $this->fake->orderSeed, 'listDropped' => $this->fake->listDropped, 'zoneAlone' => $this->fake->zoneAlone],
            'recorded' => Api::now(),
            'steps' => $this->steps,
        ];
        $tmp = $this->file . '.part';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n");
        rename($tmp, $this->file);
    }
}
