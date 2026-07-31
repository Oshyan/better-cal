<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Events;
use BetterCal\Domain\Filters;
use BetterCal\Domain\Search;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class SearchController
{
    public function __construct(
        private readonly Search $search,
        private readonly Events $events,
        private readonly Filters $filters,
    ) {
    }

    public function search(Request $req): Response
    {
        $userId = (int) $req->user['id'];
        $q = (string) ($req->q('q') ?? '');
        $limit = (int) ($req->q('limit') ?? '50');
        $rows = $this->search->search($userId, $q, $limit);

        // User filters: hide drops rows, dim marks them (additive field).
        // Prompt filters join cached background verdicts; no LLM calls here.
        $activeFilters = $this->filters->enabledForUser($userId);
        $promptCtx = $this->filters->promptFilterContext(
            $userId,
            array_map(static fn(array $row) => (int) $row['id'], $rows)
        );
        $dimmedIds = [];
        if ($activeFilters !== [] || $promptCtx['filters'] !== []) {
            $kept = [];
            foreach ($rows as $row) {
                $disposition = Filters::strongest(
                    Filters::disposition($row, $activeFilters),
                    Filters::promptDisposition($row, $promptCtx['filters'], $promptCtx['failed'])
                );
                if ($disposition === 'hide') {
                    continue;
                }
                if ($disposition === 'dim') {
                    $dimmedIds[(int) $row['id']] = true;
                }
                $kept[] = $row;
            }
            $rows = $kept;
        }

        $results = $this->events->serializeRows($rows);
        foreach ($results as &$occ) {
            if (isset($dimmedIds[$occ['eventId']])) {
                $occ['dimmed'] = true;
            }
        }
        unset($occ);
        return Response::json(['results' => $results]);
    }
}
