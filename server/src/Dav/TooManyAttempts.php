<?php

declare(strict_types=1);

namespace BetterCal\Dav;

use Sabre\DAV\Server;

/**
 * 429 for a CalDAV client whose address has sent too many wrong passwords.
 *
 * Deliberately not a 401: a 401 tells a calendar app "that password is wrong",
 * and some respond by discarding the saved password and prompting the owner.
 * A 429 with Retry-After says "later", which is the truth, and syncing resumes
 * by itself when the window rolls on. sabre/dav ships no such exception.
 */
final class TooManyAttempts extends \Sabre\DAV\Exception
{
    public function __construct(private readonly int $retryAfter)
    {
        parent::__construct('Too many failed sign-in attempts from this address. Try again in ' . max(1, (int) ceil($retryAfter / 60)) . ' minute(s).');
    }

    public function getHTTPCode()
    {
        return 429;
    }

    public function getHTTPHeaders(Server $server)
    {
        return ['Retry-After' => (string) $this->retryAfter];
    }
}
