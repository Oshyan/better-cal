<?php
// The probe's one way to Google: every request goes through call(), which
// adds the bearer token and sendUpdates=none, pauses before writes, retries
// what Google asks to be retried (rate limits, a 5xx, an expired token) and
// returns an exchange: what was sent and what came back, ready for the
// report. The transport is a closure, so the whole probe can run against
// the fake in test.php:
//   fn(string $method, string $url, array $headers, ?array $payload): array{status:int, body:string}
declare(strict_types=1);

namespace BetterCal\GoogleProbe;

final class Api
{
    public const BASE = 'https://www.googleapis.com/calendar/v3';
    private const RETRIES = 5;

    public int $requests = 0;
    private ?string $access = null;

    /**
     * @param \Closure $transport see the file comment
     * @param \Closure $token fn(): string, a fresh access token
     * @param \Closure $sleep fn(float $seconds): void
     */
    public function __construct(
        private readonly \Closure $transport,
        private readonly \Closure $token,
        public readonly string $calendarId,
        private readonly \Closure $sleep,
        private readonly float $writePause = 0.25,
        private readonly bool $mayOmitSendUpdates = false,
    ) {
    }

    /**
     * One request. $path starts with /users/me/ (relative to the API root,
     * {calendar} standing for the probe calendar) or is relative to the
     * probe calendar (/events/...). Only network failure throws; every
     * status comes back in the exchange.
     *
     * @return array{method:string, path:string, query:array, headers:array, body:?array, status:int, response:mixed, at:string, tries:int}
     */
    public function call(string $method, string $path, array $query = [], ?array $body = null, array $headers = [], bool $sendUpdates = true): array
    {
        $method = strtoupper($method);
        if ($method !== 'GET') {
            if ($sendUpdates) {
                $query = ['sendUpdates' => 'none'] + $query;
            } elseif (!$this->mayOmitSendUpdates) {
                throw new \LogicException('Only the mail case may leave out sendUpdates, and only with --guest');
            }
        }
        $own = str_starts_with($path, '/users/me/');
        $urlPath = $own
            ? str_replace('{calendar}', rawurlencode($this->calendarId), $path)
            : '/calendars/' . rawurlencode($this->calendarId) . $path;
        $url = self::BASE . $urlPath . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $recordedPath = $own ? $path : '/calendars/{calendar}' . $path;

        $at = self::now();
        $tries = 0;
        $refreshed = false;
        while (true) {
            $tries++;
            if ($method !== 'GET' && $this->writePause > 0) {
                ($this->sleep)($this->writePause); // rate limit only; nothing waits on it for correctness
            }
            $this->access ??= ($this->token)();
            $this->requests++;
            $r = ($this->transport)($method, $url, array_merge(['Authorization: Bearer ' . $this->access], $headers), $body);
            $status = (int) $r['status'];
            $decoded = json_decode((string) $r['body'], true);
            if ($status === 401 && !$refreshed) {
                $this->access = null; // the hour-long access token ran out mid-run
                $refreshed = true;
                continue;
            }
            if (self::retryable($status, $decoded) && $tries < self::RETRIES) {
                ($this->sleep)(min(16.0, 0.5 * (2 ** ($tries - 1))));
                continue;
            }
            return [
                'method' => $method,
                'path' => $recordedPath,
                'query' => $query,
                'headers' => $headers, // If-Match and the like; the bearer token is never recorded
                'body' => $body,
                'status' => $status,
                'response' => is_array($decoded) ? $decoded : (string) $r['body'],
                'at' => $at,
                'tries' => $tries,
            ];
        }
    }

    /** What Google says to retry: rate limits and server errors. */
    private static function retryable(int $status, mixed $decoded): bool
    {
        if ($status === 429 || $status >= 500) {
            return true;
        }
        if ($status === 403 && is_array($decoded)) {
            foreach ($decoded['error']['errors'] ?? [] as $e) {
                if (in_array((string) ($e['reason'] ?? ''), ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** ISO 8601 in the machine's zone, with milliseconds and the offset. */
    public static function now(): string
    {
        return (new \DateTimeImmutable('now', self::localZone()))->format('Y-m-d\TH:i:s.vP');
    }

    /**
     * The machine's own zone: the app runs PHP in UTC, but the report's
     * times are for a person matching them against a mailbox or a log.
     * TZ when set, else the zone /etc/localtime links to, else PHP's.
     */
    public static function localZone(): \DateTimeZone
    {
        static $zone = null;
        if ($zone !== null) {
            return $zone;
        }
        $candidates = [(string) getenv('TZ')];
        $link = @readlink('/etc/localtime');
        if (is_string($link) && preg_match('#zoneinfo/(.+)$#', $link, $m) === 1) {
            $candidates[] = $m[1];
        }
        foreach ($candidates as $name) {
            if ($name !== '' && in_array($name, \DateTimeZone::listIdentifiers(), true)) {
                return $zone = new \DateTimeZone($name);
            }
        }
        return $zone = new \DateTimeZone(date_default_timezone_get());
    }
}
