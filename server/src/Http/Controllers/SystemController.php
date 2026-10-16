<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\PushSubscriptions;
use BetterCal\Domain\SystemHealth;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Infra\EmailSender;
use BetterCal\Infra\Setup;
use BetterCal\Infra\PrivateFilesCheck;

/**
 * GET /system/health — is background work working, for the Settings panel and boot notices;
 * POST /system/client-event — a start that never finished;
 * GET /system/private-files and POST /system/private-files/check — can the private files be downloaded (#142).
 */
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

    /** POST /system/worker-off: the owner turned the worker off on purpose; no warnings until it runs again. */
    public function workerOff(Request $req): Response
    {
        $req->requireSession('Turning the background worker off');
        \BetterCal\Domain\WorkerWatch::turnOff($this->health->db());
        return Response::json(['worker' => \BetterCal\Domain\WorkerWatch::state($this->health->db())]);
    }

    /** The last private-files check (null when it has never run), and the seconds until "Check now" may run. */
    public function privateFiles(Request $req): Response
    {
        $req->requireSession('Reading the private-files check');
        $root = (string) $this->cfg['app_root'];
        return Response::json(['result' => PrivateFilesCheck::last($root), 'wait' => PrivateFilesCheck::wait($root)]);
    }

    /** Run the check now: at most once a minute, since it asks other servers. */
    public function checkPrivateFiles(Request $req): Response
    {
        $req->requireSession('Checking the private files');
        $root = (string) $this->cfg['app_root'];
        $wait = PrivateFilesCheck::wait($root);
        if ($wait > 0) {
            throw new HttpError('too_soon', "It was checked moments ago; try again in $wait seconds.", 429);
        }
        @set_time_limit(60);
        $result = PrivateFilesCheck::run($this->cfg);
        return Response::json(['result' => $result, 'wait' => PrivateFilesCheck::COOLDOWN_SECONDS]);
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
        // A place search service not in use (turned off, or replaced) is no
        // longer news, whatever its last state; the weather service isn't one.
        $places = \BetterCal\Domain\PlaceProviders::healthSubjects($this->cfg['places'] ?? []);
        $rows = array_values(array_filter($rows, static fn(array $r): bool => !str_starts_with((string) $r['subject'], 'geocoder:')
            || $r['subject'] === 'geocoder:open-meteo' || in_array($r['subject'], $places, true)));
        foreach ($rows as &$r) {
            if ($r['kind'] === 'push') {
                $r['endpointHash'] = $endpointHashes[$r['subject']] ?? null;
            }
        }
        unset($r);
        $alertTo = (string) ($this->cfg['alert_email'] ?? '');
        $root = (string) $this->cfg['app_root'];
        $php = Setup::workerPhp($root);
        return Response::json([
            'rows' => $rows,
            // running, never, off or stopped (Domain\WorkerWatch::state).
            'worker' => \BetterCal\Domain\WorkerWatch::state($this->health->db()),
            // The last private-files verdict, for the attention dot (null before the first check).
            'privateFiles' => PrivateFilesCheck::last($root)['status'] ?? null,
            // The worker's cron job as this server needs it now (Setup::workerPhp).
            'cron' => [
                'command' => Setup::cronCommand($root, $php['php']),
                'line' => Setup::cronLine($root, $php['php']),
                'followsServerPhp' => $php['follows'],
            ],
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
