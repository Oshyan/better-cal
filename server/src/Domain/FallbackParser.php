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

    /**
     * @return array{title:string,start:string,end:string,allDay:bool,location:?string,personNames:list<string>,confidence:float,source:string}
     */
    public static function parse(string $text, string $tzid, ?\DateTimeImmutable $now = null): array
    {
        $tz = Time::zone($tzid);
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        $work = ' ' . trim($text) . ' ';
        $confidence = 0.3;

        // Duration: "for 2 hours", "for 30 min"
        $durationMin = null;
        if (preg_match('/\bfor\s+(\d+(?:\.\d+)?)\s*(hours?|hrs?|hr|h|minutes?|mins?|min|m)\b/i', $work, $m)) {
            $n = (float) $m[1];
            $durationMin = str_starts_with(strtolower($m[2]), 'h') ? (int) round($n * 60) : (int) round($n);
            $durationMin = max(1, $durationMin);
            $work = self::cut($work, $m[0]);
            $confidence += 0.05;
        }

        [$work, $date, $dateFound] = self::extractDate($work, $now);
        [$work, $startTime, $endTime, $timeFound] = self::extractTime($work);

        // Trailing "at <location>" (times were already removed, so a remaining
        // "at ..." tail is a place, not a time).
        $location = null;
        if (preg_match('/\s(?:at|@)\s+([^,]+?)\s*$/i', $work, $m) && !preg_match('/^\d/', trim($m[1]))) {
            $location = trim($m[1]);
            $work = preg_replace('/\s(?:at|@)\s+([^,]+?)\s*$/i', ' ', $work, 1);
            $confidence += 0.1;
        }

        // "with Sam", "with Sam and Alex"
        $personNames = [];
        if (preg_match('/\bwith\s+([A-Za-z][A-Za-z\'\-]*(?:\s+(?:and|&)\s+[A-Za-z][A-Za-z\'\-]*)*)\b/i', $work, $m)) {
            $personNames = array_values(array_filter(array_map('trim', preg_split('/\s+(?:and|&)\s+/i', $m[1]))));
            $work = self::cut($work, $m[0]);
            $confidence += 0.1;
        }

        $title = trim(preg_replace('/\s+/', ' ', $work), " \t\n\r,.-@");
        if ($title === '') {
            $title = 'New event';
            $confidence -= 0.1;
        }

        if ($dateFound) {
            $confidence += 0.2;
        }
        if ($timeFound) {
            $confidence += 0.2;
        }

        $allDay = false;
        if ($timeFound) {
            $day = $dateFound ? $date : $now;
            $start = $day->setTime($startTime[0], $startTime[1]);
            if (!$dateFound && $start <= $now) {
                $start = $start->add(new \DateInterval('P1D'));
            }
            if ($endTime !== null) {
                $end = $start->setTime($endTime[0], $endTime[1]);
                if ($end <= $start) {
                    $end = $end->add(new \DateInterval('P1D'));
                }
            } else {
                $end = $start->add(new \DateInterval('PT' . ($durationMin ?? 60) . 'M'));
            }
        } elseif ($dateFound) {
            $allDay = true;
            $start = $date->setTime(0, 0);
            $end = $start->add(new \DateInterval('P1D'));
        } else {
            // Next round hour, 1h default duration.
            $start = $now->setTime((int) $now->format('G'), 0)->add(new \DateInterval('PT1H'));
            $end = $start->add(new \DateInterval('PT' . ($durationMin ?? 60) . 'M'));
        }

        return [
            'title' => $title,
            'start' => Time::iso($start),
            'end' => Time::iso($end),
            'allDay' => $allDay,
            'location' => $location,
            'personNames' => $personNames,
            'confidence' => round(min(0.95, max(0.05, $confidence)), 2),
            'source' => 'fallback',
        ];
    }

    /** @return array{0:string,1:\DateTimeImmutable,2:bool} */
    private static function extractDate(string $work, \DateTimeImmutable $now): array
    {
        $date = $now;
        $found = false;

        // ISO: 2026-07-31
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $work, $m)) {
            $candidate = self::mkDate($now, (int) $m[1], (int) $m[2], (int) $m[3]);
            if ($candidate !== null) {
                return [self::cut($work, $m[0]), $candidate, true];
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
                return [self::cut($work, $m[0]), $candidate, true];
            }
        }
        // Month name: "July 31", "Jul 31, 2026", "31 July"
        $monthAlt = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
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
                    return [self::cut($work, $m[0]), $candidate, true];
                }
            }
        }
        // today / tomorrow
        if (preg_match('/\btoday\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), $now, true];
        }
        if (preg_match('/\btomorrow\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), $now->add(new \DateInterval('P1D')), true];
        }
        // Weekday with optional next/this. "this"/bare = next occurrence (today
        // counts); "next" = strictly future occurrence (1-7 days out).
        $dayAlt = implode('|', array_keys(self::WEEKDAYS));
        if (preg_match('/\b(?:(next|this)\s+)?(' . $dayAlt . ')\b/i', $work, $m)) {
            $target = self::WEEKDAYS[strtolower($m[2])];
            $todayW = (int) $now->format('w');
            $diff = ($target - $todayW + 7) % 7;
            if (strtolower($m[1] ?? '') === 'next' && $diff === 0) {
                $diff = 7;
            }
            return [self::cut($work, $m[0]), $now->add(new \DateInterval('P' . $diff . 'D')), true];
        }

        return [$work, $date, $found];
    }

    /** @return array{0:string,1:?array{0:int,1:int},2:?array{0:int,1:int},3:bool} */
    private static function extractTime(string $work): array
    {
        // Range with meridiem: "7-9pm", "7pm-9pm", "7:30pm to 9pm"
        if (preg_match('/\b(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*(?:-|–|—|to|until)\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/i', $work, $m)) {
            $mer2 = strtolower($m[6]);
            $mer1 = strtolower($m[3] ?? '') ?: $mer2;
            $start = self::to24((int) $m[1], (int) ($m[2] ?: 0), $mer1);
            $end = self::to24((int) $m[4], (int) ($m[5] ?: 0), $mer2);
            if ($start[0] * 60 + $start[1] >= $end[0] * 60 + $end[1] && $start[0] >= 12) {
                $start[0] -= 12; // "11-1pm" style: pull start back to am
            }
            return [self::cut($work, $m[0]), $start, $end, true];
        }
        // 24h range: "19:00-21:30"
        if (preg_match('/\b(?:at\s+)?(\d{1,2}):(\d{2})\s*(?:-|–|—|to|until)\s*(\d{1,2}):(\d{2})\b/', $work, $m)) {
            return [self::cut($work, $m[0]), [(int) $m[1], (int) $m[2]], [(int) $m[3], (int) $m[4]], true];
        }
        // Single time with meridiem: "7pm", "7:30 pm", "at 2pm"
        if (preg_match('/\b(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), self::to24((int) $m[1], (int) ($m[2] ?: 0), strtolower($m[3])), null, true];
        }
        // 24h single time: "19:00"
        if (preg_match('/\b(?:at\s+)?(\d{1,2}):(\d{2})\b/', $work, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h <= 23 && $min <= 59) {
                return [self::cut($work, $m[0]), [$h, $min], null, true];
            }
        }
        if (preg_match('/\b(?:at\s+)?noon\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), [12, 0], null, true];
        }
        if (preg_match('/\b(?:at\s+)?midnight\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), [0, 0], null, true];
        }
        return [$work, null, null, false];
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

    private static function mkDate(\DateTimeImmutable $now, int $y, int $m, int $d): ?\DateTimeImmutable
    {
        if (!checkdate($m, $d, $y)) {
            return null;
        }
        return $now->setDate($y, $m, $d);
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
