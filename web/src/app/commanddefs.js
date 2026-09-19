// Command palette definitions. Pure data (no imports) so the smoke test can
// load it in Node and assert it against HOTKEYS — the palette, the keyboard
// map, and the cheat sheet all have to describe the same actions, and the
// only way to guarantee that is to make the palette name hotkey ids rather
// than restate their behaviour. commands.js attaches the handlers.
//
// Entry shape: {id, label, group, hotkey?, needs?, prompt?, keywords?, icon?}
//   hotkey   — id in HOTKEYS; the palette runs that same handler and shows
//              the key. Must resolve, or the smoke test fails.
//   needs    — context gate: 'occ' (popover or detail open) | 'popover'.
//              Gated commands are hidden, not shown-then-silently-ignored.
//   prompt   — the command asks for one more thing before it runs.
//   keywords — extra words that should match, beyond the label.
//   icon     — name in the host icon set (ui/icons.js). Rendered in the
//              row's leading slot; rows without one get an empty slot so
//              labels stay aligned.

export const COMMAND_GROUPS = ['Go to', 'Create', 'Event', 'Views', 'Calendars', 'People', 'Manage'];

export const STATIC_COMMANDS = [
  { id: 'today', icon: 'today', label: 'Go to today', group: 'Go to', hotkey: 'today', keywords: 'now current' },
  { id: 'goDate', icon: 'calendar', label: 'Go to date…', group: 'Go to', hotkey: 'jump', prompt: true, keywords: 'jump when' },
  { id: 'search', icon: 'search', label: 'Search all events', group: 'Go to', hotkey: 'search', keywords: 'find' },

  { id: 'newEvent', icon: 'plus', label: 'New event', group: 'Create', hotkey: 'newEvent', keywords: 'add create' },
  { id: 'quickAdd', icon: 'quickadd', label: 'Quick add…', group: 'Create', hotkey: 'quickAdd', keywords: 'plain language natural' },
  { id: 'newPerson', icon: 'people', label: 'New person…', group: 'Create', prompt: true, keywords: 'add create people contact friend' },

  { id: 'openDetail', icon: 'expand', label: 'Open full detail', group: 'Event', hotkey: 'openDetail', needs: 'occ' },
  { id: 'editEvent', icon: 'pencil', label: 'Edit event', group: 'Event', hotkey: 'editEvent', needs: 'occ' },
  { id: 'reschedule', icon: 'reschedule', label: 'Reschedule event', group: 'Event', hotkey: 'reschedule', needs: 'popover', keywords: 'move' },
  { id: 'deleteEvent', icon: 'trash', label: 'Delete event', group: 'Event', hotkey: 'deleteEvent', needs: 'occ', keywords: 'remove' },

  { id: 'calsAll', icon: 'visAll', label: 'Show all calendars', group: 'Calendars', keywords: 'visible every' },
  { id: 'calsNone', icon: 'visNone', label: 'Hide all calendars', group: 'Calendars', keywords: 'none invisible' },

  { id: 'shortcuts', icon: 'keyboard', label: 'Keyboard shortcuts', group: 'Manage', hotkey: 'shortcuts', keywords: 'keys help cheat sheet' },
];

// The Manage pages, shared with the sidebar footer so the two can't drift.
// [route, label]
export const MANAGE_ITEMS = [
  ['review', 'Review'],
  ['settings', 'Settings'],
  ['organize', 'Calendars & folders'],
  ['people', 'People'],
  ['activity', 'Activity'],
  ['plugins', 'Plugins'],
  ['filters', 'Filters'],
  ['views', 'Saved views'],
];

// Icons for the view ids in actions.js VIEWS (names in ui/icons.js).
export const VIEW_ICONS = {
  month: 'viewMonth',
  weeks3: 'viewWeeks3',
  weeks2: 'viewWeeks2',
  week: 'viewWeek',
  day: 'viewDay',
  agenda: 'viewAgenda',
};

// Human labels for the view ids in actions.js VIEWS.
export const VIEW_LABELS = {
  month: 'Month',
  weeks3: '3 weeks',
  weeks2: '2 weeks',
  week: 'Week',
  day: 'Day',
  agenda: 'Agenda',
};
