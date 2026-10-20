<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Domain\ExternalActionAdmission;
use BetterCal\Domain\PushSubscriptions;
use BetterCal\Domain\Reminders;
use BetterCal\Domain\Settings;
use BetterCal\Domain\SystemHealth;

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
     * $ownerOnly is for security notices: push and email both, and only to
     * destinations the owner set up from a browser. An API key can choose
     * the channel, an address and a push device; a stolen key must not be
     * able to receive the notice in the owner's place, and so have it
     * marked delivered (review of 0.9.87).
     *
     * @return array{push:int,email:bool} pushes delivered, email sent
     */
    public function send(int $userId, string $title, string $body, string $url = '/', string $tag = '', bool $ownerOnly = false): array
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
        $rows = $subs->forUser($userId);
        if ($ownerOnly) [$settings, $rows] = self::ownerOnly($settings, $rows);
        $channel = (string) ($settings['notifyChannel'] ?? 'push-fallback');
        $hasLiveSub = $rows !== [];
        $outcomes = [];
        $delivered = 0;

        if ($push->configured() && $channel !== 'email') {
            foreach ($rows as $sub) {
                $sub = $subs->currentForSend($sub);
                if ($sub === null) continue;
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
            $admitted = (new ExternalActionAdmission($this->db))->admitEmail($userId, $to, null);
            $health = new SystemHealth($this->db);
            if ($admitted['admitted']) {
                $current = $ownerOnly && $to === (string) $user['email'] || \BetterCal\Domain\NotificationDestinations::email($this->db, $userId, $to);
                $emailed = $current && $email->sendReminder($to, [
                    'title' => $payload['title'],
                    'body' => $payload['body'],
                    'url' => $payload['url'],
                ]);
                try {
                    $health->recordOk('notification-email-budget:' . $userId, 'job', $userId, 'Notification email delivery', false);
                } catch (\Throwable $e) {
                    error_log('notification email health recovery could not be recorded: ' . $e->getMessage());
                }
            } else {
                try {
                    $health->recordFailure(
                        'notification-email-budget:' . $userId,
                        'job',
                        $userId,
                        'Notification email delivery',
                        'An ad-hoc notification email was suppressed because the persistent delivery safety limit was reached.'
                    );
                } catch (\Throwable $e) {
                    error_log('notification email health failure could not be recorded: ' . $e->getMessage());
                }
                error_log('notification email safety budget suppressed one delivery attempt for user ' . $userId);
            }
        }
        return ['push' => $delivered, 'email' => $emailed];
    }

    /**
     * Settings and push devices narrowed to what the owner set up from a
     * browser: both channels, the account address in place of a key-set
     * one, and no key-registered push devices.
     *
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
     */
    public static function ownerOnly(array $settings, array $rows): array
    {
        $settings['notifyChannel'] = 'both';
        if (!empty($settings['notifyEmailToken'])) {
            $settings['notifyEmail'] = null;
            $settings['notifyEmailToken'] = null;
        }
        return [$settings, array_values(array_filter($rows, static fn(array $r): bool => empty($r['created_by_token_id'])))];
    }
}
