<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\GeocoderTransport;

/**
 * Multi-candidate place autocomplete backed by photon.komoot.io (free, no
 * key). Complements Geocode (single best match, cached): this endpoint powers
 * the location picker dropdown, so results are transient and never cached.
 *
 * Location bias keeps "Main Street" from matching half a world away:
 * explicit lat/lng (the user's home location setting) wins, else a static
 * IANA-timezone centroid approximates the region, else no bias. When a bias
 * exists, candidates are re-ranked by blending Photon's own order with
 * distance, and far results (> 500 km) are flagged so the UI can call them
 * out.
 */
final class PlaceSearch
{
    public const DEFAULT_LIMIT = 6;
    public const MAX_LIMIT = 10;
    public const FAR_KM = 500.0;

    public function __construct(private readonly GeocoderTransport $transport)
    {
    }

    /**
     * Approximate centroids (city anchor) for major IANA zones. Coarse on
     * purpose: the bias only needs to land on the right region, not the right
     * street. Zones not listed fall through to no bias.
     */
    public const TZ_CENTROIDS = [
        'America/Los_Angeles' => [37.77, -122.42],
        'America/Denver' => [39.74, -104.99],
        'America/Phoenix' => [33.45, -112.07],
        'America/Chicago' => [41.88, -87.63],
        'America/New_York' => [40.71, -74.01],
        'America/Toronto' => [43.65, -79.38],
        'America/Vancouver' => [49.28, -123.12],
        'America/Mexico_City' => [19.43, -99.13],
        'America/Bogota' => [4.71, -74.07],
        'America/Lima' => [-12.05, -77.04],
        'America/Santiago' => [-33.45, -70.67],
        'America/Sao_Paulo' => [-23.55, -46.63],
        'America/Argentina/Buenos_Aires' => [-34.60, -58.38],
        'America/Anchorage' => [61.22, -149.90],
        'Pacific/Honolulu' => [21.31, -157.86],
        'Europe/London' => [51.51, -0.13],
        'Europe/Dublin' => [53.35, -6.26],
        'Europe/Paris' => [48.86, 2.35],
        'Europe/Berlin' => [52.52, 13.41],
        'Europe/Amsterdam' => [52.37, 4.90],
        'Europe/Brussels' => [50.85, 4.35],
        'Europe/Madrid' => [40.42, -3.70],
        'Europe/Lisbon' => [38.72, -9.14],
        'Europe/Rome' => [41.90, 12.50],
        'Europe/Zurich' => [47.38, 8.54],
        'Europe/Vienna' => [48.21, 16.37],
        'Europe/Prague' => [50.08, 14.44],
        'Europe/Warsaw' => [52.23, 21.01],
        'Europe/Stockholm' => [59.33, 18.06],
        'Europe/Oslo' => [59.91, 10.75],
        'Europe/Copenhagen' => [55.68, 12.57],
        'Europe/Helsinki' => [60.17, 24.94],
        'Europe/Athens' => [37.98, 23.73],
        'Europe/Istanbul' => [41.01, 28.98],
        'Europe/Moscow' => [55.76, 37.62],
        'Europe/Kyiv' => [50.45, 30.52],
        'Africa/Cairo' => [30.04, 31.24],
        'Africa/Lagos' => [6.52, 3.38],
        'Africa/Nairobi' => [-1.29, 36.82],
        'Africa/Johannesburg' => [-26.20, 28.05],
        'Asia/Jerusalem' => [31.78, 35.22],
        'Asia/Dubai' => [25.20, 55.27],
        'Asia/Karachi' => [24.86, 67.01],
        'Asia/Kolkata' => [19.08, 72.88],
        'Asia/Dhaka' => [23.81, 90.41],
        'Asia/Bangkok' => [13.76, 100.50],
        'Asia/Jakarta' => [-6.21, 106.85],
        'Asia/Singapore' => [1.35, 103.82],
        'Asia/Hong_Kong' => [22.32, 114.17],
        'Asia/Shanghai' => [31.23, 121.47],
        'Asia/Taipei' => [25.03, 121.57],
        'Asia/Seoul' => [37.57, 126.98],
        'Asia/Tokyo' => [35.68, 139.69],
        'Australia/Perth' => [-31.95, 115.86],
        'Australia/Adelaide' => [-34.93, 138.60],
        'Australia/Brisbane' => [-27.47, 153.03],
        'Australia/Sydney' => [-33.87, 151.21],
        'Australia/Melbourne' => [-37.81, 144.96],
        'Pacific/Auckland' => [-36.85, 174.76],
    ];

    // ---- Pure helpers (unit-tested, no network) ------------------------

    /** @return array{0: float, 1: float}|null [lat, lng] for a known zone */
    public static function tzCentroid(?string $tzid): ?array
    {
        if ($tzid === null || $tzid === '') {
            return null;
        }
        return self::TZ_CENTROIDS[$tzid] ?? null;
    }

    /**
     * Concise address line from Photon feature properties: street (with house
     * number), city, state, country. The place name itself is excluded; parts
     * repeating an earlier part are dropped.
     */
    public static function composeAddress(array $props): string
    {
        $parts = [];
        $street = trim((string) ($props['street'] ?? ''));
        $houseNumber = trim((string) ($props['housenumber'] ?? ''));
        if ($street !== '') {
            $parts[] = $houseNumber !== '' ? $houseNumber . ' ' . $street : $street;
        }
        foreach (['city', 'state', 'country'] as $key) {
            $value = trim((string) ($props[$key] ?? ''));
            if ($value !== '' && !in_array($value, $parts, true) && $value !== trim((string) ($props['name'] ?? ''))) {
                $parts[] = $value;
            }
        }
        return implode(', ', $parts);
    }

    /** Great-circle distance in km (haversine). */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * Map a decoded Photon response into candidate rows. When a bias point is
     * given, each candidate carries distanceKm and far (> 500 km).
     *
     * @return list<array{name:string,address:string,lat:float,lng:float,display:string,city:?string,distanceKm:?float,far:bool}>
     */
    public static function mapFeatures(mixed $decoded, ?float $biasLat, ?float $biasLng): array
    {
        if (!is_array($decoded) || !is_array($decoded['features'] ?? null)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($decoded['features'] as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $coords = $feature['geometry']['coordinates'] ?? null;
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
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $name = trim((string) ($props['name'] ?? ''));
            $address = self::composeAddress($props);
            if ($name === '') {
                $name = $address !== '' ? explode(', ', $address)[0] : '';
            }
            if ($name === '') {
                continue;
            }
            [$lat, $lng] = $point;
            // Photon repeats places across OSM layers; keep the first of each.
            $dedupe = mb_strtolower($name . '|' . $address);
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $display = $address === '' ? $name : $name . ', ' . $address;
            $distance = null;
            if ($biasLat !== null && $biasLng !== null) {
                $distance = round(self::distanceKm($biasLat, $biasLng, $lat, $lng), 1);
            }
            $city = trim((string) ($props['city'] ?? ''));
            $out[] = [
                'name' => $name,
                'address' => $address,
                'lat' => $lat,
                'lng' => $lng,
                'display' => $display,
                'city' => $city !== '' ? $city : null,
                // Photon's feature type ('city', 'street', 'house', ...; 'other'
                // for most POIs, in which case the OSM value names it). The
                // plausibility guard exempts place-level kinds from the
                // distance check, so a bare "Tokyo" may be far away.
                'kind' => (isset($props['type']) && $props['type'] !== 'other')
                    ? (string) $props['type']
                    : (isset($props['osm_value']) ? (string) $props['osm_value'] : null),
                'distanceKm' => $distance,
                'far' => $distance !== null && $distance > self::FAR_KM,
            ];
        }
        return $out;
    }

    /**
     * Re-rank candidates when a bias exists: blend Photon's own order (index)
     * with distance so nearby matches beat textually-equal matches half a
     * world away, without letting distance completely override relevance.
     * Score = index + distance penalty (capped); lower is better; stable.
     *
     * @param list<array<string,mixed>> $candidates rows from mapFeatures
     * @return list<array<string,mixed>>
     */
    public static function rank(array $candidates): array
    {
        $scored = [];
        foreach ($candidates as $i => $c) {
            $d = $c['distanceKm'] ?? null;
            // 0 penalty at 0 km, 1 per 250 km, capped at 8 (anything beyond
            // 2000 km is equally "far"): a top far match still loses to a
            // mid-list near match, but never to page-bottom noise.
            $penalty = $d === null ? 0.0 : min(8.0, (float) $d / 250.0);
            $scored[] = ['score' => $i + $penalty, 'index' => $i, 'row' => $c];
        }
        usort($scored, static fn(array $a, array $b): int => [$a['score'], $a['index']] <=> [$b['score'], $b['index']]);
        return array_map(static fn(array $s): array => $s['row'], $scored);
    }

    // ---- Lookup --------------------------------------------------------

    /**
     * @return list<array<string,mixed>> ranked candidates (empty on provider
     *   failure; transport errors never bubble to the client)
     */
    public function search(string $q, ?float $biasLat, ?float $biasLng, int $limit): array
    {
        $q = Geocode::normalize($q);
        if ($q === '') {
            throw HttpError::badRequest('q is required');
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $params = ['q' => $q, 'limit' => $limit + 4]; // headroom for dedupe + re-rank
        $biased = $biasLat !== null && $biasLng !== null;
        if ($biased) {
            $params['lat'] = $biasLat;
            $params['lon'] = $biasLng;
        }
        // "Smith and Sons" is "Smith & Sons" in OpenStreetMap, and Photon
        // matches neither spelling from the other. Both are asked at once;
        // the other spelling's matches go first, since they are the ones the
        // typed spelling missed, and duplicates fold in mapFeatures.
        $alt = self::ampersandVariant($q);
        $batch = static fn(array $p): array => $alt === null ? [$p] : [['q' => $alt] + $p, $p];

        // Photon's lat/lon is only a soft preference: it still ranks the whole
        // world by text match and prominence, so for half-typed input nothing
        // nearby made its top results (a half-typed street name near home came
        // back as a restaurant on another continent). With a bias, search a box around it first (a hard
        // filter), and only look worldwide when the region has too little.
        $candidates = null;
        if ($biased) {
            $candidates = $this->candidates($batch($params + ['bbox' => self::regionBox($biasLat, $biasLng)]), $q, $biasLat, $biasLng);
        }
        // Worldwide only when the region has too little AND there is a real
        // word to go on: a bare "250" worldwide is every place numbered 250.
        // Far results must also contain every typed word (the last may be
        // partial); nearby, Photon's typo tolerance helps, but from across
        // the world it only adds noise (a half-typed street name matched a
        // street on another continent with a different number).
        if ($candidates === null || (count($candidates) < self::REGION_ENOUGH && self::hasWord($q))) {
            $world = $this->candidates($batch($params), $q, $biasLat, $biasLng);
            if ($candidates === null && $world === null) {
                return [];
            }
            $world = $biased ? self::containingAllWords($q, $world ?? []) : ($world ?? []);
            $candidates = self::mergeCandidates($candidates ?? [], $world);
        }
        if ($biased) {
            $candidates = self::rank($candidates);
        }
        // Airport codes: pin the IATA-table airport above everything — bias
        // must not bury SFO under a same-named street nearby, and in Denmark
        // "SFO" is an after-school program, which is never the intent of an
        // all-caps three-letter location.
        $airport = Geocode::airport($q);
        if ($airport !== null) {
            $address = implode(', ', array_values(array_filter(
                [$airport['city'], $airport['country']],
                static fn(string $p): bool => $p !== ''
            )));
            $distance = $biasLat !== null && $biasLng !== null
                ? round(self::distanceKm($biasLat, $biasLng, $airport['lat'], $airport['lng']), 1)
                : null;
            $pinned = [
                'name' => $airport['name'],
                'address' => $address,
                'lat' => $airport['lat'],
                'lng' => $airport['lng'],
                'display' => $airport['display'],
                'city' => $airport['city'] !== '' ? $airport['city'] : null,
                'distanceKm' => $distance,
                'far' => $distance !== null && $distance > self::FAR_KM,
            ];
            $candidates = array_merge([$pinned], array_values(array_filter(
                $candidates,
                static fn(array $c): bool => mb_strtolower($c['name']) !== mb_strtolower($airport['name'])
            )));
        }
        return array_slice($candidates, 0, $limit);
    }

    /** Fewer regional matches than this, and the worldwide search runs too. */
    public const REGION_ENOUGH = 3;
    /** Half the region box's height, in degrees of latitude (about 300 km). */
    public const REGION_HALF_DEG = 2.7;

    /**
     * Photon's bbox (minLon,minLat,maxLon,maxLat) around a point, about 300 km
     * each way: big enough for a metro area and its surroundings, small enough
     * that the rest of the world can't crowd it out. Pure.
     */
    public static function regionBox(float $lat, float $lng): string
    {
        $dLat = self::REGION_HALF_DEG;
        // Degrees of longitude shrink toward the poles; keep the box square-ish.
        $dLng = min(30.0, $dLat / max(0.2, cos(deg2rad($lat))));
        $f = static fn(float $v): string => rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
        return implode(',', [
            $f(max(-180.0, $lng - $dLng)), $f(max(-90.0, $lat - $dLat)),
            $f(min(180.0, $lng + $dLng)), $f(min(90.0, $lat + $dLat)),
        ]);
    }

    /**
     * Keep only candidates that contain the house number the query starts
     * with ("250 elm" must not offer "Elm Diner" or "258 Oak Road").
     * A query without a leading number keeps everything. Pure.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    public static function matchingNumber(string $q, array $candidates): array
    {
        if (preg_match('/^\s*(\d+[a-z]?)\b/iu', $q, $m) !== 1) {
            return $candidates;
        }
        $num = '/(?<![\p{L}\p{N}])' . preg_quote($m[1], '/') . '(?![\p{L}\p{N}])/iu';
        return array_values(array_filter($candidates, static fn(array $c): bool =>
            preg_match($num, (string) ($c['name'] ?? '') . ' ' . (string) ($c['address'] ?? '')) === 1));
    }

    /** A query word of three or more letters (not just numbers). Pure. */
    public static function hasWord(string $q): bool
    {
        return preg_match('/\p{L}{3,}/u', $q) === 1;
    }

    /**
     * Keep candidates whose name and address contain every query word: each
     * word as the start of a word in the candidate, numbers whole. The last
     * word may be partial (it is still being typed). Pure.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    public static function containingAllWords(string $q, array $candidates): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return $candidates;
        }
        return array_values(array_filter($candidates, static function (array $c) use ($words): bool {
            $text = mb_strtolower((string) ($c['name'] ?? '') . ' ' . (string) ($c['address'] ?? ''));
            foreach ($words as $w) {
                $pattern = ctype_digit($w)
                    ? '/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u'
                    : '/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '/u';
                if (preg_match($pattern, $text) !== 1) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Regional candidates first, then worldwide ones not already listed. Pure.
     *
     * @param list<array<string,mixed>> $first
     * @param list<array<string,mixed>> $then
     * @return list<array<string,mixed>>
     */
    public static function mergeCandidates(array $first, array $then): array
    {
        $key = static fn(array $c): string => mb_strtolower((string) $c['name']) . '|' . round((float) $c['lat'], 4) . '|' . round((float) $c['lng'], 4);
        $seen = [];
        $out = [];
        foreach ([...$first, ...$then] as $c) {
            if (!isset($seen[$key($c)])) {
                $seen[$key($c)] = true;
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * One Photon batch, mapped and filtered: null when every request failed
     * (so a provider outage is told apart from "nothing matched").
     *
     * @param list<array<string,mixed>> $paramSets
     * @return ?list<array<string,mixed>>
     */
    private function candidates(array $paramSets, string $q, ?float $biasLat, ?float $biasLng): ?array
    {
        $bodies = $this->fetchAll($paramSets);
        $features = [];
        $anyOk = false;
        foreach ($bodies as $decoded) {
            if (is_array($decoded) && is_array($decoded['features'] ?? null)) {
                $anyOk = true;
                array_push($features, ...$decoded['features']);
            }
        }
        if (!$anyOk) {
            return null;
        }
        return self::matchingNumber($q, self::mapFeatures(['features' => $features], $biasLat, $biasLng));
    }

    /** The query with "and" and "&" swapped, or null when it has neither. */
    public static function ampersandVariant(string $q): ?string
    {
        if (preg_match('/\s&\s/u', $q)) {
            return preg_replace('/\s&\s/u', ' and ', $q);
        }
        if (preg_match('/\sand\s/iu', $q)) {
            return preg_replace('/\sand\s/iu', ' & ', $q);
        }
        return null;
    }

    /**
     * Several provider fetches in parallel; each result null on failure.
     * @param list<array<string,mixed>> $paramSets
     * @return list<?array>
     */
    private function fetchAll(array $paramSets): array
    {
        return $this->transport->photon($paramSets);
    }
}
