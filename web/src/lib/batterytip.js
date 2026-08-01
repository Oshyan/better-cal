// Android battery optimization guidance. There is NO web API to request the
// battery optimization exemption (that is native-app-only territory), so a
// one-time dismissible info card telling the user the settings path IS the
// mechanism. Shown in Settings once push is enabled, and once after PWA
// install / first standalone launch; dismissal persists in localStorage.

export const BATTERY_TIP_TITLE = 'Reminders arriving late on Android?';

export const BATTERY_TIP_BODY =
  'Android battery optimization can hold back notifications for minutes or hours. '
  + 'For reliable reminders, open Settings > Apps > Chrome (or this app) > Battery '
  + 'and choose Unrestricted.';

const DISMISS_KEY = 'batteryTipDismissed';
const INSTALL_SHOWN_KEY = 'batteryTipInstallShown';

function store() {
  try {
    return typeof localStorage === 'undefined' ? null : localStorage;
  } catch {
    return null;
  }
}

// The guidance is Android-specific; keep the card off other platforms.
export function batteryTipApplies(ua) {
  const s = ua != null ? ua : (typeof navigator === 'undefined' ? '' : navigator.userAgent || '');
  return /android/i.test(s);
}

export function batteryTipDismissed() {
  const s = store();
  return !s || s.getItem(DISMISS_KEY) === '1';
}

export function dismissBatteryTip() {
  const s = store();
  if (s) s.setItem(DISMISS_KEY, '1');
}

// One-shot install-time tip: fires on the appinstalled event or the first
// standalone-display-mode launch, whichever comes first; never repeats.
export function shouldShowInstallTip() {
  if (!batteryTipApplies() || batteryTipDismissed()) return false;
  const s = store();
  return !!s && s.getItem(INSTALL_SHOWN_KEY) !== '1';
}

export function markInstallTipShown() {
  const s = store();
  if (s) s.setItem(INSTALL_SHOWN_KEY, '1');
}
