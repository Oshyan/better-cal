// App root: routes between views, wires bettercal-ui to the store and API.

import { CalDot, setPluginDecoLookup } from '../ui/icons.js';
import { COMPACT_QUERY, COARSE_QUERY, PHONE_WIDE_QUERY } from '../lib/breakpoints.js';
import { html, useState, useMemo, useRef, useEffect, useLayoutEffect, useCallback } from '../../vendor/index.js';
import { useStore, set, state, calendarMeta, shallowEq, invalidateRecords } from './store.js';
import { PHONE_QUERY } from '../lib/breakpoints.js';
import { adoptSettings } from './settings.js';
import { loadWindow, api, refreshWindow, loadPeople, loadCalendars, retryWindowsNow, loadReviewCount, loadSystemHealth, loadPlugins, loadUpdates, loadSavedViews } from './api.js';
import { hiddenDayCounts } from '../lib/relfilter.js';
import {
  moveEvent, resizeEvent, sendFeedback, exitReschedule, googleBacked, setRelationship, showAllRel,
  jumpToDate, openDetail, effectiveOverviewMode,
  moveAvailabilitySpan, moveTrip, moveEventToCalendar, linkPersonToEvent, attachToTrip,
  resizeAvailabilitySpanDays, copyEventAt, copyEventTo, toggleFitRows } from './actions.js';
import { groupOccurrences, itemMatchesFilter } from '../ui/grouping.js';
import { collapseDuplicates } from '../ui/duplicates.js';
import { hidesCancelled } from '../ui/daysteps.js';
import { sortByMatch } from '../lib/rank.js';
import { installKeyboard } from './keyboard.js';
import { installPasteAnywhere } from './pasteanywhere.js';
import {
  addDaysKey, dateOfDayKey, toISOWithOffset, parseISO, epochDayOfKey,
  dayKeyOf, todayKey,
} from '../lib/dates.js';
import { occurrenceDaySpan } from '../ui/monthmath.js';
import { bandDays } from '../lib/bands.js';
import { MonthGrid } from '../ui/MonthGrid.js';
import { TimeGrid } from '../ui/TimeGrid.js';
import { AgendaList } from '../ui/AgendaList.js';
import { dayRangeDraft } from '../lib/quickcreate.js';
import { DayExpand } from '../ui/DayExpand.js';
import { RescheduleBanner, RescheduleStrip, RescheduleOverlay } from '../ui/RescheduleMode.js';
import { Toolbar, PageToolbar } from './Toolbar.js';
import { BottomBar } from './BottomBar.js';
import { Sidebar } from './Sidebar.js';
import { QuickAdd } from './QuickAdd.js';
import { QuickAddWindow } from './QuickAddWindow.js';
import { Welcome } from './Welcome.js';
import { EventPopover } from './EventPopover.js';
import { GroupPopover } from './GroupPopover.js';
import { EditorDrawer } from './EditorDrawer.js';
import { CreateDrawer } from './CreateDrawer.js';
import { SearchOverlay } from './SearchOverlay.js';
import { ShortcutsSheet } from './ShortcutsSheet.js';
import { MoveDialog } from './MoveToGoogle.js';
import { CommandPalette } from './CommandPalette.js';
import { Toasts } from './Toasts.js';
import { ConfirmHost } from './ConfirmDialog.js';
import { SystemBanner, WorkerBanner } from './system.js';
import { UpdateBanner, OfflineBanner, AppReloadBanner } from './updates.js';
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
import { onOutsidePress, insideAny, consumeOutsidePress } from '../ui/outside.js';
import { installDrawerSwipe } from '../ui/drawerswipe.js';
import { SplitView } from '../ui/SplitView.js';
import { stepBack, afterHistorySettles } from './historysteps.js';
import { primeKeyboard } from '../lib/ioskeyboard.js';
import { noticeServerVersion, lookForUpdate } from './appversion.js';
import { refreshBadge, badgeSettingChanged, clearBadge } from './badge.js';
import { reloadIfReady } from './drafts.js';

const MONTH_ROWS = { month: 6, weeks3: 3, weeks2: 2 };

function AppBanners() {
  return html`<${OfflineBanner} /><${UpdateBanner} /><${SystemBanner} />`;
}
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

// The day under the last press, for an event that spans several days. A
// bar crosses many day cells, so the cell (or week column) under the pointer
// says which day of it was meant. A key press forgets the pointer, so an
// event opened from the keyboard keeps its own start day.
let lastPress = null;
if (typeof window !== 'undefined') {
  window.addEventListener('pointerdown', (e) => { lastPress = { x: e.clientX, y: e.clientY }; }, true);
  window.addEventListener('keydown', () => { lastPress = null; }, true);
}
function pressedDay(occ) {
  if (!occ || !lastPress) return undefined;
  const span = occurrenceDaySpan(occ);
  if (span.startKey === span.endKey) return undefined;
  const { x, y } = lastPress;
  let key = null;
  for (const el of document.elementsFromPoint(x, y)) {
    const k = el.closest && el.closest('[data-day]');
    if (k && k.dataset.day) { key = k.dataset.day; break; }
  }
  if (!key) {
    // The week grid's all-day lane sits above its day columns: match by x.
    for (const col of document.querySelectorAll('.bc-tg-col[data-day]')) {
      const r = col.getBoundingClientRect();
      if (r.width > 0 && x >= r.left && x < r.right) { key = col.dataset.day; break; }
    }
  }
  if (!key) return undefined;
  const ed = epochDayOfKey(key);
  return ed >= epochDayOfKey(span.startKey) && ed <= epochDayOfKey(span.endKey) ? key : undefined;
}

export function App() {
  const s = useStore(
    (st) => ({
      booted: st.booted, serverState: st.serverState, authed: st.authed, route: st.route, view: st.view,
      anchor: st.anchor, scrollSeq: st.scrollSeq, glideSeq: st.glideSeq, occVersion: st.occVersion,
      calendars: st.calendars, filterText: st.filterText,
      expandedDay: st.expandedDay, agendaShowPast: st.agendaShowPast,
      agendaSort: st.agendaSort, windowStatus: st.windowStatus,
      reschedule: st.reschedule,
      dropChoice: st.dropChoice,
      pluginCard: st.pluginCard,
      visibleMonth: st.reschedule ? st.visibleMonth : null,
      overviewMode: st.settings.overviewMode,
      gridAnchor: st.settings.gridAnchor,
      monthRows: st.settings.monthRows,
      nowMinute: st.nowMinute,
      viewportNarrow: st.viewportNarrow,
      phoneWide: st.phoneWide,
      viewAreaNarrow: st.viewAreaNarrow,
      coarsePointer: st.coarsePointer,
    }),
    shallowEq,
  );
  const [sidebarOpen, setSidebarOpen] = useState(false);
  // Swipe the drawer in from the left edge and back out (drawer layouts).
  const sidebarOpenRef = useRef(false);
  sidebarOpenRef.current = sidebarOpen;
  // "Manage calendar" from the event sheet: open the drawer; the sidebar
  // opens that calendar's settings (Sidebar.js).
  const manageCal = useStore((st) => st.manageCal);
  const tuckSetting = useStore((st) => st.settings.panelTucksSidebar);
  const popoverOpen = useStore((st) => !!st.popover);
  // Phone: Back closes the drawer, as it closes every other full-screen
  // surface (0.6.9); before, it left the app.
  useEffect(() => {
    if (!sidebarOpen || !window.matchMedia(PHONE_QUERY).matches) return undefined;
    history.pushState({ bcDrawer: 1 }, '');
    let popped = false;
    const onPop = () => { popped = true; setSidebarOpen(false); };
    window.addEventListener('popstate', onPop);
    return () => {
      window.removeEventListener('popstate', onPop);
      if (popped || !history.state || !history.state.bcDrawer) return;
      // Closed by opening a page (Settings, People, ...): the page takes over
      // this entry rather than stacking another on it.
      if (state.route && state.route !== 'calendar') history.replaceState({ bcRoute: state.route }, '');
      else history.back();
    };
  }, [sidebarOpen]);
  useEffect(() => installDrawerSwipe({
    enabled: () => state.viewportNarrow,
    isOpen: () => sidebarOpenRef.current,
    open: () => setSidebarOpen(true),
    close: () => setSidebarOpen(false),
  }), []);
  // Desktop sidebar collapse (persisted); mobile uses the slide-in drawer.
  const [sidebarCollapsed, setSidebarCollapsed] = useState(() => {
    try { return localStorage.getItem('bc-sidebar-collapsed') === '1'; } catch { return false; }
  });
  useEffect(() => {
    if (!manageCal) return;
    if (state.viewportNarrow) {
      setSidebarOpen(true);
    } else if (sidebarCollapsed) {
      setSidebarCollapsed(false);
      try { localStorage.setItem('bc-sidebar-collapsed', '0'); } catch { /* private mode */ }
    }
  }, [manageCal]); // Sidebar consumes manageCal after opening the settings panel.
  const toggleSidebar = () => {
    if (state.viewportNarrow) { setSidebarOpen(!sidebarOpen); return; }
    const next = !sidebarCollapsed;
    setSidebarCollapsed(next);
    try { localStorage.setItem('bc-sidebar-collapsed', next ? '1' : '0'); } catch { /* private mode */ }
  };

  useEffect(() => installKeyboard(), []);
  // Cmd/Ctrl+V on the calendar opens quick add with what was pasted.
  useEffect(() => installPasteAnywhere(), []);

  // The app icon's number (#170): follow the setting, and show nothing once
  // signed out.
  const appBadge = useStore((st) => st.settings.appBadge);
  useEffect(() => {
    if (s.authed) badgeSettingChanged();
    else if (s.booted) clearBadge();
  }, [appBadge, s.authed, s.booted]);

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
    let review = null;
    let status = null;
    let stopped = false;
    let inFlight = false;
    const check = async () => {
      if (stopped || inFlight || document.visibilityState !== 'visible') return;
      // Nothing changes under a drag: the next check, after the drop, picks
      // up whatever moved meanwhile.
      if (document.body.classList.contains('bc-dragging')) return;
      inFlight = true;
      try {
        const d = await api('/changes/cursor');
        // A different version installed on the server: look for it now.
        if (d) noticeServerVersion(d.app);
        if (!stopped && d && d.cursor) {
          // Each part reloads only what it covers, merged into what's on
          // screen: no reload, no lost place, nothing taken out of your hands.
          const moved = cursor !== null && d.cursor !== cursor;
          const reviewMoved = review !== null && d.review !== review;
          const statusMoved = status !== null && d.status !== status;
          if (moved) {
            invalidateRecords(); // something changed somewhere; fetched records may be stale
            refreshWindow();
            loadPeople().catch(() => {});
            loadSavedViews().catch(() => {});
            set({ availSeq: state.availSeq + 1 });
            // Settings changed on another device, unless this tab is
            // editing them right now.
            if (state.route !== 'settings') {
              api('/settings').then((r) => {
                const next = r && r.settings;
                // Most moves aren't settings; adopting identical ones would
                // only redraw everything that reads them.
                if (next && Object.keys(next).some((k) => JSON.stringify(next[k]) !== JSON.stringify(state.settings[k]))) adoptSettings(next);
              }).catch(() => {});
            }
          }
          if (moved || statusMoved) loadCalendars().catch(() => {});
          // The Review badge: held mail, proposals and possible duplicates
          // arrive without an event (their own part), and unanswered
          // invitations or decisions made elsewhere ride on the events part.
          if (moved || reviewMoved) loadReviewCount();
          if (statusMoved) {
            loadSystemHealth();
            loadPlugins();
            loadUpdates();
            set({ pluginSeq: state.pluginSeq + 1 });
          }
          if (moved || reviewMoved || statusMoved) set({ activitySeq: state.activitySeq + 1 });
          // The app icon's number (#170), when something it counts may have moved.
          refreshBadge({ changed: moved || reviewMoved });
          cursor = d.cursor;
          review = d.review ?? null;
          status = d.status ?? null;
        }
      } catch { /* transient network problems just delay freshness */ }
      inFlight = false;
    };
    const timer = setInterval(check, 30000);
    const onWake = () => { if (document.visibilityState === 'visible') check(); };
    // Something here knows the server just changed (a plugin run it started
    // has finished) or the calendar is back on screen: look now rather than
    // at the next 30-second check.
    window.addEventListener('bc-check-changes', onWake);
    window.addEventListener('focus', onWake);
    window.addEventListener('online', onWake);
    document.addEventListener('visibilitychange', onWake);
    check(); // establish the baseline cursor
    return () => {
      stopped = true;
      clearInterval(timer);
      window.removeEventListener('bc-check-changes', onWake);
      window.removeEventListener('focus', onWake);
      window.removeEventListener('online', onWake);
      document.removeEventListener('visibilitychange', onWake);
    };
  }, []);

  // Track the responsive roster inputs in the store so the toolbar, keyboard
  // map and saved-view fallbacks all agree on what "mobile" means.
  useEffect(() => {
    const mqNarrow = window.matchMedia(COMPACT_QUERY);
    const mqCoarse = window.matchMedia(COARSE_QUERY);
    const mqWide = window.matchMedia(PHONE_WIDE_QUERY);
    const sync = () => set({ viewportNarrow: mqNarrow.matches, coarsePointer: mqCoarse.matches, phoneWide: mqWide.matches });
    for (const mq of [mqNarrow, mqCoarse, mqWide]) mq.addEventListener('change', sync);
    sync();
    return () => {
      for (const mq of [mqNarrow, mqCoarse, mqWide]) mq.removeEventListener('change', sync);
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
    const hideCancelled = hidesCancelled(s.calendars);
    const out = [];
    for (const occ of state.occ.values()) {
      if (!visible.has(occ.calendarId)) continue;
      // A calendar set to hide what its source cancelled (#135).
      if (occ.status === 'cancelled' && hideCancelled.has(occ.calendarId)) continue;
      // The relationship quick filter: a kind switched off leaves every view.
      if (occ.relationship && showRel[occ.relationship] === false) continue;
      out.push(occ);
    }
    const flags = {};
    for (const c of s.calendars) flags[c.id] = !!c.groupSimilar;
    // The same event on two visible calendars draws once (#9).
    const calById = new Map(s.calendars.map((c) => [c.id, c]));
    return groupOccurrences(collapseDuplicates(out, calById), flags);
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
  // The month on screen, for the month-windowed fetches below (people's away
  // times, plugin bands and shading). Month and week views report it; day
  // view doesn't, so it falls back to the month of the day you're on.
  // Without that, opening straight into day view fetched neither, and a
  // tide's shading never appeared.
  const visMonthKey = useStore((st) => (st.view !== 'day' && st.visibleMonth
    ? st.visibleMonth.year * 100 + st.visibleMonth.month
    : (st.anchor ? Number(st.anchor.slice(0, 4)) * 100 + Number(st.anchor.slice(5, 7)) : 0)));
  const anyPersonVisible = s.calendars && state.people.some((p) => p.showOnCalendar);
  const availKeyRef = useRef(null);
  useEffect(() => {
    if (!anyPersonVisible) {
      if (state.availSpans.length > 0) set({ availSpans: [] });
      return;
    }
    // The same month the plugin bands use (visMonthKey): in day view, the day
    // you're on, which the view reports only when you scroll.
    const vm = visMonthKey
      ? { year: Math.floor(visMonthKey / 100), month: visMonthKey % 100 }
      : { year: Number(todayKey().slice(0, 4)), month: Number(todayKey().slice(5, 7)) };
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
    const days = bandDays(sp.start, sp.end);
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
      start: days.start,
      end: days.end,
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
  const calendarsForRanges = useStore((st) => st.calendars);
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

  // A range on one of a plugin's own calendars that's shorter than a day (a
  // tide's shading) is a window on the week and day timelines, drawn
  // strongest at its middle and fading to both edges; as an all-day band it
  // read as the whole day. Longer ranges, and plugin-wide ones (travel, trips),
  // stay bands. Every range takes its calendar's color as it is now: the
  // color stored with it is from the plugin's last run.
  useEffect(() => {
    setPluginDecoLookup((pid) => {
      const pl = (state.plugins || []).find((p) => p.id === pid);
      return (pl && pl.decoration) || null;
    });
  }, []);
  const isWindow = (r) => r.calendarId != null && parseISO(r.end) - parseISO(r.start) < 86400000;
  const pluginWindows = useMemo(() => {
    const out = [];
    const calById = new Map((calendarsForRanges || []).map((c) => [c.id, c]));
    for (const [pid, entry] of Object.entries(pluginRanges)) {
      if (pluginHidden && pluginHidden[pid]) continue;
      for (const r of entry.ranges || []) {
        if (!isWindow(r)) continue;
        const cal = calById.get(r.calendarId);
        if (!cal || cal.visible !== true) continue;
        out.push({ id: pid + ':' + r.id, startMs: parseISO(r.start).getTime(), endMs: parseISO(r.end).getTime(), color: cal.color || r.color });
      }
    }
    return out;
  }, [pluginRanges, pluginHidden, calendarsForRanges]); // eslint-disable-line

  const pluginOccs = useMemo(() => {
    const out = [];
    const decoBy = {};
    for (const pl of (state.plugins || [])) if (pl.decoration) decoBy[pl.id] = pl.decoration;
    // A band that belongs to one of a plugin's calendars hides with it.
    const calById = new Map((calendarsForRanges || []).map((c) => [c.id, c]));
    for (const [pid, entry] of Object.entries(pluginRanges)) {
      if (pluginHidden && pluginHidden[pid]) continue;
      for (const r of entry.ranges || []) {
        if (r.calendarId != null && (calById.get(r.calendarId) || {}).visible !== true) continue;
        if (isWindow(r)) continue;
        const days = bandDays(r.start, r.end);
        out.push({
          instanceId: 'plg:' + pid + ':' + r.id,
          eventId: null,
          calendarId: null,
          pluginId: pid,
          pluginColor: (r.calendarId != null && (calById.get(r.calendarId) || {}).color) || r.color || (decoBy[pid] && decoBy[pid].color) || null,
          pluginIcon: (decoBy[pid] && decoBy[pid].icon) || null,
          pluginIconPath: (decoBy[pid] && decoBy[pid].iconPath) || null,
          pluginAnim: (decoBy[pid] && decoBy[pid].animation) || null,
          detailHtml: r.detailHtml || null,
          rangeStart: r.start,
          rangeEnd: r.end,
          title: r.label,
          isContainer: true,
          allDay: true,
          start: days.start,
          end: days.end,
          attendance: 'none',
          status: 'confirmed',
          source: 'local',
        });
      }
    }
    return out;
  }, [pluginRanges, pluginHidden, calendarsForRanges]); // eslint-disable-line

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
    // Option/Alt: copy onto that calendar instead of moving there.
    if (at && at.copy) { copyEventTo(occ, calendarId, occ.recurring ? 'this' : 'all'); return; }
    if (occ.recurring && at) { promptScopeAt(at, (scope) => moveEventToCalendar(occ, calendarId, scope)); return; }
    moveEventToCalendar(occ, calendarId);
  }, [promptScopeAt]);

  const onDropToPerson = useCallback((occ, name, at) => {
    if (occ.recurring && at) { promptScopeAt(at, (scope) => linkPersonToEvent(occ, name, scope)); return; }
    linkPersonToEvent(occ, name);
  }, [promptScopeAt]);

  const onMoveEventW = useCallback((p) => {
    // Option/Alt held at the drop: a copy there, the original stays.
    if (p.at && p.at.copy) { copyEventAt(p); return; }
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
      // The drag reports an exclusive end; the span's last day is the day of
      // its end instant less a minute, so the end moves by the difference.
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
  // A move (Today, Next, a jump) to a day already on screen sends no new
  // report, so it counts as arrived now (actions.js hereKey).
  const scrollSeqNow = useStore((st) => st.scrollSeq);
  useEffect(() => {
    set((st) => {
      const d = st.visibleDays;
      return d && st.anchor >= d.start && st.anchor <= d.end && st.anchorSeen !== st.scrollSeq ? { anchorSeen: st.scrollSeq } : {};
    });
  }, [scrollSeqNow]);
  const onVisibleMonthChange = useCallback((vm, days) => {
    set((st) => {
      const out = {};
      if (!(st.visibleMonth && st.visibleMonth.year === vm.year && st.visibleMonth.month === vm.month)) out.visibleMonth = vm;
      if (days && !(st.visibleDays && st.visibleDays.start === days.start && st.visibleDays.end === days.end)) out.visibleDays = days;
      if (days && st.anchor >= days.start && st.anchor <= days.end && st.anchorSeen !== st.scrollSeq) out.anchorSeen = st.scrollSeq;
      return out;
    });
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
    // the day being browsed rather than each event's own start day. Without
    // one, a multi-day event takes the day it was pressed on (0.7.4), so
    // stepping from Monday's part of a Sunday-to-Tuesday event walks Monday.
    const dayKey = (opts && opts.dayKey) || pressedDay(state.occ.get(instanceId));
    // The full view (a double-click, a trip, the agenda's multi-day line) is
    // the side panel on a desktop and the sheet on a phone, the same as a
    // plain open; it never toggles closed.
    if (opts && opts.detail) {
      set({ popover: { instanceId, anchorRect, dayKey }, groupPopover: null, expandedDay: null });
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
  // Also called after Today, the arrows or a jump (TimeGrid), which set the
  // anchor themselves, so the month and the day on screen follow those too.
  const onVisibleDay = useCallback((dayKey) => {
    const [y, m] = dayKey.split('-').map(Number);
    set((st) => {
      const out = {};
      if (st.anchor !== dayKey) out.anchor = dayKey;
      if (!(st.visibleMonth && st.visibleMonth.year === y && st.visibleMonth.month === m)) out.visibleMonth = { year: y, month: m };
      if (!(st.visibleDays && st.visibleDays.start === dayKey && st.visibleDays.end === dayKey)) out.visibleDays = { start: dayKey, end: dayKey };
      if (st.anchorSeen !== st.scrollSeq) out.anchorSeen = st.scrollSeq;
      return out;
    });
  }, []);
  const onExpandDay = useCallback((dayKey) => {
    const cell = document.querySelector(`[data-day="${dayKey}"]`);
    set({ expandedDay: { dayKey, anchorRect: cell ? cell.getBoundingClientRect() : null } });
  }, []);
  // A tap or drag on empty calendar space opens a new event on its typing
  // box; readying the keyboard inside that gesture lets iOS raise it.
  const onCreateRange = useCallback(({ start, end, allDay }) => {
    primeKeyboard();
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

  // Pages (Settings, People, Review, ...) take a history entry, so Back
  // returns to the calendar instead of leaving the app (0.6.9). Moving from
  // one page to another reuses the entry.
  const route = s.route;
  // A page change takes a new version that's waiting (drafts.js), and looks
  // for one, so a page opened after a deploy is the new version's page.
  const firstRoute = useRef(true);
  useEffect(() => {
    if (firstRoute.current) { firstRoute.current = false; return; }
    reloadIfReady();
    lookForUpdate();
    // Back on the calendar from a page that may have changed it (a plugin's
    // settings, say): pick up what moved now.
    if (route === 'calendar') window.dispatchEvent(new Event('bc-check-changes'));
  }, [route]);
  useEffect(() => {
    if (!route || route === 'calendar') return undefined;
    let popped = false;
    let listening = false;
    const onPop = () => {
      // Back from something on top of this page (a Settings section, the
      // welcome) lands on one of this page's own entries: stay.
      if (history.state && history.state.bcRoute === state.route) return;
      popped = true;
      set({ route: 'calendar' });
    };
    // After any step back still on its way (the welcome closing as this
    // page opens), or this entry would be pushed onto one being left (#164).
    const cancel = afterHistorySettles(() => {
      // Already this page's entry (back from a reload, 0.9.1): keep it as it
      // is, with whatever a page within it (a Settings section) added.
      if (history.state && history.state.bcRoute === route) { /* adopt */ }
      else if (history.state && (history.state.bcRoute || history.state.bcDrawer)) history.replaceState({ bcRoute: route }, '');
      else history.pushState({ bcRoute: route }, '');
      window.addEventListener('popstate', onPop);
      listening = true;
    });
    return () => {
      cancel();
      if (!listening) return;
      window.removeEventListener('popstate', onPop);
      if (!popped && state.route === 'calendar' && history.state && history.state.bcRoute) stepBack(1);
    };
  }, [route]);

  if (s.serverState) return html`<${ServerNotice} kind=${s.serverState} />`;
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
  if (state.quickAddWindow) return html`<${QuickAddWindow} />`;
  // The pages (Settings, Review, People and the rest) keep the sidebar on a
  // computer: its Manage list goes straight from page to page, and its
  // mini-month back to the calendar. A phone shows the page on its own, as
  // before; the sidebar is a drawer there. Review keeps the phone's bottom
  // bar, since it is one of the bar's tabs.
  const PAGES = {
    organize: () => html`<${OrganizePage} />`,
    outfeeds: () => html`<${OutfeedsPage} />`,
    filters: () => html`<${FiltersPage} />`,
    views: () => html`<${SavedViewsPage} />`,
    settings: () => html`<${SettingsPage} />`,
    people: () => html`<${PeoplePage} />`,
    review: () => html`<${ReviewPage} />`,
    plugins: () => html`<${PluginsPage} />`,
    activity: () => html`<${ActivityPage} />`,
  };
  if (PAGES[s.route]) {
    const page = PAGES[s.route]();
    const overlays = html`${s.route === 'settings' && html`<${Welcome} />`}${s.route === 'review' && html`<${BottomBar} />`}<${CreateDrawer} /><${ShortcutsSheet} /><${MoveDialog} /><${CommandPalette} /><${AppBanners} /><${Toasts} /><${ConfirmHost} />`;
    if (s.viewportNarrow) {
      return html`<div class="bc-app"><${WorkerBanner} /><${AppReloadBanner} />${page}${overlays}</div>`;
    }
    return html`<div class="bc-app">
      <${WorkerBanner} /><${AppReloadBanner} />
      <${PageToolbar} onToggleSidebar=${toggleSidebar} />
      <div class="bc-main is-page">
        <${Sidebar} open=${false} collapsed=${sidebarCollapsed} onClose=${() => {}} />
        ${page}
      </div>
      ${overlays}
    </div>`;
  }

  let view = null;
  if (MONTH_ROWS[s.view]) {
    // The month slot: full month (7 columns) or the 3-day ribbon. Desktop is
    // always the full month unless the view area is too narrow (responsive
    // fallback); narrow viewports follow the mobile overviewMode setting.
    // A phone turned sideways (#125) has the width for five days a row and
    // the height for three rows, so the ribbon reshapes rather than leaving
    // three very wide days in rows too short to read.
    const ribbon = s.view === 'month' && effectiveOverviewMode() === '3day';
    const columns = ribbon ? (s.phoneWide ? 5 : 3) : 7;
    const ribbonRows = s.phoneWide ? 3 : 5;
    view = html`<${MonthGrid}
      key=${'grid' + columns}
      occurrences=${occurrencesWithAvail}
      hiddenDays=${hiddenDays} onShowHidden=${showAllRel}
      calendars=${calMeta}
      columns=${columns}
      visibleRows=${ribbon ? ribbonRows : MONTH_ROWS[s.view]}
      minRows=${ribbon ? ribbonRows : MONTH_MIN_ROWS[s.view] || MONTH_ROWS[s.view]}
      scrollKey=${s.anchor}
      scrollSeq=${s.scrollSeq}
      glide=${s.glideSeq === s.scrollSeq}
      anchorAtTop=${s.gridAnchor === 'top'}
      fitRows=${s.view === 'month' && !ribbon && s.monthRows === 'fit'}
      onToggleFit=${s.view === 'month' && !ribbon ? toggleFitRows : null}
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
  } else if (s.view === 'split') {
    // Split: the weeks over a continuous list (ui/SplitView.js). A tap on a
    // day in the weeks brings the list to it; the list is where you read.
    const toDay = (k) => set({ anchor: k, scrollSeq: state.scrollSeq + 1 });
    view = html`<${SplitView}
      key="split"
      scrollSeq=${s.scrollSeq}
      gridProps=${{
        occurrences: occurrencesWithAvail,
        hiddenDays, onShowHidden: showAllRel,
        calendars: calMeta,
        columns: 7, visibleRows: 2, minRows: 1,
        scrollKey: s.anchor, scrollSeq: s.scrollSeq,
        dimSet, nowMs,
        onRequestWindow,
        onOpenEvent,
        onExpandDay: toDay,
        onOpenDay: toDay,
        onCreateRange,
        onMoveEvent: onMoveEventW,
        onResizeEvent: onResizeEventW,
        onMoveSpan, onMoveTrip,
        onDropToCalendar, onDropToPerson, onDropToTrip,
      }}
      listProps=${{
        occurrences,
        hiddenDays, onShowHidden: showAllRel,
        calendars: calMeta,
        dimSet, nowMs,
        scrollKey: s.anchor, scrollSeq: s.scrollSeq,
        onVisibleMonthChange,
        onOpenEvent,
        onExpandDay,
        onSetAttendance: (occ, v) => setRelationship(occ, occ.relationship === v ? 'available' : v),
        onFeedback: sendFeedback,
        onCreateDay: (k) => onCreateRange(dayRangeDraft(k, k)),
        emptyLabel: 'No events',
        loaded: state.loadedRanges,
      }}
    />`;
  } else if (s.view === 'week') {
    // Week is a horizontally infinite day track: no remount on navigation,
    // the anchor scrolls into place via scrollSeq.
    view = html`<${TimeGrid}
      key="week"
      infinite=${true}
      occurrences=${occurrencesWithAvail}
      windows=${pluginWindows}
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
      onExpandDay=${onExpandDay}
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
      occurrences=${occurrencesWithAvail}
      windows=${pluginWindows}
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
        onExpandDay=${onExpandDay}
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
    // A day's list holds what its cell counts, people's away times and plugin
    // bands included: month's "+N more" counted them, and the list it opened
    // didn't show them (#183).
    const dayOccs = occurrencesWithAvail.filter((occ) => {
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
      onNew=${() => {
        // A new event on this day: today starts at the next quarter hour
        // (the editor's own default), any other day at 9 AM.
        const k = s.expandedDay.dayKey;
        set({ expandedDay: null, editor: { mode: 'create', draft: k === todayKey() ? {} : dayRangeDraft(k, k) } });
      }}
      onClose=${() => set({ expandedDay: null })}
    />`;
  }

  const reschedActive = !!(resched && reschedOcc);
  const ws = s.windowStatus;
  const windowProblem = ws && (ws.kind === 'failed' || ws.kind === 'limited');
  const windowNote = ws && html`<div class=${'bc-winstatus' + (windowProblem ? ' is-failed' : '')} role=${windowProblem ? 'alert' : 'status'}>
    ${ws.kind === 'loading'
      ? 'Loading events…'
      : ws.kind === 'limited'
        ? html`<span>${ws.message}</span>`
        : html`<span>${ws.offline ? 'Offline, and these dates are not saved on this device.' : 'Could not load events.'} Retrying…</span>
        <button type="button" class="bc-link-btn" onClick=${retryWindowsNow}>Retry now</button>`}
  </div>`;

  // The desktop event panel (EventPopover): the calendar makes room for it,
  // and the sidebar tucks away while it is open unless the setting says not.
  const evPanel = popoverOpen && !window.matchMedia(PHONE_QUERY).matches;
  const tuckSidebar = evPanel && tuckSetting !== false;
  return html`<div class="bc-app${dimSet ? ' is-page-filtering' : ''}">
    <${Toolbar} onToggleSidebar=${toggleSidebar} />
    <${WorkerBanner} /><${AppReloadBanner} />
    ${reschedActive && html`<${RescheduleBanner} occ=${reschedOcc} onExit=${exitReschedule} />`}
    <div class=${'bc-main' + (evPanel ? ' has-evpanel' : '')}>
      ${sidebarOpen && html`<div
        class="bc-sidebar-scrim"
        aria-hidden="true"
        onPointerDown=${(e) => { consumeOutsidePress(e); setSidebarOpen(false); }}
      ></div>`}
      <${Sidebar} open=${sidebarOpen} collapsed=${sidebarCollapsed || tuckSidebar} onClose=${() => setSidebarOpen(false)} />
      <main class="bc-view" ref=${viewAreaRef}>${windowNote}${view}</main>
      ${reschedActive && html`<${RescheduleStrip}
        year=${stripBase.year} month=${stripBase.month}
        currentYear=${s.visibleMonth ? s.visibleMonth.year : 0}
        currentMonth=${s.visibleMonth ? s.visibleMonth.month : 0}
        onJumpMonth=${onJumpMonth}
      />`}
    </div>
    ${expand}
    <${QuickAdd} />
    <${Welcome} />
    <${EventPopover} />
    <${GroupPopover} />
    <${EditorDrawer} />
    <${CreateDrawer} />
    <${SearchOverlay} />
    <${ShortcutsSheet} /><${MoveDialog} /><${CommandPalette} />
    ${s.dropChoice && html`<${DropChoiceChip} choice=${s.dropChoice} />`}
    ${s.pluginCard && html`<${PluginRangeCard} card=${s.pluginCard} />`}
    ${reschedActive && html`<${RescheduleOverlay}
      occ=${reschedOcc}
      cal=${calMeta[reschedOcc.calendarId]}
      onDropConfirmed=${onReschedMove}
      onExit=${exitReschedule}
    />`}
    <${BottomBar} />
    <${AppBanners} /><${Toasts} /><${ConfirmHost} />
  </div>`;
}

// A tiny decision chip pinned at a drop point (trip move: with or without its
// events). Escape or any outside press dismisses without acting.
function DropChoiceChip({ choice }) {
  const rootRef = useRef(null);
  // Kept fully on screen by its real size (it wraps on phones), not a guess.
  useLayoutEffect(() => {
    const el = rootRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    if (r.right > window.innerWidth - 8) el.style.left = Math.max(8, window.innerWidth - 8 - r.width) + 'px';
    if (r.bottom > window.innerHeight - 8) el.style.top = Math.max(8, window.innerHeight - 8 - r.height) + 'px';
  });
  useEffect(() => {
    const dismiss = () => set({ dropChoice: null });
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); dismiss(); } };
    const stop = onOutsidePress(insideAny(rootRef), dismiss);
    document.addEventListener('keydown', onKey, true);
    return () => {
      stop();
      document.removeEventListener('keydown', onKey, true);
    };
  }, []);
  // Clamped so a drop near the viewport edge keeps the chip fully on-screen.
  // Phones: edge to edge with a margin (CSS), at the drop's height.
  const x = window.matchMedia(PHONE_QUERY).matches ? 8 : Math.max(8, Math.min(choice.x, window.innerWidth - 300));
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
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); dismiss(); } };
    const stop = onOutsidePress(insideAny(rootRef), dismiss);
    document.addEventListener('keydown', onKey, true);
    return () => {
      stop();
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
      <${CalDot} color=${occ.pluginColor || null} deco=${occ.pluginIcon || occ.pluginIconPath ? { icon: occ.pluginIcon, iconPath: occ.pluginIconPath } : null} />
      <span class="bc-plugincard-title">${occ.title}</span>
      <span class="bc-plugincard-plugin">${occ.pluginId}</span>
    </div>
    <div class="bc-plugincard-when">
      ${fmtD(spanStart)} ${fmtT(spanStart)}${sameDay ? ' – ' + fmtT(spanEnd) : ' – ' + fmtD(spanEnd) + ' ' + fmtT(spanEnd)}
    </div>
    ${occ.detailHtml && html`<div class="bc-plugincard-body bc-rich" dangerouslySetInnerHTML=${{ __html: sanitizeHtml(occ.detailHtml) }}></div>`}
  </div>`;
}

// The server answered 503 for an update: "updating" while bin/update.php (or
// the database update page) works, which clears on its own; or database
// changes waiting after new files were uploaded by hand, which the owner
// applies on /update-database (the service worker lets that page through).
function ServerNotice({ kind }) {
  useEffect(() => {
    if (kind !== 'maintenance') return undefined;
    const t = setInterval(async () => {
      try {
        const res = await fetch('/api/v1/health', { cache: 'no-store' });
        if (res.ok) location.reload();
      } catch { /* still updating, or offline */ }
    }, 15000);
    return () => clearInterval(t);
  }, [kind]);
  return html`<div class="bc-login-wrap"><div class="bc-login bc-server-notice" role="status">
    <h1 class="bc-login-brand">${kind === 'maintenance' ? 'Updating' : 'Database update needed'}</h1>
    ${kind === 'maintenance'
      ? html`<p>Better-Cal is being updated. It will be back in a minute; this page reloads on its own.</p>`
      : html`<p>Better-Cal\u2019s files were updated, and its database needs a matching update before it can open.</p>
        <a class="bc-btn bc-btn-primary" href="/update-database">Update the database</a>`}
  </div></div>`;
}
