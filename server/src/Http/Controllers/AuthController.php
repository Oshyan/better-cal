<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Auth;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\LoginGuard;
use BetterCal\Domain\TrustedDevices;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class AuthController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly ?LoginGuard $guard = null,
        private readonly ?TrustedDevices $devices = null,
        private readonly ?GoogleAuth $google = null,
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
        $req->requireSession('Signing out this browser');
        $pushHash = $req->str('pushEndpointHash');
        if ($pushHash !== null && preg_match('/^[0-9a-f]{64}$/', $pushHash) !== 1) {
            throw HttpError::badRequest('pushEndpointHash must be a lowercase SHA-256 value', 'invalid_push_endpoint_hash');
        }
        $this->auth->logout($req->cookies[Auth::COOKIE] ?? null, $pushHash);
        return Response::json(['ok' => true])
            ->withCookie(Auth::COOKIE, '', $this->auth->cookieOptions(clear: true));
    }

    /** Confirm the password for sensitive actions without creating a new session. */
    public function stepUp(Request $req): Response
    {
        $req->requireSession('Confirming your password');
        $email = (string) ($req->user['email'] ?? '');
        $source = $this->guard?->source($_SERVER) ?? '';
        $device = $this->devices?->find($req->cookies[TrustedDevices::COOKIE] ?? null, $email);
        $wait = $this->guard?->begin($source, null, $device) ?? 0;
        if ($wait > 0) {
            return self::tooMany($wait, $this->guard?->refusal);
        }
        $ok = $this->auth->confirmPassword(
            (int) $req->user['id'],
            $req->cookies[Auth::COOKIE] ?? null,
            (string) ($req->str('password') ?? '')
        );
        if (!$ok) {
            $this->guard?->rejected($source, 'web');
            // Unlike a login 401, this must not make the API client discard a
            // still-valid session and all of its private caches.
            return Response::error('invalid_credentials', 'Password is incorrect', 403);
        }
        $this->guard?->succeeded($source);
        return Response::json(['ok' => true]);
    }

    /**
     * Change the password and sign out every browser; with `everything`, also
     * revoke API keys, change feed addresses and pause Google. Session only,
     * with the current password, under the same guessing limits as sign-in.
     * This browser is signed in again under the new password.
     */
    public function changePassword(Request $req): Response
    {
        $req->requireSession('Changing your password');
        $new = (string) ($req->str('newPassword') ?? '');
        if (strlen($new) < 8 || strlen($new) > 1024) {
            throw HttpError::badRequest('The new password needs 8 to 1024 characters.', 'invalid_password');
        }
        $userId = (int) $req->user['id'];
        $email = (string) ($req->user['email'] ?? '');
        $source = $this->guard?->source($_SERVER) ?? '';
        $device = $this->devices?->find($req->cookies[TrustedDevices::COOKIE] ?? null, $email);
        $wait = $this->guard?->begin($source, null, $device) ?? 0;
        if ($wait > 0) {
            return self::tooMany($wait, $this->guard?->refusal);
        }
        $everything = filter_var($req->body['everything'] ?? false, FILTER_VALIDATE_BOOL);
        $out = $this->auth->changePassword($userId, $req->cookies[Auth::COOKIE] ?? null, (string) ($req->str('currentPassword') ?? ''), $new, $everything);
        if ($out === null) {
            $this->guard?->rejected($source, 'web');
            return Response::error('invalid_credentials', 'Current password is incorrect', 403);
        }
        $this->guard?->succeeded($source);
        if ($everything && $out['googleAccounts'] > 0) {
            try {
                $this->google?->revokeQuarantinedTokens($userId);
            } catch (\Throwable $e) {
                error_log('Google revocation after a password change failed: ' . $e->getMessage());
            }
        }
        $login = $this->auth->login($email, $new);
        $response = Response::json(['ok' => true] + $out);
        if ($login !== null) {
            $response = $response->withCookie(Auth::COOKIE, $login['token'], $this->auth->cookieOptions());
            $deviceToken = $this->devices?->remember($userId);
            if ($deviceToken !== null) {
                $response = $response->withCookie(TrustedDevices::COOKIE, $deviceToken, $this->auth->deviceCookieOptions());
            }
        }
        return $response;
    }

    /** How many other browsers are signed in, for the Account tab. */
    public function otherSessions(Request $req): Response
    {
        $req->requireSession('Seeing where you are signed in');
        return Response::json(['others' => $this->auth->otherSessionCount((int) $req->user['id'], $req->cookies[Auth::COOKIE] ?? null)]
            + $this->auth->subscriptionRecoveryPreview((int) $req->user['id'], $req->cookies[Auth::COOKIE] ?? null));
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
        $review = $req->body['subscriptionReview'] ?? null;
        if ($review !== null && !is_array($review)) throw HttpError::badRequest('subscriptionReview must be an object');
        return Response::json($this->auth->signOutOthers($userId, $req->cookies[Auth::COOKIE] ?? null, $device, $keepPush, $review));
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
            ? 'Sign-ins from new devices are paused because of failed attempts from elsewhere. Use a browser or network that has signed in here before.'
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
