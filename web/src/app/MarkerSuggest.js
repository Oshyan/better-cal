// Suggestions while typing a quick-add marker (#169): `/` lists calendars,
// `#` tags, `@` people, narrowing as you type (lib/markersuggest.js). Arrow
// keys move, Enter or Tab fills the pick in, Esc closes the list; Enter
// still creates or fills when no list is showing. Used by quick add and the
// full editor's text box.
//
// A computer gets a list under the box. A phone gets a row of choices in the
// card's own flow instead, so nothing covers the date and time strip and the
// row sits above the keyboard with the box.

import { html, useState, useMemo, useRef, useLayoutEffect, useEffect } from '../../vendor/index.js';
import { useStore, shallowEq } from './store.js';
import { activeMarker, markerSuggestions, applyMarkerPick } from '../lib/markersuggest.js';
import { CalDot } from '../ui/icons.js';
import { loadCalendars } from './api.js';

const KIND_LABEL = { '/': 'Calendars', '#': 'Tags', '@': 'People' };

/**
 * @param {{current: HTMLInputElement|null}} inputRef
 * @param {string} value the box's text
 * @param {(text: string) => void} onChange sets the text as typing would
 * @returns {{onKeyDown: (e: KeyboardEvent) => void, list: any}}
 */
export function useMarkerSuggest(inputRef, value, onChange) {
  const data = useStore((st) => ({ calendars: st.calendars, tags: st.tags, people: st.people }), shallowEq);
  const [caret, setCaret] = useState(null);
  const [active, setActive] = useState(0);
  const [closedAt, setClosedAt] = useState(null); // Esc: closed for this marker until it changes
  const pendingCaret = useRef(null);

  const marker = useMemo(() => activeMarker(value, caret), [value, caret]);
  const markerKey = marker ? marker.start + marker.kind + marker.query : null;
  const items = useMemo(() => {
    if (!marker || closedAt === markerKey) return [];
    return markerSuggestions(marker, {
      calendars: (data.calendars || []).filter((c) => c.editable),
      tags: data.tags || [],
      people: data.people || [],
    });
  }, [markerKey, closedAt, data.calendars, data.tags, data.people]);
  useEffect(() => { setActive(0); }, [markerKey]);

  // Tags arrive with the calendars, so one made a moment ago (by the last
  // quick add) isn't listed yet: the first # in this box asks for them again.
  const tagsAsked = useRef(false);
  useEffect(() => {
    if (!marker || marker.kind !== '#' || tagsAsked.current) return;
    tagsAsked.current = true;
    loadCalendars().catch(() => {});
  }, [marker && marker.kind]);
  // An emptied box is a new event: ask again on its first #.
  useEffect(() => { if (!value) tagsAsked.current = false; }, [value]);

  // Follow the caret: typing, clicking and arrowing along the text all move
  // which word is being typed.
  // Listened for on the document, so it works whenever the box appears, and
  // in the bubble phase: after the box's own input handler has stored the
  // text. (Earlier, the re-render this causes reset the box to the old text
  // before that handler read it, and typing did nothing.)
  useEffect(() => {
    const sync = () => {
      const el = inputRef.current;
      if (!el) return;
      setCaret(document.activeElement === el && el.selectionStart === el.selectionEnd ? el.selectionStart : null);
    };
    const off = (e) => { if (e.target === inputRef.current) setCaret(null); };
    const events = ['selectionchange', 'input', 'keyup', 'mouseup', 'focusin'];
    for (const ev of events) document.addEventListener(ev, sync);
    document.addEventListener('focusout', off);
    return () => {
      for (const ev of events) document.removeEventListener(ev, sync);
      document.removeEventListener('focusout', off);
    };
  }, []);

  // After a pick, put the caret after it once the new text is in the box.
  useLayoutEffect(() => {
    const el = inputRef.current;
    if (pendingCaret.current == null || !el || el.value !== value) return;
    el.setSelectionRange(pendingCaret.current, pendingCaret.current);
    setCaret(pendingCaret.current);
    pendingCaret.current = null;
  }, [value]);

  const pick = (item) => {
    if (!marker || !item) return;
    const r = applyMarkerPick(value, marker, item.insert);
    pendingCaret.current = r.caret;
    onChange(r.text);
    if (inputRef.current) inputRef.current.focus();
  };

  const onKeyDown = (e) => {
    if (!items.length || e.isComposing) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const n = items.length;
      setActive((a) => (a + (e.key === 'ArrowDown' ? 1 : n - 1)) % n);
    } else if (e.key === 'Enter' || e.key === 'Tab') {
      e.preventDefault();
      e.stopPropagation();
      pick(items[Math.min(active, items.length - 1)]);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      setClosedAt(markerKey);
    }
  };

  const list = items.length > 0 && html`<ul class="bc-msuggest" role="listbox" aria-label=${KIND_LABEL[marker.kind]}>
    ${items.map((it, i) => html`<li
      key=${it.key} role="option" aria-selected=${i === active ? 'true' : 'false'}
      class=${'bc-msuggest-item' + (i === active ? ' is-active' : '')}
      onMouseDown=${(e) => e.preventDefault() /* keep the box focused (and a phone's keyboard up) */}
      onClick=${() => pick(it)}
      onMouseEnter=${() => setActive(i)}
    >${it.color && html`<${CalDot} color=${it.color} />`}<span class="bc-msuggest-label">${it.label}</span></li>`)}
  </ul>`;

  return { onKeyDown, list };
}
