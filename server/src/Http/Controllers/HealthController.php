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

    public function health(): Response
    {
        $dbOk = true;
        try {
            $this->db->scalar('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }
        return Response::json([
            'ok' => true,
            'time' => Time::iso(Time::nowUtc()->setTimezone(Time::zone(date_default_timezone_get()))),
            'db' => $dbOk,
            'version' => $this->cfg['version'],
        ]);
    }
}
