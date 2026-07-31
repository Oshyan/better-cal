<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;

final class Search
{
    private const EXCERPT_CHARS = 200;
    /** Below this length FULLTEXT (min token length 3, stopwords) is unreliable. */
    private const MIN_FULLTEXT_LEN = 3;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * FULLTEXT natural-language search with LIKE fallback for short or
     * stopword-only queries. Ranked by relevance, then newest first.
     * $excerpt trims descriptions for list payloads; feed export passes false.
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

        $rows = [];
        if (mb_strlen($q) >= self::MIN_FULLTEXT_LEN) {
            $rows = $this->db->all(
                "SELECT *, MATCH(title, description, location) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance
                 FROM events
                 WHERE user_id = ? AND deleted_at IS NULL
                   AND MATCH(title, description, location) AGAINST (? IN NATURAL LANGUAGE MODE)
                 ORDER BY relevance DESC, start_utc DESC
                 LIMIT $limit",
                [$q, $userId, $q]
            );
        }
        if ($rows === []) {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $rows = $this->db->all(
                "SELECT * FROM events
                 WHERE user_id = ? AND deleted_at IS NULL
                   AND (title LIKE ? OR description LIKE ? OR location LIKE ?)
                 ORDER BY start_utc DESC
                 LIMIT $limit",
                [$userId, $like, $like, $like]
            );
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
