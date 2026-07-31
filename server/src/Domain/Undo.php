<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

/**
 * Mutation log + undo. Each mutating API call records before/after snapshots
 * as {"tables": {table: [rows...]}}. Undo restores every before-row (upsert)
 * and deletes rows that exist only in after (i.e. rows the mutation created).
 */
final class Undo
{
    /** Restore order respects FK dependencies; deletions run in reverse. */
    private const TABLE_ORDER = [
        'folders', 'tags', 'people', 'calendars', 'calendar_folders', 'calendar_tags',
        'events', 'event_tags', 'event_people', 'out_feeds', 'filters', 'saved_views',
    ];

    private const TABLE_PK = [
        'folders' => ['id'], 'tags' => ['id'], 'people' => ['id'], 'calendars' => ['id'],
        'events' => ['id'], 'out_feeds' => ['id'], 'filters' => ['id'], 'saved_views' => ['id'],
        'calendar_folders' => ['calendar_id', 'folder_id'],
        'calendar_tags' => ['calendar_id', 'tag_id'],
        'event_tags' => ['event_id', 'tag_id'],
        'event_people' => ['event_id', 'person_id'],
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array<string,list<array>>|null $beforeTables
     * @param array<string,list<array>>|null $afterTables
     */
    public function record(int $userId, string $entity, int $entityId, string $op, ?array $beforeTables, ?array $afterTables): void
    {
        $this->db->insert('mutations', [
            'user_id' => $userId,
            'entity' => $entity,
            'entity_id' => $entityId,
            'op' => $op,
            'before_json' => $beforeTables === null ? null : json_encode(['tables' => $beforeTables], JSON_INVALID_UTF8_SUBSTITUTE),
            'after_json' => $afterTables === null ? null : json_encode(['tables' => $afterTables], JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    /** @return array{entity:string,op:string} */
    public function undoLatest(int $userId): array
    {
        $mutation = $this->db->one(
            'SELECT * FROM mutations WHERE user_id = ? AND undone = 0 ORDER BY id DESC LIMIT 1',
            [$userId]
        );
        if ($mutation === null) {
            throw HttpError::notFound('Nothing to undo', 'nothing_to_undo');
        }

        $before = self::tables($mutation['before_json']);
        $after = self::tables($mutation['after_json']);

        $this->db->tx(function () use ($mutation, $before, $after): void {
            // Delete rows created by the mutation (present in after, absent from before).
            foreach (array_reverse(self::TABLE_ORDER) as $table) {
                $beforeKeys = [];
                foreach ($before[$table] ?? [] as $row) {
                    $beforeKeys[$this->pkKey($table, $row)] = true;
                }
                foreach ($after[$table] ?? [] as $row) {
                    if (!isset($beforeKeys[$this->pkKey($table, $row)])) {
                        $this->deleteByPk($table, $row);
                    }
                }
            }
            // Restore original rows.
            foreach (self::TABLE_ORDER as $table) {
                foreach ($before[$table] ?? [] as $row) {
                    $this->db->upsert($table, $row);
                }
            }
            $this->db->run('UPDATE mutations SET undone = 1 WHERE id = ?', [$mutation['id']]);
        });

        // CalDAV journal: an undone event mutation must reach DAV clients too.
        // Recording MODIFY is enough — sync clients drop uris that 404.
        $pairs = [];
        foreach ([$before['events'] ?? [], $after['events'] ?? []] as $rows) {
            foreach ($rows as $row) {
                if (isset($row['calendar_id'], $row['uid'])) {
                    $pairs[$row['calendar_id'] . '|' . $row['uid']] = [(int) $row['calendar_id'], (string) $row['uid']];
                }
            }
        }
        foreach ($pairs as [$calendarId, $uid]) {
            ChangeLog::record($this->db, $calendarId, $uid, ChangeLog::OP_MODIFY);
        }

        return ['entity' => (string) $mutation['entity'], 'op' => (string) $mutation['op']];
    }

    private static function tables(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) && isset($decoded['tables']) && is_array($decoded['tables']) ? $decoded['tables'] : [];
    }

    private function pkKey(string $table, array $row): string
    {
        $parts = [];
        foreach (self::TABLE_PK[$table] ?? ['id'] as $col) {
            $parts[] = (string) ($row[$col] ?? '');
        }
        return implode('|', $parts);
    }

    private function deleteByPk(string $table, array $row): void
    {
        if (!in_array($table, self::TABLE_ORDER, true)) {
            return;
        }
        $conds = [];
        $params = [];
        foreach (self::TABLE_PK[$table] ?? ['id'] as $col) {
            $conds[] = "`$col` = ?";
            $params[] = $row[$col] ?? null;
        }
        $this->db->run("DELETE FROM `$table` WHERE " . implode(' AND ', $conds), $params);
    }
}
