<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Domain\Series\Wall;
use BetterCal\Infra\Db;
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
 * (storedWindow), the single-event record, the reminder scan, search, a
 * person's next event and a request's instants all go through these; search
 * and a person's next event read now on each row's clock by nowByZone,
 * then settle the rows near it by their instants as shown.
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

    /**
     * Now as each of this user's floating events stores it, by zone, with
     * how far a comparison against it can be off. 'at' is the stored instant
     * whose clock shows at now on the viewer's clock (stored()), so a
     * floating event has started or ended for the viewer about when its
     * stored start or end passes it, and a query can compare stored columns
     * before its limit (nowOnRowSql). About, because a wall clock and an
     * instant move together only between offset changes: where the viewer's
     * zone repeats an hour, or a row's date has another offset in either
     * zone than now has, a stored column and its instant as shown stand
     * apart by up to how much the two zones' offsets vary. 'slack' (seconds)
     * is twice that, read from both zones' offsets over the dates this
     * user's rows of the zone hold and now, two days either side: a stored
     * start or end more than 'slack' from 'at' is on the same side of now as
     * shown, and two stored starts more than 'slack' apart (each moved by
     * its zone's distance at now, Search) are in the same order as shown. A
     * query widens by it and the caller settles the rows inside it by their
     * exact instants as shown (Floating::span).
     *
     * Only zones other than the viewer's are listed (the same zone is now
     * itself, exactly), and none without a viewer: the reads with no device
     * (feeds, ranking, review, duplicates) compare on the Home clock, as
     * stored. Search and a person's next event read now through this.
     *
     * @return array<string,array{at:string,slack:int}> tzid => now stored ('Y-m-d H:i:s' UTC) and its slack
     */
    public static function nowByZone(Db $db, int $userId, string $nowSql, ?\DateTimeZone $viewer): array
    {
        if ($viewer === null) {
            return [];
        }
        $now = Time::fromDb($nowSql);
        $out = [];
        $zones = $db->all(
            'SELECT tzid, MIN(start_utc) AS lo, MAX(end_utc) AS hi FROM events
             WHERE user_id = ? AND floating = 1 AND all_day = 0 AND deleted_at IS NULL GROUP BY tzid',
            [$userId]
        );
        foreach ($zones as $z) {
            $tzid = (string) $z['tzid'];
            $own = Time::zone($tzid);
            if ($own->getName() === $viewer->getName()) {
                continue;
            }
            $from = min($now, Time::fromDb((string) $z['lo']))->modify('-2 days');
            $to = max($now, Time::fromDb((string) $z['hi']))->modify('+2 days');
            $out[$tzid] = [
                'at' => Time::toDb(self::stored($now, $own, $viewer)),
                'slack' => 2 * (self::offsetRange($own, $from, $to) + self::offsetRange($viewer, $from, $to)),
            ];
        }
        return $out;
    }

    /** How far a zone's offset varies between two instants, in seconds; 0 for a zone that keeps one. */
    private static function offsetRange(\DateTimeZone $zone, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $offsets = array_map(static fn(array $t): int => (int) $t['offset'], $zone->getTransitions($from->getTimestamp(), $to->getTimestamp()) ?: []);
        return $offsets === [] ? 0 : max($offsets) - min($offsets);
    }

    /**
     * SQL for "now" on each row's own clock (nowByZone): the floating rows
     * of each listed zone compare against their own, every other row against
     * now. $widen moves each zone's now by its slack, -1 earlier and +1
     * later, so a comparison holds every row that may be on its side of now
     * as shown; 0 is the zone's now itself. $alias prefixes the columns
     * ('e.' for a joined events table).
     *
     * @param array<string,array{at:string,slack:int}> $clocks
     * @return array{0:string,1:list<string>}
     */
    public static function nowOnRowSql(array $clocks, string $nowSql, string $alias = '', int $widen = 0): array
    {
        if ($clocks === []) {
            return ['?', [$nowSql]];
        }
        $sql = 'CASE';
        $params = [];
        foreach ($clocks as $tzid => $clock) {
            $sql .= " WHEN {$alias}floating = 1 AND {$alias}tzid = ? THEN ?";
            array_push($params, (string) $tzid, self::shifted($clock, $widen));
        }
        return [$sql . ' ELSE ? END', [...$params, $nowSql]];
    }

    /**
     * SQL for a floating row whose $column (start_utc, end_utc) is within
     * its zone's slack of its zone's now (nowByZone): the rows a comparison
     * widened by nowOnRowSql may hold on the wrong side, which the caller
     * settles by their exact instants. '0 = 1' when no zone has any slack.
     *
     * @param array<string,array{at:string,slack:int}> $clocks
     * @return array{0:string,1:list<string>}
     */
    public static function nearNowSql(array $clocks, string $column, string $alias = ''): array
    {
        $parts = [];
        $params = [];
        foreach ($clocks as $tzid => $clock) {
            if ($clock['slack'] > 0) {
                $parts[] = "({$alias}tzid = ? AND {$alias}$column >= ? AND {$alias}$column < ?)";
                array_push($params, (string) $tzid, self::shifted($clock, -1), self::shifted($clock, 1));
            }
        }
        if ($parts === []) {
            return ['0 = 1', []];
        }
        return ["({$alias}floating = 1 AND {$alias}all_day = 0 AND (" . implode(' OR ', $parts) . '))', $params];
    }

    /** The largest slack among the zones (nowByZone), in seconds. @param array<string,array{at:string,slack:int}> $clocks */
    public static function mostSlack(array $clocks): int
    {
        return $clocks === [] ? 0 : max(array_column($clocks, 'slack'));
    }

    /** A zone's now (nowByZone) moved by its slack $times times. @param array{at:string,slack:int} $clock */
    private static function shifted(array $clock, int $times): string
    {
        return $times === 0 ? $clock['at'] : Time::toDb(Time::fromDb($clock['at'])->modify(($times * $clock['slack']) . ' seconds'));
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
