// Prefetch on intent. The window carries what the grid draws; the single-
// event record and the map image ride the open. Most desktop opens can be
// made instant by fetching them when the pointer settles on a chip, or when
// a chip takes keyboard focus, which is one delegated listener at the
// document root rather than a prop through every grid component (chips
// carry data-instance).
//
// Guard rails, because a mouse sweep across a month crosses forty chips:
// an intent delay before anything is fetched, at most two record fetches in
// flight from here (opens are not counted; ensureFullOccurrence shares the
// in-flight promise anyway), nothing for chips already full, nothing on
// touch (no hover; the popover prefetches on tap instead), and nothing when
// the browser reports the user wants to save data.

import { state } from './store.js';
import { ensureFullOccurrence } from './api.js';
import { mapMosaic } from '../lib/maps.js';

const INTENT_MS = 120;
const MAX_INFLIGHT = 2;
let inflight = 0;
let timer = 0;
let pendingId = null;
const warmedMaps = new Set();

function saveData() {
  const c = navigator.connection;
  return !!(c && c.saveData);
}

// Warm the browser's image cache with the tiles the detail view will show.
export function prefetchMap(occ) {
  if (!occ || occ.locationLat == null || occ.locationLng == null) return;
  const mosaic = mapMosaic(occ.locationLat, occ.locationLng, {
    maptilerKey: state.config && state.config.maptilerKey,
    style: state.settings && state.settings.mapStyle,
    retina: (window.devicePixelRatio || 1) > 1,
  });
  if (!mosaic) return;
  for (const t of mosaic.tiles) {
    if (warmedMaps.has(t.url)) continue;
    warmedMaps.add(t.url);
    const img = new Image();
    img.decoding = 'async';
    img.referrerPolicy = 'origin';
    img.src = t.url;
  }
}

function fire(instanceId) {
  const occ = state.occ.get(instanceId);
  if (!occ || occ.isGroup) return;
  prefetchMap(occ);
  if (occ.full || inflight >= MAX_INFLIGHT) return;
  inflight++;
  ensureFullOccurrence(instanceId).finally(() => { inflight--; });
}

function schedule(instanceId) {
  if (instanceId === pendingId) return;
  clearTimeout(timer);
  pendingId = instanceId;
  timer = setTimeout(() => { pendingId = null; fire(instanceId); }, INTENT_MS);
}

function cancel() {
  clearTimeout(timer);
  pendingId = null;
}

export function installHoverPrefetch() {
  if (saveData()) return;
  document.addEventListener('pointerover', (e) => {
    if (e.pointerType && e.pointerType !== 'mouse') return;
    const chip = e.target && e.target.closest && e.target.closest('[data-instance]');
    if (chip) schedule(chip.getAttribute('data-instance'));
  }, { passive: true });
  document.addEventListener('pointerout', (e) => {
    const chip = e.target && e.target.closest && e.target.closest('[data-instance]');
    if (chip && chip.getAttribute('data-instance') === pendingId) cancel();
  }, { passive: true });
  document.addEventListener('focusin', (e) => {
    const chip = e.target && e.target.closest && e.target.closest('[data-instance]');
    if (chip) schedule(chip.getAttribute('data-instance'));
  });
}
