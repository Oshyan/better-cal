<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

/**
 * Saved views ("modes"): named snapshots of client view state. config_json is
 * an opaque-to-the-server object the client defines:
 * {viewType, visibleCalendarIds, folderCollapse, filterText, anchor:"today"|dayKey}.
 */
final class SavedViews
{
    public function __construct(private readonly Db $db, private readonly Undo $undo)
    {
    }

    public function get(int $userId, int $id): array
    {
        $row = $this->db->one('SELECT * FROM saved_views WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('View not found');
        }
        return $row;
    }

    /** @return list<array> */
    public function listAll(int $userId): array
    {
        return array_map(
            static fn(array $row) => self::serialize($row),
            $this->db->all('SELECT * FROM saved_views WHERE user_id = ? ORDER BY position, id', [$userId])
        );
    }

    public function create(int $userId, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw HttpError::badRequest('name is required');
        }
        $config = $in['config'] ?? null;
        if (!is_array($config)) {
            throw HttpError::badRequest('config must be an object');
        }
        $position = (int) ($this->db->scalar('SELECT COALESCE(MAX(position), -1) + 1 FROM saved_views WHERE user_id = ?', [$userId]) ?? 0);
        $id = $this->db->insert('saved_views', [
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 120),
            'config_json' => json_encode($config),
            'position' => $position,
        ]);
        $created = $this->get($userId, $id);
        $this->undo->record($userId, 'saved_view', $id, 'create', null, ['saved_views' => [$created]]);
        return self::serialize($created);
    }

    public function patch(int $userId, int $id, array $in): array
    {
        $before = $this->get($userId, $id);
        $fields = [];
        if (array_key_exists('name', $in)) {
            $name = trim((string) $in['name']);
            if ($name === '') {
                throw HttpError::badRequest('name cannot be empty');
            }
            $fields['name'] = mb_substr($name, 0, 120);
        }
        if (array_key_exists('config', $in)) {
            if (!is_array($in['config'])) {
                throw HttpError::badRequest('config must be an object');
            }
            $fields['config_json'] = json_encode($in['config']);
        }
        if (array_key_exists('position', $in)) {
            $fields['position'] = (int) $in['position'];
        }
        if ($fields !== []) {
            $this->db->update('saved_views', $fields, 'id = ?', [$id]);
        }
        $after = $this->get($userId, $id);
        $this->undo->record($userId, 'saved_view', $id, 'update', ['saved_views' => [$before]], ['saved_views' => [$after]]);
        return self::serialize($after);
    }

    public function delete(int $userId, int $id): void
    {
        $view = $this->get($userId, $id);
        $this->db->run('DELETE FROM saved_views WHERE id = ?', [$id]);
        $this->undo->record($userId, 'saved_view', $id, 'delete', ['saved_views' => [$view]], null);
    }

    private static function serialize(array $row): array
    {
        $config = json_decode((string) $row['config_json'], true);
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'config' => is_array($config) ? $config : [],
            'position' => (int) $row['position'],
        ];
    }
}
