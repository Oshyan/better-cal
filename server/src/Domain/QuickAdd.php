<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\LlmGateway;
use BetterCal\Support\Limits;
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
        private readonly ?ModelAdmission $modelAdmission = null,
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
    public function run(int $userId, string $text, string $tz, bool $commit, ?int $calendarId, ?int $tokenId = null): array
    {
        $text = trim($text);
        if ($text === '') {
            throw HttpError::badRequest('text is required');
        }
        if (mb_strlen($text) > Limits::get('MODEL_QUICKADD_CHARS')) {
            throw new HttpError(
                'quickadd_text_too_long',
                'Quick Add text is too long (limit ' . number_format(Limits::get('MODEL_QUICKADD_CHARS')) . ' characters).',
                413,
            );
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
                        'tzid' => $draft['tzid'] ?? $tz, // the link's own ctz when it has one (audit, 0.9.14)
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

        // Explicit markers (#86): /calendar, #tag, @person, "literal title".
        // A /word that names no calendar of yours stays in the text.
        $markers = QuickAddMarkers::extract($text);
        $markedCalendarId = null;
        if ($markers['calendar'] !== null) {
            $markedCalendarId = QuickAddMarkers::matchCalendar($markers['calendar'], $this->writableCalendars($userId));
            if ($markedCalendarId === null) {
                $markers = QuickAddMarkers::extract($text, false);
            }
        }
        $literals = $markers['literals'];
        $restore = static function (array $d) use ($literals): array {
            $d['title'] = QuickAddMarkers::restore((string) ($d['title'] ?? ''), $literals);
            $d['location'] = QuickAddMarkers::restore($d['location'] ?? null, $literals);
            return $d;
        };

        $fallback = $restore(FallbackParser::parse($markers['text'], $tz, $now));
        $mode = (string) ($this->settings->forUser($userId)['nlParseMode'] ?? 'smart');

        $draft = null;
        $modelLimit = null;
        if (self::useLlm($mode, $fallback) && $this->llm->isConfigured()) {
            $admitter = $this->modelAdmission ?? new ModelAdmission($this->db);
            $admission = $admitter->reserveQuickAdd($userId, $tokenId, $now);
            if ($admission['allowed']) {
                try {
                    $parsed = $this->llm->parseEvent($markers['llmText'], $now, $tz, repeat: true);
                    if ($parsed !== null) {
                        $draft = self::mergeLlm($restore($parsed), $fallback, $now->setTimezone(Time::zone($tz)))
                            + ['confidence' => 0.9, 'source' => 'llm'];
                        // A quoted title is the owner's own words: the model
                        // may not drop or reword it.
                        if (!QuickAddMarkers::keepsLiterals((string) $draft['title'], $literals)) {
                            $draft['title'] = $fallback['title'];
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('quickadd llm failure: ' . $e->getMessage());
                } finally {
                    $admitter->finish($admission['reservationId']);
                }
            } else {
                $modelLimit = [
                    'code' => $admission['code'],
                    'message' => $admission['message'],
                    'retryAt' => $admission['retryAt'],
                ];
            }
        }
        $draft ??= $fallback;
        // Whether the text itself named a date or a time, from the
        // deterministic parser even when the LLM wrote the draft: the LLM
        // fills in a start either way. An editor opened on a chosen day or
        // range keeps it unless the text says otherwise.
        $draft['when'] = ['date' => (bool) ($fallback['dateFound'] ?? false), 'time' => (bool) ($fallback['timeFound'] ?? false)];
        unset($draft['complete'], $draft['dateFound'], $draft['timeFound']); // parser-internal, not part of the draft contract
        if ($modelLimit !== null) {
            $draft['modelLimit'] = $modelLimit;
        }

        // The text's own calendar wins over the one the caller suggests.
        $draft['calendarId'] = $markedCalendarId ?? $this->resolveCalendarId($userId, $calendarId);
        $draft['calendarFromText'] = $markedCalendarId !== null;
        $draft['tags'] = $markers['tags'];
        if ($markers['people'] !== []) {
            $draft['personNames'] = self::markedPeople($markers['people'], $draft['personNames'] ?? [], $this->directory($userId));
        }

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
                'rrule' => $draft['rrule'] ?? null,
            ] + ($draft['tags'] !== [] ? ['tagNames' => $draft['tags']] : []) + (!empty($draft['reminders']) ? [
                // "alert 30 minutes" (#33): this event's own reminder.
                'reminders' => array_map(static fn($m): array => ['minutes' => (int) $m], $draft['reminders']),
            ] : [])));
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
     * - Companion clause stripped from the title ("Cocktails with Alex"
     *   -> "Cocktails"): the product rule is title = text minus
     *   date/time/location phrases only, so restore the fallback title.
     * - Location or people the fallback found but the LLM dropped: union
     *   people (LLM first), backfill location.
     * - Repeat: the fallback's rule wins when it found one (#165); either
     *   way the start moves to the rule's first day, keeping the model's
     *   time and length, so the series never opens with an extra day.
     *
     * @param array{title:string,start:string,end:string,allDay:bool,location:?string,personNames:list<string>} $llm
     */
    /**
     * True when the model's title is the deterministic title with words
     * missing: every one of its words appears, in order, in the longer one.
     */
    public static function overStripped(string $llmTitle, string $fbTitle): bool
    {
        $words = static fn(string $t): array => preg_split('/[^\p{L}\p{N}\']+/u', mb_strtolower($t), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $a = $words($llmTitle);
        $b = $words($fbTitle);
        if ($a === [] || count($a) >= count($b)) {
            return false;
        }
        $i = 0;
        foreach ($b as $w) {
            if ($i < count($a) && $a[$i] === $w) {
                $i++;
            }
        }
        return $i === count($a);
    }

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
        } elseif (self::overStripped($llmTitle, $fbTitle)) {
            // The model cut words the text kept ("Lunch" from "Lunch at The
            // Captain's Table"): the deterministic title, which only drops dates
            // and times, says what the event is called.
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

        $merged['rrule'] = ($fallback['rrule'] ?? null) ?: (($llm['rrule'] ?? null) ?: null);
        $merged['reminders'] = $fallback['reminders'] ?? null; // read from the text, never the model's
        if ($merged['rrule'] !== null) {
            try {
                // On the owner's clock, so a move across a DST change keeps the hour.
                $start = (new \DateTimeImmutable((string) $merged['start']))->setTimezone($now->getTimezone());
                $end = new \DateTimeImmutable((string) $merged['end']);
                $first = RepeatParser::firstOn(RepeatParser::ruleParts($merged['rrule']), $start);
                if ($first != $start) {
                    $seconds = max(0, $end->getTimestamp() - $start->getTimestamp());
                    $merged['start'] = Time::iso($first);
                    $merged['end'] = Time::iso($first->modify('+' . $seconds . ' seconds'));
                }
            } catch (\Exception) {
                // Times already guarded above; a rule that can't move them stays as is.
            }
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

    /**
     * People for a draft with `@name` markers: each marked name as the
     * directory knows it ("@Sam" -> "Sam Sample" when he is the only Sam),
     * then the names the parse found, minus the ones a marker already
     * covers (the with-clause reads the same "Sam").
     *
     * @param list<string> $typed
     * @param list<string> $parsed
     * @param list<array{id:int,name:string}> $directory
     * @return list<string>
     */
    public static function markedPeople(array $typed, array $parsed, array $directory): array
    {
        $out = [];
        $covered = [];
        foreach ($typed as $name) {
            $canonical = QuickAddMarkers::matchPerson($name, $directory);
            if (!in_array(mb_strtolower($canonical), $covered, true)) {
                $out[] = $canonical;
            }
            $covered[] = mb_strtolower($canonical);
            $covered[] = mb_strtolower($name);
        }
        foreach ($parsed as $name) {
            if (!in_array(mb_strtolower((string) $name), $covered, true)) {
                $out[] = (string) $name;
                $covered[] = mb_strtolower((string) $name);
            }
        }
        return $out;
    }

    /** @return list<array{id:int,name:string}> calendars events can be added to, in display order */
    private function writableCalendars(int $userId): array
    {
        $rows = $this->db->all(
            'SELECT id, name, kind, provider, google_calendar_id, google_account_id, google_access_role
               FROM calendars WHERE user_id = ? ORDER BY position, id',
            [$userId]
        );
        $out = [];
        foreach ($rows as $c) {
            if ($c['kind'] === 'local' || GoogleWriter::writable($c)) {
                $out[] = ['id' => (int) $c['id'], 'name' => (string) $c['name']];
            }
        }
        return $out;
    }

    /** @return list<array{id:int,name:string}> */
    private function directory(int $userId): array
    {
        return array_map(
            static fn(array $p): array => ['id' => (int) $p['id'], 'name' => (string) $p['name']],
            $this->db->all('SELECT id, name FROM people WHERE user_id = ?', [$userId])
        );
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
