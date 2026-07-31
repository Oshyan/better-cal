<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

/**
 * Keyword/regex filters at global, folder, or calendar scope. Enabled filters
 * are applied server-side to the events window and search: action `hide`
 * drops matching occurrences, action `dim` marks them `dimmed:true`
 * (additive occurrence field). Keyword = case-insensitive substring; regex =
 * PCRE, evaluated case-insensitively.
 */
final class Filters
{
    private const SCOPES = ['global', 'folder', 'calendar'];
    private const TYPES = ['keyword', 'regex'];
    private const ACTIONS = ['hide', 'dim'];
    public const FIELDS = ['title', 'description', 'location'];
    private const MAX_PATTERN_CHARS = 500;

    public function __construct(private readonly Db $db, private readonly Undo $undo)
    {
    }

    public function get(int $userId, int $id): array
    {
        $row = $this->db->one('SELECT * FROM filters WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('Filter not found');
        }
        return $row;
    }

    /** @return list<array> */
    public function listAll(int $userId): array
    {
        return array_map(
            static fn(array $row) => self::serialize($row),
            $this->db->all('SELECT * FROM filters WHERE user_id = ? ORDER BY id', [$userId])
        );
    }

    public function create(int $userId, array $in): array
    {
        $scope = (string) ($in['scope'] ?? '');
        if (!in_array($scope, self::SCOPES, true)) {
            throw HttpError::badRequest('scope must be global|folder|calendar');
        }
        $type = (string) ($in['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw HttpError::badRequest('type must be keyword|regex');
        }
        $action = (string) ($in['action'] ?? 'hide');
        if (!in_array($action, self::ACTIONS, true)) {
            throw HttpError::badRequest('action must be hide|dim');
        }
        $config = self::validateConfig($type, is_array($in['config'] ?? null) ? $in['config'] : []);

        $id = $this->db->insert('filters', [
            'user_id' => $userId,
            'scope' => $scope,
            'scope_id' => $this->validateScopeId($userId, $scope, $in['scopeId'] ?? null),
            'type' => $type,
            'config_json' => json_encode($config),
            'action' => $action,
            'enabled' => filter_var($in['enabled'] ?? true, FILTER_VALIDATE_BOOL) ? 1 : 0,
        ]);
        $created = $this->get($userId, $id);
        $this->undo->record($userId, 'filter', $id, 'create', null, ['filters' => [$created]]);
        return self::serialize($created);
    }

    public function patch(int $userId, int $id, array $in): array
    {
        $before = $this->get($userId, $id);
        $fields = [];

        $scope = (string) $before['scope'];
        if (array_key_exists('scope', $in)) {
            $scope = (string) $in['scope'];
            if (!in_array($scope, self::SCOPES, true)) {
                throw HttpError::badRequest('scope must be global|folder|calendar');
            }
            $fields['scope'] = $scope;
        }
        if (array_key_exists('scope', $in) || array_key_exists('scopeId', $in)) {
            $scopeId = array_key_exists('scopeId', $in) ? $in['scopeId'] : $before['scope_id'];
            $fields['scope_id'] = $this->validateScopeId($userId, $scope, $scopeId);
        }

        $type = (string) $before['type'];
        if (array_key_exists('type', $in)) {
            $type = (string) $in['type'];
            if (!in_array($type, self::TYPES, true)) {
                throw HttpError::badRequest('type must be keyword|regex');
            }
            $fields['type'] = $type;
        }
        if (array_key_exists('type', $in) || array_key_exists('config', $in)) {
            $config = array_key_exists('config', $in) && is_array($in['config'])
                ? $in['config']
                : (json_decode((string) $before['config_json'], true) ?: []);
            $fields['config_json'] = json_encode(self::validateConfig($type, $config));
        }

        if (array_key_exists('action', $in)) {
            $action = (string) $in['action'];
            if (!in_array($action, self::ACTIONS, true)) {
                throw HttpError::badRequest('action must be hide|dim');
            }
            $fields['action'] = $action;
        }
        if (array_key_exists('enabled', $in)) {
            $fields['enabled'] = filter_var($in['enabled'], FILTER_VALIDATE_BOOL) ? 1 : 0;
        }

        if ($fields !== []) {
            $this->db->update('filters', $fields, 'id = ?', [$id]);
        }
        $after = $this->get($userId, $id);
        $this->undo->record($userId, 'filter', $id, 'update', ['filters' => [$before]], ['filters' => [$after]]);
        return self::serialize($after);
    }

    public function delete(int $userId, int $id): void
    {
        $filter = $this->get($userId, $id);
        $this->db->run('DELETE FROM filters WHERE id = ?', [$id]);
        $this->undo->record($userId, 'filter', $id, 'delete', ['filters' => [$filter]], null);
    }

    // ---- Evaluation ---------------------------------------------------

    /**
     * Enabled filters resolved for evaluation: folder scope expands to the
     * folder's calendar ids; calendarIds null means every calendar (global).
     *
     * @return list<array{type:string,config:array,action:string,calendarIds:?array<int,true>}>
     */
    public function enabledForUser(int $userId): array
    {
        $rows = $this->db->all(
            "SELECT * FROM filters WHERE user_id = ? AND enabled = 1 AND type IN ('keyword', 'regex') ORDER BY id",
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $config = json_decode((string) $row['config_json'], true);
            if (!is_array($config)) {
                continue;
            }
            $calendarIds = null;
            if ($row['scope'] === 'calendar') {
                $calendarIds = [(int) $row['scope_id'] => true];
            } elseif ($row['scope'] === 'folder') {
                $calendarIds = [];
                $links = $this->db->all('SELECT calendar_id FROM calendar_folders WHERE folder_id = ?', [(int) $row['scope_id']]);
                foreach ($links as $link) {
                    $calendarIds[(int) $link['calendar_id']] = true;
                }
            }
            $out[] = [
                'type' => (string) $row['type'],
                'config' => $config,
                'action' => (string) $row['action'],
                'calendarIds' => $calendarIds,
            ];
        }
        return $out;
    }

    /**
     * Pure matcher: does an occurrence-like array (title/description/location
     * keys) match the filter's pattern over its selected fields?
     *
     * @param array{type?:string,config?:array{pattern?:string,fields?:list<string>}} $filter
     */
    public static function evaluate(array $occ, array $filter): bool
    {
        $type = (string) ($filter['type'] ?? 'keyword');
        $config = is_array($filter['config'] ?? null) ? $filter['config'] : [];
        $pattern = (string) ($config['pattern'] ?? '');
        if ($pattern === '') {
            return false;
        }
        $fields = self::FIELDS;
        if (isset($config['fields']) && is_array($config['fields']) && $config['fields'] !== []) {
            $fields = array_values(array_intersect(self::FIELDS, array_map('strval', $config['fields'])));
        }
        foreach ($fields as $field) {
            $value = (string) ($occ[$field] ?? '');
            if ($value === '') {
                continue;
            }
            $hit = $type === 'regex'
                ? @preg_match(self::delimit($pattern), $value) === 1
                : mb_stripos($value, $pattern) !== false;
            if ($hit) {
                return true;
            }
        }
        return false;
    }

    /**
     * Decide the outcome for one event row against pre-resolved filters:
     * 'hide' (wins outright), 'dim', or null (untouched).
     *
     * @param list<array{type:string,config:array,action:string,calendarIds:?array<int,true>}> $filters
     */
    public static function disposition(array $row, array $filters): ?string
    {
        $result = null;
        foreach ($filters as $filter) {
            if ($filter['calendarIds'] !== null && !isset($filter['calendarIds'][(int) ($row['calendar_id'] ?? 0)])) {
                continue;
            }
            if (!self::evaluate($row, $filter)) {
                continue;
            }
            if ($filter['action'] === 'hide') {
                return 'hide';
            }
            $result = 'dim';
        }
        return $result;
    }

    // ---- Helpers ------------------------------------------------------

    /** @return array{pattern:string,fields:list<string>} */
    public static function validateConfig(string $type, array $config): array
    {
        $pattern = trim((string) ($config['pattern'] ?? ''));
        if ($pattern === '') {
            throw HttpError::badRequest('config.pattern is required');
        }
        if (mb_strlen($pattern) > self::MAX_PATTERN_CHARS) {
            throw HttpError::badRequest('config.pattern is too long (max ' . self::MAX_PATTERN_CHARS . ' chars)');
        }
        $fields = self::FIELDS;
        if (array_key_exists('fields', $config)) {
            if (!is_array($config['fields'])) {
                throw HttpError::badRequest('config.fields must be an array');
            }
            $fields = array_values(array_intersect(self::FIELDS, array_map('strval', $config['fields'])));
            if ($fields === []) {
                throw HttpError::badRequest('config.fields must include at least one of title, description, location');
            }
        }
        if ($type === 'regex' && @preg_match(self::delimit($pattern), '') === false) {
            throw HttpError::badRequest('Invalid regular expression', 'filter_invalid_regex');
        }
        return ['pattern' => $pattern, 'fields' => $fields];
    }

    private function validateScopeId(int $userId, string $scope, mixed $scopeId): ?int
    {
        if ($scope === 'global') {
            return null;
        }
        $scopeId = (int) ($scopeId ?? 0);
        if ($scopeId <= 0) {
            throw HttpError::badRequest("scopeId is required for $scope scope");
        }
        $table = $scope === 'folder' ? 'folders' : 'calendars';
        $owned = $this->db->scalar("SELECT id FROM `$table` WHERE id = ? AND user_id = ?", [$scopeId, $userId]);
        if ($owned === null) {
            throw HttpError::badRequest('scopeId must reference one of your ' . ($scope === 'folder' ? 'folders' : 'calendars'));
        }
        return $scopeId;
    }

    private static function delimit(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~iu';
    }

    private static function serialize(array $row): array
    {
        $config = json_decode((string) $row['config_json'], true);
        return [
            'id' => (int) $row['id'],
            'scope' => (string) $row['scope'],
            'scopeId' => $row['scope_id'] !== null ? (int) $row['scope_id'] : null,
            'type' => (string) $row['type'],
            'config' => is_array($config) ? $config : ['pattern' => '', 'fields' => self::FIELDS],
            'action' => (string) $row['action'],
            'enabled' => (int) $row['enabled'] === 1,
        ];
    }
}
