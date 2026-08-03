// EventDetail: the full read-mode view for one occurrence. Centered modal on
// desktop (~560px, backdrop), full-screen sheet on mobile (CSS-driven).
// Prev/next chevrons page chronologically through the same day's visible
// events only. A location block lazily geocodes (server proxy, cached) and
// renders a small non-interactive Leaflet mini-map (click to enable zoom/pan).

import { html, useState, useRef, useMemo, useEffect } from '../../vendor/index.js';
import { useStore, set, state, patchOccurrence } from './store.js';
import { api } from './api.js';
import { deleteEvent, triageAttendance, sendFeedback, enterReschedule, sameDayList, openTripByEventId } from './actions.js';
import { TripDetail } from './Trips.js';
import { trapFocus } from '../ui/DayExpand.js';
import { ThumbIcon, CalDot, PinIcon, LinkIcon, Icon } from '../ui/icons.js';
import {
  parseISO, dateOfDayKey, fmtRange, fmtDateFull, fmtTime,
} from '../lib/dates.js';
import { fmtReminder } from '../lib/reminders.js';
import { hasHtml, sanitizeHtml } from '../lib/richtext.js';
import { gmapsUrl } from '../lib/maps.js';

// --- recurrence in words ----------------------------------------------------

const BYDAY_NAMES = {
  MO: 'Monday', TU: 'Tuesday', WE: 'Wednesday', TH: 'Thursday',
  FR: 'Friday', SA: 'Saturday', SU: 'Sunday',
};

// "FREQ=WEEKLY;BYDAY=MO" -> "Repeats weekly on Monday". Falls back to a plain
// "Repeats" for anything it cannot describe (overrides carry no rrule).
export function describeRrule(rrule) {
  if (!rrule) return 'Repeats';
  const parts = {};
  for (const piece of String(rrule).split(';')) {
    const [k, v] = piece.split('=');
    if (k) parts[k.toUpperCase()] = (v || '').toUpperCase();
  }
  const n = parseInt(parts.INTERVAL || '1', 10) || 1;
  let text;
  switch (parts.FREQ) {
    case 'DAILY':
      text = n === 1 ? 'Repeats daily' : `Repeats every ${n} days`;
      break;
    case 'WEEKLY': {
      text = n === 1 ? 'Repeats weekly' : `Repeats every ${n} weeks`;
      const days = (parts.BYDAY || '')
        .split(',')
        .map((d) => BYDAY_NAMES[d.replace(/^[+-]?\d+/, '')])
        .filter(Boolean);
      if (days.length > 0) text += ' on ' + days.join(', ');
      break;
    }
    case 'MONTHLY':
      text = n === 1 ? 'Repeats monthly' : `Repeats every ${n} months`;
      if (parts.BYMONTHDAY && /^\d+$/.test(parts.BYMONTHDAY)) text += ` on day ${parts.BYMONTHDAY}`;
      break;
    case 'YEARLY':
      text = n === 1 ? 'Repeats yearly' : `Repeats every ${n} years`;
      break;
    default:
      return 'Repeats';
  }
  if (parts.COUNT && /^\d+$/.test(parts.COUNT)) {
    text += `, ${parts.COUNT} times`;
  } else if (parts.UNTIL && /^\d{8}/.test(parts.UNTIL)) {
    const y = Number(parts.UNTIL.slice(0, 4));
    const m = Number(parts.UNTIL.slice(4, 6));
    const d = Number(parts.UNTIL.slice(6, 8));
    text += ', until ' + fmtDateFull(new Date(y, m - 1, d));
  }
  return text;
}

// --- safe URL linkification -------------------------------------------------

// Split text into text nodes and anchor elements. No innerHTML anywhere:
// everything renders through the vdom as text or an explicit <a>.
function linkify(text) {
  const out = [];
  const re = /https?:\/\/[^\s<>"]+/g;
  let last = 0;
  let m;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) out.push(text.slice(last, m.index));
    const raw = m[0];
    const url = raw.replace(/[),.;:!?\]]+$/, ''); // trailing punctuation is prose
    out.push(html`<a href=${url} target="_blank" rel="noopener noreferrer">${url}</a>`);
    if (raw.length > url.length) out.push(raw.slice(url.length));
    last = m.index + raw.length;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

// --- leaflet loader (vendored UMD; script tag, no bare imports) -------------

let leafletPromise = null;
function loadLeaflet() {
  if (window.L) return Promise.resolve(window.L);
  if (!leafletPromise) {
    leafletPromise = new Promise((resolve, reject) => {
      const css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = '/assets/vendor/leaflet/leaflet.css';
      document.head.appendChild(css);
      const script = document.createElement('script');
      script.src = '/assets/vendor/leaflet/leaflet.js';
      script.onload = () => (window.L ? resolve(window.L) : reject(new Error('leaflet missing')));
      script.onerror = () => reject(new Error('leaflet load failed'));
      document.head.appendChild(script);
    });
  }
  return leafletPromise;
}

function MiniMap({ lat, lng, location }) {
  const elRef = useRef(null);
  const mapRef = useRef(null);
  const [interactive, setInteractive] = useState(false);

  useEffect(() => {
    let disposed = false;
    loadLeaflet().then((L) => {
      if (disposed || !elRef.current) return;
      const map = L.map(elRef.current, {
        center: [lat, lng],
        zoom: 15,
        dragging: false,
        scrollWheelZoom: false,
        touchZoom: false,
        doubleClickZoom: false,
        boxZoom: false,
        keyboard: false,
        zoomControl: false,
      });
      // Stadia Outdoors tiles (auth is by authorized domain on the Stadia
      // account, no key in the URL); MapTiler when a key is configured
      // (legacy option, kept for self-hosters); plain OSM as last resort.
      const maptilerKey = state.config && state.config.maptilerKey;
      if (maptilerKey) {
        const style = (state.settings && state.settings.mapStyle) || 'streets-v2';
        L.tileLayer(
          'https://api.maptiler.com/maps/' + style + '/{z}/{x}/{y}@2x.png?key=' + encodeURIComponent(maptilerKey),
          {
            maxZoom: 19,
            attribution: '© <a href="https://www.maptiler.com/copyright/">MapTiler</a> © <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
          }
        ).addTo(map);
      } else {
        L.tileLayer('https://tiles.stadiamaps.com/tiles/outdoors/{z}/{x}/{y}{r}.png', {
          maxZoom: 19,
          // Stadia authorizes by request domain; the site's Referrer-Policy
          // (same-origin) would strip it from cross-origin tile requests and
          // every tile would 401, so tiles opt into sending the origin.
          referrerPolicy: 'origin',
          attribution: '© <a href="https://stadiamaps.com/">Stadia Maps</a> © <a href="https://openmaptiles.org/">OpenMapTiles</a> © <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);
      }
      const icon = L.icon({
        iconUrl: '/assets/vendor/leaflet/images/marker-icon.png',
        iconRetinaUrl: '/assets/vendor/leaflet/images/marker-icon-2x.png',
        shadowUrl: '/assets/vendor/leaflet/images/marker-shadow.png',
        iconSize: [25, 41],
        iconAnchor: [12, 41],
        shadowSize: [41, 41],
      });
      L.marker([lat, lng], { icon, title: location || '' }).addTo(map);
      mapRef.current = map;
    }).catch(() => { /* no map is a fine map */ });
    return () => {
      disposed = true;
      if (mapRef.current) {
        mapRef.current.remove();
        mapRef.current = null;
      }
    };
  }, [lat, lng]);

  const enable = () => {
    const map = mapRef.current;
    if (map && window.L) {
      map.dragging.enable();
      map.scrollWheelZoom.enable();
      map.touchZoom.enable();
      map.doubleClickZoom.enable();
      map.boxZoom.enable();
      map.keyboard.enable();
      map.addControl(window.L.control.zoom({ position: 'topright' }));
    }
    setInteractive(true);
  };

  return html`<div class="bc-map-wrap">
    <div class="bc-map" ref=${elRef}></div>
    ${!interactive && html`<button type="button" class="bc-map-cover" onClick=${enable}>
      <span>Click to zoom and pan</span>
    </button>`}
  </div>`;
}

// --- detail view ------------------------------------------------------------

export function EventDetail() {
  const detail = useStore((s) => s.detail);
  useStore((s) => s.occVersion);
  const occ = detail ? state.occ.get(detail.instanceId) : null;
  const panelRef = useRef(null);
  const [geo, setGeo] = useState(null); // {status:'loading'|'ok'|'none', lat?, lng?}

  // Focus trap while open; Esc is handled by the global keyboard map.
  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Tab') trapFocus(panelRef.current, e);
    };
    if (detail) document.addEventListener('keydown', onKey, true);
    return () => document.removeEventListener('keydown', onKey, true);
  }, [detail]);

  // Lazy geocode when the event has a location but no stored coordinates.
  // Resolved coordinates are persisted onto local events (PATCH), so the
  // lookup happens once per event, not once per open.
  const instanceId = detail ? detail.instanceId : null;
  const location = occ ? occ.location : null;
  useEffect(() => {
    if (!instanceId || !occ) { setGeo(null); return undefined; }
    if (occ.locationLat != null && occ.locationLng != null) {
      setGeo({ status: 'ok', lat: occ.locationLat, lng: occ.locationLng });
      return undefined;
    }
    if (!location || !location.trim()) { setGeo(null); return undefined; }
    let alive = true;
    setGeo({ status: 'loading' });
    // Same bias precedence as the place picker (home location, else event
    // timezone) so "Main Street" — or "SFO" — resolves in the right region.
    const params = new URLSearchParams({ q: location.trim() });
    const s = state.settings || {};
    if (s.homeLat != null && s.homeLng != null) {
      params.set('lat', String(s.homeLat));
      params.set('lng', String(s.homeLng));
    } else {
      const tz = occ.tzid || Intl.DateTimeFormat().resolvedOptions().timeZone;
      if (tz) params.set('tz', tz);
    }
    api('/geocode?' + params)
      .then((res) => {
        if (!alive) return;
        if (res && res.lat != null && res.lng != null) {
          setGeo({ status: 'ok', lat: res.lat, lng: res.lng });
          if (occ.source === 'local') {
            // Persist quietly; failure just means we geocode again next time.
            api('/events/' + occ.eventId, {
              method: 'PATCH',
              body: {
                locationLat: res.lat,
                locationLng: res.lng,
                ...(occ.recurring ? { scope: 'all' } : {}),
              },
            }).catch(() => {});
            patchOccurrence(occ.instanceId, { locationLat: res.lat, locationLng: res.lng });
          }
        } else {
          setGeo({ status: 'none' });
        }
      })
      .catch(() => { if (alive) setGeo({ status: 'none' }); });
    return () => { alive = false; };
  }, [instanceId, location]); // eslint-disable-line

  // Same-day navigation list: this day's visible events in chronological
  // order (all-day first), never spilling into other days. The list builder
  // is shared with the [ ] hotkeys (actions.js sameDayList).
  const dayNav = useMemo(() => {
    if (!occ) return { list: [], index: -1 };
    const list = sameDayList(occ);
    return { list, index: list.findIndex((o) => o.instanceId === occ.instanceId) };
  }, [occ && occ.instanceId, state.occVersion]); // eslint-disable-line

  if (!detail || !occ) return null;

  // Containers get their own view: span line, member list, add/detach.
  if (occ.isContainer) return html`<${TripDetail} occ=${occ} />`;

  const cal = state.calendars.find((c) => c.id === occ.calendarId);
  const isFeed = cal ? cal.kind === 'subscribed' : occ.source === 'feed';
  const color = (cal && cal.color) || '#888';

  const s = occ.allDay ? dateOfDayKey(occ.start.slice(0, 10)) : parseISO(occ.start);
  const e = occ.allDay ? dateOfDayKey(occ.end.slice(0, 10)) : parseISO(occ.end);

  const close = () => set({ detail: null });
  const goTo = (idx) => {
    const target = dayNav.list[idx];
    if (target) set({ detail: { instanceId: target.instanceId } });
  };
  const hasPrev = dayNav.index > 0;
  const hasNext = dayNav.index >= 0 && dayNav.index < dayNav.list.length - 1;

  const showMap = geo && geo.status === 'ok';

  return html`<div class="bc-overlay bc-detail-overlay" onClick=${(ev) => { if (ev.target === ev.currentTarget) close(); }}>
    <div class="bc-detail" ref=${panelRef} role="dialog" aria-modal="true" aria-label="Event detail">
      <div class="bc-detail-head" style=${`border-top: 4px solid ${color}`}>
        <div class="bc-detail-nav" role="group" aria-label="Previous and next event this day">
          <button
            type="button" class="bc-icon-btn bc-detail-chev" aria-label="Previous event this day"
            disabled=${!hasPrev} onClick=${() => goTo(dayNav.index - 1)}
          >‹</button>
          <button
            type="button" class="bc-icon-btn bc-detail-chev" aria-label="Next event this day"
            disabled=${!hasNext} onClick=${() => goTo(dayNav.index + 1)}
          >›</button>
          ${dayNav.list.length > 1 && html`<span class="bc-detail-count">${dayNav.index + 1} of ${dayNav.list.length} this day</span>`}
        </div>
        <span class="bc-pop-iconrow" role="group" aria-label="Event actions">
          ${!isFeed && html`<button type="button" class="bc-icon-btn" title="Reschedule (r)" aria-label="Reschedule" onClick=${() => enterReschedule(occ.instanceId)}><${Icon} name="reschedule" size=${15} /></button>`}
          ${!isFeed && html`<button type="button" class="bc-icon-btn" title="Edit (e)" aria-label="Edit" onClick=${() => set({ detail: null, editor: { mode: 'edit', occ } })}><${Icon} name="pencil" size=${15} /></button>`}
          ${!isFeed && html`<button type="button" class="bc-icon-btn bc-pop-trash" title="Delete" aria-label="Delete" onClick=${() => { set({ detail: null }); deleteEvent(occ); }}><${Icon} name="trash" size=${15} /></button>`}
          <button type="button" class="bc-icon-btn" aria-label="Close" onClick=${close}>✕</button>
        </span>
      </div>
      <div class="bc-detail-body">
        <div class="bc-detail-titlerow">
          <h2 class="bc-detail-title${occ.status === 'cancelled' ? ' is-cancelled' : ''}">
            ${occ.title || '(untitled)'}${occ.isNew ? html` <span class="bc-new-pill">new</span>` : ''}
          </h2>
          ${occ.containers && occ.containers.length > 0 && html`<button
            type="button" class="bc-partof" title="Open this trip"
            onClick=${() => openTripByEventId(occ.containers[0].eventId)}
          ><${LinkIcon} size=${12} /> Part of: ${occ.containers[0].title}</button>`}
        </div>
        <div class="bc-detail-when">
          <span class="bc-detail-calchip">
            <${CalDot} cal=${cal} color=${color} />
            ${(cal && cal.name) || 'Calendar'}
          </span>
          ${fmtRange(s, e, occ.allDay)}
          ${occ.recurring && html`<span class="bc-detail-recur">${describeRrule(occ.rrule)}</span>`}
          ${occ.reminders && occ.reminders.length > 0 && html`<span
            class="bc-bell" role="img" aria-label="Has reminders"
            title=${'Reminders: ' + occ.reminders.map(fmtReminder).join(', ')}
          >🔔</span>`}
          ${occ.status === 'cancelled' && html`<span class="bc-badge">cancelled</span>`}
        </div>
        ${occ.location && html`<div class="bc-detail-section bc-detail-loc">
          <div class="bc-detail-locline">
            <${PinIcon} size=${12} />${occ.location}
            <a
              class="bc-maplink"
              href=${gmapsUrl(occ.location, showMap ? geo.lat : occ.locationLat, showMap ? geo.lng : occ.locationLng)}
              target="_blank" rel="noopener noreferrer" title="Open in Google Maps"
            >Google Maps ↗</a>
          </div>
          ${showMap && html`<${MiniMap} lat=${geo.lat} lng=${geo.lng} location=${occ.location} />`}
        </div>`}
        ${occ.description && html`<div class="bc-detail-section">
          <div class="bc-detail-label">Description</div>
          ${hasHtml(occ.description)
            // Rich descriptions render as HTML, allowlist-sanitized client-side
            // too (defense in depth; also covers verbatim feed imports).
            ? html`<div class="bc-detail-desc bc-rich" dangerouslySetInnerHTML=${{ __html: sanitizeHtml(occ.description) }}></div>`
            : html`<div class="bc-detail-desc">${linkify(occ.description)}</div>`}
        </div>`}
        ${(occ.url || (occ.tags && occ.tags.length > 0) || (occ.people && occ.people.length > 0)) && html`<div class="bc-detail-section bc-detail-labels">
          ${occ.url && html`<a href=${occ.url} target="_blank" rel="noopener noreferrer">Event link ↗</a>`}
          ${occ.people && occ.people.length > 0 && html`<span class="bc-detail-people">
            With ${occ.people.map((n, i) => html`<span key=${n}>${i > 0 && ', '}<button
              type="button" class="bc-person-link" title=${'Open ' + n + ' in People'}
              onClick=${() => set({ detail: null, route: 'people', peopleFocus: n })}
            >${n}</button></span>`)}
          </span>`}
          ${occ.tags && occ.tags.length > 0 && html`<span class="bc-pop-tags">${occ.tags.map((t) => '#' + t).join(' ')}</span>`}
        </div>`}
        ${isFeed && cal && html`<div class="bc-detail-section bc-detail-source">
          <div class="bc-detail-label">Source</div>
          ${cal.sourceUrl
            ? html`<a href=${cal.sourceUrl} target="_blank" rel="noopener noreferrer">${cal.name} ↗</a>`
            : html`<span>${cal.name}</span>`}
        </div>`}
        <div class="bc-detail-meta">
          Added ${fmtDateFull(parseISO(occ.createdAt))} ${fmtTime(parseISO(occ.createdAt))}
          ${occ.updatedAt !== occ.createdAt && html` · Updated ${fmtDateFull(parseISO(occ.updatedAt))} ${fmtTime(parseISO(occ.updatedAt))}`}
        </div>
        ${isFeed && html`<div class="bc-detail-actions">
          ${occ.url && html`<a class="bc-btn bc-detail-openlink" href=${occ.url} target="_blank" rel="noopener noreferrer">Open original link ↗</a>`}
          <div class="bc-seg" role="group" aria-label="Attendance">
            ${[['interested', 'Interested'], ['going', 'Going'], ['hidden', 'Hide']].map(([value, label]) => html`<button
              key=${value} type="button"
              class="bc-seg-btn${occ.attendance === value ? ' is-active' : ''}"
              aria-pressed=${occ.attendance === value}
              onClick=${async () => {
                const result = await triageAttendance(occ, value);
                if (result === 'hidden') close();
              }}
            >${label}</button>`)}
          </div>
          <div class="bc-seg" role="group" aria-label="Feedback">
            ${[['up', 'More like this'], ['down', 'Less like this']].map(([value, label]) => html`<button
              key=${value} type="button" class="bc-seg-btn bc-seg-icon"
              title=${label} aria-label=${label}
              onClick=${() => sendFeedback(occ, value)}
            ><${ThumbIcon} dir=${value} /></button>`)}
          </div>
        </div>`}
      </div>
    </div>
  </div>`;
}
