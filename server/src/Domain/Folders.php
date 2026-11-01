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
        return $this->undo->write($userId, function () use ($userId, $name): array {
            $position = (int) ($this->db->scalar('SELECT COALESCE(MAX(position), -1) + 1 FROM folders WHERE user_id = ?', [$userId]) ?? 0);
            $id = $this->db->insert('folders', ['user_id' => $userId, 'name' => mb_substr($name, 0, 120), 'position' => $position]);
            $created = $this->get($userId, $id);
            $this->undo->record($userId, 'folder', $id, 'create', null, ['folders' => [$created]]);
            return self::serialize($created);
        });
    }

    public function patch(int $userId, int $id, array $in): array
    {
        return $this->undo->write($userId, fn(): array => $this->patchLocked($userId, $id, $in));
    }

    private function patchLocked(int $userId, int $id, array $in): array
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
     * it's a move to a new place. Places are sparse (Order), so this
     * changes only the calendar's own rows, and its undo is the ordinary
     * kind: just those rows go back.
     */
    public function place(int $userId, int $id, int $calendarId, int $at, ?int $from = null): array
    {
        $folder = $this->get($userId, $id);
        $cal = $this->ownedCalendar($userId, $calendarId);
        $source = $from !== null && $from !== $id ? $this->get($userId, $from) : null;
        return $this->undo->write($userId, function () use ($userId, $id, $folder, $cal, $calendarId, $at, $source): array {
            $ordered = array_map(static fn($r) => [(int) $r['calendar_id'], (int) $r['position']], $this->db->all(
                'SELECT cf.calendar_id, cf.position FROM calendar_folders cf JOIN calendars c ON c.id = cf.calendar_id WHERE cf.folder_id = ? ORDER BY cf.position, c.position, c.id',
                [$id]
            ));
            $places = Order::place($ordered, $calendarId, $at);
            $was = in_array($calendarId, array_map(static fn($r) => $r[0], $ordered), true);
            $rows = fn(): array => $this->rows($calendarId, $id, $source !== null ? (int) $source['id'] : null, array_keys($places));
            $before = $rows();
            if ($source !== null) {
                $this->db->run('DELETE FROM calendar_folders WHERE folder_id = ? AND calendar_id = ?', [(int) $source['id'], $calendarId]);
            }
            if (!$was) {
                $this->db->run('INSERT INTO calendar_folders (calendar_id, folder_id, position) VALUES (?, ?, ?)', [$calendarId, $id, $places[$calendarId]]);
            }
            foreach ($places as $c => $pos) {
                $this->db->run('UPDATE calendar_folders SET position = ? WHERE folder_id = ? AND calendar_id = ?', [$pos, $id, $c]);
            }
            $name = (string) $cal['name'];
            $summary = $was ? "Moved '" . $name . "' within folder '" . $folder['name'] . "'"
                : ($source !== null ? "Moved '" . $name . "' from folder '" . $source['name'] . "' to '" . $folder['name'] . "'"
                : "Added '" . $name . "' to folder '" . $folder['name'] . "'");
            // Numbered afresh, every place in the folder changed in effect
            // (as Calendars::placeInList).
            $touches = count($places) > 1 ? array_map(static fn(array $r) => [Undo::keyOf('calendar_folders', ['calendar_id' => $r[0], 'folder_id' => $id]), 'position'], $ordered) : [];
            $touches[] = ['order:folder:' . $id, count($places) > 1 ? '*' : '+'];
            $this->undo->record($userId, 'calendar', $calendarId, 'update', ['calendar_folders' => $before], ['calendar_folders' => $rows()], $summary, touches: $touches);
            return ['calendarIds' => self::order($this->db, $id)];
        });
    }

    /** Take a calendar out of this folder (it stays in the others it's in). */
    public function unfile(int $userId, int $id, int $calendarId): void
    {
        $folder = $this->get($userId, $id);
        $cal = $this->ownedCalendar($userId, $calendarId);
        $this->undo->write($userId, function () use ($userId, $id, $folder, $cal, $calendarId): void {
            $before = $this->rows($calendarId, $id, null, []);
            $this->db->run('DELETE FROM calendar_folders WHERE folder_id = ? AND calendar_id = ?', [$id, $calendarId]);
            $this->undo->record($userId, 'calendar', $calendarId, 'update', ['calendar_folders' => $before], ['calendar_folders' => []], "Took '" . $cal['name'] . "' out of folder '" . $folder['name'] . "'");
        });
    }

    /**
     * The calendar_folders rows a placement can change: the calendar's own
     * in this folder and the one it leaves, and any others it gave a new
     * place (only when the folder was numbered afresh).
     *
     * @param list<int> $others
     */
    private function rows(int $calendarId, int $folderId, ?int $fromId, array $others): array
    {
        $out = $this->db->all('SELECT * FROM calendar_folders WHERE calendar_id = ? AND folder_id IN (?, ?)', [$calendarId, $folderId, $fromId ?? $folderId]);
        $others = array_values(array_diff($others, [$calendarId]));
        if ($others !== []) {
            [$in, $params] = Db::in($others);
            $out = array_merge($out, $this->db->all("SELECT * FROM calendar_folders WHERE folder_id = ? AND calendar_id IN $in", array_merge([$folderId], $params)));
        }
        return $out;
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
        $this->undo->write($userId, function () use ($userId, $id): void {
            $folder = $this->get($userId, $id);
            $links = $this->db->all('SELECT * FROM calendar_folders WHERE folder_id = ?', [$id]);
            $this->db->run('DELETE FROM folders WHERE id = ?', [$id]);
            $this->undo->record($userId, 'folder', $id, 'delete', ['folders' => [$folder], 'calendar_folders' => $links], null);
        });
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
