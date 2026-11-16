<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

/**
 * Tags as a list to manage (#134): the tags in use, rename (a name taken
 * merges), delete. A tag is made by its first use on an event
 * (Labels::tagIds) and is offered while an event that isn't deleted
 * carries it (inUse): that is read from the links each time, so a tag
 * nothing uses drops out of every list without a sweep, and its row stays
 * for any undo that names it.
 *
 * Rename and delete each change the tag's links on every event at once, a
 * day of a repeating event included: the same change to the series' set
 * and to every day's, so a day that follows its series still holds
 * exactly the series' set, and a day that owns its tags keeps owning them.
 * Filters, saved views and outgoing search feeds hold text, not tags: a
 * rename doesn't change them (filterMatches says which filters match the
 * name).
 */
final class Tags
{
    public const MAX_NAME = 80;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The one spelling rule for a tag's name: what quick add's `#` marker
     * reads (QuickAddMarkers builds its pattern from this), so every name
     * the rule allows can be typed after `#` in quick add and search.
     * Letters, digits, hyphens and underscores, at least one letter, at
     * most 50 characters. The app's marker suggestions hold the same text
     * (web/src/lib/markersuggest.js, TAG_NAME); the unit suite compares
     * the two.
     */
    public const NAME_PATTERN = '(?=[\p{L}\p{N}_-]*\p{L})[\p{L}\p{N}][\p{L}\p{N}_-]{0,49}';

    /** NAME_PATTERN in words, for a refusal. */
    public const NAME_RULE = 'A tag\'s name can have letters, digits, hyphens (-) and underscores (_), starting with a letter or digit, with at least one letter, up to 50 characters and no spaces, so it can be typed after # in quick add and search.';

    /** A tag's name as stored: trimmed, at most MAX_NAME characters ('' when nothing is left). */
    public static function normalize(string $name): string
    {
        return mb_substr(trim($name), 0, self::MAX_NAME);
    }

    /** Whether a name follows the spelling rule (NAME_PATTERN), whole. */
    public static function writable(string $name): bool
    {
        return preg_match('/^' . self::NAME_PATTERN . '$/u', $name) === 1;
    }

    /**
     * The tags on at least one event that isn't deleted, by name, with how
     * many events carry each (a repeating event counts once, its edited
     * days with it). The one definition of a tag offered: autocomplete,
     * search's chips and this list all read it.
     *
     * @return list<array{id:int,name:string,eventCount:int}>
     */
    public function inUse(int $userId): array
    {
        $rows = $this->db->all(
            'SELECT t.id, t.name, COUNT(DISTINCT COALESCE(e.recurrence_parent_id, e.id)) AS event_count
             FROM tags t
             JOIN event_tags et ON et.tag_id = t.id
             JOIN events e ON e.id = et.event_id AND e.deleted_at IS NULL
             WHERE t.user_id = ?
             GROUP BY t.id, t.name
             ORDER BY t.name',
            [$userId]
        );
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'eventCount' => (int) $r['event_count'],
        ], $rows);
    }

    /**
     * inUse, each with how many of the account's keyword and regex filters
     * that match on tags match its name (enabled or not): those filters
     * follow the text they hold, not the tag, so the list can say a rename
     * leaves them behind.
     *
     * @return list<array{id:int,name:string,eventCount:int,filterCount:int}>
     */
    public function list(int $userId): array
    {
        $filters = [];
        foreach ($this->db->all("SELECT type, config_json FROM filters WHERE user_id = ? AND type IN ('keyword', 'regex')", [$userId]) as $row) {
            $config = json_decode((string) $row['config_json'], true);
            if (is_array($config) && in_array('tags', array_map('strval', (array) ($config['fields'] ?? [])), true)) {
                $filters[] = ['type' => (string) $row['type'], 'config' => $config];
            }
        }
        return array_map(static function (array $tag) use ($filters): array {
            $tag['filterCount'] = count(array_filter($filters, static fn(array $f): bool => Filters::matchedField(['tags' => [$tag['name']]], $f) === 'tags'));
            return $tag;
        }, $this->inUse($userId));
    }

    /**
     * Rename a tag. A name another tag has (compared as the database
     * compares names, case aside) merges this one into it: every event
     * carrying this one carries that one instead, and this one goes. The
     * survivor keeps its own spelling. One journal entry; undo puts both
     * back as they were.
     *
     * @return array{id:int,name:string,merged:bool,eventCount:int}
     */
    public function rename(int $userId, int $id, mixed $name): array
    {
        if (!is_string($name) || self::normalize($name) === '') {
            throw HttpError::badRequest('name is required');
        }
        $name = self::normalize($name);
        // A name the # marker can't hold could be shown but never typed (#310).
        if (!self::writable($name)) {
            throw HttpError::badRequest(self::NAME_RULE, 'tag_name_unwritable');
        }
        return $this->undo()->write($userId, function () use ($userId, $id, $name): array {
            $tag = $this->requireTag($userId, $id);
            $into = $this->db->scalar('SELECT id FROM tags WHERE user_id = ? AND name = ? AND id <> ?', [$userId, $name, $id]);
            $ids = $into !== null ? [$id, (int) $into] : [$id];
            if ($into === null && $name === (string) $tag['name']) {
                return $this->answer($userId, $id, false);
            }
            $before = $this->snapshot($ids);
            if ($into !== null) {
                $into = (int) $into;
                // The same change on every row that carries it, a series' and
                // each of its days': a day that follows its series still
                // holds the series' set, one that owns its tags still owns them.
                $this->db->run('INSERT IGNORE INTO event_tags (event_id, tag_id) SELECT event_id, ? FROM event_tags WHERE tag_id = ?', [$into, $id]);
                $this->db->run('DELETE FROM event_tags WHERE tag_id = ?', [$id]);
                $this->db->run('DELETE FROM tags WHERE id = ?', [$id]);
                $survivor = (string) $this->db->scalar('SELECT name FROM tags WHERE id = ?', [$into]);
                $summary = 'Merged tag "' . $tag['name'] . '" into "' . $survivor . '"';
            } else {
                $this->db->run('UPDATE tags SET name = ? WHERE id = ?', [$name, $id]);
                $summary = 'Renamed tag "' . $tag['name'] . '" to "' . $name . '"';
            }
            $this->undo()->record($userId, 'tag', $id, 'update', $before, $this->snapshot($ids), $summary);
            return $this->answer($userId, $into ?? $id, $into !== null);
        });
    }

    /**
     * Delete a tag: it comes off every event that carries it (the events
     * stay), and the tag goes. One journal entry; undo puts it back on the
     * same events.
     *
     * @return array{ok:true,eventCount:int}
     */
    public function delete(int $userId, int $id): array
    {
        return $this->undo()->write($userId, function () use ($userId, $id): array {
            $tag = $this->requireTag($userId, $id);
            $count = $this->eventCount($id);
            $before = $this->snapshot([$id]);
            $this->db->run('DELETE FROM event_tags WHERE tag_id = ?', [$id]);
            $this->db->run('DELETE FROM tags WHERE id = ?', [$id]);
            $this->undo()->record($userId, 'tag', $id, 'delete', $before, null,
                'Deleted tag "' . $tag['name'] . '" (' . $count . ' event' . ($count === 1 ? '' : 's') . ')');
            return ['ok' => true, 'eventCount' => $count];
        });
    }

    /** The tag rows and every link to them, as an entry keeps them. @param list<int> $ids */
    private function snapshot(array $ids): array
    {
        [$in, $params] = Db::in($ids);
        return [
            'tags' => $this->db->all("SELECT * FROM tags WHERE id IN $in", $params),
            'event_tags' => $this->db->all("SELECT * FROM event_tags WHERE tag_id IN $in", $params),
        ];
    }

    private function eventCount(int $id): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(DISTINCT COALESCE(e.recurrence_parent_id, e.id)) FROM event_tags et
             JOIN events e ON e.id = et.event_id AND e.deleted_at IS NULL WHERE et.tag_id = ?',
            [$id]
        );
    }

    /** @return array{id:int,name:string,merged:bool,eventCount:int} */
    private function answer(int $userId, int $id, bool $merged): array
    {
        return [
            'id' => $id,
            'name' => (string) $this->db->scalar('SELECT name FROM tags WHERE id = ? AND user_id = ?', [$id, $userId]),
            'merged' => $merged,
            'eventCount' => $this->eventCount($id),
        ];
    }

    private function requireTag(int $userId, int $id): array
    {
        $row = $this->db->one('SELECT * FROM tags WHERE id = ? AND user_id = ?' . $this->db->forUpdate(), [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('No such tag');
        }
        return $row;
    }

    private ?Undo $undo = null;

    private function undo(): Undo
    {
        return $this->undo ??= new Undo($this->db);
    }
}
