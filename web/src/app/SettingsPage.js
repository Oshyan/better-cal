// Settings: user preferences (GET/PATCH /settings), saved per control on
// change, applied client-side immediately (view, week start, time format,
// theme; nlParseMode is enforced server-side in /quickadd). Plus account
// (sign out) and about (version, CalDAV pointer).

import { html, useState, useEffect, useMemo } from '../../vendor/index.js';
import { useStore, toast, shallowEq } from './store.js';
import { api, logout, loadSystemHealth } from './api.js';
import { fmtSince } from '../lib/since.js';
import { adoptSettings } from './settings.js';
import { saveSetting } from './actions.js';
import { PageShell } from './PageShell.js';
import { PlaceInput, pickFillText } from './PlaceInput.js';
import { localTz, sameClock, tzOffsetLabel, tzCity } from '../lib/dates.js';
import {
  permissionState, pushSupported, fetchPushStatus, enablePush, disablePush,
  sendTestNotification, sendTestEmail,
} from './push.js';
import {
  BATTERY_TIP_TITLE, BATTERY_TIP_BODY, batteryTipApplies, batteryTipDismissed, dismissBatteryTip,
} from '../lib/batterytip.js';
import {
  TIMED_CHOICES, ALLDAY_DAYS_CHOICES, REMINDER_UNITS, fmtOffsetMinutes,
  toMinutes, fromMinutes,
} from '../lib/reminders.js';

const VIEW_OPTIONS = [
  ['month', 'Month'], ['weeks3', '3 weeks'], ['weeks2', '2 weeks'],
  ['week', 'Week'], ['day', 'Day'], ['agenda', 'Agenda'],
];

const MAP_STYLES = [
  ['streets-v2', 'Streets'], ['dataviz', 'Minimal'], ['outdoor-v2', 'Outdoor'], ['bright-v2', 'Bright'],
];

const CHANNEL_OPTIONS = [
  ['push', 'Push'], ['email', 'Email'], ['both', 'Push and email'], ['push-fallback', 'Push, email as fallback'],
];

const CHANNEL_HINTS = {
  push: 'Notifications go to devices where you enabled push; nothing is emailed.',
  email: 'Every reminder is emailed to your account address; no push notifications.',
  both: 'Every reminder goes out as a push notification and an email.',
  'push-fallback': 'Push normally; an email goes out only when no device looks reachable or every push send fails. A push the service accepts but drops later (device off too long) cannot be detected, so it will not trigger an email.',
};

const NL_HINTS = {
  always: 'The AI parses every quick add; most flexible, every entry costs an AI call.',
  smart: 'AI is consulted only when the built-in parser is unsure; occasional small AI cost.',
  never: 'Built-in parser only; instant and free, but less flexible phrasing.',
};

function Seg({ value, options, onChange, label }) {
  return html`<span class="bc-seg" role="group" aria-label=${label}>
    ${options.map(([v, l]) => html`<button
      key=${v} type="button"
      class="bc-seg-btn${value === v ? ' is-active' : ''}"
      aria-pressed=${value === v}
      onClick=${() => value !== v && onChange(v)}
    >${l}</button>`)}
  </span>`;
}

// Is background work working. One row per subject: the worker's job types
// (system-wide), each subscribed feed, each device reminders go to. Failing
// rows first. Transitions are also in Activity under "System", and a streak
// that lasts earns one email; this is the "right now" view.
function SystemSection() {
  const health = useStore((s) => s.systemHealth);
  const [loaded, setLoaded] = useState(false);
  useEffect(() => { loadSystemHealth().finally(() => setLoaded(true)); }, []);
  const rows = (health && health.rows) || [];
  const failing = rows.filter((r) => r.status === 'failing');
  const alerts = health && health.emailAlerts;
  const when = (iso) => (iso ? new Date(iso).toLocaleString() : '');
  return html`<section class="bc-set-section">
    <h2 class="bc-set-h">System</h2>
    <${Row} label="Background work" hint=${!loaded ? 'Checking…' : (rows.length === 0 ? 'Nothing has reported yet; the worker fills this in as jobs run.'
      : (failing.length === 0 ? 'Everything that has reported is working.' : failing.length + ' failing'))}>
      ${rows.length > 0 && html`<table class="bc-sys-table">
        <thead><tr><th>What</th><th>Status</th><th>Last OK</th><th>Detail</th></tr></thead>
        <tbody>${rows.map((r) => html`<tr key=${r.subject} class=${r.status === 'failing' ? 'is-failing' : ''}>
          <td>${r.label}</td>
          <td>${r.status === 'failing'
            ? html`<span class="bc-sys-bad">failing since ${fmtSince(r.firstFailedAt)} (${r.consecutiveFailures}×)</span>`
            : html`<span class="bc-sys-ok">ok</span>`}</td>
          <td title=${when(r.lastOkAt)}>${r.lastOkAt ? fmtSince(r.lastOkAt) : 'never'}</td>
          <td class="bc-sys-err" title=${r.lastError || ''}>${r.status === 'failing' && r.lastError ? r.lastError : ''}${r.alertedAt ? html` <span class="bc-badge">emailed ${fmtSince(r.alertedAt)}</span>` : ''}</td>
        </tr>`)}</tbody>
      </table>`}
    <//>
    <${Row} label="Failure emails" hint=${alerts && alerts.configured
      ? 'One email when something has been failing long enough to be real (jobs: 1 hour and 3 failures; feeds: a day; a device: 6 hours), one when it recovers, never one per failure.'
      : 'Off: email is not configured on this server (BETTERCAL_SMTP_HOST and BETTERCAL_SMTP_FROM). Failures still show here and in Activity.'}>
      ${alerts && alerts.configured && html`<span class="bc-set-value">
        Your feeds and devices: ${alerts.accountTo}${alerts.systemTo !== alerts.accountTo ? html`<br />System-wide: ${alerts.systemTo}` : html`<br />System-wide: same address (set BETTERCAL_ALERT_EMAIL to change)`}
      </span>`}
    <//>
  </section>`;
}

function Row({ label, hint, children }) {
  return html`<div class="bc-set-row">
    <span class="bc-set-label">${label}</span>
    <span class="bc-set-control">${children}</span>
    ${hint && html`<span class="bc-set-hint">${hint}</span>`}
  </div>`;
}

// Home time zone. What is on screen always follows the device; this is the
// zone the SERVER uses where there is no device to ask (the hint says which).
// When the device is on a different clock the row says so and offers the
// one-click change, because that is the moment the distinction matters.
const TZ_HINT = 'The clock for all-day reminders, and the zone of events created for you by email, plugins and the API. What you see on screen always follows the device you are using.';
function HomeTimezoneRow({ tz, onChange }) {
  const device = localTz();
  // Labels are memoized with the list: each offset costs an Intl formatter,
  // and there are ~420 zones.
  const zones = useMemo(() => {
    let all = [];
    try { all = Intl.supportedValuesOf('timeZone'); } catch { /* older browser: offer what we know */ }
    const now = new Date();
    return [...new Set([tz, device, ...all].filter(Boolean))].sort()
      .map((z) => [z, z.replace(/_/g, ' ') + ' (' + tzOffsetLabel(z, now) + ')']);
  }, [tz, device]);
  const away = !sameClock(tz, device);
  return html`<${Row} label="Home time zone" hint=${TZ_HINT}>
    <select value=${tz || device} aria-label="Home time zone" onChange=${(e) => onChange(e.target.value)}>
      ${zones.map(([z, label]) => html`<option key=${z} value=${z}>${label}</option>`)}
    </select>
    ${away && html`<span class="bc-set-away">
      This device is on ${tzCity(device)} time (${tzOffsetLabel(device)})
      <button type="button" class="bc-btn" onClick=${() => onChange(device)}>Make ${tzCity(device)} Home</button>
    </span>`}
  <//>`;
}

// Notifications: Web Push status + enable/disable/test, delivery channel
// (push/email), Android battery guidance, plus the global default reminder
// editors (the fallbacks behind calendar/event overrides).
function NotificationsSection({ settings, user }) {
  const [status, setStatus] = useState(null); // {subscribed, vapidConfigured, emailConfigured}
  const [perm, setPerm] = useState(permissionState());
  const [busy, setBusy] = useState(false);
  const [tipHidden, setTipHidden] = useState(batteryTipDismissed());

  // Email destination: mirrors the notifyEmail setting; blank means the
  // account address (which is also the SMTP sender mailbox).
  const [notifyTo, setNotifyTo] = useState(settings.notifyEmail || '');
  useEffect(() => { setNotifyTo(settings.notifyEmail || ''); }, [settings.notifyEmail]);
  const accountEmail = (user && user.email) || '';
  const effectiveEmail = settings.notifyEmail || accountEmail;
  const saveNotifyEmail = () => {
    const v = notifyTo.trim();
    if ((v || null) === (settings.notifyEmail || null)) return;
    saveSetting('notifyEmail', v === '' ? null : v);
  };

  const refresh = () => {
    setPerm(permissionState());
    fetchPushStatus().then(setStatus).catch(() => setStatus(null));
  };
  useEffect(refresh, []);

  let statusText;
  if (!pushSupported()) statusText = 'Not supported in this browser';
  else if (status && !status.vapidConfigured) statusText = 'Server not configured (VAPID keys missing)';
  else if (perm === 'denied') statusText = 'Blocked in this browser; allow notifications in site settings';
  else if (status && status.subscribed && perm === 'granted') statusText = 'Enabled';
  else if (status && status.subscribed) statusText = 'Subscribed on the server, but this browser has not granted permission';
  else statusText = 'Off';

  const canEnable = pushSupported() && status && status.vapidConfigured && perm !== 'denied';
  const enabled = !!(status && status.subscribed && perm === 'granted');

  const run = (fn, okText) => async () => {
    setBusy(true);
    try {
      const res = await fn();
      if (okText) toast(typeof okText === 'function' ? okText(res) : okText);
      refresh();
    } catch (e) {
      toast((e && e.message) || 'Push action failed', { error: true });
    }
    setBusy(false);
  };

  // Timed default: presets plus a Custom option revealing number + unit
  // inputs. Stored shape stays [{minutes}].
  const timedMinutes = settings.reminderTimed && settings.reminderTimed.length > 0
    ? Number(settings.reminderTimed[0].minutes) || 0
    : null;
  const [timedCustom, setTimedCustom] = useState(null); // {n, unit} | null
  const timedCustomActive = timedCustom !== null
    || (timedMinutes !== null && !TIMED_CHOICES.includes(timedMinutes));
  const timedPair = timedCustom
    || (timedMinutes !== null ? fromMinutes(timedMinutes) : { n: 30, unit: 'minutes' });
  const saveTimedPair = (pair) => {
    setTimedCustom(pair);
    saveSetting('reminderTimed', [{ minutes: toMinutes(pair.n, pair.unit) }]);
  };
  const onTimedSelect = (v) => {
    if (v === 'custom') { setTimedCustom(timedPair); return; }
    setTimedCustom(null);
    saveSetting('reminderTimed', v === '' ? [] : [{ minutes: Number(v) }]);
  };

  // All-day default: day presets plus Custom number + unit (days/weeks).
  const allDayEntry = settings.reminderAllDay && settings.reminderAllDay.length > 0
    ? settings.reminderAllDay[0]
    : null;
  const allDayDays = allDayEntry ? Number(allDayEntry.daysBefore) || 0 : null;
  const [allDayCustom, setAllDayCustom] = useState(null); // {n, unit} | null
  const allDayCustomActive = allDayCustom !== null
    || (allDayDays !== null && !ALLDAY_DAYS_CHOICES.some(([d]) => d === allDayDays));
  const allDayPair = allDayCustom || (allDayDays !== null && allDayDays > 0 && allDayDays % 7 === 0
    ? { n: allDayDays / 7, unit: 'weeks' }
    : { n: allDayDays == null ? 3 : allDayDays, unit: 'days' });

  const saveAllDay = (patch) => {
    const base = allDayEntry || { daysBefore: 1, time: '18:00' };
    saveSetting('reminderAllDay', [{ ...base, ...patch }]);
  };
  const saveAllDayPair = (pair) => {
    setAllDayCustom(pair);
    const days = Math.max(0, Math.min(28, Math.round((Number(pair.n) || 0) * (pair.unit === 'weeks' ? 7 : 1))));
    saveAllDay({ daysBefore: days });
  };
  const onAllDaySelect = (v) => {
    if (v === 'custom') { setAllDayCustom(allDayPair); return; }
    setAllDayCustom(null);
    if (v === '') saveSetting('reminderAllDay', []);
    else saveAllDay({ daysBefore: Number(v) });
  };

  return html`<section class="bc-set-section">
    <h2 class="bc-set-h">Notifications</h2>
    <${Row} label="Reminders on this device" hint="Reminders arrive as push notifications even when the app is closed.">
      <span class="bc-set-value">${statusText}</span>
      ${!enabled && html`<button type="button" class="bc-btn" disabled=${busy || !canEnable}
        onClick=${run(enablePush, 'Notifications enabled')}>Enable</button>`}
      ${enabled && html`<button type="button" class="bc-btn" disabled=${busy}
        onClick=${run(disablePush, 'Notifications disabled')}>Disable</button>`}
      ${enabled && html`<button type="button" class="bc-btn" disabled=${busy}
        onClick=${run(sendTestNotification, (r) => 'Test sent to ' + ((r && r.sent) || 0) + ' device(s)')}>Send test notification</button>`}
    <//>
    ${enabled && !tipHidden && batteryTipApplies() && html`<div class="bc-card">
      <div class="bc-card-main">
        <span class="bc-card-title">${BATTERY_TIP_TITLE}</span>
        <span class="bc-card-sub">${BATTERY_TIP_BODY}</span>
      </div>
      <div class="bc-card-actions">
        <button type="button" class="bc-btn" onClick=${() => { dismissBatteryTip(); setTipHidden(true); }}>Got it</button>
      </div>
    </div>`}
    <${Row} label="Delivery channel" hint=${CHANNEL_HINTS[settings.notifyChannel] || CHANNEL_HINTS.push}>
      <select aria-label="Reminder delivery channel"
        value=${settings.notifyChannel || 'push'}
        onChange=${(e) => saveSetting('notifyChannel', e.target.value)}>
        ${CHANNEL_OPTIONS.map(([v, l]) => html`<option key=${v} value=${v}>${l}</option>`)}
      </select>
    <//>
    <${Row} label=${status && status.emailConfigured ? 'Send email to' : 'Reminder email'}
      hint=${status && status.emailConfigured
        ? 'Blank sends to your account address. The test goes to ' + effectiveEmail + '.'
        : 'Reminder emails go to your account address.'}>
      ${status && status.emailConfigured
        ? html`<input type="email" aria-label="Send email to" placeholder=${accountEmail}
            value=${notifyTo} onInput=${(e) => setNotifyTo(e.target.value)} onChange=${saveNotifyEmail} />`
        : html`<span class="bc-set-value">${accountEmail}</span>`}
      ${status && status.emailConfigured === false
        && html`<span class="bc-set-value">Email not configured on server</span>`}
      <button type="button" class="bc-btn" disabled=${busy || !(status && status.emailConfigured)}
        onClick=${run(sendTestEmail, (r) => 'Test email sent to ' + ((r && r.to) || 'your address'))}>Send test email</button>
    <//>
    <${Row} label="Default reminder (timed events)" hint="Used unless a calendar or event overrides it. Custom accepts up to 4 weeks ahead.">
      <select aria-label="Default reminder for timed events"
        value=${timedCustomActive ? 'custom' : (timedMinutes === null ? '' : String(timedMinutes))}
        onChange=${(e) => onTimedSelect(e.target.value)}>
        <option value="">None</option>
        ${TIMED_CHOICES.map((m) => html`<option key=${m} value=${String(m)}>${fmtOffsetMinutes(m)}</option>`)}
        <option value="custom">Custom</option>
      </select>
      ${timedCustomActive && html`<span class="bc-rem-custom">
        <input class="bc-num" type="number" min="0" max="40320" aria-label="Custom reminder amount"
          value=${timedPair.n}
          onChange=${(e) => saveTimedPair({ ...timedPair, n: Number(e.target.value) || 0 })} />
        <select aria-label="Custom reminder unit" value=${timedPair.unit}
          onChange=${(e) => saveTimedPair({ ...timedPair, unit: e.target.value })}>
          ${REMINDER_UNITS.map((u) => html`<option key=${u} value=${u}>${u}</option>`)}
        </select>
        <span class="bc-set-hint">before start</span>
      </span>`}
    <//>
    <${Row} label="Default reminder (all-day events)" hint="Fires at the chosen time in the event's timezone. Custom accepts up to 4 weeks ahead.">
      <select aria-label="Default reminder day for all-day events"
        value=${allDayCustomActive ? 'custom' : (allDayDays === null ? '' : String(allDayDays))}
        onChange=${(e) => onAllDaySelect(e.target.value)}>
        <option value="">None</option>
        ${ALLDAY_DAYS_CHOICES.map(([d, label]) => html`<option key=${d} value=${String(d)}>${label}</option>`)}
        <option value="custom">Custom</option>
      </select>
      ${allDayCustomActive && html`<span class="bc-rem-custom">
        <input class="bc-num" type="number" min="0" max="28" aria-label="Custom reminder amount for all-day events"
          value=${allDayPair.n}
          onChange=${(e) => saveAllDayPair({ ...allDayPair, n: Number(e.target.value) || 0 })} />
        <select aria-label="Custom reminder unit for all-day events" value=${allDayPair.unit}
          onChange=${(e) => saveAllDayPair({ ...allDayPair, unit: e.target.value })}>
          <option value="days">days</option>
          <option value="weeks">weeks</option>
        </select>
        <span class="bc-set-hint">before</span>
      </span>`}
      ${allDayEntry && html`<input type="time" aria-label="Default reminder time for all-day events"
        value=${allDayEntry.time} onChange=${(e) => e.target.value && saveAllDay({ time: e.target.value })} />`}
    <//>
  </section>`;
}

// Home location (place search bias) + map style. The home input mirrors the
// stored homeLabel; picking a place or using device geolocation saves the
// lat/lng pair and label in one PATCH.
function LocationSection({ settings, config }) {
  const [homeText, setHomeText] = useState(settings.homeLabel || '');
  const [locBusy, setLocBusy] = useState(false);
  useEffect(() => { setHomeText(settings.homeLabel || ''); }, [settings.homeLabel]);

  const saveHome = async (patch, okText) => {
    try {
      const data = await api('/settings', { method: 'PATCH', body: patch });
      adoptSettings(data.settings);
      toast(okText, { duration: 2000 });
    } catch (e) {
      toast('Save failed: ' + e.message, { error: true });
    }
  };

  const useMyLocation = () => {
    if (!navigator.geolocation) {
      toast('Geolocation is not available in this browser', { error: true });
      return;
    }
    setLocBusy(true);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setLocBusy(false);
        const label = 'My location';
        setHomeText(label);
        saveHome({
          homeLat: Math.round(pos.coords.latitude * 10000) / 10000,
          homeLng: Math.round(pos.coords.longitude * 10000) / 10000,
          homeLabel: label,
        }, 'Home location saved');
      },
      () => {
        setLocBusy(false);
        toast('Could not get your location', { error: true });
      },
      { timeout: 10000 },
    );
  };

  const hasHome = settings.homeLat != null && settings.homeLng != null;

  return html`<section class="bc-set-section">
    <h2 class="bc-set-h">Location and maps</h2>
    <${Row} label="Home location" hint="Biases location search toward your area so nearby places match first.">
      <${PlaceInput}
        value=${homeText}
        ariaLabel="Home location"
        placeholder="Search for your city or address"
        tz=${localTz()}
        onText=${setHomeText}
        onPick=${(r) => {
          const label = pickFillText(r);
          setHomeText(label);
          saveHome({ homeLat: r.lat, homeLng: r.lng, homeLabel: label }, 'Home location saved');
        }}
      />
      <button type="button" class="bc-btn" disabled=${locBusy} onClick=${useMyLocation}>Use my location</button>
      ${hasHome && html`<button type="button" class="bc-btn" onClick=${() => {
        setHomeText('');
        saveHome({ homeLat: null, homeLng: null, homeLabel: null }, 'Home location cleared');
      }}>Clear</button>`}
    <//>
    <${Row} label="Map style" hint=${config && config.maptilerKey
      ? 'Style for the event detail map.'
      : 'Needs a MapTiler key on the server (BETTERCAL_MAPTILER_KEY); the map uses OpenStreetMap tiles until then.'}>
      <select
        value=${settings.mapStyle || 'streets-v2'}
        aria-label="Map style"
        disabled=${!(config && config.maptilerKey)}
        onChange=${(e) => saveSetting('mapStyle', e.target.value)}
      >
        ${MAP_STYLES.map(([v, l]) => html`<option key=${v} value=${v}>${l}</option>`)}
      </select>
    <//>
  </section>`;
}

export function SettingsPage() {
  const { settings, user, calendars, config } = useStore(
    (s) => ({ settings: s.settings, user: s.user, calendars: s.calendars, config: s.config }),
    shallowEq,
  );
  const [version, setVersion] = useState(null);
  const [busy, setBusy] = useState(false);

  // Refresh from the server on entry so the page never shows stale values.
  useEffect(() => {
    api('/settings')
      .then((data) => adoptSettings(data.settings))
      .catch(() => { /* boot values remain; per-control saves still work */ });
    api('/health').then((h) => setVersion(h && h.version)).catch(() => {});
  }, []);

  const save = (key) => (value) => saveSetting(key, value);
  const localCals = calendars.filter((c) => c.kind === 'local');

  const signOut = async () => {
    setBusy(true);
    try {
      await logout();
    } catch (e) {
      toast('Sign out failed: ' + e.message, { error: true });
      setBusy(false);
    }
  };

  return html`<${PageShell} title="Settings" note="Changes are saved as you make them.">
    <section class="bc-set-section">
      <h2 class="bc-set-h">General</h2>
      <${Row} label="Default view">
        <select value=${settings.defaultView} aria-label="Default view" onChange=${(e) => save('defaultView')(e.target.value)}>
          ${VIEW_OPTIONS.map(([v, l]) => html`<option key=${v} value=${v}>${l}</option>`)}
        </select>
      <//>
      <${Row} label="Week starts on">
        <${Seg} label="Week starts on" value=${settings.weekStart}
          options=${[['sun', 'Sunday'], ['mon', 'Monday']]} onChange=${save('weekStart')} />
      <//>
      <${Row} label="Time format">
        <${Seg} label="Time format" value=${settings.timeFormat}
          options=${[['12', '12-hour'], ['24', '24-hour']]} onChange=${save('timeFormat')} />
      <//>
      <${Row} label="Theme">
        <${Seg} label="Theme" value=${settings.theme}
          options=${[['system', 'System'], ['light', 'Light'], ['dark', 'Dark']]} onChange=${save('theme')} />
      <//>
      <${Row} label="Default calendar" hint="Where new events land unless you pick otherwise.">
        <select
          value=${settings.defaultCalendarId == null ? '' : String(settings.defaultCalendarId)}
          aria-label="Default calendar"
          onChange=${(e) => save('defaultCalendarId')(e.target.value === '' ? null : Number(e.target.value))}
        >
          <option value="">None</option>
          ${localCals.map((c) => html`<option key=${c.id} value=${String(c.id)}>${c.name}</option>`)}
        </select>
      <//>
      <${HomeTimezoneRow} tz=${settings.tz} onChange=${save('tz')} />
    </section>

    <${LocationSection} settings=${settings} config=${config} />

    <${NotificationsSection} settings=${settings} user=${user} />

    <section class="bc-set-section">
      <h2 class="bc-set-h">Quick add</h2>
      <${Row} label="Natural language parsing" hint=${NL_HINTS[settings.nlParseMode] || ''}>
        <${Seg} label="Natural language parsing" value=${settings.nlParseMode}
          options=${[['always', 'Always'], ['smart', 'Smart'], ['never', 'Never']]} onChange=${save('nlParseMode')} />
      <//>
    </section>

    <section class="bc-set-section">
      <h2 class="bc-set-h">Account</h2>
      <${Row} label="Signed in as">
        <span class="bc-set-value">${(user && user.email) || ''}</span>
        <button type="button" class="bc-btn" disabled=${busy} onClick=${signOut}>Sign out</button>
      <//>
    </section>

    <section class="bc-set-section">
      <h2 class="bc-set-h">About</h2>
      <${Row} label="Version">
        <span class="bc-set-value">${version || 'unknown'}</span>
      <//>
      <${Row} label="Device sync" hint="Username is your account email; the password is your account password or a personal access token.">
        <span class="bc-set-value">CalDAV clients (Apple Calendar, DAVx5, Thunderbird) can sync at <code>/dav</code> on this server.</span>
      <//>
    </section>

    <${SystemSection} />
  <//>`;
}
