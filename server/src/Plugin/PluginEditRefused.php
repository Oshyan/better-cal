<?php

declare(strict_types=1);

namespace BetterCal\Plugin;

/**
 * Thrown by EditablePlugin::applyEdit when the source system refuses a
 * change. Its message is shown to the person as written, so keep it to one
 * plain sentence and never put a credential in it.
 */
final class PluginEditRefused extends \RuntimeException
{
}
