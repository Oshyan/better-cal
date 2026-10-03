// Trip UI (container events, docs/design-containers.md):
// - TripSheetBody: what the side panel (desktop) or the sheet (phone) shows
//   for a trip (0.7.0). Span, place, a map of its places, its events
//   (GET /events/:id/links), adding and creating events, and the two-option
//   delete confirm (remove trip only vs delete trip and its events).
// - TripRow: the "Part of" trip-membership row in a NON-container event's
//   EDITOR (the read-only detail view shows membership as the "Part of:"
//   chip under the title instead): shows the current trip or None, offers
//   overlapping/abutting trips, and an "Other trip" title search across
//   every loaded container.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, state, insertOccurrence, toast } from './store.js';
import { api, loadWindow } from './api.js';
import { Skeleton } from './PageShell.js';
import {
  openDetail, attachToTrip, detachFromTrip, deleteTripOnly, deleteTripAndMembers,
} from './actions.js';
import { CalDot, TripBadge, LinkIcon, Icon, PinIcon } from '../ui/icons.js';
import { onOutsidePress, insideAny } from '../ui/outside.js';
import { loadLeaflet, addBaseTiles, linkify } from './EventDetail.js';
import { DurationSuffix } from '../ui/EventChip.js';
import { tripSpan, tripSpanLabel, candidateTrips, attachableInSpan } from '../ui/trips.js';
import {
  parseISO, dateOfDayKey, addDaysDate, toISOWithOffset, occDayKey, fmtTime,
} from '../lib/dates.js';
import { dayRangeDraft, dayRangeLabel } from '../lib/quickcreate.js';
import { gmapsUrl } from '../lib/maps.js';

// "Jun 3 · 9:00 AM" / "Jun 3 · all day" for member and picker rows.
function fmtWhen(occ) {
  const day = dateOfDayKey(occDayKey(occ)).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  return occ.allDay ? day + ' · all day' : day + ' · ' + fmtTime(parseISO(occ.start));
}

// --- trip view (0.7.0) ------------------------------------------------------
// A trip opens where an event does: the side panel on a desktop, the sheet on
// a phone, with the same toolbar, Back and stepping. What it holds leads with
// the trip itself: its dates and length, where it is, a map of its places,
// then its events as proper rows (when, what, where). Opening one of them
// keeps a way back to the trip. Adding and creating events sit in the
// toolbar or More; removing an event from the trip is in that event's row,
// behind a confirmation, rather than a bare x beside it.

// Every trip event with a place, numbered in list order; the trip's own place
// when none of them has one.
function tripPoints(occ, members) {
  const pts = [];
  members.forEach((m, i) => {
    if (m.locationLat != null && m.locationLng != null) pts.push({ lat: Number(m.locationLat), lng: Number(m.locationLng), n: i + 1, title: m.title || '' });
  });
  if (pts.length === 0 && occ.locationLat != null && occ.locationLng != null) {
    pts.push({ lat: Number(occ.locationLat), lng: Number(occ.locationLng), n: null, title: occ.location || '' });
  }
  return pts;
}

function TripMap({ points }) {
  const elRef = useRef(null);
  const key = points.map((p) => p.lat.toFixed(4) + ',' + p.lng.toFixed(4)).join('|');
  useEffect(() => {
    let map = null;
    let disposed = false;
    loadLeaflet().then((L) => {
      if (disposed || !elRef.current) return;
      const touch = state.coarsePointer;
      map = L.map(elRef.current, {
        zoomControl: !touch, scrollWheelZoom: false, dragging: !touch, touchZoom: !touch,
        doubleClickZoom: !touch, boxZoom: false, keyboard: false, attributionControl: true,
      });
      addBaseTiles(L, map, state.darkMode);
      const latlngs = [];
      for (const p of points) {
        const icon = L.divIcon({
          className: 'bc-tr-pin', html: '<span>' + (p.n != null ? p.n : '') + '</span>',
          iconSize: [24, 24], iconAnchor: [12, 12],
        });
        L.marker([p.lat, p.lng], { icon, title: p.title }).addTo(map);
        latlngs.push([p.lat, p.lng]);
      }
      if (latlngs.length === 1) map.setView(latlngs[0], 13);
      else map.fitBounds(latlngs, { padding: [28, 28], maxZoom: 13 });
    }).catch(() => { /* no map is a fine map */ });
    return () => { disposed = true; if (map) map.remove(); };
  }, [key, state.darkMode]); // eslint-disable-line
  return html`<div class="bc-map-wrap bc-tr-map"><div class="bc-tr-mapel" ref=${elRef} role="img" aria-label="Map of this trip's places"></div></div>`;
}

export function TripSheetBody({ occ, cal, panel = false, full = true, bodyRef }) {
  const tripLinksSeq = useStore((s) => s.tripLinksSeq);
  useStore((s) => s.occVersion); // picker candidates fill in as windows load
  const [links, setLinks] = useState({ status: 'loading', events: [] });
  const [adding, setAdding] = useState(false);
  const [selected, setSelected] = useState(() => new Set());
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);
  const [rowAsk, setRowAsk] = useState(null); // eventId whose "remove from trip" is being confirmed
  const [retrySeq, setRetrySeq] = useState(0);
  const [pickSuggested, setPickSuggested] = useState(false);
  useEffect(() => { setAdding(false); setConfirmDelete(false); setMoreOpen(false); setRowAsk(null); setPickSuggested(false); }, [occ.eventId]);

  // The trip's events: deliberately not windowed (the whole list whatever
  // range is loaded), fetched again after every change to the links.
  useEffect(() => {
    let alive = true;
    setLinks((l) => ({ status: 'loading', events: l.events }));
    api('/events/' + occ.eventId + '/links')
      .then((d) => { if (alive) setLinks({ status: 'ok', events: d.events || [] }); })
      .catch(() => { if (alive) setLinks({ status: 'error', events: [] }); });
    return () => { alive = false; };
  }, [occ.eventId, tripLinksSeq, retrySeq]);

  const span = tripSpan(occ);
  useEffect(() => {
    if (!adding) return;
    loadWindow(toISOWithOffset(dateOfDayKey(span.startKey)), toISOWithOffset(addDaysDate(dateOfDayKey(span.endKey), 1)));
  }, [adding, occ.eventId]); // eslint-disable-line

  const color = (cal && cal.color) || '#888';
  const close = () => set({ popover: null });
  const members = links.events;
  const memberIds = new Set(members.map((m) => m.eventId));
  // What could join the trip: events during it, not already in it, on a
  // calendar you show, never context (weather, sunset). Your planned and
  // maybe events first; feed suggestions only when asked for, since a trip
  // through a busy city can have hundreds.
  const shownCals = new Set(state.calendars.filter((c) => c.visible).map((c) => c.id));
  const pickAll = adding
    ? attachableInSpan(occ, [...state.occ.values()]).filter((o) =>
        !memberIds.has(o.eventId) && !(o.containers || []).some((c) => c.eventId === occ.eventId)
        && o.relationship !== 'context' && shownCals.has(o.calendarId))
    : [];
  const pickMine = pickAll.filter((o) => o.relationship === 'planned' || o.relationship === 'maybe');
  const pickList = pickSuggested ? pickAll : pickMine;
  const points = links.status === 'ok' ? tripPoints(occ, members) : [];

  const openMember = (m) => {
    if (!state.occ.get(m.instanceId)) insertOccurrence(m);
    set({ popover: { instanceId: m.instanceId, anchorRect: null, dayKey: occDayKey(m), backTo: { instanceId: occ.instanceId, title: occ.title || 'Trip' } } });
  };
  const toggleSelected = (eventId) => setSelected((prev) => {
    const next = new Set(prev);
    if (next.has(eventId)) next.delete(eventId); else next.add(eventId);
    return next;
  });
  const attachSelected = async () => {
    const chosen = pickList.filter((o) => selected.has(o.eventId));
    for (const o of chosen) await attachToTrip(occ, o, { silent: true });
    if (chosen.length > 0) toast(chosen.length + (chosen.length === 1 ? ' event added to ' : ' events added to ') + (occ.title || 'trip'), { undoable: true });
    setSelected(new Set());
    setAdding(false);
  };
  const newInTrip = () => set({ popover: null, editor: { mode: 'create', draft: dayRangeDraft(span.startKey, span.startKey), attachTrip: occ } });
  const openEdit = () => set({ popover: null, editor: { mode: 'edit', occ } });
  const startAdding = () => { setAdding(true); setSelected(new Set()); };

  const tool = (icon, label, onClick, extra = {}) => html`<button
    type="button" class=${'bc-es-tool' + (extra.word ? '' : ' is-icon') + (extra.on ? ' is-on' : '')}
    aria-label=${label} title=${extra.word ? undefined : label} aria-expanded=${extra.expanded} aria-haspopup=${extra.popup}
    onClick=${onClick}
  >${icon}${extra.word ? label : ''}</button>`;

  const item = (icon, label, fn, cls = '') => html`<button type="button" role="menuitem" class=${'bc-es-mi ' + cls} onClick=${() => { setMoreOpen(false); fn(); }}>
    <${Icon} name=${icon} size=${18} /><span>${label}</span>
  </button>`;
  const calName = (cal && cal.name) || 'Calendar';
  const menu = moreOpen && html`<${TripMenu} onClose=${() => setMoreOpen(false)} phone=${!panel}>
    ${!panel && item('plus', 'Add events to this trip', startAdding)}
    ${item('calendar', 'New event in this trip', newInTrip)}
    ${cal && html`<hr /><div class="bc-es-mh" role="presentation"><i style=${'background:' + color}></i>Calendar: ${calName}</div>
      ${item('settings', 'Calendar settings', () => set({ popover: null, manageCal: cal.id }))}`}
    <hr />
    ${item('trash', 'Delete trip…', () => setConfirmDelete(true), 'is-danger')}
  <//>`;

  const tools = panel && html`<div class="bc-es-tools" role="toolbar" aria-label="Trip actions">
      <span class="bc-es-tools-acts">
        ${tool(html`<${Icon} name="pencil" size=${15} />`, 'Edit', openEdit, { word: true })}
        ${tool(html`<${Icon} name="plus" size=${15} />`, 'Add events', startAdding, { word: true, on: adding })}
        ${tool(html`<${Icon} name="more" size=${16} />`, 'More', () => setMoreOpen(!moreOpen), { on: moreOpen, expanded: moreOpen, popup: 'menu' })}
      </span>
      ${menu}
    </div>`;
  const titleActs = !panel && html`<span class="bc-es-titleacts" role="toolbar" aria-label="Trip actions">
      ${tool(html`<${Icon} name="pencil" size=${18} />`, 'Edit', openEdit)}
      ${tool(html`<${Icon} name="more" size=${19} />`, 'More', () => setMoreOpen(!moreOpen), { on: moreOpen, expanded: moreOpen, popup: 'menu' })}
    </span>`;

  return html`<div class="bc-pop-body bc-es-body bc-tr-body" ref=${bodyRef}>
    ${tools}
    ${confirmDelete && html`<div class="bc-trip-confirm">
      <span>Delete this trip?</span>
      <div class="bc-trip-confirm-row">
        <button type="button" class="bc-btn" onClick=${() => { close(); deleteTripOnly(occ); }}>Remove trip only</button>
        <button type="button" class="bc-btn bc-btn-danger" disabled=${links.status !== 'ok'}
          onClick=${() => { close(); deleteTripAndMembers(occ, members); }}
        >Delete trip and its ${members.length} ${members.length === 1 ? 'event' : 'events'}</button>
        <button type="button" class="bc-btn" onClick=${() => setConfirmDelete(false)}>Cancel</button>
      </div>
      <span class="bc-trip-confirm-note">Remove trip only keeps the events on their calendars</span>
    </div>`}

    <div class="bc-es-head">
      <div class="bc-es-titlerow">
        <h2 class="bc-es-title">${occ.title || '(untitled)'}</h2>
        ${titleActs}
      </div>
      <div class="bc-es-calrow">
        <span class="bc-es-cal"><${TripBadge} /><i style=${'background:' + color}></i>${calName}</span>
      </div>
    </div>
    ${!panel && menu}

    <div class="bc-es-row">
      <${Icon} name="clock" size=${20} />
      <div class="bc-es-rt"><span class="bc-date-duration">${dayRangeLabel(span.startKey, span.endKey)} <${DurationSuffix} occ=${occ} expanded /></span></div>
    </div>
    ${occ.location && html`<div class="bc-es-row">
      <${PinIcon} size=${20} /><div class="bc-es-rt">${occ.location}</div>
      <a class="bc-es-rowbtn" href=${gmapsUrl(occ.location, occ.locationLat, occ.locationLng)} target="_blank" rel="noopener noreferrer">Directions <${Icon} name="arrowUpRight" size=${14} /></a>
    </div>`}

    <div class="bc-tr-section">
      <div class="bc-tr-label">Events${links.status === 'ok' && members.length > 0 ? ' (' + members.length + ')' : ''}</div>
      ${links.status === 'loading' && members.length === 0 && html`<${Skeleton} rows=${3} compact=${true} />`}
      ${links.status === 'error' && html`<div class="bc-trip-loading">Could not load this trip's events
        <button type="button" class="bc-link-btn" onClick=${() => setRetrySeq(retrySeq + 1)}>Retry</button></div>`}
      ${links.status === 'ok' && members.length === 0 && html`<div class="bc-trip-empty">No events in this trip yet</div>`}
      ${members.length > 0 && html`<div class="bc-tr-list">
        ${members.map((m, i) => {
          const mcal = state.calendars.find((c) => c.id === m.calendarId);
          const asking = rowAsk === m.eventId;
          const pinned = m.locationLat != null && m.locationLng != null;
          return html`<div key=${m.eventId} class=${'bc-tr-row' + (asking ? ' is-asking' : '')}>
            <button type="button" class="bc-tr-main" onClick=${() => openMember(m)} title=${'Open ' + (m.title || 'event')}>
              <span class="bc-tr-when">${fmtWhen(m)}</span>
              <span class="bc-tr-what">
                <span class="bc-tr-title"><${CalDot} cal=${mcal} color=${(mcal && mcal.color) || '#888'} />${m.title || '(untitled)'}</span>
                ${m.location && html`<span class="bc-tr-sub">${pinned && points.length > 1 && (panel || full) ? html`<b class="bc-tr-n">${i + 1}</b>` : ''}${m.location}</span>`}
              </span>
            </button>
            ${asking
              ? html`<span class="bc-tr-ask">
                  <button type="button" class="bc-btn bc-btn-danger" onClick=${() => { setRowAsk(null); detachFromTrip(occ.eventId, m); }}>Remove from trip</button>
                  <button type="button" class="bc-btn" onClick=${() => setRowAsk(null)}>Keep</button>
                </span>`
              : html`<button type="button" class="bc-icon-btn bc-tr-rowmore" title="Remove from this trip…" aria-label=${'Remove ' + (m.title || 'event') + ' from this trip'}
                  onClick=${() => setRowAsk(m.eventId)}><${Icon} name="more" size=${16} /></button>`}
          </div>`;
        })}
      </div>`}
      ${!adding && !panel && html`<div class="bc-tr-addrow"><button type="button" class="bc-btn" onClick=${startAdding}><${Icon} name="plus" size=${14} />Add events</button></div>`}
      ${adding && html`<div class="bc-trip-picker">
        <div class="bc-tr-label">Add to this trip</div>
        ${pickList.length === 0 && html`<div class="bc-trip-empty">${pickSuggested ? 'No events during this trip to add' : 'None of your events during this trip are outside it'}</div>`}
        ${pickList.length > 0 && html`<div class="bc-trip-pick-list">
          ${pickList.map((o) => {
            const ocal = state.calendars.find((c) => c.id === o.calendarId);
            return html`<label key=${o.eventId} class="bc-trip-pick-row">
              <input type="checkbox" checked=${selected.has(o.eventId)} onChange=${() => toggleSelected(o.eventId)} />
              <span class="bc-trip-pick-when">${fmtWhen(o)}</span>
              <${CalDot} cal=${ocal} color=${(ocal && ocal.color) || '#888'} />
              <span class="bc-trip-pick-title">${o.title || '(untitled)'}</span>
            </label>`;
          })}
        </div>`}
        ${!pickSuggested && pickAll.length > pickMine.length && html`<button type="button" class="bc-link-btn bc-tr-picksug" onClick=${() => setPickSuggested(true)}>Also show suggestions from feeds (${pickAll.length - pickMine.length})</button>`}
        <div class="bc-trip-pick-actions">
          ${pickList.length > 0 && html`<button type="button" class="bc-btn bc-btn-primary" disabled=${selected.size === 0} onClick=${attachSelected}>Add${selected.size > 0 ? ' ' + selected.size : ''}</button>`}
          <button type="button" class="bc-btn" onClick=${() => { setAdding(false); setSelected(new Set()); }}>Cancel</button>
        </div>
      </div>`}
    </div>

    ${/* Events first, then where they are: on a phone only once the sheet is pulled up, as with an event's map. */ ''}
    ${points.length > 0 && (panel || full) && html`<${TripMap} points=${points} />`}
    ${occ.description && html`<div class="bc-es-desc"><div class="bc-es-desctext">${linkify(String(occ.description).replace(/<[^>]+>/g, ' '))}</div></div>`}
  </div>`;
}

// The trip's More menu, closed by a press anywhere else; on a phone it opens
// from the bottom edge, like the event sheet's.
function TripMenu({ onClose, phone, children }) {
  const ref = useRef(null);
  useEffect(() => onOutsidePress(insideAny(ref), onClose), []); // eslint-disable-line
  return html`<div class=${'bc-es-menu' + (phone ? ' is-phone' : '')} role="menu" ref=${ref}>${children}</div>`;
}

// --- trip row on the event side ---------------------------------------------

// props: occ (a non-container occurrence, local or feed).
export function TripRow({ occ: occProp }) {
  useStore((s) => s.occVersion); // candidates and containers live in the cache
  const [other, setOther] = useState(false);
  const [query, setQuery] = useState('');

  // The editor holds the occurrence it opened with; after an attach the
  // refreshed cache carries the new containers while the prop still says
  // none, which blanked the select right after picking a trip. Read live.
  const occ = state.occ.get(occProp.instanceId) || occProp;

  const current = occ.containers && occ.containers.length > 0 ? occ.containers[0] : null;
  const all = [...state.occ.values()];
  const candidates = candidateTrips(occ, all);
  // The current trip is always offered, even when its span no longer
  // overlaps (so the select's value resolves and switching away works).
  if (current && !candidates.some((c) => c.eventId === current.eventId)) {
    const cur = all.find((o) => o.isContainer && o.eventId === current.eventId);
    if (cur) candidates.unshift(cur);
  }

  const pick = async (tripOcc) => {
    if (current && current.eventId === tripOcc.eventId) return;
    // One trip per event in v1: switching detaches the old link first.
    if (current) await detachFromTrip(current.eventId, occ, { silent: true });
    await attachToTrip(tripOcc, occ);
    setOther(false);
    setQuery('');
  };

  const clear = async () => {
    if (current) await detachFromTrip(current.eventId, occ);
  };

  const onSelect = (e) => {
    const v = e.target.value;
    if (v === '') { clear(); return; }
    if (v === 'other') { setOther(true); return; }
    const target = candidates.find((c) => String(c.eventId) === v);
    if (target) pick(target);
  };

  // "Other trip": title search across every loaded container.
  const needle = query.trim().toLowerCase();
  const searchResults = other
    ? candidateTrips(occ, all, Infinity)
        .filter((o) => !needle || (o.title || '').toLowerCase().includes(needle))
        .slice(0, 12)
    : [];

  return html`<div class="bc-trip-rowsect">
    <div class="bc-trip-rowline">
      <span class="bc-trip-rowlabel">Part of</span>
      ${current && html`<button
        type="button" class="bc-partof" title="Open this trip"
        onClick=${() => {
          const cur = all.find((o) => o.isContainer && o.eventId === current.eventId);
          if (cur) openDetail(cur.instanceId);
        }}
      ><${LinkIcon} size=${12} /> ${current.title}</button>`}
      <select class="bc-trip-select" aria-label="Trip" value=${current ? String(current.eventId) : ''} onChange=${onSelect}>
        <option value="">None</option>
        ${candidates.map((c) => html`<option key=${c.eventId} value=${String(c.eventId)}>${c.title || '(untitled)'}</option>`)}
        <option value="other">Other trip...</option>
      </select>
    </div>
    ${other && html`<div class="bc-trip-search">
      <input
        placeholder="Search trips by title"
        value=${query}
        onInput=${(e) => setQuery(e.target.value)}
        onKeyDown=${(e) => { if (e.key === 'Enter') e.preventDefault(); }}
        aria-label="Search trips by title"
        autofocus
      />
      <div class="bc-trip-search-list">
        ${searchResults.length === 0 && html`<span class="bc-trip-empty">No matching trips loaded</span>`}
        ${searchResults.map((o) => html`<button key=${o.instanceId} type="button" class="bc-trip-search-row" onClick=${() => pick(o)}>
          <span class="bc-trip-pick-when">${tripSpanLabel(o)}</span>
          <span class="bc-trip-pick-title">${o.title || '(untitled)'}</span>
        </button>`)}
      </div>
      <button type="button" class="bc-link-btn" onClick=${() => { setOther(false); setQuery(''); }}>Cancel</button>
    </div>`}
  </div>`;
}
