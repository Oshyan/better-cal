<?php
declare(strict_types=1);

// The post-response plugin run takes the worker's lock and steps aside for
// an update (Plugins::runQueuedNow). Named locks are MySQL's, so this runs
// only against a dedicated, empty, disposable test database:
//   BC_LOCK_TEST_DSN='mysql:host=127.0.0.1;port=33307;dbname=lock_test' BC_LOCK_TEST_USER=... BC_LOCK_TEST_PASS=... php server/tests/plugin-lock-mysql.php
if (PHP_SAPI !== 'cli' || getenv('BC_LOCK_TEST_DSN') === false) exit(2);
putenv('BETTERCAL_SESSION_SECRET=synthetic-lock-test-secret');
require __DIR__ . '/../src/bootstrap.php';

use BetterCal\Domain\Plugins;
use BetterCal\Infra\{Db, JobQueue, Migrator, Updater};
use BetterCal\Support\Maintenance;

$dbCfg = ['dsn' => (string) getenv('BC_LOCK_TEST_DSN'), 'user' => (string) getenv('BC_LOCK_TEST_USER'), 'pass' => (string) getenv('BC_LOCK_TEST_PASS')];
$db = new Db($dbCfg);
if ((int) $db->scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') !== 0) throw new RuntimeException('Requires an empty test database');
(new Migrator($db, __DIR__ . '/../migrations'))->run(static function () {});

// An app root of its own: the real migrations, and room for a maintenance file.
$root = sys_get_temp_dir() . '/bc-lock-test-' . bin2hex(random_bytes(4));
mkdir($root . '/server', 0700, true);
symlink(realpath(__DIR__ . '/../migrations'), $root . '/server/migrations');
$cfg = ['db' => $dbCfg, 'app_root' => $root];

$checks = 0;
$verify = static function (string $name, bool $ok) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    $checks++;
    echo "PASS: $name\n";
};
$queue = new JobQueue($db);
$status = static fn(int $id): string => (string) $db->scalar('SELECT status FROM jobs WHERE id = ?', [$id]);
$plugins = new Plugins($db, null, $cfg);

try {
    // Another holder of the worker lock (the worker, or an update): nothing
    // runs, and the job is left for the worker.
    $other = new Db($dbCfg);
    $lock = Updater::workerLockName($dbCfg['dsn']);
    $verify('another connection holds the worker lock', (int) $other->scalar('SELECT GET_LOCK(?, 0)', [$lock]) === 1);
    $job = $queue->enqueue('plugin_job', ['userId' => 1, 'plugin' => 'sample-missing', 'job' => 'sync']);
    $t = microtime(true);
    $verify('while it is held, nothing runs', $plugins->runQueuedNow([$job]) === 0);
    $verify('after waiting no longer than its limit', microtime(true) - $t < Plugins::AFTER_RESPONSE_LOCK_WAIT + 3);
    $verify('and the job stays queued for the worker', $status($job) === 'pending');
    $other->scalar('SELECT RELEASE_LOCK(?)', [$lock]);

    // An update under way (the maintenance file): it steps aside.
    Maintenance::on($root, 'sample update');
    $verify('during maintenance, nothing runs', $plugins->runQueuedNow([$job]) === 0 && $status($job) === 'pending');
    $verify('and it lets go of the lock', (int) $other->scalar('SELECT GET_LOCK(?, 0)', [$lock]) === 1);
    $other->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
    Maintenance::off($root);

    // Nothing in the way: the job is claimed and run (an unknown plugin's
    // job fails, which shows it was attempted), and the lock is let go.
    $verify('with the lock free and no update, the job runs', $plugins->runQueuedNow([$job]) === 1 && $status($job) !== 'pending');
    $verify('and the lock is free again afterwards', (int) $other->scalar('SELECT GET_LOCK(?, 0)', [$lock]) === 1);
    $other->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
} finally {
    @unlink($root . '/' . Maintenance::FILE);
    @unlink($root . '/server/migrations');
    @rmdir($root . '/server');
    foreach (glob($root . '/.runtime-cache/*') ?: [] as $f) @unlink($f);
    @rmdir($root . '/.runtime-cache');
    @rmdir($root);
}
echo "$checks passed\n";
