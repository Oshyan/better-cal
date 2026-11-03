<?php
// The harness's own arithmetic: a seeded generator, Gregorian day numbers
// and local clock readings. Nothing here calls the series model.
declare(strict_types=1);

namespace BetterCal\EditHarness;

/** A small deterministic generator (xorshift32), so a seed names a run on any PHP build. */
final class Rng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed * 2654435761 + 1013904223) & 0xFFFFFFFF ?: 0x9E3779B9;
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;
        return $this->state;
    }

    public function int(int $min, int $max): int
    {
        return $min + ($this->next() % ($max - $min + 1));
    }

    public function chance(float $p): bool
    {
        return ($this->next() % 10000) < (int) ($p * 10000);
    }

    /** @template T @param list<T> $items @return T */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /** Pick by weight: ['a' => 3, 'b' => 1]. */
    public function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $n = $this->int(1, $total);
        foreach ($weights as $key => $w) {
            $n -= $w;
            if ($n <= 0) {
                return (string) $key;
            }
        }
        return (string) array_key_last($weights);
    }
}

/** Gregorian day numbers (days from 1970-01-01), weekdays Monday = 0. */
final class Days
{
    public const CODES = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    public static function dn(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }

    /** @return array{0:int,1:int,2:int} */
    public static function ymd(int $z): array
    {
        $z += 719468;
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
        return [31, self::leap($y) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$m - 1];
    }

    /** Month index: months from year 0. */
    public static function mi(int $y, int $m): int
    {
        return $y * 12 + $m - 1;
    }

    /** @return array{0:int,1:int} */
    public static function fromMi(int $mi): array
    {
        $y = intdiv($mi >= 0 ? $mi : $mi - 11, 12);
        return [$y, $mi - $y * 12 + 1];
    }

    public static function miOf(int $dn): int
    {
        [$y, $m] = self::ymd($dn);
        return self::mi($y, $m);
    }

    public static function date(int $dn): string
    {
        [$y, $m, $d] = self::ymd($dn);
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    public static function ofDate(string $date): int
    {
        return self::dn((int) substr($date, 0, 4), (int) substr($date, 5, 2), (int) substr($date, 8, 2));
    }

    public static function code(int $wd): string
    {
        return self::CODES[$wd];
    }

    public static function wdOf(string $code): int
    {
        $i = array_search(strtoupper($code), self::CODES, true);
        return $i === false ? -1 : (int) $i;
    }
}

/** Instants as the database stores them ('Y-m-d H:i:s' UTC) and their local readings. */
final class Clock
{
    public static function utc(): \DateTimeZone
    {
        static $z = null;
        return $z ??= new \DateTimeZone('UTC');
    }

    public static function zone(string $tz): \DateTimeZone
    {
        static $zones = [];
        return $zones[$tz] ??= new \DateTimeZone($tz);
    }

    public static function parse(string $db): \DateTimeImmutable
    {
        return new \DateTimeImmutable($db, self::utc());
    }

    public static function db(\DateTimeImmutable $t): string
    {
        return $t->setTimezone(self::utc())->format('Y-m-d H:i:s');
    }

    public static function local(string $db, string $tz): \DateTimeImmutable
    {
        return self::parse($db)->setTimezone(self::zone($tz));
    }

    public static function dnOf(\DateTimeImmutable $local): int
    {
        return Days::dn((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
    }

    public static function secsOf(\DateTimeImmutable $local): int
    {
        return (int) $local->format('G') * 3600 + (int) $local->format('i') * 60 + (int) $local->format('s');
    }

    /** The local day of a stored instant in a zone. */
    public static function dayOf(string $db, string $tz): int
    {
        return self::dnOf(self::local($db, $tz));
    }

    /** The instant of a local day and clock in a zone (a clock in a gap moves forward by the gap, as PHP and sabre read it). */
    public static function at(int $dn, int $secs, string $tz): \DateTimeImmutable
    {
        $wall = sprintf('%s %02d:%02d:%02d', Days::date($dn), intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
        $t = new \DateTimeImmutable($wall, self::zone($tz));
        // A clock that happens twice (the autumn overlap) is its first, earlier instant (RFC 5545 3.3.5).
        foreach ([3600, 1800] as $back) {
            $e = self::plus($t, -$back);
            if ($e->setTimezone(self::zone($tz))->format('Y-m-d H:i:s') === $wall) {
                return $e->setTimezone(self::zone($tz));
            }
        }
        return $t;
    }

    /** Whether a local clock doesn't exist on a day (inside a spring-forward gap). */
    public static function inGap(int $dn, int $secs, string $tz): bool
    {
        return self::secsOf(self::at($dn, $secs, $tz)) !== $secs;
    }

    /** An instant $secs of elapsed time later, in the same zone (wall-clock arithmetic across a clock change would be off by the change). */
    public static function plus(\DateTimeImmutable $t, int $secs): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . ($t->getTimestamp() + $secs)))->setTimezone($t->getTimezone());
    }

    public static function iso(\DateTimeImmutable $t): string
    {
        return $t->format('Y-m-d\TH:i:sP');
    }

    /** A zone's first day after a clock change, between two days, or null. */
    public static function changeAfter(string $tz, int $fromDn, int $toDn): ?int
    {
        if ($tz === 'UTC') {
            return null;
        }
        $t = self::zone($tz)->getTransitions(self::at($fromDn, 0, 'UTC')->getTimestamp(), self::at($toDn, 0, 'UTC')->getTimestamp());
        foreach (array_slice($t, 1) as $tr) {
            return self::dnOf((new \DateTimeImmutable('@' . $tr['ts']))->setTimezone(self::zone($tz)));
        }
        return null;
    }
}

/** Time spent per phase of a case, printed by harness.php when HARNESS_PROFILE is set. */
final class Prof
{
    public static array $t = [];
    private static float $at = 0.0;
    private static string $phase = '';

    public static function mark(string $phase): void
    {
        $now = microtime(true);
        if (self::$phase !== '') {
            self::$t[self::$phase] = (self::$t[self::$phase] ?? 0.0) + ($now - self::$at);
        }
        self::$phase = $phase;
        self::$at = $now;
    }
}
