<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * Web Push transport: wraps minishlink/web-push (VAPID auth + aes128gcm
 * payload encryption). The library is a composer dependency installed on the
 * server; this class only touches it lazily so CLI tools and tests run
 * without vendor/. VAPID keys come from .env (see bin/vapid.php --generate).
 */
final class PushSender
{
    public const OK = 'ok';
    public const GONE = 'gone';   // 404/410: subscription expired or revoked
    public const ERROR = 'error';

    /**
     * The push services browsers actually hand out endpoints for. An entry
     * matches itself and any subdomain (Firefox uses updates.push.services…,
     * Edge uses wns2-….notify.windows.com).
     *
     * Why a list at all: the endpoint is a URL the CLIENT supplies, and the
     * server then POSTs to it with its own network position. "Any https URL"
     * made that a request-forgery primitive aimed at whatever the server can
     * reach (BC-02). A subscription only ever comes from a browser's push
     * service, so the honest set is small and known.
     *
     * A self-hoster whose users run a browser with a different push service
     * adds hosts with BETTERCAL_PUSH_EXTRA_HOSTS (comma-separated) rather than
     * editing this.
     */
    public const DEFAULT_PUSH_HOSTS = [
        'fcm.googleapis.com',          // Chrome, Brave, Opera, Vivaldi, Samsung Internet, Edge on Android
        'push.services.mozilla.com',   // Firefox
        'push.apple.com',              // Safari (web.push.apple.com)
        'notify.windows.com',          // Edge on Windows
    ];

    private const TTL_SECONDS = 3600;
    private const CONNECT_TIMEOUT = 10;
    private const TOTAL_TIMEOUT = 30;

    private ?object $webPush = null;

    public function __construct(private readonly array $cfg)
    {
    }

    /**
     * Why this endpoint may not be delivered to, or null when it may. Pure;
     * unit-tested. Used when a subscription is stored AND again at send time,
     * so rows saved before the policy existed (or under a since-removed extra
     * host) are covered too.
     *
     * @param list<string> $extraHosts operator additions (cfg push.extra_hosts)
     */
    public static function endpointProblem(string $endpoint, array $extraHosts = []): ?string
    {
        $parts = parse_url($endpoint);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return 'endpoint must be an https URL';
        }
        // user:pass@ is never part of a real endpoint and is the classic way to
        // make a URL read as one host while connecting to another.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'endpoint must not carry credentials';
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return 'endpoint must use the default https port';
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '') {
            return 'endpoint has no host';
        }
        foreach ([...self::DEFAULT_PUSH_HOSTS, ...$extraHosts] as $allowed) {
            $allowed = strtolower(trim((string) $allowed, " \t."));
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
                return null;
            }
        }
        return "$host is not a recognized push service (operators can allow it with BETTERCAL_PUSH_EXTRA_HOSTS)";
    }

    /** @return list<string> */
    public function extraHosts(): array
    {
        return array_values((array) ($this->cfg['push']['extra_hosts'] ?? []));
    }

    public function configured(): bool
    {
        $vapid = $this->cfg['vapid'] ?? [];
        return ($vapid['public'] ?? '') !== '' && ($vapid['private'] ?? '') !== '';
    }

    public function publicKey(): ?string
    {
        $key = (string) ($this->cfg['vapid']['public'] ?? '');
        return $key !== '' ? $key : null;
    }

    /**
     * Send one notification to one stored subscription row.
     *
     * @param array $subscription push_subscriptions row (endpoint, p256dh, auth)
     * @param array $payload {title, body, url, tag}
     * @return self::OK|self::GONE|self::ERROR
     */
    public function send(array $subscription, array $payload, ?int $ttl = null): string
    {
        $problem = self::endpointProblem((string) ($subscription['endpoint'] ?? ''), $this->extraHosts());
        if ($problem !== null) {
            // ERROR, not GONE: GONE prunes the row, and an operator who simply
            // has not listed their push host yet should see a failing device
            // in System health, not have it silently deleted.
            error_log('push send refused: ' . $problem);
            return self::ERROR;
        }
        try {
            $webPush = $this->webPush();
            $sub = \Minishlink\WebPush\Subscription::create([
                'endpoint' => (string) $subscription['endpoint'],
                'publicKey' => (string) $subscription['p256dh'],
                'authToken' => (string) $subscription['auth'],
                'contentEncoding' => 'aes128gcm',
            ]);
            $options = ['TTL' => $ttl ?? self::TTL_SECONDS, 'urgency' => 'high'];
            // Collapse repeated sends for the same occurrence at the push
            // service (Topic header), mirroring the client-side tag replace.
            if (!empty($payload['tag'])) {
                $options['topic'] = substr(preg_replace('/[^A-Za-z0-9_\-=]/', '', base64_encode((string) $payload['tag'])), 0, 32);
            }
            $report = $webPush->sendOneNotification(
                $sub,
                json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
                $options
            );
            // Forensics for delayed-delivery reports: when the push service
            // accepted the message, record the acceptance status + options so
            // late arrivals can be attributed to delivery, not to us.
            $accepted = $report->isSuccess() ? 'accepted' : 'rejected';
            $status = $report->getResponse() !== null ? $report->getResponse()->getStatusCode() : 0;
            error_log(sprintf(
                'push %s status=%d ttl=%d urgency=high tag=%s host=%s',
                $accepted,
                $status,
                $options['TTL'],
                (string) ($payload['tag'] ?? ''),
                parse_url((string) $subscription['endpoint'], PHP_URL_HOST) ?: '?'
            ));
            if ($report->isSuccess()) {
                return self::OK;
            }
            if ($report->isSubscriptionExpired()) {
                return self::GONE;
            }
            $response = $report->getResponse();
            $status = $response !== null ? $response->getStatusCode() : 0;
            if ($status === 404 || $status === 410) {
                return self::GONE;
            }
            error_log('push send failed (' . $status . '): ' . $report->getReason());
            return self::ERROR;
        } catch (\Throwable $e) {
            error_log('push send error: ' . $e->getMessage());
            return self::ERROR;
        }
    }

    private function webPush(): object
    {
        if ($this->webPush !== null) {
            return $this->webPush;
        }
        if (!class_exists(\Minishlink\WebPush\WebPush::class)) {
            throw new \RuntimeException('minishlink/web-push is not installed (run composer update on the server)');
        }
        if (!$this->configured()) {
            throw new \RuntimeException('VAPID keys are not configured (run bin/vapid.php --generate and update .env)');
        }
        $vapid = $this->cfg['vapid'];
        $subject = (string) ($vapid['subject'] ?? '');
        if ($subject === '') {
            $subject = (string) ($this->cfg['base_url'] ?? 'mailto:admin@localhost');
        }
        // Redirects off: a push service answers 201, never 3xx, and following
        // one would let an allowed host (or anything impersonating it) steer
        // the request to a destination endpointProblem() never saw, including
        // a downgrade to plain http.
        $this->webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => (string) $vapid['public'],
                'privateKey' => (string) $vapid['private'],
            ],
        ], [], self::TOTAL_TIMEOUT, [
            'allow_redirects' => false,
            'connect_timeout' => self::CONNECT_TIMEOUT,
        ]);
        return $this->webPush;
    }
}
