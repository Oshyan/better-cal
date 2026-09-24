// One rule for every popover, menu, card and sheet that isn't a full-screen
// modal: a press outside it closes it, and does nothing else.
//
// Before this, each surface closed itself on an outside pointerdown but let
// the same press carry on: tapping a calendar cell to dismiss a menu also
// started a drag-create, tapping another event to dismiss a card opened that
// event, tapping a toolbar button to dismiss a popover pressed the button.
// Now the press is consumed:
//
// - the pointerdown stops at the window (capture phase, before any element's
//   own handler), with its default prevented (no focus move, no selection);
// - the click that the same press turns into is swallowed too, however long
//   the press lasted. The next pointerdown starts a fresh sequence and clears
//   the flag, so a press that never became a click (a scroll) leaves nothing
//   armed.
//
// Full-screen modals (search, the editor, the command palette) don't need
// this: their own backdrop receives the click.

let swallowNextClick = false;
let armedAt = 0;

if (typeof window !== 'undefined') {
  // Registered at module load, so it runs before any surface's own listener:
  // every new press sequence starts unarmed.
  window.addEventListener('pointerdown', () => { swallowNextClick = false; }, true);
  window.addEventListener('click', (e) => {
    if (!swallowNextClick) return;
    swallowNextClick = false;
    // A click long after the press (a stuck flag) is let through.
    if (performance.now() - armedAt > 10000) return;
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();
  }, true);
}

// Consume an outside press: call from a pointerdown handler (a scrim's own
// onPointerDown, or the listener below) before closing the surface.
export function consumeOutsidePress(e) {
  if (e && e.cancelable) e.preventDefault();
  // stopPropagation, not stopImmediatePropagation: the page below never
  // sees the press, but every other open surface still does and closes too.
  if (e) e.stopPropagation();
  swallowClickOfThisPress();
}

// Swallow the click that ends the current press, wherever it lands. Also for
// a press-and-hold that already did its thing: iPhone Safari delivers that
// click to whatever is under the finger on release, which after a hold is
// often the menu the hold just opened.
export function swallowClickOfThisPress() {
  swallowNextClick = true;
  armedAt = performance.now();
}

// Listen for presses outside a surface while it is open. isInside(target)
// says whether the press belongs to the surface (its panel, its trigger).
// Returns the function that stops listening, for a useEffect cleanup.
export function onOutsidePress(isInside, onOutside) {
  const handler = (e) => {
    if (isInside(e.target)) return;
    consumeOutsidePress(e);
    onOutside(e);
  };
  window.addEventListener('pointerdown', handler, true);
  return () => window.removeEventListener('pointerdown', handler, true);
}

// The common case: inside means within any of these elements.
export function insideAny(...refs) {
  return (target) => refs.some((r) => r && r.current && r.current.contains(target));
}
