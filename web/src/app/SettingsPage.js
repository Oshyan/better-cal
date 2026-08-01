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
import { PlaceInput, pickFillText } from './PlaceInput.js';
import { localTz } from '../lib/dates.js';
import {
  permissionState, pushSupported, fetchPushStatus, enablePush, disablePush, sendTestNotification,
} from './push.js';
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
    </section>

    <${LocationSection} settings=${settings} config=${config} />

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
