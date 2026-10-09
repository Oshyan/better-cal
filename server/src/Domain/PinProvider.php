<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * A keyed service asked first for the single-pin lookup (Geocode): the one
 * best place for complete location text, as imported and feed events carry
 * it. Photon and Open-Meteo stand behind it and answer whenever it finds
 * nothing or can't be reached; Geocode keeps the cache, the airport table and
 * the guard against far-off guesses for every provider.
 */
interface PinProvider
{
    /**
     * @return array{lat:float,lng:float,display:string,kind:?string,provider:string}|false|null
     *   the best result; null when the service searched and found nothing;
     *   false when it could not be reached (failed, timed out, rate limited)
     */
    public function pin(string $q, ?float $biasLat, ?float $biasLng): array|false|null;
}
