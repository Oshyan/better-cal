<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Support\Time;

/**
 * ICS text primitives (escape/fold, pure PHP, testable without deps),
 * outbound VCALENDAR generation, and inbound parsing via sabre/vobject.
 */
final class Ics
{
    public const PRODID = '-//Better-Cal//Better-Cal 0.1//EN';
    private const FOLD_WIDTH = 75;

    public static function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $text);
    }

    public static function unescape(string $text): string
    {
        $out = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $next = $text[$i + 1];
                $out .= match ($next) {
                    'n', 'N' => "\n",
                    default => $next,
                };
                $i++;
            } else {
                $out .= $ch;
            }
        }
        return $out;
    }

    /** Fold a content line at 75 octets, never splitting a UTF-8 sequence. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= self::FOLD_WIDTH) {
            return $line;
        }
        $out = [];
        $width = self::FOLD_WIDTH;
        while (strlen($line) > $width) {
            $cut = $width;
            while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
            $width = self::FOLD_WIDTH - 1; // continuation lines lose one octet to the leading space
        }
        $out[] = $line;
        return implode("\r\n ", $out);
    }

    public static function unfold(string $ics): string
    {
        return preg_replace('/\r?\n[ \t]/', '', $ics);
    }

    private static function line(string $name, string $value): string
    {
        return self::fold($name . ':' . $value) . "\r\n";
    }

    /**
     * Build a VCALENDAR from event rows. Recurring masters keep their RRULE
     * (not expanded); override rows carry RECURRENCE-ID.
     *
     * @param list<array<string,mixed>> $events DB event rows
     */
    public static function buildCalendar(string $name, ?string $description, array $events): string
    {
        $out = "BEGIN:VCALENDAR\r\n";
        $out .= self::line('VERSION', '2.0');
        $out .= self::line('PRODID', self::PRODID);
        $out .= self::line('CALSCALE', 'GREGORIAN');
        $out .= self::line('X-WR-CALNAME', self::escape($name));
        if ($description !== null && $description !== '') {
            $out .= self::line('X-WR-CALDESC', self::escape($description));
        }
        $stamp = Time::nowUtc()->format('Ymd\THis\Z');
        foreach ($events as $ev) {
            $out .= self::buildEvent($ev, $stamp);
        }
        $out .= "END:VCALENDAR\r\n";
        return $out;
    }

    /**
     * Build a single CalDAV calendar-object body: a bare VCALENDAR (no X-WR-
     * metadata) wrapping one uid's VEVENTs (master first, then RECURRENCE-ID
     * overrides).
     *
     * @param list<array<string,mixed>> $events DB event rows sharing one uid
     */
    public static function buildObject(array $events): string
    {
        $out = "BEGIN:VCALENDAR\r\n";
        $out .= self::line('VERSION', '2.0');
        $out .= self::line('PRODID', self::PRODID);
        $out .= self::line('CALSCALE', 'GREGORIAN');
        $stamp = Time::nowUtc()->format('Ymd\THis\Z');
        foreach ($events as $ev) {
            $out .= self::buildEvent($ev, $stamp);
        }
        $out .= "END:VCALENDAR\r\n";
        return $out;
    }

    private static function buildEvent(array $ev, string $stamp): string
    {
        $allDay = (int) ($ev['all_day'] ?? 0) === 1;
        $tz = Time::zone($ev['tzid'] ?? null);
        $out = "BEGIN:VEVENT\r\n";
        $out .= self::line('UID', (string) $ev['uid']);
        $out .= self::line('DTSTAMP', $stamp);
        if ($allDay) {
            $start = Time::fromDb((string) $ev['start_utc'])->setTimezone($tz);
            $end = Time::fromDb((string) $ev['end_utc'])->setTimezone($tz);
            $out .= self::fold('DTSTART;VALUE=DATE:' . $start->format('Ymd')) . "\r\n";
            $out .= self::fold('DTEND;VALUE=DATE:' . $end->format('Ymd')) . "\r\n";
        } else {
            $out .= self::line('DTSTART', Time::fromDb((string) $ev['start_utc'])->format('Ymd\THis\Z'));
            $out .= self::line('DTEND', Time::fromDb((string) $ev['end_utc'])->format('Ymd\THis\Z'));
        }
        if (!empty($ev['recurrence_instance_utc'])) {
            $out .= self::line('RECURRENCE-ID', Time::fromDb((string) $ev['recurrence_instance_utc'])->format('Ymd\THis\Z'));
        }
        if (!empty($ev['rrule'])) {
            $out .= self::line('RRULE', (string) $ev['rrule']);
        }
        $exdates = [];
        if (!empty($ev['exdates_json'])) {
            $decoded = is_array($ev['exdates_json']) ? $ev['exdates_json'] : json_decode((string) $ev['exdates_json'], true);
            if (is_array($decoded)) {
                $exdates = $decoded;
            }
        }
        foreach ($exdates as $ex) {
            $out .= self::line('EXDATE', Time::fromDb((string) $ex)->format('Ymd\THis\Z'));
        }
        $out .= self::line('SUMMARY', self::escape((string) ($ev['title'] ?? '')));
        if (!empty($ev['description'])) {
            // Rich (HTML) descriptions export as plain text in DESCRIPTION
            // plus the original HTML in X-ALT-DESC (the de facto rich-text
            // field, understood by Outlook and Apple Calendar). Plain text
            // descriptions export exactly as before.
            $description = (string) $ev['description'];
            if (Sanitize::isHtml($description)) {
                $out .= self::line('DESCRIPTION', self::escape(Sanitize::toText($description)));
                $out .= self::fold('X-ALT-DESC;FMTTYPE=text/html:' . self::escape($description)) . "\r\n";
            } else {
                $out .= self::line('DESCRIPTION', self::escape($description));
            }
        }
        if (!empty($ev['location'])) {
            $out .= self::line('LOCATION', self::escape((string) $ev['location']));
        }
        if (!empty($ev['url'])) {
            $out .= self::line('URL', (string) $ev['url']);
        }
        $status = strtoupper((string) ($ev['status'] ?? 'confirmed'));
        if (in_array($status, ['CONFIRMED', 'TENTATIVE', 'CANCELLED'], true)) {
            $out .= self::line('STATUS', $status);
        }
        // Explicit per-event reminder overrides export as display VALARMs;
        // inherited defaults (NULL) and explicit-none ([]) write nothing.
        if (!empty($ev['reminders_json'])) {
            $reminders = is_array($ev['reminders_json'])
                ? $ev['reminders_json']
                : json_decode((string) $ev['reminders_json'], true);
            if (is_array($reminders)) {
                foreach ($reminders as $entry) {
                    if (!is_array($entry) || !isset($entry['minutes']) || !is_numeric($entry['minutes'])) {
                        continue;
                    }
                    $out .= "BEGIN:VALARM\r\n";
                    $out .= self::line('ACTION', 'DISPLAY');
                    $out .= self::line('DESCRIPTION', 'Reminder');
                    $out .= self::line('TRIGGER', self::formatTrigger((int) $entry['minutes']));
                    $out .= "END:VALARM\r\n";
                }
            }
        }
        $out .= "END:VEVENT\r\n";
        return $out;
    }

    // ---- VALARM trigger mapping (pure) --------------------------------

    /**
     * Parse an iCalendar duration TRIGGER into minutes before start, or null
     * when it is not a before-start relative trigger (absolute date-times and
     * after-start durations are ignored).
     */
    public static function parseTriggerMinutes(string $trigger): ?int
    {
        $trigger = strtoupper(trim($trigger));
        if (preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $trigger, $m) !== 1) {
            return null; // absolute DATE-TIME triggers (or garbage)
        }
        $minutes = (int) ($m[2] ?? 0) * 10080
            + (int) ($m[3] ?? 0) * 1440
            + (int) ($m[4] ?? 0) * 60
            + (int) ($m[5] ?? 0)
            + intdiv((int) ($m[6] ?? 0) + 30, 60);
        if ($minutes === 0) {
            return 0; // PT0S: at start, sign irrelevant
        }
        return ($m[1] ?? '') === '-' ? $minutes : null; // positive = after start
    }

    /** Minutes-before-start as an iCalendar duration TRIGGER value. */
    public static function formatTrigger(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'PT0S';
        }
        if ($minutes % 1440 === 0) {
            return '-P' . intdiv($minutes, 1440) . 'D';
        }
        $h = intdiv($minutes % 1440, 60);
        $mi = $minutes % 60;
        $days = intdiv($minutes, 1440);
        $out = '-P' . ($days > 0 ? $days . 'D' : '') . 'T';
        if ($h > 0) {
            $out .= $h . 'H';
        }
        if ($mi > 0) {
            $out .= $mi . 'M';
        }
        return $out;
    }

    /**
     * Parse an ICS document into normalized event arrays via sabre/vobject.
     * Handles VTIMEZONE, DATE values, RRULE, EXDATE and RECURRENCE-ID.
     *
     * @return list<array<string,mixed>> keys: uid, title, description, location, url,
     *   start_utc, end_utc, all_day, tzid, rrule, exdates (list), status,
     *   recurrence_instance_utc, reminders (list of {minutes} from display VALARMs)
     */
    public static function parse(string $ics): array
    {
        if (!class_exists(\Sabre\VObject\Reader::class)) {
            throw new \RuntimeException('sabre/vobject is not installed');
        }
        $vcal = \Sabre\VObject\Reader::read($ics, \Sabre\VObject\Reader::OPTION_FORGIVING | \Sabre\VObject\Reader::OPTION_IGNORE_INVALID_LINES);
        $events = [];
        foreach ($vcal->select('VEVENT') as $vevent) {
            $parsed = self::parseVevent($vevent);
            if ($parsed !== null) {
                $events[] = $parsed;
            }
        }
        return $events;
    }

    private static function parseVevent(object $vevent): ?array
    {
        if (!isset($vevent->DTSTART)) {
            return null;
        }
        $dtstart = $vevent->DTSTART;
        $isDate = $dtstart->getValueType() === 'DATE';
        $tzid = 'UTC';
        $param = $dtstart['TZID'] ?? null;
        if ($param !== null) {
            $tzid = Time::normalizeTzid((string) $param);
        }

        try {
            $start = \DateTimeImmutable::createFromInterface($dtstart->getDateTime())->setTimezone(Time::utc());
        } catch (\Exception) {
            return null;
        }

        if (isset($vevent->DTEND)) {
            $end = \DateTimeImmutable::createFromInterface($vevent->DTEND->getDateTime())->setTimezone(Time::utc());
        } elseif (isset($vevent->DURATION)) {
            try {
                $end = $start->add(new \DateInterval((string) $vevent->DURATION));
            } catch (\Exception) {
                $end = $start->add(new \DateInterval($isDate ? 'P1D' : 'PT1H'));
            }
        } else {
            $end = $start->add(new \DateInterval($isDate ? 'P1D' : 'PT1H'));
        }
        if ($end <= $start) {
            $end = $start->add(new \DateInterval($isDate ? 'P1D' : 'PT1H'));
        }

        // All-day heuristic: DATE value, or full-day-aligned span of >= 23h.
        $allDay = $isDate;
        if (!$allDay) {
            $seconds = $end->getTimestamp() - $start->getTimestamp();
            $localStart = $start->setTimezone(Time::zone($tzid));
            if ($seconds >= 23 * 3600 && $seconds % 86400 <= 3600 && $localStart->format('His') === '000000') {
                $allDay = true;
            }
        }

        $rrule = isset($vevent->RRULE) ? strtoupper((string) $vevent->RRULE) : null;

        $exdates = [];
        if (isset($vevent->EXDATE)) {
            foreach ($vevent->select('EXDATE') as $exProp) {
                foreach ($exProp->getDateTimes() as $exDt) {
                    $exdates[] = \DateTimeImmutable::createFromInterface($exDt)->setTimezone(Time::utc())->format(Time::DB);
                }
            }
        }

        $recurrenceInstance = null;
        if (isset($vevent->{'RECURRENCE-ID'})) {
            try {
                $recurrenceInstance = \DateTimeImmutable::createFromInterface($vevent->{'RECURRENCE-ID'}->getDateTime())
                    ->setTimezone(Time::utc())->format(Time::DB);
            } catch (\Exception) {
                $recurrenceInstance = null;
            }
        }

        $status = strtolower((string) ($vevent->STATUS ?? 'confirmed'));
        if (!in_array($status, ['confirmed', 'tentative', 'cancelled'], true)) {
            $status = 'confirmed';
        }

        // Display alarms with before-start relative triggers map to reminder
        // offsets; audio/email alarms, absolute triggers and RELATED=END are
        // dropped (nothing in the model expresses them).
        $reminders = [];
        foreach ($vevent->select('VALARM') as $alarm) {
            $action = strtoupper(trim((string) ($alarm->ACTION ?? 'DISPLAY')));
            if ($action !== '' && $action !== 'DISPLAY') {
                continue;
            }
            $trigger = $alarm->TRIGGER ?? null;
            if ($trigger === null) {
                continue;
            }
            $related = $trigger['RELATED'] ?? null;
            if ($related !== null && strtoupper((string) $related) === 'END') {
                continue;
            }
            $minutes = self::parseTriggerMinutes((string) $trigger);
            if ($minutes !== null && $minutes <= Reminders::MAX_MINUTES) {
                $reminders[$minutes] = true;
            }
        }
        ksort($reminders);
        $reminders = array_map(static fn(int $m): array => ['minutes' => $m], array_keys($reminders));

        return [
            'uid' => isset($vevent->UID) ? substr((string) $vevent->UID, 0, 255) : \BetterCal\Support\Ids::ulid(),
            'title' => mb_substr((string) ($vevent->SUMMARY ?? ''), 0, 500),
            'description' => isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
            'location' => isset($vevent->LOCATION) ? mb_substr((string) $vevent->LOCATION, 0, 500) : null,
            'url' => isset($vevent->URL) ? (string) $vevent->URL : null,
            'start_utc' => $start->format(Time::DB),
            'end_utc' => $end->format(Time::DB),
            'all_day' => $allDay ? 1 : 0,
            'tzid' => $tzid,
            'rrule' => $rrule,
            'exdates' => array_values(array_unique($exdates)),
            'status' => $status,
            'recurrence_instance_utc' => $recurrenceInstance,
            'reminders' => $reminders,
        ];
    }
}
