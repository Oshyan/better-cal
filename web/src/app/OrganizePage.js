// Organize page (route 'organize'): the home for folder management and
// calendar filing. Folders rename inline here (visible Save/Cancel; the
// sidebar header deliberately has no rename) and delete with an inline
// confirm. Every calendar lists with its color, kind, folder membership as
// checkboxes (tick to file, untick to remove), and an expandable copy of the
// same per-calendar settings panel the sidebar gear opens.

import { html, useState } from '../../vendor/index.js';
import { useStore, toast, shallowEq } from './store.js';
import {
  updateCalendar, renameFolder, deleteFolder, createFolder,
} from './actions.js';
import { PageShell, EmptyState } from './PageShell.js';
import { CalendarSettings } from './CalendarSettings.js';
import { Icon } from '../ui/icons.js';

function FolderRow({ folder, count }) {
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
    if (count > 0) {
      toast('Folder still has ' + count + ' calendar' + (count === 1 ? '' : 's') +
        '; untick it on those calendars below first', { error: true });
      return;
    }
    setConfirming(true);
  };

  return html`<div class="bc-org-folder">
    <div class="bc-org-folder-row">
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
          <span class="bc-org-count">${count} calendar${count === 1 ? '' : 's'}</span>
          <button type="button" class="bc-btn" onClick=${() => setEditing(true)}>Rename</button>
          <button type="button" class="bc-btn" title=${count > 0 ? 'Only empty folders can be deleted' : 'Delete folder'} onClick=${askDelete}>Delete</button>`}
    </div>
    ${confirming && html`<div class="bc-org-confirm">
      <span>Delete folder "${folder.name}"?</span>
      <button type="button" class="bc-btn bc-btn-danger" onClick=${() => deleteFolder(folder)}>Delete folder</button>
      <button type="button" class="bc-btn" onClick=${() => setConfirming(false)}>Cancel</button>
    </div>`}
  </div>`;
}

function CalendarRow({ cal, folders }) {
  const [open, setOpen] = useState(false);

  const toggleFolder = (fid) => {
    const ids = new Set(cal.folderIds || []);
    if (ids.has(fid)) ids.delete(fid); else ids.add(fid);
    updateCalendar(cal, { folderIds: [...ids] });
  };

  return html`<div class="bc-org-cal">
    <div class="bc-org-cal-row">
      <span class="bc-cal-dot" style=${`background:${cal.color}`}></span>
      <span class="bc-org-cal-name">${cal.name}</span>
      <span class="bc-org-kind">${cal.kind === 'subscribed' ? 'Feed' : 'Local'}</span>
      ${folders.length > 0 && html`<span class="bc-org-folders" role="group" aria-label=${'Folders for ' + cal.name}>
        ${folders.map((f) => html`<label key=${f.id} class="bc-check bc-org-folder-check">
          <input
            type="checkbox"
            checked=${(cal.folderIds || []).includes(f.id)}
            onChange=${() => toggleFolder(f.id)}
          />
          <span>${f.name}</span>
        </label>`)}
      </span>`}
      <button
        type="button" class="bc-btn bc-org-open"
        aria-expanded=${open}
        onClick=${() => setOpen(!open)}
      ><${Icon} name="settings" size=${13} /><span>${open ? 'Close settings' : 'Open settings'}</span></button>
    </div>
    ${open && html`<${CalendarSettings} cal=${cal} folders=${folders} onClose=${() => setOpen(false)} />`}
  </div>`;
}

export function OrganizePage() {
  const { calendars, folders } = useStore(
    (s) => ({ calendars: s.calendars, folders: s.folders }),
    shallowEq,
  );
  const [newName, setNewName] = useState('');

  const countFor = (fid) => calendars.filter((c) => (c.folderIds || []).includes(fid)).length;

  const submitNew = async (e) => {
    e.preventDefault();
    const trimmed = newName.trim();
    if (!trimmed) return;
    if (await createFolder(trimmed)) setNewName('');
  };

  return html`<${PageShell}
    title="Calendars & folders"
    note="Folders group calendars in the sidebar. Rename or delete them here; tick a folder on a calendar to file it, untick to remove it."
  >
    <div class="bc-org-grid">
      <section class="bc-set-section">
        <h2 class="bc-set-h">Folders</h2>
        ${folders.length === 0 && html`<${EmptyState} text="No folders yet. Create one below to group calendars in the sidebar." />`}
        ${folders.map((f) => html`<${FolderRow} key=${f.id} folder=${f} count=${countFor(f.id)} />`)}
        <form class="bc-org-new" onSubmit=${submitNew}>
          <input
            placeholder="New folder name" value=${newName}
            aria-label="New folder name"
            onInput=${(e) => setNewName(e.target.value)}
          />
          <button type="submit" class="bc-btn" disabled=${!newName.trim()}>Add folder</button>
        </form>
      </section>
      <section class="bc-set-section">
        <h2 class="bc-set-h">Calendars</h2>
        ${calendars.length === 0 && html`<${EmptyState} text="No calendars yet. Add one from the sidebar." />`}
        ${calendars.map((c) => html`<${CalendarRow} key=${c.id} cal=${c} folders=${folders} />`)}
      </section>
    </div>
  <//>`;
}
