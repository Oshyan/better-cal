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
        private readonly ?People $people = null,
    ) {
    }

    /**
     * Detect an availability statement: "NAME is away Aug 10-15", "John will
     * be out next week", "Sam gone Tuesday to Friday". Pure; returns the name
     * half, the normalized kind (busy stays busy, every other word means
     * away), and the remainder for date parsing — or null when the text
     * doesn't read as one.
     *
     * @return array{name:string,kind:string,rest:string}|null
     */
    public static function awayIntent(string $text): ?array
    {
        if (preg_match(
            '/^\s*(.{1,80}?)\s+(?:is|will\s+be|)\s*\b(away|busy|out|gone|traveling|travelling|on\s+vacation|ooo|here|visiting|in\s+town|back)\b\s*(.*)$/i',
            trim($text),
            $m
        ) !== 1) {
            return null;
        }
        $name = trim($m[1], " \t,.-");
        if ($name === '' || preg_match('/^[\p{L}][\p{L}\'\-. ]*$/u', $name) !== 1) {
            return null; // names only; "Checkout gone wrong 3pm" stays an event
        }
        $word = strtolower(preg_replace('/\s+/', ' ', $m[2]));
        $kind = $word === 'busy' ? 'busy'
            : (in_array($word, ['here', 'visiting', 'in town', 'back'], true) ? 'here' : 'away');
        return [
            'name' => $name,
            'kind' => $kind,
            'rest' => trim($m[3]),
        ];
    }

    /**
     * Activity source for quick-add commits: claim 'quickadd' only for plain
     * web sessions — bearer-token (agent) and other entry points keep their
     * own tag so the log's manual/automated split stays truthful.
     */
    private static function activitySource(): string
    {
        return ActivityContext::get() === 'web' ? 'quickadd' : ActivityContext::get();
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

        // A pasted Google Calendar template link IS the event: parse its
        // query parameters directly (one parser also serves the /add deep
        // link and the browser-extension redirect).
        if (GcalLink::isTemplateUrl($text)) {
            $draft = GcalLink::parse($text, $tz);
            if ($draft !== null) {
                $draft['calendarId'] = $this->resolveCalendarId($userId, $calendarId);
                $event = null;
                if ($commit) {
                    $event = ActivityContext::with(self::activitySource(), fn() => $this->events->create($userId, [
                        'calendarId' => $draft['calendarId'],
                        'title' => $draft['title'],
                        'start' => $draft['start'],
                        'end' => $draft['end'],
                        'allDay' => $draft['allDay'],
                        'tzid' => $tz,
                        'location' => $draft['location'],
                        'description' => $draft['description'],
                        'rrule' => $draft['rrule'],
                        'personNames' => [],
                    ]));
                }
                return ['draft' => $draft, 'event' => $event];
            }
        }

        // Availability statements divert to a span draft — but only when the
        // leading words exactly match an existing person, so event titles
        // that merely contain "out"/"gone" still parse as events.
        $intent = self::awayIntent($text);
        if ($intent !== null && $this->people !== null) {
            $person = $this->db->one(
                'SELECT id, name FROM people WHERE user_id = ? AND LOWER(name) = ?',
                [$userId, mb_strtolower($intent['name'])]
            );
            if ($person !== null) {
                // Reuse the event parser purely for its date-range smarts.
                $range = FallbackParser::parse($intent['rest'] !== '' ? $intent['rest'] : 'today', $tz, $now);
                $draft = [
                    'intent' => 'availability',
                    'personId' => (int) $person['id'],
                    'personName' => (string) $person['name'],
                    'kind' => $intent['kind'],
                    'start' => $range['start'],
                    'end' => $range['end'],
                    'allDay' => $range['allDay'],
                    'confidence' => $range['confidence'],
                    'source' => 'fallback',
                ];
                $span = null;
                if ($commit) {
                    $span = ActivityContext::with(self::activitySource(), fn() => $this->people->addSpan($userId, (int) $person['id'], [
                        'start' => $range['start'],
                        'end' => $range['end'],
                        'kind' => $intent['kind'],
                    ]));
                }
                return ['draft' => $draft, 'event' => null, 'availability' => $span];
            }
        }

        $fallback = FallbackParser::parse($text, $tz, $now);
        $mode = (string) ($this->settings->forUser($userId)['nlParseMode'] ?? 'smart');

        $draft = null;
        if (self::useLlm($mode, $fallback)) {
            try {
                $parsed = $this->llm->parseEvent($text, $now, $tz);
                if ($parsed !== null) {
                    $draft = self::mergeLlm($parsed, $fallback, $now->setTimezone(Time::zone($tz)))
                        + ['confidence' => 0.9, 'source' => 'llm'];
                }
            } catch (\Throwable $e) {
                error_log('quickadd llm failure: ' . $e->getMessage());
            }
        }
        $draft ??= $fallback;
        unset($draft['complete'], $draft['dateFound']); // parser-internal, not part of the draft contract

        $draft['calendarId'] = $this->resolveCalendarId($userId, $calendarId);

        $event = null;
        if ($commit) {
            $event = ActivityContext::with(self::activitySource(), fn() => $this->events->create($userId, [
                'calendarId' => $draft['calendarId'],
                'title' => $draft['title'],
                'start' => $draft['start'],
                'end' => $draft['end'],
                'allDay' => $draft['allDay'],
                'tzid' => $tz,
                'location' => $draft['location'],
                'personNames' => $draft['personNames'],
            ]));
        }

        return ['draft' => $draft, 'event' => $event];
    }

    /**
     * Guard the LLM draft with deterministic signals. The LLM handles fuzzy
     * language the regex parser can't, but makes unforced errors the parser
     * never makes; each guard swaps in the fallback's answer only where the
     * LLM's is demonstrably worse:
     * - Past start with no explicit date in the text ("cocktails at 4pm"
     *   scheduled for two days ago): take the fallback's times, which always
     *   roll forward when no date was written.
     * - Companion clause stripped from the title ("Cocktails with Virginia"
     *   -> "Cocktails"): the product rule is title = text minus
     *   date/time/location phrases only, so restore the fallback title.
     * - Location or people the fallback found but the LLM dropped: union
     *   people (LLM first), backfill location.
     *
     * @param array{title:string,start:string,end:string,allDay:bool,location:?string,personNames:list<string>} $llm
     */
    public static function mergeLlm(array $llm, array $fallback, \DateTimeImmutable $now): array
    {
        $merged = $llm;

        try {
            $llmStart = new \DateTimeImmutable((string) $llm['start']);
            $explicitDate = (bool) ($fallback['dateFound'] ?? false);
            if (!$explicitDate && $llmStart < $now->sub(new \DateInterval('PT2M'))) {
                $merged['start'] = $fallback['start'];
                $merged['end'] = $fallback['end'];
                $merged['allDay'] = $fallback['allDay'];
            }
        } catch (\Exception) {
            // Unparseable LLM start: trust the fallback times entirely.
            $merged['start'] = $fallback['start'];
            $merged['end'] = $fallback['end'];
            $merged['allDay'] = $fallback['allDay'];
        }

        $fbTitle = (string) ($fallback['title'] ?? '');
        $llmTitle = (string) ($merged['title'] ?? '');
        if (preg_match('/\b(?:with|w\/)\b/i', $fbTitle) === 1
            && preg_match('/\b(?:with|w\/)\b/i', $llmTitle) !== 1
        ) {
            $merged['title'] = $fbTitle;
        }

        $people = $merged['personNames'] ?? [];
        $seen = array_map('mb_strtolower', $people);
        foreach (($fallback['personNames'] ?? []) as $name) {
            if (!in_array(mb_strtolower($name), $seen, true)) {
                $people[] = $name;
                $seen[] = mb_strtolower($name);
            }
        }
        $merged['personNames'] = $people;

        if (($merged['location'] ?? null) === null && ($fallback['location'] ?? null) !== null) {
            $merged['location'] = $fallback['location'];
        }

        return $merged;
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
        // The user's configured default calendar wins; fall back to the first
        // local calendar by position.
        $raw = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
        $settings = is_string($raw) ? json_decode($raw, true) : null;
        $preferred = is_array($settings) ? ($settings['defaultCalendarId'] ?? null) : null;
        if (is_int($preferred) || (is_string($preferred) && ctype_digit($preferred))) {
            $owned = $this->db->scalar(
                "SELECT id FROM calendars WHERE id = ? AND user_id = ? AND kind = 'local'",
                [(int) $preferred, $userId]
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
