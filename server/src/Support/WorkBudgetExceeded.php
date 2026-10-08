<?php

declare(strict_types=1);

namespace BetterCal\Support;

/** A bounded operation ran out of work budget; callers decide how to resume or report it. */
final class WorkBudgetExceeded extends \RuntimeException
{
}
