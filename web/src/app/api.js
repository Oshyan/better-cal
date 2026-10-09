// API client: JSON fetch wrapper with CSRF header, error envelope handling,
// 401 -> login, and monotonic request ids for window loads and search so
// out-of-order responses never render.

import { state, set, mergeWindow, pruneOccurrenceCache, missingRanges, patchOccurrence, invalidateRecords, toast } from './store.js';
import { toISOWithOffset } from '../lib/dates.js';
import { adoptSettings } from './settings.js';
import { clearEditorDraft, clearQuickAddText } from './drafts.js';
import { clearResume } from './resume-storage.js';

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
    throw new ApiError(err.code || 'error', err.message || res.statusText, res.status);
  }
  if (res.headers.get('X-BetterCal-Offline') === '1' && requestEpoch === authEpoch) set({ offlineReadOnly: true });
  return data;
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
    clearEditorDraft();
    clearQuickAddText();
    clearResume();
  }
}

// --- server config ---------------------------------------------------------

// Public-safe config (MapTiler tile key, map style), fetched once at boot.
// Failure just means the mini-map keeps its OSM tiles.
export async function loadConfig() {
  try {
    const data = await api('/config');
    set({ config: { maptilerKey: (data && data.maptilerKey) || null } });
  } catch (e) { /* non-critical */ }
}

// --- calendars -------------------------------------------------------------

export async function loadCalendars() {
  const data = await api('/calendars');
  set({ calendars: data.calendars || [], folders: data.folders || [], tags: data.tags || [] });
  return data;
}

// --- people ----------------------------------------------------------------

export async function loadPeople() {
  const data = await api('/people');
  set({ people: data.people || [] });
  return data;
}

// --- plugins ---------------------------------------------------------------

export async function loadPlugins() {
  try {
    const data = await api('/plugins');
    set({ plugins: data.plugins || [] });
  } catch (e) { /* the ops page retries on open */ }
}

// The sidebar badge: how many things are waiting on a decision (paused calendar
// updates, held invitation changes, mail safety notices, unanswered invitations,
// open proposals, and possible duplicates).
export async function loadReviewCount() {
  try {
    const d = await api('/review/count');
    set({ reviewCount: d.count || 0 });
  } catch (e) { /* the page shows the real list */ }
}

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

export async function loadSavedViews() {
  const data = await api('/views');
  set({ savedViews: data.views || [] });
  return data;
}

// --- events window loading -------------------------------------------------

let windowReqId = 0;
let lastWindow = null;
// Ranges currently in flight. loadedRanges only knows about ranges that have
// already LANDED, so in-flight spans are fed to missingRanges alongside them —
// otherwise a view settling its scroll fires near-identical queries back to
// back and pays for both (measured: 800ms + 879ms racing on a cold load).
const inFlight = [];

// A wider request makes a narrower one in flight pointless: its response is
// discarded by the windowReqId check anyway, so letting it run just costs a
// second multi-month query racing the one we actually want. A virtualized
// view settling its scroll does exactly this on every cold load (measured:
// Jun-Oct followed ~immediately by Jun-Nov, both ~900ms server-side).
function abortSubsumed(s, e) {
  for (const r of inFlight) {
    if (s <= r.start && e >= r.end && r.ctrl) r.ctrl.abort();
  }
}

const iso = (ms) => toISOWithOffset(new Date(ms));

// Fetch one contiguous gap. Kept separate from loadWindow so the caller can
// issue several concurrently without any of them cancelling the others — they
// cover disjoint spans, so none is stale with respect to the rest.
// The server answers at most about two years per query (Events.php
// MAX_WINDOW_SECONDS) and cuts a longer one short without saying so. The
// whole asked-for span was then marked loaded, so the rest was never fetched
// and those years looked empty. The app now refuses a wider aggregate request
// and asks the caller to narrow it instead of creating a chunk fan-out.
const MAX_SPAN_MS = 700 * 864e5;

// A series with more occurrences in one window than the server will expand
// (1,000; an hourly series, say) is named in the response instead of quietly
// coming back short (#23). Said once per series per page.
const cappedSeen = new Set();
function noteCapped(ids) {
  for (const id of ids) {
    if (cappedSeen.has(id)) continue;
    cappedSeen.add(id);
    let title = '';
    for (const o of state.occ.values()) if (o.eventId === id) { title = o.title; break; }
    toast(`"${title || 'A repeating event'}" repeats more often than one view can show; some of its occurrences here aren't displayed.`, { duration: 10000 });
  }
}

async function fetchRange(s, e) {
  if (e - s > MAX_SPAN_MS) {
    throw new ApiError('event_window_too_large', 'This date range is too wide to load safely. Narrow it and try again.', 422);
  }
  const ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
  const entry = { start: s, end: e, ctrl };
  inFlight.push(entry);
  const startISO = iso(s);
  const endISO = iso(e);
  try {
    const data = await api('/events?' + new URLSearchParams({ start: startISO, end: endISO }), {
      signal: ctrl ? ctrl.signal : undefined,
    });
    mergeWindow(startISO, endISO, data.events || []);
    if (Array.isArray(data.capped) && data.capped.length) noteCapped(data.capped);
  } catch (err) {
    if (!err || err.code !== 'aborted') throw err;
  } finally {
    const i = inFlight.indexOf(entry);
    if (i >= 0) inFlight.splice(i, 1);
  }
}

// A window request that fails (offline, a server error) used to reject with
// nobody listening: the dates were never marked loaded, but nothing asked for
// them again until the view scrolled, so a calendar opened on a bad
// connection sat empty with no word about why (#48). Now a failure is shown
// (windowStatus, shown as a small note over the view by App.js) and retried on a growing
// delay, and at once when the device comes back online.
const RETRY_DELAYS = [2000, 5000, 15000, 30000, 60000];
let retryTimer = null;
let retryStep = 0;
const failedWindows = new Map(); // "start|end" -> {start, end} (ISO), retried together

function windowFailed(startISO, endISO, err) {
  if (err && err.code === 'event_window_too_large') {
    failedWindows.delete(startISO + '|' + endISO);
    if (failedWindows.size === 0 && retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
    if (lastWindow && (lastWindow.start !== startISO || lastWindow.end !== endISO)) return;
    set({
      windowStatus: {
        kind: 'limited',
        message: err.message || 'This date range contains too many events or repeating occurrences. Narrow the visible dates or calendars.',
      },
    });
    return;
  }
  failedWindows.set(startISO + '|' + endISO, { start: startISO, end: endISO });
  // Scrolling while offline asks for window after window; the newest few are
  // enough to retry (a retry that lands covers the view the person is on).
  while (failedWindows.size > 4) failedWindows.delete(failedWindows.keys().next().value);
  const delay = RETRY_DELAYS[Math.min(retryStep, RETRY_DELAYS.length - 1)];
  retryStep++;
  if (retryTimer) clearTimeout(retryTimer);
  retryTimer = setTimeout(retryWindowsNow, delay);
  set({ windowStatus: { kind: 'failed', offline: !!(err && err.code === 'offline'), retryAt: Date.now() + delay } });
}

function windowLoaded(startISO, endISO) {
  const s = new Date(startISO).getTime();
  const e = new Date(endISO).getTime();
  const current = !!lastWindow && lastWindow.start === startISO && lastWindow.end === endISO;
  if (current && state.loadedRanges.some((r) => r.start <= s && r.end >= e)) {
    const pruned = pruneOccurrenceCache(startISO, endISO, { activeWindow: lastWindow });
    if (pruned === 'limited') {
      failedWindows.delete(startISO + '|' + endISO);
      set({
        windowStatus: {
          kind: 'limited',
          message: 'This date range contains too many events to retain safely. Narrow the visible dates or calendars.',
        },
      });
      return;
    }
  }
  failedWindows.delete(startISO + '|' + endISO);
  // An older request may land after the person has navigated elsewhere. It
  // can satisfy its own cache gap, but must not clear or replace the current
  // view's loading/error state.
  if (!current) return;
  if (failedWindows.size > 0) return;
  retryStep = 0;
  if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
  if (state.windowStatus) set({ windowStatus: null });
}

function boundCacheAfterMerge(requestStart, requestEnd) {
  if (!lastWindow) return;
  const result = pruneOccurrenceCache(lastWindow.start, lastWindow.end, {
    activeWindow: lastWindow,
    markLoaded: false,
  });
  const current = lastWindow.start === requestStart && lastWindow.end === requestEnd;
  if (current && result === 'limited') {
    throw new ApiError(
      'event_window_too_large',
      'This date range contains too many events to retain safely. Narrow the visible dates or calendars.',
      422,
    );
  }
}

// Try every failed window again now (the Retry button, coming back online).
export function retryWindowsNow() {
  if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
  const again = [...failedWindows.values()];
  failedWindows.clear();
  for (const w of again) loadWindow(w.start, w.end);
}

if (typeof window !== 'undefined' && window.addEventListener) {
  window.addEventListener('online', () => { if (failedWindows.size > 0) retryWindowsNow(); });
}

export async function loadWindow(startISO, endISO, { force = false } = {}) {
  lastWindow = { start: startISO, end: endISO };
  const s = new Date(startISO).getTime();
  const e = new Date(endISO).getTime();
  if (!(e > s) || e - s > MAX_SPAN_MS) {
    windowFailed(startISO, endISO, new ApiError(
      'event_window_too_large',
      'This date range is too wide to load safely. Narrow it and try again.',
      422,
    ));
    return;
  }
  // Only a cold load says "Loading": later windows arrive while the calendar
  // already shows something, and a pill flickering on every scroll is noise.
  const cold = state.loadedRanges.length === 0 && !state.windowStatus;
  if (force) {
    abortSubsumed(s, e);
    windowReqId++;
    if (cold) set({ windowStatus: { kind: 'loading' } });
    try {
      await fetchRange(s, e);
      boundCacheAfterMerge(startISO, endISO);
      windowLoaded(startISO, endISO);
    } catch (err) {
      windowFailed(startISO, endISO, err);
    }
    return;
  }
  // Ask only for what is not already held or on its way. Scrolling requests a
  // whole window re-centred on the viewport, so each demand overlaps the last
  // by most of its width; sending the overlap again meant a fling queued a
  // dozen multi-thousand-occurrence responses, and merging them starved the
  // frame loop badly enough that requestAnimationFrame stopped firing (#14).
  // The gap is normally a single month.
  const gaps = missingRanges(s, e, [
    ...state.loadedRanges,
    ...inFlight.map((r) => ({ start: r.start, end: r.end })),
  ]);
  // Nothing missing: already held (perhaps by a later, wider window), or on
  // its way. Either way this window no longer counts as failed.
  if (gaps.length === 0) { windowLoaded(startISO, endISO); return; }
  if (cold) set({ windowStatus: { kind: 'loading' } });
  // A fragmented cache can contain many holes. Read them sequentially so one
  // view request cannot create an unbounded burst of simultaneous responses.
  try {
    for (const gap of gaps) {
      await fetchRange(gap.start, gap.end);
      boundCacheAfterMerge(startISO, endISO);
    }
    windowLoaded(startISO, endISO);
  } catch (err) {
    windowFailed(startISO, endISO, err);
  }
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
  if (occ.full) return Promise.resolve(occ);
  if (detailInFlight.has(instanceId)) return detailInFlight.get(instanceId);
  const p = api('/events/' + occ.eventId + '/occurrence')
    .then((d) => {
      const rec = (d && d.occurrence) || {};
      const patch = { full: true };
      for (const k of DETAIL_FIELDS) if (k in rec) patch[k] = rec[k];
      patchOccurrence(instanceId, patch);
      return state.occ.get(instanceId);
    })
    .catch(() => {
      // Offline or gone: the surface shows what the window had. Not marking
      // full, so the next open tries again.
      return state.occ.get(instanceId) || null;
    })
    .finally(() => detailInFlight.delete(instanceId));
  detailInFlight.set(instanceId, p);
  return p;
}

// Refetch the most recently requested window (after mutations/undo).
export async function refreshWindow() {
  if (!lastWindow) return;
  const refreshStart = lastWindow.start;
  const refreshEnd = lastWindow.end;
  windowReqId++;
  const id = windowReqId;
  // In pieces under the server's cap, like any other window (fetchRange).
  const s = new Date(refreshStart).getTime();
  const e = new Date(refreshEnd).getTime();
  for (let a = s; a < e; a += MAX_SPAN_MS) {
    const b = Math.min(e, a + MAX_SPAN_MS);
    const params = new URLSearchParams({ start: iso(a), end: iso(b) });
    try {
      const data = await api('/events?' + params.toString());
      if (id !== windowReqId) return;
      mergeWindow(iso(a), iso(b), data.events || []);
      boundCacheAfterMerge(refreshStart, refreshEnd);
    } catch (err) {
      // A failed refresh only leaves slightly stale data; not fatal.
      if (err && err.code === 'event_window_too_large') windowFailed(refreshStart, refreshEnd, err);
    }
  }
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

export async function undo() {
  try {
    const data = await api('/undo', { method: 'POST' });
    toast('Undone: ' + (data.undone ? data.undone.op + ' ' + data.undone.entity : 'last change'));
    invalidateRecords(); // could have been any event's record
    await refreshWindow();
    return true;
  } catch (e) {
    toast(e.status === 404 ? 'Nothing to undo' : 'Undo failed: ' + e.message, { error: true });
    return false;
  }
}
