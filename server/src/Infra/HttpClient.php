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
    private const CONNECT_TIMEOUT_MS = 5_000;
    private const TOTAL_TIMEOUT_MS = 20_000;
    private const MAX_REDIRECTS = 3;

    private int $used = 0;

    /** @var array<string,list<string>> */
    private array $resolvedHosts = [];

    /**
     * Limits are per-instance so core callers can adopt the SSRF policy without
     * inheriting the plugin-shaped ceilings: an ICS subscription is legitimately
     * far larger than any plugin's API response.
     */
    public function __construct(
        private readonly int $requestBudget = 60,
        private readonly string $userAgent = 'Better-Cal (+https://github.com/Oshyan/better-cal)',
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $maxRedirects = self::MAX_REDIRECTS,
        private readonly int $connectTimeoutMs = self::CONNECT_TIMEOUT_MS,
        private readonly int $totalTimeoutMs = self::TOTAL_TIMEOUT_MS,
        private readonly array $allowedSchemes = ['http', 'https'],
        private readonly ?int $maxTotalBytes = null,
        private readonly ?\Closure $resolver = null,
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
            // Only global unicast (2000::/3) is a real destination. That alone
            // refuses NAT64 (64:ff9b::/96, 64:ff9b:1::/48), IPv4-compatible
            // ::/96, ULA, link-local, site-local and multicast; a NAT64
            // address wrapping 10.0.0.5 used to pass (scan 2026-09-23, F24).
            $first = ord($bin[0]);
            // Well-known NAT64 (64:ff9b::/96, what DNS64 hands an IPv6-only
            // server) is judged by the IPv4 it carries, so public IPv4 hosts
            // stay reachable there and private ones do not.
            if (str_starts_with(bin2hex($bin), '0064ff9b0000000000000000')) {
                $v4 = inet_ntop(substr($bin, 12, 4));
                return !is_string($v4) || self::isForbiddenIp($v4);
            }
            if (($first & 0xE0) !== 0x20) {
                return true;
            }
            $hex = bin2hex($bin);
            if (str_starts_with($hex, '20010db8') || str_starts_with($hex, '20010000')) {
                return true; // documentation range; Teredo (wraps an IPv4 we cannot see)
            }
            if (str_starts_with($hex, '2002')) {
                // 6to4 carries its IPv4 in bits 16-47: judge that address
                $v4 = inet_ntop(substr($bin, 2, 4));
                return !is_string($v4) || self::isForbiddenIp($v4);
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
     * Concurrent GETs under the same address, pinning, redirect and body
     * policy as get(). Each item is independent so one refused/failed URL does
     * not discard its siblings. The total timeout is one absolute batch
     * deadline, including redirects.
     *
     * @param list<string> $urls
     * @return list<array{status:int,body:string,error:?string}>
     */
    public function getMany(array $urls, array $headers = []): array
    {
        if ($urls === []) {
            return [];
        }
        $results = array_fill(0, count($urls), null);
        $batch = (object) ['bytes' => 0, 'overflow' => false];
        $deadline = microtime(true) + max(1, $this->totalTimeoutMs) / 1000;
        if (!function_exists('curl_multi_init')) {
            foreach (array_values($urls) as $index => $url) {
                if (!is_string($url)) {
                    $results[$index] = ['status' => 0, 'body' => '', 'error' => 'HTTP URL must be a string'];
                    continue;
                }
                try {
                    // The shared counter includes every redirect body, not
                    // merely the final response returned by request().
                    $response = $this->request('GET', $url, $headers, null, $deadline, $batch);
                    $results[$index] = $response + ['error' => null];
                } catch (\Throwable $e) {
                    $results[$index] = ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
                }
            }
            return $results;
        }
        $multi = curl_multi_init();
        /** @var array<int,array{index:int,url:string,hop:int,handle:\CurlHandle,transfer:object}> $active */
        $active = [];

        $start = function (int $index, string $url, int $hop) use (&$active, &$results, $batch, $deadline, $multi, $headers): void {
            try {
                if ($this->used >= $this->requestBudget) {
                    throw new \RuntimeException('HTTP request budget exhausted (' . $this->requestBudget . ')');
                }
                $destination = $this->destination($url, $deadline);
                $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
                if ($remainingMs <= 0) {
                    throw new \RuntimeException('HTTP batch deadline exceeded');
                }
                $this->used++;
                $ch = curl_init($url);
                if ($ch === false) {
                    throw new \RuntimeException('Could not initialize HTTP request');
                }
                $transfer = (object) ['body' => '', 'overflow' => false, 'batchOverflow' => false];
                curl_setopt_array($ch, [
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT_MS => min(max(1, $this->connectTimeoutMs), $remainingMs),
                    CURLOPT_TIMEOUT_MS => $remainingMs,
                    CURLOPT_NOSIGNAL => true,
                    CURLOPT_USERAGENT => $this->userAgent,
                    CURLOPT_HTTPHEADER => array_merge(['Accept-Encoding: gzip'], $headers),
                    CURLOPT_ENCODING => '',
                    CURLOPT_PROTOCOLS => $this->protocolMask(),
                    CURLOPT_RESOLVE => [$destination['resolve']],
                    // Environment HTTP(S)_PROXY/ALL_PROXY settings would move
                    // DNS and the TCP connection outside this policy boundary.
                    CURLOPT_PROXY => '',
                    CURLOPT_WRITEFUNCTION => function ($c, string $chunk) use ($transfer, $batch): int {
                        $bytes = strlen($chunk);
                        if (strlen($transfer->body) + $bytes > $this->maxBytes) {
                            $transfer->overflow = true;
                            return 0;
                        }
                        if ($this->maxTotalBytes !== null && $batch->bytes + $bytes > $this->maxTotalBytes) {
                            $transfer->batchOverflow = true;
                            $batch->overflow = true;
                            return 0;
                        }
                        $transfer->body .= $chunk;
                        $batch->bytes += $bytes;
                        return $bytes;
                    },
                ]);
                $code = curl_multi_add_handle($multi, $ch);
                if ($code !== CURLM_OK) {
                    throw new \RuntimeException('Could not start HTTP request');
                }
                $active[spl_object_id($ch)] = [
                    'index' => $index,
                    'url' => $url,
                    'hop' => $hop,
                    'handle' => $ch,
                    'transfer' => $transfer,
                ];
            } catch (\Throwable $e) {
                $results[$index] = ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
            }
        };

        foreach (array_values($urls) as $index => $url) {
            if (!is_string($url)) {
                $results[$index] = ['status' => 0, 'body' => '', 'error' => 'HTTP URL must be a string'];
                continue;
            }
            $start($index, $url, 0);
        }

        while ($active !== []) {
            do {
                $multiStatus = curl_multi_exec($multi, $running);
            } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);
            if ($multiStatus !== CURLM_OK) {
                foreach ($active as $state) {
                    $results[$state['index']] = ['status' => 0, 'body' => '', 'error' => 'HTTP batch failed'];
                    curl_multi_remove_handle($multi, $state['handle']);
                }
                $active = [];
                break;
            }

            while (($info = curl_multi_info_read($multi)) !== false) {
                $ch = $info['handle'];
                $key = spl_object_id($ch);
                $state = $active[$key] ?? null;
                if ($state === null) {
                    continue;
                }
                unset($active[$key]);
                curl_multi_remove_handle($multi, $ch);
                $transfer = $state['transfer'];
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $redirect = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
                $error = curl_error($ch);

                if ($transfer->overflow) {
                    $results[$state['index']] = [
                        'status' => 0,
                        'body' => '',
                        'error' => 'Response exceeded ' . round($this->maxBytes / 1048576, 1) . ' MB cap',
                    ];
                    continue;
                }
                if ($transfer->batchOverflow) {
                    $results[$state['index']] = ['status' => 0, 'body' => '', 'error' => 'HTTP batch response budget exceeded'];
                    continue;
                }
                if ((int) $info['result'] !== CURLE_OK) {
                    $results[$state['index']] = [
                        'status' => 0,
                        'body' => '',
                        'error' => 'HTTP error for ' . (parse_url($state['url'], PHP_URL_HOST) ?: 'host')
                            . ($error !== '' ? ': ' . $error : ''),
                    ];
                    continue;
                }
                if ($status >= 300 && $status < 400 && $redirect !== '') {
                    if ($state['hop'] >= $this->maxRedirects) {
                        $results[$state['index']] = ['status' => 0, 'body' => '', 'error' => 'Too many redirects'];
                    } else {
                        $start($state['index'], $redirect, $state['hop'] + 1);
                    }
                    continue;
                }
                $results[$state['index']] = ['status' => $status, 'body' => $transfer->body, 'error' => null];
            }

            if ($batch->overflow) {
                foreach ($active as $state) {
                    $results[$state['index']] = ['status' => 0, 'body' => '', 'error' => 'HTTP batch response budget exceeded'];
                    curl_multi_remove_handle($multi, $state['handle']);
                }
                $active = [];
                break;
            }
            if ($active === []) {
                break;
            }
            if (microtime(true) >= $deadline) {
                foreach ($active as $state) {
                    $results[$state['index']] = ['status' => 0, 'body' => '', 'error' => 'HTTP batch deadline exceeded'];
                    curl_multi_remove_handle($multi, $state['handle']);
                }
                $active = [];
                break;
            }
            if ($running > 0) {
                $wait = max(0.001, min(0.2, $deadline - microtime(true)));
                if (curl_multi_select($multi, $wait) === -1) {
                    usleep(1_000);
                }
            } else {
                usleep(1_000);
            }
        }

        return array_map(
            static fn($r): array => is_array($r) ? $r : ['status' => 0, 'body' => '', 'error' => 'HTTP batch ended without a result'],
            $results
        );
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

    private function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        ?float $deadline = null,
        ?object $batch = null,
    ): array
    {
        $deadline ??= microtime(true) + max(1, $this->totalTimeoutMs) / 1000;
        $maxHops = $method === 'GET' ? $this->maxRedirects : 0;
        for ($hop = 0; $hop <= $maxHops; $hop++) {
            if ($this->used >= $this->requestBudget) {
                throw new \RuntimeException('HTTP request budget exhausted (' . $this->requestBudget . ')');
            }
            $destination = $this->destination($url, $deadline);
            $host = $destination['host'];
            $timeoutMs = (int) floor(($deadline - microtime(true)) * 1000);
            if ($timeoutMs <= 0) {
                throw new \RuntimeException('HTTP batch deadline exceeded');
            }

            $this->used++;
            $ch = curl_init($url);
            $response = '';
            $overflow = false;
            if ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($body !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
            }
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false, // redirects re-vetted manually
                CURLOPT_CONNECTTIMEOUT_MS => min(max(1, $this->connectTimeoutMs), $timeoutMs),
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_HTTPHEADER => array_merge(['Accept-Encoding: gzip'], $headers),
                CURLOPT_ENCODING => '',
                CURLOPT_PROTOCOLS => $this->protocolMask(),
                CURLOPT_RESOLVE => [$destination['resolve']],
                CURLOPT_PROXY => '',
                CURLOPT_WRITEFUNCTION => function ($c, string $chunk) use (&$response, &$overflow, $batch): int {
                    $bytes = strlen($chunk);
                    if (strlen($response) + $bytes > $this->maxBytes) {
                        $overflow = true;
                        return 0; // abort transfer: response too large
                    }
                    if ($batch !== null && $this->maxTotalBytes !== null
                        && $batch->bytes + $bytes > $this->maxTotalBytes) {
                        $batch->overflow = true;
                        return 0;
                    }
                    $response .= $chunk;
                    if ($batch !== null) {
                        $batch->bytes += $bytes;
                    }
                    return $bytes;
                },
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $redirect = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
            $err = curl_errno($ch) !== 0 ? curl_error($ch) : null;

            if ($overflow) {
                throw new \RuntimeException('Response exceeded ' . round($this->maxBytes / 1048576, 1) . ' MB cap');
            }
            if ($batch !== null && $batch->overflow) {
                throw new \RuntimeException('HTTP batch response budget exceeded');
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

    /** @return array{host:string,resolve:string} */
    private function destination(string $url, float $deadline): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, $this->allowedSchemes, true) || $host === '') {
            throw new \RuntimeException('Refused URL scheme or missing host');
        }
        $lookupHost = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        if (microtime(true) >= $deadline) {
            throw new \RuntimeException('HTTP deadline exceeded before DNS resolution');
        }
        if (isset($this->resolvedHosts[$lookupHost])) {
            $ips = $this->resolvedHosts[$lookupHost];
        } else {
            $remainingMs = max(1, (int) floor(($deadline - microtime(true)) * 1000));
            $ips = $this->resolver !== null
                ? ($this->resolver)($lookupHost, $remainingMs)
                : self::resolveHost($lookupHost, $remainingMs);
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('DNS resolution deadline exceeded for ' . $lookupHost);
            }
            if (is_array($ips)) {
                $ips = array_values(array_unique($ips));
            }
            if (is_array($ips) && $ips !== []) {
                $this->resolvedHosts[$lookupHost] = $ips;
            }
        }
        if (!is_array($ips) || $ips === []) {
            throw new \RuntimeException('DNS resolution failed for ' . $lookupHost);
        }
        foreach ($ips as $ip) {
            if (!is_string($ip) || self::isForbiddenIp($ip)) {
                throw new \RuntimeException('Refused private/internal address for ' . $lookupHost . ' (' . (is_string($ip) ? $ip : 'invalid') . ')');
            }
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $resolveHost = str_contains($lookupHost, ':') ? '[' . $lookupHost . ']' : $lookupHost;
        $pins = array_map(
            static fn(string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
            $ips
        );
        return ['host' => $lookupHost, 'resolve' => $resolveHost . ':' . $port . ':' . implode(',', $pins)];
    }

    private function protocolMask(): int
    {
        $mask = 0;
        if (in_array('http', $this->allowedSchemes, true)) {
            $mask |= CURLPROTO_HTTP;
        }
        if (in_array('https', $this->allowedSchemes, true)) {
            $mask |= CURLPROTO_HTTPS;
        }
        return $mask;
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
    private static function resolveHost(string $host, int $timeoutMs): array
    {
        // A literal IP "resolves" to itself (and still gets judged).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('Bounded DNS resolution is unavailable');
        }

        // PHP's in-process DNS functions have no portable timeout control.
        // Isolate them in a tiny child so the caller's absolute deadline also
        // covers a wedged or maliciously slow resolver.
        $php = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
        if (!is_executable($php)) {
            throw new \RuntimeException('Bounded DNS resolver executable is unavailable');
        }
        $code = <<<'PHP'
$host = $argv[1] ?? '';
$records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
$ips = [];
foreach ($records as $record) {
    if (isset($record['ip'])) {
        $ips[] = (string) $record['ip'];
    } elseif (isset($record['ipv6'])) {
        $ips[] = (string) $record['ipv6'];
    }
}
if ($ips === []) {
    foreach ((@gethostbynamel($host) ?: []) as $ip) {
        $ips[] = (string) $ip;
    }
}
echo json_encode(array_values(array_unique($ips)), JSON_THROW_ON_ERROR);
PHP;
        $pipes = [];
        $process = @proc_open(
            [$php, '-n', '-r', $code, $host],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start bounded DNS resolution');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + max(1, $timeoutMs) / 1000;
        $stdout = '';
        $status = proc_get_status($process);
        while ($status['running']) {
            $stdout .= (string) stream_get_contents($pipes[1], 65_536 - strlen($stdout));
            stream_get_contents($pipes[2], 4_096);
            if (strlen($stdout) >= 65_536 || microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                foreach (array_slice($pipes, 1) as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
                throw new \RuntimeException('DNS resolution deadline exceeded for ' . $host);
            }
            $remaining = max(0.001, min(0.05, $deadline - microtime(true)));
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, (int) ($remaining * 1_000_000));
            $status = proc_get_status($process);
        }
        $stdout .= (string) stream_get_contents($pipes[1], 65_536 - strlen($stdout));
        stream_get_contents($pipes[2], 4_096);
        foreach (array_slice($pipes, 1) as $pipe) {
            fclose($pipe);
        }
        $exitCode = (int) $status['exitcode'];
        proc_close($process);
        if ($exitCode !== 0 || $stdout === '') {
            return [];
        }
        try {
            $ips = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($ips) ? array_values(array_filter($ips, 'is_string')) : [];
    }
}
