<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * Place search turned off (BETTERCAL_PLACE_SEARCH=off, PlaceProviders::isOff):
 * no suggestions, and no request to any service.
 */
final class NoPlaces implements PlaceProvider
{
    public function candidates(string $q, ?float $biasLat, ?float $biasLng, int $limit, ?string $language): ?array
    {
        return [];
    }

    public function distanceCap(): ?float
    {
        return null;
    }

    public function credits(): array
    {
        return [];
    }
}
