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
}
