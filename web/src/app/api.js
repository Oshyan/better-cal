// API client: JSON fetch wrapper with CSRF header, error envelope handling,
// 401 -> login, and monotonic request ids for window loads and search so
// out-of-order responses never render.

import { state, set, mergeWindow, missingRanges, patchOccurrence, toast } from './store.js';
import { toISOWithOffset } from '../lib/dates.js';
import { adoptSettings } from './settings.js';

const BASE = '/api/v1';

export class ApiError extends Error {
  constructor(code, message, status) {
    super(message || code);
    this.code = code;
    this.status = status;
  }
}

export async function api(path, { method = 'GET', body, formData, signal } = {}) {
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
  if (res.status === 401) {
    set({ authed: false, user: null });
    throw new ApiError('unauthorized', 'Signed out', 401);
  }
  let data = null;
  try { data = await res.json(); } catch { /* empty body */ }
  if (!res.ok) {
    const err = (data && data.error) || {};
    throw new ApiError(err.code || 'error', err.message || res.statusText, res.status);
  }
  return data;
}

// --- session ---------------------------------------------------------------

export async function fetchMe() {
  const data = await api('/me');
  set({ authed: true, user: data.user, csrf: data.csrf });
  // /me carries the merged settings object; apply them (view, week start,
  // time format, theme) before anything renders against defaults.
  adoptSettings(data.user && data.user.settings, { initial: true });
  return data;
}

export async function login(email, password) {
  await api('/auth/login', { method: 'POST', body: { email, password } });
  return fetchMe();
}

export async function logout() {
  await api('/auth/logout', { method: 'POST' });
  set({ authed: false, user: null, csrf: null });
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

export async function loadProposalCount() {
  try {
    const d = await api('/proposals?status=open');
    set({ proposalCount: (d.proposals || []).length });
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
async function fetchRange(s, e) {
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
  } catch (err) {
    if (!err || err.code !== 'aborted') throw err;
  } finally {
    const i = inFlight.indexOf(entry);
    if (i >= 0) inFlight.splice(i, 1);
  }
}

export async function loadWindow(startISO, endISO, { force = false } = {}) {
  lastWindow = { start: startISO, end: endISO };
  const s = new Date(startISO).getTime();
  const e = new Date(endISO).getTime();
  if (force) {
    abortSubsumed(s, e);
    windowReqId++;
    await fetchRange(s, e);
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
  if (gaps.length === 0) return;
  await Promise.all(gaps.map((g) => fetchRange(g.start, g.end)));
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
  windowReqId++;
  const id = windowReqId;
  const params = new URLSearchParams({ start: lastWindow.start, end: lastWindow.end });
  try {
    const data = await api('/events?' + params.toString());
    if (id !== windowReqId) return;
    mergeWindow(lastWindow.start, lastWindow.end, data.events || []);
  } catch (e) {
    // A failed refresh only leaves slightly stale data; not fatal.
  }
}

// --- search ----------------------------------------------------------------

let searchReqId = 0;

export async function search(q, limit = 50) {
  const id = ++searchReqId;
  const data = await api('/search?' + new URLSearchParams({ q, limit }));
  if (id !== searchReqId) return null; // stale
  return data.results || [];
}

// --- undo ------------------------------------------------------------------

export async function undo() {
  try {
    const data = await api('/undo', { method: 'POST' });
    toast('Undone: ' + (data.undone ? data.undone.op + ' ' + data.undone.entity : 'last change'));
    await refreshWindow();
    return true;
  } catch (e) {
    toast(e.status === 404 ? 'Nothing to undo' : 'Undo failed: ' + e.message, { error: true });
    return false;
  }
}
