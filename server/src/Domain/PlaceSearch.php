<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;

/**
 * Multi-candidate place autocomplete for the location dropdown. Results are
 * transient and never cached (Geocode is the single-pin lookup, cached).
 *
 * The provider (PlaceProvider; PhotonPlaces today) fetches candidates and
 * carries every workaround its service needs. This class holds the rules
 * that apply whatever the provider: a leading house number must appear in a
 * result; an address goes nearest first; a bare number is never a far
 * place; results past 500 km are flagged far so the UI can call them out;
 * an airport code pins the airport. A provider may mark a row 'pin' (the
 * place named exactly what was typed): it goes first, ahead of ranking.
 *
 * Location bias keeps "Main Street" from matching half a world away:
 * explicit lat/lng (the user's home location setting) wins, else a static
 * IANA-timezone centroid approximates the region, else no bias.
 */
final class PlaceSearch
{
    public const DEFAULT_LIMIT = 6;
    public const MAX_LIMIT = 10;
    public const FAR_KM = 500.0;

    public function __construct(private readonly PlaceProvider $provider)
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
     * One candidate row, the shape every provider returns and the API sends.
     * With a bias point it carries distanceKm and far (> 500 km).
     *
     * @return array{name:string,address:string,lat:float,lng:float,display:string,city:?string,kind:?string,distanceKm:?float,far:bool}
     */
    public static function row(string $name, string $address, float $lat, float $lng, ?string $city, ?string $kind, ?float $biasLat, ?float $biasLng, ?string $display = null): array
    {
        $distance = $biasLat !== null && $biasLng !== null
            ? round(self::distanceKm($biasLat, $biasLng, $lat, $lng), 1)
            : null;
        return [
            'name' => $name,
            'address' => $address,
            'lat' => $lat,
            'lng' => $lng,
            'display' => $display ?? ($address === '' ? $name : $name . ', ' . $address),
            'city' => $city,
            'kind' => $kind,
            'distanceKm' => $distance,
            'far' => $distance !== null && $distance > self::FAR_KM,
        ];
    }

    /**
     * Re-rank candidates when a bias exists: blend the provider's own order
     * (index) with distance so nearby matches beat textually-equal matches
     * half a world away, without letting distance completely override
     * relevance. Score = index + distance penalty (capped); lower is better;
     * stable.
     *
     * @param list<array<string,mixed>> $candidates
     * @param float $maxPenalty the cap: 8 (the default) for one worldwide
     *   list, where the right answer may sit mid-list (Geocode); for the
     *   dropdown each provider sets its own (PlaceProvider::distanceCap)
     * @return list<array<string,mixed>>
     */
    public static function rank(array $candidates, float $maxPenalty = 8.0): array
    {
        $scored = [];
        foreach ($candidates as $i => $c) {
            $d = $c['distanceKm'] ?? null;
            // 0 penalty at 0 km, 1 per 250 km, capped at 8 (anything beyond
            // 2000 km is equally "far"): a top far match still loses to a
            // mid-list near match, but never to page-bottom noise.
            $penalty = $d === null ? 0.0 : min($maxPenalty, (float) $d / 250.0);
            $scored[] = ['score' => $i + $penalty, 'index' => $i, 'row' => $c];
        }
        usort($scored, static fn(array $a, array $b): int => [$a['score'], $a['index']] <=> [$b['score'], $b['index']]);
        return array_map(static fn(array $s): array => $s['row'], $scored);
    }

    /** The house number a query starts with ("250", "12b"), or null. Pure. */
    public static function houseNumber(string $q): ?string
    {
        return preg_match('/^\s*(\d+[a-z]?)\b/iu', $q, $m) === 1 ? $m[1] : null;
    }

    /**
     * The first language in an Accept-Language header as a primary subtag
     * ('en' from "en-US,en;q=0.9"), or null. Only the first: someone who
     * prefers Spanish is better served by local names than by English ones,
     * so a provider without Spanish falls back to local names. Pure.
     */
    public static function preferredLanguage(?string $acceptLanguage): ?string
    {
        $first = strtolower(trim(explode(',', (string) $acceptLanguage)[0]));
        $primary = explode('-', explode(';', $first)[0])[0];
        return preg_match('/^[a-z]{2,3}$/', $primary) === 1 ? $primary : null;
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
        $number = self::houseNumber($q);
        if ($number === null) {
            return $candidates;
        }
        $num = '/(?<![\p{L}\p{N}])' . preg_quote($number, '/') . '(?![\p{L}\p{N}])/iu';
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
     * The first list's candidates, then the second's not already listed. Pure.
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
     * Nearest first; rows without a distance keep their order at the end.
     * Stable. Pure.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    public static function byDistance(array $candidates): array
    {
        $keyed = [];
        foreach ($candidates as $i => $c) {
            $keyed[] = [$c['distanceKm'] ?? INF, $i, $c];
        }
        usort($keyed, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_column($keyed, 2);
    }

    /**
     * Results for an address query: those showing every typed word first,
     * then looser matches (a provider's typo tolerance), each group nearest
     * first. Pure.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    public static function addressOrder(string $q, array $candidates): array
    {
        $full = self::containingAllWords($q, $candidates);
        $loose = array_values(array_filter($candidates, static fn(array $c): bool => !in_array($c, $full, true)));
        return [...self::byDistance($full), ...self::byDistance($loose)];
    }

    // ---- Lookup --------------------------------------------------------

    /**
     * @param ?string $language preferred language (preferredLanguage), or null
     * @return list<array<string,mixed>> ranked candidates (empty on provider
     *   failure; transport errors never bubble to the client)
     */
    public function search(string $q, ?float $biasLat, ?float $biasLng, int $limit, ?string $language = null): array
    {
        $q = Geocode::normalize($q);
        if ($q === '') {
            throw HttpError::badRequest('q is required');
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $biased = $biasLat !== null && $biasLng !== null;

        $candidates = self::matchingNumber($q, $this->provider->candidates($q, $biasLat, $biasLng, $limit, $language) ?? []);
        $pinned = array_values(array_filter($candidates, static fn(array $c): bool => !empty($c['pin'])));
        $candidates = array_values(array_filter($candidates, static fn(array $c): bool => empty($c['pin'])));
        if ($biased) {
            if (self::houseNumber($q) !== null) {
                // A bare number is a house nearby: worldwide it is every
                // place with that number.
                if (!self::hasWord($q)) {
                    $candidates = array_values(array_filter($candidates, static fn(array $c): bool => !$c['far']));
                }
                // An address is a place you mean to go: the nearest match is
                // the one, however exact a far one looks ("250 elm" must offer
                // the Elmwood Avenue nearby before an Elm Court a thousand
                // miles off).
                $candidates = self::addressOrder($q, $candidates);
            } else {
                $cap = $this->provider->distanceCap();
                if ($cap !== null) {
                    $candidates = self::rank($candidates, $cap);
                }
            }
        }
        $candidates = array_merge(
            array_map(static function (array $c): array { unset($c['pin']); return $c; }, $pinned),
            $candidates
        );
        // Airport codes: pin the IATA-table airport above everything; bias
        // must not bury SFO under a same-named street nearby, and in Denmark
        // "SFO" is an after-school program, which is never the intent of an
        // all-caps three-letter location.
        $airport = Geocode::airport($q);
        if ($airport !== null) {
            $address = implode(', ', array_values(array_filter(
                [$airport['city'], $airport['country']],
                static fn(string $p): bool => $p !== ''
            )));
            $pinned = self::row(
                $airport['name'],
                $address,
                $airport['lat'],
                $airport['lng'],
                $airport['city'] !== '' ? $airport['city'] : null,
                'aerodrome',
                $biasLat,
                $biasLng,
                $airport['display']
            );
            $candidates = array_merge([$pinned], array_values(array_filter(
                $candidates,
                static fn(array $c): bool => mb_strtolower($c['name']) !== mb_strtolower($airport['name'])
            )));
        }
        return array_slice($candidates, 0, $limit);
    }
}
