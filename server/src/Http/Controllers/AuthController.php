<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Auth;
use BetterCal\Domain\LoginGuard;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class AuthController
{
    public function __construct(private readonly Auth $auth, private readonly ?LoginGuard $guard = null)
    {
    }

    public function login(Request $req): Response
    {
        $email = (string) ($req->str('email') ?? '');
        $password = (string) ($req->str('password') ?? '');

        // Too many wrong passwords from this address: refuse before looking at
        // the password at all, so a blocked source cannot keep guessing (or
        // keep the server busy hashing). Same counters as CalDAV sign-in.
        $source = $this->guard?->source($_SERVER) ?? '';
        $wait = $this->guard?->begin($source) ?? 0;
        if ($wait > 0) {
            return self::tooMany($wait);
        }

        $result = $this->auth->login($email, $password);
        if ($result === null) {
            $this->guard?->rejected($source, 'web');
            return Response::error('invalid_credentials', 'Email or password is incorrect', 401);
        }
        $this->guard?->succeeded($source);
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

    /** 429 with Retry-After, and the wait spelled out for the person reading the login form. */
    public static function tooMany(int $wait): Response
    {
        $minutes = (int) ceil($wait / 60);
        return Response::json(
            ['error' => [
                'code' => 'too_many_attempts',
                'message' => 'Too many wrong passwords from this network. Try again in ' . ($minutes <= 1 ? 'a minute' : $minutes . ' minutes') . '.',
                'retryAfter' => $wait,
            ]],
            429
        )->withHeader('Retry-After', (string) $wait);
    }
}
