<?php

declare(strict_types=1);

use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;

/**
 * One representation of what a journal entry stands on (mutation_touches):
 * every entry recorded before 1.5.5 rewritten in the form 1.5.5 records,
 * once, read from what the entry's own snapshots say about each row
 * (Undo::legacyGround, Undo::rewriteLegacyGround).
 *
 * Why: 1.5.5 records a day's field on its series' row as the day's ground
 * ("events:<series>", "day:<occurrence>|title"), where 1.5.4 recorded the
 * day's own row and column ("events:<row>", "title"). Undo::staleFor looks
 * for later changes under the exact keys an entry recorded, so an entry
 * from 1.5.4 never saw a rename made after the upgrade, and its unforced
 * undo overwrote it. Tests that make both changes with the same version
 * write both grounds in the same form and pass; only an entry written in
 * the old form meets this, so its test writes the old form directly. A
 * migration, not a second reading at undo time: two readings of one
 * stored value would have to agree for every pair of forms in every
 * comparison, for as long as an old entry can be undone, where a
 * rewrite leaves one form and one reading.
 *
 * Rewritten (each by the entry's snapshots, pieces included):
 * - a column of a day's row ("events:<row>", "<column>") becomes that
 *   day's field on its series' row ("day:<occurrence>|<field>");
 * - a day's row made or removed whole ("events:<row>", "*") becomes the
 *   fields the row owned, as today's ground of a day made or removed;
 * - a column placing a day in its series (its own list, its occurrence,
 *   its series) becomes what that change was today: own fields gained or
 *   lost, the day carried ("move:"), the day leaving one series and
 *   joining another;
 * - a day's row joining something ("+") is dropped: it never conflicted;
 * - a tag or person of a day ("event_tags:<row>|<tag>", "*") becomes the
 *   day's tags or people on its series' row;
 * - a series' whole skips column ("exdates_json") becomes each date it
 *   skipped or brought back ("skip:<occurrence>");
 * - an older day's mark on its series ("~") is dropped: the day's own
 *   grounds above carry it.
 * Refused instead (the whole series, "*", so every later change to that
 * series makes the entry stale): a touch of a row its snapshots don't
 * show that is a day of a series now (an entry whose snapshots Google
 * holds), a skips column with no snapshot of its series, a "~" with no
 * day of that series in the snapshots.
 * Added: today's ground of the snapshots (Undo::touchesOf given the
 * entry's recorded touches, and restoredGround for an entry undone by
 * putting its snapshots back), as record() adds it, through the same
 * functions: a skip the entry recorded as carried ("move:") stays that
 * move, never a skip removed and a skip added.
 * Opened: only an entry whose touches hold a form above
 * (Undo::holdsLegacyGround, read from the journal's touches and the
 * events rows now: "~", "exdates_json", or a key naming an event row that
 * is a day now, or one gone now that the entry did not remove whole and
 * whose removal carried a "~", or that no entry removed). An entry
 * already in today's form is never opened, its snapshots and pieces never
 * read, and its ground never derived again. One that is opened reads its
 * pieces one at a time, keeping of them only the days' rows (their id,
 * series, occurrence, own list, skips, deletion, uid and calendar) and
 * the links to a day; every other row in a piece was made or removed
 * whole, its touch the same in both forms and left as stored. So a
 * calendar deleted in a hundred pieces holds one piece's bodies at a time.
 * Left as stored: every other row's touch (a series' or an event's own
 * column, a calendar, a folder, a tag, a person), already in today's
 * form; a touch of an event row that no longer exists and that no
 * snapshot shows, which no later change can name either.
 * Not grounds and not rewritten: a half-done Google change's pending row
 * (google_pending's plan and action, read with its own fallback for a row
 * made before 074 and 075, and only by that row's own Finish and Put
 * back) and the keys of an entry's details (its Google before state, an
 * older entry's kept "local" list read by GoogleUndo::rowForLegacy): each
 * is read only for that one entry or row, never compared with another
 * change's, so an older form cannot hide a later change.
 *
 * Idempotent: an entry already in today's form is left untouched.
 */
return static function (Db $db): string {
    $undo = new Undo($db);
    [$changed, $opened, $pieces] = [0, 0, 0];
    $after = 0;
    do {
        $ids = array_map('intval', array_column($db->all(
            "SELECT DISTINCT mutation_id FROM mutation_touches
             WHERE mutation_id > ? AND (row_key LIKE 'events:%' OR row_key LIKE 'event_tags:%' OR row_key LIKE 'event_people:%')
             ORDER BY mutation_id LIMIT 200",
            [$after]
        ), 'mutation_id'));
        foreach ($ids as $id) {
            $one = $undo->rewriteLegacyGround($id);
            [$changed, $opened, $pieces] = [$changed + (int) $one['rewritten'], $opened + (int) $one['opened'], $pieces + $one['pieces']];
            $after = $id;
        }
    } while (count($ids) === 200);
    return "journal grounds in one form: $changed entries rewritten ($opened opened, $pieces pieces read)";
};
