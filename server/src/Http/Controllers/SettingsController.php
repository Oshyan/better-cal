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
        // Where reminder email goes is a standing channel out of the account (F6).
        if (array_key_exists('notifyEmail', $req->body) || array_key_exists('notifyChannel', $req->body)) {
            $req->requireSession('Changing where reminders are sent');
        }
        return Response::json(['settings' => $this->settings->patch((int) $req->user['id'], $req->body)]);
    }
}
