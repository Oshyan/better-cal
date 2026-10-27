// Declarative settings renderer for plugin manifests (docs/plugins/prd-v1.md).
// The HOST renders every control from the schema — plugins ship data, never
// markup — so styling, accessibility, and sanitisation stay in one place.
// Field types: text, textarea, secret, number, select, checklist, toggle,
// time, location (PlaceInput), person. A select or checklist with
// `optionsFrom` offers what the plugin discovered (the server fills
// `options` in); a `searchable` select asks the server for matches instead,
// for lists too long to send (thousands of tide stations). `showIf` hides a
// field until another has a given value.

import { html, useState, useEffect, useRef } from '../../vendor/index.js';
import { PlaceInput } from './PlaceInput.js';
import { useStore } from './store.js';
import { api } from './api.js';
import { holdReload } from './drafts.js';
import { Icon } from '../ui/icons.js';

const PENDING = 'Choices appear here after the plugin connects (within a minute of saving).';
const NONE = 'None to choose from.';
const emptyText = (field) => (field.optionsLoaded ? field.emptyText || NONE : field.pendingText || PENDING);

const optionPairs = (options) => (options || []).map((o) => (typeof o === 'object'
  ? { value: String(o.value), label: String(o.label ?? o.value) }
  : { value: String(o), label: String(o) }));

// A credential field you can check: hidden by default, Show reveals what
// you typed, and the count says whether a paste landed (or landed twice).
function SecretField({ field, value, onChange }) {
  const [shown, setShown] = useState(false);
  const typed = typeof value === 'string' ? value : '';
  const state = value && typeof value === 'object' && value.set ? 'Set' : value === null ? 'Will be cleared' : typed ? 'Will be replaced' : 'Not set';
  return html`<span class="bc-schema-secret">
    <span>${state}</span>
    <input type=${shown ? 'text' : 'password'} class="bc-schema-input" autocomplete="new-password" spellcheck="false" aria-label=${field.label + ' replacement'}
      value=${typed} onInput=${(e) => onChange(e.target.value)} />
    ${typed && html`<span class="bc-schema-count">${typed.length} characters</span>`}
    ${typed && html`<button type="button" class="bc-link-btn" onClick=${() => setShown(!shown)}>${shown ? 'Hide' : 'Show'}</button>`}
    <button type="button" class="bc-link-btn" onClick=${() => onChange(null)}>Clear</button>
  </span>`;
}

function ChecklistField({ field, value, onChange }) {
  const opts = optionPairs(field.options);
  const picked = new Set(Array.isArray(value) ? value.map(String) : []);
  if (opts.length === 0) return html`<span class="bc-schema-pending">${emptyText(field)}</span>`;
  const toggle = (v, on) => {
    const next = new Set(picked);
    if (on) next.add(v); else next.delete(v);
    onChange(opts.map((o) => o.value).filter((x) => next.has(x)));
  };
  return html`<span class="bc-schema-checklist">
    ${opts.map((o) => html`<label key=${o.value} class="bc-check">
      <input type="checkbox" checked=${picked.has(o.value)} onChange=${(e) => toggle(o.value, e.target.checked)} />
      <span>${o.label}</span>
    </label>`)}
  </span>`;
}

function SelectField({ field, value, onChange }) {
  const opts = optionPairs(field.options);
  if (field.optionsFrom && opts.length === 0) return html`<span class="bc-schema-pending">${emptyText(field)}</span>`;
  // A saved choice the plugin no longer finds (a renamed field) stays
  // visible, so it isn't silently swapped for the first option.
  const known = value == null || value === '' || opts.some((o) => o.value === String(value));
  return html`<select class="bc-schema-input" value=${value ?? ''} onChange=${(e) => onChange(e.target.value === '' && field.optionsFrom ? null : e.target.value)}>
    ${field.optionsFrom && html`<option value="">(none)</option>`}
    ${!known && html`<option value=${value}>${value} (no longer found)</option>`}
    ${opts.map((o) => html`<option key=${o.value} value=${o.value}>${o.label}</option>`)}
  </select>`;
}

// Type to search a long list the plugin published; the server returns
// the best matches. The chosen value's label is looked up once, so a saved
// station shows its name rather than its id.
function SearchSelectField({ field, value, onChange, pluginId }) {
  const [query, setQuery] = useState(null);
  const [label, setLabel] = useState('');
  const [matches, setMatches] = useState([]);
  const [active, setActive] = useState(0);
  const seq = useRef(0);
  // null while the chosen value's label loads: the field says so rather
  // than showing a bare id or looking empty.
  useEffect(() => {
    setLabel(value == null || value === '' || !pluginId ? '' : null);
    if (value == null || value === '' || !pluginId) return;
    api('/plugins/' + pluginId + '/options/' + field.key + '?' + new URLSearchParams({ value: String(value) }))
      .then((d) => setLabel(d.options && d.options[0] ? d.options[0].label : String(value) + ' (no longer found)'))
      .catch(() => setLabel(String(value)));
  }, [value, pluginId, field.key]);
  useEffect(() => {
    if (query === null || query.trim().length < 2) { setMatches([]); return undefined; }
    const n = ++seq.current;
    const t = setTimeout(() => {
      api('/plugins/' + pluginId + '/options/' + field.key + '?' + new URLSearchParams({ q: query.trim() }))
        .then((d) => { if (n === seq.current) { setMatches(d.options || []); setActive(0); } })
        .catch(() => {});
    }, 200);
    return () => clearTimeout(t);
  }, [query]);
  if (!field.optionsLoaded) return html`<span class="bc-schema-pending">${emptyText(field)}</span>`;
  const pick = (o) => { onChange(o.value); setLabel(o.label); setQuery(null); setMatches([]); };
  const onKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(Math.min(active + 1, matches.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(active - 1, 0)); }
    else if (e.key === 'Enter' && matches[active]) { e.preventDefault(); pick(matches[active]); }
    else if (e.key === 'Escape' && query !== null) { e.stopPropagation(); setQuery(null); setMatches([]); }
  };
  return html`<span class="bc-schema-search">
    <input type="text" class="bc-schema-input" role="combobox" aria-expanded=${matches.length > 0} autocomplete="off" spellcheck="false"
      aria-label=${field.label}
      placeholder=${label === null ? 'Loading…' : field.placeholder || 'Type to search ' + field.optionCount + ' choices'}
      value=${query !== null ? query : label || ''}
      onFocus=${(e) => e.target.select()}
      onInput=${(e) => setQuery(e.target.value)}
      onBlur=${() => setTimeout(() => { setQuery(null); setMatches([]); }, 150)}
      onKeyDown=${onKey} />
    ${matches.length > 0 && html`<ul class="bc-schema-matches" role="listbox">
      ${matches.map((o, i) => html`<li key=${o.value} role="option" aria-selected=${i === active} class=${i === active ? 'is-active' : ''}
        onMouseDown=${(e) => { e.preventDefault(); pick(o); }}><span>${o.label}</span><small>${o.value}</small></li>`)}
    </ul>`}
    ${query !== null && query.trim().length >= 2 && matches.length === 0 && html`<span class="bc-schema-pending">No matches</span>`}
  </span>`;
}

// A place picked here, or "same place as" another plugin calendar (a sun
// calendar following a tide calendar's station). The link is resolved each
// time the plugin runs, so it follows when the other calendar changes.
// Unlinking keeps the place it has now, as its own.
function LocationField({ value, onChange, placeSources }) {
  const calendars = useStore((s) => s.calendars);
  const [choosing, setChoosing] = useState(false);
  const linked = value && typeof value === 'object' && value.link != null;
  const sources = placeSources || [];
  const target = linked ? (calendars || []).find((c) => c.id === value.link) : null;
  const label = (c) => {
    const place = c.place && c.place.name;
    return place && !c.name.includes(place) ? c.name + ' (' + place + ')' : c.name;
  };
  const picker = html`<select class="bc-schema-input" aria-label="Same place as another calendar"
    value=${linked ? String(value.link) : ''}
    onChange=${(e) => { setChoosing(false); if (e.target.value) onChange({ link: Number(e.target.value) }); }}>
    <option value="">${linked ? 'Choose a calendar…' : 'Or the same place as…'}</option>
    ${sources.map((c) => html`<option key=${c.id} value=${String(c.id)}>${label(c)}</option>`)}
  </select>`;
  if (linked) {
    const unlink = () => onChange(target && target.place ? { name: target.place.name || '', lat: target.place.lat, lng: target.place.lng } : null);
    return html`<span class="bc-schema-location">
      <span class="bc-schema-linked"><${Icon} name="link" size=${12} />${target ? html`Same place as <strong>${label(target)}</strong>` : 'Its linked calendar is gone. Pick a place.'}</span>
      ${choosing ? picker : sources.length > 0 && html`<button type="button" class="bc-link-btn" onClick=${() => setChoosing(true)}>Change</button>`}
      <button type="button" class="bc-link-btn" title="Keep this place as its own, and stop following the other calendar" onClick=${unlink}>Unlink</button>
    </span>`;
  }
  return html`<span class="bc-schema-location">
    <${PlaceInput}
      value=${value ? (value.name || '') : ''}
      onText=${(t) => { if (!t) onChange(null); }}
      onPick=${(p) => onChange(p ? { name: p.name || '', lat: p.lat, lng: p.lng } : null)}
      placeholder="Search for a place…"
    />
    ${sources.length > 0 && picker}
  </span>`;
}

function Field({ field, value, onChange, pluginId, placeSources }) {
  const people = useStore((s) => s.people);
  if (field.type === 'select' && field.searchable) return html`<${SearchSelectField} field=${field} value=${value} onChange=${onChange} pluginId=${pluginId} />`;
  switch (field.type) {
    case 'secret':
      return html`<${SecretField} field=${field} value=${value} onChange=${onChange} />`;
    case 'number':
      return html`<input
        type="number" class="bc-schema-input bc-schema-number"
        min=${field.min} max=${field.max} step=${field.step || 1}
        value=${value ?? ''}
        onInput=${(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
      />`;
    case 'select':
      return html`<${SelectField} field=${field} value=${value} onChange=${onChange} />`;
    case 'checklist':
      return html`<${ChecklistField} field=${field} value=${value} onChange=${onChange} />`;
    case 'toggle':
      return html`<label class="bc-check bc-schema-toggle">
        <input type="checkbox" checked=${!!value} onChange=${(e) => onChange(e.target.checked)} />
        <span>${value ? 'On' : 'Off'}</span>
      </label>`;
    case 'time':
      return html`<input type="time" class="bc-schema-input bc-schema-time" value=${value ?? ''}
        onInput=${(e) => onChange(e.target.value === '' ? null : e.target.value)} />`;
    case 'location':
      // A location value is only valid once picked from the dropdown (the
      // server requires coordinates); typing alone just searches.
      return html`<${LocationField} value=${value} onChange=${onChange} placeSources=${placeSources} />`;
    case 'person':
      return html`<select class="bc-schema-input" value=${value ?? ''} onChange=${(e) => onChange(e.target.value || null)}>
        <option value="">(none)</option>
        ${people.map((p) => html`<option key=${p.id} value=${p.name}>${p.name}</option>`)}
      </select>`;
    case 'textarea':
      // The schema has no list type, so list-shaped settings (one item per
      // line) live here. A single-line input for a ten-line wishlist is why
      // authors were splitting one setting across four fields.
      return html`<textarea
        class="bc-schema-input bc-schema-area" rows=${field.rows || 6}
        maxLength=${field.maxLength || 10000}
        value=${value ?? ''}
        onInput=${(e) => onChange(e.target.value)}
      ></textarea>`;
    default:
      return html`<input
        type="text" class="bc-schema-input" maxLength=${field.maxLength || 500}
        value=${value ?? ''}
        onInput=${(e) => onChange(e.target.value)}
      />`;
  }
}

/**
 * The form's rows: shown fields, where a field marked `join` shares the
 * line of the shown field before it ("Not before [ ] Not after [ ]"), and
 * a section heading goes with the first shown field of its section.
 */
function rows(schema, draft) {
  const out = [];
  let section = null;
  let sectionShown = true;
  for (const f of schema || []) {
    if (f.section) { section = f.section; sectionShown = false; }
    if (!fieldShown(f, draft)) continue;
    const prev = out[out.length - 1];
    if (f.join && prev && !f.section) { prev.fields.push(f); continue; }
    out.push({ fields: [f], heading: !sectionShown ? section : null });
    sectionShown = true;
  }
  return out;
}

/** Is this field shown, given the form's current values? showIf: {key: value | [values]}. */
export function fieldShown(field, draft) {
  if (!field.showIf) return true;
  return Object.entries(field.showIf).every(([k, want]) => (Array.isArray(want) ? want : [want]).some((w) => JSON.stringify(draft[k] ?? null) === JSON.stringify(w)));
}

/**
 * Controlled schema form with its own draft + save button.
 * props: schema (manifest field list), values (current), onSave(draft) ->
 * Promise resolving truthy on success. pluginId: for searchable fields.
 * placeSources: plugin calendars a location may follow. create: a new
 * calendar's form (every value is sent, Save is on from the start).
 */
export function SchemaForm({ schema, values, onSave, saveLabel = 'Save', pluginId = null, placeSources = null, create = false, onCancel = null, children = null }) {
  const [draft, setDraft] = useState(() => ({ ...values }));
  const [busy, setBusy] = useState(false);
  // Reloading another plugin replaces the values object too. Only a real
  // saved-value change should reset this form, not an unrelated list refresh.
  useEffect(() => { setDraft({ ...values }); }, [JSON.stringify(values)]);
  const changed = (schema || []).some((f) => JSON.stringify(draft[f.key]) !== JSON.stringify(values[f.key]));
  const dirty = create || changed;
  // Unsaved changes hold the app's background update reload (drafts.js).
  const holdKey = useRef({});
  useEffect(() => { holdReload(holdKey.current, changed); }, [changed]);
  useEffect(() => () => holdReload(holdKey.current, false), []);

  const save = async () => {
    setBusy(true);
    try {
      const body = { ...draft };
      for (const field of schema || []) {
        if (field.type === 'secret' && (body[field.key] === '' || typeof body[field.key] === 'object' && body[field.key] !== null)) delete body[field.key];
        // Only changed fields go up: a discovered choice that hasn't loaded
        // yet must not be sent back and refused.
        if (!create && JSON.stringify(body[field.key]) === JSON.stringify(values[field.key])) delete body[field.key];
      }
      const ok = await onSave(body);
      if (ok) setDraft((current) => Object.fromEntries(Object.entries(current).map(([key, value]) => [key, schema.some((f) => f.key === key && f.type === 'secret') && typeof value === 'string' ? { set: value !== '' } : value])));
    } finally {
      setBusy(false);
    }
  };

  if (!schema || schema.length === 0) return null;
  return html`<div class="bc-schema-form">
    ${children}
    ${rows(schema, draft).map(({ fields: [f, ...joined], heading }) => {
      // A checklist is a group of checkboxes, so it can't sit inside one label.
      const Tag = joined.length > 0 || f.type === 'checklist' || f.type === 'location' ? 'div' : 'label';
      const v = draft[f.key];
      // A secret is there once stored ({set: true}) or typed.
      const missing = f.required && (v == null || v === '' || (typeof v === 'object' && !Array.isArray(v) && v.set === false));
      const control = (field) => html`<${Field} field=${field} value=${draft[field.key]} pluginId=${pluginId} placeSources=${placeSources} onChange=${(v) => setDraft({ ...draft, [field.key]: v })} />`;
      return html`
        ${heading && html`<h4 key=${'s-' + f.key} class="bc-schema-section">${heading}</h4>`}
        <${Tag} key=${f.key} class="bc-schema-field">
          <span class="bc-schema-label">${f.label}${missing && html`<span class="bc-schema-required">Required</span>`}${f.help && html`<span class="bc-schema-help">${f.help}</span>`}</span>
          ${joined.length === 0 ? control(f) : html`<span class="bc-schema-joined">
            ${control(f)}
            ${joined.map((j) => html`<label key=${j.key} class="bc-schema-join"><span class="bc-schema-joinlabel">${j.label}</span>${control(j)}</label>`)}
          </span>`}
        </${Tag}>`;
    })}
    <div class="bc-schema-actions">
      <button type="button" class="bc-btn bc-btn-primary" disabled=${busy || !dirty} onClick=${save}>${saveLabel}</button>
      ${onCancel && html`<button type="button" class="bc-btn" disabled=${busy} onClick=${onCancel}>Cancel</button>`}
    </div>
  </div>`;
}
