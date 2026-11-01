<?php

declare(strict_types=1);

// Two connections at once: an undo and a change to the same rows, in
// either order (child.php is the other connection). Each child holds its
// change until it sees the undo waiting, and says so with its exit code
// (0): a run where the two never overlapped proves nothing and fails.

use BetterCal\Domain\Folders;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $marker = static fn(): string => sys_get_temp_dir() . '/bc-db-test-' . bin2hex(random_bytes(4));
    // The undo runs on this connection; each child watches for this thread
    // id and no other, so a waiter of some other run on a shared server
    // can't release it early.
    $waiter = connectionId($db);
    // Deadlocks the server broke since $seen; null where it keeps no count
    // (deadlockCount), in which case the check falls back to what the two
    // connections report.
    $deadlocks = deadlockCount($db);
    if ($deadlocks === null) {
        echo "note: this server doesn't count deadlocks; the concurrency checks rely on the connections' own reports\n";
    }
    $deadlocksSince = static fn(?int $seen): ?int => $seen === null ? null : min(1, (int) deadlockCount($db) - $seen);

    // A drag committed while the undo waits for it: the undo sees it and
    // is stale, rather than putting back over it.
    scenario('concurrency: a move committed while an undo waits on it', function () use ($db, $marker, $waiter): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
        $first = (int) $d->folders->create($u, ['name' => 'First'])['id'];
        $second = (int) $d->folders->create($u, ['name' => 'Second'])['id'];
        $d->folders->place($u, $first, $cal, 0);
        $filed = lastEntry($db, $u);
        $file = $marker();
        $child = new Child(['drag', $waiter, $u, $cal, $second, $file, $first]);
        $ready = waitFor(static fn() => is_file($file));
        $code = refusal(fn() => $d->undo->undoById($u, $filed));
        [$exit, $out] = $child->wait();
        @unlink($file);
        checkEq('concurrency: a move committed while an undo waits on it makes the undo stale, and the move stays', [true, 0, 'stale_undo', [], [$cal]],
            [$ready, $exit, $code, Folders::order($db, $first), Folders::order($db, $second)]);
        if ($exit !== 0) {
            echo $out, "\n";
        }
    });

    // A deadlock with a change that skipped Undo::write: the undo holds the
    // account and waits for the filing; the other holds the filing and
    // waits for the account. The database rolls the undo back, and it runs
    // again once the other is in.
    scenario('concurrency: an undo rolled back to break a deadlock', function () use ($db, $marker, $waiter, $deadlocksSince): void {
        $v = freshUser($db);
        $dv = domains($db);
        $vcal = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$v]);
        $folder = (int) $dv->folders->create($v, ['name' => 'Held'])['id'];
        $dv->folders->place($v, $folder, $vcal, 0);
        $vfiled = lastEntry($db, $v);
        $seen = deadlockCount($db);
        $file = $marker();
        $child = new Child(['deadlock', $waiter, $v, $vcal, $folder, $file]);
        $ready = waitFor(static fn() => is_file($file));
        $error = null;
        try {
            $dv->undo->undoById($v, $vfiled);
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
        }
        [$exit, $out] = $child->wait();
        @unlink($file);
        checkEq('concurrency: an undo rolled back to break a deadlock runs again and goes through, and the other change is kept', [true, $seen === null ? null : 1, null, 0, []],
            [$ready, $deadlocksSince($seen), $error, $exit, Folders::order($db, $folder)]);
        if ($exit !== 0) {
            echo $out, "\n";
        }
    });

    // The rows an undo puts back are taken in the order every writer takes
    // them (Undo::lockRows: by table, the event before what hangs off it),
    // before any of them is changed. A holder that skipped Undo::write and
    // takes the event, then its tag link, is waited for, not deadlocked
    // with. Without lockRows the undo removes the link first and then
    // waits for the event, the holder wants the link, and the database
    // rolls one of them back.
    scenario('concurrency: an undo takes its rows in the writers\' order', function () use ($db, $marker, $waiter, $deadlocksSince): void {
        $w = freshUser($db);
        $dw = domains($db);
        $wcal = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$w]);
        $event = (int) $dw->events->create($w, eventAt($wcal, 'Tagged', '2026-12-03', ['tagNames' => ['sample']]))['eventId'];
        $tag = (int) $db->scalar('SELECT tag_id FROM event_tags WHERE event_id = ?', [$event]);
        $made = lastEntry($db, $w);
        $seen = deadlockCount($db);
        $file = $marker();
        $child = new Child(['order', $waiter, $w, $event, $tag, $file]);
        $ready = waitFor(static fn() => is_file($file));
        $code = refusal(fn() => $dw->undo->undoById($w, $made));
        [$exit, $out] = $child->wait();
        @unlink($file);
        checkEq('concurrency: an undo waits for a holder of its event before touching its links, and neither is rolled back', [true, 0, null, 0, $seen === null ? null : 0],
            [$ready, $exit, $code, (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id = ?', [$event]), $deadlocksSince($seen)]);
        if ($exit !== 0) {
            echo $out, "\n";
        }
    });
};
