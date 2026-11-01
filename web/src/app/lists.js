// A page's own list (one the store doesn't hold: Review, Filters, feeds,
// a person's away times): reloaded when an undo changes data under it
// (undoSeq), and only the newest load's answer shown, so an older one that
// finishes later can't put back a list from before. The store's shared
// lists (people, saved views) load the same way (newestOnly).

import { useRef } from '../../vendor/index.js';
import { useStore } from './store.js';

const SUPERSEDED = Symbol('superseded');

/** Is this error a load that a newer one replaced (nothing to show)? */
export const superseded = (e) => e === SUPERSEDED;

/**
 * latest(): wrap each load's request in it. A newer load supersedes it: its
 * answer, or failure, rejects with superseded(e). A further page of the
 * same list passes the list's generation, latest.current() when that
 * list's answer came: it stands only while no fresh load has begun since,
 * so a cursor from before a refresh never adds to the list after it.
 */
export function loadGuard() {
  let gen = 0;
  const latest = (promise, of) => {
    const mine = of === undefined ? ++gen : of;
    return promise.then(
      (v) => { if (mine !== gen) throw SUPERSEDED; return v; },
      (e) => { throw mine !== gen ? SUPERSEDED : e; },
    );
  };
  latest.current = () => gen;
  return latest;
}

/**
 * A shared list's loader: fetch() then apply(answer), with only the newest
 * load's answer applied. A load a newer one replaced settles with that
 * newer one, so a caller awaiting it still sees the list once it's in.
 */
export function newestOnly(fetch, apply) {
  const latest = loadGuard();
  let newest = null;
  return () => {
    const p = latest(fetch()).then(
      (data) => { apply(data); return data; },
      (e) => { if (superseded(e)) return newest; throw e; },
    );
    newest = p;
    return p;
  };
}

/** [undoSeq, latest]: put undoSeq in the effect that loads; latest as loadGuard's. */
export function useOwnList() {
  const undoSeq = useStore((s) => s.undoSeq || 0);
  const guard = useRef(null);
  if (!guard.current) guard.current = loadGuard();
  return [undoSeq, guard.current];
}
