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
// What the rule is for: a press on what lies behind a surface (the
// calendar, the page) closes it and is used up, so it does not also act
// there. What it is not for: a layer stacked above the surface (search, the
// command palette, quick add, a dialog, a drawer, a menu, a toast) is not
// behind it. A layer's root carries data-layer; its presses are its own and
// act on the first press, and a surface on the page below it stays as it
// was (#305). Between two layers the order is not known, so a layer's own
// surfaces (a menu in the editor, the series panel beside it) still close
// on a press in another layer, except in a top layer (data-layer="top":
// toasts, a confirm or password step-up), which is above everything. Full-screen modals don't use this for
// themselves: their own backdrop receives the click.

// The layers open now, from the DOM: every root marked data-layer. The top
// ones (toasts, a confirm, a password step-up) only when `top` is asked for.
// The one place that says what is open above the page: the keyboard asks it
// whether a shortcut may act (keyboard.js), and a new version asks it
// whether a reload would lose anything (drafts.js, whatWouldBeLost).
export function openLayers({ top = false } = {}) {
  try {
    const all = [...document.querySelectorAll('[data-layer]')];
    return top ? all : all.filter((el) => el.getAttribute('data-layer') !== 'top');
  } catch { return []; }
}

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

// A press inside a layer stacked above this surface. The surface is named
// by isInside.refs (insideAny gives them); one with none is on the page.
function inLayerAbove(target, isInside) {
  const layer = target && target.closest ? target.closest('[data-layer]') : null;
  if (!layer) return false;
  const own = (isInside.refs || []).map((r) => r && r.current).find(Boolean);
  if (own && layer.contains(own)) return false;
  if (layer.getAttribute('data-layer') === 'top') return true;
  return !(own && own.closest('[data-layer]'));
}

// Listen for presses outside a surface while it is open. isInside(target)
// says whether the press belongs to the surface (its panel, its trigger);
// made by insideAny, it also names the surface for the layer rule above.
// Returns the function that stops listening, for a useEffect cleanup.
export function onOutsidePress(isInside, onOutside) {
  const handler = (e) => {
    if (isInside(e.target)) return;
    // Toasts, a confirm or password step-up, search over the panel: a layer
    // above answers its own press. Undo on a toast must undo, not first
    // close the sheet the change was made in.
    if (inLayerAbove(e.target, isInside)) return;
    consumeOutsidePress(e);
    onOutside(e);
  };
  window.addEventListener('pointerdown', handler, true);
  return () => window.removeEventListener('pointerdown', handler, true);
}

// Does this press open an event? One question for every surface that lets
// such a press through instead of only closing (#305). Anything that opens
// an event when pressed carries data-opens-event; the press counts when the
// nearest pressable thing under it is that element, so a control inside it
// (a resize handle is not one; a button is) takes the press for itself.
// A list of selectors here missed a holiday once (#183) and search results
// later (#305).
const PRESSABLE = '[data-opens-event], button, a[href], input, select, textarea, [role="button"]';
export function opensEvent(target) {
  const hit = target && target.closest ? target.closest(PRESSABLE) : null;
  return !!hit && hit.hasAttribute('data-opens-event');
}

// The common case: inside means within any of these elements.
export function insideAny(...refs) {
  const inside = (target) => refs.some((r) => r && r.current && r.current.contains(target));
  inside.refs = refs;
  return inside;
}
