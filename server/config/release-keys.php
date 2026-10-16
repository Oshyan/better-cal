<?php

declare(strict_types=1);

// The keys whose signature makes a release installable by bin/update.php
// (Support\ReleaseSignature, docs/releasing.md). Any one is enough. Only
// public halves live here; the private ones never touch the repository or
// GitHub.
//
// Changing this list: a release signed by a key on the CURRENT list can add
// a key or drop one (a lost or retired key), because installs check each
// update with the list they already have.

return [
    // The maintainer's key file, kept in a password manager: the recovery signer.
    ['id' => 'file-2026-10-94994bd0', 'type' => 'ed25519', 'publicKey' => 'OaKbZCAzGvEuhPhum0xlNmO2ijxEeVcUDDftx+7Jk/M='],
    // The maintainer's hardware security key (FIDO2, PIN and touch): the everyday signer.
    ['id' => 'yubikey-1', 'type' => 'fido2-ed25519', 'rpId' => 'release-signing.better-cal', 'publicKey' => '19ZCGVKNsY16g8D+2d2mnioRUVsnGe1zMofx3qcLw34='],
];
