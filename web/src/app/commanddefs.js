// Command palette definitions. Pure data (no imports) so the smoke test can
// load it in Node and assert it against HOTKEYS — the palette, the keyboard
// map, and the cheat sheet all have to describe the same actions, and the
// only way to guarantee that is to make the palette name hotkey ids rather
// than restate their behaviour. commands.js attaches the handlers.
//
// Entry shape: {id, label, group, hotkey?, needs?, prompt?, keywords?}
//   hotkey   — id in HOTKEYS; the palette runs that same handler and shows
//              the key. Must resolve, or the smoke test fails.
//   needs    — context gate: 'occ' (popover or detail open) | 'popover'.
//              Gated commands are hidden, not shown-then-silently-ignored.
//   prompt   — the command asks for one more thing before it runs.
//   keywords — extra words that should match, beyond the label.

export const COMMAND_GROUPS = ['Go to', 'Create', 'Event', 'Views', 'Calendars', 'People', 'Manage'];

export const STATIC_COMMANDS = [
  { id: 'today', label: 'Go to today', group: 'Go to', hotkey: 'today', keywords: 'now current' },
  { id: 'goDate', label: 'Go to date…', group: 'Go to', hotkey: 'jump', prompt: true, keywords: 'jump when' },
  { id: 'search', label: 'Search all events', group: 'Go to', hotkey: 'search', keywords: 'find' },

  { id: 'newEvent', label: 'New event', group: 'Create', hotkey: 'newEvent', keywords: 'add create' },
  { id: 'quickAdd', label: 'Quick add…', group: 'Create', hotkey: 'quickAdd', keywords: 'plain language natural' },

  { id: 'openDetail', label: 'Open full detail', group: 'Event', hotkey: 'openDetail', needs: 'occ' },
  { id: 'editEvent', label: 'Edit event', group: 'Event', hotkey: 'editEvent', needs: 'occ' },
  { id: 'reschedule', label: 'Reschedule event', group: 'Event', hotkey: 'reschedule', needs: 'popover', keywords: 'move' },
  { id: 'deleteEvent', label: 'Delete event', group: 'Event', hotkey: 'deleteEvent', needs: 'occ', keywords: 'remove' },

  { id: 'calsAll', label: 'Show all calendars', group: 'Calendars', keywords: 'visible every' },
  { id: 'calsNone', label: 'Hide all calendars', group: 'Calendars', keywords: 'none invisible' },

  { id: 'shortcuts', label: 'Keyboard shortcuts', group: 'Manage', hotkey: 'shortcuts', keywords: 'keys help cheat sheet' },
];

// The Manage pages, shared with the sidebar footer so the two can't drift.
// [route, label]
export const MANAGE_ITEMS = [
  ['settings', 'Settings'],
  ['organize', 'Calendars & folders'],
  ['people', 'People'],
  ['activity', 'Activity'],
  ['outfeeds', 'Outbound feeds'],
  ['filters', 'Filters'],
  ['views', 'Saved views'],
];

// Human labels for the view ids in actions.js VIEWS.
export const VIEW_LABELS = {
  month: 'Month',
  weeks3: '3 weeks',
  weeks2: '2 weeks',
  week: 'Week',
  day: 'Day',
  agenda: 'Agenda',
};
