<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\GeocoderTransport;

/**
 * Place search on photon.komoot.io (free, no key), and every workaround its
 * behaviour needs. None of these are rules for other providers:
 *
 * - Photon's lat/lon bias is soft (it still ranks the whole world by text
 *   and prominence), so with a bias a box around the point is searched first
 *   and the world only when the box has too little.
 * - Its search can't find an address from a bare number or a number and a
 *   half-typed street, so a number-led query also asks its reverse endpoint
 *   for the nearest houses with that number.
 * - Its typo tolerance matches junk across the world, so far results must
 *   contain every typed word.
 * - "and" and "&" don't match each other, so both spellings are asked.
 * - Without a language it matches local names only.
 * - With enough nearby matches the world isn't asked, so "munich" offered
 *   only local Munich Streets, never the city. A small worldwide request
 *   for places (cities, counties, states, countries) runs alongside, and one
 *   named exactly what was typed is pinned first.
 */
final class PhotonPlaces implements PlaceProvider
{
    /** Fewer regional matches than this, and the worldwide search runs too. */
    public const REGION_ENOUGH = 3;
    /** How far the nearest-house lookup reaches, matching the region box. */
    public const NEAR_RADIUS_KM = 300;
    /** The place-level layers asked worldwide alongside the regional search. */
    public const PLACE_LAYERS = ['city', 'county', 'state', 'country'];
    /** The languages photon.komoot.io has names for, besides 'default'. */
    public const LANGS = ['en', 'de', 'fr'];

    public function __construct(private readonly GeocoderTransport $transport)
    {
    }

    public function distanceCap(): ?float
    {
        // Its nearby rows already come first; past 500 km Photon's own order
        // should decide (the Eiffel Tower in Paris, not a replica a few
        // thousand km closer).
        return PlaceSearch::FAR_KM / 250.0;
    }

    public function credits(): array
    {
        return [
            ['label' => 'Photon', 'url' => 'https://photon.komoot.io'],
            ['label' => '© OpenStreetMap', 'url' => 'https://www.openstreetmap.org/copyright'],
        ];
    }

    public function candidates(string $q, ?float $biasLat, ?float $biasLng, int $limit, ?string $language): ?array
    {
        // lang: which names Photon matches and shows. Without it, local ones
        // only, so "Eiffel Tower" found three replicas and not the tower
        // (in Paris it is "Tour Eiffel").
        $lang = self::lang($language);
        $params = ['q' => $q, 'limit' => $limit + 4, 'lang' => $lang]; // headroom for dedupe + re-rank
        $biased = $biasLat !== null && $biasLng !== null;
        $address = $biased ? self::addressFilter($q) : null;
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

        // With a bias, search a box around it first (a hard filter): for
        // half-typed input nothing nearby made Photon's top results (a
        // half-typed street name near home came back as a restaurant on
        // another continent).
        $candidates = null;
        $places = [];
        if ($biased) {
            $sets = $batch($params + ['bbox' => PlaceSearch::regionBox($biasLat, $biasLng)]);
            // Places worldwide, in the same parallel batch: a city's name
            // typed in full should offer the city, however many local
            // streets share it. Photon's soft bias still prefers the nearby
            // one ("berkeley" here is the local Berkeley).
            if ($address === null && PlaceSearch::hasWord($q)) {
                $sets[] = ['q' => $q, 'limit' => 3, 'lang' => $lang, 'lat' => $biasLat, 'lon' => $biasLng, 'layer' => self::PLACE_LAYERS];
            }
            // Photon's search can't find an address from a bare number, nor
            // from a number and a half-typed street ("250 el" found nothing
            // nearby, though "250 elm" did). Its reverse lookup can: the
            // nearest houses carrying that number, nearest first, filtered
            // to the typed words.
            if ($address !== null) {
                $sets[] = [
                    '_endpoint' => 'reverse',
                    'lat' => $biasLat,
                    'lon' => $biasLng,
                    'radius' => self::NEAR_RADIUS_KM,
                    'limit' => $limit + 6,
                    'query_string_filter' => $address,
                    'lang' => $lang,
                ];
            }
            $candidates = $this->fetch($sets, $q, $biasLat, $biasLng, $places);
        }
        // Worldwide only when the region has too little AND there is a real
        // word to go on: a bare "250" worldwide is every place numbered 250.
        // Far results must also contain every typed word (the last may be
        // partial); nearby, Photon's typo tolerance helps, but from across
        // the world it only adds noise (a half-typed street name matched a
        // street on another continent with a different number). An address
        // found nearby is the one meant; the world is asked only when the
        // region has none.
        $found = $address !== null ? count(PlaceSearch::containingAllWords($q, $candidates ?? [])) : count($candidates ?? []);
        $enough = $address !== null ? 1 : self::REGION_ENOUGH;
        if ($candidates === null || ($found < $enough && PlaceSearch::hasWord($q))) {
            $world = $this->fetch($batch($params), $q, $biasLat, $biasLng);
            if ($candidates === null && $world === null) {
                return null;
            }
            $world = $biased ? PlaceSearch::containingAllWords($q, $world ?? []) : ($world ?? []);
            $candidates = PlaceSearch::mergeCandidates($candidates ?? [], $world);
        }
        $exact = PlaceSearch::exactPlace($q, $places, $candidates ?? []);
        if ($exact !== null) {
            $candidates = PlaceSearch::mergeCandidates([$exact + ['pin' => true]], $candidates);
        }
        return $candidates;
    }

    /**
     * Places big enough to pin by name: a city or town, a county, state or
     * country. Villages and hamlets share names with too much ("muni"
     * matched a village abroad). Pure, on Photon features.
     */
    public static function placeLevel(array $feature): bool
    {
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        return in_array($props['type'] ?? null, ['county', 'state', 'country'], true)
            || in_array($props['osm_value'] ?? null, ['city', 'town'], true);
    }

    // ---- Pure helpers (unit-tested, no network) ------------------------

    /**
     * Photon's lang for a preferred language: the language when Photon has
     * names in it, else 'default' (each place's local name). Pure.
     */
    public static function lang(?string $language): string
    {
        return in_array($language, self::LANGS, true) ? $language : 'default';
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

    /**
     * Map a decoded Photon response into candidate rows (PlaceSearch::row).
     * When a bias point is given, each carries distanceKm and far.
     *
     * @return list<array<string,mixed>>
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
            // Photon repeats places across OSM layers; keep the first of each.
            $dedupe = mb_strtolower($name . '|' . $address);
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $city = trim((string) ($props['city'] ?? ''));
            // Photon's feature type ('city', 'street', 'house', ...; 'other'
            // for most POIs, in which case the OSM value names it). The
            // plausibility guard exempts place-level kinds from the distance
            // check, so a bare "Tokyo" may be far away.
            $kind = (isset($props['type']) && $props['type'] !== 'other')
                ? (string) $props['type']
                : (isset($props['osm_value']) ? (string) $props['osm_value'] : null);
            $out[] = PlaceSearch::row($name, $address, $point[0], $point[1], $city !== '' ? $city : null, $kind, $biasLat, $biasLng, null, 'photon');
        }
        return $out;
    }

    /**
     * Photon's reverse query_string_filter for an address query: the leading
     * house number, then every typed word, the last as a prefix (still being
     * typed). A prefix under three letters is left out: "a*" makes Photon
     * expand every word starting with a and fail after seconds, so the
     * nearest houses with the number come back and the word filter that
     * follows narrows them. Null when the query doesn't start with a house
     * number. Words are letters and digits only and lowercased, so nothing
     * typed can act as query syntax (operators are uppercase). Pure.
     */
    public static function addressFilter(string $q): ?string
    {
        $number = PlaceSearch::houseNumber($q);
        if ($number === null) {
            return null;
        }
        $terms = ['housenumber:' . mb_strtolower($number)];
        $rest = (string) preg_replace('/^\s*' . preg_quote($number, '/') . '/iu', '', $q);
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($rest), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice(array_values(array_filter($words, static fn(string $w): bool => $w !== 'and')), 0, 6);
        foreach ($words as $i => $w) {
            if ($i === count($words) - 1 && preg_match('/\p{L}$/u', $w) === 1) {
                if (mb_strlen($w) >= 3) {
                    $terms[] = $w . '*';
                }
            } else {
                $terms[] = $w;
            }
        }
        return implode(' AND ', $terms);
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

    // ---- Network -------------------------------------------------------

    /**
     * One Photon batch in parallel, mapped and filtered: null when every
     * request failed (so a provider outage is told apart from "nothing
     * matched"). The place-level request's results (it carries a layer
     * filter) come back separately in $places.
     *
     * @param list<array<string,mixed>> $paramSets
     * @param list<array<string,mixed>> $places
     * @return ?list<array<string,mixed>>
     */
    private function fetch(array $paramSets, string $q, ?float $biasLat, ?float $biasLng, array &$places = []): ?array
    {
        $bodies = $this->transport->photon($paramSets);
        $features = [];
        $nearest = [];
        $placeFeatures = [];
        $anyOk = false;
        foreach ($bodies as $i => $decoded) {
            if (is_array($decoded) && is_array($decoded['features'] ?? null)) {
                $anyOk = true;
                if (($paramSets[$i]['_endpoint'] ?? null) === 'reverse') {
                    array_push($nearest, ...$decoded['features']);
                } elseif (isset($paramSets[$i]['layer'])) {
                    array_push($placeFeatures, ...$decoded['features']);
                } else {
                    array_push($features, ...$decoded['features']);
                }
            }
        }
        $places = self::mapFeatures(['features' => array_values(array_filter($placeFeatures, [self::class, 'placeLevel']))], $biasLat, $biasLng);
        if (!$anyOk) {
            return null;
        }
        // The reverse filter matches a word in any field (a district, a
        // neighbouring name), so its houses must show every typed word.
        $near = PlaceSearch::containingAllWords($q, self::mapFeatures(['features' => $nearest], $biasLat, $biasLng));
        // The house number check runs here too: it decides whether the
        // region found enough.
        return PlaceSearch::matchingNumber($q, PlaceSearch::mergeCandidates($near, self::mapFeatures(['features' => $features], $biasLat, $biasLng)));
    }
}
