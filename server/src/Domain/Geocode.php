<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

/**
 * Forward geocoding proxy backed by photon.komoot.io (free, no key) with a
 * permanent cache in geocode_cache. Negative provider answers ("no features")
 * are cached too, as NULL lat/lng rows; transport failures are returned as
 * not-found but never cached, so a flaky network cannot poison the cache.
 */
final class Geocode
{
    private const ENDPOINT = 'https://photon.komoot.io/api';
    private const TIMEOUT_SECONDS = 3;
    private const USER_AGENT = 'Better-Cal/0.1 (self-hosted)';
    public const MAX_QUERY_LENGTH = 500;

    public function __construct(private readonly Db $db)
    {
    }

    // ---- Pure helpers (unit-tested, no DB, no network) -----------------

    /** Collapse whitespace and trim; the canonical query form for caching. */
    public static function normalize(string $q): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $q);
        return mb_substr(trim($collapsed ?? $q), 0, self::MAX_QUERY_LENGTH);
    }

    /**
     * Cache key: sha256 over the lowercased normalized query plus the coarse
     * bias cell. Bias participates because the same text resolves differently
     * per region ("SFO" is an airport here, an after-school program in
     * Denmark); integer-degree cells (~100 km) keep travel from thrashing the
     * cache while still separating regions.
     */
    public static function queryHash(string $q, ?float $biasLat = null, ?float $biasLng = null): string
    {
        return hash('sha256', mb_strtolower(self::normalize($q)) . '|' . self::biasCell($biasLat, $biasLng));
    }

    /** "38,-123"-style integer-degree cell, or "none" without a bias. */
    public static function biasCell(?float $biasLat, ?float $biasLng): string
    {
        if ($biasLat === null || $biasLng === null) {
            return 'none';
        }
        return (string) (int) round($biasLat) . ',' . (string) (int) round($biasLng);
    }

    /**
     * A location that is exactly three uppercase ASCII letters is treated as
     * an IATA airport code ("SFO", "KOA"): overwhelmingly the right reading
     * in a calendar, and the uppercase requirement keeps ordinary words out.
     */
    public static function isAirportCode(string $q): bool
    {
        return preg_match('/^[A-Z]{3}$/', self::normalize($q)) === 1;
    }

    /** @var array<string, array{0: float, 1: float, 2: string, 3: string, 4: string}>|null */
    private static ?array $iata = null;

    /**
     * Resolve an IATA airport code against the vendored OurAirports table
     * (server/data/iata-airports.php, ~9k airports). Null when the query is
     * not an airport code or the code is unknown — callers fall through to
     * regular geocoding.
     *
     * @return array{lat: float, lng: float, display: string, name: string, city: string, country: string}|null
     */
    public static function airport(string $q): ?array
    {
        if (!self::isAirportCode($q)) {
            return null;
        }
        self::$iata ??= require __DIR__ . '/../../data/iata-airports.php';
        $row = self::$iata[self::normalize($q)] ?? null;
        if ($row === null) {
            return null;
        }
        $parts = [$row[2]];
        foreach ([$row[3], $row[4]] as $part) {
            if ($part !== '' && !in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }
        return [
            'lat' => (float) $row[0],
            'lng' => (float) $row[1],
            'display' => implode(', ', $parts),
            'name' => $row[2],
            'city' => $row[3],
            'country' => $row[4],
        ];
    }

    /**
     * How significant a settlement is, for breaking ties between places that
     * share a name. The primary provider orders "Lisbon" as eight American
     * towns and villages before Lisboa, Portugal.
     *
     * Note that `city` outranks `county`: somebody typing "Florence" means the
     * city, not the county that shares its name. This ranks what a person
     * probably meant, not administrative seniority.
     */
    private const PLACE_RANK = [
        'country' => 100, 'state' => 90, 'region' => 85, 'province' => 85,
        'city' => 75, 'municipality' => 65, 'borough' => 55, 'county' => 50,
        'town' => 40, 'district' => 30, 'village' => 25, 'suburb' => 20,
        'hamlet' => 15, 'locality' => 10, 'isolated_dwelling' => 5,
    ];

    /**
     * Pick the best feature for a query.
     *
     * Significance ranking applies ONLY among candidates whose own name matches
     * the whole query, which keeps it out of the way of addresses: "1658 Market
     * St, San Francisco" has no candidate named exactly that, so the provider's
     * own street match is left alone. When several places genuinely share a
     * name, the more significant one wins.
     *
     * Pure and unit-tested.
     */
    public static function pickFeature(mixed $decoded, string $query): ?array
    {
        if (!is_array($decoded) || !is_array($decoded['features'] ?? null) || $decoded['features'] === []) {
            return null;
        }
        $features = array_values(array_filter($decoded['features'], 'is_array'));
        if ($features === []) {
            return null;
        }
        $norm = static fn(string $s): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? ''));
        $want = $norm($query);

        $best = null;
        $bestRank = -1;
        foreach ($features as $i => $f) {
            $props = is_array($f['properties'] ?? null) ? $f['properties'] : [];
            $name = is_string($props['name'] ?? null) ? $norm($props['name']) : '';
            if ($name === '' || $name !== $want) {
                continue; // not a same-named place; provider order stands for it
            }
            $kind = mb_strtolower((string) ($props['osm_value'] ?? $props['type'] ?? ''));
            $rank = self::PLACE_RANK[$kind] ?? 0;
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $f;
            }
        }
        // No exact-name candidate (an address, a venue, a misspelling): trust
        // the provider's own ordering, which is tuned for exactly that case.
        return $best ?? $features[0];
    }

    /**
     * Secondary provider: indexes English exonyms and reports population.
     *
     * Primary and secondary are deliberately two isolated pure decoders
     * (mapResponse for the primary, secondaryOverride for this one) with the
     * endpoint alongside each. Making the pair user-configurable later — so an
     * operator can point primary at a keyed provider they prefer — is a config
     * lookup plus one decoder per provider, not a rewrite. See GH #30.
     */
    private const SECONDARY_ENDPOINT = 'https://geocoding-api.open-meteo.com/v1/search';

    /**
     * A result at or below this rank is worth a second opinion. Set at `city`
     * because American "cities" of two thousand people routinely outrank world
     * capitals in the primary's ordering; anything above it (state, province,
     * country) is already unambiguous enough to trust.
     */
    private const DOUBT_RANK = 75; // city

    /** Only a genuinely major place may override the primary provider. */
    private const SECONDARY_MIN_POPULATION = 100000;

    /**
     * Should the secondary provider get a look at this query?
     *
     * Deliberately narrow, because the primary is better at everything except
     * exonyms. It fires only when the primary settled on a SMALL SETTLEMENT:
     *
     *   - an address or a venue has osm_key `highway`/`amenity`/`building`, so
     *     it never qualifies and "Zuni Cafe" is left alone;
     *   - a state, region or country outranks the threshold, so "Hawaii" keeps
     *     the primary's correct answer and never sees the secondary's village
     *     of the same name in Guatemala;
     *   - a town, village, hamlet or locality is exactly the case where a world
     *     capital may be hiding behind a namesake, so we look.
     *
     * Pure and unit-tested.
     */
    public static function shouldConsultSecondary(?array $feature): bool
    {
        if ($feature === null) {
            return true; // nothing found at all: a second opinion costs little
        }
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        if (mb_strtolower((string) ($props['osm_key'] ?? '')) !== 'place') {
            return false; // an address, a venue, a road: the primary owns these
        }
        $kind = mb_strtolower((string) ($props['osm_value'] ?? $props['type'] ?? ''));
        return (self::PLACE_RANK[$kind] ?? 0) <= self::DOUBT_RANK;
    }

    /**
     * Turn a secondary-provider payload into a result, but only when it names a
     * major place. Pure and unit-tested; the fetch is separate.
     */
    public static function secondaryOverride(mixed $decoded): ?array
    {
        if (!is_array($decoded) || !is_array($decoded['results'] ?? null)) {
            return null;
        }
        foreach ($decoded['results'] as $r) {
            if (!is_array($r) || !is_numeric($r['latitude'] ?? null) || !is_numeric($r['longitude'] ?? null)) {
                continue;
            }
            if ((int) ($r['population'] ?? 0) < self::SECONDARY_MIN_POPULATION) {
                continue; // small place: no better than what we already have
            }
            $parts = array_filter([
                is_string($r['name'] ?? null) ? $r['name'] : null,
                is_string($r['admin1'] ?? null) ? $r['admin1'] : null,
                is_string($r['country'] ?? null) ? $r['country'] : null,
            ]);
            return [
                'lat' => (float) $r['latitude'],
                'lng' => (float) $r['longitude'],
                'display' => implode(', ', $parts),
                // This provider only indexes populated places, and it only
                // overrides on a major one, so anything reaching here is a city.
                'kind' => 'city',
            ];
        }
        return null;
    }

    /** Ask the secondary provider; null on any failure, so it can only help. */
    private function fetchSecondary(string $q): ?array
    {
        $url = self::SECONDARY_ENDPOINT . '?' . http_build_query([
            'name' => $q, 'count' => 5, 'language' => 'en', 'format' => 'json',
        ]);
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $status >= 400) {
            return null;
        }
        return self::secondaryOverride(json_decode($body, true));
    }

    /**
     * Map a decoded primary-provider response to a result, or null when it
     * found nothing usable. Photon is GeoJSON: coordinates are [lng, lat].
     *
     * Pass the query to get significance ranking among same-named places;
     * omit it to take the provider's first feature verbatim.
     *
     * @return array{lat: float, lng: float, display: string, kind: ?string}|null
     */
    public static function mapResponse(mixed $decoded, string $query = ''): ?array
    {
        $feature = $query === ''
            ? (is_array($decoded) && is_array($decoded['features'][0] ?? null) ? $decoded['features'][0] : null)
            : self::pickFeature($decoded, $query);
        if ($feature === null) {
            return null;
        }
        $coords = $feature['geometry']['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2 || !is_numeric($coords[0]) || !is_numeric($coords[1])) {
            return null;
        }
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $parts = [];
        foreach (['name', 'street', 'city', 'state', 'country'] as $key) {
            $value = $props[$key] ?? null;
            if (is_string($value) && trim($value) !== '' && !in_array(trim($value), $parts, true)) {
                $parts[] = trim($value);
            }
        }
        return [
            'lat' => (float) $coords[1],
            'lng' => (float) $coords[0],
            'display' => implode(', ', $parts),
            // What sort of place this is, so a caller can tell a region from a
            // spot without running its own geocoder to find out.
            'kind' => mb_strtolower((string) ($props['osm_value'] ?? $props['type'] ?? '')) ?: null,
        ];
    }

    /**
     * Convert a geocode_cache row to the API result shape. NULL lat/lng rows
     * are cached negatives and come back as an all-null result.
     *
     * @return array{lat: float|null, lng: float|null, display: string|null}
     */
    public static function resultFromRow(array $row): array
    {
        if (!isset($row['lat'], $row['lng'])) {
            return ['lat' => null, 'lng' => null, 'display' => null, 'kind' => null];
        }
        return [
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
            'display' => isset($row['display']) ? (string) $row['display'] : null,
            'kind' => isset($row['kind']) && $row['kind'] !== null ? (string) $row['kind'] : null,
        ];
    }

    // ---- Lookup --------------------------------------------------------

    /**
     * Single best result with the same regional bias as the place picker.
     * Airport codes resolve against the vendored IATA table (offline,
     * authoritative — Photon fuzzy-matches "SFO" to Danish after-school
     * programs); everything else fetches a small candidate set with Photon's
     * location bias and re-ranks by PlaceSearch's order-plus-distance blend
     * before taking the top hit.
     *
     * @return array{lat: float|null, lng: float|null, display: string|null}
     */
    /**
     * Whether lookup() would answer from the cache. The background sweep
     * throttles only real network calls, and tells a definitive "could not
     * place" (cached, with null coordinates) from a transport failure (cache
     * still empty afterwards) by asking this before and after.
     */
    public function cached(string $q, ?float $biasLat = null, ?float $biasLng = null): bool
    {
        $normalized = self::normalize($q);
        if ($normalized === '') {
            return true; // nothing to fetch either way
        }
        return $this->db->scalar(
            'SELECT 1 FROM geocode_cache WHERE query_hash = ?',
            [self::queryHash($normalized, $biasLat, $biasLng)]
        ) !== null;
    }

    public function lookup(string $q, ?float $biasLat = null, ?float $biasLng = null): array
    {
        $normalized = self::normalize($q);
        if ($normalized === '') {
            throw HttpError::badRequest('q is required');
        }
        $hash = self::queryHash($normalized, $biasLat, $biasLng);

        $row = $this->db->one('SELECT lat, lng, display, kind FROM geocode_cache WHERE query_hash = ?', [$hash]);
        if ($row !== null) {
            return self::resultFromRow($row);
        }

        $mapped = null;
        $airportHit = self::airport($normalized);
        if ($airportHit !== null) {
            $mapped = ['lat' => $airportHit['lat'], 'lng' => $airportHit['lng'], 'display' => $airportHit['display']];
        } else {
            // 20, not 5: photon buries a world capital under eight American
            // namesakes, so the right answer has to be in the candidate set
            // before pickFeature can choose it.
            $params = ['q' => $normalized, 'limit' => 20];
            if ($biasLat !== null && $biasLng !== null) {
                $params['lat'] = $biasLat;
                $params['lon'] = $biasLng;
            }
            $body = $this->fetch($params);
            if ($body === null) {
                // Transport failure: answer not-found, cache nothing.
                return ['lat' => null, 'lng' => null, 'display' => null, 'kind' => null];
            }
            $decoded = json_decode($body, true);
            if ($biasLat !== null && $biasLng !== null) {
                $ranked = PlaceSearch::rank(PlaceSearch::mapFeatures($decoded, $biasLat, $biasLng));
                $top = $ranked[0] ?? null;
                $mapped = $top === null ? null : [
                    'lat' => (float) $top['lat'],
                    'lng' => (float) $top['lng'],
                    'display' => (string) $top['display'],
                ];
            } else {
                $mapped = self::mapResponse($decoded, $normalized);
                // Photon indexes places under their local name, so an English
                // exonym misses: "Lisbon" matches eight American towns and
                // never Lisboa. When its answer is a small settlement, ask the
                // secondary provider, which indexes English names and knows
                // population. See secondaryOverride() for why this is narrow.
                if (self::shouldConsultSecondary(self::pickFeature($decoded, $normalized))) {
                    $alt = $this->fetchSecondary($normalized);
                    if ($alt !== null) {
                        $mapped = $alt;
                    }
                }
            }
        }
        $this->db->run(
            'INSERT INTO geocode_cache (query_hash, query, lat, lng, display, kind) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE query_hash = query_hash',
            [
                $hash,
                $normalized,
                $mapped['lat'] ?? null,
                $mapped['lng'] ?? null,
                isset($mapped['display']) ? mb_substr($mapped['display'], 0, 500) : null,
                isset($mapped['kind']) ? mb_substr((string) $mapped['kind'], 0, 32) : null,
            ]
        );

        return $mapped ?? ['lat' => null, 'lng' => null, 'display' => null, 'kind' => null];
    }

    /** Raw provider fetch; null on any transport-level failure. */
    private function fetch(array $params): ?string
    {
        $url = self::ENDPOINT . '?' . http_build_query($params);
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $status >= 400) {
            return null;
        }
        return $body;
    }
}
