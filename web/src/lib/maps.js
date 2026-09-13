// Google Maps place link (place view, not directions: viewing is the base
// case and directions are one tap from there). Stored coordinates when
// available (unambiguous); else the location text as a search query, which
// Google resolves well for named places.
// A static map image for the detail view's first paint: one cacheable
// request instead of script + stylesheet + map init + a screen of tiles.
// MapTiler when the server has a key (its own style names), else Stadia
// (authorised by request origin, like its tiles). The interactive Leaflet
// map loads only when the user asks for it. Pure.
export function staticMapUrl(lat, lng, { width = 480, height = 200, zoom = 15, maptilerKey = null, style = null } = {}) {
  if (lat == null || lng == null) return null;
  const la = Number(lat).toFixed(5);
  const ln = Number(lng).toFixed(5);
  if (maptilerKey) {
    const st = style || 'streets-v2';
    return `https://api.maptiler.com/maps/${encodeURIComponent(st)}/static/${ln},${la},${zoom}/${width}x${height}@2x.png`
      + `?key=${encodeURIComponent(maptilerKey)}&markers=${ln},${la},%23d33d3d`;
  }
  return `https://tiles.stadiamaps.com/static/outdoors.png?center=${la},${ln}&zoom=${zoom}&size=${width}x${height}@2x&markers=${la},${ln},d33d3d`;
}

export function gmapsUrl(location, lat, lng) {
  if (lat != null && lng != null) {
    return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
  }
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(location || '')}`;
}
