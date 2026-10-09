<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * A place provider that is good at some queries and not others, run in front
 * of a fallback (FallbackPlaces): it says which queries it takes, and the
 * fallback answers the rest.
 */
interface SelectivePlaceProvider extends PlaceProvider
{
    /** Whether this provider should answer this (normalized) query. */
    public function accepts(string $q): bool;
}
