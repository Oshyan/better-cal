<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\GeocoderTransport;
use BetterCal\Infra\KeyedGeocoderTransport;

/**
 * Which place search the location dropdown uses, from the server's settings
 * (BETTERCAL_PLACE_SEARCH and the provider's key): Photon by default, no key
 * needed; LocationIQ or Stadia Maps with Photon behind them. A chosen
 * provider without its key, or a name we don't know, falls back to Photon,
 * and describe() says so for Settings.
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

    /** @param array{provider?:string,locationiq_key?:string,stadia_key?:string} $places */
    public static function build(array $places, GeocoderTransport&KeyedGeocoderTransport $transport): PlaceProvider
    {
        $photon = new PhotonPlaces($transport);
        return match (self::active($places)) {
            'locationiq' => new FallbackPlaces(new LocationIqPlaces($transport, (string) $places['locationiq_key']), $photon),
            'stadia' => new FallbackPlaces(new StadiaPlaces($transport, (string) $places['stadia_key']), $photon),
            default => $photon,
        };
    }

    /**
     * For Settings (GET /config): what was asked for, what is in use, and
     * anything to say about it. Never the key itself.
     *
     * @param array{provider?:string,locationiq_key?:string,stadia_key?:string} $places
     * @return array{requested:string,active:string,name:string,fallback:?string,problem:?string,note:?string,termsUrl:?string}
     */
    public static function describe(array $places): array
    {
        $requested = self::requested($places);
        $active = self::active($places);
        $problem = null;
        if (!isset(self::NAMES[$requested])) {
            $problem = 'BETTERCAL_PLACE_SEARCH is set to something Better-Cal does not know, so Photon is used. It can be photon, locationiq or stadia.';
        } elseif ($requested !== $active) {
            $problem = self::NAMES[$requested] . ' is chosen, but ' . self::KEYS[$requested] . ' is empty, so Photon is used.';
        }
        $stadia = $requested === 'stadia';
        return [
            'requested' => $requested,
            'active' => $active,
            'name' => self::NAMES[$active],
            'fallback' => $active === 'photon' ? null : 'Photon',
            'problem' => $problem,
            'note' => $stadia ? self::STADIA_NOTE : null,
            'termsUrl' => $stadia ? self::STADIA_TERMS_URL : null,
        ];
    }

    /** @param array<string,mixed> $places */
    private static function requested(array $places): string
    {
        $name = strtolower(trim((string) ($places['provider'] ?? '')));
        return $name === '' ? 'photon' : $name;
    }

    /** @param array<string,mixed> $places */
    private static function active(array $places): string
    {
        $requested = self::requested($places);
        return match ($requested) {
            'locationiq' => trim((string) ($places['locationiq_key'] ?? '')) !== '' ? 'locationiq' : 'photon',
            'stadia' => trim((string) ($places['stadia_key'] ?? '')) !== '' ? 'stadia' : 'photon',
            default => 'photon',
        };
    }
}
