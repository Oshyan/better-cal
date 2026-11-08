<?php

declare(strict_types=1);

namespace BetterCal\Domain;

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
 * and nowhere else: its wall clock, read on the viewer's clock by the one
 * wall-clock rule (at), and back (stored). A row's wall clock is its stored
 * instant's in its own zone; a generated occurrence's is the clock the rule
 * generated it from (occurrence), so a day the own zone skips that clock
 * moves nothing for a viewer whose zone has it. The window and its edges
 * (storedWindow), the single-event record, the reminder scan, search and a
 * request's instants all go through these.
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
     * A wall clock (seconds as Wall::asUtc gives them) as the viewer's
     * instant: the one wall-clock rule (Wall::resolve), a clock the viewer's
     * zone skips read as the clock plus the gap, one it repeats as the
     * earlier. Every other reader here comes to this.
     */
    public static function at(int $wall, \DateTimeZone $viewer): \DateTimeImmutable
    {
        $sec = (($wall % 86400) + 86400) % 86400;
        $dn = intdiv($wall - $sec, 86400);
        return Wall::resolve($dn, [intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60], $viewer)->setTimezone(Time::utc());
    }

    /** An instant's wall clock in a zone, as at() reads it. */
    public static function wallOf(\DateTimeImmutable $t, \DateTimeZone $zone): int
    {
        return $t->getTimestamp() + $zone->getOffset($t);
    }

    /**
     * A generated occurrence of a floating series on the viewer's clock: its
     * start at the wall clock the rule generated it from ($wall,
     * RuleIterator::wall), not the own zone's reading of that clock, so
     * 02:30 on the day the own zone skips it is 02:30 for a viewer whose
     * zone has it, and 03:30 only where the viewer's zone skips it too. Its
     * end keeps the stored span's length on the wall clock and moves with
     * the start as a typed range does (Wall::typedEnd). On the own zone's
     * clock it is the stored occurrence as it is. Null $wall reads the
     * start's own clock (span).
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} UTC
     */
    public static function occurrence(\DateTimeImmutable $start, \DateTimeImmutable $end, ?int $wall, \DateTimeZone $own, \DateTimeZone $viewer): array
    {
        if ($own->getName() === $viewer->getName()) {
            return [$start->setTimezone(Time::utc()), $end->setTimezone(Time::utc())];
        }
        $startWall = self::wallOf($start, $own);
        $wall ??= $startWall;
        // The stored end's clock, less the push the own zone gave the start.
        return self::range($wall, self::wallOf($end, $own) - ($startWall - $wall), $viewer);
    }

    /**
     * A range typed as two wall clocks on the viewer's clock: the start by
     * at(), the end moved by the push the gap gave the start (Wall::typedEnd),
     * or where the end's clock is not after the start's (a stored span
     * across the hour the own zone repeats) the end's own reading, never
     * before the start.
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} UTC
     */
    private static function range(int $startWall, int $endWall, \DateTimeZone $viewer): array
    {
        $start = self::at($startWall, $viewer);
        $end = Wall::typedEnd($start->setTimezone($viewer), $startWall, $endWall, $viewer);
        $end = $end === null ? max($start, self::at($endWall, $viewer)) : $end->setTimezone(Time::utc());
        return [$start, $end];
    }

    /**
     * The instant a wall clock stored in $own names on the viewer's clock:
     * its clock in $own, read in $viewer by the one wall-clock rule (at).
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
     * moved to the viewer's clock (occurrence), any other row's as given. A
     * row standing for one generated occurrence of its series (search's next
     * or latest date) carries the wall clock the rule generated it from as
     * '_wall'.
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} UTC
     */
    public static function span(array $row, \DateTimeImmutable $start, \DateTimeImmutable $end, ?\DateTimeZone $viewer): array
    {
        if ($viewer === null || !self::is($row)) {
            return [$start, $end];
        }
        $wall = isset($row['_wall']) ? (int) $row['_wall'] : null;
        return self::occurrence($start, $end, $wall, Time::zone((string) ($row['tzid'] ?? 'UTC')), $viewer);
    }

    /**
     * The viewer's window [start, end) on a floating row's own clock: each
     * bound's wall clock on the viewer's clock read in $own (stored), so a
     * series expanded over it in $own holds every occurrence that shows in
     * the window, and not a day's worth on either side. Where either zone
     * changes its offset near a bound, a wall clock and an instant no longer
     * move together there (a skipped clock reads forward, a repeated one
     * twice), so that bound is moved out by the size of those changes; a
     * window with no change near its bounds is moved exactly.
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} UTC
     */
    public static function storedWindow(\DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeZone $own, \DateTimeZone $viewer): array
    {
        if ($own->getName() === $viewer->getName()) {
            return [$start->setTimezone(Time::utc()), $end->setTimezone(Time::utc())];
        }
        $slack = static fn(\DateTimeImmutable $t): int => self::changeNear($t, $own) + self::changeNear($t, $viewer);
        return [
            self::stored($start, $own, $viewer)->modify('-' . $slack($start) . ' seconds'),
            self::stored($end, $own, $viewer)->modify('+' . $slack($end) . ' seconds'),
        ];
    }

    /** The largest offset change a zone makes within two days of an instant, in seconds; 0 for none. */
    private static function changeNear(\DateTimeImmutable $t, \DateTimeZone $zone): int
    {
        $ts = $t->getTimestamp();
        $transitions = $zone->getTransitions($ts - 2 * 86400, $ts + 2 * 86400) ?: [];
        $most = 0;
        for ($i = 1; $i < count($transitions); $i++) {
            $most = max($most, abs((int) $transitions[$i]['offset'] - (int) $transitions[$i - 1]['offset']));
        }
        return $most;
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
        return self::at(self::wallOf($at, $from), $to);
    }
}
