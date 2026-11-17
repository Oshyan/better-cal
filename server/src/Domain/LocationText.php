<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Support\Patterns;

/**
 * One reading of a location's text, made before any place service is asked
 * (#328): an optional name, an optional address and an optional rest, over
 * parts. The model, and what each place job does with each result, is
 * docs/design/location-reading.md. Pure.
 */
final class LocationText
{
    /**
     * Between two parts: a comma or semicolon; a dash of any kind with a
     * space on each side, or an em dash; `@`, `/` or `|` with a space on
     * each side. A hyphen in a word or a number range is not one.
     */
    private const SEPARATOR = '/\s*[,;]\s*|\s+[-\x{2013}\x{2014}]\s+|\s*\x{2014}\s*|\s+[@\/|]\s+/u';

    /**
     * Street words with no short form, beside SHORT_WORDS' own (which also
     * holds compass words, left out of STREET below).
     */
    private const MORE_STREET_WORDS = [
        'way', 'terrace', 'ter', 'circle', 'cir', 'square', 'sq', 'trail', 'trl', 'alley', 'row',
        'loop', 'pike', 'walk', 'crescent', 'route', 'rte', 'expressway', 'freeway', 'turnpike',
    ];

    /** Words that make a part a room when a number or letter follows. */
    private const ROOM_WORDS = ['room', 'rm', 'suite', 'ste', 'floor', 'fl', 'level', 'lvl', 'unit', 'booth'];

    /** Meeting services by name: each is a sign that a part is a meeting. */
    private const MEETING_SERVICES = [
        'zoom', 'meet', 'hangout', 'hangouts', 'teams', 'webex', 'skype', 'jitsi', 'whereby',
        'gotomeeting', 'facetime', 'discord', 'online', 'virtual', 'remote', 'webinar', 'livestream',
    ];

    /** Words that say the place comes later: each is a sign. */
    private const LATER_WORDS = [
        'tbd', 'tba', 'tbc', 'rsvp', 'rsvpd', 'registration', 'register', 'registered',
        'shared', 'sent', 'emailed', 'announced', 'provided', 'revealed', 'later', 'soon',
    ];

    /** Words that name nothing by themselves, and are no sign either. */
    private const FILLER = [
        'the', 'a', 'an', 'at', 'in', 'on', 'of', 'for', 'and', 'or', 'to', 'is', 'are', 'be', 'will',
        'by', 'via', 'with', 'our', 'your', 'my', 'we', 'you', 'us', 'it', 'see', 'check', 'here', 'there',
        'link', 'links', 'call', 'video', 'meeting', 'conference', 'dial', 'dialin', 'bridge', 'id',
        'passcode', 'password', 'pin', 'code', 'join', 'details', 'info', 'location', 'address', 'venue',
        'description', 'email', 'invite', 'invitation', 'below', 'above', 'follow', 'follows', 'coming',
        'once', 'after', 'upon', 'when', 'until', 'available', 'google', 'microsoft', 'phone', 'tel',
        'early', 'late', 'around', 'about', 'approx', 'approximately', 'ish', 'maybe', 'probably',
        'sometime', 'tentative', 'tentatively', 'mid',
    ];

    /**
     * The reading of $text.
     *
     * @return array{kind:'empty'}
     *   |array{kind:'coordinates',lat:float,lng:float}
     *   |array{kind:'room'}
     *   |array{kind:'none',why:string}
     *   |array{kind:'place',text:string,rest:?string,name:?string,address:?string,hold:?string,parts:list<string>,opensWithAddress:bool}
     */
    public static function read(string $text): array
    {
        $t = Geocode::normalize($text);
        $t = ltrim((string) preg_replace('/^@\s*/u', '', $t));
        if ($t === '') {
            return ['kind' => 'empty'];
        }
        if (($point = self::coordinates($t)) !== null) {
            return ['kind' => 'coordinates', 'lat' => $point[0], 'lng' => $point[1]];
        }
        if (Patterns::isPendingLocation($t)) {
            return ['kind' => 'none', 'why' => 'later'];
        }
        $len = self::placeLength($t);
        $place = substr($t, 0, $len);
        $rest = trim((string) preg_replace('/^[\s,;@\/|\-\x{2013}\x{2014}]+/u', '', substr($t, $len)));
        if (trim($place) === '') {
            return ['kind' => 'none', 'why' => 'note'];
        }
        $parts = self::parts(self::withoutBrackets($place));
        if ($parts === []) {
            $parts = self::parts(trim((string) preg_replace('/[\(\)\[\]]/u', ' ', $place)));
        }
        $kept = [];
        $rooms = 0;
        $why = null;
        foreach ($parts as $p) {
            if (self::isRoom($p)) {
                $rooms++;
                continue;
            }
            $sign = self::notPlace($p);
            if ($sign !== null) {
                $why ??= $sign;
                continue;
            }
            $kept[] = $p;
        }
        if ($kept === []) {
            return $rooms > 0 ? ['kind' => 'room'] : ['kind' => 'none', 'why' => $why ?? 'note'];
        }
        $name = null;
        $address = null;
        $hold = null;
        $opens = false;
        foreach ($kept as $i => $p) {
            $at = self::addressStart($p);
            if ($at === null) {
                continue;
            }
            [$offset, $hold] = $at;
            $opens = $i === 0 && $offset === 0;
            $before = array_slice($kept, 0, $i);
            $head = trim(substr($p, 0, $offset), " \t,;-@/|\u{2013}\u{2014}");
            if ($head !== '') {
                $before[] = $head;
            }
            $name = $before === [] ? null : implode(', ', $before);
            $address = implode(', ', array_merge([substr($p, $offset)], array_slice($kept, $i + 1)));
            break;
        }
        return [
            'kind' => 'place',
            'text' => trim($place),
            'rest' => $rest === '' ? null : $rest,
            'name' => $name,
            'address' => $address,
            'hold' => $hold,
            'parts' => $kept,
            'opensWithAddress' => $opens,
        ];
    }

    /**
     * Where the place in $text ends, in bytes: at a with-clause ("with Alex",
     * "w/ Sam"), or at the separator before a later part that asks a
     * question; the separator and spaces before the end are not the place's.
     * Quick add's place after "at" ends here too (FallbackParser::place).
     */
    public static function placeLength(string $text): int
    {
        $end = strlen($text);
        if (preg_match('/(?<![\p{L}\p{N}])(?:with\s|w\/)/iu', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $end = (int) $m[0][1];
        }
        $pieces = preg_split(self::SEPARATOR, $text, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        foreach ($pieces as $i => [$piece, $offset]) {
            if ($i > 0 && $offset < $end && str_ends_with(rtrim((string) $piece), '?')) {
                $end = (int) $offset;
                break;
            }
        }
        $head = substr($text, 0, $end);
        $trimmed = (string) preg_replace('/[\s,;@\/|\-\x{2013}\x{2014}]+$/u', '', $head);
        return strlen($trimmed);
    }

    /**
     * Where an address starts in one part: [byte offset, what a pin must
     * hold], or null. A house number or number range followed by a street
     * name: any word when the number opens the part (the separator marks
     * where a name ended), else a name ending in a street word within five
     * words. Or an intersection: two street names joined by "and", "&" or
     * "at". The earliest start wins.
     *
     * @return ?array{0:int,1:string}
     */
    public static function addressStart(string $part): ?array
    {
        $street = self::streetWordPattern();
        $number = '\d+[a-z]?(?:[-\x{2013}]\d+[a-z]?)?';
        $found = [];
        // The number and its street, up to the first street word.
        $numbered = '/(?<![\p{L}\p{N}])' . $number . '\s+(?!\d+(?:\s|$))(?:[\p{L}\p{N}\'.]+\s+){0,4}?' . $street . 's?\b\.?/iu';
        if (preg_match($numbered, $part, $m, PREG_OFFSET_CAPTURE) === 1) {
            $found[] = [(int) $m[0][1], rtrim($m[0][0])];
        }
        if (preg_match('/^' . $number . '\s+\S/iu', $part) === 1) {
            $found[] = [0, $found !== [] && $found[0][0] === 0 ? $found[0][1] : $part];
        }
        $ordinal = '\d+(?:st|nd|rd|th)';
        $one = '(?:' . $ordinal . '(?:\s+' . $street . ')?|[\p{L}][\p{L}\'-]*\s+' . $street . ')\.?';
        $two = '(?:' . $ordinal . '(?:\s+' . $street . 's?)?|[\p{L}][\p{L}\'-]*\s+' . $street . 's?)\b\.?';
        if (preg_match('/(?<![\p{L}\p{N}])' . $one . '\s+(?:and|&|at)\s+' . $two . '/iu', $part, $m, PREG_OFFSET_CAPTURE) === 1) {
            $found[] = [(int) $m[0][1], rtrim($m[0][0])];
        }
        if ($found === []) {
            return null;
        }
        usort($found, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return $found[0];
    }

    /**
     * The parts of $text, split at the separators, empty ones dropped.
     *
     * @return list<string>
     */
    public static function parts(string $text): array
    {
        $pieces = preg_split(self::SEPARATOR, $text) ?: [];
        return array_values(array_filter(array_map(
            static fn(string $p): string => trim($p, " \t,;"),
            $pieces
        ), static fn(string $p): bool => $p !== ''));
    }

    /** $text without its bracketed parts, spaces and commas tidied. */
    public static function withoutBrackets(string $text): string
    {
        $bare = preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/u', ' ', $text) ?? $text;
        $bare = trim(preg_replace('/\s{2,}/u', ' ', $bare) ?? $bare, " ,;-\t");
        return preg_replace('/\s+,/u', ',', $bare) ?? $bare;
    }

    /** A part that is a room with its number or letter ("Room 12B", "2nd floor"). */
    public static function isRoom(string $part): bool
    {
        if (self::addressStart($part) !== null) {
            return false;
        }
        $words = implode('|', self::ROOM_WORDS);
        $id = '(?:\d[\p{L}\d-]{0,5}|(?-i:\p{Lu}{1,2}\d{0,4}))';
        return preg_match('/^(?:[\p{L}\'-]+\s+)?(?:' . $words . ')\.?\s*#?\s*' . $id . '$/iu', $part) === 1
            || preg_match('/^(?:\d+(?:st|nd|rd|th)|ground|first|second|third|top|upper|lower|main)\s+(?:floor|level)$/iu', $part) === 1;
    }

    /**
     * Why one part names nowhere ('link', 'phone', 'meeting', 'later',
     * 'note'), or null when it may name a place: null unless nothing that
     * names somewhere is left once links, long numbers, words with digits,
     * meeting words, later-words, dates and times, and filler are taken out,
     * and one of those taken out was a sign.
     */
    public static function notPlace(string $part): ?string
    {
        $sign = null;
        $s = $part;
        $link = '~(?i:https?://|www\.)\S+|(?<![\p{L}\p{N}@.])[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.[a-z]{2,}(?:/\S*)?(?![\p{L}\p{N}])~u';
        if (preg_match($link, $s) === 1) {
            $sign = 'link';
            $s = (string) preg_replace($link, ' ', $s);
        }
        $s = (string) preg_replace_callback('/\+?\d[\d\s().\-]{5,}\d/u', static function (array $m) use (&$sign): string {
            if (strlen((string) preg_replace('/\D/', '', $m[0])) >= 7) {
                $sign ??= 'phone';
                return ' ';
            }
            return $m[0];
        }, $s);
        $when = FallbackParser::withoutWhen('at', $s, new \DateTimeImmutable());
        if (PlaceSearch::words($when) !== PlaceSearch::words(trim($s, " \t\n\r,.-@"))) {
            $sign ??= 'note';
            $s = $when;
        }
        foreach (PlaceSearch::words($s) as $w) {
            if (preg_match('/\d/', $w) === 1) {
                continue;
            }
            if (in_array($w, self::MEETING_SERVICES, true)) {
                $sign ??= 'meeting';
                continue;
            }
            if (in_array($w, self::LATER_WORDS, true)) {
                $sign ??= 'later';
                continue;
            }
            if (in_array($w, self::FILLER, true) || mb_strlen($w) < 2) {
                continue;
            }
            return null;
        }
        return $sign;
    }

    /**
     * Coordinates typed as the text, "lat, lng" with decimals, in range.
     *
     * @return ?array{0:float,1:float}
     */
    public static function coordinates(string $text): ?array
    {
        if (preg_match('/^\s*(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*$/', $text, $m) !== 1) {
            return null;
        }
        try {
            return Coordinates::pair((float) $m[1], (float) $m[2]);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The sentence the place dropdown shows when the reading sends nothing.
     *
     * @param array{kind:string,why?:string} $reading
     */
    public static function sentence(array $reading): ?string
    {
        if ($reading['kind'] === 'room') {
            return 'A room has no place on the map by itself. Add the venue or its address.';
        }
        if ($reading['kind'] !== 'none') {
            return null;
        }
        return match ($reading['why'] ?? '') {
            'link' => 'That is a web address, not a place.',
            'phone' => 'That is a phone number, not a place.',
            'meeting' => 'That reads as an online meeting, not a place.',
            'later' => 'That says the place is given later.',
            default => 'That reads as a note, not a place.',
        };
    }

    /** The street words, as one regex alternation. */
    private static function streetWordPattern(): string
    {
        static $pattern = null;
        if ($pattern === null) {
            $compass = ['north', 'south', 'east', 'west', 'n', 's', 'e', 'w'];
            $words = [];
            foreach (PlaceSearch::SHORT_WORDS as $long => $short) {
                if (!in_array($long, $compass, true)) {
                    $words[] = $long;
                    $words[] = $short;
                }
            }
            $words = array_merge($words, self::MORE_STREET_WORDS);
            usort($words, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
            $pattern = '(?:' . implode('|', array_map(static fn(string $w): string => preg_quote($w, '/'), $words)) . ')';
        }
        return $pattern;
    }
}
