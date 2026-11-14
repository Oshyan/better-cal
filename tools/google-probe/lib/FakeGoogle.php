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
// time on an all-day series' instance, a GET that can lag, and (the probe of
// 2026-11-10, 3.18 to 3.31) a PATCH's start and end merged into the held ones,
// a field sent as null removed and 400 "Invalid start time." for a start or
// end that would hold a date and a clock time at once. And (the second and
// third runs of 2026-11-13): a switch between timed and all-day hides each day
// of the new form at the instant of a record of the old (3.48 to 3.51, 3.63 to
// 3.65); a master write that leaves out the notes clears every kept record's
// own notes on a series with none (3.52 to 3.61); a rule change that leaves
// only deleted days cancels the series for good (3.62).
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

    /**
     * Settings for what neither the probe nor Google's documentation fixes, so the suites stop depending on one
     * accidental behaviour of this stand-in (rule 1 audit, "What the harness generator must reach"). Each default is
     * this stand-in's behaviour before the settings existed; each is also read from the environment at construction,
     * so every suite that makes a FakeGoogle (the database suite, the edit harness and its shards, the interleaving
     * mode) runs under it:
     *
     * - $order (FAKE_GOOGLE_ORDER): the order a list comes in, for deltas, whole reads and read-backs alike.
     *   'made' lists resources in the order they were first made (a resource made again keeps its old place);
     *   'reversed' lists them last made first; 'shuffled' in an order fixed by $orderSeed and each id
     *   (FAKE_GOOGLE_ORDER=shuffled:SEED), the same on every page of one list.
     * - $listDropped (FAKE_GOOGLE_DROPPED=listed|omitted): whether a dropped record (a cancelled item with no
     *   originalStartTime, left under its old id) is listed at all.
     * - $zoneAlone (FAKE_GOOGLE_ZONE_ALONE=kept|dropped): what a master change of the zone alone, which moves no
     *   instant, does to the exception records (probe question Q2, open): 'kept' keeps those whose instant is still an
     *   occurrence, as before; 'dropped' drops every record of the series, as a clock or start change does.
     */
    public string $order = 'made';
    public int $orderSeed = 0;
    public bool $listDropped = true;
    public string $zoneAlone = 'kept';
    /** The label Google adds to a colour set on one day (10.6, 2026-11-13); invented, the form of the one seen. */
    public const LABEL = '00000000-0000-4000-8000-00000000c010';

    private int $seq = 0;
    private int $etags = 0;
    private int $ids = 0;
    /** @var array<string,int> id => change sequence */
    private array $changed = [];
    /** @var array<string,int> id => GETs left that answer 404 */
    private array $lagLeft = [];
    /** @var array<string,true> records dropped by a zone change under zoneAlone 'dropped': their id names an occurrence again, which lists as the series' own */
    private array $zoneDropped = [];
    /** @var array<string,array{start:array,end:array}> instance ids that were once days of a series, by what they were (the cancelled stub, find) */
    private array $wereDays = [];
    /** @var array<string,true> instance ids a switch between timed and all-day hid (hidden(): 3.48 to 3.51, 3.63 to 3.65) */
    private array $hidden = [];
    /** @var array<string,true> masters Google cancelled because a rule change left them only deleted days (3.62) */
    private array $emptied = [];

    public function __construct(public readonly string $calendarId)
    {
        $order = (string) getenv('FAKE_GOOGLE_ORDER');
        if ($order !== '') {
            [$kind, $seed] = array_pad(explode(':', $order, 2), 2, '0');
            if (!in_array($kind, ['made', 'reversed', 'shuffled'], true)) {
                throw new \InvalidArgumentException("FAKE_GOOGLE_ORDER is made, reversed or shuffled[:SEED], not '$order'");
            }
            $this->order = $kind;
            $this->orderSeed = (int) $seed;
        }
        $dropped = (string) getenv('FAKE_GOOGLE_DROPPED');
        if ($dropped !== '') {
            if (!in_array($dropped, ['listed', 'omitted'], true)) {
                throw new \InvalidArgumentException("FAKE_GOOGLE_DROPPED is listed or omitted, not '$dropped'");
            }
            $this->listDropped = $dropped === 'listed';
        }
        $zone = (string) getenv('FAKE_GOOGLE_ZONE_ALONE');
        if ($zone !== '') {
            if (!in_array($zone, ['kept', 'dropped'], true)) {
                throw new \InvalidArgumentException("FAKE_GOOGLE_ZONE_ALONE is kept or dropped, not '$zone'");
            }
            $this->zoneAlone = $zone;
        }
    }

    /** The settings in force, for a report: order, dropped items, the zone alone. */
    public function settings(): string
    {
        return 'order=' . $this->order . ($this->order === 'shuffled' ? ':' . $this->orderSeed : '') . ' dropped=' . ($this->listDropped ? 'listed' : 'omitted') . ' zoneAlone=' . $this->zoneAlone;
    }

    /** A dropped record: cancelled, no originalStartTime, under an instance id of a series. */
    private function isDropped(array $e): bool
    {
        return ($e['status'] ?? '') === 'cancelled' && !isset($e['originalStartTime']) && empty($e['recurrence']) && $this->seriesIdOf((string) $e['id']) !== (string) $e['id'];
    }

    /** A list's items in the order the setting says (made, reversed, or shuffled by the seed and each id), dropped records left out when they are omitted. */
    private function arrange(array $items): array
    {
        if (!$this->listDropped) {
            $items = array_values(array_filter($items, fn(array $e): bool => !$this->isDropped($e)));
        }
        if ($this->order === 'reversed') {
            return array_reverse($items);
        }
        if ($this->order === 'shuffled') {
            $rank = [];
            foreach ($items as $i => $e) {
                $rank[$i] = crc32($this->orderSeed . ':' . ($e['id'] ?? '') . ':' . $i);
            }
            uksort($items, static fn(int $a, int $b): int => $rank[$a] <=> $rank[$b] ?: $a <=> $b);
            return array_values($items);
        }
        return $items;
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
        $answer = $this->shapedAnswer($method, $this->route($method, $path, $query, $headers, $payload));
        // The status the stand-in answered, beside the request: a write it refused (4xx) took nothing. A lost answer was
        // taken, so its status is recorded before the answer is lost.
        $this->requests[$n - 1]['status'] = (int) $answer['status'];
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
        self::allDayOnce($this->events[$id]);
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
        $e = $this->find($id, true);
        if ($e === null) {
            // A day a switch hid: a write with an If-Match is 412, one without is 404 (3.48). A dropped record, under an
            // id that names no day now, refuses being made live with 400 (3.48, "revive dropped").
            if (isset($this->hidden[$id]) && $ifMatch !== null) {
                return self::error(412, 'Precondition Failed');
            }
            if (isset($this->events[$id]) && $this->isDropped($this->events[$id])) {
                return self::error(400, 'Bad Request');
            }
            return self::error(404, 'Not Found');
        }
        if ($ifMatch !== null && $ifMatch !== '*' && $ifMatch !== $e['etag']) {
            return self::error(412, 'Precondition Failed');
        }
        $seriesId = $this->seriesIdOf($id);
        if (!empty($e['recurrence'])) {
            return $this->patchMaster($e, $body);
        }
        $body = self::mergedWhen($e, $body);
        if ($body === null) {
            return self::error(400, 'Invalid start time.');
        }
        $master = $seriesId !== $id ? ($this->events[$seriesId] ?? null) : null;
        // A day that already holds a record (live, cancelled or revived): its write moves its own etag alone.
        $hadRecord = $master !== null && isset($this->events[$id]['originalStartTime']);
        if (array_key_exists('colorId', $body) && $body['colorId'] !== null && !isset($e['eventLabelId'])) {
            $body['eventLabelId'] = self::LABEL; // Google adds a label of its own to a colour set on one day (10.6, 2026-11-13)
        }
        if ($master !== null) {
            // An instance of an all-day series takes a clock time once its date is sent as null (3.38, 2026-11-13); sent
            // without it, the merge above already refuses it as holding a date and a clock time (2.2a, 3.30).
            unset($body['recurrence']); // an instance never carries a rule
        } elseif (isset($body['recurrence'])) {
            $why = self::recurrenceError((array) $body['recurrence'], isset(($body['start'] ?? $e['start'])['date']));
            if ($why !== null) {
                return self::error(400, $why);
            }
        }
        $e = array_replace($e, $body);
        $e['updated'] = gmdate('Y-m-d\TH:i:s.000\Z');
        self::allDayOnce($e);
        $this->events[$id] = $e;
        unset($this->zoneDropped[$id]);
        $master !== null ? $this->bumpDay($seriesId, $id, $hadRecord) : $this->bump($seriesId);
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
     * status) and a link set on it (3.32: `source` written into every kept
     * record, the cancelled one included), and its end follows a changed
     * length (3.2). A zone change that moves no instant (3.17) resets a kept
     * record's start to the pattern's in the new zone and leaves its end
     * where it was, in the new zone; a pattern change
     * drops the cancelled records it would keep (3.7). A dropped record
     * stays readable as a cancelled item with no originalStartTime, which
     * the next delta lists (3.1).
     */
    private function patchMaster(array $old, array $body): array
    {
        $body = self::mergedWhen($old, $body);
        if ($body === null) {
            return self::error(400, 'Invalid start time.');
        }
        $new = array_replace($old, $body);
        $allDay = isset($new['start']['date']);
        if (isset($body['recurrence'])) {
            $why = self::recurrenceError((array) $body['recurrence'], $allDay);
            if ($why !== null) {
                return self::error(400, $why);
            }
        }
        $id = (string) $old['id'];
        foreach ($this->generate($old) as $inst) {
            $this->wereDays[(string) $inst['id']] = ['start' => $inst['start'], 'end' => $inst['end']];
        }
        self::allDayOnce($new);
        $oldLen = self::lengthOf($old);
        $newLen = self::lengthOf($new);
        $startChanged = json_encode($old['start']) !== json_encode($new['start']);
        $patternChanged = self::pattern($old) !== self::pattern($new);
        // A series Google cancelled because a rule change left it only deleted days stays cancelled: a later status sent
        // is taken and changes nothing (3.62).
        $emptied = isset($this->emptied[(string) $old['id']]);
        if ($emptied) {
            unset($body['status']);
            $new['status'] = 'cancelled';
        }
        $fields = array_intersect_key($body, array_flip(['summary', 'description', 'location', 'status', 'source']));
        // A series with no notes, written without them: every kept record's own notes are cleared (3.52 to 3.57; and
        // with no recurrence sent, 3.68, 2026-11-14; with notes of its own, or its empty notes sent, they stay: 3.58 to
        // 3.61).
        $clearNotes = !array_key_exists('description', $body) && (string) ($old['description'] ?? '') === '';
        // Those empty notes sent to a series that has none change nothing and are written into no record (3.61).
        if (($fields['description'] ?? null) === '' && (string) ($old['description'] ?? '') === '') {
            unset($fields['description']);
        }
        $exdates = self::exdateKeys($new);
        // A switch between timed and all-day hides each day of the new form whose instant is a record's (live or
        // cancelled, with its originalStartTime) under the old: not listed, not readable, not writable (3.48 to 3.51,
        // 3.65), whatever the record holds; a dropped record there hides nothing (3.63, 3.64).
        if ($allDay !== isset($old['start']['date'])) {
            $this->hide($old, $new);
        }
        // The zone alone changed (both timed, another timeZone): under the 'dropped' setting every record goes (Q2).
        $zoneAlone = isset($old['start']['dateTime'], $new['start']['dateTime']) && ($old['start']['timeZone'] ?? null) !== ($new['start']['timeZone'] ?? null);
        $dropAll = $zoneAlone && $this->zoneAlone === 'dropped';
        // And moved no instant, the length unchanged (3.17; replay of 2026-11-13, D): each kept record's end stays where
        // it was. With the length changed too, the ends follow the new length as before (no live case shows otherwise).
        $sameInstants = $zoneAlone && $newLen === $oldLen
            && (new \DateTimeImmutable((string) $old['start']['dateTime']))->getTimestamp() === (new \DateTimeImmutable((string) $new['start']['dateTime']))->getTimestamp();
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
            // Kept exactly while still a day of the master as changed, a cancelled record too, whatever the rule change (D1: 3.42 to 3.47, 2026-11-13; 3.7's cancelled day went because it fell past the new end).
            if ($dropAll || !isset($occ[$key]) || isset($exdates[$key])) {
                // Gone: a cancelled item under its id, no originalStartTime.
                unset($r['recurringEventId'], $r['originalStartTime']);
                $r['status'] = 'cancelled';
                $r['sequence'] = (int) ($r['sequence'] ?? 0) + 1;
                $this->events[$rid] = $r;
                if ($dropAll && isset($occ[$key])) {
                    $this->zoneDropped[$rid] = true;
                }
                continue;
            }
            // The start changed and the length did not: a kept timed record's start goes onto its occurrence and its end
            // stays at its instant, in the series' zone. Seen for the zone alone at the same moments (3.17, replay round 2
            // D), for a start date moved along the pattern (3.5: no record's end reported changed) and for a zone and rule
            // change put back (replay round 5, google-an-untouched-edited-day-keeps-its-own-list-after-a-zone-and-rule-change
            // request 16: a day with its own 12:30 to 14:00 read back 13:00 to 14:00 under a 13:00 to 14:30 series), and
            // for the first day dragged a week later (3.67, 2026-11-14: a day at its own 14:00 to 15:30 read 10:00 to 15:30).
            // A timed day of an all-day series the same way, its start the occurrence's date at 00:00 UTC and neither
            // end named in a zone (replay round 5, google-a-reading-keeping-a-timed-day-under-an-all-day-series-is-
            // offered-as-locally request 9: a day at 09:00 to 10:00 Berlin read 00:00 UTC to 10:00 Berlin, no timeZone).
            if (($sameInstants || ($startChanged && $newLen === $oldLen)) && isset($r['end']['dateTime'])) {
                if ($allDay) {
                    $r['start'] = ['dateTime' => $occ[$key]->format('Y-m-d') . 'T00:00:00+00:00'];
                    $r['end'] = ['dateTime' => (new \DateTimeImmutable((string) $r['end']['dateTime']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP')];
                } else {
                    $tz = (string) $new['start']['timeZone'];
                    [$r['start']] = $this->span($occ[$key], $newLen, $allDay, $tz);
                    $r['end'] = ['dateTime' => (new \DateTimeImmutable((string) $r['end']['dateTime']))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
                }
            } elseif ($startChanged) {
                [$r['start'], $r['end']] = $this->span($occ[$key], $newLen, $allDay, (string) ($new['start']['timeZone'] ?? $this->zone));
            } elseif ($newLen !== $oldLen) {
                // In the record's own form: an all-day day of a timed series keeps whole days.
                $r['end'] = $this->endOf($r['start'], $newLen, isset($r['start']['date']));
            }
            foreach ($fields as $k => $v) {
                if ($v === null) {
                    unset($r[$k]); // a link cleared (a PATCH's null removes a field)
                    continue;
                }
                $r[$k] = $v;
            }
            if ($clearNotes) {
                unset($r['description']);
            }
            $this->events[$rid] = $r;
        }
        // A record an earlier master change dropped, whose id is a day of the master again (a clock or form changed back,
        // a bound or split taken back): revived as a live record at the series' values (3.36, 3.37, 3.40, 10.2, 10.3,
        // 2026-11-13), a day deleted before the first change live again, an edited day's own values gone.
        $generated = [];
        foreach ($this->generate($new) as $inst) {
            $generated[(string) $inst['id']] = $inst;
        }
        foreach ($this->events as $rid => $r) {
            if (isset($generated[$rid]) && !isset($this->zoneDropped[$rid]) && $this->isDropped($r) && $this->seriesIdOf((string) $rid) === $id) {
                $this->events[$rid] = ['sequence' => (int) ($r['sequence'] ?? 0) + 1] + $generated[$rid];
            }
        }
        // A rule change that leaves the series no day but deleted ones: Google cancels the series (3.62).
        if (!$emptied && ($new['status'] ?? 'confirmed') !== 'cancelled' && isset($body['recurrence'])) {
            $live = false;
            foreach ($this->generate($new) as $inst) {
                $r = $this->events[(string) $inst['id']] ?? null;
                if (!isset($this->hidden[(string) $inst['id']]) && ($r === null || !isset($r['originalStartTime']) || ($r['status'] ?? '') !== 'cancelled')) {
                    $live = true;
                    break;
                }
            }
            if (!$live) {
                $new['status'] = 'cancelled';
                $this->emptied[$id] = true;
            }
        }
        if ($startChanged || $patternChanged || isset($fields['status']) || isset($this->emptied[$id])) {
            $new['sequence'] = (int) ($old['sequence'] ?? 0) + 1;
        }
        $new['updated'] = gmdate('Y-m-d\TH:i:s.000\Z');
        $this->events[$id] = $new;
        $this->bump($id);
        return self::ok($this->events[$id]);
    }

    /**
     * The days a switch between timed and all-day hides (3.48 to 3.51, 3.65): each occurrence of $new at the instant of
     * a record of $old (live or cancelled, with its originalStartTime), an all-day day's instant being its date at 00:00
     * UTC. Before the switch drops those records.
     */
    private function hide(array $old, array $new): void
    {
        $id = (string) $old['id'];
        $instant = static fn(string $key): string => strlen($key) === 8 ? $key . 'T000000Z' : $key;
        $held = [];
        foreach ($this->events as $r) {
            if (($r['recurringEventId'] ?? '') === $id && isset($r['originalStartTime'])) {
                $held[$instant(self::keyOf($r['originalStartTime'], $r['start']['timeZone'] ?? $this->zone))] = true;
            }
        }
        $allDay = isset($new['start']['date']);
        foreach ($this->occurrences($new) as $s) {
            $key = self::keyOfStart($s, $allDay);
            if (isset($held[$instant($key)])) {
                $this->hidden[$id . '_' . $key] = true;
            }
        }
    }

    /**
     * A PATCH's start and end merged into what the event holds, as the probe
     * of 2026-11-10 found Google merges them (3.18 to 3.31): each field sent
     * replaces the held one, a field sent as null is removed, and one not
     * named stays. A start or end that would hold a date and a clock time
     * together is refused whole (null: the 400 "Invalid start time.", nothing
     * changed), which is what a body naming only the new form's fields gets
     * when the form changes. The body with its start and end as they will
     * be held; other fields as sent.
     */
    /**
     * A change a test makes at Google, as another client would: each start
     * and end it names set whole, the other form's fields as null, so a
     * change between timed and all-day made there lands as Google takes one
     * (3.19 to 3.31). Never what the app sends: only a test's own changes.
     */
    public static function asSet(array $body): array
    {
        foreach (['start', 'end'] as $k) {
            if (is_array($body[$k] ?? null)) {
                $body[$k] += isset($body[$k]['date']) ? ['dateTime' => null, 'timeZone' => null] : ['date' => null];
            }
        }
        return $body;
    }

    private static function mergedWhen(array $held, array $body): ?array
    {
        foreach (['start', 'end'] as $k) {
            if (!is_array($body[$k] ?? null)) {
                continue;
            }
            $when = array_replace(is_array($held[$k] ?? null) ? $held[$k] : [], $body[$k]);
            $when = array_filter($when, static fn($v): bool => $v !== null);
            if (isset($when['date']) && isset($when['dateTime'])) {
                return null;
            }
            $body[$k] = $when;
        }
        return $body;
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
        $seriesId = $this->seriesIdOf($id);
        $hadRecord = $seriesId !== $id && isset($this->events[$id]['originalStartTime']);
        $this->events[$id] = $e;
        if ($seriesId !== $id) {
            $this->bumpDay($seriesId, $id, $hadRecord);
            return ['status' => 204, 'body' => ''];
        }
        if (!empty($e['recurrence'])) {
            // A master deleted takes its records with it: each reads cancelled, its originalStartTime kept (10.3, 10.4,
            // 10.5, 2026-11-13); a PATCH to confirmed on the master brings them all back (the status written into every
            // record, 3.15), a day cancelled before the master was included (10.4).
            foreach ($this->events as $rid => $r) {
                if (($r['recurringEventId'] ?? '') === $id && isset($r['originalStartTime'])) {
                    $this->events[$rid]['status'] = 'cancelled';
                }
            }
        }
        $this->bump($seriesId);
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
                // A cancelled day of a series is listed all the same, in a short form with the series' etag (replay of
                // 2026-11-13, section A; 26 scenarios); a cancelled master or a dropped record is not.
                if (!empty($e['recurringEventId']) && isset($e['originalStartTime'], $this->events[$e['recurringEventId']])) {
                    $items[] = ['kind' => 'calendar#event', 'etag' => $this->events[$e['recurringEventId']]['etag'], 'id' => $e['id'], 'status' => 'cancelled',
                        'recurringEventId' => $e['recurringEventId'], 'originalStartTime' => $e['originalStartTime']];
                }
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
        $items = $this->arrange($items);
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
    private function find(string $id, bool $forWrite = false): ?array
    {
        $masterId = $this->seriesIdOf($id);
        if (isset($this->events[$id]) && ($masterId === $id || isset($this->events[$id]['originalStartTime']))) {
            return $this->events[$id];
        }
        if ($masterId === $id || !isset($this->events[$masterId])) {
            return null;
        }
        foreach ($this->generate($this->events[$masterId]) as $inst) {
            if ($inst['id'] === $id && !isset($this->hidden[$id])) {
                return $inst;
            }
        }
        // A dropped record under an id that names no day of the series now: a GET reads it as it is, cancelled with
        // no originalStartTime (3.36, 3.37, 3.39, 2026-11-13). A write to it was never measured: no such day.
        if ($forWrite) {
            return null;
        }
        if (isset($this->events[$id])) {
            return $this->events[$id];
        }
        // An id that was once a day of the series and names none now (the other form's, after a switch back): a GET
        // answers a cancelled stub, as Google does (3.39, 2026-11-13, steps 21, 27 and 33), its dates the day's then.
        if (isset($this->wereDays[$id])) {
            $m = $this->events[$masterId];
            return ['kind' => 'calendar#event', 'etag' => $m['etag'], 'id' => $id, 'status' => 'cancelled', 'created' => '0000-12-31T00:00:00.000Z', 'updated' => $m['updated'] ?? gmdate('Y-m-d\TH:i:s.000\Z'),
                'summary' => 'CANCELLED', 'creator' => ['email' => $this->calendarId, 'self' => true], 'organizer' => ['email' => $this->calendarId, 'self' => true],
                'start' => $this->wereDays[$id]['start'], 'end' => $this->wereDays[$id]['end'], 'iCalUID' => $m['iCalUID'] ?? '', 'sequence' => 1, 'reminders' => ['useDefault' => false]];
        }
        return null;
    }

    /**
     * Google's own reminders on a resource once it has been all-day: `useDefault` false, kept when it is timed again
     * (replay of 2026-11-13, section F: 170 resources in probe cases 3.23 to 3.26, 3.29, 3.30, 3.36 to 3.40). A resource
     * never all-day holds none, and its answers say `useDefault` true (shaped).
     */
    private static function allDayOnce(array &$e): void
    {
        if (isset($e['start']['date']) && !isset($e['reminders'])) {
            $e['reminders'] = ['useDefault' => false];
        }
    }

    /** @return list<array> the occurrences with exception records laid over them */
    private function instances(array $master, bool $deleted): array
    {
        $out = [];
        $seen = [];
        foreach ($this->generate($master) as $inst) {
            if (isset($this->hidden[$inst['id']])) {
                continue;
            }
            $e = isset($this->zoneDropped[$inst['id']]) ? $inst : ($this->events[$inst['id']] ?? $inst);
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
     * A write to one day of a series ($id, under $seriesId). Google gives each resource its own etag once a day has a
     * record (2026-11-13): a write to a day that already held a record moves that record's etag alone, the master's and
     * the others' staying as they were (3.36, 3.37 and 3.40 second changes, 10.2); a first write to an ordinary day
     * answered once with an etag of its own while the master and the other records moved to another (3.37 first
     * change), which is the reading kept here, the strictest seen. Dropped records keep theirs. The next delta lists
     * the day, and the master when it moved (5.3), and every other record whose etag moved with it, unchanged (replay
     * round 5, `app-series-deleted-whole` request 13: an ordinary day deleted on a series holding one renamed day, and
     * Google's next delta listed the master, the deleted day and the renamed day as it was, all three on one etag).
     * That is the only shape the live runs show: a first write to an ordinary day while another record is held. A
     * write to a day that already holds a record lists that day alone; no live delta has said otherwise.
     */
    private function bumpDay(string $seriesId, string $id, bool $hadRecord): void
    {
        $seq = ++$this->seq;
        $this->events[$id]['etag'] = '"' . (3000000000000000 + ++$this->etags) . '"';
        $this->changed[$id] = $seq;
        if ($hadRecord) {
            return;
        }
        $other = '"' . (3000000000000000 + ++$this->etags) . '"';
        foreach ($this->events as $rid => $e) {
            if ($rid !== $id && ($rid === $seriesId || (($e['recurringEventId'] ?? '') === $seriesId && isset($e['originalStartTime'])))) {
                $this->events[$rid]['etag'] = $other;
                $this->changed[$rid] = $seq;
            }
        }
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

    /**
     * Every answer as Google shapes it on the way out, the store left as it is (docs/design/research/google-series/
     * replay-2026-11-13.md): each event resource (a single answer, or each item of a list) through shaped(), and an error
     * with the reason and wording Google gives where a live answer has shown them.
     */
    private function shapedAnswer(string $method, array $answer): array
    {
        $body = json_decode((string) $answer['body'], true);
        if (!is_array($body)) {
            return $answer;
        }
        if (isset($body['error'])) {
            // As probed (2026-11-10 and 2026-11-13): reason and domain per status; a PATCH's 412 has its own wording and a status.
            $reasons = [400 => 'invalid', 404 => 'notFound', 410 => 'deleted'];
            $code = (int) $answer['status'];
            if ($code === 412 && $method === 'PATCH') {
                $body = ['error' => ['code' => 412, 'message' => 'Precondition check failed.', 'errors' => [['message' => 'Precondition check failed.', 'domain' => 'global', 'reason' => 'failedPrecondition']], 'status' => 'FAILED_PRECONDITION']];
            } elseif (isset($reasons[$code])) {
                $message = (string) $body['error']['message'];
                $body = ['error' => ['errors' => [['domain' => 'global', 'reason' => $reasons[$code], 'message' => $message]], 'code' => $code, 'message' => $message]];
            }
            return ['status' => $answer['status'], 'body' => (string) json_encode($body, JSON_UNESCAPED_SLASHES)];
        }
        if (($body['kind'] ?? 'calendar#event') === 'calendar#event' && isset($body['id'])) {
            $body = $this->shaped($body);
        } elseif (isset($body['items']) && is_array($body['items'])) {
            $body['items'] = array_map(fn(array $e): array => $this->shaped($e), $body['items']);
        } else {
            return $answer;
        }
        return ['status' => $answer['status'], 'body' => (string) json_encode($body, JSON_UNESCAPED_SLASHES)];
    }

    /**
     * One event resource as Google returns it, as the live answers show (3,244 resources in the probe of 2026-11-13, the
     * replay of the same day): no field held as null, no description or location held as "", no recurrence held as [];
     * every dateTime (start, end, originalStartTime) written with the calendar's own offset at that instant, whatever
     * zone the event names; reminders {useDefault: true} on a timed event and false on an all-day one when none are held;
     * eventType "default" and kind "calendar#event" always.
     */
    private function shaped(array $e): array
    {
        $e = array_filter($e, static fn($v): bool => $v !== null);
        foreach (['description', 'location'] as $k) {
            if (($e[$k] ?? null) === '') {
                unset($e[$k]);
            }
        }
        if (($e['recurrence'] ?? null) === []) {
            unset($e['recurrence']);
        }
        foreach (['start', 'end', 'originalStartTime'] as $k) {
            if (isset($e[$k]['dateTime']) && is_string($e[$k]['dateTime'])) {
                $e[$k]['dateTime'] = (new \DateTimeImmutable($e[$k]['dateTime']))->setTimezone(new \DateTimeZone($this->zone))->format('Y-m-d\TH:i:sP');
            }
        }
        // originalStartTime names the zone the resource's own start names, none when that start is a date (3,472 of
        // 3,472 resources in the probes of 2026-11-10 and 2026-11-13; replay round 2).
        if (isset($e['originalStartTime']['dateTime'], $e['start'])) {
            unset($e['originalStartTime']['timeZone']);
            if (isset($e['start']['timeZone'])) {
                $e['originalStartTime']['timeZone'] = $e['start']['timeZone'];
            }
        }
        if (!isset($e['reminders']) && isset($e['start'])) {
            $e['reminders'] = ['useDefault' => !isset($e['start']['date'])];
        }
        return $e + ['kind' => 'calendar#event', 'eventType' => 'default'];
    }

    private static function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => (string) json_encode(['error' => ['code' => $status, 'message' => $message, 'errors' => [['reason' => 'fake', 'message' => $message]]]])];
    }
}
