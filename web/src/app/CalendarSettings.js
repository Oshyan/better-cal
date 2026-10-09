// Per-calendar settings panel, opened from the gear on a sidebar calendar
// row. Name, color (preset swatches + free hex), folder membership; for
// subscribed feeds also source URL, poll interval, stale threshold, similar-
// event grouping, refresh-now and last-poll status. Default reminders for the
// calendar's events. Delete confirms inline.
// All writes go through PATCH /calendars/:id (updateCalendar).

import { html, useState } from '../../vendor/index.js';
import { updateCalendar, refreshCalendar, deleteCalendar } from './actions.js';
import { api, loadCalendars, loadReviewCount } from './api.js';
import { state, toast } from './store.js';
import { parseHex, PALETTE } from '../lib/color.js';
import { SchemaForm } from './SchemaForm.js';
import { Icon } from '../ui/icons.js';
import { MoveToGoogle } from './MoveToGoogle.js';
import { TimedDefault, AllDayDefault } from './ReminderDefaults.js';
import { summarizeDefaults, FOLLOW_DEFAULTS } from '../lib/reminders.js';

// Preset grid: the app palette plus teal and slate to round out 12.
const SWATCHES = [...PALETTE, '#3aa695', '#708090'];

const POLL_OPTIONS = [
  [15, 'Every 15 minutes'], [60, 'Every hour'], [360, 'Every 6 hours'], [1440, 'Every 24 hours'],
];
const STALE_OPTIONS = [
  [1, 'After 1 day'], [2, 'After 2 days'], [7, 'After 7 days'], [14, 'After 14 days'], [30, 'After 30 days'],
];

// Nearest allowed option for a stored value the presets do not include.
function nearest(options, value) {
  let best = options[0][0];
  for (const [v] of options) if (Math.abs(v - value) < Math.abs(best - value)) best = v;
  return best;
}

function pollStatusLine(cal) {
  const h = cal.health || {};
  if (!h.lastPolledAt) return 'Not polled yet';
  let line = 'Last poll ' + h.lastPolledAt;
  if (h.status === 'error') line += ' (error: ' + (h.error || 'unknown') + ')';
  else if (h.content === 'emptied') line += ' (came back empty; had events before)';
  else if (h.content === 'stale') line += ' (stale: unchanged for a while, nothing upcoming)';
  else if (h.content === 'empty') line += ' (ok, no events yet)';
  else line += ' (ok' + (h.eventCount != null ? ', ' + h.eventCount + ' events' : '') + ')';
  return line;
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

export function CalendarSettings({ cal, folders, onClose }) {
  const [name, setName] = useState(cal.name);
  const [hex, setHex] = useState(cal.color);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const subscribed = cal.kind === 'subscribed';
  const subscriptionAuth = cal.subscriptionAuthorization;
  const subscriptionPaused = !!(subscriptionAuth && subscriptionAuth.status === 'paused');

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
      await api('/calendars/' + cal.id + '/claim-subscription', { method: 'POST' });
      toast('Calendar updates resumed');
      await Promise.allSettled([loadCalendars(), loadReviewCount()]);
    } catch (e) {
      toast('Could not resume subscription: ' + e.message, { error: true });
    }
  };

  return html`<div class="bc-calset" role="group" aria-label=${'Settings for ' + cal.name}>
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
    ${subscribed && html`<div class="bc-calset-field">
      <span class="bc-calset-label">Source</span>
      ${cal.provider === 'google'
        ? html`<span class="bc-calset-url" title=${cal.googleCalendarId || ''}>Google Calendar, through your connected account (Settings, Connections)${cal.editable ? '; edits here go to Google' : '; view only at Google'}</span>`
        : html`<span class="bc-calset-url" title=${cal.sourceUrl}>${cal.sourceUrl}</span>`}
    </div>
    ${subscriptionPaused && html`<div class="bc-calset-auth" role="alert">
      <span>${subscriptionAuth.reason}</span>
      <button type="button" class="bc-btn" onClick=${keepSubscriptionActive}>Keep updating</button>
    </div>`}
    <div class="bc-calset-field">
      <span class="bc-calset-label">Check for updates</span>
      <select
        aria-label="Poll interval"
        value=${String(nearest(POLL_OPTIONS, cal.pollIntervalMinutes || 60))}
        onChange=${(e) => updateCalendar(cal, { pollIntervalMinutes: Number(e.target.value) })}
      >
        ${POLL_OPTIONS.map(([v, l]) => html`<option key=${v} value=${String(v)}>${l}</option>`)}
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
          if (!window.confirm('Adopt "' + cal.name + '" as a local calendar? ' + (cal.provider === 'google'
            ? 'It stops syncing with Google in both directions and becomes yours here; the copy at Google is left as it is.'
            : 'It will stop syncing from its feed and all events become editable.'))) return;
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
