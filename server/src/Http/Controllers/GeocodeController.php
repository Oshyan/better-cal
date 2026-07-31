<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Geocode;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class GeocodeController
{
    public function __construct(private readonly Geocode $geocode)
    {
    }

    public function lookup(Request $req): Response
    {
        return Response::json($this->geocode->lookup((string) ($req->q('q') ?? '')));
    }
}
