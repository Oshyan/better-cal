<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\PushSubscriptions;
use BetterCal\Domain\SystemHealth;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Infra\EmailSender;

/** GET /system/health — is background work working, for the Settings panel and boot notices; POST /system/client-event — a start that never finished. */
final class SystemController
{
    public function __construct(
        private readonly SystemHealth $health,
        private readonly PushSubscriptions $subscriptions,
        private readonly EmailSender $email,
        private readonly array $cfg,
    ) {
    }

    /**
     * POST /system/client-event {kind:"boot-stalled", at, file?}: a start of
     * the app on this browser that never reached its first render (a white
     * screen), reported by the next start that did. One line in the server's
     * error log, beside the access log of that moment; nothing is stored.
     * Everything is validated to a fixed shape, so no client text reaches the
     * log as written. A signed-in browser only: it describes that browser.
     */
    public function clientEvent(Request $req): Response
    {
        $req->requireSession('Reporting a start of this browser');
        if (($req->body['kind'] ?? null) !== 'boot-stalled') {
            throw HttpError::badRequest('kind must be boot-stalled');
        }
        $at = self::recentInstant($req->body['at'] ?? null);
        if ($at === null) {
            throw HttpError::badRequest('at must be a time within the last 30 days');
        }
        $file = $req->body['file'] ?? null;
        if ($file !== null && (!is_string($file) || preg_match('~^/assets/[A-Za-z0-9/._-]{1,200}$~', $file) !== 1)) {
            throw HttpError::badRequest('file must be an app asset path');
        }
        error_log(sprintf(
            '[Better-Cal] client: a start of the app for user %d did not finish (began %s; %s)',
            (int) $req->user['id'],
            $at->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i:s.vP'),
            $file !== null ? 'loading ' . $file . ' failed' : 'no file failed outright, so a download likely hung',
        ));
        return Response::json(['ok' => true]);
    }

    /** An ISO 8601 instant no older than 30 days and not in the future (a day of clock skew allowed). Pure. */
    public static function recentInstant(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || strlen($raw) > 40 || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $raw) !== 1) {
            return null;
        }
        try {
            $t = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
        $now = time();
        return $t->getTimestamp() <= $now + 86400 && $t->getTimestamp() >= $now - 30 * 86400 ? $t : null;
    }

    public function health(Request $req): Response
    {
        $userId = (int) $req->user['id'];
        $rows = $this->health->snapshot($userId);
        // The client tells a push row is THIS device by hashing its local
        // endpoint. Never serialize the raw delivery capability merely so a
        // browser can compare identities.
        $endpointHashes = [];
        foreach ($this->subscriptions->forUser($userId) as $sub) {
            $endpointHashes['push:' . $sub['id']] = (string) $sub['endpoint_hash'];
        }
        foreach ($rows as &$r) {
            if ($r['kind'] === 'push') {
                $r['endpointHash'] = $endpointHashes[$r['subject']] ?? null;
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
