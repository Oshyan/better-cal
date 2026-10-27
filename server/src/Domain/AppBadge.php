<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * The number on the installed app's icon (#170), as chosen in Settings
 * (appBadge): none, Review items waiting, events today, or events still to
 * come today. The page and the service worker both ask the server, so the
 * icon says the same thing whether the app is open or not.
 */
final class AppBadge
{
    public const MODES = ['none', 'review', 'today', 'remaining'];

    /**
     * Events that count for a day: on a calendar shown, planned or maybe
     * (not opportunities you haven't picked, context like weather, hidden or
     * cancelled ones), not a trip's container, and the same event on two
     * calendars once. "Remaining" keeps the timed ones that haven't ended, so
     * the count falls through the day and reaches nothing once it's over.
     *
     * The window is asked for in instants, so it also returns all-day events
     * on a neighbouring date (their dates sit at UTC midnight); those are
     * kept to the day's own date here, as the calendar draws them.
     *
     * @param list<array<string, mixed>> $occurrences from Events::window
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable} $day from dayBounds
     */
    public static function countEvents(array $occurrences, bool $remainingOnly, \DateTimeImmutable $now, array $day): int
    {
        [$dayStart, $dayEnd] = $day;
        $dayKey = $dayStart->format('Y-m-d');
        $seen = [];
        foreach ($occurrences as $o) {
            if (!in_array($o['relationship'] ?? '', ['planned', 'maybe'], true)) continue;
            if (($o['status'] ?? '') === 'cancelled' || !empty($o['isContainer'])) continue;
            $start = (string) ($o['start'] ?? '');
            $end = (string) ($o['end'] ?? '');
            if (!empty($o['allDay'])) {
                if ($remainingOnly) continue;
                $first = substr($start, 0, 10);
                $last = max($first, substr($end, 0, 10)); // end date is exclusive
                if ($dayKey < $first || ($dayKey >= $last && $last !== $first) || ($last === $first && $dayKey !== $first)) continue;
            } else {
                try {
                    $s = new \DateTimeImmutable($start);
                    $e = new \DateTimeImmutable($end);
                } catch (\Exception) {
                    continue;
                }
                if ($e <= $dayStart || $s >= $dayEnd) continue;
                if ($remainingOnly && $e <= $now) continue;
            }
            // Copies of one event can sit in different zones, so a timed start
            // compares as an instant; an all-day one is a plain date.
            $dupes = is_array($o['dupes'] ?? null) ? $o['dupes'] : [];
            $group = isset($dupes[0]['groupId']) ? 'g' . $dupes[0]['groupId'] : 'e' . ($o['eventId'] ?? $o['instanceId'] ?? '');
            $when = !empty($o['allDay']) ? substr($start, 0, 10) : (string) (strtotime($start) ?: $start);
            $seen[$group . '|' . $when] = true;
        }
        return count($seen);
    }

    /** Today in the given zone (the device's, or the Home zone), as [start, end). */
    public static function dayBounds(\DateTimeImmutable $now, \DateTimeZone $tz): array
    {
        $start = $now->setTimezone($tz)->setTime(0, 0);
        return [$start, $start->modify('+1 day')];
    }
}
