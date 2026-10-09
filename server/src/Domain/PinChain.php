<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * Several keyed services for the single-pin lookup, asked in order until one
 * places it (BETTERCAL_PLACE_LOOKUP lists them). Photon comes after all of
 * them, in Geocode.
 */
final class PinChain implements PinProvider
{
    /** @param list<PinProvider> $pins */
    public function __construct(private readonly array $pins)
    {
    }

    public function pin(string $q, ?float $biasLat, ?float $biasLng): array|false|null
    {
        $reached = false;
        foreach ($this->pins as $p) {
            $r = $p->pin($q, $biasLat, $biasLng);
            if (is_array($r)) {
                return $r;
            }
            $reached = $reached || $r === null;
        }
        // Found nothing anywhere it could ask; or reached none of them.
        return $reached ? null : false;
    }
}
