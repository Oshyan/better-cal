<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Events;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Support\Time;

final class EventsController
{
    public function __construct(private readonly Events $events)
    {
    }

    public function window(Request $req): Response
    {
        $startRaw = $req->q('start');
        $endRaw = $req->q('end');
        if ($startRaw === null || $endRaw === null) {
            throw HttpError::badRequest('start and end query params are required');
        }
        try {
            $start = Time::parseIso($startRaw);
            $end = Time::parseIso($endRaw);
        } catch (\InvalidArgumentException $e) {
            throw HttpError::badRequest($e->getMessage());
        }
        $calendars = null;
        $calendarsRaw = $req->q('calendars');
        if ($calendarsRaw !== null) {
            $calendars = array_values(array_filter(array_map('intval', explode(',', $calendarsRaw)), static fn(int $id) => $id > 0));
        }
        $occurrences = $this->events->window(
            (int) $req->user['id'],
            $start,
            $end,
            $calendars,
            $req->q('q'),
            filter_var($req->q('includeHidden', '0'), FILTER_VALIDATE_BOOL)
        );
        return Response::json(['events' => $occurrences]);
    }

    public function create(Request $req): Response
    {
        try {
            $occurrence = $this->events->create((int) $req->user['id'], $req->body);
        } catch (\InvalidArgumentException $e) {
            throw HttpError::badRequest($e->getMessage());
        }
        return Response::json($occurrence, 201);
    }

    public function patch(Request $req, array $params): Response
    {
        try {
            $this->events->patch((int) $req->user['id'], (int) $params['id'], $req->body);
        } catch (\InvalidArgumentException $e) {
            throw HttpError::badRequest($e->getMessage());
        }
        return Response::json(['ok' => true]);
    }

    public function delete(Request $req, array $params): Response
    {
        try {
            $this->events->deleteEvent(
                (int) $req->user['id'],
                (int) $params['id'],
                $req->str('scope') ?? $req->q('scope'),
                $req->str('instanceStart') ?? $req->q('instanceStart')
            );
        } catch (\InvalidArgumentException $e) {
            throw HttpError::badRequest($e->getMessage());
        }
        return Response::json(['ok' => true]);
    }

    public function attendance(Request $req, array $params): Response
    {
        $this->events->setAttendance(
            (int) $req->user['id'],
            (int) $params['id'],
            (string) ($req->str('attendance') ?? '')
        );
        return Response::json(['ok' => true]);
    }
}
