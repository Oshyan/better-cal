<?php

declare(strict_types=1);

// Post-deploy smoke: exercise the request paths a health check cannot.
//
// /health proves PHP runs and the database answers. It does not prove the
// events window serialises, which is the one path every screen depends on
// and the one a serializer refactor once broke for several minutes while
// "Deploy OK" printed underneath. This runs the real domain code against the
// real database for the first user, read-only, and exits non-zero on any
// throwable — so the deploy script fails loudly instead of the calendar.
//
//   php server/bin/smoke.php

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Domain;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;
use BetterCal\Support\Time;

// Notices and warnings are failures here: an "Undefined array key" on the
// window path is exactly the class of bug this exists to catch.
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    throw new \ErrorException($str, 0, $no, $file, $line);
});

$fail = static function (string $what, \Throwable $e): never {
    fwrite(STDERR, "SMOKE FAIL: $what: " . get_class($e) . ': ' . $e->getMessage()
        . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
};

try {
    $db = new Db(config()['db']);
    $userId = (int) ($db->scalar('SELECT id FROM users ORDER BY id LIMIT 1') ?? 0);
    if ($userId === 0) {
        echo "smoke: no users yet, nothing to exercise\n";
        exit(0);
    }
    $undo = new Domain\Undo($db);
    $labels = new Domain\Labels($db);
    $filters = new Domain\Filters($db, $undo, new JobQueue($db));
    $trips = new Domain\Trips($db, $undo);
    $events = new Domain\Events($db, new Domain\Recurrence(), $undo, $labels, $filters, $trips);
    $calendars = new Domain\Calendars($db, $undo, $labels);
} catch (\Throwable $e) {
    $fail('bootstrap', $e);
}

// 1. The events window, one month around now: expansion, filters, ranking,
//    serialization — the whole read path.
try {
    $now = Time::nowUtc();
    $occ = $events->window($userId, $now->modify('-1 week'), $now->modify('+3 weeks'), null, null, false);
    $n = count($occ);
    if ($n > 0) {
        $o = $occ[0];
        foreach (['instanceId', 'eventId', 'calendarId', 'title', 'start', 'end', 'allDay'] as $k) {
            if (!array_key_exists($k, $o)) {
                throw new \RuntimeException("window occurrence is missing '$k'");
            }
        }
        if (array_key_exists('uid', $o)) {
            throw new \RuntimeException('window occurrence carries uid (list shape regressed)');
        }
        json_encode($occ, JSON_THROW_ON_ERROR);
    }
    echo "smoke: window ok ($n occurrences)\n";
} catch (\Throwable $e) {
    $fail('events window', $e);
}

// 2. The single-event record the detail view and editor fetch.
try {
    $row = $db->one('SELECT * FROM events WHERE user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$userId]);
    if ($row !== null) {
        $single = $events->serializeSingle($row);
        if (!array_key_exists('uid', $single)) {
            throw new \RuntimeException('single record is missing uid (full shape regressed)');
        }
        json_encode($single, JSON_THROW_ON_ERROR);
    }
    echo "smoke: single record ok\n";
} catch (\Throwable $e) {
    $fail('single event record', $e);
}

// 3. The calendars listing with feed health, which the sidebar paints from.
try {
    $list = $calendars->listAll($userId);
    json_encode($list, JSON_THROW_ON_ERROR);
    echo 'smoke: calendars ok (' . count($list['calendars']) . ")\n";
} catch (\Throwable $e) {
    $fail('calendars list', $e);
}

echo "smoke: all ok\n";
