<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

final class Folders
{
    /** How a folder lists its calendars: its own order, or A to Z. */
    public const SORTS = ['manual', 'az'];

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
        if (array_key_exists('sort', $in)) {
            if (!in_array($in['sort'], self::SORTS, true)) {
                throw HttpError::badRequest('sort must be manual|az');
            }
            $fields['sort'] = (string) $in['sort'];
        }
        if ($fields !== []) {
            $this->db->update('folders', $fields, 'id = ?', [$id]);
        }
        $after = $this->get($userId, $id);
        $this->undo->record($userId, 'folder', $id, 'update', ['folders' => [$before]], ['folders' => [$after]]);
        return self::serialize($after);
    }

    /**
     * Put a calendar in this folder at a place in its own order (0 first),
     * taking it out of folder $from on the way when given: a calendar
     * dragged from one folder to another (#187). Within the same folder,
     * it's a move to a new place.
     */
    public function place(int $userId, int $id, int $calendarId, int $at, ?int $from = null): array
    {
        $folder = $this->get($userId, $id);
        $cal = $this->ownedCalendar($userId, $calendarId);
        $source = $from !== null && $from !== $id ? $this->get($userId, $from) : null;
        return $this->db->tx(function () use ($userId, $id, $folder, $cal, $calendarId, $at, $source): array {
            $ids = $source !== null ? [$id, (int) $source['id']] : [$id];
            $before = $this->links($ids);
            $was = in_array($calendarId, $this->order($id), true);
            if ($source !== null) {
                $this->db->run('DELETE FROM calendar_folders WHERE folder_id = ? AND calendar_id = ?', [(int) $source['id'], $calendarId]);
                $this->renumber((int) $source['id'], $this->order((int) $source['id']));
            }
            $order = array_values(array_filter($this->order($id), static fn(int $c) => $c !== $calendarId));
            array_splice($order, max(0, min($at, count($order))), 0, [$calendarId]);
            if (!$was) {
                $this->db->run('INSERT INTO calendar_folders (calendar_id, folder_id, position) VALUES (?, ?, ?)', [$calendarId, $id, 0]);
            }
            $this->renumber($id, $order);
            $name = (string) $cal['name'];
            $summary = $was ? "Moved '" . $name . "' within folder '" . $folder['name'] . "'"
                : ($source !== null ? "Moved '" . $name . "' from folder '" . $source['name'] . "' to '" . $folder['name'] . "'"
                : "Added '" . $name . "' to folder '" . $folder['name'] . "'");
            $this->undo->record($userId, 'folder', $id, 'update', ['calendar_folders' => $before], ['calendar_folders' => $this->links($ids)], $summary);
            return ['calendarIds' => $order];
        });
    }

    /** Take a calendar out of this folder (it stays in the others it's in). */
    public function unfile(int $userId, int $id, int $calendarId): void
    {
        $folder = $this->get($userId, $id);
        $cal = $this->ownedCalendar($userId, $calendarId);
        $this->db->tx(function () use ($userId, $id, $folder, $cal, $calendarId): void {
            $before = $this->links([$id]);
            $this->db->run('DELETE FROM calendar_folders WHERE folder_id = ? AND calendar_id = ?', [$id, $calendarId]);
            $this->renumber($id, $this->order($id));
            $this->undo->record($userId, 'folder', $id, 'update', ['calendar_folders' => $before], ['calendar_folders' => $this->links([$id])], "Took '" . $cal['name'] . "' out of folder '" . $folder['name'] . "'");
        });
    }

    /** A folder's calendars in its own order (ties by the calendars' order). */
    private function order(int $id): array
    {
        return array_map('intval', array_column($this->db->all(
            'SELECT cf.calendar_id FROM calendar_folders cf JOIN calendars c ON c.id = cf.calendar_id WHERE cf.folder_id = ? ORDER BY cf.position, c.position, c.id',
            [$id]
        ), 'calendar_id'));
    }

    private function renumber(int $id, array $order): void
    {
        foreach ($order as $i => $calendarId) {
            $this->db->run('UPDATE calendar_folders SET position = ? WHERE folder_id = ? AND calendar_id = ?', [$i, $id, $calendarId]);
        }
    }

    /** @param list<int> $ids */
    private function links(array $ids): array
    {
        [$in, $params] = Db::in($ids);
        return $this->db->all("SELECT * FROM calendar_folders WHERE folder_id IN $in ORDER BY folder_id, position", $params);
    }

    private function ownedCalendar(int $userId, int $calendarId): array
    {
        $cal = $this->db->one('SELECT id, name FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]);
        if ($cal === null) {
            throw HttpError::notFound('Calendar not found');
        }
        return $cal;
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
        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'position' => (int) $row['position'], 'sort' => self::sortOf($row)];
    }

    public static function sortOf(array $row): string
    {
        return in_array($row['sort'] ?? null, self::SORTS, true) ? (string) $row['sort'] : 'manual';
    }
}
