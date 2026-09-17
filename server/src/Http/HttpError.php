<?php

declare(strict_types=1);

namespace BetterCal\Http;

class HttpError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message, string $code = 'invalid_request'): self
    {
        return new self($code, $message, 400);
    }

    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self('unauthorized', $message, 401);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self($code, $message, 403);
    }

    public static function notFound(string $message = 'Not found', string $code = 'not_found'): self
    {
        return new self($code, $message, 404);
    }

    /** The request was fine; the thing it acts on is no longer in a state that allows it. */
    public static function conflict(string $code, string $message): self
    {
        return new self($code, $message, 409);
    }
}
