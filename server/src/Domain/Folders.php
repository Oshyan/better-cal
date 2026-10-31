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
            $before = self::places($this->db, $calendarId, $ids);
            if ($source !== null) {
                self::putAt($this->db, (int) $source['id'], $calendarId, null);
            }
            self::putAt($this->db, $id, $calendarId, $at);
            $was = $before[0]['at'] !== null;
            $name = (string) $cal['name'];
            $summary = $was ? "Moved '" . $name . "' within folder '" . $folder['name'] . "'"
                : ($source !== null ? "Moved '" . $name . "' from folder '" . $source['name'] . "' to '" . $folder['name'] . "'"
                : "Added '" . $name . "' to folder '" . $folder['name'] . "'");
            $this->undo->record($userId, Undo::PLACE, $calendarId, 'update', [], [], $summary, ['before' => $before, 'after' => self::places($this->db, $calendarId, $ids)], [], self::touches($calendarId, $ids));
            return ['calendarIds' => self::order($this->db, $id)];
        });
    }

    /** Take a calendar out of this folder (it stays in the others it's in). */
    public function unfile(int $userId, int $id, int $calendarId): void
    {
        $folder = $this->get($userId, $id);
        $cal = $this->ownedCalendar($userId, $calendarId);
        $this->db->tx(function () use ($userId, $id, $folder, $cal, $calendarId): void {
            $before = self::places($this->db, $calendarId, [$id]);
            self::putAt($this->db, $id, $calendarId, null);
            $this->undo->record($userId, Undo::PLACE, $calendarId, 'update', [], [], "Took '" . $cal['name'] . "' out of folder '" . $folder['name'] . "'", ['before' => $before, 'after' => self::places($this->db, $calendarId, [$id])], [], self::touches($calendarId, [$id]));
        });
    }

    /**
     * Where a calendar is in each of these folders: [{folder, at}], at null
     * when it isn't in one. What Undo puts back (Undo::PLACE): only this
     * calendar's place, so later changes to the others stay (#187 review).
     *
     * @param list<int> $folderIds
     * @return list<array{folder:int,at:?int}>
     */
    public static function places(Db $db, int $calendarId, array $folderIds): array
    {
        return array_map(static function (int $f) use ($db, $calendarId): array {
            $i = array_search($calendarId, self::order($db, $f), true);
            return ['folder' => $f, 'at' => $i === false ? null : (int) $i];
        }, $folderIds);
    }

    /**
     * What a placement touched, for Undo's staleness check: the calendar's
     * filing in each folder, keyed as the settings' own filing changes are.
     *
     * @param list<int> $folderIds
     * @return list<string>
     */
    public static function touches(int $calendarId, array $folderIds): array
    {
        return array_map(static fn(int $f) => 'calendar_folders:' . $calendarId . '|' . $f, $folderIds);
    }

    /** Put a calendar at a place in a folder's own order, or out of it (null). */
    public static function putAt(Db $db, int $folderId, int $calendarId, ?int $at): void
    {
        $order = array_values(array_filter(self::order($db, $folderId), static fn(int $c) => $c !== $calendarId));
        $in = $db->scalar('SELECT calendar_id FROM calendar_folders WHERE folder_id = ? AND calendar_id = ?', [$folderId, $calendarId]) !== null;
        if ($at === null) {
            if ($in) {
                $db->run('DELETE FROM calendar_folders WHERE folder_id = ? AND calendar_id = ?', [$folderId, $calendarId]);
            }
        } else {
            array_splice($order, max(0, min($at, count($order))), 0, [$calendarId]);
            if (!$in) {
                $db->run('INSERT INTO calendar_folders (calendar_id, folder_id, position) VALUES (?, ?, ?)', [$calendarId, $folderId, 0]);
            }
        }
        foreach ($order as $i => $c) {
            $db->run('UPDATE calendar_folders SET position = ? WHERE folder_id = ? AND calendar_id = ?', [$i, $folderId, $c]);
        }
    }

    /** A folder's calendars in its own order (ties by the calendars' order). */
    public static function order(Db $db, int $id): array
    {
        return array_map('intval', array_column($db->all(
            'SELECT cf.calendar_id FROM calendar_folders cf JOIN calendars c ON c.id = cf.calendar_id WHERE cf.folder_id = ? ORDER BY cf.position, c.position, c.id',
            [$id]
        ), 'calendar_id'));
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
