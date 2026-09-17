<?php

declare(strict_types=1);

// Creates or updates the single user:
//   php bin/seed.php --email=... --password=... [--name=...] [--revoke-tokens]
// Also creates a default "Personal" calendar when the user has none.
//
// Setting the password of an existing user signs every browser out: a reset is
// what you do when someone else may be in, so their session must not outlive
// it. API tokens (CalDAV clients, the MCP server) survive unless you pass
// --revoke-tokens, which is the "I was compromised" switch: everything that
// could act as this account stops working and has to be re-issued.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Domain\Auth;
use BetterCal\Infra\Db;

$options = getopt('', ['email:', 'password:', 'name::', 'revoke-tokens']);
$email = trim((string) ($options['email'] ?? ''));
$password = (string) ($options['password'] ?? '');
$name = trim((string) ($options['name'] ?? ''));
$revokeTokens = isset($options['revoke-tokens']);

if ($email === '' || $password === '') {
    fwrite(STDERR, "Usage: php bin/seed.php --email=you@example.com --password=secret [--name=\"Display Name\"] [--revoke-tokens]\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$cfg = config();
$db = new Db($cfg['db']);

$existing = $db->one('SELECT * FROM users WHERE email = ?', [$email]);
if ($existing !== null) {
    $userId = (int) $existing['id'];
    $revoked = (new Auth($db, $cfg))->setPassword($userId, $password, $revokeTokens);
    if ($name !== '') {
        $db->update('users', ['display_name' => $name], 'id = ?', [$userId]);
    }
    echo "Updated user $email (id $userId).\n";
    echo "Signed out {$revoked['sessions']} session(s); log in again with the new password.\n";
    if ($revokeTokens) {
        echo "Revoked {$revoked['tokens']} API token(s); re-issue with bin/token.php --create.\n";
    } else {
        $kept = (int) $db->scalar('SELECT COUNT(*) FROM api_tokens WHERE user_id = ?', [$userId]);
        if ($kept > 0) {
            echo "$kept API token(s) still valid. If this reset follows a compromise, run again with --revoke-tokens.\n";
        }
    }
} else {
    $userId = $db->insert('users', [
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'display_name' => $name !== '' ? $name : explode('@', $email)[0],
    ]);
    echo "Created user $email (id $userId).\n";
}

$hasCalendar = $db->scalar('SELECT id FROM calendars WHERE user_id = ? LIMIT 1', [$userId]);
if ($hasCalendar === null) {
    $calId = $db->insert('calendars', [
        'user_id' => $userId,
        'name' => 'Personal',
        'color' => '#4a7dff',
        'kind' => 'local',
    ]);
    echo "Created default calendar \"Personal\" (id $calId).\n";
}
