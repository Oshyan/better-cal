// Installing the app (0.9.2, the first-run welcome). Chromium browsers offer
// an install prompt the page may show from a button; it arrives once, early,
// as `beforeinstallprompt`, so main.js imports this module at boot to keep it.
// Safari and Firefox have no such prompt: the welcome says where their own
// menu puts it.

let deferred = null;
let listeners = new Set();

if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); // keep it for our button rather than the browser's banner
    deferred = e;
    for (const fn of listeners) fn();
  });
  window.addEventListener('appinstalled', () => {
    deferred = null;
    for (const fn of listeners) fn();
  });
}

export function isInstalled() {
  try { return matchMedia('(display-mode: standalone)').matches || navigator.standalone === true; } catch { return false; }
}

export function canPromptInstall() {
  return !!deferred;
}

/** Shows the browser's install dialog; resolves true when installed. */
export async function promptInstall() {
  if (!deferred) return false;
  const e = deferred;
  deferred = null;
  e.prompt();
  const choice = await e.userChoice.catch(() => null);
  for (const fn of listeners) fn();
  return !!(choice && choice.outcome === 'accepted');
}

export function onInstallChange(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

/** Where this browser's own menu offers installing, for when there's no prompt. */
export function installHint() {
  const ua = navigator.userAgent || '';
  if (/iPhone|iPad|iPod/.test(ua)) return 'In Safari: the Share button, then Add to Home Screen.';
  if (/Android/.test(ua)) return "In your browser's menu: Install app, or Add to Home screen.";
  if (/Firefox\//.test(ua)) return 'Firefox on a computer has no install; Chrome, Edge or Safari do.';
  if (/Safari\//.test(ua) && !/Chrome\//.test(ua)) return 'In Safari: File, then Add to Dock.';
  return "In your browser's address bar or menu: Install Better-Cal.";
}
