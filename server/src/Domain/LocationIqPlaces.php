<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\KeyedGeocoderTransport;

/**
 * Place search on LocationIQ (keyed), run in front of Photon
 * (FallbackPlaces), and the workarounds its behaviour needs:
 *
 * - Its autocomplete finds partial names well but has no proximity ranking:
 *   with a bias it is boxed to the region (viewbox + bounded), and the
 *   dropdown's own ranking orders what comes back.
 * - Its search (/v1/search) gets complete words right where autocomplete
 *   doesn't: a park by its full name, a city or landmark worldwide. It runs
 *   alongside, with the region preferred but not required. It also matches
 *   loosely ("grand la" found anything called Grand), so its results must
 *   contain every typed word, the last as a prefix, and only those with
 *   every word whole go ahead of autocomplete's.
 * - Neither finds an address from a bare number or a number and under four
 *   letters of the street ("811 ada" offered a different street outright),
 *   so those queries go to the fallback (accepts()).
 * - "Munich" offers local Munich Streets nearby; a city named exactly what
 *   was typed is pinned first (PlaceSearch::exactPlace), as with Photon.
 * - It answers "nothing found" with HTTP 404 (the transport maps that to an
 *   empty list) and its free plan allows two requests a second: past that it
 *   answers 429, and the fallback answers instead.
 */
final class LocationIqPlaces implements SelectivePlaceProvider
{
    private const BASE = 'https://api.locationiq.com/v1/';
    /** Its autocomplete returns at most 20. */
    private const MAX_AUTOCOMPLETE = 20;

    public function __construct(
        private readonly KeyedGeocoderTransport $transport,
        private readonly string $key,
    ) {
    }

    public function accepts(string $q): bool
    {
        return PlaceSearch::houseNumber($q) === null || preg_match('/\p{L}{4,}/u', $q) === 1;
    }

    public function distanceCap(): ?float
    {
        // Nearby rows come first; past 500 km the service's order decides.
        return PlaceSearch::FAR_KM / 250.0;
    }

    public function credits(): array
    {
        // Its free plan asks for this link, worded so.
        return [
            ['label' => 'Search by LocationIQ.com', 'url' => 'https://locationiq.com'],
            ['label' => '© OpenStreetMap', 'url' => 'https://www.openstreetmap.org/copyright'],
        ];
    }

    public function candidates(string $q, ?float $biasLat, ?float $biasLng, int $limit, ?string $language): ?array
    {
        $biased = $biasLat !== null && $biasLng !== null;
        $box = $biased ? PlaceSearch::regionBox($biasLat, $biasLng) : null;
        $bodies = $this->transport->keyed('locationiq', [
            self::url('autocomplete', $this->key, $q, $limit, $language, $box === null ? [] : ['viewbox' => $box, 'bounded' => 1]),
            self::url('search', $this->key, $q, $limit, $language, ['format' => 'json', 'addressdetails' => 1, 'normalizeaddress' => 1] + ($box === null ? [] : ['viewbox' => $box])),
        ]);
        if (($bodies[0] ?? null) === null && ($bodies[1] ?? null) === null) {
            return null;
        }
        $typed = self::mapResults($bodies[0] ?? [], $biasLat, $biasLng);
        $searched = self::mapResults($bodies[1] ?? [], $biasLat, $biasLng);
        $places = self::mapResults(array_values(array_filter((array) ($bodies[1] ?? []), [self::class, 'placeLevel'])), $biasLat, $biasLng);
        $searched = PlaceSearch::containingAllWords($q, $searched);
        if (!$biased) {
            return PlaceSearch::mergeCandidates($searched, $typed);
        }
        // Nearby matches of every typed word, whole, first (the park by its
        // full name); then the region's partial matches, then other nearby
        // search results ("grand la" must offer Grand Lake before a Grand
        // Meadow Lane), then the world's.
        $near = array_values(array_filter($searched, static fn(array $c): bool => !$c['far']));
        $whole = self::withWholeWords($q, $near);
        $far = array_values(array_filter($searched, static fn(array $c): bool => $c['far']));
        $candidates = array_reduce([$typed, $near, $far], [PlaceSearch::class, 'mergeCandidates'], $whole);
        $exact = PlaceSearch::exactPlace($q, $places, $candidates);
        return $exact === null ? $candidates : PlaceSearch::mergeCandidates([$exact + ['pin' => true]], $candidates);
    }

    // ---- Pure helpers (unit-tested, no network) ------------------------

    /**
     * One request URL. The key rides in the query string, as LocationIQ
     * requires; the transport keeps URLs out of logs. Pure.
     *
     * @param array<string,mixed> $extra
     */
    public static function url(string $endpoint, string $key, string $q, int $limit, ?string $language, array $extra = []): string
    {
        $params = ['q' => $q, 'limit' => min(self::MAX_AUTOCOMPLETE, $limit + 4), 'normalizecity' => 1, 'dedupe' => 1] + $extra;
        if ($language !== null) {
            $params['accept-language'] = $language;
        }
        return self::BASE . $endpoint . '?' . http_build_query($params + ['key' => $key]);
    }

    /**
     * Rows whose name and address contain every typed word as a whole word
     * ("tilden park" in "Tilden Regional Park"; "grand la" in nothing yet,
     * since "la" is still being typed). Pure.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function withWholeWords(string $q, array $rows): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return [];
        }
        return array_values(array_filter($rows, static function (array $c) use ($words): bool {
            $text = mb_strtolower((string) ($c['name'] ?? '') . ' ' . (string) ($c['address'] ?? ''));
            foreach ($words as $w) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $text) !== 1) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Places big enough to pin by name: a city or town, or an administrative
     * area (county, state, country). Villages share names with too much.
     * Pure, on LocationIQ results.
     */
    public static function placeLevel(mixed $result): bool
    {
        if (!is_array($result)) {
            return false;
        }
        $class = $result['class'] ?? null;
        $type = $result['type'] ?? null;
        return ($class === 'place' && in_array($type, ['city', 'town'], true))
            || ($class === 'boundary' && $type === 'administrative' && empty($result['address']['house_number']));
    }

    /**
     * Map decoded autocomplete or search results into candidate rows. The
     * name is the place's own ("Grand Lake Theatre"); for an address it is
     * the house number and street, since LocationIQ names a house after its
     * street. The address line is street, city, state, country, without
     * repeats of the name. Pure.
     *
     * @return list<array<string,mixed>>
     */
    public static function mapResults(mixed $decoded, ?float $biasLat, ?float $biasLng): array
    {
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $r) {
            if (!is_array($r) || !is_numeric($r['lat'] ?? null) || !is_numeric($r['lon'] ?? null)) {
                continue;
            }
            try {
                $point = Coordinates::pair($r['lat'], $r['lon']);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($point === null) {
                continue;
            }
            $a = is_array($r['address'] ?? null) ? $r['address'] : [];
            $str = static fn(string $k): string => trim((string) ($a[$k] ?? ''));
            $display = trim((string) ($r['display_name'] ?? ''));
            $place = trim((string) ($r['display_place'] ?? '')) ?: $str('name') ?: trim(explode(',', $display)[0]);
            $road = $str('road');
            $number = $str('house_number');
            $street = $road !== '' ? trim($number . ' ' . $road) : '';
            // A house comes named after its street, or by its number alone.
            $name = $number !== '' && $road !== '' && in_array($place, ['', $road, $number], true) ? $street : $place;
            if ($name === '') {
                continue;
            }
            $parts = [];
            foreach ([$street, $str('city'), $str('state'), $str('country')] as $part) {
                if ($part !== '' && $part !== $name && !in_array($part, $parts, true)) {
                    $parts[] = $part;
                }
            }
            $city = $str('city');
            $kind = isset($r['type']) ? (string) $r['type'] : null;
            $out[] = PlaceSearch::row($name, implode(', ', $parts), $point[0], $point[1], $city !== '' ? $city : null, $kind, $biasLat, $biasLng, null, 'locationiq');
        }
        return $out;
    }
}
