<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Events;
use BetterCal\Domain\Filters;
use BetterCal\Domain\Labels;
use BetterCal\Domain\Search;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class SearchController
{
    public function __construct(
        private readonly Search $search,
        private readonly Events $events,
        private readonly Filters $filters,
        private readonly Labels $labels,
    ) {
    }

    public function search(Request $req): Response
    {
        $userId = (int) $req->user['id'];
        $q = (string) ($req->q('q') ?? '');
        $limit = max(1, min(200, (int) ($req->q('limit') ?? '50')));
        // #60: Upcoming (the default), All or Past, and optionally one calendar,
        // applied in the query before the limit.
        $when = (string) ($req->q('when') ?? 'all');
        if (!in_array($when, ['all', 'upcoming', 'past'], true)) {
            $when = 'all';
        }
        $calendarId = $req->q('calendar');
        $opts = ['when' => $when, 'calendarId' => ($calendarId !== null && $calendarId !== '' && ctype_digit((string) $calendarId)) ? (int) $calendarId : null];
        $rows = $this->search->search($userId, $q, $limit, true, $opts);
        $upcomingIds = [];
        foreach ($rows as $row) {
            if (!empty($row['_upcoming'])) {
                $upcomingIds[(int) $row['id']] = true;
            }
        }

        // User filters: hide drops rows, dim/highlight mark them (additive
        // fields). Prompt filters join cached background verdicts; no LLM
        // calls here.
        $activeFilters = $this->filters->enabledForUser($userId);
        $rowIds = array_map(static fn(array $row) => (int) $row['id'], $rows);
        $promptCtx = $this->filters->promptFilterContext($userId, $rowIds);
        // Filters matching on the tags field need each row's tag names.
        $tagsByEvent = Filters::anyUsesTags($activeFilters)
            ? $this->labels->forEvents($rowIds)['tags']
            : null;
        $dimmedIds = [];
        $highlightedIds = [];
        if ($activeFilters !== [] || $promptCtx['filters'] !== []) {
            $kept = [];
            foreach ($rows as $row) {
                $candidate = $row;
                if ($tagsByEvent !== null) {
                    $candidate['tags'] = $tagsByEvent[(int) $row['id']] ?? [];
                }
                $disposition = Filters::strongest(
                    Filters::disposition($candidate, $activeFilters),
                    Filters::promptDisposition($candidate, $promptCtx['filters'], $promptCtx['failed'])
                );
                if ($disposition === 'hide') {
                    continue;
                }
                if ($disposition === 'dim') {
                    $dimmedIds[(int) $row['id']] = true;
                } elseif ($disposition === 'highlight') {
                    $highlightedIds[(int) $row['id']] = true;
                }
                $kept[] = $row;
            }
            $rows = $kept;
        }

        $results = $this->events->serializeRows($rows);
        foreach ($results as &$occ) {
            $occ['upcoming'] = isset($upcomingIds[$occ['eventId']]);
            if (isset($dimmedIds[$occ['eventId']])) {
                $occ['dimmed'] = true;
            }
            if (isset($highlightedIds[$occ['eventId']])) {
                $occ['highlighted'] = true;
            }
        }
        unset($occ);
        $payload = ['results' => $results];
        if ($when === 'upcoming') {
            $payload['pastCount'] = $this->search->countPast($userId, $q, ['calendarId' => $opts['calendarId']]);
        }
        return Response::json($payload);
    }
}
