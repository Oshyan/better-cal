// Sidebar: folders > calendars with color dots + visibility toggles, folder
// visibility modes (all/none/custom), per-calendar settings panels (gear),
// folder create/rename/delete, add-calendar menu (local / subscribe / import),
// feed health badges, and the manage navigation in the footer.

import { html, useState, useRef } from '../../vendor/index.js';
import { useStore, set, state, toast, shallowEq } from './store.js';
import { api, loadCalendars } from './api.js';
import {
  toggleCalendarVisible, createFolder, renameFolder, deleteFolder,
  folderMode, setFolderVisibilityMode,
} from './actions.js';
import { CalendarSettings } from './CalendarSettings.js';
import { PALETTE } from '../lib/color.js';

// Inline stroke icons, sized for compact rows. viewBox 0 0 16 16.
function Icon({ name, size = 15 }) {
  const body = {
    settings: html`<circle cx="8" cy="8" r="2.8" />
      <path d="M8 1.2v2.1M8 12.7v2.1M1.2 8h2.1M12.7 8h2.1M3.2 3.2l1.5 1.5M11.3 11.3l1.5 1.5M12.8 3.2l-1.5 1.5M4.7 11.3l-1.5 1.5" />`,
    outfeeds: html`<path d="M3 8.6a4.4 4.4 0 0 1 4.4 4.4M3 4.6a8.4 8.4 0 0 1 8.4 8.4" />
      <circle cx="3.7" cy="12.3" r="1.1" fill="currentColor" stroke="none" />`,
    filters: html`<path d="M2 3h12l-4.6 5.4V13l-2.8-1.5V8.4z" />`,
    views: html`<path d="M4.5 2h7v12l-3.5-2.7L4.5 14z" />`,
  }[name];
  return html`<svg
    viewBox="0 0 16 16" width=${size} height=${size} aria-hidden="true"
    fill="none" stroke="currentColor" stroke-width="1.4"
    stroke-linecap="round" stroke-linejoin="round"
  >${body}</svg>`;
}

function healthBadge(cal) {
  if (cal.kind !== 'subscribed' || !cal.health) return null;
  const { status, stale, error, lastPolledAt } = cal.health;
  if (status !== 'error' && !stale) return null;
  const tip = status === 'error'
    ? 'Feed error: ' + (error || 'unknown') + (lastPolledAt ? ' (last poll ' + lastPolledAt + ')' : '')
    : 'Feed is stale' + (lastPolledAt ? ' (last poll ' + lastPolledAt + ')' : '');
  return html`<span class="bc-health" role="img" aria-label=${tip} title=${tip}>⚠</span>`;
}

function CalendarRow({ cal, folders, open, onGear }) {
  return html`<div class="bc-cal-item">
    <div class="bc-cal-row">
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
      <button
        type="button"
        class="bc-icon-btn bc-cal-gear${open ? ' is-open' : ''}"
        aria-label=${'Settings for ' + cal.name}
        aria-expanded=${open}
        title="Calendar settings"
        onClick=${() => onGear(cal.id)}
      ><${Icon} name="settings" size=${13} /></button>
    </div>
    ${open && html`<${CalendarSettings} cal=${cal} folders=${folders} onClose=${() => onGear(null)} />`}
  </div>`;
}

// Folder visibility mode: compact cycle button, All -> None -> Custom.
const MODE_ORDER = ['all', 'none', 'custom'];
const MODE_LABELS = { all: 'All', none: 'None', custom: 'Custom' };
const MODE_TIP = 'Folder visibility: All shows every calendar, None hides them all, ' +
  'Custom is your own per-calendar selection. Click to switch.';

function FolderModeButton({ folder }) {
  const mode = folderMode(folder.id);
  const next = MODE_ORDER[(MODE_ORDER.indexOf(mode) + 1) % MODE_ORDER.length];
  return html`<button
    type="button" class="bc-folder-mode"
    title=${MODE_TIP}
    aria-label=${'Visibility for ' + folder.name + ': ' + MODE_LABELS[mode] + '. Switch to ' + MODE_LABELS[next]}
    onClick=${() => setFolderVisibilityMode(folder.id, next)}
  >${MODE_LABELS[mode]}</button>`;
}

function FolderHead({ folder, cals, collapsed, onToggleCollapse }) {
  const [renaming, setRenaming] = useState(false);
  const [name, setName] = useState(folder.name);
  const doneRef = useRef(false); // guards Enter-then-blur double commits

  const startRename = () => { setName(folder.name); doneRef.current = false; setRenaming(true); };

  const commitRename = async () => {
    if (doneRef.current) return;
    doneRef.current = true;
    const trimmed = name.trim();
    if (trimmed && trimmed !== folder.name) await renameFolder(folder, trimmed);
    setRenaming(false);
  };

  const cancelRename = () => { doneRef.current = true; setRenaming(false); };

  const remove = () => {
    if (cals.length > 0) {
      toast('Folder has ' + cals.length + ' calendar' + (cals.length === 1 ? '' : 's') +
        '; remove them from the folder first (calendar settings > folders)', { error: true });
      return;
    }
    deleteFolder(folder);
  };

  if (renaming) {
    return html`<form class="bc-folder-rename" onSubmit=${(e) => { e.preventDefault(); commitRename(); }}>
      <input
        value=${name} autofocus aria-label="Folder name"
        onInput=${(e) => setName(e.target.value)}
        onBlur=${commitRename}
        onKeyDown=${(e) => { if (e.key === 'Escape') cancelRename(); }}
      />
    </form>`;
  }

  return html`<div class="bc-folder-headrow">
    <button
      type="button" class="bc-folder-head"
      aria-expanded=${!collapsed}
      onClick=${onToggleCollapse}
      onDblClick=${startRename}
    >
      <span class="bc-folder-caret">${collapsed ? '▸' : '▾'}</span>
      <span class="bc-folder-name">${folder.name}</span>
    </button>
    ${cals.length > 0 && html`<${FolderModeButton} folder=${folder} />`}
    <span class="bc-folder-tools">
      <button type="button" class="bc-icon-btn bc-folder-tool" aria-label=${'Rename folder ' + folder.name} title="Rename folder" onClick=${startRename}>✎</button>
      <button type="button" class="bc-icon-btn bc-folder-tool" aria-label=${'Delete folder ' + folder.name} title=${cals.length > 0 ? 'Only empty folders can be deleted' : 'Delete folder'} onClick=${remove}>✕</button>
    </span>
  </div>`;
}

function AddMenu() {
  const [mode, setMode] = useState(null); // null | 'local' | 'subscribe' | 'import' | 'folder'
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
        await loadCalendars();
      } else if (mode === 'subscribe') {
        await api('/calendars/subscribe', { method: 'POST', body: { url, name: name || undefined } });
        toast('Subscribed; first fetch running');
        await loadCalendars();
      } else if (mode === 'import') {
        const file = fileRef.current && fileRef.current.files[0];
        if (!file) { setBusy(false); return; }
        const fd = new FormData();
        fd.append('ics', file);
        if (name) fd.append('name', name);
        const res = await api('/calendars/import', { method: 'POST', formData: fd });
        toast('Imported ' + ((res && res.imported) || 0) + ' events');
        await loadCalendars();
      } else if (mode === 'folder') {
        await createFolder(name || 'New folder');
      }
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
      <button type="button" class="bc-link-btn" onClick=${() => setMode('folder')}>+ New folder</button>
    </div>`;
  }

  return html`<form class="bc-add-form" onSubmit=${submit}>
    <input placeholder="Name" required=${mode === 'folder'} value=${name} onInput=${(e) => setName(e.target.value)} />
    ${mode === 'subscribe' && html`<input placeholder="ICS URL" required type="url" value=${url} onInput=${(e) => setUrl(e.target.value)} />`}
    ${mode === 'import' && html`<input type="file" accept=".ics,text/calendar" ref=${fileRef} required />`}
    <div class="bc-add-form-row">
      <button type="submit" class="bc-btn bc-btn-primary" disabled=${busy}>${busy ? 'Working' : 'Add'}</button>
      <button type="button" class="bc-btn" onClick=${close}>Cancel</button>
    </div>
  </form>`;
}

const MANAGE_ITEMS = [
  ['settings', 'Settings'],
  ['outfeeds', 'Outbound feeds'],
  ['filters', 'Filters'],
  ['views', 'Saved views'],
];

export function Sidebar({ open }) {
  const { calendars, folders, collapsedFolders, route } = useStore(
    (s) => ({
      calendars: s.calendars, folders: s.folders,
      collapsedFolders: s.collapsedFolders, route: s.route,
      folderVisibility: s.folderVisibility,
    }),
    shallowEq,
  );
  const [openCalId, setOpenCalId] = useState(null);

  const toggleGear = (id) => setOpenCalId(openCalId === id ? null : id);

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

  const rows = (cals) => cals.map((c) => html`<${CalendarRow}
    key=${c.id} cal=${c} folders=${folders}
    open=${openCalId === c.id} onGear=${toggleGear}
  />`);

  return html`<aside class="bc-sidebar${open ? ' is-open' : ''}">
    <div class="bc-sidebar-scroll">
      ${byFolder.map(({ folder, cals }) => html`<section key=${'f' + folder.id} class="bc-folder">
        <${FolderHead}
          folder=${folder} cals=${cals}
          collapsed=${!!collapsedFolders[folder.id]}
          onToggleCollapse=${() => toggleFolder(folder.id)}
        />
        ${!collapsedFolders[folder.id] && rows(cals)}
      </section>`)}
      <section class="bc-folder">
        ${byFolder.length > 0 && html`<div class="bc-folder-head bc-folder-static">Calendars</div>`}
        ${rows(loose)}
      </section>
      <${AddMenu} />
    </div>
    <footer class="bc-sidebar-foot">
      <div class="bc-manage-head">Manage</div>
      ${MANAGE_ITEMS.map(([r, label]) => html`<button
        key=${r} type="button"
        class="bc-manage-item${route === r ? ' is-active' : ''}"
        aria-current=${route === r ? 'page' : 'false'}
        onClick=${() => set({ route: r })}
      ><${Icon} name=${r} /><span>${label}</span></button>`)}
    </footer>
  </aside>`;
}
