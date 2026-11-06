<?php

declare(strict_types=1);

// Shared by replay.php and every case: an in-process client for one
// checkout's HTTP controllers, and the few readings the cases need.
//
// The checkout's own classes are loaded (replay.php requires its
// bootstrap), so the same calls reach whichever version is booted. A request
// is a BetterCal\Http\Request handed to that checkout's EventsController, as
// its entry-web.php does after it has resolved an API key; the two undo
// routes, which entry-web.php writes as closures, are the same two calls to
// its Undo. Where a version wraps a request (1.5.1's RequestAuthority::during
// with the credential it signed in with), the client does the same.

use BetterCal\Domain;
use BetterCal\Http\Controllers\EventsController;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;

/** Thrown by a case when what it needs does not exist in this version: the reason is the evidence. */
final class NotApplicable extends \RuntimeException
{
}

final class Api
{
    public readonly Domain\Events $events;
    public readonly Domain\Undo $undo;
    public readonly EventsController $controller;
    public readonly int $calendarId;
    public readonly bool $plans;
    /** The journal entries the last write made (newest mutation ids), for undo by entry. */
    public array $lastEntries = [];

    public function __construct(public readonly Db $db, public readonly int $userId, private readonly int $tokenId)
    {
        $this->undo = new Domain\Undo($db);
        $filters = new Domain\Filters($db, $this->undo, new JobQueue($db));
        $trips = new Domain\Trips($db, $this->undo);
        $this->events = new Domain\Events($db, new Domain\Recurrence(), $this->undo, new Domain\Labels($db), $filters, $trips);
        $this->controller = new EventsController($this->events, $trips, null);
        $this->calendarId = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ? ORDER BY id LIMIT 1', [$userId]);
        $this->plans = method_exists($this->events, 'previewPatch');
    }

    /** A new account with an API key, as an install makes the owner. */
    public static function fresh(Db $db): self
    {
        $userId = \BetterCal\Infra\Setup::createOwner($db, 'owner-' . bin2hex(random_bytes(4)) . '@example.test', 'correct horse battery');
        $db->run('INSERT INTO api_tokens (user_id, name, token_hash) VALUES (?, ?, ?)', [$userId, 'Sample key', hash('sha256', random_bytes(16))]);
        return new self($db, $userId, (int) $db->pdo()->lastInsertId());
    }

    /**
     * One request: [status, decoded body]. An HttpError is the error body
     * entry-web.php writes ({error:{code, message, ...extra}}); anything else
     * thrown is a 500 with its class and message.
     *
     * @return array{0:int,1:mixed}
     */
    public function call(string $method, string $path, array $body = [], array $query = []): array
    {
        $req = new Request($method, '/api/v1' . $path, $query, $body);
        $req->user = ['id' => $this->userId];
        $req->authMethod = 'token';
        $req->tokenId = $this->tokenId;
        Domain\ActivityContext::set('api');
        $before = (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM mutations WHERE user_id = ?', [$this->userId]);
        $handler = fn() => $this->dispatch($req, $method, $path);
        try {
            if (method_exists(Domain\RequestAuthority::class, 'of') && method_exists(Domain\RequestAuthority::class, 'during')) {
                $response = Domain\RequestAuthority::during(Domain\RequestAuthority::of($req), $handler, $method !== 'GET');
            } else {
                $response = $handler();
            }
            if (method_exists(Domain\ActivityContext::class, 'takeEntries')) {
                Domain\ActivityContext::takeEntries();
            }
            $this->lastEntries = array_map('intval', array_column($this->db->all(
                'SELECT id FROM mutations WHERE user_id = ? AND id > ? AND undone = 0 ORDER BY id', [$this->userId, $before]), 'id'));
            return [$response->status, json_decode($response->body, true)];
        } catch (HttpError $e) {
            $extra = property_exists($e, 'extra') ? $e->extra : [];
            return [$e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + $extra]];
        } catch (\Throwable $e) {
            return [500, ['error' => ['code' => get_class($e), 'message' => $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine()]]];
        }
    }

    private function dispatch(Request $req, string $method, string $path): \BetterCal\Http\Response
    {
        $c = $this->controller;
        if ($path === '/events' && $method === 'GET') {
            return $c->window($req);
        }
        if ($path === '/events' && $method === 'POST') {
            return $c->create($req);
        }
        if ($path === '/undo' && $method === 'POST') {
            $undone = $this->hasAuthorityArg('undoLatest')
                ? $this->undo->undoLatest($this->userId, Domain\RequestAuthority::request($req))
                : $this->undo->undoLatest($this->userId);
            return \BetterCal\Http\Response::json(['ok' => true, 'undone' => $undone]);
        }
        if (preg_match('#^/activity/(\d+)/undo$#', $path, $m) && $method === 'POST') {
            $force = (bool) ($req->body['force'] ?? false);
            $undone = $this->hasAuthorityArg('undoById')
                ? $this->undo->undoById($this->userId, (int) $m[1], $force, Domain\RequestAuthority::request($req))
                : $this->undo->undoById($this->userId, (int) $m[1], $force);
            return \BetterCal\Http\Response::json(['ok' => true, 'undone' => $undone]);
        }
        if (preg_match('#^/events/(\d+)(/[a-z]+)?$#', $path, $m)) {
            $params = ['id' => $m[1]];
            $tail = $m[2] ?? '';
            return match (true) {
                $tail === '' && $method === 'PATCH' => $c->patch($req, $params),
                $tail === '' && $method === 'DELETE' => $c->delete($req, $params),
                $tail === '/attendance' && $method === 'POST' => $c->attendance($req, $params),
                $tail === '/relationship' && $method === 'POST' => $c->relationship($req, $params),
                $tail === '/occurrence' && $method === 'GET' => \BetterCal\Http\Response::json(['occurrence' => $this->events->serializeSingle($this->events->get($this->userId, (int) $m[1]))]),
                default => throw new \LogicException("no route for $method $path"),
            };
        }
        throw new \LogicException("no route for $method $path");
    }

    private function hasAuthorityArg(string $fn): bool
    {
        foreach ((new \ReflectionMethod($this->undo, $fn))->getParameters() as $p) {
            if ($p->getName() === 'authority') {
                return true;
            }
        }
        return false;
    }

    /** Create an event; its answer (the first occurrence). Throws when refused. */
    public function create(array $fields): array
    {
        [$status, $body] = $this->call('POST', '/events', $fields + ['calendarId' => $this->calendarId, 'allDay' => false]);
        if ($status !== 201) {
            throw new \RuntimeException('create refused: ' . json_encode($body));
        }
        return $body;
    }

    /** The occurrences GET /events gives for a window (ISO with offset). */
    public function window(string $from, string $to): array
    {
        [$status, $body] = $this->call('GET', '/events', [], ['start' => $from, 'end' => $to]);
        if ($status !== 200) {
            throw new \RuntimeException('window refused: ' . json_encode($body));
        }
        return $body['events'];
    }

    /**
     * A series change as each version's client makes it. Before series plans
     * (1.3, 1.4) the request is sent as it is. With them (1.5.x) a scope that
     * needs a plan is previewed, every group named (its preselected option
     * unless $choices names one; an option $choices names that a group
     * doesn't offer is left to the preselection), and committed with the
     * plan's hash; a plan that says `direct` or `whole` is sent without one.
     * Returns [status, body, plan|null].
     *
     * @return array{0:int,1:mixed,2:?array}
     */
    public function change(string $method, string $path, array $body, array $choices = []): array
    {
        $scope = $body['scope'] ?? null;
        if (!$this->plans || !in_array($scope, ['all', 'following'], true)) {
            return [...$this->call($method, $path, $body), null];
        }
        [$status, $preview] = $this->call($method, $path, $body + ['preview' => true, 'plan' => ['choices' => []]]);
        if ($status === 400 && (($preview['error']['code'] ?? '') === 'preview_unavailable')) {
            return [...$this->call($method, $path, $body), null];
        }
        if ($status !== 200) {
            return [$status, $preview, null];
        }
        $plan = $preview['plan'];
        if (!empty($plan['direct']) || !empty($plan['whole'])) {
            return [...$this->call($method, $path, $body), $plan];
        }
        $sel = [];
        for ($round = 0; $round < 3; $round++) {
            $next = [];
            foreach ($plan['groups'] ?? [] as $g) {
                $offered = array_column(array_filter($g['options'], static fn(array $o): bool => empty($o['disabled'])), 'id');
                $want = $choices[$g['id']] ?? null;
                $next[$g['id']] = $want !== null && in_array($want, $offered, true) ? $want : ($sel[$g['id']] ?? $g['selected']);
            }
            if ($next === $sel && $round > 0) {
                break;
            }
            $sel = $next;
            [$status, $preview] = $this->call($method, $path, $body + ['preview' => true, 'plan' => ['choices' => $sel]]);
            if ($status !== 200) {
                return [$status, $preview, null];
            }
            $plan = $preview['plan'];
        }
        return [...$this->call($method, $path, $body + ['plan' => ['hash' => $plan['hash'], 'choices' => $sel]]), $plan];
    }

    /** Undo the newest entry, as the Undo button does. */
    public function undoLatest(): array
    {
        return $this->call('POST', '/undo');
    }

    /** The account's rows of events (master, edited days, standalone). */
    public function rows(): array
    {
        return $this->db->all('SELECT * FROM events WHERE user_id = ? ORDER BY id', [$this->userId]);
    }
}

/** An occurrence's start read as a wall clock in a zone: 'Y-m-d H:i D'. */
function clockIn(string $iso, string $tz): string
{
    return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d H:i D');
}

/** Occurrences on a local day in a zone. */
function onDay(array $occurrences, string $day, string $tz): array
{
    return array_values(array_filter($occurrences, static fn(array $o): bool => substr(clockIn($o['start'], $tz), 0, 10) === $day));
}

/** Short evidence for a list of occurrences: "Tue 2027-01-12 18:00 'Title'". */
function brief(array $occurrences, string $tz, int $max = 8): string
{
    $out = array_map(static function (array $o) use ($tz): string {
        $c = clockIn($o['start'], $tz);
        return substr($c, 17) . ' ' . substr($c, 0, 16) . " '" . ($o['title'] ?? '') . "'" . (!empty($o['isException']) ? ' (edited)' : '') . (empty($o['recurring']) && empty($o['isException']) ? ' (own event)' : '');
    }, array_slice($occurrences, 0, $max));
    return implode('; ', $out) . (count($occurrences) > $max ? '; ...' . count($occurrences) . ' in all' : '');
}

/** The error code of an answer, or ''. */
function code(mixed $body): string
{
    return is_array($body) ? (string) ($body['error']['code'] ?? '') : '';
}

/** Require a 2xx answer, else stop the case with the answer as evidence. */
function ok(array $answer, string $what): mixed
{
    [$status, $body] = $answer;
    if ($status >= 300) {
        throw new \RuntimeException("$what answered $status " . substr(json_encode($body), 0, 300));
    }
    return $body;
}

/** One check of a case: pass true, false, or null (not applicable here, with why). */
function chk(string $name, ?bool $pass, string $evidence): array
{
    return ['name' => $name, 'pass' => $pass, 'evidence' => $evidence];
}

/** The occurrence on a local day (the first), or throw: a case's setup needs it. */
function theOne(array $occurrences, string $day, string $tz): array
{
    $on = onDay($occurrences, $day, $tz);
    if ($on === []) {
        throw new \RuntimeException("no occurrence on $day");
    }
    return $on[0];
}
