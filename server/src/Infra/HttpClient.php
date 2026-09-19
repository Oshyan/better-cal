<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * Policied outbound HTTP: the only sanctioned way for plugins (and,
 * incrementally, core fetchers) to reach the network. Closes the BC-01/BC-02
 * SSRF class: every hostname is resolved BEFORE connecting and requests to
 * private, loopback, link-local, or cloud-metadata addresses are refused —
 * and cURL is pinned to the vetted IP (CURLOPT_RESOLVE) so a DNS rebind
 * between check and connect changes nothing. Redirects are re-vetted per hop
 * for the same reason.
 *
 * Budget caps are constructor state so a caller (the plugin runner) can hand
 * each plugin its own counter: connect 5s, total 20s, response 5 MB,
 * 3 redirects, N requests per run.
 */
final class HttpClient
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const CONNECT_TIMEOUT = 5;
    private const TOTAL_TIMEOUT = 20;
    private const MAX_REDIRECTS = 3;

    private int $used = 0;

    /**
     * Limits are per-instance so core callers can adopt the SSRF policy without
     * inheriting the plugin-shaped ceilings: an ICS subscription is legitimately
     * far larger than any plugin's API response.
     */
    public function __construct(
        private readonly int $requestBudget = 60,
        private readonly string $userAgent = 'Better-Cal/0.1 (+https://cal.oshyan.com)',
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $maxRedirects = self::MAX_REDIRECTS,
    ) {
    }

    public function requestsUsed(): int
    {
        return $this->used;
    }

    /**
     * Is this IP one we refuse to talk to? Pure; unit-tested. Covers v4
     * loopback/private/link-local/CGNAT/metadata and v6 loopback/ULA/link-local
     * plus v4-mapped forms.
     */
    public static function isForbiddenIp(string $ip): bool
    {
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7); // v4-mapped v6: judge the v4 part
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false
                || str_starts_with($ip, '169.254.')   // link-local incl. 169.254.169.254 metadata
                || str_starts_with($ip, '100.64.')    // CGNAT start (100.64.0.0/10 spot checks below)
                || (preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip) === 1);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $bin = inet_pton($ip);
            if ($bin === false) {
                return true;
            }
            if ($ip === '::1' || $ip === '::') {
                return true;
            }
            $first = ord($bin[0]);
            $second = ord($bin[1]);
            if (($first & 0xFE) === 0xFC) {                     // fc00::/7 ULA
                return true;
            }
            if ($first === 0xFE && ($second & 0xC0) === 0x80) { // fe80::/10 link-local
                return true;
            }
            return false;
        }
        return true; // not an IP at all
    }

    /**
     * GET a URL under policy. Returns ['status'=>int,'body'=>string] or
     * throws \RuntimeException with a human reason (budget, policy, network).
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers, null);
    }

    /**
     * POST a form body under the same policy. Redirects are not followed
     * for a POST (a token endpoint that redirects is not one to trust).
     * Returns ['status'=>int,'body'=>string]; only network failure throws.
     */
    public function postForm(string $url, array $fields, array $headers = []): array
    {
        return $this->request('POST', $url, array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers), http_build_query($fields));
    }

    /**
     * A JSON request with any method (POST/PATCH/PUT/DELETE), under the same
     * policy, no redirects. Returns ['status'=>int,'body'=>string]; the
     * caller judges the status. Only network failure throws.
     */
    public function json(string $method, string $url, ?array $payload, array $headers = []): array
    {
        $headers = array_merge(['Accept: application/json'], $headers);
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        return $this->request(strtoupper($method), $url, $headers, $payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
    }

    private function request(string $method, string $url, array $headers, ?string $body): array
    {
        $maxHops = $method === 'GET' ? $this->maxRedirects : 0;
        for ($hop = 0; $hop <= $maxHops; $hop++) {
            if ($this->used >= $this->requestBudget) {
                throw new \RuntimeException('HTTP request budget exhausted (' . $this->requestBudget . ')');
            }
            $parts = parse_url($url);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = (string) ($parts['host'] ?? '');
            if (($scheme !== 'https' && $scheme !== 'http') || $host === '') {
                throw new \RuntimeException('Refused URL: ' . $url);
            }
            // Resolve first, judge the IPs, then PIN the connection to the
            // vetted address so the answer cannot change under us.
            $ips = self::resolveHost($host);
            if ($ips === []) {
                throw new \RuntimeException('DNS resolution failed for ' . $host);
            }
            foreach ($ips as $ip) {
                if (self::isForbiddenIp($ip)) {
                    throw new \RuntimeException('Refused private/internal address for ' . $host . ' (' . $ip . ')');
                }
            }
            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

            $this->used++;
            $ch = curl_init($url);
            $response = '';
            if ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($body !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
            }
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false, // redirects re-vetted manually
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_HTTPHEADER => array_merge(['Accept-Encoding: gzip'], $headers),
                CURLOPT_ENCODING => '',
                CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],
                CURLOPT_WRITEFUNCTION => function ($c, string $chunk) use (&$response): int {
                    $response .= $chunk;
                    if (strlen($response) > $this->maxBytes) {
                        return 0; // abort transfer: response too large
                    }
                    return strlen($chunk);
                },
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $redirect = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
            $err = curl_errno($ch) !== 0 ? curl_error($ch) : null;
            curl_close($ch);

            if (strlen($response) > $this->maxBytes) {
                throw new \RuntimeException('Response exceeded ' . round($this->maxBytes / 1048576, 1) . ' MB cap');
            }
            if ($err !== null && $status === 0) {
                throw new \RuntimeException('HTTP error for ' . $host . ': ' . $err);
            }
            if ($status >= 300 && $status < 400 && $redirect !== '') {
                $url = $redirect; // loop re-runs full policy on the new URL
                continue;
            }
            return ['status' => $status, 'body' => $response];
        }
        throw new \RuntimeException('Too many redirects');
    }

    /** GET expecting a JSON object/array; throws on non-2xx or bad JSON. */
    public function getJson(string $url, array $headers = []): array
    {
        $r = $this->get($url, array_merge(['Accept: application/json'], $headers));
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new \RuntimeException('HTTP ' . $r['status'] . ' from ' . parse_url($url, PHP_URL_HOST));
        }
        $data = json_decode($r['body'], true);
        if (!is_array($data)) {
            throw new \RuntimeException('Non-JSON response from ' . parse_url($url, PHP_URL_HOST));
        }
        return $data;
    }

    /** @return list<string> */
    private static function resolveHost(string $host): array
    {
        // A literal IP "resolves" to itself (and still gets judged).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $r) {
            if (isset($r['ip'])) {
                $ips[] = (string) $r['ip'];
            } elseif (isset($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
        if ($ips === []) {
            // Fallback for resolvers where dns_get_record is flaky.
            $a = @gethostbynamel($host) ?: [];
            foreach ($a as $ip) {
                $ips[] = $ip;
            }
        }
        return $ips;
    }
}
