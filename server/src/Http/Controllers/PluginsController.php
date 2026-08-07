<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Plugins;
use BetterCal\Domain\Proposals;
use BetterCal\Domain\Undo;
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
        $this->plugins->enable((string) $params['id']);
        return Response::json(['ok' => true]);
    }

    public function disable(Request $req, array $params): Response
    {
        $this->plugins->disable((string) $params['id']);
        return Response::json(['ok' => true]);
    }

    public function uninstall(Request $req, array $params): Response
    {
        $deleteCalendars = filter_var($req->body['deleteCalendars'] ?? false, FILTER_VALIDATE_BOOL);
        $impact = $this->plugins->uninstall((int) $req->user['id'], (string) $params['id'], $deleteCalendars);
        return Response::json(['ok' => true, 'purged' => $impact]);
    }

    public function saveSettings(Request $req, array $params): Response
    {
        return Response::json(['settings' => $this->plugins->saveSettings((string) $params['id'], is_array($req->body) ? $req->body : [])]);
    }

    public function saveCalendarSettings(Request $req, array $params): Response
    {
        return Response::json(['settings' => $this->plugins->saveCalendarSettings(
            (int) $req->user['id'],
            (int) $params['calendarId'],
            (string) $params['id'],
            is_array($req->body) ? $req->body : []
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
        $queued = [];
        foreach (($m['jobs'] ?? []) as $job) {
            $jobId = (string) $job['id'];
            if ($only !== null && $jobId !== $only) {
                continue;
            }
            $this->queue->enqueue('plugin_job', ['plugin' => $id, 'job' => $jobId]);
            $queued[] = $jobId;
        }
        return Response::json(['queued' => $queued]);
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
        foreach ($clean as $k => $v) {
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
        return Response::json(['saved' => $clean]);
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
        $result = $this->undo->undoRun((int) $req->user['id'], (string) $params['runId']);
        return Response::json($result);
    }

    /** Overlay ranges for the window: an indexed read of job-written rows. */
    public function ranges(Request $req): Response
    {
        $start = Time::parseIso((string) ($req->query['start'] ?? ''));
        $end = Time::parseIso((string) ($req->query['end'] ?? ''));
        if ($end <= $start) {
            throw HttpError::badRequest('end must be after start');
        }
        return Response::json(['plugins' => $this->plugins->rangesForWindow($start, $end)]);
    }
}
