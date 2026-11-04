<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

/**
 * The rule iterator looked at as many periods as it allows for one
 * occurrence without finding it. That is a limit of the walk, not the end
 * of the rule, so it is never read as one: the expansion stops with this
 * error instead of a rule that seems to have ended.
 */
final class WalkLimitReached extends WalkIncomplete
{
}
