// Start record. The shell document's head script notes every start in
// localStorage ('bc-boot') before any app code loads; markBooted() records
// that this one reached its first render. A start the next one finds still
// unfinished is kept as 'bc-boot-stalled': the app never came up (a hung or
// failed download leaves a white screen and nothing else to go on). Shown in
// Settings, System, and reported once to the server log so it sits beside
// the access log of that moment.

import { api } from './api.js';

const BOOT_KEY = 'bc-boot';
const STALLED_KEY = 'bc-boot-stalled';
const REPORTED_KEY = 'bc-boot-stalled-reported';

/** Called once the app has rendered: this start finished. */
export function markBooted() {
  window.bcBooted = true;
  try {
    const cur = JSON.parse(localStorage.getItem(BOOT_KEY) || '{}');
    localStorage.setItem(BOOT_KEY, JSON.stringify({ ...cur, done: true, doneAt: Date.now() }));
  } catch { /* no storage: no record */ }
}

/** The last start that never finished, or null: {at, failed?}. */
export function stalledStart() {
  try {
    const st = JSON.parse(localStorage.getItem(STALLED_KEY) || 'null');
    return st && Number.isFinite(st.at) ? st : null;
  } catch { return null; }
}

/** Report a stalled start to the server log once (signed in only). */
export async function reportStalledStart() {
  const st = stalledStart();
  if (!st) return;
  try {
    if (localStorage.getItem(REPORTED_KEY) === String(st.at)) return;
    await api('/system/client-event', {
      method: 'POST',
      body: { kind: 'boot-stalled', at: new Date(st.at).toISOString(), file: typeof st.failed === 'string' ? st.failed : null },
    });
    localStorage.setItem(REPORTED_KEY, String(st.at));
  } catch { /* offline or refused: the record stays on the device */ }
}
