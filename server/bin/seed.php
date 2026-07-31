<?php

declare(strict_types=1);

// Creates or updates the single user: php bin/seed.php --email=... --password=... [--name=...]
// Also creates a default "Personal" calendar when the user has none.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Infra\Db;

$options = getopt('', ['email:', 'password:', 'name::']);
$email = trim((string) ($options['email'] ?? ''));
$password = (string) ($options['password'] ?? '');
$name = trim((string) ($options['name'] ?? ''));

if ($email === '' || $password === '') {
    fwrite(STDERR, "Usage: php bin/seed.php --email=you@example.com --password=secret [--name=\"Display Name\"]\n");
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
$hash = password_hash($password, PASSWORD_DEFAULT);

$existing = $db->one('SELECT * FROM users WHERE email = ?', [$email]);
if ($existing !== null) {
    $fields = ['password_hash' => $hash];
    if ($name !== '') {
        $fields['display_name'] = $name;
    }
    $db->update('users', $fields, 'id = ?', [(int) $existing['id']]);
    $userId = (int) $existing['id'];
    echo "Updated user $email (id $userId).\n";
} else {
    $userId = $db->insert('users', [
        'email' => $email,
        'password_hash' => $hash,
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
