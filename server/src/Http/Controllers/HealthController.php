<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Http\Response;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

final class HealthController
{
    public function __construct(private readonly Db $db, private readonly array $cfg)
    {
    }

    /**
     * Anyone may ask whether the service is up (deploys and uptime checks
     * do); only a signed-in caller learns which version and what time the
     * server thinks it is. An anonymous visitor gets nothing that helps
     * fingerprint the install.
     */
    public function health(bool $signedIn = false): Response
    {
        $dbOk = true;
        try {
            $this->db->scalar('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }
        $out = ['ok' => true, 'db' => $dbOk];
        if ($signedIn) {
            $out['time'] = Time::iso(Time::nowUtc()->setTimezone(Time::zone(date_default_timezone_get())));
            $out['version'] = $this->cfg['version'];
        }
        return Response::json($out);
    }
}
