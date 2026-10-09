// Service worker strategy:
// - app shell (document, styles, every module, vendor): CACHE-FIRST, keyed
//   to VERSION. VERSION and SHELL are filled in by the server when it serves
//   this file (server/src/Http/AppShell.php), from the module graph and a hash
//   of every file in it, so a deploy that changes anything gets a new version
//   (a new cache, the old one deleted on activate) and a deploy that changes
//   nothing invalidates nothing. The browser revalidates sw.js itself on
//   every navigation (no-cache), which is how a new version is noticed.
// - served raw (VERSION still 'bc-unversioned': a web server sent this file
//   from disk instead of letting the app fill it in), the worker leaves the
//   app's files to the network: the app works online, just without offline
//   start, rather than pinning a cache no deploy can ever replace. API
//   caching, share target and push still work.
// - a new version, once active, tells open pages so they can offer a reload;
//   until they do they keep running what they loaded, which is always a
//   consistent set.
// - API GETs: network-first with a 5s timeout falling back to cache. That
//   cache is private data and is purged on sign-out and on any 401 (see
//   "Private API cache" below).
//
// Why cache-first: unbundled modules are ~75 requests. Network-first with
// forced revalidation made every one of them a round trip before the app
// could start, which on a 450 ms link was nine seconds of a blank page.

// @generated-shell:start (filled in by the server: server/src/Http/AppShell.php)
const VERSION = 'bc-unversioned';
const SHELL = [];
// @generated-shell:end

const SERVED_RAW = VERSION === 'bc-unversioned';
const SHELL_CACHE = VERSION + '-shell';
const API_CACHE = VERSION + '-api';
const API_TIMEOUT_MS = 5000;
const SESSION_HEADER = 'X-BetterCal-Session';
const OFFLINE_UNTIL_HEADER = 'X-BetterCal-Offline-Until';
const OFFLINE_RESPONSE_HEADER = 'X-BetterCal-Offline';
// Unversioned: a share stashed by one worker version must survive the next
// one activating before the page has collected it.
const HANDOFF_CACHE = 'bc-handoff';
const HANDOFF_KEY = '/__handoff';
// URL encoding can expand one decoded Unicode character to nine bytes. This
// remains a small hard transport ceiling while still admitting the documented
// 4,000-character decoded payload for non-ASCII text.
const SHARE_RAW_BYTES = 65536;
const SHARE_FIELD_CHARS = 4000;
const SHARE_TOTAL_CHARS = 4000;

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    if (SERVED_RAW) {
      console.warn('Better-Cal: sw.js was served without its version, so app files are not cached. Route /sw.js to the app (docs/install.md).');
      await self.skipWaiting();
      return;
    }
    const cache = await caches.open(SHELL_CACHE);
    // Bypass the HTTP cache: this is the one moment the worker must see the
    // server's current files, not a 30-day immutable copy of the old ones.
    // One missing icon must not block an update, so entries fail alone.
    await Promise.all(SHELL.map(async (u) => {
      try {
        const res = await fetch(new Request(u, { cache: 'reload' }));
        if (res.ok) await cache.put(u, res);
      } catch { /* fetched on demand later */ }
    }));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    if (SERVED_RAW) {
      await self.clients.claim();
      return;
    }
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => !k.startsWith(VERSION) && k !== HANDOFF_CACHE).map((k) => caches.delete(k)));
    await self.clients.claim();
    // Pages already open loaded the previous version; let them offer a reload.
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of clients) c.postMessage({ type: 'sw-updated', version: VERSION });
  })());
});

// --- Private API cache --------------------------------------------------------
// API responses are the signed-in account's data (events, people, /me with its
// CSRF token, feed URLs that are capabilities). They are cached so the calendar
// opens offline, and that cache must not outlive the session: before this, a
// signed-out browser still answered /api/v1/me with 200 offline and the app
// booted as if authenticated (BC-04).
//
// Purged on: the page's 'purge-api' message (sign out), and any 401 from the
// API (session expired, revoked by a password reset, or ended elsewhere).
//
// apiEpoch closes the race a plain delete leaves open: a request already in
// flight when the purge happens would otherwise be cached a moment after it.
// Each request remembers the epoch it started under and only caches its
// response if no purge happened since.
let apiEpoch = 0;

async function purgeApiCache() {
  apiEpoch++;
  const keys = await caches.keys();
  await Promise.all(keys.filter((k) => k.endsWith('-api')).map((k) => caches.delete(k)));
}

function cacheableApiPath(request) {
  const path = new URL(request.url).pathname.replace(/^\/api\/v1/, '');
  return path === '/me'
    || path === '/config'
    || path === '/calendars'
    || path === '/events'
    || path === '/search'
    || path === '/people'
    || path === '/views'
    || path === '/review/count'
    || path === '/system/health'
    || path === '/updates';
}

async function privateCacheCopy(request, response) {
  const url = new URL(request.url);
  if (url.pathname !== '/api/v1/me' && url.pathname !== '/api/v1/calendars') return response.clone();
  const data = await response.clone().json();
  if (url.pathname === '/api/v1/me') delete data.csrf;
  if (url.pathname === '/api/v1/calendars' && Array.isArray(data.calendars)) {
    for (const calendar of data.calendars) if (calendar && typeof calendar === 'object') calendar.sourceUrl = null;
  }
  const headers = new Headers(response.headers);
  // These describe the original body, not the capability-stripped JSON.
  headers.delete('Content-Length');
  headers.delete('Content-Encoding');
  headers.delete('ETag');
  return new Response(JSON.stringify(data), {
    status: response.status,
    statusText: response.statusText,
    headers,
  });
}

async function validOfflineResponse(request, cacheName) {
  // An endpoint deliberately outside the offline allowlist is an ordinary
  // miss, not evidence that the signed-in cache is corrupt. Offline boot asks
  // for some online-only resources (plugins, integration state); those must
  // fail alone without erasing the saved calendar.
  if (!cacheableApiPath(request)) return null;
  const epoch = apiEpoch;
  const cache = await caches.open(cacheName);
  if (epoch !== apiEpoch) return null;
  const cached = await cache.match(request);
  if (epoch !== apiEpoch) return null;
  if (!cached) return null;
  const me = await cache.match(new URL('/api/v1/me', self.location.origin).href);
  if (epoch !== apiEpoch) return null;
  const session = cached?.headers.get(SESSION_HEADER);
  const currentSession = me?.headers.get(SESSION_HEADER);
  const deadline = Number(cached?.headers.get(OFFLINE_UNTIL_HEADER) || 0);
  if (!session || !currentSession || session !== currentSession
      || !Number.isSafeInteger(deadline) || deadline * 1000 < Date.now()) {
    await purgeApiCache();
    return null;
  }
  const headers = new Headers(cached.headers);
  headers.set(OFFLINE_RESPONSE_HEADER, '1');
  const body = await cached.arrayBuffer();
  // A logout, 401 or login change that happened while Cache Storage read the
  // body wins. Never deliver private bytes across that invalidation boundary.
  if (epoch !== apiEpoch) return null;
  return new Response(body, {
    status: cached.status,
    statusText: cached.statusText,
    headers,
  });
}

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'purge-api') event.waitUntil(purgeApiCache());
});

function networkFirstWithTimeout(request, cacheName, timeoutMs) {
  return new Promise((resolve) => {
    let settled = false;
    const epoch = apiEpoch;
    const timer = timeoutMs ? setTimeout(async () => {
      // Private responses may only come from the private API cache. Searching
      // every cache would let a poisoned/legacy shell cache survive logout.
      const cached = await validOfflineResponse(request, cacheName);
      if (cached && !settled) { settled = true; resolve(cached); }
    }, timeoutMs) : null;
    fetch(request).then(async (res) => {
      if (timer) clearTimeout(timer);
      if (res.status === 401 && epoch === apiEpoch) {
        await purgeApiCache();
      } else if (res.ok && epoch === apiEpoch && cacheableApiPath(request)
          && res.headers.get(SESSION_HEADER) && res.headers.get(OFFLINE_UNTIL_HEADER)) {
        const copy = await privateCacheCopy(request, res);
        // JSON capability stripping above is asynchronous. A logout, 401 or
        // different login that landed while it ran permanently invalidates
        // this response; it must not be allowed to reinterpret the new epoch
        // as its own below.
        if (epoch !== apiEpoch) {
          if (!settled) { settled = true; resolve(res); }
          return;
        }
        caches.open(cacheName).then(async (cache) => {
          if (epoch !== apiEpoch) return;
          let cacheEpoch = epoch;
          const current = await cache.match(new URL('/api/v1/me', self.location.origin).href);
          if (epoch !== apiEpoch) return;
          if (current && current.headers.get(SESSION_HEADER) !== res.headers.get(SESSION_HEADER)) {
            const expectedTransitionEpoch = epoch + 1;
            await purgeApiCache();
            // A second purge means another logout/login transition raced this
            // one. Neither response is allowed to win by adopting that epoch.
            if (apiEpoch !== expectedTransitionEpoch) return;
            cacheEpoch = apiEpoch;
            cache = await caches.open(cacheName);
            if (cacheEpoch !== apiEpoch) return;
          }
          if (cacheEpoch !== apiEpoch) return;
          await cache.put(request, copy);
          // A purge that landed while this was being written wins.
          if (cacheEpoch !== apiEpoch) await purgeApiCache();
        }).catch(() => { /* quota or storage error: just not cached */ });
      }
      if (!settled) { settled = true; resolve(res); }
    }).catch(async () => {
      if (timer) clearTimeout(timer);
      const cached = await validOfflineResponse(request, cacheName);
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

// Shell: the versioned cache answers; anything not precached (a lazily
// loaded file, a query-string variant) is fetched once and kept. ignoreSearch
// so /assets/styles/app.css?v=3 and the bare path are one entry.
async function cacheFirst(request) {
  const cached = await caches.match(request, { ignoreSearch: true });
  if (cached) return cached;
  const res = await fetch(request);
  if (res.ok) {
    const cache = await caches.open(SHELL_CACHE);
    cache.put(request, res.clone());
  }
  return res;
}

// --- Web Share Target ---------------------------------------------------------
// The OS share sheet POSTs title/text/url to /share (manifest share_target).
// A GET target would have put the shared content in the address bar, in the
// server's access log, and in the Referer of every request the page made
// before it cleaned up (BC-18). Instead the form body is read here, parked in
// the handoff cache for the page to collect once (src/app/handoff.js), and the
// browser is sent to a bare "/". Nothing about the share leaves the device.
async function readShareBody(request) {
  const hinted = Number(request.headers.get('Content-Length') || 0);
  if (Number.isFinite(hinted) && hinted > SHARE_RAW_BYTES) throw new Error('too_large');
  if (!request.body || typeof request.body.getReader !== 'function') throw new Error('unstreamable');
  const reader = request.body.getReader();
  const chunks = [];
  let bytes = 0;
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      bytes += value.byteLength;
      if (bytes > SHARE_RAW_BYTES) throw new Error('too_large');
      chunks.push(value);
    }
  } catch (e) {
    try { await reader.cancel(); } catch { /* already closed */ }
    throw e;
  }
  const body = new Uint8Array(bytes);
  let offset = 0;
  for (const chunk of chunks) { body.set(chunk, offset); offset += chunk.byteLength; }
  return new TextDecoder('utf-8', { fatal: true }).decode(body);
}

function trustedShareRequest(request) {
  const fetchSite = request.headers.get('Sec-Fetch-Site');
  const origin = request.headers.get('Origin');
  return fetchSite !== 'cross-site'
    && (!origin || origin === 'null' || origin === self.location.origin);
}

function discardShareBody(request) {
  try {
    const cancelled = request.body && request.body.cancel();
    if (cancelled && typeof cancelled.catch === 'function') cancelled.catch(() => {});
  } catch { /* already locked or closed */ }
}

let shareBusy = false;

async function storeShare(request) {
  const cache = await caches.open(HANDOFF_CACHE);
  // A failed new handoff must never replay content left by an older share.
  await cache.delete(HANDOFF_KEY);

  try {
    const contentType = (request.headers.get('Content-Type') || '').toLowerCase();
    if (!contentType.startsWith('application/x-www-form-urlencoded')) throw new Error('content_type');
    const form = new URLSearchParams(await readShareBody(request));
    const params = {};
    let total = 0;
    for (const k of ['title', 'text', 'url']) {
      const v = form.get(k);
      if (typeof v !== 'string' || !v) continue;
      if (v.length > SHARE_FIELD_CHARS) throw new Error('too_large');
      total += v.length;
      if (total > SHARE_TOTAL_CHARS) throw new Error('too_large');
      params[k] = v;
    }
    await cache.put(HANDOFF_KEY, new Response(JSON.stringify({ path: '/share', params }), {
      headers: { 'Content-Type': 'application/json' },
    }));
  } catch {
    await cache.put(HANDOFF_KEY, new Response(JSON.stringify({
      path: '/share', error: 'That shared item was too large or could not be read, so it was not opened.',
    }), { headers: { 'Content-Type': 'application/json' } }));
  }
}

async function shareTarget(request) {
  // Reject untrusted forms before touching the one-shot handoff. A denial is
  // not redirected into the app because that navigation could consume a
  // legitimate share already waiting there.
  if (!trustedShareRequest(request)) {
    discardShareBody(request);
    return new Response('Share request refused.', {
      status: 403,
      headers: { 'Cache-Control': 'no-store', 'Content-Type': 'text/plain; charset=utf-8' },
    });
  }
  // One active body is the aggregate memory boundary. Ordinary OS share-sheet
  // use is sequential; a truly simultaneous second share gets an explicit
  // retry response instead of being retained in an unbounded promise queue or
  // overwriting the first share's one-shot cache entry.
  if (shareBusy) {
    discardShareBody(request);
    return new Response('Another share is still being received. Try again.', {
      status: 429,
      headers: { 'Cache-Control': 'no-store', 'Content-Type': 'text/plain; charset=utf-8', 'Retry-After': '1' },
    });
  }
  shareBusy = true;
  // Cache Storage can be unavailable or out of quota. The share cannot be
  // handed off then, but its contents must still stay out of the URL and the
  // browser must still land on the bare app root.
  try { await storeShare(request); } catch { /* nothing persisted */ }
  finally { shareBusy = false; }
  return new Response(null, { status: 303, headers: { Location: '/', 'Cache-Control': 'no-store' } });
}

// --- Web Push reminders -----------------------------------------------------
// The worker sends {title, body, url, tag, join?, map?}; tag replaces earlier
// notifications for the same occurrence instead of stacking duplicates. Join
// and Map become the notification's buttons (0.6.3). The badge is a white
// silhouette: Android draws the status-bar icon from its shape alone, so the
// full-colour app icon showed as a plain square.

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { body: event.data ? event.data.text() : '' };
  }
  const actions = [];
  if (data.join) actions.push({ action: 'join', title: 'Join' });
  if (data.map) actions.push({ action: 'map', title: 'Map' });
  event.waitUntil(self.registration.showNotification(data.title || 'Better-Cal', {
    body: data.body || '',
    tag: data.tag || undefined,
    icon: '/assets/icons/icon-192.png',
    badge: '/assets/icons/badge-96.png',
    actions: actions.slice(0, 2),
    data: { url: data.url || '/', join: data.join || null, map: data.map || null },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const data = event.notification.data || {};
  // A button goes straight out: the call, or the place on Google Maps, whose
  // page offers to open it in the Maps app. (An Android intent link naming
  // the Maps app was tried in 0.6.4; a notification can't launch one.)
  if ((event.action === 'join' || event.action === 'map') && data[event.action]) {
    event.waitUntil(clients.openWindow(data[event.action]));
    return;
  }
  const url = data.url || '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const client of list) {
        if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
          client.focus();
          return 'navigate' in client ? client.navigate(url) : undefined;
        }
      }
      return clients.openWindow(url);
    }),
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (url.origin !== location.origin) return;
  if (event.request.method === 'POST' && url.pathname === '/share') {
    event.respondWith(shareTarget(event.request));
    return;
  }
  if (event.request.method !== 'GET') return;

  if (url.pathname.startsWith('/api/')) {
    event.respondWith(networkFirstWithTimeout(event.request, API_CACHE, API_TIMEOUT_MS));
    return;
  }

  // The document for any app path ('/', '/add', '/subscribe', ...) is the
  // one cached shell document; a deep-link path and query are taken off the
  // address by the shell's head script before anything else loads.
  if (SERVED_RAW) return; // the network serves the document and every app file

  if (event.request.mode === 'navigate') {
    event.respondWith(cacheFirst(new Request('/')).catch(() => fetch(event.request)));
    return;
  }

  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(cacheFirst(event.request));
  }
});
