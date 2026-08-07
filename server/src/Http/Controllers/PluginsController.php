<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Plugins;
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
