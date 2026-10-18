<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/** Test-only barrier after real password verification, before durable login. */
function password_verify(string $password, string $hash): bool
{
    $valid = \password_verify($password, $hash);
    if ($valid && isset($GLOBALS['credentialPasswordBarrier'])) {
        $barrier = $GLOBALS['credentialPasswordBarrier'];
        unset($GLOBALS['credentialPasswordBarrier']);
        $barrier();
    }
    return $valid;
}
