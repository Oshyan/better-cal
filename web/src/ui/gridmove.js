// A month grid's drag-to-move, for things drawn outside the grid that list
// its events (the expanded day). The grid registers its own drag here while
// mounted, so dropping lands on the grid's days with its targeting, its
// highlights and its move rules, rather than a second copy of them.
//
//   gridMove.start(occ, pointerEvent, { grabKey, onLift, onEnd })
//
// grabKey is the day the event was picked up from (the pointer is over the
// panel, not the grid, so the grid can't tell); onLift runs when the drag
// actually starts (the panel steps aside), onEnd after a drop or a cancel.
export const gridMove = { start: null };
