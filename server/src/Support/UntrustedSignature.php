<?php

declare(strict_types=1);

namespace BetterCal\Support;

/**
 * A well-formed release signature that no key this copy of Better-Cal lists
 * verifies. Kept apart from the other signature failures because it has an
 * innocent cause the updater can work around: a key added by a release this
 * copy hasn't installed yet (Domain\Updates, #194).
 */
final class UntrustedSignature extends \RuntimeException
{
}
