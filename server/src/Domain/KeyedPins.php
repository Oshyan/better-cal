<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\KeyedGeocoderTransport;

/**
 * The single-pin lookup on a keyed service, from its full-text search, which
 * is built for complete text ("Venue, 12 Main Street, City") where the
 * dropdown's autocomplete is built for partial typing:
 *
 * - LocationIQ /v1/search with the region around the bias preferred (a
 *   viewbox, not a filter), so a local venue wins and a distant city named
 *   in full is still found. It answers in well under a second and is given
 *   the transport's 1.2 s; past that, Photon answers.
 * - Stadia /v1/search with the bias as its focus point. It costs 20 credits
 *   a request on Stadia's plans, as its autocomplete does.
 * - MapTiler's geocoding with completions off and venues on, its own
 *   fallbacks (relevance under 0.5) left for the next service.
 *
 * With a bias, candidates are ranked by the service's order blended with
 * distance (PlaceSearch::rank), as Photon's are.
 */
final class KeyedPins implements PinProvider
{
    private const CANDIDATES = 5;
    public const MAPTILER_MIN_RELEVANCE = 0.5;

    /** @param 'locationiq'|'stadia'|'maptiler' $service */
    public function __construct(
        private readonly KeyedGeocoderTransport $transport,
        private readonly string $service,
        private readonly string $key,
    ) {
    }

    public function pin(string $q, ?float $biasLat, ?float $biasLng): array|false|null
    {
        $biased = $biasLat !== null && $biasLng !== null;
        $url = match ($this->service) {
            'stadia' => StadiaPlaces::url($this->key, $q, self::CANDIDATES, null, $biased ? ['focus.point.lat' => $biasLat, 'focus.point.lon' => $biasLng] : [], 'search'),
            // Complete text, so no guessing at completions; venues included.
            // English names, as the other services give with no language
            // asked; without one it answers in local names.
            'maptiler' => MapTilerPlaces::url($this->key, $q, self::CANDIDATES, 'en', $biased ? $biasLng . ',' . $biasLat : null, true, ['autocomplete' => 'false']),
            default => LocationIqPlaces::url('search', $this->key, $q, self::CANDIDATES, null, ['format' => 'json', 'addressdetails' => 1, 'normalizeaddress' => 1]
                + ($biased ? ['viewbox' => PlaceSearch::regionBox($biasLat, $biasLng)] : [])),
        };
        $body = $this->transport->keyed($this->service, [$url])[0] ?? null;
        if ($body === null) {
            return false;
        }
        $rows = match ($this->service) {
            'stadia' => StadiaPlaces::mapFeatures($body, $biasLat, $biasLng),
            // Under 0.5 is its own fallback, such as the city or county for a
            // venue it didn't find; the next service does better than that.
            'maptiler' => MapTilerPlaces::mapFeatures($body, $biasLat, $biasLng, self::MAPTILER_MIN_RELEVANCE),
            default => LocationIqPlaces::mapResults($body, $biasLat, $biasLng),
        };
        if ($biased) {
            $rows = PlaceSearch::rank($rows);
        }
        $top = $rows[0] ?? null;
        if ($top === null) {
            return null;
        }
        return [
            'lat' => (float) $top['lat'],
            'lng' => (float) $top['lng'],
            'display' => (string) $top['display'],
            'kind' => isset($top['kind']) ? (string) $top['kind'] : null,
            'provider' => $this->service,
        ];
    }
}
