// Sidebar: folders > calendars with color dots + visibility toggles, folder
// visibility modes (all/none/custom), per-calendar settings panels (gear),
// folder create/delete (renaming lives in the folder manager), add-calendar
// menu (local / subscribe / import), feed health badges, and the manage
// navigation in the footer.

import { html, useState, useRef } from '../../vendor/index.js';
import { useStore, set, state, toast, shallowEq } from './store.js';
import { api, loadCalendars } from './api.js';
import {
  toggleCalendarVisible, createFolder, deleteFolder,
  folderMode, setFolderVisibilityMode,
  togglePersonVisible, enterPeopleSolo, exitPeopleSolo,
} from './actions.js';
import { CalendarSettings } from './CalendarSettings.js';
import { MiniMonth } from './MiniMonth.js';
import { PALETTE } from '../lib/color.js';
import { Icon, CalDot } from '../ui/icons.js';

// Solo ("show only this calendar"): transient, session-scoped. Entering solo
// captures the current visibility set; exiting restores it exactly. Switching
// the solo target keeps the ORIGINAL captured set so "Show all" always returns
// to the pre-solo state. Applied as real visible-flag PATCHes (silent, batch)
// so every view and the server agree; folder custom sets are not touched.
async function applyVisibilityMap(entries) {
  const want = new Map(entries);
  const changed = state.calendars.filter((c) => want.has(c.id) && c.visible !== want.get(c.id));
  if (changed.length === 0) return;
  set({ calendars: state.calendars.map((c) => (want.has(c.id) && c.visible !== want.get(c.id) ? { ...c, visible: want.get(c.id) } : c)) });
  const results = await Promise.allSettled(
    changed.map((c) => api('/calendars/' + c.id, { method: 'PATCH', body: { visible: want.get(c.id) } })),
  );
  if (results.some((r) => r.status === 'rejected')) {
    toast('Some visibility changes failed to save', { error: true });
    loadCalendars();
  }
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

function CalendarRow({ cal, folders, open, onGear, soloed, onSolo }) {
  return html`<div class="bc-cal-item">
    <div class="bc-cal-row">
      <label class="bc-cal-label">
        <input
          type="checkbox"
          checked=${cal.visible}
          onChange=${() => toggleCalendarVisible(cal)}
          aria-label=${'Toggle ' + cal.name}
        />
        <${CalDot} cal=${cal} />
        <span class="bc-cal-name">${cal.name}</span>
      </label>
      ${healthBadge(cal)}
      <button
        type="button"
        class="bc-icon-btn bc-cal-solo${soloed ? ' is-on' : ''}"
        aria-label=${soloed ? 'Stop showing only ' + cal.name : 'Show only ' + cal.name}
        aria-pressed=${soloed}
        title=${soloed ? 'Showing only this calendar. Click to restore.' : 'Show only this calendar'}
        onClick=${() => onSolo(cal)}
      >${soloed ? 'Only ✓' : 'Only'}</button>
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
  >${mode === 'custom' && html`<${Icon} name="mixed" size=${9} />`}${MODE_LABELS[mode]}</button>`;
}

// Folder rename lives in the folder manager; the header keeps collapse,
// visibility mode, and delete only.
function FolderHead({ folder, cals, collapsed, onToggleCollapse }) {
  const remove = () => {
    if (cals.length > 0) {
      toast('Folder has ' + cals.length + ' calendar' + (cals.length === 1 ? '' : 's') +
        '; remove them from the folder first (calendar settings > folders)', { error: true });
      return;
    }
    deleteFolder(folder);
  };

  return html`<div class="bc-folder-headrow">
    <button
      type="button" class="bc-folder-head"
      aria-expanded=${!collapsed}
      onClick=${onToggleCollapse}
    >
      <span class="bc-folder-caret">${collapsed ? '▸' : '▾'}</span>
      <span class="bc-folder-icon"><${Icon} name="folder" size=${12} /></span>
      <span class="bc-folder-name">${folder.name}</span>
    </button>
    ${cals.length > 0 && html`<${FolderModeButton} folder=${folder} />`}
    <span class="bc-folder-tools">
      <button type="button" class="bc-icon-btn bc-folder-tool bc-folder-delete" aria-label=${'Delete folder ' + folder.name} title=${cals.length > 0 ? 'Only empty folders can be deleted' : 'Delete folder'} onClick=${remove}>
        <${Icon} name="trash" size=${12} />
      </button>
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
  ['organize', 'Calendars & folders'],
  ['people', 'People'],
  ['outfeeds', 'Outbound feeds'],
  ['filters', 'Filters'],
  ['views', 'Saved views'],
];

export function Sidebar({ open, onClose }) {
  const { calendars, folders, collapsedFolders, route, people, collapsedAllCals, collapsedPeople, peopleSolo } = useStore(
    (s) => ({
      calendars: s.calendars, folders: s.folders,
      collapsedFolders: s.collapsedFolders, route: s.route,
      folderVisibility: s.folderVisibility,
      people: s.people, collapsedAllCals: s.collapsedAllCals,
      collapsedPeople: s.collapsedPeople, peopleSolo: s.peopleSolo,
    }),
    shallowEq,
  );
  const [openCalId, setOpenCalId] = useState(null);
  const [solo, setSolo] = useState(null); // {calId, prev: [[id, visible], ...]}

  const toggleGear = (id) => setOpenCalId(openCalId === id ? null : id);

  const enterSolo = async (cal) => {
    const prev = solo ? solo.prev : state.calendars.map((c) => [c.id, c.visible]);
    setSolo({ calId: cal.id, prev });
    await applyVisibilityMap(state.calendars.map((c) => [c.id, c.id === cal.id]));
  };
  const exitSolo = async () => {
    if (!solo) return;
    const prev = solo.prev;
    setSolo(null);
    await applyVisibilityMap(prev);
  };
  const soloCal = solo ? calendars.find((c) => c.id === solo.calId) : null;

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
    soloed=${solo && solo.calId === c.id}
    onSolo=${(cal) => (solo && solo.calId === cal.id ? exitSolo() : enterSolo(cal))}
  />`);

  return html`<aside class="bc-sidebar${open ? ' is-open' : ''}">
    ${onClose && html`<div class="bc-sidebar-mobilehead">
      <span class="bc-manage-head">Calendars</span>
      <button type="button" class="bc-icon-btn" aria-label="Close sidebar" onClick=${onClose}>✕</button>
    </div>`}
    <div class="bc-sidebar-scroll">
      <${MiniMonth} />
      ${soloCal && html`<div class="bc-solo-banner" role="status">
        Showing only <strong>${soloCal.name}</strong>
        <button type="button" class="bc-link-btn" onClick=${exitSolo}>Show all</button>
      </div>`}
      ${byFolder.map(({ folder, cals }) => html`<section key=${'f' + folder.id} class="bc-folder">
        <${FolderHead}
          folder=${folder} cals=${cals}
          collapsed=${!!collapsedFolders[folder.id]}
          onToggleCollapse=${() => toggleFolder(folder.id)}
        />
        ${!collapsedFolders[folder.id] && rows(cals)}
      </section>`)}
      <section class="bc-folder">
        ${byFolder.length > 0 && html`<button
          type="button" class="bc-folder-head bc-folder-static"
          aria-expanded=${!collapsedAllCals}
          onClick=${() => set({ collapsedAllCals: !collapsedAllCals })}
        ><span class="bc-folder-caret">${collapsedAllCals ? '▸' : '▾'}</span>All calendars</button>`}
        ${(byFolder.length === 0 || !collapsedAllCals) && rows(loose)}
      </section>
      ${people.length > 0 && html`<section class="bc-folder">
        <button
          type="button" class="bc-folder-head bc-folder-static"
          aria-expanded=${!collapsedPeople}
          onClick=${() => set({ collapsedPeople: !collapsedPeople })}
        ><span class="bc-folder-caret">${collapsedPeople ? '▸' : '▾'}</span>People</button>
        ${!collapsedPeople && people.map((p) => html`<div key=${p.id} class="bc-cal-item">
          <div class="bc-cal-row${p.currentSpan && p.currentSpan.kind === 'away' ? ' is-person-away' : ''}">
            <span class="bc-cal-label">
              <input
                type="checkbox"
                checked=${p.showOnCalendar}
                onChange=${() => togglePersonVisible(p)}
                title="Show away/busy spans on the calendar"
                aria-label=${'Show ' + p.name + ' availability on calendar'}
              />
              <button
                type="button" class="bc-cal-name bc-person-namebtn"
                title=${(p.currentSpan ? p.name + ' is ' + p.currentSpan.kind + ' now. ' : '') + 'Open in People'}
                onClick=${() => set({ route: 'people', peopleFocus: p.name })}
              >${p.name}</button>
              ${p.currentSpan && html`<span class="bc-badge bc-away-pill">${p.currentSpan.kind}</span>`}
            </span>
            <button
              type="button"
              class="bc-icon-btn bc-cal-solo${peopleSolo && peopleSolo.personId === p.id ? ' is-on' : ''}"
              aria-pressed=${peopleSolo && peopleSolo.personId === p.id}
              title=${peopleSolo && peopleSolo.personId === p.id ? 'Showing only this person. Click to restore.' : "Show only this person's spans"}
              onClick=${() => (peopleSolo && peopleSolo.personId === p.id ? exitPeopleSolo() : enterPeopleSolo(p))}
            >${peopleSolo && peopleSolo.personId === p.id ? 'Only ✓' : 'Only'}</button>
          </div>
        </div>`)}
      </section>`}
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
