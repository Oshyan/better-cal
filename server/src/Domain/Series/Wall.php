<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

/**
 * Wall clocks and the instants they name in a zone, from the zone's own
 * transitions (Zones, DST and All-Day Days). One rule for every reader, the
 * expander (RuleIterator) and the model (Calc::at) alike, so a day's key is
 * the same instant wherever it is read:
 *
 *  - a clock that happens once is that instant;
 *  - a clock that happens twice (a fall-back overlap) is its earlier
 *    instant, as RFC 5545 3.3.5 says for a DATE-TIME; an instant given
 *    stays that instant, which is the caller's to keep (Calc::at);
 *  - a clock that doesn't exist (a spring-forward gap) is read by the
 *    offset before the gap, the same clock plus the gap (02:30 becomes
 *    03:30, 02:15 becomes 02:45 where the clocks move half an hour).
 */
final class Wall
{
    /**
     * Every instant a wall clock names on a local day, in order: none in a
     * gap, two in a repeated span, one otherwise.
     *
     * @param array{0:int,1:int,2:int} $clock
     * @return list<\DateTimeImmutable> in the zone
     */
    public static function instants(int $dn, array $clock, \DateTimeZone $zone): array
    {
        $asUtc = self::asUtc($dn, $clock);
        $out = [];
        foreach (self::offsets($asUtc, $zone) as $offset) {
            $t = $asUtc - $offset;
            if ($zone->getOffset(new \DateTimeImmutable('@' . $t)) === $offset) {
                $out[$t] = (new \DateTimeImmutable('@' . $t))->setTimezone($zone);
            }
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * The one instant a wall clock is read as where no instant is given:
     * its earlier instant, or in a gap the offset before it.
     *
     * @param array{0:int,1:int,2:int} $clock
     */
    public static function resolve(int $dn, array $clock, \DateTimeZone $zone): \DateTimeImmutable
    {
        $all = self::instants($dn, $clock, $zone);
        if ($all !== []) {
            return $all[0];
        }
        $asUtc = self::asUtc($dn, $clock);
        $transitions = $zone->getTransitions($asUtc - 2 * 86400, $asUtc + 2 * 86400) ?: [];
        for ($i = 1; $i < count($transitions); $i++) {
            $before = (int) $transitions[$i - 1]['offset'];
            $after = (int) $transitions[$i]['offset'];
            $at = (int) $transitions[$i]['ts'];
            if ($after > $before && $asUtc >= $at + $before && $asUtc < $at + $after) {
                return (new \DateTimeImmutable('@' . ($asUtc - $before)))->setTimezone($zone);
            }
        }
        // No instant and no gap is a zone the data can't place; never met in
        // tzdata, and PHP's own reading is the gap rule's where it is one.
        return new \DateTimeImmutable(sprintf('%s %02d:%02d:%02d', Cal::date($dn), ...$clock), $zone);
    }

    /** The wall clock's seconds as if it were UTC. @param array{0:int,1:int,2:int} $clock */
    public static function asUtc(int $dn, array $clock): int
    {
        return $dn * 86400 + $clock[0] * 3600 + $clock[1] * 60 + $clock[2];
    }

    /**
     * Every offset the zone has within two days of a wall clock: an instant
     * a clock names is that clock less one of them.
     *
     * @return list<int>
     */
    private static function offsets(int $asUtc, \DateTimeZone $zone): array
    {
        $offsets = [];
        foreach ($zone->getTransitions($asUtc - 2 * 86400, $asUtc + 2 * 86400) ?: [] as $t) {
            $offsets[(int) $t['offset']] = true;
        }
        if ($offsets === []) {
            $offsets[$zone->getOffset(new \DateTimeImmutable('@' . $asUtc))] = true;
        }
        return array_keys($offsets);
    }
}
