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
