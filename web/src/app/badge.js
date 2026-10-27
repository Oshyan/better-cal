// The number on the installed app's icon (#170): Settings' "Show on app
// icon" picks none, the Review count, events today, or events still to come
// today. The server works the number out (GET /badge), so the page and the
// service worker (sw.js, on a push and on periodic background sync) always
// agree. Where the browser has no Badging API this does nothing.

import { api } from './api.js';
import { state } from './store.js';
import { localTz, todayKey } from '../lib/dates.js';

const SYNC_TAG = 'bc-badge';
// A day's count drifts as events end, with nothing on the server changing:
// while the app is open it's asked again at most this often for those modes.
const TIMED_REFRESH_MS = 5 * 60 * 1000;

let lastAt = 0;
let lastDay = '';
let inFlight = false;

export function badgeSupported() {
  return typeof navigator !== 'undefined' && 'setAppBadge' in navigator;
}

function mode() {
  return (state.settings && state.settings.appBadge) || 'none';
}

async function apply(count) {
  try {
    if (count > 0) await navigator.setAppBadge(count);
    else await navigator.clearAppBadge();
  } catch { /* not installed, or the platform refused: nothing to show */ }
}

// Called on every change check (App.js): asks again when something moved,
// when the day turned over, or now and then for the time-based modes.
export async function refreshBadge({ changed = true } = {}) {
  if (!badgeSupported() || inFlight) return;
  const m = mode();
  if (m === 'none') { lastAt = 0; return; }
  const day = todayKey();
  const due = changed || day !== lastDay || ((m === 'today' || m === 'remaining') && Date.now() - lastAt > TIMED_REFRESH_MS);
  if (!due) return;
  inFlight = true;
  try {
    const d = await api('/badge?tz=' + encodeURIComponent(localTz()));
    lastAt = Date.now();
    lastDay = day;
    await apply(d && d.show !== 'none' ? Number(d.count) || 0 : 0);
  } catch { /* offline: keep what's showing until the next check */ }
  inFlight = false;
}

// Signed out: nothing on the icon, and no background updates.
export async function clearBadge() {
  if (!badgeSupported()) return;
  lastAt = 0;
  lastDay = '';
  await apply(0);
  await syncRegistration(false);
}

// The setting changed (here or on another device): show the new choice now,
// and keep background updates registered only while it's on.
export async function badgeSettingChanged() {
  if (!badgeSupported()) return;
  const m = mode();
  if (m === 'none') await apply(0);
  else await refreshBadge({ changed: true });
  await syncRegistration(m !== 'none');
}

// Periodic background sync (Chrome and Edge, installed app only, about once
// a day at a time the browser picks) keeps the number fresh when the app
// isn't open. Elsewhere it's simply unavailable.
async function syncRegistration(on) {
  try {
    if (!('serviceWorker' in navigator)) return;
    const reg = await navigator.serviceWorker.getRegistration();
    if (!reg || !reg.periodicSync) return;
    if (on) await reg.periodicSync.register(SYNC_TAG, { minInterval: 6 * 60 * 60 * 1000 });
    else await reg.periodicSync.unregister(SYNC_TAG);
  } catch { /* permission not granted (not installed, or low engagement) */ }
}
