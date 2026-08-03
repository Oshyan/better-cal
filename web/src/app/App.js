// App root: routes between views, wires bettercal-ui to the store and API.

import { html, useState, useMemo, useRef, useEffect, useCallback } from '../../vendor/index.js';
import { useStore, set, state, calendarMeta, shallowEq } from './store.js';
import { loadWindow } from './api.js';
import {
  moveEvent, resizeEvent, triageAttendance, sendFeedback, exitReschedule,
  jumpToDate, openDetail, effectiveOverviewMode,
} from './actions.js';
import { groupOccurrences, itemMatchesFilter } from '../ui/grouping.js';
import { sortByMatch } from '../lib/rank.js';
import { installKeyboard } from './keyboard.js';
import {
  addDaysKey, dateOfDayKey, toISOWithOffset, parseISO, epochDayOfKey,
} from '../lib/dates.js';
import { occurrenceDaySpan } from '../ui/monthmath.js';
import { MonthGrid } from '../ui/MonthGrid.js';
import { TimeGrid } from '../ui/TimeGrid.js';
import { AgendaList } from '../ui/AgendaList.js';
import { dayRangeDraft } from '../lib/quickcreate.js';
import { DayExpand } from '../ui/DayExpand.js';
import { RescheduleBanner, RescheduleStrip, RescheduleOverlay } from '../ui/RescheduleMode.js';
import { Toolbar } from './Toolbar.js';
import { Sidebar } from './Sidebar.js';
import { QuickAdd } from './QuickAdd.js';
import { EventPopover } from './EventPopover.js';
import { EventDetail } from './EventDetail.js';
import { GroupPopover } from './GroupPopover.js';
import { EditorDrawer } from './EditorDrawer.js';
import { SearchOverlay } from './SearchOverlay.js';
import { ShortcutsSheet } from './ShortcutsSheet.js';
import { Toasts } from './Toasts.js';
import { Login } from './Login.js';
import { OutfeedsPage } from './OutfeedsPage.js';
import { FiltersPage } from './FiltersPage.js';
import { SavedViewsPage } from './SavedViewsPage.js';
import { SettingsPage } from './SettingsPage.js';
import { PeoplePage } from './PeoplePage.js';
import { OrganizePage } from './OrganizePage.js';

const MONTH_ROWS = { month: 6, weeks3: 3, weeks2: 2 };
// Below this calendar-area width (px) the 7-column month grid is cramped
// enough that the 3-day ribbon takes over automatically on desktop.
const VIEW_AREA_MIN = 560;

function matchesFilter(occ, needle) {
  return (occ.title || '').toLowerCase().includes(needle) ||
    (occ.location || '').toLowerCase().includes(needle) ||
    (occ.description || '').toLowerCase().includes(needle) ||
    (occ.tags || []).some((t) => t.toLowerCase().includes(needle));
}

export function App() {
  const s = useStore(
    (st) => ({
      booted: st.booted, authed: st.authed, route: st.route, view: st.view,
      anchor: st.anchor, scrollSeq: st.scrollSeq, occVersion: st.occVersion,
      calendars: st.calendars, filterText: st.filterText,
      expandedDay: st.expandedDay, agendaShowPast: st.agendaShowPast,
      agendaSort: st.agendaSort,
      reschedule: st.reschedule,
      visibleMonth: st.reschedule ? st.visibleMonth : null,
      overviewMode: st.settings.overviewMode,
      nowMinute: st.nowMinute,
      viewportNarrow: st.viewportNarrow,
      viewAreaNarrow: st.viewAreaNarrow,
      coarsePointer: st.coarsePointer,
    }),
    shallowEq,
  );
  const [sidebarOpen, setSidebarOpen] = useState(false);

  useEffect(() => installKeyboard(), []);

  // Global minute tick: the single interval behind all time-relative styling
  // (past dim, active gold ring, now-line). Checks every 15s but only bumps
  // the store when the epoch minute actually changes, so the tree re-renders
  // at most once a minute (and never drifts more than 15s past the boundary).
  useEffect(() => {
    const tick = () => {
      const m = Math.floor(Date.now() / 60000);
      if (state.nowMinute !== m) set({ nowMinute: m });
    };
    const t = setInterval(tick, 15000);
    return () => clearInterval(t);
  }, []);

  // Track the responsive roster inputs in the store so the toolbar, keyboard
  // map and saved-view fallbacks all agree on what "mobile" means.
  useEffect(() => {
    const mqNarrow = window.matchMedia('(max-width: 800px)');
    const mqCoarse = window.matchMedia('(pointer: coarse)');
    const sync = () => set({ viewportNarrow: mqNarrow.matches, coarsePointer: mqCoarse.matches });
    mqNarrow.addEventListener('change', sync);
    mqCoarse.addEventListener('change', sync);
    sync();
    return () => {
      mqNarrow.removeEventListener('change', sync);
      mqCoarse.removeEventListener('change', sync);
    };
  }, []);

  // Watch the calendar view area's own width (sidebar and window both move
  // it): below VIEW_AREA_MIN the month slot falls back to the 3-day ribbon
  // automatically (desktop responsive fallback, no user setting involved).
  // Ref callback because <main> mounts/unmounts with the calendar route.
  const viewAreaRO = useRef(null);
  const viewAreaRef = useCallback((el) => {
    if (viewAreaRO.current) { viewAreaRO.current.disconnect(); viewAreaRO.current = null; }
    if (!el) return;
    const sync = () => {
      const isNarrow = el.clientWidth > 0 && el.clientWidth < VIEW_AREA_MIN;
      if (state.viewAreaNarrow !== isNarrow) set({ viewAreaNarrow: isNarrow });
    };
    viewAreaRO.current = new ResizeObserver(sync);
    viewAreaRO.current.observe(el);
    sync();
  }, []);

  const calMeta = useMemo(() => calendarMeta(), [s.calendars]);

  // Time-relative styling reference: the current minute as ms. Views pass it
  // down to chips so past events dim and running events glow, advancing with
  // the minute tick above.
  const nowMs = s.nowMinute * 60000;

  // Occurrences on visible calendars, from the cache. Calendars flagged
  // groupSimilar get near-duplicate collapsing (synthetic group items).
  const occurrences = useMemo(() => {
    const visible = new Set(s.calendars.filter((c) => c.visible).map((c) => c.id));
    const out = [];
    for (const occ of state.occ.values()) {
      if (visible.has(occ.calendarId)) out.push(occ);
    }
    const flags = {};
    for (const c of s.calendars) flags[c.id] = !!c.groupSimilar;
    return groupOccurrences(out, flags);
  }, [s.occVersion, s.calendars]);

  // Group lookup for click routing (onOpenEvent has a stable identity, so it
  // reads the current map through a ref).
  const groupsRef = useRef(new Map());
  groupsRef.current = useMemo(() => {
    const m = new Map();
    for (const item of occurrences) {
      if (item.isGroup) m.set(item.instanceId, item);
    }
    return m;
  }, [occurrences]);

  // On-page filter: dim non-matching rendered events (client-side, live).
  // A group dims only when none of its members match.
  const dimSet = useMemo(() => {
    const needle = s.filterText.trim().toLowerCase();
    if (!needle) return null;
    const matches = (occ) => matchesFilter(occ, needle);
    const dim = new Set();
    for (const item of occurrences) {
      if (!itemMatchesFilter(item, matches)) dim.add(item.instanceId);
    }
    return dim;
  }, [occurrences, s.filterText]);

  // Window demand for day and agenda views (month and the infinite week
  // drive their own via scroll).
  useEffect(() => {
    if (s.view === 'day') {
      const start = s.anchor;
      loadWindow(
        toISOWithOffset(dateOfDayKey(addDaysKey(start, -1))),
        toISOWithOffset(dateOfDayKey(addDaysKey(start, 2))),
      );
      const [y, m] = start.split('-').map(Number);
      set({ visibleMonth: { year: y, month: m } });
    } else if (s.view === 'agenda') {
      // Anchor-aware window: follow the anchor (navigation/jump) rather than
      // always loading today-forward; "show past" widens the lookback.
      const from = addDaysKey(s.anchor, s.agendaShowPast ? -365 : -90);
      loadWindow(
        toISOWithOffset(dateOfDayKey(from)),
        toISOWithOffset(dateOfDayKey(addDaysKey(s.anchor, 120))),
      );
      const [y, m] = s.anchor.split('-').map(Number);
      set({ visibleMonth: { year: y, month: m } });
    }
  }, [s.view, s.anchor, s.agendaShowPast]);

  const onRequestWindow = useCallback(({ start, end }) => { loadWindow(start, end); }, []);
  const onVisibleMonthChange = useCallback((vm) => {
    set((st) => (st.visibleMonth && st.visibleMonth.year === vm.year && st.visibleMonth.month === vm.month
      ? {} : { visibleMonth: vm }));
  }, []);
  const onOpenEvent = useCallback((instanceId, anchorRect, opts) => {
    const group = groupsRef.current.get(instanceId);
    if (group) {
      set({ groupPopover: { group, anchorRect }, popover: null });
      return;
    }
    if (opts && opts.detail) {
      openDetail(instanceId);
      return;
    }
    set({ popover: { instanceId, anchorRect } });
  }, []);
  const onOpenDetail = useCallback((instanceId) => openDetail(instanceId), []);
  const onExpandDay = useCallback((dayKey) => {
    const cell = document.querySelector(`[data-day="${dayKey}"]`);
    set({ expandedDay: { dayKey, anchorRect: cell ? cell.getBoundingClientRect() : null } });
  }, []);
  const onCreateRange = useCallback(({ start, end, allDay }) => {
    set({ editor: { mode: 'create', draft: { start, end, allDay } } });
  }, []);

  // --- reschedule mode (PRD 5.9) -------------------------------------------
  const resched = s.reschedule;
  const reschedOcc = resched ? state.occ.get(resched.instanceId) : null;

  // The mode dies with its occurrence (a window refresh can drop it).
  useEffect(() => {
    if (resched && !reschedOcc) exitReschedule();
  }, [resched, reschedOcc]);

  // Film-strip center month: frozen at mode entry so the strip does not shift
  // under the pointer while navigating.
  const stripBase = useMemo(() => {
    if (!resched) return null;
    if (state.visibleMonth) return state.visibleMonth;
    const [y, m] = state.anchor.split('-').map(Number);
    return { year: y, month: m };
  }, [resched]);

  const onReschedMove = useCallback(({ instanceId, newStart, newEnd, targetKey }) => {
    exitReschedule();
    moveEvent({ instanceId, newStart, newEnd });
    jumpToDate(targetKey, instanceId); // return the view to where the event now is
  }, []);
  const onJumpMonth = useCallback((firstKey) => jumpToDate(firstKey), []);

  if (!s.booted) {
    return html`<div class="bc-boot">Loading</div>`;
  }
  if (!s.authed) {
    return html`<${Login} />`;
  }
  if (s.route === 'organize') {
    return html`<div class="bc-app"><${OrganizePage} /><${ShortcutsSheet} /><${Toasts} /></div>`;
  }
  if (s.route === 'outfeeds') {
    return html`<div class="bc-app"><${OutfeedsPage} /><${ShortcutsSheet} /><${Toasts} /></div>`;
  }
  if (s.route === 'filters') {
    return html`<div class="bc-app"><${FiltersPage} /><${ShortcutsSheet} /><${Toasts} /></div>`;
  }
  if (s.route === 'views') {
    return html`<div class="bc-app"><${SavedViewsPage} /><${ShortcutsSheet} /><${Toasts} /></div>`;
  }
  if (s.route === 'settings') {
    return html`<div class="bc-app"><${SettingsPage} /><${ShortcutsSheet} /><${Toasts} /></div>`;
  }
  if (s.route === 'people') {
    return html`<div class="bc-app"><${PeoplePage} /><${ShortcutsSheet} /><${Toasts} /></div>`;
  }

  let view = null;
  if (MONTH_ROWS[s.view]) {
    // The month slot: full month (7 columns) or the 3-day ribbon. Desktop is
    // always the full month unless the view area is too narrow (responsive
    // fallback); narrow viewports follow the mobile overviewMode setting.
    const ribbon = s.view === 'month' && effectiveOverviewMode() === '3day';
    const columns = ribbon ? 3 : 7;
    view = html`<${MonthGrid}
      key=${'grid' + columns}
      occurrences=${occurrences}
      calendars=${calMeta}
      columns=${columns}
      visibleRows=${ribbon ? 5 : MONTH_ROWS[s.view]}
      scrollKey=${s.anchor}
      scrollSeq=${s.scrollSeq}
      dimSet=${dimSet}
      nowMs=${nowMs}
      onRequestWindow=${onRequestWindow}
      onVisibleMonthChange=${onVisibleMonthChange}
      onOpenEvent=${onOpenEvent}
      onExpandDay=${onExpandDay}
      onCreateRange=${onCreateRange}
      onMoveEvent=${moveEvent}
      onResizeEvent=${resizeEvent}
    />`;
  } else if (s.view === 'week') {
    // Week is a horizontally infinite day track: no remount on navigation,
    // the anchor scrolls into place via scrollSeq.
    view = html`<${TimeGrid}
      key="week"
      infinite=${true}
      occurrences=${occurrences}
      calendars=${calMeta}
      dimSet=${dimSet}
      nowMs=${nowMs}
      scrollKey=${s.anchor}
      scrollSeq=${s.scrollSeq}
      onRequestWindow=${onRequestWindow}
      onVisibleMonthChange=${onVisibleMonthChange}
      onCreateRange=${onCreateRange}
      onMoveEvent=${moveEvent}
      onResizeEvent=${resizeEvent}
      onOpenEvent=${onOpenEvent}
    />`;
  } else if (s.view === 'day') {
    const days = [s.anchor];
    view = html`<${TimeGrid}
      key=${s.view + ':' + days[0]}
      days=${days}
      occurrences=${occurrences}
      calendars=${calMeta}
      dimSet=${dimSet}
      nowMs=${nowMs}
      onCreateRange=${onCreateRange}
      onMoveEvent=${moveEvent}
      onResizeEvent=${resizeEvent}
      onOpenEvent=${onOpenEvent}
    />`;
  } else {
    // "Show past" filtering keys off the same minute tick as the dim styling,
    // so an event that just ended drops out (or dims) on the next tick.
    let agendaOccs = s.agendaShowPast
      ? occurrences
      : occurrences.filter((o) => parseISO(o.end).getTime() >= nowMs);
    if (s.agendaSort === 'match') agendaOccs = sortByMatch(agendaOccs);
    view = html`<div class="bc-agenda-wrap">
      <div class="bc-agenda-toggle">
        <label class="bc-check">
          <input type="checkbox" checked=${s.agendaShowPast} onChange=${(e) => set({ agendaShowPast: e.target.checked })} />
          <span>Show past events</span>
        </label>
        <span class="bc-seg" role="group" aria-label="Agenda sort">
          ${[['time', 'By time'], ['match', 'By match']].map(([value, label]) => html`<button
            key=${value} type="button"
            class="bc-seg-btn${s.agendaSort === value ? ' is-active' : ''}"
            aria-pressed=${s.agendaSort === value}
            title=${value === 'match' ? 'Best-matching feed events first (background-scored)' : 'Chronological'}
            onClick=${() => set({ agendaSort: value })}
          >${label}</button>`)}
        </span>
      </div>
      <${AgendaList}
        occurrences=${agendaOccs}
        calendars=${calMeta}
        dimSet=${dimSet}
        nowMs=${nowMs}
        sortMode=${s.agendaSort}
        scrollKey=${s.anchor}
        scrollSeq=${s.scrollSeq}
        onOpenEvent=${onOpenEvent}
        onSetAttendance=${triageAttendance}
        onFeedback=${sendFeedback}
        onCreateDay=${(k) => onCreateRange(dayRangeDraft(k, k))}
        emptyLabel=${s.agendaShowPast ? 'No events' : 'No upcoming events'}
      />
    </div>`;
  }

  // Events overlapping the expanded day.
  let expand = null;
  if (s.expandedDay) {
    const ed = epochDayOfKey(s.expandedDay.dayKey);
    const dayOccs = occurrences.filter((occ) => {
      const { startKey, endKey } = occurrenceDaySpan(occ);
      return epochDayOfKey(startKey) <= ed && epochDayOfKey(endKey) >= ed;
    });
    expand = html`<${DayExpand}
      dayKey=${s.expandedDay.dayKey}
      anchorRect=${s.expandedDay.anchorRect}
      occurrences=${dayOccs}
      calendars=${calMeta}
      dimSet=${dimSet}
      nowMs=${nowMs}
      onOpenEvent=${onOpenEvent}
      onOpenDetail=${onOpenDetail}
      onClose=${() => set({ expandedDay: null })}
    />`;
  }

  const reschedActive = !!(resched && reschedOcc);

  return html`<div class="bc-app${dimSet ? ' is-page-filtering' : ''}">
    <${Toolbar} onToggleSidebar=${() => setSidebarOpen(!sidebarOpen)} />
    ${reschedActive && html`<${RescheduleBanner} occ=${reschedOcc} onExit=${exitReschedule} />`}
    <div class="bc-main">
      ${sidebarOpen && html`<div
        class="bc-sidebar-scrim"
        aria-hidden="true"
        onPointerDown=${(e) => { e.preventDefault(); e.stopPropagation(); setSidebarOpen(false); }}
      ></div>`}
      <${Sidebar} open=${sidebarOpen} onClose=${() => setSidebarOpen(false)} />
      <main class="bc-view" ref=${viewAreaRef}>${view}</main>
      ${reschedActive && html`<${RescheduleStrip}
        year=${stripBase.year} month=${stripBase.month}
        currentYear=${s.visibleMonth ? s.visibleMonth.year : 0}
        currentMonth=${s.visibleMonth ? s.visibleMonth.month : 0}
        onJumpMonth=${onJumpMonth}
      />`}
    </div>
    ${expand}
    <${QuickAdd} />
    <${EventPopover} />
    <${GroupPopover} />
    <${EventDetail} />
    <${EditorDrawer} />
    <${SearchOverlay} />
    <${ShortcutsSheet} />
    ${reschedActive && html`<${RescheduleOverlay}
      occ=${reschedOcc}
      cal=${calMeta[reschedOcc.calendarId]}
      onDropConfirmed=${onReschedMove}
      onExit=${exitReschedule}
    />`}
    <${Toasts} />
  </div>`;
}
