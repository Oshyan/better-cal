<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

// 058 once more. An install updated from a development build ran 058
// before it compared values as text and before it filled in entries older
// than a week that still had their snapshots, so some of its entries lack
// the touches that make a later change count against them; undo would put
// those back over newer work. 058 adds only what is missing, so where it
// ran as released this changes nothing.
return static function (Db $db): string {
    (require __DIR__ . '/058_undo_ground.php')($db);
    return 'Undo knows what every change that can still be undone stands on.';
};
