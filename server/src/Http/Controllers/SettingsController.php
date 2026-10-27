<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Auth;
use BetterCal\Domain\MailPolicy;
use BetterCal\Domain\Settings;
use BetterCal\Http\HttpError;
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
        // A reminder address set with an API token is remembered with that token
        // and stops receiving mail once the token is revoked or expires (F6).
        return Response::json(['settings' => $this->settings->patch(
            (int) $req->user['id'],
            $req->body,
            $req->authMethod === 'token' ? $req->tokenId : null,
            $req->authMethod === 'session' ? ($req->cookies[Auth::COOKIE] ?? null) : null,
        )]);
    }

    /** Read a pasted approved-senders list for the bulk editor; saving is the ordinary PATCH. */
    public function parseMailSenders(Request $req): Response
    {
        $text = $req->body['text'] ?? null;
        if (!is_string($text)) {
            throw HttpError::badRequest('text is required');
        }
        return Response::json(MailPolicy::parseList($text));
    }
}
