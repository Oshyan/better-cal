<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

/**
 * Gregorian day arithmetic on day numbers (days since 1970-01-01), with no
 * time zones and no expander: what the series model reads month lengths,
 * weekdays and periods from. Weekdays are 0 for Monday to 6 for Sunday.
 */
final class Cal
{
    public const CODES = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /** Day number of a civil date (Hinnant's days_from_civil). */
    public static function dn(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }

    /** @return array{0:int,1:int,2:int} year, month, day */
    public static function ymd(int $dn): array
    {
        $z = $dn + 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $y = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m = $mp + ($mp < 10 ? 3 : -9);
        return [$y + ($m <= 2 ? 1 : 0), $m, $d];
    }

    /** 0 Monday .. 6 Sunday. 1970-01-01 was a Thursday. */
    public static function wd(int $dn): int
    {
        return (($dn % 7) + 7 + 3) % 7;
    }

    public static function leap(int $y): bool
    {
        return ($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0;
    }

    public static function monthLen(int $y, int $m): int
    {
        return $m === 2 ? (self::leap($y) ? 29 : 28) : (in_array($m, [4, 6, 9, 11], true) ? 30 : 31);
    }

    /** Months since year 0, so consecutive months differ by one. */
    public static function mi(int $y, int $m): int
    {
        return $y * 12 + $m - 1;
    }

    /** @return array{0:int,1:int} year and month of a month index */
    public static function fromMi(int $mi): array
    {
        $y = intdiv($mi, 12);
        $m = $mi - $y * 12;
        if ($m < 0) {
            $m += 12;
            $y--;
        }
        return [$y, $m + 1];
    }

    public static function miOf(int $dn): int
    {
        [$y, $m] = self::ymd($dn);
        return self::mi($y, $m);
    }

    public static function dnOf(\DateTimeImmutable $local): int
    {
        return self::dn((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
    }

    public static function date(int $dn): string
    {
        [$y, $m, $d] = self::ymd($dn);
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    public static function code(int $wd): string
    {
        return self::CODES[$wd];
    }

    public static function wdOf(string $code): ?int
    {
        $i = array_search($code, self::CODES, true);
        return $i === false ? null : (int) $i;
    }
}
