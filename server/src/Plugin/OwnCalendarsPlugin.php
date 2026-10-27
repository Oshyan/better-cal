<?php

declare(strict_types=1);

namespace BetterCal\Plugin;

/**
 * A plugin whose calendars the person adds one at a time, each with its own
 * settings (a tide station per calendar, a place per weather calendar).
 * Declare `ownCalendars` and `ownCalendarSettings` in plugin.json; the job
 * then reads them with PluginHost::ownCalendars().
 *
 * Implementing this interface is optional: it adds a check of one
 * calendar's settings beyond the schema's own, run on an explicit save like
 * validateSettings.
 */
interface OwnCalendarsPlugin
{
    /**
     * Check one calendar's complete settings (the stored values with this
     * save's changes merged in, defaults filled). Return key => message for
     * what's wrong; an empty array accepts.
     *
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    public function validateCalendarSettings(array $values): array;
}
