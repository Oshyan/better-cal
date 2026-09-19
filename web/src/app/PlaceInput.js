// PlaceInput: a location text input with a multi-candidate autocomplete
// dropdown (GET /geocode/search). Shared by the editor drawer, the quick add
// strip (compact variant) and the Settings home location row.
//
// Free typing keeps the existing behavior: the text is stored as-is and
// coordinates resolve lazily later (detail view geocode). Picking a candidate
// fills the text ("Name, City") AND hands back lat/lng so the caller can
// persist coordinates directly on create/PATCH.
//
// Search bias, in order: where this device is, when the browser already
// lets us know (devicelocation.js, never prompting); the device's time zone
// when it differs from the home zone (travelling: "The George" should find
// the pub in London, not a bar in San Francisco); the home location setting;
// the given time zone. Zone centroids resolve server-side. Results beyond
// 500 km of the bias are marked with their region so a match half a world
// away is identifiable at a glance.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { api } from './api.js';
import { state } from './store.js';
import { devicePosition, awayFromHome } from './devicelocation.js';
import { localTz } from '../lib/dates.js';

/** The bias parameters for a place search right now (exported for Settings to describe). */
export async function placeBias(tz) {
  const s = state.settings || {};
  const here = await devicePosition();
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

export function PlaceInput({
  value, onText, onPick, tz, compact, placeholder, ariaLabel, inputClass,
}) {
  const [results, setResults] = useState(null); // null = closed, [] = no matches
  const [active, setActive] = useState(-1);
  const timerRef = useRef(0);
  const reqRef = useRef(0);
  const wrapRef = useRef(null);
  const blurTimer = useRef(0);

  useEffect(() => () => { clearTimeout(timerRef.current); clearTimeout(blurTimer.current); }, []);

  const close = () => { setResults(null); setActive(-1); };

  const runSearch = async (q) => {
    const id = ++reqRef.current;
    const params = new URLSearchParams({ q, limit: '6' });
    const bias = await placeBias(tz);
    if (id !== reqRef.current) return; // typed on while the position was read
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
