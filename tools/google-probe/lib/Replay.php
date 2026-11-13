<?php
// Replays a trace (lib/Trace.php) against a target through Api and
// compares each answer with the one the stand-in gave. See README.md,
// "Replaying the App's Requests".
//
// What the target issues is mapped from what the stand-in issued, learned
// from the answers in order: event ids from each insert's answer (an
// insert with an id of its own gets a fresh one, as Google never takes an
// id twice), instance ids through their series id (<id>_<YYYYMMDD>,
// <id>_<YYYYMMDDTHHMMSSZ>), iCalUIDs, etags (by event, then by value),
// sync tokens and page tokens. Each recorded request is sent as recorded
// with those values mapped, and its answer compared after normalize().
// At the first divergence the scenario stops, as every later request
// depends on it. Every event the replay creates carries the probe's
// marker (extendedProperties.private, never the description), and every
// one is deleted when the scenario ends, also after an error.
declare(strict_types=1);

namespace BetterCal\GoogleProbe;

final class Replay
{
    /** The probe's own marker key (Probe::MARKER_KEY), so the probe's sweep also takes what a stopped replay left. */
    public const MARKER_KEY = 'betterCalProbe';
    public const MARKER_PREFIX = 'replay-';
    /** Fields the target sets on its own; dropped on both sides before comparing. Each is listed in README.md. */
    public const IGNORED = ['etag', 'updated', 'created', 'sequence', 'htmlLink', 'creator', 'organizer'];
    /** On a cancelled item only these are compared strictly; any other field that differs is a shape note. */
    public const CANCELLED_CORE = ['id', 'status', 'recurringEventId', 'originalStartTime'];
    private const INSTANCE = '/^(.+)_(\d{8}(?:T\d{6}Z)?)$/';

    private string $runId;
    private int $fresh = 0;
    /** @var array<string,string> recorded id => target id */
    private array $ids = [];
    /** @var array<string,string> target id => recorded id */
    private array $idsBack = [];
    /** @var array<string,string> */
    private array $uids = [];
    /** @var array<string,string> */
    private array $uidsBack = [];
    /** @var array<string,array<string,string>> recorded id => recorded etag => target etag */
    private array $etagsById = [];
    /** @var array<string,string> recorded etag => target etag, the latest pairing */
    private array $etagsAny = [];
    /** @var array<string,string> target id => its latest etag */
    private array $lastEtag = [];
    /** @var array<string,string> */
    private array $syncTokens = [];
    /** @var array<string,string> */
    private array $pageTokens = [];
    /** @var list<string> target ids of the events this scenario created */
    private array $created = [];
    /** @var list<string> notes on mapping (an etag or token never seen) */
    private array $notes = [];
    /** Setup PATCHes sent for outside steps in this scenario. */
    private int $setups = 0;

    /**
     * @param \Closure $sleep fn(float $seconds): void
     * @param int $readTries a GET whose answer differs is asked again up to this many times in all (Google's reads lag
     *        behind its writes); 1 on the stand-in
     */
    public function __construct(
        private readonly Api $api,
        private readonly \Closure $sleep,
        private readonly int $readTries = 1,
        private readonly float $readPause = 2.0,
        ?string $runId = null,
    ) {
        $this->runId = $runId ?? bin2hex(random_bytes(4));
    }

    /** The marker value this replay writes. */
    public function marker(): string
    {
        return self::MARKER_PREFIX . $this->runId;
    }

    /**
     * Replays one trace. The result: scenario, status (same, diverged, unreplayable, error), steps (the recorded
     * requests sent), sent (requests the target got, cleanup included), at (the step it stopped on), divergence,
     * reason, shape (notes on cancelled items), ignored (target list items of no event of this scenario), notes,
     * cleanup.
     *
     * @return array<string,mixed>
     */
    public function scenario(array $trace): array
    {
        $this->reset();
        $out = ['scenario' => (string) ($trace['scenario'] ?? '?'), 'source' => (string) ($trace['source'] ?? ''), 'status' => 'same', 'steps' => 0,
            'at' => null, 'divergence' => null, 'reason' => null, 'shape' => [], 'ignored' => 0, 'notes' => [], 'cleanup' => null];
        $before = $this->api->requests;
        try {
            if ((int) ($trace['format'] ?? 0) !== TraceRecorder::FORMAT) {
                throw new \UnexpectedValueException('not a trace of format ' . TraceRecorder::FORMAT);
            }
            foreach ($trace['steps'] ?? [] as $step) {
                if ($step['kind'] === 'outside') {
                    $why = $this->outside($step);
                    if ($why !== null) {
                        $out['status'] = 'unreplayable';
                        $out['at'] = $step['before'];
                        $out['reason'] = $why;
                        break;
                    }
                    continue;
                }
                $out['steps']++;
                [$sent, $got, $ignored] = $this->send($step);
                $out['ignored'] += $ignored;
                $diff = self::compare($step, $got, $this->idsBack, $this->uidsBack);
                foreach ($diff['shape'] as $note) {
                    $out['shape'][] = ['at' => $step['n']] + $note;
                }
                if ($diff['differs'] !== []) {
                    $out['status'] = 'diverged';
                    $out['at'] = $step['n'];
                    $out['divergence'] = [
                        'request' => array_intersect_key($step, array_flip(['n', 'method', 'path', 'query', 'headers', 'body'])),
                        'sent' => $sent,
                        'recorded' => ['status' => $step['status'], 'response' => $step['response']],
                        'target' => ['status' => $got['status'], 'response' => $got['response'], 'tries' => $got['readTries'] ?? 1],
                        'difference' => $diff['differs'],
                    ];
                    break;
                }
            }
        } catch (\Throwable $e) {
            $out['status'] = 'error';
            $out['reason'] = get_class($e) . ': ' . $e->getMessage();
        } finally {
            $out['cleanup'] = $this->cleanup();
            $out['notes'] = $this->notes;
            $out['setups'] = $this->setups;
            $out['sent'] = $this->api->requests - $before;
        }
        return $out;
    }

    private function reset(): void
    {
        $this->ids = $this->idsBack = $this->uids = $this->uidsBack = [];
        $this->etagsById = $this->etagsAny = $this->lastEtag = $this->syncTokens = $this->pageTokens = [];
        $this->created = $this->notes = [];
        $this->setups = 0;
    }

    /**
     * An outside step: follows an etag-only change; sends the recorded setup (a PATCH of the fields changed, on an
     * event with no exception records) for one that changed what an event holds; refuses any other. The setup's
     * answers are not compared (no request of the app's was answered), only learned from; a refused setup stops the
     * scenario as unreplayable on this target, with the target's reason.
     */
    private function outside(array $step): ?string
    {
        if (!empty($step['changed'])) {
            $what = implode(', ', array_slice($step['changed'], 0, 5)) . (count($step['changed']) > 5 ? ', ...' : '');
            if (!is_array($step['setup'] ?? null)) {
                return "the stand-in's store was changed outside a request before request {$step['before']} ($what), in a way no request can repeat (an event seeded, or a series with exception records changed): the target can't be brought to the same state";
            }
            foreach ($step['setup'] as $rid => $body) {
                $tid = $this->mapId((string) $rid);
                $r = $this->api->call('PATCH', '/events/' . rawurlencode($tid), [], $body);
                $this->setups++;
                if ($r['status'] >= 300 || !is_array($r['response'])) {
                    $why = is_array($r['response']) ? (string) ($r['response']['error']['message'] ?? '') : '';
                    return "the change the stand-in took outside a request before request {$step['before']} ($rid: " . implode(', ', array_keys($body)) . ") was refused by the target: HTTP {$r['status']} $why";
                }
                if (isset($r['response']['etag'])) {
                    $this->lastEtag[$tid] = (string) $r['response']['etag'];
                }
            }
        }
        foreach ($step['etags'] ?? [] as $rid => $etag) {
            $tid = $this->mapId((string) $rid);
            if (isset($this->lastEtag[$tid])) {
                // The same resource stored again: on the target it is as it was, so its etag there stands for the new one.
                $this->etagsById[(string) $rid][(string) $etag] = $this->lastEtag[$tid];
                $this->etagsAny[(string) $etag] = $this->lastEtag[$tid];
            }
        }
        return null;
    }

    // ---- mapping ------------------------------------------------------------

    public function mapId(string $rid): string
    {
        if (isset($this->ids[$rid])) {
            return $this->ids[$rid];
        }
        if (preg_match(self::INSTANCE, $rid, $m) === 1 && isset($this->ids[$m[1]])) {
            return $this->ids[$m[1]] . '_' . $m[2];
        }
        return $rid;
    }

    /** A target id in the recorded namespace, or null when it is of no event this scenario knows. */
    public static function backId(string $tid, array $idsBack): ?string
    {
        if (isset($idsBack[$tid])) {
            return $idsBack[$tid];
        }
        if (preg_match(self::INSTANCE, $tid, $m) === 1 && isset($idsBack[$m[1]])) {
            return $idsBack[$m[1]] . '_' . $m[2];
        }
        return null;
    }

    private function bindId(string $rid, string $tid): void
    {
        $this->ids[$rid] = $tid;
        $this->idsBack[$tid] = $rid;
    }

    private function bindUid(string $ruid, string $tuid): void
    {
        $this->uids[$ruid] = $tuid;
        $this->uidsBack[$tuid] = $ruid;
    }

    /** An id the target takes as an event's own: base32hex, as Google asks. */
    private function freshId(): string
    {
        return 'rp' . $this->runId . str_pad((string) ++$this->fresh, 4, '0', STR_PAD_LEFT);
    }

    /** @return array{0:string,1:array,2:?array,3:array} path, query, body, headers as the target gets them */
    private function mapRequest(array $step): array
    {
        $method = (string) $step['method'];
        $path = (string) $step['path'];
        $creates = $method === 'POST' && in_array($path, ['/events', '/events/import'], true);
        if (!$creates) {
            $path = (string) preg_replace_callback('#^/events/([^/?]+)#', fn(array $m): string => '/events/' . rawurlencode($this->mapId(rawurldecode($m[1]))), $path);
        }
        $query = (array) ($step['query'] ?? []);
        foreach (['syncToken' => 'syncTokens', 'pageToken' => 'pageTokens'] as $k => $map) {
            if (isset($query[$k])) {
                if (!isset($this->{$map}[(string) $query[$k]])) {
                    $this->notes[] = "request {$step['n']}: a $k the stand-in never gave in this scenario, sent as recorded";
                }
                $query[$k] = $this->{$map}[(string) $query[$k]] ?? $query[$k];
            }
        }
        if (isset($query['iCalUID'])) {
            $query['iCalUID'] = $this->uids[(string) $query['iCalUID']] ?? $query['iCalUID'];
        }
        $body = $step['body'] ?? null;
        if (is_array($body)) {
            if ($creates) {
                if (isset($body['id']) && !isset($this->ids[(string) $body['id']])) {
                    // The same id sent again maps to the same fresh one, so a second insert meets the first as it did.
                    $this->bindId((string) $body['id'], $this->freshId());
                }
                if (isset($body['iCalUID']) && !isset($this->uids[(string) $body['iCalUID']])) {
                    $this->bindUid((string) $body['iCalUID'], $this->freshId() . '@replay.invalid');
                }
            }
            $body = $this->mapBody($body);
            if ($creates && !isset($body['extendedProperties']['private'][self::MARKER_KEY])) {
                $body['extendedProperties']['private'][self::MARKER_KEY] = $this->marker();
            }
        }
        $headers = [];
        $rid = preg_match('#^/events/([^/?]+)#', (string) $step['path'], $m) === 1 ? rawurldecode($m[1]) : '';
        foreach ($step['headers'] ?? [] as $h) {
            [$name, $value] = array_map('trim', explode(':', (string) $h, 2) + [1 => '']);
            if (in_array(strtolower($name), ['if-match', 'if-none-match'], true) && $value !== '*') {
                $mapped = $this->etagsById[$rid][$value] ?? $this->etagsAny[$value] ?? null;
                if ($mapped === null) {
                    $this->notes[] = "request {$step['n']}: an etag the stand-in never gave in this scenario, sent as recorded";
                }
                $value = $mapped ?? $value;
            }
            $headers[] = "$name: $value";
        }
        return [$path, $query, $body, $headers];
    }

    private function mapBody(array $v): array
    {
        foreach ($v as $k => $item) {
            if (is_array($item)) {
                $v[$k] = $this->mapBody($item);
            } elseif (is_string($item) && in_array($k, ['id', 'recurringEventId'], true)) {
                $v[$k] = $this->mapId($item);
            } elseif (is_string($item) && $k === 'iCalUID') {
                $v[$k] = $this->uids[$item] ?? $item;
            }
        }
        return $v;
    }

    // ---- sending ------------------------------------------------------------

    /**
     * Sends one recorded request; a GET whose answer differs goes again (readTries). A list the target splits into
     * more pages than the stand-in did is read to its end and taken as one answer.
     *
     * @return array{0:array,1:array,2:int} the request as sent, the target's answer, items ignored
     */
    private function send(array $step): array
    {
        [$path, $query, $body, $headers] = $this->mapRequest($step);
        $sent = ['method' => $step['method'], 'path' => $path, 'query' => $query, 'headers' => $headers, 'body' => $body];
        $tries = $step['method'] === 'GET' ? max(1, $this->readTries) : 1;
        for ($try = 1; ; $try++) {
            $got = $this->api->call((string) $step['method'], $path, $query, $body, $headers);
            if ($step['method'] === 'GET' && is_array($got['response']) && isset($got['response']['items'], $got['response']['nextPageToken']) && !isset($step['response']['nextPageToken'])) {
                $got = $this->rest($got, $path, $query, $headers);
            }
            $got['readTries'] = $try;
            $this->learn($step, $got); // a later try's tokens and etags replace this one's
            [$got, $ignored] = $this->ownItems($got);
            if ($try >= $tries || self::compare($step, $got, $this->idsBack, $this->uidsBack)['differs'] === []) {
                return [$sent, $got, $ignored];
            }
            ($this->sleep)($this->readPause);
        }
    }

    /**
     * A list's items left as those of events this scenario knows (by id, or an instance id's series id): the calendar
     * may hold other scenarios' events, cancelled ones a whole read still lists among them.
     *
     * @return array{0:array,1:int}
     */
    private function ownItems(array $got): array
    {
        if (!is_array($got['response']) || !isset($got['response']['items']) || !is_array($got['response']['items'])) {
            return [$got, 0];
        }
        $kept = array_values(array_filter($got['response']['items'], fn($i): bool => is_array($i) && self::backId((string) ($i['id'] ?? ''), $this->idsBack) !== null));
        $ignored = count($got['response']['items']) - count($kept);
        $got['response']['items'] = $kept;
        return [$got, $ignored];
    }

    /** The further pages of a list the target split, merged into the first answer. */
    private function rest(array $got, string $path, array $query, array $headers): array
    {
        $items = $got['response']['items'];
        $page = $got['response'];
        $pages = 1;
        while (isset($page['nextPageToken']) && $pages < 50) {
            $next = $this->api->call('GET', $path, ['pageToken' => $page['nextPageToken']] + $query, null, $headers);
            if ($next['status'] !== 200 || !is_array($next['response'])) {
                return $next;
            }
            $page = $next['response'];
            $items = array_merge($items, $page['items'] ?? []);
            $pages++;
        }
        $got['response'] = ['items' => $items] + $page;
        unset($got['response']['nextPageToken']);
        $this->notes[] = "a list came back in $pages pages where the stand-in gave one; read to its end and compared as one";
        return $got;
    }

    /** What the target issued, paired with what the stand-in issued in the same answer. */
    private function learn(array $step, array $got): void
    {
        $rec = $step['response'];
        $tgt = $got['response'];
        if (!is_array($rec) || !is_array($tgt) || $step['status'] >= 300 || $got['status'] >= 300) {
            return;
        }
        if ($step['method'] === 'POST' && isset($rec['id'], $tgt['id'])) {
            if (!isset($this->ids[(string) $rec['id']])) {
                $this->bindId((string) $rec['id'], (string) $tgt['id']);
            }
            $this->created[] = (string) $tgt['id'];
        }
        if (isset($rec['iCalUID'], $tgt['iCalUID']) && !isset($this->uids[(string) $rec['iCalUID']])) {
            $this->bindUid((string) $rec['iCalUID'], (string) $tgt['iCalUID']);
        }
        foreach (['nextSyncToken' => 'syncTokens', 'nextPageToken' => 'pageTokens'] as $k => $map) {
            if (isset($rec[$k], $tgt[$k])) {
                $this->{$map}[(string) $rec[$k]] = (string) $tgt[$k];
            }
        }
        $pairs = [];
        if (isset($rec['items'], $tgt['items'])) {
            $byId = [];
            foreach ($tgt['items'] as $t) {
                if (is_array($t) && isset($t['id'])) {
                    $byId[(string) $t['id']] = $t;
                    if (isset($t['etag'])) {
                        $this->lastEtag[(string) $t['id']] = (string) $t['etag'];
                    }
                }
            }
            foreach ($rec['items'] as $r) {
                if (is_array($r) && isset($r['id'], $byId[$this->mapId((string) $r['id'])])) {
                    $pairs[] = [$r, $byId[$this->mapId((string) $r['id'])]];
                }
                if (is_array($r) && isset($r['iCalUID'], $byId[$this->mapId((string) ($r['id'] ?? ''))]['iCalUID']) && !isset($this->uids[(string) $r['iCalUID']])) {
                    $this->bindUid((string) $r['iCalUID'], (string) $byId[$this->mapId((string) $r['id'])]['iCalUID']);
                }
            }
        } elseif (isset($rec['id'], $tgt['id']) && $this->mapId((string) $rec['id']) === (string) $tgt['id']) {
            $pairs[] = [$rec, $tgt];
            if (isset($tgt['etag'])) {
                $this->lastEtag[(string) $tgt['id']] = (string) $tgt['etag'];
            }
        }
        foreach ($pairs as [$r, $t]) {
            if (isset($r['etag'], $t['etag'])) {
                $this->etagsById[(string) $r['id']][(string) $r['etag']] = (string) $t['etag'];
                $this->etagsAny[(string) $r['etag']] = (string) $t['etag'];
            }
        }
    }

    /** Deletes every event this scenario created; a summary for the report. */
    private function cleanup(): string
    {
        $deleted = 0;
        $failed = [];
        foreach (array_unique($this->created) as $tid) {
            try {
                $r = $this->api->call('DELETE', '/events/' . rawurlencode($tid));
                in_array($r['status'], [200, 204, 404, 410], true) ? $deleted++ : $failed[] = "$tid (HTTP {$r['status']})";
            } catch (\Throwable $e) {
                $failed[] = "$tid ({$e->getMessage()})";
            }
        }
        return "deleted $deleted of " . count(array_unique($this->created)) . ' events it created' . ($failed !== [] ? '; failed: ' . implode(', ', $failed) : '');
    }

    // ---- comparing ----------------------------------------------------------

    /**
     * The recorded answer against the target's. differs: what makes it a divergence (empty when the answers agree);
     * shape: fields of a cancelled item that only one side carries or that differ outside CANCELLED_CORE.
     *
     * @return array{differs:list<array>, shape:list<array>}
     */
    public static function compare(array $step, array $got, array $idsBack, array $uidsBack): array
    {
        $differs = [];
        $shape = [];
        if ((int) $step['status'] !== (int) $got['status']) {
            $differs[] = ['what' => 'status', 'recorded' => (int) $step['status'], 'target' => (int) $got['status']];
        }
        $rec = $step['response'];
        $tgt = $got['response'];
        if ((int) $step['status'] >= 400 || (int) $got['status'] >= 400) {
            $rm = is_array($rec) ? (string) ($rec['error']['message'] ?? '') : trim((string) $rec);
            $tm = is_array($tgt) ? (string) ($tgt['error']['message'] ?? '') : trim((string) $tgt);
            if ($differs === [] && $rm !== $tm) {
                $differs[] = ['what' => 'error message', 'recorded' => $rm, 'target' => $tm];
            }
            return ['differs' => $differs, 'shape' => $shape];
        }
        if ($differs !== []) {
            return ['differs' => $differs, 'shape' => $shape];
        }
        if (!str_starts_with((string) $step['path'], '/events')) {
            return ['differs' => [], 'shape' => []]; // the calendar and its list entry: the status alone (a real calendar's name, role and zone are its own)
        }
        if (!is_array($rec) || !is_array($tgt)) {
            if (trim(is_array($rec) ? json_encode($rec) : (string) $rec) !== trim(is_array($tgt) ? json_encode($tgt) : (string) $tgt)) {
                $differs[] = ['what' => 'body', 'recorded' => $rec, 'target' => $tgt];
            }
            return ['differs' => $differs, 'shape' => $shape];
        }
        if (isset($rec['items']) || isset($tgt['items'])) {
            if (isset($rec['nextPageToken']) !== isset($tgt['nextPageToken'])) {
                $differs[] = ['what' => 'more pages', 'recorded' => isset($rec['nextPageToken']), 'target' => isset($tgt['nextPageToken'])];
            }
            $r = [];
            foreach ((array) ($rec['items'] ?? []) as $i) {
                $r[] = self::normalize($i, null, null);
            }
            $t = [];
            foreach ((array) ($tgt['items'] ?? []) as $i) {
                $t[] = self::normalize($i, $idsBack, $uidsBack);
            }
            [$d, $s] = self::compareSets($r, $t);
            return ['differs' => array_merge($differs, $d), 'shape' => $s];
        }
        $r = self::normalize($rec, null, null);
        $t = self::normalize($tgt, $idsBack, $uidsBack);
        [$d, $s] = self::compareItems($r, $t, (string) ($r['id'] ?? ''));
        return ['differs' => $d, 'shape' => $s];
    }

    /** Two lists of normalized items as sets, matched by id (and by status where an id comes twice). */
    private static function compareSets(array $rec, array $tgt): array
    {
        $key = static fn(array $i): string => (string) ($i['id'] ?? '') . '|' . (string) ($i['status'] ?? '');
        $byKey = static function (array $items) use ($key): array {
            $out = [];
            foreach ($items as $i) {
                $out[$key($i)][] = $i;
            }
            return $out;
        };
        $r = $byKey($rec);
        $t = $byKey($tgt);
        $differs = [];
        $shape = [];
        $onlyRec = [];
        $onlyTgt = [];
        foreach ($r + $t as $k => $_) {
            $ri = $r[$k] ?? [];
            $ti = $t[$k] ?? [];
            usort($ri, static fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
            usort($ti, static fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
            for ($i = 0; $i < max(count($ri), count($ti)); $i++) {
                if (!isset($ti[$i])) {
                    $onlyRec[] = $ri[$i];
                } elseif (!isset($ri[$i])) {
                    $onlyTgt[] = $ti[$i];
                } else {
                    [$d, $s] = self::compareItems($ri[$i], $ti[$i], (string) ($ri[$i]['id'] ?? ''));
                    array_push($differs, ...$d);
                    array_push($shape, ...$s);
                }
            }
        }
        if ($onlyRec !== []) {
            $differs[] = ['what' => 'items only the stand-in listed', 'items' => $onlyRec];
        }
        if ($onlyTgt !== []) {
            $differs[] = ['what' => 'items only the target listed', 'items' => $onlyTgt];
        }
        return [$differs, $shape];
    }

    /** Two normalized resources: every field, or on a cancelled one the core strictly and the rest as shape notes. */
    private static function compareItems(array $rec, array $tgt, string $id): array
    {
        $cancelled = ($rec['status'] ?? '') === 'cancelled' && ($tgt['status'] ?? '') === 'cancelled';
        $differs = [];
        $shape = [];
        foreach (array_keys($rec + $tgt) as $k) {
            $rv = $rec[$k] ?? null;
            $tv = $tgt[$k] ?? null;
            if ($rv === $tv) {
                continue;
            }
            $d = ['what' => 'field', 'id' => $id, 'field' => $k, 'recorded' => $rv, 'target' => $tv];
            if ($cancelled && !in_array($k, self::CANCELLED_CORE, true)) {
                $shape[] = $d;
            } else {
                $differs[] = $d;
            }
        }
        return [$differs, $shape];
    }

    /**
     * A resource as compared: target ids and iCalUIDs read back into the recorded namespace (when $idsBack is given),
     * IGNORED fields dropped, the replay's marker taken out of extendedProperties, each RRULE's parts in one order, keys
     * sorted. What Google writes on its own and the stand-in can model (reminders, eventType, a dateTime's offset, empty
     * fields left out) is the stand-in's to answer alike, not this function's to ignore (replay-2026-11-13.md).
     */
    public static function normalize(mixed $e, ?array $idsBack, ?array $uidsBack): mixed
    {
        if (!is_array($e)) {
            return $e;
        }
        if ($idsBack !== null) {
            foreach (['id', 'recurringEventId'] as $k) {
                if (isset($e[$k])) {
                    $e[$k] = self::backId((string) $e[$k], $idsBack) ?? '{unmapped ' . $e[$k] . '}';
                }
            }
            if (isset($e['iCalUID'])) {
                $e['iCalUID'] = $uidsBack[(string) $e['iCalUID']] ?? '{unmapped ' . $e['iCalUID'] . '}';
            }
        }
        foreach (self::IGNORED as $k) {
            unset($e[$k]);
        }
        if (isset($e['extendedProperties']['private']) && is_array($e['extendedProperties']['private'])) {
            unset($e['extendedProperties']['private'][self::MARKER_KEY]);
            if ($e['extendedProperties']['private'] === []) {
                unset($e['extendedProperties']['private']);
            }
            if ($e['extendedProperties'] === []) {
                unset($e['extendedProperties']);
            }
        }
        if (isset($e['recurrence']) && is_array($e['recurrence'])) {
            $e['recurrence'] = array_map(static function ($line) {
                if (!is_string($line) || !str_starts_with($line, 'RRULE:')) {
                    return $line;
                }
                $parts = explode(';', substr($line, 6));
                sort($parts);
                return 'RRULE:' . implode(';', $parts);
            }, $e['recurrence']);
        }
        return self::sortKeys($e);
    }

    private static function sortKeys(array $v): array
    {
        foreach ($v as $k => $item) {
            if (is_array($item)) {
                $v[$k] = self::sortKeys($item);
            }
        }
        if (!array_is_list($v)) {
            ksort($v);
        }
        return $v;
    }
}

/** The two targets' setup and the report, kept out of Replay so the test drives Replay alone. */
final class ReplayTarget
{
    /** A fresh stand-in set as the trace's was: its zone, its list settings, its occurrences. */
    public static function fake(array $trace, string $root): FakeGoogle
    {
        $fake = new FakeGoogle('replay-target@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = (string) ($trace['zone'] ?? $fake->zone);
        foreach (['order', 'orderSeed', 'listDropped', 'zoneAlone'] as $k) {
            if (isset($trace['settings'][$k])) {
                $fake->{$k} = $trace['settings'][$k];
            }
        }
        if (($trace['expand'] ?? 'built-in') === 'edit-harness') {
            foreach (['Base', 'Shape', 'Rfc', 'Truth', 'GoogleStand'] as $f) {
                require_once $root . "/tools/edit-harness/$f.php";
            }
            $fake->expand = static fn(array $master): array => \BetterCal\EditHarness\GoogleStand::expand($master);
        }
        return $fake;
    }

    /** As the probe's preflight: not the main calendar, writable, no events of others. Returns the calendar's zone. */
    public static function preflight(Api $api, bool $allowOthers): string
    {
        $entry = $api->call('GET', '/users/me/calendarList/{calendar}');
        if ($entry['status'] !== 200 || !is_array($entry['response'])) {
            throw new \RuntimeException('the account cannot see that calendar (HTTP ' . $entry['status'] . ')');
        }
        if (!empty($entry['response']['primary'])) {
            throw new \RuntimeException('that is the account\'s main calendar; the replay needs a throwaway one');
        }
        if (!in_array((string) ($entry['response']['accessRole'] ?? ''), ['writer', 'owner'], true)) {
            throw new \RuntimeException('the account may not write to that calendar');
        }
        $cal = $api->call('GET', '');
        $others = array_filter(self::listAll($api), static fn(array $e): bool => !isset($e['extendedProperties']['private'][Replay::MARKER_KEY]) && !str_contains((string) ($e['description'] ?? ''), '[better-cal-probe]'));
        if ($others !== [] && !$allowOthers) {
            throw new \RuntimeException('that calendar has ' . count($others) . ' events neither the probe nor a replay made; use an empty calendar, or pass --allow-other-events (they are never touched)');
        }
        return (string) (is_array($cal['response']) ? ($cal['response']['timeZone'] ?? 'UTC') : 'UTC');
    }

    /** @return list<array> every live event and series on the calendar */
    public static function listAll(Api $api): array
    {
        $items = [];
        $query = ['showDeleted' => 'false', 'singleEvents' => 'false', 'maxResults' => '2500'];
        for ($i = 0; $i < 50; $i++) {
            $r = $api->call('GET', '/events', $query);
            if ($r['status'] !== 200 || !is_array($r['response'])) {
                throw new \RuntimeException('listing the calendar failed (HTTP ' . $r['status'] . ')');
            }
            $items = array_merge($items, $r['response']['items'] ?? []);
            if (!isset($r['response']['nextPageToken'])) {
                break;
            }
            $query['pageToken'] = $r['response']['nextPageToken'];
        }
        return $items;
    }

    /** Deletes the live events an earlier replay left; returns how many. */
    public static function sweep(Api $api): int
    {
        $n = 0;
        foreach (self::listAll($api) as $e) {
            if (str_starts_with((string) ($e['extendedProperties']['private'][Replay::MARKER_KEY] ?? ''), Replay::MARKER_PREFIX) && empty($e['recurringEventId'])) {
                $r = $api->call('DELETE', '/events/' . rawurlencode((string) $e['id']));
                $n += in_array($r['status'], [200, 204, 410], true) ? 1 : 0;
            }
        }
        return $n;
    }

    /** @return array{0:string,1:string} the markdown and JSON paths */
    public static function write(Report $scrub, string $dir, array $run, array $results, array $coverage): array
    {
        $stamp = (new \DateTimeImmutable('now', Api::localZone()))->format('Y-m-d\THisO');
        $md = rtrim($dir, '/') . "/replay-$stamp.md";
        $json = rtrim($dir, '/') . "/replay-$stamp.json";
        $data = $scrub->scrub(['run' => $run, 'coverage' => $coverage, 'scenarios' => $results]);
        file_put_contents($json, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        $cell = static fn(string $s): string => str_replace(['|', "\r", "\n"], ['\\|', '', ' '], $s);
        $tally = array_count_values(array_column($results, 'status'));
        $out = "# Google Replay Report\n\n";
        $out .= "Target: {$run['target']}" . (isset($run['calendarZone']) ? " (calendar zone {$run['calendarZone']})" : '') . ". Run {$run['started']} to {$run['finished']}; {$run['requests']} requests sent. ";
        $out .= count($results) . ' scenarios: ' . implode(', ', array_map(static fn($k, $v) => "$v $k", array_keys($tally), $tally)) . ".\n\n";
        foreach ($data['run']['notes'] ?? [] as $n) {
            $out .= '- ' . $cell((string) $n) . "\n";
        }
        $out .= "\nA scenario stops at its first divergence: later requests depend on it. The rules each answer went through before comparing are in tools/google-probe/README.md. Addresses, the calendar id, event links and tokens are replaced by placeholders here and in the JSON.\n\n";
        $out .= "## Scenarios\n\n| Scenario | Result | Requests | Stopped at | What differed | Notes |\n|---|---|---|---|---|---|\n";
        foreach ($data['scenarios'] as $r) {
            $what = '';
            if (!empty($r['divergence'])) {
                $what = implode('; ', array_map(static fn(array $d): string => $d['what'] . (isset($d['field']) ? ' ' . $d['field'] . ' of ' . $d['id'] : '') . (isset($d['items']) ? ' (' . count($d['items']) . ')' : ''), array_slice($r['divergence']['difference'], 0, 4)));
                $q = $r['divergence']['request'];
                $what = "{$q['method']} {$q['path']}: $what";
            } elseif (!empty($r['reason'])) {
                $what = (string) $r['reason'];
            }
            $notes = count($r['shape'] ?? []) > 0 ? count($r['shape']) . ' cancelled-item shape notes' : '';
            $notes .= ($r['ignored'] ?? 0) > 0 ? ($notes !== '' ? '; ' : '') . $r['ignored'] . ' items of other events left out' : '';
            $out .= '| ' . $cell((string) $r['scenario']) . ' | ' . $r['status'] . ' | ' . ($r['steps'] ?? 0) . ' | ' . ($r['at'] ?? '') . ' | ' . $cell($what) . ' | ' . $cell($notes) . " |\n";
        }
        $out .= "\n## What the Traces Cover\n\n| Kind | Scenarios | Times |\n|---|---|---|\n";
        foreach ($coverage as $k => $c) {
            $out .= "| $k | {$c['scenarios']} | {$c['times']} |\n";
        }
        foreach ($data['scenarios'] as $r) {
            if (empty($r['divergence']) && empty($r['shape'])) {
                continue;
            }
            $out .= "\n## {$r['scenario']}\n\n";
            if (!empty($r['divergence'])) {
                $out .= "Diverged at request {$r['at']}. The request, both answers and the difference after normalizing:\n\n```json\n" . json_encode($r['divergence'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n```\n";
            }
            if (!empty($r['shape'])) {
                $out .= "\nCancelled items whose fields beyond id, status, recurringEventId and originalStartTime differ (not a divergence, listed so it can be judged):\n\n```json\n" . json_encode(array_slice($r['shape'], 0, 20), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n```\n";
            }
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($md, $out);
        return [$md, $json];
    }
}
