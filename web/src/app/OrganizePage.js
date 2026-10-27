// Organize page (route 'organize'): one hierarchy matching the sidebar's
// mental model. The new-folder form sits on top; folders are sections
// (rename inline via the pencil, delete via the trash with an inline
// confirm), each listing its member calendars as rows; an Unfiled section at
// the end collects calendars in no folder. Every calendar row carries its
// color dot, kind badge, and a single Folder dropdown; membership in several
// folders stays possible through "+ Add to another folder". The gear expands
// the same per-calendar settings panel the sidebar opens.

import { html, useState } from '../../vendor/index.js';
import { useStore, toast, shallowEq } from './store.js';
import {
  updateCalendar, renameFolder, deleteFolder, createFolder, setSidebarOrder,
} from './actions.js';
import { sidebarGroups, moveGroup } from '../lib/sidebarorder.js';
import { PageShell, EmptyState } from './PageShell.js';
import { CalendarSettings } from './CalendarSettings.js';
import { Icon, CalDot } from '../ui/icons.js';

function folderNameOf(folders, id) {
  const f = folders.find((x) => x.id === id);
  return (f && f.name) || 'folder';
}

function CalendarRow({ cal, folders, contextFolderId }) {
  const [open, setOpen] = useState(false);
  const [adding, setAdding] = useState(false);

  const ids = cal.folderIds || [];
  const others = ids.filter((id) => id !== contextFolderId);

  // The dropdown reassigns THIS row's membership: picking a folder swaps the
  // section's folder for the chosen one, None removes it. Memberships in
  // other folders (the "Also in" note) are untouched.
  const assign = (e) => {
    const picked = folders.find((f) => String(f.id) === e.target.value);
    const next = ids.filter((id) => id !== contextFolderId);
    if (picked && !next.includes(picked.id)) next.push(picked.id);
    updateCalendar(cal, { folderIds: next });
  };

  const addTo = (e) => {
    const picked = folders.find((f) => String(f.id) === e.target.value);
    setAdding(false);
    if (picked && !ids.includes(picked.id)) {
      updateCalendar(cal, { folderIds: [...ids, picked.id] });
    }
  };

  const addable = folders.filter((f) => !ids.includes(f.id));

  return html`<div class="bc-org-cal">
    <div class="bc-org-cal-row">
      <${CalDot} cal=${cal} />
      <span class="bc-org-cal-name">${cal.name}</span>
      <span class="bc-org-kind">${cal.kind === 'subscribed' ? 'Feed' : 'Local'}</span>
      ${others.length > 0 && html`<span class="bc-org-note">Also in ${others.map((id) => folderNameOf(folders, id)).join(', ')}</span>`}
      <span class="bc-org-side">
        ${folders.length > 0 && html`<label class="bc-org-assign">
          <span>Folder</span>
          <select
            value=${contextFolderId != null ? String(contextFolderId) : ''}
            aria-label=${'Folder for ' + cal.name}
            onChange=${assign}
          >
            ${folders.map((f) => html`<option key=${f.id} value=${String(f.id)}>${f.name}</option>`)}
            <option value="">None</option>
          </select>
        </label>`}
        ${!adding && ids.length > 0 && addable.length > 0 && html`<button
          type="button" class="bc-link-btn bc-org-addto"
          onClick=${() => setAdding(true)}
        >+ Add to another folder</button>`}
        ${adding && html`<select
          class="bc-org-addsel" autofocus
          aria-label=${'Add ' + cal.name + ' to another folder'}
          onChange=${addTo}
          onBlur=${() => setAdding(false)}
        >
          <option value="">Choose folder</option>
          ${addable.map((f) => html`<option key=${f.id} value=${String(f.id)}>${f.name}</option>`)}
        </select>`}
        <button
          type="button" class="bc-icon-btn bc-org-gear${open ? ' is-open' : ''}"
          title="Calendar settings"
          aria-label=${'Settings for ' + cal.name}
          aria-expanded=${open}
          onClick=${() => setOpen(!open)}
        ><${Icon} name="settings" size=${14} /></button>
      </span>
    </div>
    ${open && html`<${CalendarSettings} cal=${cal} folders=${folders} onClose=${() => setOpen(false)} />`}
  </div>`;
}

function FolderSection({ folder, cals, folders }) {
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState(folder.name);
  const [confirming, setConfirming] = useState(false);

  const save = async () => {
    const trimmed = name.trim();
    if (trimmed && trimmed !== folder.name) {
      if (await renameFolder(folder, trimmed)) setEditing(false);
    } else {
      setName(folder.name);
      setEditing(false);
    }
  };
  const cancel = () => { setName(folder.name); setEditing(false); };
  const askDelete = () => {
    if (cals.length > 0) {
      toast('Folder still has ' + cals.length + ' calendar' + (cals.length === 1 ? '' : 's') +
        '; set their Folder dropdown to None or another folder first', { error: true });
      return;
    }
    setConfirming(true);
  };

  return html`<section class="bc-org-section">
    <div class="bc-org-sect-head">
      <span class="bc-org-icon"><${Icon} name="folder" size=${14} /></span>
      ${editing
        ? html`<input
            class="bc-org-rename" value=${name} autofocus
            aria-label=${'Rename folder ' + folder.name}
            onInput=${(e) => setName(e.target.value)}
            onKeyDown=${(e) => {
              if (e.key === 'Enter') { e.preventDefault(); save(); }
              if (e.key === 'Escape') { e.stopPropagation(); cancel(); }
            }}
          />
          <button type="button" class="bc-btn bc-btn-primary" disabled=${!name.trim()} onClick=${save}>Save</button>
          <button type="button" class="bc-btn" onClick=${cancel}>Cancel</button>`
        : html`<span class="bc-org-folder-name">${folder.name}</span>
          <span class="bc-org-count">${cals.length} calendar${cals.length === 1 ? '' : 's'}</span>
          <span class="bc-org-tools">
            <button
              type="button" class="bc-icon-btn"
              title="Rename folder" aria-label=${'Rename folder ' + folder.name}
              onClick=${() => setEditing(true)}
            ><${Icon} name="pencil" size=${13} /></button>
            <button
              type="button" class="bc-icon-btn bc-org-trash"
              title=${cals.length > 0 ? 'Only empty folders can be deleted' : 'Delete folder'}
              aria-label=${'Delete folder ' + folder.name}
              onClick=${askDelete}
            ><${Icon} name="trash" size=${13} /></button>
          </span>`}
    </div>
    ${confirming && html`<div class="bc-org-confirm">
      <span>Delete folder "${folder.name}"?</span>
      <button type="button" class="bc-btn bc-btn-danger" onClick=${() => deleteFolder(folder)}>Delete folder</button>
      <button type="button" class="bc-btn" onClick=${() => setConfirming(false)}>Cancel</button>
    </div>`}
    ${cals.length === 0 && html`<div class="bc-org-emptyrow">No calendars in this folder.</div>`}
    ${cals.map((c) => html`<${CalendarRow} key=${c.id} cal=${c} folders=${folders} contextFolderId=${folder.id} />`)}
  </section>`;
}

const BUILT_IN_NAMES = { all: 'All calendars', people: 'People', plugins: 'Plugins' };

// The sidebar's groups in order, each with Move up and Move down: the way to
// reorder on a phone or from the keyboard (on a computer the sidebar's
// headers also drag). Built-in groups move like folders. Only the groups the
// sidebar shows are listed (People with someone in it, Plugins with a plugin
// drawing on the calendar); a move steps past the next one shown.
function SidebarOrder({ keys, shown, folders }) {
  const nameOf = (k) => BUILT_IN_NAMES[k] || folderNameOf(folders, Number(k.slice(1)));
  const move = (k, by) => {
    const next = shown[shown.indexOf(k) + by];
    if (!next) return;
    const rest = keys.filter((x) => x !== k);
    setSidebarOrder(moveGroup(keys, k, rest.indexOf(next) + (by > 0 ? 1 : 0)));
  };
  return html`<section class="bc-org-order">
    <div class="bc-org-sect-head">
      <span class="bc-org-folder-name">Sidebar order</span>
      <span class="bc-org-count">Drag a group's header in the sidebar, or move it here.</span>
    </div>
    <ol class="bc-org-order-list">
      ${shown.map((k, i) => html`<li key=${k} class=${'bc-org-order-item' + (BUILT_IN_NAMES[k] ? ' is-builtin' : '')}>
        <span>${nameOf(k)}</span>
        <button
          type="button" class="bc-icon-btn" disabled=${i === 0}
          aria-label=${'Move ' + nameOf(k) + ' up'} title="Move up"
          onClick=${() => move(k, -1)}
        ><${Icon} name="chevronUp" size=${12} /></button>
        <button
          type="button" class="bc-icon-btn" disabled=${i === shown.length - 1}
          aria-label=${'Move ' + nameOf(k) + ' down'} title="Move down"
          onClick=${() => move(k, 1)}
        ><${Icon} name="chevronDown" size=${12} /></button>
      </li>`)}
    </ol>
  </section>`;
}

export function OrganizePage() {
  const { calendars, folders, sidebarOrder, hasPeople, hasLayers } = useStore(
    (s) => ({
      calendars: s.calendars, folders: s.folders, sidebarOrder: s.settings.sidebarOrder,
      hasPeople: (s.people || []).length > 0,
      hasLayers: (s.plugins || []).some((pl) => pl.enabled && (pl.permissions || []).includes('ranges')),
    }),
    shallowEq,
  );
  const groupKeys = sidebarGroups(folders, sidebarOrder);
  const shownKeys = groupKeys.filter((k) => (k !== 'people' || hasPeople) && (k !== 'plugins' || hasLayers));
  const [newName, setNewName] = useState('');

  const submitNew = async (e) => {
    e.preventDefault();
    const trimmed = newName.trim();
    if (!trimmed) return;
    if (await createFolder(trimmed)) setNewName('');
  };

  const inFolder = new Set();
  // Folders in the sidebar's order.
  const ordered = groupKeys.filter((k) => k.startsWith('f')).map((k) => folders.find((f) => 'f' + f.id === k)).filter(Boolean);
  const byFolder = ordered.map((f) => {
    const cals = calendars.filter((c) => (c.folderIds || []).includes(f.id));
    for (const c of cals) inFolder.add(c.id);
    return { folder: f, cals };
  });
  const unfiled = calendars.filter((c) => !inFolder.has(c.id));

  return html`<${PageShell}
    title="Calendars & folders"
    note="Folders group calendars in the sidebar. Rename or delete a folder from its section; file a calendar with the Folder dropdown on its row."
  >
    <form class="bc-org-new" onSubmit=${submitNew}>
      <input
        placeholder="New folder name" value=${newName}
        aria-label="New folder name"
        onInput=${(e) => setNewName(e.target.value)}
      />
      <button type="submit" class="bc-btn" disabled=${!newName.trim()}>Add folder</button>
    </form>
    ${calendars.length > 0 && html`<${SidebarOrder} keys=${groupKeys} shown=${shownKeys} folders=${folders} />`}
    ${folders.length === 0 && calendars.length > 0 && html`<${EmptyState} text="No folders yet. Create one above to group calendars in the sidebar." />`}
    ${byFolder.map(({ folder, cals }) => html`<${FolderSection} key=${folder.id} folder=${folder} cals=${cals} folders=${folders} />`)}
    ${unfiled.length > 0 && html`<section class="bc-org-section">
      <div class="bc-org-sect-head">
        <span class="bc-org-icon"><${Icon} name="folder" size=${14} /></span>
        <span class="bc-org-folder-name">Unfiled</span>
        <span class="bc-org-count">${unfiled.length} calendar${unfiled.length === 1 ? '' : 's'}</span>
      </div>
      ${unfiled.map((c) => html`<${CalendarRow} key=${c.id} cal=${c} folders=${folders} contextFolderId=${null} />`)}
    </section>`}
    ${calendars.length === 0 && html`<${EmptyState} text="No calendars yet. Add one from the sidebar." />`}
  <//>`;
}
