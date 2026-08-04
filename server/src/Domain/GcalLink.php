<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Support\Time;

/**
 * Parser for Google Calendar "add event" template links — the URLs behind
 * every "Add to Google Calendar" button on the web (Luma, Eventbrite, ...):
 *
 *   https://calendar.google.com/calendar/render?action=TEMPLATE
 *     &text=Title&dates=20260809T160000Z/20260810T050000Z
 *     &details=...&location=...&ctz=America/Los_Angeles&recur=RRULE:...
 *
 * (and the equivalent /calendar/u/N/r/eventedit form). Pure: no I/O.
 * QuickAdd feeds pasted links through this, and the /add deep link (the
 * Chrome extension's redirect target) reuses QuickAdd, so one parser serves
 * every entry path.
 */
final class GcalLink
{
    private const HOST_PATH = '~^https://(?:www\.)?calendar\.google\.com/calendar/(?:u/\d+/)?(?:r/eventedit|render)(?:\?|$)~i';

    /** Does this text look like a GCal template link? (leading/trailing space ok) */
    public static function isTemplateUrl(string $text): bool
    {
        return preg_match(self::HOST_PATH, trim($text)) === 1;
    }

    /**
     * Parse a template link into a quick-add draft. $fallbackTz interprets
     * naive times and renders instants; the link's own ctz wins when present.
     *
     * @return array{title:string,start:string,end:string,allDay:bool,location:?string,description:?string,rrule:?string,personNames:list<string>,confidence:float,source:string}|null
     */
    public static function parse(string $url, string $fallbackTz): ?array
    {
        $url = trim($url);
        if (!self::isTemplateUrl($url)) {
            return null;
        }
        $queryStr = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        parse_str($queryStr, $q);

        $tzid = is_string($q['ctz'] ?? null) && $q['ctz'] !== '' ? $q['ctz'] : $fallbackTz;
        try {
            $tz = new \DateTimeZone($tzid);
        } catch (\Exception) {
            $tz = Time::zone($fallbackTz);
        }

        $dates = is_string($q['dates'] ?? null) ? $q['dates'] : '';
        $range = self::parseDates($dates, $tz);
        if ($range === null) {
            return null; // no usable dates: not a draft we can trust
        }
        [$start, $end, $allDay] = $range;

        $title = trim((string) ($q['text'] ?? ''));
        $location = trim((string) ($q['location'] ?? ''));
        $details = trim((string) ($q['details'] ?? ''));
        $recur = trim((string) ($q['recur'] ?? ''));
        if (str_starts_with(strtoupper($recur), 'RRULE:')) {
            $recur = substr($recur, 6);
        }

        return [
            'title' => $title !== '' ? $title : 'New event',
            'start' => Time::iso($start),
            'end' => Time::iso($end),
            'allDay' => $allDay,
            'location' => $location !== '' ? $location : null,
            'description' => $details !== '' ? $details : null,
            'rrule' => $recur !== '' ? $recur : null,
            'personNames' => [],
            'confidence' => 0.98,
            'source' => 'gcal-link',
        ];
    }

    /**
     * "START/END" where each side is Ymd (all-day, end exclusive) or
     * Ymd\THis with optional trailing Z (UTC instant; naive means $tz).
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable,2:bool}|null
     */
    private static function parseDates(string $dates, \DateTimeZone $tz): ?array
    {
        $parts = explode('/', $dates);
        if (count($parts) !== 2) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $side = static function (string $raw) use ($tz, $utc): ?array {
            $raw = trim($raw);
            if (preg_match('/^(\d{8})$/', $raw) === 1) {
                $d = \DateTimeImmutable::createFromFormat('!Ymd', $raw, $tz);
                return $d === false ? null : [$d, true];
            }
            if (preg_match('/^(\d{8})T(\d{6})(Z?)$/', $raw, $m) === 1) {
                $zone = $m[3] === 'Z' ? $utc : $tz;
                $d = \DateTimeImmutable::createFromFormat('Ymd\THis', $m[1] . 'T' . $m[2], $zone);
                if ($d === false) {
                    return null;
                }
                return [$d->setTimezone($tz), false];
            }
            return null;
        };
        $s = $side($parts[0]);
        $e = $side($parts[1]);
        if ($s === null || $e === null) {
            return null;
        }
        $allDay = $s[1] && $e[1];
        $start = $s[0];
        $end = $e[0];
        if ($end <= $start) {
            $end = $allDay ? $start->add(new \DateInterval('P1D')) : $start->add(new \DateInterval('PT1H'));
        }
        return [$start, $end, $allDay];
    }
}
