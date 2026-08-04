// CreateDrawer: the generalized "New <thing>" surface — a titled slide-in
// panel from the right (same chrome as the event editor) for creating
// calendars, feed subscriptions, ICS imports, folders, and people. Replaces
// the old untitled inline form at the bottom of the sidebar: a modal drawer
// says clearly what it is creating and has room for more options as entity
// creation grows. Opened via store.createDrawer:
//   {kind: 'calendar'|'subscribe'|'import'|'folder'|'person', folderId?}

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, state, toast } from './store.js';
import { api, loadCalendars, loadPeople } from './api.js';
import { createFolder } from './actions.js';
import { trapFocus } from '../ui/DayExpand.js';
import { PALETTE } from '../lib/color.js';

const TITLES = {
  calendar: 'New calendar',
  subscribe: 'Subscribe to calendar feed',
  import: 'Import ICS file',
  folder: 'New folder',
  person: 'New person',
};

export function CreateDrawer() {
  const req = useStore((s) => s.createDrawer);
  const panelRef = useRef(null);
  const fileRef = useRef(null);
  const [name, setName] = useState('');
  const [url, setUrl] = useState('');
  const [notes, setNotes] = useState('');
  const [color, setColor] = useState(null);
  const [folderId, setFolderId] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!req) return;
    setName('');
    setUrl('');
    setNotes('');
    setColor(PALETTE[state.calendars.length % PALETTE.length]);
    setFolderId(req.folderId != null ? String(req.folderId) : '');
    setBusy(false);
  }, [req]);

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Tab') trapFocus(panelRef.current, e); };
    if (req) document.addEventListener('keydown', onKey, true);
    return () => document.removeEventListener('keydown', onKey, true);
  }, [req]);

  if (!req) return null;

  const kind = req.kind;
  const close = () => set({ createDrawer: null });

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    try {
      if (kind === 'calendar') {
        const created = await api('/calendars', { method: 'POST', body: { name: name.trim() || 'New calendar', color } });
        if (folderId !== '' && created && created.id != null) {
          await api('/calendars/' + created.id, { method: 'PATCH', body: { folderIds: [Number(folderId)] } }).catch(() => {});
        }
        toast('Calendar created');
        await loadCalendars();
      } else if (kind === 'subscribe') {
        await api('/calendars/subscribe', { method: 'POST', body: { url, name: name.trim() || undefined } });
        toast('Subscribed; first fetch running');
        await loadCalendars();
      } else if (kind === 'import') {
        const file = fileRef.current && fileRef.current.files[0];
        if (!file) { setBusy(false); return; }
        const fd = new FormData();
        fd.append('ics', file);
        if (name.trim()) fd.append('name', name.trim());
        const res = await api('/calendars/import', { method: 'POST', formData: fd });
        toast('Imported ' + ((res && res.imported) || 0) + ' events');
        await loadCalendars();
      } else if (kind === 'folder') {
        await createFolder(name.trim() || 'New folder');
      } else if (kind === 'person') {
        const person = await api('/people', {
          method: 'POST',
          body: { name: name.trim(), notes: notes.trim() || null },
        });
        toast(person.created ? `Added ${person.name}` : `${person.name} already exists`);
        // The People page auto-expands whoever peopleFocus names once the
        // refreshed list lands.
        set({ peopleFocus: person.name });
        await loadPeople();
      }
      close();
    } catch (err) {
      toast('Failed: ' + err.message, { error: true });
    }
    setBusy(false);
  };

  const canSubmit = !busy && (
    (kind === 'calendar' || kind === 'folder' || kind === 'import') ||
    (kind === 'subscribe' && url.trim() !== '') ||
    (kind === 'person' && name.trim() !== '')
  );

  return html`<div class="bc-drawer-backdrop" onClick=${(e) => { if (e.target === e.currentTarget) close(); }}>
    <form class="bc-drawer bc-drawer-slim" ref=${panelRef} onSubmit=${submit} role="dialog" aria-modal="true" aria-label=${TITLES[kind]}>
      <div class="bc-drawer-head">
        <h2>${TITLES[kind]}</h2>
        <button type="button" class="bc-icon-btn" aria-label="Close" onClick=${close}>✕</button>
      </div>

      <label class="bc-field">
        <span>Name${kind === 'subscribe' || kind === 'import' ? ' (optional)' : ''}</span>
        <input
          value=${name} autofocus required=${kind === 'person' || kind === 'folder'}
          placeholder=${kind === 'person' ? "Person's name" : ''}
          onInput=${(e) => setName(e.target.value)}
        />
      </label>

      ${kind === 'subscribe' && html`<label class="bc-field">
        <span>ICS URL</span>
        <input type="url" required placeholder="https://example.com/calendar.ics" value=${url} onInput=${(e) => setUrl(e.target.value)} />
      </label>`}

      ${kind === 'import' && html`<label class="bc-field">
        <span>ICS file</span>
        <input type="file" accept=".ics,text/calendar" ref=${fileRef} required />
      </label>`}

      ${kind === 'calendar' && html`<div class="bc-field">
        <span>Color</span>
        <div class="bc-colorrow" role="radiogroup" aria-label="Calendar color">
          ${PALETTE.map((c) => html`<button
            key=${c} type="button" role="radio"
            class="bc-colorswatch${color === c ? ' is-on' : ''}"
            style=${`background:${c}`}
            aria-checked=${color === c} aria-label=${'Color ' + c}
            onClick=${() => setColor(c)}
          ></button>`)}
        </div>
      </div>`}

      ${kind === 'calendar' && state.folders.length > 0 && html`<label class="bc-field">
        <span>Folder</span>
        <select value=${folderId} onChange=${(e) => setFolderId(e.target.value)}>
          <option value="">No folder</option>
          ${state.folders.map((f) => html`<option key=${f.id} value=${String(f.id)}>${f.name}</option>`)}
        </select>
      </label>`}

      ${kind === 'person' && html`<label class="bc-field">
        <span>Notes (optional)</span>
        <textarea
          rows="3" value=${notes}
          placeholder="How you met, birthday, preferences..."
          onInput=${(e) => setNotes(e.target.value)}
        ></textarea>
      </label>`}

      <div class="bc-drawer-actions">
        <button type="submit" class="bc-btn bc-btn-primary" disabled=${!canSubmit}>${busy ? 'Working' : 'Create'}</button>
        <button type="button" class="bc-btn" onClick=${close}>Cancel</button>
      </div>
    </form>
  </div>`;
}
