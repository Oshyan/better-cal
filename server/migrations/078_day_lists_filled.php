<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Every edited day's row has its own list (events.override_fields,
 * series-model.md, Own Fields). A feed poll in 1.5.0 to 1.5.4 could store
 * an edited day with no list when the poll did not carry its series'
 * record (unreadable, or absent); 1.5.5 writes a list for every row it
 * makes. This fills those rows once, by migration 063's own logic, which
 * is run again unchanged: each day row with a series parent and no list,
 * live or deleted (a deleted row can be brought back by a CalDAV PUT, an
 * import or an undo, and has its list then), gets the fields that differ
 * from its series' row, tags and people compared, and [] where that row is
 * missing; a series deleted still has its row, which is compared as 063
 * compared it. Nothing shown changes and updated_at stays.
 *
 * A correction of live rows, not a rewrite of a stored form: the rows'
 * form is the list every writer since 1.5.0 writes; these rows lacked it.
 * After it no row has the older form, and the code reads one form.
 *
 * In batches of 500 rows by id, each with only its rows' series and link
 * sets, one transaction a batch: memory stays bounded whatever the
 * calendar's size. Idempotent: it touches only rows whose list is NULL.
 */
return static function (Db $db): string {
    $fill = require __DIR__ . '/063_override_fields.php';
    $said = $fill($db);
    return str_replace(['events.override_fields already present; ', 'added events.override_fields; '], '', $said);
};
