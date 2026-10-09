<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\GeocoderTransport;
use BetterCal\Infra\KeyedGeocoderTransport;

/**
 * Which services place search uses, from the server's settings: for the
 * dropdown BETTERCAL_PLACE_SEARCH, for the single-pin lookup
 * BETTERCAL_PLACE_LOOKUP (empty: the dropdown's), each an ordered list such
 * as "stadia,locationiq", tried in turn, with Photon always last. Photon is
 * never left out: it needs no key, so it is always there, and it's the only
 * service that finds an address from a bare house number. A listed service
 * without its key, or a name we don't know, is skipped, and describe() says
 * so for Settings.
 */
final class PlaceProviders
{
    public const NAMES = ['photon' => 'Photon', 'locationiq' => 'LocationIQ', 'stadia' => 'Stadia Maps'];
    private const KEYS = ['locationiq' => 'BETTERCAL_LOCATIONIQ_KEY', 'stadia' => 'BETTERCAL_STADIA_KEY'];

    /**
     * Shown wherever Better-Cal mentions Stadia (Settings, .env.example, the
     * install docs). General on purpose: plan names and what each includes
     * change, the requirement does not.
     */
    public const STADIA_NOTE = 'Better-Cal saves the coordinates of places you pick. Stadia Maps\' terms allow keeping them only on a paid plan that includes storing results, so check Stadia\'s current plans. Making sure your plan fits is your responsibility; Better-Cal does not check.';
    public const STADIA_TERMS_URL = 'https://stadiamaps.com/terms-of-service/';

    /**
     * The dropdown's provider: the listed services in order, each in front of
     * the rest, Photon last.
     *
     * @param array{provider?:string,locationiq_key?:string,stadia_key?:string} $places
     */
    public static function build(array $places, GeocoderTransport&KeyedGeocoderTransport $transport): PlaceProvider
    {
        $chain = new PhotonPlaces($transport);
        foreach (array_reverse(self::chain($places, 'provider')['active']) as $service) {
            $key = (string) $places[$service . '_key'];
            $chain = new FallbackPlaces($service === 'stadia' ? new StadiaPlaces($transport, $key) : new LocationIqPlaces($transport, $key), $chain);
        }
        return $chain;
    }

    /**
     * The keyed services the single-pin lookup (Geocode) asks before Photon,
     * in order, or null for Photon alone.
     *
     * @param array{provider?:string,lookup?:string,locationiq_key?:string,stadia_key?:string} $places
     */
    public static function pin(array $places, KeyedGeocoderTransport $transport): ?PinProvider
    {
        $pins = array_map(
            static fn(string $service): PinProvider => new KeyedPins($transport, $service, (string) $places[$service . '_key']),
            self::chain($places, 'lookup')['active']
        );
        return match (count($pins)) {
            0 => null,
            1 => $pins[0],
            default => new PinChain($pins),
        };
    }

    /**
     * For Settings (GET /config): what was asked for, what is in use, and
     * anything to say about it, for the dropdown and, under `lookup`, for
     * the single-pin lookup. Never a key. `requested` and `active` are the
     * services as comma lists; `active` is "photon" when nothing keyed is in
     * use, and otherwise lists only the keyed services, Photon following.
     *
     * @param array{provider?:string,lookup?:string,locationiq_key?:string,stadia_key?:string} $places
     * @return array{requested:string,active:string,name:string,fallback:?string,problem:?string,note:?string,termsUrl:?string,lookup:array{requested:string,active:string,name:string,problem:?string}}
     */
    public static function describe(array $places): array
    {
        $search = self::chain($places, 'provider');
        $lookup = self::chain($places, 'lookup');
        $stadia = in_array('stadia', $search['requested'], true) || in_array('stadia', $lookup['requested'], true);
        $summary = static fn(array $c): array => [
            'requested' => $c['requested'] === [] ? 'photon' : implode(',', $c['requested']),
            'active' => $c['active'] === [] ? 'photon' : implode(',', $c['active']),
            'name' => $c['active'] === [] ? 'Photon' : implode(', then ', array_map(static fn(string $s): string => self::NAMES[$s], $c['active'])),
            'problem' => $c['problems'] === [] ? null : implode(' ', $c['problems']),
        ];
        $s = $summary($search);
        return $s + [
            'fallback' => $search['active'] === [] ? null : 'Photon',
            'note' => $stadia ? self::STADIA_NOTE : null,
            'termsUrl' => $stadia ? self::STADIA_TERMS_URL : null,
            'lookup' => $summary($lookup),
        ];
    }

    /**
     * One job's list: what was asked for, the keyed services in use in that
     * order, and why any were skipped. Pure.
     *
     * @param array<string,mixed> $places
     * @param 'provider'|'lookup' $job
     * @return array{requested:list<string>,active:list<string>,problems:list<string>}
     */
    public static function chain(array $places, string $job): array
    {
        $raw = trim((string) ($places[$job] ?? ''));
        if ($raw === '' && $job === 'lookup') {
            $raw = trim((string) ($places['provider'] ?? ''));
        }
        $setting = $job === 'lookup' && trim((string) ($places['lookup'] ?? '')) !== '' ? 'BETTERCAL_PLACE_LOOKUP' : 'BETTERCAL_PLACE_SEARCH';
        $requested = array_values(array_unique(array_filter(array_map(
            static fn(string $s): string => strtolower(trim($s)),
            explode(',', $raw)
        ), static fn(string $s): bool => $s !== '')));
        $active = [];
        $problems = [];
        foreach ($requested as $service) {
            if ($service === 'photon') {
                continue; // always last, whether listed or not
            }
            if (!isset(self::NAMES[$service])) {
                $problems[] = $setting . ' names "' . $service . '", which Better-Cal does not know, so it is skipped. Services can be photon, locationiq or stadia.';
            } elseif (trim((string) ($places[$service . '_key'] ?? '')) === '') {
                $problems[] = self::NAMES[$service] . ' is chosen, but ' . self::KEYS[$service] . ' is empty, so it is skipped.';
            } else {
                $active[] = $service;
            }
        }
        return ['requested' => $requested, 'active' => $active, 'problems' => $problems];
    }
}
