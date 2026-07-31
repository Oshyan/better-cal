// Reminder helpers: offset formatting, all-day default conversion, and a
// client-side mirror of the server's effective-reminder resolution (event
// override > calendar default > global default; subscribed calendars never
// inherit the global default). Pure module, smoke-tested in node.

// Offsets offered by the editor / settings selects (minutes before start;
// for all-day events, before local midnight of the event date).
export const TIMED_CHOICES = [0, 5, 10, 15, 30, 60, 120, 1440, 10080];
export const ALLDAY_CHOICES = [
  { label: 'At midnight', minutes: 0 },
  { label: 'Day before at 6 PM', minutes: 360 },
  { label: 'Day before at noon', minutes: 720 },
  { label: '2 days before at 6 PM', minutes: 1800 },
  { label: 'Week before at 6 PM', minutes: 9000 },
];
export const ALLDAY_DAYS_CHOICES = [
  [0, 'Same day'], [1, 'Day before'], [2, '2 days before'], [7, 'Week before'],
  [14, '2 weeks before'], [28, '4 weeks before'],
];

// Custom number + unit inputs (server cap: 4 weeks = 40320 minutes).
export const REMINDER_UNITS = ['minutes', 'hours', 'days', 'weeks'];
export const UNIT_MINUTES = { minutes: 1, hours: 60, days: 1440, weeks: 10080 };
export const MAX_REMINDER_MINUTES = 40320;

// (n, unit) -> whole minutes, clamped to the server's accepted range.
export function toMinutes(n, unit) {
  const mult = UNIT_MINUTES[unit] || 1;
  const v = Math.round((Number(n) || 0) * mult);
  return Math.max(0, Math.min(MAX_REMINDER_MINUTES, v));
}

// minutes -> {n, unit} using the largest unit that divides cleanly
// (10080 -> 1 week, 120 -> 2 hours, 90 -> 90 minutes, 0 -> 0 minutes).
export function fromMinutes(minutes) {
  const m = Math.max(0, Number(minutes) || 0);
  for (const unit of ['weeks', 'days', 'hours']) {
    if (m > 0 && m % UNIT_MINUTES[unit] === 0) return { n: m / UNIT_MINUTES[unit], unit };
  }
  return { n: m, unit: 'minutes' };
}

// "10 minutes before", "1 hour before", "At start", "2 days before", "1 week before".
export function fmtOffsetMinutes(minutes) {
  const m = Number(minutes) || 0;
  if (m === 0) return 'At start';
  if (m % 10080 === 0) {
    const w = m / 10080;
    return w === 1 ? '1 week before' : w + ' weeks before';
  }
  if (m % 1440 === 0) {
    const d = m / 1440;
    return d === 1 ? '1 day before' : d + ' days before';
  }
  if (m % 60 === 0) {
    const h = m / 60;
    return h === 1 ? '1 hour before' : h + ' hours before';
  }
  return m === 1 ? '1 minute before' : m + ' minutes before';
}

// Human text for one reminder entry of either shape.
export function fmtReminder(entry) {
  if (!entry || typeof entry !== 'object') return '';
  if (entry.minutes != null) return fmtOffsetMinutes(entry.minutes);
  if (entry.daysBefore != null) {
    const when = entry.daysBefore === 0 ? 'Same day'
      : entry.daysBefore === 1 ? 'Day before'
      : entry.daysBefore + ' days before';
    return when + (entry.time ? ' at ' + entry.time : '');
  }
  return '';
}

// {daysBefore, time} -> minutes before local midnight of the event date.
// Day before at 18:00 = 360 minutes before midnight. Same-day times land
// after midnight and clamp to 0 (event overrides only carry >= 0 offsets).
export function allDayEntryToMinutes(entry) {
  const [h, mi] = String((entry && entry.time) || '00:00').split(':').map(Number);
  const m = (Number(entry && entry.daysBefore) || 0) * 1440 - ((h || 0) * 60 + (mi || 0));
  return Math.max(0, m);
}

// Any entry -> minutes offset (override shape).
export function entryToMinutes(entry) {
  if (entry && entry.minutes != null) return Math.max(0, Number(entry.minutes) || 0);
  if (entry && entry.daysBefore != null) return allDayEntryToMinutes(entry);
  return 0;
}

// Unique + ascending list of {minutes} entries.
export function normalizeMinutesList(minutes) {
  return [...new Set(minutes.map((m) => Math.max(0, Number(m) || 0)))]
    .sort((a, b) => a - b)
    .map((m) => ({ minutes: m }));
}

// Mirror of the server's Reminders::effective (docs/api-contract.md).
// Returns {reminders, source: 'event'|'calendar'|'default'}.
export function effectiveReminders({ override, calendarDefaults, settings, allDay, calendarKind }) {
  if (override != null) return { reminders: override, source: 'event' };
  if (calendarDefaults != null) {
    const list = allDay ? (calendarDefaults.allDay || []) : (calendarDefaults.timed || []);
    return { reminders: list, source: 'calendar' };
  }
  if (calendarKind === 'subscribed') return { reminders: [], source: 'default' };
  const s = settings || {};
  return {
    reminders: (allDay ? s.reminderAllDay : s.reminderTimed) || [],
    source: 'default',
  };
}
