// PeopleInput: chip-style people picker for the editor. Committed names show
// as removable chips; the trailing text input commits on Enter/comma/blur and
// offers autocomplete against the user's existing people (fetched once per
// mount, filtered client-side — the list is small). Backspace in an empty
// input removes the last chip. The NL assist writes straight into `value`.
//
// props: value (list<string>), onChange(list<string>), ariaLabel

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { api } from './api.js';

export function PeopleInput({ value, onChange, ariaLabel }) {
  const [text, setText] = useState('');
  const [known, setKnown] = useState([]); // [{id, name}] from GET /people
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const blurTimer = useRef(0);

  useEffect(() => {
    let alive = true;
    api('/people')
      .then((d) => { if (alive) setKnown((d && d.people) || []); })
      .catch(() => { /* autocomplete is optional */ });
    return () => { alive = false; clearTimeout(blurTimer.current); };
  }, []);

  const names = value || [];
  const has = (name) => names.some((n) => n.toLowerCase() === name.toLowerCase());

  const commit = (raw) => {
    const name = raw.trim().replace(/\s+/g, ' ');
    setText('');
    setOpen(false);
    setActive(-1);
    if (name && !has(name)) onChange([...names, name]);
  };

  const remove = (i) => onChange(names.filter((_, idx) => idx !== i));

  const needle = text.trim().toLowerCase();
  const suggestions = needle
    ? known
        .filter((p) => p.name.toLowerCase().includes(needle) && !has(p.name))
        .slice(0, 6)
    : [];

  const onKeyDown = (e) => {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      if (open && active >= 0 && suggestions[active]) commit(suggestions[active].name);
      else if (text.trim()) commit(text);
    } else if (e.key === 'Backspace' && text === '' && names.length > 0) {
      remove(names.length - 1);
    } else if (e.key === 'ArrowDown' && suggestions.length > 0) {
      e.preventDefault();
      setOpen(true);
      setActive((a) => Math.min(a + 1, suggestions.length - 1));
    } else if (e.key === 'ArrowUp' && open) {
      e.preventDefault();
      setActive((a) => Math.max(a - 1, 0));
    } else if (e.key === 'Escape' && open) {
      e.stopPropagation();
      setOpen(false);
      setActive(-1);
    }
  };

  return html`<div class="bc-peoplein">
    ${names.map((n, i) => html`<span key=${n} class="bc-peoplein-chip">
      ${n}
      <button
        type="button" class="bc-peoplein-x"
        aria-label=${'Remove ' + n} onClick=${() => remove(i)}
      >✕</button>
    </span>`)}
    <span class="bc-peoplein-entry">
      <input
        value=${text}
        placeholder=${names.length === 0 ? 'Add people' : ''}
        aria-label=${ariaLabel || 'People'}
        onInput=${(e) => { setText(e.target.value); setOpen(true); setActive(-1); }}
        onKeyDown=${onKeyDown}
        onBlur=${() => { blurTimer.current = setTimeout(() => { if (text.trim()) commit(text); else { setOpen(false); setActive(-1); } }, 150); }}
        onFocus=${() => clearTimeout(blurTimer.current)}
      />
      ${open && suggestions.length > 0 && html`<div class="bc-peoplein-drop" role="listbox">
        ${suggestions.map((p, i) => html`<button
          key=${p.id} type="button" role="option"
          aria-selected=${i === active}
          class="bc-peoplein-opt${i === active ? ' is-active' : ''}"
          onMouseDown=${(e) => { e.preventDefault(); commit(p.name); }}
          onMouseEnter=${() => setActive(i)}
        >${p.name}</button>`)}
      </div>`}
    </span>
  </div>`;
}
