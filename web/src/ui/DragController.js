// Shared pointer-drag logic for mouse and touch.
// Mouse: drag starts after a 4px movement threshold.
// Touch: long-press (350ms) lifts the item; moving first cancels so the
// gesture stays a scroll. Once lifted, the finger's movement is kept from
// the browser (which would otherwise start scrolling and cancel the drag),
// a short buzz says it's lifted, and a second finger going down cancels.
// A release that never lifted calls onTap when given (a click or tap),
// otherwise onCancel; scrolling away or Escape always calls onCancel.
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

// The scroller the edge auto-scroll last moved, and when: a scroll that
// came from it, not from data landing or a re-layout (SplitView).
let autoScroll = null;
export function autoScrolledJustNow(el) {
  return !!autoScroll && autoScroll.el === el && performance.now() - autoScroll.at < 120;
}

// The drag under way: one at a time. Lifted, it kept only its own finger;
// a second finger on another event lifted that one too, and each dropped
// on its own (#185).
let current = null;

// startPointerDrag(pointerdownEvent, opts)
// opts: {
//   makeGhost(): Node | null      ghost appended to body, moved via transform
//   ghostOffset: {x, y}           ghost offset from pointer (default 8,8)
//   scrollEl: Element | null      auto-scrolled near its top/bottom edges
//   hScrollEl: Element | null     auto-scrolled near its left/right edges
//   copyable: bool              Option/Alt held at the drop makes it a copy:
//                                 onDrop gets pt.copy = true
//   onLift(pt), onMove(pt), onDrop(pt), onCancel(), onTap?()
//   pt = {x, y} in client coordinates
// }
// Returns a cancel function.
export function startPointerDrag(e, opts) {
  // The main button only. A right-click, Ctrl-click or two-finger click
  // (the context menu) started drags and creates; on a Mac the menu ate the
  // release, and the drag stayed stuck to the pointer until the next click
  // dropped it (#184).
  // A pen's barrel button and eraser too; Ctrl-click is the context menu
  // only on a Mac (elsewhere it's an ordinary press).
  const mac = /Mac/.test(navigator.platform || '') && navigator.maxTouchPoints < 2;
  if ((e.pointerType === 'mouse' || e.pointerType === 'pen') && (e.button !== 0 || (mac && e.ctrlKey))) return () => {};
  if (current && current.lifted()) return () => {};
  const isTouch = e.pointerType === 'touch';
  const startX = e.clientX, startY = e.clientY;
  let lifted = false;
  let ghost = null;
  let raf = 0;
  let lastPt = { x: startX, y: startY };
  let pressTimer = 0;
  let done = false;

  const gOff = opts.ghostOffset || { x: 8, y: 8 };
  const self = { lifted: () => lifted && !done };
  current = self;

  // Option (Mac) or Alt held during a drag makes the drop a copy. It is read
  // live, so pressing or releasing it mid-drag changes the outcome, and the
  // page shows which (body.bc-drag-copy).
  let copyKey = !!e.altKey;
  // copyable may be a function, asked as the pointer moves: over a person
  // or a trip, Option links or attaches the original, so no copy badge (#184).
  const canCopy = () => (typeof opts.copyable === 'function' ? !!opts.copyable() : !!opts.copyable);
  const showCopy = () => document.body.classList.toggle('bc-drag-copy', lifted && copyKey && canCopy());

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
    if (isTouch && navigator.vibrate) { try { navigator.vibrate(10); } catch { /* not allowed */ } }
    showCopy();
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
      const dy = edgeScrollDy(el, lastPt.y, lastPt.x);
      if (dy !== 0) { el.scrollTop += dy; moved = true; autoScroll = { el, at: performance.now() }; }
    }
    const hel = opts.hScrollEl;
    if (hel) {
      const dx = edgeScrollDx(hel, lastPt.x, lastPt.y);
      if (dx !== 0) { hel.scrollLeft += dx; moved = true; }
    }
    if (moved && opts.onMove) opts.onMove(lastPt); // targets shift under the pointer
    raf = requestAnimationFrame(loop);
  }


  // Only the pointer that started the drag moves or drops it: a second
  // finger's movement and lift went to the drag, dropping it where that
  // finger was (#185).
  const ours = (ev) => e.pointerId == null || ev.pointerId == null || ev.pointerId === e.pointerId;

  function onMove(ev) {
    if (!ours(ev)) return;
    // A mouse moving with no button held was let go somewhere we didn't
    // hear about (another window, Cmd-Tab, a menu): the drag is over, and
    // without knowing where, nothing is dropped.
    if (ev.pointerType === 'mouse' && ev.buttons === 0) { cancel(); return; }
    lastPt = { x: ev.clientX, y: ev.clientY };
    if (ev.altKey !== copyKey) { copyKey = ev.altKey; showCopy(); }
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
    if (copyKey) showCopy();
  }

  function onUp(ev) {
    if (done || (ev && !ours(ev))) return;
    const wasLifted = lifted;
    if (ev && typeof ev.altKey === 'boolean') copyKey = ev.altKey;
    cleanup();
    if (wasLifted) {
      suppressClick = true;
      setTimeout(() => { suppressClick = false; }, 350);
      if (opts.onDrop) opts.onDrop(copyKey && canCopy() ? { ...lastPt, copy: true } : lastPt);
    } else if (opts.onTap) opts.onTap();
    else if (opts.onCancel) opts.onCancel();
  }

  // Touch, once lifted: the browser must not turn the finger's movement into
  // a scroll (it would fire pointercancel and drop the drag).
  function onTouchMove(ev) {
    if (lifted && ev.cancelable) ev.preventDefault();
  }

  function onPointerCancel(ev) {
    if (ours(ev)) cancel();
  }

  // A second finger is a pinch or a two-finger scroll, never this drag.
  // Once the drag has lifted, it is ignored (ours, above), and starts no
  // drag of its own (current).
  function onOtherDown(ev) {
    if (ev.pointerType === 'touch' && ev.pointerId !== e.pointerId && !lifted) cancel();
  }

  // The page scrolled under a still pointer (the wheel, a trackpad, an
  // arrow key): what's under it changed, so the target follows. It kept the
  // day or time from before the scroll (#184).
  function onScrollWhileLifted() {
    if (lifted && !done && opts.onMove) opts.onMove(lastPt);
  }

  function onKey(ev) {
    // Escape cancels the drag and only that: the event panel or the day's
    // list under it stays open (#184).
    if (ev.key === 'Escape') { if (ev.type === 'keydown') { ev.stopPropagation(); ev.preventDefault(); } cancel(); }
    else if (ev.key === 'Alt') { copyKey = ev.type === 'keydown'; showCopy(); if (lifted) ev.preventDefault(); }
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
    if (current === self) current = null;
    clearTimeout(pressTimer);
    cancelAnimationFrame(raf);
    if (ghost) ghost.remove();
    document.body.classList.remove('bc-dragging');
    document.body.classList.remove('bc-drag-copy');
    window.removeEventListener('keyup', onKey, true);
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
    window.removeEventListener('pointercancel', onPointerCancel);
    window.removeEventListener('keydown', onKey, true);
    window.removeEventListener('contextmenu', onContextMenu, true);
    window.removeEventListener('touchmove', onTouchMove, { passive: false });
    window.removeEventListener('pointerdown', onOtherDown, true);
    window.removeEventListener('scroll', onScrollWhileLifted, true);
  }

  window.addEventListener('pointermove', onMove, { passive: false });
  window.addEventListener('scroll', onScrollWhileLifted, true);
  window.addEventListener('pointerup', onUp);
  window.addEventListener('pointercancel', onPointerCancel);
  window.addEventListener('keydown', onKey, true);
  window.addEventListener('keyup', onKey, true);
  if (isTouch) {
    window.addEventListener('contextmenu', onContextMenu, true);
    window.addEventListener('touchmove', onTouchMove, { passive: false });
    window.addEventListener('pointerdown', onOtherDown, true);
  }

  if (isTouch) pressTimer = setTimeout(lift, LONG_PRESS_MS);

  return cancel;
}

// Is the calendar covered at this point by something drawn over it (the
// event panel, the phone sheet, any dialog)? A drop there would land at a
// time hidden underneath (#184). Only covers count: a toast, the loading
// pill or the toolbar the pointer passes while edge-scrolling don't.
export function coveredAt(pt, root) {
  const hit = document.elementFromPoint(pt.x, pt.y);
  const cover = hit && hit.closest && hit.closest('[role="dialog"]');
  return !!cover && !(root && root.contains(cover));
}

// Vertical auto-scroll delta for a pointer y near a scroll container's
// top/bottom edges; speed proportional to edge proximity. Shared by
// startPointerDrag and RescheduleMode.
// Each counts only near the scroller: inside its edge zone, or past its edge
// by no more than that zone again, and (given the other coordinate) beside
// it. Further out (a pointer over the sidebar, the toolbar or the event
// panel) is somewhere else, not "very near the edge": the week track ran at
// full speed, a week a second, while a drag crossed the sidebar to a
// calendar row (#183).
export function edgeScrollDy(scrollEl, y, x = null) {
  const r = scrollEl.getBoundingClientRect();
  if (y < r.top - EDGE_ZONE || y > r.bottom + EDGE_ZONE) return 0;
  if (x != null && (x < r.left || x > r.right)) return 0;
  if (y < r.top + EDGE_ZONE) return -edgeSpeed(r.top + EDGE_ZONE - y);
  if (y > r.bottom - EDGE_ZONE) return edgeSpeed(y - (r.bottom - EDGE_ZONE));
  return 0;
}

// Horizontal counterpart for left/right edges (infinite week day track).
export function edgeScrollDx(scrollEl, x, y = null) {
  const r = scrollEl.getBoundingClientRect();
  if (x < r.left - EDGE_ZONE || x > r.right + EDGE_ZONE) return 0;
  if (y != null && (y < r.top || y > r.bottom)) return 0;
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
