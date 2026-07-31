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

    private const TTL_SECONDS = 3600;

    private ?object $webPush = null;

    public function __construct(private readonly array $cfg)
    {
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
    public function send(array $subscription, array $payload): string
    {
        try {
            $webPush = $this->webPush();
            $sub = \Minishlink\WebPush\Subscription::create([
                'endpoint' => (string) $subscription['endpoint'],
                'publicKey' => (string) $subscription['p256dh'],
                'authToken' => (string) $subscription['auth'],
                'contentEncoding' => 'aes128gcm',
            ]);
            $report = $webPush->sendOneNotification(
                $sub,
                json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
                ['TTL' => self::TTL_SECONDS, 'urgency' => 'high']
            );
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
        $this->webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => (string) $vapid['public'],
                'privateKey' => (string) $vapid['private'],
            ],
        ]);
        return $this->webPush;
    }
}
