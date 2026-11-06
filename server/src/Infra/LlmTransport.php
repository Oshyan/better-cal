<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * Minimal HTTP transport used by LlmGateway so tests can substitute a fake.
 * Implementations return the response body on HTTP 200 and, on any failure
 * (network error, non-200 status, timeout), an LlmFailure saying which, or
 * null when they cannot tell; they never throw. Before failures carried a
 * reason, a refused call (an account out of credit) and a dropped connection
 * read the same in every job error.
 */
interface LlmTransport
{
    /** @param list<string> $headers "Name: value" strings */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): string|LlmFailure|null;
}
