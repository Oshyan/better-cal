// Filters management: list, create/edit (scope/type/config/action),
// enable/disable toggle, delete. The server applies enabled filters to the
// events window and search; hide removes occurrences, dim marks them.
// Keyword/regex match inline; prompt filters are evaluated by the AI in the
// background (worker) and their cached verdicts applied on the next load.

import { html, useState, useEffect } from '../../vendor/index.js';
import { useStore, set, state, toast, shallowEq } from './store.js';
import { api, refreshWindow } from './api.js';

const FIELD_OPTIONS = ['title', 'description', 'location'];
const ALL_FIELDS = { title: true, description: true, location: true };

export function FiltersPage() {
  const { calendars, folders } = useStore(
    (s) => ({ calendars: s.calendars, folders: s.folders }),
    shallowEq,
  );
  const [filters, setFilters] = useState(null);
  const [editingId, setEditingId] = useState(null);
  const [scope, setScope] = useState('global');
  const [scopeId, setScopeId] = useState('');
  const [type, setType] = useState('keyword');
  const [pattern, setPattern] = useState('');
  const [fields, setFields] = useState({ ...ALL_FIELDS });
  const [prompt, setPrompt] = useState('');
  const [negativePrompt, setNegativePrompt] = useState('');
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

  const resetForm = () => {
    setEditingId(null);
    setPattern('');
    setPrompt('');
    setNegativePrompt('');
    setFields({ ...ALL_FIELDS });
  };

  const startEdit = (f) => {
    setEditingId(f.id);
    setScope(f.scope);
    setScopeId(f.scopeId ? String(f.scopeId) : '');
    setType(f.type);
    setAction(f.action);
    if (f.type === 'prompt') {
      setPrompt(f.config.prompt || '');
      setNegativePrompt(f.config.negativePrompt || '');
      setPattern('');
      setFields({ ...ALL_FIELDS });
    } else {
      setPattern(f.config.pattern || '');
      const on = f.config.fields || FIELD_OPTIONS;
      setFields(Object.fromEntries(FIELD_OPTIONS.map((x) => [x, on.includes(x)])));
      setPrompt('');
      setNegativePrompt('');
    }
  };

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    const config = type === 'prompt'
      ? { prompt, ...(negativePrompt.trim() ? { negativePrompt } : {}) }
      : { pattern, fields: FIELD_OPTIONS.filter((f) => fields[f]) };
    const body = { scope, type, action, config };
    if (scope === 'calendar') body.scopeId = Number(scopeId || (calendars[0] && calendars[0].id));
    if (scope === 'folder') body.scopeId = Number(scopeId || (folders[0] && folders[0].id));
    try {
      if (editingId !== null) {
        await api('/filters/' + editingId, { method: 'PATCH', body });
        toast('Filter updated', { undoable: true });
      } else {
        await api('/filters', { method: 'POST', body });
        toast(type === 'prompt' ? 'Filter created; evaluating in the background' : 'Filter created', { undoable: true });
      }
      resetForm();
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
    if (f.id === editingId) resetForm();
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

  const isPrompt = type === 'prompt';
  const canSubmit = isPrompt ? prompt.trim() : pattern.trim();

  return html`<div class="bc-page">
    <div class="bc-page-head">
      <h1>Filters</h1>
      <button type="button" class="bc-btn" onClick=${() => set({ route: 'calendar' })}>Back to calendar</button>
    </div>
    <p class="bc-page-note">Filters hide or dim matching events everywhere. Keyword matches as a case-insensitive substring; regex is a full pattern. Prompt filters describe what you want to keep in plain language; events that do not match are hidden or dimmed.</p>

    <form class="bc-filter-form" onSubmit=${submit}>
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
        <option value="prompt">Prompt (AI)</option>
      </select>
      ${!isPrompt && html`<input
        placeholder=${type === 'regex' ? 'Pattern, e.g. karaoke|trivia' : 'Keyword, e.g. webinar'}
        value=${pattern} onInput=${(e) => setPattern(e.target.value)} required aria-label="Pattern"
      />`}
      ${!isPrompt && html`<span class="bc-filter-fields" role="group" aria-label="Fields to match">
        ${FIELD_OPTIONS.map((f) => html`<label key=${f} class="bc-check">
          <input type="checkbox" checked=${fields[f]} onChange=${() => setFields({ ...fields, [f]: !fields[f] })} />
          <span>${f}</span>
        </label>`)}
      </span>`}
      ${isPrompt && html`<textarea
        placeholder="What to keep, e.g. dance and live music events at small venues"
        value=${prompt} onInput=${(e) => setPrompt(e.target.value)} required rows="2" aria-label="Prompt"
      ></textarea>`}
      ${isPrompt && html`<textarea
        placeholder="Optional: what to exclude, e.g. no DJ nights or cover bands"
        value=${negativePrompt} onInput=${(e) => setNegativePrompt(e.target.value)} rows="2" aria-label="Negative prompt"
      ></textarea>`}
      <select value=${action} onChange=${(e) => setAction(e.target.value)} aria-label="Action">
        <option value="hide">Hide</option>
        <option value="dim">Dim</option>
      </select>
      <button type="submit" class="bc-btn bc-btn-primary" disabled=${busy || !canSubmit}>
        ${editingId !== null ? 'Save filter' : 'Add filter'}
      </button>
      ${editingId !== null && html`<button type="button" class="bc-btn" onClick=${resetForm}>Cancel edit</button>`}
      ${isPrompt && html`<span class="bc-page-note bc-filter-hint">Prompt filters run in the background: new and updated feed events are scored within a few minutes of arriving, so results appear shortly rather than instantly. Non-matching events are ${action === 'hide' ? 'hidden' : 'dimmed'}; not-yet-scored events stay visible.</span>`}
    </form>

    ${filters === null && html`<div class="bc-empty">Loading filters</div>`}
    ${filters !== null && filters.length === 0 && html`<div class="bc-empty">No filters yet</div>`}
    ${filters !== null && filters.length > 0 && html`<div class="bc-outfeed-list">
      ${filters.map((f) => html`<div key=${f.id} class="bc-outfeed-row${f.enabled ? '' : ' is-off'}">
        <label class="bc-check" title=${f.enabled ? 'Disable filter' : 'Enable filter'}>
          <input type="checkbox" checked=${f.enabled} onChange=${() => toggle(f)} aria-label=${'Toggle filter ' + (f.type === 'prompt' ? f.config.prompt : f.config.pattern)} />
        </label>
        <div class="bc-outfeed-main">
          <code class="bc-filter-pattern">${f.type === 'prompt' ? f.config.prompt : f.config.pattern}</code>
          <span class="bc-outfeed-scope">${f.type} · ${f.action} · ${scopeLabel(f)}${f.type === 'prompt'
            ? (f.config.negativePrompt ? ' · not: ' + f.config.negativePrompt : '')
            : ' · ' + (f.config.fields || FIELD_OPTIONS).join(', ')}</span>
        </div>
        <button type="button" class="bc-btn" onClick=${() => startEdit(f)}>Edit</button>
        <button type="button" class="bc-btn bc-btn-danger" onClick=${() => remove(f)}>Delete</button>
      </div>`)}
    </div>`}
  </div>`;
}
