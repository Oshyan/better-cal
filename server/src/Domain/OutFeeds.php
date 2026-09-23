<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Ids;
use BetterCal\Support\Time;

/** Outbound ICS feeds: token URLs scoped to all events, one calendar, or a saved search. */
final class OutFeeds
{
    private const SCOPE_TYPES = ['all', 'calendar', 'search'];
    private const MAX_EVENTS = 5000;
    private const LOOKBACK = 'P1Y';

    public function __construct(
        private readonly Db $db,
        private readonly Search $search,
        private readonly array $cfg,
    ) {
    }

    /** @return list<array> */
    public function listAll(int $userId): array
    {
        return array_map(
            fn(array $row) => $this->serialize($row),
            $this->db->all('SELECT * FROM out_feeds WHERE user_id = ? ORDER BY id', [$userId])
        );
    }

    public function create(int $userId, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw HttpError::badRequest('name is required');
        }
        $scope = $in['scope'] ?? null;
        if (!is_array($scope) || !in_array($scope['type'] ?? null, self::SCOPE_TYPES, true)) {
            throw HttpError::badRequest('scope.type must be all|calendar|search');
        }
        $normalized = ['type' => (string) $scope['type']];
        if ($normalized['type'] === 'calendar') {
            $calendarId = (int) ($scope['calendarId'] ?? 0);
            $owned = $this->db->scalar('SELECT id FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]);
            if ($owned === null) {
                throw HttpError::badRequest('scope.calendarId must reference one of your calendars');
            }
            $normalized['calendarId'] = $calendarId;
        }
        if ($normalized['type'] === 'search') {
            $q = trim((string) ($scope['q'] ?? ''));
            if ($q === '') {
                throw HttpError::badRequest('scope.q is required for search feeds');
            }
            $normalized['q'] = $q;
        }

        $id = $this->db->insert('out_feeds', [
            'user_id' => $userId,
            'token' => Ids::feedToken(),
            'name' => mb_substr($name, 0, 160),
            'scope_json' => json_encode($normalized),
            'description' => isset($in['description']) ? trim((string) $in['description']) : null,
        ]);
        (new Undo($this->db))->record($userId, 'outfeed', (int) $id, 'create', null, null,
            'Created outbound feed "' . mb_substr($name, 0, 160) . '" (' . $normalized['type'] . ')');
        return $this->serialize($this->db->one('SELECT * FROM out_feeds WHERE id = ?', [$id]));
    }

    public function delete(int $userId, int $id): void
    {
        $row = $this->db->one('SELECT * FROM out_feeds WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('Feed not found');
        }
        $this->db->run('DELETE FROM out_feeds WHERE id = ?', [$id]);
    }

    /** Render the public .ics for a feed token; null when the token is unknown. */
    public function renderByToken(string $token): ?string
    {
        $feed = $this->db->one('SELECT * FROM out_feeds WHERE token = ?', [$token]);
        if ($feed === null) {
            return null;
        }
        $userId = (int) $feed['user_id'];
        $scope = json_decode((string) $feed['scope_json'], true) ?: ['type' => 'all'];
        $cutoff = Time::toDb(Time::nowUtc()->sub(new \DateInterval(self::LOOKBACK)));
        $limit = self::MAX_EVENTS;

        $events = match ($scope['type'] ?? 'all') {
            'calendar' => $this->db->all(
                "SELECT * FROM events WHERE user_id = ? AND calendar_id = ? AND deleted_at IS NULL
                 AND (end_utc >= ? OR rrule IS NOT NULL) ORDER BY start_utc LIMIT $limit",
                [$userId, (int) ($scope['calendarId'] ?? 0), $cutoff]
            ),
            'search' => $this->search->search($userId, (string) ($scope['q'] ?? ''), 500, excerpt: false),
            default => $this->db->all(
                "SELECT * FROM events WHERE user_id = ? AND deleted_at IS NULL AND attendance <> 'hidden'
                 AND (end_utc >= ? OR rrule IS NOT NULL) ORDER BY start_utc LIMIT $limit",
                [$userId, $cutoff]
            ),
        };

        // Trip relationships export as RELATED-TO lines (same treatment as
        // CalDAV objects): decorate rows with member/container uids.
        $related = Trips::relatedUidMap($this->db, array_map(static fn(array $e): int => (int) $e['id'], $events));
        foreach ($events as &$ev) {
            $id = (int) $ev['id'];
            if (!empty($related['children'][$id])) {
                $ev['related_children'] = $related['children'][$id];
            }
            if (!empty($related['parents'][$id])) {
                $ev['related_parents'] = $related['parents'][$id];
            }
        }
        unset($ev);

        return Ics::buildCalendar((string) $feed['name'], $this->describeScope($feed, $scope), $events);
    }

    private function describeScope(array $feed, array $scope): string
    {
        $base = trim((string) ($feed['description'] ?? ''));
        $scopeText = match ($scope['type'] ?? 'all') {
            'calendar' => 'This feed contains events from one calendar.',
            'search' => 'This feed contains only events matching: ' . (string) ($scope['q'] ?? ''),
            default => 'This feed contains all events.',
        };
        return $base === '' ? $scopeText : $base . ' — ' . $scopeText;
    }

    private function serialize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'url' => $this->cfg['base_url'] . '/feed/' . $row['token'] . '.ics',
            'scope' => json_decode((string) $row['scope_json'], true),
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
        ];
    }
}
