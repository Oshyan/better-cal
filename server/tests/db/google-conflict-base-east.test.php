<?php

declare(strict_types=1);

// google-conflict-base.php's table for one of Google's copies: timed east of UTC (GOOGLE_CONFLICT_BASE_SLICES). The table
// lives there alone; this file only names the slice, so the suite runs the four side by side.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-conflict-base.php';

return static fn(Db $db) => googleConflictBase($db, 'east');
