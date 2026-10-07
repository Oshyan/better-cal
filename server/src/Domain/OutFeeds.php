<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Ids;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;

/** Outbound ICS feeds: token URLs scoped to all events, one calendar, or a saved search. */
final class OutFeeds
{
    private const SCOPE_TYPES = ['all', 'calendar', 'search'];
    private const LOOKBACK = 'P1Y';
    /** A saved-search feed holds the search's own top results. */
    public const SEARCH_LIMIT = 500;

    public function __construct(
        private readonly Db $db,
        private readonly Search $search,
        private readonly array $cfg,
    ) {
    }

    /** @return list<array> */
    /**
     * @param ?int $viewerTokenId the API token asking, or null for the signed-in owner. A
     *   feed URL is a capability that outlives the token that read it, so a token
     *   sees the address only of feeds it created itself (the others: url null).
     */
    public function listAll(int $userId, ?int $viewerTokenId = null): array
    {
        return array_map(
            fn(array $row) => $this->serialize($row, $viewerTokenId === null || (int) ($row['created_by_token_id'] ?? 0) === $viewerTokenId) + $this->size($row),
            $this->db->all('SELECT * FROM out_feeds WHERE user_id = ? ORDER BY id', [$userId])
        );
    }

    public function create(int $userId, array $in, ?int $tokenId = null, ?string $sessionToken = null): array
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

        $id = $this->db->tx(function () use ($userId, $name, $normalized, $in, $tokenId, $sessionToken): int {
            if ($tokenId === null) {
                // Close the resolve-then-revoke race: if lost-device recovery
                // wins, this request cannot recreate a feed after rotation.
                Auth::assertSession($this->db, $userId, $sessionToken, true);
            } else {
                ApiTokens::assertStillValid($this->db, $tokenId, $userId, true);
            }
            return $this->db->insert('out_feeds', [
                'user_id' => $userId,
                'token' => Ids::feedToken(),
                'name' => mb_substr($name, 0, 160),
                'scope_json' => json_encode($normalized),
                'description' => isset($in['description']) ? trim((string) $in['description']) : null,
                'created_by_token_id' => $tokenId,
            ]);
        });
        (new Undo($this->db))->record($userId, 'outfeed', (int) $id, 'create', null, null,
            'Created outbound feed "' . mb_substr($name, 0, 160) . '" (' . $normalized['type'] . ')' . ($tokenId !== null ? ', with an API token' : ''));
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
        // A feed a token created lives as long as the token (revoking deletes
        // it through the foreign key; an expired token's feed stops answering).
        if (!empty($feed['created_by_token_id']) && !ApiTokens::stillValid($this->db, (int) $feed['created_by_token_id'])) {
            return null;
        }
        $userId = (int) $feed['user_id'];
        $scope = json_decode((string) $feed['scope_json'], true) ?: ['type' => 'all'];
        $events = ($scope['type'] ?? 'all') === 'search'
            ? $this->search->search($userId, (string) ($scope['q'] ?? ''), self::SEARCH_LIMIT, excerpt: false)
            : $this->nearest($userId, $scope, Limits::get('OUTFEED_EVENTS'));

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

    /**
     * What an "all" or "calendar" feed covers: events that ended within the
     * last year or later, and every repeating series.
     *
     * @return array{0:string,1:list<int|string>}
     */
    private function coverage(int $userId, array $scope): array
    {
        $cutoff = Time::toDb(Time::nowUtc()->sub(new \DateInterval(self::LOOKBACK)));
        if (($scope['type'] ?? 'all') === 'calendar') {
            return ['user_id = ? AND calendar_id = ? AND deleted_at IS NULL AND (end_utc >= ? OR rrule IS NOT NULL)',
                [$userId, (int) ($scope['calendarId'] ?? 0), $cutoff]];
        }
        return ["user_id = ? AND deleted_at IS NULL AND attendance <> 'hidden' AND (end_utc >= ? OR rrule IS NOT NULL)",
            [$userId, $cutoff]];
    }

    /**
     * Up to $max events, nearest to today: everything ongoing or ahead (and
     * every series) first, then the most recent past. Over the cap, the
     * oldest past events are left off, never the upcoming ones (#110).
     */
    private function nearest(int $userId, array $scope, int $max): array
    {
        [$where, $params] = $this->coverage($userId, $scope);
        $now = Time::nowDb();
        $ahead = $this->db->all(
            "SELECT * FROM events WHERE $where AND (end_utc >= ? OR rrule IS NOT NULL) ORDER BY start_utc LIMIT $max",
            [...$params, $now]
        );
        $room = $max - count($ahead);
        $past = $room > 0 ? $this->db->all(
            "SELECT * FROM events WHERE $where AND end_utc < ? AND rrule IS NULL ORDER BY start_utc DESC LIMIT $room",
            [...$params, $now]
        ) : [];
        $events = [...$past, ...$ahead];
        usort($events, static fn(array $a, array $b): int => strcmp((string) $a['start_utc'], (string) $b['start_utc']));
        return $events;
    }

    /** How many events a feed covers and how many it holds, for its card in Settings. */
    private function size(array $row): array
    {
        $scope = json_decode((string) $row['scope_json'], true) ?: ['type' => 'all'];
        if (($scope['type'] ?? 'all') === 'search') {
            return ['eventCount' => null, 'eventLimit' => self::SEARCH_LIMIT];
        }
        [$where, $params] = $this->coverage((int) $row['user_id'], $scope);
        return [
            'eventCount' => (int) $this->db->scalar("SELECT COUNT(*) FROM events WHERE $where", $params),
            'eventLimit' => Limits::get('OUTFEED_EVENTS'),
        ];
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

    private function serialize(array $row, bool $showUrl = true): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'url' => $showUrl ? $this->cfg['base_url'] . '/feed/' . $row['token'] . '.ics' : null,
            'viaToken' => !empty($row['created_by_token_id']),
            'scope' => json_decode((string) $row['scope_json'], true),
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
        ];
    }
}
