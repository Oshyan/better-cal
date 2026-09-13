// Filters management: list, create/edit (scope/type/config/action),
// enable/disable toggle, delete. The server applies enabled filters to the
// events window and search; hide removes occurrences, dim mutes them,
// highlight emphasizes them with an accent ring. Keyword/regex match inline
// (over title/description/location and, opt-in, tag names); prompt filters
// are evaluated by the AI in the background (worker) and their cached
// verdicts applied on the next load. Form is type-first: pick the filter
// type, then the relevant fields show. The top form only creates; editing
// expands the same form inline beneath the filter's own row (one at a time,
// Esc cancels).

import { html, useState, useEffect } from '../../vendor/index.js';
import { useStore, state, toast, shallowEq } from './store.js';
import { api, refreshWindow } from './api.js';
import { PageShell, EmptyState, Skeleton } from './PageShell.js';
import { PALETTE } from '../lib/color.js';
import { fmtSince } from '../lib/since.js';

const FIELD_OPTIONS = ['title', 'description', 'location', 'tags'];
// Default selection mirrors the server default: tags is opt-in.
const DEFAULT_FIELDS = ['title', 'description', 'location'];
const ALL_FIELDS = { title: true, description: true, location: true, tags: false };
const TYPE_LABELS = { keyword: 'Keyword', regex: 'Regex', prompt: 'AI prompt' };
const ACTION_LABELS = { hide: 'Hides', dim: 'Dims', highlight: 'Highlights' };

// One form for both places: the create card at the top (no initial) and the
// inline editor beneath a row (initial = the filter being edited).
function FilterForm({ calendars, folders, initial, inline, busy, onSave, onCancel }) {
  const [scope, setScope] = useState(initial ? initial.scope : 'global');
  const [scopeId, setScopeId] = useState(initial && initial.scopeId ? String(initial.scopeId) : '');
  const [type, setType] = useState(initial ? initial.type : 'keyword');
  const [action, setAction] = useState(initial ? initial.action : 'hide');
  const [pattern, setPattern] = useState(initial && initial.type !== 'prompt' ? (initial.config.pattern || '') : '');
  const [fields, setFields] = useState(() => (initial && initial.type !== 'prompt'
    ? Object.fromEntries(FIELD_OPTIONS.map((x) => [x, (initial.config.fields || DEFAULT_FIELDS).includes(x)]))
    : { ...ALL_FIELDS }));
  const [prompt, setPrompt] = useState(initial && initial.type === 'prompt' ? (initial.config.prompt || '') : '');
  const [negativePrompt, setNegativePrompt] = useState(initial && initial.type === 'prompt' ? (initial.config.negativePrompt || '') : '');
  const [color, setColor] = useState((initial && initial.config && initial.config.color) || '');

  const isPrompt = type === 'prompt';
  const canSubmit = isPrompt ? prompt.trim() : pattern.trim();

  const submit = (e) => {
    e.preventDefault();
    const config = isPrompt
      ? { prompt, ...(negativePrompt.trim() ? { negativePrompt } : {}) }
      : { pattern, fields: FIELD_OPTIONS.filter((f) => fields[f]) };
    // Only meaningful for highlight, but kept on the object whatever the
    // action is so flipping to Dim and back does not lose the choice.
    if (color) config.color = color;
    const body = { scope, type, action, config };
    if (scope === 'calendar') body.scopeId = Number(scopeId || (calendars[0] && calendars[0].id));
    if (scope === 'folder') body.scopeId = Number(scopeId || (folders[0] && folders[0].id));
    onSave(body);
  };

  return html`<form class="bc-mgmt-form${inline ? ' is-inline' : ''}" onSubmit=${submit}>
    <div class="bc-form-row">
      <label class="bc-field">
        <span>Type</span>
        <select value=${type} onChange=${(e) => setType(e.target.value)}>
          <option value="keyword">Keyword</option>
          <option value="regex">Regex</option>
          <option value="prompt">AI prompt</option>
        </select>
      </label>
      ${!isPrompt && html`<label class="bc-field grow">
        <span>${type === 'regex' ? 'Pattern (case-insensitive regex)' : 'Keyword (case-insensitive)'}</span>
        <input
          placeholder=${type === 'regex' ? 'e.g. karaoke|trivia' : 'e.g. webinar'}
          value=${pattern} onInput=${(e) => setPattern(e.target.value)} required
        />
      </label>`}
      ${!isPrompt && html`<div class="bc-field">
        <span>Match in</span>
        <span class="bc-filter-fields" role="group" aria-label="Fields to match">
          ${FIELD_OPTIONS.map((f) => html`<label key=${f} class="bc-check">
            <input type="checkbox" checked=${fields[f]} onChange=${() => setFields({ ...fields, [f]: !fields[f] })} />
            <span>${f}</span>
          </label>`)}
        </span>
      </div>`}
    </div>
    ${isPrompt && html`<div class="bc-form-row">
      <label class="bc-field grow">
        <span>Keep events that match</span>
        <textarea
          placeholder="e.g. dance and live music events at small venues"
          value=${prompt} onInput=${(e) => setPrompt(e.target.value)} required rows="2"
        ></textarea>
      </label>
      <label class="bc-field grow">
        <span>Exclude (optional)</span>
        <textarea
          placeholder="e.g. no DJ nights or cover bands"
          value=${negativePrompt} onInput=${(e) => setNegativePrompt(e.target.value)} rows="2"
        ></textarea>
      </label>
    </div>`}
    <div class="bc-form-row">
      <label class="bc-field">
        <span>Applies to</span>
        <select value=${scope} onChange=${(e) => { setScope(e.target.value); setScopeId(''); }}>
          <option value="global">Everything</option>
          <option value="folder">One folder</option>
          <option value="calendar">One calendar</option>
        </select>
      </label>
      ${scope === 'folder' && html`<label class="bc-field">
        <span>Folder</span>
        <select value=${scopeId} onChange=${(e) => setScopeId(e.target.value)}>
          ${folders.map((f) => html`<option key=${f.id} value=${f.id}>${f.name}</option>`)}
        </select>
      </label>`}
      ${scope === 'calendar' && html`<label class="bc-field">
        <span>Calendar</span>
        <select value=${scopeId} onChange=${(e) => setScopeId(e.target.value)}>
          ${calendars.map((c) => html`<option key=${c.id} value=${c.id}>${c.name}</option>`)}
        </select>
      </label>`}
      <label class="bc-field">
        <span>Action</span>
        <select value=${action} onChange=${(e) => setAction(e.target.value)}>
          <option value="hide">Hide matching events</option>
          <option value="dim">Dim matching events</option>
          <option value="highlight">Highlight matching events</option>
        </select>
      </label>
      ${action === 'highlight' && html`<label class="bc-field bc-hlcolor-field">
        <span>Highlight colour</span>
        <div class="bc-hlcolor" role="radiogroup" aria-label="Highlight colour">
          <button
            type="button" role="radio" aria-checked=${!color}
            class="bc-hlcolor-swatch is-default${!color ? ' is-sel' : ''}"
            title="Default (accent)" onClick=${() => setColor('')}
          ></button>
          ${PALETTE.map((c) => html`<button
            key=${c} type="button" role="radio" aria-checked=${color === c}
            class="bc-hlcolor-swatch${color === c ? ' is-sel' : ''}"
            style=${`--sw:${c}`} title=${c} aria-label=${'Highlight colour ' + c}
            onClick=${() => setColor(c)}
          ></button>`)}
        </div>
      </label>`}
      <div class="bc-field bc-form-actions">
        <span aria-hidden="true"> </span>
        <div class="bc-form-btnrow">
          <button type="submit" class="bc-btn bc-btn-primary" disabled=${busy || !canSubmit}>
            ${inline ? 'Save' : 'Add filter'}
          </button>
          ${inline && html`<button type="button" class="bc-btn" onClick=${onCancel}>Cancel</button>`}
        </div>
      </div>
    </div>
    ${isPrompt && html`<p class="bc-page-note bc-filter-hint">Prompt filters run in the background: new and updated feed events are scored within a few minutes of arriving, so results appear shortly rather than instantly. Non-matching events are ${action === 'hide' ? 'hidden' : action === 'dim' ? 'dimmed' : 'highlighted'}; not-yet-scored events stay visible.</p>`}
  </form>`;
}

export function FiltersPage() {
  const { calendars, folders } = useStore(
    (s) => ({ calendars: s.calendars, folders: s.folders }),
    shallowEq,
  );
  const health = useStore((s) => s.systemHealth);
  const [filters, setFilters] = useState(null);
  const [editingId, setEditingId] = useState(null);
  const [busy, setBusy] = useState(false);
  const [createSeq, setCreateSeq] = useState(0); // remounts the create form after a successful add

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

  // Esc anywhere cancels the inline edit (capture, so grid hotkeys never see it).
  useEffect(() => {
    if (editingId === null) return undefined;
    const onKey = (e) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        setEditingId(null);
      }
    };
    document.addEventListener('keydown', onKey, true);
    return () => document.removeEventListener('keydown', onKey, true);
  }, [editingId]);

  const create = async (body) => {
    setBusy(true);
    try {
      await api('/filters', { method: 'POST', body });
      toast(body.type === 'prompt' ? 'Filter created; evaluating in the background' : 'Filter created', { undoable: true });
      setCreateSeq((n) => n + 1);
      await load();
      refreshWindow();
    } catch (err) {
      toast('Failed: ' + err.message, { error: true });
    }
    setBusy(false);
  };

  const saveEdit = async (id, body) => {
    setBusy(true);
    try {
      await api('/filters/' + id, { method: 'PATCH', body });
      toast('Filter updated', { undoable: true });
      setEditingId(null);
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
    if (f.id === editingId) setEditingId(null);
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

  const focusForm = () => {
    const el = document.querySelector('.bc-mgmt-form select');
    if (el) el.focus();
  };

  const evalFailing = ((health && health.rows) || []).find((r) => r.subject === 'job:filter_eval' && r.status === 'failing');
  return html`<${PageShell}
    title="Filters"
    note="Filters hide, dim, or highlight matching events everywhere: in every view and in search."
  >
    ${evalFailing && html`<div class="bc-syswarn" role="alert">
      Prompt filters are not being evaluated (failing since ${fmtSince(evalFailing.firstFailedAt)}${evalFailing.lastError ? ': ' + evalFailing.lastError : ''}). Keyword filters still apply; events a prompt filter would hide may be showing.
    </div>`}
    <${FilterForm}
      key=${'create' + createSeq}
      calendars=${calendars} folders=${folders}
      busy=${busy} onSave=${create}
    />

    ${filters === null && html`<${Skeleton} rows=${4} />`}
    ${filters !== null && filters.length === 0 && html`<${EmptyState}
      text="No filters yet. A filter hides, dims, or highlights events that match a keyword, a regex, or a plain-language description."
      actionLabel="Create your first filter" onAction=${focusForm}
    />`}
    ${filters !== null && filters.length > 0 && html`<div class="bc-card-list">
      ${filters.map((f) => html`<div key=${f.id} class="bc-card-item">
        <div class="bc-card${f.enabled ? '' : ' is-off'}${f.id === editingId ? ' is-editing' : ''}">
          <div class="bc-card-main">
            <div class="bc-card-badges">
              <span class="bc-badge bc-badge-type">${TYPE_LABELS[f.type] || f.type}</span>
              <span class="bc-badge bc-badge-action">${ACTION_LABELS[f.action] || f.action}</span>
              <span class="bc-badge">${scopeLabel(f)}</span>
            </div>
            <div class="bc-card-body">${f.type === 'prompt' ? f.config.prompt : f.config.pattern}</div>
            ${f.type === 'prompt' && f.config.negativePrompt && html`<div class="bc-card-sub">Excludes: ${f.config.negativePrompt}</div>`}
            ${f.type !== 'prompt' && html`<div class="bc-card-sub">Matches in ${(f.config.fields || DEFAULT_FIELDS).join(', ')}</div>`}
          </div>
          <label class="bc-switch">
            <input type="checkbox" checked=${f.enabled} onChange=${() => toggle(f)} />
            <span class="bc-switch-track" aria-hidden="true"></span>
            <span class="bc-switch-label">Enabled</span>
          </label>
          <div class="bc-card-actions">
            <button type="button" class="bc-btn" onClick=${() => setEditingId(editingId === f.id ? null : f.id)}>
              ${editingId === f.id ? 'Close' : 'Edit'}
            </button>
            <button type="button" class="bc-btn bc-btn-danger" onClick=${() => remove(f)}>Delete</button>
          </div>
        </div>
        ${f.id === editingId && html`<${FilterForm}
          key=${'edit' + f.id}
          inline initial=${f}
          calendars=${calendars} folders=${folders}
          busy=${busy}
          onSave=${(body) => saveEdit(f.id, body)}
          onCancel=${() => setEditingId(null)}
        />`}
      </div>`)}
    </div>`}
  <//>`;
}
