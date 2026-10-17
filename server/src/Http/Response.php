<?php

declare(strict_types=1);

namespace BetterCal\Http;

final class Response
{
    public readonly array $headers;

    /** @param array<string,string> $headers */
    private function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body = '',
        public readonly ?string $filePath = null,
        public readonly array $cookies = [],
        private readonly ?\Closure $stream = null,
    ) {
        $this->headers = $headers + [
            'Content-Security-Policy' => "frame-ancestors 'none'",
            'X-Frame-Options' => 'DENY',
        ];
    }

    public static function json(mixed $data, int $status = 200, array $cookies = []): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            null,
            $cookies
        );
    }

    public static function error(string $code, string $message, int $status): self
    {
        return self::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    public static function text(string $body, string $contentType = 'text/plain; charset=utf-8', int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => $contentType] + $headers, $body);
    }

    /** 304: the validators and cache policy again, no body and no Content-Type. */
    public static function notModified(array $headers = []): self
    {
        return new self(304, $headers);
    }

    public static function file(string $path, string $mime, array $headers = []): self
    {
        return new self(200, ['Content-Type' => $mime] + $headers, '', $path);
    }

    /** The callback owns its resources through completion, including on failure. */
    public static function stream(\Closure $send, string $mime, array $headers = []): self
    {
        return new self(200, ['Content-Type' => $mime] + $headers, '', null, [], $send);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, [$name => $value] + $this->headers, $this->body, $this->filePath, $this->cookies, $this->stream);
    }

    public function withCookie(string $name, string $value, array $options): self
    {
        $cookies = $this->cookies;
        $cookies[] = [$name, $value, $options];
        return new self($this->status, $this->headers, $this->body, $this->filePath, $cookies, $this->stream);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->cookies as [$name, $value, $options]) {
            setcookie($name, $value, $options);
        }
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->stream !== null) {
            // Do not let the API exception handler append JSON to a partly
            // sent calendar. A failed stream has no END:VCALENDAR trailer.
            try {
                // PHP output buffering must not accumulate the whole export.
                // A reverse proxy may still buffer independently of PHP.
                while (ob_get_level() > 0 && @ob_end_flush()) {
                }
                ($this->stream)();
            } catch (\Throwable $e) {
                error_log('Better-Cal download stopped: ' . $e->getMessage());
                if (!headers_sent()) {
                    http_response_code(500);
                    header_remove('Content-Disposition');
                    header_remove('Content-Length');
                    header('Content-Type: text/plain; charset=utf-8');
                    echo 'The export could not be completed. Please try again.';
                }
            }
            return;
        }
        if ($this->filePath !== null) {
            header('Content-Length: ' . (string) filesize($this->filePath));
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
