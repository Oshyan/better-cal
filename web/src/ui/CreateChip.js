// Shared confirm chip for the two-step create flow: after a selection (day
// cells in month/3-day, a drafted time block in week/day), a small fixed
// chip appears at the pointer asking "New event ...?" with Create / Cancel.
// Enter confirms, Esc cancels; any outside pointerdown or scroll dismisses.
// Pure UI: owns no create logic, just calls back.

import { html, useRef, useEffect, useLayoutEffect, useState } from '../../vendor/index.js';
import { chipPosition } from '../lib/quickcreate.js';

export function CreateChip({ x, y, label, onConfirm, onCancel }) {
  const ref = useRef(null);
  const [pos, setPos] = useState(null);

  // Callbacks live in a ref so the document listeners (mounted once) never
  // act on stale closures.
  const cbRef = useRef({ onConfirm, onCancel });
  cbRef.current = { onConfirm, onCancel };

  // Measure, then clamp into the viewport (anchored just below the pointer).
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    setPos(chipPosition(x + 2, y + 12, r.width, r.height, window.innerWidth, window.innerHeight));
  }, [x, y]);

  useEffect(() => {
    const el = ref.current;
    const btn = el && el.querySelector('button');
    if (btn) btn.focus();
    const onDoc = (e) => {
      if (ref.current && !ref.current.contains(e.target)) cbRef.current.onCancel();
    };
    const onKey = (e) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        e.preventDefault();
        cbRef.current.onCancel();
      } else if (e.key === 'Enter') {
        // A focused chip button handles its own Enter via click.
        if (ref.current && ref.current.contains(document.activeElement)) return;
        e.stopPropagation();
        e.preventDefault();
        cbRef.current.onConfirm();
      }
    };
    // Any scroll (view container or page) detaches the fixed chip from its
    // selection; dismiss rather than drift.
    const onScroll = (e) => {
      if (ref.current && ref.current.contains(e.target)) return;
      cbRef.current.onCancel();
    };
    document.addEventListener('pointerdown', onDoc, true);
    document.addEventListener('keydown', onKey, true);
    document.addEventListener('scroll', onScroll, true);
    return () => {
      document.removeEventListener('pointerdown', onDoc, true);
      document.removeEventListener('keydown', onKey, true);
      document.removeEventListener('scroll', onScroll, true);
    };
  }, []);

  return html`<div
    ref=${ref}
    class="bc-create-chip"
    role="dialog"
    aria-label=${label}
    style=${pos ? `left:${pos.x}px;top:${pos.y}px` : 'visibility:hidden'}
  >
    <span class="bc-create-chip-label">${label}</span>
    <button type="button" class="bc-btn bc-btn-primary" onClick=${() => cbRef.current.onConfirm()}>Create</button>
    <button type="button" class="bc-btn" onClick=${() => cbRef.current.onCancel()}>Cancel</button>
  </div>`;
}
