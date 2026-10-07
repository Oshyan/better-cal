<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Support\Limits;

/** Default curl-backed transport for LlmGateway. */
final class CurlLlmTransport implements LlmTransport
{
    private readonly int $maxResponseBytes;

    public function __construct(?int $maxResponseBytes = null)
    {
        $this->maxResponseBytes = $maxResponseBytes ?? Limits::get('MODEL_RESPONSE_BYTES');
    }

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $raw = '';
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            // Authoritative even when the server omits or lies about
            // Content-Length, uses chunked transfer, or sends compressed data.
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$raw, &$tooLarge): int {
                if (!self::appendBounded($raw, $chunk, $this->maxResponseBytes)) {
                    $tooLarge = true;
                    return 0; // abort cURL before the over-limit chunk is retained
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok !== true || $tooLarge || $status !== 200) {
            return null;
        }
        return $raw;
    }

    /** Pure helper used by the streaming callback and exact-boundary tests. */
    public static function appendBounded(string &$buffer, string $chunk, int $maxBytes): bool
    {
        if ($maxBytes < 1 || strlen($chunk) > $maxBytes - strlen($buffer)) {
            return false;
        }
        $buffer .= $chunk;
        return true;
    }
}
