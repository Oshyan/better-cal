<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Support\Time;

/**
 * The single recurrence expansion engine. Expansion happens in the event's
 * tzid (wall-clock stable across DST) via sabre/vobject's EventIterator,
 * then converts to UTC. Hard-capped at MAX_INSTANCES per event per query.
 */
final class Recurrence
{
    public const MAX_INSTANCES = 500;

    private const ALLOWED_RRULE_KEYS = [
        'FREQ', 'UNTIL', 'COUNT', 'INTERVAL', 'BYSECOND', 'BYMINUTE', 'BYHOUR',
        'BYDAY', 'BYMONTHDAY', 'BYYEARDAY', 'BYWEEKNO', 'BYMONTH', 'BYSETPOS', 'WKST',
    ];
    private const FREQS = ['SECONDLY', 'MINUTELY', 'HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /** @var callable(array,\DateTimeImmutable,\DateTimeImmutable):list<array{start:\DateTimeImmutable,end:\DateTimeImmutable}> */
    private $expander;

    public function __construct(?callable $expander = null)
    {
        $this->expander = $expander ?? [self::class, 'sabreExpand'];
    }

    /** Frozen contract: instanceId = eventId + ":" + occurrenceStartUtc (Ymd\THis\Z). */
    public static function instanceId(int|string $eventId, \DateTimeImmutable $startUtc): string
    {
        return $eventId . ':' . $startUtc->setTimezone(Time::utc())->format('Ymd\THis\Z');
    }

    /**
     * Expand a master event row into occurrences within [winStart, winEnd).
     * Overrides replace their instances; exdates suppress theirs. Overrides
     * that moved into the window from an out-of-window instance are appended.
     *
     * @param array<string,mixed> $master
     * @param list<array<string,mixed>> $overrides rows with recurrence_instance_utc
     * @return list<array{row:array,start:\DateTimeImmutable,end:\DateTimeImmutable,instanceUtc:string}>
     */
    public function expand(array $master, array $overrides, \DateTimeImmutable $winStart, \DateTimeImmutable $winEnd): array
    {
        $result = [];
        $consumed = [];

        $ovByInstance = [];
        foreach ($overrides as $ov) {
            if (!empty($ov['recurrence_instance_utc'])) {
                $ovByInstance[(string) $ov['recurrence_instance_utc']] = $ov;
            }
        }

        if (empty($master['rrule'])) {
            $start = Time::fromDb((string) $master['start_utc']);
            $end = Time::fromDb((string) $master['end_utc']);
            if ($start < $winEnd && $end > $winStart) {
                $result[] = ['row' => $master, 'start' => $start, 'end' => $end, 'instanceUtc' => Time::toDb($start)];
            }
        } else {
            $exdates = [];
            if (!empty($master['exdates_json'])) {
                $decoded = is_array($master['exdates_json'])
                    ? $master['exdates_json']
                    : json_decode((string) $master['exdates_json'], true);
                if (is_array($decoded)) {
                    $exdates = array_fill_keys(array_map('strval', $decoded), true);
                }
            }
            $raw = ($this->expander)($master, $winStart, $winEnd);
            $count = 0;
            foreach ($raw as $inst) {
                if (++$count > self::MAX_INSTANCES) {
                    break;
                }
                $instanceUtc = Time::toDb($inst['start']);
                if (isset($exdates[$instanceUtc])) {
                    continue;
                }
                if (isset($ovByInstance[$instanceUtc])) {
                    $ov = $ovByInstance[$instanceUtc];
                    $consumed[$instanceUtc] = true;
                    $ovStart = Time::fromDb((string) $ov['start_utc']);
                    $ovEnd = Time::fromDb((string) $ov['end_utc']);
                    if ($ovStart < $winEnd && $ovEnd > $winStart) {
                        $result[] = ['row' => $ov, 'start' => $ovStart, 'end' => $ovEnd, 'instanceUtc' => $instanceUtc];
                    }
                    continue;
                }
                $result[] = ['row' => $master, 'start' => $inst['start'], 'end' => $inst['end'], 'instanceUtc' => $instanceUtc];
            }
        }

        foreach ($ovByInstance as $instanceUtc => $ov) {
            if (isset($consumed[$instanceUtc])) {
                continue;
            }
            $ovStart = Time::fromDb((string) $ov['start_utc']);
            $ovEnd = Time::fromDb((string) $ov['end_utc']);
            if ($ovStart < $winEnd && $ovEnd > $winStart) {
                $result[] = ['row' => $ov, 'start' => $ovStart, 'end' => $ovEnd, 'instanceUtc' => (string) $instanceUtc];
            }
        }

        return $result;
    }

    /** Validate an RRULE string; throws HttpError(invalid_rrule) on failure. Returns normalized form. */
    public static function validateRrule(string $rrule): string
    {
        $rrule = strtoupper(trim($rrule));
        if ($rrule === '') {
            throw HttpError::badRequest('Empty RRULE', 'invalid_rrule');
        }
        $parts = self::rruleParts($rrule);
        if (!isset($parts['FREQ']) || !in_array($parts['FREQ'], self::FREQS, true)) {
            throw HttpError::badRequest('RRULE must contain a valid FREQ', 'invalid_rrule');
        }
        foreach ($parts as $key => $value) {
            if (!in_array($key, self::ALLOWED_RRULE_KEYS, true)) {
                throw HttpError::badRequest("Unknown RRULE part: $key", 'invalid_rrule');
            }
            if ($value === '') {
                throw HttpError::badRequest("Empty RRULE value for $key", 'invalid_rrule');
            }
        }
        if (isset($parts['INTERVAL']) && (!ctype_digit($parts['INTERVAL']) || (int) $parts['INTERVAL'] < 1)) {
            throw HttpError::badRequest('Invalid RRULE INTERVAL', 'invalid_rrule');
        }
        if (isset($parts['COUNT']) && (!ctype_digit($parts['COUNT']) || (int) $parts['COUNT'] < 1)) {
            throw HttpError::badRequest('Invalid RRULE COUNT', 'invalid_rrule');
        }
        $normalized = self::joinParts($parts);
        if (class_exists(\Sabre\VObject\Recur\RRuleIterator::class)) {
            try {
                new \Sabre\VObject\Recur\RRuleIterator($normalized, new \DateTimeImmutable('2026-01-01', Time::utc()));
            } catch (\Throwable $e) {
                throw HttpError::badRequest('Invalid RRULE: ' . $e->getMessage(), 'invalid_rrule');
            }
        }
        return $normalized;
    }

    /** @return array<string,string> */
    public static function rruleParts(string $rrule): array
    {
        $parts = [];
        foreach (explode(';', strtoupper(trim($rrule))) as $piece) {
            if ($piece === '') {
                continue;
            }
            if (!str_contains($piece, '=')) {
                throw HttpError::badRequest("Malformed RRULE part: $piece", 'invalid_rrule');
            }
            [$k, $v] = explode('=', $piece, 2);
            $parts[trim($k)] = trim($v);
        }
        return $parts;
    }

    private static function joinParts(array $parts): string
    {
        $out = [];
        foreach ($parts as $k => $v) {
            $out[] = $k . '=' . $v;
        }
        return implode(';', $out);
    }

    /** Replace COUNT with an UNTIL bound (used for "following" splits). */
    public static function setUntil(string $rrule, \DateTimeImmutable $untilUtc, bool $allDay): string
    {
        $parts = self::rruleParts($rrule);
        unset($parts['COUNT']);
        $parts['UNTIL'] = $allDay
            ? $untilUtc->setTimezone(Time::utc())->format('Ymd')
            : $untilUtc->setTimezone(Time::utc())->format('Ymd\THis\Z');
        return self::joinParts($parts);
    }

    /** UNTIL bound for the old master when splitting a series before $instanceUtc. */
    public static function splitUntil(\DateTimeImmutable $instanceUtc): \DateTimeImmutable
    {
        return $instanceUtc->sub(new \DateInterval('PT1S'));
    }

    /** Event duration in seconds. */
    public static function durationSeconds(array $row): int
    {
        return max(0, Time::fromDb((string) $row['end_utc'])->getTimestamp() - Time::fromDb((string) $row['start_utc'])->getTimestamp());
    }

    /**
     * Default expander: sabre/vobject EventIterator, DTSTART in the event tzid.
     *
     * @return list<array{start:\DateTimeImmutable,end:\DateTimeImmutable}>
     */
    public static function sabreExpand(array $master, \DateTimeImmutable $winStart, \DateTimeImmutable $winEnd): array
    {
        if (!class_exists(\Sabre\VObject\Component\VCalendar::class)) {
            throw new \RuntimeException('sabre/vobject is not installed');
        }
        $allDay = (int) ($master['all_day'] ?? 0) === 1;
        $tz = Time::zone($master['tzid'] ?? null);
        $start = Time::fromDb((string) $master['start_utc'])->setTimezone($tz);
        $end = Time::fromDb((string) $master['end_utc'])->setTimezone($tz);
        $uid = (string) ($master['uid'] ?? 'bettercal-expand');

        $vcal = new \Sabre\VObject\Component\VCalendar();
        $vevent = $vcal->add('VEVENT', ['UID' => $uid]);
        if ($allDay) {
            $vevent->add('DTSTART', $start->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $end->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', $start);
            $vevent->add('DTEND', $end);
        }
        $vevent->add('RRULE', (string) $master['rrule']);

        $out = [];
        try {
            $it = new \Sabre\VObject\Recur\EventIterator($vcal, $uid, Time::utc());
            $it->fastForward(\DateTime::createFromImmutable($winStart));
            $count = 0;
            while ($it->valid() && $count < self::MAX_INSTANCES) {
                $occStart = \DateTimeImmutable::createFromInterface($it->getDtStart())->setTimezone(Time::utc());
                if ($occStart >= $winEnd) {
                    break;
                }
                $occEnd = \DateTimeImmutable::createFromInterface($it->getDtEnd())->setTimezone(Time::utc());
                $out[] = ['start' => $occStart, 'end' => $occEnd];
                $count++;
                $it->next();
            }
        } catch (\Throwable $e) {
            error_log('recurrence expansion failed for event ' . ($master['id'] ?? '?') . ': ' . $e->getMessage());
        }
        return $out;
    }
}
