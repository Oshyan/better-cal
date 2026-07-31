// App root: routes between views, wires bettercal-ui to the store and API.

import { html, useState, useMemo, useEffect, useCallback } from '../../vendor/index.js';
import { useStore, set, state, calendarMeta, shallowEq } from './store.js';
import { loadWindow } from './api.js';
import { moveEvent, resizeEvent, triageAttendance } from './actions.js';
import { installKeyboard } from './keyboard.js';
import {
  startOfWeekKey, dayKeysOfWeek, weekIndexOfKey, addDaysKey, dateOfDayKey,
  toISOWithOffset, todayKey, parseISO, epochDayOfKey,
} from '../lib/dates.js';
import { occurrenceDaySpan } from '../ui/monthmath.js';
import { MonthGrid } from '../ui/MonthGrid.js';
import { TimeGrid } from '../ui/TimeGrid.js';
import { AgendaList } from '../ui/AgendaList.js';
import { DayExpand } from '../ui/DayExpand.js';
import { Toolbar } from './Toolbar.js';
import { Sidebar } from './Sidebar.js';
import { QuickAdd } from './QuickAdd.js';
import { EventPopover } from './EventPopover.js';
import { EditorDrawer } from './EditorDrawer.js';
import { SearchOverlay } from './SearchOverlay.js';
import { Toasts } from './Toasts.js';
import { Login } from './Login.js';
import { OutfeedsPage } from './OutfeedsPage.js';
import { FiltersPage } from './FiltersPage.js';
import { SavedViewsPage } from './SavedViewsPage.js';

const MONTH_ROWS = { month: 6, weeks3: 3, weeks2: 2 };

function matchesFilter(occ, needle) {
  return (occ.title || '').toLowerCase().includes(needle) ||
    (occ.location || '').toLowerCase().includes(needle) ||
    (occ.description || '').toLowerCase().includes(needle);
}

export function App() {
  const s = useStore(
    (st) => ({
      booted: st.booted, authed: st.authed, route: st.route, view: st.view,
      anchor: st.anchor, scrollSeq: st.scrollSeq, occVersion: st.occVersion,
      calendars: st.calendars, filterText: st.filterText,
      expandedDay: st.expandedDay, agendaShowPast: st.agendaShowPast,
    }),
    shallowEq,
  );
  const [sidebarOpen, setSidebarOpen] = useState(false);

  useEffect(() => installKeyboard(), []);

  const calMeta = useMemo(() => calendarMeta(), [s.calendars]);

  // Occurrences on visible calendars, from the cache.
  const occurrences = useMemo(() => {
    const visible = new Set(s.calendars.filter((c) => c.visible).map((c) => c.id));
    const out = [];
    for (const occ of state.occ.values()) {
      if (visible.has(occ.calendarId)) out.push(occ);
    }
    return out;
  }, [s.occVersion, s.calendars]);

  // On-page filter: dim non-matching rendered events (client-side, live).
  const dimSet = useMemo(() => {
    const needle = s.filterText.trim().toLowerCase();
    if (!needle) return null;
    const dim = new Set();
    for (const occ of occurrences) {
      if (!matchesFilter(occ, needle)) dim.add(occ.instanceId);
    }
    return dim;
  }, [occurrences, s.filterText]);

  // Window demand for non-month views (month drives its own via scroll).
  useEffect(() => {
    if (s.view === 'week' || s.view === 'day') {
      const start = s.view === 'week' ? startOfWeekKey(s.anchor) : s.anchor;
      const days = s.view === 'week' ? 7 : 1;
      loadWindow(
        toISOWithOffset(dateOfDayKey(addDaysKey(start, -1))),
        toISOWithOffset(dateOfDayKey(addDaysKey(start, days + 1))),
      );
      const [y, m] = start.split('-').map(Number);
      set({ visibleMonth: { year: y, month: m } });
    } else if (s.view === 'agenda') {
      const from = s.agendaShowPast ? addDaysKey(todayKey(), -365) : todayKey();
      loadWindow(
        toISOWithOffset(dateOfDayKey(from)),
        toISOWithOffset(dateOfDayKey(addDaysKey(todayKey(), 120))),
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
  const onOpenEvent = useCallback((instanceId, anchorRect) => {
    set({ popover: { instanceId, anchorRect } });
  }, []);
  const onExpandDay = useCallback((dayKey) => {
    const cell = document.querySelector(`[data-day="${dayKey}"]`);
    set({ expandedDay: { dayKey, anchorRect: cell ? cell.getBoundingClientRect() : null } });
  }, []);
  const onCreateRange = useCallback(({ start, end, allDay }) => {
    set({ editor: { mode: 'create', draft: { start, end, allDay } } });
  }, []);

  if (!s.booted) {
    return html`<div class="bc-boot">Loading</div>`;
  }
  if (!s.authed) {
    return html`<${Login} />`;
  }
  if (s.route === 'outfeeds') {
    return html`<div class="bc-app"><${OutfeedsPage} /><${Toasts} /></div>`;
  }
  if (s.route === 'filters') {
    return html`<div class="bc-app"><${FiltersPage} /><${Toasts} /></div>`;
  }
  if (s.route === 'views') {
    return html`<div class="bc-app"><${SavedViewsPage} /><${Toasts} /></div>`;
  }

  let view = null;
  if (MONTH_ROWS[s.view]) {
    view = html`<${MonthGrid}
      occurrences=${occurrences}
      calendars=${calMeta}
      visibleRows=${MONTH_ROWS[s.view]}
      scrollKey=${s.anchor}
      scrollSeq=${s.scrollSeq}
      dimSet=${dimSet}
      onRequestWindow=${onRequestWindow}
      onVisibleMonthChange=${onVisibleMonthChange}
      onOpenEvent=${onOpenEvent}
      onExpandDay=${onExpandDay}
      onCreateRange=${onCreateRange}
      onMoveEvent=${moveEvent}
      onResizeEvent=${resizeEvent}
    />`;
  } else if (s.view === 'week' || s.view === 'day') {
    const days = s.view === 'week' ? dayKeysOfWeek(weekIndexOfKey(s.anchor)) : [s.anchor];
    view = html`<${TimeGrid}
      key=${s.view + ':' + days[0]}
      days=${days}
      occurrences=${occurrences}
      calendars=${calMeta}
      dimSet=${dimSet}
      onCreateRange=${onCreateRange}
      onMoveEvent=${moveEvent}
      onResizeEvent=${resizeEvent}
      onOpenEvent=${onOpenEvent}
    />`;
  } else {
    const nowMs = Date.now();
    const agendaOccs = s.agendaShowPast
      ? occurrences
      : occurrences.filter((o) => parseISO(o.end).getTime() >= nowMs);
    view = html`<div class="bc-agenda-wrap">
      <label class="bc-check bc-agenda-toggle">
        <input type="checkbox" checked=${s.agendaShowPast} onChange=${(e) => set({ agendaShowPast: e.target.checked })} />
        <span>Show past events</span>
      </label>
      <${AgendaList}
        occurrences=${agendaOccs}
        calendars=${calMeta}
        dimSet=${dimSet}
        onOpenEvent=${onOpenEvent}
        onSetAttendance=${triageAttendance}
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
      onOpenEvent=${onOpenEvent}
      onClose=${() => set({ expandedDay: null })}
    />`;
  }

  return html`<div class="bc-app">
    <${Toolbar} onToggleSidebar=${() => setSidebarOpen(!sidebarOpen)} />
    <div class="bc-main">
      <${Sidebar} open=${sidebarOpen} />
      <main class="bc-view">${view}</main>
    </div>
    ${expand}
    <${QuickAdd} />
    <${EventPopover} />
    <${EditorDrawer} />
    <${SearchOverlay} />
    <${Toasts} />
  </div>`;
}
