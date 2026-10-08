<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;
use BetterCal\Support\Time;

/**
 * Moving a local calendar to Google (0.9.4, #55), so people who use Google
 * Calendar can see it live and, if it's shared with them that way, add to it.
 *
 * Better-Cal creates a calendar in the connected account (or uses one the
 * person picks), uploads every event with Google's import call (which keeps
 * our iCalendar UID and sends no mail), then turns the calendar into a
 * Google-backed one: from then on it is edited in place like any Google
 * calendar here (GoogleWriter), and changes made at Google arrive by polling.
 * Google holds the one copy, so there is nothing to reconcile.
 *
 * The flip happens only once every event is at Google: the first sync after
 * it removes rows Google doesn't have, so a half-uploaded calendar must never
 * be flipped. The upload runs as a job (a big calendar takes minutes) and
 * picks up where it stopped after an interruption or a failure. Rows keep
 * their ids, so tags, people, reminders and trip membership stay with them.
 *
 * What is given up: Undo on that calendar (a write to Google can't be taken
 * back here), as for any Google calendar. Adopt as local reverses the move.
 */
final class GoogleMove
{
    public const RESULT_DONE = 'done';
    public const RESULT_MORE = 'more';
    public const RESULT_BUSY = 'busy';
    private const LEASE_SECONDS = 90;
    private const REMOTE_MARKER_PREFIX = 'Better-Cal move recovery: ';

    public function __construct(
        private readonly Db $db,
        private readonly GoogleAuth $auth,
        private readonly GoogleWriter $writer,
        private readonly Feeds $feeds,
        private readonly Undo $undo,
        private readonly JobQueue $queue,
    ) {
    }

    /**
     * Start moving a calendar, or continue a move that failed. With
     * $googleCalendarId, use that existing calendar (one the account owns or
     * may edit) instead of creating one.
     */
    public function start(int $userId, int $calendarId, int $accountId, string $sessionToken, ?string $googleCalendarId = null): array
    {
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]);
        if ($calendar === null) {
            throw HttpError::notFound('No such calendar');
        }
        if ((string) $calendar['kind'] !== 'local') {
            throw HttpError::badRequest('Only a calendar of your own here can be moved to Google');
        }
        $account = $this->auth->account($userId, $accountId);
        $account = $this->auth->assertUsable($account);

        $latest = $this->latest($calendarId);
        $retry = $latest !== null && $latest['status'] === 'failed' && ($latest['cancelled_at'] ?? null) === null
            && (int) $latest['google_account_id'] === $accountId
            && ($googleCalendarId === null || $googleCalendarId === $latest['google_calendar_id']);
        $alreadyActive = $latest !== null && in_array($latest['status'], ['queued', 'running'], true);
        if (!$retry && !$alreadyActive) {
            if ($googleCalendarId === null && !GoogleAuth::canCreateCalendars($account)) {
                throw new HttpError('google_reconnect_needed', 'Reconnect your Google account once to let Better-Cal create calendars there, or pick a Google calendar you already have.', 409);
            }
            if ($googleCalendarId !== null) {
                $this->checkTarget($userId, $account, $googleCalendarId);
            }
        }

        [$moveId, $started] = $this->db->tx(function () use ($userId, $calendarId, $accountId, $googleCalendarId, $account, $sessionToken): array {
            // Serialize durable move creation/retry with the exact initiating
            // session and quarantine. A reset/reconnect cannot revive the old
            // request; if this commits first, reset cancels the new move.
            Auth::assertRecentSession($this->db, $userId, $sessionToken, true);
            $calendar = CalendarMoveGuard::lockCalendar($this->db, $calendarId);
            if ($calendar !== null && (int) $calendar['user_id'] !== $userId) {
                $calendar = null;
            }
            if ($calendar === null) {
                throw HttpError::notFound('No such calendar');
            }
            if ((string) $calendar['kind'] !== 'local') {
                throw HttpError::badRequest('Only a calendar of your own here can be moved to Google');
            }
            // Canonical durable-write order is user -> calendar -> account.
            // Google polling, write-through and compromise reset take the
            // same calendar -> account order, preventing an account/calendar
            // deadlock while this move is being started.
            $account = $this->auth->assertUsable($account, true);
            $latest = $this->latest($calendarId);
            if ($latest !== null && in_array($latest['status'], ['queued', 'running'], true)) {
                $this->ensureMoveJob((int) $latest['id']);
                return [(int) $latest['id'], false];
            }
            if ($latest !== null && $latest['status'] === 'failed' && ($latest['cancelled_at'] ?? null) === null
                && (int) $latest['google_account_id'] === $accountId
                && ($googleCalendarId === null || $googleCalendarId === $latest['google_calendar_id'])) {
                // Continue: what was uploaded stays uploaded.
                $this->db->update('calendar_moves', [
                    'status' => 'queued',
                    'error' => null,
                    'finished_at' => null,
                    'runner_token' => null,
                    'lease_expires_at' => null,
                ], 'id = ?', [(int) $latest['id']]);
                $this->ensureMoveJob((int) $latest['id']);
                return [(int) $latest['id'], true];
            }
            if ($latest !== null && $latest['status'] === 'failed' && ($latest['cancelled_at'] ?? null) === null) {
                throw HttpError::conflict(
                    'google_move_unfinished',
                    'Retry or stop the unfinished Google move before starting a different one.'
                );
            }
            if ($googleCalendarId !== null) {
                $this->assertTargetAvailable($userId, $accountId, $calendarId, $googleCalendarId);
            }
            if ($googleCalendarId === null && !GoogleAuth::canCreateCalendars($account)) {
                throw new HttpError('google_reconnect_needed', 'Reconnect your Google account once to let Better-Cal create calendars there, or pick a Google calendar you already have.', 409);
            }
            // Events may still carry Google ids from an earlier life on
            // Google (a calendar adopted as local keeps them as history);
            // this move starts from none.
            $this->db->run('UPDATE events SET google_event_id = NULL WHERE calendar_id = ?', [$calendarId]);
            $total = (int) $this->db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$calendarId]);
            $moveId = $this->db->insert('calendar_moves', [
                'user_id' => $userId,
                'calendar_id' => $calendarId,
                'google_account_id' => $accountId,
                'google_calendar_id' => $googleCalendarId,
                'create_new' => $googleCalendarId === null ? 1 : 0,
                'remote_marker' => bin2hex(random_bytes(16)),
                'remote_reconciled_at' => $googleCalendarId === null ? null : Time::nowDb(),
                'total' => $total,
            ]);
            // The move row and its recovery job commit together. There is no
            // crash window in which the calendar is frozen by a queued move
            // that no worker can ever discover.
            $this->ensureMoveJob($moveId);
            return [$moveId, true];
        });
        if ($started) {
            // Most calendars finish within the request; a big one carries on in
            // the worker and the settings panel shows its progress.
            $this->run($moveId, 12);
        }
        $row = $this->db->one('SELECT * FROM calendar_moves WHERE id = ?', [$moveId]);
        if ($row === null) {
            throw HttpError::conflict('google_disconnected', 'The Google account was disconnected before the move could start.');
        }
        return $this->serialize($row);
    }

    /** Stop a failed move so local editing can resume. Remote partial data is left for the owner to review at Google. */
    public function abandon(int $userId, int $calendarId): array
    {
        $row = $this->db->tx(function () use ($userId, $calendarId): array {
            $calendar = CalendarMoveGuard::lockCalendar($this->db, $calendarId);
            if ($calendar === null || (int) $calendar['user_id'] !== $userId) {
                throw HttpError::notFound('No such calendar');
            }
            $move = $this->latest($calendarId);
            if ($move === null || (int) $move['user_id'] !== $userId) {
                throw HttpError::notFound('No Google move to stop');
            }
            if ((string) $move['status'] !== 'failed' || ($move['cancelled_at'] ?? null) !== null) {
                throw HttpError::conflict('google_move_not_stoppable', 'Only a stopped, retryable Google move can be abandoned.');
            }
            $now = Time::nowDb();
            $this->db->update('calendar_moves', [
                'cancelled_at' => $now,
                'finished_at' => $now,
                'runner_token' => null,
                'lease_expires_at' => null,
                'error' => 'Stopped by the owner',
            ], 'id = ?', [(int) $move['id']]);
            // These ids identify partial remote copies and must not be reused
            // by a later, separate move.
            $this->db->run('UPDATE events SET google_event_id = NULL WHERE calendar_id = ?', [$calendarId]);
            return $this->db->one('SELECT * FROM calendar_moves WHERE id = ?', [(int) $move['id']]) ?? $move;
        });
        return self::serialize($row);
    }

    public function status(int $userId, int $calendarId): ?array
    {
        $row = $this->latest($calendarId);
        return $row !== null && (int) $row['user_id'] === $userId ? $this->serialize($row) : null;
    }

    /** Work on a move under an exclusive, crash-recoverable lease. */
    public function run(int $moveId, int $budgetSeconds): string
    {
        $move = $this->db->one('SELECT * FROM calendar_moves WHERE id = ?', [$moveId]);
        if ($move === null || ($move['cancelled_at'] ?? null) !== null || !in_array((string) $move['status'], ['queued', 'running'], true)) {
            return self::RESULT_DONE;
        }
        $claimed = $this->claim($moveId);
        if ($claimed === null) {
            $fresh = $this->db->one('SELECT status, cancelled_at FROM calendar_moves WHERE id = ?', [$moveId]);
            return $fresh !== null && ($fresh['cancelled_at'] ?? null) === null
                && in_array((string) $fresh['status'], ['queued', 'running'], true)
                ? self::RESULT_BUSY
                : self::RESULT_DONE;
        }
        $move = $claimed['move'];
        $token = $claimed['token'];
        $started = time();
        $calendarId = (int) $move['calendar_id'];
        try {
            if (trim((string) ($move['remote_marker'] ?? '')) === '') {
                // New starts always persist this before they can be queued.
                // A missing marker therefore identifies pre-integrity state
                // whose remote outcome is unknowable; never invent one and
                // accidentally resume it during a rolling migration.
                throw new \RuntimeException('This unfinished move predates safe recovery and must be stopped before a new move is started.');
            }
            $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]);
            if ($calendar === null || (string) $calendar['kind'] !== 'local') {
                throw new \RuntimeException('The calendar is no longer a local calendar');
            }
            $account = $this->db->one('SELECT * FROM google_accounts WHERE id = ?', [(int) $move['google_account_id']]);
            if ($account === null) {
                throw new \RuntimeException('The Google account was disconnected');
            }
            $googleCalendarId = $move['google_calendar_id'] !== null ? (string) $move['google_calendar_id'] : null;
            if ($googleCalendarId === null) {
                $googleCalendarId = $this->createOrRecoverCalendar($move, $token, $account, $calendar);
                $move['google_calendar_id'] = $googleCalendarId;
            }
            if ((int) $move['create_new'] === 1 && ($move['remote_reconciled_at'] ?? null) === null) {
                $this->requireLease($moveId, $token);
                $this->auth->updateCalendarDescription($account, $googleCalendarId, '');
                $this->updateOwnedMove($moveId, $token, ['remote_reconciled_at' => Time::nowDb()]);
            }
            $target = ['google_account_id' => (int) $account['id'], 'google_calendar_id' => $googleCalendarId];

            // Exactly one import can have an unknown local outcome after a
            // process or network failure. Reconcile its private opaque marker
            // before considering any new remote write.
            $this->recoverInterruptedImport($move, $token, $target);

            // Whole events and series first, then the changed occurrences of
            // series, which are addressed through their series' Google id.
            foreach ($this->pending($calendarId, masters: true) as $row) {
                if (time() - $started >= $budgetSeconds) {
                    return $this->continueLater($moveId, $token);
                }
                $this->requireLease($moveId, $token);
                $marker = self::eventRecoveryMarker((string) $move['remote_marker'], (int) $row['id']);
                $this->beginImport($moveId, $token, (int) $row['id'], $marker);
                $res = $this->writer->import($target, $row, $marker);
                $this->uploaded($moveId, $token, (int) $row['id'], (string) ($res['id'] ?? ''), true);
            }
            foreach ($this->pending($calendarId, masters: false) as $row) {
                if (time() - $started >= $budgetSeconds) {
                    return $this->continueLater($moveId, $token);
                }
                $this->requireLease($moveId, $token);
                $master = $row['recurrence_parent_id'] !== null
                    ? $this->db->one('SELECT * FROM events WHERE id = ?', [(int) $row['recurrence_parent_id']])
                    : $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [$calendarId, (string) $row['uid']]);
                if ($master === null || empty($master['google_event_id'])) {
                    throw new \RuntimeException('A changed occurrence of "' . (string) $row['title'] . '" has no series to belong to');
                }
                $instanceId = GoogleWriter::instanceId((string) $master['google_event_id'], (string) $row['recurrence_instance_utc'], (int) $master['all_day'] === 1, (string) $master['tzid']);
                $res = $this->writer->patch($target, $instanceId, GoogleWriter::instanceBody($row));
                $this->uploaded($moveId, $token, (int) $row['id'], (string) ($res['id'] ?? $instanceId));
            }

            // Everything is at Google: the calendar becomes a Google-backed
            // one, and the first sync matches every row by its UID.
            $this->db->tx(function () use ($moveId, $token, $calendarId, $account, $googleCalendarId): void {
                $calendarAtCutover = CalendarMoveGuard::lockCalendar($this->db, $calendarId);
                if ($calendarAtCutover === null) {
                    throw new \RuntimeException('The calendar disappeared before move completion');
                }
                // Claim completion with the same row the reset cancels. The
                // conditional UPDATE is the race boundary: whichever commits
                // first wins, so reset cannot land between the last check and
                // the local cutover and then be overwritten by status='done'.
                $finished = $this->db->run(
                    "UPDATE calendar_moves SET status = 'done', finished_at = ?, runner_token = NULL, lease_expires_at = NULL
                     WHERE id = ? AND status = 'running' AND runner_token = ? AND cancelled_at IS NULL",
                    [Time::nowDb(), $moveId, $token]
                )->rowCount();
                if ($finished !== 1) {
                    throw new \RuntimeException('The move was stopped by the account compromise reset');
                }
                $this->db->update('calendars', [
                    'kind' => 'subscribed',
                    'provider' => 'google',
                    'source_url' => null,
                    'google_account_id' => (int) $account['id'],
                    'google_calendar_id' => $googleCalendarId,
                    'google_access_role' => 'owner',
                    'google_sync_token' => null,
                    'google_binding_version' => (int) ($calendarAtCutover['google_binding_version'] ?? 0) + 1,
                    // Polled as often as any other Google calendar, not on
                    // the hourly schedule it had as a local one.
                    'poll_interval_minutes' => 5,
                    'last_poll_status' => 'never',
                    'last_poll_error' => null,
                ], 'id = ?', [$calendarId]);
                $this->db->run("UPDATE events SET source = 'feed' WHERE calendar_id = ?", [$calendarId]);
                // Earlier changes to it stay in Activity but can no longer be
                // undone: undoing one now would change the row here and never
                // Google, which would then overwrite it on the next poll.
                $this->db->run(
                    "UPDATE mutations SET before_json = NULL, after_json = NULL
                     WHERE id IN (SELECT mutation_id FROM mutation_calendar_refs WHERE calendar_id = ?)
                        OR (entity = 'event' AND entity_id IN (SELECT id FROM events WHERE calendar_id = ?))
                        OR (entity = 'calendar' AND entity_id = ?)",
                    [$calendarId, $calendarId, $calendarId]
                );
            });
            $count = (int) $this->db->scalar('SELECT done_count FROM calendar_moves WHERE id = ?', [$moveId]);
            $this->undo->record((int) $move['user_id'], 'calendar', $calendarId, 'update', null, null,
                "Moved calendar '" . (string) $calendar['name'] . "' to Google (" . $count . ' event' . ($count === 1 ? '' : 's') . ')');
            try {
                $this->feeds->poll($calendarId);
            } catch (\Throwable $e) {
                error_log('google move: first sync of calendar ' . $calendarId . ' failed: ' . $e->getMessage());
            }
            return self::RESULT_DONE;
        } catch (\Throwable $e) {
            // Only the current owner may fail the move. A stale executor can
            // neither overwrite a newer owner nor turn a completed move back
            // into failed.
            $this->db->run(
                "UPDATE calendar_moves
                 SET status = 'failed', error = ?, finished_at = ?, runner_token = NULL, lease_expires_at = NULL
                 WHERE id = ? AND status = 'running' AND runner_token = ? AND cancelled_at IS NULL",
                [mb_substr($e->getMessage(), 0, 2000), Time::nowDb(), $moveId, $token]
            );
            return self::RESULT_DONE;
        }
    }

    // ---- internals ----------------------------------------------------------

    /** @return list<array> events still to upload, oldest first */
    private function pending(int $calendarId, bool $masters): array
    {
        return $this->db->all(
            'SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL AND google_event_id IS NULL AND recurrence_instance_utc IS '
            . ($masters ? 'NULL' : 'NOT NULL') . ' ORDER BY id',
            [$calendarId]
        );
    }

    private function uploaded(int $moveId, string $token, int $eventId, string $googleId, bool $trackedImport = false): void
    {
        if ($googleId === '') {
            throw new \RuntimeException('Google accepted an event but returned no id for it');
        }
        $this->db->tx(function () use ($moveId, $token, $eventId, $googleId, $trackedImport): void {
            $this->requireLease($moveId, $token);
            $updated = $this->db->update('events', ['google_event_id' => $googleId], 'id = ? AND google_event_id IS NULL', [$eventId]);
            if ($updated !== 1) {
                throw new \RuntimeException('The imported event changed before its Google id could be saved');
            }
            $progressed = $trackedImport
                ? $this->db->run(
                    'UPDATE calendar_moves
                     SET done_count = done_count + 1, current_event_id = NULL, current_event_marker = NULL
                     WHERE id = ? AND runner_token = ? AND current_event_id = ?',
                    [$moveId, $token, $eventId]
                )->rowCount()
                : $this->db->run(
                    'UPDATE calendar_moves SET done_count = done_count + 1 WHERE id = ? AND runner_token = ?',
                    [$moveId, $token]
                )->rowCount();
            if ($progressed !== 1) {
                throw new \RuntimeException('The Google move executor lease was lost');
            }
        });
    }

    private function beginImport(int $moveId, string $token, int $eventId, string $marker): void
    {
        $started = $this->db->run(
            "UPDATE calendar_moves SET current_event_id = ?, current_event_marker = ?
             WHERE id = ? AND status = 'running' AND runner_token = ? AND cancelled_at IS NULL
               AND current_event_id IS NULL",
            [$eventId, $marker, $moveId, $token]
        )->rowCount();
        if ($started !== 1) {
            throw new \RuntimeException('A previous Google event import must be reconciled before another can start');
        }
    }

    private function recoverInterruptedImport(array $move, string $token, array $target): void
    {
        $eventId = isset($move['current_event_id']) ? (int) $move['current_event_id'] : 0;
        if ($eventId <= 0) {
            return;
        }
        $event = $this->db->one(
            'SELECT * FROM events WHERE id = ? AND calendar_id = ? AND deleted_at IS NULL',
            [$eventId, (int) $move['calendar_id']]
        );
        if ($event === null || $event['recurrence_instance_utc'] !== null) {
            throw new \RuntimeException('The interrupted Google import no longer matches a movable event');
        }
        if (!empty($event['google_event_id'])) {
            $this->updateOwnedMove((int) $move['id'], $token, [
                'current_event_id' => null,
                'current_event_marker' => null,
            ]);
            return;
        }
        $marker = trim((string) ($move['current_event_marker'] ?? ''));
        if ($marker === '') {
            throw new \RuntimeException('The interrupted Google import has no recovery marker; stop this move before editing the calendar.');
        }
        $this->requireLease((int) $move['id'], $token);
        $recovered = $this->writer->findImportedByMarker($target, $marker, (string) $event['uid']);
        $googleId = trim((string) ($recovered['id'] ?? ''));
        if ($googleId === '') {
            // A missing result cannot prove that the earlier import did not
            // commit. Never reissue it and risk a duplicate remote event.
            throw new \RuntimeException('The previous Google event import could not be confirmed; try again later or stop this move and review the partial Google copy.');
        }
        $this->uploaded((int) $move['id'], $token, $eventId, $googleId, true);
    }

    public static function eventRecoveryMarker(string $moveMarker, int $eventId): string
    {
        return hash_hmac('sha256', 'event:' . $eventId, $moveMarker);
    }

    /** @return array{move:array,token:string}|null */
    private function claim(int $moveId): ?array
    {
        $candidate = $this->db->one('SELECT calendar_id FROM calendar_moves WHERE id = ?', [$moveId]);
        if ($candidate === null) {
            return null;
        }
        return $this->db->tx(function () use ($moveId, $candidate): ?array {
            CalendarMoveGuard::lockCalendar($this->db, (int) $candidate['calendar_id']);
            $token = bin2hex(random_bytes(32));
            $now = Time::nowDb();
            $expires = Time::toDb(Time::nowUtc()->modify('+' . self::LEASE_SECONDS . ' seconds'));
            $claimed = $this->db->run(
                "UPDATE calendar_moves SET status = 'running', runner_token = ?, lease_expires_at = ?
                 WHERE id = ? AND cancelled_at IS NULL
                   AND (status = 'queued' OR (status = 'running' AND (lease_expires_at IS NULL OR lease_expires_at < ?)))",
                [$token, $expires, $moveId, $now]
            )->rowCount();
            if ($claimed !== 1) {
                return null;
            }
            $move = $this->db->one('SELECT * FROM calendar_moves WHERE id = ?', [$moveId]);
            return $move === null ? null : ['move' => $move, 'token' => $token];
        });
    }

    private function requireLease(int $moveId, string $token): void
    {
        $owned = $this->db->scalar(
            "SELECT id FROM calendar_moves
             WHERE id = ? AND status = 'running' AND runner_token = ? AND cancelled_at IS NULL
               AND lease_expires_at >= ?",
            [$moveId, $token, Time::nowDb()]
        );
        if ($owned === null) {
            throw new \RuntimeException('The Google move executor lease was lost');
        }
        $expires = Time::toDb(Time::nowUtc()->modify('+' . self::LEASE_SECONDS . ' seconds'));
        $this->db->run(
            "UPDATE calendar_moves SET lease_expires_at = ?
             WHERE id = ? AND status = 'running' AND runner_token = ? AND cancelled_at IS NULL",
            [$expires, $moveId, $token]
        );
        $fresh = $this->db->one('SELECT runner_token, lease_expires_at FROM calendar_moves WHERE id = ?', [$moveId]);
        if ($fresh === null || !hash_equals($token, (string) ($fresh['runner_token'] ?? ''))
            || (string) ($fresh['lease_expires_at'] ?? '') < $expires) {
            throw new \RuntimeException('The Google move executor lease was lost');
        }
    }

    private function updateOwnedMove(int $moveId, string $token, array $fields): void
    {
        $updated = $this->db->update('calendar_moves', $fields, 'id = ? AND status = ? AND runner_token = ? AND cancelled_at IS NULL', [$moveId, 'running', $token]);
        if ($updated !== 1) {
            throw new \RuntimeException('The Google move executor lease was lost');
        }
    }

    private function continueLater(int $moveId, string $token): string
    {
        $released = $this->db->run(
            "UPDATE calendar_moves SET status = 'queued', runner_token = NULL, lease_expires_at = NULL
             WHERE id = ? AND status = 'running' AND runner_token = ? AND cancelled_at IS NULL",
            [$moveId, $token]
        )->rowCount();
        return $released === 1 ? self::RESULT_MORE : self::RESULT_DONE;
    }

    private function ensureMoveJob(int $moveId): void
    {
        // A running job may be the old attempt that just failed and is about
        // to mark itself done. Keep a distinct pending recovery job; checking
        // only "pending or running" can strand a retry in that handoff.
        if (!$this->queue->hasQueuedGoogleMove($moveId)) {
            $this->queue->enqueue('google_move', ['moveId' => $moveId]);
        }
    }

    private function createOrRecoverCalendar(array $move, string $token, array $account, array $calendar): string
    {
        $moveId = (int) $move['id'];
        $marker = trim((string) ($move['remote_marker'] ?? ''));
        if ($marker === '') {
            throw new \RuntimeException('This unfinished move predates safe recovery and must be stopped before a new move is started.');
        }
        $description = self::REMOTE_MARKER_PREFIX . $marker;
        $this->requireLease($moveId, $token);
        $recovered = self::recoveredCalendarId($this->auth->listCalendars($account, null, true), $marker);
        if ($recovered !== null) {
            $googleCalendarId = $recovered;
        } else {
            if (($move['remote_create_started_at'] ?? null) !== null) {
                // The prior request may have committed remotely and lost its
                // response. Never guess by creating a second calendar; a later
                // retry can reconcile once Google's list shows the marker.
                throw new \RuntimeException('A previous Google calendar creation could not yet be confirmed; try again later or stop this move.');
            }
            $this->updateOwnedMove($moveId, $token, ['remote_create_started_at' => Time::nowDb()]);
            $this->requireLease($moveId, $token);
            $googleCalendarId = $this->auth->createCalendar(
                $account,
                (string) $calendar['name'],
                $this->homeTz((int) $move['user_id']),
                $description,
            );
        }
        $this->db->tx(function () use ($move, $moveId, $token, $account, $calendar, $googleCalendarId): void {
            // A just-created/recovered id becomes a reserved target under the
            // same account-row lock used by existing-target moves and
            // subscriptions. A concurrently added subscription wins cleanly
            // instead of sharing one remote calendar with this move.
            $this->auth->assertUsable($account, true);
            $this->assertTargetAvailable(
                (int) $move['user_id'],
                (int) $account['id'],
                (int) $calendar['id'],
                $googleCalendarId,
            );
            $this->updateOwnedMove($moveId, $token, ['google_calendar_id' => $googleCalendarId]);
        });
        return $googleCalendarId;
    }

    /** @param list<array{id:string,description?:string}> $entries */
    public static function recoveredCalendarId(array $entries, string $marker): ?string
    {
        $description = self::REMOTE_MARKER_PREFIX . $marker;
        $matches = array_values(array_filter(
            $entries,
            static fn(array $entry): bool => hash_equals($description, (string) ($entry['description'] ?? ''))
        ));
        if (count($matches) > 1) {
            throw new \RuntimeException('Google returned more than one calendar for the move recovery marker');
        }
        return $matches === [] ? null : (string) $matches[0]['id'];
    }

    private function checkTarget(int $userId, array $account, string $googleCalendarId): void
    {
        $entry = null;
        foreach ($this->auth->listCalendars($account) as $c) {
            if ($c['id'] === $googleCalendarId) {
                $entry = $c;
                break;
            }
        }
        if ($entry === null || !in_array($entry['accessRole'], ['owner', 'writer'], true)) {
            throw HttpError::badRequest('That Google calendar is not one this account can edit');
        }
        if ($entry['primary']) {
            // The account's main calendar holds everything else too; moving
            // into it would mix this calendar's events in for good.
            throw HttpError::badRequest('Pick a calendar other than the account\'s main one');
        }
        $taken = $this->db->scalar('SELECT id FROM calendars WHERE user_id = ? AND google_calendar_id = ?', [$userId, $googleCalendarId]);
        if ($taken !== null) {
            throw HttpError::conflict('google_calendar_in_use', 'That Google calendar is already a calendar here; pick another, or let Better-Cal create one');
        }
    }

    /** Account row is locked by the caller, serializing moves with subscriptions. */
    private function assertTargetAvailable(int $userId, int $accountId, int $sourceCalendarId, string $googleCalendarId): void
    {
        $taken = $this->db->scalar(
            'SELECT id FROM calendars WHERE user_id = ? AND google_account_id = ? AND google_calendar_id = ? LIMIT 1',
            [$userId, $accountId, $googleCalendarId]
        );
        $moving = $this->db->scalar(
            "SELECT id FROM calendar_moves
             WHERE google_account_id = ? AND google_calendar_id = ? AND calendar_id <> ?
               AND status IN ('queued', 'running', 'failed') AND cancelled_at IS NULL LIMIT 1",
            [$accountId, $googleCalendarId, $sourceCalendarId]
        );
        if ($taken !== null || $moving !== null) {
            throw HttpError::conflict('google_calendar_in_use', 'That Google calendar is already reserved here; pick another, or let Better-Cal create one');
        }
    }

    private function homeTz(int $userId): string
    {
        $raw = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
        $settings = is_string($raw) ? json_decode($raw, true) : null;
        $tz = is_array($settings) && is_string($settings['tz'] ?? null) && $settings['tz'] !== '' ? $settings['tz'] : 'UTC';
        return Time::normalizeTzid($tz);
    }

    private function latest(int $calendarId): ?array
    {
        return $this->db->one('SELECT * FROM calendar_moves WHERE calendar_id = ? ORDER BY id DESC LIMIT 1', [$calendarId]);
    }

    public static function serialize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'googleAccountId' => (int) ($row['google_account_id'] ?? 0),
            'status' => (string) $row['status'],
            'cancelled' => ($row['cancelled_at'] ?? null) !== null,
            'stoppedByOwner' => ($row['cancelled_at'] ?? null) !== null && (string) ($row['error'] ?? '') === 'Stopped by the owner',
            'stoppedByUpgrade' => ($row['cancelled_at'] ?? null) !== null
                && str_starts_with((string) ($row['error'] ?? ''), 'Stopped by the move-integrity upgrade;'),
            'total' => (int) $row['total'],
            'done' => (int) $row['done_count'],
            'error' => $row['error'] !== null ? (string) $row['error'] : null,
            'createdNew' => (int) $row['create_new'] === 1,
            'googleCalendarId' => $row['google_calendar_id'] !== null ? (string) $row['google_calendar_id'] : null,
        ];
    }
}
