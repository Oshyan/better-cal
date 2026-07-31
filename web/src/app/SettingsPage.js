// Settings: user preferences (GET/PATCH /settings), saved per control on
// change, applied client-side immediately (view, week start, time format,
// theme; nlParseMode is enforced server-side in /quickadd). Plus account
// (sign out) and about (version, CalDAV pointer).

import { html, useState, useEffect } from '../../vendor/index.js';
import { useStore, toast, shallowEq } from './store.js';
import { api, logout } from './api.js';
import { adoptSettings } from './settings.js';
import { saveSetting } from './actions.js';
import { PageShell } from './PageShell.js';
import {
  permissionState, pushSupported, fetchPushStatus, enablePush, disablePush, sendTestNotification,
} from './push.js';
import { TIMED_CHOICES, ALLDAY_DAYS_CHOICES, fmtOffsetMinutes } from '../lib/reminders.js';

const VIEW_OPTIONS = [
  ['month', 'Month'], ['weeks3', '3 weeks'], ['weeks2', '2 weeks'],
  ['week', 'Week'], ['day', 'Day'], ['agenda', 'Agenda'],
];

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

function Row({ label, hint, children }) {
  return html`<div class="bc-set-row">
    <span class="bc-set-label">${label}</span>
    <span class="bc-set-control">${children}</span>
    ${hint && html`<span class="bc-set-hint">${hint}</span>`}
  </div>`;
}

// Notifications: Web Push status + enable/disable/test, plus the global
// default reminder editors (the fallbacks behind calendar/event overrides).
function NotificationsSection({ settings }) {
  const [status, setStatus] = useState(null); // {subscribed, vapidConfigured}
  const [perm, setPerm] = useState(permissionState());
  const [busy, setBusy] = useState(false);

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

  const timedValue = settings.reminderTimed && settings.reminderTimed.length > 0
    ? String(settings.reminderTimed[0].minutes)
    : '';
  const allDayEntry = settings.reminderAllDay && settings.reminderAllDay.length > 0
    ? settings.reminderAllDay[0]
    : null;

  const saveAllDay = (patch) => {
    const base = allDayEntry || { daysBefore: 1, time: '18:00' };
    saveSetting('reminderAllDay', [{ ...base, ...patch }]);
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
    <${Row} label="Default reminder (timed events)" hint="Used unless a calendar or event overrides it.">
      <select aria-label="Default reminder for timed events" value=${timedValue}
        onChange=${(e) => saveSetting('reminderTimed', e.target.value === '' ? [] : [{ minutes: Number(e.target.value) }])}>
        <option value="">None</option>
        ${TIMED_CHOICES.map((m) => html`<option key=${m} value=${String(m)}>${fmtOffsetMinutes(m)}</option>`)}
      </select>
    <//>
    <${Row} label="Default reminder (all-day events)" hint="Fires at the chosen time in the event's timezone.">
      <select aria-label="Default reminder day for all-day events"
        value=${allDayEntry ? String(allDayEntry.daysBefore) : ''}
        onChange=${(e) => (e.target.value === ''
          ? saveSetting('reminderAllDay', [])
          : saveAllDay({ daysBefore: Number(e.target.value) }))}>
        <option value="">None</option>
        ${ALLDAY_DAYS_CHOICES.map(([d, label]) => html`<option key=${d} value=${String(d)}>${label}</option>`)}
      </select>
      ${allDayEntry && html`<input type="time" aria-label="Default reminder time for all-day events"
        value=${allDayEntry.time} onChange=${(e) => e.target.value && saveAllDay({ time: e.target.value })} />`}
    <//>
  </section>`;
}

export function SettingsPage() {
  const { settings, user, calendars } = useStore(
    (s) => ({ settings: s.settings, user: s.user, calendars: s.calendars }),
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
    </section>

    <${NotificationsSection} settings=${settings} />

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
  <//>`;
}
