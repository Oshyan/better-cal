<?php

declare(strict_types=1);

namespace BetterCal\Support;

final class Time
{
    public const DB = 'Y-m-d H:i:s';

    /** Common US abbreviations normalized to IANA zones. */
    private const TZ_ALIASES = [
        'PDT' => 'America/Los_Angeles', 'PST' => 'America/Los_Angeles',
        'MDT' => 'America/Denver', 'MST' => 'America/Denver',
        'CDT' => 'America/Chicago', 'CST' => 'America/Chicago',
        'EDT' => 'America/New_York', 'EST' => 'America/New_York',
        'Z' => 'UTC', 'GMT' => 'UTC', 'UT' => 'UTC',
    ];

    private static ?\DateTimeZone $utc = null;

    public static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }

    /** Resolve a tzid (IANA name or common abbreviation) to a valid zone; UTC fallback. */
    public static function zone(?string $tzid): \DateTimeZone
    {
        if ($tzid === null || $tzid === '') {
            return self::utc();
        }
        $tzid = self::TZ_ALIASES[strtoupper($tzid)] ?? $tzid;
        try {
            return new \DateTimeZone($tzid);
        } catch (\Exception) {
            return self::utc();
        }
    }

    /** Normalize a tzid string to a valid IANA identifier ('UTC' fallback). */
    public static function normalizeTzid(?string $tzid): string
    {
        return self::zone($tzid)->getName();
    }

    /** Parse an ISO8601 string (with or without offset) into a UTC instant. */
    public static function parseIso(string $iso, ?string $assumeTzid = null): \DateTimeImmutable
    {
        $iso = trim($iso);
        if ($iso === '') {
            throw new \InvalidArgumentException('empty datetime');
        }
        $hasOffset = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $iso);
        try {
            $dt = $hasOffset
                ? new \DateTimeImmutable($iso)
                : new \DateTimeImmutable($iso, self::zone($assumeTzid));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('invalid datetime: ' . $iso, 0, $e);
        }
        return $dt->setTimezone(self::utc());
    }

    public static function toDb(\DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(self::utc())->format(self::DB);
    }

    public static function fromDb(string $db): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat(self::DB, $db, self::utc());
        if ($dt === false) {
            throw new \InvalidArgumentException('invalid db datetime: ' . $db);
        }
        return $dt;
    }

    /** ISO8601 with offset. */
    public static function iso(\DateTimeImmutable $dt): string
    {
        return $dt->format('Y-m-d\TH:i:sP');
    }

    /** UTC db string rendered as ISO8601 in the given tzid. */
    public static function dbToIso(string $db, ?string $tzid = null): string
    {
        return self::iso(self::fromDb($db)->setTimezone(self::zone($tzid)));
    }

    public static function nowUtc(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::utc());
    }

    public static function nowDb(): string
    {
        return self::nowUtc()->format(self::DB);
    }
}
