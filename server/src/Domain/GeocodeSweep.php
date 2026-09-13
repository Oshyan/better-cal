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

    /**
     * Grouping key for "the same address": whitespace-collapsed, case-folded,
     * typographic quotes folded to ASCII. Two feeds wrote "Oakland's" and
     * "Oakland’s" for one venue and it cost two lookups.
     */
    public static function normalizeLocation(string $location): string
    {
        $s = str_replace(['’', '‘', '“', '”'], ["'", "'", '"', '"'], $location);
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
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

    /**
     * A "location" that is really a link (Luma and Partiful put the event
     * page there, video calls put the meeting URL there). Not an address; not
     * worth a lookup, not worth an Activity line saying it could not be
     * placed. Pure.
     */
    public static function isUrlLocation(string $location): bool
    {
        $s = trim($location);
        return $s !== '' && preg_match('~^(https?://|www\.)\S+$~i', $s) === 1;
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
        $gaveUp = []; // userId => [firstEventId, [address, ...]]
        $transients = 0;
        foreach ($groups as $g) {
            if (microtime(true) - $t0 > $budgetSeconds) {
                break;
            }
            if (self::isUrlLocation($g['location'])) {
                [$in, $params] = Db::in($g['ids']);
                $this->db->run("UPDATE events SET geocoded_at = ? WHERE id IN $in", [Time::nowDb(), ...$params]);
                $stats['skippedUrls'] = ($stats['skippedUrls'] ?? 0) + count($g['ids']);
                continue;
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
                // did not answer for THIS query. Either it is down, or this
                // one query makes it choke (which caches nothing and would
                // otherwise sit at the head of the queue blocking everyone
                // behind it on every run). Push these rows back a day and
                // move on; give up on the run only if it keeps happening.
                if (!$this->geocode->cached($g['location'], $biasLat, $biasLng)) {
                    $stats['transient'] = true;
                    $transients++;
                    [$in, $params] = Db::in($g['ids']);
                    $this->db->run("UPDATE events SET geocoded_at = ? WHERE id IN $in", [$this->deferredStamp(), ...$params]);
                    usleep(self::THROTTLE_US);
                    if ($transients >= self::MAX_TRANSIENTS) {
                        break;
                    }
                    continue;
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
                $gaveUp[$g['userId']] ??= [$g['ids'][0], []];
                $gaveUp[$g['userId']][1][] = $g['location'];
            }

            if (!$cached) {
                usleep(self::THROTTLE_US);
            }
        }

        // Say so where the user will see it. One log-only Activity entry per
        // user per run listing the addresses given up on; otherwise "could
        // not place" lives only in a column and a worker log line, and an
        // event quietly has no map for a month.
        foreach ($gaveUp as $userId => [$eventId, $addresses]) {
            $shown = array_slice($addresses, 0, 5);
            $more = count($addresses) - count($shown);
            $summary = "Couldn't place " . count($addresses) . ' address' . (count($addresses) === 1 ? '' : 'es')
                . ' on the map: ' . implode('; ', array_map(static fn(string $a): string => mb_substr($a, 0, 60), $shown))
                . ($more > 0 ? "; and $more more" : '');
            ActivityContext::with('geocode', function () use ($userId, $eventId, $summary, $addresses): void {
                (new Undo($this->db))->record((int) $userId, 'event', (int) $eventId, 'update', null, null, $summary, ['addresses' => array_slice($addresses, 0, 50)]);
            });
        }

        $stats['seconds'] = round(microtime(true) - $t0, 2);
        return $stats;
    }

    /** Provider did not answer this many times in one run: it is down, stop. */
    private const MAX_TRANSIENTS = 3;
    /** A query the provider did not answer is retried after this long, not RETRY_AFTER. */
    private const DEFER_TRANSIENT = 'P1D';

    private function retryCutoff(): string
    {
        return Time::nowUtc()->sub(new \DateInterval(self::RETRY_AFTER))->format('Y-m-d H:i:s');
    }

    /**
     * A geocoded_at value that makes a row eligible again after DEFER_TRANSIENT
     * rather than RETRY_AFTER: "now minus (retry-after minus defer)".
     */
    private function deferredStamp(): string
    {
        return Time::nowUtc()
            ->sub(new \DateInterval(self::RETRY_AFTER))
            ->add(new \DateInterval(self::DEFER_TRANSIENT))
            ->format('Y-m-d H:i:s');
    }
}
