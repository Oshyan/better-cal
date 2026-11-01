<?php

declare(strict_types=1);

// A second connection for concurrency.test.php, in its own process: holds a
// change open until the test's own connection is seen waiting on it, then
// finishes. Exit 0 when it committed, 3 when the database rolled it back to
// break a deadlock.

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require __DIR__ . '/lib.php';

use BetterCal\Infra\Db;

[, $scenario, $userId, $calendarId, $folderId, $marker, $from] = $argv + [5 => '', 6 => ''];
$db = new Db(['dsn' => (string) getenv('BC_TEST_DSN'), 'user' => (string) getenv('BC_TEST_DB_USER'), 'pass' => (string) getenv('BC_TEST_DB_PASS')]);
$userId = (int) $userId;
$calendarId = (int) $calendarId;
$folderId = (int) $folderId;

try {
    if ($scenario === 'drag') {
        // A drag of the calendar from one folder to another, as the app
        // makes it, committed only once the undo waits on it.
        $db->tx(function () use ($db, $userId, $calendarId, $folderId, $marker, $from): void {
            domains($db)->folders->place($userId, $folderId, $calendarId, 0, (int) $from);
            touch($marker);
            waitFor(static fn() => someoneWaits($db), 15.0);
        });
    } elseif ($scenario === 'deadlock') {
        // A change that holds the calendar's filing, then journals itself,
        // which needs the account's row: the undo holds that and waits for
        // the filing. Bigger than the undo (a few rows written first, none
        // of them the account's), so the database picks the undo to roll back.
        $db->tx(function () use ($db, $userId, $calendarId, $folderId, $marker): void {
            foreach (range(1, 5) as $i) {
                $db->insert('dav_changes', ['calendar_id' => $calendarId, 'uri' => 'held-' . $i . '.ics', 'operation' => 2, 'synctoken' => $i]);
            }
            $db->run('UPDATE calendar_folders SET position = position + 1 WHERE calendar_id = ? AND folder_id = ?', [$calendarId, $folderId]);
            touch($marker);
            waitFor(static fn() => someoneWaits($db), 15.0);
            $db->insert('mutations', ['user_id' => $userId, 'entity' => 'calendar', 'entity_id' => $calendarId, 'op' => 'update', 'source' => 'web', 'summary' => 'Held change']);
        });
    }
} catch (\PDOException $e) {
    if (Db::isDeadlock($e)) {
        exit(3);
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
exit(0);
