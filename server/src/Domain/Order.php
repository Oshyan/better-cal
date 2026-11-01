<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * Places in a list kept as sparse numbers (#187): a step apart, so putting
 * one thing between two others changes only that one, and its undo is an
 * ordinary change to one row. Only when there's no room left between two
 * neighbours is the whole list numbered afresh. Pure.
 */
final class Order
{
    public const STEP = 1024;

    /**
     * New positions for putting $id at place $at (0 first; past the end is
     * the end) in a list given in its order as [[id, position], ...]
     * (with or without $id itself): id => position, only what changes.
     *
     * @param list<array{0:int,1:int}> $ordered
     * @return array<int,int>
     */
    public static function place(array $ordered, int $id, int $at): array
    {
        $rest = array_values(array_filter($ordered, static fn(array $r) => (int) $r[0] !== $id));
        $at = max(0, min($at, count($rest)));
        $prev = $at > 0 ? (int) $rest[$at - 1][1] : null;
        $next = $at < count($rest) ? (int) $rest[$at][1] : null;
        if ($prev === null && $next === null) {
            return [$id => self::STEP];
        }
        if ($prev === null) {
            return [$id => $next - self::STEP];
        }
        if ($next === null) {
            return [$id => $prev + self::STEP];
        }
        if ($next - $prev >= 2) {
            return [$id => intdiv($prev + $next, 2)];
        }
        // No room between them: number the whole list afresh.
        $ids = array_map(static fn(array $r) => (int) $r[0], $rest);
        array_splice($ids, $at, 0, [$id]);
        $was = [];
        foreach ($ordered as $r) {
            $was[(int) $r[0]] = (int) $r[1];
        }
        $out = [];
        foreach ($ids as $i => $each) {
            $pos = ($i + 1) * self::STEP;
            if (($was[$each] ?? null) !== $pos) {
                $out[$each] = $pos;
            }
        }
        return $out;
    }
}
