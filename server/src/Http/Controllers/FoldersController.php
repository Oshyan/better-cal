<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Folders;
use BetterCal\Http\HttpError;
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

    /** POST /folders/:id/calendars/:calendarId {at, from?}: file it here, at a place (#187). */
    public function place(Request $req, array $params): Response
    {
        $at = $req->body['at'] ?? null;
        $from = $req->body['from'] ?? null;
        if (!is_int($at) || ($from !== null && !is_int($from))) {
            throw HttpError::badRequest('at (and from, when given) must be whole numbers');
        }
        return Response::json($this->folders->place((int) $req->user['id'], (int) $params['id'], (int) $params['calendarId'], $at, $from));
    }

    /** DELETE /folders/:id/calendars/:calendarId: out of this folder. */
    public function unfile(Request $req, array $params): Response
    {
        $this->folders->unfile((int) $req->user['id'], (int) $params['id'], (int) $params['calendarId']);
        return Response::json(['ok' => true]);
    }

    public function delete(Request $req, array $params): Response
    {
        $this->folders->delete((int) $req->user['id'], (int) $params['id']);
        return Response::json(['ok' => true]);
    }
}
