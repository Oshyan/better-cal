<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Events;
use BetterCal\Domain\Search;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class SearchController
{
    public function __construct(private readonly Search $search, private readonly Events $events)
    {
    }

    public function search(Request $req): Response
    {
        $q = (string) ($req->q('q') ?? '');
        $limit = (int) ($req->q('limit') ?? '50');
        $rows = $this->search->search((int) $req->user['id'], $q, $limit);
        return Response::json(['results' => $this->events->serializeRows($rows)]);
    }
}
