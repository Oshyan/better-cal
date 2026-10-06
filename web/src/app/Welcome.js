// First-run welcome (0.9.2, #79). A new account (seed.php sets welcomeDone
// false) opens on two short steps over the calendar: confirm the settings the
// device already suggests, then where to start. Skippable at every step,
// shown until finished or skipped, and reachable again from Settings, General.
// Existing accounts never see it unasked: welcomeDone defaults to true.
//
// It stands aside while something it opened is in use (a create drawer, the
// editor), and on other pages, and comes back on the calendar until Done.
// Reopened from Settings, it opens over Settings and closes back to it.

import { html, useState, useEffect, useRef } from '../../vendor/index.js';
import { useStore, set, state, toast, shallowEq } from './store.js';
import { api } from './api.js';
import { adoptSettings } from './settings.js';
import { Icon } from '../ui/icons.js';
import { localTz, zoneOptions } from '../lib/dates.js';
import { pushSupported, permissionState, enablePush, currentPushEndpoint } from './push.js';
import { isInstalled, canPromptInstall, promptInstall, onInstallChange, installHint } from './install.js';
import { PHONE_QUERY } from '../lib/breakpoints.js';
import { GOOGLE_SETUP_URL } from './GoogleConnector.js';

// What the device's locale says, for the settings a new account hasn't chosen.
function localeWeekStart() {
  try {
    const loc = new Intl.Locale(navigator.language || 'en-US');
    const info = typeof loc.getWeekInfo === 'function' ? loc.getWeekInfo() : loc.weekInfo;
    return info && info.firstDay === 1 ? 'mon' : 'sun';
  } catch { return 'sun'; }
}
function localeClock() {
  try {
    const hc = new Intl.DateTimeFormat(navigator.language || 'en-US', { hour: 'numeric' }).resolvedOptions().hourCycle;
    return hc === 'h23' || hc === 'h24' ? '24' : '12';
  } catch { return '12'; }
}

// One quiet save for the welcome's choices (saveSetting toasts per key).
async function saveQuietly(patch) {
  const d = await api('/settings', { method: 'PATCH', body: patch });
  adoptSettings(d.settings || patch);
  set({ scrollSeq: state.scrollSeq + 1 });
}

function Seg({ label, value, options, onChange }) {
  return html`<span class="bc-seg" role="group" aria-label=${label}>
    ${options.map(([v, l]) => html`<button key=${v} type="button" class=${'bc-seg-btn' + (value === v ? ' is-active' : '')} aria-pressed=${value === v} onClick=${() => onChange(v)}>${l}</button>`)}
  </span>`;
}

export function Welcome() {
  const s = useStore((st) => ({
    settings: st.settings, calendars: st.calendars, route: st.route, open: st.welcomeOpen, plugins: st.plugins,
    busy: !!(st.createDrawer || st.editor || st.searchOpen || st.quickAddOpen), later: st.welcomeLater,
  }), shallowEq);
  const pending = s.settings && s.settings.welcomeDone === false;
  // welcomeLater: put aside with Back on a phone; it comes back next launch.
  // Reopened from Settings it opens right there; on its own, on the calendar.
  const show = ((pending && !s.later && s.route === 'calendar') || s.open) && !s.busy;

  const firstCal = (s.calendars || []).find((c) => c.kind === 'local' && c.editable) || null;
  const [step, setStep] = useState(1);
  const stepRef = useRef(1);
  stepRef.current = step;
  const [form, setForm] = useState(null);
  const [google, setGoogle] = useState(null); // {configured, connected}
  const [pushOn, setPushOn] = useState(false); // this device already gets reminders
  const [, bump] = useState(0);
  useEffect(() => onInstallChange(() => bump((n) => n + 1)), []);

  useEffect(() => {
    if (!show || form) return;
    // A new account still holds the defaults, so the device's own choices
    // are the better guess; anything already changed is kept.
    const st = state.settings || {};
    setForm({
      tz: st.tz || localTz(),
      weekStart: pending ? localeWeekStart() : st.weekStart,
      timeFormat: pending ? localeClock() : st.timeFormat,
      theme: st.theme || 'system',
      // Left unset until typed: on a first sign-in the calendars can still be
      // loading when this opens, so the field follows the first calendar's
      // name once it arrives instead of what was known at this moment.
      calName: null,
    });
  }, [show]); // eslint-disable-line

  useEffect(() => {
    if (!show || step !== 2 || google) return;
    api('/google/status').then((d) => setGoogle({ configured: !!d.configured, connected: (d.accounts || []).length > 0 })).catch(() => setGoogle({ configured: false, connected: false }));
    currentPushEndpoint().then((ep) => setPushOn(!!ep && permissionState() === 'granted')).catch(() => {});
  }, [show, step]); // eslint-disable-line

  // Phone: Back steps from 2 to 1, and from 1 puts the welcome aside until
  // the next launch (not done: it comes back), like every other phone surface.
  const phone = (() => { try { return matchMedia(PHONE_QUERY).matches; } catch { return false; } })();
  useEffect(() => {
    if (!show || !phone) return undefined;
    if (!(history.state && history.state.bcWelcome)) history.pushState({ bcWelcome: 1 }, '');
    let popped = false;
    const onPop = () => {
      if (stepRef.current === 2) { setStep(1); history.pushState({ bcWelcome: 1 }, ''); return; }
      popped = true;
      set({ welcomeOpen: false, welcomeLater: true });
    };
    window.addEventListener('popstate', onPop);
    return () => {
      window.removeEventListener('popstate', onPop);
      if (!popped && history.state && history.state.bcWelcome) history.back();
    };
  }, [show, phone]); // eslint-disable-line

  if (!show || !form) return null;

  const done = new Set((s.settings && s.settings.welcomeSteps) || []);
  const mark = (id) => {
    if (done.has(id)) return;
    saveQuietly({ welcomeSteps: [...done, id] }).catch(() => {});
  };
  const finish = () => {
    set({ welcomeOpen: false });
    if (pending) saveQuietly({ welcomeDone: true }).catch(() => {});
    setStep(1);
  };
  const calName = form.calName ?? (firstCal ? firstCal.name : '');
  const upd = (patch) => setForm((f) => ({ ...f, ...patch }));

  const saveCore = async () => {
    const st = state.settings || {};
    const patch = {};
    for (const k of ['tz', 'weekStart', 'timeFormat', 'theme']) {
      if (form[k] && form[k] !== st[k]) patch[k] = form[k];
    }
    const name = calName.trim();
    try {
      if (Object.keys(patch).length) await saveQuietly(patch);
      if (pending && firstCal && name && name !== firstCal.name) {
        const updated = await api('/calendars/' + firstCal.id, { method: 'PATCH', body: { name } });
        set({ calendars: state.calendars.map((c) => (c.id === firstCal.id ? { ...c, ...updated } : c)) });
      }
    } catch (e) { toast('Could not save: ' + e.message, { error: true }); return; }
    setStep(2);
  };

  const pushState = pushSupported() ? permissionState() : 'unsupported';
  // Already true counts as done, whether or not the welcome started it.
  const hasFeeds = (s.calendars || []).some((c) => c.kind === 'subscribed');
  const hasPlugin = (s.plugins || []).some((p) => p.enabled);
  const installed = isInstalled();
  const rows = [
    {
      id: 'subscribe', icon: 'link', title: 'Subscribe to a calendar',
      sub: "By its address: holidays, a venue, a team's or a friend's public calendar.",
      act: 'Subscribe', run: () => { mark('subscribe'); set({ createDrawer: { kind: 'subscribe' } }); },
      isDone: hasFeeds,
    },
    {
      id: 'import', icon: 'calendar', title: 'Import a file',
      sub: 'An .ics file, or the calendars in a Google Takeout export.',
      act: 'Import', run: () => { mark('import'); set({ createDrawer: { kind: 'import' } }); },
    },
    {
      id: 'google', icon: 'google', title: 'Connect Google Calendar',
      sub: google && !google.configured
        ? "Needs this server's own Google setup first: a free Google Cloud project, about ten minutes, by whoever runs the server."
        : 'Your Google calendars, kept in step both ways where you can edit them.',
      act: google && google.configured ? 'Connect' : null,
      href: google && !google.configured ? GOOGLE_SETUP_URL : null,
      hrefLabel: 'Setup guide',
      run: () => { mark('google'); set({ route: 'settings', settingsTab: 'connections' }); },
      isDone: !!(google && google.connected),
    },
    {
      id: 'install', icon: 'expand', title: 'Install the app',
      sub: installed ? 'Installed on this device.' : canPromptInstall() ? 'Its own window and icon, and reminders on a phone.' : installHint(),
      act: !installed && canPromptInstall() ? 'Install' : null,
      run: async () => { if (await promptInstall()) mark('install'); },
      isDone: installed,
    },
    {
      id: 'reminders', icon: 'bell', title: 'Reminders on this device',
      sub: pushState === 'unsupported' ? 'This browser has no push notifications; reminders can come by email instead (Settings, Notifications).'
        : pushState === 'denied' ? 'Notifications are blocked for this site in the browser.'
          : 'Notifications for your events, even with the app closed.',
      act: !pushOn && (pushState === 'default' || pushState === 'granted') ? 'Turn on' : null,
      isDone: pushOn,
      run: async () => {
        try { await enablePush(); setPushOn(true); mark('reminders'); toast('Reminders on for this device'); } catch (e) { toast(e.message, { error: true }); }
      },
    },
    {
      id: 'plugins', icon: 'plugins', title: 'Weather, sun and tides',
      sub: 'Plugins that add the day’s context to your calendar.',
      act: 'Browse', run: () => { mark('plugins'); set({ route: 'plugins' }); },
      isDone: hasPlugin,
    },
  ];

  const zones = zoneOptions([form.tz]);

  return html`<div class="bc-welcome-wrap">
    <div class="bc-welcome" role="dialog" aria-modal="true" aria-labelledby="bc-welcome-h">
      ${step === 1 && html`
        <div class="bc-welcome-head">
          <h2 id="bc-welcome-h">Welcome to Better-Cal</h2>
          <span class="bc-welcome-step">1 of 2</span>
        </div>
        <p class="bc-welcome-lede">${pending
          ? "These come from this device. Change anything that's wrong; all of it is in Settings later."
          : 'Your current settings. Change anything here, or later in Settings.'}</p>
        <div class="bc-welcome-form">
          <label class="bc-welcome-row"><span>Home time zone</span>
            <select value=${form.tz} onChange=${(e) => upd({ tz: e.target.value })} aria-label="Home time zone">
              ${zones.map(([z, l]) => html`<option key=${z} value=${z}>${l}</option>`)}
            </select>
          </label>
          <div class="bc-welcome-row"><span>Week starts on</span>
            <${Seg} label="Week starts on" value=${form.weekStart} options=${[['sun', 'Sunday'], ['mon', 'Monday']]} onChange=${(v) => upd({ weekStart: v })} />
          </div>
          <div class="bc-welcome-row"><span>Clock</span>
            <${Seg} label="Clock" value=${form.timeFormat} options=${[['12', '12-hour'], ['24', '24-hour']]} onChange=${(v) => upd({ timeFormat: v })} />
          </div>
          <div class="bc-welcome-row"><span>Theme</span>
            <${Seg} label="Theme" value=${form.theme} options=${[['system', 'System'], ['light', 'Light'], ['dark', 'Dark']]} onChange=${(v) => upd({ theme: v })} />
          </div>
          ${pending && firstCal && html`<label class="bc-welcome-row"><span>Your first calendar</span>
            <input value=${calName} maxlength="80" onInput=${(e) => upd({ calName: e.target.value })} aria-label="First calendar's name" />
          </label>`}
        </div>
        <div class="bc-welcome-foot">
          <button type="button" class="bc-link-btn" onClick=${finish}>Skip setup</button>
          <button type="button" class="bc-btn bc-btn-primary" onClick=${saveCore}>Next</button>
        </div>`}
      ${step === 2 && html`
        <div class="bc-welcome-head">
          <h2 id="bc-welcome-h">Where to start</h2>
          <span class="bc-welcome-step">2 of 2</span>
        </div>
        <p class="bc-welcome-lede">Any of these, in any order, or none. New on the bottom bar or in the toolbar adds an event any time.</p>
        <div class="bc-welcome-list">
          ${rows.map((r) => {
            const isDone = r.isDone || done.has(r.id);
            return html`<div key=${r.id} class=${'bc-welcome-item' + (isDone ? ' is-done' : '')}>
              <span class="bc-welcome-ico"><${Icon} name=${isDone ? 'check' : r.icon} size=${17} /></span>
              <span class="bc-welcome-text"><b>${r.title}</b><small>${r.sub}</small></span>
              ${r.act && !(isDone && (r.id === 'reminders' || r.id === 'install')) && html`<button type="button" class="bc-btn" onClick=${r.run}>${r.act}</button>`}
              ${!r.act && r.href && html`<a class="bc-btn" href=${r.href} target="_blank" rel="noopener">${r.hrefLabel}</a>`}
            </div>`;
          })}
        </div>
        <div class="bc-welcome-foot">
          <button type="button" class="bc-link-btn" onClick=${() => setStep(1)}>Back</button>
          <button type="button" class="bc-btn bc-btn-primary" onClick=${finish}>Done</button>
        </div>`}
    </div>
  </div>`;
}
