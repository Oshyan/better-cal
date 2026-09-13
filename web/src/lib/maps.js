// Map helpers. Pure: everything here is arithmetic and URL building, so the
// smoke tests cover it without a browser.

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
export function mapMosaic(lat, lng, { width = 640, height = 200, zoom = 15, maptilerKey = null, style = null, retina = true } = {}) {
  if (lat == null || lng == null) return null;
  const n = 2 ** zoom;
  const latRad = (Number(lat) * Math.PI) / 180;
  const xt = ((Number(lng) + 180) / 360) * n;
  const yt = ((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) * n;
  const cx = xt * 256;
  const cy = yt * 256;
  const originX = cx - width / 2;
  const originY = cy - height / 2;
  const url = (x, y) => {
    const wx = ((x % n) + n) % n;
    if (maptilerKey) {
      return `https://api.maptiler.com/maps/${encodeURIComponent(style || 'streets-v2')}/${zoom}/${wx}/${y}${retina ? '@2x' : ''}.png?key=${encodeURIComponent(maptilerKey)}`;
    }
    return `https://tiles.stadiamaps.com/tiles/outdoors/${zoom}/${wx}/${y}${retina ? '@2x' : ''}.png`;
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
// case and directions are one tap from there). Stored coordinates when
// available (unambiguous); else the location text as a search query, which
// Google resolves well for named places.
export function gmapsUrl(location, lat, lng) {
  if (lat != null && lng != null) {
    return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
  }
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(location || '')}`;
}
