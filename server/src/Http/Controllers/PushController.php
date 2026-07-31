<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\PushSubscriptions;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Infra\PushSender;

final class PushController
{
    public function __construct(
        private readonly PushSubscriptions $subscriptions,
        private readonly PushSender $sender,
    ) {
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
        ]);
    }

    /** POST /push/subscribe {endpoint, keys:{p256dh, auth}} (upsert) */
    public function subscribe(Request $req): Response
    {
        $this->subscriptions->subscribe((int) $req->user['id'], $req->body);
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
}
