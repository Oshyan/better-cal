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
        if ($latest !== null && in_array($latest['status'], ['queued', 'running'], true)) {
            return $this->serialize($latest);
        }
        $retry = $latest !== null && $latest['status'] === 'failed' && ($latest['cancelled_at'] ?? null) === null
            && (int) $latest['google_account_id'] === $accountId
            && ($googleCalendarId === null || $googleCalendarId === $latest['google_calendar_id']);
        if (!$retry) {
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
            $account = $this->auth->assertUsable($account, true);
            $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]);
            if ($calendar === null) {
                throw HttpError::notFound('No such calendar');
            }
            if ((string) $calendar['kind'] !== 'local') {
                throw HttpError::badRequest('Only a calendar of your own here can be moved to Google');
            }
            $latest = $this->latest($calendarId);
            if ($latest !== null && in_array($latest['status'], ['queued', 'running'], true)) {
                return [(int) $latest['id'], false];
            }
            if ($latest !== null && $latest['status'] === 'failed' && ($latest['cancelled_at'] ?? null) === null
                && (int) $latest['google_account_id'] === $accountId
                && ($googleCalendarId === null || $googleCalendarId === $latest['google_calendar_id'])) {
                // Continue: what was uploaded stays uploaded.
                $this->db->update('calendar_moves', ['status' => 'queued', 'error' => null, 'finished_at' => null], 'id = ?', [(int) $latest['id']]);
                return [(int) $latest['id'], true];
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
                'total' => $total,
            ]);
            return [$moveId, true];
        });
        if ($started) {
            $this->queue->enqueue('google_move', ['moveId' => $moveId]);
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

    public function status(int $userId, int $calendarId): ?array
    {
        $row = $this->latest($calendarId);
        return $row !== null && (int) $row['user_id'] === $userId ? $this->serialize($row) : null;
    }

    /**
     * Work on a move for up to $budgetSeconds. Returns true when it is over
     * (done or failed), false when there is more to upload.
     */
    public function run(int $moveId, int $budgetSeconds): bool
    {
        $move = $this->db->one('SELECT * FROM calendar_moves WHERE id = ?', [$moveId]);
        if ($move === null || in_array($move['status'], ['done', 'failed'], true)) {
            return true;
        }
        $started = time();
        $this->db->run(
            "UPDATE calendar_moves SET status = 'running'
             WHERE id = ? AND status IN ('queued', 'running') AND cancelled_at IS NULL",
            [$moveId]
        );
        $move = $this->db->one('SELECT * FROM calendar_moves WHERE id = ?', [$moveId]);
        if ($move === null || ($move['cancelled_at'] ?? null) !== null || in_array($move['status'], ['done', 'failed'], true)) {
            return true;
        }
        $calendarId = (int) $move['calendar_id'];
        try {
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
                if ($this->cancelled($moveId)) {
                    return true;
                }
                $googleCalendarId = $this->auth->createCalendar($account, (string) $calendar['name'], $this->homeTz((int) $move['user_id']));
                $this->db->update('calendar_moves', ['google_calendar_id' => $googleCalendarId], 'id = ?', [$moveId]);
            }
            $target = ['google_account_id' => (int) $account['id'], 'google_calendar_id' => $googleCalendarId];

            // Whole events and series first, then the changed occurrences of
            // series, which are addressed through their series' Google id.
            foreach ($this->pending($calendarId, masters: true) as $row) {
                if (time() - $started >= $budgetSeconds) {
                    return false;
                }
                if ($this->cancelled($moveId)) {
                    return true;
                }
                $res = $this->writer->import($target, $row);
                $this->uploaded($moveId, (int) $row['id'], (string) ($res['id'] ?? ''));
            }
            foreach ($this->pending($calendarId, masters: false) as $row) {
                if (time() - $started >= $budgetSeconds) {
                    return false;
                }
                if ($this->cancelled($moveId)) {
                    return true;
                }
                $master = $row['recurrence_parent_id'] !== null
                    ? $this->db->one('SELECT * FROM events WHERE id = ?', [(int) $row['recurrence_parent_id']])
                    : $this->db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [$calendarId, (string) $row['uid']]);
                if ($master === null || empty($master['google_event_id'])) {
                    throw new \RuntimeException('A changed occurrence of "' . (string) $row['title'] . '" has no series to belong to');
                }
                $instanceId = GoogleWriter::instanceId((string) $master['google_event_id'], (string) $row['recurrence_instance_utc'], (int) $master['all_day'] === 1, (string) $master['tzid']);
                $res = $this->writer->patch($target, $instanceId, GoogleWriter::instanceBody($row));
                $this->uploaded($moveId, (int) $row['id'], (string) ($res['id'] ?? $instanceId));
            }

            // Everything is at Google: the calendar becomes a Google-backed
            // one, and the first sync matches every row by its UID.
            if ($this->cancelled($moveId)) {
                return true;
            }
            $this->db->tx(function () use ($moveId, $calendarId, $account, $googleCalendarId): void {
                // Claim completion with the same row the reset cancels. The
                // conditional UPDATE is the race boundary: whichever commits
                // first wins, so reset cannot land between the last check and
                // the local cutover and then be overwritten by status='done'.
                $finished = $this->db->run(
                    "UPDATE calendar_moves SET status = 'done', finished_at = ?
                     WHERE id = ? AND status = 'running' AND cancelled_at IS NULL",
                    [Time::nowDb(), $moveId]
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
                    'last_poll_status' => 'never',
                    'last_poll_error' => null,
                ], 'id = ?', [$calendarId]);
                $this->db->run("UPDATE events SET source = 'feed' WHERE calendar_id = ?", [$calendarId]);
                // Earlier changes to it stay in Activity but can no longer be
                // undone: undoing one now would change the row here and never
                // Google, which would then overwrite it on the next poll.
                $this->db->run(
                    "UPDATE mutations SET before_json = NULL, after_json = NULL
                     WHERE (entity = 'event' AND entity_id IN (SELECT id FROM events WHERE calendar_id = ?))
                        OR (entity = 'calendar' AND entity_id = ?)",
                    [$calendarId, $calendarId]
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
            return true;
        } catch (\Throwable $e) {
            $this->db->update('calendar_moves', [
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => Time::nowDb(),
            ], 'id = ?', [$moveId]);
            return true;
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

    private function uploaded(int $moveId, int $eventId, string $googleId): void
    {
        if ($googleId === '') {
            throw new \RuntimeException('Google accepted an event but returned no id for it');
        }
        $this->db->update('events', ['google_event_id' => $googleId], 'id = ?', [$eventId]);
        $this->db->run('UPDATE calendar_moves SET done_count = done_count + 1 WHERE id = ?', [$moveId]);
    }

    /** A compromise reset can land while this worker is between two uploads. */
    private function cancelled(int $moveId): bool
    {
        $row = $this->db->one('SELECT cancelled_at FROM calendar_moves WHERE id = ?', [$moveId]);
        return $row === null || ($row['cancelled_at'] ?? null) !== null;
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
            'status' => (string) $row['status'],
            'cancelled' => ($row['cancelled_at'] ?? null) !== null,
            'total' => (int) $row['total'],
            'done' => (int) $row['done_count'],
            'error' => $row['error'] !== null ? (string) $row['error'] : null,
            'createdNew' => (int) $row['create_new'] === 1,
            'googleCalendarId' => $row['google_calendar_id'] !== null ? (string) $row['google_calendar_id'] : null,
        ];
    }
}
