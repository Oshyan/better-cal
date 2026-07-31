<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Settings;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class SettingsController
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function index(Request $req): Response
    {
        return Response::json(['settings' => $this->settings->forUser((int) $req->user['id'])]);
    }

    public function patch(Request $req): Response
    {
        return Response::json(['settings' => $this->settings->patch((int) $req->user['id'], $req->body)]);
    }
}
