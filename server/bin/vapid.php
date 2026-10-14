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

if ($modes[array_key_first($modes)] === 'status') {
    $vapid = config()['vapid'];
    echo 'VAPID public key:  ' . ($vapid['public'] !== '' ? 'configured' : 'MISSING') . "\n";
    echo 'VAPID private key: ' . ($vapid['private'] !== '' ? 'configured' : 'MISSING') . "\n";
    echo 'VAPID subject:     ' . ($vapid['subject'] !== '' ? $vapid['subject'] : '(unset; base URL will be used)') . "\n";
    exit($vapid['public'] !== '' && $vapid['private'] !== '' ? 0 : 1);
}

try {
    $keys = BetterCal\Infra\Setup::vapidKeys();
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$public = $keys['public'];
$private = $keys['private'];

echo "Generated VAPID key pair. Add these lines to the app's .env file\n";
echo "(keep the private key secret; changing keys invalidates existing subscriptions):\n\n";
echo 'BETTERCAL_VAPID_PUBLIC=' . $public . "\n";
echo 'BETTERCAL_VAPID_PRIVATE=' . $private . "\n";
echo 'BETTERCAL_VAPID_SUBJECT=mailto:you@example.com' . "\n";
