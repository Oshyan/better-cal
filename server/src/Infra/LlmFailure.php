<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * Why a model call came back with nothing: the transport's or the gateway's
 * account of it, kept so a failed job can say what went wrong instead of only
 * that it did. Built from the response status, the provider's own error
 * object when the body carries one, or curl's error; never from the request,
 * so it holds no key and no event text.
 */
final class LlmFailure
{
    /** How much of the provider's message a job error or Review notice carries. */
    public const MESSAGE_CHARS = 240;

    public function __construct(
        /** HTTP status, 0 when no response arrived. */
        public readonly int $httpStatus,
        /** The provider's error status ("RESOURCE_EXHAUSTED"), when its body said. */
        public readonly ?string $providerStatus = null,
        /** The provider's error message, when its body said. */
        public readonly ?string $providerMessage = null,
        /** What went wrong on this side: unreachable, too large, unreadable reply. */
        public readonly ?string $localReason = null,
    ) {
    }

    /** A non-200 response, reading the provider's {"error": {...}} body when there is one. */
    public static function fromResponse(int $status, string $body): self
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : null;
        $providerStatus = is_string($error['status'] ?? null) ? $error['status'] : null;
        $message = is_string($error['message'] ?? null) ? $error['message'] : null;
        return new self($status, $providerStatus, $message);
    }

    /**
     * Billing or quota: the provider refused because of the account, not the
     * request. Retrying does not help until the owner acts at the provider.
     */
    public function isAccountRefusal(): bool
    {
        return $this->httpStatus === 402 || $this->httpStatus === 429
            || strtoupper((string) $this->providerStatus) === 'RESOURCE_EXHAUSTED';
    }

    /**
     * One short sentence for a job error or a Review notice, with $secret
     * (the key) removed wherever it might have been echoed back.
     */
    public function describe(string $secret = ''): string
    {
        // The key comes out before clipping, so no part of it survives a cut.
        $hide = static fn(?string $s): string => $secret !== '' ? str_replace($secret, '[key]', (string) $s) : (string) $s;
        if ($this->localReason !== null) {
            return self::clip($hide($this->localReason));
        }
        $message = self::clean($hide($this->providerMessage));
        return self::clean('Gemini answered ' . ($this->httpStatus > 0 ? $this->httpStatus : 'with no status')
            . ($this->providerStatus !== null ? ' ' . self::clip($hide($this->providerStatus)) : '')
            . ($message !== '' ? ': ' . self::clip($message) : '.'));
    }

    private static function clip(string $s): string
    {
        $s = self::clean($s);
        return mb_strlen($s) > self::MESSAGE_CHARS ? rtrim(mb_substr($s, 0, self::MESSAGE_CHARS - 3)) . '...' : $s;
    }

    /** One line: control characters and runs of whitespace become single spaces. */
    private static function clean(string $s): string
    {
        $s = mb_scrub($s, 'UTF-8');
        return trim(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $s) ?? '');
    }
}
