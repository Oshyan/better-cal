// The desktop sidebar's width (#129): dragged at its right edge, remembered
// per device, as the week view remembers its zoom. One CSS variable carries
// it (--bc-sidebar-w on the root); the drawer layouts keep their own widths.
//
// Remembered per screen: a width that suits a large monitor is too much on a
// laptop's screen, and changing resolution changed nothing. Each screen size
// keeps its own; a screen not seen before starts from the width last set on
// another, scaled by how much wider or narrower it is. Kept in this browser
// only, like the zoom.

export const SIDEBAR_KEY = 'bc-sidebar-w';
export const SIDEBAR_DEFAULT = 256;
export const SIDEBAR_MIN = 200;
export const SIDEBAR_MAX = 500;
const MAX_SCREENS = 8;

export function clampSidebarWidth(w) {
  const n = Math.round(Number(w));
  if (!Number.isFinite(n)) return SIDEBAR_DEFAULT;
  return Math.min(SIDEBAR_MAX, Math.max(SIDEBAR_MIN, n));
}

/** The screen's size, "1920x1080": the screen, not the window. */
export function screenKey() {
  try {
    const s = globalThis.screen;
    return s && s.width && s.height ? s.width + 'x' + s.height : 'screen';
  } catch { return 'screen'; }
}

// { widths: { "1920x1080": 300, ... }, last: "1920x1080" }. A plain number
// (before 1.4.3) was one width for every screen: it becomes the last set.
function load() {
  try {
    const raw = localStorage.getItem(SIDEBAR_KEY);
    if (raw == null) return { widths: {}, last: null };
    if (/^\d+(\.\d+)?$/.test(raw.trim())) return { widths: { screen: clampSidebarWidth(raw) }, last: 'screen', legacy: true };
    const v = JSON.parse(raw);
    if (v && typeof v === 'object' && v.widths && typeof v.widths === 'object') return { widths: v.widths, last: v.last || null };
  } catch { /* unreadable: start over */ }
  return { widths: {}, last: null };
}

const widthOf = (key) => Number(String(key).split('x')[0]) || 0;

/** The width for this screen: its own, or scaled from the last one set. */
export function readSidebarWidth(key = screenKey()) {
  const { widths, last, legacy } = load();
  if (widths[key] != null) return clampSidebarWidth(widths[key]);
  if (!last || widths[last] == null) return SIDEBAR_DEFAULT;
  const from = widthOf(last);
  const to = widthOf(key);
  if (legacy || !from || !to) return clampSidebarWidth(widths[last]);
  return clampSidebarWidth(widths[last] * (to / from));
}

/** Show a width now (while dragging, and at boot before the first frame). */
export function applySidebarWidth(w) {
  try { document.documentElement.style.setProperty('--bc-sidebar-w', clampSidebarWidth(w) + 'px'); } catch { /* no DOM */ }
}

/** Remember a width for this screen; null means the default. */
export function saveSidebarWidth(w, key = screenKey()) {
  const n = w == null ? SIDEBAR_DEFAULT : clampSidebarWidth(w);
  const { widths } = load();
  const next = { ...widths };
  delete next.screen; // a pre-1.4.3 width, now this screen's
  delete next[key];
  next[key] = n; // last set goes last; the oldest screens drop off
  const keys = Object.keys(next);
  for (const k of keys.slice(0, Math.max(0, keys.length - MAX_SCREENS))) delete next[k];
  try { localStorage.setItem(SIDEBAR_KEY, JSON.stringify({ widths: next, last: key })); } catch { /* storage unavailable: lasts until reload */ }
  applySidebarWidth(n);
  return n;
}

/**
 * Follow the window onto another screen, or a change of resolution: apply
 * that screen's width and tell the sidebar (a 'bc-sidebar-width' event).
 */
export function followScreen() {
  let key = screenKey();
  const check = () => {
    const now = screenKey();
    if (now === key) return;
    key = now;
    const w = readSidebarWidth(now);
    applySidebarWidth(w);
    window.dispatchEvent(new CustomEvent('bc-sidebar-width', { detail: w }));
  };
  // A window dragged to another screen may keep its size: Chrome says so
  // on the screen itself.
  const scr = globalThis.screen && globalThis.screen.addEventListener ? globalThis.screen : null;
  window.addEventListener('resize', check);
  if (scr) scr.addEventListener('change', check);
  return () => {
    window.removeEventListener('resize', check);
    if (scr) scr.removeEventListener('change', check);
  };
}
