<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

/**
 * A rule the expander can't read (Rule::table): outside RFC 5545's grammar,
 * or with a part its table marks N/A for the rule's frequency. Refused where
 * a rule is written (a 400 invalid_rrule), field changes only where one is
 * stored (Imported Rules, by Capability).
 */
final class RuleNotReadable extends \InvalidArgumentException
{
}
