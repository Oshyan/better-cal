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

    /** How many other browsers are signed in, for the Account tab. */
    public function otherSessions(Request $req): Response
    {
        $req->requireSession('Seeing where you are signed in');
        return Response::json(['others' => $this->auth->otherSessionCount((int) $req->user['id'], $req->cookies[Auth::COOKIE] ?? null)]);
    }

    /**
     * Sign out every browser and device but this one (Auth::signOutOthers).
     * Session only: an API token must not be able to sign the owner out, and
     * "this browser" means the session cookie making the request. The page
     * sends this browser's push endpoint hash so its reminders keep coming;
     * the device cookie arrives by itself (this route is under its path).
     */
    public function signOutOthers(Request $req): Response
    {
        $req->requireSession('Signing out everywhere else');
        $userId = (int) $req->user['id'];
        $keepPush = $req->str('keepPushHash');
        if ($keepPush !== null && preg_match('/^[0-9a-f]{64}$/', $keepPush) !== 1) {
            $keepPush = null;
        }
        $device = $this->devices?->find($req->cookies[TrustedDevices::COOKIE] ?? null, (string) ($req->user['email'] ?? ''));
        return Response::json($this->auth->signOutOthers($userId, $req->cookies[Auth::COOKIE] ?? null, $device, $keepPush));
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
