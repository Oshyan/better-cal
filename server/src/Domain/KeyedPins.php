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
 *
 * With a bias, candidates are ranked by the service's order blended with
 * distance (PlaceSearch::rank), as Photon's are.
 */
final class KeyedPins implements PinProvider
{
    private const CANDIDATES = 5;

    /** @param 'locationiq'|'stadia' $service */
    public function __construct(
        private readonly KeyedGeocoderTransport $transport,
        private readonly string $service,
        private readonly string $key,
    ) {
    }

    public function pin(string $q, ?float $biasLat, ?float $biasLng): array|false|null
    {
        $biased = $biasLat !== null && $biasLng !== null;
        $url = $this->service === 'stadia'
            ? StadiaPlaces::url($this->key, $q, self::CANDIDATES, null, $biased ? ['focus.point.lat' => $biasLat, 'focus.point.lon' => $biasLng] : [], 'search')
            : LocationIqPlaces::url('search', $this->key, $q, self::CANDIDATES, null, ['format' => 'json', 'addressdetails' => 1, 'normalizeaddress' => 1]
                + ($biased ? ['viewbox' => PlaceSearch::regionBox($biasLat, $biasLng)] : []));
        $body = $this->transport->keyed($this->service, [$url])[0] ?? null;
        if ($body === null) {
            return false;
        }
        $rows = $this->service === 'stadia'
            ? StadiaPlaces::mapFeatures($body, $biasLat, $biasLng)
            : LocationIqPlaces::mapResults($body, $biasLat, $biasLng);
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
