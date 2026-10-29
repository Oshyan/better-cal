// Sidebar: folders > calendars with color dots + visibility toggles, folder
// visibility modes (all/none/custom), per-calendar settings panels (gear),
// folder gear options (rename/delete), the People folder (availability
// visibility), hover "+" launchers and the creation launcher list (all of
// which open the titled CreateDrawer), feed health badges, and the manage
// navigation in the footer.
//
// On a phone (0.8.0) the drawer is arranged for a thumb: the pages are a
// short grid at the bottom (0.8.2: below the calendars, since they are
// visited far less there; Review is left to the bottom bar), each calendar is listed once (in its folder, the
// rest under Other calendars), and a row's Only and settings sit behind a
// press and hold instead of taking width from every name.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, state, toast, shallowEq } from './store.js';
import { api, loadCalendars } from './api.js';
import {
  toggleCalendarVisible, createFolder, deleteFolder,
  folderMode, setFolderVisibilityMode, ALL_GROUP_ID, setSidebarActiveOnly,
  togglePersonVisible, enterPeopleSolo, exitPeopleSolo,
  peopleVisibilityMode, setPeopleVisibilityMode, linkPersonToEvent, saveSetting, setSidebarOrder,
} from './actions.js';
import { sidebarGroups, moveGroup } from '../lib/sidebarorder.js';

// What a dragged sidebar group carries, so other drags never read as one.
const GROUP_MIME = 'application/x-bettercal-group';
import { startPointerDrag, externalDropTarget, setDropRowHighlight } from '../ui/DragController.js';
import { CalendarSettings } from './CalendarSettings.js';
import { needsAttention } from './system.js';
import { MiniMonth } from './MiniMonth.js';
import { PALETTE } from '../lib/color.js';
import { MANAGE_ITEMS } from './commanddefs.js';
import { Icon, CalDot, GoogleG } from '../ui/icons.js';
import { attachScrollRail } from '../ui/scrollrail.js';
import { COARSE_QUERY, PHONE_QUERY } from '../lib/breakpoints.js';
import { onOutsidePress, insideAny } from '../ui/outside.js';
import { useLongPress, RowMenu } from '../ui/rowmenu.js';
import {
  readSidebarWidth, saveSidebarWidth, applySidebarWidth, clampSidebarWidth, SIDEBAR_DEFAULT, SIDEBAR_MIN, SIDEBAR_MAX,
} from '../lib/sidebarwidth.js';

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

// Three things can be wrong with a feed and they want different reactions:
// the fetch failed (error), the fetch worked but came back with nothing where
// there used to be something (emptied — how expired tokens fail, silently),
// or nothing has changed in a long while and nothing is coming up (stale).
// A feed that is simply empty, and always has been, is not a problem and
// gets no badge; the settings line says "no events yet".
function healthBadge(cal, onOpen) {
  if (cal.kind !== 'subscribed' || !cal.health) return null;
  const { status, content, error, lastPolledAt } = cal.health;
  const when = lastPolledAt ? ' (last poll ' + lastPolledAt + ')' : '';
  let tip = null;
  if (cal.subscriptionAuthorization && cal.subscriptionAuthorization.status === 'paused') {
    tip = (cal.subscriptionAuthorization.reason || 'Updates are paused until you review this subscription.') + when;
  } else if (status === 'error') tip = 'Feed error: ' + (error || 'unknown') + when;
  else if (content === 'emptied') tip = 'Feed came back empty; it had events before. The source may have broken or revoked access' + when;
  else if (content === 'stale') tip = 'Feed is stale: nothing has changed for a while and nothing is upcoming' + when;
  if (!tip) return null;
  return html`<button
    type="button"
    class="bc-health"
    aria-label=${'Attention needed for ' + cal.name + '. Open calendar options.'}
    title=${tip + '. Click to open calendar options.'}
    onPointerDown=${(e) => e.stopPropagation()}
    onContextMenu=${(e) => { e.preventDefault(); e.stopPropagation(); }}
    onClick=${(e) => { e.stopPropagation(); onOpen(); }}
  ><${Icon} name="warning" size=${12} /></button>`;
}

// A plugin-owned calendar wears its plugin's declared decoration. Read from the
// loaded plugin list rather than the calendar payload, so it tracks the
// manifest without a schema field to keep in sync.
/**
 * Google's mark after a Google calendar's name. A calendar subscribed by web
 * address says so with its link icon in place of the dot (ui/icons.js
 * CalDot), as a plugin calendar shows its own icon there.
 */
function calSourceMark(cal) {
  if (cal.kind !== 'subscribed' || cal.pluginId || cal.provider !== 'google') return null;
  const label = cal.googleAccountEmail
    ? `From Google Calendar (${cal.googleAccountEmail})${cal.editable ? '; edits here go to Google' : '; view only'}`
    : 'From Google Calendar; its account was disconnected, so it no longer updates';
  return html`<span class="bc-cal-src is-google" role="img" aria-label=${label} title=${label}><${GoogleG} size=${11} /></span>`;
}

function pluginDecoFor(cal) {
  if (!cal || !cal.pluginId) return null;
  const pl = (state.plugins || []).find((p) => p.id === cal.pluginId);
  return (pl && pl.decoration) || null;
}

// The sidebar's right edge (#129): drag to resize, arrows to nudge, Home or a
// double click for the default. Desktop only; the drawer layouts hide it.
function SidebarResize() {
  const [width, setWidth] = useState(readSidebarWidth);
  const drag = useRef(null);
  const commit = (w) => setWidth(saveSidebarWidth(w));
  const onPointerDown = (e) => {
    if (e.button !== 0) return;
    e.preventDefault();
    e.currentTarget.setPointerCapture(e.pointerId);
    drag.current = { x: e.clientX, w: width, last: width };
    document.documentElement.classList.add('bc-resizing-sidebar');
  };
  const onPointerMove = (e) => {
    const d = drag.current;
    if (!d) return;
    d.last = clampSidebarWidth(d.w + e.clientX - d.x);
    applySidebarWidth(d.last);
  };
  // A mouse leaves no focus behind on the edge: the arrow keys go on
  // moving through the calendar. Only a keyboard user who tabbed here
  // resizes with them.
  const end = (e) => {
    const d = drag.current;
    if (!d) return;
    drag.current = null;
    document.documentElement.classList.remove('bc-resizing-sidebar');
    commit(d.last);
    if (e && e.currentTarget && e.currentTarget.blur) e.currentTarget.blur();
  };
  const onKeyDown = (e) => {
    const step = e.shiftKey ? 48 : 16;
    if (e.key === 'ArrowLeft') commit(width - step);
    else if (e.key === 'ArrowRight') commit(width + step);
    else if (e.key === 'Home') commit(SIDEBAR_DEFAULT);
    else return;
    e.preventDefault();
    e.stopPropagation(); // not also a step through the calendar
  };
  return html`<div
    class="bc-sidebar-resize" role="separator" aria-orientation="vertical" tabindex="0"
    aria-label="Sidebar width" title="Drag to resize; double-click for the default"
    aria-valuemin=${SIDEBAR_MIN} aria-valuemax=${SIDEBAR_MAX} aria-valuenow=${width}
    onPointerDown=${onPointerDown} onPointerMove=${onPointerMove}
    onPointerUp=${end} onPointerCancel=${end} onLostPointerCapture=${end}
    onDblClick=${(e) => { commit(SIDEBAR_DEFAULT); e.currentTarget.blur(); }} onKeyDown=${onKeyDown}
  ></div>`;
}

function CalendarRow({ cal, folders, open, onGear, soloed, onSolo, filedIn, phone = false, onMenu = null }) {
  const press = useLongPress(() => onMenu && onMenu());
  // data-drop-cal marks writable calendars as drag targets: dropping an event
  // here moves it onto this calendar (feeds are read-only, so no attribute).
  return html`<div class="bc-cal-item">
    <div
      class="bc-cal-row${cal.visible ? '' : ' is-off'}${phone ? ' is-held' : ''}"
      data-drop-cal=${cal.kind === 'subscribed' ? undefined : cal.id}
      ...${phone ? press : {}}
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
        ${calSourceMark(cal)}
        ${filedIn && filedIn.length > 0 && html`<span
          class="bc-cal-filed" role="img"
          aria-label=${'Also in ' + filedIn.join(', ')}
          title=${'Also listed in ' + filedIn.join(', ')}
        ><${Icon} name="folder" size=${11} /></span>`}
      </label>
      ${healthBadge(cal, onGear)}
      ${(!phone || soloed) && html`<button
        type="button"
        class="bc-icon-btn bc-cal-solo${soloed ? ' is-on' : ''}"
        aria-label=${soloed ? 'Stop showing only ' + cal.name : 'Show only ' + cal.name}
        aria-pressed=${soloed}
        title=${soloed ? 'Showing only this calendar. Click to restore.' : 'Show only this calendar'}
        onClick=${(e) => { onSolo(cal); e.currentTarget.blur(); }}
      >${soloed ? html`Only <${Icon} name="check" size=${10} />` : 'Only'}</button>`}
      ${!phone && html`<button
        type="button"
        class="bc-icon-btn bc-cal-gear${open ? ' is-open' : ''}"
        aria-label=${'Settings for ' + cal.name}
        aria-expanded=${open}
        title="Calendar settings"
        onClick=${() => onGear()}
      ><${Icon} name="settings" size=${13} /></button>`}
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
    return onOutsidePress(insideAny(rootRef), () => setOpen(false));
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
    tip="People visibility: All shows everyone's away and busy times, None hides them all, Custom is your own per-person selection."
    ariaName="People visibility"
    customDisabled=${mode !== 'custom' && !(peopleVisCustom && peopleVisCustom.length > 0)}
    onPick=${setPeopleVisibilityMode}
  />`;
}

// Folder header: collapse, visibility mode, hover-revealed + (new calendar
// in this folder) and a gear opening a small options panel (rename, delete).
function FolderHead({ folder, cals, collapsed, onToggleCollapse, dragProps }) {
  const [options, setOptions] = useState(false);
  const [renameTo, setRenameTo] = useState(folder.name);

  const remove = () => {
    if (cals.length > 0) {
      toast('Folder has ' + cals.length + ' calendar' + (cals.length === 1 ? '' : 's') +
        '; remove them from the folder first (calendar settings > folders)', { error: true });
      return;
    }
    deleteFolder(folder); // undoable from its notice
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
    <div class="bc-folder-headrow" ...${dragProps || {}}>
      <button
        type="button" class="bc-folder-head"
        aria-expanded=${!collapsed}
        onClick=${onToggleCollapse}
      >
        <span class="bc-folder-caret${collapsed ? ' is-closed' : ''}"><${Icon} name="chevronDown" size=${13} /></span>
        <span class="bc-folder-icon"><${Icon} name="folder" size=${13} /></span>
        <span class="bc-folder-name" title=${folder.name}>${folder.name}</span>
      </button>
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
        ${cals.length > 0 && html`<${FolderModeButton} folder=${folder} />`}
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

function PersonRow({ p, soloed, phone, onDragStart, onMenu }) {
  const press = useLongPress(() => onMenu && onMenu());
  const toggleSolo = () => (soloed ? exitPeopleSolo() : enterPeopleSolo(p));
  return html`<div class="bc-cal-item">
    <div
      class="bc-cal-row${p.currentSpan && p.currentSpan.kind === 'away' ? ' is-person-away' : ''}${p.showOnCalendar ? '' : ' is-off'}${phone ? ' is-held' : ''}"
      data-drop-person=${p.id}
      data-person-name=${p.name}
      onPointerDown=${(e) => { if (phone) press.onPointerDown(e); onDragStart(e); }}
      ...${phone ? { onPointerMove: press.onPointerMove, onPointerUp: press.onPointerUp, onPointerCancel: press.onPointerCancel, onContextMenu: press.onContextMenu, onClickCapture: press.onClickCapture } : {}}
    >
      <span class="bc-cal-label">
        <input
          type="checkbox"
          checked=${p.showOnCalendar}
          onChange=${() => togglePersonVisible(p)}
          title="Show away and busy times on the calendar"
          aria-label=${'Show ' + p.name + ' availability on calendar'}
        />
        <button
          type="button" class="bc-cal-name bc-person-namebtn"
          title=${(p.currentSpan ? p.name + ' is ' + p.currentSpan.kind + ' now. ' : '') + 'Open person'}
          onClick=${() => set({ route: 'people', peopleFocus: p.name })}
        >${p.name}</button>
        ${p.currentSpan && html`<span class="bc-badge bc-away-pill is-${p.currentSpan.kind}">${p.currentSpan.kind}</span>`}
      </span>
      ${(!phone || soloed) && html`<button
        type="button"
        class="bc-icon-btn bc-cal-solo${soloed ? ' is-on' : ''}"
        aria-pressed=${soloed}
        title=${soloed ? 'Showing only this person. Click to restore.' : "Show only this person's availability"}
        onClick=${(e) => { toggleSolo(); e.currentTarget.blur(); }}
      >${soloed ? html`Only <${Icon} name="check" size=${10} />` : 'Only'}</button>`}
    </div>
  </div>`;
}

// Creation launchers: every "+ ..." opens the titled CreateDrawer (the
// slide-in from the right), never an anonymous inline form.
function AddMenu() {
  return html`<div class="bc-add-menu">
    <button type="button" class="bc-link-btn" onClick=${() => set({ createDrawer: { kind: 'calendar' } })}>+ New calendar</button>
    <button type="button" class="bc-link-btn" onClick=${() => set({ createDrawer: { kind: 'subscribe' } })}>+ Subscribe URL</button>
    <button type="button" class="bc-link-btn" onClick=${() => set({ createDrawer: { kind: 'import' } })}>+ Import calendars</button>
    <button type="button" class="bc-link-btn" onClick=${() => set({ createDrawer: { kind: 'folder' } })}>+ New folder</button>
  </div>`;
}

// MANAGE_ITEMS lives in commanddefs.js so the sidebar footer and the
// command palette offer exactly the same pages.
// The phone's grid (0.8.2): Review is on the bottom bar and Saved views in
// its View sheet, so neither repeats here; the long names sit in the wider
// left column (the grid fills row by row).
const PHONE_PAGES = ['organize', 'settings', 'people', 'activity', 'plugins', 'filters'];

export function Sidebar({ open, collapsed, onClose }) {
  // On touch screens the scroll bar is drawn, so it shows at rest and says
  // there is more below (ui/scrollrail.js); elsewhere the native one shows.
  const scrollRef = useRef(null);
  useEffect(() => {
    const el = scrollRef.current;
    if (!el || !window.matchMedia(COARSE_QUERY).matches) return undefined;
    return attachScrollRail(el);
  }, []);
  // The Manage group is admin and configuration, visited rarely, so it
  // starts folded and stays how you left it. Review is the exception
  // (an inbox, not admin): its count rides the folded header so nothing
  // waiting on a decision is ever out of sight.
  const [manageCollapsed, setManageCollapsed] = useState(() => {
    try { return localStorage.getItem('bc-manage-collapsed') !== '0'; } catch { return true; }
  });
  const toggleManage = () => {
    const next = !manageCollapsed;
    setManageCollapsed(next);
    try { localStorage.setItem('bc-manage-collapsed', next ? '1' : '0'); } catch { /* private mode */ }
  };
  const { calendars, folders, collapsedFolders, route, people, collapsedAllCals, sidebarOrder, collapsedPeople, peopleSolo, activeOnly, plugins, pluginHidden, reviewCount, attention } = useStore(
    (s) => ({
      calendars: s.calendars, folders: s.folders,
      collapsedFolders: s.collapsedFolders, route: s.route,
      folderVisibility: s.folderVisibility,
      people: s.people, collapsedAllCals: s.collapsedAllCals, sidebarOrder: s.settings.sidebarOrder,
      plugins: s.plugins, pluginHidden: s.settings.pluginHidden || {},
      reviewCount: s.reviewCount,
      attention: needsAttention(s.systemHealth),
      collapsedPeople: s.collapsedPeople, peopleSolo: s.peopleSolo,
      activeOnly: !!s.settings.sidebarActiveOnly,
    }),
    shallowEq,
  );
  // A group being dragged to a new place, and where it would land.
  const [dragGroup, setDragGroup] = useState(null);
  const [dropAt, setDropAt] = useState(null); // {key, after}
  // A calendar filed in a folder is listed twice — once under its folder and
  // once in All calendars — so the open gear is keyed by SECTION as well as
  // id; keying by id alone opened both copies of the settings panel at once.
  const [openGear, setOpenGear] = useState(null); // "<section>:<calendarId>"
  const [solo, setSolo] = useState(null); // {calId, prev: [[id, visible], ...]}
  const [menu, setMenu] = useState(null); // phone: {head, color, items} of a held row
  const phone = (() => { try { return window.matchMedia(PHONE_QUERY).matches; } catch { return false; } })();
  // A phone lists a calendar once: in the first folder it is filed in, else
  // under Other calendars. That is the section its settings open in.
  const homeSection = (calId) => {
    const cal = state.calendars.find((c) => c.id === calId);
    const f = cal && state.folders.find((fo) => (cal.folderIds || []).includes(fo.id));
    return f ? 'f' + f.id : 'all';
  };

  const toggleGear = (key) => setOpenGear(openGear === key ? null : key);
  // Asked for from the event sheet's More ("Manage calendar"): open that
  // calendar's settings in All calendars and bring it into view.
  const manageCal = useStore((st) => st.manageCal);
  useEffect(() => {
    if (!manageCal) return;
    const section = phone ? homeSection(manageCal) : 'all';
    setOpenGear(section + ':' + manageCal);
    if (section !== 'all' && state.collapsedFolders[section.slice(1)]) {
      set({ collapsedFolders: { ...state.collapsedFolders, [section.slice(1)]: false } });
    }
    if (section === 'all' && state.collapsedAllCals) set({ collapsedAllCals: false });
    set({ manageCal: null });
    requestAnimationFrame(() => requestAnimationFrame(() => {
      const el = document.querySelector('.bc-calset');
      if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }));
  }, [manageCal]);

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
              cb: (v) => { if (v) linkPersonToEvent(occ, p, v); },
            },
          });
          return;
        }
        linkPersonToEvent(occ, p);
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
  // On a phone the button sits over every section, so it applies to all.
  const listed = (cals, section) => (activeOnly && (section === 'all' || phone)
    ? cals.filter((c) => c.visible || openGear === section + ':' + c.id)
    : cals);

  const calMenu = (c, section) => {
    const soloed = solo && solo.calId === c.id;
    setMenu({
      head: c.name, color: c.color || 'var(--border-strong)',
      items: [
        soloed ? ['visAll', 'Show all calendars again', exitSolo] : ['visNone', 'Show only this calendar', () => enterSolo(c)],
        ['settings', openGear === section + ':' + c.id ? 'Close its settings' : 'Calendar settings', () => toggleGear(section + ':' + c.id)],
      ],
    });
  };

  const rows = (cals, section) => listed(cals, section).map((c) => html`<${CalendarRow}
    key=${c.id} cal=${c} folders=${folders}
    open=${openGear === section + ':' + c.id}
    onGear=${() => toggleGear(section + ':' + c.id)}
    filedIn=${section === 'all' && !phone ? folderNamesOf(c) : null}
    soloed=${solo && solo.calId === c.id}
    onSolo=${(cal) => (solo && solo.calId === cal.id ? exitSolo() : enterSolo(cal))}
    phone=${phone}
    onMenu=${() => calMenu(c, section)}
  />`);
  const unfiled = calendars.filter((c) => !folders.some((f) => (c.folderIds || []).includes(f.id)));
  const allCalTools = html`<span class="bc-folder-tools">
    <button
      type="button" class="bc-icon-btn bc-folder-tool"
      aria-label="New calendar" title="New calendar"
      onClick=${() => set({ createDrawer: { kind: 'calendar' } })}
    ><${Icon} name="plus" size=${13} /></button>
    <${ActiveOnlyButton} />
    <${AllModeButton} />
  </span>`;
  const pagesGrid = html`<nav class="bc-sb-pages" aria-label="Manage">
    <div class="bc-sb-pageshead">Manage</div>
    ${PHONE_PAGES.map((r) => MANAGE_ITEMS.find(([id]) => id === r)).filter(Boolean).map(([r, label]) => html`<button
      key=${r} type="button"
      class="bc-sb-page${route === r ? ' is-active' : ''}"
      aria-current=${route === r ? 'page' : 'false'}
      onClick=${() => set({ route: r })}
    ><${Icon} name=${r} size=${17} /><span>${label}</span>${r === 'review' && reviewCount > 0
      && html`<span class="bc-manage-badge" aria-label=${reviewCount + ' awaiting a decision'}>${reviewCount}</span>`}${r === 'settings' && attention && html`<span class="bc-attn-dot" role="img" aria-label="Needs attention" title="Something needs your attention: Settings, System"></span>`}</button>`)}
  </nav>`;

  // The groups by key, shown in the order chosen (lib/sidebarorder.js). On
  // a computer a group's header drags to a new place; the Organize page has
  // Move up and Move down for phones and the keyboard.
  const groupKeys = sidebarGroups(folders, sidebarOrder);
  const groupDrag = (key) => (phone ? {} : {
    draggable: true,
    onDragStart: (e) => {
      e.dataTransfer.setData(GROUP_MIME, key);
      e.dataTransfer.effectAllowed = 'move';
      setDragGroup(key);
    },
    onDragEnd: () => { setDragGroup(null); setDropAt(null); },
  });
  const onGroupDragOver = (e) => {
    if (!dragGroup) return;
    const sec = e.target.closest && e.target.closest('[data-group]');
    if (!sec) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const r = sec.getBoundingClientRect();
    const key = sec.dataset.group;
    const after = e.clientY > r.top + r.height / 2;
    if (!dropAt || dropAt.key !== key || dropAt.after !== after) setDropAt({ key, after });
  };
  const onGroupDrop = (e) => {
    if (!dragGroup) return;
    e.preventDefault();
    const target = dropAt;
    const moving = dragGroup;
    setDragGroup(null);
    setDropAt(null);
    if (!target || target.key === moving) return;
    const rest = groupKeys.filter((k) => k !== moving);
    const at = rest.indexOf(target.key) + (target.after ? 1 : 0);
    const next = moveGroup(groupKeys, moving, at);
    if (next.join() !== groupKeys.join()) setSidebarOrder(next);
  };
  const groupCls = (key) => 'bc-folder'
    + (dragGroup === key ? ' is-dragging' : '')
    + (dropAt && dragGroup && dropAt.key === key && key !== dragGroup ? (dropAt.after ? ' is-drop-after' : ' is-drop-before') : '');

  const groupNodes = {};
  for (const { folder, cals } of byFolder) {
    const key = 'f' + folder.id;
    groupNodes[key] = html`<section key=${key} data-group=${key} class=${groupCls(key)}>
        <${FolderHead}
          folder=${folder} cals=${cals}
          collapsed=${!!collapsedFolders[folder.id]}
          onToggleCollapse=${() => toggleFolder(folder.id)}
          dragProps=${groupDrag(key)}
        />
        ${!collapsedFolders[folder.id] && html`<div class="bc-folder-body">${rows(cals, 'f' + folder.id)}</div>`}
      </section>`;
  }
  groupNodes.all = phone ? (unfiled.length > 0 && html`<section key="all" data-group="all" class=${groupCls('all')}>
        ${byFolder.length > 0 && html`<div class="bc-folder-headrow" ...${groupDrag('all')}>
          <button
            type="button" class="bc-folder-head bc-folder-static"
            aria-expanded=${!collapsedAllCals}
            onClick=${() => set({ collapsedAllCals: !collapsedAllCals })}
          ><span class="bc-folder-caret${collapsedAllCals ? ' is-closed' : ''}"><${Icon} name="chevronDown" size=${13} /></span>Other calendars</button>
        </div>`}
        ${(byFolder.length === 0 || !collapsedAllCals) && rows(unfiled, 'all')}
      </section>`) : html`<section key="all" data-group="all" class=${groupCls('all')}>
        ${byFolder.length > 0 && html`<div class="bc-folder-headrow" ...${groupDrag('all')}>
          <button
            type="button" class="bc-folder-head bc-folder-static"
            aria-expanded=${!collapsedAllCals}
            onClick=${() => set({ collapsedAllCals: !collapsedAllCals })}
          ><span class="bc-folder-caret${collapsedAllCals ? ' is-closed' : ''}"><${Icon} name="chevronDown" size=${13} /></span>All calendars</button>
          ${allCalTools}
        </div>`}
        ${(byFolder.length === 0 || !collapsedAllCals) && rows(calendars, 'all')}
      </section>`;
  groupNodes.people = people.length > 0 && html`<section key="people" data-group="people" class=${groupCls('people')}>
        <div class="bc-folder-headrow" ...${groupDrag('people')}>
          <button
            type="button" class="bc-folder-head bc-folder-static"
            aria-expanded=${!collapsedPeople}
            onClick=${() => set({ collapsedPeople: !collapsedPeople })}
          ><span class="bc-folder-caret${collapsedPeople ? ' is-closed' : ''}"><${Icon} name="chevronDown" size=${13} /></span>People</button>
          <span class="bc-folder-tools">
            <button
              type="button" class="bc-icon-btn bc-folder-tool"
              aria-label="New person" title="New person"
              onClick=${() => set({ createDrawer: { kind: 'person' } })}
            ><${Icon} name="plus" size=${13} /></button>
            <${PeopleModeButton} />
          </span>
        </div>
        ${!collapsedPeople && people.map((p) => {
          const soloed = !!(peopleSolo && peopleSolo.personId === p.id);
          return html`<${PersonRow}
            key=${p.id} p=${p} soloed=${soloed} phone=${phone}
            onDragStart=${(e) => dragPersonStart(p, e)}
            onMenu=${() => setMenu({
              head: p.name, color: null,
              items: [
                soloed ? ['visAll', "Show everyone's availability again", exitPeopleSolo] : ['visNone', "Show only this person's availability", () => enterPeopleSolo(p)],
                ['people', 'Open person', () => set({ route: 'people', peopleFocus: p.name })],
              ],
            })}
          />`;
        })}
      </section>`;
  groupNodes.plugins = layerPlugins.length > 0 && html`<section key="plugins" data-group="plugins" class=${groupCls('plugins')} aria-label="Plugin layers">
        <div class="bc-folder-head" ...${groupDrag('plugins')}>
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
            <button type="button" class="bc-icon-btn" title="Plugin settings" onClick=${() => set({ route: 'plugins', pluginFocus: pl.id })}><${Icon} name="settings" size=${13} /></button>
          </div>
        </div>`)}
      </section>`;

  return html`<aside class="bc-sidebar${open ? ' is-open' : ''}${collapsed ? ' is-collapsed' : ''}">
    ${!phone && html`<${SidebarResize} />`}
    ${onClose && html`<div class="bc-sidebar-mobilehead">
      <span class="bc-manage-head">Better-Cal</span>
      <button type="button" class="bc-icon-btn" aria-label="Close sidebar" onClick=${onClose}><${Icon} name="close" size=${15} /></button>
    </div>`}
    <div class="bc-sidebar-scroll bc-scroll-edges" ref=${scrollRef} onDragOver=${onGroupDragOver} onDrop=${onGroupDrop}>
      <${MiniMonth} />
      ${phone && html`<div class="bc-folder-headrow bc-sb-calhead">
        <span class="bc-folder-head bc-folder-static">Calendars</span>
        ${allCalTools}
      </div>`}
      ${groupKeys.map((k) => groupNodes[k])}
      <${AddMenu} />
      ${phone && pagesGrid}
      ${soloCal && html`<div class="bc-solo-banner" role="status">
        Showing only <strong>${soloCal.name}</strong>
        <button type="button" class="bc-link-btn" onClick=${exitSolo}>Show all</button>
      </div>`}
    </div>
    ${menu && html`<${RowMenu} head=${menu.head} color=${menu.color} items=${menu.items} onClose=${() => setMenu(null)} />`}
    ${!phone && html`<footer class="bc-sidebar-foot">
      <button
        type="button" class="bc-folder-head bc-manage-head"
        aria-expanded=${!manageCollapsed}
        onClick=${toggleManage}
      ><span class="bc-folder-caret bc-caret-vertical"><${Icon} name=${manageCollapsed ? 'chevronUp' : 'chevronDown'} size=${13} /></span>Manage${manageCollapsed && reviewCount > 0
        && html`<span class="bc-manage-badge" aria-label=${reviewCount + ' waiting on a decision'}>${reviewCount}</span>`}${manageCollapsed && attention
        && html`<span class="bc-attn-dot" role="img" aria-label="Needs attention" title="Something needs your attention: Settings, System"></span>`}</button>
      ${!manageCollapsed && MANAGE_ITEMS.map(([r, label]) => html`<button
        key=${r} type="button"
        class="bc-manage-item${route === r ? ' is-active' : ''}"
        aria-current=${route === r ? 'page' : 'false'}
        onClick=${() => set({ route: r })}
      ><${Icon} name=${r} /><span>${label}</span>${r === 'review' && reviewCount > 0
        && html`<span class="bc-manage-badge" aria-label=${reviewCount + ' awaiting a decision'}>${reviewCount}</span>`}${r === 'settings' && attention && html`<span class="bc-attn-dot" role="img" aria-label="Needs attention" title="Something needs your attention: Settings, System"></span>`}</button>`)}
    </footer>`}
  </aside>`;
}
