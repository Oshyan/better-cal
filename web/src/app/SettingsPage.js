// Settings: user preferences (GET/PATCH /settings), saved per control on
// change, applied client-side immediately (view, week start, time format,
// theme; nlParseMode is enforced server-side in /quickadd). Plus account
// (sign out) and about (version, CalDAV pointer).

import { PHONE_QUERY } from '../lib/breakpoints.js';
import { html, useState, useEffect, useMemo } from '../../vendor/index.js';
import { useStore, toast, shallowEq, set } from './store.js';
import { api, logout, loadSystemHealth, loadCalendars, loadReviewCount, loadUpdates } from './api.js';
import { fmtSince } from '../lib/since.js';
import { adoptSettings } from './settings.js';
import { saveSetting } from './actions.js';
import { recentStarts } from './resume.js';
import { needsAttention } from './system.js';
import { stalledStart } from './bootlog.js';
import { UpdateSettings } from './updates.js';
import { PageShell } from './PageShell.js';
import { Icon } from '../ui/icons.js';
import { GoogleConnector } from './GoogleConnector.js';
import { usePasswordStepUp } from './PasswordStepUp.js';
import { OutfeedsSection } from './OutfeedsPage.js';
import { PlaceInput, pickFillText, placeBias } from './PlaceInput.js';
import { allowDeviceLocation, deviceLocationPermission } from './devicelocation.js';
import { localTz, sameClock, tzOffsetLabel, tzCity, fmtDayMedium, fmtTime } from '../lib/dates.js';
import { ZonePicker } from './ZonePicker.js';
import {
  permissionState, pushSupported, fetchPushStatus, enablePush, disablePush, fetchPushDevices, removePushDevice, currentEndpointHash, currentPushEndpoint,
  sendTestNotification, sendTestEmail,
} from './push.js';
import {
  BATTERY_TIP_TITLE, BATTERY_TIP_BODY, batteryTipApplies, batteryTipDismissed, dismissBatteryTip,
} from '../lib/batterytip.js';
import { TimedDefault, AllDayDefault } from './ReminderDefaults.js';

const VIEW_OPTIONS = [
  ['month', 'Month'], ['weeks3', '3 weeks'], ['weeks2', '2 weeks'],
  ['week', 'Week'], ['day', 'Day'], ['agenda', 'Agenda'], ['split', 'Split'],
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

// Settings, System: what the server is doing in the background, and whether
// anything needs its owner. Each part is a block with its title above it
// (tables and commands need the width more than a label column does), and a
// title says the state at a glance ("1 failing") before the detail below.
function Block({ id, title, aside, children }) {
  return html`<div class="bc-set-block" id=${id}>
    <h3 class="bc-set-block-h">${title}${aside}</h3>
    ${children}
  </div>`;
}

// Is background work working. One row per subject: the worker itself and its
// job types (system-wide), each subscribed feed, each device reminders go to.
// Failing rows first. Transitions are also in Activity under "System", and a
// streak that lasts earns one email; this is the "right now" view.
function SystemSection() {
  const health = useStore((s) => s.systemHealth);
  const focus = useStore((s) => s.settingsFocus);
  const [loaded, setLoaded] = useState(false);
  useEffect(() => { loadSystemHealth().finally(() => setLoaded(true)); }, []);
  const rows = (health && health.rows) || [];
  const failing = rows.filter((r) => r.status === 'failing');
  const alerts = health && health.emailAlerts;
  const when = (iso) => (iso ? new Date(iso).toLocaleString() : '');
  // Arriving from the stopped-worker banner: show the cron job, once it's there.
  useEffect(() => {
    if (focus !== 'cron' || !(health && health.cron)) return;
    const el = document.getElementById('bc-set-cron');
    if (el) el.scrollIntoView({ block: 'start' });
    set({ settingsFocus: null });
  }, [focus, health]);
  return html`<section class="bc-set-section">
    <h2 class="bc-set-h">System</h2>
    <${Block} title="Background work" aside=${!loaded ? html`<span class="bc-set-block-aside">checking…</span>`
      : rows.length === 0 ? html`<span class="bc-set-block-aside">nothing has reported yet</span>`
        : failing.length > 0 ? html`<span class="bc-set-block-aside is-bad">${failing.length} failing</span>`
          : html`<span class="bc-set-block-aside">all working</span>`}>
      ${rows.length === 0 && loaded && html`<p class="bc-set-block-note">The worker fills this in as jobs run.</p>`}
      ${rows.length > 0 && html`<div class="bc-sys-wrap"><table class="bc-sys-table">
        <thead><tr><th>What</th><th>Status</th><th>Last OK</th><th>Detail</th></tr></thead>
        <tbody>${rows.map((r) => html`<tr key=${r.subject} class=${r.status === 'failing' ? 'is-failing' : ''}>
          <td>${r.label}</td>
          <td class="bc-sys-status">${r.status === 'failing'
            ? html`<span class="bc-sys-bad">failing since ${fmtSince(r.firstFailedAt)} (${r.consecutiveFailures}×)</span>`
            : html`<span class="bc-sys-ok">ok</span>`}</td>
          <td class="bc-sys-when" title=${when(r.lastOkAt)}>${r.lastOkAt ? fmtSince(r.lastOkAt) : 'never'}</td>
          <td class="bc-sys-err">${r.status === 'failing' && r.lastError ? r.lastError : ''}${r.alertedAt ? html` <span class="bc-badge">emailed ${fmtSince(r.alertedAt)}</span>` : ''}</td>
        </tr>`)}</tbody>
      </table></div>`}
    <//>
    <${Block} title="Failure emails" aside=${alerts && !alerts.configured ? html`<span class="bc-set-block-aside">off</span>` : null}>
      ${alerts && alerts.configured && html`<p class="bc-set-block-text">
        Your feeds and devices: ${alerts.accountTo}${alerts.systemTo !== alerts.accountTo ? html`<br />System-wide: ${alerts.systemTo}` : html`<br />System-wide: same address (set BETTERCAL_ALERT_EMAIL to change)`}
      </p>`}
      <p class="bc-set-block-note">${alerts && alerts.configured
        ? 'One email when something has been failing long enough to be real (jobs: 1 hour and 3 failures; feeds: a day; a device: 6 hours), one when it recovers, never one per failure.'
        : 'Email is not configured on this server (BETTERCAL_SMTP_HOST and BETTERCAL_SMTP_FROM). Failures still show here and in Activity.'}</p>
    <//>
    <${CronBlock} cron=${health && health.cron} worker=${rows.find((r) => r.subject === 'worker')} />
    <${PrivateFilesRow} />
    <${StartsRow} />
  </section>`;
}

// The worker's cron job (Infra\Setup::workerPhp): first whether it runs,
// and when it doesn't, what to do about it; then the schedule and a button
// for the command, as control panels ask for them; then the note on which PHP
// it uses, with the full crontab line for people with a terminal.
function CronBlock({ cron, worker }) {
  const [copied, setCopied] = useState(false);
  if (!cron) return null;
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(cron.command);
      setCopied(true);
    } catch {
      toast('Copy failed; select the command in the crontab line below and copy it instead');
    }
  };
  const stopped = worker && worker.status === 'failing';
  const php = cron.followsServerPhp
    ? "It runs the server's own PHP, so it keeps working through PHP upgrades."
    : "It names this site's PHP version directly, because the server's plain php is older or couldn't be checked; after a PHP upgrade, use the command shown here then.";
  return html`<${Block} id="bc-set-cron" title="Cron job" aside=${stopped ? html`<span class="bc-set-block-aside is-bad">stopped</span>` : null}>
    ${stopped
      ? html`<div class="bc-cron-fix">
          <p><strong>The worker has stopped: no run since ${fmtSince(worker.firstFailedAt)}.</strong> Reminders, subscribed calendars and email import are paused until it runs again.</p>
          <p>Open the cron jobs page in your host's control panel (or <code>crontab -e</code> on a server). If Better-Cal's job is missing or paused, add it again: every minute, with the command below. If it's there, the PHP it names may have been removed in an upgrade: replace its command with this one. This page notices within a few minutes once it runs.</p>
        </div>`
      : html`<p class="bc-set-block-text">${worker ? 'The worker last ran ' + fmtSince(worker.lastOkAt) + '.' : "The worker hasn't reported yet; it does on its first run."}</p>`}
    <div class="bc-cron-run">
      <span>Every minute (all five schedule fields <code>*</code>), running this command:</span>
      <button type="button" class="bc-btn" onClick=${copy}>${copied ? 'Copied' : 'Copy command'}</button>
    </div>
    <p class="bc-set-block-note">${php} Crontab line: <code class="bc-cron-line">${cron.line}</code></p>
  <//>`;
}

// Can the settings file and backups be downloaded (#142, Infra\PrivateFilesCheck).
// The server keeps the last result; "Check now" asks the main domain again,
// at most once a minute.
const PF_WORD = { safe: 'safe', probably: 'probably safe', exposed: 'fix this now', unchecked: 'not checked' };
function PrivateFilesRow() {
  const [state, setState] = useState(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => { api('/system/private-files').then(setState).catch(() => setState({ result: null, wait: 0 })); }, []);
  const check = async () => {
    setBusy(true);
    try {
      setState(await api('/system/private-files/check', { method: 'POST' }));
    } catch (e) {
      toast(e.message || 'The check failed');
    } finally {
      setBusy(false);
    }
  };
  const r = state && state.result;
  const hint = busy ? 'Checking… this asks your main domain for a test file and takes a few seconds.'
    : (r ? 'Checked ' + fmtSince(r.checkedAt) + '.' : (state ? 'Not checked yet.' : 'Loading…'));
  return html`<${Block} title="Private files" aside=${r ? html`<span class=${'bc-set-block-aside' + (r.status === 'exposed' ? ' is-bad' : '')}>${PF_WORD[r.status] || ''}</span>` : null}>
    <div class="bc-cron-run">
      <span class=${'bc-pf' + (r && r.status === 'exposed' ? ' bc-set-warn' : '')}>
        ${r ? html`<strong>${r.title}</strong> ${r.message}` : (state ? 'Not checked yet.' : 'Loading…')}
      </span>
      <button type="button" class="bc-btn" disabled=${busy || !state} onClick=${check}>Check now</button>
    </div>
    ${r && r.notes && r.notes.length > 0 && html`<ul class="bc-pf-notes">${r.notes.map((n) => html`<li key=${n}>${n}</li>`)}</ul>`}
    <p class="bc-set-block-note">${hint}</p>
  <//>`;
}

// Why this device's app started, most recent first (0.9.1, resume.js): to
// tell a phone ending the app in the background from the app reloading itself
// for a new version. Kept on the device only.
const START_WORDS = {
  update: 'reloaded for a new version',
  discarded: 'discarded in the background by the browser, then reloaded',
  restarted: 'started again minutes after use (ended in the background, or swiped away)',
  reload: 'reloaded (pulled down or refreshed)',
  launch: 'opened',
};
function StartsRow() {
  const starts = recentStarts().slice(0, 10);
  const stalled = stalledStart();
  if (starts.length === 0 && !stalled) return null;
  return html`<${Block} title="Recent starts on this device">
    <ul class="bc-sys-starts">
      ${stalled && html`<li class="is-stalled"><span class="bc-sys-starts-when">${fmtDayMedium(new Date(stalled.at))}, ${fmtTime(new Date(stalled.at))}</span> didn't finish starting (${stalled.failed ? 'couldn’t load ' + stalled.failed.split('/').pop() : 'a download probably hung'})</li>`}
      ${starts.map((st) => html`<li key=${st.at}><span class="bc-sys-starts-when">${fmtDayMedium(new Date(st.at))}, ${fmtTime(new Date(st.at))}</span> ${START_WORDS[st.reason] || st.reason}</li>`)}
    </ul>
  <p class="bc-set-block-note">Why the app last started here, most recent first. Kept on this device only; a start that never finished is also noted in the server's log.</p>
  <//>`;
}

// API keys: what CalDAV clients, the MCP server and scripts sign in with.
// Create shows the value exactly once (only its hash is stored); revoke is
// immediate. Session-only on the server, so a leaked token cannot mint more.
// The Chrome extension that sends "Add to Google Calendar" links here. It
// is generic (any instance) and asks for the address once; this row hands
// the address over so nobody has to type it.
const EXTENSION_STORE_URL = 'https://chromewebstore.google.com/detail/djlgegfeedifkamchpfhkcdjohdchijn';
function ExtensionSection() {
  const origin = window.location.origin;
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(origin);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch { /* clipboard blocked: the value is selectable */ }
  };
  return html`<section class="bc-set-section">
    <h2 class="bc-set-h">Browser extension</h2>
    <${Row} label="Add-to-calendar links" hint="Works in Chrome, Edge, Brave and Vivaldi (and on Android in Firefox or Kiwi). It rewrites the link at the network layer: no page access, nothing read. Until an address is set it does nothing.">
      <span class="bc-set-value">Sends every "Add to Google Calendar" button on the web (Luma, Eventbrite, Meetup, event sites) to the editor here, pre-filled.
        <a href=${EXTENSION_STORE_URL} target="_blank" rel="noopener">Get the extension <${Icon} name="arrowUpRight" size=${10} /></a></span>
    <//>
    <${Row} label="Your address" hint="Paste this into the extension's options (it opens on install).">
      <code class="bc-tokens-value">${origin}</code>
      <button type="button" class="bc-btn" onClick=${copy}>${copied ? 'Copied' : 'Copy'}</button>
    <//>
  </section>`;
}

function TokensSection() {
  const [tokens, setTokens] = useState(null);
  const [name, setName] = useState('');
  const [fresh, setFresh] = useState(null); // {id, name, token}: shown once, until dismissed
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState(false);
  const stepUp = usePasswordStepUp();
  const load = () => api('/tokens').then((d) => setTokens(d.tokens || [])).catch(() => setTokens([]));
  useEffect(() => { load(); }, []);

  const create = async (e) => {
    e.preventDefault();
    if (!name.trim()) return;
    setBusy(true);
    try {
      const d = await stepUp.run('create an API key', () => api('/tokens', { method: 'POST', body: { name: name.trim() } }));
      if (!d) return;
      setFresh(d);
      setCopied(false);
      setName('');
      await load();
    } catch (err) {
      toast('Could not create key: ' + err.message, { error: true });
    } finally {
      setBusy(false);
    }
  };
  const revoke = async (t) => {
    setBusy(true);
    try {
      const result = await api('/tokens/' + t.id, { method: 'DELETE' });
      if (fresh && fresh.id === t.id) setFresh(null);
      toast('Revoked "' + t.name + '". Anything using it stops working now.'
        + (result.subscriptionsPaused ? ' Paused ' + result.subscriptionsPaused + ' calendar subscription' + (result.subscriptionsPaused === 1 ? '' : 's') + ' for review.' : ''));
      await Promise.all([
        load(),
        result.subscriptionsPaused ? loadCalendars().catch(() => {}) : Promise.resolve(),
        result.subscriptionsPaused ? loadReviewCount() : Promise.resolve(),
      ]);
    } catch (err) {
      toast('Could not revoke: ' + err.message, { error: true });
    } finally {
      setBusy(false);
    }
  };
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(fresh.token);
      setCopied(true);
    } catch { /* clipboard blocked: the value is selectable */ }
  };
  const when = (iso) => (iso ? fmtSince(iso) : 'never');

  return html`<section class="bc-set-section">
    <h2 class="bc-set-h">API keys</h2>
    <${Row} label="Keys" hint="Each key signs in as you: for CalDAV clients (as the password), the MCP server, and scripts. Revoke one and whatever used it stops immediately. A key you no longer recognize is a reason to revoke it.">
      ${tokens === null && html`<span class="bc-set-value">Loading…</span>`}
      ${tokens !== null && tokens.length === 0 && html`<span class="bc-set-value">None yet.</span>`}
      ${tokens !== null && tokens.length > 0 && html`<table class="bc-sys-table bc-tokens">
        <thead><tr><th>Name</th><th>Created</th><th>Last used</th><th></th></tr></thead>
        <tbody>${tokens.map((t) => html`<tr key=${t.id}>
          <td>${t.name}</td>
          <td title=${new Date(t.createdAt).toLocaleString()}>${when(t.createdAt)}</td>
          <td title=${t.lastUsedAt ? new Date(t.lastUsedAt).toLocaleString() : ''}>${when(t.lastUsedAt)}</td>
          <td><button type="button" class="bc-link-btn bc-tokens-revoke" disabled=${busy} onClick=${() => revoke(t)}>Revoke</button></td>
        </tr>`)}</tbody>
      </table>`}
    <//>
    <${Row} label="New key">
      <form class="bc-tokens-new" onSubmit=${create}>
        <input type="text" value=${name} placeholder="What will use it, e.g. Phone CalDAV" maxlength="120" aria-label="Key name" onInput=${(e) => setName(e.target.value)} />
        <button type="submit" class="bc-btn bc-btn-primary" disabled=${busy || !name.trim()}>Create key</button>
      </form>
    <//>
    ${fresh && html`<${Row} label="" hint="Shown once. It is stored hashed, so it cannot be shown again; if you lose it, revoke it and create another.">
      <div class="bc-tokens-fresh" role="status">
        <span>Key for <strong>${fresh.name}</strong>:</span>
        <code class="bc-tokens-value">${fresh.token}</code>
        <button type="button" class="bc-btn" onClick=${copy}>${copied ? 'Copied' : 'Copy'}</button>
        <button type="button" class="bc-link-btn" onClick=${() => setFresh(null)}>Done</button>
      </div>
    <//>`}
    ${stepUp.prompt}
  </section>`;
}

// Sign out everywhere else: the in-app answer to a lost or stolen device
// (SECURITY.md, "If a device is lost or stolen"). This browser stays signed
// in and keeps its push reminders; API keys are managed just below.
function OtherBrowsersRow() {
  const [others, setOthers] = useState(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    api('/auth/sessions').then((d) => setOthers(d.others)).catch(() => setOthers(null));
  }, []);
  const run = async () => {
    if (!window.confirm('Sign out every other browser and device? They will need your password to sign in again, and stop getting push reminders until they do. Public calendar feeds created while signed in will get new URLs, so external calendars using the old URLs stop updating until you replace them. A custom reminder email will return to the account address. This browser stays signed in. API keys and things they created are not affected.')) return;
    setBusy(true);
    try {
      const keepPushHash = await currentEndpointHash().catch(() => null);
      const r = await api('/auth/sign-out-others', { method: 'POST', body: { keepPushHash } });
      setOthers(0);
      if (r.notifyEmailReset) {
        await api('/settings').then((current) => adoptSettings(current.settings)).catch(() => {});
      }
      toast('Signed out ' + r.sessions + ' other browser' + (r.sessions === 1 ? '' : 's')
        + (r.pushDevices ? ' and removed ' + r.pushDevices + ' push device' + (r.pushDevices === 1 ? '' : 's') : '')
        + (r.feedsRotated ? '. Changed ' + r.feedsRotated + ' public feed URL' + (r.feedsRotated === 1 ? '' : 's') : '')
        + (r.notifyEmailReset ? '. Reminder email returned to the account address' : '') + '.');
    } catch (err) {
      toast('Could not sign out other browsers: ' + err.message, { error: true });
    } finally {
      setBusy(false);
    }
  };
  const count = others === null ? '' : others === 0 ? 'No other browsers are signed in.' : others === 1 ? '1 other browser is signed in.' : others + ' other browsers are signed in.';
  return html`<${Row} label="Other browsers" hint="If a device is lost or stolen, this signs it out, stops its push reminders, changes signed-in public feed URLs, and returns custom reminder email to the account address. External calendars need the new feed URLs. If the device used an API key, revoke that below too.">
    <span class="bc-set-value">${count}</span>
    <button type="button" class="bc-btn" disabled=${busy} onClick=${run}>Sign out everywhere else</button>
  <//>`;
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
  const away = !sameClock(tz, device);
  return html`<${Row} label="Home time zone" hint=${TZ_HINT}>
    <${ZonePicker} value=${tz || device} extra=${[device]} ariaLabel="Home time zone" className="bc-set-zone" onChange=${onChange} />
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

  // Every device reminders go to: a device nobody remembers adding is the
  // one to remove (it could only have been registered while signed in).
  const [devices, setDevices] = useState(null);
  const [myHash, setMyHash] = useState(null);
  const [hasLocalSub, setHasLocalSub] = useState(false);
  const refresh = () => {
    setPerm(permissionState());
    fetchPushStatus().then(setStatus).catch(() => setStatus(null));
    fetchPushDevices().then(setDevices).catch(() => setDevices(null));
    currentEndpointHash().then(setMyHash).catch(() => setMyHash(null));
    currentPushEndpoint().then((ep) => setHasLocalSub(!!ep)).catch(() => setHasLocalSub(false));
  };
  useEffect(refresh, []);
  const removeDevice = async (d) => {
    if (!window.confirm('Stop sending reminders to this device (' + d.service + ')?')) return;
    try {
      await removePushDevice(d.id);
      // This browser: drop its own subscription too, so it is gone at both ends.
      if (d.endpointHash === myHash) await disablePush().catch(() => {});
      toast(d.endpointHash === myHash ? 'This device no longer gets reminders' : 'Device removed. If someone else may be signed in on it, reset your password to sign every browser out.');
      refresh();
    } catch (e) { toast((e && e.message) || 'Could not remove the device', { error: true }); }
  };

  // This device, never the account (0.6.5): it is signed up when this browser
  // holds a subscription the server also lists. Falling back to "does the
  // account have any device" kept a phone looking enabled after Disable, or
  // after a reinstall, while another (dead) device was still on the list.
  const thisRegistered = hasLocalSub && (devices && myHash ? devices.some((d) => d.endpointHash === myHash) : true);
  const enabled = !!(thisRegistered && perm === 'granted');

  let statusText;
  if (!pushSupported()) statusText = 'Not supported in this browser';
  else if (status && !status.vapidConfigured) statusText = 'Server not configured (VAPID keys missing)';
  else if (perm === 'denied') statusText = 'Blocked in this browser; allow notifications in site settings';
  else if (enabled) statusText = 'Enabled';
  else if (perm === 'granted') statusText = 'Off on this device';
  else statusText = 'Off';

  const canEnable = pushSupported() && !!status && status.vapidConfigured && perm !== 'denied';

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
    ${devices && devices.length > 0 && html`<${Row} label="Devices receiving reminders" hint="Every browser or phone reminders are sent to. Remove any you do not recognise.">
      <ul class="bc-devices">
        ${devices.map((d) => html`<li key=${d.id} class="bc-device">
          <span class="bc-device-name">${d.label || d.service}${d.endpointHash === myHash ? html` <span class="bc-badge">this device</span>` : ''}${d.failing ? html` <span class="bc-badge bc-badge-warn">not reachable</span>` : ''}</span>
          <span class="bc-device-meta">${d.label ? '' : 'not yet named (it names itself when the app opens there) · '}added ${d.createdAt ? fmtSince(d.createdAt) : 'at some point'}${d.lastUsedAt ? ', last reminder ' + fmtSince(d.lastUsedAt) : ', no reminder yet'}</span>
          <button type="button" class="bc-link-btn" onClick=${() => removeDevice(d)}>Remove</button>
        </li>`)}
      </ul>
    <//>`}
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
      <${TimedDefault} value=${settings.reminderTimed} onChange=${(list) => saveSetting('reminderTimed', list)} />
    <//>
    <${Row} label="Default reminder (all-day events)" hint="Fires at the chosen time on your Home time zone's clock, wherever the event was created. Custom accepts up to 4 weeks ahead.">
      <${AllDayDefault} value=${settings.reminderAllDay} onChange=${(list) => saveSetting('reminderAllDay', list)} />
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
  const search = config && config.placeSearch;
  // Every place search service in use is failing right now (Settings says
  // what to do about it; the boot toast says it once).
  const health = useStore((s) => s.systemHealth);
  const searchDown = !!search && (search.healthSubjects || []).length > 0 && (search.healthSubjects || []).every((subject) =>
    ((health && health.rows) || []).some((r) => r.subject === subject && r.status === 'failing'));

  // What a place search is biased toward right now, and whether the device's
  // own position can be used (granted) or offered (prompt).
  const [bias, setBias] = useState(null);
  const [perm, setPerm] = useState(null);
  const describeBias = async () => {
    const b = await placeBias(localTz());
    setBias(b);
    setPerm(await deviceLocationPermission());
  };
  useEffect(() => { describeBias(); }, [settings.homeLat, settings.homeLng, settings.tz]);
  const allowDevice = async () => {
    setLocBusy(true);
    const p = await allowDeviceLocation();
    setLocBusy(false);
    if (!p) { toast('Could not get this device\'s location', { error: true }); return; }
    toast('Place search now starts from where this device is');
    describeBias();
  };
  const biasText = !bias ? '' : {
    device: 'where this device is (its location)',
    devicetz: 'where this device is (' + tzCity(localTz()) + ', from its time zone; allow its location for street-level)',
    home: 'your home location',
    tz: 'your home time zone (' + tzCity(localTz()) + ')',
    none: 'nowhere in particular',
  }[bias.source];

  return html`<section class="bc-set-section">
    <h2 class="bc-set-h">Location and maps</h2>
    <${Row} label="Home location" hint="Biases location search toward your area so nearby places match first.">
      <${PlaceInput}
        value=${homeText}
        ariaLabel="Home location"
        placeholder="Search for your city or address"
        placed=${hasHome}
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
    <${Row} label="Past events" hint="Addresses nobody picked a place for (imports, feeds, typed text) are looked up in the background so maps are ready. Events that have already ended are skipped unless this is on; a past event you open is still placed then, and a repeating event that is still going counts as upcoming.">
      <label class="bc-set-check"><input
        type="checkbox" checked=${settings.placePastEvents === true}
        onChange=${(e) => saveSetting('placePastEvents', e.target.checked)}
      /> Look up addresses for past events too</label>
    <//>
    <${Row} label="Place search near" hint="Searches for a place start near where you are: this device's location when the browser allows it, else the region its time zone puts it in when that differs from home, else your home location.">
      <span class="bc-set-value">${biasText}</span>
      ${perm !== 'granted' && perm !== 'denied' && html`<button type="button" class="bc-btn" disabled=${locBusy} onClick=${allowDevice}>Allow this device's location</button>`}
      ${perm === 'denied' && html`<span class="bc-set-value">Location is blocked for this site in the browser.</span>`}
    <//>
    ${search && html`<${Row} label="Place search service" hint=${html`Set on the server, in .env: BETTERCAL_PLACE_SEARCH for suggestions as you type and BETTERCAL_PLACE_LOOKUP for placing events nobody picked a place for (imports, feeds, typed text), each a list of services tried in order (photon, locationiq, stadia, maptiler), with Photon always last; and each service's key.${(search.notes || []).map((n) => html` ${n.text} <a href=${n.url} target="_blank" rel="noopener noreferrer">${n.link}</a>`)}`}>
      <span class="bc-set-value">${search.name}${search.fallback ? ', then ' + search.fallback : ''}${search.lookup && search.lookup.active !== search.active
        ? '; placing events nobody picked a place for: ' + search.lookup.name + (search.lookup.active !== 'photon' ? ', then Photon' : '') : ''}</span>
      ${search.problem && html`<span class="bc-set-value">${search.problem}</span>`}
      ${searchDown && html`<span class="bc-set-value bc-set-warn">${search.downAdvice || 'Every place search service in use is failing; see the System tab.'}</span>`}
      ${search.lookup && search.lookup.problem && search.lookup.problem !== search.problem && html`<span class="bc-set-value">${search.lookup.problem}</span>`}
    <//>`}
  </section>`;
}

// One page, tabs. Each tab is a screen or less; things stop being appended
// to a scroll. It opens on General; /settings/<tab> opens one directly
// (handoff.js) and the System tab is where the boot notices point.
export const SETTINGS_TABS = [
  ['general', 'General'],
  ['notifications', 'Notifications'],
  ['location', 'Location & maps'],
  ['connections', 'Connections'],
  ['account', 'Account'],
  ['about', 'About'],
  ['system', 'System'],
];

// What each section holds, under its name in the phone's list (0.8.0).
// Live values where they are cheap to show, so the list says something
// without opening anything.
function sectionSummary(id, settings, user, version) {
  const view = (VIEW_OPTIONS.find(([v]) => v === settings.defaultView) || [null, 'Month'])[1];
  switch (id) {
    case 'general': return [view, settings.weekStart === 'mon' ? 'Monday' : 'Sunday', settings.timeFormat === '24' ? '24-hour' : '12-hour',
      (settings.theme === 'light' ? 'Light' : settings.theme === 'dark' ? 'Dark' : 'System') + ' theme', 'default calendar, home time zone, quick add'].join(' · ');
    case 'notifications': return 'Reminders on this device, devices, delivery, default reminders';
    case 'location': return 'Home location, map style, place search';
    case 'connections': return 'Google, outgoing feeds, CalDAV, browser extension';
    case 'account': return [(user && user.email) || 'Signed in', 'other browsers', 'API keys'].join(' · ');
    case 'about': return (version ? 'Version ' + version : 'Version') + ' · source';
    case 'system': return 'Background work, failure emails';
    default: return '';
  }
}

export function SettingsPage() {
  const { settings, user, calendars, config, settingsTab, updates } = useStore(
    (s) => ({ settings: s.settings, user: s.user, calendars: s.calendars, config: s.config, settingsTab: s.settingsTab, updates: s.updates }),
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
    loadUpdates();
  }, []);

  const save = (key) => (value) => saveSetting(key, value);
  const localCals = calendars.filter((c) => c.editable);

  const signOut = async () => {
    setBusy(true);
    try {
      // Remove only this browser's server-side reminder destination with the
      // session. Keep its browser subscription so signing back in can restore
      // reminders quietly, without another permission prompt.
      await logout(await currentEndpointHash());
    } catch (e) {
      toast('Sign out failed: ' + e.message, { error: true });
      setBusy(false);
    }
  };

  // A phone (0.8.0) opens on a list of the sections, each a page of its own
  // with Back to the list; the tab strip ran to two crowded rows there.
  const phone = window.matchMedia(PHONE_QUERY).matches;
  const attention = useStore((s) => needsAttention(s.systemHealth));
  // The attention dot on the System tab needs health even on other tabs.
  useEffect(() => { loadSystemHealth().catch(() => {}); }, []);
  const chosen = SETTINGS_TABS.some(([id]) => id === settingsTab) ? settingsTab : null;
  const tab = chosen || 'general';
  const pick = (id) => set({ settingsTab: id });
  // Leaving the page forgets the tab: coming back means starting at General
  // (or the list), not wherever you happened to be last.
  useEffect(() => () => set({ settingsTab: null }), []);

  // Phone: a section takes a history entry, so Back returns to the list.
  useEffect(() => {
    if (!phone || !chosen) return undefined;
    // Back from a reload (0.9.1) the section's entry is already there.
    if (!(history.state && history.state.bcSetSection === chosen)) history.pushState({ bcRoute: 'settings', bcSetSection: chosen }, '');
    let popped = false;
    // Back from something on top of the section (the welcome) lands on the
    // section's own entry: stay in it.
    const onPop = () => {
      if (history.state && history.state.bcSetSection === chosen) return;
      popped = true;
      set({ settingsTab: null });
    };
    window.addEventListener('popstate', onPop);
    return () => {
      window.removeEventListener('popstate', onPop);
      if (!popped && history.state && history.state.bcSetSection) history.back();
    };
  }, [phone, chosen]);

  if (phone && !chosen) {
    return html`<${PageShell} title="Settings" note="Changes are saved as you make them.">
      <nav class="bc-setlist" aria-label="Settings sections">
        ${SETTINGS_TABS.map(([id, label]) => html`<button key=${id} type="button" class="bc-setlist-row" onClick=${() => pick(id)}>
          <span class="bc-setlist-text"><span class="bc-setlist-name">${label}${id === 'system' && attention && html`<span class="bc-attn-dot" role="img" aria-label="Needs attention"></span>`}</span><span class="bc-setlist-sum">${sectionSummary(id, settings, user, version)}</span></span>
          <${Icon} name="chevronRight" size=${16} />
        </button>`)}
      </nav>
    <//>`;
  }

  const sectionLabel = phone ? (SETTINGS_TABS.find(([id]) => id === tab) || [null, 'Settings'])[1] : 'Settings';
  return html`<${PageShell}
    title=${sectionLabel}
    note=${phone ? null : 'Changes are saved as you make them.'}
    back=${phone ? { label: 'Settings', onClick: () => history.back() } : null}
  >
    ${!phone && html`<div class="bc-tabs" role="tablist" aria-label="Settings sections">
      ${SETTINGS_TABS.map(([id, label]) => html`<button
        key=${id} type="button" role="tab" id=${'bc-settab-' + id}
        class="bc-tab${tab === id ? ' is-active' : ''}"
        aria-selected=${tab === id ? 'true' : 'false'}
        onClick=${() => pick(id)}
      >${label}${id === 'system' && attention && html`<span class="bc-attn-dot" role="img" aria-label="Needs attention" title="Something here needs your attention"></span>`}</button>`)}
    </div>`}
    <div role=${phone ? undefined : 'tabpanel'} aria-labelledby=${phone ? undefined : 'bc-settab-' + tab} class=${phone ? 'bc-setpage' : undefined} data-tab=${tab}>
    ${tab === 'general' && html`
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
      ${!window.matchMedia(PHONE_QUERY).matches && html`<${Row} label="Event panel" hint="On a wide screen an event opens in a panel on the right, and the calendar narrows to make room. Tucking the sidebar away meanwhile gives the days more width back.">
        <label class="bc-set-check"><input
          type="checkbox" checked=${settings.panelTucksSidebar !== false}
          onChange=${(e) => save('panelTucksSidebar')(e.target.checked)}
        /> Hide the sidebar while an event is open</label>
      <//>`}
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
      <${Row} label="Welcome" hint="The first-run setup: these settings from the device, and where to start (subscribe, import, Google, install, reminders, plugins).">
        <button type="button" class="bc-btn" onClick=${() => set({ welcomeOpen: true })}>Show the welcome again</button>
      <//>
    </section>
    <section class="bc-set-section">
      <h2 class="bc-set-h">Invitations</h2>
      <${Row} label="Cancelled invitations" hint="When an organizer cancels, the cancellation waits in Review. Accepting it normally keeps the event, struck through, as a record. With this on, accepting removes it instead; Undo brings it back.">
        <label class="bc-set-check"><input
          type="checkbox" checked=${settings.removeAcceptedCancellations === true}
          onChange=${(e) => save('removeAcceptedCancellations')(e.target.checked)}
        /> Remove the event when you accept its cancellation</label>
      <//>

      <h2 class="bc-set-h">Quick add</h2>
      <${Row} label="Natural language parsing" hint=${NL_HINTS[settings.nlParseMode] || ''}>
        <${Seg} label="Natural language parsing" value=${settings.nlParseMode}
          options=${[['always', 'Always'], ['smart', 'Smart'], ['never', 'Never']]} onChange=${save('nlParseMode')} />
      <//>
    </section>`}

    ${tab === 'notifications' && html`<${NotificationsSection} settings=${settings} user=${user} />`}

    ${tab === 'location' && html`<${LocationSection} settings=${settings} config=${config} />`}

    ${tab === 'connections' && html`
    <${GoogleConnector} />
    <${OutfeedsSection} />
    <section class="bc-set-section">
      <h2 class="bc-set-h">Device sync</h2>
      <${Row} label="CalDAV" hint="Username is your account email; the password is your account password or an API key (Account tab).">
        <span class="bc-set-value">Apple Calendar, DAVx5, Thunderbird and other CalDAV clients can sync at <code>/dav</code> on this server.</span>
      <//>
    </section>
    <${ExtensionSection} />`}

    ${tab === 'account' && html`
    <section class="bc-set-section">
      <h2 class="bc-set-h">Account</h2>
      <${Row} label="Signed in as">
        <span class="bc-set-value">${(user && user.email) || ''}</span>
        <button type="button" class="bc-btn" disabled=${busy} onClick=${signOut}>Sign out</button>
      <//>
      <${OtherBrowsersRow} />
    </section>
    <${TokensSection} />`}

    ${tab === 'about' && html`
    <section class="bc-set-section">
      <h2 class="bc-set-h">About</h2>
      <${Row} label="Version">
        <span class="bc-set-value">${version || 'unknown'}</span>
      <//>
      <${Row} label="Update notices" hint="Checks GitHub Releases once a day. Better-Cal never installs an update automatically.">
        <select value=${settings.updateNotifications || 'all'} aria-label="Update notices" onChange=${async (e) => {
          if (await save('updateNotifications')(e.target.value)) await loadUpdates();
        }}>
          <option value="all">All releases</option>
          <option value="security">Security updates only</option>
          <option value="off">Off</option>
        </select>
      <//>
      <${Row} label="Updates" hint="A manual check works even when automatic notices are off.">
        <${UpdateSettings} update=${updates} />
      <//>
      <${Row} label="Source" hint="Self-hosted; the code, issues and docs live on GitHub.">
        <a class="bc-set-value" href="https://github.com/Oshyan/better-cal" target="_blank" rel="noopener">github.com/Oshyan/better-cal</a>
      <//>
    </section>`}

    ${tab === 'system' && html`<${SystemSection} />`}
    </div>
  <//>`;
}
