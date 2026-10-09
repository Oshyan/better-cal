// Boot: GET /me -> login or app; load calendars; register the service worker.

import { html, render } from '../../vendor/index.js';
import { App } from './App.js';
import { set, toast } from './store.js';
import { fetchMe } from './api.js';
import { loadSession, startSession } from './session.js';
import { armQuietReload } from './drafts.js';
import { restoreResume, recordStart } from './resume.js';
import { takeHandoff, runHandoff } from './handoff.js';
import { markBooted, reportStalledStart } from './bootlog.js';
import './install.js'; // keeps the browser's one-time install prompt for the welcome
import {
  BATTERY_TIP_BODY, shouldShowInstallTip, markInstallTipShown,
} from '../lib/batterytip.js';

async function boot() {
  let authed = false;
  // Whatever a deep link or a share handed over, collected before the first
  // request (the address is already a bare "/", see handoff.js).
  const handoff = await takeHandoff();
  try {
    // /me first and alone: it establishes the session, the CSRF token every
    // later write needs, and the settings that decide which view and window
    // the app opens on.
    await fetchMe();
    // Everything else a session needs, shared with signing in (session.js).
    await loadSession();
    authed = true;
  } catch (e) {
    // 401 already flipped authed=false; anything else lands on login too.
  }
  // Why this start happened (Settings, System), then back where you were
  // after a reload (resume.js), unless a link or a share is what opened it.
  if (authed) recordStart();
  // A reload never reopens the drawer, so an entry it left would be a Back
  // step that does nothing.
  try { if (history.state && history.state.bcDrawer) history.replaceState(null, ''); } catch { /* fine */ }
  if (authed && !handoff) restoreResume();
  set({ booted: true, pendingHandoff: authed ? null : handoff });
  if (authed) startSession(handoff);
  // A start that never finished (a white screen) goes to the server log once.
  if (authed) reportStalledStart();
}

// Installed-PWA launches. The manifest asks for focus-existing: opening the
// app (its icon's "New event" shortcut, a webcal link, an .ics file) brings
// the open window forward instead of starting a second one, and the address
// it was opened with arrives here rather than as a page load.
if ('launchQueue' in window) {
  // The address this page itself was loaded at (the shell's head script
  // parked it): boot runs that handoff, so its launch must not run twice.
  const bootPath = (() => {
    try { const h = JSON.parse(sessionStorage.getItem('bc-handoff') || 'null'); return h && h.path; } catch { return null; }
  })();
  let firstLaunch = true;
  window.launchQueue.setConsumer(async (launchParams) => {
    const first = firstLaunch;
    firstLaunch = false;
    // .ics files: the OS hands them here.
    const handle = launchParams.files && launchParams.files[0];
    if (handle) {
      try {
        const file = await handle.getFile();
        set({ pendingImportFile: file, createDrawer: { kind: 'import' } });
      } catch { /* best-effort */ }
      return;
    }
    let url = null;
    try { url = launchParams.targetURL ? new URL(launchParams.targetURL) : null; } catch { /* not a URL */ }
    if (!url || url.origin !== location.origin || url.pathname === '/') return;
    if (first && url.pathname === bootPath) return;
    if (url.pathname === '/share') {
      // A share's content travels as a form post, which a launch into an
      // open window doesn't carry.
      toast('That share arrived without its content. Share it again, or paste it into quick add.', { error: true });
      return;
    }
    runHandoff({ path: url.pathname, search: url.search }).catch(() => { /* best-effort */ });
  });
}

render(html`<${App} />`, document.getElementById('app'));
// Every module loaded and the app is on screen: this start finished.
markBooted();
boot();

// Android battery guidance, one time only: after installing the PWA (or on
// the first standalone launch, whichever happens first) explain that battery
// optimization can delay notifications. There is no web API to request the
// exemption, so telling the user the settings path is the whole mechanism.
function maybeShowBatteryTip() {
  if (!shouldShowInstallTip()) return;
  markInstallTipShown();
  toast(BATTERY_TIP_BODY, { duration: 15000 });
}
window.addEventListener('appinstalled', maybeShowBatteryTip);
if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
  maybeShowBatteryTip();
}

if ('serviceWorker' in navigator) {
  // A new version installed and activated behind this page (its cache is
  // the new one; this page still runs what it loaded). Apply it at the next
  // quiet moment. Drafts are durable, so nothing typed is lost; the reload
  // waits for the tab to be hidden or for input to pause (drafts.js).
  navigator.serviceWorker.addEventListener('message', (e) => {
    if (e.data && e.data.type === 'sw-updated') armQuietReload();
  });
  window.addEventListener('load', () => {
    // /sw.js only: the app fills in its version as it serves it. The same
    // file under /assets/ is the raw template, which would run without one.
    navigator.serviceWorker.register('/sw.js')
      .then((reg) => {
        // The browser only looks for a new version when a page loads. An
        // installed app brought back from the background loads nothing, so
        // it could run an old version for hours after a deploy. Look when the
        // app comes back into view (at most once a minute) and every 30
        // minutes while it stays open; a new version reports 'sw-updated'.
        let lastCheck = Date.now();
        const check = () => {
          if (Date.now() - lastCheck < 60000) return;
          lastCheck = Date.now();
          reg.update().catch(() => { /* offline: try again next time */ });
        };
        document.addEventListener('visibilitychange', () => {
          if (document.visibilityState === 'visible') check();
        });
        setInterval(check, 30 * 60000);
      })
      .catch(() => { /* offline support unavailable */ });
  });
}
