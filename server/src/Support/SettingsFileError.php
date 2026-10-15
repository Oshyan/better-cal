<?php

declare(strict_types=1);

namespace BetterCal\Support;

/** The settings file can't be trusted; the message says why and what to do. */
final class SettingsFileError extends \RuntimeException
{
}
