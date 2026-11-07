// The month grid's glide (Today, the arrows): the flag the rest of the app
// reads, and the pure rules of one, kept here so the tests can load them.
//
// Until a glide arrives, what's on screen is on its way there (actions.js
// arrived), so Next steps from where it's going: stepped from the month
// half-way, a quick second press aimed back at it, and the grid turned
// round (#185).

let active = false;

export function glideActive() {
  return active;
}

export function setGlideActive(on) {
  active = !!on;
}

// How far a glide heading for `target` still has to go after a scroll to
// `top`, or null when it is over: within a pixel of the target (or of as far
// as the scroller goes, maxTop), or farther off than at the last scroll,
// which is you scrolling, since a glide only ever closes in (a scrollbar
// drag, Page Down; Safari has no scrollend to say so).
export function glideDistance(top, target, maxTop, lastDist) {
  const dist = Math.abs(top - Math.min(target, maxTop));
  if (dist <= 1 || (lastDist != null && dist > lastDist + 1)) return null;
  return dist;
}

// The scrollTop in one row geometry (monthmath) that shows the same week,
// the same way into it, as `top` does in another: what a glide keeps when
// rows change height under it, and what a switch between fixed and fitted
// rows keeps.
export function samePlace(from, to, top) {
  const w = from.weekAt(top);
  return to.top(w) + ((top - from.top(w)) / from.height(w)) * to.height(w);
}
