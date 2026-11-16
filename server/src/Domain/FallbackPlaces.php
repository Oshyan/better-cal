<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * A keyed provider with Photon behind it. The primary answers the queries it
 * takes (SelectivePlaceProvider::accepts); the fallback answers the rest, and
 * also whenever the primary fails, is rate limited or gives an answer that
 * doesn't settle the search (PlaceSearch::usable): a row has to contain
 * every typed word, so a service that answers with near misses has not
 * answered, and one whose matching rows are all far from the person is asked
 * past. The primary's matching rows are kept behind the fallback's, near
 * ones first, and stand alone if the fallback fails. Ranking follows the
 * service that answered last; credits name every service whose rows are in.
 */
final class FallbackPlaces implements PlaceProvider
{
    /** @var list<PlaceProvider> */
    private array $answered;

    public function __construct(
        private readonly SelectivePlaceProvider $primary,
        private readonly PlaceProvider $fallback,
    ) {
        $this->answered = [$primary];
    }

    public function candidates(string $q, ?float $biasLat, ?float $biasLng, int $limit, ?string $language): ?array
    {
        $held = [];
        if ($this->primary->accepts($q)) {
            $rows = $this->primary->candidates($q, $biasLat, $biasLng, $limit, $language);
            if ($rows !== null && PlaceSearch::usable($q, $rows, $biasLat !== null && $biasLng !== null)) {
                $this->answered = [$this->primary];
                return $rows;
            }
            // Matching rows that didn't settle it (all far) wait behind.
            $held = PlaceSearch::containingAllWords($q, PlaceSearch::matchingNumber($q, $rows ?? []));
        }
        $more = $this->fallback->candidates($q, $biasLat, $biasLng, $limit, $language);
        if ($held === []) {
            $this->answered = [$this->fallback];
            return $more;
        }
        if ($more === null) {
            $this->answered = [$this->primary];
            return $held;
        }
        $this->answered = [$this->fallback, $this->primary];
        return PlaceSearch::nearFirst(PlaceSearch::mergeCandidates($more, $held));
    }

    public function distanceCap(): ?float
    {
        return $this->answered[0]->distanceCap();
    }

    public function credits(): array
    {
        $out = [];
        foreach ($this->answered as $p) {
            foreach ($p->credits() as $c) {
                $out[$c['url']] ??= $c;
            }
        }
        return array_values($out);
    }
}
