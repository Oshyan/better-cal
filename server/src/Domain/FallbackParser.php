<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Support\Time;

/**
 * Deterministic natural-language event parser, used when the LLM is
 * unavailable or fails. Pure function of (text, tz, now); no I/O.
 */
final class FallbackParser
{
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];
    private const WEEKDAYS = [
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
        'thursday' => 4, 'friday' => 5, 'saturday' => 6,
    ];
    /** Short day names (#33), read only where they can't be a word ("Sun Valley"). */
    private const WEEKDAY_ABBR = [
        'sun' => 0, 'mon' => 1, 'tue' => 2, 'tues' => 2, 'wed' => 3,
        'thu' => 4, 'thur' => 4, 'thurs' => 4, 'fri' => 5, 'sat' => 6,
    ];
    private const NUMBER_WORDS = [
        'a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6,
        'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12,
    ];
    /**
     * Zones typed after a time ("9:40am EST", #33). Each names the place's
     * clock, so EST in July still means Eastern time as people use it.
     */
    private const ZONE_ABBR = [
        'EST' => 'America/New_York', 'EDT' => 'America/New_York', 'ET' => 'America/New_York',
        'CST' => 'America/Chicago', 'CDT' => 'America/Chicago', 'CT' => 'America/Chicago',
        'MST' => 'America/Denver', 'MDT' => 'America/Denver', 'MT' => 'America/Denver',
        'PST' => 'America/Los_Angeles', 'PDT' => 'America/Los_Angeles', 'PT' => 'America/Los_Angeles',
        'AKST' => 'America/Anchorage', 'AKDT' => 'America/Anchorage', 'HST' => 'Pacific/Honolulu',
        'GMT' => 'UTC', 'UTC' => 'UTC', 'BST' => 'Europe/London', 'CET' => 'Europe/Paris', 'CEST' => 'Europe/Paris',
    ];

    /**
     * `complete` is internal quality metadata for QuickAdd's LLM-skip decision
     * (date resolved AND (time resolved OR all-day)); it is stripped before the
     * draft reaches the API response.
     *
     * `rrule` is the repeat the text asked for ("every Tuesday until Dec 15"),
     * or null; the first occurrence is the event's start (#165).
     *
     * `reminders` is minutes before the start the text asked to be alerted
     * ("alert 30 minutes", "remind me ... to"), or null (#33).
     *
     * @return array{title:string,start:string,end:string,allDay:bool,location:?string,personNames:list<string>,rrule:?string,reminders:?list<int>,confidence:float,source:string,complete:bool,dateFound:bool,timeFound:bool}
     */
    public static function parse(string $text, string $tzid, ?\DateTimeImmutable $now = null): array
    {
        $tz = Time::zone($tzid);
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        $work = ' ' . trim($text) . ' ';
        // "tonight" carries both a date and a vague time; normalize it so the
        // two extractors each pick up their half.
        $work = preg_replace('/\btonight\b/i', ' today evening ', $work) ?? $work;
        $confidence = 0.3;

        // Explicit "all day" / "all-day" keyword forces an all-day event.
        $allDayKeyword = false;
        if (preg_match('/\ball[\s-]day\b/i', $work, $m)) {
            $allDayKeyword = true;
            $work = self::cut($work, $m[0]);
        }

        // Alerts (#33): "alert 30 minutes", "with a 1 hour reminder", and
        // "Remind me ... to call the bank" (an event with an alert at its
        // time, until there are reminders of their own). Before the duration,
        // which "30 minutes" would otherwise be.
        [$work, $reminders, $remindMe] = self::extractReminders($work);

        // Repeats first: "every Tuesday", "until Dec 15" and "for 6 weeks"
        // would otherwise read as a date, a range or a duration.
        $repeat = RepeatParser::extract($work, $now);
        if ($repeat !== null) {
            $work = $repeat['work'];
        }

        // Duration: "for 2 hours", "for 30 min"
        $durationMin = null;
        if (preg_match('/\bfor\s+(\d+(?:\.\d+)?)\s*(hours?|hrs?|hr|h|minutes?|mins?|min|m)\b/i', $work, $m)) {
            $n = (float) $m[1];
            $durationMin = str_starts_with(strtolower($m[2]), 'h') ? (int) round($n * 60) : (int) round($n);
            $durationMin = max(1, $durationMin);
            $work = self::cut($work, $m[0]);
            $confidence += 0.05;
        }

        // A repeat that names its own days ("every Tuesday", "the 15th")
        // leaves a lone weekday to the title: in "Report every Tuesday about
        // Friday sales" Friday is a word, not the start.
        $ownDays = $repeat !== null && (isset($repeat['rule']['BYDAY']) || isset($repeat['rule']['BYMONTHDAY']));
        // "starting Aug 12", "from Jan 21 to May 23" for a single event (#33);
        // with a repeat they are its bounds (RepeatParser).
        [$work, $startOn, $startRangeEnd] = $repeat === null ? self::startPhrase($work, $now) : [$work, null, null];
        [$work, $date, $dateFound, $rangeEnd] = self::extractDate($work, $now, !$ownDays);
        if ($startOn !== null) {
            $date = $startOn;
            $dateFound = true;
            $rangeEnd = $startRangeEnd ?? $rangeEnd;
        }
        [$work, $startTime, $endTime, $timeFound, $timeZone] = self::extractTime($work);
        // A length in days or longer ("3 weeks long", "for 90 days",
        // "week-long", #33): a multi-day event from its start.
        $span = null;
        if ($repeat === null && $rangeEnd === null) {
            [$work, $span] = self::extractSpan($work);
        }
        $repeatDayGiven = false;
        if ($repeat !== null) {
            // A range with a repeat is the series' start and end, not one
            // multi-day event ("every Monday Jan 5 to Feb 23").
            if ($rangeEnd !== null) {
                $repeat['until'] ??= $rangeEnd;
                $rangeEnd = null;
            }
            if (!$timeFound && !$allDayKeyword && $repeat['time'] !== null) {
                $startTime = $repeat['time']; // "every morning"
                $timeFound = true;
            }
            $repeatDayGiven = $repeat['startOn'] !== null || $dateFound;
            $date = RepeatParser::firstOn($repeat['rule'], $repeat['startOn'] ?? ($dateFound ? $date : $now));
            $dateFound = true;
        }
        if ($allDayKeyword || $rangeEnd !== null) {
            $timeFound = false; // "all day" / a date range wins over a stray time
        }

        // Trailing "at <location>" (times were already removed, so a remaining
        // "at ..." tail is a place, not a time — the time pattern always wins).
        // Multi-word locations are kept intact; a trailing with-clause is not
        // part of the location ("Dinner at Luna with Sam" -> "Luna").
        // The place also STAYS in the title (0.6.2): "Lunch at The Captain's Table"
        // is what the event is called, the way a with-clause stays; only the
        // date and time phrases leave it. "@" reads as "at" there.
        $location = null;
        $locPattern = '/\s(?:at|@)\s+([^,]+?)\s*(?=$|\bwith\s|\bw\/)/i';
        if (preg_match($locPattern, $work, $m) && !preg_match('/^\d/', trim($m[1]))) {
            $location = trim($m[1]);
            $work = preg_replace('/\s@\s+/', ' at ', $work, 1) ?? $work;
            $confidence += 0.05;
        }

        // People: "with <phrase>" / "w/ <phrase>". The clause STAYS in the
        // title (the title is the input minus date/time phrases only), while
        // personNames parses the clause: split on commas/and/&,
        // title-cased; a leading article stays part of a group name
        // ("the Examples" -> "The Examples"), a bare article is never a name.
        $personNames = [];
        if (preg_match('/\b(?:with|w\/)\s*(.+?)(?=\s(?:at|@)\s|$)/iu', $work, $m)) {
            $personNames = self::parsePeople($m[1]);
            if ($personNames !== []) {
                $confidence += 0.05;
            }
        }

        $title = trim(preg_replace('/\s+/', ' ', $work), " \t\n\r,.-@");
        if ($title === '') {
            $title = 'New event';
            $confidence -= 0.1;
        } else {
            // Sentence-case: uppercase the first letter only, so a leading
            // article never turns the title into Title Case ("the standup"
            // -> "The standup", never "The Standup").
            $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
        }

        // "Remind me tomorrow at 3pm to call the bank": the event is "Call
        // the bank".
        if ($remindMe && preg_match('/^to\s+(.+)$/i', $title, $m) === 1) {
            $title = mb_strtoupper(mb_substr($m[1], 0, 1)) . mb_substr($m[1], 1);
        }

        $allDay = false;
        if ($rangeEnd !== null) {
            // Multi-day all-day range, end exclusive.
            $allDay = true;
            $start = $date->setTime(0, 0);
            $end = $rangeEnd->setTime(0, 0)->add(new \DateInterval('P1D'));
        } elseif ($timeFound) {
            $day = $dateFound ? $date : $now;
            $start = $day->setTime($startTime[0], $startTime[1]);
            // A typed zone: that clock's time on that day, shown on ours.
            $zoneOf = $timeZone !== null ? Time::zone($timeZone) : null;
            if ($zoneOf !== null) {
                $start = (new \DateTimeImmutable($start->format('Y-m-d H:i'), $zoneOf))->setTimezone($tz);
            }
            if (!$dateFound && $start <= $now) {
                $start = $start->add(new \DateInterval('P1D'));
            }
            if ($endTime !== null) {
                $minutes = ($endTime[0] * 60 + $endTime[1]) - ($startTime[0] * 60 + $startTime[1]);
                $end = $start->add(new \DateInterval('PT' . ($minutes > 0 ? $minutes : $minutes + 1440) . 'M'));
            } elseif ($span !== null) {
                $end = $start->modify('+' . $span['n'] . ' ' . $span['unit']);
            } else {
                $end = $start->add(new \DateInterval('PT' . ($durationMin ?? 60) . 'M'));
            }
        } elseif ($span !== null) {
            // "Lisbon trip 2 weeks": whole days, from the date or today.
            $allDay = true;
            $start = ($dateFound ? $date : $now)->setTime(0, 0);
            $end = $start->modify('+' . $span['n'] . ' ' . $span['unit']);
            $dateFound = true;
        } elseif ($dateFound || $allDayKeyword) {
            $allDay = true;
            $day = $dateFound ? $date : $now;
            $start = $day->setTime(0, 0);
            $end = $start->add(new \DateInterval('P1D'));
        } else {
            // Next round hour, 1h default duration.
            $start = $now->setTime((int) $now->format('G'), 0)->add(new \DateInterval('PT1H'));
            $end = $start->add(new \DateInterval('PT' . ($durationMin ?? 60) . 'M'));
        }

        // A series with no day given starts at its next occurrence, so
        // "standup every weekday at 9am" typed at 10 starts tomorrow.
        $rrule = null;
        if ($repeat !== null) {
            if (!$repeatDayGiven && !$allDay && $start <= $now) {
                $minutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
                $start = RepeatParser::firstOn($repeat['rule'], $start->modify('+1 day'));
                $end = $start->add(new \DateInterval('PT' . $minutes . 'M'));
            }
            $rrule = RepeatParser::rrule($repeat, $start, $allDay);
        }

        if ($dateFound) {
            $confidence += 0.25;
        }
        if ($timeFound) {
            $confidence += 0.25;
        } elseif ($allDay && $dateFound) {
            $confidence += 0.2; // resolved as an all-day date (implicit, keyword, or range)
        }

        // Leftover temporal words in the title mean a phrase was only half
        // understood ("Lunch day after" from "day after tomorrow" before that
        // compound existed) — stop claiming completeness so smart mode
        // consults the LLM, whose answer still passes the merge-guard.
        $suspect = preg_match(
            '/\b(?:after|before|from\s+now|following|week\s+of|first|second|third|fourth|last|every|other)\b/i',
            $title
        ) === 1;
        if ($suspect) {
            $confidence = min($confidence, 0.5);
        }

        return [
            'title' => $title,
            'start' => Time::iso($start),
            'end' => Time::iso($end),
            'allDay' => $allDay,
            'location' => $location,
            'personNames' => $personNames,
            'rrule' => $rrule,
            'reminders' => $reminders,
            'confidence' => round(min(0.95, max(0.05, $confidence)), 2),
            'source' => 'fallback',
            'complete' => $dateFound && ($timeFound || $allDay) && !$suspect,
            // Internal signal for QuickAdd's LLM merge-guard: an explicit date
            // in the text means a past start may be intentional.
            'dateFound' => $dateFound,
            // Whether the text named a time ("all day" or a date range wins
            // over a stray one); QuickAdd reports both as the draft's `when`.
            'timeFound' => $timeFound,
        ];
    }

    /**
     * @return array{0:string,1:\DateTimeImmutable,2:bool,3:?\DateTimeImmutable}
     *         [remaining text, start date, found?, inclusive range end date]
     */
    private static function extractDate(string $work, \DateTimeImmutable $now, bool $weekdays = true): array
    {
        $date = $now;
        $found = false;
        $monthAlt = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
        $sep = '\s*(?:[-–—]|\b(?:to|through|until)\b)\s*';

        // Date range: "June 1-12", "Aug 3 to Aug 7", "Dec 30 to Jan 2, 2026".
        // Lookahead keeps "June 1 to 5pm" (a time tail) out of the range path.
        if (preg_match(
            '/\b(' . $monthAlt . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?' . $sep
            . '(?:(' . $monthAlt . ')\.?\s+)?(\d{1,2})(?:st|nd|rd|th)?(?!\s*(?:am|pm|[:\d]))(?:,?\s*(\d{4}))?\b/i',
            $work,
            $m
        )) {
            $m1 = self::MONTHS[strtolower(substr($m[1], 0, 3))] ?? null;
            $m2 = ($m[3] ?? '') !== '' ? (self::MONTHS[strtolower(substr($m[3], 0, 3))] ?? null) : $m1;
            $year = ($m[5] ?? '') !== '' ? (int) $m[5] : null;
            if ($m1 !== null && $m2 !== null) {
                $y = $year ?? (int) $now->format('Y');
                $start = self::mkDate($now, $y, $m1, (int) $m[2]);
                $end = self::mkDate($now, $m2 < $m1 ? $y + 1 : $y, $m2, (int) $m[4]);
                if ($start !== null && $end !== null && $end >= $start) {
                    if ($year === null && $start < $now->setTime(0, 0)) {
                        $start = $start->modify('+1 year');
                        $end = $end->modify('+1 year');
                    }
                    return [self::cut($work, $m[0]), $start, true, $end];
                }
            }
        }
        // ISO: 2026-07-31
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $work, $m)) {
            $candidate = self::mkDate($now, (int) $m[1], (int) $m[2], (int) $m[3]);
            if ($candidate !== null) {
                return [self::cut($work, $m[0]), $candidate, true, null];
            }
        }
        // Slash: 7/31 or 7/31/2026
        if (preg_match('#\b(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?\b#', $work, $m)) {
            $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
            if ($year !== null && $year < 100) {
                $year += 2000;
            }
            $candidate = self::mkDate($now, $year ?? (int) $now->format('Y'), (int) $m[1], (int) $m[2]);
            if ($candidate !== null) {
                if ($year === null && $candidate < $now->setTime(0, 0)) {
                    $candidate = $candidate->modify('+1 year');
                }
                return [self::cut($work, $m[0]), $candidate, true, null];
            }
        }
        // Month name: "July 31", "Jul 31, 2026", "31 July"
        if (preg_match('/\b(' . $monthAlt . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s*(\d{4}))?\b/i', $work, $m)
            || preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(' . $monthAlt . ')\b(?:,?\s*(\d{4}))?/i', $work, $m2)
        ) {
            if (isset($m2) && $m2 !== [] && (!isset($m[0]) || $m === [])) {
                $m = [$m2[0], $m2[2], $m2[1], $m2[3] ?? ''];
            }
            $monthNum = self::MONTHS[strtolower(substr($m[1], 0, 3))] ?? null;
            if ($monthNum !== null) {
                $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
                $candidate = self::mkDate($now, $year ?? (int) $now->format('Y'), $monthNum, (int) $m[2]);
                if ($candidate !== null) {
                    if ($year === null && $candidate < $now->setTime(0, 0)) {
                        $candidate = $candidate->modify('+1 year');
                    }
                    return [self::cut($work, $m[0]), $candidate, true, null];
                }
            }
        }
        $dayAltAll = implode('|', array_keys(self::WEEKDAYS));
        $numAlt = '\d{1,3}|' . implode('|', array_keys(self::NUMBER_WORDS));
        $num = static fn(string $n): int => ctype_digit($n) ? (int) $n : self::NUMBER_WORDS[strtolower($n)];
        $today = $now->setTime(0, 0);
        // Relative (#33): "in 2 weeks", "3 days from now", "2 weeks from
        // Monday". Before the plain weekday, which "from Monday" would be.
        if (preg_match('/\bin\s+(' . $numAlt . ')\s+(day|week|month)s?\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), $now->modify('+' . $num($m[1]) . ' ' . strtolower($m[2])), true, null];
        }
        if (preg_match('/\b(' . $numAlt . ')\s+(day|week|month)s?\s+(?:from|after)\s+(now|today|tomorrow|' . $dayAltAll . ')\b/i', $work, $m)) {
            $base = strtolower($m[3]);
            $from = match ($base) {
                'now', 'today' => $now,
                'tomorrow' => $now->add(new \DateInterval('P1D')),
                default => $now->add(new \DateInterval('P' . ((self::WEEKDAYS[$base] - (int) $now->format('w') + 7) % 7 ?: 7) . 'D')),
            };
            return [self::cut($work, $m[0]), $from->modify('+' . $num($m[1]) . ' ' . strtolower($m[2])), true, null];
        }
        // "Friday after next": a week past the coming Friday.
        if (preg_match('/\b(?:the\s+)?(' . $dayAltAll . ')\s+after\s+next\b/i', $work, $m)) {
            $diff = (self::WEEKDAYS[strtolower($m[1])] - (int) $now->format('w') + 7) % 7 ?: 7;
            return [self::cut($work, $m[0]), $now->add(new \DateInterval('P' . ($diff + 7) . 'D')), true, null];
        }
        // "end of the month", "end of next month": its last day.
        if (preg_match('/\b(?:at\s+)?(?:the\s+)?end\s+of\s+(?:the\s+|this\s+)?(next\s+)?month\b/i', $work, $m)) {
            $month = ($m[1] ?? '') !== '' ? $today->modify('first day of next month') : $today;
            return [self::cut($work, $m[0]), $month->modify('last day of this month'), true, null];
        }
        // "this weekend", "next weekend": Saturday and Sunday.
        if (preg_match('/\b(this|next|the)\s+weekend\b/i', $work, $m)) {
            $w = (int) $now->format('w');
            $sat = $w === 0 ? $today->sub(new \DateInterval('P1D')) : $today->add(new \DateInterval('P' . (6 - $w) . 'D'));
            if (strtolower($m[1]) === 'next') $sat = $sat->add(new \DateInterval('P7D'));
            $first = $sat < $today ? $today : $sat;
            return [self::cut($work, $m[0]), $first, true, $sat->add(new \DateInterval('P1D'))];
        }
        // "all week next week", "next week all week": Monday to Sunday.
        if (preg_match('/\ball\s+week\b/i', $work, $all) && preg_match('/\b(this|next)\s+week\b(?!\s+(?:on\s+)?(?:' . $dayAltAll . '))/i', $work, $m)) {
            $monday = $today->sub(new \DateInterval('P' . (((int) $now->format('w') + 6) % 7) . 'D'));
            if (strtolower($m[1]) === 'next') $monday = $monday->add(new \DateInterval('P7D'));
            $work = self::cut(self::cut($work, $all[0]), $m[0]);
            return [$work, $monday < $today ? $today : $monday, true, $monday->add(new \DateInterval('P6D'))];
        }
        // "the 15th", "on the 3rd": the next day with that number.
        if (preg_match('/\b(?:on\s+)?the\s+(\d{1,2})(?:st|nd|rd|th)\b(?!\s+(?:of\s+)?(?:' . $monthAlt . '|every|each|the\s+month)\b)/i', $work, $m)) {
            $d = (int) $m[1];
            for ($i = 0; $i < 13; $i++) {
                $month = $today->modify('first day of this month')->modify('+' . $i . ' month');
                $candidate = self::mkDate($now, (int) $month->format('Y'), (int) $month->format('n'), $d);
                if ($candidate !== null && $candidate->setTime(0, 0) >= $today) {
                    return [self::cut($work, $m[0]), $candidate, true, null];
                }
            }
        }
        // Compounds BEFORE their fragments, or "day after tomorrow" would
        // match as "tomorrow" and "a week from Friday" as "Friday".
        if (preg_match('/\b(?:the\s+)?day\s+after\s+tomorrow\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), $now->add(new \DateInterval('P2D')), true, null];
        }
        // "a week from Friday" / "week from tomorrow": the base day plus 7.
        if (preg_match('/\b(?:a\s+)?week\s+from\s+(today|tomorrow|' . $dayAltAll . ')\b/i', $work, $m)) {
            $base = strtolower($m[1]);
            if ($base === 'today') {
                $day = $now;
            } elseif ($base === 'tomorrow') {
                $day = $now->add(new \DateInterval('P1D'));
            } else {
                $diff = (self::WEEKDAYS[$base] - (int) $now->format('w') + 7) % 7;
                $day = $now->add(new \DateInterval('P' . $diff . 'D'));
            }
            return [self::cut($work, $m[0]), $day->add(new \DateInterval('P7D')), true, null];
        }
        // "first Monday of September" / "last Friday in October" (nth weekday
        // of a month; rolls to next year when the whole month is past).
        if (preg_match(
            '/\b(first|second|third|fourth|last)\s+(' . $dayAltAll . ')\s+(?:of|in)\s+(' . $monthAlt . ')\b(?:,?\s*(\d{4}))?/i',
            $work,
            $m
        )) {
            $monthNum = self::MONTHS[strtolower(substr($m[3], 0, 3))] ?? null;
            if ($monthNum !== null) {
                $year = ($m[4] ?? '') !== '' ? (int) $m[4] : (int) $now->format('Y');
                $candidate = self::nthWeekdayOfMonth($now, $year, $monthNum, self::WEEKDAYS[strtolower($m[2])], strtolower($m[1]));
                if ($candidate !== null) {
                    if (($m[4] ?? '') === '' && $candidate < $now->setTime(0, 0)) {
                        $candidate = self::nthWeekdayOfMonth($now, $year + 1, $monthNum, self::WEEKDAYS[strtolower($m[2])], strtolower($m[1]));
                    }
                    if ($candidate !== null) {
                        return [self::cut($work, $m[0]), $candidate, true, null];
                    }
                }
            }
        }
        // today / tomorrow
        if (preg_match('/\btoday\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), $now, true, null];
        }
        if (preg_match('/\btomorrow\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), $now->add(new \DateInterval('P1D')), true, null];
        }
        // "yesterday" is an explicit (past) date: logging something that
        // already happened is legitimate, and marking dateFound keeps the
        // LLM merge-guard from "correcting" it into the future.
        if (preg_match('/\byesterday\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), $now->sub(new \DateInterval('P1D')), true, null];
        }
        $dayAlt = implode('|', array_keys(self::WEEKDAYS));
        // "next week thursday": the named weekday within the next calendar week
        // (weeks start Monday). Checked before the bare-weekday branch below.
        if (!$weekdays) {
            return [$work, $date, $found, null];
        }
        if (preg_match('/\bnext\s+week(?:\s+(?:on\s+)?(' . $dayAlt . '))\b/i', $work, $m)) {
            $target = self::WEEKDAYS[strtolower($m[1])];
            $todayW = (int) $now->format('w');
            $daysToNextMonday = (1 - $todayW + 7) % 7 ?: 7;
            $offsetInWeek = ($target - 1 + 7) % 7; // Monday-based position
            return [self::cut($work, $m[0]), $now->add(new \DateInterval('P' . ($daysToNextMonday + $offsetInWeek) . 'D')), true, null];
        }
        // Weekday with optional next/this. "this"/bare = next occurrence (today
        // counts); "next" = strictly future occurrence (1-7 days out). Right
        // after "to" or "at" a weekday names a place ("Ferry to Friday
        // Harbor", "Drinks at Sunday Social"), so the first weekday not in
        // that spot is the date.
        // Short names ("Fri", "Sat") too, but not where they start a name
        // ("Sun Valley", "Sat Nav": followed by a capitalized word), in a
        // day range ("Mon-Fri"), or as the first word unless a time follows
        // ("Sat exam prep" vs "Sat 10am brunch"); a full name wins over them.
        $abbrAlt = implode('|', array_keys(self::WEEKDAY_ABBR));
        preg_match_all('/\b(?:(next|this)\s+)?(' . $dayAlt . '|(?:' . $abbrAlt . ')\.?(?!\s+(?-i:[A-Z])))(?![\w\'])/i', $work, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $pick = null;
        foreach ($all as $m) {
            $before = substr($work, 0, $m[0][1]);
            if (preg_match('/\b(?:to|at)\s+$/i', $before) === 1) {
                continue;
            }
            if (!isset(self::WEEKDAYS[strtolower($m[2][0])])) {
                $after = substr($work, $m[0][1] + strlen($m[0][0]));
                if (preg_match('/[-–—]\s*$/u', $before) === 1 || preg_match('/^\s*[-–—]\s*\w/u', $after) === 1
                    || (trim($before) === '' && $m[1][0] === '' && preg_match('/^\s*(?:at\s+)?\d/i', $after) !== 1)) {
                    continue;
                }
                $pick ??= $m;
                continue;
            }
            $pick = $m;
            break;
        }
        if ($pick !== null) {
            $name = strtolower(rtrim($pick[2][0], '.'));
            $target = self::WEEKDAYS[$name] ?? self::WEEKDAY_ABBR[$name];
            $todayW = (int) $now->format('w');
            $diff = ($target - $todayW + 7) % 7;
            if (strtolower($pick[1][0] ?? '') === 'next' && $diff === 0) {
                $diff = 7;
            }
            // Cut at the match itself, not an earlier copy of the same word
            // ("Meet at Sat Nam Yoga Sat 10am").
            $rest = substr($work, 0, $pick[0][1]) . ' ' . substr($work, $pick[0][1] + strlen($pick[0][0]));
            return [$rest, $now->add(new \DateInterval('P' . $diff . 'D')), true, null];
        }

        return [$work, $date, $found, null];
    }

    /**
     * The date that opens $text ("Nov 3 at 7pm", "Jan 21 to May 23",
     * "Friday"), for RepeatParser's start and end phrases: the longest run of
     * up to six leading words that extractDate reads in full. `length` counts
     * from the start of $text.
     *
     * @return null|array{date:\DateTimeImmutable,length:int,rangeEnd:?\DateTimeImmutable}
     */
    public static function leadingDate(string $text, \DateTimeImmutable $now): ?array
    {
        preg_match_all('/\S+/', $text, $words, PREG_OFFSET_CAPTURE);
        $best = null;
        foreach (array_slice($words[0], 0, 6) as [$word, $at]) {
            $length = $at + strlen($word);
            [$rest, $date, $found, $rangeEnd] = self::extractDate(' ' . substr($text, 0, $length) . ' ', $now);
            if ($found && trim($rest, " \t,.") === '') {
                $best = ['date' => $date, 'length' => $length, 'rangeEnd' => $rangeEnd];
            }
        }
        return $best;
    }

    /**
     * A time, a time range, or a time-of-day word, with a zone typed right
     * after it ("9:40am EST") as [4].
     *
     * @return array{0:string,1:?array{0:int,1:int},2:?array{0:int,1:int},3:bool,4:?string}
     */
    private static function extractTime(string $work): array
    {
        $zoneAlt = '(?:\s*\b(' . implode('|', array_keys(self::ZONE_ABBR)) . ')\b)?';
        $zone = static fn(array $m, int $i): ?string => isset($m[$i]) && $m[$i] !== '' ? self::ZONE_ABBR[$m[$i]] : null;
        // Range with meridiem: "7-9pm", "7pm-9pm", "7:30pm to 9pm"
        if (preg_match('/\b(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*(?:-|–|—|to|until)\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b' . $zoneAlt . '/i', $work, $m)) {
            $mer2 = strtolower($m[6]);
            $mer1 = strtolower($m[3] ?? '') ?: $mer2;
            $start = self::to24((int) $m[1], (int) ($m[2] ?: 0), $mer1);
            $end = self::to24((int) $m[4], (int) ($m[5] ?: 0), $mer2);
            if ($start[0] * 60 + $start[1] >= $end[0] * 60 + $end[1] && $start[0] >= 12) {
                $start[0] -= 12; // "11-1pm" style: pull start back to am
            }
            return [self::cut($work, $m[0]), $start, $end, true, $zone($m, 7)];
        }
        // 24h range: "19:00-21:30"
        if (preg_match('/\b(?:at\s+)?(\d{1,2}):(\d{2})\s*(?:-|–|—|to|until)\s*(\d{1,2}):(\d{2})\b' . $zoneAlt . '/', $work, $m)) {
            return [self::cut($work, $m[0]), [(int) $m[1], (int) $m[2]], [(int) $m[3], (int) $m[4]], true, $zone($m, 5)];
        }
        // Single time with meridiem: "7pm", "7:30 pm", "at 2pm"
        if (preg_match('/\b(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b' . $zoneAlt . '/i', $work, $m)) {
            return [self::cut($work, $m[0]), self::to24((int) $m[1], (int) ($m[2] ?: 0), strtolower($m[3])), null, true, $zone($m, 4)];
        }
        // "h:mm" with no am/pm: 24-hour, except that 1:00 to 7:59 written
        // without a leading zero means the afternoon ("at 2:30" is 2:30 PM,
        // #33), unless the text says morning ("Breakfast at 7:30");
        // "07:30" and "19:00" are taken as written.
        $morning = preg_match('/\b(?:morning|breakfast|sunrise|dawn)\b/i', $work) === 1;
        if (preg_match('/\b(?:at\s+)?(\d{1,2}):(\d{2})\b' . $zoneAlt . '/', $work, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h <= 23 && $min <= 59) {
                if ($h >= 1 && $h <= 7 && $m[1][0] !== '0' && !$morning) $h += 12;
                return [self::cut($work, $m[0]), [$h, $min], null, true, $zone($m, 3)];
            }
        }
        // "at 1", "at 11": an hour on its own, after "at" and before the end
        // or another part of the phrase (never "at 5 Main St"). 1 to 7 is the
        // afternoon (unless the text says morning), 8 to 11 the morning, 12
        // noon.
        if (preg_match('/\bat\s+(\d{1,2})\b' . $zoneAlt . '(?=\s*(?:$|,|;|\b(?:with|w\/|for|on|in|at|tomorrow|today|tonight|next|this|every)\b))/i', $work, $m)) {
            $h = (int) $m[1];
            if ($h >= 1 && $h <= 12) {
                return [self::cut($work, $m[0]), [$h <= 7 && !$morning ? $h + 12 : $h, 0], null, true, $zone($m, 2)];
            }
        }
        if (preg_match('/\b(?:at\s+)?noon\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), [12, 0], null, true, null];
        }
        if (preg_match('/\b(?:at\s+)?midnight\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), [0, 0], null, true, null];
        }
        // Vague time-of-day words get sensible defaults — but only when
        // clearly meant as a time ("this evening", "in the morning", or
        // trailing as in "call mom Sunday evening"), so a title like
        // "Night hike Friday" keeps its noun and stays all-day.
        $vague = ['morning' => [9, 0], 'afternoon' => [14, 0], 'evening' => [18, 0], 'night' => [20, 0]];
        if (preg_match('/\b(?:in\s+the\s+|this\s+)(morning|afternoon|evening|night)\b/i', $work, $m)
            || preg_match('/\b(morning|afternoon|evening|night)\s*$/i', $work, $m)
        ) {
            return [self::cut($work, $m[0]), $vague[strtolower($m[1])], null, true, null];
        }
        return [$work, null, null, false, null];
    }

    /**
     * Alerts in the text: [remaining text, minutes before (or null), whether
     * it began "remind me"].
     *
     * @return array{0:string,1:?list<int>,2:bool}
     */
    private static function extractReminders(string $work): array
    {
        $unit = '(minutes?|mins?|min|m|hours?|hrs?|hr|h|days?|d)';
        $minutes = static function (string $n, string $u): int {
            $u = strtolower($u);
            return (int) $n * (str_starts_with($u, 'd') ? 1440 : (str_starts_with($u, 'h') ? 60 : 1));
        };
        $out = [];
        if (preg_match('/\b(?:alert|remind(?:er)?|notify)(?:\s+me)?\s+(\d{1,4})\s*' . $unit . '\b(?:\s+(?:before|early|earlier|ahead|prior))?/i', $work, $m)
            || preg_match('/\b(?:with\s+)?(?:an?\s+)?(\d{1,4})[\s-]*' . $unit . '\s+(?:alert|reminder|warning|notice)\b/i', $work, $m)
        ) {
            $out[] = $minutes($m[1], $m[2]);
            $work = self::cut($work, $m[0]);
        }
        $remindMe = false;
        if (preg_match('/^\s*remind\s+me\b\s*/i', $work, $m)) {
            $remindMe = true;
            $work = ' ' . substr($work, strlen($m[0]));
            if ($out === []) $out[] = 0; // an alert at the event's time
        }
        return [$work, $out === [] ? null : $out, $remindMe];
    }

    /**
     * "starting Aug 12", "from Jan 21 to May 23", "beginning Monday" for a
     * single event: [remaining text, start date, inclusive end date]. Not
     * the "from" of "3 days from now", which extractDate reads.
     *
     * @return array{0:string,1:?\DateTimeImmutable,2:?\DateTimeImmutable}
     */
    private static function startPhrase(string $work, \DateTimeImmutable $now): array
    {
        preg_match_all('/\b(?:from|starting(?:\s+(?:on|from))?|beginning(?:\s+on)?)\s+/i', $work, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $numAlt = '\d{1,3}|' . implode('|', array_keys(self::NUMBER_WORDS));
        foreach ($all as $m) {
            $at = $m[0][1];
            $rest = substr($work, $at + strlen($m[0][0]));
            if (preg_match('/^from\s/i', $m[0][0]) === 1
                && preg_match('/\b(?:' . $numAlt . ')\s+(?:days?|weeks?|months?)\s+$/i', substr($work, 0, $at)) === 1
                && preg_match('/^(?:now|today|tomorrow|' . implode('|', array_keys(self::WEEKDAYS)) . ')\b/i', $rest) === 1) {
                continue; // "3 days from now", read by extractDate
            }
            $first = self::leadingDate($rest, $now);
            if ($first === null) {
                continue;
            }
            $len = strlen($m[0][0]) + $first['length'];
            $end = $first['rangeEnd'];
            if ($end === null && preg_match('/^\s*(?:to|until|till|through|thru|-|–|—)\s*/i', substr($rest, $first['length']), $t)) {
                $second = self::leadingDate(substr($rest, $first['length'] + strlen($t[0])), $now);
                if ($second !== null && $second['date'] >= $first['date']) {
                    $end = $second['date'];
                    $len += strlen($t[0]) + $second['length'];
                }
            }
            return [substr($work, 0, $at) . ' ' . substr($work, $at + $len), $first['date'], $end];
        }
        return [$work, null, null];
    }

    /**
     * A length in whole days or longer: "3 weeks long", "for 90 days",
     * "week-long", "2 months". [remaining text, {n, unit} or null].
     *
     * @return array{0:string,1:?array{n:int,unit:string}}
     */
    private static function extractSpan(string $work): array
    {
        $numAlt = '\d{1,3}|' . implode('|', array_keys(self::NUMBER_WORDS));
        if (preg_match('/\b(?:for\s+)?(?:an?\s+)?(week|month)[\s-]long\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), ['n' => 1, 'unit' => strtolower($m[1])]];
        }
        // Bare "2 weeks" only at the end ("Lisbon trip 2 weeks"); before a
        // noun it describes the thing ("2 week sprint review").
        if (preg_match('/\bfor\s+(' . $numAlt . ')[\s-]+(day|week|month)s?(?:[\s-]+long)?\b/i', $work, $m)
            || preg_match('/\b(' . $numAlt . ')[\s-]+(day|week|month)s?[\s-]+long\b/i', $work, $m)
            || preg_match('/\b(' . $numAlt . ')[\s-]+(day|week|month)s?\b(?=[\s,.;!]*$)/i', $work, $m)) {
            $n = ctype_digit($m[1]) ? (int) $m[1] : self::NUMBER_WORDS[strtolower($m[1])];
            if ($n >= 1 && $n <= 366) {
                return [self::cut($work, $m[0]), ['n' => $n, 'unit' => strtolower($m[2])]];
            }
        }
        return [$work, null];
    }

    /** @return array{0:int,1:int} */
    private static function to24(int $h, int $min, string $meridiem): array
    {
        $h %= 12;
        if ($meridiem === 'pm') {
            $h += 12;
        }
        return [$h, min(59, $min)];
    }

    /** "first Monday of September": nth (or last) weekday within a month. */
    private static function nthWeekdayOfMonth(\DateTimeImmutable $now, int $y, int $m, int $weekday, string $ordinal): ?\DateTimeImmutable
    {
        $first = self::mkDate($now, $y, $m, 1);
        if ($first === null) {
            return null;
        }
        if ($ordinal === 'last') {
            $lastDay = (int) $first->format('t');
            $last = $first->setDate($y, $m, $lastDay);
            $back = ((int) $last->format('w') - $weekday + 7) % 7;
            return $last->sub(new \DateInterval('P' . $back . 'D'));
        }
        $n = ['first' => 0, 'second' => 1, 'third' => 2, 'fourth' => 3][$ordinal] ?? 0;
        $forward = ($weekday - (int) $first->format('w') + 7) % 7 + $n * 7;
        $day = 1 + $forward;
        return $day <= (int) $first->format('t') ? $first->setDate($y, $m, $day) : null;
    }

    private static function mkDate(\DateTimeImmutable $now, int $y, int $m, int $d): ?\DateTimeImmutable
    {
        if (!checkdate($m, $d, $y)) {
            return null;
        }
        return $now->setDate($y, $m, $d);
    }

    /**
     * Person names from a with-clause: split on commas/and/&, drop bare
     * articles and non-name junk, title-case each word. A leading article is
     * kept as part of a group name ("the Examples" -> "The Examples").
     *
     * @return list<string>
     */
    private static function parsePeople(string $clause): array
    {
        $clause = trim(preg_replace('/\s+/', ' ', $clause) ?? '', " \t\n\r,.-@");
        if ($clause === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\s*(?:,|\band\b|&)\s*/i', $clause) ?: [] as $part) {
            $part = trim((string) $part, " \t\n\r,.-@");
            if ($part === '' || preg_match('/^[A-Za-z][A-Za-z\'\- ]*$/', $part) !== 1) {
                continue;
            }
            if (in_array(strtolower($part), ['the', 'a', 'an'], true)) {
                continue;
            }
            $out[] = self::titleCaseName($part);
        }
        return array_values(array_unique($out));
    }

    /** Uppercase the first letter of each word, leaving the rest untouched. */
    private static function titleCaseName(string $name): string
    {
        $words = explode(' ', $name);
        foreach ($words as &$word) {
            if ($word !== '') {
                $word = mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
            }
        }
        unset($word);
        return implode(' ', $words);
    }

    private static function cut(string $work, string $match): string
    {
        $pos = strpos($work, $match);
        if ($pos === false) {
            return $work;
        }
        return substr($work, 0, $pos) . ' ' . substr($work, $pos + strlen($match));
    }
}
