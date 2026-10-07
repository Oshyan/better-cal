<?php

declare(strict_types=1);

namespace BetterCal\Http;

use BetterCal\Domain\Auth;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;

final class Request
{
    public ?array $user = null;
    public ?string $csrf = null;
    /** 'session' or 'token' once authenticated; null on exempt routes. */
    public ?string $authMethod = null;
    /** Last password verification for this browser session, as a UTC DB timestamp. */
    public ?string $authenticatedAt = null;
    /** The API token this request authenticated with (authMethod 'token'), for binding what it creates. */
    public ?int $tokenId = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly array $files = [],
    ) {
    }

    /**
     * Actions whose meaning depends on a particular browser or an explicit
     * person at the screen remain session-only (account recovery, token
     * management, and Google consent). Supported token-created channels carry
     * their token provenance and stop when that token does.
     */
    public function requireSession(string $what): void
    {
        if ($this->authMethod !== 'session') {
            throw HttpError::forbidden('session_required', $what . ': sign in with your password to do this; an API token cannot');
        }
    }

    /** Require a password sign-in or confirmation in the last ten minutes. */
    public function requireRecentAuthentication(string $what): void
    {
        $this->requireSession($what);
        if ($this->authenticatedAt === null) {
            throw HttpError::forbidden('step_up_required', 'Confirm your Better-Cal password to ' . lcfirst($what) . '.');
        }
        try {
            $fresh = Time::fromDb($this->authenticatedAt) >= Time::nowUtc()->sub(new \DateInterval('PT' . Auth::STEP_UP_SECONDS . 'S'));
        } catch (\Throwable) {
            $fresh = false;
        }
        if (!$fresh) {
            throw HttpError::forbidden('step_up_required', 'Confirm your Better-Cal password to ' . lcfirst($what) . '.');
        }
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        // Apache running PHP as CGI/FPM does not pass Authorization through as
        // HTTP_AUTHORIZATION; the usual rewrite that restores it lands under a
        // REDIRECT_ prefix. Without this, API tokens silently stop working on
        // Apache while cookies keep working, which is a confusing way to fail.
        if (!isset($headers['authorization'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] ?? null;
            if (is_string($auth) && $auth !== '') {
                $headers['authorization'] = $auth;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $body = [];
        $contentType = $headers['content-type'] ?? '';
        if (str_contains($contentType, 'json')) {
            $input = fopen('php://input', 'rb');
            if ($input === false) {
                throw HttpError::badRequest('Could not read request body', 'invalid_request');
            }
            try {
                $body = self::jsonBodyFromStream($input, $_SERVER['CONTENT_LENGTH'] ?? null);
            } finally {
                fclose($input);
            }
        } elseif ($_POST !== []) {
            $body = $_POST;
        }

        return new self($method, $path, $_GET, $body, $headers, $_COOKIE, $_FILES);
    }

    /**
     * Read and decode one JSON body without ever buffering more than the
     * configured budget plus the byte needed to prove that it is oversized.
     * Content-Length is only an early rejection hint: clients can omit it or a
     * proxy can pass a streamed request, so the bytes read are authoritative.
     *
     * @param resource $input
     * @return array<mixed>
     */
    public static function jsonBodyFromStream($input, mixed $declaredLength = null): array
    {
        if (!is_resource($input)) {
            throw new \InvalidArgumentException('JSON request input must be a stream');
        }

        $limit = Limits::get('JSON_BODY_BYTES');
        if (self::decimalExceeds($declaredLength, $limit)) {
            throw self::jsonTooLarge($limit);
        }

        $raw = stream_get_contents($input, $limit + 1);
        if ($raw === false) {
            throw HttpError::badRequest('Could not read request body', 'invalid_request');
        }
        if (strlen($raw) > $limit) {
            throw self::jsonTooLarge($limit);
        }
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw HttpError::badRequest('Request body is not valid JSON', 'invalid_json');
        }
        return $decoded;
    }

    private static function decimalExceeds(mixed $value, int $limit): bool
    {
        if (is_int($value)) {
            return $value > $limit;
        }
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        if (preg_match('/^\d+$/D', $value) !== 1) {
            return false;
        }
        $value = ltrim($value, '0');
        if ($value === '') {
            return false;
        }
        $maximum = (string) $limit;
        return strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0);
    }

    private static function jsonTooLarge(int $limit): HttpError
    {
        return HttpError::payloadTooLarge(
            'JSON request body is too large (limit ' . number_format($limit) . ' bytes)'
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function q(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? null;
        return $v === null || $v === '' ? $default : (string) $v;
    }

    public function str(string $key, ?string $default = null): ?string
    {
        $v = $this->body[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        if (!is_scalar($v)) {
            throw HttpError::badRequest("Field '$key' must be a string");
        }
        return (string) $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->body[$key] ?? null;
        return $v === null ? $default : filter_var($v, FILTER_VALIDATE_BOOL);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }
}
