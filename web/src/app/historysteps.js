// History steps back that are still on their way (#164).
//
// history.back() and history.go() are asynchronous: the step lands later,
// with a popstate. A surface that pushes its own entry in the meantime
// (a page opening as the welcome closes, the welcome coming back as a page
// closes) pushes onto the entry being left, and the step that then lands
// goes back from the new one instead. Entries get stepped over and Back
// bounces between surfaces.
//
// So surfaces that hand over to each other step back through stepBack(),
// and push their entries inside afterHistorySettles(), which runs at once
// when nothing is in flight and otherwise right after the step's popstate.

let inFlight = 0;

if (typeof window !== 'undefined') {
  // Registered at module load, so it runs before the listeners below.
  window.addEventListener('popstate', () => { if (inFlight > 0) inFlight -= 1; });
}

// Step back n entries. Only for entries this app knows are there: a step
// past the start never lands, and nothing would ever settle.
export function stepBack(n = 1) {
  if (n < 1) return;
  inFlight += 1;
  history.go(-n);
}

// Run fn once no step back is on its way. Returns a cancel function, for a
// useEffect cleanup.
//
// It looks only after the current round of effects has run (a microtask):
// the surface closing in the same render (the welcome as a page opens, a
// page as the welcome comes back) may run its cleanup, and start its step
// back, after the effect that called this.
export function afterHistorySettles(fn) {
  let done = false;
  const on = () => {
    if (done || inFlight > 0) return;
    done = true;
    window.removeEventListener('popstate', on);
    fn();
  };
  queueMicrotask(() => {
    if (done) return;
    if (inFlight === 0) on();
    else window.addEventListener('popstate', on);
  });
  return () => { done = true; window.removeEventListener('popstate', on); };
}
