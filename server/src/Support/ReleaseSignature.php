<?php

declare(strict_types=1);

namespace BetterCal\Support;

/**
 * Release signatures (docs/releasing.md, Signing). Every release carries
 * bettercal-release.sig: one statement naming the version, its commit and the
 * SHA-256 of its zip and manifest, signed by at least one key listed in
 * server/config/release-keys.php. bin/update.php installs nothing unless one
 * of those signatures verifies, so publishing a release on GitHub is not
 * enough on its own to push code to installs: it also takes a signing key,
 * and those never touch GitHub.
 *
 * Two kinds of key, either one enough:
 * - "ed25519": a key file on the maintainer's machine (scripts/release-sign.php);
 * - "fido2-ed25519": a credential on a hardware security key, signed with its
 *   PIN and a touch (scripts/release-sign-fido.py). The key signs its
 *   authenticator data followed by SHA-256 of the statement (CTAP2
 *   getAssertion with the statement's hash as the client data hash).
 *
 * Verification is sodium's Ed25519 either way, so it needs nothing beyond
 * the extensions Better-Cal already requires.
 */
final class ReleaseSignature
{
    public const ASSET = 'bettercal-release.sig';
    public const MAX_BYTES = 16384;
    private const HEADER = 'Better-Cal release signature v1';

    /** The exact text that is signed. */
    public static function statement(string $version, string $commit, string $zipSha256, string $manifestSha256): string
    {
        if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1 || preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $zipSha256) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $manifestSha256) !== 1) {
            throw new \InvalidArgumentException('Bad release statement field');
        }
        return self::HEADER . "\nversion: $version\ncommit: $commit\nzip-sha256: $zipSha256\nmanifest-sha256: $manifestSha256\n";
    }

    /** @return array{version:string,commit:string,zip:string,manifest:string} */
    public static function parseStatement(string $statement): array
    {
        if (preg_match('/^' . preg_quote(self::HEADER, '/') . "\nversion: (\\d+\\.\\d+\\.\\d+)\ncommit: ([0-9a-f]{40})\nzip-sha256: ([0-9a-f]{64})\nmanifest-sha256: ([0-9a-f]{64})\n\$/D", $statement, $m) !== 1) {
            throw new \RuntimeException('The release signature statement is malformed');
        }
        return ['version' => $m[1], 'commit' => $m[2], 'zip' => $m[3], 'manifest' => $m[4]];
    }

    /**
     * The trusted keys this copy of Better-Cal ships with.
     *
     * @return list<array{id:string,type:string,publicKey:string,rpId?:string}>
     */
    public static function trustedKeys(): array
    {
        $keys = require dirname(__DIR__, 2) . '/config/release-keys.php';
        return is_array($keys) ? array_values($keys) : [];
    }

    /**
     * Check a signature file. Returns the statement's fields and the id of
     * the key that verified; throws unless the statement is well-formed and
     * at least one signature on it verifies with a trusted key.
     *
     * @param list<array{id:string,type:string,publicKey:string,rpId?:string}> $keys
     * @return array{version:string,commit:string,zip:string,manifest:string,key:string}
     */
    public static function verify(string $file, array $keys): array
    {
        if ($keys === []) {
            throw new \RuntimeException('This copy of Better-Cal has no release signing keys');
        }
        if (strlen($file) > self::MAX_BYTES) {
            throw new \RuntimeException('The release signature file is too large');
        }
        $data = json_decode($file, true);
        if (!is_array($data) || !is_string($data['statement'] ?? null) || !is_array($data['signatures'] ?? null)) {
            throw new \RuntimeException('The release signature file is malformed');
        }
        $fields = self::parseStatement($data['statement']);
        $byId = [];
        foreach ($keys as $key) {
            $byId[$key['id']] = $key;
        }
        foreach ($data['signatures'] as $sig) {
            if (!is_array($sig) || !is_string($sig['key'] ?? null) || !isset($byId[$sig['key']])) {
                continue;
            }
            if (self::check($byId[$sig['key']], $sig, $data['statement'])) {
                return $fields + ['key' => $sig['key']];
            }
        }
        throw new \RuntimeException('No signature on this release verifies with a key this copy of Better-Cal trusts');
    }

    private static function check(array $key, array $sig, string $statement): bool
    {
        $pk = base64_decode((string) $key['publicKey'], true);
        $signature = base64_decode((string) ($sig['sig'] ?? ''), true);
        if (!is_string($pk) || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        if ($key['type'] === 'ed25519' && ($sig['type'] ?? null) === 'ed25519') {
            return sodium_crypto_sign_verify_detached($signature, $statement, $pk);
        }
        if ($key['type'] === 'fido2-ed25519' && ($sig['type'] ?? null) === 'fido2') {
            $auth = base64_decode((string) ($sig['authData'] ?? ''), true);
            // rpIdHash (32) | flags (1) | signCount (4), nothing more: no extensions asked for.
            if (!is_string($auth) || strlen($auth) !== 37 || !hash_equals(hash('sha256', (string) $key['rpId'], true), substr($auth, 0, 32))) {
                return false;
            }
            $flags = ord($auth[32]);
            if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) {
                return false; // the key was not touched, or its PIN not given
            }
            return sodium_crypto_sign_verify_detached($signature, $auth . hash('sha256', $statement, true), $pk);
        }
        return false;
    }
}
