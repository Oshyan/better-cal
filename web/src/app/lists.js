// A page's own list (one the store doesn't hold: Review, Filters, feeds,
// a person's away times): reloaded when an undo changes data under it
// (undoSeq), and only the newest load's answer shown, so an older one that
// finishes later can't put back a list from before.

import { useRef } from '../../vendor/index.js';
import { useStore } from './store.js';

const SUPERSEDED = Symbol('superseded');

/** Is this error a load that a newer one replaced (nothing to show)? */
export const superseded = (e) => e === SUPERSEDED;

/**
 * latest(): wrap each load's request in it. A newer load supersedes it: its
 * answer, or failure, rejects with superseded(e). latest(p, true) is a
 * further page of the same list: it doesn't supersede the load it adds to,
 * and a fresh load supersedes it.
 */
export function loadGuard() {
  let gen = 0;
  return (promise, more = false) => {
    const mine = more ? gen : ++gen;
    return promise.then(
      (v) => { if (mine !== gen) throw SUPERSEDED; return v; },
      (e) => { throw mine !== gen ? SUPERSEDED : e; },
    );
  };
}

/** [undoSeq, latest]: put undoSeq in the effect that loads; latest as loadGuard's. */
export function useOwnList() {
  const undoSeq = useStore((s) => s.undoSeq || 0);
  const guard = useRef(null);
  if (!guard.current) guard.current = loadGuard();
  return [undoSeq, guard.current];
}
