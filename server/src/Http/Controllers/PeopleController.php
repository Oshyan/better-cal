<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Events;
use BetterCal\Domain\People;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class PeopleController
{
    public function __construct(
        private readonly People $people,
        private readonly Events $events,
    ) {
    }

    public function index(Request $req): Response
    {
        return Response::json(['people' => $this->people->list((int) $req->user['id'])]);
    }

    public function patch(Request $req, array $params): Response
    {
        return Response::json($this->people->update((int) $req->user['id'], (int) $params['id'], $req->body));
    }

    public function delete(Request $req, array $params): Response
    {
        $this->people->delete((int) $req->user['id'], (int) $params['id']);
        return Response::json(['ok' => true]);
    }

    /** Same occurrence shape as /search results (first instance per event). */
    public function events(Request $req, array $params): Response
    {
        $rows = $this->people->eventRows((int) $req->user['id'], (int) $params['id']);
        return Response::json(['results' => $this->events->serializeRows($rows)]);
    }

    public function spans(Request $req, array $params): Response
    {
        return Response::json(['spans' => $this->people->spans((int) $req->user['id'], (int) $params['id'])]);
    }

    public function addSpan(Request $req, array $params): Response
    {
        return Response::json($this->people->addSpan((int) $req->user['id'], (int) $params['id'], $req->body), 201);
    }

    public function deleteSpan(Request $req, array $params): Response
    {
        $this->people->deleteSpan((int) $req->user['id'], (int) $params['id'], (int) $params['spanId']);
        return Response::json(['ok' => true]);
    }

    /** GET /availability?start&end — visible people's spans (calendar bands). */
    public function window(Request $req): Response
    {
        return Response::json(['spans' => $this->people->spansInWindow(
            (int) $req->user['id'],
            (string) ($req->q('start') ?? ''),
            (string) ($req->q('end') ?? '')
        )]);
    }

    /** GET /availability/check?start&end&names=A,B — scheduling assist. */
    public function check(Request $req): Response
    {
        $names = array_map('trim', explode(',', (string) ($req->q('names') ?? '')));
        return Response::json(['conflicts' => $this->people->check(
            (int) $req->user['id'],
            $names,
            (string) ($req->q('start') ?? ''),
            (string) ($req->q('end') ?? '')
        )]);
    }
}
