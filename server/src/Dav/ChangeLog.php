<?php

declare(strict_types=1);

namespace BetterCal\Dav;

use BetterCal\Infra\Db;

/**
 * CalDAV change journal. Every event mutation (DAV, API, or feed poll) bumps
 * the calendar's synctoken and appends a dav_changes row so sync-collection
 * clients (iOS/macOS/Android) pick up deltas cheaply.
 *
 * Following sabre's PDO backend semantics: the change row carries the token
 * value *before* the bump, and getChanges selects synctoken >= client-token.
 */
final class ChangeLog
{
    public const OP_ADD = 1;
    public const OP_MODIFY = 2;
    public const OP_DELETE = 3;

    public static function record(Db $db, int $calendarId, string $uid, int $op): void
    {
        try {
            $db->tx(function () use ($db, $calendarId, $uid, $op): void {
                $token = $db->scalar('SELECT synctoken FROM calendars WHERE id = ? FOR UPDATE', [$calendarId]);
                if ($token === null) {
                    return; // calendar gone (cascade delete); nothing to journal
                }
                $db->insert('dav_changes', [
                    'calendar_id' => $calendarId,
                    'uri' => DavIcs::objectUri($uid),
                    'operation' => $op,
                    'synctoken' => (int) $token,
                ]);
                $db->run('UPDATE calendars SET synctoken = synctoken + 1 WHERE id = ?', [$calendarId]);
            });
        } catch (\PDOException $e) {
            // Never let journaling break the mutation itself (e.g. migration
            // 003 not applied yet during a rolling deploy).
            error_log('dav changelog failed for calendar ' . $calendarId . ': ' . $e->getMessage());
        }
    }

    /**
     * Journal an event update given before/after rows; a calendar move is a
     * delete on the old calendar plus an add on the new one.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public static function recordUpdate(Db $db, array $before, array $after): void
    {
        $beforeCal = (int) $before['calendar_id'];
        $afterCal = (int) $after['calendar_id'];
        if ($beforeCal !== $afterCal) {
            self::record($db, $beforeCal, (string) $before['uid'], self::OP_DELETE);
            self::record($db, $afterCal, (string) $after['uid'], self::OP_ADD);
            return;
        }
        self::record($db, $afterCal, (string) $after['uid'], self::OP_MODIFY);
    }
}
