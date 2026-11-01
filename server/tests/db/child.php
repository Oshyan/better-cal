<?php

declare(strict_types=1);

// A second connection for concurrency.test.php, in its own process: holds a
// change open until the test's own connection (its server thread id is the
// second argument) is seen waiting on it, then finishes. Exit 0 when it
// committed after seeing that connection wait, 4 when it committed without
// ever seeing it wait (the two didn't overlap, so the test proved nothing),
// 3 when the database rolled it back to break a deadlock.

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require __DIR__ . '/lib.php';

use BetterCal\Infra\Db;

[, $scenario, $waiter, $userId, $calendarId, $folderId, $marker, $from] = $argv + [6 => '', 7 => ''];
$db = new Db(['dsn' => (string) getenv('BC_TEST_DSN'), 'user' => (string) getenv('BC_TEST_DB_USER'), 'pass' => (string) getenv('BC_TEST_DB_PASS')]);
$waiter = (int) $waiter;
$userId = (int) $userId;
$calendarId = (int) $calendarId;
$folderId = (int) $folderId;
$sawWait = false;
$hold = static function () use ($db, $waiter, &$sawWait): void {
    $sawWait = waitForWaiter($db, $waiter);
};

try {
    if ($scenario === 'drag') {
        // A drag of the calendar from one folder to another, as the app
        // makes it, committed only once the undo waits on it.
        $d = domains($db);
        $d->undo->write($userId, function () use ($d, $userId, $calendarId, $folderId, $marker, $from, $hold): void {
            $d->folders->place($userId, $folderId, $calendarId, 0, (int) $from);
            touch($marker);
            $hold();
        });
    } elseif ($scenario === 'deadlock') {
        // A change that doesn't go through Undo::write (none in the app
        // should): it holds the calendar's filing, then journals itself,
        // which needs the account's row; the undo holds that and waits for
        // the filing. Bigger than the undo (a few rows written first, none
        // of them the account's), so the database picks the undo to roll back.
        $db->tx(function () use ($db, $userId, $calendarId, $folderId, $marker, $hold): void {
            foreach (range(1, 5) as $i) {
                $db->insert('dav_changes', ['calendar_id' => $calendarId, 'uri' => 'held-' . $i . '.ics', 'operation' => 2, 'synctoken' => $i]);
            }
            $db->run('UPDATE calendar_folders SET position = position + 1 WHERE calendar_id = ? AND folder_id = ?', [$calendarId, $folderId]);
            touch($marker);
            $hold();
            $db->insert('mutations', ['user_id' => $userId, 'entity' => 'calendar', 'entity_id' => $calendarId, 'op' => 'update', 'source' => 'web', 'summary' => 'Held change']);
        });
    } elseif ($scenario === 'order') {
        // A holder that skipped Undo::write and takes rows in the order
        // every writer does (the event, then what hangs off it): here
        // $calendarId is the event and $folderId its tag. It holds the
        // event while the undo runs, then takes the tag link. The undo
        // must take the event before the link too (Undo::lockRows), or
        // the two hold what the other wants and one is rolled back.
        $db->tx(function () use ($db, $calendarId, $folderId, $marker, $hold): void {
            $db->all('SELECT id FROM events WHERE id = ? FOR UPDATE', [$calendarId]);
            touch($marker);
            $hold();
            $db->all('SELECT event_id FROM event_tags WHERE event_id = ? AND tag_id = ? FOR UPDATE', [$calendarId, $folderId]);
        });
    }
} catch (\PDOException $e) {
    if (Db::isDeadlock($e)) {
        exit(3);
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
exit($sawWait ? 0 : 4);
