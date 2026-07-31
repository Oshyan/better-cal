<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Filters;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class FiltersController
{
    public function __construct(private readonly Filters $filters)
    {
    }

    public function index(Request $req): Response
    {
        return Response::json(['filters' => $this->filters->listAll((int) $req->user['id'])]);
    }

    public function create(Request $req): Response
    {
        return Response::json($this->filters->create((int) $req->user['id'], $req->body), 201);
    }

    public function patch(Request $req, array $params): Response
    {
        return Response::json($this->filters->patch((int) $req->user['id'], (int) $params['id'], $req->body));
    }

    public function delete(Request $req, array $params): Response
    {
        $this->filters->delete((int) $req->user['id'], (int) $params['id']);
        return Response::json(['ok' => true]);
    }
}
