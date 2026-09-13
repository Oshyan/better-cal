<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Infra\JobQueue;
use BetterCal\Support\Time;

/** ICS feed subscription: fetch, parse, and sync by (calendar_id, uid). */
final class Feeds
{
    // Timeouts now come from HttpClient (5s connect / 20s total).
    private const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly Db $db, private readonly ?JobQueue $queue = null)
    {
    }

    /** Force-poll a subscribed calendar now. Returns imported (upserted) count. */
    public function poll(int $calendarId): int
    {
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]);
        if ($calendar === null || $calendar['kind'] !== 'subscribed' || empty($calendar['source_url'])) {
            throw HttpError::badRequest('Calendar is not a feed subscription');
        }
        try {
            $ics = $this->fetch((string) $calendar['source_url']);
            $parsed = Ics::parse($ics);
            $count = $this->sync($calendar, $parsed);
            $this->db->update('calendars', [
                'last_polled_at' => Time::nowDb(),
                'last_poll_status' => 'ok',
                'last_poll_error' => null,
            ], 'id = ?', [$calendarId]);
            $this->recordStats($calendarId, count($parsed));
            (new SystemHealth($this->db))->recordOk('feed:' . $calendarId, 'feed', (int) $calendar['user_id'], 'Feed: ' . (string) $calendar['name']);
            // New/updated feed events need background prompt-filter evaluation
            // and rank scoring (mirrors the ChangeLog hook: fired per poll).
            if ($count > 0 && $this->queue !== null) {
                if (!$this->queue->hasPending('geocode_sweep')) {
                    $this->queue->enqueue('geocode_sweep', []);
                }
                if (!$this->queue->hasPending('filter_eval')) {
                    $this->queue->enqueue('filter_eval', []);
                }
                if (!$this->queue->hasPending('rank_events')) {
                    $this->queue->enqueue('rank_events', []);
                }
            }
            return $count;
        } catch (\Throwable $e) {
            $this->db->update('calendars', [
                'last_polled_at' => Time::nowDb(),
                'last_poll_status' => 'error',
                'last_poll_error' => mb_substr($e->getMessage(), 0, 2000),
            ], 'id = ?', [$calendarId]);
            // The badge says "error" while you look; this gives it a since-when,
            // a history, and eventually an email if it stays that way.
            (new SystemHealth($this->db))->recordFailure('feed:' . $calendarId, 'feed', (int) $calendar['user_id'], 'Feed: ' . (string) $calendar['name'], $e->getMessage());
            throw $e;
        }
    }

    public function fetch(string $url): string
    {
        if (str_starts_with($url, 'webcal://')) {
            $url = 'https://' . substr($url, strlen('webcal://'));
        }
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Feed URL must be http(s) or webcal');
        }
        // GH #21: this used to be raw cURL with CURLOPT_FOLLOWLOCATION and no
        // address check, so a subscription URL — which the user supplies and an
        // upstream server can redirect at will — could be walked to 127.0.0.1
        // or the cloud metadata endpoint. HttpClient resolves before connecting,
        // refuses private/loopback/link-local/metadata addresses, pins cURL to
        // the vetted IP against DNS rebind, and re-vets every redirect hop.
        // Feed-shaped limits, not plugin-shaped ones: an ICS subscription is
        // legitimately large, and redirect chains through calendar providers are
        // common enough to want more than the default three.
        $http = new HttpClient(
            requestBudget: 8,
            userAgent: 'Better-Cal/0.1 (+ics-subscriber)',
            maxBytes: self::MAX_BYTES,
            maxRedirects: 5,
        );
        try {
            $r = $http->get($url);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Feed fetch failed: ' . $e->getMessage(), 0, $e);
        }
        $body = $r['body'];
        $status = $r['status'];
        if ($status >= 400) {
            throw new \RuntimeException("Feed fetch failed: HTTP $status");
        }
        if ($body === '') {
            throw new \RuntimeException('Feed fetch failed: empty response');
        }
        if (!str_contains($body, 'BEGIN:VCALENDAR')) {
            throw new \RuntimeException('Response is not an ICS calendar');
        }
        return $body;
    }

    /**
     * Sync parsed VEVENTs into the calendar: update changed, insert new,
     * delete events whose uid disappeared from the feed.
     *
     * @param list<array<string,mixed>> $parsed
     */
    public function sync(array $calendar, array $parsed): int
    {
        $calendarId = (int) $calendar['id'];
        $userId = (int) $calendar['user_id'];

        $existing = $this->db->all('SELECT * FROM events WHERE calendar_id = ?', [$calendarId]);
        $existingByKey = [];
        foreach ($existing as $row) {
            $existingByKey[$row['uid'] . '|' . ($row['recurrence_instance_utc'] ?? '')] = $row;
        }

        // Masters first so overrides can resolve recurrence_parent_id.
        usort($parsed, static fn(array $a, array $b): int => ($a['recurrence_instance_utc'] === null ? 0 : 1) <=> ($b['recurrence_instance_utc'] === null ? 0 : 1));

        $masterIdByUid = [];
        foreach ($existing as $row) {
            if ($row['recurrence_instance_utc'] === null) {
                $masterIdByUid[(string) $row['uid']] = (int) $row['id'];
            }
        }

        $seen = [];
        $upserts = 0;
        $changedUids = [];
        $newMasterUids = [];
        $updatedEventIds = [];
        $addedTitles = [];
        $removedCount = 0;
        $this->db->tx(function () use ($parsed, $existingByKey, &$masterIdByUid, &$seen, &$upserts, &$changedUids, &$newMasterUids, &$updatedEventIds, &$addedTitles, &$removedCount, $calendarId, $userId): void {
            foreach ($parsed as $ev) {
                $key = $ev['uid'] . '|' . ($ev['recurrence_instance_utc'] ?? '');
                if (isset($seen[$key])) {
                    continue; // duplicate VEVENT in feed
                }
                $seen[$key] = true;

                // reminders_json is deliberately not synced: reminders on feed
                // events are user-local (like tags), and feed VALARMs must not
                // overwrite them on every poll.
                $columns = [
                    'title' => $ev['title'],
                    'description' => $ev['description'],
                    'location' => $ev['location'],
                    'url' => $ev['url'],
                    'start_utc' => $ev['start_utc'],
                    'end_utc' => $ev['end_utc'],
                    'all_day' => $ev['all_day'],
                    'tzid' => $ev['tzid'],
                    'rrule' => $ev['rrule'],
                    'exdates_json' => $ev['exdates'] === [] ? null : json_encode($ev['exdates']),
                    'status' => $ev['status'],
                    'recurrence_instance_utc' => $ev['recurrence_instance_utc'],
                    'recurrence_parent_id' => $ev['recurrence_instance_utc'] !== null
                        ? ($masterIdByUid[(string) $ev['uid']] ?? null)
                        : null,
                ];

                $current = $existingByKey[$key] ?? null;
                if ($current === null) {
                    $id = $this->db->insert('events', $columns + [
                        'user_id' => $userId,
                        'calendar_id' => $calendarId,
                        'uid' => $ev['uid'],
                        'source' => 'feed',
                        'created_via' => 'feed',
                    ]);
                    if ($ev['recurrence_instance_utc'] === null) {
                        $masterIdByUid[(string) $ev['uid']] = $id;
                        $newMasterUids[(string) $ev['uid']] = true;
                    }
                    $changedUids[(string) $ev['uid']] = true;
                    $addedTitles[] = (string) $ev['title'];
                    $upserts++;
                    continue;
                }

                $changed = [];
                foreach ($columns as $col => $value) {
                    $currentValue = $current[$col];
                    if ($currentValue !== null && in_array($col, ['all_day', 'recurrence_parent_id'], true)) {
                        $currentValue = (int) $currentValue;
                    }
                    if ($currentValue !== $value && (string) $currentValue !== (string) $value) {
                        $changed[$col] = $value;
                    }
                }
                if ($current['deleted_at'] !== null) {
                    $changed['deleted_at'] = null;
                }
                if ($changed !== []) {
                    // A new address means the old coordinates are wrong;
                    // the geocode sweep re-resolves within the minute.
                    if (array_key_exists('location', $changed)) {
                        $changed['location_lat'] = null;
                        $changed['location_lng'] = null;
                        $changed['geocoded_at'] = null;
                    }
                    $changed['updated_at'] = Time::nowDb();
                    $this->db->update('events', $changed, 'id = ?', [(int) $current['id']]);
                    $changedUids[(string) $current['uid']] = true;
                    $updatedEventIds[] = (int) $current['id'];
                    $upserts++;
                }
            }

            // Content changed: cached prompt-filter verdicts are stale; drop
            // them so the pending sweep re-evaluates (removed events cascade).
            if ($updatedEventIds !== []) {
                [$in, $inParams] = Db::in($updatedEventIds);
                $this->db->run("DELETE FROM filter_evals WHERE event_id IN $in", $inParams);
            }

            // Remove events that disappeared from the feed.
            foreach ($existingByKey as $key => $row) {
                if (!isset($seen[$key])) {
                    $this->db->run('DELETE FROM events WHERE id = ?', [(int) $row['id']]);
                    $changedUids[(string) $row['uid']] = true;
                    $removedCount++;
                }
            }
        });

        // CalDAV change journal: one entry per affected object (uid).
        foreach (array_keys($changedUids) as $uid) {
            $uid = (string) $uid;
            if (isset($newMasterUids[$uid])) {
                ChangeLog::record($this->db, $calendarId, $uid, ChangeLog::OP_ADD);
                continue;
            }
            $masterExists = $this->db->scalar(
                'SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND deleted_at IS NULL
                   AND recurrence_parent_id IS NULL AND recurrence_instance_utc IS NULL',
                [$calendarId, $uid]
            );
            ChangeLog::record($this->db, $calendarId, $uid, $masterExists !== null ? ChangeLog::OP_MODIFY : ChangeLog::OP_DELETE);
        }

        // Activity log: one roll-up entry per poll that changed anything
        // (log-only; the next poll would redo an undo, so none is offered).
        $addedCount = count($addedTitles);
        $updatedCount = count($updatedEventIds);
        if ($addedCount + $updatedCount + $removedCount > 0) {
            // The publisher touched something. This is what "stale" is judged
            // against: not whether the feed HAS events (a dead feed keeps
            // serving its old ones forever) but when it last changed any.
            $this->db->update('calendars', ['content_changed_at' => Time::nowDb()], 'id = ?', [$calendarId]);
            $parts = [];
            if ($addedCount > 0) {
                $parts[] = $addedCount . ' added';
            }
            if ($updatedCount > 0) {
                $parts[] = $updatedCount . ' updated';
            }
            if ($removedCount > 0) {
                $parts[] = $removedCount . ' removed';
            }
            ActivityContext::with('feed', function () use ($userId, $calendarId, $calendar, $parts, $addedTitles): void {
                (new Undo($this->db))->record(
                    $userId,
                    'calendar',
                    $calendarId,
                    'update',
                    null,
                    null,
                    "Feed '" . (string) $calendar['name'] . "': " . implode(', ', $parts),
                    ['addedTitles' => array_slice($addedTitles, 0, 20)]
                );
            });
        }

        return $upserts;
    }

    private function recordStats(int $calendarId, int $rawCount): void
    {
        $this->db->run(
            'INSERT INTO feed_stats (calendar_id, poll_date, raw_count, passing_count) VALUES (?, CURDATE(), ?, ?) AS new_row
             ON DUPLICATE KEY UPDATE raw_count = new_row.raw_count, passing_count = new_row.passing_count',
            [$calendarId, $rawCount, $rawCount]
        );
    }
}
