// Toolbar: sidebar toggle, brand, today + stable prev/next chevrons ahead of
// the date label (their position never shifts with the label's width, so
// rapid month-stepping works), saved views menu, on-page filter, a
// right-aligned current-view dropdown (GCal-style), search, quick add, New.
//
// The view dropdown lists every view on desktop; on viewports <= 800px the
// month slot splits into "Full month" and "3 day" (persisted as the
// mobile-only overviewMode setting) and the multiweek options drop out.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, shallowEq } from './store.js';
import { setView, goToday, stepAnchor, setOverviewMode, effectiveOverviewMode } from './actions.js';
import { fmtMonthYear, fmtDayLong, dateOfDayKey } from '../lib/dates.js';
import { ViewSwitcher } from './ViewSwitcher.js';
import { Icon } from '../ui/icons.js';
import { JumpPopover } from './JumpPopover.js';

const DESKTOP_VIEWS = [
  ['month', 'Month'],
  ['weeks3', '3 weeks'],
  ['weeks2', '2 weeks'],
  ['week', 'Week'],
  ['day', 'Day'],
  ['agenda', 'Agenda'],
];
const MOBILE_VIEWS = [
  ['month:month', 'Full month'],
  ['month:3day', '3 day'],
  ['week', 'Week'],
  ['day', 'Day'],
  ['agenda', 'Agenda'],
];

const STEP_UNITS = {
  month: 'month', weeks3: '3 weeks', weeks2: '2 weeks',
  week: 'week', day: 'day', agenda: 'month',
};

// Current-view dropdown, right-aligned so the label's width never moves it.
function ViewMenu({ view, narrow }) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);
  const mode = effectiveOverviewMode();

  useEffect(() => {
    if (!open) return undefined;
    const onDoc = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); setOpen(false); }
    };
    document.addEventListener('pointerdown', onDoc, true);
    document.addEventListener('keydown', onKey, true);
    return () => {
      document.removeEventListener('pointerdown', onDoc, true);
      document.removeEventListener('keydown', onKey, true);
    };
  }, [open]);

  const items = narrow ? MOBILE_VIEWS : DESKTOP_VIEWS;
  const currentKey = narrow && view === 'month' ? 'month:' + mode : view;
  const current = items.find(([k]) => k === currentKey);
  const label = current ? current[1] : 'Month';

  const pick = (k) => {
    setOpen(false);
    if (k === currentKey) return;
    if (k.startsWith('month:')) setOverviewMode(k.slice(6));
    else setView(k);
  };

  return html`<div class="bc-ov bc-viewmenu" ref=${rootRef}>
    <button
      type="button" class="bc-btn bc-viewmenu-btn"
      aria-haspopup="menu" aria-expanded=${open}
      title="Change view (v cycles, 1-6 direct)"
      onClick=${() => setOpen(!open)}
    >${label}<span class="bc-viewmenu-caret" aria-hidden="true"><${Icon} name="chevronDown" size=${11} /></span></button>
    ${open && html`<div class="bc-ov-menu bc-viewmenu-drop" role="menu" aria-label="Calendar view">
      ${items.map(([k, l]) => html`<button
        key=${k} type="button" role="menuitemradio"
        aria-checked=${currentKey === k}
        class="bc-ov-item${currentKey === k ? ' is-sel' : ''}"
        onClick=${() => pick(k)}
      ><span class="bc-ov-check" aria-hidden="true">${currentKey === k ? '✓' : ''}</span>${l}</button>`)}
    </div>`}
  </div>`;
}

export function Toolbar({ onToggleSidebar }) {
  const { view, anchor, visibleMonth, filterText, jumpOpen, narrow } = useStore(
    (s) => ({
      view: s.view, anchor: s.anchor, visibleMonth: s.visibleMonth,
      filterText: s.filterText, jumpOpen: s.jumpOpen,
      narrow: s.viewportNarrow,
      // Subscribed so the dropdown checkmark tracks the persisted setting.
      overviewMode: s.settings.overviewMode,
    }),
    shallowEq,
  );

  // Day view shows the full date ("Friday, August 8"); everything else the
  // visible month.
  const label = view === 'day'
    ? fmtDayLong(dateOfDayKey(anchor))
    : (visibleMonth ? fmtMonthYear(new Date(visibleMonth.year, visibleMonth.month - 1, 1)) : '');
  const unit = STEP_UNITS[view] || 'month';

  return html`<header class="bc-toolbar">
    <button type="button" class="bc-icon-btn bc-menu-btn" aria-label="Toggle sidebar" title="Show or hide the sidebar" onClick=${onToggleSidebar}><${Icon} name="menu" size=${17} /></button>
    <span class="bc-brand"><span class="bc-brand-icon"><${Icon} name="brand" size=${21} /></span><span class="bc-brand-name">Better-Cal</span></span>
    <button type="button" class="bc-btn" onClick=${goToday}>Today</button>
    <div class="bc-toolbar-nav">
      <button type="button" class="bc-icon-btn bc-nav-btn" aria-label=${'Previous ' + unit} title=${'Previous ' + unit} onClick=${() => stepAnchor(-1)}><${Icon} name="chevronLeft" size=${15} /></button>
      <button type="button" class="bc-icon-btn bc-nav-btn" aria-label=${'Next ' + unit} title=${'Next ' + unit} onClick=${() => stepAnchor(1)}><${Icon} name="chevronRight" size=${15} /></button>
      <button
        type="button" class="bc-toolbar-month bc-toolbar-date"
        aria-haspopup="dialog" aria-expanded=${jumpOpen}
        title="Jump to date (g)" aria-live="polite"
        onClick=${() => set({ jumpOpen: !jumpOpen })}
      >${label}</button>
      <${JumpPopover} />
    </div>
    <${ViewSwitcher} />
    <span class="bc-toolbar-spacer"></span>
    <input
      class="bc-filter-input"
      type="search"
      placeholder="Filter visible events"
      aria-label="Filter visible events"
      value=${filterText}
      onInput=${(e) => set({ filterText: e.target.value })}
    />
    <${ViewMenu} view=${view} narrow=${narrow} />
    <button type="button" class="bc-icon-btn" aria-label="Keyboard shortcuts" title="Keyboard shortcuts (?)" onClick=${() => set({ shortcutsOpen: true })}><${Icon} name="keyboard" size=${16} /></button>
    <button type="button" class="bc-icon-btn" aria-label="Search" title="Search ( / )" onClick=${() => set({ searchOpen: true })}><${Icon} name="search" size=${16} /></button>
    <button type="button" class="bc-icon-btn bc-qa-btn" aria-label="Quick add" title="Quick add: type it in plain language (c)" onClick=${() => set({ quickAddOpen: true })}><${Icon} name="quickadd" size=${16} /></button>
    <${NewMenu} />
  </header>`;
}

// "+ New" split menu: Event is the headline action; the caret reveals Trip,
// Person, and Calendar.
function NewMenu() {
  const [open, setOpen] = useState(false);
  const wrapRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onDoc = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('pointerdown', onDoc, true);
    return () => document.removeEventListener('pointerdown', onDoc, true);
  }, [open]);

  const go = (fn) => () => { setOpen(false); fn(); };
  const items = [
    ['Event', () => set({ editor: { mode: 'create', draft: {} } })],
    ['Trip', () => set({ editor: { mode: 'create', draft: { isContainer: true, allDay: true } } })],
    ['Person', () => set({ createDrawer: { kind: 'person' } })],
    ['Calendar', () => set({ createDrawer: { kind: 'calendar' } })],
  ];

  return html`<span class="bc-newmenu" ref=${wrapRef}>
    <button type="button" class="bc-btn bc-btn-primary bc-newmenu-main" title="New event (n)" onClick=${() => set({ editor: { mode: 'create', draft: {} } })}>+ New</button>
    <button
      type="button" class="bc-btn bc-btn-primary bc-newmenu-caret"
      aria-label="More things to create" aria-expanded=${open} aria-haspopup="menu"
      onClick=${() => setOpen(!open)}
    ><${Icon} name="chevronDown" size=${11} /></button>
    ${open && html`<div class="bc-newmenu-drop" role="menu">
      ${items.map(([label, fn]) => html`<button key=${label} type="button" role="menuitem" class="bc-newmenu-item" onClick=${go(fn)}>${label}</button>`)}
    </div>`}
  </span>`;
}
