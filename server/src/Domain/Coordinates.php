<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/** Canonical geographic-coordinate validation for every provider and write path. */
final class Coordinates
{
    public static function latitude(mixed $value): ?float
    {
        return self::value($value, -90.0, 90.0, 'latitude');
    }

    public static function longitude(mixed $value): ?float
    {
        return self::value($value, -180.0, 180.0, 'longitude');
    }

    /** @return array{0:float,1:float}|null */
    public static function pair(mixed $lat, mixed $lng): ?array
    {
        if ($lat === null || $lng === null) {
            return null;
        }
        return [self::latitude($lat), self::longitude($lng)];
    }

    private static function value(mixed $value, float $min, float $max, string $name): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("$name must be a number or null");
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < $min || $number > $max) {
            throw new \InvalidArgumentException("$name is outside its geographic range");
        }
        return $number;
    }
}
