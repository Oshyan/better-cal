// The repeat menu behind quick add's repeat chip and button (#165): the
// presets for the event's day, in Google Calendar's order, then when the
// series ends, then Custom, which hands everything to the full editor. A
// parsed rule that is none of the presets ("every 3 days") heads the list,
// ticked, so it can be kept as well as replaced.
//
// It drops down from its trigger inside the quick add card. Touch screens
// get finger-sized rows and the drawn scroll rail (ui/scrollrail.js) in case
// a short screen can't show it all.

import { html, useRef, useEffect, useLayoutEffect } from '../../vendor/index.js';
import { onOutsidePress, insideAny } from '../ui/outside.js';
import { attachScrollRail } from '../ui/scrollrail.js';
import { COARSE_QUERY } from '../lib/breakpoints.js';
import { Icon } from '../ui/icons.js';
import { repeatPresets, ruleBase, describeRepeat, repeatEnd } from '../lib/repeat.js';
import { addDaysKey, dayKeyOf } from '../lib/dates.js';

export function RepeatMenu({ day, rrule, triggerRef, cardRef, onPick, onEnd, onCustom, onClose }) {
  const ref = useRef(null);
  const listRef = useRef(null);

  useEffect(() => onOutsidePress(insideAny(ref, triggerRef), onClose), []); // eslint-disable-line
  // Under the trigger, inside the card's edges, and no taller than the
  // screen below it (the visible part, when a phone keyboard is up).
  useLayoutEffect(() => {
    const place = () => {
      const menu = ref.current;
      const card = cardRef && cardRef.current;
      const trigger = triggerRef.current;
      if (!menu || !card || !trigger) return;
      const c = card.getBoundingClientRect();
      const t = trigger.getBoundingClientRect();
      const width = Math.min(300, c.width - 16);
      menu.style.width = width + 'px';
      menu.style.top = Math.round(t.bottom - c.top + 4) + 'px';
      menu.style.left = Math.round(Math.max(8, Math.min(t.left - c.left, c.width - width - 8))) + 'px';
      const vh = window.visualViewport ? window.visualViewport.height : window.innerHeight;
      menu.style.maxHeight = Math.max(180, Math.floor(vh - t.bottom - 12)) + 'px';
    };
    place();
    const vv = window.visualViewport;
    window.addEventListener('resize', place);
    if (vv) vv.addEventListener('resize', place);
    return () => {
      window.removeEventListener('resize', place);
      if (vv) vv.removeEventListener('resize', place);
    };
  });
  useEffect(() => {
    const el = listRef.current;
    if (!el || !window.matchMedia(COARSE_QUERY).matches) return undefined;
    return attachScrollRail(el);
  }, []);
  // Keyboard users land on the ticked choice.
  useEffect(() => {
    const on = ref.current && ref.current.querySelector('[aria-checked="true"]');
    if (on && !window.matchMedia(COARSE_QUERY).matches) on.focus();
  }, []);

  const presets = repeatPresets(day);
  const base = ruleBase(rrule);
  const extra = rrule && !presets.some((p) => p.rrule === base)
    ? { key: 'parsed', label: describeRepeat(base, { start: day }) || 'Custom repeat', rrule: base }
    : null;
  const items = extra ? [extra, ...presets] : presets;
  const end = repeatEnd(rrule);

  const onKeyDown = (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      onClose(true);
      return;
    }
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    const opts = [...ref.current.querySelectorAll('.bc-repeat-opt')];
    const at = opts.indexOf(document.activeElement);
    if (at < 0) return;
    e.preventDefault();
    opts[(at + (e.key === 'ArrowDown' ? 1 : opts.length - 1)) % opts.length].focus();
  };

  return html`<div class="bc-repeat-menu" ref=${ref} role="menu" aria-label="Repeat" onKeyDown=${onKeyDown}>
    <div class="bc-repeat-list bc-scroll-edges" ref=${listRef}>
      ${items.map((p) => {
        const on = p.rrule === base;
        return html`<button
          key=${p.key} type="button" role="menuitemradio" aria-checked=${on ? 'true' : 'false'}
          class=${'bc-repeat-opt' + (on ? ' is-on' : '')}
          onClick=${() => onPick(p.key, p.rrule)}
        ><span class="bc-repeat-tick">${on && html`<${Icon} name="check" size=${13} />`}</span>${p.label}</button>`;
      })}
    </div>
    ${rrule && html`<div class="bc-repeat-ends">
      <span class="bc-qa-label">Ends</span>
      <select
        value=${end.kind} aria-label="Ends"
        onChange=${(e) => onEnd({ ...end, kind: e.target.value, until: end.until || addDaysKey(dayKeyOf(day), 90) })}
      >
        <option value="never">Never</option>
        <option value="until">On</option>
        <option value="count">After</option>
      </select>
      ${end.kind === 'until' && html`<input
        type="date" value=${end.until} min=${dayKeyOf(day)} aria-label="Last day"
        onChange=${(e) => e.target.value && onEnd({ ...end, until: e.target.value })}
      />`}
      ${end.kind === 'count' && html`<span class="bc-repeat-count">
        <input
          class="bc-num" type="number" min="1" max="999" inputmode="numeric" value=${end.count} aria-label="Number of times"
          onInput=${(e) => Number(e.target.value) > 0 && onEnd({ ...end, count: Number(e.target.value) })}
        /><span>times</span>
      </span>`}
    </div>`}
    <button type="button" role="menuitem" class="bc-repeat-opt bc-repeat-custom" onClick=${onCustom}>
      <span class="bc-repeat-tick"></span>Custom…<small>in the full editor</small>
    </button>
  </div>`;
}
