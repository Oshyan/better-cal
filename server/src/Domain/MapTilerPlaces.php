<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\KeyedGeocoderTransport;

/**
 * Place search on MapTiler's geocoding API (keyed; OpenStreetMap plus
 * OpenAddresses, TIGER and county address data in the US), run in front of
 * the services after it (FallbackPlaces), and the workarounds its behaviour
 * needs:
 *
 * - It finds addresses from a bare house number or a number with a letter or
 *   two of the street, near the bias, which only Photon otherwise did, and
 *   in about a third of a second; so it takes every query.
 * - Venues (its "poi" type) are left out unless asked for, and asking can
 *   make a search slower, so they're skipped only while a query is a bare
 *   house number or a number with a letter or two: an address being typed.
 * - Its proximity is a bias, and a far match it rates higher stays ahead of
 *   a local one, so distance reorders within PlaceSearch's cap; a city named
 *   exactly what was typed is pinned first (local streets named after it
 *   otherwise lead), as is a nearby town whose name starts with it.
 *
 * Its terms allow keeping results permanently; on its free plan, searches
 * share one monthly allowance with its map tiles (PlaceProviders::MAPTILER_NOTE).
 */
final class MapTilerPlaces implements SelectivePlaceProvider
{
    private const BASE = 'https://api.maptiler.com/geocoding/';
    /** Its results are capped at 10. */
    private const MAX_LIMIT = 10;
    /** Kinds big enough to pin by an exact name; neighbourhoods and the like share names with too much. */
    public const PLACE_LEVEL = ['country', 'region', 'subregion', 'county', 'joint_municipality', 'municipality'];
    /** Its kinds of town, for PlaceSearch::townStartingWith. */
    public const TOWNS = ['municipality', 'joint_municipality', 'locality'];

    public function __construct(
        private readonly KeyedGeocoderTransport $transport,
        private readonly string $key,
    ) {
    }

    public function accepts(string $q): bool
    {
        return true;
    }

    public function distanceCap(): ?float
    {
        return PlaceSearch::FAR_KM / 250.0;
    }

    public function credits(): array
    {
        // What its responses carry as their attribution.
        return [
            ['label' => '© MapTiler', 'url' => 'https://www.maptiler.com/copyright/'],
            ['label' => '© OpenStreetMap', 'url' => 'https://www.openstreetmap.org/copyright'],
        ];
    }

    public function candidates(string $q, ?float $biasLat, ?float $biasLng, int $limit, ?string $language): ?array
    {
        $biased = $biasLat !== null && $biasLng !== null;
        // Venues too once there's a real word: plenty start with a number
        // (a chain named "7-Something", a gym open "24 Hour"). A bare number
        // or a number and a letter or two is someone typing an address.
        $venues = PlaceSearch::houseNumber($q) === null || PlaceSearch::hasWord($q);
        $body = $this->transport->keyed('maptiler', [self::url($this->key, $q, $limit + 4, $language, $biased ? $biasLng . ',' . $biasLat : null, $venues)])[0] ?? null;
        if ($body === null) {
            return null;
        }
        $candidates = self::mapFeatures($body, $biasLat, $biasLng);
        if (!$biased) {
            return $candidates;
        }
        $pins = [];
        $places = array_values(array_filter($candidates, static fn(array $c): bool => in_array($c['kind'], self::PLACE_LEVEL, true)));
        $exact = PlaceSearch::exactPlace($q, $places, $candidates);
        if ($exact !== null) {
            $pins[] = $exact + ['pin' => true];
        }
        $town = PlaceSearch::townStartingWith($q, $candidates, self::TOWNS);
        if ($town !== null) {
            $pins[] = $town + ['pin' => true];
        }
        return $pins === [] ? $candidates : PlaceSearch::mergeCandidates($pins, $candidates);
    }

    // ---- Pure helpers (unit-tested, no network) ------------------------

    /**
     * One request URL: the query is part of the path; every type, venues
     * included when asked ("all types except continental_marine"), else its
     * default set without venues. Pure.
     *
     * @param ?string $proximity "lng,lat"
     * @param array<string,mixed> $extra
     */
    public static function url(string $key, string $q, int $limit, ?string $language, ?string $proximity, bool $venues, array $extra = []): string
    {
        $params = ['limit' => max(1, min(self::MAX_LIMIT, $limit))];
        if ($proximity !== null) {
            $params['proximity'] = $proximity;
        }
        if ($language !== null) {
            $params['language'] = $language;
        }
        if ($venues) {
            $params += ['types' => 'continental_marine', 'excludeTypes' => 'true'];
        }
        return self::BASE . rawurlencode($q) . '.json?' . http_build_query($params + $extra + ['key' => $key]);
    }

    /**
     * Map a decoded response into candidate rows. The name is its own
     * (`text`), but for an address the first part of its full name, which
     * carries the house number its `text` leaves off.
     * The address line is the rest of the full name. Features under
     * $minRelevance (its own 0-1 score; under 0.5 is a fallback such as a
     * city for an unmatched street) are dropped. Pure.
     *
     * @return list<array<string,mixed>>
     */
    public static function mapFeatures(mixed $decoded, ?float $biasLat, ?float $biasLng, float $minRelevance = 0.0): array
    {
        if (!is_array($decoded) || !is_array($decoded['features'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($decoded['features'] as $f) {
            if (!is_array($f) || (float) ($f['relevance'] ?? 1) < $minRelevance) {
                continue;
            }
            $center = $f['center'] ?? null;
            if (!is_array($center) || count($center) < 2 || !is_numeric($center[0]) || !is_numeric($center[1])) {
                continue;
            }
            try {
                $point = Coordinates::pair($center[1], $center[0]);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($point === null) {
                continue;
            }
            $types = (array) ($f['place_type'] ?? []);
            $type = isset($types[0]) ? (string) $types[0] : '';
            $full = trim((string) ($f['place_name'] ?? ''));
            $parts = $full === '' ? [] : explode(', ', $full);
            $name = $type === 'address' && $parts !== [] ? $parts[0] : trim((string) ($f['text'] ?? ''));
            if ($name === '') {
                continue;
            }
            $address = str_starts_with($full, $name . ', ') ? substr($full, strlen($name) + 2) : ($full === $name ? '' : $full);
            $city = null;
            $country = null;
            foreach ((array) ($f['context'] ?? []) as $c) {
                $id = (string) ($c['id'] ?? '');
                if ($city === null && (str_starts_with($id, 'municipality.') || str_starts_with($id, 'locality.'))) {
                    $city = trim((string) ($c['text'] ?? '')) ?: null;
                }
                if (str_starts_with($id, 'country.')) {
                    $country = trim((string) ($c['text'] ?? '')) ?: null;
                }
            }
            $categories = (array) ($f['properties']['categories'] ?? []);
            $kind = $type === 'poi' && isset($categories[0]) ? (string) $categories[0] : ($type !== '' ? $type : null);
            $out[] = PlaceSearch::row($name, $address, $point[0], $point[1], $city, $kind, $biasLat, $biasLng, null, 'maptiler', $country);
        }
        return $out;
    }
}
