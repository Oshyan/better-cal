// Sidebar: folders > calendars with color dots + visibility toggles, folder
// visibility modes (all/none/custom), per-calendar settings panels (gear),
// folder gear options (rename/delete), the People folder (availability
// visibility), hover "+" launchers and the creation launcher list (all of
// which open the titled CreateDrawer), feed health badges, and the manage
// navigation in the footer.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, state, toast, shallowEq } from './store.js';
import { api, loadCalendars } from './api.js';
import {
  toggleCalendarVisible, createFolder, deleteFolder,
  folderMode, setFolderVisibilityMode, ALL_GROUP_ID, setSidebarActiveOnly,
  togglePersonVisible, enterPeopleSolo, exitPeopleSolo,
  peopleVisibilityMode, setPeopleVisibilityMode, linkPersonToEvent, saveSetting,
} from './actions.js';
import { startPointerDrag, externalDropTarget, setDropRowHighlight } from '../ui/DragController.js';
import { CalendarSettings } from './CalendarSettings.js';
import { MiniMonth } from './MiniMonth.js';
import { PALETTE } from '../lib/color.js';
import { MANAGE_ITEMS } from './commanddefs.js';
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
  return html`<span class="bc-health" role="img" aria-label=${tip} title=${tip}><${Icon} name="warning" size=${12} /></span>`;
}

// A plugin-owned calendar wears its plugin's declared decoration. Read from the
// loaded plugin list rather than the calendar payload, so it tracks the
// manifest without a schema field to keep in sync.
function pluginDecoFor(cal) {
  if (!cal || !cal.pluginId) return null;
  const pl = (state.plugins || []).find((p) => p.id === cal.pluginId);
  return (pl && pl.decoration) || null;
}

function CalendarRow({ cal, folders, open, onGear, soloed, onSolo, filedIn }) {
  // data-drop-cal marks writable calendars as drag targets: dropping an event
  // here moves it onto this calendar (feeds are read-only, so no attribute).
  return html`<div class="bc-cal-item">
    <div
      class="bc-cal-row${cal.visible ? '' : ' is-off'}"
      data-drop-cal=${cal.kind === 'subscribed' ? undefined : cal.id}
    >
      <label class="bc-cal-label" title=${cal.name}>
        <input
          type="checkbox"
          checked=${cal.visible}
          onChange=${() => toggleCalendarVisible(cal)}
          aria-label=${'Toggle ' + cal.name}
        />
        <${CalDot} cal=${cal} deco=${pluginDecoFor(cal)} />
        <span class="bc-cal-name">${cal.name}</span>
        ${filedIn && filedIn.length > 0 && html`<span
          class="bc-cal-filed" role="img"
          aria-label=${'Also in ' + filedIn.join(', ')}
          title=${'Also listed in ' + filedIn.join(', ')}
        ><${Icon} name="folder" size=${11} /></span>`}
      </label>
      ${healthBadge(cal)}
      <button
        type="button"
        class="bc-icon-btn bc-cal-solo${soloed ? ' is-on' : ''}"
        aria-label=${soloed ? 'Stop showing only ' + cal.name : 'Show only ' + cal.name}
        aria-pressed=${soloed}
        title=${soloed ? 'Showing only this calendar. Click to restore.' : 'Show only this calendar'}
        onClick=${(e) => { onSolo(cal); e.currentTarget.blur(); }}
      >${soloed ? html`Only <${Icon} name="check" size=${10} />` : 'Only'}</button>
      <button
        type="button"
        class="bc-icon-btn bc-cal-gear${open ? ' is-open' : ''}"
        aria-label=${'Settings for ' + cal.name}
        aria-expanded=${open}
        title="Calendar settings"
        onClick=${() => onGear()}
      ><${Icon} name="settings" size=${13} /></button>
    </div>
    ${open && html`<${CalendarSettings} cal=${cal} folders=${folders} onClose=${() => onGear()} />`}
  </div>`;
}

// Visibility mode: All / None / Custom as a small dropdown (a click-to-cycle
// button proved a trap — from None the next stop was Custom, which can be a
// no-op, wedging the cycle; a menu lets any mode be picked directly).
const MODE_LABELS = { all: 'All', none: 'None', custom: 'Custom' };
// Icon per mode: filled circle (all on), struck circle (all off), half-filled
// (custom). An icon trigger at the same size as the row's other tools keeps
// the header compact and leaves the width for long folder names.
const MODE_ICONS = { all: 'visAll', none: 'visNone', custom: 'mixed' };

function ModeMenu({ mode, tip, ariaName, customDisabled, onPick }) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onDoc = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('pointerdown', onDoc, true);
    return () => document.removeEventListener('pointerdown', onDoc, true);
  }, [open]);

  return html`<div class="bc-ov bc-modemenu" ref=${rootRef}>
    <button
      type="button" class="bc-icon-btn bc-folder-tool bc-folder-mode${mode === 'none' ? ' is-off' : ''}"
      title=${MODE_LABELS[mode] + ' — ' + tip}
      aria-label=${ariaName + ': ' + MODE_LABELS[mode]}
      aria-haspopup="menu" aria-expanded=${open}
      onClick=${() => setOpen(!open)}
    ><${Icon} name=${MODE_ICONS[mode]} size=${13} /></button>
    ${open && html`<div class="bc-ov-menu" role="menu" aria-label=${ariaName}>
      ${['all', 'none', 'custom'].map((m) => html`<button
        key=${m} type="button" role="menuitemradio"
        aria-checked=${mode === m}
        disabled=${m === 'custom' && customDisabled}
        title=${m === 'custom' && customDisabled ? 'No custom selection saved yet — toggle individual checkboxes to make one' : ''}
        class="bc-ov-item${mode === m ? ' is-sel' : ''}"
        onClick=${() => { setOpen(false); if (m !== mode) onPick(m); }}
      ><span class="bc-ov-check" aria-hidden="true">${mode === m ? html`<${Icon} name="check" size=${11} />` : ''}</span><${Icon} name=${MODE_ICONS[m]} size=${12} />${MODE_LABELS[m]}</button>`)}
    </div>`}
  </div>`;
}

// The same control for the All group. It governs exactly the calendars that
// section lists — which is now every one of them — so All / None / Custom
// there means what it says.
function AllModeButton() {
  return html`<${ModeMenu}
    mode=${folderMode(ALL_GROUP_ID)}
    tip="Visibility for every calendar: All shows them all, None hides them all, Custom is your own selection."
    ariaName="Visibility for every calendar"
    customDisabled=${false}
    onPick=${(m) => setFolderVisibilityMode(ALL_GROUP_ID, m)}
  />`;
}

// Display-only: hides unchecked rows without changing a single calendar's
// visibility. Scoped to the section it sits in — a control in one header that
// silently reordered the sections above it was disorienting, and it read as
// odd that a global switch lived inside one group.
function ActiveOnlyButton() {
  const on = useStore((s) => !!s.settings.sidebarActiveOnly);
  return html`<button
    type="button" class="bc-icon-btn bc-folder-tool bc-activeonly${on ? ' is-on' : ''}"
    aria-pressed=${on}
    aria-label=${on ? 'List every calendar in this group' : 'List only active calendars in this group'}
    title=${on
      ? 'Listing only active calendars here — click to list them all again'
      : 'List only the active calendars in this group. Changes what the list shows, not which calendars are on.'}
    onClick=${() => setSidebarActiveOnly(!on)}
  ><${Icon} name="activeOnly" size=${13} /></button>`;
}

function FolderModeButton({ folder }) {
  return html`<${ModeMenu}
    mode=${folderMode(folder.id)}
    tip="Folder visibility: All shows every calendar, None hides them all, Custom is your own per-calendar selection."
    ariaName=${'Visibility for ' + folder.name}
    customDisabled=${false}
    onPick=${(m) => setFolderVisibilityMode(folder.id, m)}
  />`;
}

// People folder visibility; None is the one-click "silence every
// availability indicator".
function PeopleModeButton() {
  const { peopleVisCustom } = useStore((s) => ({ people: s.people, peopleVisCustom: s.peopleVisCustom }), shallowEq);
  const mode = peopleVisibilityMode();
  return html`<${ModeMenu}
    mode=${mode}
    tip="People visibility: All shows everyone's away/busy spans, None hides them all, Custom is your own per-person selection."
    ariaName="People visibility"
    customDisabled=${mode !== 'custom' && !(peopleVisCustom && peopleVisCustom.length > 0)}
    onPick=${setPeopleVisibilityMode}
  />`;
}

// Folder header: collapse, visibility mode, hover-revealed + (new calendar
// in this folder) and a gear opening a small options panel (rename, delete).
function FolderHead({ folder, cals, collapsed, onToggleCollapse }) {
  const [options, setOptions] = useState(false);
  const [renameTo, setRenameTo] = useState(folder.name);

  const remove = () => {
    if (cals.length > 0) {
      toast('Folder has ' + cals.length + ' calendar' + (cals.length === 1 ? '' : 's') +
        '; remove them from the folder first (calendar settings > folders)', { error: true });
      return;
    }
    if (window.confirm('Delete folder "' + folder.name + '"?')) deleteFolder(folder);
  };

  const submitRename = async (e) => {
    e.preventDefault();
    const name = renameTo.trim();
    setOptions(false);
    if (!name || name === folder.name) return;
    try {
      await api('/folders/' + folder.id, { method: 'PATCH', body: { name } });
      await loadCalendars();
    } catch (err) {
      toast('Rename failed: ' + err.message, { error: true });
    }
  };

  return html`<div class="bc-folder-headwrap">
    <div class="bc-folder-headrow">
      <button
        type="button" class="bc-folder-head"
        aria-expanded=${!collapsed}
        onClick=${onToggleCollapse}
      >
        <span class="bc-folder-caret${collapsed ? ' is-closed' : ''}"><${Icon} name="chevronDown" size=${13} /></span>
        <span class="bc-folder-icon"><${Icon} name="folder" size=${13} /></span>
        <span class="bc-folder-name" title=${folder.name}>${folder.name}</span>
      </button>
      ${cals.length > 0 && html`<${FolderModeButton} folder=${folder} />`}
      <span class="bc-folder-tools">
        <button
          type="button" class="bc-icon-btn bc-folder-tool"
          aria-label=${'New calendar in ' + folder.name} title="New calendar in this folder"
          onClick=${() => set({ createDrawer: { kind: 'calendar', folderId: folder.id } })}
        ><${Icon} name="plus" size=${13} /></button>
        <button
          type="button" class="bc-icon-btn bc-folder-tool"
          aria-label=${'Options for folder ' + folder.name} aria-expanded=${options}
          title="Folder options"
          onClick=${() => { setRenameTo(folder.name); setOptions(!options); }}
        ><${Icon} name="settings" size=${12} /></button>
      </span>
    </div>
    ${options && html`<div class="bc-folder-options">
      <form class="bc-folder-renameform" onSubmit=${submitRename}>
        <input value=${renameTo} onInput=${(e) => setRenameTo(e.target.value)} aria-label="Folder name" />
        <button type="submit" class="bc-btn">Rename</button>
      </form>
      <button
        type="button" class="bc-btn bc-btn-danger"
        title=${cals.length > 0 ? 'Only empty folders can be deleted' : 'Delete folder'}
        onClick=${remove}
      >Delete folder</button>
    </div>`}
  </div>`;
}

// Creation launchers: every "+ ..." opens the titled CreateDrawer (the
// slide-in from the right), never an anonymous inline form.
function AddMenu() {
  return html`<div class="bc-add-menu">
    <button type="button" class="bc-link-btn" onClick=${() => set({ createDrawer: { kind: 'calendar' } })}>+ New calendar</button>
    <button type="button" class="bc-link-btn" onClick=${() => set({ createDrawer: { kind: 'subscribe' } })}>+ Subscribe URL</button>
    <button type="button" class="bc-link-btn" onClick=${() => set({ createDrawer: { kind: 'import' } })}>+ Import ICS</button>
    <button type="button" class="bc-link-btn" onClick=${() => set({ createDrawer: { kind: 'folder' } })}>+ New folder</button>
  </div>`;
}

// MANAGE_ITEMS lives in commanddefs.js so the sidebar footer and the
// command palette offer exactly the same pages.

export function Sidebar({ open, collapsed, onClose }) {
  const { calendars, folders, collapsedFolders, route, people, collapsedAllCals, collapsedPeople, peopleSolo, activeOnly, plugins, pluginHidden, proposalCount } = useStore(
    (s) => ({
      calendars: s.calendars, folders: s.folders,
      collapsedFolders: s.collapsedFolders, route: s.route,
      folderVisibility: s.folderVisibility,
      people: s.people, collapsedAllCals: s.collapsedAllCals,
      plugins: s.plugins, pluginHidden: s.settings.pluginHidden || {},
      proposalCount: s.proposalCount,
      collapsedPeople: s.collapsedPeople, peopleSolo: s.peopleSolo,
      activeOnly: !!s.settings.sidebarActiveOnly,
    }),
    shallowEq,
  );
  // A calendar filed in a folder is listed twice — once under its folder and
  // once in All calendars — so the open gear is keyed by SECTION as well as
  // id; keying by id alone opened both copies of the settings panel at once.
  const [openGear, setOpenGear] = useState(null); // "<section>:<calendarId>"
  const [solo, setSolo] = useState(null); // {calId, prev: [[id, visible], ...]}

  const toggleGear = (key) => setOpenGear(openGear === key ? null : key);

  // Enabled plugins that contribute overlay bands get a visibility row; the
  // toggle is a persisted setting so it survives reloads.
  const layerPlugins = (plugins || []).filter((pl) => pl.enabled && (pl.permissions || []).includes('ranges'));
  const togglePluginLayer = (id) => {
    saveSetting('pluginHidden', { ...(state.settings.pluginHidden || {}), [id]: !pluginHidden[id] });
    set({ pluginSeq: state.pluginSeq + 1 });
  };

  // Drag a person out of the sidebar and drop them on an event chip to link
  // them to that event. The 4px lift threshold keeps plain clicks working
  // everywhere — including on the name button, which is the most natural
  // thing to grab — so only the checkbox keeps its native press behavior.
  const dragPersonStart = (p, ev) => {
    if (ev.target.closest('input')) return;
    if (ev.pointerType === 'touch') return; // sidebar scroll wins on touch
    let ext = null;
    const linkable = (iid) => {
      if (String(iid).startsWith('avail:')) return false;
      const occ = state.occ.get(iid);
      if (!occ || (occ.people || []).includes(p.name)) return false;
      const cal = state.calendars.find((c) => c.id === occ.calendarId);
      return !cal || cal.kind !== 'subscribed'; // feed events are read-only
    };
    startPointerDrag(ev, {
      makeGhost: () => {
        const g = document.createElement('div');
        g.className = 'bc-drag-personpill';
        g.textContent = p.name;
        return g;
      },
      onMove: (pt) => {
        ext = externalDropTarget(pt, { instance: linkable });
        setDropRowHighlight(ext ? ext.el : null);
      },
      onDrop: (pt) => {
        setDropRowHighlight(null);
        if (!ext) return;
        const occ = state.occ.get(ext.instanceId);
        if (occ && occ.recurring) {
          // Repeating event: ask which occurrences take the person.
          set({
            dropChoice: {
              x: pt.x, y: pt.y, title: 'Repeating event',
              options: [
                { label: 'This event', value: 'this' },
                { label: 'This + following', value: 'following' },
                { label: 'All events', value: 'all' },
                { label: 'Cancel', value: null },
              ],
              cb: (v) => { if (v) linkPersonToEvent(occ, p.name, v); },
            },
          });
          return;
        }
        linkPersonToEvent(occ, p.name);
      },
      onCancel: () => setDropRowHighlight(null),
    });
  };

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

  const byFolder = folders.map((f) => ({
    folder: f,
    cals: calendars.filter((c) => (c.folderIds || []).includes(f.id)),
  }));
  // "All calendars" means all of them. A calendar filed in a folder is listed
  // in both places on purpose: this section doubles as the management surface
  // (every row carries its own gear), so having to remember which folder a
  // calendar lives in just to reach its settings was the friction. The
  // duplicate rows share one visible flag, so their checkboxes never diverge;
  // a folder glyph on the right marks the ones that appear above as well.
  const folderNamesOf = (cal) => folders
    .filter((f) => (cal.folderIds || []).includes(f.id))
    .map((f) => f.name);

  const toggleFolder = (id) => {
    set({ collapsedFolders: { ...collapsedFolders, [id]: !collapsedFolders[id] } });
  };

  // "Only active" drops unchecked rows from the LIST, and only in the group
  // whose header carries the button. The row whose gear is open stays
  // regardless: switching a calendar off from its own settings panel would
  // otherwise yank the panel out from under the pointer.
  const listed = (cals, section) => (activeOnly && section === 'all'
    ? cals.filter((c) => c.visible || openGear === 'all:' + c.id)
    : cals);

  const rows = (cals, section) => listed(cals, section).map((c) => html`<${CalendarRow}
    key=${c.id} cal=${c} folders=${folders}
    open=${openGear === section + ':' + c.id}
    onGear=${() => toggleGear(section + ':' + c.id)}
    filedIn=${section === 'all' ? folderNamesOf(c) : null}
    soloed=${solo && solo.calId === c.id}
    onSolo=${(cal) => (solo && solo.calId === cal.id ? exitSolo() : enterSolo(cal))}
  />`);

  return html`<aside class="bc-sidebar${open ? ' is-open' : ''}${collapsed ? ' is-collapsed' : ''}">
    ${onClose && html`<div class="bc-sidebar-mobilehead">
      <span class="bc-manage-head">Calendars</span>
      <button type="button" class="bc-icon-btn" aria-label="Close sidebar" onClick=${onClose}><${Icon} name="close" size=${15} /></button>
    </div>`}
    <div class="bc-sidebar-scroll">
      <${MiniMonth} />
      ${byFolder.map(({ folder, cals }) => html`<section key=${'f' + folder.id} class="bc-folder">
        <${FolderHead}
          folder=${folder} cals=${cals}
          collapsed=${!!collapsedFolders[folder.id]}
          onToggleCollapse=${() => toggleFolder(folder.id)}
        />
        ${!collapsedFolders[folder.id] && html`<div class="bc-folder-body">${rows(cals, 'f' + folder.id)}</div>`}
      </section>`)}
      <section class="bc-folder">
        ${byFolder.length > 0 && html`<div class="bc-folder-headrow">
          <button
            type="button" class="bc-folder-head bc-folder-static"
            aria-expanded=${!collapsedAllCals}
            onClick=${() => set({ collapsedAllCals: !collapsedAllCals })}
          ><span class="bc-folder-caret${collapsedAllCals ? ' is-closed' : ''}"><${Icon} name="chevronDown" size=${13} /></span>All calendars</button>
          <span class="bc-folder-tools">
            <button
              type="button" class="bc-icon-btn bc-folder-tool"
              aria-label="New calendar" title="New calendar"
              onClick=${() => set({ createDrawer: { kind: 'calendar' } })}
            ><${Icon} name="plus" size=${13} /></button>
            <${ActiveOnlyButton} />
            <${AllModeButton} />
          </span>
        </div>`}
        ${(byFolder.length === 0 || !collapsedAllCals) && rows(calendars, 'all')}
      </section>
      ${people.length > 0 && html`<section class="bc-folder">
        <div class="bc-folder-headrow">
          <button
            type="button" class="bc-folder-head bc-folder-static"
            aria-expanded=${!collapsedPeople}
            onClick=${() => set({ collapsedPeople: !collapsedPeople })}
          ><span class="bc-folder-caret${collapsedPeople ? ' is-closed' : ''}"><${Icon} name="chevronDown" size=${13} /></span>People</button>
          <${PeopleModeButton} />
          <span class="bc-folder-tools">
            <button
              type="button" class="bc-icon-btn bc-folder-tool"
              aria-label="New person" title="New person"
              onClick=${() => set({ createDrawer: { kind: 'person' } })}
            ><${Icon} name="plus" size=${13} /></button>
          </span>
        </div>
        ${!collapsedPeople && people.map((p) => html`<div key=${p.id} class="bc-cal-item">
          <div
            class="bc-cal-row${p.currentSpan && p.currentSpan.kind === 'away' ? ' is-person-away' : ''}${p.showOnCalendar ? '' : ' is-off'}"
            data-drop-person=${p.id}
            data-person-name=${p.name}
            onPointerDown=${(e) => dragPersonStart(p, e)}
          >
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
              ${p.currentSpan && html`<span class="bc-badge bc-away-pill is-${p.currentSpan.kind}">${p.currentSpan.kind}</span>`}
            </span>
            <button
              type="button"
              class="bc-icon-btn bc-cal-solo${peopleSolo && peopleSolo.personId === p.id ? ' is-on' : ''}"
              aria-pressed=${peopleSolo && peopleSolo.personId === p.id}
              title=${peopleSolo && peopleSolo.personId === p.id ? 'Showing only this person. Click to restore.' : "Show only this person's spans"}
              onClick=${(e) => { if (peopleSolo && peopleSolo.personId === p.id) exitPeopleSolo(); else enterPeopleSolo(p); e.currentTarget.blur(); }}
            >${peopleSolo && peopleSolo.personId === p.id ? html`Only <${Icon} name="check" size=${10} />` : 'Only'}</button>
          </div>
        </div>`)}
      </section>`}
      ${layerPlugins.length > 0 && html`<section class="bc-folder" aria-label="Plugin layers">
        <div class="bc-folder-head">
          <span class="bc-folder-name"><${Icon} name="plugins" size=${13} /> Plugins</span>
        </div>
        ${layerPlugins.map((pl) => html`<div key=${pl.id} class="bc-cal-item">
          <div class="bc-cal-row${pluginHidden[pl.id] ? ' is-off' : ''}">
            <label class="bc-cal-label" title=${pl.name + ' overlay bands'}>
              <input
                type="checkbox"
                checked=${!pluginHidden[pl.id]}
                onChange=${() => togglePluginLayer(pl.id)}
                aria-label=${'Show ' + pl.name + ' bands on the calendar'}
              />
              <span class="bc-cal-name">${pl.name}</span>
            </label>
            <button type="button" class="bc-icon-btn" title="Plugin settings" onClick=${() => set({ route: 'plugins' })}><${Icon} name="settings" size=${13} /></button>
          </div>
        </div>`)}
      </section>`}
      <${AddMenu} />
      ${soloCal && html`<div class="bc-solo-banner" role="status">
        Showing only <strong>${soloCal.name}</strong>
        <button type="button" class="bc-link-btn" onClick=${exitSolo}>Show all</button>
      </div>`}
    </div>
    <footer class="bc-sidebar-foot">
      <div class="bc-manage-head">Manage</div>
      ${MANAGE_ITEMS.map(([r, label]) => html`<button
        key=${r} type="button"
        class="bc-manage-item${route === r ? ' is-active' : ''}"
        aria-current=${route === r ? 'page' : 'false'}
        onClick=${() => set({ route: r })}
      ><${Icon} name=${r} /><span>${label}</span>${r === 'proposals' && proposalCount > 0
        && html`<span class="bc-manage-badge" aria-label=${proposalCount + ' awaiting a decision'}>${proposalCount}</span>`}</button>`)}
    </footer>
  </aside>`;
}
