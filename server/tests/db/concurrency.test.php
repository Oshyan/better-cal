<?php

declare(strict_types=1);

// Two connections at once: an undo and a change to the same rows, in
// either order (child.php is the other connection).

use BetterCal\Domain\Folders;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $marker = static fn(): string => sys_get_temp_dir() . '/bc-db-test-' . bin2hex(random_bytes(4));

    // A drag committed while the undo waits for it: the undo sees it and
    // is stale, rather than putting back over it.
    $u = freshUser($db);
    $d = domains($db);
    $cal = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
    $first = (int) $d->folders->create($u, ['name' => 'First'])['id'];
    $second = (int) $d->folders->create($u, ['name' => 'Second'])['id'];
    $d->folders->place($u, $first, $cal, 0);
    $filed = lastEntry($db, $u);
    $file = $marker();
    $child = new Child(['drag', $u, $cal, $second, $file, $first]);
    $ready = waitFor(static fn() => is_file($file));
    $code = refusal(fn() => $d->undo->undoById($u, $filed));
    [$exit, $out] = $child->wait();
    @unlink($file);
    checkEq('concurrency: a move committed while an undo waits on it makes the undo stale, and the move stays', [true, 0, 'stale_undo', [], [$cal]],
        [$ready, $exit, $code, Folders::order($db, $first), Folders::order($db, $second)]);
    if ($exit !== 0) {
        echo $out, "\n";
    }

    // A deadlock with a change that skipped Undo::write: the undo holds the
    // account and waits for the filing; the other holds the filing and
    // waits for the account. The database rolls the undo back, and it runs
    // again once the other is in.
    $v = freshUser($db);
    $dv = domains($db);
    $vcal = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$v]);
    $folder = (int) $dv->folders->create($v, ['name' => 'Held'])['id'];
    $dv->folders->place($v, $folder, $vcal, 0);
    $vfiled = lastEntry($db, $v);
    $deadlocks = static fn(): int => (int) ($db->one("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'")['Value'] ?? 0);
    $seen = $deadlocks();
    $file = $marker();
    $child = new Child(['deadlock', $v, $vcal, $folder, $file]);
    $ready = waitFor(static fn() => is_file($file));
    $error = null;
    try {
        $dv->undo->undoById($v, $vfiled);
    } catch (\Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }
    [$exit, $out] = $child->wait();
    @unlink($file);
    checkEq('concurrency: an undo rolled back to break a deadlock runs again and goes through, and the other change is kept', [true, 1, null, 0, []],
        [$ready, $deadlocks() - $seen, $error, $exit, Folders::order($db, $folder)]);
    if ($exit !== 0) {
        echo $out, "\n";
    }
};
