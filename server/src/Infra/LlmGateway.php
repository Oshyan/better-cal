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
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(private readonly array $cfg)
    {
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

    /** POST to Gemini and decode the forced-JSON reply; null on any failure. */
    private function generate(array $body): ?array
    {
        $url = sprintf(self::ENDPOINT, rawurlencode($this->cfg['gemini']['model']));
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->cfg['gemini']['key'],
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($raw) || $status !== 200) {
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
