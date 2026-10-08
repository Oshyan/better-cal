<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

/**
 * The database boundary for a local calendar while it is moving to Google.
 *
 * Every snapshot-changing writer locks the calendar row before checking the
 * latest move. GoogleMove::start() takes the same lock before creating a move,
 * so either the write commits first and is included, or the move commits first
 * and the write receives calendar_moving. A retryable failure stays frozen
 * until it is retried or explicitly abandoned: some rows may already exist at
 * Google and the resume algorithm deliberately skips those uploaded rows.
 */
final class CalendarMoveGuard
{
    /** @param list<int> $calendarIds @return array<int,array<string,mixed>> */
    public static function lockCalendars(Db $db, array $calendarIds): array
    {
        $calendarIds = array_values(array_unique(array_filter(array_map('intval', $calendarIds), static fn(int $id): bool => $id > 0)));
        sort($calendarIds, SORT_NUMERIC);
        $rows = [];
        foreach ($calendarIds as $calendarId) {
            $row = self::lockCalendar($db, $calendarId);
            if ($row !== null) {
                $rows[$calendarId] = $row;
            }
        }
        return $rows;
    }

    /** @param list<int> $calendarIds */
    public static function lockAndAssertMutable(Db $db, array $calendarIds): void
    {
        foreach (array_keys(self::lockCalendars($db, $calendarIds)) as $calendarId) {
            self::assertMutableLocked($db, $calendarId);
        }
    }

    public static function lockCalendar(Db $db, int $calendarId): ?array
    {
        $lock = $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        try {
            return $db->one('SELECT * FROM calendars WHERE id = ?' . $lock, [$calendarId]);
        } catch (\PDOException $e) {
            $message = strtolower($e->getMessage());
            if ((string) $e->getCode() === '42S02' || str_contains($message, 'no such table: calendars')) {
                return null; // narrow unit-test/rolling-install compatibility
            }
            throw $e;
        }
    }

    /** The caller must already hold the calendar-row lock when using MySQL. */
    public static function assertMutableLocked(Db $db, int $calendarId): void
    {
        try {
            // Under MySQL's default REPEATABLE READ, a plain SELECT could use
            // a snapshot established before the calendar-row lock was
            // acquired. A locking read is current, and keeps the universal
            // calendar -> move lock order used by starts, writers and reset.
            $lock = $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $move = $db->one(
                "SELECT status, cancelled_at FROM calendar_moves
                 WHERE calendar_id = ? AND status IN ('queued', 'running') AND cancelled_at IS NULL
                 ORDER BY id DESC LIMIT 1" . $lock,
                [$calendarId]
            );
            $move ??= $db->one(
                'SELECT status, cancelled_at FROM calendar_moves WHERE calendar_id = ? ORDER BY id DESC LIMIT 1' . $lock,
                [$calendarId]
            );
        } catch (\PDOException $e) {
            // Rolling upgrade and small unit-test schemas can briefly lack the
            // moves table. Other database errors are real and must not turn a
            // security boundary into fail-open behavior.
            $message = strtolower($e->getMessage());
            if ((string) $e->getCode() === '42S02' || str_contains($message, 'no such table: calendar_moves')) {
                return;
            }
            throw $e;
        }
        if ($move === null || ($move['cancelled_at'] ?? null) !== null) {
            return;
        }
        $status = (string) ($move['status'] ?? '');
        if (!in_array($status, ['queued', 'running', 'failed'], true)) {
            return;
        }
        $message = $status === 'failed'
            ? 'This move to Google stopped before it finished. Retry it or stop the move before editing this calendar.'
            : 'This calendar is being moved to Google; it takes edits again once the upload finishes.';
        throw new HttpError('calendar_moving', $message, 409);
    }
}
