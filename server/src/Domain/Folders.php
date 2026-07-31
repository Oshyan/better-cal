<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

final class Folders
{
    public function __construct(private readonly Db $db, private readonly Undo $undo)
    {
    }

    public function get(int $userId, int $id): array
    {
        $row = $this->db->one('SELECT * FROM folders WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('Folder not found');
        }
        return $row;
    }

    public function create(int $userId, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw HttpError::badRequest('name is required');
        }
        $position = (int) ($this->db->scalar('SELECT COALESCE(MAX(position), -1) + 1 FROM folders WHERE user_id = ?', [$userId]) ?? 0);
        $id = $this->db->insert('folders', ['user_id' => $userId, 'name' => mb_substr($name, 0, 120), 'position' => $position]);
        $created = $this->get($userId, $id);
        $this->undo->record($userId, 'folder', $id, 'create', null, ['folders' => [$created]]);
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
        if (array_key_exists('position', $in)) {
            $fields['position'] = (int) $in['position'];
        }
        if ($fields !== []) {
            $this->db->update('folders', $fields, 'id = ?', [$id]);
        }
        $after = $this->get($userId, $id);
        $this->undo->record($userId, 'folder', $id, 'update', ['folders' => [$before]], ['folders' => [$after]]);
        return self::serialize($after);
    }

    public function delete(int $userId, int $id): void
    {
        $folder = $this->get($userId, $id);
        $links = $this->db->all('SELECT * FROM calendar_folders WHERE folder_id = ?', [$id]);
        $this->db->run('DELETE FROM folders WHERE id = ?', [$id]);
        $this->undo->record($userId, 'folder', $id, 'delete', ['folders' => [$folder], 'calendar_folders' => $links], null);
    }

    public static function serialize(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'position' => (int) $row['position']];
    }
}
