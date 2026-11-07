<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

/**
 * Undo for a write to one Google resource (#172): a single event, or one
 * occurrence of a series, which Google keeps as one resource of its own
 * (the probe, 2026-11-07, groups 2, 5 and 6).
 *
 * The entry is shaped as a plugin edit's: empty snapshots ({"tables":[]}),
 * since the row is Google's, and what to send back in its details, under
 * `google`:
 *
 *   kind        edit, delete, create or answer: what the change did
 *   calendarId  the local calendar
 *   googleId    the resource's id at Google (an occurrence's instance id)
 *   before      edit: the resource's fields as GoogleWriter sends them
 *               (summary, description, location, status, start, end,
 *               source, and recurrence for an event in no series), as they
 *               were before the change; delete: {status}; else null
 *   etagBefore  the row's etag before the change, when known
 *   etagAfter   the resource's etag Google answered the change with; null
 *               after a delete, which answers nothing
 *   local       delete: the row's own tags, people and reminders, put back
 *               on the row the undo brings back
 *   responseStatus, attendance  answer: my answer before, and my Planned or
 *               Maybe before (an answer resets it)
 *
 * Undoing sends the inverse through GoogleWriter, conditional on etagAfter:
 * any change to the event at Google since changed its etag, so Google
 * answers 412 and nothing is written (the undo is refused). A delete is
 * undone by a PATCH of the same id back to its status (probe 6.2: a deleted
 * event stays readable under its id), conditional on the etag of the
 * deleted resource as read just before. The undo is recorded as an entry
 * of the same shape, so it can be undone back.
 *
 * A change made by a whole-series plan (SeriesPlans) is not one resource
 * and keeps a log-only entry; its undo comes later, built on undo() here.
 */
final class GoogleUndo
{
    public const EDIT = 'edit';
    public const DELETE = 'delete';
    public const CREATE = 'create';
    public const ANSWER = 'answer';

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
        return is_array($g) && in_array($g['kind'] ?? null, [self::EDIT, self::DELETE, self::CREATE, self::ANSWER], true)
            && is_string($g['googleId'] ?? null) && $g['googleId'] !== '' && isset($g['calendarId']);
    }

    /** A row's Google fields as GoogleWriter sends them: an occurrence's without the series' recurrence. Pure. */
    public static function bodyOf(array $row): array
    {
        return ($row['recurrence_instance_utc'] ?? null) !== null ? GoogleWriter::instanceBody($row) : GoogleWriter::body($row);
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

    /** What a row has of its own that a delete at Google takes with it: tags, people, reminders. */
    public function localOf(array $row): array
    {
        $id = (int) $row['id'];
        return [
            'tags' => array_map('intval', array_column($this->db->all('SELECT tag_id FROM event_tags WHERE event_id = ? ORDER BY tag_id', [$id]), 'tag_id')),
            'people' => array_map('intval', array_column($this->db->all('SELECT person_id FROM event_people WHERE event_id = ? ORDER BY person_id', [$id]), 'person_id')),
            'reminders' => $row['reminders_json'] ?? null,
        ];
    }

    /**
     * Record an undoable entry for a write to one Google resource, inside
     * Undo::write. $touches defaults to the event whole, which an older
     * undo of it (its tags, say) stands on.
     *
     * @param list<array{0:string,1:string}>|null $touches
     */
    public function record(int $userId, int $eventId, string $summary, array $google, ?array $touches = null, string $op = 'update'): int
    {
        return $this->undo->record($userId, 'event', $eventId, $op, [], [], $summary, ['google' => $google], [(int) $google['calendarId']],
            $touches ?? ($eventId > 0 ? [[Undo::keyOf('events', ['id' => $eventId]), '*']] : []));
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
                if ($row === null || !is_array($g['before'] ?? null)) {
                    throw HttpError::badRequest('That event is no longer here to change back', 'not_undoable');
                }
                $now = self::bodyOf($row);
                $resp = self::refusingConflict(fn() => $writer->patch($calendar, $googleId, $g['before'], $beforeSend, $g['etagAfter'] ?? null));
                $this->commit($mutation, $calendar, $authority, function () use ($writer, $calendar, $resp, $googleId, $now, $row, $userId, $summary, $mutation): void {
                    $writer->apply($calendar, [$resp]);
                    $this->record($userId, $this->rowId($calendar, $googleId, (int) $mutation['entity_id']), $summary,
                        self::details(self::EDIT, $calendar, $googleId, $now, $row['google_etag'] ?? null, self::etagOf($resp)));
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
                $resp = self::refusingConflict(fn() => $writer->patch($calendar, $googleId, ['status' => $status], $beforeSend, self::etagOf($current) ?? ''));
                $local = is_array($g['local'] ?? null) ? $g['local'] : [];
                $this->commit($mutation, $calendar, $authority, function () use ($writer, $calendar, $resp, $googleId, $userId, $summary, $mutation, $local): void {
                    $writer->apply($calendar, [$resp]);
                    $back = $writer->rowByGoogleId((int) $calendar['id'], $googleId);
                    if ($back !== null) {
                        $this->restoreLocal($userId, (int) $back['id'], $local);
                    }
                    $this->record($userId, $back !== null ? (int) $back['id'] : (int) $mutation['entity_id'], $summary,
                        self::details(self::CREATE, $calendar, $googleId, null, null, self::etagOf($resp)));
                });
                return ['entity' => 'event', 'op' => 'update'];

            case self::CREATE:
                if ($row === null) {
                    throw HttpError::badRequest('That event is no longer here to remove', 'not_undoable');
                }
                $local = $this->localOf($row);
                self::refusingConflict(fn() => $writer->delete($calendar, $googleId, $beforeSend, $g['etagAfter'] ?? null));
                $this->commit($mutation, $calendar, $authority, function () use ($writer, $calendar, $row, $googleId, $userId, $summary, $local, $g): void {
                    $writer->apply($calendar, [], [GoogleWriter::tombstone((string) $row['uid'], $row['recurrence_instance_utc'] !== null ? (string) $row['recurrence_instance_utc'] : null)]);
                    $this->record($userId, (int) $row['id'], $summary,
                        self::details(self::DELETE, $calendar, $googleId, ['status' => self::bodyOf($row)['status']], $g['etagAfter'] ?? null, null, ['local' => $local]));
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
                $resp = self::refusingConflict(fn() => $writer->patch($calendar, $googleId, ['attendees' => array_values($attendees)], $beforeSend, $g['etagAfter'] ?? null));
                $this->commit($mutation, $calendar, $authority, function () use ($writer, $calendar, $resp, $row, $googleId, $userId, $summary, $was, $current, $g): void {
                    $attendance = $row['attendance'] ?? null;
                    $writer->apply($calendar, [$resp]);
                    if (is_string($g['attendance'] ?? null)) {
                        $this->db->run('UPDATE events SET attendance = ? WHERE id = ?', [$g['attendance'], (int) $row['id']]);
                    }
                    $this->record($userId, (int) $row['id'], $summary,
                        self::details(self::ANSWER, $calendar, $googleId, null, self::etagOf($current), self::etagOf($resp),
                            ['responseStatus' => $was, 'attendance' => is_string($attendance) ? $attendance : null]),
                        array_map(static fn(string $col) => [Undo::keyOf('events', ['id' => (int) $row['id']]), $col], ['invite_json', 'status', 'attendance']));
                });
                return ['entity' => 'event', 'op' => 'update'];
        }
        throw HttpError::badRequest('This entry has nothing to send back to Google', 'not_undoable');
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

    /** The row Google knows by $googleId here, or $fallback. */
    private function rowId(array $calendar, string $googleId, int $fallback): int
    {
        $row = $this->writer()->rowByGoogleId((int) $calendar['id'], $googleId);
        return $row !== null ? (int) $row['id'] : $fallback;
    }

    /** Tags, people and reminders a delete took, back on the row the undo brought back (those still in the account). */
    private function restoreLocal(int $userId, int $eventId, array $local): void
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
    }

    private static function etagOf(array $resource): ?string
    {
        return isset($resource['etag']) && is_string($resource['etag']) && $resource['etag'] !== '' ? $resource['etag'] : null;
    }

    /** Changed at Google since: the stale-undo words and the conflict's sentence. */
    private static function conflict(): HttpError
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
