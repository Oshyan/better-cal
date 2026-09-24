// Shared pointer-drag logic for mouse and touch.
// Mouse: drag starts after a 4px movement threshold.
// Touch: long-press (350ms) lifts the item; moving first cancels so the
// gesture stays a scroll.
// Provides a ghost element that follows the pointer via CSS transforms and
// edge auto-scroll of a scroll container while dragging.

const MOVE_THRESHOLD = 4;
const TOUCH_CANCEL_THRESHOLD = 10; // finger drift allowed before long-press cancels
const LONG_PRESS_MS = 350;
const EDGE_ZONE = 44; // px from scroll edge where auto-scroll engages
const EDGE_MAX_SPEED = 18; // px per frame at the very edge

// After a real drag completes, the browser fires a synthetic click on the
// source element; swallow exactly one so drops never also open popovers.
// A drag that ends without a click (usual on touch) must not leave the flag
// set to eat the next real tap, so every new press clears it.
let suppressClick = false;
window.addEventListener('pointerdown', () => { suppressClick = false; }, true);
window.addEventListener('click', (e) => {
  if (suppressClick) {
    suppressClick = false;
    e.stopPropagation();
    e.preventDefault();
  }
}, true);

// startPointerDrag(pointerdownEvent, opts)
// opts: {
//   makeGhost(): Node | null      ghost appended to body, moved via transform
//   ghostOffset: {x, y}           ghost offset from pointer (default 8,8)
//   scrollEl: Element | null      auto-scrolled near its top/bottom edges
//   hScrollEl: Element | null     auto-scrolled near its left/right edges
//   onLift(pt), onMove(pt), onDrop(pt), onCancel()
//   pt = {x, y} in client coordinates
// }
// Returns a cancel function.
export function startPointerDrag(e, opts) {
  const isTouch = e.pointerType === 'touch';
  const startX = e.clientX, startY = e.clientY;
  let lifted = false;
  let ghost = null;
  let raf = 0;
  let lastPt = { x: startX, y: startY };
  let pressTimer = 0;
  let done = false;

  const gOff = opts.ghostOffset || { x: 8, y: 8 };

  function lift() {
    if (lifted || done) return;
    lifted = true;
    if (opts.makeGhost) {
      ghost = opts.makeGhost();
      if (ghost) {
        ghost.classList.add('bc-drag-ghost');
        document.body.appendChild(ghost);
        positionGhost();
      }
    }
    document.body.classList.add('bc-dragging');
    if (opts.onLift) opts.onLift(lastPt);
    loop();
  }

  function positionGhost() {
    if (ghost) ghost.style.transform = 'translate(' + (lastPt.x + gOff.x) + 'px,' + (lastPt.y + gOff.y) + 'px)';
  }

  // rAF loop: edge auto-scroll runs continuously while lifted, so holding
  // near an edge keeps scrolling even without pointer movement.
  function loop() {
    if (done || !lifted) return;
    const el = opts.scrollEl;
    let moved = false;
    if (el) {
      const dy = edgeScrollDy(el, lastPt.y);
      if (dy !== 0) { el.scrollTop += dy; moved = true; }
    }
    const hel = opts.hScrollEl;
    if (hel) {
      const dx = edgeScrollDx(hel, lastPt.x);
      if (dx !== 0) { hel.scrollLeft += dx; moved = true; }
    }
    if (moved && opts.onMove) opts.onMove(lastPt); // targets shift under the pointer
    raf = requestAnimationFrame(loop);
  }


  function onMove(ev) {
    lastPt = { x: ev.clientX, y: ev.clientY };
    if (!lifted) {
      const dist = Math.hypot(ev.clientX - startX, ev.clientY - startY);
      if (isTouch) {
        // Finger drift before the long-press fires means scrolling wins.
        if (dist > TOUCH_CANCEL_THRESHOLD) cancel();
        return;
      }
      if (dist > MOVE_THRESHOLD) lift();
      if (!lifted) return;
    }
    ev.preventDefault();
    positionGhost();
    if (opts.onMove) opts.onMove(lastPt);
  }

  function onUp() {
    if (done) return;
    const wasLifted = lifted;
    cleanup();
    if (wasLifted) {
      suppressClick = true;
      setTimeout(() => { suppressClick = false; }, 350);
      if (opts.onDrop) opts.onDrop(lastPt);
    } else if (opts.onCancel) opts.onCancel();
  }

  function onKey(ev) {
    if (ev.key === 'Escape') cancel();
  }

  function cancel() {
    if (done) return;
    cleanup();
    if (opts.onCancel) opts.onCancel();
  }

  function onContextMenu(ev) {
    // Long-pressable elements must not pop the OS context menu mid-gesture.
    ev.preventDefault();
  }

  function cleanup() {
    done = true;
    clearTimeout(pressTimer);
    cancelAnimationFrame(raf);
    if (ghost) ghost.remove();
    document.body.classList.remove('bc-dragging');
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
    window.removeEventListener('pointercancel', cancel);
    window.removeEventListener('keydown', onKey, true);
    window.removeEventListener('contextmenu', onContextMenu, true);
  }

  window.addEventListener('pointermove', onMove, { passive: false });
  window.addEventListener('pointerup', onUp);
  window.addEventListener('pointercancel', cancel);
  window.addEventListener('keydown', onKey, true);
  if (isTouch) window.addEventListener('contextmenu', onContextMenu, true);

  if (isTouch) pressTimer = setTimeout(lift, LONG_PRESS_MS);

  return cancel;
}

// Vertical auto-scroll delta for a pointer y near a scroll container's
// top/bottom edges; speed proportional to edge proximity. Shared by
// startPointerDrag and RescheduleMode.
export function edgeScrollDy(scrollEl, y) {
  const r = scrollEl.getBoundingClientRect();
  if (y < r.top + EDGE_ZONE) return -edgeSpeed(r.top + EDGE_ZONE - y);
  if (y > r.bottom - EDGE_ZONE) return edgeSpeed(y - (r.bottom - EDGE_ZONE));
  return 0;
}

// Horizontal counterpart for left/right edges (infinite week day track).
export function edgeScrollDx(scrollEl, x) {
  const r = scrollEl.getBoundingClientRect();
  if (x < r.left + EDGE_ZONE) return -edgeSpeed(r.left + EDGE_ZONE - x);
  if (x > r.right - EDGE_ZONE) return edgeSpeed(x - (r.right - EDGE_ZONE));
  return 0;
}

function edgeSpeed(depth) {
  return Math.min(EDGE_MAX_SPEED, 2 + (depth / EDGE_ZONE) * EDGE_MAX_SPEED);
}

// --- external drop targets ---------------------------------------------------
// Hit-test non-grid drop targets under the pointer: sidebar calendar rows
// ([data-drop-cal]), sidebar person rows ([data-drop-person]), and rendered
// occurrences ([data-instance] — chips, bars, trip bands). The drag ghost is
// pointer-events:none, so elementFromPoint sees through it. Each kind in
// `kinds` is either falsy (skip), true (accept all), or a validator taking
// the target's id and returning whether it is a live target for THIS drag.
export function externalDropTarget(pt, kinds) {
  const el = document.elementFromPoint(pt.x, pt.y);
  if (!el) return null;
  if (kinds.cal) {
    const row = el.closest('[data-drop-cal]');
    if (row) {
      const id = Number(row.dataset.dropCal);
      if (kinds.cal === true || kinds.cal(id)) return { kind: 'cal', el: row, id };
    }
  }
  if (kinds.person) {
    const row = el.closest('[data-drop-person]');
    if (row) {
      const id = Number(row.dataset.dropPerson);
      const name = row.dataset.personName || '';
      if (kinds.person === true || kinds.person(id)) return { kind: 'person', el: row, id, name };
    }
  }
  if (kinds.instance) {
    const chip = el.closest('[data-instance]');
    if (chip) {
      const instanceId = chip.dataset.instance;
      if (kinds.instance === true || kinds.instance(instanceId)) return { kind: 'instance', el: chip, instanceId };
    }
  }
  return null;
}

// One highlighted drop row at a time, managed imperatively so hover tracking
// never re-renders the tree mid-drag.
let highlightedRow = null;
export function setDropRowHighlight(el) {
  if (highlightedRow === el) return;
  if (highlightedRow) highlightedRow.classList.remove('bc-drop-row');
  highlightedRow = el || null;
  if (highlightedRow) highlightedRow.classList.add('bc-drop-row');
}

// Clone an element for use as a drag ghost, preserving its rendered size.
// The source may carry inline layout styles from its positioned parent
// (.bc-block sets top/left within its day column); cloned as-is they would
// override the .bc-drag-ghost stylesheet position and displace the ghost far
// from the pointer. Reset positioning so the ghost is fixed at the origin and
// driven purely by the pointer transform, keeping only the rendered size.
export function cloneAsGhost(el) {
  const r = el.getBoundingClientRect();
  const g = el.cloneNode(true);
  g.style.position = 'fixed';
  g.style.left = '0';
  g.style.top = '0';
  g.style.right = 'auto';
  g.style.bottom = 'auto';
  g.style.margin = '0';
  g.style.width = r.width + 'px';
  g.style.height = r.height + 'px';
  return g;
}
