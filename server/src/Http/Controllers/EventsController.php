<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\ChangeCursor;
use BetterCal\Domain\Events;
use BetterCal\Domain\RequestAuthority;
use BetterCal\Domain\Trips;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Http\StaticFiles;
use BetterCal\Support\Time;

final class EventsController
{
    /**
     * The window's cache policy (#16): a browser may keep an answer but
     * must ask with its ETag before showing it again, and only this
     * account's browser may keep it. A matching ask is a 304 with no body,
     * answered before any event is read.
     */
    public const WINDOW_CACHE_CONTROL = 'private, no-cache';

    public function __construct(
        private readonly Events $events,
        private readonly Trips $trips,
        private readonly ?\BetterCal\Domain\Rsvp $rsvp = null,
        /** The code version the window's ETag names (ChangeCursor::windowTag). */
        private readonly string $version = '',
        private readonly ?ChangeCursor $cursor = null,
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
        // The window's ETag is the account's change cursor (a few indexed
        // aggregates, no event row read): a client holding this window
        // under the same tag has it as the server would send it now, and is
        // told so before any expansion, sort, filter or encoding.
        $headers = ['Cache-Control' => self::WINDOW_CACHE_CONTROL];
        if ($this->cursor !== null) {
            // Floating events show on the device's clock, so the device's
            // zone is part of what the window is (#167).
            $headers['ETag'] = $this->cursor->windowTag((int) $req->user['id'], $this->version . '|' . $this->events->viewerName());
            if (StaticFiles::matches($req->header('If-None-Match'), $headers['ETag'])) {
                return Response::notModified($headers);
            }
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
        // cap is named, so a client can say so instead of showing fewer (#23),
        // but only one this answer shows: one hidden by a filter, named with
        // no rows, stopped the client's follow-up for every other.
        $capped = $this->events->cappedSeries($occurrences);
        $response = Response::json(['events' => $occurrences] + ($capped !== [] ? ['capped' => $capped] : []));
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }

    public function create(Request $req): Response
    {
        if (($req->body['preview'] ?? false) === true) {
            // A new series' creation plan: its words, start and first dates. Nothing is written.
            try {
                return Response::json(['plan' => $this->events->previewCreate((int) $req->user['id'], $this->onRowClock($req, null))]);
            } catch (\InvalidArgumentException $e) {
                throw HttpError::badRequest($e->getMessage());
            }
        }
        try {
            $occurrence = $this->events->create((int) $req->user['id'], $this->onRowClock($req, null), RequestAuthority::request($req));
        } catch (\InvalidArgumentException $e) {
            throw HttpError::badRequest($e->getMessage());
        }
        return Response::json($occurrence, 201);
    }

    /** POST /events/:id/copy {calendarId, scope?: this|all, instanceStart?} -> the new event's occurrence */
    public function copy(Request $req, array $params): Response
    {
        // duplicate: a copy on the same calendar (Duplicate, Option-drag);
        // calendarId may then be left out. start/end place the copy at
        // another time (where it was dropped).
        $duplicate = ($req->body['duplicate'] ?? false) === true;
        $calendarId = (int) ($req->body['calendarId'] ?? 0);
        if ($calendarId <= 0 && !$duplicate) {
            throw HttpError::badRequest('calendarId is required');
        }
        $scope = (string) ($req->str('scope') ?? 'all');
        if (!in_array($scope, ['this', 'all'], true)) {
            throw HttpError::badRequest('scope must be this|all');
        }
        $body = $this->onRowClock($req, (int) $params['id'], true);
        try {
            $occurrence = $this->events->copyTo((int) $req->user['id'], (int) $params['id'], $calendarId, $scope, $body['instanceStart'] ?? null,
                $body['start'] ?? null, $body['end'] ?? null, $duplicate, RequestAuthority::request($req));
        } catch (\InvalidArgumentException $e) {
            throw HttpError::badRequest($e->getMessage());
        }
        return Response::json($occurrence, 201);
    }

    public function patch(Request $req, array $params): Response
    {
        if (($req->body['preview'] ?? false) === true) {
            // A whole-series change's plan (series-model.md, The API): nothing written.
            try {
                return Response::json(['plan' => $this->events->previewPatch((int) $req->user['id'], (int) $params['id'], $this->onRowClock($req, (int) $params['id']))]);
            } catch (\InvalidArgumentException $e) {
                throw HttpError::badRequest($e->getMessage());
            }
        }
        try {
            $this->events->patch((int) $req->user['id'], (int) $params['id'], $this->onRowClock($req, (int) $params['id']), RequestAuthority::request($req));
        } catch (\InvalidArgumentException $e) {
            throw HttpError::badRequest($e->getMessage());
        }
        return Response::json(['ok' => true] + self::split($this->events));
    }

    public function delete(Request $req, array $params): Response
    {
        $scope = $req->str('scope') ?? $req->q('scope');
        $instance = $this->instanceOnRowClock($req, (int) $params['id']);
        if (($req->body['preview'] ?? false) === true || filter_var($req->q('preview', '0'), FILTER_VALIDATE_BOOL)) {
            // Delete following's plan (Series Plans): nothing written.
            return Response::json(['plan' => $this->events->previewDelete((int) $req->user['id'], (int) $params['id'], $scope, $instance, self::choices($req))]);
        }
        try {
            $this->events->deleteEvent((int) $req->user['id'], (int) $params['id'], $scope, $instance, RequestAuthority::request($req), self::plan($req));
        } catch (\InvalidArgumentException $e) {
            throw HttpError::badRequest($e->getMessage());
        }
        return Response::json(['ok' => true]);
    }

    /**
     * The request's body with its instants on the event's own clock: a
     * floating event's times arrive on the device's clock and are stored on
     * its own (Events::fromViewer, #167). $instantsOnly keeps only the
     * instants (a copy reads its other fields itself).
     */
    private function onRowClock(Request $req, ?int $id, bool $instantsOnly = false): array
    {
        $body = $req->body;
        if ($instantsOnly) {
            $body = array_intersect_key($body, array_flip(['instanceStart', 'start', 'end']));
        }
        $moved = $this->events->fromViewer((int) $req->user['id'], $id, $body);
        if ($instantsOnly) {
            return array_map(static fn($v) => is_scalar($v) && $v !== '' ? (string) $v : null, array_intersect_key($moved, array_flip(['instanceStart', 'start', 'end'])));
        }
        return $moved;
    }

    /** The occurrence a request names (body or query), on the event's own clock. */
    private function instanceOnRowClock(Request $req, int $id): ?string
    {
        $instance = $req->str('instanceStart') ?? $req->q('instanceStart');
        if ($instance === null) {
            return null;
        }
        $moved = $this->events->fromViewer((int) $req->user['id'], $id, ['instanceStart' => $instance]);
        return is_string($moved['instanceStart'] ?? null) ? $moved['instanceStart'] : $instance;
    }

    /** The plan a commit names ({hash, choices}), or null. */
    private static function plan(Request $req): ?array
    {
        return is_array($req->body['plan'] ?? null) ? $req->body['plan'] : null;
    }

    /** The choices a preview names. */
    private static function choices(Request $req): array
    {
        return is_array($req->body['plan']['choices'] ?? null) ? $req->body['plan']['choices'] : [];
    }

    public function rsvp(Request $req, array $params): Response
    {
        $req->requireSession('Invitation replies');
        if ($this->rsvp === null) {
            throw HttpError::notFound('RSVP unavailable');
        }
        return Response::json($this->rsvp->answer((int) $req->user['id'], (int) $params['id'], (string) ($req->str('answer') ?? ''), $req->cookies[\BetterCal\Domain\Auth::COOKIE] ?? null, ($req->body['retryUncertain'] ?? false) === true));
    }

    /** POST /events/:id/relationship {relationship, scope?, instanceStart?} -> {ok:true} */
    public function relationship(Request $req, array $params): Response
    {
        $scope = $req->str('scope');
        if ($scope !== null && !in_array($scope, ['this', 'following', 'all'], true)) {
            throw HttpError::badRequest('scope must be this|following|all');
        }
        if (($req->body['preview'] ?? false) === true) {
            return Response::json(['plan' => $this->events->previewRelationship((int) $req->user['id'], (int) $params['id'], (string) ($req->str('relationship') ?? ''), $scope, $this->instanceOnRowClock($req, (int) $params['id']), self::choices($req))]);
        }
        $this->events->setRelationship(
            (int) $req->user['id'],
            (int) $params['id'],
            (string) ($req->str('relationship') ?? ''),
            $scope,
            $this->instanceOnRowClock($req, (int) $params['id']),
            RequestAuthority::request($req),
            self::plan($req)
        );
        return Response::json(['ok' => true] + self::split($this->events));
    }

    public function attendance(Request $req, array $params): Response
    {
        $scope = $req->str('scope');
        if ($scope !== null && !in_array($scope, ['this', 'following', 'all'], true)) {
            throw HttpError::badRequest('scope must be this|following|all');
        }
        if (($req->body['preview'] ?? false) === true) {
            return Response::json(['plan' => $this->events->previewAttendance((int) $req->user['id'], (int) $params['id'], (string) ($req->str('attendance') ?? ''), $scope, $this->instanceOnRowClock($req, (int) $params['id']), self::choices($req))]);
        }
        $this->events->setAttendance(
            (int) $req->user['id'],
            (int) $params['id'],
            (string) ($req->str('attendance') ?? ''),
            $scope,
            $this->instanceOnRowClock($req, (int) $params['id']),
            self::plan($req)
        );
        return Response::json(['ok' => true] + self::split($this->events));
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

    /** The event view's lookup of an address (Events::placeFromLookup). */
    public function place(Request $req, array $params): Response
    {
        $placed = $this->events->placeFromLookup((int) $req->user['id'], (int) $params['id'], (string) ($req->str('location') ?? ''), $req->body['lat'] ?? null, $req->body['lng'] ?? null, $req->str('provider'));
        return Response::json(['ok' => true, 'placed' => $placed]);
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

    /**
     * After a 'following' split, the event that now holds the occurrence the
     * owner acted on: {splitEventId}. Its instanceId is that id plus the
     * occurrence's start, so a client can keep it open.
     *
     * @return array{splitEventId?:int}
     */
    private static function split(?Events $events): array
    {
        $id = $events?->splitEventId();
        return $id !== null ? ['splitEventId' => $id] : [];
    }
}
