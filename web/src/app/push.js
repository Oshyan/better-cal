// Web Push client: permission + subscription lifecycle against /push/*, and
// the ?event=instanceId deep link that notification clicks open with.
// Permission is only ever requested from the explicit Enable button in
// settings, never on page load.

import { api, loadWindow } from './api.js';
import { state, toast, insertOccurrence } from './store.js';
import { openDetail } from './actions.js';
import { deepLinkWindows } from '../lib/deeplink.js';

export function pushSupported() {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

// 'granted' | 'denied' | 'default' | 'unsupported'
export function permissionState() {
  return pushSupported() ? Notification.permission : 'unsupported';
}

export function fetchPushStatus() {
  return api('/push/status');
}

// Standard VAPID application server key conversion.
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(base64);
  const out = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
  return out;
}

// Request permission (user gesture), subscribe the service worker, register
// the subscription with the server. Throws with a human message on failure.
export async function enablePush() {
  if (!pushSupported()) throw new Error('This browser does not support push notifications');
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') throw new Error('Notification permission was not granted');
  const { key } = await api('/push/key');
  if (!key) throw new Error('Server VAPID keys are not configured');
  // getRegistration instead of .ready: ready never settles when registration
  // failed, which would leave the Enable button hanging forever.
  const reg = await navigator.serviceWorker.getRegistration();
  if (!reg) throw new Error('Service worker is not registered yet; reload and try again');
  const sub = await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(key),
  });
  const json = sub.toJSON();
  await api('/push/subscribe', {
    method: 'POST',
    body: { endpoint: sub.endpoint, keys: { p256dh: json.keys.p256dh, auth: json.keys.auth } },
  });
}

// Remove this browser's subscription on both ends.
export async function disablePush() {
  if (!pushSupported()) return;
  const reg = await navigator.serviceWorker.getRegistration();
  if (!reg) return;
  const sub = await reg.pushManager.getSubscription();
  if (!sub) return;
  await api('/push/unsubscribe', { method: 'POST', body: { endpoint: sub.endpoint } });
  await sub.unsubscribe().catch(() => { /* server side is already gone */ });
}

export function sendTestNotification() {
  return api('/push/test', { method: 'POST' });
}

// Notification clicks open /?event=instanceId&at=ISO. After boot, load a
// window covering the occurrence (&at= from the server; older notifications
// fall back to the instanceId timestamp), open the event's detail view, and
// strip the query so refreshes do not reopen it.
export async function handleEventLink() {
  const params = new URLSearchParams(window.location.search);
  const instanceId = params.get('event');
  if (!instanceId) return;
  const at = params.get('at');
  params.delete('event');
  params.delete('at');
  const rest = params.toString();
  window.history.replaceState(null, '', window.location.pathname + (rest ? '?' + rest : ''));

  const { broad, tight } = deepLinkWindows(instanceId, at, Date.now());
  try {
    await loadWindow(broad.start, broad.end);
  } catch { /* cache may still hold it; the targeted retry below covers it */ }
  if (state.occ.has(instanceId)) {
    openDetail(instanceId);
    return;
  }
  // Not in the cache. Two known ways that happens even though the event
  // exists: (a) the calendar view issued its own window load concurrently and
  // the monotonic request id discarded our merge as stale; (b) the occurrence
  // is excluded from normal window responses (attendance hidden, or an
  // enabled filter hides it) while reminders still fire for it. Retry once
  // with a tight window around the occurrence, includeHidden=1, and insert
  // just the linked occurrence so hidden events do not leak into the grids.
  try {
    const q = new URLSearchParams({ start: tight.start, end: tight.end, includeHidden: '1' });
    const data = await api('/events?' + q.toString());
    const hit = (data.events || []).find((ev) => ev.instanceId === instanceId);
    if (hit) {
      insertOccurrence(hit);
      openDetail(instanceId);
      return;
    }
  } catch { /* fall through: genuinely unreachable */ }
  toast('Could not find the event from that notification', { error: true });
}
