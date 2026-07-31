<?php

declare(strict_types=1);

// Generate VAPID keys for Web Push (run once, on the server or anywhere with
// ext-openssl), then add the printed lines to the app's .env:
//   php bin/vapid.php --generate
// Show whether the current environment has keys configured:
//   php bin/vapid.php --status

require dirname(__DIR__) . '/src/bootstrap.php';

$options = getopt('', ['generate', 'status']);
$modes = array_intersect(['generate', 'status'], array_keys($options));
if (count($modes) !== 1) {
    fwrite(STDERR, "Usage: php bin/vapid.php --generate | --status\n");
    exit(1);
}

function bc_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

if ($modes[array_key_first($modes)] === 'status') {
    $vapid = config()['vapid'];
    echo 'VAPID public key:  ' . ($vapid['public'] !== '' ? 'configured' : 'MISSING') . "\n";
    echo 'VAPID private key: ' . ($vapid['private'] !== '' ? 'configured' : 'MISSING') . "\n";
    echo 'VAPID subject:     ' . ($vapid['subject'] !== '' ? $vapid['subject'] : '(unset; base URL will be used)') . "\n";
    exit($vapid['public'] !== '' && $vapid['private'] !== '' ? 0 : 1);
}

if (!function_exists('openssl_pkey_new')) {
    fwrite(STDERR, "ext-openssl is required to generate VAPID keys.\n");
    exit(1);
}

$key = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);
if ($key === false) {
    fwrite(STDERR, 'Key generation failed: ' . (openssl_error_string() ?: 'unknown openssl error') . "\n");
    exit(1);
}
$details = openssl_pkey_get_details($key);
if ($details === false || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
    fwrite(STDERR, "Could not extract EC key material.\n");
    exit(1);
}

$pad = static fn(string $bin): string => str_pad($bin, 32, "\0", STR_PAD_LEFT);
$public = bc_b64url("\x04" . $pad($details['ec']['x']) . $pad($details['ec']['y']));
$private = bc_b64url($pad($details['ec']['d']));

echo "Generated VAPID key pair. Add these lines to the app's .env file\n";
echo "(keep the private key secret; changing keys invalidates existing subscriptions):\n\n";
echo 'BETTERCAL_VAPID_PUBLIC=' . $public . "\n";
echo 'BETTERCAL_VAPID_PRIVATE=' . $private . "\n";
echo 'BETTERCAL_VAPID_SUBJECT=mailto:calendar@oshyan.com' . "\n";
