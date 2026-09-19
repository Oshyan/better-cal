// Per-calendar settings panel, opened from the gear on a sidebar calendar
// row. Name, color (preset swatches + free hex), folder membership; for
// subscribed feeds also source URL, poll interval, stale threshold, similar-
// event grouping, refresh-now and last-poll status. Delete confirms inline.
// All writes go through PATCH /calendars/:id (updateCalendar).

import { html, useState } from '../../vendor/index.js';
import { updateCalendar, refreshCalendar, deleteCalendar } from './actions.js';
import { api, loadCalendars } from './api.js';
import { state, toast } from './store.js';
import { parseHex, PALETTE } from '../lib/color.js';
import { SchemaForm } from './SchemaForm.js';
import { Icon } from '../ui/icons.js';

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

export function CalendarSettings({ cal, folders, onClose }) {
  const [name, setName] = useState(cal.name);
  const [hex, setHex] = useState(cal.color);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const subscribed = cal.kind === 'subscribed';

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

    ${subscribed && html`<div class="bc-calset-field">
      <span class="bc-calset-label">Source</span>
      ${cal.provider === 'google'
        ? html`<span class="bc-calset-url" title=${cal.googleCalendarId || ''}>Google Calendar, through your connected account (Settings, Google)</span>`
        : html`<span class="bc-calset-url" title=${cal.sourceUrl}>${cal.sourceUrl}</span>`}
    </div>
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
      <button type="button" class="bc-btn" disabled=${refreshing} onClick=${refresh}>
        ${refreshing ? 'Refreshing' : 'Refresh now'}
      </button>
    </div>
    <div class="bc-calset-status">${pollStatusLine(cal)}</div>
    <div class="bc-calset-field">
      <button
        type="button" class="bc-btn"
        title="Sever the feed link and make every event a local, editable copy (one-way; used when migrating off the old calendar)"
        onClick=${async () => {
          if (!window.confirm('Adopt "' + cal.name + '" as a local calendar? It will stop syncing from its feed and all events become editable.')) return;
          try {
            await api('/calendars/' + cal.id + '/adopt', { method: 'POST' });
            toast('Adopted as local calendar');
            await loadCalendars();
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
