<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Durable, atomic admission for externally visible email and discretionary
 * plugin work. The singleton lock serializes the count-and-insert boundary;
 * hashes retain no recipient or plugin-setting text.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $hasTable = static function (string $table) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            return (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) > 0;
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    };

    if (!$hasTable('external_action_lock')) {
        $db->run($driver === 'sqlite'
            ? 'CREATE TABLE external_action_lock (id INTEGER PRIMARY KEY)'
            : 'CREATE TABLE external_action_lock (id TINYINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    }
    // MySQL DDL commits independently. If a deploy was interrupted between
    // CREATE TABLE and the seed insert, rerunning the migration must repair
    // the singleton instead of leaving all external action admission closed.
    if ($db->scalar('SELECT id FROM external_action_lock WHERE id = 1') === null) {
        $db->insert('external_action_lock', ['id' => 1]);
    }
    if (!$hasTable('external_action_admissions')) {
        $db->run($driver === 'sqlite'
            ? 'CREATE TABLE external_action_admissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                kind TEXT NOT NULL,
                subject_key TEXT NOT NULL,
                cycle_key TEXT NULL,
                admitted_at TEXT NOT NULL
            )'
            : 'CREATE TABLE external_action_admissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                kind VARCHAR(32) NOT NULL,
                subject_key CHAR(64) NOT NULL,
                cycle_key CHAR(64) NULL,
                admitted_at DATETIME NOT NULL,
                KEY idx_external_action_account (user_id, kind, admitted_at),
                KEY idx_external_action_subject (user_id, kind, subject_key, admitted_at),
                KEY idx_external_action_cycle (user_id, kind, cycle_key, admitted_at),
                KEY idx_external_action_install (kind, admitted_at),
                CONSTRAINT fk_external_action_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB');
        if ($driver === 'sqlite') {
            $db->run('CREATE INDEX idx_external_action_account ON external_action_admissions (user_id, kind, admitted_at)');
            $db->run('CREATE INDEX idx_external_action_subject ON external_action_admissions (user_id, kind, subject_key, admitted_at)');
            $db->run('CREATE INDEX idx_external_action_cycle ON external_action_admissions (user_id, kind, cycle_key, admitted_at)');
            $db->run('CREATE INDEX idx_external_action_install ON external_action_admissions (kind, admitted_at)');
        }
    }

    // Pre-upgrade Run now requests had no stable hash and could have built a
    // duplicate pending backlog. Do not carry that discretionary work across
    // the new admission boundary. Scheduled work will be admitted again on a
    // later worker tick; an already-running job is allowed to finish.
    $cancelledJobs = $hasTable('jobs')
        ? $db->run(
            "UPDATE jobs SET status = 'failed', last_error = ?
             WHERE type = 'plugin_job' AND status = 'pending'",
            ['cancelled during plugin queue safety upgrade']
        )->rowCount()
        : 0;

    // Different-UID title/time matches were never confirmed by the owner.
    // Reopen only the risky external/local pairs; same-UID and owner-confirmed
    // links keep their established behavior.
    $external = "(a.source <> 'local' OR b.source <> 'local'
        OR ca.kind <> 'local' OR cb.kind <> 'local'
        OR COALESCE(ca.provider, 'ics') <> 'ics' OR COALESCE(cb.provider, 'ics') <> 'ics'
        OR a.created_via LIKE 'mail:%' OR b.created_via LIKE 'mail:%'
        OR a.created_via LIKE 'plugin:%' OR b.created_via LIKE 'plugin:%')";
    $changed = $driver === 'sqlite'
        ? $db->run(
            "UPDATE event_duplicates SET status = 'possible'
             WHERE status = 'linked' AND basis = 'title'
               AND EXISTS (
                 SELECT 1 FROM events a JOIN calendars ca ON ca.id = a.calendar_id
                 JOIN events b ON b.id = event_duplicates.event_b
                 JOIN calendars cb ON cb.id = b.calendar_id
                 WHERE a.id = event_duplicates.event_a AND $external
               )"
        )->rowCount()
        : $db->run(
            "UPDATE event_duplicates d
             JOIN events a ON a.id = d.event_a JOIN calendars ca ON ca.id = a.calendar_id
             JOIN events b ON b.id = d.event_b JOIN calendars cb ON cb.id = b.calendar_id
             SET d.status = 'possible'
             WHERE d.status = 'linked' AND d.basis = 'title' AND $external"
        )->rowCount();

    return "$changed external duplicate link" . ($changed === 1 ? '' : 's')
        . " reopened for review; $cancelledJobs pending plugin job"
        . ($cancelledJobs === 1 ? '' : 's') . ' cancelled';
};
