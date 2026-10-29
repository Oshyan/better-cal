// Per-calendar settings panel, opened from the gear on a sidebar calendar
// row. Name, color (preset swatches + free hex), folder membership; for
// subscribed feeds also source URL, poll interval, stale threshold, similar-
// event grouping, refresh-now and last-poll status. Default reminders for the
// calendar's events. Delete confirms inline.
// All writes go through PATCH /calendars/:id (updateCalendar).

import { feedProblem, whenText } from '../lib/feedproblem.js';
import { html, useState } from '../../vendor/index.js';
import { updateCalendar, refreshCalendar, deleteCalendar } from './actions.js';
import { api, loadCalendars, loadReviewCount } from './api.js';
import { state, set, toast } from './store.js';
import { parseHex, PALETTE } from '../lib/color.js';
import { SchemaForm } from './SchemaForm.js';
import { OwnCalendarSettings, LinkedCalendars, pluginFor } from './OwnCalendar.js';
import { Icon, GoogleG } from '../ui/icons.js';
import { MoveToGoogle } from './MoveToGoogle.js';
import { usePasswordStepUp } from './PasswordStepUp.js';
import { TimedDefault, AllDayDefault } from './ReminderDefaults.js';
import { summarizeDefaults, FOLLOW_DEFAULTS } from '../lib/reminders.js';
import { askConfirm } from './ConfirmDialog.js';

// Preset grid: the app palette plus teal and slate to round out 12.
const SWATCHES = [...PALETTE, '#3aa695', '#708090'];

const POLL_OPTIONS = [
  [15, 'Every 15 minutes'], [60, 'Every hour'], [360, 'Every 6 hours'], [1440, 'Every 24 hours'],
];
// A Google calendar starts at 5 minutes: each check asks only for what
// changed. A feed is fetched whole, so it keeps 15 as its shortest.
const GOOGLE_POLL_OPTIONS = [[5, 'Every 5 minutes'], ...POLL_OPTIONS];
const STALE_OPTIONS = [
  [1, 'After 1 day'], [2, 'After 2 days'], [7, 'After 7 days'], [14, 'After 14 days'], [30, 'After 30 days'],
];

// Nearest allowed option for a stored value the presets do not include.
function nearest(options, value) {
  let best = options[0][0];
  for (const [v] of options) if (Math.abs(v - value) < Math.abs(best - value)) best = v;
  return best;
}

// The quiet line under Refresh now: when it was last checked, on this
// device's clock, and what it holds. A problem has its own notice at the top.
function pollStatusLine(cal) {
  const h = cal.health || {};
  if (!h.lastPolledAt) return 'Not checked yet';
  const line = 'Last checked ' + whenText(h.lastPolledAt);
  if (h.status === 'error') return line;
  if (h.content === 'empty') return line + ' · no events yet';
  return line + (h.eventCount != null ? ' · ' + h.eventCount + (h.eventCount === 1 ? ' event' : ' events') : '');
}

/**
 * What's wrong with a feed, at the top of its settings. Compact in the
 * sidebar (what happened, why in a line, Try again, Details); in full on
 * Calendars & folders (also the raw detail and when it last worked).
 */
function FeedNotice({ cal, full, refreshing, onRetry }) {
  const p = feedProblem(cal);
  if (!p) return null;
  const paused = cal.subscriptionAuthorization && cal.subscriptionAuthorization.status === 'paused';
  const when = p.tried && (p.worked
    ? `Last tried ${p.tried}; last updated ${p.worked}.`
    : `Last tried ${p.tried}.`);
  return html`<div class=${'bc-feednotice is-' + p.level + (full ? ' is-full' : '')} role="status">
    <div class="bc-feednotice-head"><${Icon} name="warning" size=${14} /><strong>${p.headline}</strong></div>
    <p class="bc-feednotice-why">${p.reason}</p>
    ${full && html`<p class="bc-feednotice-when">${when} ${cal.health && cal.health.status === 'error' ? 'The events it already has keep showing.' : ''}</p>`}
    ${full && p.detail && html`<p class="bc-feednotice-detail">${p.detail}</p>`}
    <div class="bc-feednotice-acts">
      ${!paused && html`<button type="button" class="bc-btn" disabled=${refreshing} onClick=${onRetry}>${refreshing ? 'Trying' : 'Try again'}</button>`}
      ${!full && html`<button type="button" class="bc-link-btn" onClick=${() => set({ route: 'organize', organizeFocus: cal.id })}>Details</button>`}
    </div>
  </div>`;
}

// Which reminders the calendar's events get: your defaults (linked, so they
// follow Settings), none, or the calendar's own. Until one is chosen the role
// decides: Mine uses your defaults, Opportunities and Context stay quiet (a
// busy feed or sunsets shouldn't all remind). Any role can choose any of the
// three.
const ROLE_NAMES = { mine: 'Mine', opportunities: 'Opportunities', context: 'Context' };
const FALLBACK_OWN = { timed: [{ minutes: 10 }], allDay: [{ daysBefore: 1, time: '18:00' }] };

function ReminderField({ cal }) {
  const rd = cal.reminderDefaults;
  const role = cal.role || 'mine';
  const s = state.settings || {};
  const own = rd && typeof rd === 'object';
  const ownEmpty = own && !(rd.timed || []).length && !(rd.allDay || []).length;
  const mode = own ? (ownEmpty ? 'none' : 'own')
    : rd === FOLLOW_DEFAULTS ? 'defaults'
    : role === 'mine' ? 'defaults' : 'none';
  const save = (reminderDefaults) => updateCalendar(cal, { reminderDefaults });
  const onMode = (v) => {
    if (v === 'defaults') save(FOLLOW_DEFAULTS);
    else if (v === 'none') save({ timed: [], allDay: [] });
    else {
      const timed = s.reminderTimed || [];
      const allDay = s.reminderAllDay || [];
      save(timed.length || allDay.length ? { timed, allDay } : FALLBACK_OWN);
    }
  };
  const summary = summarizeDefaults(s.reminderTimed, s.reminderAllDay);
  return html`<div class="bc-calset-field">
    <span class="bc-calset-label">Reminders</span>
    <select aria-label="Reminders for this calendar" value=${mode} onChange=${(e) => onMode(e.target.value)}>
      <option value="defaults">My defaults</option>
      <option value="none">None</option>
      <option value="own">Set for this calendar</option>
    </select>
    ${mode === 'defaults' && html`<span class="bc-calset-status">From Settings, Notifications: ${summary}.</span>`}
    ${mode === 'none' && rd == null && html`<span class="bc-calset-status">${ROLE_NAMES[role] || 'This'} calendars don't remind unless you choose, so a busy feed or sunsets don't all notify. An event's own reminders still apply.</span>`}
    ${mode === 'own' && html`<div class="bc-calset-rem">
      <span class="bc-calset-sub">Timed events</span>
      <div class="bc-calset-remrow"><${TimedDefault} label="Reminder for timed events on this calendar"
        value=${rd.timed}
        onChange=${(list) => save({ ...rd, timed: list })} /></div>
      <span class="bc-calset-sub">All-day events</span>
      <div class="bc-calset-remrow"><${AllDayDefault} label="Reminder for all-day events on this calendar"
        value=${rd.allDay}
        onChange=${(list) => save({ ...rd, allDay: list })} /></div>
      <span class="bc-calset-status">An event's own reminders still win.</span>
    </div>`}
  </div>`;
}

export function CalendarSettings({ cal, folders, onClose, full = false }) {
  const stepUp = usePasswordStepUp();
  const [name, setName] = useState(cal.name);
  const [hex, setHex] = useState(cal.color);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const subscribed = cal.kind === 'subscribed';
  const subscriptionAuth = cal.subscriptionAuthorization;
  const subscriptionPaused = !!(subscriptionAuth && subscriptionAuth.status === 'paused');
  const pollOptions = cal.provider === 'google' ? GOOGLE_POLL_OPTIONS : POLL_OPTIONS;

  const commitName = () => {
    const trimmed = name.trim();
    if (trimmed && trimmed !== cal.name) updateCalendar(cal, { name: trimmed });
    else setName(cal.name);
  };

  const setColor = (color) => {
    setHex(color);
    if (color !== cal.color) updateCalendar(cal, { color });
  };

  const commitHex = () => {
    const val = hex.trim().startsWith('#') ? hex.trim() : '#' + hex.trim();
    if (parseHex(val)) setColor(val.toLowerCase());
    else setHex(cal.color);
  };

  const toggleFolder = (fid) => {
    const ids = new Set(cal.folderIds || []);
    if (ids.has(fid)) ids.delete(fid); else ids.add(fid);
    updateCalendar(cal, { folderIds: [...ids] });
  };

  const refresh = async () => {
    setRefreshing(true);
    await refreshCalendar(cal);
    setRefreshing(false);
  };

  const remove = async () => {
    if (await deleteCalendar(cal)) onClose();
  };

  const keepSubscriptionActive = async () => {
    try {
      const result = await stepUp.run('keep this subscription updating', () => api('/calendars/' + cal.id + '/claim-subscription', { method: 'POST' }));
      if (!result) return;
      toast('Calendar updates resumed');
      await Promise.allSettled([loadCalendars(), loadReviewCount()]);
    } catch (e) {
      toast('Could not resume subscription: ' + e.message, { error: true });
    }
  };

  return html`<div class="bc-calset" role="group" aria-label=${'Settings for ' + cal.name}>
    ${stepUp.prompt}
    <${FeedNotice} cal=${cal} full=${full} refreshing=${refreshing} onRetry=${refresh} />
    <div class="bc-calset-field">
      <span class="bc-calset-label">Name</span>
      <input
        value=${name} aria-label="Calendar name"
        onInput=${(e) => setName(e.target.value)}
        onBlur=${commitName}
        onKeyDown=${(e) => { if (e.key === 'Enter') e.target.blur(); }}
      />
    </div>

    <div class="bc-calset-field">
      <span class="bc-calset-label">Color</span>
      <div class="bc-swatches" role="group" aria-label="Preset colors">
        ${SWATCHES.map((c) => html`<button
          key=${c} type="button"
          class="bc-swatch${c === cal.color ? ' is-sel' : ''}"
          style=${`background:${c}`}
          aria-label=${'Color ' + c} aria-pressed=${c === cal.color}
          onClick=${() => setColor(c)}
        ></button>`)}
      </div>
      <input
        class="bc-hex-input" value=${hex} aria-label="Hex color"
        onInput=${(e) => setHex(e.target.value)}
        onBlur=${commitHex}
        onKeyDown=${(e) => { if (e.key === 'Enter') e.target.blur(); }}
      />
    </div>

    ${folders.length > 0 && html`<div class="bc-calset-field">
      <span class="bc-calset-label">Folders</span>
      <div class="bc-calset-folders">
        ${folders.map((f) => html`<label key=${f.id} class="bc-check">
          <input
            type="checkbox"
            checked=${(cal.folderIds || []).includes(f.id)}
            onChange=${() => toggleFolder(f.id)}
          />
          <span>${f.name}</span>
        </label>`)}
      </div>
    </div>`}

    <div class="bc-calset-field">
      <span class="bc-calset-label">What this calendar is</span>
      <select aria-label="Calendar role" value=${cal.role || 'mine'} onChange=${(e) => updateCalendar(cal, { role: e.target.value })}>
        <option value="mine">Mine: things I do (planned unless marked maybe)</option>
        <option value="opportunities">Opportunities: things I could do (available until I pick)</option>
        <option value="context">Context: information (sunset, tides, someone's schedule)</option>
      </select>
    </div>
    <${ReminderField} cal=${cal} />
    ${cal.kind === 'plugin' && html`<div class="bc-calset-field">
      <span class="bc-calset-label">Source</span>
      <span class="bc-calset-source"><${Icon} name="plugins" size=${13} /><span>
        <strong>${(cal.pluginName || 'A') + ' plugin'}</strong>${cal.reschedulable
          ? '. Moving an event here changes it there; everything else is edited there. '
          : '. It fills this calendar, so its events are read-only here. '}
        <button type="button" class="bc-link-btn" onClick=${() => { if (onClose) onClose(); set({ route: 'plugins', pluginFocus: cal.pluginId, pluginCalendarFocus: cal.ownSettings ? cal.id : null }); }}>Plugin settings</button>
      </span></span>
    </div>`}
    ${cal.kind === 'plugin' && cal.ownSettings && pluginFor(cal) && html`<div class="bc-calset-plugin">
      <span class="bc-calset-pluginhead"><${Icon} name="plugins" size=${12} /> This ${pluginFor(cal).ownCalendars ? pluginFor(cal).ownCalendars.noun : 'calendar'}</span>
      <${OwnCalendarSettings} cal=${cal} plugin=${pluginFor(cal)} />
    </div>`}
    ${cal.kind === 'plugin' && html`<${LinkedCalendars} cal=${cal} />`}
    ${subscribed && html`<div class="bc-calset-field">
      <span class="bc-calset-label">Source</span>
      ${cal.provider === 'google'
        ? html`<span class="bc-calset-source"><${GoogleG} size=${13} /><span>
            <strong>Google Calendar</strong>${(cal.googleAccountEmail ? `, in ${cal.googleAccountEmail}` : '') + '. '
              + (!cal.googleAccountEmail
                ? 'Its Google account was disconnected, so it no longer updates and can\'t be edited here. Connect the account again in Settings, Connections, and choose Re-attach to pick up where it left off.'
                : cal.editable ? 'Edits here go to Google, and changes made there come in at each check below.' : 'View only: it can\'t be changed here. Changes made there come in at each check below.')}
          </span></span>`
        : html`<span class="bc-calset-source"><${Icon} name="link" size=${13} /><span>
            <strong>Subscribed from a web address</strong>${', view only. It refreshes at each check below.'}
            ${cal.sourceUrl && html`<span class="bc-calset-url" title=${cal.sourceUrl}>${cal.sourceUrl}</span>`}
          </span></span>`}
    </div>
    ${subscriptionPaused && html`<div class="bc-calset-auth" role="alert">
      <span>${subscriptionAuth.reason}</span>
      <button type="button" class="bc-btn" onClick=${keepSubscriptionActive}>Keep updating</button>
    </div>`}
    <div class="bc-calset-field">
      <span class="bc-calset-label">Check for updates</span>
      <select
        aria-label="Poll interval"
        value=${String(nearest(pollOptions, cal.pollIntervalMinutes || 60))}
        onChange=${(e) => updateCalendar(cal, { pollIntervalMinutes: Number(e.target.value) })}
      >
        ${pollOptions.map(([v, l]) => html`<option key=${v} value=${String(v)}>${l}</option>`)}
      </select>
    </div>
    <div class="bc-calset-field">
      <span class="bc-calset-label">Mark feed stale</span>
      <select
        aria-label="Stale threshold"
        value=${String(nearest(STALE_OPTIONS, cal.staleAfterDays || 7))}
        onChange=${(e) => updateCalendar(cal, { staleAfterDays: Number(e.target.value) })}
      >
        ${STALE_OPTIONS.map(([v, l]) => html`<option key=${v} value=${String(v)}>${l}</option>`)}
      </select>
    </div>
    <label class="bc-check">
      <input
        type="checkbox"
        checked=${!!cal.groupSimilar}
        onChange=${(e) => updateCalendar(cal, { groupSimilar: e.target.checked })}
      />
      <span>Group similar events</span>
    </label>
    <label class="bc-check" title="Events the feed marks cancelled are left off the calendar instead of struck through. They come back if the feed un-cancels them.">
      <input
        type="checkbox"
        checked=${!!cal.hideCancelled}
        onChange=${(e) => updateCalendar(cal, { hideCancelled: e.target.checked })}
      />
      <span>Hide cancelled events</span>
    </label>
    <div class="bc-calset-field">
      <button type="button" class="bc-btn" disabled=${refreshing || subscriptionPaused} onClick=${refresh}>
        ${subscriptionPaused ? 'Updates paused' : refreshing ? 'Refreshing' : 'Refresh now'}
      </button>
    </div>
    <div class="bc-calset-status">${pollStatusLine(cal)}</div>
    <div class="bc-calset-field">
      <button
        type="button" class="bc-btn"
        title="Sever the feed link and make every event a local, editable copy (one-way; used when migrating off the old calendar)"
        onClick=${async () => {
          if (!(await askConfirm({
            title: 'Adopt "' + cal.name + '" as a local calendar?',
            body: (cal.provider === 'google'
              ? 'It stops syncing with Google in both directions and becomes yours here; the copy at Google is left as it is.'
              : 'It stops updating from its feed, and all its events become editable.') + ' This can\'t be undone.',
            confirmLabel: 'Adopt as local',
            danger: true,
          }))) return;
          try {
            await api('/calendars/' + cal.id + '/adopt', { method: 'POST' });
            toast('Adopted as local calendar');
            await Promise.allSettled([loadCalendars(), loadReviewCount()]);
          } catch (e) {
            toast('Adopt failed: ' + e.message, { error: true });
          }
        }}
      >Adopt as local calendar</button>
    </div>`}

    ${state.plugins.filter((pl) => pl.enabled && pl.calendarSettingsSchema.length > 0).map((pl) => html`<div key=${pl.id} class="bc-calset-plugin">
      <span class="bc-calset-pluginhead"><${Icon} name="plugins" size=${12} /> ${pl.name}</span>
      <${SchemaForm}
        schema=${pl.calendarSettingsSchema}
        values=${(cal.pluginSettings && cal.pluginSettings[pl.id]) || {}}
        saveLabel="Save"
        onSave=${async (draft) => {
          try {
            await api('/plugins/' + pl.id + '/calendar-settings/' + cal.id, { method: 'PATCH', body: draft });
            toast(pl.name + ' settings saved for ' + cal.name);
            await loadCalendars();
            return true;
          } catch (e) {
            toast(e.message || 'Save failed', { error: true });
            return false;
          }
        }}
      />
    </div>`)}

    ${cal.kind === 'local' && cal.editable && html`<${MoveToGoogle} cal=${cal} />`}

    <div class="bc-calset-field">
      <span class="bc-calset-label">Export</span>
      <a class="bc-btn" href=${'/api/v1/calendars/' + cal.id + '/export'} download>Download .ics</a>
      <span class="bc-calset-status">Every event in this calendar as a calendar file, for any calendar app that imports .ics files.</span>
    </div>

    <div class="bc-calset-danger">
      ${confirmDelete
        ? html`<span class="bc-calset-confirm">Delete "${cal.name}" and all of its events?</span>
          <div class="bc-calset-confirm-row">
            <button type="button" class="bc-btn bc-btn-danger" onClick=${remove}>Delete calendar</button>
            <button type="button" class="bc-btn" onClick=${() => setConfirmDelete(false)}>Cancel</button>
          </div>`
        : html`<button type="button" class="bc-link-btn bc-danger-link" onClick=${() => setConfirmDelete(true)}>Delete calendar...</button>`}
    </div>
  </div>`;
}
