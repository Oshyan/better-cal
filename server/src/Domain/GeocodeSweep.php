<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/**
 * Resolve coordinates for events that have a location and none yet, in the
 * background, upcoming first.
 *
 * Why a sweep rather than a hook on create: coordinates are derived data
 * that arrive from a rate-limited third party, so they belong off the
 * request path entirely. Events get a location from the editor, quick add,
 * imports, mail ingest and feed polls; one job that looks for "location but
 * no coordinates" covers every path, including ones that do not exist yet,
 * and the worker runs it every minute so nothing waits longer than that.
 *
 * Provider manners: the public Photon instance asks for fair use, so at most
 * one network lookup per second, at most a few dozen per run, and identical
 * addresses are looked up once per user and written to every event sharing
 * them (a tide table has one harbour on hundreds of rows). Cached answers
 * cost nothing and are not throttled. A transport failure stops the run
 * rather than stamping a month of "could not place" onto rows that were
 * never actually tried.
 */
final class GeocodeSweep
{
    /** An address the provider could not place is retried after this long. */
    public const RETRY_AFTER = 'P30D';
    /** Pause between NETWORK lookups, microseconds. */
    private const THROTTLE_US = 1_100_000;
    /** Rows considered per run; grouping by address shrinks it further. */
    private const SCAN_LIMIT = 400;

    public function __construct(
        private readonly Db $db,
        private readonly Geocode $geocode,
        private readonly Settings $settings,
    ) {
    }

    /** Grouping key for "the same address": whitespace-collapsed, case-folded. */
    public static function normalizeLocation(string $location): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $location)));
    }

    /**
     * Bias precedence, identical to the detail view and the place picker:
     * the user's home location, else the centroid of the event's zone.
     *
     * @param array<string,mixed> $settings the user's merged settings
     * @return array{0:?float,1:?float}
     */
    public static function biasFor(array $settings, ?string $tzid): array
    {
        if (isset($settings['homeLat'], $settings['homeLng'])) {
            return [(float) $settings['homeLat'], (float) $settings['homeLng']];
        }
        $centroid = PlaceSearch::tzCentroid($tzid ?: (is_string($settings['tz'] ?? null) ? $settings['tz'] : null));
        return $centroid !== null ? [(float) $centroid[0], (float) $centroid[1]] : [null, null];
    }

    /** Cheap gate so the worker can skip enqueueing when there is nothing to do. */
    public function hasCandidates(): bool
    {
        return $this->db->scalar(
            "SELECT 1 FROM events WHERE deleted_at IS NULL AND location_lat IS NULL
               AND location IS NOT NULL AND location <> ''
               AND (geocoded_at IS NULL OR geocoded_at < ?) LIMIT 1",
            [$this->retryCutoff()]
        ) !== null;
    }

    /**
     * One run. Upcoming events first (soonest first), then the past (most
     * recent first), so the map is ready for anything you are about to open.
     *
     * @return array{groups:int,lookups:int,resolved:int,unplaced:int,transient:bool,seconds:float}
     */
    public function run(int $maxLookups = 30, int $budgetSeconds = 25): array
    {
        $t0 = microtime(true);
        $stats = ['groups' => 0, 'lookups' => 0, 'resolved' => 0, 'unplaced' => 0, 'transient' => false, 'seconds' => 0.0];

        $rows = $this->db->all(
            "SELECT id, user_id, location, tzid FROM events
             WHERE deleted_at IS NULL AND location_lat IS NULL
               AND location IS NOT NULL AND location <> ''
               AND (geocoded_at IS NULL OR geocoded_at < ?)
             ORDER BY (start_utc >= UTC_TIMESTAMP()) DESC,
                      CASE WHEN start_utc >= UTC_TIMESTAMP() THEN start_utc END ASC,
                      start_utc DESC
             LIMIT " . self::SCAN_LIMIT,
            [$this->retryCutoff()]
        );

        // Group by user + address, preserving the priority order of first
        // appearance. Bias is per user, so the same street name for two users
        // is two lookups by design.
        $groups = [];
        foreach ($rows as $r) {
            $key = (int) $r['user_id'] . "\0" . self::normalizeLocation((string) $r['location']);
            $groups[$key] ??= ['userId' => (int) $r['user_id'], 'location' => trim((string) $r['location']), 'tzid' => $r['tzid'], 'ids' => []];
            $groups[$key]['ids'][] = (int) $r['id'];
        }

        $settingsByUser = [];
        foreach ($groups as $g) {
            if (microtime(true) - $t0 > $budgetSeconds) {
                break;
            }
            $settingsByUser[$g['userId']] ??= $this->settings->forUser($g['userId']);
            [$biasLat, $biasLng] = self::biasFor($settingsByUser[$g['userId']], is_string($g['tzid']) ? $g['tzid'] : null);

            $cached = $this->geocode->cached($g['location'], $biasLat, $biasLng);
            if (!$cached && $stats['lookups'] >= $maxLookups) {
                break;
            }
            $stats['groups']++;
            try {
                $res = $this->geocode->lookup($g['location'], $biasLat, $biasLng);
            } catch (\Throwable) {
                $res = ['lat' => null, 'lng' => null];
            }
            if (!$cached) {
                $stats['lookups']++;
                // Not cached before and still not cached after: the provider
                // was not reached. Leave these rows untouched and stop.
                if (!$this->geocode->cached($g['location'], $biasLat, $biasLng)) {
                    $stats['transient'] = true;
                    break;
                }
            }

            [$in, $params] = Db::in($g['ids']);
            if (isset($res['lat'], $res['lng'])) {
                $this->db->run(
                    "UPDATE events SET location_lat = ?, location_lng = ?, geocoded_at = ?
                     WHERE id IN $in AND location_lat IS NULL",
                    [(float) $res['lat'], (float) $res['lng'], Time::nowDb(), ...$params]
                );
                $stats['resolved'] += count($g['ids']);
            } else {
                $this->db->run("UPDATE events SET geocoded_at = ? WHERE id IN $in", [Time::nowDb(), ...$params]);
                $stats['unplaced'] += count($g['ids']);
            }

            if (!$cached) {
                usleep(self::THROTTLE_US);
            }
        }

        $stats['seconds'] = round(microtime(true) - $t0, 2);
        return $stats;
    }

    private function retryCutoff(): string
    {
        return Time::nowUtc()->sub(new \DateInterval(self::RETRY_AFTER))->format('Y-m-d H:i:s');
    }
}
