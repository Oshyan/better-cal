// Web Push client: permission + subscription lifecycle against /push/*, and
// the ?event=instanceId deep link that notification clicks open with.
// Permission is only ever requested from the explicit Enable button in
// settings, never on page load.

import { api, loadWindow } from './api.js';
import { state, toast } from './store.js';
import { openDetail } from './actions.js';
import { toISOWithOffset } from '../lib/dates.js';

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

// Notification clicks open /?event=instanceId. After boot, load a window
// around now (reminders fire near now), open the event's detail view, and
// strip the query so refreshes do not reopen it.
export async function handleEventLink() {
  const params = new URLSearchParams(window.location.search);
  const instanceId = params.get('event');
  if (!instanceId) return;
  params.delete('event');
  const rest = params.toString();
  window.history.replaceState(null, '', window.location.pathname + (rest ? '?' + rest : ''));
  const now = Date.now();
  try {
    await loadWindow(
      toISOWithOffset(new Date(now - 2 * 86400000)),
      toISOWithOffset(new Date(now + 16 * 86400000)),
    );
  } catch { /* cache may still hold it */ }
  if (state.occ.has(instanceId)) {
    openDetail(instanceId);
  } else {
    toast('Could not find the event from that notification', { error: true });
  }
}
