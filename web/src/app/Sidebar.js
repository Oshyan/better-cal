// Sidebar: folders > calendars with color dots + visibility toggles,
// add-calendar menu (local / subscribe / import), feed health badges,
// outbound feeds link in the footer.

import { html, useState, useRef } from '../../vendor/index.js';
import { useStore, set, state, toast, shallowEq } from './store.js';
import { api, loadCalendars } from './api.js';
import { toggleCalendarVisible } from './actions.js';
import { PALETTE } from '../lib/color.js';

function healthBadge(cal) {
  if (cal.kind !== 'subscribed' || !cal.health) return null;
  const { status, stale, error, lastPolledAt } = cal.health;
  if (status !== 'error' && !stale) return null;
  const tip = status === 'error'
    ? 'Feed error: ' + (error || 'unknown') + (lastPolledAt ? ' (last poll ' + lastPolledAt + ')' : '')
    : 'Feed is stale' + (lastPolledAt ? ' (last poll ' + lastPolledAt + ')' : '');
  return html`<span class="bc-health" role="img" aria-label=${tip} title=${tip}>⚠</span>`;
}

function CalendarRow({ cal }) {
  return html`<div class="bc-cal-row">
    <label class="bc-cal-label">
      <input
        type="checkbox"
        checked=${cal.visible}
        onChange=${() => toggleCalendarVisible(cal)}
        aria-label=${'Toggle ' + cal.name}
      />
      <span class="bc-cal-dot" style=${`background:${cal.color}`}></span>
      <span class="bc-cal-name">${cal.name}</span>
    </label>
    ${healthBadge(cal)}
  </div>`;
}

function AddMenu() {
  const [mode, setMode] = useState(null); // null | 'local' | 'subscribe' | 'import'
  const [name, setName] = useState('');
  const [url, setUrl] = useState('');
  const [busy, setBusy] = useState(false);
  const fileRef = useRef(null);

  const close = () => { setMode(null); setName(''); setUrl(''); };

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    try {
      if (mode === 'local') {
        await api('/calendars', { method: 'POST', body: { name: name || 'New calendar', color: PALETTE[state.calendars.length % PALETTE.length] } });
        toast('Calendar created');
      } else if (mode === 'subscribe') {
        await api('/calendars/subscribe', { method: 'POST', body: { url, name: name || undefined } });
        toast('Subscribed; first fetch running');
      } else if (mode === 'import') {
        const file = fileRef.current && fileRef.current.files[0];
        if (!file) { setBusy(false); return; }
        const fd = new FormData();
        fd.append('ics', file);
        if (name) fd.append('name', name);
        const res = await api('/calendars/import', { method: 'POST', formData: fd });
        toast('Imported ' + ((res && res.imported) || 0) + ' events');
      }
      await loadCalendars();
      close();
    } catch (err) {
      toast('Failed: ' + err.message, { error: true });
    }
    setBusy(false);
  };

  if (!mode) {
    return html`<div class="bc-add-menu">
      <button type="button" class="bc-link-btn" onClick=${() => setMode('local')}>+ New calendar</button>
      <button type="button" class="bc-link-btn" onClick=${() => setMode('subscribe')}>+ Subscribe URL</button>
      <button type="button" class="bc-link-btn" onClick=${() => setMode('import')}>+ Import ICS</button>
    </div>`;
  }

  return html`<form class="bc-add-form" onSubmit=${submit}>
    <input placeholder="Name" value=${name} onInput=${(e) => setName(e.target.value)} />
    ${mode === 'subscribe' && html`<input placeholder="ICS URL" required type="url" value=${url} onInput=${(e) => setUrl(e.target.value)} />`}
    ${mode === 'import' && html`<input type="file" accept=".ics,text/calendar" ref=${fileRef} required />`}
    <div class="bc-add-form-row">
      <button type="submit" class="bc-btn bc-btn-primary" disabled=${busy}>${busy ? 'Working' : 'Add'}</button>
      <button type="button" class="bc-btn" onClick=${close}>Cancel</button>
    </div>
  </form>`;
}

export function Sidebar({ open }) {
  const { calendars, folders, collapsedFolders } = useStore(
    (s) => ({ calendars: s.calendars, folders: s.folders, collapsedFolders: s.collapsedFolders }),
    shallowEq,
  );

  const inFolder = new Set();
  const byFolder = folders.map((f) => {
    const cals = calendars.filter((c) => (c.folderIds || []).includes(f.id));
    for (const c of cals) inFolder.add(c.id);
    return { folder: f, cals };
  });
  const loose = calendars.filter((c) => !inFolder.has(c.id));

  const toggleFolder = (id) => {
    set({ collapsedFolders: { ...collapsedFolders, [id]: !collapsedFolders[id] } });
  };

  return html`<aside class="bc-sidebar${open ? ' is-open' : ''}">
    <div class="bc-sidebar-scroll">
      ${byFolder.map(({ folder, cals }) => html`<section key=${'f' + folder.id} class="bc-folder">
        <button
          type="button" class="bc-folder-head"
          aria-expanded=${!collapsedFolders[folder.id]}
          onClick=${() => toggleFolder(folder.id)}
        >
          <span class="bc-folder-caret">${collapsedFolders[folder.id] ? '▸' : '▾'}</span>
          ${folder.name}
        </button>
        ${!collapsedFolders[folder.id] && cals.map((c) => html`<${CalendarRow} key=${c.id} cal=${c} />`)}
      </section>`)}
      <section class="bc-folder">
        ${byFolder.length > 0 && html`<div class="bc-folder-head bc-folder-static">Calendars</div>`}
        ${loose.map((c) => html`<${CalendarRow} key=${c.id} cal=${c} />`)}
      </section>
      <${AddMenu} />
    </div>
    <footer class="bc-sidebar-foot">
      <button type="button" class="bc-link-btn" onClick=${() => set({ route: 'outfeeds' })}>Outbound feeds</button>
    </footer>
  </aside>`;
}
