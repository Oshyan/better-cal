// Rank ordering helpers (PRD 5.8). Scores are computed server-side in the
// background and arrive on occurrences as `score` (number 0-1 or null).

import { startMs } from './dates.js';

// "By match" order for the agenda: scored occurrences first, best match
// first (ties by start time), then unscored occurrences in plain time order.
export function sortByMatch(occurrences) {
  const byStart = (a, b) => startMs(a) - startMs(b);
  const scored = [];
  const unscored = [];
  for (const occ of occurrences) {
    (typeof occ.score === 'number' ? scored : unscored).push(occ);
  }
  scored.sort((a, b) => (b.score - a.score) || byStart(a, b));
  unscored.sort(byStart);
  return [...scored, ...unscored];
}
