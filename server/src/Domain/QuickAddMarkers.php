<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * Explicit markers in quick-add text (#86), so the parts it would otherwise
 * guess can be stated outright:
 *
 * - `/friends`: the calendar (QuickAdd matches it against calendar names);
 * - `#food`: a tag (at least one letter, so "Room #5" stays text);
 * - `@Sam`: a person, linked, with the name kept in the title;
 * - `"Wacky Wednesday"`: title text, never read as a date or time.
 *
 * Example: `Dinner with @Sam on Tuesday #food /friends` -> title "Dinner
 * with Sam", tag food, calendar friends. A marker only counts at the start
 * of a word (after a space or the start), so "7/31", "w/ Sam", URLs and
 * email addresses are left alone. Pure, no I/O.
 */
final class QuickAddMarkers
{
    /** Private-use characters around a quoted literal's index in the work text. */
    private const OPEN = "\u{E000}";
    private const CLOSE = "\u{E001}";
    /** Words after @ that mean "at", not a person. */
    private const NOT_PEOPLE = ['noon', 'midnight', 'home', 'work', 'office', 'school', 'night', 'morning', 'afternoon', 'evening', 'today', 'tomorrow', 'tonight'];

    /**
     * @return array{text:string, llmText:string, calendar:?string, tags:list<string>,
     *   people:list<string>, literals:list<string>}
     *   `text` is what the parsers read: tags removed, the calendar marker
     *   removed, `@` dropped from names, each quoted literal replaced by a
     *   placeholder (restore() puts it back). With $calendar false a
     *   `/word` is left as text (QuickAdd re-reads it so when no calendar
     *   matches).
     */
    public static function extract(string $text, bool $calendar = true): array
    {
        $readCalendar = $calendar;
        // The placeholder characters are ours: typed ones go.
        $text = str_replace([self::OPEN, self::CLOSE], '', $text);
        $literals = [];
        $work = preg_replace_callback('/(?<![\p{L}\p{N}])(["“”])([^"“”]{1,200})["“”]/u', static function (array $m) use (&$literals): string {
            $inner = trim($m[2]);
            if ($inner === '') {
                return $m[0];
            }
            $literals[] = $inner;
            return self::OPEN . (count($literals) - 1) . self::CLOSE;
        }, $text) ?? $text;

        $tags = [];
        $work = preg_replace_callback('/(?<=^|\s)#((?=[\p{L}\p{N}_-]*\p{L})[\p{L}\p{N}][\p{L}\p{N}_-]{0,49})(?=$|\s|[,.;!?])/u', static function (array $m) use (&$tags): string {
            if (!in_array(mb_strtolower($m[1]), array_map('mb_strtolower', $tags), true)) {
                $tags[] = $m[1];
            }
            return '';
        }, $work) ?? $work;

        $calendar = null;
        $work = !$readCalendar ? $work : preg_replace_callback('/(?<=^|\s)\/([\p{L}\p{N}][\p{L}\p{N}_&\'-]{0,63})(?=$|\s|[,.;!?])/u', static function (array $m) use (&$calendar): string {
            if ($calendar !== null) {
                return $m[0]; // the first one decides; later ones stay as typed
            }
            $calendar = $m[1];
            return '';
        }, $work) ?? $work;

        $people = [];
        $work = preg_replace_callback('/(?<=^|\s)@(\p{L}[\p{L}\p{N}\'’._-]{0,63})/u', static function (array $m) use (&$people): string {
            $name = rtrim($m[1], '.');
            // "@noon", "@home": the old shorthand for at, not a person.
            if (in_array(mb_strtolower($name), self::NOT_PEOPLE, true)) {
                return 'at ' . $m[1];
            }
            // "@Sam's place": the person is Sam.
            $name = preg_replace("/['’]s$/u", '', $name) ?? $name;
            if ($name === '') {
                return $m[0];
            }
            if (!in_array(mb_strtolower($name), array_map('mb_strtolower', $people), true)) {
                $people[] = $name;
            }
            return $m[1];
        }, $work) ?? $work;

        $tidy = static fn(string $s): string => trim(preg_replace('/\s{2,}/u', ' ', $s) ?? $s);
        $work = $tidy($work);
        // The model reads a stand-in word for a literal, never its text, so
        // it cannot take "Wednesday" in a quoted title for a date either.
        $llmText = preg_replace_callback('/' . self::OPEN . '(\d+)' . self::CLOSE . '/u', static fn(array $m): string => 'QUOTED' . $m[1], $work) ?? $work;

        return [
            'text' => $work,
            'llmText' => $llmText,
            'calendar' => $calendar,
            'tags' => $tags,
            'people' => $people,
            'literals' => $literals,
        ];
    }

    /** True when $text has any marker or quoted literal at all. */
    public static function any(array $markers): bool
    {
        return $markers['calendar'] !== null || $markers['tags'] !== [] || $markers['people'] !== [] || $markers['literals'] !== [];
    }

    /**
     * Put quoted literals back into a parsed string (a title, a place). Both
     * the parser's placeholder and the model's stand-in word are replaced.
     *
     * @param list<string> $literals
     */
    public static function restore(?string $s, array $literals, bool $fromModel = false): ?string
    {
        if ($s === null || $literals === []) {
            return $s;
        }
        $s = preg_replace_callback('/' . self::OPEN . '(\d+)' . self::CLOSE . '/u', static fn(array $m): string => $literals[(int) $m[1]] ?? '', $s) ?? $s;
        // The stand-in word only ever reaches the model; text the owner typed
        // that happens to read "QUOTED0" is left alone.
        return $fromModel ? (preg_replace_callback('/\bQUOTED(\d+)\b/', static fn(array $m): string => $literals[(int) $m[1]] ?? $m[0], $s) ?? $s) : $s;
    }

    /** Whether every quoted literal survived into $title (the model may drop one). */
    public static function keepsLiterals(string $title, array $literals): bool
    {
        foreach ($literals as $lit) {
            if (!str_contains(mb_strtolower($title), mb_strtolower($lit))) {
                return false;
            }
        }
        return true;
    }

    /**
     * The calendar a `/marker` names, from [{id, name}] in display order:
     * the same name (ignoring case, spaces and punctuation), else the first
     * whose name, or a word in it, starts with the marker ("/w" for Work).
     *
     * @param list<array{id:int,name:string}> $calendars
     */
    public static function matchCalendar(string $marker, array $calendars): ?int
    {
        $norm = static fn(string $s): string => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)) ?? '';
        $want = $norm($marker);
        if ($want === '') {
            return null;
        }
        foreach ($calendars as $c) {
            if ($norm($c['name']) === $want) {
                return (int) $c['id'];
            }
        }
        foreach ($calendars as $c) {
            if (str_starts_with($norm($c['name']), $want)) {
                return (int) $c['id'];
            }
        }
        foreach ($calendars as $c) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($c['name']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (str_starts_with($word, $want)) {
                    return (int) $c['id'];
                }
            }
        }
        return null;
    }

    /**
     * The person an `@name` means, from the directory: the same name, else
     * the only person whose first name is it. Otherwise the name as typed
     * (a new person, which the app asks about before creating).
     *
     * @param list<array{id:int,name:string}> $people
     */
    public static function matchPerson(string $typed, array $people): string
    {
        $lower = mb_strtolower($typed);
        foreach ($people as $p) {
            if (mb_strtolower($p['name']) === $lower) {
                return $p['name'];
            }
        }
        $byFirst = array_values(array_filter($people, static function (array $p) use ($lower): bool {
            $first = preg_split('/\s+/u', trim($p['name']))[0] ?? '';
            return mb_strtolower($first) === $lower;
        }));
        return count($byFirst) === 1 ? $byFirst[0]['name'] : $typed;
    }
}
