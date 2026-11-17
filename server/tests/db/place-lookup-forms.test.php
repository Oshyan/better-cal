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

    // The text is read before anything is asked (#328,
    // docs/design/location-reading.md).
    scenario('place lookup: what is not a place, or a room alone, asks nothing and caches nothing', function () use ($geo, $photon, $lat, $lng, $cachedAt): void {
        $photon->asked = [];
        $out = [];
        foreach (['Zoom link to follow', '+1 555-010-0199 PIN: 448812#', "Location available once RSVP'd", 'example.com/events/fall-social', 'Room 12B', 'sometime after lunch'] as $t) {
            $r = $geo->lookup($t, $lat, $lng);
            $out[] = [$r['lat'], $r['noPlace'] ?? null, $cachedAt($t), $geo->cached($t, $lat, $lng)];
        }
        checkEq('place lookup: each says why, none is cached, each counts as nothing to fetch',
            [[null, 'meeting', null, true], [null, 'phone', null, true], [null, 'later', null, true], [null, 'link', null, true], [null, 'room', null, true], [null, 'note', null, true]], $out);
        checkEq('place lookup: no service was asked', [], $photon->asked);
    });

    scenario('place lookup: typed coordinates are their own answer', function () use ($geo, $photon, $lat, $lng): void {
        $photon->asked = [];
        $r = $geo->lookup('12.3456, -65.4321', $lat, $lng);
        checkEq('place lookup: the coordinates, no provider, nothing asked', [12.3456, -65.4321, null, []], [$r['lat'], $r['lng'], $r['provider'], $photon->asked]);
    });

    scenario('place lookup: an address after a dash is found, and the text keeps the answer', function () use ($geo, $photon, $house, $town, $lat, $lng, $cachedAt): void {
        $photon->byQ = ['12 sample street, exampleton' => [$house, $town]];
        $photon->asked = [];
        $t = 'Sample Kitchen - 12 Sample Street - Exampleton';
        $r = $geo->lookup($t, $lat, $lng);
        checkEq('place lookup: the text, then from the address, pinned at the house',
            [12.33, ['sample kitchen - 12 sample street - exampleton', '12 sample street, exampleton']], [$r['lat'], $photon->asked]);
        $row = $cachedAt($t) ?? ['lat' => null];
        checkEq('place lookup: the text\'s own cache row holds the pin', 12.33, round((float) $row['lat'], 2));
        $photon->asked = [];
        $geo->lookup($t, $lat, $lng);
        checkEq('place lookup: asked again, the cache answers', [], $photon->asked);
    });

    scenario('place lookup: a with-clause is never sent, and the text gets its own answer', function () use ($geo, $photon, $pier, $lat, $lng, $cachedAt): void {
        $photon->byQ = ['example boathouse' => [$pier]];
        $photon->asked = [];
        $r = $geo->lookup('Example Boathouse with Alex', $lat, $lng);
        checkEq('place lookup: only the place is asked', [12.31, ['example boathouse']], [$r['lat'], $photon->asked]);
        check('place lookup: the text as written is cached with that pin', ($cachedAt('Example Boathouse with Alex')['lat'] ?? null) !== null);
        $photon->asked = [];
        $r = $geo->lookup('Example Shed with Sam', $lat, $lng);
        checkEq('place lookup: a place found nowhere is a miss for the text as written', [null, ['example shed'], true], [$r['lat'], $photon->asked, $cachedAt('Example Shed with Sam') !== null]);
    });

    scenario('place lookup: a service that does not answer leaves the text uncached', function () use ($db, $lat, $lng): void {
        $down = new class implements GeocoderTransport {
            public function photon(array $paramSets): array { return array_map(static fn(): ?array => null, $paramSets); }
            public function openMeteo(array $params): ?array { return null; }
        };
        $g = new Geocode($db, $down);
        $t = 'Sample Mill - 40 Sample Road - Exampleton';
        $before = $g->cached($t, $lat, $lng);
        $r = $g->lookup($t, $lat, $lng);
        checkEq('place lookup: not found, and still nothing cached for the text', [false, null, false], [$before, $r['lat'], $g->cached($t, $lat, $lng)]);
    });

    // The sweep acts on the same reading: what is not a place is marked tried
    // and listed nowhere; typed coordinates are placed with no request.
    scenario('place sweep: not places are marked tried and not listed, coordinates are placed', function () use ($db, $photon, $house, $town): void {
        $u = freshUser($db);
        $db->update('users', ['settings_json' => json_encode(['homeLat' => 12.30, 'homeLng' => 56.70])], 'id = ?', [$u]);
        $d = domains($db);
        $cal = (int) $d->calendars->create($u, ['name' => 'Sample places'])['id'];
        $day = (new DateTimeImmutable('+10 days'))->format('Y-m-d');
        $ids = [];
        foreach (['meeting' => 'Google Meet', 'room' => 'Room 12B', 'coords' => '12.3456, -65.4321', 'dash' => 'Sample Diner - 12 Sample Street - Exampleton', 'nowhere' => 'Sample Nowhere Shack'] as $k => $loc) {
            $ids[$k] = (int) $d->events->create($u, eventAt($cal, 'Sample ' . $k, $day, ['location' => $loc]))['eventId'];
        }
        $photon->byQ = ['12 sample street, exampleton' => [$house, $town]];
        $photon->asked = [];
        $sweep = new BetterCal\Domain\GeocodeSweep($db, new Geocode($db, $photon), new BetterCal\Domain\Settings($db));
        $stats = $sweep->run(10, 30);
        $rows = [];
        foreach ($ids as $k => $id) {
            $e = $db->one('SELECT location_lat, location_source, location_provider, geocoded_at FROM events WHERE id = ?', [$id]);
            $rows[$k] = [$e['location_lat'] !== null ? round((float) $e['location_lat'], 2) : null, $e['location_source'], $e['geocoded_at'] !== null];
        }
        checkEq('place sweep: each event as its reading says', [
            'meeting' => [null, null, true], 'room' => [null, null, true], 'coords' => [12.35, 'lookup', true],
            'dash' => [12.33, 'lookup', true], 'nowhere' => [null, null, true],
        ], $rows);
        // The street form was cached by an earlier scenario (same bias cell),
        // so it costs no request here.
        checkEq('place sweep: two counted as no place, and only real lookups asked a service', [2, ['sample diner - 12 sample street - exampleton', 'sample nowhere shack']],
            [$stats['notPlaces'] ?? 0, $photon->asked]);
        $listed = (string) $db->scalar("SELECT GROUP_CONCAT(summary SEPARATOR ' | ') FROM mutations WHERE user_id = ? AND summary LIKE 'Couldn%'", [$u]);
        checkEq('place sweep: only the place found nowhere is listed as not placed', [true, false, false], [
            str_contains($listed, 'Sample Nowhere Shack'), str_contains($listed, 'Google Meet'), str_contains($listed, 'Room 12B'),
        ]);
    });

    // Whether an event shows a place (LocationText::shownNoPlace): a pin a
    // person placed, or the event's own data gave, always shows whatever the
    // text; a lookup's pin shows only for text that reads as a place; with no
    // pin, the text decides.
    scenario('place shown: a pin a person placed shows whatever the text, a lookup\'s guess does not', function () use ($db): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = (int) $d->calendars->create($u, ['name' => 'Sample shown'])['id'];
        $day = (new DateTimeImmutable('+5 days'))->format('Y-m-d');
        $pin = ['locationLat' => 12.3456, 'locationLng' => -65.4321];
        $rows = [
            'picked room' => ['Room 12B', $pin + ['locationSource' => 'picked'], null],
            'picked meeting' => ['Google Meet', $pin + ['locationSource' => 'picked'], null],
            'picked phone' => ['+1 555-010-0199', $pin + ['locationSource' => 'picked'], null],
            'picked note' => ['sometime after lunch', $pin + ['locationSource' => 'picked'], null],
            'picked later' => ['Address TBD', $pin + ['locationSource' => 'picked'], null],
            'picked link' => ['example.com/events/fall-social', $pin + ['locationSource' => 'picked'], null],
            'supplied room' => ['Room 14', $pin + ['locationSource' => 'supplied'], null],
            'lookup room' => ['Room 12B', $pin + ['locationSource' => 'lookup'], 'room'],
            'lookup meeting' => ['Zoom link to follow', $pin + ['locationSource' => 'lookup'], 'meeting'],
            'lookup place' => ['Sample Hall, 12 Example Street', $pin + ['locationSource' => 'lookup'], null],
            'no pin room' => ['Room 12B', [], 'room'],
            'no pin place' => ['Sample Hall', [], null],
        ];
        $ids = [];
        $full = [];
        foreach ($rows as $k => [$loc, $more]) {
            $occ = $d->events->create($u, eventAt($cal, 'Sample ' . $k, $day, ['location' => $loc] + $more));
            $ids[(int) $occ['eventId']] = $k;
            $full[$k] = $occ['noPlace'] ?? null;
        }
        $listed = [];
        foreach ($d->events->window($u, new DateTimeImmutable($day . 'T00:00:00Z'), new DateTimeImmutable($day . 'T23:59:59Z'), null, null, false) as $o) {
            if (isset($ids[(int) $o['eventId']])) {
                $listed[$ids[(int) $o['eventId']]] = $o['noPlace'] ?? null;
            }
        }
        $want = array_map(static fn(array $r): ?string => $r[2], $rows);
        ksort($listed);
        ksort($want);
        ksort($full);
        checkEq('place shown: the full record says it', $want, $full);
        checkEq('place shown: the list shape says the same', $want, $listed);
    });
};
