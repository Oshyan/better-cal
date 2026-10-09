<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Domain\SystemHealth;

/**
 * The one network boundary for geocoding. Provider profiles stay small and
 * explicit while HttpClient owns address classification, DNS pinning,
 * redirects, deadlines and response budgets.
 */
final class PoliciedGeocoderTransport implements GeocoderTransport, KeyedGeocoderTransport
{
    private const PHOTON_ENDPOINT = 'https://photon.komoot.io/api';
    private const PHOTON_REVERSE_ENDPOINT = 'https://photon.komoot.io/reverse';
    private const OPEN_METEO_ENDPOINT = 'https://geocoding-api.open-meteo.com/v1/search';
    private const TIMEOUT_MS = 3_000;
    private const MAX_BYTES = 1024 * 1024;
    /**
     * The keyed place-search services: the one host each may be asked, the
     * health label, whether to connect over IPv4 only (LocationIQ can
     * restrict a key to source addresses, and accepts only IPv4 ones there),
     * and how long to wait. Photon stands behind each of these, so none gets
     * more than KEYED_MAX_MS: past that, waiting is slower than asking Photon,
     * which keeps the longer TIMEOUT_MS since nothing stands behind it.
     * LocationIQ answers in well under a second (0.06 s median, 0.9 s the
     * slowest normal answer in testing), so past 1.2 s it is stuck.
     */
    private const KEYED_MAX_MS = 2_000;
    private const KEYED = [
        'locationiq' => ['host' => 'api.locationiq.com', 'label' => 'LocationIQ place search', 'ipv4' => true, 'timeoutMs' => 1_200],
        'stadia' => ['host' => 'api.stadiamaps.com', 'label' => 'Stadia Maps place search', 'ipv4' => false, 'timeoutMs' => self::KEYED_MAX_MS],
        'maptiler' => ['host' => 'api.maptiler.com', 'label' => 'MapTiler place search', 'ipv4' => false, 'timeoutMs' => self::KEYED_MAX_MS],
    ];

    public function __construct(
        private readonly Db $db,
        private readonly ?\Closure $logger = null,
        private readonly ?\Closure $resolver = null,
    ) {
    }

    public function photon(array $paramSets): array
    {
        $urls = array_map([self::class, 'photonUrl'], $paramSets);
        return $this->jsonBatch('photon', 'Photon geocoding', $urls, 3);
    }

    /**
     * The request URL for one Photon parameter set: /reverse when it says
     * '_endpoint' => 'reverse', and a list value as repeated keys
     * (layer=city&layer=state), since Photon refuses PHP's bracketed form
     * (layer[0]=city). Pure.
     */
    public static function photonUrl(array $params): string
    {
        $endpoint = ($params['_endpoint'] ?? null) === 'reverse' ? self::PHOTON_REVERSE_ENDPOINT : self::PHOTON_ENDPOINT;
        unset($params['_endpoint']);
        return $endpoint . '?' . preg_replace('/%5B\d+%5D=/', '=', http_build_query($params));
    }

    public function keyed(string $provider, array $urls): array
    {
        $profile = self::KEYED[$provider] ?? null;
        if ($profile === null) {
            throw new \InvalidArgumentException('Unknown place search provider');
        }
        foreach ($urls as $url) {
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || strtolower((string) parse_url($url, PHP_URL_HOST)) !== $profile['host']) {
                throw new \InvalidArgumentException('Place search URL is not for ' . $provider);
            }
        }
        return $this->jsonBatch($provider, $profile['label'], $urls, 0, $profile['ipv4'], $profile['timeoutMs']);
    }

    public function openMeteo(array $params): ?array
    {
        $rows = $this->jsonBatch(
            'open-meteo',
            'Open-Meteo geocoding fallback',
            [self::OPEN_METEO_ENDPOINT . '?' . http_build_query($params)],
            0
        );
        return $rows[0] ?? null;
    }

    /** @param list<string> $urls @return list<?array> */
    private function jsonBatch(string $provider, string $label, array $urls, int $maxRedirects, bool $ipv4Only = false, int $timeoutMs = self::TIMEOUT_MS): array
    {
        if ($urls === []) {
            return [];
        }
        $http = new HttpClient(
            requestBudget: count($urls) * ($maxRedirects + 1),
            userAgent: HttpClient::userAgentFor('geocoding'),
            maxBytes: self::MAX_BYTES,
            maxRedirects: $maxRedirects,
            connectTimeoutMs: $timeoutMs,
            totalTimeoutMs: $timeoutMs,
            allowedSchemes: ['https'],
            maxTotalBytes: count($urls) * self::MAX_BYTES,
            resolver: $this->resolver,
            ipv4Only: $ipv4Only,
        );
        $responses = $http->getMany($urls, ['Accept: application/json']);
        $decoded = [];
        $errors = [];
        $limited = 0;
        foreach ($responses as $response) {
            $error = $response['error'];
            // LocationIQ answers "nothing found" with a 404.
            if ($error === null && $provider === 'locationiq' && $response['status'] === 404) {
                $decoded[] = [];
                continue;
            }
            if ($error === null && $response['status'] === 429) {
                $limited++;
            }
            if ($error === null && ($response['status'] < 200 || $response['status'] >= 300)) {
                $error = 'HTTP ' . $response['status'];
            }
            $data = null;
            if ($error === null) {
                try {
                    $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($data)) {
                        $error = 'provider returned a non-object JSON response';
                    }
                } catch (\JsonException) {
                    $error = 'provider returned invalid JSON';
                }
            }
            if ($error === null && in_array($provider, ['photon', 'stadia', 'maptiler'], true) && !is_array($data['features'] ?? null)) {
                $error = 'provider response did not contain a feature list';
            }
            if ($error === null && $provider === 'open-meteo' && !empty($data['error'])) {
                $error = 'provider returned an error response';
            }
            if ($error !== null) {
                $safe = self::safeError($error);
                $errors[] = $safe;
                $decoded[] = null;
                $this->writeLog($label . ' request failed: ' . $safe);
            } else {
                $decoded[] = $data;
            }
        }

        // A free plan's rate limit is not an outage: the search falls back
        // to Photon, and System health stays for real failures.
        // Rate limited only is neither a failure nor a recovery.
        if (count($errors) < count($responses)) {
            $this->recordOk($provider, $label);
        } elseif ($limited < count($errors)) {
            $this->recordFailure($provider, $label, implode('; ', array_values(array_unique($errors))));
        }
        return $decoded;
    }

    /**
     * Keep provider-controlled URLs, redirect hosts and user location queries
     * out of logs. Transport messages are deliberately collapsed to a small
     * fixed vocabulary rather than attempting to redact attacker-controlled
     * text perfectly.
     */
    public static function safeError(string $error): string
    {
        if (preg_match('/^HTTP ([1-5]\d\d)$/', trim($error), $match) === 1) {
            return 'provider returned HTTP ' . $match[1];
        }
        $lower = strtolower($error);
        if (str_contains($lower, 'private/internal') || str_contains($lower, 'url scheme')) {
            return 'request refused by outbound policy';
        }
        if (str_contains($lower, 'dns')) {
            return 'provider DNS resolution failed';
        }
        if (str_contains($lower, 'deadline') || str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return 'provider request timed out';
        }
        if (str_contains($lower, 'response budget') || str_contains($lower, 'response exceeded')) {
            return 'provider response exceeded the size limit';
        }
        if (str_contains($lower, 'too many redirects')) {
            return 'provider exceeded the redirect limit';
        }
        if (str_contains($lower, 'request budget')) {
            return 'provider exceeded the request limit';
        }
        if (str_contains($lower, 'invalid json')) {
            return 'provider returned invalid JSON';
        }
        if (str_contains($lower, 'non-object json')) {
            return 'provider returned a non-object JSON response';
        }
        if (str_contains($lower, 'feature list')) {
            return 'provider response did not contain a feature list';
        }
        if (str_contains($lower, 'error response')) {
            return 'provider returned an error response';
        }
        return 'provider network request failed';
    }

    private function recordFailure(string $provider, string $label, string $error): void
    {
        try {
            (new SystemHealth($this->db))->recordFailure(
                'geocoder:' . $provider,
                'job',
                null,
                $label,
                mb_substr($error, 0, 300)
            );
        } catch (\Throwable $e) {
            $this->writeLog('Could not record geocoder health: ' . self::safeError($e->getMessage()));
        }
    }

    private function recordOk(string $provider, string $label): void
    {
        try {
            // Healthy autocomplete is frequent. Do not create or continually
            // update an OK row; write only when an existing failure recovers.
            (new SystemHealth($this->db))->recordOk('geocoder:' . $provider, 'job', null, $label, false);
        } catch (\Throwable $e) {
            $this->writeLog('Could not record geocoder recovery: ' . self::safeError($e->getMessage()));
        }
    }

    private function writeLog(string $message): void
    {
        $line = '[Better-Cal] ' . $message;
        if ($this->logger !== null) {
            try {
                ($this->logger)($line);
                return;
            } catch (\Throwable) {
                // Logging must never turn a provider outage into an API 500.
            }
        }
        error_log($line);
    }
}
