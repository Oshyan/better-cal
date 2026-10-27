// Calendars a person adds to a plugin one at a time, each with its own
// settings (a tide calendar per station, a weather calendar per place). The
// same pieces show on the plugin's card (Plugins page) and in each
// calendar's own settings: the settings form and the plugin's status line,
// the form for adding one, and "Add for this place", which makes another
// plugin's calendar follow this calendar's place.

import { html, useState } from '../../vendor/index.js';
import { state, toast, useStore } from './store.js';
import { api, loadCalendars, loadPlugins, refreshWindow } from './api.js';
import { SchemaForm } from './SchemaForm.js';
import { Icon } from '../ui/icons.js';

const defaultsOf = (schema) => Object.fromEntries((schema || []).map((f) => [f.key, f.default ?? null]));
const locationKey = (plugin) => ((plugin.ownCalendarSettingsSchema || []).find((f) => f.type === 'location') || {}).key || null;

/** Plugin calendars a location setting may follow: those that know their place (not this one). */
export function placeSourcesFor(calendars, selfId = null) {
  return (calendars || []).filter((c) => c.kind === 'plugin' && c.place && c.id !== selfId);
}

function fmtAgo(iso) {
  const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
  if (min < 1) return 'just now';
  if (min < 60) return min + ' min ago';
  const h = Math.round(min / 60);
  return h < 48 ? h + ' h ago' : Math.round(h / 24) + ' d ago';
}

/** The plugin's latest line about this calendar ("38 low tides in the next 90 days"). */
export function OwnStatus({ cal }) {
  const st = cal.ownStatus;
  if (!st) return html`<div class="bc-own-status">Not filled yet. It fills within a minute of being added or changed.</div>`;
  return html`<div class=${'bc-own-status is-' + st.severity}>
    ${st.severity !== 'info' && html`<${Icon} name="warning" size=${12} />`}<span>${st.text}</span><small>${fmtAgo(st.at)}</small>
  </div>`;
}

/** One calendar's own settings, saved for it alone (and refilled within a minute). */
export function OwnCalendarSettings({ cal, plugin }) {
  const calendars = useStore((s) => s.calendars);
  if (!plugin || !cal.ownSettings) return null;
  return html`<div class="bc-own">
    <${OwnStatus} cal=${cal} />
    <${SchemaForm}
      schema=${plugin.ownCalendarSettingsSchema}
      values=${cal.ownSettings}
      pluginId=${plugin.id}
      placeSources=${placeSourcesFor(calendars, cal.id)}
      onSave=${async (draft) => {
        try {
          await api('/plugins/' + plugin.id + '/calendars/' + cal.id, { method: 'PATCH', body: draft });
          toast(cal.name + ' saved. It refills within a minute.');
          await loadCalendars();
          return true;
        } catch (e) {
          toast(e.message || 'Save failed', { error: true });
          return false;
        }
      }}
    />
  </div>`;
}

/** "Add tide calendar": a name (optional, the plugin names it from the settings) and its settings. */
export function AddOwnCalendar({ plugin, onDone }) {
  const calendars = useStore((s) => s.calendars);
  const [name, setName] = useState('');
  const decl = plugin.ownCalendars;
  const named = /\{[^}]+\}/.test(decl.name || '');
  return html`<div class="bc-own-add">
    <${SchemaForm}
      schema=${plugin.ownCalendarSettingsSchema}
      values=${defaultsOf(plugin.ownCalendarSettingsSchema)}
      pluginId=${plugin.id}
      placeSources=${placeSourcesFor(calendars)}
      create=${true}
      saveLabel=${'Add ' + decl.noun}
      onCancel=${onDone}
      onSave=${async (draft) => {
        try {
          await api('/plugins/' + plugin.id + '/calendars', { method: 'POST', body: { name: name.trim() || null, settings: draft } });
          toast('Added. It fills within a minute.');
          await Promise.all([loadCalendars(), loadPlugins()]);
          refreshWindow();
          onDone();
          return true;
        } catch (e) {
          toast(e.message || 'Could not add it', { error: true });
          return false;
        }
      }}
    >
      <label class="bc-schema-field">
        <span class="bc-schema-label">Calendar name${named && html`<span class="bc-schema-help">Optional. Left empty, it's named after its settings and follows them.</span>`}</span>
        <input type="text" class="bc-schema-input" maxLength="160" value=${name} onInput=${(e) => setName(e.target.value)}
          placeholder=${named ? decl.name.replace(/\{([^}]+)\}/g, (_, k) => ((plugin.ownCalendarSettingsSchema || []).find((f) => f.key === k) || {}).label || k) : decl.name}
          title="Leave empty to name it from its settings; it follows them until you rename it" />
      </label>
    </${SchemaForm}>
  </div>`;
}

/**
 * "Add for this place": one click makes another plugin's calendar (sun,
 * weather, air quality) that follows this calendar's place, so a tide
 * calendar gets its sunrise and sunset without entering the place again.
 */
export function AddForPlace({ cal }) {
  const plugins = useStore((s) => s.plugins);
  const calendars = useStore((s) => s.calendars);
  const [busy, setBusy] = useState(null);
  if (!cal.place) return null;
  const followers = new Set((calendars || []).filter((c) => c.ownSettings && Object.values(c.ownSettings).some((v) => v && v.link === cal.id)).map((c) => c.pluginId));
  const offers = (plugins || []).filter((p) => p.enabled && p.ownCalendars && p.id !== cal.pluginId && locationKey(p));
  if (offers.length === 0) return null;
  const add = async (p) => {
    setBusy(p.id);
    try {
      await api('/plugins/' + p.id + '/calendars', { method: 'POST', body: { settings: { [locationKey(p)]: { link: cal.id } } } });
      toast(p.name + ' added for ' + (cal.place.name || cal.name) + '. It fills within a minute.');
      await Promise.all([loadCalendars(), loadPlugins()]);
      refreshWindow();
    } catch (e) {
      toast(e.message || 'Could not add it', { error: true });
    } finally {
      setBusy(null);
    }
  };
  return html`<div class="bc-own-place">
    <span class="bc-own-place-label">Add for ${cal.place.name || 'this place'}</span>
    <span class="bc-own-offers">
      ${offers.map((p) => followers.has(p.id)
        ? html`<span key=${p.id} class="bc-own-offer is-done" title=${'A ' + p.name + ' calendar already follows this place'}><${Icon} name="check" size=${12} /> ${p.name}</span>`
        : html`<button key=${p.id} type="button" class="bc-btn bc-own-offer" disabled=${busy !== null} title=${'Add a ' + p.ownCalendars.noun + ' that follows ' + (cal.place.name || 'this place')}
            onClick=${() => add(p)}><${Icon} name="plus" size=${12} /> ${p.name}</button>`)}
    </span>
  </div>`;
}

/** On a plugin's card: its calendars, each opening to its own settings, and "Add". */
export function OwnCalendarsList({ plugin }) {
  const calendars = useStore((s) => s.calendars);
  const [open, setOpen] = useState(null);
  const [adding, setAdding] = useState(false);
  const mine = (calendars || []).filter((c) => c.kind === 'plugin' && c.pluginId === plugin.id);
  const decl = plugin.ownCalendars;
  const full = mine.length >= decl.max;
  return html`<div class="bc-own-list">
    <div class="bc-own-rows">
      ${mine.length === 0 && !adding && html`<span class="bc-own-empty">No ${decl.noun}s yet.</span>`}
      ${mine.map((c) => html`<button key=${c.id} type="button" class=${'bc-own-chip' + (open === c.id ? ' is-open' : '') + (c.ownStatus && c.ownStatus.severity !== 'info' ? ' is-' + c.ownStatus.severity : '')}
        aria-expanded=${open === c.id} onClick=${() => { setOpen(open === c.id ? null : c.id); setAdding(false); }}>
        <span class="bc-own-dot" style=${'background:' + c.color}></span>${c.name}
      </button>`)}
      ${!adding && !full && html`<button type="button" class="bc-link-btn" onClick=${() => { setAdding(true); setOpen(null); }}>
        <${Icon} name="plus" size=${12} /> Add ${decl.noun}</button>`}
    </div>
    ${adding && html`<${AddOwnCalendar} plugin=${plugin} onDone=${() => setAdding(false)} />`}
    ${mine.filter((c) => c.id === open).map((c) => html`<div key=${c.id}>
      <${OwnCalendarSettings} cal=${c} plugin=${plugin} />
      <${AddForPlace} cal=${c} />
    </div>`)}
  </div>`;
}

export function pluginFor(cal) {
  return (state.plugins || []).find((p) => p.id === cal.pluginId) || null;
}
