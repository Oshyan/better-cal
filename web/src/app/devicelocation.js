// Where this device is, for biasing place search while travelling.
//
// Never prompts on its own: a permission prompt in the middle of typing a
// location is the wrong moment. If the browser already granted geolocation
// (the person allowed it from Settings, Location & maps, or set their home
// from it), the position is used, cached for ten minutes. Otherwise the
// device's time zone stands in for its region (PlaceInput), which is coarse
// but right on the continent and needs no permission at all.

import { localTz } from '../lib/dates.js';

const CACHE_MS = 10 * 60 * 1000;
let cached = null; // {lat, lng, at}
let permission = null; // 'granted' | 'prompt' | 'denied' | null (unknown)

async function permissionState() {
  if (permission !== null) return permission;
  try {
    const status = await navigator.permissions.query({ name: 'geolocation' });
    permission = status.state;
    status.onchange = () => { permission = status.state; cached = null; };
  } catch {
    permission = 'prompt'; // no Permissions API: treat as not yet granted
  }
  return permission;
}

function position(timeout) {
  return new Promise((resolve) => {
    if (!navigator.geolocation) { resolve(null); return; }
    navigator.geolocation.getCurrentPosition(
      (pos) => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
      () => resolve(null),
      { timeout, maximumAge: CACHE_MS },
    );
  });
}

/**
 * The device position when it can be had without asking; null otherwise.
 * @returns {Promise<{lat:number,lng:number}|null>}
 */
export async function devicePosition() {
  if (cached && Date.now() - cached.at < CACHE_MS) return { lat: cached.lat, lng: cached.lng };
  if (await permissionState() !== 'granted') return null;
  const p = await position(4000);
  if (p) cached = { ...p, at: Date.now() };
  return p;
}

/** Ask (this is the one place that prompts) and remember the answer. */
export async function allowDeviceLocation() {
  const p = await position(10000);
  if (p) { cached = { ...p, at: Date.now() }; permission = 'granted'; }
  return p;
}

export async function deviceLocationPermission() {
  return permissionState();
}

/** True when the device is not in the home zone: the travelling case. */
export function awayFromHome(homeTz) {
  const here = localTz();
  return !!here && !!homeTz && here !== homeTz;
}
