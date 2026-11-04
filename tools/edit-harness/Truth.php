<?php
// Ground truth for which instants a stored series produces: the expander
// the app uses (Recurrence::sabreExpand) from the series' start through its
// bound, with no skipped days applied. A bounded series is read whole; an
// unbounded one over a window from its start, and 'through' says how far.
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Domain\Recurrence;

final class Truth
{
    public const WINDOW_DAYS = 740;

    /**
     * @return array{occ:list<array{key:string,dn:int,secs:int}>,through:?string}
     *  occ: the raw occurrences, each with its local day and clock in the
     *  series' zone; through: null when occ is the whole series, else the
     *  key before which it holds every occurrence.
     */
    public static function expand(array $row, ?int $limit = null): array
    {
        $tz = self::tzOf($row);
        $start = Clock::parse((string) $row['start_utc']);
        $p = Shape::parts((string) $row['rrule']);
        $freq = $p['FREQ'] ?? '';
        if (isset($p['COUNT'])) {
            $winEnd = $start->modify('+400 years');
        } elseif (isset($p['UNTIL'])) {
            $u = (string) $p['UNTIL'];
            $until = strlen($u) === 8
                ? new \DateTimeImmutable(substr($u, 0, 4) . '-' . substr($u, 4, 2) . '-' . substr($u, 6, 2), Clock::utc())
                : (\DateTimeImmutable::createFromFormat('Ymd\THis\Z', $u, Clock::utc()) ?: $start);
            $winEnd = max($start->modify('+2 days'), $until->modify('+2 days'));
        } else {
            $days = match ($freq) {
                'DAILY', 'WEEKLY' => self::WINDOW_DAYS,
                'MONTHLY' => self::WINDOW_DAYS * 4,
                'YEARLY' => 9150,
                default => 20,
            };
            $winEnd = $start->modify('+' . $days . ' days');
        }
        $through = isset($p['COUNT']) || isset($p['UNTIL']) ? null : Clock::db($winEnd);
        $master = ['exdates_json' => null, 'uid' => (string) ($row['uid'] ?? 'harness')] + $row;
        $found = Recurrence::sabreExpand($master, $start->modify('-1 day'), $winEnd);
        if (count($found) > Recurrence::MAX_INSTANCES) {
            array_pop($found);
            $through = Clock::db(end($found)['start']->modify('+1 second'));
        }
        $out = [];
        foreach ($found as $occ) {
            $local = $occ['start']->setTimezone(Clock::zone($tz));
            $out[] = ['key' => Clock::db($occ['start']), 'dn' => Clock::dnOf($local), 'secs' => Clock::secsOf($local)];
            if ($limit !== null && count($out) >= $limit) {
                $through = Clock::db($occ['start']->modify('+1 second'));
                break;
            }
        }
        return ['occ' => $out, 'through' => $through];
    }

    /** The zone a series' days are read in (UTC for an all-day series). */
    public static function tzOf(array $row): string
    {
        return (int) ($row['all_day'] ?? 0) === 1 ? 'UTC' : (string) ($row['tzid'] ?? 'UTC');
    }

    /** Whether $key is a raw occurrence of the row's rule, read around it. */
    public static function isOccurrence(array $row, string $key): bool
    {
        $at = Clock::parse($key);
        $master = ['exdates_json' => null, 'uid' => (string) ($row['uid'] ?? 'harness')] + $row;
        if ($at < Clock::parse((string) $row['start_utc'])) {
            return false;
        }
        foreach (Recurrence::sabreExpand($master, $at->modify('-1 second'), $at->modify('+1 second')) as $occ) {
            if (Clock::db($occ['start']) === $key) {
                return true;
            }
        }
        return false;
    }

    /** The raw occurrences' keys from $from (inclusive) for $days days. @return list<string> */
    public static function keysFrom(array $row, string $from, int $days): array
    {
        $at = Clock::parse($from);
        $master = ['exdates_json' => null, 'uid' => (string) ($row['uid'] ?? 'harness')] + $row;
        return array_map(static fn(array $o): string => Clock::db($o['start']), Recurrence::sabreExpand($master, $at->modify('-1 second'), $at->modify('+' . $days . ' days')));
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
