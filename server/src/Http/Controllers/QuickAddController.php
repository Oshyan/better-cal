<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\QuickAdd;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class QuickAddController
{
    public function __construct(private readonly QuickAdd $quickAdd)
    {
    }

    public function run(Request $req): Response
    {
        $result = $this->quickAdd->run(
            (int) $req->user['id'],
            (string) ($req->str('text') ?? ''),
            (string) ($req->str('tz') ?? 'UTC'),
            $req->bool('commit'),
            isset($req->body['calendarId']) ? (int) $req->body['calendarId'] : null
        );
        $out = ['draft' => $result['draft']];
        if ($result['event'] !== null) {
            $out['event'] = $result['event'];
        }
        if (($result['availability'] ?? null) !== null) {
            $out['availability'] = $result['availability'];
        }
        $created = $result['event'] !== null || ($result['availability'] ?? null) !== null;
        return Response::json($out, $created ? 201 : 200);
    }
}
