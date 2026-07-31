<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Folders;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class FoldersController
{
    public function __construct(private readonly Folders $folders)
    {
    }

    public function create(Request $req): Response
    {
        return Response::json($this->folders->create((int) $req->user['id'], $req->body), 201);
    }

    public function patch(Request $req, array $params): Response
    {
        return Response::json($this->folders->patch((int) $req->user['id'], (int) $params['id'], $req->body));
    }

    public function delete(Request $req, array $params): Response
    {
        $this->folders->delete((int) $req->user['id'], (int) $params['id']);
        return Response::json(['ok' => true]);
    }
}
