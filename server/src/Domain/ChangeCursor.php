<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/**
 * GET /changes/cursor (docs/api-contract.md): three opaque parts an open tab
 * polls every 30 seconds while visible, refetching only what the part that
 * moved covers. Each is a handful of small indexed aggregates, so an idle
 * tab stays cheap; nothing here reads event rows.
 *
 *   cursor  events, people and availability, any API write (change_seq),
 *           and the events still marked new, so the window the cursor
 *           names is the window the server would send now (#16)
 *   review  what waits in Review apart from events
 *   status  health and background state: failing jobs and devices, feed and
 *           Google account status, plugins, and update notices
 */
final class ChangeCursor
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array{cursor:string,review:string,status:string} */
    public function all(int $userId): array
    {
        return ['cursor' => $this->events($userId), 'review' => $this->review($userId), 'status' => $this->status($userId)];
    }

    public function events(int $userId): string
    {
        $cal = $this->db->one('SELECT COALESCE(SUM(synctoken), 0) AS s, COUNT(*) AS c FROM calendars WHERE user_id = ?', [$userId]);
        $av = $this->db->one('SELECT COUNT(*) AS c, COALESCE(MAX(a.id), 0) AS m FROM availability a JOIN people p ON p.id = a.person_id WHERE p.user_id = ?', [$userId]);
        $people = $this->db->scalar('SELECT COUNT(*) FROM people WHERE user_id = ?', [$userId]);
        $seq = $this->db->scalar('SELECT change_seq FROM users WHERE id = ?', [$userId]);
        // The one part of the window that depends on the clock: an event's
        // "new" mark ends NEW_WINDOW_HOURS after it arrived (Events::isNewFor)
        // with nothing written, so the count of events still inside that
        // window is read here. It falls when a mark ends, so a tab refetches
        // then, and the window's ETag (windowTag) changes with it. Reads the
        // (user_id, created_at) index of migration 066.
        $since = Time::toDb(Time::nowUtc()->sub(new \DateInterval('PT' . Events::NEW_WINDOW_HOURS . 'H')));
        $new = $this->db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ? AND created_at > ?', [$userId, $since]);
        return $cal['s'] . ':' . $cal['c'] . ':' . $av['c'] . ':' . $av['m'] . ':' . $people . ':' . $seq . ':' . $new;
    }

    /**
     * The events window's ETag (GET /events, #16): a name for the account's
     * window as it would be sent now. It changes exactly when the `cursor`
     * part does, and with the code version, since a release can change what
     * a window carries (a new field, a migration moving series keys) with
     * no row of the account's written. The account's id is folded in so two
     * accounts' identical cursors never name the same entity in a shared
     * browser's cache. Weak: the entity is the same calendar data however
     * it is encoded or compressed on the way.
     */
    public function windowTag(int $userId, string $version): string
    {
        return self::windowTagFor($userId, $this->events($userId), $version);
    }

    /** windowTag's pure form, for the checks. */
    public static function windowTagFor(int $userId, string $cursor, string $version): string
    {
        return 'W/"' . substr(hash('sha256', $userId . '|' . $cursor . '|' . $version), 0, 32) . '"';
    }

    /**
     * Mail held for a decision and the limit notices, plugin proposals,
     * possible duplicates. Background work adds these without touching an
     * event, so they get their own part; unanswered invitations ride on the
     * events part.
     */
    public function review(int $userId): string
    {
        // Review items count their open invitations apart: a day held before
        // its series becomes a change to an occurrence as the queue is read
        // (ReviewQueue::convertHeldDays), with the open count and the newest
        // id as they were, and a tab still showing it waiting has to refetch.
        $items = $this->db->one(
            "SELECT COUNT(CASE WHEN status = 'open' THEN 1 END) AS c, COALESCE(MAX(id), 0) AS m, COUNT(CASE WHEN status = 'open' AND kind = ? THEN 1 END) AS w FROM review_items WHERE user_id = ?",
            [ReviewQueue::KIND_INVITE_NEW, $userId]
        );
        $parts = [$items['c'] . '.' . $items['m'] . '.' . $items['w']];
        foreach ([
            "SELECT COUNT(CASE WHEN status = 'open' THEN 1 END) AS c, COALESCE(MAX(id), 0) AS m FROM plugin_proposals WHERE user_id = ?",
            "SELECT COUNT(CASE WHEN status = 'possible' THEN 1 END) AS c, COALESCE(MAX(id), 0) AS m FROM event_duplicates WHERE user_id = ?",
        ] as $sql) {
            $r = $this->db->one($sql, [$userId]);
            $parts[] = $r['c'] . '.' . $r['m'];
        }
        return implode(':', $parts);
    }

    /**
     * Background state the sidebar, banners and plugin layers show. Only
     * transitions move it (a check that stays ok writes nothing here), so a
     * worker running every minute doesn't make tabs refetch every minute.
     */
    public function status(int $userId): string
    {
        $health = $this->db->one(
            "SELECT COUNT(*) AS n, COUNT(CASE WHEN status = 'failing' THEN 1 END) AS f, COALESCE(MAX(first_failed_at), '') AS since
             FROM system_health WHERE user_id = ? OR user_id IS NULL",
            [$userId]
        );
        $feeds = '';
        foreach ($this->db->all('SELECT id, last_poll_status, last_poll_error FROM calendars WHERE user_id = ? ORDER BY id', [$userId]) as $c) {
            $feeds .= $c['id'] . '=' . $c['last_poll_status'] . ':' . (string) ($c['last_poll_error'] ?? '') . ';';
        }
        $google = '';
        foreach ($this->db->all('SELECT id, status, reauth_required_at FROM google_accounts WHERE user_id = ? ORDER BY id', [$userId]) as $a) {
            $google .= $a['id'] . '=' . $a['status'] . ':' . (string) ($a['reauth_required_at'] ?? '') . ';';
        }
        $plugins = '';
        foreach ($this->db->all('SELECT id, enabled, consecutive_failures FROM plugins ORDER BY id') as $p) {
            $plugins .= $p['id'] . '=' . $p['enabled'] . ':' . ((int) $p['consecutive_failures'] > 0 ? 'f' : 'ok') . ';';
        }
        $ranges = $this->db->one('SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS m FROM plugin_ranges');
        $warnings = $this->db->one('SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS m FROM plugin_warnings');
        // newest_version too: the notice's wording changes when a newer release
        // appears behind the one this copy can verify, with latest_version as it was.
        $update = $this->db->one('SELECT latest_version, newest_version, minimum_secure_version FROM app_update_state WHERE id = 1') ?? [];
        $dismissed = $this->db->scalar('SELECT dismissed_version FROM user_update_notices WHERE user_id = ?', [$userId]);
        return substr(hash('sha256', implode('|', [
            $health['n'] . ':' . $health['f'] . ':' . $health['since'],
            $feeds,
            $google,
            $plugins,
            $ranges['n'] . ':' . $ranges['m'] . ':' . $warnings['n'] . ':' . $warnings['m'],
            (string) ($update['latest_version'] ?? '') . ':' . (string) ($update['newest_version'] ?? '') . ':' . (string) ($update['minimum_secure_version'] ?? '') . ':' . (string) ($dismissed ?? ''),
        ])), 0, 16);
    }
}
