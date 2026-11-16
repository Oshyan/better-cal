<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * The comparison of a change to one Google event with what Google holds now
 * (docs/design/google-conflict-choice.md, the page "1.5.7, Slice 1"): the
 * base the person was shown, Google's value now, and what the change wants,
 * field by field over the seven fields a row and Google both hold. Pure: no
 * database, no request. Every single-event write with the setting on asks it
 * (Events::googleChoice, Rsvp), and nothing else compares.
 */
final class GoogleConflict
{
    /** The fields, in the order they are listed and named. */
    public const FIELDS = ['title', 'description', 'location', 'url', 'status', 'showAs', 'time'];

    /** The row's columns each field is. */
    public const COLUMNS = [
        'title' => ['title'],
        'description' => ['description'],
        'location' => ['location'],
        'url' => ['url'],
        'status' => ['status'],
        'showAs' => ['show_as'],
        'time' => ['start_utc', 'end_utc', 'all_day'],
    ];

    /**
     * What a delete wants of every field: a value no map holds (a field's
     * value is a string, null or, for the time, its three columns).
     */
    public const DELETE = ['deleted' => true];

    /**
     * The one name a person reads for each field (the page, "Words"; the
     * client's fieldNoun), and whether it is plural; null for Planned or
     * Maybe, which a sentence says by its value, never by a name.
     */
    private const NOUNS = ['title' => ['title', false], 'description' => ['notes', true], 'location' => ['location', false], 'url' => ['link', false], 'status' => null, 'showAs' => ['free or busy setting', false], 'time' => ['time', false]];

    /** Text this long is cut where two values are set side by side (the client's TEXT_MAX). */
    private const TEXT_MAX = 80;

    /**
     * One outcome per field: `same` (not wanted, now equals base), `kept`
     * (not wanted, now differs from base), `send` (wanted, now equals base
     * or wanted), `ask` (wanted, now differs from both). A field wanted at
     * its base value is not wanted. Equal means equal after the one
     * normaliser (GoogleSeries::fieldsOf), as the page's table says.
     *
     * @param array<string,mixed> $base every field
     * @param array<string,mixed> $now every field
     * @param array<string,mixed> $wanted the fields the change sets
     * @return array<string,string> field => outcome
     */
    public static function threeWay(array $base, array $now, array $wanted): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $b = $base[$field] ?? null;
            $n = $now[$field] ?? null;
            if (!array_key_exists($field, $wanted) || self::equal($field, $wanted[$field], $b)) {
                $out[$field] = self::equal($field, $n, $b) ? 'same' : 'kept';
                continue;
            }
            $out[$field] = self::equal($field, $n, $b) || self::equal($field, $n, $wanted[$field]) ? 'send' : 'ask';
        }
        return $out;
    }

    /** Whether two values of a field are the same to a person (the page's table). */
    public static function equal(string $field, mixed $a, mixed $b): bool
    {
        return self::normal($field, $a) === self::normal($field, $b);
    }

    /**
     * A row's seven fields (a row in the columns' shape: a stored row, a parsed
     * Google resource, a row with a request's columns over it).
     *
     * @return array<string,mixed>
     */
    public static function mapOf(array $row): array
    {
        $out = [];
        foreach (self::COLUMNS as $field => $columns) {
            $out[$field] = $field === 'time'
                ? ['start_utc' => (string) ($row['start_utc'] ?? ''), 'end_utc' => (string) ($row['end_utc'] ?? ''), 'all_day' => (int) ($row['all_day'] ?? 0)]
                : ($row[$columns[0]] ?? null);
        }
        return $out;
    }

    /**
     * The fields a set of columns touches (a request's columns for Google).
     *
     * @param array<string,mixed> $columns
     * @return list<string>
     */
    public static function fieldsIn(array $columns): array
    {
        return array_values(array_filter(self::FIELDS, static fn(string $f): bool => array_intersect(self::COLUMNS[$f], array_keys($columns)) !== []));
    }

    /**
     * The fields of an outcome map that have $outcome.
     *
     * @param array<string,string> $outcomes
     * @return list<string>
     */
    public static function having(array $outcomes, string $outcome): array
    {
        return array_keys(array_filter($outcomes, static fn(string $o): bool => $o === $outcome));
    }

    /**
     * The sentence a 409 google_changed carries, whole on its own for an
     * entry with no dialog (Review, a plugin run, an API caller): the
     * question's own words (question), then what was not done.
     * $asks: [{field, was, now, yours}] in the served form; $title: the
     * title the person was shown; $now: the day the year is said against.
     */
    public static function message(string $title, array $asks, bool $delete, ?\DateTimeImmutable $now = null): string
    {
        ['body' => $body, 'lines' => $lines] = self::question($title, $asks, $delete, $now);
        return implode(' ', [$body, ...$lines]) . ' '
            . ($delete ? "It wasn't deleted; open the event to see it, and delete it again if you still mean to." : "It wasn't changed; open the event to see it and make the change again.");
    }

    /**
     * What the question says about the fields asked, as the app's dialog
     * says it (web/src/lib/planwords.js, googleChangedAsk, its body and
     * lines; server/tests/fixtures/google-changed-words.json holds them to
     * the same sentences): one field in sentences, several a line each. A
     * field is named by its one noun, Planned or Maybe by its value; two
     * values are never joined by "to", each its own clause after a
     * semicolon; the time values said together say the year both or
     * neither. Times are said on the clock each value is served on.
     *
     * @return array{body:string, lines:list<string>}
     */
    public static function question(string $name, array $asks, bool $delete, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $list = array_values(array_filter($asks, static fn(mixed $a): bool => is_array($a) && isset($a['field']) && in_array($a['field'], self::FIELDS, true)));
        $times = [];
        foreach ($list as $a) {
            if ($a['field'] === 'time') {
                array_push($times, $a['was'] ?? null, $a['now'] ?? null, $a['yours'] ?? null);
            }
        }
        $withYear = self::yearSaid($times, $now);
        $said = static fn(string $f, mixed $v, mixed $other = null): string => self::valueWords($f, $v, $other, $withYear);
        $side = static fn(string $f, mixed $v, mixed $other): string => self::isEmpty($f, $v) ? 'no ' . self::nounOf($f) : $said($f, $v, $other);
        $since = 'Since ' . self::quoted($name !== '' ? $name : 'Untitled event') . ' was last shown to you, ';
        if (count($list) !== 1) {
            $lines = array_map(static fn(array $a): string => self::labelOf($a['field']) . ': ' . ($delete
                ? self::stop('was ' . $side($a['field'], $a['was'] ?? null, $a['now'] ?? null) . '; now ' . $side($a['field'], $a['now'] ?? null, $a['was'] ?? null))
                : $side($a['field'], $a['now'] ?? null, $a['yours'] ?? null) . ' in Google Calendar; ' . $side($a['field'], $a['yours'] ?? null, $a['now'] ?? null) . ' in your change.'), $list);
            return ['body' => $since . 'these were changed in Google Calendar:', 'lines' => $lines];
        }
        $a = $list[0];
        $f = (string) $a['field'];
        [$was, $there, $yours] = [$a['was'] ?? null, $a['now'] ?? null, $a['yours'] ?? null];
        $plural = (self::NOUNS[$f][1] ?? false) === true;
        [$wasWere, $isAre, $pronoun, $object] = $plural ? ['were', 'are', 'they', 'them'] : ['was', 'is', 'it', 'it'];
        $subject = self::NOUNS[$f] === null ? 'it' : 'its ' . self::nounOf($f);
        $changed = static fn(string $how): string => "$subject $wasWere $how in Google Calendar";
        $It = ucfirst($pronoun);
        if ($delete) {
            $text = self::isEmpty($f, $there) ? $changed('removed') . "; $pronoun $wasWere " . $said($f, $was)
                : (self::isEmpty($f, $was) ? $changed('added') . "; $pronoun $isAre now " . $said($f, $there)
                : $changed('changed') . ". $It $wasWere " . $said($f, $was, $there) . "; $pronoun $isAre now " . $said($f, $there, $was));
        } else {
            $mine = self::isEmpty($f, $yours) ? "your change removes $object" : "your change makes $object " . $said($f, $yours, $there);
            $text = self::isEmpty($f, $there) ? $changed('removed') . '; ' . $mine
                : $changed('changed') . ". $It $isAre now " . $said($f, $there, $yours) . ' there; ' . $mine;
        }
        return ['body' => $since . self::stop($text), 'lines' => []];
    }

    /** A field's noun: "title", "notes"; Planned or Maybe has none, and "Planned or Maybe" stands where a label needs one. */
    private static function nounOf(string $field): string
    {
        return self::NOUNS[$field][0] ?? 'Planned or Maybe';
    }

    /** A line's label: "Location", "Notes", "Planned or Maybe". */
    private static function labelOf(string $field): string
    {
        return ucfirst(self::nounOf($field));
    }

    private static function quoted(string $s): string
    {
        return "'" . $s . "'";
    }

    /** A sentence's full stop, left out after a quoted value that already ends one: "'Bring the agenda.'". */
    private static function stop(string $s): string
    {
        return preg_match("/[.!?]'$/", $s) === 1 ? $s : $s . '.';
    }

    /** A value as plain text: notes as the text a person reads, never markup; whitespace runs as one space. */
    private static function plainOf(string $field, mixed $v): string
    {
        if ($v === null || is_array($v)) {
            return '';
        }
        $s = $field === 'description' ? Sanitize::toText((string) $v) : (string) $v;
        return trim((string) preg_replace('/[\s\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]+/u', ' ', $s));
    }

    private static function isEmpty(string $field, mixed $v): bool
    {
        return $field === 'time' ? !is_array($v) || empty($v['start']) || empty($v['end']) : self::plainOf($field, $v) === '';
    }

    /**
     * A non-empty value as a sentence says it: a time in days and clock,
     * Planned or Maybe as "Planned" or "a Maybe", text quoted and, when
     * long, cut where it differs from $other (the value it is set beside).
     */
    private static function valueWords(string $field, mixed $v, mixed $other, bool $withYear): string
    {
        if ($field === 'time') {
            return self::timeWords($v, $withYear);
        }
        if ($field === 'status') {
            return $v === 'tentative' ? 'a Maybe' : 'Planned';
        }
        if ($field === 'showAs') {
            return $v === 'free' ? 'Free' : 'Busy';
        }
        $o = self::isEmpty($field, $other) ? null : self::plainOf($field, $other);
        return self::quoted(self::cut(self::plainOf($field, $v), $o));
    }

    /**
     * A text cut to about 80 characters from a little before where it first
     * differs from $other, at word boundaries, with "…" where cut, so the
     * difference shows; with no $other, from its start.
     */
    private static function cut(string $text, ?string $other): string
    {
        $a = mb_str_split($text);
        $i = 0;
        if ($other !== null) {
            $b = mb_str_split($other);
            while ($i < count($a) && $i < count($b) && $a[$i] === $b[$i]) {
                $i++;
            }
        }
        $len = count($a);
        $from = $other !== null && $i > 20 ? $i - 20 : 0;
        if ($from > 0) {
            $space = array_search(' ', array_slice($a, $from, null, true), true);
            $from = $space !== false && $space < $i ? $space + 1 : $from;
        }
        if ($from === 0 && $len <= self::TEXT_MAX) {
            return $text;
        }
        $to = min($len, $from + self::TEXT_MAX);
        if ($to < $len) {
            for ($space = min($to, $len - 1); $space >= 0 && $a[$space] !== ' '; $space--) {
            }
            if ($space > $from + self::TEXT_MAX / 2) {
                $to = $space;
            }
        }
        $piece = (string) preg_replace('/[\s,.;:]+$/u', '', implode('', array_slice($a, $from, $to - $from)));
        return ($from > 0 ? '…' : '') . $piece . ($to < $len ? '…' : '');
    }

    /**
     * A time value's first and last day and its ends, on the clock it is
     * served on; an all-day value's from its dates alone. Null when it is
     * not a time.
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable,2:\DateTimeImmutable}|null [start, end, last moment's day]
     */
    private static function timeDates(mixed $t): ?array
    {
        if (!is_array($t) || empty($t['start']) || empty($t['end'])) {
            return null;
        }
        try {
            if (!empty($t['allDay'])) {
                $start = new \DateTimeImmutable(substr((string) $t['start'], 0, 10) . 'T00:00:00+00:00');
                $end = new \DateTimeImmutable(substr((string) $t['end'], 0, 10) . 'T00:00:00+00:00');
                return [$start, $end, $end->modify('-1 day')];
            }
            $start = new \DateTimeImmutable((string) $t['start']);
            $end = new \DateTimeImmutable((string) $t['end']);
            return [$start, $end, $end];
        } catch (\Exception) {
            return null;
        }
    }

    /** Whether the time values said together say their year: when any of them is in another year than $now's. */
    private static function yearSaid(array $times, \DateTimeImmutable $now): bool
    {
        foreach ($times as $t) {
            $d = self::timeDates($t);
            if ($d !== null && ($d[0]->format('Y') !== $now->format('Y') || $d[2]->format('Y') !== $now->format('Y'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * A time value as a sentence says it: "Tue, Nov 17, 10:00 AM to 11:00
     * AM", "Tue, Nov 17 10:00 PM to Wed, Nov 18 1:00 AM", "all day on Tue,
     * Nov 17", "all day on Nov 17 to Nov 19"; the year after each date when
     * $withYear (yearSaid).
     */
    private static function timeWords(mixed $t, bool $withYear): string
    {
        $d = self::timeDates($t);
        if ($d === null) {
            return '';
        }
        [$start, $end, $last] = $d;
        $y = $withYear ? ', Y' : '';
        $clock = static fn(\DateTimeImmutable $at): string => $at->format('g:i A');
        if (!empty($t['allDay'])) {
            if ($start->format('Y-m-d') === $last->format('Y-m-d') || $last < $start) {
                return 'all day on ' . $start->format('D, M j' . $y);
            }
            return 'all day on ' . $start->format('M j' . $y) . ' to ' . $last->format('M j' . $y);
        }
        $day = static fn(\DateTimeImmutable $at): string => $at->format('D, M j' . $y);
        if ($start == $end) {
            return $day($start) . ', ' . $clock($start);
        }
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $day($start) . ', ' . $clock($start) . ' to ' . $clock($end);
        }
        return $day($start) . ' ' . $clock($start) . ' to ' . $day($end) . ' ' . $clock($end);
    }

    /**
     * A field's value after the one normaliser that exists
     * (GoogleSeries::fieldsOf), nothing added. A value that is not of the
     * field's shape (a delete's) is kept as it is, so it equals no value of
     * that shape.
     */
    private static function normal(string $field, mixed $value): mixed
    {
        $row = ['title' => '', 'description' => null, 'location' => '', 'url' => null, 'status' => 'confirmed', 'show_as' => 'busy', 'start_utc' => '', 'end_utc' => '', 'all_day' => 0, 'tzid' => 'UTC'];
        if ($field === 'time') {
            if (!is_array($value) || !array_key_exists('start_utc', $value) || !array_key_exists('end_utc', $value) || !array_key_exists('all_day', $value)) {
                return ['shape' => $value];
            }
            $f = GoogleSeries::fieldsOf(array_merge($row, ['start_utc' => (string) $value['start_utc'], 'end_utc' => (string) $value['end_utc'], 'all_day' => (int) $value['all_day']]));
            return [$f['start_utc'], $f['end_utc'], $f['all_day']];
        }
        if ($value !== null && !is_string($value)) {
            return ['shape' => $value];
        }
        $column = self::COLUMNS[$field][0];
        return GoogleSeries::fieldsOf(array_merge($row, [$column => $value]))[$column];
    }
}
