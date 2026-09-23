<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\PushSubscriptions;
use BetterCal\Domain\Settings;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Infra\EmailSender;
use BetterCal\Infra\PushSender;
use BetterCal\Infra\Throttle;

final class PushController
{
    public function __construct(
        private readonly PushSubscriptions $subscriptions,
        private readonly PushSender $sender,
        private readonly EmailSender $email,
        private readonly ?Throttle $throttle = null,
    ) {
    }

    /** A handful of test sends is plenty to see that delivery works. */
    private const TEST_SENDS = 5;
    private const TEST_WINDOW = 600;

    /**
     * "Send a test" reaches an address the caller chose (notifyEmail is any
     * syntactically valid address) with the server's own SMTP identity, as
     * often as it is called: a way to mail-bomb a stranger and burn the
     * sender's reputation (BC-19). Five per ten minutes, per account, across
     * both test buttons.
     */
    private function limitTestSends(Request $req): void
    {
        if ($this->throttle === null) {
            return;
        }
        $bucket = 'test-send:user:' . (int) $req->user['id'];
        $wait = $this->throttle->retryAfter($bucket, self::TEST_SENDS, self::TEST_WINDOW);
        if ($wait > 0) {
            throw new HttpError('too_many_requests', 'That is enough tests for now. Try again in ' . max(1, (int) ceil($wait / 60)) . ' minute(s).', 429);
        }
        $this->throttle->hit($bucket);
    }

    /** GET /push/key: the VAPID public key the client subscribes with. */
    public function key(): Response
    {
        return Response::json(['key' => $this->sender->publicKey()]);
    }

    /** GET /push/status */
    public function status(Request $req): Response
    {
        return Response::json([
            'subscribed' => $this->subscriptions->hasAny((int) $req->user['id']),
            'vapidConfigured' => $this->sender->configured(),
            'emailConfigured' => $this->email->isConfigured(),
        ]);
    }

    /** POST /push/subscribe {endpoint, keys:{p256dh, auth}} (upsert) */
    public function subscribe(Request $req): Response
    {
        $req->requireSession('Registering a device for reminders');
        $this->subscriptions->subscribe((int) $req->user['id'], $req->body);
        return Response::json(['ok' => true]);
    }

    /** GET /push/devices: every device reminders go to (session only). */
    public function devices(Request $req): Response
    {
        $req->requireSession('Listing reminder devices');
        return Response::json(['devices' => $this->subscriptions->devices((int) $req->user['id'])]);
    }

    /** DELETE /push/devices/:id (session only). */
    public function removeDevice(Request $req, array $params): Response
    {
        $req->requireSession('Removing a reminder device');
        $this->subscriptions->remove((int) $req->user['id'], (int) $params['id']);
        return Response::json(['ok' => true]);
    }

    /** POST /push/unsubscribe {endpoint} */
    public function unsubscribe(Request $req): Response
    {
        $this->subscriptions->unsubscribe((int) $req->user['id'], (string) ($req->str('endpoint') ?? ''));
        return Response::json(['ok' => true]);
    }

    /** POST /push/test: send a test notification to all of the user's subscriptions now. */
    public function test(Request $req): Response
    {
        if (!$this->sender->configured()) {
            throw HttpError::badRequest('Server VAPID keys are not configured', 'push_not_configured');
        }
        $subs = $this->subscriptions->forUser((int) $req->user['id']);
        if ($subs === []) {
            throw HttpError::badRequest('No push subscription for this account; enable notifications first', 'no_subscription');
        }
        $this->limitTestSends($req);
        $payload = [
            'title' => 'Better-Cal test notification',
            'body' => 'Push notifications are working.',
            'url' => '/',
            'tag' => 'bettercal-test',
        ];
        $sent = 0;
        $failed = 0;
        foreach ($subs as $sub) {
            $result = $this->sender->send($sub, $payload);
            if ($result === PushSender::OK) {
                $this->subscriptions->recordSuccess((int) $sub['id']);
                $sent++;
            } else {
                if ($result === PushSender::GONE) {
                    $this->subscriptions->recordFailure((int) $sub['id']);
                }
                $failed++;
            }
        }
        return Response::json(['ok' => $sent > 0, 'sent' => $sent, 'failed' => $failed]);
    }

    /** POST /push/test-email: send a test reminder email to the user's notifyEmail setting, else the account address. */
    public function testEmail(Request $req): Response
    {
        if (!$this->email->isConfigured()) {
            throw new HttpError(
                'email_not_configured',
                'Email is not configured on this server (set BETTERCAL_SMTP_HOST and BETTERCAL_SMTP_FROM in .env)',
                501
            );
        }
        $this->limitTestSends($req);
        $stored = is_string($req->user['settings_json'] ?? null)
            ? json_decode((string) $req->user['settings_json'], true)
            : null;
        $to = Settings::notifyDestination(
            Settings::withDefaults(is_array($stored) ? $stored : []),
            (string) ($req->user['email'] ?? '')
        );
        $ok = $this->email->sendReminder($to, [
            'title' => 'Better-Cal test email',
            'body' => 'Email notifications are working.',
            'url' => '/',
        ]);
        if (!$ok) {
            throw HttpError::badRequest('Sending failed; check the server SMTP settings and logs', 'email_send_failed');
        }
        return Response::json(['ok' => true, 'to' => $to]);
    }
}
