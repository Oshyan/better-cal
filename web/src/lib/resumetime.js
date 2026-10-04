// The time of day a reload should put week and day view back at (0.9.1).
// resume.js leaves it here at boot; TimeGrid takes it, once, for its first
// scroll instead of "now" or 7am. A module rather than the store, since the
// grid (web/src/ui) never reaches into the app's store.
//
// {within}: px from the top of the time scroller (week).
// {day, within}: the day at the top of the stacked day view, and px into it.

let pending = null;

export function setResumeTime(v) {
  pending = v && Number.isFinite(v.within) ? v : null;
}

export function takeResumeTime() {
  const v = pending;
  pending = null;
  return v;
}
