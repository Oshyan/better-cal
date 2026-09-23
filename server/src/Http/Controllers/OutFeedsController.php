<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\OutFeeds;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class OutFeedsController
{
    public function __construct(private readonly OutFeeds $outFeeds)
    {
    }

    // Feed URLs are capabilities (anyone holding one reads the events without
    // signing in), so only a session may list, create or delete them: a
    // leaked API token must not be able to mint one that outlives it (F6).
    public function index(Request $req): Response
    {
        $req->requireSession('Outbound feed addresses');
        return Response::json(['feeds' => $this->outFeeds->listAll((int) $req->user['id'])]);
    }

    public function create(Request $req): Response
    {
        $req->requireSession('Creating an outbound feed');
        return Response::json($this->outFeeds->create((int) $req->user['id'], $req->body), 201);
    }

    public function delete(Request $req, array $params): Response
    {
        $req->requireSession('Deleting an outbound feed');
        $this->outFeeds->delete((int) $req->user['id'], (int) $params['id']);
        return Response::json(['ok' => true]);
    }

    /** Public, unauthenticated: GET /feed/{token}.ics */
    public function publicFeed(string $token): Response
    {
        $ics = $this->outFeeds->renderByToken($token);
        if ($ics === null) {
            return Response::text('Not found', 'text/plain; charset=utf-8', 404);
        }
        return Response::text($ics, 'text/calendar; charset=utf-8', 200, [
            'Content-Disposition' => 'inline; filename="calendar.ics"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
