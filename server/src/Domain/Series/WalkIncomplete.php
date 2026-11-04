<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

/**
 * A series' walk that could not be completed: a limit of the walk was
 * reached (WalkLimitReached) or the iterator failed on the way. It is never
 * read as the series ending; the model reads the rule as one the expander
 * can't read instead.
 */
class WalkIncomplete extends \RuntimeException
{
}
