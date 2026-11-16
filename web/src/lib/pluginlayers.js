// Which plugins get a row in the sidebar's Plugins group (#257): the ones
// that draw something no calendar row already controls. A plugin that
// declares `ranges` and makes no calendars of its own (travel, trips) draws
// bands that belong to no calendar, and its row is the only way to show or
// hide them. A plugin with calendars of its own (tides) draws on those
// calendars, whose rows show and hide it; a second row for it switched
// nothing a person could see. Read from the manifest, never from the bands
// loaded, so a row never comes and goes while scrolling.
export function hasLayerRow(pl) {
  return !!pl && !!pl.enabled && (pl.permissions || []).includes('ranges') && !pl.ownCalendars;
}

// A plugin's ranges are hidden only from its row: a hidden state stored for
// a plugin with no row (one unchecked before #257) would leave its drawing
// hidden with nothing left to bring it back, so it counts for nothing.
export function layerHidden(plugins, hidden, pluginId) {
  if (!hidden || !hidden[pluginId]) return false;
  return hasLayerRow((plugins || []).find((p) => p.id === pluginId));
}
