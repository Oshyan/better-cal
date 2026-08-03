// Trip UI (container events, docs/design-containers.md):
// - TripDetail: the detail modal for isContainer occurrences. Span line,
//   chronological member list (GET /events/:id/links), inline "Add events"
//   picker, "New event in this trip" shortcut, and the two-option delete
//   confirm (remove trip only vs delete trip and its events).
// - TripRow: the "Part of" trip-membership row in a NON-container event's
//   EDITOR (the read-only detail view shows membership as the "Part of:"
//   chip under the title instead): shows the current trip or None, offers
//   overlapping/abutting trips, and an "Other trip" title search across
//   every loaded container.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, state, insertOccurrence, toast } from './store.js';
import { api, loadWindow } from './api.js';
import {
  openDetail, attachToTrip, detachFromTrip, deleteTripOnly, deleteTripAndMembers,
} from './actions.js';
import { trapFocus } from '../ui/DayExpand.js';
import { CalDot, TripBadge, LinkIcon } from '../ui/icons.js';
import { tripSpan, tripSpanLabel, candidateTrips, attachableInSpan } from '../ui/trips.js';
import {
  parseISO, dateOfDayKey, addDaysDate, toISOWithOffset, occDayKey, fmtTime,
} from '../lib/dates.js';
import { dayRangeDraft } from '../lib/quickcreate.js';
import { gmapsUrl } from '../lib/maps.js';

// "Jun 3 · 9:00 AM" / "Jun 3 · all day" for member and picker rows.
function fmtWhen(occ) {
  const day = dateOfDayKey(occDayKey(occ)).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  return occ.allDay ? day + ' · all day' : day + ' · ' + fmtTime(parseISO(occ.start));
}

// --- trip detail -------------------------------------------------------------

export function TripDetail({ occ }) {
  const tripLinksSeq = useStore((s) => s.tripLinksSeq);
  useStore((s) => s.occVersion); // picker candidates fill in as windows load
  const panelRef = useRef(null);
  const [links, setLinks] = useState({ status: 'loading', events: [] });
  const [adding, setAdding] = useState(false);
  const [selected, setSelected] = useState(() => new Set());
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [retrySeq, setRetrySeq] = useState(0);

  // Focus trap while open; Esc is handled by the global keyboard map.
  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Tab') trapFocus(panelRef.current, e); };
    document.addEventListener('keydown', onKey, true);
    return () => document.removeEventListener('keydown', onKey, true);
  }, []);

  // Member list: deliberately non-windowed (the full list regardless of the
  // visible range), refetched after every link change.
  useEffect(() => {
    let alive = true;
    setLinks((l) => ({ status: 'loading', events: l.events }));
    api('/events/' + occ.eventId + '/links')
      .then((d) => { if (alive) setLinks({ status: 'ok', events: d.events || [] }); })
      .catch(() => { if (alive) setLinks({ status: 'error', events: [] }); });
    return () => { alive = false; };
  }, [occ.eventId, tripLinksSeq, retrySeq]);

  const span = tripSpan(occ);

  // Opening the picker demands the trip's span window so every candidate is
  // in the cache (the picker list also draws from already-loaded windows).
  useEffect(() => {
    if (!adding) return;
    loadWindow(
      toISOWithOffset(dateOfDayKey(span.startKey)),
      toISOWithOffset(addDaysDate(dateOfDayKey(span.endKey), 1)),
    );
  }, [adding, occ.eventId]); // eslint-disable-line

  const cal = state.calendars.find((c) => c.id === occ.calendarId);
  const color = (cal && cal.color) || '#888';
  const close = () => set({ detail: null });

  const members = links.events;
  const memberIds = new Set(members.map((m) => m.eventId));
  // Already-attached events never show in the picker: by the fetched member
  // list, and by the occurrence's own containers while that list still loads.
  const pickList = adding
    ? attachableInSpan(occ, [...state.occ.values()]).filter((o) =>
        !memberIds.has(o.eventId) &&
        !(o.containers || []).some((c) => c.eventId === occ.eventId))
    : [];

  const openMember = (m) => {
    if (!state.occ.get(m.instanceId)) insertOccurrence(m);
    openDetail(m.instanceId);
  };

  const toggleSelected = (eventId) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(eventId)) next.delete(eventId);
      else next.add(eventId);
      return next;
    });
  };

  const attachSelected = async () => {
    const chosen = pickList.filter((o) => selected.has(o.eventId));
    for (const o of chosen) await attachToTrip(occ, o, { silent: true });
    if (chosen.length > 0) {
      toast(chosen.length + (chosen.length === 1 ? ' event added to ' : ' events added to ') + (occ.title || 'trip'), { undoable: true });
    }
    setSelected(new Set());
    setAdding(false);
  };

  const newInTrip = () => {
    set({
      editor: {
        mode: 'create',
        draft: dayRangeDraft(span.startKey, span.startKey),
        attachTrip: occ,
      },
    });
  };

  return html`<div class="bc-overlay bc-detail-overlay" onClick=${(ev) => { if (ev.target === ev.currentTarget) close(); }}>
    <div class="bc-detail" ref=${panelRef} role="dialog" aria-modal="true" aria-label="Trip detail">
      <div class="bc-detail-head" style=${`border-top: 4px solid ${color}`}>
        <${TripBadge} />
        <button type="button" class="bc-icon-btn" aria-label="Close" onClick=${close}>✕</button>
      </div>
      <div class="bc-detail-body">
        <h2 class="bc-detail-title">${occ.title || '(untitled)'}</h2>
        <div class="bc-detail-calrow">
          <span class="bc-detail-calchip">
            <${CalDot} cal=${cal} color=${color} />
            ${(cal && cal.name) || 'Calendar'}
          </span>
        </div>
        <div class="bc-detail-when">${tripSpanLabel(occ)}</div>
        ${occ.location && html`<div class="bc-detail-section">
          <div class="bc-detail-locline">
            ${occ.location}
            <a
              class="bc-maplink" href=${gmapsUrl(occ.location, occ.locationLat, occ.locationLng)}
              target="_blank" rel="noopener noreferrer" title="Open in Google Maps"
            >Google Maps ↗</a>
          </div>
        </div>`}

        <div class="bc-detail-section">
          <div class="bc-detail-label">Events${links.status === 'ok' && members.length > 0 ? ' (' + members.length + ')' : ''}</div>
          ${links.status === 'loading' && html`<div class="bc-trip-loading">Loading events</div>`}
          ${links.status === 'error' && html`<div class="bc-trip-loading">
            Could not load this trip's events
            <button type="button" class="bc-link-btn" onClick=${() => setRetrySeq(retrySeq + 1)}>Retry</button>
          </div>`}
          ${links.status === 'ok' && members.length === 0 && html`<div class="bc-trip-empty">No events in this trip yet</div>`}
          ${links.status === 'ok' && html`<div class="bc-trip-members">
            ${members.map((m) => {
              const mcal = state.calendars.find((c) => c.id === m.calendarId);
              return html`<div key=${m.eventId} class="bc-trip-member">
                <span class="bc-trip-member-when">${fmtWhen(m)}</span>
                <button
                  type="button" class="bc-trip-member-title"
                  title=${'Open ' + (m.title || 'event')}
                  onClick=${() => openMember(m)}
                >
                  <${CalDot} cal=${mcal} color=${(mcal && mcal.color) || '#888'} />
                  <span class="bc-chip-title">${m.title || '(untitled)'}</span>
                </button>
                <button
                  type="button" class="bc-icon-btn"
                  title="Open details" aria-label=${'Open details for ' + (m.title || 'event')}
                  onClick=${() => openMember(m)}
                >↗</button>
                <button
                  type="button" class="bc-icon-btn bc-trip-x"
                  title="Remove from trip" aria-label=${'Remove ' + (m.title || 'event') + ' from trip'}
                  onClick=${() => detachFromTrip(occ.eventId, m)}
                >✕</button>
              </div>`;
            })}
          </div>`}
          <div class="bc-trip-addrow">
            <button type="button" class="bc-btn" onClick=${() => { setAdding(!adding); setSelected(new Set()); }}>
              ${adding ? 'Close picker' : 'Add events'}
            </button>
            <button type="button" class="bc-btn" onClick=${newInTrip}>New event in this trip</button>
          </div>
          ${adding && html`<div class="bc-trip-picker">
            ${pickList.length === 0 && html`<div class="bc-trip-empty">No unattached events during this trip</div>`}
            ${pickList.length > 0 && html`<div class="bc-trip-pick-list">
              ${pickList.map((o) => {
                const ocal = state.calendars.find((c) => c.id === o.calendarId);
                return html`<label key=${o.eventId} class="bc-trip-pick-row">
                  <input
                    type="checkbox"
                    checked=${selected.has(o.eventId)}
                    onChange=${() => toggleSelected(o.eventId)}
                  />
                  <span class="bc-trip-pick-when">${fmtWhen(o)}</span>
                  <${CalDot} cal=${ocal} color=${(ocal && ocal.color) || '#888'} />
                  <span class="bc-trip-pick-title">${o.title || '(untitled)'}</span>
                </label>`;
              })}
            </div>`}
            ${pickList.length > 0 && html`<div class="bc-trip-pick-actions">
              <button type="button" class="bc-btn bc-btn-primary" disabled=${selected.size === 0} onClick=${attachSelected}>
                Attach${selected.size > 0 ? ' ' + selected.size : ''}
              </button>
              <button type="button" class="bc-btn" onClick=${() => { setAdding(false); setSelected(new Set()); }}>Cancel</button>
            </div>`}
          </div>`}
        </div>

        <div class="bc-detail-actions">
          <button type="button" class="bc-btn" onClick=${() => set({ detail: null, editor: { mode: 'edit', occ } })}>Edit</button>
          ${!confirmDelete && html`<button type="button" class="bc-btn bc-btn-danger" onClick=${() => setConfirmDelete(true)}>Delete</button>`}
        </div>
        ${confirmDelete && html`<div class="bc-trip-confirm">
          <span>Delete this trip?</span>
          <div class="bc-trip-confirm-row">
            <button type="button" class="bc-btn" onClick=${() => deleteTripOnly(occ)}>Remove trip only</button>
            <button
              type="button" class="bc-btn bc-btn-danger"
              disabled=${links.status !== 'ok'}
              onClick=${() => deleteTripAndMembers(occ, members)}
            >Delete trip and its ${members.length} ${members.length === 1 ? 'event' : 'events'}</button>
            <button type="button" class="bc-btn" onClick=${() => setConfirmDelete(false)}>Cancel</button>
          </div>
          <span class="bc-trip-confirm-note">Remove trip only keeps the events on their calendars</span>
        </div>`}
      </div>
    </div>
  </div>`;
}

// --- trip row on the event side ---------------------------------------------

// props: occ (a non-container occurrence, local or feed).
export function TripRow({ occ }) {
  useStore((s) => s.occVersion); // candidates and containers live in the cache
  const [other, setOther] = useState(false);
  const [query, setQuery] = useState('');

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
