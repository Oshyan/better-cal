<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Auth;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class AuthController
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function login(Request $req): Response
    {
        $email = (string) ($req->str('email') ?? '');
        $password = (string) ($req->str('password') ?? '');
        $result = $this->auth->login($email, $password);
        if ($result === null) {
            return Response::error('invalid_credentials', 'Email or password is incorrect', 401);
        }
        return Response::json(['ok' => true])
            ->withCookie(Auth::COOKIE, $result['token'], $this->auth->cookieOptions());
    }

    public function logout(Request $req): Response
    {
        $this->auth->logout($req->cookies[Auth::COOKIE] ?? null);
        return Response::json(['ok' => true])
            ->withCookie(Auth::COOKIE, '', $this->auth->cookieOptions(clear: true));
    }

    public function me(Request $req): Response
    {
        return Response::json([
            'user' => Auth::serializeUser($req->user),
            'csrf' => $req->csrf,
        ]);
    }
}
