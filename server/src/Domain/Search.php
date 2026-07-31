<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;

final class Search
{
    private const EXCERPT_CHARS = 200;
    /** Below this length FULLTEXT (min token length 3, stopwords) is unreliable. */
    private const MIN_FULLTEXT_LEN = 3;

    public function __construct(
        private readonly Db $db,
        private readonly Labels $labels,
    ) {
    }

    /**
     * FULLTEXT natural-language search with LIKE fallback for short or
     * stopword-only queries. Ranked by relevance, then newest first.
     * Tag names match too: events tagged with a name containing the query
     * are included, and a query equal to or prefixed with `#` (e.g. "#work")
     * searches tags only. $excerpt trims descriptions for list payloads;
     * feed export passes false.
     *
     * @return list<array> event rows
     */
    public function search(int $userId, string $q, int $limit = 50, bool $excerpt = true): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $limit = max(1, min(200, $limit));

        $tagOnly = str_starts_with($q, '#');
        $term = $tagOnly ? trim(mb_substr($q, 1)) : $q;
        if ($term === '') {
            return [];
        }
        $tagEventIds = $this->labels->eventIdsForTagQuery($userId, $term);

        $rows = [];
        if ($tagOnly) {
            if ($tagEventIds !== []) {
                [$in, $inParams] = Db::in($tagEventIds);
                $rows = $this->db->all(
                    "SELECT * FROM events
                     WHERE user_id = ? AND deleted_at IS NULL AND id IN $in
                     ORDER BY start_utc DESC
                     LIMIT $limit",
                    [$userId, ...$inParams]
                );
            }
        } else {
            [$tagIn, $tagParams] = Db::in($tagEventIds !== [] ? $tagEventIds : [0]);
            if (mb_strlen($term) >= self::MIN_FULLTEXT_LEN) {
                $rows = $this->db->all(
                    "SELECT *, MATCH(title, description, location) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance
                     FROM events
                     WHERE user_id = ? AND deleted_at IS NULL
                       AND (MATCH(title, description, location) AGAINST (? IN NATURAL LANGUAGE MODE) OR id IN $tagIn)
                     ORDER BY relevance DESC, start_utc DESC
                     LIMIT $limit",
                    [$term, $userId, $term, ...$tagParams]
                );
            }
            if ($rows === []) {
                $like = '%' . addcslashes($term, '%_\\') . '%';
                $rows = $this->db->all(
                    "SELECT * FROM events
                     WHERE user_id = ? AND deleted_at IS NULL
                       AND (title LIKE ? OR description LIKE ? OR location LIKE ? OR id IN $tagIn)
                     ORDER BY start_utc DESC
                     LIMIT $limit",
                    [$userId, $like, $like, $like, ...$tagParams]
                );
            }
        }
        foreach ($rows as &$row) {
            unset($row['relevance']);
            if ($excerpt && $row['description'] !== null && mb_strlen((string) $row['description']) > self::EXCERPT_CHARS) {
                $row['description'] = mb_substr((string) $row['description'], 0, self::EXCERPT_CHARS) . '…';
            }
        }
        return $rows;
    }
}
