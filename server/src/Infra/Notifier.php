<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Domain\PushSubscriptions;
use BetterCal\Domain\Reminders;
use BetterCal\Domain\Settings;

/**
 * Send one ad-hoc notification to the user, honouring their channel setting.
 *
 * Reminders::scan writes its delivery inline, so there was no reusable "send
 * this now" path — plugins (and the push test endpoints) each reinvented the
 * fan-out. This is that path, extracted: it reuses the pure decision helpers
 * (Reminders::channelPlan) so an ad-hoc notification obeys exactly the same
 * push/email/fallback rules as a reminder.
 *
 * Silent by design: an unconfigured channel is not an error a plugin should
 * crash on. The return value says what actually happened.
 */
final class Notifier
{
    public function __construct(
        private readonly Db $db,
        private readonly array $cfg,
        private readonly ?PushSender $push = null,
        private readonly ?EmailSender $email = null,
    ) {
    }

    /**
     * @return array{push:int,email:bool} pushes delivered, email sent
     */
    public function send(int $userId, string $title, string $body, string $url = '/', string $tag = ''): array
    {
        $push = $this->push ?? new PushSender($this->cfg);
        $email = $this->email ?? new EmailSender($this->cfg);
        $subs = new PushSubscriptions($this->db);

        $payload = [
            'title' => mb_substr($title, 0, 120),
            'body' => mb_substr($body, 0, 400),
            'url' => $url,
            'tag' => $tag,
        ];

        $user = $this->db->one('SELECT email, settings_json FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return ['push' => 0, 'email' => false];
        }
        $stored = is_string($user['settings_json'] ?? null) ? json_decode((string) $user['settings_json'], true) : null;
        $settings = Settings::withDefaults(is_array($stored) ? $stored : []);
        $channel = (string) ($settings['notifyChannel'] ?? 'push-fallback');

        $rows = $subs->forUser($userId);
        $hasLiveSub = $rows !== [];
        $outcomes = [];
        $delivered = 0;

        if ($push->configured() && $channel !== 'email') {
            foreach ($rows as $sub) {
                $result = $push->send($sub, $payload, 900);
                $outcomes[] = $result;
                if ($result === PushSender::OK) {
                    $subs->recordSuccess((int) $sub['id']);
                    $delivered++;
                } elseif ($result === PushSender::GONE) {
                    $subs->recordFailure((int) $sub['id']);
                }
            }
        }

        $plan = Reminders::channelPlan($channel, $hasLiveSub, $outcomes);
        $emailed = false;
        if ($plan['email'] && $email->isConfigured()) {
            $to = Settings::notifyDestination($settings, (string) $user['email'], $this->db);
            $emailed = $email->sendReminder($to, [
                'title' => $payload['title'],
                'body' => $payload['body'],
                'url' => $payload['url'],
            ]);
        }
        return ['push' => $delivered, 'email' => $emailed];
    }
}
