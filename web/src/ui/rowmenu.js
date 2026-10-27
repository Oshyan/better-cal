// Press and hold, and the small menu it opens on a phone: the sidebar's rows
// (0.8.0) and a day's date in the month and 3-day views.

import { html, useRef, useEffect } from '../../vendor/index.js';
import { Icon } from './icons.js';
import { onOutsidePress, insideAny } from './outside.js';

// Half a second without moving; a scroll or a drag cancels it. Android also
// reports the hold as a context menu, which opens the same menu (and never
// the browser's own). The tap that ends a hold doesn't also do the tap.
const HOLD_MS = 480;
export function useLongPress(onLong) {
  const hold = useRef(null);
  const fired = useRef(false);
  const clear = () => { if (hold.current) { clearTimeout(hold.current.t); hold.current = null; } };
  const fire = () => { clear(); fired.current = true; onLong(); };
  return {
    onPointerDown: (e) => {
      fired.current = false;
      clear();
      if (e.button > 0) return;
      hold.current = { x: e.clientX, y: e.clientY, t: setTimeout(fire, HOLD_MS) };
    },
    onPointerMove: (e) => { if (hold.current && Math.hypot(e.clientX - hold.current.x, e.clientY - hold.current.y) > 8) clear(); },
    onPointerUp: clear,
    onPointerCancel: clear,
    onContextMenu: (e) => { e.preventDefault(); if (!fired.current) fire(); },
    onClickCapture: (e) => { if (fired.current) { e.preventDefault(); e.stopPropagation(); fired.current = false; } },
  };
}

// The menu a hold opens: a sheet at the foot of the screen, in the event
// sheet's More style. items: [icon, label, fn] (falsy entries skipped).
export function RowMenu({ head, color, items, onClose }) {
  const ref = useRef(null);
  useEffect(() => onOutsidePress(insideAny(ref), onClose), []); // eslint-disable-line
  return html`<div class="bc-es-menu is-phone bc-rowmenu" role="menu" ref=${ref}>
    <div class="bc-es-mh" role="presentation">${color && html`<i style=${'background:' + color}></i>`}${head}</div>
    ${items.filter(Boolean).map(([icon, label, fn]) => html`<button key=${label} type="button" role="menuitem" class="bc-es-mi" onClick=${() => { onClose(); fn(); }}>
      <${Icon} name=${icon} size=${18} /><span>${label}</span>
    </button>`)}
  </div>`;
}
