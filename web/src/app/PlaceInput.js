// PlaceInput: a location text input with a multi-candidate autocomplete
// dropdown (GET /geocode/search). Shared by the editor drawer, the quick add
// strip (compact variant) and the Settings home location row.
//
// Free typing keeps the existing behavior: the text is stored as-is and
// coordinates resolve lazily later (detail view geocode). Picking a candidate
// fills the text ("Name, City") AND hands back lat/lng so the caller can
// persist coordinates directly on create/PATCH.
//
// Search bias, in order: where the calendar puts you around the event's date
// (the nearest-in-time planned event with a known place, within a day and a half, so
// a dinner added during a trip searches near the trip); where this device is,
// when the browser already knows (devicelocation.js, never prompting and
// never waited for); the device's time zone
// when it differs from the home zone (travelling: "The George" should find
// the pub in London, not a bar in San Francisco); the home location setting;
// the given time zone. Zone centroids resolve server-side. Results beyond
// 500 km of the bias are marked with their region so a match half a world
// away is identifiable at a glance.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { api } from './api.js';
import { state } from './store.js';
import { knownPosition, awayFromHome } from './devicelocation.js';
import { localTz } from '../lib/dates.js';
import { onOutsidePress, insideAny } from '../ui/outside.js';

const NEAR_MS = 36 * 3600 * 1000;

/**
 * Where the calendar puts you at `atMs`: the place of the event nearest in
 * time (0 while one spans it, like a stay), within a day and a half. The
 * event being edited is left out. Null when nothing placed is that close.
 */
export function calendarPlaceNear(atMs, excludeEventId = null) {
  if (!Number.isFinite(atMs)) return null;
  let best = null;
  let bestGap = NEAR_MS;
  for (const o of state.occ.values()) {
    // Only plans say where you will be: a feed suggestion or a context entry
    // (sunset at home) is about a place, not about you.
    if (o.relationship !== 'planned') continue;
    if (o.locationLat == null || o.locationLng == null || o.eventId === excludeEventId) continue;
    const s = Date.parse(o.start);
    const e = Date.parse(o.end);
    if (!Number.isFinite(s)) continue;
    const gap = atMs < s ? s - atMs : (Number.isFinite(e) && atMs <= e ? 0 : atMs - (Number.isFinite(e) ? e : s));
    if (gap <= bestGap) { bestGap = gap; best = o; }
  }
  return best ? { lat: best.locationLat, lng: best.locationLng, title: best.title } : null;
}

/** The bias parameters for a place search right now (exported for Settings to describe). */
export function placeBias(tz, near = null) {
  const s = state.settings || {};
  if (near) return { lat: near.lat, lng: near.lng, source: 'calendar' };
  const here = knownPosition();
  if (here) return { lat: here.lat, lng: here.lng, source: 'device' };
  if (awayFromHome(s.tz)) return { tz: localTz(), source: 'devicetz' };
  if (s.homeLat != null && s.homeLng != null) return { lat: s.homeLat, lng: s.homeLng, source: 'home' };
  if (tz) return { tz, source: 'tz' };
  return { source: 'none' };
}

const MIN_CHARS = 2;
const DEBOUNCE_MS = 300;

// "Name, City" fill text for a picked candidate.
export function pickFillText(candidate) {
  if (!candidate) return '';
  const name = candidate.name || '';
  const city = candidate.city || '';
  return city && city !== name ? name + ', ' + city : name;
}

// Region tag for far results: the tail of the address (country, or
// state + country when both exist).
function farRegion(candidate) {
  const parts = (candidate.address || '').split(', ').filter(Boolean);
  if (parts.length === 0) return '';
  return parts.slice(-Math.min(2, parts.length)).join(', ');
}

// ask: {seq, focus} from the caller to search what the box holds now and
// open the candidates (the editor's quick fill found a place). Nothing is
// picked for you; with focus, the cursor moves here so arrows and Enter pick.
export function PlaceInput({
  value, onText, onPick, tz, near, compact, placeholder, ariaLabel, inputClass, ask,
}) {
  const [results, setResults] = useState(null); // null = closed, [] = no matches
  const [active, setActive] = useState(-1);
  const timerRef = useRef(0);
  const reqRef = useRef(0);
  const wrapRef = useRef(null);
  const blurTimer = useRef(0);

  useEffect(() => () => { clearTimeout(timerRef.current); clearTimeout(blurTimer.current); }, []);

  const close = () => { setResults(null); setActive(-1); };
  const inputRef = useRef(null);

  // Opened without the box having focus, the list closes on a press
  // anywhere else rather than on a blur that never comes.
  useEffect(() => (results ? onOutsidePress(insideAny(wrapRef), close) : undefined), [!!results]); // eslint-disable-line

  useEffect(() => {
    if (!ask || !ask.seq) return;
    const q = (value || '').trim();
    if (q.length < MIN_CHARS) return;
    clearTimeout(timerRef.current);
    runSearch(q);
    if (ask.focus && inputRef.current) inputRef.current.focus({ preventScroll: false });
  }, [ask && ask.seq]); // eslint-disable-line

  const runSearch = async (q) => {
    const id = ++reqRef.current;
    const params = new URLSearchParams({ q, limit: '6' });
    const bias = placeBias(tz, typeof near === 'function' ? near() : near);
    if (bias.lat != null) {
      params.set('lat', String(bias.lat));
      params.set('lng', String(bias.lng));
    } else if (bias.tz) {
      params.set('tz', bias.tz);
    }
    try {
      const data = await api('/geocode/search?' + params.toString());
      if (id !== reqRef.current) return; // stale response
      const list = (data && data.results) || [];
      setResults(list.length ? list : null);
      setActive(list.length ? 0 : -1);
    } catch {
      if (id === reqRef.current) close(); // search is best-effort
    }
  };

  const onInput = (e) => {
    const v = e.target.value;
    onText(v);
    clearTimeout(timerRef.current);
    if (v.trim().length < MIN_CHARS) { close(); return; }
    timerRef.current = setTimeout(() => runSearch(v.trim()), DEBOUNCE_MS);
  };

  const pick = (candidate) => {
    clearTimeout(timerRef.current);
    reqRef.current++; // void any in-flight search
    close();
    onPick(candidate);
  };

  const onKeyDown = (e) => {
    if (!results || results.length === 0) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive((i) => (i + 1) % results.length);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((i) => (i - 1 + results.length) % results.length);
    } else if (e.key === 'Enter') {
      // Only intercept Enter while the list is open; a plain input keeps its
      // normal submit behavior.
      e.preventDefault();
      e.stopPropagation();
      pick(results[Math.max(0, active)]);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation(); // just close the list, not the surrounding dialog
      close();
    }
  };

  const onBlur = () => {
    // Delay so a pointerdown on an option lands before the list unmounts.
    blurTimer.current = setTimeout(close, 150);
  };
  const onFocus = () => clearTimeout(blurTimer.current);

  return html`<span class=${'bc-place' + (compact ? ' bc-place-compact' : '')} ref=${wrapRef}>
    <input
      class=${inputClass || ''}
      ref=${inputRef}
      value=${value}
      placeholder=${placeholder || ''}
      aria-label=${ariaLabel || 'Location'}
      autocomplete="off"
      role="combobox"
      aria-expanded=${!!(results && results.length)}
      aria-autocomplete="list"
      onInput=${onInput}
      onKeyDown=${onKeyDown}
      onBlur=${onBlur}
      onFocus=${onFocus}
    />
    ${results && results.length > 0 && html`<ul class="bc-place-list" role="listbox">
      ${results.map((r, i) => html`<li
        key=${r.lat + ',' + r.lng + ':' + r.name}
        class=${'bc-place-row' + (i === active ? ' is-active' : '') + (r.far ? ' is-far' : '')}
        role="option"
        aria-selected=${i === active}
        onPointerDown=${(e) => { e.preventDefault(); pick(r); }}
        onPointerMove=${() => active !== i && setActive(i)}
      >
        <span class="bc-place-name">${r.name}</span>
        ${(r.address || r.far) && html`<span class="bc-place-addr">
          ${r.far && html`<span class="bc-place-farpill" title="Far from your usual area">Far: ${farRegion(r)}</span>`}
          ${r.address}
        </span>`}
      </li>`)}
    </ul>`}
  </span>`;
}
