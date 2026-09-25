// The day the Split view's list is on, for resume.js (where you were across a
// reload). A plain value in its own module so the reload code doesn't pull in
// the whole view to read it.
let current = null;
export function noteSplitDay(key) { current = key; }
export function splitDay() { return current; }
