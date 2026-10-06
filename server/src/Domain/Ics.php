<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Support\Limits;
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

    /**
     * Fold a content line at 75 octets, never splitting a UTF-8 sequence.
     *
     * Walks the line by OFFSET. The earlier version cut the head off and kept
     * `substr($line, $cut)` as the new line, which copies the whole remaining
     * tail on every 75-octet step: quadratic, measured at 117 ms for a 1 MiB
     * description, paid again on every feed and CalDAV export of that event
     * (BC-14). Each octet is now copied once.
     */
    public static function fold(string $line): string
    {
        $len = strlen($line);
        if ($len <= self::FOLD_WIDTH) {
            return $line;
        }
        $out = [];
        $pos = 0;
        $width = self::FOLD_WIDTH;
        while ($len - $pos > $width) {
            $cut = $pos + $width;
            // Back off a continuation byte (10xxxxxx) so a character stays whole.
            while ($cut > $pos + 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out[] = substr($line, $pos, $cut - $pos);
            $pos = $cut;
            $width = self::FOLD_WIDTH - 1; // continuation lines lose one octet to the leading space
        }
        $out[] = substr($line, $pos);
        return implode("\r\n ", $out);
    }

    /**
     * A value for a property that is NOT text-escaped (UID, URL, RRULE): every
     * control character removed, above all CR and LF.
     *
     * Text properties go through escape(), which turns a newline into the two
     * characters "\n". UID and URL were written raw, and a parser unescapes
     * "\n" in an incoming UID into a real newline, so an event named
     * "abc\nX-INJECTED:1" came back out of a feed or CalDAV export as an extra
     * property line Better-Cal never modelled (BC-16). Applied when such a
     * value comes in AND when it goes out, so rows stored before this are
     * covered too.
     */
    public static function structural(string $value): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $value);
    }

    /**
     * Why this calendar file is too much to parse, or null. Checked on the raw
     * text BEFORE a parser materializes anything, because the parser is where
     * the cost is: 3,000 tiny events in 329 KB became a 24 MiB object graph
     * (BC-12). Counts VEVENT openings; nested components do not start with it.
     */
    /** Content lines allowed per event in the budget: generous for real events with long descriptions, alarms and attendees. */
    public const LINES_PER_EVENT = 25;

    public static function budgetProblem(string $ics, int $maxBytes, int $maxEvents): ?string
    {
        $bytes = strlen($ics);
        if ($bytes > $maxBytes) {
            return 'The calendar file is ' . self::mib($bytes) . ', over the ' . self::mib($maxBytes) . ' limit';
        }
        // Counted on the unfolded text, the way the parser will read it: a
        // folded "BEGIN:VEV\r\n ENT" used to slip past the count (review of F8).
        // Every line ending the parser accepts (CRLF, LF, lone CR, extra CRs)
        // becomes one LF first, so "BEGIN:VEVENT\r\r\n" is counted too.
        $normalized = preg_replace('/\r*\n|\r+/', "\n", $ics) ?? $ics;
        $unfolded = preg_replace('/\n[ \t]/', '', $normalized) ?? $normalized;
        $events = preg_match_all('/^BEGIN:VEVENT[ \t]*$/mi', $unfolded);
        if ($events > $maxEvents) {
            return 'The calendar file holds ' . number_format((int) $events) . ' events, over the limit of ' . number_format($maxEvents);
        }
        // Parser memory grows with properties, not only events: one event
        // with a million lines is as heavy as a million events.
        $lines = substr_count($unfolded, "\n");
        $maxLines = $maxEvents * self::LINES_PER_EVENT;
        if ($lines > $maxLines) {
            return 'The calendar file holds ' . number_format($lines) . ' lines, over the limit of ' . number_format($maxLines);
        }
        return null;
    }

    /** The incoming UID made safe and bounded, or a fresh one when nothing usable is left. */
    public static function uidOrNew(string $uid): string
    {
        $uid = substr(self::structural(trim($uid)), 0, 255);
        return $uid !== '' ? $uid : \BetterCal\Support\Ids::ulid();
    }

    /** Cut to $max characters without splitting one. */
    public static function clip(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }

    private static function mib(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MiB';
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
        $out .= self::buildBody($events);
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
        $out .= self::buildBody($events);
        $out .= "END:VCALENDAR\r\n";
        return $out;
    }

    /**
     * The VTIMEZONEs the events use, then the events. A timed event goes out
     * in its own zone (audit, 0.9.14): written as UTC, a weekly 9:00 meeting
     * expanded in UTC elsewhere and moved an hour at every DST change, and a
     * CalDAV round trip stored it back as UTC for good. An override takes its
     * RECURRENCE-ID's type and zone from its series, so it still names the
     * series' own occurrence when it changed between all-day and timed.
     *
     * @param list<array<string,mixed>> $events
     */
    private static function buildBody(array $events): string
    {
        $masters = [];
        foreach ($events as $ev) {
            if (empty($ev['recurrence_instance_utc'])) {
                $masters[(string) ($ev['uid'] ?? '')] = $ev;
            }
        }
        $zones = [];
        foreach ($events as $ev) {
            $series = !empty($ev['recurrence_instance_utc']) ? ($masters[(string) ($ev['uid'] ?? '')] ?? $ev) : $ev;
            foreach ([$ev, $series] as $row) {
                $z = self::zoneOf($row);
                if ($z !== null) {
                    $zones[$z] = true;
                }
            }
        }
        $out = '';
        foreach (array_keys($zones) as $z) {
            $out .= self::vtimezone($z);
        }
        $stamp = Time::nowUtc()->format('Ymd\THis\Z');
        foreach ($events as $ev) {
            $series = !empty($ev['recurrence_instance_utc']) ? ($masters[(string) ($ev['uid'] ?? '')] ?? null) : null;
            $out .= self::buildEvent($ev, $stamp, $series);
        }
        return $out;
    }

    /** The zone a timed row is written in, or null for all-day and UTC rows (written as dates or Z). */
    private static function zoneOf(array $row): ?string
    {
        if ((int) ($row['all_day'] ?? 0) === 1) {
            return null;
        }
        $z = Time::normalizeTzid((string) ($row['tzid'] ?? 'UTC'));
        return $z === 'UTC' ? null : $z;
    }

    /** A date-time property value for a row's shape: a date, a zoned local time, or UTC. */
    private static function dateProp(string $name, string $utc, array $shape): string
    {
        $t = Time::fromDb($utc);
        if ((int) ($shape['all_day'] ?? 0) === 1) {
            return self::fold($name . ';VALUE=DATE:' . $t->setTimezone(Time::zone($shape['tzid'] ?? null))->format('Ymd')) . "\r\n";
        }
        $z = self::zoneOf($shape);
        return $z !== null
            ? self::fold($name . ';TZID=' . $z . ':' . $t->setTimezone(new \DateTimeZone($z))->format('Ymd\THis')) . "\r\n"
            : self::line($name, $t->format('Ymd\THis\Z'));
    }

    /**
     * A VTIMEZONE for an IANA zone, from PHP's zone data: its two yearly
     * changes as STANDARD/DAYLIGHT rules (nth or last weekday of a month, as
     * every zone with regular DST has them), one STANDARD for a zone without
     * DST, or the actual changes of the surrounding years for an irregular one.
     */
    private static function vtimezone(string $tzid): string
    {
        $zone = new \DateTimeZone($tzid);
        $year = (int) Time::nowUtc()->format('Y');
        $fmtOff = static fn(int $s): string => ($s < 0 ? '-' : '+') . sprintf('%02d%02d', intdiv(abs($s), 3600), intdiv(abs($s) % 3600, 60));
        $out = "BEGIN:VTIMEZONE\r\n" . self::line('TZID', $tzid);
        $all = $zone->getTransitions((new \DateTimeImmutable(($year - 1) . '-01-01', Time::utc()))->getTimestamp(), (new \DateTimeImmutable(($year + 6) . '-01-01', Time::utc()))->getTimestamp());
        $changes = array_values(array_slice($all ?: [], 1));
        $thisYear = array_values(array_filter($changes, static fn($t) => (int) gmdate('Y', $t['ts']) === $year));
        $component = static function (array $t, int $from, ?string $rrule, string $dtstart) use ($fmtOff): string {
            $kind = $t['isdst'] ? 'DAYLIGHT' : 'STANDARD';
            $c = "BEGIN:$kind\r\n" . self::line('DTSTART', $dtstart) . self::line('TZOFFSETFROM', $fmtOff($from))
                . self::line('TZOFFSETTO', $fmtOff((int) $t['offset']));
            if ($rrule !== null) {
                $c .= self::line('RRULE', $rrule);
            }
            if (!empty($t['abbr']) && preg_match('/^[A-Za-z]+$/', (string) $t['abbr'])) {
                $c .= self::line('TZNAME', (string) $t['abbr']);
            }
            return $c . "END:$kind\r\n";
        };
        if ($changes === []) {
            $off = (int) ($all[0]['offset'] ?? 0);
            $out .= "BEGIN:STANDARD\r\n" . self::line('DTSTART', '19700101T000000') . self::line('TZOFFSETFROM', $fmtOff($off))
                . self::line('TZOFFSETTO', $fmtOff($off)) . "END:STANDARD\r\n";
        } elseif (count($thisYear) === 2) {
            foreach ($changes as $i => $t) {
                $from = $i === 0 ? (int) $all[0]['offset'] : (int) $changes[$i - 1]['offset'];
                if ((int) gmdate('Y', $t['ts']) !== $year) {
                    continue;
                }
                // The wall-clock moment of the change, in the offset it leaves.
                $local = (new \DateTimeImmutable('@' . $t['ts']))->modify(($from >= 0 ? '+' : '-') . abs($from) . ' seconds');
                $day = (int) $local->format('j');
                $nth = $day + 7 > (int) $local->format('t') ? -1 : intdiv($day - 1, 7) + 1;
                $wd = strtoupper(substr($local->format('D'), 0, 2));
                $rrule = 'FREQ=YEARLY;BYMONTH=' . (int) $local->format('n') . ';BYDAY=' . $nth . $wd;
                $out .= $component($t, $from, $rrule, $local->format('Ymd\THis'));
            }
        } else {
            foreach ($changes as $i => $t) {
                $from = $i === 0 ? (int) $all[0]['offset'] : (int) $changes[$i - 1]['offset'];
                $local = (new \DateTimeImmutable('@' . $t['ts']))->modify(($from >= 0 ? '+' : '-') . abs($from) . ' seconds');
                $out .= $component($t, $from, null, $local->format('Ymd\THis'));
            }
        }
        return $out . "END:VTIMEZONE\r\n";
    }

    private static function buildEvent(array $ev, string $stamp, ?array $series = null): string
    {
        $out = "BEGIN:VEVENT\r\n";
        $out .= self::line('UID', self::structural((string) $ev['uid']));
        $out .= self::line('DTSTAMP', $stamp);
        $out .= self::dateProp('DTSTART', (string) $ev['start_utc'], $ev);
        $out .= self::dateProp('DTEND', (string) $ev['end_utc'], $ev);
        // RECURRENCE-ID and EXDATE take the series' shape (RFC 5545 wants the
        // value type of its DTSTART): dates for an all-day series, local times
        // in its zone for a timed one.
        if (!empty($ev['recurrence_instance_utc'])) {
            $out .= self::dateProp('RECURRENCE-ID', (string) $ev['recurrence_instance_utc'], $series ?? $ev);
        }
        if (!empty($ev['rrule'])) {
            $out .= self::line('RRULE', self::structural((string) $ev['rrule']));
        }
        $exdates = [];
        if (!empty($ev['exdates_json'])) {
            $decoded = is_array($ev['exdates_json']) ? $ev['exdates_json'] : json_decode((string) $ev['exdates_json'], true);
            if (is_array($decoded)) {
                $exdates = $decoded;
            }
        }
        foreach ($exdates as $ex) {
            $out .= self::dateProp('EXDATE', (string) $ex, $ev);
        }
        $out .= self::line('SUMMARY', self::escape((string) ($ev['title'] ?? '')));
        if (!empty($ev['description'])) {
            // Rich (HTML) descriptions export as plain text in DESCRIPTION
            // plus HTML in X-ALT-DESC (the de facto rich-text field,
            // understood by Outlook and Apple Calendar). Plain text
            // descriptions export exactly as before.
            //
            // Feed and Google descriptions are stored as they arrived, so
            // both fields are made inert here, the one door every export
            // (feeds, CalDAV, files) goes out through (#58, review D-02):
            // the HTML through the same allowlist the app shows it with,
            // the text with no markup left, not even markup that was only
            // entity-escaped in the source (some clients, Google among
            // them, read DESCRIPTION as HTML).
            $description = (string) $ev['description'];
            if (Sanitize::isHtml($description)) {
                $out .= self::line('DESCRIPTION', self::escape(Sanitize::inertText($description)));
                $html = Sanitize::html($description);
                if ($html !== '') {
                    $out .= self::fold('X-ALT-DESC;FMTTYPE=text/html:' . self::escape($html)) . "\r\n";
                }
            } else {
                $out .= self::line('DESCRIPTION', self::escape($description));
            }
        }
        if (!empty($ev['location'])) {
            $out .= self::line('LOCATION', self::escape((string) $ev['location']));
        }
        if (!empty($ev['url'])) {
            $out .= self::line('URL', self::structural((string) $ev['url']));
        }
        // Trip relationships (RFC 5545 RELATED-TO): containers list member
        // uids as RELTYPE=CHILD, members list container uids as
        // RELTYPE=PARENT. Callers opt in by decorating rows with
        // related_children / related_parents uid lists (Trips::relatedUidMap);
        // undecorated rows write nothing. Import ignores RELATED-TO (parse()).
        foreach (['related_children' => 'CHILD', 'related_parents' => 'PARENT'] as $key => $reltype) {
            foreach (is_array($ev[$key] ?? null) ? $ev[$key] : [] as $relUid) {
                $out .= self::line('RELATED-TO;RELTYPE=' . $reltype, self::escape((string) $relUid));
            }
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
     * RELATED-TO properties are deliberately ignored on import for now: trip
     * membership is user-local metadata created only through the API (see
     * docs/design-containers.md), so inbound feeds and file imports never
     * create or mutate event_links rows.
     *
     * @return list<array<string,mixed>> keys: uid, title, description, location, url,
     *   start_utc, end_utc, all_day, tzid, rrule, exdates (list), status,
     *   recurrence_instance_utc, reminders (list of {minutes} from display VALARMs)
     */
    public static function parse(string $ics, ?string $floatingTzid = null): array
    {
        if (!class_exists(\Sabre\VObject\Reader::class)) {
            throw new \RuntimeException('sabre/vobject is not installed');
        }
        $vcal = \Sabre\VObject\Reader::read($ics, \Sabre\VObject\Reader::OPTION_FORGIVING | \Sabre\VObject\Reader::OPTION_IGNORE_INVALID_LINES);
        // A floating time ("9:00", no zone) means 9:00 where the calendar is:
        // the calendar's own X-WR-TIMEZONE when it names one, else the zone
        // the caller knows (the owner's Home), else UTC. Read as UTC, it moved
        // by the owner's whole offset.
        $wr = isset($vcal->{'X-WR-TIMEZONE'}) ? trim((string) $vcal->{'X-WR-TIMEZONE'}) : '';
        $floatName = $wr !== '' && Time::normalizeTzid($wr) !== 'UTC' ? Time::normalizeTzid($wr)
            : ($floatingTzid !== null && $floatingTzid !== '' ? Time::normalizeTzid($floatingTzid) : 'UTC');
        $events = [];
        foreach ($vcal->select('VEVENT') as $vevent) {
            $parsed = self::parseVevent($vevent, $floatName);
            if ($parsed !== null) {
                $events[] = $parsed;
            }
        }
        return $events;
    }

    /**
     * A date or date-time property as UTC instants. DATE values are read
     * literally as UTC midnights (sabre would apply a stray TZID to them);
     * floating date-times in $readTz; zoned ones in their own zone.
     *
     * @return list<\DateTimeImmutable>
     */
    private static function utcInstants(object $prop, ?\DateTimeZone $readTz): array
    {
        if ($prop->getValueType() === 'DATE') {
            $out = [];
            foreach ($prop->getParts() as $part) {
                $digits = substr((string) preg_replace('/\D/', '', (string) $part), 0, 8);
                $d = \DateTimeImmutable::createFromFormat('!Ymd', $digits, Time::utc());
                if ($d === false) {
                    throw new \InvalidArgumentException('bad date: ' . $part);
                }
                $out[] = $d;
            }
            return $out;
        }
        return array_map(
            static fn($dt) => \DateTimeImmutable::createFromInterface($dt)->setTimezone(Time::utc()),
            $prop->getDateTimes($readTz)
        );
    }

    private static function parseVevent(object $vevent, string $floatName = 'UTC'): ?array
    {
        if (!isset($vevent->DTSTART)) {
            return null;
        }
        $dtstart = $vevent->DTSTART;
        $isDate = $dtstart->getValueType() === 'DATE';
        $tzid = 'UTC';
        $param = $dtstart['TZID'] ?? null;
        $floating = !$isDate && $param === null && method_exists($dtstart, 'isFloating') && $dtstart->isFloating();
        // DATE values stay UTC midnights (the import convention); a floating
        // time is read in $floatName; a zoned time in its own zone.
        $readTz = $floating ? Time::zone($floatName) : ($isDate ? Time::utc() : null);
        if ($isDate) {
            // A date is a date: UTC midnight, labelled UTC, even when a sender
            // attaches a TZID to it (not allowed on DATE values, but seen).
        } elseif ($param !== null) {
            $tzid = Time::normalizeTzid((string) $param);
            // Not an IANA name (Outlook's "Pacific Standard Time", a custom
            // VTIMEZONE id): sabre has already resolved it to a real zone, so
            // keep that one. Flattened to UTC, a repeating 9:00 meeting moved
            // an hour at every DST change.
            if ($tzid === 'UTC' && !in_array(strtoupper(trim((string) $param)), ['UTC', 'Z', 'GMT', 'ETC/UTC', 'ETC/GMT', 'ETC/UCT', 'UCT'], true)) {
                try {
                    $resolved = $dtstart->getDateTime()->getTimezone()->getName();
                    if (str_contains($resolved, '/') && Time::normalizeTzid($resolved) !== 'UTC') {
                        $tzid = Time::normalizeTzid($resolved);
                    }
                } catch (\Exception) {
                    // keep UTC
                }
            }
        } elseif ($floating) {
            $tzid = $floatName;
        }

        try {
            $start = self::utcInstants($dtstart, $readTz)[0];
        } catch (\Throwable) {
            return null;
        }

        if (isset($vevent->DTEND)) {
            $end = self::utcInstants($vevent->DTEND, $readTz)[0];
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

        // Every path that turns ICS into rows comes through here (feeds,
        // imports, CalDAV, mail): the RRULE is made safe once, at the door.
        $rrule = isset($vevent->RRULE) ? Recurrence::safeRrule((string) $vevent->RRULE) : null;

        $exdates = [];
        if (isset($vevent->EXDATE)) {
            foreach ($vevent->select('EXDATE') as $exProp) {
                foreach (self::utcInstants($exProp, $readTz) as $exDt) {
                    $exdates[] = $exDt->format(Time::DB);
                }
            }
        }

        $recurrenceInstance = null;
        if (isset($vevent->{'RECURRENCE-ID'})) {
            try {
                $recurrenceInstance = self::utcInstants($vevent->{'RECURRENCE-ID'}, $readTz)[0]->format(Time::DB);
            } catch (\Throwable) {
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
            // UID and URL are structural: control characters (a parser hands us
            // "\n" in a UID as a real newline) are dropped on the way IN, see
            // structural(). An empty result is no UID at all.
            'uid' => self::uidOrNew(isset($vevent->UID) ? (string) $vevent->UID : ''),
            'title' => mb_substr((string) ($vevent->SUMMARY ?? ''), 0, 500),
            // Long fields are cut, not refused: one absurd event in a feed must
            // not stop the rest of it syncing (Limits::DESCRIPTION_CHARS).
            'description' => isset($vevent->DESCRIPTION) ? self::clip((string) $vevent->DESCRIPTION, Limits::get('DESCRIPTION_CHARS')) : null,
            'location' => isset($vevent->LOCATION) ? mb_substr((string) $vevent->LOCATION, 0, 500) : null,
            'url' => isset($vevent->URL) ? (self::clip(self::structural((string) $vevent->URL), Limits::get('URL_CHARS')) ?: null) : null,
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
