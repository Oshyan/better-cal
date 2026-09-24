// The phone frame's bottom half (mobile audit phase 2, 0.3.x): the five
// actions reached for most, within thumb reach, each doing one distinct
// thing. The thin top bar keeps the menu and the month title.
//
//   View    opens a sheet: the views, then saved views (they are the same
//           kind of choice: how am I looking at this), then the agenda's sort
//   Search  the search overlay
//   New     centre, raised: tap opens quick add at once (most phone entries
//           are one line); press and hold fans out Event, Trip, Person,
//           Calendar
//   Filter  the text filter and the Show choices in one sheet, with a dot
//           while anything is filtered (a filtered calendar otherwise looks
//           like missing events)
//   Today   shows today's date; tap scrolls back to now, press and hold opens
//           jump-to-date
//
// Shown only below the phone breakpoint (CSS); nothing here snaps or pages.

import { html, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, shallowEq } from './store.js';
import { setView, goToday, setOverviewMode, effectiveOverviewMode, toggleRel, showOnlyRel, showAllRel } from './actions.js';
import { REL_LABEL, REL_ORDER } from './Relationship.js';
import { useSavedViewPicker } from './ViewSwitcher.js';
import { trapFocus } from '../ui/DayExpand.js';
import { Icon } from '../ui/icons.js';
import { consumeOutsidePress } from '../ui/outside.js';

const PHONE_VIEWS = [
  ['month:month', 'Full month', 'viewMonth'],
  ['month:3day', '3 day', 'calendar'],
  ['week', 'Week', 'viewWeek'],
  ['day', 'Day', 'viewDay'],
  ['agenda', 'Agenda', 'viewAgenda'],
];
const HOLD_MS = 450;

function phoneViewKey(view) {
  return view === 'month' ? 'month:' + effectiveOverviewMode() : view;
}

export function filterActive(st) {
  return !!(st.filterText && st.filterText.trim()) || REL_ORDER.some((k) => st.showRel[k] === false);
}

// Tap does one thing, press-and-hold another. A hold that fired swallows the
// click that follows it; keyboard activation (no pointer) is always a tap.
function useHold(onTap, onHold) {
  const timer = useRef(0);
  const held = useRef(false);
  const clear = () => { clearTimeout(timer.current); timer.current = 0; };
  useEffect(() => clear, []);
  return {
    onPointerDown: (e) => {
      if (e.button !== undefined && e.button !== 0) return;
      held.current = false;
      clear();
      timer.current = setTimeout(() => {
        held.current = true;
        timer.current = 0;
        if (navigator.vibrate) { try { navigator.vibrate(8); } catch { /* not allowed */ } }
        onHold();
      }, HOLD_MS);
    },
    onPointerUp: clear,
    onPointerLeave: clear,
    onPointerCancel: clear,
    onContextMenu: (e) => e.preventDefault(),
    onClick: () => {
      if (held.current) { held.current = false; return; }
      onTap();
    },
  };
}

// A bottom sheet: dimmed backdrop (a tap on it closes), a drag handle (pull
// it down to close), 48-point rows, Escape to close, focus kept inside.
function Sheet({ label, onClose, children }) {
  const ref = useRef(null);
  const drag = useRef(null);
  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); onClose(); }
      if (e.key === 'Tab') trapFocus(ref.current, e);
    };
    document.addEventListener('keydown', onKey, true);
    if (ref.current) ref.current.focus({ preventScroll: true });
    return () => document.removeEventListener('keydown', onKey, true);
  }, []); // eslint-disable-line
  const onHandleDown = (e) => {
    drag.current = { y: e.clientY };
    e.currentTarget.setPointerCapture(e.pointerId);
  };
  const onHandleMove = (e) => {
    if (!drag.current || !ref.current) return;
    const dy = Math.max(0, e.clientY - drag.current.y);
    ref.current.style.transform = dy ? `translateY(${dy}px)` : '';
  };
  const onHandleUp = (e) => {
    if (!drag.current) return;
    const dy = e.clientY - drag.current.y;
    drag.current = null;
    if (ref.current) ref.current.style.transform = '';
    if (dy > 60) onClose();
  };
  return html`<div class="bc-bsheet-scrim" onPointerDown=${(e) => { consumeOutsidePress(e); onClose(); }}></div>
    <div class="bc-bsheet" role="dialog" aria-modal="true" aria-label=${label} tabindex="-1" ref=${ref}>
      <div class="bc-bsheet-grip"
        onPointerDown=${onHandleDown} onPointerMove=${onHandleMove} onPointerUp=${onHandleUp} onPointerCancel=${onHandleUp}
      ><span aria-hidden="true"></span></div>
      ${children}
    </div>`;
}

function Row({ icon, label, checked, role, onClick, dot, disabled }) {
  return html`<button
    type="button" class=${'bc-bsheet-row' + (checked ? ' is-sel' : '')}
    role=${role} aria-checked=${role && role !== 'menuitem' ? !!checked : undefined}
    disabled=${disabled} onClick=${onClick}
  >
    ${icon ? html`<span class="bc-bsheet-ico"><${Icon} name=${icon} size=${18} /></span>` : html`<span class="bc-bsheet-ico"></span>`}
    <span class="bc-bsheet-label">${label}</span>
    ${dot && html`<span class="bc-views-dot" role="img" aria-label="Modified"></span>`}
    ${checked && html`<span class="bc-bsheet-check"><${Icon} name="check" size=${16} /></span>`}
  </button>`;
}

function ViewSheet({ onClose }) {
  const s = useStore((st) => ({ view: st.view, overviewMode: st.settings.overviewMode, agendaSort: st.agendaSort }), shallowEq);
  const picker = useSavedViewPicker(onClose);
  const current = phoneViewKey(s.view);
  const pick = (k) => {
    onClose();
    if (k === current) return;
    if (k.startsWith('month:')) setOverviewMode(k.slice(6));
    else setView(k);
  };
  return html`<${Sheet} label="View" onClose=${onClose}>
    <div class="bc-bsheet-h">View</div>
    <div role="menu" aria-label="Calendar view">
      ${PHONE_VIEWS.map(([k, l, icon]) => html`<${Row} key=${k} icon=${icon} label=${l} role="menuitemradio" checked=${k === current} onClick=${() => pick(k)} />`)}
    </div>
    ${s.view === 'agenda' && html`<div class="bc-bsheet-inline">
      <span>Agenda order</span>
      <span class="bc-seg" role="group" aria-label="Agenda order">
        ${[['time', 'By time'], ['match', 'By match']].map(([v, l]) => html`<button
          key=${v} type="button" class=${'bc-seg-btn' + (s.agendaSort === v ? ' is-active' : '')}
          aria-pressed=${s.agendaSort === v} onClick=${() => set({ agendaSort: v })}
        >${l}</button>`)}
      </span>
    </div>`}
    <div class="bc-bsheet-h">Saved views</div>
    <${Row} icon="views" label=${'Default view' + (picker.defaultView ? ': ' + picker.defaultView.name : '')} onClick=${picker.selectDefault} />
    ${picker.savedViews.map((v) => html`<${Row}
      key=${v.id} label=${v.name} checked=${v.id === picker.activeViewId}
      dot=${v.id === picker.activeViewId && picker.modified} onClick=${() => picker.selectView(v)}
    />`)}
    ${picker.savedViews.length === 0 && html`<div class="bc-bsheet-note">No saved views yet. Save one from the menu's Saved views page.</div>`}
    ${picker.pendingView && html`<div class="bc-bsheet-confirm">
      <span>Save changes to ${picker.active ? picker.active.name : 'this view'}?</span>
      <div class="bc-bsheet-confirmrow">
        <button type="button" class="bc-btn bc-btn-primary" onClick=${picker.confirmSave}>Save</button>
        <button type="button" class="bc-btn" onClick=${picker.confirmDiscard}>Discard</button>
        <button type="button" class="bc-btn" onClick=${picker.cancelPending}>Cancel</button>
      </div>
    </div>`}
    <${Row} icon="settings" label="Manage views" onClick=${() => { onClose(); set({ route: 'views' }); }} />
  <//>`;
}

function FilterSheet({ onClose }) {
  const s = useStore((st) => ({ showRel: st.showRel, filterText: st.filterText, view: st.view, agendaShowPast: st.agendaShowPast }), shallowEq);
  const all = REL_ORDER.every((k) => s.showRel[k] !== false);
  const on = filterActive(s);
  return html`<${Sheet} label="Filter" onClose=${onClose}>
    <div class="bc-bsheet-h">Filter</div>
    <label class="bc-bsheet-field">
      <${Icon} name="search" size=${16} />
      <input
        id="bc-phone-filter" type="search" placeholder="Filter visible events" aria-label="Filter visible events"
        value=${s.filterText} onInput=${(e) => set({ filterText: e.target.value })}
      />
    </label>
    <div class="bc-bsheet-h">Show</div>
    <div role="menu" aria-label="What to show">
      ${REL_ORDER.map((k) => html`<${Row} key=${k} label=${REL_LABEL[k]} role="menuitemcheckbox" checked=${s.showRel[k] !== false} onClick=${() => toggleRel(k)} />`)}
    </div>
    <div class="bc-bsheet-inline">
      <button type="button" class="bc-btn" onClick=${() => showOnlyRel('planned')}>Planned only</button>
      <button type="button" class="bc-btn" disabled=${all} onClick=${() => showAllRel()}>Show all</button>
      ${on && html`<button type="button" class="bc-link-btn" onClick=${() => { set({ filterText: '' }); showAllRel(); }}>Clear filters</button>`}
    </div>
    ${s.view === 'agenda' && html`<label class="bc-bsheet-toggle">
      <input type="checkbox" checked=${s.agendaShowPast} onChange=${(e) => set({ agendaShowPast: e.target.checked })} />
      <span>Show past events in the agenda</span>
    </label>`}
  <//>`;
}

function NewSheet({ onClose }) {
  const go = (patch) => () => { onClose(); set(patch); };
  return html`<${Sheet} label="New" onClose=${onClose}>
    <div class="bc-bsheet-h">New</div>
    <div role="menu" aria-label="Create">
      <${Row} icon="quickadd" label="Quick add" role="menuitem" onClick=${go({ quickAddOpen: true })} />
      <${Row} icon="calendar" label="Event" role="menuitem" onClick=${go({ editor: { mode: 'create', draft: {} } })} />
      <${Row} icon="trip" label="Trip" role="menuitem" onClick=${go({ editor: { mode: 'create', draft: { isContainer: true, allDay: true } } })} />
      <${Row} icon="people" label="Person" role="menuitem" onClick=${go({ createDrawer: { kind: 'person' } })} />
      <${Row} icon="folder" label="Calendar" role="menuitem" onClick=${go({ createDrawer: { kind: 'calendar' } })} />
    </div>
  <//>`;
}

export function BottomBar() {
  const s = useStore((st) => ({
    view: st.view, overviewMode: st.settings.overviewMode, sheet: st.phoneSheet,
    filterOn: filterActive(st), nowMinute: st.nowMinute,
  }), shallowEq);
  const toggle = (name) => set({ phoneSheet: s.sheet === name ? null : name });
  const close = () => set({ phoneSheet: null });
  const cur = PHONE_VIEWS.find(([k]) => k === phoneViewKey(s.view)) || PHONE_VIEWS[0];
  const newPress = useHold(() => set({ quickAddOpen: true, phoneSheet: null }), () => set({ phoneSheet: 'new' }));
  const todayPress = useHold(() => { close(); goToday(); }, () => set({ phoneSheet: null, jumpOpen: true }));
  return html`<nav class="bc-bottombar" aria-label="Calendar actions">
      <button type="button" class=${'bc-bb-btn' + (s.sheet === 'view' ? ' is-open' : '')} aria-haspopup="dialog" aria-expanded=${s.sheet === 'view'} onClick=${() => toggle('view')}>
        <${Icon} name=${cur[2]} size=${22} /><span>${cur[1]}</span>
      </button>
      <button type="button" class="bc-bb-btn" onClick=${() => { close(); set({ searchOpen: true }); }}>
        <${Icon} name="search" size=${22} /><span>Search</span>
      </button>
      <button type="button" class="bc-bb-btn bc-bb-new" aria-label="New: tap for quick add, hold for more" ...${newPress}>
        <span class="bc-bb-newico"><${Icon} name="plus" size=${26} /></span><span>New</span>
      </button>
      <button type="button" class=${'bc-bb-btn' + (s.filterOn ? ' is-on' : '') + (s.sheet === 'filter' ? ' is-open' : '')} aria-haspopup="dialog" aria-expanded=${s.sheet === 'filter'} aria-label=${s.filterOn ? 'Filter (on)' : 'Filter'} onClick=${() => toggle('filter')}>
        <${Icon} name="filters" size=${22} /><span>Filter</span>
      </button>
      <button type="button" class="bc-bb-btn bc-bb-today" aria-label="Today: tap to go to today, hold to jump to a date" ...${todayPress}>
        <span class="bc-bb-date">${new Date().getDate()}</span><span>Today</span>
      </button>
    </nav>
    ${s.sheet === 'view' && html`<${ViewSheet} onClose=${close} />`}
    ${s.sheet === 'filter' && html`<${FilterSheet} onClose=${close} />`}
    ${s.sheet === 'new' && html`<${NewSheet} onClose=${close} />`}`;
}
