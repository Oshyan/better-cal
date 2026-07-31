<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\SavedViews;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class SavedViewsController
{
    public function __construct(private readonly SavedViews $savedViews)
    {
    }

    public function index(Request $req): Response
    {
        return Response::json(['views' => $this->savedViews->listAll((int) $req->user['id'])]);
    }

    public function create(Request $req): Response
    {
        return Response::json($this->savedViews->create((int) $req->user['id'], $req->body), 201);
    }

    public function patch(Request $req, array $params): Response
    {
        return Response::json($this->savedViews->patch((int) $req->user['id'], (int) $params['id'], $req->body));
    }

    public function delete(Request $req, array $params): Response
    {
        $this->savedViews->delete((int) $req->user['id'], (int) $params['id']);
        return Response::json(['ok' => true]);
    }
}
