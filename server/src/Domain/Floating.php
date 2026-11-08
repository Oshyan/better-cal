<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Domain\Series\Cal;
use BetterCal\Domain\Series\Wall;
use BetterCal\Support\Time;

/**
 * Floating times (#167): an event whose clock time holds in any zone, "7am,
 * wherever I am". A floating row is stored like any other, its start_utc and
 * end_utc the wall clock read in its own zone (tzid, the Home zone when it
 * was written), so every query, index, key and the series model read it as
 * an ordinary row in that zone: a floating series expands in wall time there,
 * and an edited day's key is the instant its wall-clock occurrence has there.
 *
 * What a floating row's instant is for someone looking at it is decided here
 * and nowhere else: its wall clock in its own zone, read on the viewer's
 * clock (shown), and back (stored). The window, the single-event record, the
 * reminder scan and a request's instants all go through these two.
 */
final class Floating
{
    /**
     * How far a wall clock's instant moves between any two zones: from
     * UTC-12 to UTC+14. A window widened by this both ways holds every
     * floating row that can resolve inside it, whatever the viewer's zone.
     */
    public const SPAN_SECONDS = 26 * 3600;

    /** Is this row floating? Only a timed event can be. */
    public static function is(array $row): bool
    {
        return (int) ($row['floating'] ?? 0) === 1 && (int) ($row['all_day'] ?? 0) !== 1;
    }

    /**
     * The instant a wall clock stored in $own names on the viewer's clock:
     * its clock in $own, read in $viewer by the one wall-clock rule (Wall).
     */
    public static function shown(\DateTimeImmutable $stored, \DateTimeZone $own, \DateTimeZone $viewer): \DateTimeImmutable
    {
        return self::move($stored, $own, $viewer);
    }

    /** The reverse of shown(): an instant on the viewer's clock as the row stores its wall clock. */
    public static function stored(\DateTimeImmutable $shown, \DateTimeZone $own, \DateTimeZone $viewer): \DateTimeImmutable
    {
        return self::move($shown, $viewer, $own);
    }

    /**
     * A row's span as the viewer sees it: a floating row's start and end
     * moved to the viewer's clock, any other row's as given.
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} UTC
     */
    public static function span(array $row, \DateTimeImmutable $start, \DateTimeImmutable $end, ?\DateTimeZone $viewer): array
    {
        if ($viewer === null || !self::is($row)) {
            return [$start, $end];
        }
        $own = Time::zone((string) ($row['tzid'] ?? 'UTC'));
        return [self::shown($start, $own, $viewer), self::shown($end, $own, $viewer)];
    }

    /** @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} the window widened by SPAN_SECONDS both ways */
    public static function widened(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return [$start->modify('-' . self::SPAN_SECONDS . ' seconds'), $end->modify('+' . self::SPAN_SECONDS . ' seconds')];
    }

    /**
     * SQL for a stored row that can show inside [start, end): an ordinary
     * row overlapping it, a floating one overlapping it widened (widened()).
     * The outer bounds come first so the start_utc range still drives the
     * index. With $series, a master with a rule counts by its start alone.
     *
     * @return array{0:string,1:list<string>}
     */
    public static function overlapSql(\DateTimeImmutable $start, \DateTimeImmutable $end, bool $series = false): array
    {
        [$wideStart, $wideEnd] = self::widened($start, $end);
        $s = Time::toDb($start);
        $e = Time::toDb($end);
        $ws = Time::toDb($wideStart);
        $we = Time::toDb($wideEnd);
        if (!$series) {
            return ['(start_utc < ? AND end_utc > ? AND (floating = 1 OR (start_utc < ? AND end_utc > ?)))', [$we, $ws, $e, $s]];
        }
        return [
            '(start_utc < ? AND ((rrule IS NULL AND end_utc > ? AND (floating = 1 OR (start_utc < ? AND end_utc > ?)))'
                . ' OR (rrule IS NOT NULL AND (floating = 1 OR start_utc < ?))))',
            [$we, $ws, $e, $s, $e],
        ];
    }

    /** A wall clock read in $from, as the instant the same clock names in $to. */
    private static function move(\DateTimeImmutable $at, \DateTimeZone $from, \DateTimeZone $to): \DateTimeImmutable
    {
        if ($from->getName() === $to->getName()) {
            return $at->setTimezone(Time::utc());
        }
        $local = $at->setTimezone($from);
        $clock = [(int) $local->format('G'), (int) $local->format('i'), (int) $local->format('s')];
        return Wall::resolve(Cal::dnOf($local), $clock, $to)->setTimezone(Time::utc());
    }
}
