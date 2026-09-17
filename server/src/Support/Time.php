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

    /**
     * An all-day boundary as the UTC instant of midnight, in the event's zone,
     * of the calendar date WRITTEN in the string.
     *
     * An all-day event is a date, not an instant, so the date portion is read
     * literally and any time or offset after it is ignored. Converting the
     * instant into the event's zone first (the old behaviour) moved the event
     * a day whenever the sender's offset and the event's zone disagreed:
     * "2026-06-02T00:00:00+01:00" from a browser in Lisbon is June 1 in Los
     * Angeles, and the server's own serialization ("…T00:00:00+00:00") echoed
     * back by an API client was the previous day anywhere west of UTC.
     *
     * This is the write-side twin of the serializer, which already emits the
     * literal date, so a read followed by a write is now the identity in every
     * zone.
     *
     * Three shapes arrive:
     *   "2026-06-02"                  a date: what current clients send.
     *   "2026-06-02T00:00:00+01:00"   midnight somewhere: the date is literal,
     *                                 whatever the offset (a browser's local
     *                                 midnight, or our own +00:00 echoed back).
     *   "2026-06-02T19:00:00-07:00"   a time of day (a timed event being made
     *                                 all-day): the sender's own date, June 2.
     *
     * One legacy exception to the last: clients from before this fix moved
     * all-day events by pushing our "+00:00 midnight" through a local Date,
     * producing e.g. "2026-06-01T17:00:00-07:00" to mean June 2. Those always
     * sit on a UTC midnight (give or take the hour a DST change inside the
     * shift costs), so an instant within an hour of one is read as that UTC
     * date. Tabs opened before the deploy keep sending it until they reload;
     * the branch can go once no such client can exist.
     */
    public static function parseAllDay(string $iso, ?string $tzid): \DateTimeImmutable
    {
        $iso = trim($iso);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|[T ](\d{2}):(\d{2}))/', $iso, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('invalid all-day date: ' . $iso);
        }
        $date = "$m[1]-$m[2]-$m[3]";
        if (isset($m[4]) && ($m[4] !== '00' || $m[5] !== '00')) {
            $instant = self::parseIso($iso, $tzid);
            $nearestUtcMidnight = $instant->add(new \DateInterval('PT12H'))->setTime(0, 0);
            if (abs($instant->getTimestamp() - $nearestUtcMidnight->getTimestamp()) <= 3600) {
                $date = $nearestUtcMidnight->format('Y-m-d');
            }
        }
        return (new \DateTimeImmutable($date . ' 00:00:00', self::zone($tzid)))->setTimezone(self::utc());
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
