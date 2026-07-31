<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * Minimal HTTP transport used by LlmGateway so tests can substitute a fake.
 * Implementations return the response body on HTTP 200 and null on any
 * failure (network error, non-200 status, timeout); they never throw.
 */
interface LlmTransport
{
    /** @param list<string> $headers "Name: value" strings */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): ?string;
}
