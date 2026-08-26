// Per-event plugin data in the detail view (C7/C8).
//
// Fetched when an event is OPENED, never as part of the events window — the
// window carries thousands of occurrences and this data would undo its whole
// payload budget. That separation is the reason the endpoint exists.
//
// Two things render here per plugin: the controls a plugin declares in its
// manifest `eventSettings` (the user answers them; the host renders them, so
// plugins ship no markup), and any read-only values the plugin computed for
// this event.

import { html, useState, useEffect } from '../../vendor/index.js';
import { useStore, toast } from './store.js';
import { api } from './api.js';
import { SchemaForm } from './SchemaForm.js';
import { Icon } from '../ui/icons.js';

// A computed value a plugin wrote for this event, rendered generically: we
// cannot know a plugin's shapes, so scalars print, objects list their fields.
function ComputedValue({ value }) {
  if (value === null || value === undefined) return null;
  if (typeof value !== 'object') return html`<span class="bc-epd-value">${String(value)}</span>`;
  if (Array.isArray(value)) return html`<span class="bc-epd-value">${value.join(', ')}</span>`;
  return html`<span class="bc-epd-value">
    ${Object.entries(value).map(([k, v]) => html`<span key=${k} class="bc-epd-pair">
      <span class="bc-epd-k">${k}</span> ${typeof v === 'object' ? JSON.stringify(v) : String(v)}
    </span>`)}
  </span>`;
}

export function EventPluginData({ eventId, readOnly }) {
  const plugins = useStore((s) => s.plugins);
  const [data, setData] = useState(null);

  useEffect(() => {
    let live = true;
    setData(null);
    if (!eventId) return undefined;
    api('/events/' + eventId + '/plugin-data')
      .then((d) => { if (live) setData(d.data || {}); })
      .catch(() => { if (live) setData({}); });
    return () => { live = false; };
  }, [eventId]);

  // Only plugins that are enabled AND have something to say about this event:
  // either controls to offer or values they already computed.
  const relevant = (plugins || []).filter((p) => {
    if (!p.enabled) return false;
    const hasControls = (p.eventSettingsSchema || []).length > 0;
    const hasData = data && data[p.id] && Object.keys(data[p.id]).length > 0;
    return hasControls || hasData;
  });
  if (data === null || relevant.length === 0) return null;

  return html`${relevant.map((p) => {
    const mine = data[p.id] || {};
    const controlKeys = new Set((p.eventSettingsSchema || []).map((f) => f.key));
    const computed = Object.entries(mine).filter(([k]) => !controlKeys.has(k));
    return html`<div key=${p.id} class="bc-detail-section bc-epd">
      <div class="bc-detail-label"><${Icon} name="plugins" size=${11} /> ${p.name}</div>
      ${computed.length > 0 && html`<div class="bc-epd-computed">
        ${computed.map(([k, v]) => html`<div key=${k} class="bc-epd-row">
          <span class="bc-epd-key">${k}</span><${ComputedValue} value=${v} />
        </div>`)}
      </div>`}
      ${!readOnly && (p.eventSettingsSchema || []).length > 0 && html`<${SchemaForm}
        schema=${p.eventSettingsSchema}
        values=${mine}
        saveLabel="Save"
        onSave=${async (draft) => {
          try {
            await api('/events/' + eventId + '/plugin-data/' + p.id, { method: 'PATCH', body: draft });
            setData({ ...data, [p.id]: { ...mine, ...draft } });
            toast(p.name + ' updated for this event');
            return true;
          } catch (e) {
            toast(e.message || 'Save failed', { error: true });
            return false;
          }
        }}
      />`}
    </div>`;
  })}`;
}
