// Boot: GET /me -> login or app; load calendars; register the service worker.

import { html, render } from '../../vendor/index.js';
import { App } from './App.js';
import { set, toast } from './store.js';
import { fetchMe, loadCalendars, loadSavedViews, loadConfig } from './api.js';
import { handleEventLink } from './push.js';
import {
  BATTERY_TIP_BODY, shouldShowInstallTip, markInstallTipShown,
} from '../lib/batterytip.js';

async function boot() {
  let authed = false;
  try {
    await fetchMe();
    await loadCalendars();
    await loadSavedViews().catch(() => { /* views are non-critical at boot */ });
    loadConfig(); // fire-and-forget; map tiles fall back to OSM meanwhile
    authed = true;
  } catch (e) {
    // 401 already flipped authed=false; anything else lands on login too.
  }
  set({ booted: true });
  if (authed) {
    // Notification deep link (/?event=instanceId): open that event's detail.
    handleEventLink().catch(() => { /* best-effort */ });
  }
}

render(html`<${App} />`, document.getElementById('app'));
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
  window.addEventListener('load', () => {
    // Prefer root scope; fall back to the /assets/ path (scope-limited)
    // if the server does not alias /sw.js.
    navigator.serviceWorker.register('/sw.js')
      .catch(() => navigator.serviceWorker.register('/assets/sw.js', { scope: '/' }))
      .catch(() => navigator.serviceWorker.register('/assets/sw.js'))
      .catch(() => { /* offline support unavailable */ });
  });
}
