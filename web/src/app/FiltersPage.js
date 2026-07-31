// Filters management: list, create (scope/type/pattern/fields/action),
// enable/disable toggle, delete. The server applies enabled filters to the
// events window and search; hide removes occurrences, dim marks them.

import { html, useState, useEffect } from '../../vendor/index.js';
import { useStore, set, state, toast, shallowEq } from './store.js';
import { api, refreshWindow } from './api.js';

const FIELD_OPTIONS = ['title', 'description', 'location'];

export function FiltersPage() {
  const { calendars, folders } = useStore(
    (s) => ({ calendars: s.calendars, folders: s.folders }),
    shallowEq,
  );
  const [filters, setFilters] = useState(null);
  const [scope, setScope] = useState('global');
  const [scopeId, setScopeId] = useState('');
  const [type, setType] = useState('keyword');
  const [pattern, setPattern] = useState('');
  const [fields, setFields] = useState({ title: true, description: true, location: true });
  const [action, setAction] = useState('hide');
  const [busy, setBusy] = useState(false);

  const load = async () => {
    try {
      const data = await api('/filters');
      setFilters(data.filters || []);
    } catch (e) {
      toast('Failed to load filters: ' + e.message, { error: true });
      setFilters([]);
    }
  };

  useEffect(() => { load(); }, []);

  const create = async (e) => {
    e.preventDefault();
    setBusy(true);
    const body = {
      scope, type, action,
      config: { pattern, fields: FIELD_OPTIONS.filter((f) => fields[f]) },
    };
    if (scope === 'calendar') body.scopeId = Number(scopeId || (calendars[0] && calendars[0].id));
    if (scope === 'folder') body.scopeId = Number(scopeId || (folders[0] && folders[0].id));
    try {
      await api('/filters', { method: 'POST', body });
      toast('Filter created', { undoable: true });
      setPattern('');
      await load();
      refreshWindow();
    } catch (err) {
      toast('Failed: ' + err.message, { error: true });
    }
    setBusy(false);
  };

  const toggle = async (f) => {
    try {
      await api('/filters/' + f.id, { method: 'PATCH', body: { enabled: !f.enabled } });
      toast(f.enabled ? 'Filter disabled' : 'Filter enabled', { undoable: true });
      await load();
      refreshWindow();
    } catch (err) {
      toast('Failed: ' + err.message, { error: true });
    }
  };

  const remove = async (f) => {
    try {
      await api('/filters/' + f.id, { method: 'DELETE' });
      toast('Filter deleted', { undoable: true });
      await load();
      refreshWindow();
    } catch (err) {
      toast('Failed: ' + err.message, { error: true });
    }
  };

  const scopeLabel = (f) => {
    if (f.scope === 'global') return 'Global';
    if (f.scope === 'folder') {
      const folder = state.folders.find((x) => x.id === f.scopeId);
      return 'Folder: ' + ((folder && folder.name) || f.scopeId);
    }
    const cal = state.calendars.find((x) => x.id === f.scopeId);
    return 'Calendar: ' + ((cal && cal.name) || f.scopeId);
  };

  return html`<div class="bc-page">
    <div class="bc-page-head">
      <h1>Filters</h1>
      <button type="button" class="bc-btn" onClick=${() => set({ route: 'calendar' })}>Back to calendar</button>
    </div>
    <p class="bc-page-note">Filters hide or dim matching events everywhere. Keyword matches as a case-insensitive substring; regex is a full pattern.</p>

    <form class="bc-filter-form" onSubmit=${create}>
      <select value=${scope} onChange=${(e) => { setScope(e.target.value); setScopeId(''); }} aria-label="Filter scope">
        <option value="global">Global</option>
        <option value="folder">Folder</option>
        <option value="calendar">Calendar</option>
      </select>
      ${scope === 'folder' && html`<select value=${scopeId} onChange=${(e) => setScopeId(e.target.value)} aria-label="Folder">
        ${folders.map((f) => html`<option key=${f.id} value=${f.id}>${f.name}</option>`)}
      </select>`}
      ${scope === 'calendar' && html`<select value=${scopeId} onChange=${(e) => setScopeId(e.target.value)} aria-label="Calendar">
        ${calendars.map((c) => html`<option key=${c.id} value=${c.id}>${c.name}</option>`)}
      </select>`}
      <select value=${type} onChange=${(e) => setType(e.target.value)} aria-label="Filter type">
        <option value="keyword">Keyword</option>
        <option value="regex">Regex</option>
      </select>
      <input
        placeholder=${type === 'regex' ? 'Pattern, e.g. karaoke|trivia' : 'Keyword, e.g. webinar'}
        value=${pattern} onInput=${(e) => setPattern(e.target.value)} required aria-label="Pattern"
      />
      <span class="bc-filter-fields" role="group" aria-label="Fields to match">
        ${FIELD_OPTIONS.map((f) => html`<label key=${f} class="bc-check">
          <input type="checkbox" checked=${fields[f]} onChange=${() => setFields({ ...fields, [f]: !fields[f] })} />
          <span>${f}</span>
        </label>`)}
      </span>
      <select value=${action} onChange=${(e) => setAction(e.target.value)} aria-label="Action">
        <option value="hide">Hide</option>
        <option value="dim">Dim</option>
      </select>
      <button type="submit" class="bc-btn bc-btn-primary" disabled=${busy || !pattern.trim()}>Add filter</button>
    </form>

    ${filters === null && html`<div class="bc-empty">Loading filters</div>`}
    ${filters !== null && filters.length === 0 && html`<div class="bc-empty">No filters yet</div>`}
    ${filters !== null && filters.length > 0 && html`<div class="bc-outfeed-list">
      ${filters.map((f) => html`<div key=${f.id} class="bc-outfeed-row${f.enabled ? '' : ' is-off'}">
        <label class="bc-check" title=${f.enabled ? 'Disable filter' : 'Enable filter'}>
          <input type="checkbox" checked=${f.enabled} onChange=${() => toggle(f)} aria-label=${'Toggle filter ' + f.config.pattern} />
        </label>
        <div class="bc-outfeed-main">
          <code class="bc-filter-pattern">${f.config.pattern}</code>
          <span class="bc-outfeed-scope">${f.type} · ${f.action} · ${scopeLabel(f)} · ${(f.config.fields || FIELD_OPTIONS).join(', ')}</span>
        </div>
        <button type="button" class="bc-btn bc-btn-danger" onClick=${() => remove(f)}>Delete</button>
      </div>`)}
    </div>`}
  </div>`;
}
