// Declarative settings renderer for plugin manifests (docs/plugins/prd-v1.md).
// The HOST renders every control from the schema — plugins ship data, never
// markup — so styling, accessibility, and sanitisation stay in one place.
// Field types: text, textarea, number, select, toggle, location (PlaceInput),
// person.

import { html, useState, useEffect } from '../../vendor/index.js';
import { PlaceInput } from './PlaceInput.js';
import { useStore } from './store.js';

function Field({ field, value, onChange }) {
  const people = useStore((s) => s.people);
  switch (field.type) {
    case 'secret':
      return html`<span>
        <span>${value && typeof value === 'object' && value.set ? 'Set' : value === null ? 'Will be cleared' : typeof value === 'string' && value ? 'Will be replaced' : 'Not set'}</span>
        <input type="password" class="bc-schema-input" autocomplete="new-password" aria-label=${field.label + ' replacement'}
          value=${typeof value === 'string' ? value : ''} onInput=${(e) => onChange(e.target.value)} />
        <button type="button" class="bc-link-btn" onClick=${() => onChange(null)}>Clear</button>
      </span>`;
    case 'number':
      return html`<input
        type="number" class="bc-schema-input"
        min=${field.min} max=${field.max} step=${field.step || 1}
        value=${value ?? ''}
        onInput=${(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
      />`;
    case 'select':
      return html`<select class="bc-schema-input" value=${value ?? ''} onChange=${(e) => onChange(e.target.value)}>
        ${(field.options || []).map((o) => {
          const v = typeof o === 'object' ? o.value : o;
          const label = typeof o === 'object' ? (o.label ?? o.value) : o;
          return html`<option key=${v} value=${v}>${label}</option>`;
        })}
      </select>`;
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
      }
      const ok = await onSave(body);
      if (ok) setDraft((current) => Object.fromEntries(Object.entries(current).map(([key, value]) => [key, schema.some((f) => f.key === key && f.type === 'secret') && typeof value === 'string' ? { set: value !== '' } : value])));
    } finally {
      setBusy(false);
    }
  };

  if (!schema || schema.length === 0) return null;
  return html`<div class="bc-schema-form">
    ${schema.map((f) => html`<label key=${f.key} class="bc-schema-field">
      <span class="bc-schema-label">${f.label}</span>
      <${Field} field=${f} value=${draft[f.key]} onChange=${(v) => setDraft({ ...draft, [f.key]: v })} />
    </label>`)}
    <div class="bc-schema-actions">
      <button type="button" class="bc-btn bc-btn-primary" disabled=${busy || !dirty} onClick=${save}>${saveLabel}</button>
    </div>
  </div>`;
}
