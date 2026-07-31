<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\LlmGateway;
use BetterCal\Support\Time;

/**
 * Natural-language quick-add: LLM parse with deterministic fallback.
 * LLM failure never surfaces to the user.
 */
final class QuickAdd
{
    public function __construct(
        private readonly Db $db,
        private readonly LlmGateway $llm,
        private readonly Events $events,
    ) {
    }

    /** @return array{draft:array, event:?array} */
    public function run(int $userId, string $text, string $tz, bool $commit, ?int $calendarId): array
    {
        $text = trim($text);
        if ($text === '') {
            throw HttpError::badRequest('text is required');
        }
        $tz = Time::normalizeTzid($tz);
        $now = Time::nowUtc();

        $draft = null;
        try {
            $parsed = $this->llm->parseEvent($text, $now, $tz);
            if ($parsed !== null) {
                $draft = $parsed + ['confidence' => 0.9, 'source' => 'llm'];
            }
        } catch (\Throwable $e) {
            error_log('quickadd llm failure: ' . $e->getMessage());
        }
        $draft ??= FallbackParser::parse($text, $tz, $now);

        $draft['calendarId'] = $this->resolveCalendarId($userId, $calendarId);

        $event = null;
        if ($commit) {
            $event = $this->events->create($userId, [
                'calendarId' => $draft['calendarId'],
                'title' => $draft['title'],
                'start' => $draft['start'],
                'end' => $draft['end'],
                'allDay' => $draft['allDay'],
                'tzid' => $tz,
                'location' => $draft['location'],
                'personNames' => $draft['personNames'],
            ]);
        }

        return ['draft' => $draft, 'event' => $event];
    }

    private function resolveCalendarId(int $userId, ?int $calendarId): ?int
    {
        if ($calendarId !== null) {
            $owned = $this->db->scalar(
                "SELECT id FROM calendars WHERE id = ? AND user_id = ? AND kind = 'local'",
                [$calendarId, $userId]
            );
            if ($owned !== null) {
                return (int) $owned;
            }
        }
        $default = $this->db->scalar(
            "SELECT id FROM calendars WHERE user_id = ? AND kind = 'local' ORDER BY position, id LIMIT 1",
            [$userId]
        );
        return $default !== null ? (int) $default : null;
    }
}
