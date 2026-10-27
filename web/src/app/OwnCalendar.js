// Calendars a person adds to a plugin one at a time, each with its own
// settings (a tide calendar per station, a weather calendar per place). The
// same pieces show on the plugin's card (Plugins page) and in each
// calendar's own settings: the settings form and the plugin's status line,
// the form for adding one, and "Linked calendars", the other plugins'
// calendars that use this calendar's place (add, open or unlink them).

import { html, useState, useEffect, useRef } from '../../vendor/index.js';
import { state, set, toast, useStore } from './store.js';
import { api, loadCalendars, loadPlugins, refreshWindow } from './api.js';
import { SchemaForm } from './SchemaForm.js';
import { Icon } from '../ui/icons.js';

const defaultsOf = (schema) => Object.fromEntries((schema || []).map((f) => [f.key, f.default ?? null]));
const locationKey = (plugin) => ((plugin.ownCalendarSettingsSchema || []).find((f) => f.type === 'location') || {}).key || null;

/**
 * Calendars a location setting may follow: plugin calendars with a place of
 * their own (a tide station, a place entered), from other plugins. Ones that
 * follow a place themselves are left out, so each place is offered once, by
 * the calendar it comes from.
 */
export function placeSourcesFor(calendars, self = null) {
  return (calendars || []).filter((c) => c.kind === 'plugin' && c.place && c.place.via == null
    && (!self || (c.id !== self.id && c.pluginId !== self.pluginId)));
}

/** "Tides, Sample Harbor" as is; a calendar whose name doesn't say its place gets it after. */
export function sourceLabel(c) {
  const place = c.place && c.place.name;
  return place && !c.name.includes(place) ? c.name + ' (' + place + ')' : c.name;
}

/** The plugin calendars whose place follows this one, with the setting that links them. */
function followersOf(cal, calendars) {
  const out = [];
  for (const c of calendars || []) {
    if (!c.ownSettings) continue;
    for (const [key, v] of Object.entries(c.ownSettings)) {
      if (v && typeof v === 'object' && v.link === cal.id) { out.push({ cal: c, key }); break; }
    }
  }
  return out;
}

/** Open one plugin calendar's settings on the Plugins page. */
export function openOwnCalendar(c) {
  set({ route: 'plugins', pluginFocus: c.pluginId, pluginCalendarFocus: c.id });
}

/**
 * After adding or changing one of these calendars, refresh the calendars
 * until its plugin has filled it (its status line moves on), so the events
 * and status appear without a reload. Quick when the server runs the plugin
 * right away, slower when it waits for the worker; gives up after two minutes.
 */
async function followFill(calendarId, now) {
  const before = (state.calendars || []).find((c) => c.id === calendarId);
  const at = before && before.ownStatus ? before.ownStatus.at : null;
  const every = now ? 1500 : 5000;
  for (let i = 0; i < 120000 / every; i++) {
    await new Promise((r) => setTimeout(r, every));
    await loadCalendars();
    const c = (state.calendars || []).find((x) => x.id === calendarId);
    if (!c) return;
    if (c.ownStatus && c.ownStatus.at !== at) {
      refreshWindow();
      return;
    }
  }
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
      placeSources=${placeSourcesFor(calendars, cal)}
      onSave=${async (draft) => {
        try {
          const d = await api('/plugins/' + plugin.id + '/calendars/' + cal.id, { method: 'PATCH', body: draft });
          toast(cal.name + (d.now ? ' saved. Refilling it now.' : ' saved. It refills within a minute.'));
          await loadCalendars();
          followFill(cal.id, !!d.now).catch(() => {});
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
      placeSources=${placeSourcesFor(calendars, { id: null, pluginId: plugin.id })}
      create=${true}
      saveLabel=${'Add ' + decl.noun}
      onCancel=${onDone}
      onSave=${async (draft) => {
        try {
          const d = await api('/plugins/' + plugin.id + '/calendars', { method: 'POST', body: { name: name.trim() || null, settings: draft } });
          toast(d.now ? 'Added. Filling it now.' : 'Added. It fills within a minute.');
          await Promise.all([loadCalendars(), loadPlugins()]);
          refreshWindow();
          followFill(d.calendarId, !!d.now).catch(() => {});
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
 * On a calendar with a place of its own (a tide station): the other
 * plugins' calendars that use that place, each opening its settings or
 * unlinked (it keeps the place, fixed, and stops following), and one-click
 * buttons to add the ones it doesn't have yet.
 */
export function LinkedCalendars({ cal }) {
  const plugins = useStore((s) => s.plugins);
  const calendars = useStore((s) => s.calendars);
  const [busy, setBusy] = useState(false);
  if (!cal.place || cal.place.via != null) return null;
  const followers = followersOf(cal, calendars);
  const linkedPlugins = new Set(followers.map((f) => f.cal.pluginId));
  const offers = (plugins || []).filter((p) => p.enabled && p.ownCalendars && p.id !== cal.pluginId && locationKey(p) && !linkedPlugins.has(p.id));
  if (followers.length === 0 && offers.length === 0) return null;
  const place = cal.place.name || 'this place';
  const run = async (fn, ok) => {
    setBusy(true);
    try {
      await fn();
      toast(ok);
      await Promise.all([loadCalendars(), loadPlugins()]);
      refreshWindow();
    } catch (e) {
      toast(e.message || 'That didn\'t work', { error: true });
    } finally {
      setBusy(false);
    }
  };
  const add = (p) => run(
    async () => {
      const d = await api('/plugins/' + p.id + '/calendars', { method: 'POST', body: { settings: { [locationKey(p)]: { link: cal.id } } } });
      followFill(d.calendarId, !!d.now).catch(() => {});
    },
    p.name + ' added for ' + place + '.',
  );
  const unlink = (f) => run(
    () => api('/plugins/' + f.cal.pluginId + '/calendars/' + f.cal.id, { method: 'PATCH', body: { [f.key]: { name: cal.place.name || '', lat: cal.place.lat, lng: cal.place.lng } } }),
    f.cal.name + ' keeps ' + place + ' as its own place now.',
  );
  return html`<div class="bc-own-linked">
    <span class="bc-own-linked-head">Linked calendars<span class="bc-schema-help">${followers.length > 0
      ? 'Other calendars for ' + place + '. They follow this one if its place changes.'
      : 'Add calendars for ' + place + ' that follow this one if its place changes.'}</span></span>
    ${followers.map((f) => html`<div key=${f.cal.id} class="bc-own-linked-row">
      <button type="button" class="bc-own-chip" title="Open its settings" onClick=${() => openOwnCalendar(f.cal)}>
        <span class="bc-own-dot" style=${'background:' + f.cal.color}></span>${f.cal.name}
      </button>
      <button type="button" class="bc-link-btn" disabled=${busy} title=${'Keep ' + place + ' as its own place, and stop following this calendar'} onClick=${() => unlink(f)}>Unlink</button>
    </div>`)}
    ${offers.length > 0 && html`<div class="bc-own-linked-row">
      ${offers.map((p) => html`<button key=${p.id} type="button" class="bc-btn bc-own-offer" disabled=${busy}
        title=${'Add a ' + p.ownCalendars.noun + ' for ' + place} onClick=${() => add(p)}><${Icon} name="plus" size=${12} /> ${p.name}</button>`)}
    </div>`}
  </div>`;
}

/** On a plugin's card: its calendars, each opening to its own settings, and "Add". */
export function OwnCalendarsList({ plugin }) {
  const calendars = useStore((s) => s.calendars);
  const focus = useStore((s) => s.pluginCalendarFocus);
  const [open, setOpen] = useState(null);
  const [adding, setAdding] = useState(false);
  const openRef = useRef(null);
  const mine = (calendars || []).filter((c) => c.kind === 'plugin' && c.pluginId === plugin.id);
  // Arriving from one calendar's "Plugin settings" (or a linked calendar)
  // opens that calendar here and brings it into view, after the card's own
  // scroll.
  useEffect(() => {
    if (focus == null || !mine.some((c) => c.id === focus)) return;
    setOpen(focus);
    setAdding(false);
    set({ pluginCalendarFocus: null });
    requestAnimationFrame(() => requestAnimationFrame(() => { if (openRef.current) openRef.current.scrollIntoView({ block: 'start', behavior: 'smooth' }); }));
  }, [focus, mine.length]);
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
    ${mine.filter((c) => c.id === open).map((c) => html`<div key=${c.id} ref=${openRef} class="bc-own-open">
      <${OwnCalendarSettings} cal=${c} plugin=${plugin} />
      <${LinkedCalendars} cal=${c} />
    </div>`)}
  </div>`;
}

export function pluginFor(cal) {
  return (state.plugins || []).find((p) => p.id === cal.pluginId) || null;
}
