<?php

declare(strict_types=1);

// Release signing with a key file (docs/releasing.md, Signing). Maintainer
// tooling: installs never run this, they only verify.
//
//   php scripts/release-sign.php --new-key
//       make a key pair; the private half goes to the key file (owner-only,
//       never replaced), the public half is printed for server/config/release-keys.php
//   php scripts/release-sign.php --statement --version=X.Y.Z --commit=SHA --zip=FILE --manifest=FILE
//       print the statement a release signs
//   php scripts/release-sign.php --sign --statement-file=FILE --out=SIG [--key-id=ID]
//       sign it with the key file, adding to SIG (created if missing)
//   php scripts/release-sign.php --verify=SIG [--version=… --commit=… --zip=FILE --manifest=FILE] [--keys=FILE]
//       check SIG against the keys in server/config/release-keys.php (or FILE, an
//       earlier release's copy of it), and that it names these files
//
// The key file is ~/.config/better-cal/release-signing.key, or BETTERCAL_RELEASE_KEY.
// Keep a copy in a password manager: without it, and without the hardware
// key, no further release can be signed.

// Verification/signing must not execute Composer packages or load site settings.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/server/src/Support/ReleaseSignature.php';

use BetterCal\Support\ReleaseSignature;

$opt = getopt('', ['new-key', 'statement', 'sign', 'verify:', 'version:', 'commit:', 'zip:', 'manifest:', 'statement-file:', 'out:', 'key-id:', 'keys:']);
$keyPath = getenv('BETTERCAL_RELEASE_KEY') ?: (getenv('HOME') . '/.config/better-cal/release-signing.key');
$fail = static function (string $msg): never {
    fwrite(STDERR, "release-sign: $msg\n");
    exit(1);
};

if (isset($opt['new-key'])) {
    if (file_exists($keyPath)) {
        $fail("$keyPath already exists; not replacing a signing key");
    }
    @mkdir(dirname($keyPath), 0700, true);
    $pair = sodium_crypto_sign_keypair();
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $old = umask(0077);
    $fh = fopen($keyPath, 'x');
    if ($fh === false) {
        $fail("could not create $keyPath");
    }
    fwrite($fh, base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
    fclose($fh);
    umask($old);
    chmod($keyPath, 0600);
    $id = 'file-' . gmdate('Y-m') . '-' . substr(hash('sha256', $public), 0, 8);
    echo "Wrote $keyPath. Copy it into your password manager now.\n\n";
    echo "Add this to server/config/release-keys.php:\n\n";
    echo "    ['id' => '$id', 'type' => 'ed25519', 'publicKey' => '$public'],\n";
    exit(0);
}

$sha = static function (string $path) use ($fail): string {
    $h = @hash_file('sha256', $path);
    return $h === false ? $fail("cannot read $path") : $h;
};

if (isset($opt['statement'])) {
    foreach (['version', 'commit', 'zip', 'manifest'] as $k) {
        if (!isset($opt[$k])) {
            $fail("--statement needs --$k");
        }
    }
    $statement = ReleaseSignature::statement($opt['version'], $opt['commit'], $sha($opt['zip']), $sha($opt['manifest']));
    if (isset($opt['out'])) {
        file_put_contents($opt['out'], $statement);
    } else {
        echo $statement;
    }
    exit(0);
}

if (isset($opt['sign'])) {
    if (!isset($opt['statement-file'], $opt['out'])) {
        $fail('--sign needs --statement-file and --out');
    }
    $statement = (string) file_get_contents($opt['statement-file']);
    ReleaseSignature::parseStatement($statement);
    $secret = base64_decode(trim((string) @file_get_contents($keyPath)), true);
    if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        $fail("no usable signing key at $keyPath");
    }
    $public = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    $id = $opt['key-id'] ?? null;
    foreach (ReleaseSignature::trustedKeys() as $key) {
        if ($key['publicKey'] === $public) {
            $id = $key['id'];
        }
    }
    if ($id === null) {
        $fail("the key at $keyPath is not in server/config/release-keys.php");
    }
    $file = is_file($opt['out']) ? json_decode((string) file_get_contents($opt['out']), true) : ['statement' => $statement, 'signatures' => []];
    if (!is_array($file) || ($file['statement'] ?? null) !== $statement) {
        $fail("{$opt['out']} signs a different statement");
    }
    $file['signatures'] = array_values(array_filter($file['signatures'], static fn($s) => ($s['key'] ?? null) !== $id));
    $file['signatures'][] = ['key' => $id, 'type' => 'ed25519', 'sig' => base64_encode(sodium_crypto_sign_detached($statement, $secret))];
    file_put_contents($opt['out'], json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    sodium_memzero($secret);
    echo "Signed with $id.\n";
    exit(0);
}

if (isset($opt['verify'])) {
    try {
        $keys = isset($opt['keys']) ? (static fn(string $f) => require $f)($opt['keys']) : ReleaseSignature::trustedKeys();
        if (!is_array($keys)) {
            $fail("{$opt['keys']} isn't a key list");
        }
        $ok = ReleaseSignature::verify((string) @file_get_contents($opt['verify']), array_values($keys));
    } catch (\Throwable $e) {
        $fail($e->getMessage());
    }
    $checks = ['version' => $opt['version'] ?? null, 'commit' => $opt['commit'] ?? null,
        'zip' => isset($opt['zip']) ? $sha($opt['zip']) : null, 'manifest' => isset($opt['manifest']) ? $sha($opt['manifest']) : null];
    foreach ($checks as $field => $want) {
        if ($want !== null && $ok[$field] !== $want) {
            $fail("the signature names a different $field");
        }
    }
    echo "Verified: {$ok['version']} ({$ok['commit']}), signed by {$ok['key']}.\n";
    exit(0);
}

fwrite(STDERR, "usage: see the comment at the top of scripts/release-sign.php\n");
exit(2);
