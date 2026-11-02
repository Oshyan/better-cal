// API client: JSON fetch wrapper with CSRF header, error envelope handling,
// 401 -> login, and generations for window loads (docs/design/loading-model.md)
// and request ids for search, so out-of-order responses never render.

import { state, set, mergeWindow, pruneOccurrenceCache, missingRanges, patchOccurrence, invalidateRecords, toast, freshRanges, recordGeneration, dataGeneration, newGeneration } from './store.js';
import { defineReload, owe, landed, reload, reloadGeneration } from './reloads.js';
import { toISOWithOffset, dateOfDayKey } from '../lib/dates.js';
import { adoptSettings } from './settings.js';
import { clearAllDrafts } from './drafts.js';
import { clearResume } from './resume-storage.js';
import { newestOnly } from './lists.js';

const BASE = '/api/v1';
// Monotonic local authentication generation. Logout, a live 401 and a new
// login invalidate session responses already being parsed in another task.
let authEpoch = 0;

export class ApiError extends Error {
  constructor(code, message, status) {
    super(message || code);
    this.code = code;
    this.status = status;
  }
}

export async function api(path, { method = 'GET', body, formData, signal } = {}) {
  const requestEpoch = authEpoch;
  if (method !== 'GET' && state.offlineReadOnly && path !== '/auth/login') {
    // The cached /me deliberately contains no CSRF value. Re-establish the
    // live session before a write; if the device is still offline, keep the
    // calendar explicitly read-only instead of sending a doomed mutation.
    await fetchMe();
    if (state.offlineReadOnly) throw new ApiError('offline_read_only', 'Offline saved data is read-only', 0);
  }
  if (requestEpoch !== authEpoch) throw new ApiError('session_changed', 'Session changed while the request was in flight', 0);
  const headers = {};
  if (method !== 'GET' && state.csrf) headers['X-CSRF'] = state.csrf;
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  let res;
  try {
    res = await fetch(BASE + path, {
      method,
      headers,
      credentials: 'same-origin',
      signal,
      body: formData ? formData : (body !== undefined ? JSON.stringify(body) : undefined),
    });
  } catch (e) {
    if (e && e.name === 'AbortError') throw new ApiError('aborted', 'Request superseded', 0);
    throw new ApiError('network', 'Network error', 0);
  }
  // A response belongs only to the login generation that sent it. In
  // particular, a delayed 401 from the prior session must not sign out a
  // login that completed while that old request was in flight.
  if (requestEpoch !== authEpoch) throw new ApiError('session_changed', 'Session changed while the request was in flight', 0);
  if (res.status === 401) {
    // The session is gone (expired, revoked by a password reset, or signed
    // out elsewhere), so this browser no longer has any claim to the data it
    // cached under it.
    purgePrivateCaches();
    clearResume();
    authEpoch++;
    set({ authed: false, user: null, csrf: null, offlineReadOnly: false });
    throw new ApiError('unauthorized', 'Signed out', 401);
  }
  let data = null;
  try { data = await res.json(); } catch { /* empty body */ }
  // Parsing can yield to logout or login just like fetch(). Do not return an
  // old session's body to a caller that may write it into current app state.
  if (requestEpoch !== authEpoch) throw new ApiError('session_changed', 'Session changed while the request was in flight', 0);
  if (!res.ok) {
    const err = (data && data.error) || {};
    // The server is mid-update, or has database changes waiting after new
    // files were uploaded: the app shows that instead of failing piecemeal.
    if (err.code === 'database_update_required') set({ serverState: 'database_update' });
    else if (err.code === 'maintenance') set({ serverState: 'maintenance' });
    throw new ApiError(err.code || 'error', err.message || res.statusText, res.status);
  }
  if (state.serverState) set({ serverState: null });
  if (res.headers.get('X-BetterCal-Offline') === '1' && requestEpoch === authEpoch) set({ offlineReadOnly: true });
  // The journal entries this write made that can be undone (entriesOf), on
  // the answer itself: two writes at once can't mix up whose they are.
  const entries = (res.headers.get('X-Undo-Entries') || '').split(',').map(Number).filter((n) => n > 0);
  if (entries.length && data && typeof data === 'object') Object.defineProperty(data, UNDO_ENTRIES, { value: entries });
  return data;
}

const UNDO_ENTRIES = Symbol('undo entries');

/** The journal entries a write's answer says it made, for its toast's Undo. */
export function entriesOf(data) {
  return (data && data[UNDO_ENTRIES]) || [];
}

// --- session ---------------------------------------------------------------

export async function fetchMe(expectedEpoch = authEpoch) {
  if (expectedEpoch !== authEpoch) throw new ApiError('session_changed', 'Session changed while the request was in flight', 0);
  const data = await api('/me');
  if (expectedEpoch !== authEpoch) throw new ApiError('session_changed', 'Session changed while the request was in flight', 0);
  // Only a live /me can restore a writable session: the worker deliberately
  // removes CSRF from its cached copy. A successful unrelated GET is not
  // enough evidence to leave offline read-only mode.
  const csrf = typeof data.csrf === 'string' && data.csrf !== '' ? data.csrf : null;
  set({ authed: true, user: data.user, csrf, offlineReadOnly: csrf === null });
  // /me carries the merged settings object; apply them (view, week start,
  // time format, theme) before anything renders against defaults.
  adoptSettings(data.user && data.user.settings, { initial: true });
  return data;
}

export async function login(email, password) {
  const loginEpoch = ++authEpoch;
  await api('/auth/login', { method: 'POST', body: { email, password } });
  return fetchMe(loginEpoch);
}

// The service worker's cache of API responses is private to the signed-in
// account: events, people, /me with its CSRF token, feed URLs that are
// themselves capabilities. Signing out has to mean that data is no longer readable here; before this,
// the cache outlived the session and an offline boot rendered the old /me as
// if still signed in (BC-04).
//
// Two routes on purpose. The message lets the worker also discard responses
// still in flight, which would otherwise be cached a moment AFTER the delete.
// The direct delete covers a page the worker does not control yet (first load,
// hard refresh). Never throws: a failed purge must not block signing out.
export async function purgePrivateCaches() {
  try {
    const sw = typeof navigator !== 'undefined' && navigator.serviceWorker;
    if (sw && sw.controller) sw.controller.postMessage({ type: 'purge-api' });
  } catch { /* no worker: the direct delete below is the whole job */ }
  try {
    if (typeof caches !== 'undefined') {
      const keys = await caches.keys();
      await Promise.all(keys.filter((k) => k.endsWith('-api')).map((k) => caches.delete(k)));
    }
  } catch { /* storage unavailable (private mode): nothing was cached either */ }
}

export async function logout(pushEndpointHash = null) {
  // Invalidate any /me already being fetched before making the network call;
  // the CSRF value remains available long enough for this logout request.
  authEpoch++;
  try {
    await api('/auth/logout', {
      method: 'POST',
      body: pushEndpointHash ? { pushEndpointHash } : {},
    });
  } finally {
    // Even when the request fails (offline), clear what is stored locally:
    // that is the half of signing out this device can always do. The caller
    // still sees the error, because the server session is NOT ended.
    // Mark the app signed out before awaiting storage cleanup so a pagehide in
    // between cannot save fresh resume context.
    set({ authed: false, user: null, csrf: null, offlineReadOnly: false });
    await purgePrivateCaches();
    // Unsaved drafts go too, but only here: a session that merely EXPIRED
    // mid-edit (the 401 path) must not cost the owner what they were typing.
    clearAllDrafts();
    clearResume();
  }
}

// --- server config ---------------------------------------------------------

// Public-safe config, fetched once at boot: the MapTiler tile key and which
// place search services are in use (Settings shows them; the boot notice
// reads which ones must all be down before it speaks). Failure just means
// the mini-map keeps its OSM tiles and Settings leaves the row out.
export async function loadConfig() {
  try {
    const data = await api('/config');
    set({ config: {
      maptilerKey: (data && data.maptilerKey) || null,
      placeSearch: (data && data.placeSearch) || null,
    } });
  } catch (e) { /* non-critical */ }
}

// --- calendars -------------------------------------------------------------

// Changes to calendars and folders made in the list go through
// writeCalendars (#187): a drag, A to Z, a checkbox, a calendar's settings,
// a folder's name. Ones that make or remove a calendar (create, delete,
// refresh, claim) call the API and then reload the list.
// - shown at once (show), then sent, one at a time in the order made;
// - a failed one puts back what it showed (revert), unless a newer write
//   to the same thing (key) came since;
// - a reload of the list only shows if no write was unsaved when it was
//   asked for and none began before it answered: one asked for between
//   writes held an older list, and landing late put it back on screen;
// - once the last write is done, however it went, the list is owed a
//   reload until one lands (reloads.js: the change check asks again);
// - and of two reloads, an older answer never replaces a newer one.
let calendarEdits = 0;
let calendarsUnsaved = 0;
let calendarLoads = 0;
let calendarsShown = 0;
let calendarTurn = Promise.resolve();
const latestByKey = new Map();
export function writeCalendars({ key = null, show = null, send, revert = null }) {
  const edit = ++calendarEdits;
  calendarsUnsaved++;
  if (key) latestByKey.set(key, edit);
  if (show) show();
  const run = calendarTurn.then(send, send);
  calendarTurn = run.catch(() => {});
  return run.catch((err) => {
    if (revert && (!key || latestByKey.get(key) === edit)) revert();
    throw err;
  }).finally(() => {
    if (key && latestByKey.get(key) === edit) latestByKey.delete(key);
    calendarsUnsaved--;
    owe('calendars');
    if (calendarsUnsaved === 0) reload('calendars');
  });
}

// Resolves true when this answer is the list shown; one that couldn't be
// (a write unsaved) leaves the list owed a reload, as does a failure.
export async function loadCalendars() {
  const began = reloadGeneration();
  const asked = calendarEdits;
  const clean = calendarsUnsaved === 0;
  const load = ++calendarLoads;
  let data;
  try {
    data = await api('/calendars');
  } catch (e) {
    owe('calendars');
    throw e;
  }
  if (load <= calendarsShown) return false;
  if (!(clean && asked === calendarEdits && calendarsUnsaved === 0)) {
    owe('calendars');
    return false;
  }
  set({ calendars: data.calendars || [], folders: data.folders || [], tags: data.tags || [] });
  calendarsShown = load;
  landed('calendars', began);
  return true;
}
defineReload('calendars', loadCalendars);

// --- people ----------------------------------------------------------------

// Only the newest load's answer is kept: an older one finishing later (a
// save's reload, after an undo's) can't put back the list from before.
export const loadPeople = newestOnly(() => api('/people'), (data) => set({ people: data.people || [] }));

// --- plugins ---------------------------------------------------------------

// Only the newest load's answer is kept (lists.js); neither ever rejects.
const pluginsList = newestOnly(() => api('/plugins'), (data) => set({ plugins: data.plugins || [] }));
export const loadPlugins = () => pluginsList().catch(() => { /* the ops page retries on open */ });

// The sidebar badge: how many things are waiting on a decision (paused calendar
// updates, held invitation changes, mail safety notices, unanswered invitations,
// open proposals, and possible duplicates).
const reviewCount = newestOnly(() => api('/review/count'), (d) => set({ reviewCount: d.count || 0 }));
export const loadReviewCount = () => reviewCount().catch(() => { /* the page shows the real list */ });

// --- system health ---------------------------------------------------------

// Is background work working: feeds, reminders to each device, the worker's
// job types. Loaded at boot for the one-time notices and the device banner,
// refreshed when the Settings page opens.
export async function loadSystemHealth() {
  try {
    const d = await api('/system/health');
    set({ systemHealth: d || null });
    return d;
  } catch (e) {
    return null; // the panel says it could not load; nothing else depends on it
  }
}

// --- application updates --------------------------------------------------

export async function loadUpdates() {
  try {
    const updates = await api('/updates');
    set({ updates });
    return updates;
  } catch {
    return null;
  }
}

export async function checkUpdates() {
  try {
    const updates = await api('/updates/check', { method: 'POST', body: {} });
    set({ updates });
    return updates;
  } catch (e) {
    await loadUpdates();
    throw e;
  }
}

export async function dismissUpdate() {
  const updates = await api('/updates/dismiss', { method: 'POST', body: {} });
  set({ updates });
  return updates;
}

// --- saved views -----------------------------------------------------------

export const loadSavedViews = newestOnly(() => api('/views'), (data) => set({ savedViews: data.views || [] }));

// --- events window loading -------------------------------------------------

// One way to fetch, one idea of fresh, one retry: docs/design/loading-model.md.

// The change cursor just after this tab's own last change, read by a
// refresh that then landed: the change check needn't fetch the window again
// for it (#184: every edit cost two full refreshes).
let ownCursor = null;
export function ownChangeCursor() { return ownCursor; }
// The view's window: what a save, the change check and a retry make fresh.
let lastWindow = null;
// Requests on their way, {start, end, gen, done}. One asked at the current
// generation counts as coming: a view settling its scroll fired
// near-identical queries back to back and paid for both (measured: 800ms +
// 879ms racing on a cold load).
const inFlight = [];

const iso = (ms) => toISOWithOffset(new Date(ms));
const ms = (isoText) => new Date(isoText).getTime();

// The server answers at most about two years per query (Events.php
// MAX_WINDOW_SECONDS) and cuts a longer one short without saying so. The
// whole asked-for span was then marked loaded, so the rest was never fetched
// and those years looked empty. The app now refuses a wider aggregate request
// and asks the caller to narrow it instead of creating a chunk fan-out.
const MAX_SPAN_MS = 700 * 864e5;

// A series with more occurrences in one window than the server will expand
// (1,000; an hourly series, say) is named in the response instead of quietly
// coming back short (#23). Said once per series per page.
// Only for a series this answer shows (one hidden by a filter shows
// nothing, so nothing of it is missing), named from the answer itself.
const cappedSeen = new Set();
function noteCapped(ids, events) {
  for (const id of ids) {
    if (cappedSeen.has(id)) continue;
    const shown = events.find((o) => o.eventId === id && !o.isException);
    if (!shown) continue;
    cappedSeen.add(id);
    toast(`"${shown.title || 'A repeating event'}" repeats more often than one view can show; some of its occurrences here aren't displayed.`, { duration: 10000 });
  }
}

// How many requests one stretch may take to bring a capped series whole:
// an hourly series is a request a month or so.
const CAPPED_REQUESTS = 12;

// The server expands each series in order and stops at its first 1,000, so
// an answer naming capped series is complete up to the earliest of their
// last occurrences returned. An edited date (isException) can sit anywhere,
// so it isn't counted. A capped series with none of its own occurrences in
// the answer (all hidden by a filter, or every one an edited date) shows
// nothing that could be cut short, so it doesn't stop the others' follow
// up. null when there's nothing to go on.
function completeUntil(events, capped) {
  let cut = null;
  for (const id of capped) {
    let last = null;
    for (const o of events) {
      if (o.eventId !== id || o.isException) continue;
      const t = ms(o.start);
      if (last === null || t > last) last = t;
    }
    if (last === null) continue;
    if (cut === null || last < cut) cut = last;
  }
  return cut;
}

// One stretch, asked for at the generation now. An answer that cut a series
// short is held up to where it's complete, and the rest asked for next, so
// a very frequent series comes whole (after a save's refresh it was cut
// short with nothing left to fetch the missing days). Only one too dense
// for that is kept as answered, and says so. A failure throws, and leaves
// what it didn't bring as it was.
async function fetchRange(s, e) {
  if (e - s > MAX_SPAN_MS) {
    throw new ApiError('event_window_too_large', 'This date range is too wide to load safely. Narrow it and try again.', 422);
  }
  const gen = dataGeneration();
  const entry = { start: s, end: e, gen };
  entry.done = new Promise((r) => { entry.settle = r; });
  inFlight.push(entry);
  let ok = false;
  try {
    let a = s;
    for (let n = 1; ; n++) {
      const data = await api('/events?' + new URLSearchParams({ start: iso(a), end: iso(e) }));
      const events = data.events || [];
      const capped = Array.isArray(data.capped) ? data.capped : [];
      const cut = capped.length && n < CAPPED_REQUESTS ? completeUntil(events, capped) : null;
      if (cut !== null && cut > a) {
        // What the next request brings again isn't held from this one.
        mergeWindow(iso(a), iso(cut), events.filter((o) => ms(o.start) < cut || ms(o.end) <= cut), gen);
        a = cut;
        continue;
      }
      if (capped.length) noteCapped(capped, events);
      mergeWindow(iso(a), iso(e), events, gen);
      break;
    }
    ok = true;
  } finally {
    inFlight.splice(inFlight.indexOf(entry), 1);
    entry.settle(ok);
  }
  // Whatever asked for it (a trip's stretch, a day, an older view), it may
  // have been what the view's note waited on.
  settleView();
}

// Gaps widened to whole local months, less what's held or on its way,
// merged, and cut to the server's widest window. Scrolling uncovered one day
// (week view) or one week (month view) at a time, and each request costs the
// server about the same whatever its width, so a year of scrolling sent
// dozens (#184). A month's request covers the next weeks.
function monthGaps(gaps, have) {
  const wide = [];
  for (const g of gaps) {
    const a = new Date(g.start);
    const b = new Date(g.end - 1);
    const from = new Date(a.getFullYear(), a.getMonth(), 1).getTime();
    const to = new Date(b.getFullYear(), b.getMonth() + 1, 1).getTime();
    for (const m of missingRanges(from, to, have)) wide.push(m);
  }
  wide.sort((x, y) => x.start - y.start);
  const merged = [];
  for (const g of wide) {
    const last = merged[merged.length - 1];
    if (last && g.start <= last.end) last.end = Math.max(last.end, g.end);
    else merged.push({ ...g });
  }
  const out = [];
  for (const g of merged) for (let a = g.start; a < g.end; a += MAX_SPAN_MS) out.push({ start: a, end: Math.min(g.end, a + MAX_SPAN_MS) });
  return out;
}

// What's held and fresh, with what's on its way at this generation.
const haveNow = () => {
  const gen = dataGeneration();
  return [...freshRanges(), ...inFlight.filter((r) => r.gen === gen)];
};

// One stretch, in one request; a widened one the server refuses as too
// many rows is asked for as just the days needed (parts).
async function fetchStretch(g, parts) {
  const widened = !(parts.length === 1 && parts[0].start === g.start && parts[0].end === g.end);
  try {
    await fetchRange(g.start, g.end);
  } catch (err) {
    if (!err || err.code !== 'event_window_too_large' || !widened) throw err;
    for (const p of parts) await fetchRange(p.start, p.end);
  }
}

/**
 * Make [s, e) fresh: the one way events are fetched. What's missing (not
 * fresh, not on its way at this generation) is asked for in whole months,
 * one request at a time so a fragmented cache can't burst; a month the
 * server refuses as too many rows is asked for as just the days needed.
 * Just before each request it looks again at what's on its way, so a gap
 * another load asked for meanwhile isn't asked for twice. Requests on their
 * way that it counted on are waited for, then it looks again and fetches
 * whatever they didn't bring, until nothing is missing. A failed request
 * ends it with the failure. onGaps: told what's missing the first time
 * anything is; afterGap: after each gap lands; wanted: asked before each
 * pass and each request, and false ends it (the view moved on).
 */
async function ensure(s, e, { onGaps = null, afterGap = null, wanted = null } = {}) {
  for (;;) {
    if (wanted && !wanted()) return;
    const gen = dataGeneration();
    const coming = inFlight.filter((r) => r.gen === gen && r.start < e && r.end > s);
    const have = [...freshRanges(), ...coming];
    const narrow = missingRanges(s, e, have);
    if (narrow.length === 0 && coming.length === 0) return;
    const gaps = monthGaps(narrow, have);
    if (onGaps && gaps.length) { onGaps(gaps); onGaps = null; }
    for (const gap of gaps) {
      if (wanted && !wanted()) return;
      const left = missingRanges(gap.start, gap.end, haveNow());
      if (left.length === 0) continue; // asked for meanwhile: the next pass waits on it
      const pieces = left.length === 1 && left[0].start === gap.start && left[0].end === gap.end ? [gap] : left;
      for (const piece of pieces) {
        const parts = narrow.filter((n) => n.start < piece.end && n.end > piece.start)
          .map((n) => ({ start: Math.max(n.start, piece.start), end: Math.min(n.end, piece.end) }));
        await fetchStretch(piece, parts.length ? parts : [piece]);
      }
      if (afterGap) afterGap();
    }
    await Promise.all(coming.map((r) => r.done));
  }
}

// Whether any day on screen (as the view last reported) is in these gaps
// and not held at all. A day held from an older generation is shown but not
// fresh: drawn as it was, and asked for again quietly, so a save or a
// change elsewhere doesn't say "Loading" over days already showing.
function unheldOnScreen(gaps) {
  const d = state.visibleDays;
  if (!d || !d.start || !d.end) return false;
  const s = dateOfDayKey(d.start).getTime();
  const end = dateOfDayKey(d.end);
  end.setDate(end.getDate() + 1);
  const e = end.getTime();
  return gaps.some((g) => g.start < e && g.end > s
    && missingRanges(Math.max(g.start, s), Math.min(g.end, e), state.loadedRanges).length > 0);
}

// The view's "Loading" or failure note goes once its window is all held and
// fresh, whichever request brought the last of it.
function settleView() {
  const note = state.windowStatus && state.windowStatus.kind;
  if ((note !== 'failed' && note !== 'loading') || !lastWindow) return;
  if (missingRanges(ms(lastWindow.start), ms(lastWindow.end), freshRanges()).length) return;
  set({ windowStatus: null });
}

const LIMITED_CACHE = 'This date range contains too many events to retain safely. Narrow the visible dates or calendars.';

// Past the cache's ceiling mid-load: the view's window, held whole or not
// at all (pruneOccurrenceCache).
function boundCache(startISO, endISO) {
  if (!lastWindow) return;
  const result = pruneOccurrenceCache(lastWindow.start, lastWindow.end, { activeWindow: lastWindow });
  if (result === 'limited' && lastWindow.start === startISO && lastWindow.end === endISO) {
    throw new ApiError('event_window_too_large', LIMITED_CACHE, 422);
  }
}

/**
 * The view's window: made fresh, with a note while days on screen are on
 * their way. Resolves true once it's all fresh. A failure leaves it owed a
 * reload (reloads.js), retried with the change check's others, and says so;
 * a window the server can never answer says so instead, and isn't retried.
 */
export async function loadWindow(startISO, endISO) {
  lastWindow = { start: startISO, end: endISO };
  const began = reloadGeneration();
  const isView = () => !!lastWindow && lastWindow.start === startISO && lastWindow.end === endISO;
  const limited = (message) => {
    landed('window', began);
    set({ windowStatus: { kind: 'limited', message } });
    return false;
  };
  const s = ms(startISO);
  const e = ms(endISO);
  if (!(e > s) || e - s > MAX_SPAN_MS) return limited('This date range is too wide to load safely. Narrow it and try again.');
  // Only a cold load says "Loading": later windows arrive while the calendar
  // already shows something, and a pill flickering on every scroll is noise.
  // And whenever days on screen aren't held at all: after a jump they
  // looked empty until they arrived (#184). Scrolling ahead, and days on
  // screen held from before a change, are asked for quietly.
  const cold = state.loadedRanges.length === 0 && !state.windowStatus;
  try {
    // Once the view has moved on, this window's loop ends and the cache is
    // bounded only by the view's own load: pruned to the newer window, an
    // older one found its stretch gone after every request and asked again
    // without end.
    await ensure(s, e, {
      onGaps: (gaps) => { if (cold || unheldOnScreen(gaps)) set({ windowStatus: { kind: 'loading' } }); },
      afterGap: () => { if (isView()) boundCache(startISO, endISO); },
      wanted: isView,
    });
  } catch (err) {
    // The view moved on: its own load says how that went.
    if (!isView()) return false;
    if (err && err.code === 'event_window_too_large') return limited(err.message || LIMITED_CACHE);
    owe('window');
    set({ windowStatus: { kind: 'failed', offline: !!(err && err.code === 'offline') } });
    return false;
  }
  if (!isView()) { settleView(); return true; }
  landed('window', began);
  if (state.windowStatus) set({ windowStatus: null });
  return true;
}

// The owed reload of the view's window: whatever of it isn't fresh.
defineReload('window', () => (lastWindow ? loadWindow(lastWindow.start, lastWindow.end) : true));

// A stretch for something other than the view (a trip's "Add events", an
// event opened from a notification, the agenda's next stretch): fetched
// the same way, leaving lastWindow alone. As the view's window it became
// what every refresh after it (a save, undo, the change poll) refetched,
// instead of what's on screen (#183). Rejects when a request fails.
export async function ensureRangeLoaded(startISO, endISO) {
  const s = ms(startISO);
  const e = ms(endISO);
  if (e > s) await ensure(s, e);
}

// One day, complete: an open event steps through its day's events, but it
// can be cached on its own (Review, a notification, search, People, Activity
// and trips insert just the one), with the rest of the day never loaded, so
// stepping showed only it. Fetch whatever of the day isn't held or on its
// way; the open event redraws when it lands. lastWindow is the view's, so
// this leaves it alone.
export async function ensureDayLoaded(dayKey) {
  const next = dateOfDayKey(dayKey);
  next.setDate(next.getDate() + 1);
  try {
    await ensure(dateOfDayKey(dayKey).getTime(), next.getTime());
  } catch { /* offline: the day steps through what is held, as before */ }
}

// The window carries what the grid draws. Description, cadence, reminders,
// timestamps and the like ride the single-event record instead (#15), so a
// surface that shows them asks for the record on open and merges the missing
// fields into the cached occurrence, where every consumer reads from. The
// record is the ROW's serialization: for a recurring instance its start/end
// are the master's, so only the detail-only fields are merged, never the
// occurrence's own identity or times. A window refresh replaces the cached
// occurrence with the list shape again, which is why `full` lives on it.
const DETAIL_FIELDS = ['description', 'rrule', 'reminders', 'reminderSource', 'createdAt', 'updatedAt', 'tzid', 'uid', 'url', 'location', 'locationLat', 'locationLng', 'invite', 'styleJson'];
const detailInFlight = new Map(); // instanceId -> Promise, so a popover then editor open shares one fetch

export function ensureFullOccurrence(instanceId) {
  const occ = state.occ.get(instanceId);
  if (!occ) return Promise.resolve(null);
  if (occ.full && !occ.stale) return Promise.resolve(occ);
  if (detailInFlight.has(instanceId)) return detailInFlight.get(instanceId);
  const gen = recordGeneration();
  const p = api('/events/' + occ.eventId + '/occurrence')
    .then((d) => {
      // Asked before records were marked out of date (a change made
      // elsewhere) or before an edit here, it may hold what they replaced:
      // marked current, it stayed that way until the next change (#185).
      // Ask again.
      if (gen !== recordGeneration()) {
        if (detailInFlight.get(instanceId) === p) detailInFlight.delete(instanceId);
        return ensureFullOccurrence(instanceId);
      }
      const rec = (d && d.occurrence) || {};
      const patch = { full: true, stale: false };
      for (const k of DETAIL_FIELDS) if (k in rec) patch[k] = rec[k];
      patchOccurrence(instanceId, patch, { edit: false });
      return state.occ.get(instanceId);
    })
    .catch(() => {
      // Offline or gone: the surface shows what the window had. Not marking
      // full, so the next open tries again.
      return state.occ.get(instanceId) || null;
    })
    .finally(() => { if (detailInFlight.get(instanceId) === p) detailInFlight.delete(instanceId); });
  detailInFlight.set(instanceId, p);
  return p;
}

// After a change made here (a save, an undo): everything held may be the
// old version of what changed (an edited series, an event moved into a
// month seen earlier), so the generation moves and the view's window is
// fetched again. The rest stays held and is fetched again when next shown
// (#183). The change cursor is read first: when the change check next sees
// that same cursor, the window already holds everything up to it (#184).
// Resolves false when the window didn't land; it's then owed, and the
// change check tries again (#184: a failed refresh was never retried).
export async function refreshWindow() {
  ownCursor = null;
  let cursor = null;
  try { const d = await api('/changes/cursor'); if (d && d.cursor) cursor = d.cursor; } catch { /* the check refreshes as before */ }
  newGeneration();
  owe('window');
  const ok = await reload('window');
  if (ok) ownCursor = cursor;
  return ok;
}

// The change check's other reloads (reloads.js). A failed one, or one that
// didn't apply, stays owed until one lands (#185).
defineReload('people', loadPeople);
defineReload('views', loadSavedViews);
defineReload('review', () => reviewCount().then(() => true));
// Settings changed on another device: not while this tab is editing them,
// owed until it leaves the page. Most moves aren't settings; adopting
// identical ones would only redraw everything that reads them.
defineReload('settings', async () => {
  if (state.route === 'settings') return false;
  const r = await api('/settings');
  const next = r && r.settings;
  if (next && Object.keys(next).some((k) => JSON.stringify(next[k]) !== JSON.stringify(state.settings[k]))) adoptSettings(next);
  return true;
});
// Background state: system health and updates. Owed on its own, so a
// failing health read is asked again without plugins and their bands.
defineReload('status', async () => {
  const [health, updates] = await Promise.all([loadSystemHealth(), loadUpdates()]);
  return health !== null && updates !== null;
});
// Plugins and their bands: refetched once per move of the status part, when
// the list lands; a failed read stays owed and refetches no band.
defineReload('plugins', async () => {
  await pluginsList();
  set({ pluginSeq: state.pluginSeq + 1 });
  return true;
});

// A plugin's ranges in a stretch. The server sends each plugin's earliest
// ranges up to a cap and says when it stopped short (several tide stations
// over a few months pass it), so this asks for the rest a page at a time,
// after the last range sent by its start and key (#183). By start alone,
// ranges sharing a start, or a long one still overlapping, came back again
// and paging stopped short (#185). A refresh between pages (gen changes)
// starts the plugin's pages again: its ranges can move, and pages from two
// sets missed some and kept others' old times. Past 40 pages (20,000 ranges
// a plugin, far past any real stretch) the plugin keeps truncated: true,
// and the person is told; so does one refreshed on every page, after a few
// fresh starts, with the ranges of its last start.
const truncatedSeen = new Set();
export async function fetchPluginRanges(start, end) {
  const first = await api('/plugins/ranges?' + new URLSearchParams({ start, end }));
  const out = {};
  for (const [pid, firstPage] of Object.entries(first.plugins || {})) {
    let entry = firstPage;
    let page = entry;
    let ranges = [...(entry.ranges || [])];
    let sent = new Set(ranges.map((r) => r.key));
    let restarts = 0;
    let mixed = false;
    for (let n = 1; page.truncated && (page.ranges || []).length > 0 && n < 40; n++) {
      const last = page.ranges[page.ranges.length - 1];
      const d = await api('/plugins/ranges?' + new URLSearchParams({ start, end, plugin: pid, afterStart: last.start, afterKey: String(last.key) }));
      page = (d.plugins || {})[pid] || { truncated: false, ranges: [] };
      if (page.gen !== entry.gen) {
        if (restarts < 3) {
          restarts++;
          const again = await api('/plugins/ranges?' + new URLSearchParams({ start, end, plugin: pid }));
          entry = (again.plugins || {})[pid] || { truncated: false, ranges: [] };
          page = entry;
          ranges = [...(entry.ranges || [])];
          sent = new Set(ranges.map((r) => r.key));
          continue;
        }
        mixed = true;
      }
      for (const r of page.ranges || []) {
        if (sent.has(r.key)) continue;
        sent.add(r.key);
        ranges.push(r);
      }
    }
    out[pid] = { ...entry, ranges, truncated: !!page.truncated || mixed };
    if (out[pid].truncated && !truncatedSeen.has(pid)) {
      truncatedSeen.add(pid);
      const pl = (state.plugins || []).find((p) => p.id === pid);
      toast(`${(pl && pl.name) || 'A plugin'} has more bands here than can be shown; some are missing.`, { duration: 10000 });
    }
  }
  return out;
}

// --- search ----------------------------------------------------------------

let searchReqId = 0;

// opts: {when: 'upcoming' | 'all' | 'past', calendar: id or ''} (0.7.3, #60).
// Resolves to {results, pastCount} (pastCount only for an Upcoming search),
// or null when a newer search has superseded this one.
export async function search(q, opts = {}, limit = 50) {
  const id = ++searchReqId;
  const params = { q, limit, when: opts.when || 'all' };
  if (opts.calendar) params.calendar = opts.calendar;
  const data = await api('/search?' + new URLSearchParams(params));
  if (id !== searchReqId) return null; // stale
  return { results: data.results || [], pastCount: data.pastCount || 0 };
}

// --- undo ------------------------------------------------------------------

// After any undo, from a toast or the log: it could have been any event's
// record, a calendar's place or settings, the account's settings (where
// new events go, put back with a calendar), or the window's events.
export function afterUndo() {
  invalidateRecords();
  // People's away times, plugins' bands, an open trip's members, and the
  // lists pages keep of their own (Review, Filters, feeds: undoSeq) are
  // fetched on their own.
  set({ availSeq: state.availSeq + 1, pluginSeq: state.pluginSeq + 1, tripLinksSeq: state.tripLinksSeq + 1, undoSeq: (state.undoSeq || 0) + 1 });
  // Owed until they land, like the change check's (reloads.js).
  const lists = ['review', 'calendars', 'people', 'views', 'settings'];
  owe(...lists);
  for (const name of lists) reload(name);
  return refreshWindow();
}

// A toast's Undo: just the entries its change made, newest first. One a
// later change stands on isn't overwritten from a toast: the log offers
// that, with a warning.
export async function undoEntries(ids) {
  try {
    for (const id of [...ids].sort((a, b) => b - a)) {
      await api('/activity/' + id + '/undo', { method: 'POST', body: {} });
    }
    toast('Undone');
  } catch (e) {
    toast(e.code === 'stale_undo' ? 'Changed since, so not undone here. Activity can undo it anyway.' : e.code === 'not_undoable' ? e.message : 'Undo failed: ' + e.message, { error: true });
  }
  await afterUndo();
}

