// A signed-in session: what it loads and what it starts, the same whether the
// page opened already signed in (main.js boot, after /me) or someone signed
// in on the login screen (Login.js, after /auth/login). Until 0.9.14 the sign-in
// path loaded only the calendars, so people, saved views, plugins, the review
// count and the device's time zone waited for the next full page load.

import { state, toast } from './store.js';
import { saveSetting } from './actions.js';
import { loadCalendars, loadSavedViews, loadConfig, loadPeople, loadPlugins, loadReviewCount, loadSystemHealth, loadUpdates } from './api.js';
import { announceSystemHealth } from './system.js';
import { installHoverPrefetch } from './prefetch.js';
import { restoreDraftsAfterBoot, installActivityTracking } from './drafts.js';
import { installResumeSaving } from './resume.js';
import { preloadRichText } from './RichText.js';
import { loadLeaflet } from './eventparts.js';
import { handleEventLink, resyncPush, offerPushOnThisDevice } from './push.js';
import { runHandoff } from './handoff.js';
import { localTz, sameClock, tzCity, tzOffsetLabel } from '../lib/dates.js';

// Everything after /me is independent; each was awaited in turn purely by
// habit, which cost a full round trip per call (~85ms each) before the app
// could render. Only calendars genuinely gate first paint: without them
// events would draw in placeholder colours, so their failure is this
// function's failure.
export function loadSession() {
  return Promise.all([
    loadCalendars(),
    loadSavedViews().catch(() => { /* views are non-critical at boot */ }),
    loadConfig().catch(() => { /* map tiles fall back to OSM */ }),
    loadPeople().catch(() => { /* people are non-critical at boot */ }),
    loadSystemHealth(), // own catch inside; feeds the boot notices and the device banner
    loadPlugins(), // ops listing feeds the sidebar layer toggles; own catch inside
    loadReviewCount(),
    loadUpdates(),
    // Tell the server our timezone once. The browser has always known it;
    // worker-side code (plugins building local times) had no way to.
    (state.settings.tz ? Promise.resolve() : saveSetting('tz', localTz()).catch(() => {})),
    // And where this device is now, whenever that changes: mail naming no
    // place is read on this clock (a booking email while travelling).
    (state.settings.hereTz === localTz() ? Promise.resolve() : saveSetting('hereTz', localTz()).catch(() => {})),
  ]);
}

// The screen follows this device's clock; settings.tz is the owner's Home zone,
// which the server uses for all-day reminder times and for events created on
// their behalf. When the two differ (travel, or a new device elsewhere) say so
// once per pairing, with the one-tap change: silently keeping Home is right for
// a trip and wrong for a move, and only the owner knows which this is.
const TZ_NOTICE_KEY = 'bc-tz-notice';
function announceAwayFromHome() {
  const home = state.settings.tz;
  const device = localTz();
  if (!home || sameClock(home, device)) return;
  const pairing = home + '|' + device;
  try {
    if (localStorage.getItem(TZ_NOTICE_KEY) === pairing) return;
    localStorage.setItem(TZ_NOTICE_KEY, pairing);
  } catch { /* private mode: the notice simply shows each load */ }
  toast(
    'This device is on ' + tzCity(device) + ' time (' + tzOffsetLabel(device) + '). Home is still ' + tzCity(home)
      + ' (' + tzOffsetLabel(home) + '): all-day reminders follow Home. Times on screen follow this device.',
    {
      duration: 20000,
      actions: [{ label: 'Make ' + tzCity(device) + ' Home', run: () => saveSetting('tz', device) }],
      dismissLabel: 'Keep ' + tzCity(home),
    },
  );
}

// Listeners and trackers that belong to the page, not the session: signing
// out and back in without a reload must not install them twice.
let pageWired = false;
function wirePage() {
  if (pageWired) return;
  pageWired = true;
  installResumeSaving();
  // Where this device is, kept current while the app stays open (0.9.1):
  // reminder text reads on this clock, so a trip shouldn't wait for a
  // restart to be noticed.
  document.addEventListener('visibilitychange', () => {
    if (state.authed && document.visibilityState === 'visible' && state.settings.hereTz !== localTz()) saveSetting('hereTz', localTz()).catch(() => {});
  });
  installActivityTracking();
  // Prefetch on intent (hover/focus on a chip), and warm the two lazily
  // loaded scripts at idle so the FIRST editor and the FIRST interactive
  // map are as fast as later ones. Skipped when the browser says the user
  // wants to save data.
  installHoverPrefetch();
  // "Idle" to requestIdleCallback means the CPU, not the network: on a
  // slow link it fired the moment the events window started downloading
  // and the scripts competed with it for the same 1 Mbps. So: only after
  // the first window has landed, a beat later, and never on a link the
  // browser itself calls 2g/3g or where the user asked to save data.
  const conn = navigator.connection || {};
  const slowLink = conn.saveData || /(^|-)2g$|^3g$/.test(conn.effectiveType || '');
  if (!slowLink) {
    const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 2500));
    const whenWindowLanded = () => new Promise((resolve) => {
      if (state.loadedRanges.length > 0) { resolve(); return; }
      const t = setInterval(() => { if (state.loadedRanges.length > 0) { clearInterval(t); resolve(); } }, 500);
      setTimeout(() => { clearInterval(t); resolve(); }, 15000); // give up waiting, not preloading
    });
    whenWindowLanded().then(() => setTimeout(() => idle(() => {
      preloadRichText().catch(() => {});
      loadLeaflet().catch(() => {});
    }, { timeout: 8000 }), 2000));
  }
}

// After loadSession succeeded: the page's own wiring (once), then this
// session's follow-ups. handoff: a deep link or share still owed.
export function startSession(handoff) {
  wirePage();
  // Notification deep link (/?event=instanceId): open that event's detail.
  handleEventLink().catch(() => { /* best-effort */ });
  if (handoff) runHandoff(handoff).catch(() => { /* best-effort */ });
  resyncPush(); // own catch; keeps this device registered after a reset
  offerPushOnThisDevice(); // own catch; a reinstalled phone signs back up
  // One-time notices for failures that change what the calendar shows.
  announceSystemHealth();
  announceAwayFromHome();
  // Whatever was in progress when the page last unloaded comes back:
  // an editor with its form, or quick add with its text.
  restoreDraftsAfterBoot();
}
