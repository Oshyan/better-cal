// The desktop sidebar's width (#129): dragged at its right edge, remembered
// per device, as the week view remembers its zoom. One CSS variable carries
// it (--bc-sidebar-w on the root); the drawer layouts keep their own widths.

export const SIDEBAR_KEY = 'bc-sidebar-w';
export const SIDEBAR_DEFAULT = 256;
export const SIDEBAR_MIN = 200;
export const SIDEBAR_MAX = 420;

export function clampSidebarWidth(w) {
  const n = Math.round(Number(w));
  if (!Number.isFinite(n)) return SIDEBAR_DEFAULT;
  return Math.min(SIDEBAR_MAX, Math.max(SIDEBAR_MIN, n));
}

/** The remembered width, or the default. */
export function readSidebarWidth() {
  try {
    const raw = localStorage.getItem(SIDEBAR_KEY);
    return raw == null ? SIDEBAR_DEFAULT : clampSidebarWidth(raw);
  } catch { return SIDEBAR_DEFAULT; }
}

/** Show a width now (while dragging, and at boot before the first frame). */
export function applySidebarWidth(w) {
  try { document.documentElement.style.setProperty('--bc-sidebar-w', clampSidebarWidth(w) + 'px'); } catch { /* no DOM */ }
}

/** Remember a width; null or the default forgets it. */
export function saveSidebarWidth(w) {
  const n = w == null ? SIDEBAR_DEFAULT : clampSidebarWidth(w);
  try {
    if (n === SIDEBAR_DEFAULT) localStorage.removeItem(SIDEBAR_KEY);
    else localStorage.setItem(SIDEBAR_KEY, String(n));
  } catch { /* storage unavailable: lasts until reload */ }
  applySidebarWidth(n);
  return n;
}
