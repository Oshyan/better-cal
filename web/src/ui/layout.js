// Pure layout math for TimeGrid overlap and shared interval logic.
// No DOM, no preact: unit-testable.

// Classic day-view overlap algorithm:
// 1. Sort events by start (then longer first).
// 2. Group into clusters: maximal sets of transitively-overlapping events.
// 3. Within a cluster, greedily assign each event the lowest column whose
//    last event has ended. Cluster width = max columns used; every event in
//    the cluster renders at width 1/cols, left = col/cols.
// A minimum visual span (default 30 min) keeps short events clickable; the
// inflated visual end participates in collision detection so stacked short
// events still get separate columns. Real endMin is preserved on output.
// Input: [{id, startMin, endMin}] (minutes from day start, endMin > startMin).
// Output: [{id, startMin, endMin, visualEnd, col, cols}]
export function layoutOverlaps(items, minSpan = 30) {
  const evs = items
    .map((e) => ({ ...e, visualEnd: Math.max(e.endMin, e.startMin + minSpan) }))
    .sort((a, b) => a.startMin - b.startMin || b.visualEnd - a.visualEnd);
  const out = [];
  let cluster = [];
  let clusterEnd = -1; // running high-water mark of visual ends
  const flush = () => {
    if (!cluster.length) return;
    const colEnds = [];
    for (const e of cluster) {
      let col = colEnds.findIndex((end) => end <= e.startMin);
      if (col === -1) { col = colEnds.length; colEnds.push(0); }
      colEnds[col] = e.visualEnd;
      e.col = col;
    }
    for (const e of cluster) e.cols = colEnds.length;
    out.push(...cluster);
    cluster = [];
  };
  for (const e of evs) {
    if (cluster.length && e.startMin >= clusterEnd) flush();
    cluster.push(e);
    clusterEnd = Math.max(clusterEnd, e.visualEnd);
  }
  flush();
  return out;
}

// Greedy lane assignment for horizontal bars (all-day lane, month multi-day
// rows). Input: [{id, startCol, endCol}] with inclusive integer columns.
// Returns Map id -> lane, packing into the lowest free lane.
export function assignLanes(bars) {
  const sorted = [...bars].sort((a, b) => a.startCol - b.startCol || b.endCol - a.endCol);
  const laneEnds = []; // last occupied endCol per lane
  const lanes = new Map();
  for (const b of sorted) {
    let lane = laneEnds.findIndex((end) => end < b.startCol);
    if (lane === -1) { lane = laneEnds.length; laneEnds.push(-1); }
    laneEnds[lane] = b.endCol;
    lanes.set(b.id, lane);
  }
  return lanes;
}

// Do two [start, end) ranges overlap?
export function rangesOverlap(aStart, aEnd, bStart, bEnd) {
  return aStart < bEnd && bStart < aEnd;
}
