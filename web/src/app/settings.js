// Client-side settings enforcement. The server stores and validates settings
// (docs/api-contract.md, Settings); this module applies them in the client:
// week start and time format thread into the date utils, theme stamps a root
// attribute the CSS keys off, defaultView picks the boot view. Kept free of
// api.js so fetchMe can adopt settings without an import cycle.

import { setWeekStart, setTimeFormat } from '../lib/dates.js';
import { state, set } from './store.js';

const VALID_VIEWS = ['month', 'weeks3', 'weeks2', 'week', 'day', 'agenda'];

const THEME_KEY = 'bc-theme';
const DARK_MQ = typeof window !== 'undefined' && window.matchMedia
  ? window.matchMedia('(prefers-color-scheme: dark)') : null;
// The pinned theme, if any. Seeded from the root attribute because
// index.html stamps the remembered choice before any module runs.
let pinnedTheme = (() => {
  const t = typeof document !== 'undefined' ? document.documentElement.dataset.theme : null;
  return t === 'light' || t === 'dark' ? t : null;
})();

// Side effects only: push the current values into the date utils and theme.
export function applySettings(settings) {
  if (!settings) return;
  setWeekStart(settings.weekStart);
  setTimeFormat(settings.timeFormat);
  applyTheme(settings.theme);
}

// Stamp the root (CSS keys off it, and it must beat prefers-color-scheme in
// both directions), remember the choice for the next boot (index.html reads
// it before the stylesheet, so a pinned theme never flashes the other one),
// then resolve what is actually showing for everything CSS cannot reach.
export function applyTheme(theme) {
  pinnedTheme = theme === 'light' || theme === 'dark' ? theme : null;
  const root = document.documentElement;
  if (pinnedTheme) root.dataset.theme = pinnedTheme; else delete root.dataset.theme;
  try {
    if (pinnedTheme) localStorage.setItem(THEME_KEY, pinnedTheme);
    else localStorage.removeItem(THEME_KEY);
  } catch { /* storage may be unavailable; the server setting still applies next boot */ }
  syncResolved();
}

export function isDark() {
  return pinnedTheme ? pinnedTheme === 'dark' : !!(DARK_MQ && DARK_MQ.matches);
}

function syncResolved() {
  const dark = isDark();
  // Browser chrome (Android toolbar, PWA title bar) matches the app bar,
  // whichever ground is showing.
  const bg = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim()
    || (dark ? '#16181d' : '#f6f7f9');
  for (const m of document.querySelectorAll('meta[name="theme-color"]')) m.setAttribute('content', bg);
  if (state.darkMode !== dark) {
    // occVersion bump: chips memoise on it, and their ink colour depends on
    // the ground (lib/color.js ink), so they must redraw.
    set({ darkMode: dark, occVersion: state.occVersion + 1 });
  }
}

// System theme: follow the OS live, not just at boot.
if (DARK_MQ) DARK_MQ.addEventListener('change', () => { if (!pinnedTheme) syncResolved(); });

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
