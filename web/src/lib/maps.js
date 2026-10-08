// Map helpers. Pure: everything here is arithmetic and URL building, so the
// smoke tests cover it without a browser.
import PATTERNS from './patterns.js';

// The tiles that cover a box centred on a point, positioned so the caller
// can lay them out absolutely inside a clipped container and draw a pin at
// the centre. This is the detail view's first paint: the SAME tiles the
// interactive map would fetch (already authorised, already cached after
// one look), six small requests instead of Leaflet + stylesheet + init,
// and no dependence on any provider's static-map product (Stadia's answers
// the free tier with an "Upgrade" image, status 200, which is what this
// replaced).
//
// Web Mercator, slippy-tile scheme: tile x = (lng+180)/360 * 2^z, tile y
// from the Mercator projection of latitude; 256 CSS px per tile at any
// device ratio (the @2x file is the same footprint, twice the pixels).
// Tile styles per ground. Stadia's dark style is the one that pairs with
// Outdoors in weight; MapTiler publishes dark twins for some styles and
// none for others (topo, satellite), which stay as they are.
export function stadiaStyle(dark) {
  return dark ? 'alidade_smooth_dark' : 'outdoors';
}
const MAPTILER_DARK_TWINS = ['streets-v2', 'basic-v2', 'bright-v2', 'dataviz'];
export function mapTilerStyle(style, dark) {
  const base = style || 'streets-v2';
  if (!dark || base.endsWith('-dark') || !MAPTILER_DARK_TWINS.includes(base)) return base;
  return base + '-dark';
}

export function validCoordinates(lat, lng) {
  if (lat == null || lng == null) return null;
  const y = Number(lat);
  const x = Number(lng);
  if (!Number.isFinite(y) || !Number.isFinite(x) || y < -90 || y > 90 || x < -180 || x > 180) return null;
  return { lat: y, lng: x };
}

export function mapMosaic(lat, lng, { width = 640, height = 200, zoom = 15, maptilerKey = null, style = null, retina = true, dark = false } = {}) {
  const point = validCoordinates(lat, lng);
  if (!point || !Number.isFinite(width) || !Number.isFinite(height) || width < 1 || height < 1 || width > 4096 || height > 4096
    || !Number.isInteger(zoom) || zoom < 0 || zoom > 22) return null;
  const n = 2 ** zoom;
  // Web Mercator stops short of the geographic poles.
  const mercatorLat = Math.max(-85.05112878, Math.min(85.05112878, point.lat));
  const latRad = (mercatorLat * Math.PI) / 180;
  const xt = ((point.lng + 180) / 360) * n;
  const yt = ((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) * n;
  const cx = xt * 256;
  const cy = yt * 256;
  const originX = cx - width / 2;
  const originY = cy - height / 2;
  const url = (x, y) => {
    const wx = ((x % n) + n) % n;
    if (maptilerKey) {
      return `https://api.maptiler.com/maps/${encodeURIComponent(mapTilerStyle(style, dark))}/${zoom}/${wx}/${y}${retina ? '@2x' : ''}.png?key=${encodeURIComponent(maptilerKey)}`;
    }
    return `https://tiles.stadiamaps.com/tiles/${stadiaStyle(dark)}/${zoom}/${wx}/${y}${retina ? '@2x' : ''}.png`;
  };
  const tiles = [];
  for (let ty = Math.floor(originY / 256); ty <= Math.floor((originY + height - 1) / 256); ty++) {
    if (ty < 0 || ty >= n) continue;
    for (let tx = Math.floor(originX / 256); tx <= Math.floor((originX + width - 1) / 256); tx++) {
      tiles.push({ url: url(tx, ty), left: tx * 256 - originX, top: ty * 256 - originY });
    }
  }
  // Leaflet's default marker (25x41, anchored bottom-centre), so the static
  // paint and the interactive map put the pin in the same place.
  return { width, height, tiles, pin: { left: width / 2 - 12, top: height / 2 - 41 } };
}

// Google Maps place link (place view, not directions: viewing is the base
// case and directions are one tap from there). The place's own words, name
// and address, searched with the map already at its stored coordinates: Google
// opens that place's card (hours, phone, reviews, Directions) rather than a
// dropped pin on a bare lat/lng, and the coordinates keep an ambiguous text
// ("100 Main Street") from resolving in another city. Coordinates alone
// only when the text says nothing findable (empty, "available once RSVP'd", a
// link, or itself a pair of numbers); the text alone when nothing is stored.
const COORD_TEXT = /^-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?$/;
export function gmapsUrl(location, lat, lng) {
  const text = (location || '').trim();
  const findable = text !== '' && !isPendingLocation(text) && !/^https?:\/\//i.test(text) && !COORD_TEXT.test(text);
  const point = validCoordinates(lat, lng);
  if (findable && point) {
    return `https://www.google.com/maps/search/${encodeURIComponent(text).replace(/%20/g, '+')}/@${point.lat},${point.lng},17z`;
  }
  if (!findable && point) {
    return `https://www.google.com/maps/search/?api=1&query=${point.lat},${point.lng}`;
  }
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(text)}`;
}

// A location that says the location will come later ("Location available
// once RSVP'd", "Register to see address"): feeds put it in the location
// field, but it is not a place. Shown as a short "after RSVP" mark instead
// of the sentence, never offered as a map, never geocoded.
// Shared with the server (lib/patterns.js), which checks the same phrases.
const PENDING_LOCATION = PATTERNS.pendingLocation.map((p) => new RegExp(p, 'i'));
export function isPendingLocation(text) {
  const t = (text || '').trim();
  return !!t && PENDING_LOCATION.some((re) => re.test(t));
}
