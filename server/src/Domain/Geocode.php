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

    /** Cache key: sha256 over the lowercased normalized query. */
    public static function queryHash(string $q): string
    {
        return hash('sha256', mb_strtolower(self::normalize($q)));
    }

    /**
     * Map a decoded photon response to a result, or null when the provider
     * found nothing usable. Photon is GeoJSON: coordinates are [lng, lat].
     *
     * @return array{lat: float, lng: float, display: string}|null
     */
    public static function mapResponse(mixed $decoded): ?array
    {
        if (!is_array($decoded) || !isset($decoded['features'][0]) || !is_array($decoded['features'][0])) {
            return null;
        }
        $feature = $decoded['features'][0];
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
            return ['lat' => null, 'lng' => null, 'display' => null];
        }
        return [
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
            'display' => isset($row['display']) ? (string) $row['display'] : null,
        ];
    }

    // ---- Lookup --------------------------------------------------------

    /** @return array{lat: float|null, lng: float|null, display: string|null} */
    public function lookup(string $q): array
    {
        $normalized = self::normalize($q);
        if ($normalized === '') {
            throw HttpError::badRequest('q is required');
        }
        $hash = self::queryHash($normalized);

        $row = $this->db->one('SELECT lat, lng, display FROM geocode_cache WHERE query_hash = ?', [$hash]);
        if ($row !== null) {
            return self::resultFromRow($row);
        }

        $body = $this->fetch($normalized);
        if ($body === null) {
            // Transport failure: answer not-found, cache nothing.
            return ['lat' => null, 'lng' => null, 'display' => null];
        }

        $mapped = self::mapResponse(json_decode($body, true));
        $this->db->run(
            'INSERT INTO geocode_cache (query_hash, query, lat, lng, display) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE query_hash = query_hash',
            [
                $hash,
                $normalized,
                $mapped['lat'] ?? null,
                $mapped['lng'] ?? null,
                isset($mapped['display']) ? mb_substr($mapped['display'], 0, 500) : null,
            ]
        );

        return $mapped ?? ['lat' => null, 'lng' => null, 'display' => null];
    }

    /** Raw provider fetch; null on any transport-level failure. */
    private function fetch(string $q): ?string
    {
        $url = self::ENDPOINT . '?' . http_build_query(['q' => $q, 'limit' => 1]);
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
