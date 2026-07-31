// Rank ordering helpers (PRD 5.8). Scores are computed server-side in the
// background and arrive on occurrences as `score` (number 0-1 or null).

// "By match" order for the agenda: scored occurrences first, best match
// first (ties by start time), then unscored occurrences in plain time order.
export function sortByMatch(occurrences) {
  const byStart = (a, b) => (a.start < b.start ? -1 : a.start > b.start ? 1 : 0);
  const scored = [];
  const unscored = [];
  for (const occ of occurrences) {
    (typeof occ.score === 'number' ? scored : unscored).push(occ);
  }
  scored.sort((a, b) => (b.score - a.score) || byStart(a, b));
  unscored.sort(byStart);
  return [...scored, ...unscored];
}
