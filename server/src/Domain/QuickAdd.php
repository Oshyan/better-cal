<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\LlmGateway;
use BetterCal\Support\Time;

/**
 * Natural-language quick-add, deterministic-first: FallbackParser always runs;
 * the LLM is only consulted when the deterministic parse is incomplete or
 * low-confidence and the user's nlParseMode setting allows it (see useLlm).
 * LLM failure never surfaces to the user.
 */
final class QuickAdd
{
    /** Minimum fallback confidence for a complete parse to skip the LLM. */
    public const FALLBACK_CONFIDENCE = 0.75;

    public function __construct(
        private readonly Db $db,
        private readonly LlmGateway $llm,
        private readonly Events $events,
        private readonly Settings $settings,
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

        $fallback = FallbackParser::parse($text, $tz, $now);
        $mode = (string) ($this->settings->forUser($userId)['nlParseMode'] ?? 'smart');

        $draft = null;
        if (self::useLlm($mode, $fallback)) {
            try {
                $parsed = $this->llm->parseEvent($text, $now, $tz);
                if ($parsed !== null) {
                    $draft = $parsed + ['confidence' => 0.9, 'source' => 'llm'];
                }
            } catch (\Throwable $e) {
                error_log('quickadd llm failure: ' . $e->getMessage());
            }
        }
        $draft ??= $fallback;
        unset($draft['complete']); // parser-internal, not part of the draft contract

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

    /**
     * Pure decision: should the LLM parse run? Mode `always` keeps the old
     * LLM-first behavior, `never` is deterministic-only, `smart` (default)
     * skips the LLM when the fallback parse is complete (date resolved AND
     * time-or-allDay) with confidence >= FALLBACK_CONFIDENCE.
     */
    public static function useLlm(string $mode, array $fallback): bool
    {
        if ($mode === 'never') {
            return false;
        }
        if ($mode === 'always') {
            return true;
        }
        $complete = (bool) ($fallback['complete'] ?? false);
        return !($complete && (float) ($fallback['confidence'] ?? 0.0) >= self::FALLBACK_CONFIDENCE);
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
