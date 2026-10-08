// Deep-link entry paths (all serve the SPA shell; the path carries intent):
//   /add?<gcal template params>  -> editor prefilled (extension redirect target)
//   /subscribe?url=<ics|webcal>  -> subscribe drawer prefilled (webcal: handler)
//   /import                      -> import drawer (file arrives via launchQueue)
//   /review                      -> the Review queue (held-change notification target)
//   /new                         -> quick add, open and empty (the app icon's
//                                   "New event" shortcut and the extension's key)
//   /google?connected=|error=    -> Settings, Connections (OAuth callback landing)
//   /settings[/<tab>]            -> Settings, on that tab (general, notifications,
//                                   location, connections, account, about, system)
//   /share  (POST title/text/url) -> Web Share Target: route by content
//
// None of them is read from the address bar. The shell's head script moves a
// GET path and query into sessionStorage and rewrites the address to "/"
// before the first asset request, and the service worker parks a POST share
// in a cache entry and redirects to "/"; takeHandoff() collects whichever is
// there, once, before boot makes its first request. A shared link or a feed
// URL (a capability) therefore never appears in a request line or a Referer.

import { api } from './api.js';
import { set, toast } from './store.js';
import { localTz } from '../lib/dates.js';

const SESSION_KEY = 'bc-handoff';
const CACHE_NAME = 'bc-handoff';
const CACHE_KEY = '/__handoff';
const MAX_HANDOFF_BYTES = 20480;
const MAX_SHARE_CHARS = 4000;

async function boundedResponseText(res, maxBytes = MAX_HANDOFF_BYTES) {
  const hinted = Number(res.headers.get('Content-Length') || 0);
  if (Number.isFinite(hinted) && hinted > maxBytes) throw new Error('handoff too large');
  if (!res.body || typeof res.body.getReader !== 'function') throw new Error('handoff is not stream-readable');
  const reader = res.body.getReader();
  const chunks = [];
  let bytes = 0;
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    bytes += value.byteLength;
    if (bytes > maxBytes) {
      try { await reader.cancel(); } catch { /* already closed */ }
      throw new Error('handoff too large');
    }
    chunks.push(value);
  }
  const body = new Uint8Array(bytes);
  let offset = 0;
  for (const chunk of chunks) { body.set(chunk, offset); offset += chunk.byteLength; }
  return new TextDecoder('utf-8', { fatal: true }).decode(body);
}

export function normalizeShareParams(value) {
  if (!value || typeof value !== 'object') return null;
  const out = {};
  let total = 0;
  for (const key of ['title', 'text', 'url']) {
    const field = value[key];
    if (field == null || field === '') continue;
    if (typeof field !== 'string' || field.length > MAX_SHARE_CHARS) return null;
    total += field.length;
    if (total > MAX_SHARE_CHARS) return null;
    out[key] = field;
  }
  return out;
}

/** @returns {Promise<{path:string, search?:string, params?:object}|null>} */
export async function takeHandoff() {
  try {
    const raw = sessionStorage.getItem(SESSION_KEY);
    if (raw) {
      sessionStorage.removeItem(SESSION_KEY);
      if (raw.length > MAX_HANDOFF_BYTES) return null;
      const h = JSON.parse(raw);
      if (h && typeof h.path === 'string') return h;
    }
  } catch { /* storage unavailable: nothing to hand off */ }
  if (!('caches' in window)) return null;
  try {
    const cache = await caches.open(CACHE_NAME);
    const res = await cache.match(CACHE_KEY);
    if (!res) return null;
    await cache.delete(CACHE_KEY);
    const h = JSON.parse(await boundedResponseText(res));
    return h && typeof h.path === 'string' ? h : null;
  } catch {
    return null;
  }
}

export async function runHandoff(handoff) {
  if (!handoff) return;
  if (handoff.error) {
    toast(String(handoff.error).slice(0, 200), { error: true });
    return;
  }
  const { path } = handoff;
  const params = new URLSearchParams(handoff.search || '');

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
    await openGcal('https://calendar.google.com/calendar/render' + (handoff.search || ''));
  } else if (path === '/subscribe') {
    openSubscribe(params.get('url') || '');
  } else if (path === '/import') {
    set({ createDrawer: { kind: 'import' } });
  } else if (path === '/new') {
    set({ quickAddOpen: true, route: 'calendar' });
  } else if (path === '/review') {
    // Where the "an organizer changed ..." notification lands.
    set({ route: 'review' });
  } else if (path === '/settings' || path.startsWith('/settings/')) {
    const tab = path.split('/')[2];
    set(tab ? { route: 'settings', settingsTab: tab } : { route: 'settings' });
  } else if (path === '/google') {
    // Back from Google's consent screen (GoogleController::callback).
    set({ route: 'settings', settingsTab: 'connections' });
    // Fixed words for fixed codes: nothing from the URL reaches the screen (F16).
    const GOOGLE_ERRORS = {
      denied: 'Google sign-in was cancelled; nothing was connected.',
      state: 'The Google sign-in did not come back the way it left (it expired or was started elsewhere). Try again.',
      failed: 'Google accepted the sign-in but connecting the account failed. Try again; the server log has the detail.',
      google: 'Google reported a problem with the sign-in. Try again.',
    };
    if (params.get('connected')) toast('Google account connected. Pick the calendars to add below.');
    else if (params.get('error')) toast(GOOGLE_ERRORS[params.get('error')] || 'Google sign-in did not complete. Try again.', { error: true });
  } else if (path === '/share') {
    const p = normalizeShareParams(handoff.params);
    if (p === null) {
      toast('That shared item was too large or invalid, so it was not opened.', { error: true });
      return;
    }
    const shared = [p.url, p.text, p.title].filter(Boolean).join(' ').trim();
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
