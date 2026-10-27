// Declarative settings renderer for plugin manifests (docs/plugins/prd-v1.md).
// The HOST renders every control from the schema — plugins ship data, never
// markup — so styling, accessibility, and sanitisation stay in one place.
// Field types: text, textarea, secret, number, select, checklist, toggle,
// location (PlaceInput), person. A select or checklist with `optionsFrom`
// offers what the plugin discovered (the server fills `options` in).

import { html, useState, useEffect } from '../../vendor/index.js';
import { PlaceInput } from './PlaceInput.js';
import { useStore } from './store.js';

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

function Field({ field, value, onChange }) {
  const people = useStore((s) => s.people);
  switch (field.type) {
    case 'secret':
      return html`<${SecretField} field=${field} value=${value} onChange=${onChange} />`;
    case 'number':
      return html`<input
        type="number" class="bc-schema-input"
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
    case 'location':
      // A location value is only valid once picked from the dropdown (the
      // server requires coordinates); typing alone just searches.
      return html`<${PlaceInput}
        value=${value ? (value.name || '') : ''}
        onText=${(t) => { if (!t) onChange(null); }}
        onPick=${(p) => onChange(p ? { name: p.name || '', lat: p.lat, lng: p.lng } : null)}
        placeholder="Search for a place…"
      />`;
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
 * Controlled schema form with its own draft + save button.
 * props: schema (manifest field list), values (current), onSave(draft) ->
 * Promise resolving truthy on success.
 */
export function SchemaForm({ schema, values, onSave, saveLabel = 'Save' }) {
  const [draft, setDraft] = useState(() => ({ ...values }));
  const [busy, setBusy] = useState(false);
  // Reloading another plugin replaces the values object too. Only a real
  // saved-value change should reset this form, not an unrelated list refresh.
  useEffect(() => { setDraft({ ...values }); }, [JSON.stringify(values)]);
  const dirty = (schema || []).some((f) => JSON.stringify(draft[f.key]) !== JSON.stringify(values[f.key]));

  const save = async () => {
    setBusy(true);
    try {
      const body = { ...draft };
      for (const field of schema || []) {
        if (field.type === 'secret' && (body[field.key] === '' || typeof body[field.key] === 'object' && body[field.key] !== null)) delete body[field.key];
        // Only changed fields go up: a discovered choice that hasn't loaded
        // yet must not be sent back and refused.
        if (JSON.stringify(body[field.key]) === JSON.stringify(values[field.key])) delete body[field.key];
      }
      const ok = await onSave(body);
      if (ok) setDraft((current) => Object.fromEntries(Object.entries(current).map(([key, value]) => [key, schema.some((f) => f.key === key && f.type === 'secret') && typeof value === 'string' ? { set: value !== '' } : value])));
    } finally {
      setBusy(false);
    }
  };

  if (!schema || schema.length === 0) return null;
  return html`<div class="bc-schema-form">
    ${schema.map((f) => {
      // A checklist is a group of checkboxes, so it can't sit inside one label.
      const Tag = f.type === 'checklist' ? 'div' : 'label';
      return html`<${Tag} key=${f.key} class="bc-schema-field">
        <span class="bc-schema-label">${f.label}${f.help && html`<span class="bc-schema-help">${f.help}</span>`}</span>
        <${Field} field=${f} value=${draft[f.key]} onChange=${(v) => setDraft({ ...draft, [f.key]: v })} />
      </${Tag}>`;
    })}
    <div class="bc-schema-actions">
      <button type="button" class="bc-btn bc-btn-primary" disabled=${busy || !dirty} onClick=${save}>${saveLabel}</button>
    </div>
  </div>`;
}
