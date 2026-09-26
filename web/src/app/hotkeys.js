// Single source of truth for keyboard shortcuts. keyboard.js builds its
// dispatch from this table and ShortcutsSheet renders it, so the cheat sheet
// can never drift from the real bindings. Pure data (no imports) so the
// smoke test loads it in Node.
//
// Entry shape: {id, keys:[...e.key values], label, group, display?:[...], mod?}
// display overrides how the keys render in the sheet (arrows, ranges).
// mod:true means the binding requires Cmd/Ctrl, so the plain dispatch skips
// it and the bare key stays free.

export const HOTKEY_GROUPS = ['Navigation', 'Views', 'Events', 'Overlays'];

export const HOTKEYS = [
  // Navigation
  { id: 'today', keys: ['t'], label: 'Go to today', group: 'Navigation' },
  { id: 'jump', keys: ['g'], label: 'Jump to date', group: 'Navigation' },
  { id: 'prevDay', keys: ['ArrowLeft'], display: ['←'], label: 'Back one day', group: 'Navigation' },
  { id: 'nextDay', keys: ['ArrowRight'], display: ['→'], label: 'Forward one day', group: 'Navigation' },
  { id: 'prevWeek', keys: ['ArrowUp'], display: ['↑'], label: 'Back one week', group: 'Navigation' },
  { id: 'nextWeek', keys: ['ArrowDown'], display: ['↓'], label: 'Forward one week', group: 'Navigation' },

  // Views
  { id: 'cycleView', keys: ['v'], label: 'Cycle through views', group: 'Views' },
  { id: 'relPlanned', keys: ['p'], label: 'Show or hide planned events', group: 'Views' },
  { id: 'relMaybe', keys: ['m'], label: 'Show or hide maybe events', group: 'Views' },
  { id: 'relAvailable', keys: ['a'], label: 'Show or hide available events', group: 'Views' },
  { id: 'relContext', keys: ['x'], label: 'Show or hide context (information) events', group: 'Views' },
  { id: 'setView', keys: ['1', '2', '3', '4', '5', '6', '7'], display: ['1-7'], label: 'Views in switcher order', group: 'Views' },
  { id: 'defaultView', keys: ['0'], label: 'Default view (reset filters, month, today)', group: 'Views' },

  // Events
  { id: 'quickAdd', keys: ['c'], label: 'Quick add (plain language)', group: 'Events' },
  { id: 'newEvent', keys: ['n'], label: 'New event (full editor)', group: 'Events' },
  { id: 'openDetail', keys: ['o'], label: 'Open full detail (popover open)', group: 'Events' },
  { id: 'editEvent', keys: ['e'], label: 'Edit event (popover or detail open)', group: 'Events' },
  { id: 'reschedule', keys: ['r'], label: 'Reschedule (popover open)', group: 'Events' },
  { id: 'deleteEvent', keys: ['Delete', 'Backspace'], display: ['Del'], label: 'Delete event (popover or detail open)', group: 'Events' },
  { id: 'detailPrev', keys: ['['], label: 'Previous event that day (event open)', group: 'Events' },
  { id: 'detailNext', keys: [']'], label: 'Next event that day (event open)', group: 'Events' },

  // Overlays
  { id: 'palette', keys: ['k'], mod: true, display: ['⌘/Ctrl', 'K'], label: 'Command palette', group: 'Overlays' },
  { id: 'search', keys: ['/'], label: 'Search all events', group: 'Overlays' },
  { id: 'filter', keys: ['f'], label: 'Filter visible events', group: 'Overlays' },
  { id: 'shortcuts', keys: ['?'], label: 'Keyboard shortcuts', group: 'Overlays' },
  { id: 'escape', keys: ['Escape'], display: ['Esc'], label: 'Close open overlay', group: 'Overlays' },
];
