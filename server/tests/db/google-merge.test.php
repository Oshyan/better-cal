<?php

declare(strict_types=1);

// The pure checks of the Google list merge (server/tests/google-merge.php:
// GoogleSync::toParsed and applyChanges, each merge over every order of its
// list), run here as well as in the pure suite. They use no database. They
// are here because the edit harness's mutation runner judges a mutation by
// the labels a database test file fails (BC_TEST_PRELOAD, BC_TEST_REPORT),
// and the pure suite has neither: the mutation `merge-dropped-by-key` names
// these checks.

use BetterCal\Infra\Db;

return static function (Db $db): void {
    require dirname(__DIR__) . '/google-merge.php';
};
