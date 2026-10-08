<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Serialize Google move creation/execution and make remote calendar creation
 * recoverable after response loss. Restart-safe because MySQL commits each
 * ALTER independently.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $hasColumn = static function (string $column) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            return in_array($column, array_column($db->all('PRAGMA table_info(`calendar_moves`)'), 'name'), true);
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['calendar_moves', $column]
        ) > 0;
    };
    $hasCalendarColumn = static function (string $column) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            return in_array($column, array_column($db->all('PRAGMA table_info(`calendars`)'), 'name'), true);
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['calendars', $column]
        ) > 0;
    };
    $hasIndex = static function (string $index) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            return (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?", [$index]) > 0;
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['calendar_moves', $index]
        ) > 0;
    };
    $hasTable = static function (string $table) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            return (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) > 0;
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    };
    $hasConstraint = static function (string $constraint) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            return false;
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['calendar_moves', $constraint]
        ) > 0;
    };
    $hasTrigger = static function (string $trigger) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            return (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$trigger]) > 0;
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger]
        ) > 0;
    };

    $added = 0;
    $columns = [
        ['runner_token', 'VARCHAR(64) NULL', 'finished_at', 'TEXT NULL'],
        ['lease_expires_at', 'DATETIME NULL', 'runner_token', 'TEXT NULL'],
        ['remote_marker', 'CHAR(32) NULL', 'lease_expires_at', 'TEXT NULL'],
        ['remote_create_started_at', 'DATETIME NULL', 'remote_marker', 'TEXT NULL'],
        ['remote_reconciled_at', 'DATETIME NULL', 'remote_create_started_at', 'TEXT NULL'],
        ['current_event_id', 'BIGINT UNSIGNED NULL', 'remote_reconciled_at', 'INTEGER NULL'],
        ['current_event_marker', 'CHAR(64) NULL', 'current_event_id', 'TEXT NULL'],
    ];
    foreach ($columns as [$column, $type, $after, $sqliteType]) {
        if ($hasColumn($column)) {
            continue;
        }
        $db->run('ALTER TABLE `calendar_moves` ADD COLUMN `' . $column . '` ' . ($driver === 'sqlite' ? $sqliteType : $type . ' AFTER `' . $after . '`'));
        $added++;
    }
    if (!$hasCalendarColumn('google_binding_version')) {
        $db->run(
            'ALTER TABLE `calendars` ADD COLUMN `google_binding_version` '
            . ($driver === 'sqlite' ? 'INTEGER NOT NULL DEFAULT 0' : 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `google_sync_token`')
        );
        $added++;
    }

    // Rows created by the old executor have no durable provenance marker and
    // may already be inside an unknowable remote response-loss window. Never
    // resume them under the new algorithm or overlap an old in-flight PHP
    // request. Cancellation is authoritative to both versions; partial remote
    // data is left for explicit owner review.
    $upgradeStop = 'Stopped by the move-integrity upgrade; review any partial Google copy before starting a new move.';
    $now = gmdate('Y-m-d H:i:s');
    $db->run(
        "UPDATE calendar_moves
         SET status = 'failed', cancelled_at = ?, finished_at = ?, error = ?, runner_token = NULL, lease_expires_at = NULL
         WHERE status IN ('queued', 'running', 'failed') AND cancelled_at IS NULL AND remote_marker IS NULL",
        [$now, $now, $upgradeStop]
    );

    // New code creates a recovery marker in the same INSERT that publishes an
    // active move. Enforce that boundary in the database too: after the
    // required quiesced first rollout, stale or accidentally downgraded code
    // cannot create/reactivate work that the recovery algorithm cannot prove.
    if ($driver === 'sqlite') {
        if (!$hasTrigger('trg_calendar_moves_marker_insert')) {
            $db->run(
                "CREATE TRIGGER trg_calendar_moves_marker_insert
                 BEFORE INSERT ON calendar_moves
                 WHEN NEW.status IN ('queued', 'running') AND NEW.cancelled_at IS NULL AND NEW.remote_marker IS NULL
                 BEGIN SELECT RAISE(ABORT, 'active calendar move requires a recovery marker'); END"
            );
        }
        if (!$hasTrigger('trg_calendar_moves_marker_update')) {
            $db->run(
                "CREATE TRIGGER trg_calendar_moves_marker_update
                 BEFORE UPDATE ON calendar_moves
                 WHEN NEW.status IN ('queued', 'running') AND NEW.cancelled_at IS NULL AND NEW.remote_marker IS NULL
                 BEGIN SELECT RAISE(ABORT, 'active calendar move requires a recovery marker'); END"
            );
        }
    } elseif (!$hasConstraint('chk_calendar_moves_active_marker')) {
        $db->run(
            "ALTER TABLE `calendar_moves`
             ADD CONSTRAINT `chk_calendar_moves_active_marker`
             CHECK (`cancelled_at` IS NOT NULL OR `status` NOT IN ('queued', 'running') OR `remote_marker` IS NOT NULL)"
        );
    }

    // A canonical calendar-row lock prevents new duplicates. Refuse to add
    // the invariant over ambiguous historical state rather than silently
    // choosing which in-flight external operation should survive.
    $duplicates = (int) $db->scalar(
        "SELECT COUNT(*) FROM (
            SELECT calendar_id FROM calendar_moves
            WHERE status IN ('queued', 'running') AND cancelled_at IS NULL
            GROUP BY calendar_id HAVING COUNT(*) > 1
        ) active_duplicates"
    );
    if ($duplicates > 0) {
        throw new RuntimeException('calendar_moves has multiple active rows for a calendar; reconcile them before applying migration 042');
    }

    if ($driver === 'sqlite') {
        if (!$hasIndex('uq_calendar_moves_active')) {
            $db->run(
                "CREATE UNIQUE INDEX uq_calendar_moves_active ON calendar_moves (calendar_id)
                 WHERE status IN ('queued', 'running') AND cancelled_at IS NULL"
            );
        }
    } else {
        if (!$hasColumn('active_calendar_id')) {
            $db->run(
                'ALTER TABLE `calendar_moves` ADD COLUMN `active_calendar_id` BIGINT UNSIGNED NULL AFTER `current_event_marker`'
            );
            $added++;
        }
        // A STORED generated column would be attractive here, but adding one
        // rebuilds this table on MySQL 8.4. Existing foreign keys are then
        // re-created and some valid upgraded schemas are rejected with 1215.
        // A normal nullable key plus BEFORE triggers preserves the same
        // database-owned invariant without rebuilding the table.
        $activeExtra = strtolower((string) $db->scalar(
            "SELECT EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_moves' AND COLUMN_NAME = 'active_calendar_id'"
        ));
        $activeGenerated = str_contains($activeExtra, 'generated');
        if (!$activeGenerated) {
            $db->run(
                "UPDATE calendar_moves
                 SET active_calendar_id = CASE
                    WHEN status IN ('queued', 'running') AND cancelled_at IS NULL THEN calendar_id
                    ELSE NULL END"
            );
            if (!$hasTrigger('trg_calendar_moves_active_key_insert')) {
                $db->run(
                    "CREATE TRIGGER trg_calendar_moves_active_key_insert
                     BEFORE INSERT ON calendar_moves FOR EACH ROW
                     SET NEW.active_calendar_id = CASE
                        WHEN NEW.status IN ('queued', 'running') AND NEW.cancelled_at IS NULL THEN NEW.calendar_id
                        ELSE NULL END"
                );
            }
            if (!$hasTrigger('trg_calendar_moves_active_key_update')) {
                $db->run(
                    "CREATE TRIGGER trg_calendar_moves_active_key_update
                     BEFORE UPDATE ON calendar_moves FOR EACH ROW
                     SET NEW.active_calendar_id = CASE
                        WHEN NEW.status IN ('queued', 'running') AND NEW.cancelled_at IS NULL THEN NEW.calendar_id
                        ELSE NULL END"
                );
            }
        }
        if (!$hasIndex('uq_calendar_moves_active')) {
            $db->run('CREATE UNIQUE INDEX `uq_calendar_moves_active` ON `calendar_moves` (`active_calendar_id`)');
        }
    }

    // A mutation can be keyed to one calendar while its snapshot contains
    // trip members from another. Persist every referenced calendar so cutover
    // can permanently retire all pre-Google Undo snapshots, including after a
    // later adopt-back-to-local transition.
    if ($hasTable('mutations')) {
        if (!$hasTable('mutation_calendar_refs')) {
            if ($driver === 'sqlite') {
                $db->run(
                    'CREATE TABLE mutation_calendar_refs (
                        mutation_id INTEGER NOT NULL,
                        calendar_id INTEGER NOT NULL,
                        PRIMARY KEY (mutation_id, calendar_id),
                        FOREIGN KEY (mutation_id) REFERENCES mutations(id) ON DELETE CASCADE
                    )'
                );
            } else {
                $db->run(
                    'CREATE TABLE `mutation_calendar_refs` (
                        `mutation_id` BIGINT UNSIGNED NOT NULL,
                        `calendar_id` BIGINT UNSIGNED NOT NULL,
                        PRIMARY KEY (`mutation_id`, `calendar_id`),
                        KEY `idx_mutation_calendar_refs_calendar` (`calendar_id`, `mutation_id`),
                        CONSTRAINT `fk_mutation_calendar_refs_mutation`
                            FOREIGN KEY (`mutation_id`) REFERENCES `mutations` (`id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            }
            $added++;
        }
        if ($driver === 'sqlite' && (int) $db->scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'idx_mutation_calendar_refs_calendar'"
        ) === 0) {
            $db->run('CREATE INDEX idx_mutation_calendar_refs_calendar ON mutation_calendar_refs (calendar_id, mutation_id)');
        }
        foreach ($db->all('SELECT id, before_json, after_json FROM mutations WHERE before_json IS NOT NULL OR after_json IS NOT NULL') as $mutation) {
            $calendarIds = [];
            foreach (['before_json', 'after_json'] as $column) {
                $decoded = json_decode((string) ($mutation[$column] ?? ''), true);
                $tables = is_array($decoded) && is_array($decoded['tables'] ?? null) ? $decoded['tables'] : [];
                foreach ($tables['events'] ?? [] as $row) {
                    if (is_array($row) && isset($row['calendar_id'])) {
                        $calendarIds[] = (int) $row['calendar_id'];
                    }
                }
                foreach ($tables['calendars'] ?? [] as $row) {
                    if (is_array($row) && isset($row['id'])) {
                        $calendarIds[] = (int) $row['id'];
                    }
                }
            }
            foreach (array_values(array_unique(array_filter($calendarIds, static fn(int $id): bool => $id > 0))) as $calendarId) {
                $exists = $db->scalar(
                    'SELECT mutation_id FROM mutation_calendar_refs WHERE mutation_id = ? AND calendar_id = ?',
                    [(int) $mutation['id'], $calendarId]
                );
                if ($exists === null) {
                    $db->insert('mutation_calendar_refs', ['mutation_id' => (int) $mutation['id'], 'calendar_id' => $calendarId]);
                }
            }
        }
        if ($hasTable('calendars')) {
            foreach ($db->all(
                "SELECT DISTINCT r.mutation_id
                 FROM mutation_calendar_refs r
                 JOIN calendars c ON c.id = r.calendar_id
                 WHERE c.kind = 'subscribed' AND c.provider = 'google'"
            ) as $row) {
                $db->update('mutations', ['before_json' => null, 'after_json' => null], 'id = ?', [(int) $row['mutation_id']]);
            }
        }
    }

    // Repair any queued/running row left by the pre-042 commit-then-enqueue
    // window. A duplicate recovery job is harmless under the lease, but avoid
    // one when a pending/running job already names the move.
    if ($hasTable('jobs')) {
        $scheduled = [];
        foreach ($db->all("SELECT payload_json FROM jobs WHERE type = 'google_move' AND status IN ('pending', 'running')") as $job) {
            $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
            if (is_array($payload) && (int) ($payload['moveId'] ?? 0) > 0) {
                $scheduled[(int) $payload['moveId']] = true;
            }
        }
        foreach ($db->all("SELECT id FROM calendar_moves WHERE status IN ('queued', 'running') AND cancelled_at IS NULL") as $move) {
            $moveId = (int) $move['id'];
            if (!isset($scheduled[$moveId])) {
                $db->insert('jobs', [
                    'type' => 'google_move',
                    'payload_json' => json_encode(['moveId' => $moveId]),
                    'status' => 'pending',
                ]);
            }
        }
    }

    return 'Google move ownership and reconciliation fields ensured (' . $added . ' added)';
};
