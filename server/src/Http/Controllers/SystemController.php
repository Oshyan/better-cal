<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\PushSubscriptions;
use BetterCal\Domain\SystemHealth;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Infra\EmailSender;

/** GET /system/health — is background work working, for the Settings panel and boot notices. */
final class SystemController
{
    public function __construct(
        private readonly SystemHealth $health,
        private readonly PushSubscriptions $subscriptions,
        private readonly EmailSender $email,
        private readonly array $cfg,
    ) {
    }

    public function health(Request $req): Response
    {
        $userId = (int) $req->user['id'];
        $rows = $this->health->snapshot($userId);
        // The client tells a push row is THIS device by endpoint; attach the
        // user's own endpoints (nobody else's) so it can match.
        $endpoints = [];
        foreach ($this->subscriptions->forUser($userId) as $sub) {
            $endpoints['push:' . $sub['id']] = (string) $sub['endpoint'];
        }
        foreach ($rows as &$r) {
            if ($r['kind'] === 'push') {
                $r['endpoint'] = $endpoints[$r['subject']] ?? null;
            }
        }
        unset($r);
        $alertTo = (string) ($this->cfg['alert_email'] ?? '');
        return Response::json([
            'rows' => $rows,
            'emailAlerts' => [
                'configured' => $this->email->isConfigured(),
                // Per-user subjects always go to the account email; this is
                // where system-wide ones go.
                'systemTo' => $alertTo !== '' ? $alertTo : (string) $req->user['email'],
                'accountTo' => (string) $req->user['email'],
            ],
        ]);
    }
}
