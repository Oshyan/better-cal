// App root: routes between views, wires bettercal-ui to the store and API.

import { html, useState, useMemo, useRef, useEffect, useLayoutEffect, useCallback } from '../../vendor/index.js';
import { useStore, set, state, calendarMeta, shallowEq, invalidateRecords } from './store.js';
import { loadWindow, api, refreshWindow, loadPeople } from './api.js';
import { hiddenDayCounts } from '../lib/relfilter.js';
import {
  moveEvent, resizeEvent, sendFeedback, exitReschedule, googleBacked, setRelationship, showAllRel,
  jumpToDate, openDetail, effectiveOverviewMode,
  moveAvailabilitySpan, moveTrip, moveEventToCalendar, linkPersonToEvent, attachToTrip,
  resizeAvailabilitySpanDays,
} from './actions.js';
import { groupOccurrences, itemMatchesFilter } from '../ui/grouping.js';
import { sortByMatch } from '../lib/rank.js';
import { installKeyboard } from './keyboard.js';
import {
  addDaysKey, dateOfDayKey, toISOWithOffset, parseISO, epochDayOfKey,
  occDayKey, dayKeyOf, todayKey,
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
import { CreateDrawer } from './CreateDrawer.js';
import { SearchOverlay } from './SearchOverlay.js';
import { ShortcutsSheet } from './ShortcutsSheet.js';
import { CommandPalette } from './CommandPalette.js';
import { Toasts } from './Toasts.js';
import { SystemBanner } from './system.js';
import { Login } from './Login.js';
import { OutfeedsPage } from './OutfeedsPage.js';
import { FiltersPage } from './FiltersPage.js';
import { SavedViewsPage } from './SavedViewsPage.js';
import { SettingsPage } from './SettingsPage.js';
import { PeoplePage } from './PeoplePage.js';
import { ActivityPage } from './ActivityPage.js';
import { OrganizePage } from './OrganizePage.js';
import { PluginsPage } from './PluginsPage.js';
import { ReviewPage } from './ReviewPage.js';
import { sanitizeHtml } from '../lib/richtext.js';

const MONTH_ROWS = { month: 6, weeks3: 3, weeks2: 2 };
// Rows the month view will hold on screen before it starts squashing row
// height. Six is a month's worst case, not its usual one, so a short window
// clips down rather than compressing every day cell — the grid scrolls
// continuously, so a clipped row is one scroll away while a squashed row
// hides events on every day at once.
//
// Four, not five: the grid is willing to give up two rows before it gives up
// height, and four rows is still more calendar than the 3-week view we ship
// deliberately. The multiweek views have no floor of their own — their row
// count IS the view.
const MONTH_MIN_ROWS = { month: 4 };
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
      dropChoice: st.dropChoice,
      pluginCard: st.pluginCard,
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
  // Desktop sidebar collapse (persisted); mobile uses the slide-in drawer.
  const [sidebarCollapsed, setSidebarCollapsed] = useState(() => {
    try { return localStorage.getItem('bc-sidebar-collapsed') === '1'; } catch { return false; }
  });
  const toggleSidebar = () => {
    if (state.viewportNarrow) { setSidebarOpen(!sidebarOpen); return; }
    const next = !sidebarCollapsed;
    setSidebarCollapsed(next);
    try { localStorage.setItem('bc-sidebar-collapsed', next ? '1' : '0'); } catch { /* private mode */ }
  };

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

  // Auto-refresh: the server changes underneath us (mail ingest, feed polls,
  // CalDAV edits from other devices, agent API). Poll the cheap change
  // cursor every 30s while visible, and immediately on focus / visibility /
  // network return; when it moves, silently refetch the window plus
  // people/availability. Own mutations also move the cursor — the redundant
  // refresh merges harmlessly.
  useEffect(() => {
    let cursor = null;
    let stopped = false;
    let inFlight = false;
    const check = async () => {
      if (stopped || inFlight || document.visibilityState !== 'visible') return;
      inFlight = true;
      try {
        const d = await api('/changes/cursor');
        if (!stopped && d && d.cursor) {
          if (cursor !== null && d.cursor !== cursor) {
            invalidateRecords(); // something changed somewhere; fetched records may be stale
            refreshWindow();
            loadPeople().catch(() => {});
            set({ availSeq: state.availSeq + 1 });
          }
          cursor = d.cursor;
        }
      } catch { /* transient network problems just delay freshness */ }
      inFlight = false;
    };
    const timer = setInterval(check, 30000);
    const onWake = () => { if (document.visibilityState === 'visible') check(); };
    window.addEventListener('focus', onWake);
    window.addEventListener('online', onWake);
    document.addEventListener('visibilitychange', onWake);
    check(); // establish the baseline cursor
    return () => {
      stopped = true;
      clearInterval(timer);
      window.removeEventListener('focus', onWake);
      window.removeEventListener('online', onWake);
      document.removeEventListener('visibilitychange', onWake);
    };
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
  const showRel = useStore((st) => st.showRel);
  const occurrences = useMemo(() => {
    const visible = new Set(s.calendars.filter((c) => c.visible).map((c) => c.id));
    const out = [];
    for (const occ of state.occ.values()) {
      if (!visible.has(occ.calendarId)) continue;
      // The relationship quick filter: a kind switched off leaves every view.
      if (occ.relationship && showRel[occ.relationship] === false) continue;
      out.push(occ);
    }
    const flags = {};
    for (const c of s.calendars) flags[c.id] = !!c.groupSimilar;
    return groupOccurrences(out, flags);
  }, [s.occVersion, s.calendars, showRel]);
  // Days where the kind filter hid something: every view marks them, so a
  // filtered view never loses things silently (docs/relationships.md).
  const hiddenDays = useMemo(() => {
    const visible = new Set(s.calendars.filter((c) => c.visible).map((c) => c.id));
    const occs = [];
    for (const occ of state.occ.values()) if (visible.has(occ.calendarId)) occs.push(occ);
    return hiddenDayCounts(occs, showRel);
  }, [s.occVersion, s.calendars, showRel]);

  // Availability bands: visible people's away/busy spans for a wide window
  // around the visible month, synthesized into container-shaped pseudo
  // occurrences so the month/ribbon band machinery renders them.
  const availSpans = useStore((st) => st.availSpans);
  const availSeq = useStore((st) => st.availSeq);
  // Scalar month key so the effect follows real navigation (the s.visibleMonth
  // selector above is reschedule-gated and unsuitable here).
  const visMonthKey = useStore((st) => (st.visibleMonth ? st.visibleMonth.year * 100 + st.visibleMonth.month : 0));
  const anyPersonVisible = s.calendars && state.people.some((p) => p.showOnCalendar);
  const availKeyRef = useRef(null);
  useEffect(() => {
    if (!anyPersonVisible) {
      if (state.availSpans.length > 0) set({ availSpans: [] });
      return;
    }
    const vm = state.visibleMonth || { year: Number(todayKey().slice(0, 4)), month: Number(todayKey().slice(5, 7)) };
    const start = new Date(vm.year, vm.month - 1 - 1, 1);
    const end = new Date(vm.year, vm.month - 1 + 2, 1);
    const key = toISOWithOffset(start) + '|' + toISOWithOffset(end) + '|' + availSeq;
    // At boot this effect runs twice for the same months: once when people
    // finish loading, then again when the grid reports the month it settled
    // on — which is usually the month we had already guessed from today.
    // Skip the repeat rather than paying a second round trip for it.
    if (availKeyRef.current === key) return;
    availKeyRef.current = key;
    api('/availability?' + new URLSearchParams({ start: toISOWithOffset(start), end: toISOWithOffset(end) }))
      .then((d) => set({ availSpans: d.spans || [] }))
      .catch(() => { availKeyRef.current = null; }); // let a failure retry
  }, [anyPersonVisible, visMonthKey, availSeq]); // eslint-disable-line

  const availOccs = useMemo(() => availSpans.map((sp) => {
    const startKey = occDayKey({ allDay: false, start: sp.start });
    // End instant is exclusive; step back a minute so a midnight end doesn't
    // bleed the band into the next day.
    const endDate = new Date(parseISO(sp.end).getTime() - 60000);
    const endKey = dayKeyOf(endDate);
    return {
      instanceId: 'avail:' + sp.id,
      spanId: sp.id,
      personId: sp.personId,
      eventId: null,
      calendarId: null,
      title: sp.name + ' ' + sp.kind + (sp.note ? ' — ' + sp.note : ''),
      isContainer: true,
      availKind: sp.kind,
      personName: sp.name,
      allDay: true,
      start: startKey + 'T00:00:00+00:00',
      end: (endKey >= startKey ? endKey : startKey) + 'T00:00:00+00:00',
      attendance: 'none',
      status: 'confirmed',
      source: 'local',
    };
  }), [availSpans]);
  // Plugin overlay ranges: job-written rows fetched per visible month window
  // (same shape as availability). Hidden layers are dropped client-side so a
  // toggle is instant; the fetch itself is one indexed query.
  const pluginRanges = useStore((st) => st.pluginRanges);
  const pluginSeq = useStore((st) => st.pluginSeq);
  const pluginHidden = useStore((st) => st.settings.pluginHidden);
  const plugRangeKeyRef = useRef(null);
  useEffect(() => {
    if (!visMonthKey) return;
    const y = Math.floor(visMonthKey / 100);
    const m = visMonthKey % 100;
    const start = new Date(y, m - 1 - 1, 1);
    const end = new Date(y, m - 1 + 2, 1);
    const key = toISOWithOffset(start) + '|' + toISOWithOffset(end) + '|' + pluginSeq;
    if (plugRangeKeyRef.current === key) return;
    plugRangeKeyRef.current = key;
    api('/plugins/ranges?' + new URLSearchParams({ start: toISOWithOffset(start), end: toISOWithOffset(end) }))
      .then((d) => set({ pluginRanges: d.plugins || {} }))
      .catch(() => { plugRangeKeyRef.current = null; });
  }, [visMonthKey, pluginSeq]);

  const pluginOccs = useMemo(() => {
    const out = [];
    const decoBy = {};
    for (const pl of (state.plugins || [])) if (pl.decoration) decoBy[pl.id] = pl.decoration;
    for (const [pid, entry] of Object.entries(pluginRanges)) {
      if (pluginHidden && pluginHidden[pid]) continue;
      for (const r of entry.ranges || []) {
        const startKey = dayKeyOf(parseISO(r.start));
        const endKey = dayKeyOf(new Date(parseISO(r.end).getTime() - 60000));
        out.push({
          instanceId: 'plg:' + pid + ':' + r.id,
          eventId: null,
          calendarId: null,
          pluginId: pid,
          pluginColor: r.color || (decoBy[pid] && decoBy[pid].color) || null,
          pluginIcon: (decoBy[pid] && decoBy[pid].icon) || null,
          pluginIconPath: (decoBy[pid] && decoBy[pid].iconPath) || null,
          pluginAnim: (decoBy[pid] && decoBy[pid].animation) || null,
          detailHtml: r.detailHtml || null,
          rangeStart: r.start,
          rangeEnd: r.end,
          title: r.label,
          isContainer: true,
          allDay: true,
          start: startKey + 'T00:00:00+00:00',
          end: (endKey >= startKey ? endKey : startKey) + 'T00:00:00+00:00',
          attendance: 'none',
          status: 'confirmed',
          source: 'local',
        });
      }
    }
    return out;
  }, [pluginRanges, pluginHidden]);

  const occurrencesWithAvail = useMemo(
    () => {
      const extra = [...availOccs, ...pluginOccs];
      return extra.length > 0 ? [...occurrences, ...extra] : occurrences;
    },
    [occurrences, availOccs, pluginOccs],
  );

  // Stable-callback mirror of the merged occurrence list (plugin cards).
  const occurrencesRef = useRef([]);
  occurrencesRef.current = occurrencesWithAvail;

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

  // Window demand for the agenda view (month, the infinite week, and the day
  // stack all drive their own from scroll position).
  useEffect(() => {
    if (s.view === 'agenda') {
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

  // Drag-and-drop drop handlers (Wave 11). All of them route through actions
  // that toast with undo; the trip move asks first because silently dragging
  // every linked event along is sometimes right and sometimes very wrong.
  const onMoveSpan = useCallback(({ spanId, deltaDays }) => { moveAvailabilitySpan(spanId, deltaDays); }, []);
  const onMoveTrip = useCallback(({ occ, deltaDays, at }) => {
    set({
      dropChoice: {
        x: at.x,
        y: at.y,
        title: 'Move "' + (occ.title || 'trip') + '"',
        options: [
          { label: 'Trip only', value: 'only' },
          { label: 'Trip + events', value: 'members' },
          { label: 'Cancel', value: null },
        ],
        cb: (v) => { if (v) moveTrip(occ, deltaDays, v === 'members'); },
      },
    });
  }, []);
  const onDropToTrip = useCallback((occ, tripOcc) => { attachToTrip(tripOcc, occ); }, []);

  // GCal-style scope chip for direct manipulation of repeating events: every
  // drag that would silently edit "this occurrence" asks which occurrences it
  // means, at the drop point. The editor keeps its own scope select.
  const promptScopeAt = useCallback((at, cb, occ) => {
    set({
      dropChoice: {
        x: at.x, y: at.y, title: occ && googleBacked(occ) ? "Repeating event (goes to Google; can't be undone)" : 'Repeating event',
        options: [
          { label: 'This event', value: 'this' },
          { label: 'This + following', value: 'following' },
          { label: 'All events', value: 'all' },
          { label: 'Cancel', value: null },
        ],
        cb: (v) => { if (v) cb(v); },
      },
    });
  }, []);

  const onDropToCalendar = useCallback((occ, calendarId, at) => {
    if (occ.recurring && at) { promptScopeAt(at, (scope) => moveEventToCalendar(occ, calendarId, scope)); return; }
    moveEventToCalendar(occ, calendarId);
  }, [promptScopeAt]);

  const onDropToPerson = useCallback((occ, name, at) => {
    if (occ.recurring && at) { promptScopeAt(at, (scope) => linkPersonToEvent(occ, name, scope)); return; }
    linkPersonToEvent(occ, name);
  }, [promptScopeAt]);

  const onMoveEventW = useCallback((p) => {
    const occ = state.occ.get(p.instanceId);
    if (occ && occ.recurring && p.at) { promptScopeAt(p.at, (scope) => moveEvent({ ...p, scope }), occ); return; }
    moveEvent(p);
  }, [promptScopeAt]);

  const onResizeEventW = useCallback((p) => {
    // Availability bands resize through their span endpoint: shift only the
    // dragged edge by whole days, preserving the span's times.
    if (String(p.instanceId).startsWith('avail:')) {
      const spanId = Number(String(p.instanceId).slice(6));
      const sp = state.availSpans.find((x) => x.id === spanId);
      if (!sp) return;
      // All-day drags report literal day keys; span instants are real ISO.
      const dayOf = (v) => epochDayOfKey(/^\d{4}-\d{2}-\d{2}$/.test(String(v)) ? String(v) : dayKeyOf(parseISO(v)));
      // The pseudo-occurrence's end is inclusive-day; the drag reports an
      // exclusive end, so compare each against its own convention.
      const startDelta = p.edge === 'start' ? dayOf(p.newStart) - dayOf(sp.start) : 0;
      const endDelta = p.edge === 'end'
        ? dayOf(p.newEnd) - epochDayOfKey(dayKeyOf(new Date(parseISO(sp.end).getTime() - 60000))) - 1
        : 0;
      resizeAvailabilitySpanDays(spanId, startDelta, endDelta);
      return;
    }
    const occ = state.occ.get(p.instanceId);
    if (occ && occ.recurring && p.at) { promptScopeAt(p.at, (scope) => resizeEvent({ ...p, scope }), occ); return; }
    resizeEvent(p);
  }, [promptScopeAt]);

  const onRequestWindow = useCallback(({ start, end }) => { loadWindow(start, end); }, []);
  const onVisibleMonthChange = useCallback((vm) => {
    set((st) => (st.visibleMonth && st.visibleMonth.year === vm.year && st.visibleMonth.month === vm.month
      ? {} : { visibleMonth: vm }));
  }, []);
  const onOpenEvent = useCallback((instanceId, anchorRect, opts) => {
    // Plugin bands open a host-rendered info card (sanitized detail HTML).
    if (String(instanceId).startsWith('plg:')) {
      const occ = occurrencesRef.current.find((o) => o.instanceId === instanceId);
      if (occ) set({ pluginCard: { occ, anchorRect } });
      return;
    }
    // Availability bands are people, not events: land on their People entry.
    if (String(instanceId).startsWith('avail:')) {
      const span = state.availSpans.find((sp) => 'avail:' + sp.id === instanceId);
      set({ route: 'people', peopleFocus: span ? span.name : null });
      return;
    }
    const group = groupsRef.current.get(instanceId);
    if (group) {
      set({ groupPopover: { group, anchorRect }, popover: null });
      return;
    }
    // dayKey (passed by the day-expand list) pins prev/next navigation to
    // the day being browsed rather than each event's own start day.
    const dayKey = opts && opts.dayKey;
    if (opts && opts.detail) {
      set({ detail: { instanceId, dayKey }, popover: null, groupPopover: null, expandedDay: null });
      return;
    }
    // Clicking the event whose popover is already open dismisses it (a
    // second click otherwise just re-opens the same popover with no way to
    // close it from inside the grid).
    set((st) => (st.popover && st.popover.instanceId === instanceId
      ? { popover: null }
      : { popover: { instanceId, anchorRect, dayKey } }));
  }, []);
  const onOpenDetail = useCallback((instanceId) => openDetail(instanceId), []);
  // Day-number clicks (month grid, week header) go to that day's Day view.
  const onOpenDay = useCallback((dayKey) => {
    set({ view: 'day', anchor: dayKey, scrollSeq: state.scrollSeq + 1 });
  }, []);
  // Day view scrolled to a different day: follow it in the toolbar label and
  // mini-month WITHOUT bumping scrollSeq, which would yank the scroll back.
  const onVisibleDay = useCallback((dayKey) => {
    const [y, m] = dayKey.split('-').map(Number);
    set((st) => (st.anchor === dayKey ? {} : { anchor: dayKey, visibleMonth: { year: y, month: m } }));
  }, []);
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

  const onReschedMove = useCallback(({ instanceId, newStart, newEnd, targetKey, x, y }) => {
    exitReschedule();
    const occ = state.occ.get(instanceId);
    const land = (scope) => {
      moveEvent({ instanceId, newStart, newEnd, scope });
      jumpToDate(targetKey, instanceId); // return the view to where the event now is
    };
    // A series asks which occurrences, at the confirm chip, like a grid drop.
    if (occ && occ.recurring && x != null) { promptScopeAt({ x, y }, land, occ); return; }
    land(undefined);
  }, [promptScopeAt]);
  const onJumpMonth = useCallback((firstKey) => jumpToDate(firstKey), []);

  if (!s.booted) {
    // The frame the app will have, painted before /me answers, so the first
    // second reads as loading into place rather than a word on a blank page.
    return html`<div class="bc-boot" aria-busy="true" aria-label="Loading">
      <div class="bc-boot-bar"><i></i><i></i><i></i></div>
      <div class="bc-boot-body">
        <div class="bc-boot-side"><i></i><i></i><i></i><i></i><i></i><i></i></div>
        <div class="bc-boot-grid">${Array.from({ length: 35 }, (_, i) => html`<div key=${i}></div>`)}</div>
      </div>
    </div>`;
  }
  if (!s.authed) {
    return html`<${Login} />`;
  }
  if (s.route === 'organize') {
    return html`<div class="bc-app"><${OrganizePage} /><${CreateDrawer} /><${ShortcutsSheet} /><${CommandPalette} /><${SystemBanner} /><${Toasts} /></div>`;
  }
  if (s.route === 'outfeeds') {
    return html`<div class="bc-app"><${OutfeedsPage} /><${CreateDrawer} /><${ShortcutsSheet} /><${CommandPalette} /><${SystemBanner} /><${Toasts} /></div>`;
  }
  if (s.route === 'filters') {
    return html`<div class="bc-app"><${FiltersPage} /><${CreateDrawer} /><${ShortcutsSheet} /><${CommandPalette} /><${SystemBanner} /><${Toasts} /></div>`;
  }
  if (s.route === 'views') {
    return html`<div class="bc-app"><${SavedViewsPage} /><${CreateDrawer} /><${ShortcutsSheet} /><${CommandPalette} /><${SystemBanner} /><${Toasts} /></div>`;
  }
  if (s.route === 'settings') {
    return html`<div class="bc-app"><${SettingsPage} /><${CreateDrawer} /><${ShortcutsSheet} /><${CommandPalette} /><${SystemBanner} /><${Toasts} /></div>`;
  }
  if (s.route === 'people') {
    return html`<div class="bc-app"><${PeoplePage} /><${CreateDrawer} /><${ShortcutsSheet} /><${CommandPalette} /><${SystemBanner} /><${Toasts} /></div>`;
  }
  if (s.route === 'review') {
    return html`<div class="bc-app"><${ReviewPage} /><${CreateDrawer} /><${ShortcutsSheet} /><${CommandPalette} /><${SystemBanner} /><${Toasts} /></div>`;
  }
  if (s.route === 'plugins') {
    return html`<div class="bc-app"><${PluginsPage} /><${CreateDrawer} /><${ShortcutsSheet} /><${CommandPalette} /><${SystemBanner} /><${Toasts} /></div>`;
  }
  if (s.route === 'activity') {
    return html`<div class="bc-app"><${ActivityPage} /><${CreateDrawer} /><${ShortcutsSheet} /><${CommandPalette} /><${SystemBanner} /><${Toasts} /></div>`;
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
      occurrences=${occurrencesWithAvail}
      hiddenDays=${hiddenDays} onShowHidden=${showAllRel}
      calendars=${calMeta}
      columns=${columns}
      visibleRows=${ribbon ? 5 : MONTH_ROWS[s.view]}
      minRows=${ribbon ? 5 : MONTH_MIN_ROWS[s.view] || MONTH_ROWS[s.view]}
      scrollKey=${s.anchor}
      scrollSeq=${s.scrollSeq}
      dimSet=${dimSet}
      nowMs=${nowMs}
      onRequestWindow=${onRequestWindow}
      onVisibleMonthChange=${onVisibleMonthChange}
      onOpenEvent=${onOpenEvent}
      onExpandDay=${onExpandDay}
      onOpenDay=${onOpenDay}
      onCreateRange=${onCreateRange}
      onMoveEvent=${onMoveEventW}
      onResizeEvent=${onResizeEventW}
      onMoveSpan=${onMoveSpan}
      onMoveTrip=${onMoveTrip}
      onDropToCalendar=${onDropToCalendar}
      onDropToPerson=${onDropToPerson}
      onDropToTrip=${onDropToTrip}
    />`;
  } else if (s.view === 'week') {
    // Week is a horizontally infinite day track: no remount on navigation,
    // the anchor scrolls into place via scrollSeq.
    view = html`<${TimeGrid}
      key="week"
      infinite=${true}
      occurrences=${occurrences}
      hiddenDays=${hiddenDays}
      calendars=${calMeta}
      dimSet=${dimSet}
      nowMs=${nowMs}
      scrollKey=${s.anchor}
      scrollSeq=${s.scrollSeq}
      onRequestWindow=${onRequestWindow}
      onVisibleMonthChange=${onVisibleMonthChange}
      onCreateRange=${onCreateRange}
      onMoveEvent=${onMoveEventW}
      onResizeEvent=${onResizeEventW}
      onOpenEvent=${onOpenEvent}
      onOpenDay=${onOpenDay}
      onDropToCalendar=${onDropToCalendar}
      onDropToPerson=${onDropToPerson}
    />`;
  } else if (s.view === 'day') {
    // Day view is a vertical stack: scrolling past midnight continues into
    // the next day, so it never remounts per day (the anchor scrolls).
    view = html`<${TimeGrid}
      key="day"
      vstack=${true}
      days=${[s.anchor]}
      occurrences=${occurrences}
      hiddenDays=${hiddenDays}
      calendars=${calMeta}
      dimSet=${dimSet}
      nowMs=${nowMs}
      scrollKey=${s.anchor}
      scrollSeq=${s.scrollSeq}
      onRequestWindow=${onRequestWindow}
      onVisibleDay=${onVisibleDay}
      onExpandDay=${onExpandDay}
      onCreateRange=${onCreateRange}
      onMoveEvent=${onMoveEventW}
      onResizeEvent=${onResizeEventW}
      onOpenEvent=${onOpenEvent}
      onDropToCalendar=${onDropToCalendar}
      onDropToPerson=${onDropToPerson}
    />`;
  } else {
    // "Show past" filtering keys off the same minute tick as the dim styling,
    // so an event that just ended drops out (or dims) on the next tick.
    //
    // The cutoff follows the anchor once the anchor moves BEHIND now: the
    // chevrons used to walk the label back through May while the list stayed
    // pinned to today, because every occurrence they were walking toward had
    // already been filtered out. Anchors on today or ahead of it keep the
    // now-cutoff, so the default view still drops this morning's leftovers.
    const agendaCutoff = Math.min(nowMs, dateOfDayKey(s.anchor).getTime());
    let agendaOccs = s.agendaShowPast
      ? occurrences
      : occurrences.filter((o) => parseISO(o.end).getTime() >= agendaCutoff);
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
        hiddenDays=${hiddenDays} onShowHidden=${showAllRel}
        calendars=${calMeta}
        dimSet=${dimSet}
        nowMs=${nowMs}
        sortMode=${s.agendaSort}
        scrollKey=${s.anchor}
        scrollSeq=${s.scrollSeq}
        onVisibleMonthChange=${onVisibleMonthChange}
        onOpenEvent=${onOpenEvent}
        onSetAttendance=${(occ, v) => setRelationship(occ, occ.relationship === v ? 'available' : v)}
        onFeedback=${sendFeedback}
        onCreateDay=${(k) => onCreateRange(dayRangeDraft(k, k))}
        emptyLabel=${s.agendaShowPast ? 'No events' : 'No upcoming events'}
        gapFrom=${s.agendaShowPast ? null : dayKeyOf(new Date(agendaCutoff))}
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
    <${Toolbar} onToggleSidebar=${toggleSidebar} />
    ${reschedActive && html`<${RescheduleBanner} occ=${reschedOcc} onExit=${exitReschedule} />`}
    <div class="bc-main">
      ${sidebarOpen && html`<div
        class="bc-sidebar-scrim"
        aria-hidden="true"
        onPointerDown=${(e) => { e.preventDefault(); e.stopPropagation(); setSidebarOpen(false); }}
      ></div>`}
      <${Sidebar} open=${sidebarOpen} collapsed=${sidebarCollapsed} onClose=${() => setSidebarOpen(false)} />
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
    <${CreateDrawer} />
    <${SearchOverlay} />
    <${ShortcutsSheet} /><${CommandPalette} />
    ${s.dropChoice && html`<${DropChoiceChip} choice=${s.dropChoice} />`}
    ${s.pluginCard && html`<${PluginRangeCard} card=${s.pluginCard} />`}
    ${reschedActive && html`<${RescheduleOverlay}
      occ=${reschedOcc}
      cal=${calMeta[reschedOcc.calendarId]}
      onDropConfirmed=${onReschedMove}
      onExit=${exitReschedule}
    />`}
    <${SystemBanner} /><${Toasts} />
  </div>`;
}

// A tiny decision chip pinned at a drop point (trip move: with or without its
// events). Escape or any outside press dismisses without acting.
function DropChoiceChip({ choice }) {
  const rootRef = useRef(null);
  useEffect(() => {
    const dismiss = () => set({ dropChoice: null });
    const onDoc = (e) => { if (rootRef.current && !rootRef.current.contains(e.target)) dismiss(); };
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); dismiss(); } };
    document.addEventListener('pointerdown', onDoc, true);
    document.addEventListener('keydown', onKey, true);
    return () => {
      document.removeEventListener('pointerdown', onDoc, true);
      document.removeEventListener('keydown', onKey, true);
    };
  }, []);
  // Clamped so a drop near the viewport edge keeps the chip fully on-screen.
  const x = Math.max(8, Math.min(choice.x, window.innerWidth - 300));
  const y = Math.max(8, Math.min(choice.y, window.innerHeight - 56));
  return html`<div class="bc-dropchoice" ref=${rootRef} style=${'left:' + x + 'px;top:' + y + 'px'} role="dialog" aria-label=${choice.title}>
    <span class="bc-dropchoice-title">${choice.title}</span>
    ${choice.options.map((o) => html`<button
      key=${String(o.value)} type="button"
      class="bc-btn${o.value ? ' bc-btn-primary' : ''}"
      onClick=${() => { set({ dropChoice: null }); choice.cb(o.value); }}
    >${o.label}</button>`)}
  </div>`;
}

// Info card for a plugin band: label, span, and the plugin's sanitized
// detail HTML (server-sanitized at write time, client-sanitized again at
// render — same belt-and-suspenders as event descriptions).
function PluginRangeCard({ card }) {
  const rootRef = useRef(null);
  useEffect(() => {
    const dismiss = () => set({ pluginCard: null });
    const onDoc = (e) => { if (rootRef.current && !rootRef.current.contains(e.target)) dismiss(); };
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); dismiss(); } };
    document.addEventListener('pointerdown', onDoc, true);
    document.addEventListener('keydown', onKey, true);
    return () => {
      document.removeEventListener('pointerdown', onDoc, true);
      document.removeEventListener('keydown', onKey, true);
    };
  }, []);
  // The x axis was clamped to the viewport from the start; y was not, so a band
  // low on the screen opened a card that ran off the bottom — and because the
  // card is position:fixed, the overflow was unreachable rather than merely
  // ugly. Measure after layout and, when there is no room below the anchor,
  // flip above it; if it fits neither way, clamp and let the body scroll.
  useLayoutEffect(() => {
    const el = rootRef.current;
    if (!el || !anchorRect) return;
    const margin = 8;
    const h = el.offsetHeight;
    if (anchorRect.bottom + 6 + h <= window.innerHeight - margin) return; // fits below
    const above = anchorRect.top - 6 - h;
    el.style.top = (above >= margin ? above : Math.max(margin, window.innerHeight - margin - h)) + 'px';
  }, [card]);
  const { occ, anchorRect } = card;
  const x = Math.max(8, Math.min(anchorRect ? anchorRect.left : 100, window.innerWidth - 340));
  const y = Math.max(8, (anchorRect ? anchorRect.bottom + 6 : 100));
  const spanStart = new Date(occ.rangeStart);
  const spanEnd = new Date(occ.rangeEnd);
  const sameDay = spanStart.toDateString() === spanEnd.toDateString();
  const fmtD = (d) => d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
  const fmtT = (d) => d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  return html`<div class="bc-plugincard" ref=${rootRef} style=${'left:' + x + 'px;top:' + y + 'px'} role="dialog" aria-label=${occ.title}>
    <div class="bc-plugincard-head">
      <span class="bc-plugincard-dot" style=${occ.pluginColor ? 'background:' + occ.pluginColor : ''}></span>
      <span class="bc-plugincard-title">${occ.title}</span>
      <span class="bc-plugincard-plugin">${occ.pluginId}</span>
    </div>
    <div class="bc-plugincard-when">
      ${fmtD(spanStart)} ${fmtT(spanStart)}${sameDay ? ' – ' + fmtT(spanEnd) : ' – ' + fmtD(spanEnd) + ' ' + fmtT(spanEnd)}
    </div>
    ${occ.detailHtml && html`<div class="bc-plugincard-body bc-rich" dangerouslySetInnerHTML=${{ __html: sanitizeHtml(occ.detailHtml) }}></div>`}
  </div>`;
}
