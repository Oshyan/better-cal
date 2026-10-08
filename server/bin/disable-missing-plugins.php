<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Infra\Db;

$ids = array_values(array_unique(array_filter(explode(',', (string) getenv('STALE_MANAGED_PLUGINS')))));
foreach ($ids as $id) {
    if (preg_match('/^[a-z][a-z0-9-]{1,62}[a-z0-9]$/', $id) !== 1) {
        fwrite(STDERR, "Invalid managed plugin id.\n");
        exit(1);
    }
}
if ($ids === []) {
    exit(0);
}

$db = new Db(config()['db']);
$disabled = 0;
$cancelled = 0;
$db->tx(function () use ($db, $ids, &$disabled, &$cancelled): void {
    foreach ($ids as $id) {
        $disabled += $db->run('UPDATE plugins SET enabled = 0 WHERE id = ?', [$id])->rowCount();
    }
    foreach ($db->all("SELECT id, payload_json FROM jobs WHERE type = 'plugin_job' AND status = 'pending'") as $job) {
        $payload = json_decode((string) $job['payload_json'], true);
        if (!is_array($payload) || !in_array((string) ($payload['plugin'] ?? ''), $ids, true)) {
            continue;
        }
        $cancelled += $db->run(
            "UPDATE jobs SET status = 'failed', last_error = ? WHERE id = ? AND status = 'pending'",
            ['cancelled because this release removed the plugin', (int) $job['id']]
        )->rowCount();
    }
});
echo "$disabled removed plugin" . ($disabled === 1 ? '' : 's') . " disabled; $cancelled pending job" . ($cancelled === 1 ? '' : 's') . " cancelled.\n";
