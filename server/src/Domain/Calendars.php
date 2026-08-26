<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

final class Calendars
{
    public function __construct(
        private readonly Db $db,
        private readonly Undo $undo,
        private readonly Labels $labels,
    ) {
    }

    /** @return array{calendars:list<array>,folders:list<array>,tags:list<array>} */
    public function listAll(int $userId): array
    {
        $calendars = $this->db->all('SELECT * FROM calendars WHERE user_id = ? ORDER BY position, id', [$userId]);
        $folders = $this->db->all('SELECT id, name, position FROM folders WHERE user_id = ? ORDER BY position, id', [$userId]);
        $tags = $this->db->all('SELECT id, name FROM tags WHERE user_id = ? ORDER BY name', [$userId]);

        $calIds = array_map(static fn($c) => (int) $c['id'], $calendars);
        $folderIdsByCal = [];
        $tagNamesByCal = [];
        $recentRawByCal = [];
        if ($calIds !== []) {
            [$in, $params] = Db::in($calIds);
            foreach ($this->db->all("SELECT calendar_id, folder_id FROM calendar_folders WHERE calendar_id IN $in", $params) as $row) {
                $folderIdsByCal[(int) $row['calendar_id']][] = (int) $row['folder_id'];
            }
            foreach ($this->db->all("SELECT ct.calendar_id, t.name FROM calendar_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.calendar_id IN $in ORDER BY t.name", $params) as $row) {
                $tagNamesByCal[(int) $row['calendar_id']][] = (string) $row['name'];
            }
            foreach ($this->db->all(
                "SELECT c.id, COALESCE(SUM(fs.raw_count), 0) AS recent_raw
                 FROM calendars c LEFT JOIN feed_stats fs
                   ON fs.calendar_id = c.id AND fs.poll_date >= DATE_SUB(CURDATE(), INTERVAL c.stale_after_days DAY)
                 WHERE c.id IN $in GROUP BY c.id",
                $params
            ) as $row) {
                $recentRawByCal[(int) $row['id']] = (int) $row['recent_raw'];
            }
        }

        return [
            'calendars' => array_map(
                fn(array $c) => $this->serialize(
                    $c,
                    $folderIdsByCal[(int) $c['id']] ?? [],
                    $tagNamesByCal[(int) $c['id']] ?? [],
                    $recentRawByCal[(int) $c['id']] ?? 0
                ),
                $calendars
            ),
            'folders' => array_map(static fn(array $f) => [
                'id' => (int) $f['id'], 'name' => (string) $f['name'], 'position' => (int) $f['position'],
            ], $folders),
            'tags' => array_map(static fn(array $t) => ['id' => (int) $t['id'], 'name' => (string) $t['name']], $tags),
        ];
    }

    public function get(int $userId, int $id): array
    {
        $row = $this->db->one('SELECT * FROM calendars WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('Calendar not found');
        }
        return $row;
    }

    public function create(int $userId, array $in, string $kind = 'local', ?string $sourceUrl = null): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw HttpError::badRequest('name is required');
        }
        $position = (int) ($this->db->scalar('SELECT COALESCE(MAX(position), -1) + 1 FROM calendars WHERE user_id = ?', [$userId]) ?? 0);
        $id = $this->db->tx(function () use ($userId, $in, $name, $kind, $sourceUrl, $position): int {
            $id = $this->db->insert('calendars', [
                'user_id' => $userId,
                'name' => mb_substr($name, 0, 160),
                'color' => $this->colorOrDefault($in['color'] ?? null),
                'kind' => $kind,
                'source_url' => $sourceUrl,
                'position' => $position,
            ]);
            if (!empty($in['folderIds']) && is_array($in['folderIds'])) {
                $this->setFolders($userId, $id, $in['folderIds']);
            }
            if (!empty($in['tagNames']) && is_array($in['tagNames'])) {
                $this->setTags($userId, $id, $in['tagNames']);
            }
            return $id;
        });
        $created = $this->get($userId, $id);
        $this->undo->record($userId, 'calendar', $id, 'create', null, [
            'calendars' => [$created],
            'calendar_folders' => $this->db->all('SELECT * FROM calendar_folders WHERE calendar_id = ?', [$id]),
            'calendar_tags' => $this->db->all('SELECT * FROM calendar_tags WHERE calendar_id = ?', [$id]),
        ]);
        return $this->serializeById($userId, $id);
    }

    public function patch(int $userId, int $id, array $in): array
    {
        $before = $this->get($userId, $id);
        $beforeLinks = [
            'calendar_folders' => $this->db->all('SELECT * FROM calendar_folders WHERE calendar_id = ?', [$id]),
            'calendar_tags' => $this->db->all('SELECT * FROM calendar_tags WHERE calendar_id = ?', [$id]),
        ];

        $fields = [];
        if (array_key_exists('name', $in)) {
            $name = trim((string) $in['name']);
            if ($name === '') {
                throw HttpError::badRequest('name cannot be empty');
            }
            $fields['name'] = mb_substr($name, 0, 160);
        }
        if (array_key_exists('color', $in)) {
            $fields['color'] = $this->colorOrDefault($in['color']);
        }
        if (array_key_exists('visible', $in)) {
            $fields['visible'] = filter_var($in['visible'], FILTER_VALIDATE_BOOL) ? 1 : 0;
        }
        if (array_key_exists('position', $in)) {
            $fields['position'] = (int) $in['position'];
        }
        if (array_key_exists('pollIntervalMinutes', $in)) {
            $fields['poll_interval_minutes'] = max(5, (int) $in['pollIntervalMinutes']);
        }
        if (array_key_exists('staleAfterDays', $in)) {
            $fields['stale_after_days'] = max(1, (int) $in['staleAfterDays']);
        }
        if (array_key_exists('groupSimilar', $in) || array_key_exists('reminderDefaults', $in)) {
            $settings = json_decode((string) ($before['settings_json'] ?? ''), true);
            $settings = is_array($settings) ? $settings : [];
            if (array_key_exists('groupSimilar', $in)) {
                $settings['groupSimilar'] = filter_var($in['groupSimilar'], FILTER_VALIDATE_BOOL);
            }
            if (array_key_exists('reminderDefaults', $in)) {
                $validated = Reminders::validateDefaults($in['reminderDefaults']);
                if ($validated === null) {
                    unset($settings['reminderDefaults']); // fall through to global defaults
                } else {
                    $settings['reminderDefaults'] = $validated;
                }
            }
            $fields['settings_json'] = json_encode($settings);
        }

        $this->db->tx(function () use ($userId, $id, $fields, $in): void {
            if ($fields !== []) {
                $this->db->update('calendars', $fields, 'id = ?', [$id]);
            }
            if (array_key_exists('folderIds', $in) && is_array($in['folderIds'])) {
                $this->setFolders($userId, $id, $in['folderIds']);
            }
            if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
                $this->setTags($userId, $id, $in['tagNames']);
            }
        });

        $after = $this->get($userId, $id);
        $this->undo->record($userId, 'calendar', $id, 'update', ['calendars' => [$before]] + $beforeLinks, [
            'calendars' => [$after],
            'calendar_folders' => $this->db->all('SELECT * FROM calendar_folders WHERE calendar_id = ?', [$id]),
            'calendar_tags' => $this->db->all('SELECT * FROM calendar_tags WHERE calendar_id = ?', [$id]),
        ]);
        return $this->serializeById($userId, $id);
    }

    /** Delete calendar + events. Fully captured in the mutation log, so undo restores everything. */
    /**
     * Adopt a subscribed calendar as local (docs/migration.md "transition
     * mode"): the feed link is severed, every event becomes an editable
     * local event, and history is kept exactly as last synced. One-way.
     *
     * @return array the updated calendar row (serialized)
     */
    public function adopt(int $userId, int $id): array
    {
        $row = $this->db->one('SELECT * FROM calendars WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('No such calendar');
        }
        if ((string) $row['kind'] !== 'subscribed') {
            throw HttpError::badRequest('Only subscribed calendars can be adopted');
        }
        $this->db->run(
            "UPDATE calendars SET kind = 'local', source_url = NULL, last_poll_status = 'never', last_poll_error = NULL WHERE id = ?",
            [$id]
        );
        $this->db->run("UPDATE events SET source = 'local' WHERE calendar_id = ?", [$id]);
        return $this->serializeById($userId, $id);
    }

    public function delete(int $userId, int $id): void
    {
        $calendar = $this->get($userId, $id);
        $events = $this->db->all('SELECT * FROM events WHERE calendar_id = ?', [$id]);
        $before = [
            'calendars' => [$calendar],
            'calendar_folders' => $this->db->all('SELECT * FROM calendar_folders WHERE calendar_id = ?', [$id]),
            'calendar_tags' => $this->db->all('SELECT * FROM calendar_tags WHERE calendar_id = ?', [$id]),
            'events' => $events,
        ];
        $eventIds = array_map(static fn($e) => (int) $e['id'], $events);
        if ($eventIds !== []) {
            [$in, $params] = Db::in($eventIds);
            $before['event_tags'] = $this->db->all("SELECT * FROM event_tags WHERE event_id IN $in", $params);
            $before['event_people'] = $this->db->all("SELECT * FROM event_people WHERE event_id IN $in", $params);
        }
        $this->db->run('DELETE FROM calendars WHERE id = ?', [$id]);
        $this->undo->record($userId, 'calendar', $id, 'delete', $before, null);
    }

    public function serializeById(int $userId, int $id): array
    {
        $row = $this->get($userId, $id);
        $folderIds = array_map(
            static fn($r) => (int) $r['folder_id'],
            $this->db->all('SELECT folder_id FROM calendar_folders WHERE calendar_id = ?', [$id])
        );
        $tagNames = array_map(
            static fn($r) => (string) $r['name'],
            $this->db->all('SELECT t.name FROM calendar_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.calendar_id = ? ORDER BY t.name', [$id])
        );
        $recentRaw = (int) ($this->db->scalar(
            'SELECT COALESCE(SUM(raw_count), 0) FROM feed_stats WHERE calendar_id = ? AND poll_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)',
            [$id, (int) $row['stale_after_days']]
        ) ?? 0);
        return $this->serialize($row, $folderIds, $tagNames, $recentRaw);
    }

    private function serialize(array $c, array $folderIds, array $tagNames, int $recentRaw): array
    {
        $subscribed = $c['kind'] === 'subscribed';
        $stale = $subscribed && $c['last_polled_at'] !== null
            && ($c['last_poll_status'] === 'error' || $recentRaw === 0);
        return [
            'id' => (int) $c['id'],
            'name' => (string) $c['name'],
            'color' => (string) $c['color'],
            'kind' => (string) $c['kind'],
            'pluginId' => isset($c['plugin_id']) && $c['plugin_id'] !== null ? (string) $c['plugin_id'] : null,
            'pluginSettings' => self::pluginSettingsFor($c['settings_json'] !== null ? (string) $c['settings_json'] : null),
            'sourceUrl' => $c['source_url'] !== null ? (string) $c['source_url'] : null,
            'visible' => (int) $c['visible'] === 1,
            'position' => (int) $c['position'],
            'pollIntervalMinutes' => (int) $c['poll_interval_minutes'],
            'staleAfterDays' => (int) $c['stale_after_days'],
            'folderIds' => $folderIds,
            'tagNames' => $tagNames,
            'groupSimilar' => self::groupSimilarFor(
                $c['settings_json'] !== null ? (string) $c['settings_json'] : null,
                (string) $c['kind']
            ),
            'reminderDefaults' => self::reminderDefaultsFor(
                $c['settings_json'] !== null ? (string) $c['settings_json'] : null
            ),
            'health' => [
                'lastPolledAt' => $c['last_polled_at'] !== null ? Time::dbToIso((string) $c['last_polled_at']) : null,
                'status' => (string) $c['last_poll_status'],
                'error' => $c['last_poll_error'] !== null ? (string) $c['last_poll_error'] : null,
                'stale' => $stale,
            ],
        ];
    }

    private function setFolders(int $userId, int $calendarId, array $folderIds): void
    {
        $this->db->run('DELETE FROM calendar_folders WHERE calendar_id = ?', [$calendarId]);
        foreach (array_unique(array_map('intval', $folderIds)) as $folderId) {
            $owned = $this->db->scalar('SELECT id FROM folders WHERE id = ? AND user_id = ?', [$folderId, $userId]);
            if ($owned !== null) {
                $this->db->run('INSERT IGNORE INTO calendar_folders (calendar_id, folder_id) VALUES (?, ?)', [$calendarId, $folderId]);
            }
        }
    }

    private function setTags(int $userId, int $calendarId, array $tagNames): void
    {
        $this->db->run('DELETE FROM calendar_tags WHERE calendar_id = ?', [$calendarId]);
        foreach ($this->labels->tagIds($userId, $tagNames) as $tagId) {
            $this->db->run('INSERT IGNORE INTO calendar_tags (calendar_id, tag_id) VALUES (?, ?)', [$calendarId, $tagId]);
        }
    }

    /**
     * Effective groupSimilar for a calendar: the stored settings_json value
     * when set, otherwise the kind default (subscribed feeds group similar
     * events by default; local calendars do not). Grouping itself is client-
     * side; the server only persists the preference.
     */
    /** settings_json.plugins verbatim (per-plugin calendar-scope values). */
    private static function pluginSettingsFor(?string $settingsJson): array
    {
        $s = is_string($settingsJson) ? (json_decode($settingsJson, true) ?: []) : [];
        return is_array($s['plugins'] ?? null) ? $s['plugins'] : [];
    }

    public static function groupSimilarFor(?string $settingsJson, string $kind): bool
    {
        $settings = $settingsJson !== null && $settingsJson !== '' ? json_decode($settingsJson, true) : null;
        if (is_array($settings) && array_key_exists('groupSimilar', $settings)) {
            return (bool) $settings['groupSimilar'];
        }
        return $kind === 'subscribed';
    }

    /** Stored per-calendar reminder defaults, or null when unset (global defaults apply). */
    public static function reminderDefaultsFor(?string $settingsJson): ?array
    {
        $settings = $settingsJson !== null && $settingsJson !== '' ? json_decode($settingsJson, true) : null;
        return is_array($settings) && isset($settings['reminderDefaults']) && is_array($settings['reminderDefaults'])
            ? $settings['reminderDefaults']
            : null;
    }

    private function colorOrDefault(mixed $color): string
    {
        $color = (string) ($color ?? '');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : '#4a7dff';
    }
}
