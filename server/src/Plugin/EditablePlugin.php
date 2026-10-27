<?php

declare(strict_types=1);

namespace BetterCal\Plugin;

/**
 * A plugin whose calendars can be rescheduled from Better-Cal: dragging an
 * event, the Move button, the editor's dates, the API and MCP all reach the
 * source system through here. Declare `events:edit` in plugin.json too.
 *
 * This is the one place plugin code runs while a person waits on a request,
 * as a Google calendar's write-through does: the edit is the person's own
 * act, and they need the source system's answer before the calendar shows
 * it. Keep it to the write itself.
 */
interface EditablePlugin
{
    /**
     * Apply a new time to one of your events in the system it came from.
     *
     * $edit = {sourceKey, eventId, calendarId, title, before: span, after: span}
     * where a span is {start, end, allDay}: 'YYYY-MM-DD' dates for all-day
     * (end exclusive), ISO instants otherwise, the same shapes syncEvents
     * takes.
     *
     * Return the span the source now holds (usually `after`, but it may
     * differ, say a date-only field that keeps the day and drops a time).
     * Throw PluginEditRefused with a sentence the person can read when the
     * source refuses; the event is put back where it was.
     *
     * @param array<string,mixed> $edit
     * @return array{start:string,end:string,allDay:bool}
     */
    public function applyEdit(PluginHost $host, array $edit): array;
}
