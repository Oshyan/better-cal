<?php

declare(strict_types=1);

namespace BetterCal\Dav;

use BetterCal\Domain\Ics;
use BetterCal\Support\Time;

/**
 * Pure CalDAV mapping helpers: calendar/object URI naming, etag derivation,
 * parsed-VEVENT -> event-column mapping, and calendar-object body assembly.
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

    /** Calendar-object uri for an event uid; one object per uid (master + overrides). */
    public static function objectUri(string $uid): string
    {
        return $uid . '.ics';
    }

    public static function uidFromObjectUri(string $uri): ?string
    {
        if (!str_ends_with($uri, '.ics')) {
            return null;
        }
        $uid = substr($uri, 0, -4);
        return $uid === '' ? null : $uid;
    }

    /** Weak content fingerprint: changes whenever the row (or an override) is touched. */
    public static function etag(string $updatedAt, int|string $id): string
    {
        return md5($updatedAt . ':' . $id);
    }

    /**
     * Map one parsed VEVENT (Ics::parse output) onto events-table columns.
     * DATE-valued events (all_day) are pinned to tzid UTC; EXDATEs collapse
     * to exdates_json; empty rrule stays NULL.
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    public static function eventColumns(array $parsed): array
    {
        $allDay = (int) ($parsed['all_day'] ?? 0) === 1;
        $exdates = is_array($parsed['exdates'] ?? null) ? $parsed['exdates'] : [];
        return [
            'title' => (string) ($parsed['title'] ?? ''),
            'description' => $parsed['description'] ?? null,
            'location' => $parsed['location'] ?? null,
            'url' => $parsed['url'] ?? null,
            'start_utc' => (string) $parsed['start_utc'],
            'end_utc' => (string) $parsed['end_utc'],
            'all_day' => $allDay ? 1 : 0,
            'tzid' => $allDay ? 'UTC' : Time::normalizeTzid((string) ($parsed['tzid'] ?? 'UTC')),
            'rrule' => ($parsed['rrule'] ?? null) === '' ? null : ($parsed['rrule'] ?? null),
            'exdates_json' => $exdates === [] ? null : json_encode($exdates),
            'status' => (string) ($parsed['status'] ?? 'confirmed'),
            'recurrence_instance_utc' => $parsed['recurrence_instance_utc'] ?? null,
        ];
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
