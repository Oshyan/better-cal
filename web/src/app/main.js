// Boot: GET /me -> login or app; load calendars; register the service worker.

import { html, render } from '../../vendor/index.js';
import { App } from './App.js';
import { set } from './store.js';
import { fetchMe, loadCalendars } from './api.js';

async function boot() {
  try {
    await fetchMe();
    await loadCalendars();
  } catch (e) {
    // 401 already flipped authed=false; anything else lands on login too.
  }
  set({ booted: true });
}

render(html`<${App} />`, document.getElementById('app'));
boot();

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
