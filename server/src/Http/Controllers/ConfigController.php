<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\PlaceProviders;
use BetterCal\Domain\Settings;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

/**
 * GET /config — public-safe client configuration, fetched once at boot.
 * maptilerKey is the optional MapTiler tile key (BETTERCAL_MAPTILER_KEY);
 * mapStyle is the requesting user's map style setting for convenience so the
 * map can render before /settings is consulted. placeSearch says which place
 * search the server uses (PlaceProviders::describe), never its key.
 */
final class ConfigController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly array $cfg,
    ) {
    }

    public function index(Request $req): Response
    {
        $key = (string) ($this->cfg['maptiler']['key'] ?? '');
        $userSettings = $this->settings->forUser((int) $req->user['id']);
        return Response::json([
            'maptilerKey' => $key !== '' ? $key : null,
            'mapStyle' => $userSettings['mapStyle'] ?? 'streets-v2',
            'placeSearch' => PlaceProviders::describe($this->cfg['places'] ?? []),
        ]);
    }
}
