<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Geocode;
use BetterCal\Domain\PlaceSearch;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class GeocodeController
{
    public function __construct(
        private readonly Geocode $geocode,
        private readonly PlaceSearch $placeSearch,
    ) {
    }

    /**
     * GET /geocode?q=&lat=&lng=&tz= — single best result. Bias precedence
     * matches search: explicit lat/lng (home location) > tz centroid > none.
     */
    public function lookup(Request $req): Response
    {
        $lat = self::floatParam($req->q('lat'));
        $lng = self::floatParam($req->q('lng'));
        if ($lat === null || $lng === null) {
            $centroid = PlaceSearch::tzCentroid($req->q('tz'));
            [$lat, $lng] = $centroid ?? [null, null];
        }
        return Response::json($this->geocode->lookup((string) ($req->q('q') ?? ''), $lat, $lng));
    }

    /**
     * GET /geocode/search?q=&lat=&lng=&tz=&limit= — multi-candidate place
     * autocomplete. Bias precedence: explicit lat/lng (home location) >
     * tz centroid > none.
     */
    public function search(Request $req): Response
    {
        $lat = self::floatParam($req->q('lat'));
        $lng = self::floatParam($req->q('lng'));
        if ($lat === null || $lng === null) {
            $centroid = PlaceSearch::tzCentroid($req->q('tz'));
            [$lat, $lng] = $centroid ?? [null, null];
        }
        $limit = (int) ($req->q('limit') ?? PlaceSearch::DEFAULT_LIMIT);
        $results = $this->placeSearch->search((string) ($req->q('q') ?? ''), $lat, $lng, $limit > 0 ? $limit : PlaceSearch::DEFAULT_LIMIT);
        return Response::json(['results' => $results]);
    }

    private static function floatParam(?string $value): ?float
    {
        return $value !== null && $value !== '' && is_numeric($value) ? (float) $value : null;
    }
}
