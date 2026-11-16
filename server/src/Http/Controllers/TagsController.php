<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Tags;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class TagsController
{
    public function __construct(private readonly Tags $tags)
    {
    }

    /** GET /tags: the tags in use, with their event and filter counts. */
    public function index(Request $req): Response
    {
        return Response::json(['tags' => $this->tags->list((int) $req->user['id'])]);
    }

    /** PATCH /tags/:id {name}: rename; a name another tag has merges into it. */
    public function patch(Request $req, array $params): Response
    {
        return Response::json($this->tags->rename((int) $req->user['id'], (int) $params['id'], $req->body['name'] ?? null));
    }

    /** DELETE /tags/:id: off every event, then gone. */
    public function delete(Request $req, array $params): Response
    {
        return Response::json($this->tags->delete((int) $req->user['id'], (int) $params['id']));
    }
}
