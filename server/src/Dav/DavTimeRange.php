<?php

declare(strict_types=1);

namespace BetterCal\Dav;

use BetterCal\Domain\Ics;
use BetterCal\Domain\Recurrence;
use BetterCal\Support\ExpansionBudget;
use BetterCal\Support\WorkBudgetExceeded;
use BetterCal\Support\Time;
use Sabre\VObject;

/**
 * A CalDAV time range (RFC 4791 9.9) read by the app's own expander.
 *
 * Which occurrences a series has comes from Recurrence::expand, the entry
 * point the calendar's window reads, so a client asking for a range gets the
 * objects the window shows in it: the series' readings of gap days and
 * repeated hours, its skipped days, and its edited days at the times they were
 * moved to. Whether one occurrence is in the range is 9.9's table for VEVENT,
 * read over the occurrence's start and end as stored: an end after the start
 * (DTEND, DTSTART plus a positive DURATION, or an all-day day) overlaps when
 * it starts before the range ends and ends after it starts; an occurrence
 * with no length is in the range when it starts at or after the range's start
 * and before its end. A missing start or end is an open side.
 */
final class DavTimeRange
{
    /** The first window looked at for an occurrence; each next one is twice as long. */
    private const FIRST_STEP_SECONDS = 31 * 86400;

    /** RFC 4791 9.9 for one occurrence: [$start, $end) against the range. */
    public static function overlaps(
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?\DateTimeInterface $rangeStart,
        ?\DateTimeInterface $rangeEnd,
    ): bool {
        $s = $start->getTimestamp();
        $e = $end->getTimestamp();
        if ($rangeEnd !== null && $s >= $rangeEnd->getTimestamp()) {
            return false;
        }
        if ($rangeStart === null) {
            return true;
        }
        $rs = $rangeStart->getTimestamp();
        return $e > $s ? $e > $rs : $s >= $rs;
    }

    /**
     * Every occurrence of one object (its series row and its edited days) in
     * the range, as Recurrence::expand gives them. $first stops at the first
     * window holding one, for a caller that only asks whether there is any.
     *
     * The range is read in windows the expander's cap (MAX_INSTANCES per
     * series per window) cannot cut: a window it cut is read again as two
     * halves, and each next window after a whole one is twice as long, so
     * every window kept is complete and the answer is every occurrence in
     * the range, however many, the calendar's window reading the same rows.
     * A window never grows back to a length that was cut.
     *
     * $budget is the request's (ExpansionBudget, as the calendar's window
     * charges it): the object is one series, and every occurrence each
     * window read is charged, a cut window's too, so a range past it throws
     * WorkBudgetExceeded and is refused by the caller (refusal()), never cut.
     *
     * @param array<string,mixed> $master
     * @param list<array<string,mixed>> $overrides
     * @return list<array{row:array,start:\DateTimeImmutable,end:\DateTimeImmutable,instanceUtc:string}>
     */
    public static function occurrences(
        array $master,
        array $overrides,
        ?\DateTimeInterface $rangeStart,
        ?\DateTimeInterface $rangeEnd,
        bool $first = false,
        ?ExpansionBudget $budget = null,
    ): array {
        // The expander's window keeps what ends after its start, so it opens
        // one second early to hold an occurrence with no length at the
        // range's start; overlaps() then decides.
        $from = $rangeStart === null ? new \DateTimeImmutable('0001-01-01 00:00:00', Time::utc()) : self::utc($rangeStart)->modify('-1 second');
        $to = $rangeEnd === null ? new \DateTimeImmutable('9999-12-31 23:59:59', Time::utc()) : self::utc($rangeEnd);
        $out = [];
        // Nothing of the object starts before its series' stored start or
        // its earliest edited day, so the windows start there at the
        // earliest, not at the open side of a range.
        $earliest = Time::fromDb((string) $master['start_utc']);
        foreach ($overrides as $ov) {
            $earliest = min($earliest, Time::fromDb((string) $ov['start_utc']));
        }
        $from = max($from, $earliest->modify('-1 second'));
        $step = self::FIRST_STEP_SECONDS;
        $ceiling = PHP_INT_MAX; // the shortest window cut so far
        if (!empty($master['rrule'])) {
            $budget?->beginSeries();
        }
        while ($from < $to) {
            $until = $to->getTimestamp() - $from->getTimestamp() > $step ? $from->modify('+' . $step . ' seconds') : $to;
            // A Recurrence of its own per window, so capped() says whether
            // this window was cut.
            $window = new Recurrence();
            $occs = $window->expand($master, $overrides, $from, $until);
            $span = $until->getTimestamp() - $from->getTimestamp();
            $cut = $window->capped() !== [];
            // Each window's work is bounded by the cap, so it is charged
            // after it is read.
            $budget?->occurrence($cut ? Recurrence::MAX_INSTANCES : count($occs));
            if ($cut && $span > 1) {
                $ceiling = $span;
                $step = intdiv($span, 2);
                continue;
            }
            foreach ($occs as $occ) {
                if (self::overlaps($occ['start'], $occ['end'], $rangeStart, $rangeEnd) && !isset($out[$occ['instanceUtc']])) {
                    $out[$occ['instanceUtc']] = $occ;
                }
            }
            if ($first && $out !== []) {
                break;
            }
            $from = $until;
            $step = $span * 2 < $ceiling ? $span * 2 : $span;
        }
        $out = array_values($out);
        usort($out, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        return $out;
    }

    /**
     * The VEVENT time ranges of a parsed calendar-query filter, and the
     * filter without them for the library's validator. A time range on a
     * VEVENT comp-filter directly under VCALENDAR is the one this class
     * answers; every other part (property and text matches, VTODO and
     * VJOURNAL, a time range on a VALARM or on a property) stays in the rest.
     *
     * @param array<string,mixed> $filters as Sabre\CalDAV's query parser makes them
     * @return array{0:array<string,mixed>,1:list<array{start:?\DateTimeInterface,end:?\DateTimeInterface}>}
     */
    public static function split(array $filters): array
    {
        $ranges = [];
        foreach ($filters['comp-filters'] ?? [] as $i => $filter) {
            if (strtoupper((string) ($filter['name'] ?? '')) !== 'VEVENT' || !empty($filter['is-not-defined']) || empty($filter['time-range'])) {
                continue;
            }
            $ranges[] = ['start' => $filter['time-range']['start'] ?? null, 'end' => $filter['time-range']['end'] ?? null];
            $filters['comp-filters'][$i]['time-range'] = false;
        }
        return [$filters, $ranges];
    }

    /**
     * Whether a filter asks nothing every object here doesn't already have:
     * a VCALENDAR holding a VEVENT, with no other condition. Every object
     * this backend serves is that.
     *
     * @param array<string,mixed> $filters
     */
    public static function holdsForEveryEvent(array $filters): bool
    {
        if (strtoupper((string) ($filters['name'] ?? '')) !== 'VCALENDAR' || !empty($filters['is-not-defined']) || !empty($filters['prop-filters']) || !empty($filters['time-range'])) {
            return false;
        }
        foreach ($filters['comp-filters'] ?? [] as $filter) {
            if (strtoupper((string) ($filter['name'] ?? '')) !== 'VEVENT' || !empty($filter['is-not-defined'])
                || !empty($filter['comp-filters']) || !empty($filter['prop-filters']) || !empty($filter['time-range'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * RFC 4791 9.6.5's expanded form of one calendar object this backend
     * served: each occurrence in the range as its own VEVENT, with its
     * RECURRENCE-ID and no RRULE or EXDATE, every date-time in UTC and all-day
     * dates kept as dates. The occurrences are the expander's, read from the
     * object as a PUT of it is read (Ics::parse, DavIcs::eventColumns), and an
     * edited day carries its own VEVENT's properties.
     */
    public static function expandObject(string $data, \DateTimeInterface $rangeStart, \DateTimeInterface $rangeEnd, ?ExpansionBudget $budget = null): string
    {
        $data = (string) preg_replace("/\r?\n/", "\r\n", $data);
        $master = null;
        $overrides = [];
        foreach (Ics::parse($data, 'UTC') as $parsed) {
            $cols = DavIcs::eventColumns($parsed) + ['id' => 0];
            if ($cols['recurrence_instance_utc'] === null) {
                $master ??= $cols;
            } else {
                $overrides[] = $cols;
            }
        }
        $source = VObject\Reader::read($data);
        $children = [];
        foreach ($source->children() as $child) {
            if ($child instanceof VObject\Property && $child->name !== 'PRODID') {
                $children[] = clone $child;
            }
        }
        if ($master !== null) {
            $masterEvent = null;
            $edited = [];
            foreach ($source->select('VEVENT') as $vevent) {
                if (isset($vevent->{'RECURRENCE-ID'})) {
                    $edited[Time::toDb(self::utc($vevent->{'RECURRENCE-ID'}->getDateTime(Time::utc())))] = $vevent;
                } else {
                    $masterEvent ??= $vevent;
                }
            }
            $repeats = !empty($master['rrule']);
            foreach (self::occurrences($master, $overrides, $rangeStart, $rangeEnd, false, $budget) as $occ) {
                $from = $edited[$occ['instanceUtc']] ?? $masterEvent;
                if ($from === null) {
                    continue;
                }
                $children[] = self::instance($from, $occ, $repeats);
            }
        }
        $out = new VObject\Component\VCalendar($children);
        $text = $out->serialize();
        $source->destroy();
        $out->destroy();
        return $text;
    }

    /**
     * A report whose occurrences are past the request's expansion budget,
     * refused as a whole (403, DAV:number-of-matches-within-limits) with
     * the budget's own words, never answered short.
     */
    public static function refusal(WorkBudgetExceeded $e): \Sabre\DAV\Exception\TooManyMatches
    {
        return new \Sabre\DAV\Exception\TooManyMatches(
            'This report has too many events or repeating occurrences to answer safely (' . $e->getMessage() . '). Ask for a shorter time range.'
        );
    }

    /** One occurrence as its own VEVENT. @param array{row:array,start:\DateTimeImmutable,end:\DateTimeImmutable,instanceUtc:string} $occ */
    private static function instance(VObject\Component $from, array $occ, bool $repeats): VObject\Component
    {
        $event = clone $from;
        foreach (['RRULE', 'RDATE', 'EXDATE', 'EXRULE', 'DURATION', 'DTEND', 'RECURRENCE-ID'] as $name) {
            $event->remove($name);
        }
        $allDay = (int) ($occ['row']['all_day'] ?? 0) === 1;
        $date = static fn(\DateTimeImmutable $at): \DateTimeImmutable => new \DateTimeImmutable($at->setTimezone(Time::utc())->format('Y-m-d') . ' 00:00:00', Time::utc());
        $value = static fn(\DateTimeImmutable $at): \DateTimeImmutable => $allDay ? $date($at) : $at->setTimezone(Time::utc());
        $params = $allDay ? ['VALUE' => 'DATE'] : [];
        $event->remove('DTSTART');
        $event->add('DTSTART', $value($occ['start']), $params);
        $event->add('DTEND', $value($occ['end']), $params);
        if ($repeats) {
            $event->add('RECURRENCE-ID', $value(Time::fromDb($occ['instanceUtc'])), $params);
        }
        self::toUtc($event);
        return $event;
    }

    /** Every other date-time in the component (and its alarms) in UTC, as 9.6.5 asks. */
    private static function toUtc(VObject\Component $component): void
    {
        foreach ($component->children() as $child) {
            if ($child instanceof VObject\Property\ICalendar\DateTime && $child->hasTime() && !$child->isFloating()) {
                $all = $child->getDateTimes();
                $all[0] = $all[0]->setTimezone(Time::utc());
                $child->setDateTimes($all);
            } elseif ($child instanceof VObject\Component) {
                self::toUtc($child);
            }
        }
    }

    private static function utc(\DateTimeInterface $at): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(Time::utc());
    }
}
