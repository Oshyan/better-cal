// Boot: GET /me -> login or app; load calendars; register the service worker.

import { html, render } from '../../vendor/index.js';
import { App } from './App.js';
import { set, state, toast } from './store.js';
import { saveSetting } from './actions.js';
import { fetchMe, loadCalendars, loadSavedViews, loadConfig, loadPeople, loadPlugins, loadProposalCount, loadSystemHealth, api } from './api.js';
import { announceSystemHealth } from './system.js';
import { installHoverPrefetch } from './prefetch.js';
import { preloadRichText } from './RichText.js';
import { loadLeaflet } from './EventDetail.js';
import { localTz } from '../lib/dates.js';
import { handleEventLink } from './push.js';
import {
  BATTERY_TIP_BODY, shouldShowInstallTip, markInstallTipShown,
} from '../lib/batterytip.js';

async function boot() {
  let authed = false;
  try {
    // /me first and alone: it establishes the session, the CSRF token every
    // later write needs, and the settings that decide which view and window
    // the app opens on.
    await fetchMe();
    // Everything after it is independent — each was awaited in turn purely by
    // habit, which cost a full round trip per call (~85ms each) before the app
    // could render. Only calendars genuinely gate first paint: without them
    // events would draw in placeholder colours.
    await Promise.all([
      loadCalendars(),
      loadSavedViews().catch(() => { /* views are non-critical at boot */ }),
      loadConfig().catch(() => { /* map tiles fall back to OSM */ }),
      loadPeople().catch(() => { /* people are non-critical at boot */ }),
      loadSystemHealth(), // own catch inside; feeds the boot notices and the device banner
      loadPlugins(), // ops listing feeds the sidebar layer toggles; own catch inside
      loadProposalCount(),
      // Tell the server our timezone once. The browser has always known it;
      // worker-side code (plugins building local times) had no way to.
      (state.settings.tz ? Promise.resolve() : saveSetting('tz', localTz()).catch(() => {})),
    ]);
    authed = true;
  } catch (e) {
    // 401 already flipped authed=false; anything else lands on login too.
  }
  set({ booted: true });
  if (authed) {
    // Notification deep link (/?event=instanceId): open that event's detail.
    handleEventLink().catch(() => { /* best-effort */ });
    handleDeepPaths().catch(() => { /* best-effort */ });
    // One-time notices for failures that change what the calendar shows.
    announceSystemHealth();
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
}

// Deep-link entry paths (all serve the SPA shell; the path carries intent):
//   /add?<gcal template params>  -> editor prefilled (extension redirect target)
//   /subscribe?url=<ics|webcal>  -> subscribe drawer prefilled
//   /import                      -> import drawer (file arrives via launchQueue)
//   /share?url=&text=&title=     -> Web Share Target: route by content
async function handleDeepPaths() {
  const path = location.pathname;
  if (path === '/' || path === '') return;
  const params = new URLSearchParams(location.search);
  const fullUrl = location.href;
  history.replaceState(null, '', '/');

  const openGcal = async (url) => {
    // One parser for every entry path: QuickAdd handles GCal template links.
    const data = await api('/quickadd', { method: 'POST', body: { text: url, tz: localTz() } });
    const d = data && data.draft;
    if (!d || !d.start) { toast('Could not read that calendar link', { error: true }); return; }
    set({
      editor: {
        mode: 'create',
        draft: {
          title: d.title, start: d.start, end: d.end, allDay: d.allDay,
          location: d.location, description: d.description, rrule: d.rrule,
          calendarId: d.calendarId,
        },
      },
    });
  };
  const openSubscribe = (url) => {
    if (url && url.startsWith('webcal://')) url = 'https://' + url.slice('webcal://'.length);
    set({ createDrawer: { kind: 'subscribe', url: url || '' } });
  };

  if (path === '/add') {
    // /add carries the GCal template params verbatim (extension redirect);
    // hand the parser a canonical GCal URL so it matches strictly.
    await openGcal('https://calendar.google.com/calendar/render' + new URL(fullUrl).search);
  } else if (path === '/subscribe') {
    openSubscribe(params.get('url') || '');
  } else if (path === '/import') {
    set({ createDrawer: { kind: 'import' } });
  } else if (path === '/share') {
    const shared = [params.get('url'), params.get('text'), params.get('title')]
      .filter(Boolean).join(' ').trim();
    // First URL in the shared payload decides the route.
    const m = shared.match(/https?:\/\/\S+|webcal:\/\/\S+/i);
    const sharedUrl = m ? m[0] : null;
    if (sharedUrl && /calendar\.google\.com\/calendar\/(u\/\d+\/)?(r\/eventedit|render)\?/i.test(sharedUrl)) {
      await openGcal(sharedUrl);
    } else if (sharedUrl && (/\.ics(\?|$)/i.test(sharedUrl) || sharedUrl.startsWith('webcal://'))) {
      openSubscribe(sharedUrl);
    } else if (shared) {
      // Anything else lands in quick add for the parser to chew on.
      set({ quickAddOpen: true, quickAddSeed: shared });
    }
  }
}

// Installed-PWA file handling (.ics): the OS hands files here.
if ('launchQueue' in window) {
  window.launchQueue.setConsumer(async (launchParams) => {
    const handle = launchParams.files && launchParams.files[0];
    if (!handle) return;
    try {
      const file = await handle.getFile();
      set({ pendingImportFile: file, createDrawer: { kind: 'import' } });
    } catch { /* best-effort */ }
  });
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
