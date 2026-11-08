<?php

declare(strict_types=1);

namespace BetterCal\Domain;

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

    public function __construct(
        private readonly Db $db,
        private readonly Undo $undo,
        private ?GoogleWriter $writer = null,
    ) {
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
     * one) as null, which clears it at Google. Pure.
     *
     * @param list<string> $changed
     */
    public static function inverse(array $before, array $changed): array
    {
        $out = [];
        foreach ($changed as $key) {
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
    public function recordSeries(int $userId, int $eventId, string $summary, array $calendar, array $befores, array $beforeTables, array $afterTables, ?int $undoes = null): int
    {
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
                $undoes === null ? [] : ['undoes' => $undoes]),
            null, 'update', [$beforeTables, $afterTables]);
    }

    /**
     * Undo one entry that carries() a Google before state: the inverse sent
     * to Google, its answer stored as a poll would store it, the entry
     * marked undone and the undo recorded. The caller has checked the
     * entry is the account's, not undone, and (unless forced) not stale.
     *
     * @return array{entity:string,op:string}
     */
    public function undo(array $mutation, ?RequestAuthority $authority = null): array
    {
        $details = json_decode((string) ($mutation['details_json'] ?? ''), true);
        if (!self::carries(is_array($details) ? $details : null)) {
            throw HttpError::badRequest('This entry has nothing to send back to Google', 'not_undoable');
        }
        $g = $details['google'];
        $userId = (int) $mutation['user_id'];
        if ((string) $g['kind'] === self::SERIES) {
            return $this->undoSeries($mutation, $g, $authority);
        }
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ? AND user_id = ?', [(int) $g['calendarId'], $userId]);
        if ($calendar === null || !GoogleWriter::writable($calendar)) {
            throw HttpError::badRequest("That calendar no longer writes to Google from here, so this change can't be undone", 'not_undoable');
        }
        $googleId = (string) $g['googleId'];
        $writer = $this->writer();
        $beforeSend = $authority === null ? null : fn() => $authority->assertValid($this->db, false);
        $row = $writer->rowByGoogleId((int) $calendar['id'], $googleId);
        $summary = str_starts_with((string) $mutation['summary'], self::UNDID)
            ? substr((string) $mutation['summary'], strlen(self::UNDID))
            : self::UNDID . (string) $mutation['summary'];

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
                // field made this entry stale: Undo::staleFor).
                $changed = self::changedOf($g, $leftRow);
                $send = self::inverse($g['before'], $changed);
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
                $etag = $current !== null ? (self::etagOf($current) ?? '') : null;
                $rowId = $row !== null ? (int) $row['id'] : (int) $wasRow['id'];
                // What the edit changed here (tags, reminders, the trip flag,
                // the row itself when the edit made it) is put back from the
                // snapshots, refused before Google is sent anything when it
                // can't be.
                if ($local) {
                    $this->undo->checkRestore($mutation, true);
                }
                $resp = $send === [] ? null : self::refusingConflict(fn() => $writer->patch($calendar, $googleId, $send, $beforeSend, $etag));
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
                $resp = self::refusingConflict(fn() => $writer->patch($calendar, $googleId, ['status' => $status], $beforeSend, self::etagOf($current) ?? ''));
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
                    $this->record($userId, $back !== null ? (int) $back['id'] : (int) $mutation['entity_id'], $summary,
                        self::details(self::CREATE, $calendar, $googleId, null, null, self::etagOf($resp), ['undoes' => (int) $mutation['id']] + ($occurrence === null ? [] : ['occurrence' => $occurrence])));
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
                            self::details(self::DELETE, $calendar, $googleId, ['status' => (string) ($occurrence['status'] ?? 'confirmed')], $g['etagAfter'] ?? null, null, ['local' => [], 'undoes' => (int) $mutation['id']]));
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
                $resp = self::refusingConflict(fn() => $writer->patch($calendar, $googleId, ['attendees' => array_values($attendees)], $beforeSend));
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
     * Undo a whole-series change: the befores worked out from what putting
     * the snapshots back leaves, refused first where the trip rules refuse
     * that (rebased), then sent to Google
     * (GoogleSeries::apply, each series conditional on its stored etag),
     * the snapshots put back here, Google's read-back stored on top, the
     * pending row settled, the entry marked undone and the undo recorded,
     * all in the account's write. A failure before anything reached Google
     * rolls all of it back (a 412 is the conflict); one after a step did
     * commits the pending row and what Google has, and is thrown once the
     * write has committed.
     *
     * @return array{entity:string,op:string}
     */
    private function undoSeries(array $mutation, array $g, ?RequestAuthority $authority): array
    {
        $userId = (int) $mutation['user_id'];
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ? AND user_id = ?', [(int) $g['calendarId'], $userId]);
        if ($calendar === null || !GoogleWriter::writable($calendar)) {
            throw HttpError::badRequest("That calendar no longer writes to Google from here, so this change can't be undone", 'not_undoable');
        }
        $summary = str_starts_with((string) $mutation['summary'], self::UNDID)
            ? substr((string) $mutation['summary'], strlen(self::UNDID))
            : self::UNDID . (string) $mutation['summary'];
        $beforeSend = $authority === null ? null : fn() => $authority->assertValid($this->db, false);
        $series = new GoogleSeries($this->db, $this->writer(), new Feeds($this->db));
        $befores = $this->rebased($mutation, $calendar, (array) $g['before']);
        $deferred = null;
        $this->undo->write($userId, function () use ($mutation, $befores, $calendar, $authority, $beforeSend, $series, $userId, $summary, &$deferred): void {
            $authority?->assertValid($this->db);
            $locked = CalendarMoveGuard::lockCalendar($this->db, (int) $calendar['id']);
            if ($locked === null || !GoogleSync::sameBinding($calendar, $locked)) {
                throw HttpError::conflict('google_binding_changed', 'This calendar changed while the undo was running. Reload before trying again.');
            }
            $fresh = $this->db->one('SELECT * FROM mutations WHERE id = ?', [(int) $mutation['id']]);
            if ($fresh === null || (int) $fresh['undone'] === 1) {
                throw HttpError::badRequest('Already undone', 'already_undone');
            }
            $targets = $series->withStoredEtags($locked, $befores);
            $title = (string) ($targets[0]['master']['title'] ?? '');
            try {
                $out = $series->apply($userId, $locked, $targets, $title, $summary, (int) $mutation['entity_id'] ?: null, $beforeSend);
            } catch (HttpError $e) {
                $pending = isset($e->extra['pending']) ? (int) $e->extra['pending'] : null;
                if ($pending === null || $series->stepsDone($pending) === 0) {
                    throw $e->errorCode === 'google_conflict' ? self::conflict() : $e; // nothing reached Google: all of it rolls back
                }
                $deferred = $e;
                return;
            }
            // The rows as the change left them, the undo's own before.
            $ids = $this->undo->snapshotEventIds($fresh);
            $uids = array_filter(array_map(static fn(array $t): ?string => isset($t['uid']) ? (string) $t['uid'] : null, $targets));
            $was = $this->seriesTables((int) $locked['id'], $uids, $ids);
            $this->undo->restoreSnapshots($fresh);
            $series->store($locked, $out['readbacks'], $out['targets']);
            $series->settle($out['pending']);
            $this->db->run('UPDATE mutations SET undone = 1 WHERE id = ?', [(int) $mutation['id']]);
            $uids = array_merge($uids, array_keys($out['readbacks']));
            $this->recordSeries($userId, (int) $mutation['entity_id'], $summary, $locked, $out['befores'], $was,
                $this->seriesTables((int) $locked['id'], $uids, array_merge($ids, array_map('intval', array_column($was['events'], 'id')))), (int) $mutation['id']);
        });
        if ($deferred !== null) {
            throw $deferred;
        }
        return ['entity' => 'event', 'op' => 'update'];
    }

    /**
     * The befores an undo sends: each series the entry changed as putting
     * its snapshots back here leaves it, which gives back the columns and
     * rows the entry changed and keeps what changed since in the rest (a
     * rename after a new end, undone by its entry, keeps the rename), as a
     * local undo does; a series the undo removes, and the events of their
     * own, as recorded. Worked out in a write of its own that is rolled
     * back, which also refuses first what putting the snapshots back would
     * refuse (Undo::checkRestore), before Google is sent anything. Inside
     * another transaction there is nothing to roll back to on its own, and
     * the befores are sent as recorded.
     *
     * @param list<array> $befores
     * @return list<array>
     */
    private function rebased(array $mutation, array $calendar, array $befores): array
    {
        if ($this->db->pdo()->inTransaction()) {
            return $befores;
        }
        $done = new \RuntimeException('rebased');
        $out = $befores;
        try {
            $this->undo->write((int) $mutation['user_id'], function () use ($mutation, $calendar, $befores, &$out, $done): void {
                $this->undo->restoreSnapshots($mutation);
                foreach ($befores as $i => $t) {
                    if (!empty($t['remove'])) {
                        continue;
                    }
                    $master = !empty($t['uid'])
                        ? $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [(int) $calendar['id'], (string) $t['uid']])
                        : null;
                    $master ??= $this->writer()->rowByGoogleId((int) $calendar['id'], (string) $t['googleId']);
                    if ($master === null || empty($master['rrule'])) {
                        continue;
                    }
                    $now = GoogleSeries::stateOf($master, $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [(int) $master['id']]));
                    $out[$i] = array_replace($t, ['master' => $now['master'], 'exceptions' => $now['exceptions'], 'skips' => $now['skips']]);
                }
                throw $done;
            });
        } catch (\RuntimeException $e) {
            if ($e !== $done) {
                throw $e;
            }
        }
        return $out;
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
