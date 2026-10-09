<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\KeyedGeocoderTransport;

/**
 * Place search on Stadia Maps (keyed; Pelias, with OpenAddresses house
 * numbers), run in front of Photon (FallbackPlaces), and the workarounds its
 * behaviour needs:
 *
 * - Its autocomplete ranks by distance from a focus point by itself, so the
 *   bias is passed as the focus and its order is kept.
 * - A bare number finds postcodes abroad, so that goes to the fallback; a
 *   number with even one letter of the street it handles well.
 * - With a focus, a city's full name offers local streets named after it
 *   ("munich"), so a worldwide request for places (layers=coarse) runs
 *   alongside and one named exactly what was typed is pinned first.
 *
 * Its v1 autocomplete costs 20 credits a request on Stadia's plans; the
 * places request makes that 40 for a query without a house number.
 *
 * Better-Cal keeps the coordinates of a picked place. Stadia's terms allow
 * keeping results only on plans that include storage; Settings and the
 * install docs say so (PlaceProviders::STADIA_NOTE). Better-Cal does not
 * check the plan.
 */
final class StadiaPlaces implements SelectivePlaceProvider
{
    private const AUTOCOMPLETE = 'https://api.stadiamaps.com/geocoding/v1/autocomplete';
    /** Pelias layers big enough to pin by name; neighbourhoods and the like share names with too much. */
    public const PLACE_LAYERS = ['locality', 'localadmin', 'county', 'region', 'macroregion', 'country', 'dependency'];

    public function __construct(
        private readonly KeyedGeocoderTransport $transport,
        private readonly string $key,
    ) {
    }

    public function accepts(string $q): bool
    {
        return PlaceSearch::houseNumber($q) === null || preg_match('/\p{L}/u', $q) === 1;
    }

    public function distanceCap(): ?float
    {
        return null; // it ranks by distance from the focus itself
    }

    public function credits(): array
    {
        // Stadia asks for a credit to itself and the data; its attribution
        // page lists every source (OpenStreetMap, OpenAddresses, ...).
        return [['label' => 'Stadia Maps and data sources', 'url' => 'https://stadiamaps.com/attribution/']];
    }

    public function candidates(string $q, ?float $biasLat, ?float $biasLng, int $limit, ?string $language): ?array
    {
        $biased = $biasLat !== null && $biasLng !== null;
        $urls = [self::url($this->key, $q, $limit + 4, $language, $biased ? ['focus.point.lat' => $biasLat, 'focus.point.lon' => $biasLng] : [])];
        $askPlaces = $biased && PlaceSearch::houseNumber($q) === null && PlaceSearch::hasWord($q);
        if ($askPlaces) {
            $urls[] = self::url($this->key, $q, 3, $language, ['layers' => 'coarse']);
        }
        $bodies = $this->transport->keyed('stadia', $urls);
        if (($bodies[0] ?? null) === null) {
            return null;
        }
        $candidates = self::mapFeatures($bodies[0], $biasLat, $biasLng);
        if (!$askPlaces || ($bodies[1] ?? null) === null) {
            return $candidates;
        }
        $placeFeatures = array_values(array_filter((array) ($bodies[1]['features'] ?? []), static fn($f): bool =>
            is_array($f) && in_array($f['properties']['layer'] ?? null, self::PLACE_LAYERS, true)));
        $exact = PlaceSearch::exactPlace($q, self::mapFeatures(['features' => $placeFeatures], $biasLat, $biasLng), $candidates);
        return $exact === null ? $candidates : PlaceSearch::mergeCandidates([$exact + ['pin' => true]], $candidates);
    }

    // ---- Pure helpers (unit-tested, no network) ------------------------

    /**
     * @param array<string,mixed> $extra
     * @param 'autocomplete'|'search' $endpoint search is full text (the
     *   single-pin lookup); autocomplete is for typing
     */
    public static function url(string $key, string $q, int $size, ?string $language, array $extra = [], string $endpoint = 'autocomplete'): string
    {
        $params = ['text' => $q, 'size' => max(1, min(20, $size))] + $extra;
        if ($language !== null) {
            $params['lang'] = $language;
        }
        $base = $endpoint === 'search' ? str_replace('/autocomplete', '/search', self::AUTOCOMPLETE) : self::AUTOCOMPLETE;
        return $base . '?' . http_build_query($params + ['api_key' => $key]);
    }

    /**
     * Map a decoded Pelias response into candidate rows. The address line is
     * the label after the name ("Richmond, CA, USA"), or street, locality,
     * region and country when the label doesn't start with it. Pure.
     *
     * @return list<array<string,mixed>>
     */
    public static function mapFeatures(mixed $decoded, ?float $biasLat, ?float $biasLng): array
    {
        if (!is_array($decoded) || !is_array($decoded['features'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($decoded['features'] as $f) {
            $coords = is_array($f) ? ($f['geometry']['coordinates'] ?? null) : null;
            if (!is_array($coords) || count($coords) < 2 || !is_numeric($coords[0]) || !is_numeric($coords[1])) {
                continue;
            }
            try {
                $point = Coordinates::pair($coords[1], $coords[0]);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($point === null) {
                continue;
            }
            $p = is_array($f['properties'] ?? null) ? $f['properties'] : [];
            $str = static fn(string $k): string => trim((string) ($p[$k] ?? ''));
            $name = $str('name');
            if ($name === '') {
                continue;
            }
            $label = $str('label');
            if (str_starts_with($label, $name . ', ')) {
                $address = substr($label, strlen($name) + 2);
            } else {
                $street = $str('street') !== '' ? trim($str('housenumber') . ' ' . $str('street')) : '';
                $parts = [];
                foreach ([$street, $str('locality'), $str('region'), $str('country')] as $part) {
                    if ($part !== '' && $part !== $name && !in_array($part, $parts, true)) {
                        $parts[] = $part;
                    }
                }
                $address = implode(', ', $parts);
            }
            $city = $str('locality');
            $kind = $str('layer');
            // Its labels end with the country's code for some ("CA, USA") and
            // its name for others ("Bavaria, Germany").
            $country = str_ends_with($address, $str('country_a')) && $str('country_a') !== '' ? $str('country_a') : $str('country');
            $out[] = PlaceSearch::row($name, $address, $point[0], $point[1], $city !== '' ? $city : null, $kind !== '' ? $kind : null, $biasLat, $biasLng, null, 'stadia', $country);
        }
        return $out;
    }
}
