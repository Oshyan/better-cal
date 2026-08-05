<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;

/**
 * Keyword/regex/prompt filters at global, folder, or calendar scope. Enabled
 * filters are applied server-side to the events window and search: action
 * `hide` drops matching occurrences, action `dim` marks them `dimmed:true`,
 * action `highlight` marks them `highlighted:true` (additive occurrence
 * fields; precedence hide > dim > highlight when several filters match).
 * Keyword = case-insensitive substring; regex =
 * PCRE, evaluated case-insensitively. Prompt filters are never evaluated in
 * the request path: a background worker scores feed events against the prompt
 * (see PromptEval) and the cached verdicts in `filter_evals` are joined here;
 * verdict `fail` applies the filter's action, a missing verdict is a pass.
 */
final class Filters
{
    private const SCOPES = ['global', 'folder', 'calendar'];
    private const TYPES = ['keyword', 'regex', 'prompt'];
    private const ACTIONS = ['hide', 'dim', 'highlight'];
    /** Disposition precedence: a stronger action beats a weaker one. */
    private const STRENGTH = ['hide' => 3, 'dim' => 2, 'highlight' => 1];
    /** Fields a keyword/regex filter may match against. */
    public const FIELDS = ['title', 'description', 'location', 'tags'];
    /**
     * Curated highlight colours. A closed set rather than a free hex field:
     * the highlight is a glow drawn over a tinted chip, so an arbitrary
     * colour can land unreadable in one theme while looking fine in the
     * other. Mirrors web/src/lib/color.js PALETTE.
     */
    public const HIGHLIGHT_COLORS = [
        '#5b7fd4', '#c95d5d', '#4f9d69', '#c98c3d', '#8e6cc0',
        '#3d9dc9', '#c9569b', '#6b8e23', '#b0713a', '#5d6dc9',
    ];
    /** Default when config.fields is absent; tags is opt-in. */
    public const DEFAULT_FIELDS = ['title', 'description', 'location'];
    private const MAX_PATTERN_CHARS = 500;
    private const MAX_PROMPT_CHARS = 2000;
    /** Cap on event ids per on-read filter_eval enqueue. */
    public const MAX_ON_READ_EVENT_IDS = 300;

    public function __construct(
        private readonly Db $db,
        private readonly Undo $undo,
        private readonly ?JobQueue $queue = null,
    ) {
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
            throw HttpError::badRequest('type must be keyword|regex|prompt');
        }
        $action = (string) ($in['action'] ?? 'hide');
        if (!in_array($action, self::ACTIONS, true)) {
            throw HttpError::badRequest('action must be hide|dim|highlight');
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
        $this->scheduleEval($created);
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
                throw HttpError::badRequest('type must be keyword|regex|prompt');
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
                throw HttpError::badRequest('action must be hide|dim|highlight');
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

        // A prompt/type/config change invalidates cached verdicts; the
        // background job then re-evaluates from scratch.
        $definitionChanged = isset($fields['type']) || isset($fields['config_json']);
        if ($definitionChanged && ($before['type'] === 'prompt' || $after['type'] === 'prompt')) {
            $this->db->run('DELETE FROM filter_evals WHERE filter_id = ?', [$id]);
        }
        if ($definitionChanged || (isset($fields['enabled']) && (int) $after['enabled'] === 1)) {
            $this->scheduleEval($after);
        }
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
            $out[] = [
                'type' => (string) $row['type'],
                'config' => $config,
                'action' => (string) $row['action'],
                'calendarIds' => $this->scopeCalendarIds($row),
            ];
        }
        return $out;
    }

    /**
     * Enabled prompt filters plus their cached verdicts for the given events,
     * ready for promptDisposition(). No LLM calls here, ever: this only reads
     * the filter_evals cache written by the background worker.
     *
     * @param list<int> $eventIds
     * @return array{
     *   filters: list<array{id:int,action:string,calendarIds:?array<int,true>}>,
     *   failed: array<int,array<int,true>>,
     *   evaluated: array<int,array<int,true>>
     * } failed is filterId => set of event ids whose verdict is fail;
     *   evaluated is filterId => set of event ids with ANY cached verdict
     *   (so callers can spot coverage gaps and queue background evaluation).
     */
    public function promptFilterContext(int $userId, array $eventIds): array
    {
        $empty = ['filters' => [], 'failed' => [], 'evaluated' => []];
        if ($eventIds === []) {
            return $empty;
        }
        $rows = $this->db->all(
            "SELECT * FROM filters WHERE user_id = ? AND enabled = 1 AND type = 'prompt' ORDER BY id",
            [$userId]
        );
        if ($rows === []) {
            return $empty;
        }
        $filters = [];
        foreach ($rows as $row) {
            $filters[] = [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'color' => (json_decode((string) $row['config_json'], true)['color'] ?? null),
                'calendarIds' => $this->scopeCalendarIds($row),
            ];
        }
        [$fIn, $fParams] = Db::in(array_column($filters, 'id'));
        [$eIn, $eParams] = Db::in(array_values(array_unique(array_map('intval', $eventIds))));
        $failed = [];
        $evaluated = [];
        $evals = $this->db->all(
            "SELECT filter_id, event_id, verdict FROM filter_evals
             WHERE filter_id IN $fIn AND event_id IN $eIn",
            [...$fParams, ...$eParams]
        );
        foreach ($evals as $eval) {
            $filterId = (int) $eval['filter_id'];
            $eventId = (int) $eval['event_id'];
            $evaluated[$filterId][$eventId] = true;
            if ((string) $eval['verdict'] === 'fail') {
                $failed[$filterId][$eventId] = true;
            }
        }
        return ['filters' => $filters, 'failed' => $failed, 'evaluated' => $evaluated];
    }

    /** @return ?array<int,true> calendar ids the filter row applies to; null = all */
    private function scopeCalendarIds(array $row): ?array
    {
        if ($row['scope'] === 'calendar') {
            return [(int) $row['scope_id'] => true];
        }
        if ($row['scope'] === 'folder') {
            $calendarIds = [];
            $links = $this->db->all('SELECT calendar_id FROM calendar_folders WHERE folder_id = ?', [(int) $row['scope_id']]);
            foreach ($links as $link) {
                $calendarIds[(int) $link['calendar_id']] = true;
            }
            return $calendarIds;
        }
        return null;
    }

    /** Queue background (re-)evaluation for a prompt filter row. */
    private function scheduleEval(array $row): void
    {
        if ($this->queue !== null && (string) $row['type'] === 'prompt' && (int) $row['enabled'] === 1) {
            $this->queue->enqueue('filter_eval', ['filterId' => (int) $row['id']]);
        }
    }

    /**
     * On-read healing: queue a targeted filter_eval job for events that lack
     * cached verdicts (e.g. old events browsed for the first time). Never
     * evaluates inline — the worker does the LLM calls. Capped at
     * MAX_ON_READ_EVENT_IDS ids and deduped by payload hash so repeated reads
     * of the same window enqueue at most one pending job.
     *
     * @param list<int> $eventIds
     */
    public function enqueueEvalForEvents(array $eventIds): void
    {
        if ($this->queue === null || $eventIds === []) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $eventIds)));
        sort($ids);
        $ids = array_slice($ids, 0, self::MAX_ON_READ_EVENT_IDS);
        $hash = self::evalPayloadHash($ids);
        if ($this->queue->hasPendingWithHash('filter_eval', $hash)) {
            return;
        }
        $this->queue->enqueue('filter_eval', ['eventIds' => $ids, 'hash' => $hash]);
    }

    /** Stable job identity for an event-id set: sha256 over the sorted unique ids. */
    public static function evalPayloadHash(array $eventIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $eventIds)));
        sort($ids);
        return hash('sha256', implode(',', $ids));
    }

    /**
     * Pure matcher: does an occurrence-like array (title/description/location
     * keys, plus a `tags` list of tag names when the filter selects the tags
     * field) match the filter's pattern over its selected fields?
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
        $fields = self::DEFAULT_FIELDS;
        if (isset($config['fields']) && is_array($config['fields']) && $config['fields'] !== []) {
            $fields = array_values(array_intersect(self::FIELDS, array_map('strval', $config['fields'])));
        }
        foreach ($fields as $field) {
            if ($field === 'tags') {
                $tags = is_array($occ['tags'] ?? null) ? $occ['tags'] : [];
                foreach ($tags as $tag) {
                    if (self::patternHits($type, $pattern, (string) $tag)) {
                        return true;
                    }
                }
                continue;
            }
            $value = (string) ($occ[$field] ?? '');
            if ($value !== '' && self::patternHits($type, $pattern, $value)) {
                return true;
            }
        }
        return false;
    }

    private static function patternHits(string $type, string $pattern, string $value): bool
    {
        return $type === 'regex'
            ? @preg_match(self::delimit($pattern), $value) === 1
            : mb_stripos($value, $pattern) !== false;
    }

    /** Does any of the given resolved filters match against the tags field? */
    public static function anyUsesTags(array $filters): bool
    {
        foreach ($filters as $filter) {
            $fields = $filter['config']['fields'] ?? null;
            if (is_array($fields) && in_array('tags', array_map('strval', $fields), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Decide the outcome for one event row against pre-resolved filters:
     * 'hide' (wins outright), 'dim', 'highlight', or null (untouched).
     * Precedence across matching filters: hide > dim > highlight.
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
            $result = self::strongest($result, (string) $filter['action']);
        }
        return $result;
    }

    /**
     * The colour a highlighted row should glow in: the first matching enabled
     * highlight filter that names one. Separate from disposition() because
     * that returns the winning ACTION and several callers depend on it being
     * a plain string; null means "no colour chosen", i.e. the accent.
     */
    public static function highlightColorFor(array $row, array $filters): ?string
    {
        foreach ($filters as $filter) {
            if (($filter['action'] ?? '') !== 'highlight') {
                continue;
            }
            if ($filter['calendarIds'] !== null && !isset($filter['calendarIds'][(int) ($row['calendar_id'] ?? 0)])) {
                continue;
            }
            $color = $filter['config']['color'] ?? null;
            if ($color !== null && self::evaluate($row, $filter)) {
                return (string) $color;
            }
        }
        return null;
    }

    /**
     * Same, for prompt filters: their verdicts are pre-fetched, so matching
     * means "this event is in the filter's failed set".
     *
     * @param list<array{id:int,action:string,color?:?string,calendarIds:?array<int,true>}> $promptFilters
     * @param array<int,array<int,true>> $failed filterId => set of failing event ids
     */
    public static function promptHighlightColorFor(array $row, array $promptFilters, array $failed): ?string
    {
        $eventId = (int) ($row['id'] ?? 0);
        foreach ($promptFilters as $filter) {
            if (($filter['action'] ?? '') !== 'highlight' || empty($filter['color'])) {
                continue;
            }
            if ($filter['calendarIds'] !== null && !isset($filter['calendarIds'][(int) ($row['calendar_id'] ?? 0)])) {
                continue;
            }
            if (isset($failed[$filter['id']][$eventId])) {
                return (string) $filter['color'];
            }
        }
        return null;
    }

    /**
     * Pure prompt-filter disposition for one event row given pre-fetched
     * verdicts (see promptFilterContext). Same precedence as disposition():
     * hide wins outright, then dim, then highlight; null when every filter
     * passes, is out of scope, or has no cached verdict yet.
     *
     * @param list<array{id:int,action:string,calendarIds:?array<int,true>}> $promptFilters
     * @param array<int,array<int,true>> $failed filterId => set of failing event ids
     */
    public static function promptDisposition(array $row, array $promptFilters, array $failed): ?string
    {
        $eventId = (int) ($row['id'] ?? 0);
        $result = null;
        foreach ($promptFilters as $filter) {
            if ($filter['calendarIds'] !== null && !isset($filter['calendarIds'][(int) ($row['calendar_id'] ?? 0)])) {
                continue;
            }
            if (!isset($failed[$filter['id']][$eventId])) {
                continue;
            }
            if ($filter['action'] === 'hide') {
                return 'hide';
            }
            $result = self::strongest($result, (string) $filter['action']);
        }
        return $result;
    }

    /** Combine dispositions from independent filter tiers: hide beats dim beats highlight beats null. */
    public static function strongest(?string ...$dispositions): ?string
    {
        $result = null;
        foreach ($dispositions as $d) {
            if ($d === null || !isset(self::STRENGTH[$d])) {
                continue;
            }
            if ($result === null || self::STRENGTH[$d] > self::STRENGTH[$result]) {
                $result = $d;
            }
        }
        return $result;
    }

    // ---- Helpers ------------------------------------------------------

    /**
     * Keyword/regex: {pattern, fields}. Prompt: {prompt, negativePrompt?, threshold?}.
     */
    public static function validateConfig(string $type, array $config): array
    {
        if ($type === 'prompt') {
            return self::validatePromptConfig($config);
        }
        $pattern = trim((string) ($config['pattern'] ?? ''));
        if ($pattern === '') {
            throw HttpError::badRequest('config.pattern is required');
        }
        if (mb_strlen($pattern) > self::MAX_PATTERN_CHARS) {
            throw HttpError::badRequest('config.pattern is too long (max ' . self::MAX_PATTERN_CHARS . ' chars)');
        }
        $fields = self::DEFAULT_FIELDS;
        if (array_key_exists('fields', $config)) {
            if (!is_array($config['fields'])) {
                throw HttpError::badRequest('config.fields must be an array');
            }
            $fields = array_values(array_intersect(self::FIELDS, array_map('strval', $config['fields'])));
            if ($fields === []) {
                throw HttpError::badRequest('config.fields must include at least one of title, description, location, tags');
            }
        }
        if ($type === 'regex' && @preg_match(self::delimit($pattern), '') === false) {
            throw HttpError::badRequest('Invalid regular expression', 'filter_invalid_regex');
        }
        $out = ['pattern' => $pattern, 'fields' => $fields];
        $color = self::highlightColor($config);
        if ($color !== null) {
            $out['color'] = $color;
        }
        return $out;
    }

    /**
     * config.color for a highlight filter: null when absent (the client falls
     * back to the accent), otherwise one of HIGHLIGHT_COLORS. Stored for any
     * action so switching a filter to highlight and back does not lose the
     * choice, but only ever read when the action is highlight.
     */
    private static function highlightColor(array $config): ?string
    {
        if (!isset($config['color']) || $config['color'] === '' || $config['color'] === null) {
            return null;
        }
        $color = strtolower(trim((string) $config['color']));
        if (!in_array($color, self::HIGHLIGHT_COLORS, true)) {
            throw HttpError::badRequest('config.color must be one of the highlight palette colours');
        }
        return $color;
    }

    /** @return array{prompt:string,negativePrompt?:string,threshold?:float} */
    private static function validatePromptConfig(array $config): array
    {
        $prompt = trim((string) ($config['prompt'] ?? ''));
        if ($prompt === '') {
            throw HttpError::badRequest('config.prompt is required');
        }
        if (mb_strlen($prompt) > self::MAX_PROMPT_CHARS) {
            throw HttpError::badRequest('config.prompt is too long (max ' . self::MAX_PROMPT_CHARS . ' chars)');
        }
        $out = ['prompt' => $prompt];
        $color = self::highlightColor($config);
        if ($color !== null) {
            $out['color'] = $color;
        }
        $negative = trim((string) ($config['negativePrompt'] ?? ''));
        if ($negative !== '') {
            if (mb_strlen($negative) > self::MAX_PROMPT_CHARS) {
                throw HttpError::badRequest('config.negativePrompt is too long (max ' . self::MAX_PROMPT_CHARS . ' chars)');
            }
            $out['negativePrompt'] = $negative;
        }
        if (isset($config['threshold']) && $config['threshold'] !== '') {
            if (!is_numeric($config['threshold'])) {
                throw HttpError::badRequest('config.threshold must be a number between 0 and 1');
            }
            $threshold = (float) $config['threshold'];
            if ($threshold < 0.0 || $threshold > 1.0) {
                throw HttpError::badRequest('config.threshold must be a number between 0 and 1');
            }
            $out['threshold'] = $threshold;
        }
        return $out;
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
        $fallback = (string) $row['type'] === 'prompt'
            ? ['prompt' => '']
            : ['pattern' => '', 'fields' => self::DEFAULT_FIELDS];
        return [
            'id' => (int) $row['id'],
            'scope' => (string) $row['scope'],
            'scopeId' => $row['scope_id'] !== null ? (int) $row['scope_id'] : null,
            'type' => (string) $row['type'],
            'config' => is_array($config) ? $config : $fallback,
            'action' => (string) $row['action'],
            'enabled' => (int) $row['enabled'] === 1,
        ];
    }
}
