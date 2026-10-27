<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Plugins;
use BetterCal\Domain\Proposals;
use BetterCal\Domain\Undo;
use BetterCal\Domain\RequestAuthority;
use BetterCal\Infra\Db;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Infra\JobQueue;
use BetterCal\Support\Time;

/**
 * Ops surface for the plugin system. Nothing here executes plugin code —
 * the one exception is the synchronous validateSettings hook inside
 * Plugins::saveSettings, which runs only on an explicit save.
 */
final class PluginsController
{
    public function __construct(
        private readonly Plugins $plugins,
        private readonly JobQueue $queue,
        private readonly ?Proposals $proposals = null,
        private readonly ?Undo $undo = null,
        private readonly ?Db $db = null,
    ) {
    }

    public function index(Request $req): Response
    {
        return Response::json(['plugins' => $this->plugins->listForOps((int) $req->user['id'])]);
    }

    public function enable(Request $req, array $params): Response
    {
        $authority = RequestAuthority::request($req);
        $this->plugins->withAuthority($authority, fn() => $this->plugins->enable((string) $params['id'], $authority->tokenId()));
        return Response::json(['ok' => true]);
    }

    public function disable(Request $req, array $params): Response
    {
        $this->plugins->withAuthority(RequestAuthority::request($req), fn() => $this->plugins->disable((string) $params['id']));
        return Response::json(['ok' => true]);
    }

    public function uninstall(Request $req, array $params): Response
    {
        $req->requireSession('Plugin management');
        $deleteCalendars = filter_var($req->body['deleteCalendars'] ?? false, FILTER_VALIDATE_BOOL);
        $impact = $this->plugins->uninstall((int) $req->user['id'], (string) $params['id'], $deleteCalendars);
        return Response::json(['ok' => true, 'purged' => $impact]);
    }

    public function saveSettings(Request $req, array $params): Response
    {
        $id = (string) $params['id'];
        $settings = $this->plugins->saveSettings($id, is_array($req->body) ? $req->body : [], RequestAuthority::request($req));
        // Jobs marked runOnSave run right after a save, so choices a plugin
        // discovers from what was just entered (a service's databases once a
        // key is in) show up within a minute rather than at the next interval.
        $onSave = [];
        foreach (($this->plugins->manifest($id)['jobs'] ?? []) as $job) {
            if (!empty($job['runOnSave'])) {
                $onSave[] = (string) $job['id'];
            }
        }
        $queued = [];
        // Only a save that changed something: an empty one is no reason to run.
        if ($onSave !== [] && is_array($req->body) && $req->body !== [] && $this->plugins->missingRequired($id) === []) {
            $result = $this->plugins->withAuthority(RequestAuthority::request($req), fn() => $this->queue->enqueuePluginJobs((int) $req->user['id'], $id, $onSave));
            $queued = array_merge($result['queued'] ?? [], $result['alreadyQueued'] ?? []);
        }
        return Response::json(['settings' => $settings, 'queued' => $queued]);
    }

    public function saveCalendarSettings(Request $req, array $params): Response
    {
        return Response::json(['settings' => $this->plugins->saveCalendarSettings(
            (int) $req->user['id'],
            (int) $params['calendarId'],
            (string) $params['id'],
            is_array($req->body) ? $req->body : [],
            RequestAuthority::request($req),
        )]);
    }

    // ---- calendars the person adds (ownCalendars) ---------------------------

    /**
     * The jobs that fill a new or changed calendar: those marked runOnSave,
     * or every job when none is. Queued for that calendar alone.
     *
     * @return list<string>
     */
    private function queueForCalendar(Request $req, string $id, int $calendarId): array
    {
        $ids = $this->plugins->calendarJobIds($id);
        if ($ids === []) {
            return [];
        }
        $result = $this->plugins->withAuthority(RequestAuthority::request($req), fn() => $this->queue->enqueuePluginJobs((int) $req->user['id'], $id, $ids, false, null, $calendarId));
        return array_merge($result['queued'] ?? [], $result['alreadyQueued'] ?? []);
    }

    /** Add one of a plugin's calendars: {name?, color?, settings}. */
    public function createCalendar(Request $req, array $params): Response
    {
        $id = (string) $params['id'];
        $body = is_array($req->body) ? $req->body : [];
        $calendarId = $this->plugins->createOwnCalendar(
            (int) $req->user['id'],
            $id,
            is_string($body['name'] ?? null) ? $body['name'] : null,
            is_string($body['color'] ?? null) ? $body['color'] : null,
            is_array($body['settings'] ?? null) ? $body['settings'] : [],
            RequestAuthority::request($req),
        );
        return Response::json(['calendarId' => $calendarId, 'queued' => $this->queueForCalendar($req, $id, $calendarId)], 201);
    }

    /** Change one of a plugin's calendars' own settings (only the keys sent). */
    public function saveOwnCalendar(Request $req, array $params): Response
    {
        $id = (string) $params['id'];
        $calendarId = (int) $params['calendarId'];
        $settings = $this->plugins->saveOwnCalendarSettings((int) $req->user['id'], $calendarId, $id, is_array($req->body) ? $req->body : [], RequestAuthority::request($req));
        $queued = [];
        if (is_array($req->body) && $req->body !== []) {
            $queued = $this->queueForCalendar($req, $id, $calendarId);
            // Its place may have changed: calendars following it refill too.
            $this->plugins->withAuthority(RequestAuthority::request($req), fn() => $this->plugins->queuePlaceFollowers((int) $req->user['id'], $calendarId, $this->queue));
        }
        return Response::json(['settings' => $settings, 'queued' => $queued]);
    }

    /** Matches for a searchable setting: ?q= text, or ?value= one exact value (its label). */
    public function searchOptions(Request $req, array $params): Response
    {
        $value = isset($req->query['value']) ? (string) $req->query['value'] : null;
        return Response::json(['options' => $this->plugins->searchOptions(
            (string) $params['id'],
            (string) $params['field'],
            mb_substr((string) ($req->query['q'] ?? ''), 0, 100),
            $value,
        )]);
    }

    /** "Run now": enqueue every job (or one, via ?job=) for the worker's next tick. */
    public function runNow(Request $req, array $params): Response
    {
        $id = (string) $params['id'];
        $only = isset($req->query['job']) ? (string) $req->query['job'] : null;
        $m = $this->plugins->manifest($id);
        if ($m === null) {
            throw HttpError::badRequest('Unknown plugin');
        }
        $missing = $this->plugins->missingRequired($id, $m);
        if ($missing !== []) {
            throw HttpError::conflict('plugin_needs_setup', 'Fill in ' . implode(' and ', $missing) . ' first.');
        }
        $jobIds = [];
        foreach (($m['jobs'] ?? []) as $job) {
            $jobId = (string) $job['id'];
            if ($only !== null && $jobId !== $only) {
                continue;
            }
            $jobIds[] = $jobId;
        }
        if ($only !== null && $jobIds === []) {
            throw HttpError::badRequest('Unknown plugin job', 'unknown_plugin_job');
        }
        $result = $this->plugins->withAuthority(RequestAuthority::request($req), fn() => $this->queue->enqueuePluginJobs((int) $req->user['id'], $id, $jobIds, true));
        if ($result['status'] === 'disabled') {
            throw HttpError::conflict('plugin_disabled', 'Enable this plugin before running it.');
        }
        if ($result['status'] === 'capacity') {
            throw new HttpError('plugin_run_capacity', 'Too many plugin jobs are already waiting. Let them finish before running more.', 429);
        }
        if ($result['status'] === 'rate') {
            throw new HttpError('plugin_run_rate_limited', 'Too many manual plugin runs were requested. Try again later.', 429);
        }
        return Response::json(['queued' => $result['queued'], 'alreadyQueued' => $result['alreadyQueued']]);
    }

    public function warnings(Request $req, array $params): Response
    {
        return Response::json(['warnings' => $this->plugins->warnings((string) $params['id'])]);
    }

    // ---- per-event plugin data (C7) --------------------------------------

    /**
     * Every plugin's keyed values for one event. Fetched when the user OPENS
     * an event, never as part of the events window — that separation is the
     * whole reason this endpoint exists.
     */
    public function eventData(Request $req, array $params): Response
    {
        $eventId = (int) $params['id'];
        $this->requireOwnedEvent($req, $eventId);
        $out = [];
        foreach ($this->db->all(
            'SELECT plugin_id, k, v_json FROM event_plugin_data WHERE event_id = ?',
            [$eventId]
        ) as $r) {
            $out[(string) $r['plugin_id']][(string) $r['k']] = json_decode((string) $r['v_json'], true);
        }
        foreach ($out as $pluginId => &$values) $values = $this->plugins->publicValues($pluginId, 'eventSettings', $values);
        unset($values);
        return Response::json(['data' => $out]);
    }

    /** Save the user's answers to one plugin's event-scope controls (C8). */
    public function saveEventData(Request $req, array $params): Response
    {
        $eventId = (int) $params['id'];
        $pluginId = (string) $params['pluginId'];
        $this->requireOwnedEvent($req, $eventId);
        $m = $this->plugins->manifest($pluginId);
        if ($m === null) {
            throw HttpError::badRequest('Unknown plugin');
        }
        [$clean, $errs] = Plugins::validateAgainstSchema($m['eventSettings'] ?? [], is_array($req->body) ? $req->body : []);
        if ($errs !== []) {
            throw HttpError::badRequest('Invalid values: ' . json_encode($errs));
        }
        $saved = $this->plugins->withAuthority(RequestAuthority::request($req), function () use ($req, $eventId, $pluginId, $clean): array {
            $this->requireOwnedEvent($req, $eventId);
            $sealed = $this->plugins->sealValues($pluginId, 'eventSettings', $clean);
            foreach ($sealed as $k => $v) {
                if ($v === null) {
                    $this->db->run('DELETE FROM event_plugin_data WHERE event_id = ? AND plugin_id = ? AND k = ?', [$eventId, $pluginId, $k]);
                    continue;
                }
                $this->db->run(
                    'INSERT INTO event_plugin_data (event_id, plugin_id, k, v_json) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE v_json = VALUES(v_json)',
                    [$eventId, $pluginId, mb_substr((string) $k, 0, 120), json_encode($v, JSON_INVALID_UTF8_SUBSTITUTE)]
                );
            }
            $stored = [];
            foreach ($this->db->all('SELECT k, v_json FROM event_plugin_data WHERE event_id = ? AND plugin_id = ?', [$eventId, $pluginId]) as $row) {
                $stored[(string) $row['k']] = json_decode((string) $row['v_json'], true);
            }
            return $this->plugins->publicValues($pluginId, 'eventSettings', $stored);
        });
        return Response::json(['saved' => $saved]);
    }

    private function requireOwnedEvent(Request $req, int $eventId): void
    {
        $row = $this->db->one('SELECT id FROM events WHERE id = ? AND user_id = ?', [$eventId, (int) $req->user['id']]);
        if ($row === null) {
            throw HttpError::notFound('No such event');
        }
    }

    // ---- run-grouped undo (C10) -------------------------------------------

    /** Reverse every mutation one plugin run made, newest first. */
    public function undoRun(Request $req, array $params): Response
    {
        $req->requireSession('Plugin management');
        $result = $this->undo->undoRun((int) $req->user['id'], (string) $params['runId']);
        return Response::json($result);
    }

    /** Overlay ranges for the window: an indexed read of job-written rows. */
    public function ranges(Request $req): Response
    {
        // parseIso throws InvalidArgumentException on a missing or malformed
        // value, which surfaced as a 500 rather than telling the caller what
        // was wrong with their request.
        try {
            $start = Time::parseIso((string) ($req->query['start'] ?? ''));
            $end = Time::parseIso((string) ($req->query['end'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            throw HttpError::badRequest('start and end are required ISO timestamps');
        }
        if ($end <= $start) {
            throw HttpError::badRequest('end must be after start');
        }
        return Response::json(['plugins' => $this->plugins->rangesForWindow($start, $end)]);
    }
}
