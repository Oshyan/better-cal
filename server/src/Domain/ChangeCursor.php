<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;

/**
 * GET /changes/cursor (docs/api-contract.md): three opaque parts an open tab
 * polls every 30 seconds while visible, refetching only what the part that
 * moved covers. Each is a handful of small indexed aggregates, so an idle
 * tab stays cheap; nothing here reads event rows.
 *
 *   cursor  events, people and availability, and any API write (change_seq)
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
        return $cal['s'] . ':' . $cal['c'] . ':' . $av['c'] . ':' . $av['m'] . ':' . $people . ':' . $seq;
    }

    /**
     * Mail held for a decision and the limit notices, plugin proposals,
     * possible duplicates. Background work adds these without touching an
     * event, so they get their own part; unanswered invitations ride on the
     * events part.
     */
    public function review(int $userId): string
    {
        $parts = [];
        foreach ([
            "SELECT COUNT(CASE WHEN status = 'open' THEN 1 END) AS c, COALESCE(MAX(id), 0) AS m FROM review_items WHERE user_id = ?",
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
        $update = $this->db->one('SELECT latest_version, minimum_secure_version FROM app_update_state WHERE id = 1') ?? [];
        $dismissed = $this->db->scalar('SELECT dismissed_version FROM user_update_notices WHERE user_id = ?', [$userId]);
        return substr(hash('sha256', implode('|', [
            $health['n'] . ':' . $health['f'] . ':' . $health['since'],
            $feeds,
            $google,
            $plugins,
            $ranges['n'] . ':' . $ranges['m'] . ':' . $warnings['n'] . ':' . $warnings['m'],
            (string) ($update['latest_version'] ?? '') . ':' . (string) ($update['minimum_secure_version'] ?? '') . ':' . (string) ($dismissed ?? ''),
        ])), 0, 16);
    }
}
