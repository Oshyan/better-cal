// Service worker strategy:
// - vendor files: cache-first (immutable in practice, long nginx cache)
// - app shell/src/styles: network-first with cache fallback, so deploys are
//   picked up on next load while offline still works
// - API GETs: network-first with a 5s timeout falling back to cache

const VERSION = 'bc-v2';
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

function networkFirstWithTimeout(request, cacheName, timeoutMs) {
  return new Promise((resolve) => {
    let settled = false;
    const timer = timeoutMs ? setTimeout(async () => {
      const cached = await caches.match(request);
      if (cached && !settled) { settled = true; resolve(cached); }
    }, timeoutMs) : null;
    fetch(request).then(async (res) => {
      if (timer) clearTimeout(timer);
      if (res.ok) {
        const cache = await caches.open(cacheName);
        cache.put(request, res.clone());
      }
      if (!settled) { settled = true; resolve(res); }
    }).catch(async () => {
      if (timer) clearTimeout(timer);
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
    event.respondWith(networkFirstWithTimeout(event.request, API_CACHE, API_TIMEOUT_MS));
    return;
  }

  if (url.pathname.startsWith('/assets/vendor/')) {
    // Cache-first: vendor files change only with a deliberate upgrade.
    event.respondWith(
      caches.match(event.request).then((cached) => cached || fetch(event.request).then(async (res) => {
        if (res.ok) {
          const cache = await caches.open(SHELL_CACHE);
          cache.put(event.request, res.clone());
        }
        return res;
      })),
    );
    return;
  }

  if (url.pathname === '/' || url.pathname.startsWith('/assets/')) {
    // Network-first with forced revalidation (bypasses stale HTTP cache entries;
    // nginx answers 304 via ETag when unchanged). Cache keeps offline working.
    const revalidated = new Request(event.request, { cache: 'no-cache' });
    event.respondWith(networkFirstWithTimeout(revalidated, SHELL_CACHE, 0));
  }
});
