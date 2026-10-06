// Pieces every event view shares (the desktop panel, the phone sheet, trips):
// a repeat rule in words, links in plain-text descriptions, the Leaflet loader
// and base map, the event's mini-map, and the lazy address lookup behind it.
// They lived in EventDetail.js beside the old full-page event view, which was
// removed in 0.7.2 once everything opened in the panel or the sheet.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { state, patchOccurrence } from './store.js';
import { api } from './api.js';
import { PinIcon } from '../ui/icons.js';
import { fmtDateFull, untilDayKey, dateOfDayKey } from '../lib/dates.js';
import { splitUrlTail } from '../lib/richtext.js';
import { mapMosaic, stadiaStyle, mapTilerStyle, isPendingLocation } from '../lib/maps.js';

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
    text += ', until ' + fmtDateFull(dateOfDayKey(untilDayKey(parts.UNTIL)));
  }
  return text;
}

// --- safe URL linkification -------------------------------------------------

// Split text into text nodes and anchor elements. No innerHTML anywhere:
// everything renders through the vdom as text or an explicit <a>.
export function linkify(text) {
  const out = [];
  const re = /https?:\/\/[^\s<>"]+/g;
  let last = 0;
  let m;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) out.push(text.slice(last, m.index));
    const raw = m[0];
    const [url, tail] = splitUrlTail(raw); // trailing punctuation is prose (F26)
    if (url === null || url === '') {
      out.push(raw);
    } else {
      out.push(html`<a href=${url} target="_blank" rel="noopener noreferrer">${url}</a>`);
      if (tail) out.push(tail);
    }
    last = m.index + raw.length;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

// --- leaflet loader (vendored UMD; script tag, no bare imports) -------------

let leafletPromise = null;
export function loadLeaflet() {
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

// On a fine pointer the interactive map mounts immediately (Leaflet is in
// the shell cache and warmed at idle). On touch, first paint is a mosaic of
// the same tiles the map would fetch, behind tap-to-activate; Leaflet loads
// only then, or if a tile fails. While tiles load, the box shows the address
// over a shimmer rather than flat grey, which read as broken.
// The base map every map in the app draws (the event's mini-map, a trip's
// map): Stadia tiles, or MapTiler when a key is configured, or nothing.
export function addBaseTiles(L, map, dark) {
  // Stadia Outdoors tiles (auth is by authorized domain on the Stadia
  // account, no key in the URL); MapTiler when a key is configured
  // (legacy option, kept for self-hosters); plain OSM as last resort.
  const maptilerKey = state.config && state.config.maptilerKey;
  if (maptilerKey) {
    const style = mapTilerStyle(state.settings && state.settings.mapStyle, dark);
    L.tileLayer(
      'https://api.maptiler.com/maps/' + style + '/{z}/{x}/{y}@2x.png?key=' + encodeURIComponent(maptilerKey),
      {
        maxZoom: 19,
        attribution: '© <a href="https://www.maptiler.com/copyright/">MapTiler</a> © <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      }
    ).addTo(map);
  } else {
    L.tileLayer('https://tiles.stadiamaps.com/tiles/' + stadiaStyle(dark) + '/{z}/{x}/{y}{r}.png', {
      maxZoom: 19,
      // Stadia authorizes by request domain; the site's Referrer-Policy
      // (same-origin) would strip it from cross-origin tile requests and
      // every tile would 401, so tiles opt into sending the origin.
      referrerPolicy: 'origin',
      attribution: '© <a href="https://stadiamaps.com/">Stadia Maps</a> © <a href="https://openmaptiles.org/">OpenMapTiles</a> © <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);
  }
}

export function MiniMap({ lat, lng, location }) {
  const elRef = useRef(null);
  const mapRef = useRef(null);
  // Desktop gets the live map straight away: a wheel over it zooms the map,
  // which is what pointing at a map and scrolling means, and nothing under
  // the detail card scrolls anyway. Touch keeps the tile mosaic behind a
  // tap-to-activate, where a live map really does capture the gestures a
  // person needs to move the card.
  const [interactive, setInteractive] = useState(() => !state.coarsePointer);
  // First paint is a mosaic of the same tiles the interactive map uses (see
  // lib/maps.js); 'ok' once every tile has loaded, 'error' if any fails, in
  // which case Leaflet takes over as it would on click.
  const [img, setImg] = useState('loading'); // 'loading' | 'ok' | 'error'
  const loadedRef = useRef(0);
  const dark = state.darkMode;
  const mosaic = mapMosaic(lat, lng, {
    maptilerKey: state.config && state.config.maptilerKey,
    style: state.settings && state.settings.mapStyle,
    retina: (window.devicePixelRatio || 1) > 1,
    dark,
  });
  const wantLeaflet = interactive || img === 'error' || !mosaic;
  const tileLoaded = () => { if (mosaic && ++loadedRef.current >= mosaic.tiles.length) setImg('ok'); };

  useEffect(() => {
    if (!wantLeaflet) return undefined;
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
      addBaseTiles(L, map, dark);
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
      if (interactive) enableOn(map, L);
    }).catch(() => { /* no map is a fine map */ });
    return () => {
      disposed = true;
      if (mapRef.current) {
        mapRef.current.remove();
        mapRef.current = null;
      }
    };
  }, [lat, lng, wantLeaflet, dark]); // eslint-disable-line

  const enable = () => {
    setInteractive(true);
    const map = mapRef.current;
    if (map && window.L) enableOn(map, window.L);
  };

  return html`<div class="bc-map-wrap">
    ${wantLeaflet
      ? html`<div class="bc-map" ref=${elRef}></div>`
      : html`<div class="bc-map-mosaic" role="img" aria-label=${'Map of ' + (location || 'the location')}>
          <div class="bc-map-mosaic-inner" style=${`width:${mosaic.width}px;height:${mosaic.height}px;margin-left:${-mosaic.width / 2}px`}>
            ${mosaic.tiles.map((t) => html`<img
              key=${t.url} src=${t.url} alt="" decoding="async" referrerpolicy="origin"
              style=${`left:${t.left}px;top:${t.top}px`}
              onLoad=${tileLoaded} onError=${() => setImg('error')}
            />`)}
            <img class="bc-map-pin" src="/assets/vendor/leaflet/images/marker-icon-2x.png" alt="" style=${`left:${mosaic.pin.left}px;top:${mosaic.pin.top}px`} />
          </div>
        </div>`}
    ${!wantLeaflet && img === 'loading' && html`<div class="bc-map-ph"><${PinIcon} size=${12} />${location || 'Loading map'}</div>`}
    ${!interactive && html`<button type="button" class="bc-map-cover" onClick=${enable}>
      <span>Click to zoom and pan</span>
    </button>`}
  </div>`;
}

function enableOn(map, L) {
  map.dragging.enable();
  map.scrollWheelZoom.enable();
  map.touchZoom.enable();
  map.doubleClickZoom.enable();
  map.boxZoom.enable();
  map.keyboard.enable();
  map.addControl(L.control.zoom({ position: 'topright' }));
}

// Where an event is on a map: its stored coordinates, or a lookup (server
// proxy, cached) when it has an address but no coordinates yet. Shared by
// the full view and the phone's pulled-up sheet; `active` is whether the
// view that wants it is showing.
export function useEventGeo(occ, active) {
  const [geo, setGeo] = useState(null); // {status:'loading'|'ok'|'none', lat?, lng?}
  // Lazy geocode when the event has a location but no stored coordinates.
  // Resolved coordinates are persisted onto local events (PATCH), so the
  // lookup happens once per event, not once per open.
  const instanceId = active && occ ? occ.instanceId : null;
  const location = occ ? occ.location : null;
  useEffect(() => {
    if (!instanceId || !occ) { setGeo(null); return undefined; }
    if (occ.locationLat != null && occ.locationLng != null) {
      setGeo({ status: 'ok', lat: occ.locationLat, lng: occ.locationLng });
      return undefined;
    }
    if (!location || !location.trim() || isPendingLocation(location)) { setGeo(null); return undefined; }
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
  return geo;
}

// --- detail view ------------------------------------------------------------
