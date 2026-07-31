<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Support\Time;

/**
 * Gemini-backed LLM gateway. Every method returns null on any failure so
 * callers can fall back deterministically; nothing here ever throws upward.
 */
final class LlmGateway
{
    private const TIMEOUT_SECONDS = 6;
    private const BATCH_TIMEOUT_SECONDS = 45;
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        private readonly array $cfg,
        private readonly ?LlmTransport $transport = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return ($this->cfg['gemini']['key'] ?? '') !== '';
    }

    /**
     * Parse natural-language event text.
     *
     * @return ?array{title:string,start:string,end:string,allDay:bool,location:?string,personNames:list<string>}
     */
    public function parseEvent(string $text, \DateTimeImmutable $now, string $tz): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        $localNow = $now->setTimezone(Time::zone($tz));
        $prompt = "Extract a calendar event from the user's text.\n"
            . 'Current datetime: ' . Time::iso($localNow) . ' (' . $localNow->format('l') . ") in timezone $tz.\n"
            . "Rules: resolve relative dates against the current datetime; if no time is given, treat as an all-day event; "
            . "if no end is given, default to one hour after start; start and end must be ISO8601 with UTC offset; "
            . "location is a place name or empty string; personNames are people mentioned as companions (e.g. \"with Sam\").\n"
            . "Text: " . $text;

        $body = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'response_schema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'title' => ['type' => 'STRING'],
                        'start' => ['type' => 'STRING', 'description' => 'ISO8601 with offset'],
                        'end' => ['type' => 'STRING', 'description' => 'ISO8601 with offset'],
                        'allDay' => ['type' => 'BOOLEAN'],
                        'location' => ['type' => 'STRING'],
                        'personNames' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    ],
                    'required' => ['title', 'start', 'end', 'allDay'],
                ],
                'temperature' => 0.1,
            ],
        ];

        $parsed = $this->generate($body);
        if ($parsed === null) {
            return null;
        }
        try {
            $start = Time::parseIso((string) ($parsed['start'] ?? ''), $tz);
            $end = Time::parseIso((string) ($parsed['end'] ?? ''), $tz);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if ($end <= $start) {
            $end = $start->add(new \DateInterval('PT1H'));
        }
        $tzZone = Time::zone($tz);
        return [
            'title' => trim((string) ($parsed['title'] ?? '')) ?: 'New event',
            'start' => Time::iso($start->setTimezone($tzZone)),
            'end' => Time::iso($end->setTimezone($tzZone)),
            'allDay' => (bool) ($parsed['allDay'] ?? false),
            'location' => trim((string) ($parsed['location'] ?? '')) ?: null,
            'personNames' => array_values(array_filter(array_map(
                static fn($n) => is_string($n) ? trim($n) : '',
                is_array($parsed['personNames'] ?? null) ? $parsed['personNames'] : []
            ))),
        ];
    }

    /**
     * Batch-evaluate a prompt filter against feed events: one JSON call, one
     * verdict per event. Results are raw model output; callers validate and
     * clamp via PromptEval::validateEvalResponse. Null on any failure.
     *
     * @param list<array{eventId:int,title:string,description:?string,location:?string,start:string}> $events
     * @return ?list<array{eventId:int,pass:bool,score:float}>
     */
    public function evaluateFilterBatch(string $prompt, ?string $negativePrompt, array $events): ?array
    {
        if (!$this->isConfigured() || $events === []) {
            return null;
        }
        $instructions = "You are filtering calendar events for a user.\n"
            . "The user wants to keep events matching this description: " . $prompt . "\n"
            . ($negativePrompt !== null && $negativePrompt !== ''
                ? "The user does NOT want events matching: " . $negativePrompt . "\n"
                : '')
            . "For each event, decide pass=true if it matches what the user wants to keep "
            . "(and does not match the unwanted description), pass=false otherwise. "
            . "score is your 0-1 confidence that the event matches what the user wants.\n"
            . "Return one result per event, echoing its eventId.\n"
            . "Events (JSON): " . json_encode($events, JSON_UNESCAPED_UNICODE);

        $parsed = $this->generate([
            'contents' => [['parts' => [['text' => $instructions]]]],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'response_schema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'results' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'eventId' => ['type' => 'INTEGER'],
                                    'pass' => ['type' => 'BOOLEAN'],
                                    'score' => ['type' => 'NUMBER', 'description' => '0 to 1'],
                                ],
                                'required' => ['eventId', 'pass', 'score'],
                            ],
                        ],
                    ],
                    'required' => ['results'],
                ],
                'temperature' => 0.0,
            ],
        ], self::BATCH_TIMEOUT_SECONDS);
        return is_array($parsed['results'] ?? null) ? $parsed['results'] : null;
    }

    /**
     * Score future feed events 0-1 against the user's thumbs-up/down history.
     * Results are raw model output; callers validate and clamp via
     * Ranking::validateRankResponse. Null on any failure.
     *
     * @param list<array{title:string,signal:string}> $examples up|down feedback examples
     * @param list<array{eventId:int,title:string,description:?string,location:?string,start:string}> $events
     * @return ?list<array{eventId:int,score:float}>
     */
    public function rankEvents(array $examples, array $events): ?array
    {
        if (!$this->isConfigured() || $events === []) {
            return null;
        }
        $instructions = "You are ranking upcoming calendar events for a user based on their past feedback.\n"
            . "Feedback examples (signal up = the user liked a similar event, down = disliked):\n"
            . json_encode($examples, JSON_UNESCAPED_UNICODE) . "\n"
            . "For each event below, output score between 0 and 1: how likely the user is to want "
            . "to attend it, judging by similarity to the liked and disliked examples.\n"
            . "Return one result per event, echoing its eventId.\n"
            . "Events (JSON): " . json_encode($events, JSON_UNESCAPED_UNICODE);

        $parsed = $this->generate([
            'contents' => [['parts' => [['text' => $instructions]]]],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'response_schema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'results' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'eventId' => ['type' => 'INTEGER'],
                                    'score' => ['type' => 'NUMBER', 'description' => '0 to 1'],
                                ],
                                'required' => ['eventId', 'score'],
                            ],
                        ],
                    ],
                    'required' => ['results'],
                ],
                'temperature' => 0.0,
            ],
        ], self::BATCH_TIMEOUT_SECONDS);
        return is_array($parsed['results'] ?? null) ? $parsed['results'] : null;
    }

    /** POST to Gemini and decode the forced-JSON reply; null on any failure. */
    private function generate(array $body, int $timeoutSeconds = self::TIMEOUT_SECONDS): ?array
    {
        $url = sprintf(self::ENDPOINT, rawurlencode($this->cfg['gemini']['model']));
        $transport = $this->transport ?? new CurlLlmTransport();
        $raw = $transport->post($url, [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->cfg['gemini']['key'],
        ], (string) json_encode($body), $timeoutSeconds);
        if ($raw === null) {
            return null;
        }
        $envelope = json_decode($raw, true);
        $text = $envelope['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text)) {
            return null;
        }
        $parsed = json_decode($text, true);
        return is_array($parsed) ? $parsed : null;
    }
}
