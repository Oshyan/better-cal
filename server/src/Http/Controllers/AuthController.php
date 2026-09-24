<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Auth;
use BetterCal\Domain\LoginGuard;
use BetterCal\Domain\TrustedDevices;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class AuthController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly ?LoginGuard $guard = null,
        private readonly ?TrustedDevices $devices = null,
    ) {
    }

    public function login(Request $req): Response
    {
        $email = (string) ($req->str('email') ?? '');
        $password = (string) ($req->str('password') ?? '');

        // Too many wrong passwords from this address: refuse before looking at
        // the password at all, so a blocked source cannot keep guessing (or
        // keep the server busy hashing). Same counters as CalDAV sign-in.
        // A browser that signed in here before carries a device cookie, which
        // gets it past the overall brake from any network (issue #59). Looked
        // up before the password; it never signs anyone in by itself.
        $source = $this->guard?->source($_SERVER) ?? '';
        $device = $this->devices?->find($req->cookies[TrustedDevices::COOKIE] ?? null, $email);
        $wait = $this->guard?->begin($source, null, $device) ?? 0;
        if ($wait > 0) {
            return self::tooMany($wait, $this->guard?->refusal);
        }

        $result = $this->auth->login($email, $password);
        if ($result === null) {
            $this->guard?->rejected($source, 'web');
            return Response::error('invalid_credentials', 'Email or password is incorrect', 401);
        }
        $this->guard?->succeeded($source);
        $response = Response::json(['ok' => true])
            ->withCookie(Auth::COOKIE, $result['token'], $this->auth->cookieOptions());
        $deviceToken = $this->devices?->remember((int) $result['user']['id'], $device);
        if ($deviceToken !== null) {
            $response = $response->withCookie(TrustedDevices::COOKIE, $deviceToken, $this->auth->deviceCookieOptions());
        }
        return $response;
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

    /**
     * 429 with Retry-After, and the wait spelled out for the person reading
     * the login form. The overall brake gets its own words: the person
     * reading it did nothing wrong, and a device that has signed in here
     * before would get through.
     */
    public static function tooMany(int $wait, ?string $refusal = null): Response
    {
        $minutes = (int) ceil($wait / 60);
        $when = 'Try again in ' . ($minutes <= 1 ? 'a minute' : $minutes . ' minutes') . '.';
        $message = $refusal === 'brake'
            ? 'Sign-ins from new devices are paused because of many failed attempts from elsewhere. Browsers that have signed in here before can still sign in. ' . $when
            : 'Too many wrong passwords from this network. ' . $when;
        return Response::json(
            ['error' => [
                'code' => 'too_many_attempts',
                'message' => $message,
                'retryAfter' => $wait,
            ]],
            429
        )->withHeader('Retry-After', (string) $wait);
    }
}
