<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * The comparison of a change to one Google event with what Google holds now
 * (docs/design/google-conflict-choice.md, the page "1.5.7, Slice 1"): the
 * base the person was shown, Google's value now, and what the change wants,
 * field by field over the six fields a row and Google both hold. Pure: no
 * database, no request. Every single-event write with the setting on asks it
 * (Events::googleChoice, Rsvp), and nothing else compares.
 */
final class GoogleConflict
{
    /** The fields, in the order they are listed and named. */
    public const FIELDS = ['title', 'description', 'location', 'url', 'status', 'time'];

    /** The row's columns each field is. */
    public const COLUMNS = [
        'title' => ['title'],
        'description' => ['description'],
        'location' => ['location'],
        'url' => ['url'],
        'status' => ['status'],
        'time' => ['start_utc', 'end_utc', 'all_day'],
    ];

    /**
     * What a delete wants of every field: a value no map holds (a field's
     * value is a string, null or, for the time, its three columns).
     */
    public const DELETE = ['deleted' => true];

    /**
     * The one name a person reads for each field (the page, "Words"), and
     * whether it is plural; null for the status, which a sentence says as
     * Planned or Maybe, never by a name.
     */
    private const WORDS = ['title' => ['title', false], 'description' => ['notes', true], 'location' => ['location', false], 'url' => ['link', false], 'status' => null, 'time' => ['time', false]];

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
     * A row's six fields (a row in the columns' shape: a stored row, a parsed
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
     * entry with no dialog: what was not done, which fields changed in
     * Google Calendar since the event was last shown, and with one field
     * what it is there now. $asks: [{field, was, now, yours}] in the served
     * form; $title: the title the person was shown.
     */
    public static function message(string $title, array $asks, bool $delete): string
    {
        $what = trim($title) !== '' ? "'" . trim($title) . "'" : 'This event';
        $clauses = [];
        // The fields a noun names (WORDS), said together; with one, and not a delete, what it is now.
        $named = array_values(array_filter($asks, static fn(array $a): bool => (self::WORDS[$a['field']] ?? null) !== null));
        if ($named !== []) {
            $nouns = array_map(static fn(array $a): string => self::WORDS[$a['field']][0], $named);
            $verb = count($named) > 1 || self::WORDS[$named[0]['field']][1] ? 'were' : 'was';
            $value = count($named) === 1 && !$delete ? self::valueWords((string) $named[0]['field'], $named[0]['now']) : '';
            $clauses[] = 'its ' . self::listOf($nouns) . " $verb " . ($value === null ? 'removed in Google Calendar'
                : ($value === '' ? 'changed in Google Calendar' : 'changed in Google Calendar to ' . $value));
        }
        // Planned or Maybe has no noun: the sentence says which it is now.
        foreach ($asks as $a) {
            if ((self::WORDS[$a['field']] ?? null) === null) {
                $clauses[] = 'it was changed ' . ($clauses === [] ? 'in Google Calendar' : 'there') . ' to ' . (self::valueWords((string) $a['field'], $a['now']) ?? '');
            }
        }
        return "$what wasn't " . ($delete ? 'deleted' : 'changed') . ': since it was last shown, ' . implode(', and ', $clauses) . '. '
            . ($delete ? 'Open the event to see it, and delete it again if you still mean to.' : 'Open the event to see it and make the change again.');
    }

    /** "title", "title and location", "title, location and time". */
    private static function listOf(array $names): string
    {
        if (count($names) <= 1) {
            return (string) ($names[0] ?? '');
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    /**
     * A served value as a person reads it in a sentence: text in quotes,
     * a time as its days and clock, '' when it is not worth quoting (notes,
     * Planned or Maybe), null when it is empty.
     */
    private static function valueWords(string $field, mixed $value): ?string
    {
        if ($field === 'time') {
            return is_array($value) ? self::timeWords($value) : '';
        }
        if ($field === 'status') {
            return $value === 'tentative' ? 'Maybe' : 'Planned';
        }
        if ($field === 'description') {
            return '';
        }
        $text = trim((string) $value);
        return $text === '' ? null : "'" . $text . "'";
    }

    /** "Tue, Nov 18, 10:00 AM to 11:00 AM", "Tue, Nov 18, all day", on the clock the event is served on. */
    private static function timeWords(array $t): string
    {
        try {
            $start = new \DateTimeImmutable((string) ($t['start'] ?? ''));
            $end = new \DateTimeImmutable((string) ($t['end'] ?? ''));
        } catch (\Exception) {
            return '';
        }
        if (!empty($t['allDay'])) {
            $last = $end->modify('-1 day');
            return $last > $start ? $start->format('D, M j') . ' to ' . $last->format('D, M j') . ', all day' : $start->format('D, M j') . ', all day';
        }
        return $start->format('D, M j, g:i A') . ' to ' . ($start->format('Y-m-d') === $end->format('Y-m-d') ? $end->format('g:i A') : $end->format('D, M j, g:i A'));
    }

    /**
     * A field's value after the one normaliser that exists
     * (GoogleSeries::fieldsOf), nothing added. A value that is not of the
     * field's shape (a delete's) is kept as it is, so it equals no value of
     * that shape.
     */
    private static function normal(string $field, mixed $value): mixed
    {
        $row = ['title' => '', 'description' => null, 'location' => '', 'url' => null, 'status' => 'confirmed', 'start_utc' => '', 'end_utc' => '', 'all_day' => 0, 'tzid' => 'UTC'];
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
        return GoogleSeries::fieldsOf(array_merge($row, [$field => $value]))[$field];
    }
}
