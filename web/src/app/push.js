// Web Push client: permission + subscription lifecycle against /push/*, and
// the ?event=instanceId deep link that notification clicks open with.
// Permission is only ever requested from a button press (Enable in Settings,
// or Turn on in the offer below), never by the page on its own.

// Disable on this device is remembered, so the app never signs it back up.
const OFF_KEY = 'bc-push-off';
const OFFERED_KEY = 'bc-push-offered';
const markOff = (off) => { try { if (off) localStorage.setItem(OFF_KEY, '1'); else localStorage.removeItem(OFF_KEY); } catch { /* private mode */ } };

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

// This browser's own push endpoint, or null. The health payload names push
// rows by endpoint; matching against this is how the app knows a failing
// device is THE device it is running on, which is the only case worth a
// banner rather than a line in Settings.
export async function currentPushEndpoint() {
  try {
    if (!pushSupported()) return null;
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();
    return sub ? sub.endpoint : null;
  } catch {
    return null;
  }
}

// What this device is, in words a person recognises (0.6.7): "Pixel 9 Pro ·
// Chrome app", "Mac · Chrome", "iPhone · Safari app". Chromium browsers say
// it through userAgentData (the phone's model is one of its details); the
// rest are read from the user-agent string. "app" when it runs installed.
export async function deviceLabel() {
  const ua = navigator.userAgent || '';
  let where = '';
  let browser = '';
  const d = navigator.userAgentData;
  if (d) {
    let platform = d.platform || '';
    let model = '';
    try {
      const h = await d.getHighEntropyValues(['model', 'platform']);
      model = (h.model || '').trim();
      platform = h.platform || platform;
    } catch { /* the low-entropy platform will do */ }
    where = model || (platform === 'macOS' ? 'Mac' : platform);
    const brand = (d.brands || []).map((b) => b.brand)
      .find((n) => !/Not.?A.?Brand|Chromium/i.test(n));
    browser = brand ? brand.replace(/^(Google|Microsoft) /, '') : 'Chromium';
  } else {
    where = /iPhone/.test(ua) ? 'iPhone' : /iPad/.test(ua) ? 'iPad' : /Android/.test(ua) ? 'Android'
      : /Mac OS X/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : /Linux/.test(ua) ? 'Linux' : '';
    browser = /Firefox\//.test(ua) ? 'Firefox' : /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
  }
  let installed = false;
  try { installed = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true; } catch { /* no media queries */ }
  return [where, browser + (installed ? ' app' : '')].filter(Boolean).join(' · ');
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
    body: { endpoint: sub.endpoint, keys: { p256dh: json.keys.p256dh, auth: json.keys.auth }, label: await deviceLabel() },
  });
  markOff(false);
}

// At launch (0.6.5): a device that should get reminders but isn't signed up.
// Reinstalling the app on a phone drops its subscription, and before this
// nothing noticed until a reminder failed to arrive. Notifications already
// allowed (the owner allowed them in the phone's settings): sign up quietly.
// Never asked: offer it once a fortnight with a Turn on button, since only a
// press may ask. Blocked, switched off here, or the account uses email only:
// nothing.
export async function offerPushOnThisDevice() {
  try {
    if (!pushSupported() || Notification.permission === 'denied') return;
    if (localStorage.getItem(OFF_KEY) === '1') return;
    if (await currentPushEndpoint()) return; // signed up; resyncPush keeps the server in step
    const status = await fetchPushStatus();
    if (!status || !status.vapidConfigured || state.settings.notifyChannel === 'email') return;
    if (Notification.permission === 'granted') {
      await enablePush();
      toast('Reminders are on for this device');
      return;
    }
    const last = Number(localStorage.getItem(OFFERED_KEY) || 0);
    if (Date.now() - last < 14 * 86400000) return;
    localStorage.setItem(OFFERED_KEY, String(Date.now()));
    toast('Get reminders on this device?', {
      duration: 20000,
      actions: [{
        label: 'Turn on',
        run: () => enablePush().then(() => toast('Reminders are on for this device'), (e) => toast((e && e.message) || 'Could not turn reminders on', { error: true })),
      }],
      dismissLabel: 'Not now',
    });
  } catch { /* best-effort: Settings still offers Enable */ }
}

// After a sign-in: a password reset removes every push device on the server
// (so one registered with a stolen session cannot keep receiving), and this
// browser still holds its own subscription. Hand it back, quietly, so the
// owner's devices keep getting reminders without re-enabling anything.
export async function resyncPush() {
  try {
    if (!pushSupported() || Notification.permission !== 'granted') return;
    const reg = await navigator.serviceWorker.getRegistration();
    const sub = reg && await reg.pushManager.getSubscription();
    if (!sub) return;
    const json = sub.toJSON();
    await api('/push/subscribe', {
      method: 'POST',
      // resync: restore a device a reset cleared; never one the owner removed.
      // The label rides along so a device registered before 0.6.7 gets named.
      body: { endpoint: sub.endpoint, keys: { p256dh: json.keys.p256dh, auth: json.keys.auth }, resync: true, label: await deviceLabel() },
    });
  } catch { /* best-effort: Settings still offers Enable */ }
}

// Every device reminders go to, for review in Settings.
export function fetchPushDevices() {
  return api('/push/devices').then((r) => r.devices || []);
}
export function removePushDevice(id) {
  return api('/push/devices/' + id, { method: 'DELETE' });
}
// sha256 of this browser's endpoint, to find it in the device list.
export async function currentEndpointHash() {
  const ep = await currentPushEndpoint();
  if (!ep || !crypto.subtle) return null;
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(ep));
  return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

// Remove this browser's subscription on both ends.
export async function disablePush() {
  if (!pushSupported()) return;
  const reg = await navigator.serviceWorker.getRegistration();
  if (!reg) return;
  markOff(true);
  const sub = await reg.pushManager.getSubscription();
  if (!sub) return;
  await api('/push/unsubscribe', { method: 'POST', body: { endpoint: sub.endpoint } });
  await sub.unsubscribe().catch(() => { /* server side is already gone */ });
}

export function sendTestNotification() {
  return api('/push/test', { method: 'POST' });
}

export function sendTestEmail() {
  return api('/push/test-email', { method: 'POST' });
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
  await openOccurrence(instanceId, at);
}

// Open one occurrence's detail view wherever it is on the calendar: load a
// window that contains it, then open it. Shared by notification links and by
// the Review page, whose items are about events that may be months away.
// quiet: say nothing when it can't be found (a reload restoring an event
// deleted since, 0.9.1).
export async function openOccurrence(instanceId, at, { quiet = false } = {}) {
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
  if (!quiet) toast('Could not find the event from that notification', { error: true });
}
