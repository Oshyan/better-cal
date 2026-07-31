// Client-side settings enforcement. The server stores and validates settings
// (docs/api-contract.md, Settings); this module applies them in the client:
// week start and time format thread into the date utils, theme stamps a root
// attribute the CSS keys off, defaultView picks the boot view. Kept free of
// api.js so fetchMe can adopt settings without an import cycle.

import { setWeekStart, setTimeFormat } from '../lib/dates.js';
import { state, set } from './store.js';

const VALID_VIEWS = ['month', 'weeks3', 'weeks2', 'week', 'day', 'agenda'];

// Side effects only: push the current values into the date utils and theme.
export function applySettings(settings) {
  if (!settings) return;
  setWeekStart(settings.weekStart);
  setTimeFormat(settings.timeFormat);
  const theme = settings.theme;
  if (theme === 'light' || theme === 'dark') {
    document.documentElement.dataset.theme = theme;
  } else {
    delete document.documentElement.dataset.theme;
  }
}

// Merge server settings into the store and apply them. `initial` (boot/login)
// additionally honors defaultView and any persisted folder visibility state.
export function adoptSettings(settings, { initial = false } = {}) {
  if (!settings) return;
  const merged = { ...state.settings, ...settings };
  applySettings(merged);
  const patch = { settings: merged };
  if (initial) {
    if (VALID_VIEWS.includes(merged.defaultView)) patch.view = merged.defaultView;
    if (merged.folderVisibility && typeof merged.folderVisibility === 'object') {
      patch.folderVisibility = merged.folderVisibility;
    }
  }
  set(patch);
}
