<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Domain\Series\Model;
use BetterCal\Domain\Series\OwnFields;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

/**
 * Undo for a write to Google (#172): a single event, or one occurrence of a
 * series, which Google keeps as one resource of its own (the probe,
 * 2026-11-07, groups 2, 5 and 6), and a whole series changed, made or
 * deleted (kind series, GoogleSeries).
 *
 * An edit's entry keeps the row's snapshots before and after, with its tags
 * and people (none on a side where the edit made the row, or made the day
 * ordinary again), so its undo puts back what it changed here, the row
 * itself included; a create, delete or answer keeps them as noted below. A
 * series entry keeps the rows' snapshots as a local plan's entry keeps
 * them (the rows' identities, tags, people, own lists and links). Either
 * way, what to send back is in its details, under `google`:
 *
 *   kind        edit, delete, create, answer or series: what the change did
 *   calendarId  the local calendar
 *   googleId    the resource's id at Google (an occurrence's instance id;
 *               for a series, its first series' id)
 *   before      edit: the resource's fields as GoogleWriter sends them
 *               (summary, description, location, status, start, end,
 *               source, and recurrence for an event in no series), as they
 *               were before the change; delete: {status}; series: the
 *               series as they were, as GoogleSeries targets (stateOf; a
 *               series the change made is its removal); else null
 *   changed     edit: the fields of `before` the edit changed (changedKeys),
 *               the only ones its undo sends back, over the event as it is
 *               then, so a later change to another field stays (one the
 *               event had none of goes as null: inverse); an entry
 *               without them sends what its after snapshot shows changed,
 *               else all of `before`
 *   etagBefore  the row's etag before the change, when known
 *   etagAfter   the resource's etag Google answered the change with; null
 *               after a delete, which answers nothing
 *   local       delete, as handed to record(): the removed row whole and
 *               what hangs off it (localOf, Undo::removedRows), which
 *               record() keeps as the entry's before snapshot, so the undo
 *               puts the row back under its id with every relation and
 *               column of its own, then stores Google's answer on top.
 *               An entry recorded before 1.5.5 keeps here the row's tags,
 *               people, reminders, trip flag and own list (restoreLegacy).
 *   responseStatus, attendance  answer: my answer before, and my Planned or
 *               Maybe before (an answer resets it)
 *
 * Undoing sends the inverse through GoogleWriter, conditional on the row's
 * stored etag (GoogleWriter sends it when none is given): any change to the
 * event at Google since the app last read it changed its etag, so Google
 * answers 412 and nothing is written (the undo is refused). The etag the
 * change was answered with (etagAfter) is kept for the record, not sent:
 * Google changes every row's etag in a series on any write (probe 5.2 and
 * 5.3), so an edit to another day of the series would make this undo a
 * false conflict, while the stored etag follows the app's own writes and a
 * later local change to the row is the journal's own stale check. A delete is
 * undone by a PATCH of the same id back to its status (probe 6.2: a deleted
 * event stays readable under its id), conditional on the etag of the
 * deleted resource as read just before. The undo is recorded as an entry
 * of the same shape, so it can be undone back.
 *
 * A series is undone by GoogleSeries::apply with the before targets, each
 * conditional on its series' etag as stored at undo time, as the single
 * kinds are; then the snapshots are put back here (Undo::restoreSnapshots)
 * and what Google read back is stored on top (GoogleSeries::store), so the
 * rows keep their own parts and show what Google holds. A 412 on the
 * first write is the conflict, nothing written; a failure after a step
 * reached Google leaves the pending row for Review (Finish, Put back) and
 * the entry to undo again. The undo is recorded with the befores of its
 * own apply, so it undoes back.
 */
final class GoogleUndo
{
    public const EDIT = 'edit';
    public const DELETE = 'delete';
    public const CREATE = 'create';
    public const ANSWER = 'answer';
    public const SERIES = 'series';

    private const UNDID = 'Undid: ';
    /** Where an entry's words for what Google kept start (SeriesPlans::googleAfter's words, an undo's the same). */
    public const KEPT = ' Google kept something else: ';
    /** What an undo's entry says when Review's Finish completed it. */
    public const FINISHED = 'Finished at Google from Review.';
    /** The heading of what a forced undo put back over. */
    public const FORCED = ' Put back over a change made at Google since, as forced: ';

    public function __construct(
        private readonly Db $db,
        private readonly Undo $undo,
        private ?GoogleWriter $writer = null,
    ) {
    }

    /** What an entry held back by seriesUndoOff says, from undo, redo and the Activity list alike. */
    public const SERIES_UNDO_OFF = 'Undo of a change to all events on a Google calendar comes in a later release; this change stays. Undo of a single day still works.';

    /**
     * Is this entry's undo held back by the account's google_series_undo
     * setting being off (Settings)? A whole-series entry on a Google
     * calendar (kind series: all events, this and following, delete
     * following, answers from a day on, a series made or deleted, and the
     * entry of such an undo, which its redo undoes). Everything else undoes
     * as built.
     */
    public static function seriesUndoOff(Db $db, array $mutation): bool
    {
        $details = json_decode((string) ($mutation['details_json'] ?? ''), true);
        return self::seriesEntry(is_array($details) ? $details : null) && !Settings::googleSeriesUndo($db, (int) $mutation['user_id']);
    }

    /** Is this a whole-series entry on a Google calendar (kind series)? Pure. */
    public static function seriesEntry(?array $details): bool
    {
        return self::carries($details) && (string) $details['google']['kind'] === self::SERIES;
    }

    /** Does this entry's details carry a Google before state undo can send back? */
    public static function carries(?array $details): bool
    {
        $g = $details['google'] ?? null;
        return is_array($g) && in_array($g['kind'] ?? null, [self::EDIT, self::DELETE, self::CREATE, self::ANSWER, self::SERIES], true)
            && is_string($g['googleId'] ?? null) && $g['googleId'] !== '' && isset($g['calendarId'])
            && (($g['kind'] ?? null) !== self::SERIES || (is_array($g['before'] ?? null) && $g['before'] !== []));
    }

    /** A row's Google fields as GoogleWriter sends them: an occurrence's without the series' recurrence. Pure. */
    public static function bodyOf(array $row): array
    {
        return ($row['recurrence_instance_utc'] ?? null) !== null ? GoogleWriter::instanceBody($row) : GoogleWriter::body($row);
    }

    /**
     * The fields of a Google body two versions differ in, as one PATCH
     * sends them: a start or end changed sends both, which Google reads
     * together. Pure.
     *
     * @return list<string>
     */
    public static function changedKeys(array $before, array $after): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (json_encode($before[$key] ?? null) !== json_encode($after[$key] ?? null)) {
                $out[] = (string) $key;
            }
        }
        if (array_intersect($out, ['start', 'end']) !== []) {
            $out = array_values(array_unique(array_merge($out, array_intersect(['start', 'end'], array_keys($before)))));
        }
        return $out;
    }

    /**
     * The PATCH that puts the fields $changed back as $before had them: each
     * at its before value, and one $before has none of (a link the edit
     * added, which GoogleWriter::body sends as `source` only when there is
     * one) as null, which clears it at Google. Only the keys of what Google
     * holds (GoogleWriter::allowedKeys), the one list every body is built
     * from: an entry that changed nothing Google holds sends nothing. Pure.
     *
     * @param list<string> $changed
     */
    public static function inverse(array $before, array $changed): array
    {
        $out = [];
        foreach (array_intersect($changed, GoogleWriter::allowedKeys()) as $key) {
            $out[$key] = array_key_exists($key, $before) ? $before[$key] : null;
        }
        return $out;
    }

    /**
     * The fields an edit changed at Google: its before body against the
     * event as the edit left it, its row here or, the day left with no row
     * (an ordinary occurrence again), Google's answer. Pure.
     *
     * @return list<string>
     */
    public static function editChanged(array $before, ?array $leftRow, ?array $answer): array
    {
        $parsed = $leftRow === null && $answer !== null ? GoogleSync::toParsed($answer) : null;
        $left = $leftRow ?? (is_array($parsed) && empty($parsed['cancelled']) ? $parsed : null);
        return $left === null ? array_keys($before) : self::changedKeys($before, self::bodyOf($left));
    }

    /**
     * What an edit entry sends back: the fields it recorded as changed
     * (details `changed`); for one recorded without them, its before body
     * against the row its after snapshot keeps, else all of the before body.
     * Pure.
     *
     * @return list<string>
     */
    private static function changedOf(array $g, ?array $leftRow): array
    {
        if (is_array($g['changed'] ?? null)) {
            return array_values(array_filter($g['changed'], 'is_string'));
        }
        return self::editChanged((array) $g['before'], $leftRow, null);
    }

    /**
     * An edit's before body over the event as it stands: each field a day
     * owned before ($wasRow's own list; none for a day with no row) at its
     * value then, each other field Google holds at its series' value there
     * now (Feeds::ofSeries, as the sync follows a day), so the inverse of
     * "gave the day a field of its own" is "the day inherits it again". An
     * event in no series, or a day its series no longer has, keeps $before.
     */
    private function beforeOver(array $before, ?array $wasRow, ?array $day, ?array $series = null): array
    {
        $base = $wasRow ?? $day;
        if ($base === null || ($base['recurrence_instance_utc'] ?? null) === null) {
            return $before;
        }
        $master = $series ?? $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL',
            [(int) $base['calendar_id'], (string) $base['uid']]);
        $target = $master === null || empty($master['rrule']) ? null : Feeds::ofSeries($base, null, $master);
        if ($target === null) {
            return $before;
        }
        $owned = $wasRow === null ? [] : (OwnFields::decode($wasRow['override_fields'] ?? null) ?? OwnFields::googleFields());
        $series = self::bodyOf(Feeds::followed($base, $target));
        foreach (array_diff(GoogleWriter::allowedKeys(), GoogleWriter::keysOf(OwnFields::googleHeld($owned))) as $key) {
            if (array_key_exists($key, $series)) {
                $before[$key] = $series[$key];
            } else {
                unset($before[$key]);
            }
        }
        return $before;
    }

    /**
     * A day is its series' uid and its occurrence: the day an entry names,
     * where it is now. A later change that carried it to another occurrence
     * (a times or zone change for the whole series, Undo::dayNow) carried
     * it as any change carries a day, so the entry's undo acts on it there,
     * the day taking its series' values at that occurrence (rule A). An
     * entry about a day that is no longer an occurrence of the series it
     * names (a later "this and following" took it into a series of its own,
     * a rule or bound change dropped it) is refused as stale, plainly, with
     * nothing sent. An id that names no day of a series is itself.
     */
    /** dayAt for an entry, before any other check (Undo::undoById): its day, by the Google id it names. */
    public static function refuseLeftSeriesOf(Db $db, Undo $undo, array $mutation): void
    {
        $g = json_decode((string) ($mutation['details_json'] ?? ''), true)['google'] ?? null;
        if (!self::carries(['google' => $g]) || (string) $g['kind'] === self::SERIES) {
            return;
        }
        $calendar = $db->one('SELECT * FROM calendars WHERE id = ?', [(int) $g['calendarId']]);
        if ($calendar !== null) {
            (new self($db, $undo))->dayAt($mutation, $calendar, (string) $g['googleId']);
        }
    }

    private function dayAt(array $mutation, array $calendar, string $googleId): string
    {
        $key = GoogleSync::droppedInstance($googleId);
        $cut = strrpos($googleId, '_');
        if ($key === null || $cut === false) {
            return $googleId;
        }
        $master = $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [(int) $calendar['id'], substr($googleId, 0, $cut)]);
        if ($master !== null && empty($master['rrule'])) {
            return $googleId;
        }
        if ($master !== null) {
            $at = $this->undo->dayNow((int) $mutation['id'], $master, (string) $key);
            if ($at !== null) {
                return $at === (string) $key ? $googleId
                    : GoogleWriter::instanceId(substr($googleId, 0, $cut), $at, (int) $master['all_day'] === 1, (string) $master['tzid']);
            }
        }
        throw HttpError::badRequest("That day is no longer part of the series this change was made in (a later change moved it into another series or ended the series before it), so this can't be undone.", 'stale_undo');
    }

    /**
     * The body that brings a deleted day of a series back: every field
     * Google holds at the series' value there now, a field the removed row
     * ($row, the entry's snapshot) owned at its value (beforeOver). Empty
     * for an event in no series.
     */
    private function dayBack(array $calendar, string $googleId, ?array $row): array
    {
        // A day of a series is addressed by its instance id: the series' id and the occurrence.
        $key = GoogleSync::droppedInstance($googleId);
        $cut = strrpos($googleId, '_');
        if ($key === null || $cut === false) {
            return [];
        }
        $master = $this->writer()->rowByGoogleId((int) $calendar['id'], substr($googleId, 0, $cut));
        if ($master === null || empty($master['rrule'])) {
            return [];
        }
        // The series as it is with the day back: its skip of the day is what this undo takes away.
        $skips = array_values(array_diff(Recurrence::decodeExdates($master['exdates_json'] ?? null), [(string) $key]));
        $master['exdates_json'] = $skips === [] ? null : json_encode($skips);
        $day = $row ?? (['recurrence_instance_utc' => (string) $key, 'recurrence_parent_id' => (int) $master['id'], 'override_fields' => '[]'] + $master);
        $body = $this->beforeOver($row !== null ? self::bodyOf($row) : [], $row ?? $day, $day, $master);
        return array_intersect_key($body, array_flip(GoogleWriter::allowedKeys()));
    }

    /**
     * The `google` details of an entry. Pure.
     *
     * @param array<string,mixed> $extra
     */
    public static function details(string $kind, array $calendar, string $googleId, ?array $before, ?string $etagBefore, ?string $etagAfter, array $extra = []): array
    {
        $etag = static fn(?string $e): ?string => is_string($e) && $e !== '' ? $e : null;
        return [
            'kind' => $kind,
            'calendarId' => (int) $calendar['id'],
            'googleId' => $googleId,
            'before' => $before,
            'etagBefore' => $etag($etagBefore),
            'etagAfter' => $etag($etagAfter),
        ] + $extra;
    }

    /**
     * What a delete at Google takes with a row here: the row as stored and
     * what hangs off it, as any change that removes an event keeps it
     * (Undo::removedRows): tags, people, trip links, duplicate decisions,
     * plugin data, and every column of its own (reminders, the trip and
     * floating flags, coordinates, an edited day's own list). Empty when
     * the row is gone already.
     *
     * @return array<string,list<array>>
     */
    public function localOf(array $row): array
    {
        $stored = $this->db->one('SELECT * FROM events WHERE id = ?', [(int) $row['id']]);
        return $stored === null ? [] : Undo::removedRows($this->db, [$stored]);
    }

    /**
     * Record an undoable entry for a write to Google, inside Undo::write.
     * $tables: a series' snapshots [before, after] (seriesTables), whose
     * rows say what it touched; without them $touches defaults to the event
     * whole, which an older undo of it (its tags, say) stands on. A
     * delete's removed row (details `local`, localOf) is kept as the
     * entry's before snapshot, as a local change keeps what it removes.
     *
     * @param list<array{0:string,1:string}>|null $touches
     * @param array{0:array,1:array}|null $tables
     */
    public function record(int $userId, int $eventId, string $summary, array $google, ?array $touches = null, string $op = 'update', ?array $tables = null): int
    {
        if (($google['kind'] ?? null) === self::DELETE && $tables === null && array_key_exists('local', $google)) {
            $removed = is_array($google['local']) ? $google['local'] : [];
            unset($google['local']);
            if (($removed['events'] ?? []) !== []) {
                $tables = [$removed, []];
            }
        }
        return $this->undo->record($userId, 'event', $eventId, $op, $tables[0] ?? [], $tables[1] ?? [], $summary, ['google' => $google], [(int) $google['calendarId']],
            $touches ?? ($tables === null && $eventId > 0 ? [[Undo::keyOf('events', ['id' => $eventId]), '*']] : []));
    }

    /**
     * Rows of series and events of their own on a calendar, for a series
     * entry's snapshot: the live rows of each uid (a master and its edited
     * days), the rows $ids name, and what hangs off them (Undo::removedRows).
     *
     * @param list<string> $uids
     * @param list<int> $ids
     * @return array<string,list<array>>
     */
    public function seriesTables(int $calendarId, array $uids, array $ids = []): array
    {
        $rows = [];
        $uids = array_values(array_unique(array_filter(array_map('strval', $uids), static fn(string $u): bool => $u !== '')));
        if ($uids !== []) {
            [$in, $params] = Db::in($uids);
            foreach ($this->db->all("SELECT * FROM events WHERE calendar_id = ? AND uid IN $in AND deleted_at IS NULL ORDER BY id", array_merge([$calendarId], $params)) as $r) {
                $rows[(int) $r['id']] = $r;
            }
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0 && !isset($rows[$i]))));
        if ($ids !== []) {
            [$in, $params] = Db::in($ids);
            foreach ($this->db->all("SELECT * FROM events WHERE id IN $in AND deleted_at IS NULL ORDER BY id", $params) as $r) {
                $rows[(int) $r['id']] = $r;
            }
        }
        ksort($rows);
        return Undo::removedRows($this->db, array_values($rows));
    }

    /**
     * Record a whole-series change sent to Google (GoogleSeries): its
     * befores as apply() returned them (or built here for a series made or
     * deleted whole) and the rows' snapshots before and after. The one
     * recording of every series change on a Google calendar.
     *
     * @param list<array> $befores
     */
    public function recordSeries(int $userId, int $eventId, string $action, array $calendar, array $befores, array $beforeTables, array $afterTables, ?int $undoes = null, array $kept = [], array $forced = [], ?string $note = null, array $removed = [], array $touches = []): int
    {
        $summary = self::line($action, (string) ($calendar['name'] ?? ''), $kept, $forced, $note);
        $etag = static function (array $tables, ?string $googleId): ?string {
            foreach ($tables['events'] ?? [] as $r) {
                if ($googleId !== null && (string) ($r['google_event_id'] ?? '') === $googleId && !empty($r['google_etag'])) {
                    return (string) $r['google_etag'];
                }
            }
            return null;
        };
        $googleId = (string) ($befores[0]['googleId'] ?? '');
        return $this->record($userId, $eventId, $summary,
            self::details(self::SERIES, $calendar, $googleId, array_values($befores), $etag($beforeTables, $googleId), $etag($afterTables, $googleId),
                ['action' => $action, 'kept' => array_values($kept)] + ($forced === [] ? [] : ['forced' => array_values($forced)]) + ($undoes === null ? [] : ['undoes' => $undoes])
                    + ($removed === [] ? [] : ['removedDays' => $removed])),
            $touches, 'update', [$beforeTables, $afterTables]);
    }

    /**
     * Undo one entry that carries() a Google before state: the inverse sent
     * to Google, its answer stored as a poll would store it, the entry
     * marked undone and the undo recorded. The caller has checked the
     * entry is the account's, not undone, and (unless forced) not stale.
     *
     * @return array{entity:string,op:string}
     */
    public function undo(array $mutation, ?RequestAuthority $authority = null, bool $force = false): array
    {
        $details = json_decode((string) ($mutation['details_json'] ?? ''), true);
        if (!self::carries(is_array($details) ? $details : null)) {
            throw HttpError::badRequest('This entry has nothing to send back to Google', 'not_undoable');
        }
        $g = $details['google'];
        $userId = (int) $mutation['user_id'];
        if ((string) $g['kind'] === self::SERIES) {
            return $this->undoSeries($mutation, $g, $authority, $force);
        }
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ? AND user_id = ?', [(int) $g['calendarId'], $userId]);
        if ($calendar === null || !GoogleWriter::writable($calendar)) {
            throw HttpError::badRequest("That calendar no longer writes to Google from here, so this change can't be undone", 'not_undoable');
        }
        // A day is where its series has it now (dayAt): a later change may have carried it.
        $googleId = $this->dayAt($mutation, $calendar, (string) $g['googleId']);
        $writer = $this->writer();
        $summary = self::line(self::undoSummary((string) $mutation['summary'], (string) ($mutation['details_json'] ?? '')), (string) $calendar['name']);
        $beforeSend = $authority === null ? null : fn() => $authority->assertValid($this->db, false);
        $row = $writer->rowByGoogleId((int) $calendar['id'], $googleId);

        switch ((string) $g['kind']) {
            case self::EDIT:
                // The row's snapshots, when the edit kept them (every edit
                // since 1.5.5): the row as it was (none, for a day the edit
                // gave a row) and as the edit left it (none, for a day it
                // made ordinary again).
                $snap = static fn(string $side): ?array => json_decode((string) ($mutation[$side] ?? ''), true)['tables']['events'][0] ?? null;
                [$wasRow, $leftRow] = [$snap('before_json'), $snap('after_json')];
                $local = $wasRow !== null || $leftRow !== null;
                if (!is_array($g['before'] ?? null) || ($row === null && $wasRow === null)) {
                    throw HttpError::badRequest('That event is no longer here to change back', 'not_undoable');
                }
                // Only what the edit changed goes back, each field at its
                // value before, over the event as it is now: a later change
                // to another field stays, here and at Google (one to the same
                // field made this entry stale: Undo::staleFor). A day's field
                // it did not own before goes back to following its series:
                // the series' value there now, not the one it showed then.
                $changed = self::changedOf($g, $leftRow);
                $dayKey = GoogleSync::droppedInstance($googleId);
                $at = static fn(?array $r): ?array => $r === null || $dayKey === null || ($r['recurrence_instance_utc'] ?? null) === null ? $r : array_merge($r, ['recurrence_instance_utc' => (string) $dayKey]);
                $send = self::inverse($this->beforeOver($g['before'], $at($wasRow), $row ?? $at($leftRow)), $changed);
                // The event as it is now: its row, or, a day with no row
                // here (the edit made it ordinary, or an undo removed the row
                // the edit made), as Google shows it, read just before and
                // sent back conditional on that read.
                $current = $row === null && $send !== [] ? $writer->get($calendar, $googleId) : null;
                $parsed = $current !== null ? GoogleSync::toParsed($current) : null;
                if ($current !== null && (!is_array($parsed) || !empty($parsed['cancelled']))) {
                    throw self::conflict();
                }
                $now = $row !== null ? self::bodyOf($row) : ($parsed !== null ? self::bodyOf($parsed) : []);
                $rowId = $row !== null ? (int) $row['id'] : (int) $wasRow['id'];
                // What the edit changed here (tags, reminders, the trip flag,
                // the row itself when the edit made it) is put back from the
                // snapshots, refused before Google is sent anything when it
                // can't be.
                if ($local) {
                    $this->undo->checkRestore($mutation, true);
                }
                $resp = $send === [] ? null : self::refusingConflict(fn() => $writer->patch($calendar, $googleId, $send, $beforeSend, $current));
                $this->commit($mutation, $calendar, $authority, function () use ($writer, $calendar, $resp, $googleId, $now, $row, $rowId, $changed, $userId, $summary, $mutation, $local): void {
                    $was = $this->rowTables($rowId);
                    // The row put back first (made again under its id, or
                    // removed, as the edit found it), Google's answer stored
                    // on top, as a poll would store it.
                    if ($local) {
                        $this->undo->restoreSnapshots($mutation, true);
                    }
                    if ($resp !== null) {
                        $writer->apply($calendar, [$resp]);
                    }
                    // The undo's own entry carries the fields it sent back,
                    // each at its value before the undo, so undoing it (redo)
                    // sends those alone too.
                    $this->record($userId, $this->rowId($calendar, $googleId, (int) $mutation['entity_id']), $summary,
                        self::details(self::EDIT, $calendar, $googleId, $now, $row['google_etag'] ?? null, $resp !== null ? self::etagOf($resp) : null,
                            ['undoes' => (int) $mutation['id'], 'changed' => $changed]),
                        null, 'update', [$was, $this->rowTables($rowId)]);
                });
                return ['entity' => 'event', 'op' => 'update'];

            case self::DELETE:
                // Deleted at Google, the resource stays readable under its id
                // (probe 6.1); one that is live again came back since.
                $current = $writer->get($calendar, $googleId);
                if ((string) ($current['status'] ?? '') !== 'cancelled') {
                    throw self::conflict();
                }
                $status = (string) ($g['before']['status'] ?? '') === 'tentative' ? 'tentative' : 'confirmed';
                // The row it removed, kept as the entry's snapshot (record),
                // goes back first under its id with all it had, and Google's
                // answer is stored on top, as a poll would store it; what
                // putting it back would refuse (the trip rules over what it
                // leaves) is refused before Google is sent anything.
                $removed = $this->undo->snapshotEventIds($mutation) !== [];
                if ($removed) {
                    $this->undo->checkRestore($mutation);
                }
                // A day of a series comes back as the series' occurrence
                // there now with the removed row's own part over it, never
                // with what Google kept on the cancelled instance from when it
                // was deleted (the series' values of then).
                $body = ['status' => $status] + $this->dayBack($calendar, $googleId, json_decode((string) ($mutation['before_json'] ?? ''), true)['tables']['events'][0] ?? null);
                $resp = self::refusingConflict(fn() => $writer->patch($calendar, $googleId, $body, $beforeSend, $current));
                $legacy = is_array($g['local'] ?? null) ? $g['local'] : [];
                $this->commit($mutation, $calendar, $authority, function () use ($writer, $calendar, $resp, $googleId, $userId, $summary, $mutation, $removed, $legacy): void {
                    if ($removed) {
                        $this->undo->restoreSnapshots($mutation);
                    }
                    $writer->apply($calendar, [$resp]);
                    $back = $writer->rowByGoogleId((int) $calendar['id'], $googleId) ?? ($removed ? null : $this->rowForLegacy($calendar, $resp, $legacy));
                    if ($back !== null && !$removed) {
                        $this->restoreLegacy($userId, (int) $back['id'], $legacy);
                    }
                    // A day of a series back at the series' own values is an ordinary
                    // occurrence again, with no row here: its series and day are kept,
                    // for the undo's undo to skip it again.
                    $parsed = $back === null ? GoogleSync::toParsed($resp) : null;
                    $occurrence = is_array($parsed) && !empty($parsed['recurrence_instance_utc'])
                        ? ['uid' => (string) $parsed['uid'], 'key' => (string) $parsed['recurrence_instance_utc'], 'status' => (string) ($resp['status'] ?? '') === 'tentative' ? 'tentative' : 'confirmed']
                        : null;
                    // An ordinary day brought back changed its own skip, nothing else: that is its ground.
                    $this->record($userId, $back !== null ? (int) $back['id'] : (int) $mutation['entity_id'], $summary,
                        self::details(self::CREATE, $calendar, $googleId, null, null, self::etagOf($resp), ['undoes' => (int) $mutation['id']] + ($occurrence === null ? [] : ['occurrence' => $occurrence])),
                        $back === null && $occurrence !== null && (int) $mutation['entity_id'] > 0 ? [[Undo::keyOf('events', ['id' => (int) $mutation['entity_id']]), Undo::SKIP . $occurrence['key']]] : null);
                });
                return ['entity' => 'event', 'op' => 'update'];

            case self::CREATE:
                $occurrence = $row === null && is_array($g['occurrence'] ?? null) && is_string($g['occurrence']['uid'] ?? null) && is_string($g['occurrence']['key'] ?? null)
                    ? $g['occurrence'] : null;
                if ($row === null && $occurrence !== null) {
                    // A day of a series brought back with no row of its own: skipped again, as deleting
                    // that one occurrence skips it (Events: the instance deleted at Google, a skip here).
                        self::refusingConflict(fn() => $writer->delete($calendar, $googleId, $beforeSend));
                    $this->commit($mutation, $calendar, $authority, function () use ($writer, $calendar, $googleId, $userId, $summary, $mutation, $occurrence, $g): void {
                        $writer->apply($calendar, [], [GoogleWriter::tombstone($occurrence['uid'], $occurrence['key'])]);
                        $master = $this->db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL',
                            [(int) $calendar['id'], $occurrence['uid']]);
                        $this->record($userId, $master !== null ? (int) $master : (int) $mutation['entity_id'], $summary,
                            self::details(self::DELETE, $calendar, $googleId, ['status' => (string) ($occurrence['status'] ?? 'confirmed')], $g['etagAfter'] ?? null, null, ['local' => [], 'undoes' => (int) $mutation['id']]),
                            $master !== null ? [[Undo::keyOf('events', ['id' => (int) $master]), Undo::SKIP . $occurrence['key']]] : null);
                    });
                    return ['entity' => 'event', 'op' => 'update'];
                }
                if ($row === null) {
                    throw HttpError::badRequest('That event is no longer here to remove', 'not_undoable');
                }
                $local = $this->localOf($row);
                self::refusingConflict(fn() => $writer->delete($calendar, $googleId, $beforeSend));
                $this->commit($mutation, $calendar, $authority, function () use ($writer, $calendar, $row, $googleId, $userId, $summary, $local, $g, $mutation): void {
                    $writer->apply($calendar, [], [GoogleWriter::tombstone((string) $row['uid'], $row['recurrence_instance_utc'] !== null ? (string) $row['recurrence_instance_utc'] : null)]);
                    $this->record($userId, (int) $row['id'], $summary,
                        self::details(self::DELETE, $calendar, $googleId, ['status' => self::bodyOf($row)['status']], $g['etagAfter'] ?? null, null, ['local' => $local, 'undoes' => (int) $mutation['id']]));
                });
                return ['entity' => 'event', 'op' => 'update'];

            case self::ANSWER:
                if ($row === null || !is_string($g['responseStatus'] ?? null)) {
                    throw HttpError::badRequest('That invitation is no longer here to answer again', 'not_undoable');
                }
                // Google replaces the whole guest list on a write: the list
                // as it is now, with only my answer put back.
                $current = $writer->get($calendar, $googleId);
                $attendees = (array) ($current['attendees'] ?? []);
                $was = null;
                foreach ($attendees as $i => $a) {
                    if (is_array($a) && !empty($a['self'])) {
                        $was = (string) ($a['responseStatus'] ?? 'needsAction');
                        $attendees[$i]['responseStatus'] = (string) $g['responseStatus'];
                    }
                }
                if ($was === null) {
                    throw HttpError::badRequest('Google no longer lists this account as a guest of the event', 'not_undoable');
                }
                $resp = self::refusingConflict(fn() => $writer->patch($calendar, $googleId, ['attendees' => array_values($attendees)], $beforeSend, $current));
                $this->commit($mutation, $calendar, $authority, function () use ($writer, $calendar, $resp, $row, $googleId, $userId, $summary, $was, $current, $g, $mutation): void {
                    $attendance = $row['attendance'] ?? null;
                    $writer->apply($calendar, [$resp]);
                    if (is_string($g['attendance'] ?? null)) {
                        $this->db->run('UPDATE events SET attendance = ? WHERE id = ?', [$g['attendance'], (int) $row['id']]);
                    }
                    $this->record($userId, (int) $row['id'], $summary,
                        self::details(self::ANSWER, $calendar, $googleId, null, self::etagOf($current), self::etagOf($resp),
                            ['responseStatus' => $was, 'attendance' => is_string($attendance) ? $attendance : null, 'undoes' => (int) $mutation['id']]),
                        array_map(static fn(string $col) => [Undo::keyOf('events', ['id' => (int) $row['id']]), $col], ['invite_json', 'status', 'attendance']));
                });
                return ['entity' => 'event', 'op' => 'update'];
        }
        throw HttpError::badRequest('This entry has nothing to send back to Google', 'not_undoable');
    }

    /**
     * Each day's row as the undo's series places it, by row id: its
     * target's key. Pure.
     *
     * @param list<array> $targets
     * @return array<int,string>
     */
    private static function placedOf(array $targets): array
    {
        $placed = [];
        foreach ($targets as $tg) {
            foreach ((array) ($tg['exceptions'] ?? []) as $key => $x) {
                if (!empty($x['rowId'])) {
                    $placed[(int) $x['rowId']] = (string) $key;
                }
            }
        }
        return $placed;
    }

    /**
     * Undo a whole-series change: an ordinary series write. What it asks of
     * each series is the inverse of the undone change's own deltas, applied
     * over the series as stored now (inverseOf); it is refused first where
     * the trip rules refuse putting the snapshots back (Undo::checkRestore),
     * then sent to Google through GoogleSeries::apply like any change (the
     * read and digest check before its first step, its writes, the read-back
     * and the one rebase over it), the snapshots put back here for the rows'
     * identities, tags and people, Google's read-back stored on top, the
     * pending row settled, the entry marked undone and the undo recorded,
     * all in the account's write. A failure before anything reached Google
     * rolls all of it back (a 412 is the conflict); one after a step did
     * commits the pending row and what Google has, and is thrown once the
     * write has committed.
     *
     * @return array{entity:string,op:string}
     */
    private function undoSeries(array $mutation, array $g, ?RequestAuthority $authority, bool $force = false): array
    {
        $userId = (int) $mutation['user_id'];
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ? AND user_id = ?', [(int) $g['calendarId'], $userId]);
        if ($calendar === null || !GoogleWriter::writable($calendar)) {
            throw HttpError::badRequest("That calendar no longer writes to Google from here, so this change can't be undone", 'not_undoable');
        }
        $summary = self::undoSummary((string) $mutation['summary'], (string) ($mutation['details_json'] ?? ''));
        $beforeSend = $authority === null ? null : fn() => $authority->assertValid($this->db, false);
        $series = new GoogleSeries($this->db, $this->writer(), new Feeds($this->db));
        // The check is the restore it guards: the same snapshots at the keys the undo's series gives them.
        $this->undo->checkRestore($mutation, false, fn(): array => self::placedOf($series->withStoredIds($calendar, $this->inverseOf($mutation, $calendar, (array) $g['before'], $force)[0])));
        $deferred = null;
        $this->undo->write($userId, function () use ($mutation, $g, $calendar, $authority, $beforeSend, $series, $userId, $summary, $force, &$deferred): void {
            $authority?->assertValid($this->db);
            $locked = CalendarMoveGuard::lockCalendar($this->db, (int) $calendar['id']);
            if ($locked === null || !GoogleSync::sameBinding($calendar, $locked)) {
                throw HttpError::conflict('google_binding_changed', 'This calendar changed while the undo was running. Reload before trying again.');
            }
            $fresh = $this->db->one('SELECT * FROM mutations WHERE id = ?', [(int) $mutation['id']]);
            if ($fresh === null || (int) $fresh['undone'] === 1) {
                throw HttpError::badRequest('Already undone', 'already_undone');
            }
            [$inverse, $kept, $overwrote, $removed, $moves] = $this->inverseOf($fresh, $locked, (array) $g['before'], $force);
            $targets = $series->withStoredIds($locked, $inverse);
            $title = (string) ($targets[0]['master']['title'] ?? '');
            try {
                // Its part here, kept with a pending row for Finish and Put
                // back, whole: which entry it undoes, that entry as it is now
                // (its snapshots, which Finish puts back, so Finish never
                // depends on the entry still being there), and its words:
                // its bare action (undoSummary's, composed into a line once,
                // by line), what Google kept, what it overwrote, the days it
                // removes.
                $out = $series->apply($userId, $locked, $targets, $title, $summary, (int) $mutation['entity_id'] ?: null, $beforeSend,
                    ['undo' => (int) $mutation['id'], 'entry' => array_intersect_key($fresh, array_flip(['id', 'user_id', 'entity_id', 'summary', 'details_json', 'before_json', 'after_json'])),
                        'action' => $summary, 'kept' => $kept, 'forced' => $overwrote] + ($removed === [] ? [] : ['removedDays' => $removed]) + ($moves === [] ? [] : ['moves' => $moves]));
            } catch (HttpError $e) {
                if (!GoogleSeries::reachedGoogle($e)) {
                    throw $e->errorCode === 'google_conflict' ? self::conflict() : $e; // nothing reached Google: all of it rolls back
                }
                $deferred = $e;
                return;
            }
            // The rows as the change left them, the undo's own before.
            $ids = $this->undo->snapshotEventIds($fresh);
            $uids = array_filter(array_map(static fn(array $t): ?string => isset($t['uid']) ? (string) $t['uid'] : null, $targets));
            $was = $this->seriesTables((int) $locked['id'], $uids, $ids);
            $this->undo->restoreSnapshots($fresh, false, self::placedOf($targets));
            $series->store($locked, $out['readbacks'], $out['targets']);
            // A day the undo keeps from Google (changed there while the
            // change ran) stands as the row it was, not one made again.
            $series->reattach($locked, $was, $out['targets']);
            $series->settle($out['pending']);
            $this->finishUndo($fresh, $locked, $summary, array_merge($kept, $out['differences']), $out['befores'], $was, $uids, array_keys($out['readbacks']), $overwrote, null, $removed, $moves);
        });
        if ($deferred !== null) {
            throw $deferred;
        }
        return ['entity' => 'event', 'op' => 'update'];
    }

    /**
     * The journal of an undo of a series entry once Google holds it, the
     * same whether the undo completed or a Finish of it did: the entry
     * marked undone and the undo's own entry recorded (its befores for a
     * redo, the rows as they were and are), its words naming what Google
     * kept. Inside the account's write.
     *
     * @param list<string> $kept
     * @param list<array> $befores
     * @param list<string> $uids
     * @param list<string> $readUids
     */
    public function finishUndo(array $mutation, array $calendar, string $summary, array $kept, array $befores, array $was, array $uids, array $readUids, array $forced = [], ?string $note = null, array $removed = [], array $touches = []): int
    {
        $this->db->run('UPDATE mutations SET undone = 1 WHERE id = ?', [(int) $mutation['id']]);
        $ids = $this->undo->snapshotEventIds($mutation);
        // The days it removed, named by row and date (Feeds::droppedDay), as a poll names its own.
        if ($removed !== []) {
            $kept[] = 'Removed here: ' . Feeds::droppedWords($removed) . '.';
        }
        return $this->recordSeries((int) $mutation['user_id'], (int) $mutation['entity_id'], $summary, $calendar, $befores, $was,
            $this->seriesTables((int) $calendar['id'], array_merge($uids, $readUids), array_merge($ids, array_map('intval', array_column($was['events'] ?? [], 'id')))), (int) $mutation['id'],
            array_values(array_unique($kept)), array_values(array_unique($forced)), $note, $removed, $touches);
    }

    /**
     * An undo's action from its entry: the entry's own action (details
     * `action`, what it did, with no calendar or kept words), "Undid: " put
     * on, or taken off for a redo, and a Finish's note taken off. An entry
     * recorded before actions were kept gives its summary as it is.
     */
    public static function undoSummary(string $summary, ?string $detailsJson = null): string
    {
        $action = json_decode((string) $detailsJson, true)['google']['action'] ?? null;
        $summary = is_string($action) ? $action : self::actionOf($summary);
        $summary = rtrim(trim($summary), ' .');
        return str_starts_with($summary, self::UNDID) ? substr($summary, strlen(self::UNDID)) : self::UNDID . $summary;
    }

    /**
     * The action of a line written before entries kept it (details
     * `action`): the line less what the composer (line) adds, the calendar,
     * a Finish's note and the words for what Google kept or what was put
     * back over, so a line composed from it never repeats them. Pure.
     */
    public static function actionOf(string $line): string
    {
        foreach ([self::KEPT, trim(self::FORCED), self::FINISHED, " on Google calendar '"] as $part) {
            $at = strpos($line, $part);
            if ($at !== false) {
                $line = substr($line, 0, $at);
            }
        }
        return rtrim(trim($line), ' .');
    }

    /**
     * An entry's one line, composed from its parts, never cut from another
     * line: what was done, on which calendar, then what Google kept, each
     * part whole where the column (300) allows it; what is left out to fit
     * is the end of the kept words, then of the action, never the calendar.
     *
     * @param list<string> $kept
     */
    public static function line(string $action, string $calendarName, array $kept = [], array $forced = [], ?string $note = null): string
    {
        $max = 300;
        // The parts carry no punctuation of their own at their ends; the composer puts it.
        $action = rtrim(trim($action), " .");
        $on = " on Google calendar '" . $calendarName . "'.";
        $tail = $note === null || trim($note) === '' ? '' : ' ' . rtrim(trim($note), '.') . '.';
        $fixed = mb_strlen($on) + mb_strlen($tail);
        if (mb_strlen($action) + $fixed > $max) {
            $action = mb_substr($action, 0, max(0, $max - $fixed - 1)) . '…';
        }
        $line = $action . $on . $tail;
        foreach ([[self::FORCED, $forced], [self::KEPT, $kept]] as [$heading, $list]) {
            $words = trim(implode(' ', array_values(array_unique($list))));
            $room = $max - mb_strlen($line) - mb_strlen($heading);
            if ($words === '' || $room < 2) {
                continue;
            }
            $line .= $heading . (mb_strlen($words) > $room ? mb_substr($words, 0, $room - 1) . '…' : $words);
        }
        return $line;
    }

    /**
     * What an undo of a series entry asks of each series: the inverse of
     * what the undone change asked (its `asked`, back to its before),
     * planned as any change is planned (rule A, decisions.md): the series'
     * own fields it changed, set back to what they were
     * (GoogleSeries::inverseEdit), are the planner's input
     * (Series\Model::setPlan) over the series as stored now, so its edited
     * days and skips are carried to their places exactly as a forward
     * times, zone or count change carries them; the days the change itself changed beyond carrying them (a day
     * it skipped, dropped, or gave or took fields of its own) go back at
     * their places in what the planner makes (GoogleSeries::withDayDeltas).
     * A redo is the same: its entry's befores carry what the undo asked. A
     * value the change changed that holds otherwise now was changed at
     * Google while the change ran (a later change of it, here or polled,
     * made the entry stale: Undo::staleFor): kept and named, or, forced,
     * put back over and named. An entry recorded before changes kept what
     * they asked is refused: what it asked can't be told from what it
     * learned from Google. A series the undo removes, one not here, and the
     * events of their own go as recorded.
     *
     * @param list<array> $befores
     * @return array{0:list<array>,1:list<string>,2:list<string>,3:list<array>} the targets, words for what was kept, words for what was put back over, the days it removes (Feeds::droppedDay)
     */
    private function inverseOf(array $mutation, array $calendar, array $befores, bool $force = false): array
    {
        $year = (int) \BetterCal\Support\Time::nowUtc()->format('Y');
        $out = [];
        $words = [];
        $overwrote = [];
        $removed = [];
        $moves = [];
        foreach ($befores as $i => $t) {
            $asked = $t['asked'] ?? null;
            unset($t['asked']);
            $out[$i] = $t;
            if (!empty($t['remove']) || empty($t['master'])) {
                continue;
            }
            if (!is_array($asked) || empty($asked['master'])) {
                throw HttpError::badRequest("This change was saved before Better-Cal kept what each change asks of Google, so its undo can't tell its own changes from Google's. Change it back by hand.", 'not_undoable');
            }
            $master = !empty($t['uid'])
                ? $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [(int) $calendar['id'], (string) $t['uid']])
                : null;
            $master ??= $this->writer()->rowByGoogleId((int) $calendar['id'], (string) $t['googleId']);
            if ($master === null || empty($master['rrule'])) {
                continue;
            }
            $rows = $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [(int) $master['id']]);
            $now = GoogleSeries::stateOf($master, $rows);
            [$set, $kept, $over] = GoogleSeries::inverseEdit($t, $asked, $now, $force);
            // A day is the same day by what carried it: the change's own
            // moves, the later ones (Undo::dayNow), and the planner's
            // placement of the undo (its rows and skips; any other day by
            // its occurrence, as the sync follows one, Feeds::ofSeries).
            $moved = [];
            foreach ($this->db->all('SELECT col FROM mutation_touches WHERE mutation_id = ? AND row_key = ? AND col LIKE ?',
                [(int) $mutation['id'], Undo::keyOf('events', $master), Undo::MOVE . '%']) as $m) {
                [$from, $to] = explode('>', substr((string) $m['col'], strlen(Undo::MOVE)), 2) + [1 => ''];
                $moved[$from] = $to;
            }
            // The days the change carried, where they are now => where it found them.
            $back = [];
            foreach ($moved as $from => $to) {
                $at = $this->undo->dayNow((int) $mutation['id'], $master, (string) $to);
                if ($at !== null) {
                    $back[$at] = (string) $from;
                }
            }
            $target = $now;
            $writes = null;
            $after = $master;
            if ($set !== []) {
                $planned = Model::setPlan($master, $rows, $set, ['google' => true], $back);
                if ($planned['writes'] === null) {
                    throw HttpError::badRequest(($planned['code'] ?? '') === 'unreadable' || !isset(Model::REASONS[$planned['code'] ?? ''])
                        ? "This series repeats in a way Better-Cal can't walk, so this change can't be undone here. Change it back by hand."
                        : "This change can't be undone as a whole: " . Model::REASONS[$planned['code']], 'not_undoable');
                }
                $writes = $planned['writes'];
                $moves = array_merge($moves, Undo::skipMoveGrounds((int) $master['id'], $writes['skipMoves'] ?? []), Undo::addedGrounds((int) $master['id'], $writes['added'] ?? null));
                $target = GoogleSeries::targetOf($master, $rows, $writes);
                $after = array_merge($master, $writes['master']);
                // A day the restored series no longer has is removed, and named by its row and date.
                foreach ($rows as $row) {
                    if (in_array((int) $row['id'], array_map('intval', $writes['delete']), true)) {
                        $own = OwnFields::decode($row['override_fields'] ?? null) ?? [];
                        $removed[] = Feeds::droppedDay($row, Feeds::heldHere($own, $row), OwnFields::references($this->db, [(int) $row['id']])[(int) $row['id']] ?? [], 'gone');
                    }
                }
            }
            $rowAt = [];
            foreach ($rows as $row) {
                $rowAt[(string) $row['recurrence_instance_utc']] = (int) $row['id'];
            }
            $imageIn = static function (string $key, array $was, array $series): ?string {
                $at = Feeds::ofSeries(['recurrence_instance_utc' => $key], ['exdates_json' => null] + $was, ['exdates_json' => null] + $series);
                return $at === null ? null : (string) ($at['recurrence_instance_utc'] ?? $key);
            };
            $found = array_merge($master, $t['master']);
            $follow = function (?string $kb, ?string $ka) use ($mutation, $master, $now, $target, $writes, $after, $rowAt, $imageIn, $found): array {
                // Where the day is now (dayNow, the one walk of a day's identity): a day the change
                // dropped whose date a day holds now is that day, and what the undo brings back is composed onto it.
                $kc = $this->undo->dayNow((int) $mutation['id'], $master, (string) ($ka ?? $kb));
                if ($kc === null) {
                    // A day the series no longer has comes back on its date where the undo's series has it.
                    return [null, $imageIn((string) ($kb ?? $ka), $found, $after)];
                }
                if ($writes === null) {
                    return [$kc, $kc];
                }
                $id = $rowAt[$kc] ?? null;
                if ($id !== null && isset($writes['overrides'][$id]['recurrence_instance_utc'])) {
                    return [$kc, (string) $writes['overrides'][$id]['recurrence_instance_utc']];
                }
                if (in_array($kc, $now['skips'], true)) {
                    return [$kc, isset($writes['skipMoves'][$kc]) ? (string) $writes['skipMoves'][$kc] : (in_array($kc, $target['skips'], true) ? $kc : null)];
                }
                return [$kc, $imageIn($kc, $master, $after)];
            };
            [$target, $dayKept, $dayOver] = GoogleSeries::withDayDeltas($target, $t, $asked, $now, $year, $force, $moved, $follow);
            // The events of their own the change made or removed go as recorded (their removal, or back).
            $out[$i] = array_replace($target, ['singles' => array_merge((array) ($t['singles'] ?? []), $target['singles'] ?? [])]);
            $words = array_merge($words, $kept, $dayKept);
            $overwrote = array_merge($overwrote, $over, $dayOver);
        }
        return [array_values($out), $words, $overwrote, $removed, $moves];
    }

    /**
     * An undo or redo touching a series with changes not yet at Google is
     * refused before anything is read or sent (GoogleSeries::refusePending):
     * the series its entry names (what it sends back to Google: a series'
     * uid and id, an event's or a day's id, a day's series), and those of
     * the event the entry is about and of the rows its snapshots hold, as
     * they are now and as the snapshots hold them (a row gone since still
     * names its series), each on its calendar. Asked for every entry (Undo::undoById), one sent to
     * Google or one put back here alone.
     */
    public static function refuseWhilePending(Db $db, Undo $undo, array $mutation): void
    {
        $userId = (int) $mutation['user_id'];
        $names = [];
        $details = json_decode((string) ($mutation['details_json'] ?? ''), true);
        if (self::carries(is_array($details) ? $details : null)) {
            $g = $details['google'];
            $calendarId = (int) $g['calendarId'];
            $names[$calendarId][] = (string) ($g['googleId'] ?? '');
            $names[$calendarId][] = (string) ($g['occurrence']['uid'] ?? '');
            foreach ((string) $g['kind'] === self::SERIES ? (array) ($g['before'] ?? []) : [] as $t) {
                array_push($names[$calendarId], (string) ($t['uid'] ?? ''), (string) ($t['googleId'] ?? ''));
            }
        }
        $ids = $undo->snapshotEventIds($mutation);
        if ((string) ($mutation['entity'] ?? '') === 'event' && (int) ($mutation['entity_id'] ?? 0) > 0) {
            $ids[] = (int) $mutation['entity_id'];
        }
        // The rows as they are now, and as its snapshots hold them: a row gone since still names its series.
        $rows = $undo->snapshotEventRows($mutation);
        if ($ids !== []) {
            [$in, $params] = Db::in($ids);
            $rows = array_merge($rows, $db->all("SELECT * FROM events WHERE id IN $in", $params));
        }
        $google = [];
        foreach ($rows as $row) {
            $calendarId = (int) ($row['calendar_id'] ?? 0);
            $google[$calendarId] ??= $calendarId > 0 && $db->scalar("SELECT 1 FROM calendars WHERE id = ? AND provider = 'google'", [$calendarId]) !== null;
            if ($google[$calendarId]) {
                $names[$calendarId] = array_merge($names[$calendarId] ?? [], GoogleSeries::namesOf($row));
            }
        }
        foreach ($names as $calendarId => $list) {
            GoogleSeries::refusePending($db, $userId, (int) $calendarId, array_values(array_filter($list, static fn(string $n): bool => $n !== '')));
        }
    }

    private function writer(): GoogleWriter
    {
        return $this->writer ??= new GoogleWriter($this->db, new GoogleAuth($this->db, config()), new Feeds($this->db, null, config()));
    }

    /**
     * Store what Google answered and record the undo, in one write for the
     * account: the credential checked first, the calendar still the one
     * the change went to, then the entry marked undone.
     */
    private function commit(array $mutation, array $calendar, ?RequestAuthority $authority, callable $fn): void
    {
        $this->undo->write((int) $mutation['user_id'], function () use ($mutation, $calendar, $authority, $fn): void {
            $authority?->assertValid($this->db);
            $locked = CalendarMoveGuard::lockCalendar($this->db, (int) $calendar['id']);
            if ($locked === null || !GoogleSync::sameBinding($calendar, $locked)) {
                throw HttpError::conflict('google_binding_changed', 'This calendar changed while the undo was running. Reload before trying again.');
            }
            $fn();
            $this->db->run('UPDATE mutations SET undone = 1 WHERE id = ?', [(int) $mutation['id']]);
        });
    }

    /** An event row and its tags and people, as an edit's entry keeps them. @return array<string,list<array>> */
    private function rowTables(int $id): array
    {
        return [
            'events' => $this->db->all('SELECT * FROM events WHERE id = ?', [$id]),
            'event_tags' => $this->db->all('SELECT * FROM event_tags WHERE event_id = ?', [$id]),
            'event_people' => $this->db->all('SELECT * FROM event_people WHERE event_id = ?', [$id]),
        ];
    }

    /** The row Google knows by $googleId here, or $fallback. */
    private function rowId(array $calendar, string $googleId, int $fallback): int
    {
        $row = $this->writer()->rowByGoogleId((int) $calendar['id'], $googleId);
        return $row !== null ? (int) $row['id'] : $fallback;
    }

    /**
     * For an entry recorded before 1.5.5, which kept only a list of the
     * row's local parts (details `local`) and not the row: the row for a
     * day brought back at Google whose answer equals its series on that
     * day, when the day had local things of its own (tags, people,
     * reminders, the trip flag, an own list): the sync stores no row for
     * such a record (Feeds::sync, an ordinary occurrence), so the undo, the
     * writer here, makes it as the sync would have. Null when the day had
     * nothing local, or Google's answer is no day of a series. Such entries
     * leave the undo window a week after the upgrade.
     */
    private function rowForLegacy(array $calendar, array $resource, array $local): ?array
    {
        $hasLocal = (array) ($local['tags'] ?? []) !== [] || (array) ($local['people'] ?? []) !== [] || is_string($local['reminders'] ?? null)
            || (int) ($local['trip'] ?? 0) === 1 || (OwnFields::decode($local['own'] ?? null) ?? []) !== [];
        $parsed = GoogleSync::toParsed($resource);
        if (!$hasLocal || $parsed === null || !empty($parsed['cancelled']) || $parsed['recurrence_instance_utc'] === null) {
            return null;
        }
        $masterId = $this->db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [(int) $calendar['id'], (string) $parsed['uid']]);
        if ($masterId === null) {
            return null;
        }
        $columns = Feeds::columnsOf($parsed, (int) $masterId) + [
            'user_id' => (int) $calendar['user_id'],
            'calendar_id' => (int) $calendar['id'],
            'uid' => (string) $parsed['uid'],
            'source' => 'feed',
            'created_via' => 'feed',
        ] + (isset($parsed['google_etag']) ? ['google_etag' => $parsed['google_etag']] : []);
        $id = (int) $this->db->insert('events', $columns);
        return $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
    }

    /** For an entry recorded before 1.5.5 (rowForLegacy): the tags, people, reminders, trip flag and own list it kept, back on the row the undo brought back (tags and people still in the account). */
    private function restoreLegacy(int $userId, int $eventId, array $local): void
    {
        $tags = array_values(array_filter(array_map('intval', (array) ($local['tags'] ?? [])), static fn(int $id): bool => $id > 0));
        if ($tags !== []) {
            [$in, $params] = Db::in($tags);
            $this->db->run($this->db->insertIgnore() . " INTO event_tags (event_id, tag_id) SELECT ?, id FROM tags WHERE user_id = ? AND id IN $in", array_merge([$eventId, $userId], $params));
        }
        $people = array_values(array_filter(array_map('intval', (array) ($local['people'] ?? [])), static fn(int $id): bool => $id > 0));
        if ($people !== []) {
            [$in, $params] = Db::in($people);
            $this->db->run($this->db->insertIgnore() . " INTO event_people (event_id, person_id) SELECT ?, id FROM people WHERE user_id = ? AND id IN $in", array_merge([$eventId, $userId], $params));
        }
        if (is_string($local['reminders'] ?? null)) {
            $this->db->run('UPDATE events SET reminders_json = ? WHERE id = ?', [$local['reminders'], $eventId]);
        }
        if ((int) ($local['trip'] ?? 0) === 1) {
            $this->db->run('UPDATE events SET is_container = 1 WHERE id = ?', [$eventId]);
        }
        if (is_string($local['own'] ?? null) && OwnFields::hasColumn($this->db)) {
            $this->db->run('UPDATE events SET override_fields = ? WHERE id = ?', [$local['own'], $eventId]);
        }
    }

    private static function etagOf(array $resource): ?string
    {
        return isset($resource['etag']) && is_string($resource['etag']) && $resource['etag'] !== '' ? $resource['etag'] : null;
    }

    /** Changed at Google since: the stale-undo words and the conflict's sentence. */
    public static function conflict(): HttpError
    {
        return new HttpError('google_conflict', 'Newer changes exist for this item; undoing would overwrite them. This event changed at Google since you last saw it.', 409);
    }

    private static function refusingConflict(callable $send): mixed
    {
        try {
            return $send();
        } catch (HttpError $e) {
            if ($e->errorCode === 'google_conflict') {
                throw self::conflict();
            }
            throw $e;
        }
    }
}
