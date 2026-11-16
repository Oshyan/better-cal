<?php

declare(strict_types=1);

// The single-pin lookup (Geocode::lookup) asks again before giving up
// (#150): when the full text finds nothing, its easier forms are tried, and
// a form that leaves out what came before a comma (a venue's name the map
// data lacks) is kept only when its pin holds the part the form starts with
// and is a spot, never a town or an area: "Venue, Pier 3, Town" is placed by
// the pier, "Venue, Town" is never placed by the town. Photon is stood in
// for; the cache is the real table.

use BetterCal\Domain\Geocode;
use BetterCal\Infra\Db;
use BetterCal\Infra\GeocoderTransport;

return static function (Db $db): void {
    $photon = new class implements GeocoderTransport {
        /** @var array<string,list<array>> lowercased query => features */
        public array $byQ = [];
        /** @var list<string> */
        public array $asked = [];
        public function photon(array $paramSets): array
        {
            return array_map(function (array $p): array {
                $q = mb_strtolower((string) $p['q']);
                $this->asked[] = $q;
                return ['features' => $this->byQ[$q] ?? []];
            }, $paramSets);
        }
        public function openMeteo(array $params): ?array { return null; }
    };
    $feature = static fn(string $name, float $lat, float $lng, array $props): array =>
        ['geometry' => ['coordinates' => [$lng, $lat]], 'properties' => ['name' => $name] + $props + ['city' => 'Exampleton', 'country' => 'Exampleland']];
    $pier = $feature('Pier 3', 12.31, 56.71, ['type' => 'other', 'osm_key' => 'man_made', 'osm_value' => 'pier']);
    $pier30 = $feature('Pier 30', 12.32, 56.72, ['type' => 'other', 'osm_key' => 'man_made', 'osm_value' => 'pier']);
    $town = $feature('Exampleton', 12.30, 56.70, ['type' => 'city', 'osm_key' => 'place', 'osm_value' => 'town']);
    $house = $feature('', 12.33, 56.73, ['type' => 'house', 'housenumber' => '12', 'street' => 'Sample Street']);
    $geo = new Geocode($db, $photon);
    [$lat, $lng] = [12.30, 56.70];
    $cachedAt = static fn(string $q): ?array => $db->one('SELECT lat, lng FROM geocode_cache WHERE query_hash = ?', [Geocode::queryHash($q, $lat, $lng)]);

    scenario('place lookup: a venue the map lacks is placed by the address after its name', function () use ($geo, $photon, $pier, $town, $lat, $lng, $cachedAt): void {
        $photon->byQ = ['pier 3, exampleton' => [$pier, $town]];
        $r = $geo->lookup('Example Pavilion, Pier 3, Exampleton', $lat, $lng);
        checkEq('place lookup: "Venue, Pier 3, Town" is pinned at the pier', [12.31, 56.71, 'photon'], [$r['lat'], $r['lng'], $r['provider'] ?? null]);
        $row = $cachedAt('Example Pavilion, Pier 3, Exampleton') ?? ['lat' => null, 'lng' => null];
        checkEq('place lookup: the full text keeps that answer in the cache', [12.31, 56.71], [round((float) $row['lat'], 2), round((float) $row['lng'], 2)]);
    });

    scenario('place lookup: the town after a venue\'s name is never its pin', function () use ($geo, $photon, $town, $pier30, $lat, $lng): void {
        $photon->byQ = ['exampleton' => [$town], 'pier 3, example harbor' => [$pier30, $town]];
        $photon->asked = [];
        $r = $geo->lookup('Example Arcade, Exampleton', $lat, $lng);
        checkEq('place lookup: "Venue, Town" is not placed at the town', [null, ['example arcade, exampleton', 'exampleton']], [$r['lat'], $photon->asked]);
        $r = $geo->lookup('Example Gallery, Pier 3, Example Harbor', 12.30, 56.70);
        checkEq('place lookup: a pin without the part the form starts with (Pier 30 for Pier 3) is not kept', null, $r['lat']);
    });

    scenario('place lookup: a street address after a venue is held to the same rule', function () use ($geo, $photon, $town, $house): void {
        $photon->byQ = ['450 sample st, exampleton' => [$town]];
        $r = $geo->lookup('The Example Lounge, 450 Sample St, Exampleton', 12.30, 56.70);
        checkEq('place lookup: the street-number form answered only by the town is no pin', null, $r['lat']);
        $photon->byQ = ['12 sample st, exampleton' => [$house, $town]];
        $r = $geo->lookup('The Example Bar, 12 Sample St, Exampleton', 12.30, 56.70);
        checkEq('place lookup: the street-number form answered by the house is pinned there', [12.33, 56.73], [$r['lat'], $r['lng']]);
    });

    scenario('place lookup: a full text that is found asks nothing more', function () use ($geo, $photon, $town): void {
        $photon->byQ = ['example plaza, exampleton' => [$town]];
        $photon->asked = [];
        $r = $geo->lookup('Example Plaza, Exampleton', 12.30, 56.70);
        checkEq('place lookup: the full text\'s own answer stands, as before', [12.30, ['example plaza, exampleton']], [$r['lat'], $photon->asked]);
    });
};
