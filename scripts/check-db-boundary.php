<?php

declare(strict_types=1);

// Streamed by deploy-dev.sh under each application identity. It prints no
// credentials: always verifies that the active .env selects its intended
// schema. Strict/dedicated identities must also be unable to cross into the
// other environment; explicit shared mode records that isolation was waived.

$appRoot = (string) getenv('APP_ROOT');
$expected = (string) getenv('EXPECTED_DB');
$forbidden = (string) getenv('FORBIDDEN_DB');
$requireIsolation = (string) (getenv('REQUIRE_DB_ISOLATION') ?: '1');
$reportPrincipal = (string) (getenv('REPORT_DB_PRINCIPAL') ?: '0');
$expectedUser = (string) getenv('EXPECTED_DB_USER');
if ($appRoot === '' || $appRoot[0] !== '/' || !is_file($appRoot . '/server/src/bootstrap.php')) {
    fwrite(STDERR, "Invalid application root for database boundary check.\n");
    exit(1);
}
foreach ([$expected, $forbidden] as $name) {
    if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1) {
        fwrite(STDERR, "Invalid database boundary name.\n");
        exit(1);
    }
}
if (!in_array($requireIsolation, ['0', '1'], true)) {
    fwrite(STDERR, "Invalid database-isolation policy.\n");
    exit(1);
}
if (!in_array($reportPrincipal, ['0', '1'], true)
    || ($reportPrincipal === '1' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $expectedUser) !== 1)) {
    fwrite(STDERR, "Invalid database-principal reporting policy.\n");
    exit(1);
}

require $appRoot . '/server/src/bootstrap.php';

$cfg = config();
$pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    fwrite(STDERR, "The environment is not connected to its intended database.\n");
    exit(1);
}
if ($reportPrincipal === '1') {
    $principal = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    if ($principal === '' || !str_contains($principal, '@')) {
        fwrite(STDERR, "Could not resolve the effective database principal.\n");
        exit(1);
    }
    // Report only a stable digest plus whether the canonical account name
    // matches the configured login. Deploy compares the digests without
    // printing a server account identifier into logs.
    $matchesConfigured = str_starts_with($principal, $expectedUser . '@') ? '1' : '0';
    echo hash('sha256', strtolower($principal)) . ' ' . $matchesConfigured . "\n";
    exit(0);
}
if ($requireIsolation === '0') {
    echo "Database selected its intended schema; cross-schema isolation was explicitly waived.\n";
    exit(0);
}
if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($forbidden))->fetchColumn() !== 0) {
    fwrite(STDERR, "The database credentials can enumerate the other environment's tables.\n");
    exit(1);
}
try {
    $pdo->query('SELECT 1 FROM `' . $forbidden . '`.`users` LIMIT 1')->fetchColumn();
} catch (PDOException) {
    echo "Database credentials are isolated from the other environment.\n";
    exit(0);
}
fwrite(STDERR, "The database credentials can read the other environment.\n");
exit(1);
