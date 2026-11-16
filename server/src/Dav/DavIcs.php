<?php

declare(strict_types=1);

namespace BetterCal\Dav;

use BetterCal\Domain\Ics;

/**
 * Pure CalDAV mapping helpers: calendar/object URI naming, etag derivation
 * and calendar-object body assembly (a parsed VEVENT's columns are
 * Feeds::columnsOf's, the one mapping for every source).
 * No sabre/dav dependency; fully testable offline.
 */
final class DavIcs
{
    /** DAV collection uri for a calendars row. */
    public static function calendarUri(int $calendarId): string
    {
        return 'cal-' . $calendarId;
    }

    public static function calendarIdFromUri(string $uri): ?int
    {
        if (preg_match('/^cal-([1-9][0-9]*)$/', $uri, $m) !== 1) {
            return null;
        }
        return (int) $m[1];
    }

    /**
     * Calendar-object uri for an event uid; one object per uid (master +
     * overrides). A UID arrives from feeds, imports and email and may hold
     * anything, and a "/" in it split the object's href into two path
     * segments, which failed every sync REPORT for the calendar (scan
     * 2026-09-23, F7). Only what breaks a path is encoded: a "/" or "\\",
     * a bare "." or "..", or a UID that itself starts "b64-". Everything else
     * (spaces, ":", non-ASCII) keeps its plain name, which sabre percent-
     * encodes in hrefs as it always did, so existing object names do not
     * change and no client is left with a stale href. The rest are named by
     * their UID in base64url behind "b64-", which round-trips without a lookup.
     */
    public static function objectUri(string $uid): string
    {
        if ($uid !== '' && $uid !== '.' && $uid !== '..' && strpbrk($uid, '/\\') === false && !str_starts_with($uid, 'b64-')) {
            return $uid . '.ics';
        }
        return 'b64-' . rtrim(strtr(base64_encode($uid), '+/', '-_'), '=') . '.ics';
    }

    public static function uidFromObjectUri(string $uri): ?string
    {
        if (!str_ends_with($uri, '.ics')) {
            return null;
        }
        $stem = substr($uri, 0, -4);
        if ($stem === '') {
            return null;
        }
        if (str_starts_with($stem, 'b64-') && preg_match('/^[A-Za-z0-9_-]+$/', substr($stem, 4)) === 1) {
            $decoded = base64_decode(strtr(substr($stem, 4), '-_', '+/'), true);
            // Accept any exact base64url name, not only the ones objectUri would
            // pick today: 0.1.3 to 0.1.5 encoded more UIDs (":", spaces), and
            // a client may still hold those names.
            if (is_string($decoded) && $decoded !== '' && 'b64-' . rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') === $stem) {
                return $decoded;
            }
        }
        return $stem;
    }

    /** Weak content fingerprint: changes whenever the row (or an override) is touched. */
    public static function etag(string $updatedAt, int|string $id): string
    {
        return md5($updatedAt . ':' . $id);
    }

    /**
     * Serialize one calendar object: the master VEVENT (with RRULE/EXDATEs)
     * plus its per-instance overrides as RECURRENCE-ID VEVENTs. Trip
     * relationships ride on the master as RELATED-TO lines: $relatedChildren
     * = member uids when the master is a container (RELTYPE=CHILD),
     * $relatedParents = container uids when it is a member (RELTYPE=PARENT).
     *
     * @param array<string,mixed> $master
     * @param list<array<string,mixed>> $overrides
     * @param list<string> $relatedChildren
     * @param list<string> $relatedParents
     */
    public static function buildObject(array $master, array $overrides, array $relatedChildren = [], array $relatedParents = []): string
    {
        if ($relatedChildren !== []) {
            $master['related_children'] = $relatedChildren;
        }
        if ($relatedParents !== []) {
            $master['related_parents'] = $relatedParents;
        }
        return Ics::buildObject([$master, ...$overrides]);
    }
}
