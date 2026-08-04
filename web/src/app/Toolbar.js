// Toolbar: saved views menu, view switcher, today, prev/next chevrons around
// a clickable date label (opens the jump popover, hotkey g), on-page filter,
// search, quick add and New buttons. Single-row inline layout.
//
// The view switcher is responsive: on desktop the month slot is a plain
// "Month" button (always the full month grid; a too-narrow calendar area
// falls back to the 3-day ribbon automatically, no setting involved). On
// viewports <= 800px the slot becomes an "Overview" dropdown (Full month /
// 3 day ribbon, persisted as the mobile-only overviewMode setting) and the
// multiweek buttons drop out, leaving [Overview] [Week] [Day] [Agenda].

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, shallowEq } from './store.js';
import { setView, goToday, stepAnchor, setOverviewMode, effectiveOverviewMode } from './actions.js';
import { fmtMonthYear, fmtDayLong, dateOfDayKey } from '../lib/dates.js';
import { ViewSwitcher } from './ViewSwitcher.js';
import { Icon } from '../ui/icons.js';
import { JumpPopover } from './JumpPopover.js';

const DESKTOP_VIEWS = [
  ['weeks3', '3 wk'],
  ['weeks2', '2 wk'],
  ['week', 'Week'],
  ['day', 'Day'],
  ['agenda', 'Agenda'],
];
const MOBILE_VIEWS = [
  ['week', 'Week'],
  ['day', 'Day'],
  ['agenda', 'Agenda'],
];

const OVERVIEW_MODES = [
  ['month', 'Full month'],
  ['3day', '3 day'],
];

const STEP_UNITS = {
  month: 'month', weeks3: '3 weeks', weeks2: '2 weeks',
  week: 'week', day: 'day', agenda: 'month',
};

// Desktop month slot: a plain button, no dropdown, no 3-day option.
function MonthButton({ view }) {
  return html`<button
    type="button"
    class="bc-viewswitch-btn bc-ov-btn${view === 'month' ? ' is-active' : ''}"
    aria-pressed=${view === 'month'}
    title="Month view"
    onClick=${() => setView('month')}
  >Month</button>`;
}

// Mobile Overview slot: click switches to the overview; when already there,
// click opens the Full month / 3 day mode menu (the caret advertises it).
function OverviewButton({ view }) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);
  const mode = effectiveOverviewMode();

  // Document-level listeners; torn down on unmount, not just close.
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

  const pick = (m) => {
    setOpen(false);
    if (m !== mode || view !== 'month') setOverviewMode(m);
  };

  return html`<div class="bc-ov" ref=${rootRef}>
    <button
      type="button"
      class="bc-viewswitch-btn bc-ov-btn${view === 'month' ? ' is-active' : ''}"
      aria-pressed=${view === 'month'}
      aria-haspopup="menu" aria-expanded=${open}
      title=${'Overview: ' + (mode === '3day' ? '3 day' : 'full month')}
      onClick=${() => (view === 'month' ? setOpen(!open) : setView('month'))}
    >Overview<span class="bc-ov-caret" aria-hidden="true">▾</span></button>
    ${open && html`<div class="bc-ov-menu" role="menu" aria-label="Overview layout">
      ${OVERVIEW_MODES.map(([m, label]) => html`<button
        key=${m} type="button" role="menuitemradio"
        aria-checked=${mode === m}
        class="bc-ov-item${mode === m ? ' is-sel' : ''}"
        onClick=${() => pick(m)}
      ><span class="bc-ov-check" aria-hidden="true">${mode === m ? '✓' : ''}</span>${label}</button>`)}
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
  const roster = narrow ? MOBILE_VIEWS : DESKTOP_VIEWS;

  return html`<header class="bc-toolbar">
    <button type="button" class="bc-icon-btn bc-menu-btn" aria-label="Toggle sidebar" onClick=${onToggleSidebar}>☰</button>
    <${ViewSwitcher} />
    <span class="bc-brand">Better-Cal</span>
    <button type="button" class="bc-btn" onClick=${goToday}>Today</button>
    <div class="bc-toolbar-nav">
      <button type="button" class="bc-icon-btn bc-nav-btn" aria-label=${'Previous ' + unit} title=${'Previous ' + unit} onClick=${() => stepAnchor(-1)}>‹</button>
      <button
        type="button" class="bc-toolbar-month bc-toolbar-date"
        aria-haspopup="dialog" aria-expanded=${jumpOpen}
        title="Jump to date (g)" aria-live="polite"
        onClick=${() => set({ jumpOpen: !jumpOpen })}
      >${label}</button>
      <button type="button" class="bc-icon-btn bc-nav-btn" aria-label=${'Next ' + unit} title=${'Next ' + unit} onClick=${() => stepAnchor(1)}>›</button>
      <${JumpPopover} />
    </div>
    <nav class="bc-viewswitch" aria-label="View">
      ${narrow
        ? html`<${OverviewButton} view=${view} />`
        : html`<${MonthButton} view=${view} />`}
      ${roster.map(([v, l]) => html`<button
        key=${v} type="button"
        class="bc-viewswitch-btn${view === v ? ' is-active' : ''}"
        aria-pressed=${view === v}
        onClick=${() => setView(v)}
      >${l}</button>`)}
    </nav>
    <input
      class="bc-filter-input"
      type="search"
      placeholder="Filter visible events"
      aria-label="Filter visible events"
      value=${filterText}
      onInput=${(e) => set({ filterText: e.target.value })}
    />
    <button type="button" class="bc-icon-btn" aria-label="Keyboard shortcuts" title="Keyboard shortcuts (?)" onClick=${() => set({ shortcutsOpen: true })}><${Icon} name="keyboard" size=${16} /></button>
    <button type="button" class="bc-icon-btn" aria-label="Search" title="Search ( / )" onClick=${() => set({ searchOpen: true })}>${'🔍'}</button>
    <button type="button" class="bc-icon-btn" aria-label="Quick add" title="Quick add: type it in plain language (c)" onClick=${() => set({ quickAddOpen: true })}>${'⚡'}</button>
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
    >▾</button>
    ${open && html`<div class="bc-newmenu-drop" role="menu">
      ${items.map(([label, fn]) => html`<button key=${label} type="button" role="menuitem" class="bc-newmenu-item" onClick=${go(fn)}>${label}</button>`)}
    </div>`}
  </span>`;
}
