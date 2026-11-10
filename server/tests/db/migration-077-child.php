<?php

declare(strict_types=1);

// Migration 077 run alone, in a process of its own, on the suite's
// database (BC_TEST_DSN), under whatever memory_limit the caller started it
// with (large-calendar.test.php: -d memory_limit=128M, a common limit for a
// PHP worker). Prints the migration's own line, then the peak memory.

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use BetterCal\Infra\Db;

$db = new Db(['dsn' => (string) getenv('BC_TEST_DSN'), 'user' => (string) getenv('BC_TEST_DB_USER'), 'pass' => (string) getenv('BC_TEST_DB_PASS')]);
echo (require dirname(__DIR__, 2) . '/migrations/077_grounds_one_form.php')($db), "\n";
echo 'peak_bytes=', memory_get_peak_usage(true), "\n";
