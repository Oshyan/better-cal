<?php

declare(strict_types=1);

// Manage personal access tokens from the server shell:
//   php bin/token.php --create --name="Claude Code"
//   php bin/token.php --list
//   php bin/token.php --revoke=ID
// Optional --email=... selects the user when more than one exists.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Domain\ApiTokens;
use BetterCal\Infra\Db;

$options = getopt('', ['create', 'list', 'revoke:', 'name:', 'email:']);
$modes = array_intersect(['create', 'list', 'revoke'], array_keys($options));
if (count($modes) !== 1) {
    fwrite(STDERR, "Usage: php bin/token.php --create --name=NAME | --list | --revoke=ID  [--email=user@example.com]\n");
    exit(1);
}
$mode = $modes[array_key_first($modes)];

$db = new Db(config()['db']);

$email = trim((string) ($options['email'] ?? ''));
if ($email !== '') {
    $user = $db->one('SELECT id, email FROM users WHERE email = ?', [$email]);
    if ($user === null) {
        fwrite(STDERR, "No user with email $email.\n");
        exit(1);
    }
} else {
    $users = $db->all('SELECT id, email FROM users ORDER BY id');
    if (count($users) === 0) {
        fwrite(STDERR, "No users exist; run bin/seed.php first.\n");
        exit(1);
    }
    if (count($users) > 1) {
        fwrite(STDERR, "Multiple users exist; pass --email= one of: " . implode(', ', array_column($users, 'email')) . "\n");
        exit(1);
    }
    $user = $users[0];
}
$userId = (int) $user['id'];

$tokens = new ApiTokens($db);

switch ($mode) {
    case 'create':
        $name = trim((string) ($options['name'] ?? ''));
        if ($name === '') {
            fwrite(STDERR, "--create requires --name=NAME\n");
            exit(1);
        }
        try {
            $created = $tokens->create($userId, $name);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        echo "Created token \"{$created['name']}\" (id {$created['id']}) for {$user['email']}.\n";
        echo "This value is shown ONCE; store it now:\n\n";
        echo $created['token'] . "\n";
        break;

    case 'list':
        $rows = $tokens->listAll($userId);
        if ($rows === []) {
            echo "No tokens for {$user['email']}.\n";
            break;
        }
        printf("%-6s %-30s %-26s %s\n", 'ID', 'NAME', 'CREATED', 'LAST USED');
        foreach ($rows as $row) {
            printf("%-6d %-30s %-26s %s\n", $row['id'], $row['name'], $row['createdAt'], $row['lastUsedAt'] ?? 'never');
        }
        break;

    case 'revoke':
        $id = (int) $options['revoke'];
        if ($id <= 0) {
            fwrite(STDERR, "--revoke requires a numeric token id\n");
            exit(1);
        }
        if (!$tokens->revoke($userId, $id)) {
            fwrite(STDERR, "No token with id $id for {$user['email']}.\n");
            exit(1);
        }
        echo "Revoked token $id.\n";
        break;
}
