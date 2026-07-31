// Service worker: cache-first app shell + vendor, network-first API GETs
// with a 5s timeout falling back to cache.

const VERSION = 'bc-v1';
const SHELL_CACHE = VERSION + '-shell';
const API_CACHE = VERSION + '-api';
const API_TIMEOUT_MS = 5000;

const SHELL = [
  '/',
  '/assets/styles/app.css',
  '/assets/manifest.webmanifest',
  '/assets/icons/icon.svg',
  '/assets/icons/icon-maskable.svg',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/assets/vendor/preact.module.js',
  '/assets/vendor/hooks.module.js',
  '/assets/vendor/htm.module.js',
  '/assets/vendor/index.js',
  '/assets/src/app/main.js',
  '/assets/src/app/App.js',
  '/assets/src/app/store.js',
  '/assets/src/app/api.js',
  '/assets/src/app/actions.js',
  '/assets/src/app/keyboard.js',
  '/assets/src/app/Toolbar.js',
  '/assets/src/app/Sidebar.js',
  '/assets/src/app/QuickAdd.js',
  '/assets/src/app/EventPopover.js',
  '/assets/src/app/EditorDrawer.js',
  '/assets/src/app/SearchOverlay.js',
  '/assets/src/app/Toasts.js',
  '/assets/src/app/Login.js',
  '/assets/src/app/OutfeedsPage.js',
  '/assets/src/ui/MonthGrid.js',
  '/assets/src/ui/TimeGrid.js',
  '/assets/src/ui/AgendaList.js',
  '/assets/src/ui/DayExpand.js',
  '/assets/src/ui/DragController.js',
  '/assets/src/ui/EventChip.js',
  '/assets/src/ui/layout.js',
  '/assets/src/ui/monthmath.js',
  '/assets/src/lib/dates.js',
  '/assets/src/lib/color.js',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE)
      .then((cache) => cache.addAll(SHELL))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

function networkFirstWithTimeout(request) {
  return new Promise((resolve) => {
    let settled = false;
    const timer = setTimeout(async () => {
      const cached = await caches.match(request);
      if (cached && !settled) { settled = true; resolve(cached); }
    }, API_TIMEOUT_MS);
    fetch(request).then(async (res) => {
      clearTimeout(timer);
      if (res.ok) {
        const cache = await caches.open(API_CACHE);
        cache.put(request, res.clone());
      }
      if (!settled) { settled = true; resolve(res); }
    }).catch(async () => {
      clearTimeout(timer);
      const cached = await caches.match(request);
      if (!settled) {
        settled = true;
        resolve(cached || new Response(JSON.stringify({ error: { code: 'offline', message: 'Offline and not cached' } }), {
          status: 503,
          headers: { 'Content-Type': 'application/json' },
        }));
      }
    });
  });
}

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== location.origin) return;

  if (url.pathname.startsWith('/api/')) {
    event.respondWith(networkFirstWithTimeout(event.request));
    return;
  }

  if (url.pathname === '/' || url.pathname.startsWith('/assets/')) {
    // Cache-first for the shell and static assets.
    event.respondWith(
      caches.match(event.request).then((cached) => cached || fetch(event.request).then(async (res) => {
        if (res.ok) {
          const cache = await caches.open(SHELL_CACHE);
          cache.put(event.request, res.clone());
        }
        return res;
      })),
    );
  }
});
