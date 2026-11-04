<?php
// Ground truth for which instants a stored series produces: the harness's
// own evaluator of the rule (Rfc), from the series' start through its
// bound, with no skipped days applied. A readable rule is never oracled by
// the expander it checks; only a rule the expander can't read by design
// (RSCALE, an X- part) is read through it, and those are refused. A bounded
// series is read whole; an unbounded one over a window from its start, and
// 'through' says how far. The window's expander (Recurrence::sabreExpand)
// is read by window() alone, for the checks that compare it (c-occurrences,
// c-window).
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Domain\Recurrence;

final class Truth
{
    public const WINDOW_DAYS = 740;
    /** An unbounded series is read at least through this many occurrences, however sparse its days. */
    public const WINDOW_OCCURRENCES = 6;

    /**
     * @return array{occ:list<array{key:string,dn:int,secs:int}>,through:?string}
     *  occ: the raw occurrences, each with its local day and clock in the
     *  series' zone; through: null when occ is the whole series, else the
     *  key before which it holds every occurrence.
     */
    public static function expand(array $row, ?int $limit = null): array
    {
        $ev = Rfc::ofRow($row);
        if ($ev->refused) {
            return self::expandByExpander($row, $limit);
        }
        if ($limit !== null) {
            $occ = $ev->first($limit);
            $whole = count($occ) < $limit && $ev->complete;
            return ['occ' => self::out($occ), 'through' => $whole || $occ === [] ? null : Clock::db(new \DateTimeImmutable('@' . (end($occ)[0] + 1)))];
        }
        if ($ev->bounded()) {
            $occ = $ev->all();
            // An incomplete evaluation (c-occurrences fails it) holds what was read.
            return ['occ' => self::out($occ), 'through' => $ev->complete ? null : Clock::db(new \DateTimeImmutable('@' . ((end($occ)[0] ?? 0) + 1)))];
        }
        $end = self::windowEnd($row);
        return ['occ' => self::out($ev->through($end - 1)), 'through' => Clock::db(new \DateTimeImmutable('@' . $end))];
    }

    /** The end of an unbounded series' window: a span by frequency from its start, or its sixth occurrence's next day when that is later. */
    public static function windowEnd(array $row): int
    {
        $start = Clock::parse((string) $row['start_utc']);
        $freq = Shape::parts((string) $row['rrule'])['FREQ'] ?? '';
        $days = match ($freq) {
            'DAILY', 'WEEKLY' => self::WINDOW_DAYS,
            'MONTHLY' => self::WINDOW_DAYS * 4,
            'YEARLY' => 9150,
            default => 20,
        };
        $end = $start->modify('+' . $days . ' days')->getTimestamp();
        $first = Rfc::ofRow($row)->first(self::WINDOW_OCCURRENCES);
        return count($first) === self::WINDOW_OCCURRENCES ? max($end, end($first)[0] + 86400) : $end;
    }

    /** @param list<array{0:int,1:int,2:int}> $occ */
    private static function out(array $occ): array
    {
        return array_map(static fn(array $o): array => ['key' => gmdate('Y-m-d H:i:s', $o[0]), 'dn' => $o[1], 'secs' => $o[2]], $occ);
    }

    /** A rule the expander can't read (refused by design): what the expander gives, which is nothing it can read. */
    private static function expandByExpander(array $row, ?int $limit): array
    {
        $tz = self::tzOf($row);
        $start = Clock::parse((string) $row['start_utc']);
        $winEnd = $start->modify('+20 days');
        $out = [];
        foreach (Recurrence::sabreExpand(['exdates_json' => null, 'uid' => (string) ($row['uid'] ?? 'harness')] + $row, $start->modify('-1 day'), $winEnd) as $occ) {
            $local = $occ['start']->setTimezone(Clock::zone($tz));
            $out[] = ['key' => Clock::db($occ['start']), 'dn' => Clock::dnOf($local), 'secs' => Clock::secsOf($local)];
            if ($limit !== null && count($out) >= $limit) {
                break;
            }
        }
        return ['occ' => $out, 'through' => Clock::db($winEnd)];
    }

    /** The window's expander (Recurrence::sabreExpand): the starts in [$from, $to), as keys. @return list<string> */
    public static function window(array $row, int $from, int $to): array
    {
        $master = ['exdates_json' => null, 'uid' => (string) ($row['uid'] ?? 'harness')] + $row;
        $keys = [];
        foreach (Recurrence::sabreExpand($master, new \DateTimeImmutable('@' . $from), new \DateTimeImmutable('@' . $to)) as $occ) {
            $t = $occ['start']->getTimestamp();
            if ($t >= $from && $t < $to) {
                $keys[] = Clock::db($occ['start']);
            }
        }
        return $keys;
    }

    /** The zone a series' days are read in (UTC for an all-day series). */
    public static function tzOf(array $row): string
    {
        return (int) ($row['all_day'] ?? 0) === 1 ? 'UTC' : (string) ($row['tzid'] ?? 'UTC');
    }

    /** Whether $key is a raw occurrence of the row's rule. */
    public static function isOccurrence(array $row, string $key): bool
    {
        $ts = Clock::parse($key)->getTimestamp();
        if ($ts < Clock::parse((string) $row['start_utc'])->getTimestamp()) {
            return false;
        }
        $ev = Rfc::ofRow($row);
        if ($ev->refused) {
            return false;
        }
        return in_array($ts, array_column($ev->through($ts), 0), true);
    }

    /** The raw occurrences' keys from $from (inclusive) for $days days. @return list<string> */
    public static function keysFrom(array $row, string $from, int $days): array
    {
        $ev = Rfc::ofRow($row);
        if ($ev->refused) {
            return [];
        }
        $a = Clock::parse($from)->getTimestamp();
        $b = $a + $days * 86400;
        return array_values(array_map(static fn(array $o): string => gmdate('Y-m-d H:i:s', $o[0]), array_filter($ev->through($b), static fn(array $o): bool => $o[0] >= $a)));
    }

    /** Whether a key is an occurrence of an expansion, looked for beyond its window when needed. */
    public static function member(array $exp, array $row, string $key): bool
    {
        foreach ($exp['occ'] as $o) {
            if ($o['key'] === $key) {
                return true;
            }
        }
        return $exp['through'] !== null && $key >= $exp['through'] && self::isOccurrence($row, $key);
    }

    /** The occurrence on a local day, or null. */
    public static function onDay(array $exp, int $dn): ?array
    {
        foreach ($exp['occ'] as $o) {
            if ($o['dn'] === $dn) {
                return $o;
            }
        }
        return null;
    }
}
