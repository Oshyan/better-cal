<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Events;
use BetterCal\Domain\Trips;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Support\Time;

final class EventsController
{
    public function __construct(
        private readonly Events $events,
        private readonly Trips $trips,
        private readonly ?\BetterCal\Domain\Rsvp $rsvp = null,
    ) {
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
        // A window longer than the domain's cap used to be cut short there
        // without a word, and the caller took the part it got for the whole.
        // Refuse it instead: a client asks for long spans in pieces.
        if ($end->getTimestamp() - $start->getTimestamp() > Events::MAX_WINDOW_SECONDS) {
            throw HttpError::badRequest('A window can cover at most two years; ask for longer spans in pieces.');
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
        // A series with more occurrences in this window than the per-series
        // cap is named, so a client can say so instead of showing fewer (#23).
        $capped = $this->events->cappedSeries();
        return Response::json(['events' => $occurrences] + ($capped !== [] ? ['capped' => $capped] : []));
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

    /** POST /events/:id/copy {calendarId, scope?: this|all, instanceStart?} -> the new event's occurrence */
    public function copy(Request $req, array $params): Response
    {
        $calendarId = (int) ($req->body['calendarId'] ?? 0);
        if ($calendarId <= 0) {
            throw HttpError::badRequest('calendarId is required');
        }
        $scope = (string) ($req->str('scope') ?? 'all');
        if (!in_array($scope, ['this', 'all'], true)) {
            throw HttpError::badRequest('scope must be this|all');
        }
        try {
            $occurrence = $this->events->copyTo((int) $req->user['id'], (int) $params['id'], $calendarId, $scope, $req->str('instanceStart'));
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

    public function rsvp(Request $req, array $params): Response
    {
        $req->requireSession('Invitation replies');
        if ($this->rsvp === null) {
            throw HttpError::notFound('RSVP unavailable');
        }
        return Response::json($this->rsvp->answer((int) $req->user['id'], (int) $params['id'], (string) ($req->str('answer') ?? '')));
    }

    /** POST /events/:id/relationship {relationship, scope?, instanceStart?} -> {ok:true} */
    public function relationship(Request $req, array $params): Response
    {
        $scope = $req->str('scope');
        if ($scope !== null && !in_array($scope, ['this', 'following', 'all'], true)) {
            throw HttpError::badRequest('scope must be this|following|all');
        }
        $this->events->setRelationship(
            (int) $req->user['id'],
            (int) $params['id'],
            (string) ($req->str('relationship') ?? ''),
            $scope,
            $req->str('instanceStart')
        );
        return Response::json(['ok' => true]);
    }

    public function attendance(Request $req, array $params): Response
    {
        $scope = $req->str('scope');
        if ($scope !== null && !in_array($scope, ['this', 'following', 'all'], true)) {
            throw HttpError::badRequest('scope must be this|following|all');
        }
        $this->events->setAttendance(
            (int) $req->user['id'],
            (int) $params['id'],
            (string) ($req->str('attendance') ?? ''),
            $scope,
            $req->str('instanceStart')
        );
        return Response::json(['ok' => true]);
    }

    // ---- Trip links ---------------------------------------------------

    /** GET /events/:id/links — every member of a trip, chronological. */
    public function links(Request $req, array $params): Response
    {
        $rows = $this->trips->listMembers((int) $req->user['id'], (int) $params['id']);
        return Response::json(['events' => $this->events->serializeRows($rows)]);
    }

    /** POST /events/:id/links {eventId} — attach an event to a trip. */
    public function attachLink(Request $req, array $params): Response
    {
        $eventId = (int) ($req->str('eventId') ?? 0);
        if ($eventId <= 0) {
            throw HttpError::badRequest('eventId is required');
        }
        $this->trips->attach((int) $req->user['id'], (int) $params['id'], $eventId);
        return Response::json(['ok' => true], 201);
    }

    /** DELETE /events/:id/links/:eventId — detach; the member survives. */
    public function detachLink(Request $req, array $params): Response
    {
        $this->trips->detach((int) $req->user['id'], (int) $params['id'], (int) $params['eventId']);
        return Response::json(['ok' => true]);
    }

    public function feedback(Request $req, array $params): Response
    {
        $feedback = $this->events->recordFeedback(
            (int) $req->user['id'],
            (int) $params['id'],
            (string) ($req->str('signal') ?? '')
        );
        return Response::json(['ok' => true, 'feedback' => $feedback]);
    }
}
