// API client: JSON fetch wrapper with CSRF header, error envelope handling,
// 401 -> login, and monotonic request ids for window loads and search so
// out-of-order responses never render.

import { state, set, mergeWindow, rangeCovered, toast } from './store.js';
import { adoptSettings } from './settings.js';

const BASE = '/api/v1';

export class ApiError extends Error {
  constructor(code, message, status) {
    super(message || code);
    this.code = code;
    this.status = status;
  }
}

export async function api(path, { method = 'GET', body, formData } = {}) {
  const headers = {};
  if (method !== 'GET' && state.csrf) headers['X-CSRF'] = state.csrf;
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  let res;
  try {
    res = await fetch(BASE + path, {
      method,
      headers,
      credentials: 'same-origin',
      body: formData ? formData : (body !== undefined ? JSON.stringify(body) : undefined),
    });
  } catch (e) {
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

// --- saved views -----------------------------------------------------------

export async function loadSavedViews() {
  const data = await api('/views');
  set({ savedViews: data.views || [] });
  return data;
}

// --- events window loading -------------------------------------------------

let windowReqId = 0;
let lastWindow = null;

export async function loadWindow(startISO, endISO, { force = false } = {}) {
  lastWindow = { start: startISO, end: endISO };
  if (!force && rangeCovered(startISO, endISO)) return;
  const id = ++windowReqId;
  const params = new URLSearchParams({ start: startISO, end: endISO });
  const data = await api('/events?' + params.toString());
  if (id !== windowReqId) return; // stale response: a newer request superseded it
  mergeWindow(startISO, endISO, data.events || []);
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
