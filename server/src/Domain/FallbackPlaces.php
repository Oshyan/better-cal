<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * A keyed provider with Photon behind it. The primary answers the queries it
 * takes (SelectivePlaceProvider::accepts); the fallback answers the rest, and
 * also whenever the primary fails, is rate limited or finds nothing. Ranking
 * and credits follow whichever answered.
 */
final class FallbackPlaces implements PlaceProvider
{
    private PlaceProvider $answered;

    public function __construct(
        private readonly SelectivePlaceProvider $primary,
        private readonly PlaceProvider $fallback,
    ) {
        $this->answered = $primary;
    }

    public function candidates(string $q, ?float $biasLat, ?float $biasLng, int $limit, ?string $language): ?array
    {
        if ($this->primary->accepts($q)) {
            $rows = $this->primary->candidates($q, $biasLat, $biasLng, $limit, $language);
            if ($rows !== null && $rows !== []) {
                $this->answered = $this->primary;
                return $rows;
            }
        }
        $this->answered = $this->fallback;
        return $this->fallback->candidates($q, $biasLat, $biasLng, $limit, $language);
    }

    public function distanceCap(): ?float
    {
        return $this->answered->distanceCap();
    }

    public function credits(): array
    {
        return $this->answered->credits();
    }
}
