// PeopleInput: chip-style people picker for the editor.
//
// Chips are {id, name} pairs. A chip with an id is a real directory person and
// is what gets saved — the editor sends person ids, never names, so renaming
// someone can't round-trip a stale name into a duplicate person.
//
// A chip with id === null is a *proposed* person: a name the NL parse pulled
// out of "dinner with Sam", or one typed here that matches nobody. Those are
// never created behind the user's back. They render unconfirmed and the prompt
// row below asks, one at a time, whether to create the person or drop the name.
//
// The trailing text input commits on Enter/comma/blur and autocompletes against
// the directory (small list, filtered client-side). Backspace in an empty input
// removes the last chip.
//
// props: value (list<{id, name}>), onChange(list<{id, name}>), ariaLabel

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { api, loadPeople } from './api.js';
import { useStore, state } from './store.js';
import { Icon } from '../ui/icons.js';

export function PeopleInput({ value, onChange, ariaLabel }) {
  const [text, setText] = useState('');
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [busy, setBusy] = useState(false);
  const blurTimer = useRef(0);

  // The shared directory, so a rename on the People page is reflected here
  // without a reload. Boot loads it; refetch only if we arrived before it did.
  const known = useStore((s) => s.people, (a, b) => a === b) || [];
  useEffect(() => {
    if (state.people.length === 0) loadPeople().catch(() => { /* autocomplete is optional */ });
    return () => clearTimeout(blurTimer.current);
  }, []);

  const chips = value || [];
  const has = (name) => chips.some((c) => c.name.toLowerCase() === name.toLowerCase());
  const pendingIndex = chips.findIndex((c) => c.id == null);
  const pending = pendingIndex >= 0 ? chips[pendingIndex] : null;

  const replaceAt = (i, chip) => onChange(chips.map((c, idx) => (idx === i ? chip : c)));
  const remove = (i) => onChange(chips.filter((_, idx) => idx !== i));

  // Commit typed text: an exact directory match links that person (taking the
  // directory's canonical casing); anything else becomes a proposed chip for
  // the prompt row to resolve.
  const commit = (raw) => {
    const name = raw.trim().replace(/\s+/g, ' ');
    setText('');
    setOpen(false);
    setActive(-1);
    if (!name || has(name)) return;
    const hit = known.find((p) => p.name.toLowerCase() === name.toLowerCase());
    onChange([...chips, hit ? { id: hit.id, name: hit.name } : { id: null, name }]);
  };

  // Create the proposed person for real, then link the chip to its new id.
  const createPending = async () => {
    if (!pending || busy) return;
    setBusy(true);
    try {
      const r = await api('/people', { method: 'POST', body: { name: pending.name } });
      replaceAt(pendingIndex, { id: r.id, name: r.name });
      loadPeople().catch(() => {});
    } catch (e) {
      // Leave the chip proposed so the prompt stays up and nothing is lost.
    } finally {
      setBusy(false);
    }
  };

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
    } else if (e.key === 'Backspace' && text === '' && chips.length > 0) {
      remove(chips.length - 1);
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

  return html`<div class="bc-peoplein-wrap">
    <div class="bc-peoplein">
      ${chips.map((c, i) => html`<span
        key=${(c.id == null ? 'new:' : c.id + ':') + c.name}
        class=${'bc-peoplein-chip' + (c.id == null ? ' is-proposed' : '')}
        title=${c.id == null ? 'Not in your people yet' : c.name}
      >
        ${c.id == null && html`<${Icon} name="plus" size=${9} />`}
        ${c.name}
        <button
          type="button" class="bc-peoplein-x"
          aria-label=${'Remove ' + c.name} onClick=${() => remove(i)}
        ><${Icon} name="close" size=${10} /></button>
      </span>`)}
      <span class="bc-peoplein-entry">
        <input
          value=${text}
          placeholder=${chips.length === 0 ? 'Add people' : ''}
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
    </div>
    ${pending && html`<div class="bc-peoplein-confirm" role="status">
      <span>Add to your people?</span>
      <button type="button" class="bc-peoplein-mk" disabled=${busy} onClick=${createPending}>
        ${busy ? 'Adding…' : 'Add person'}
      </button>
      <button type="button" class="bc-peoplein-no" onClick=${() => remove(pendingIndex)}>
        Not a person
      </button>
    </div>`}
  </div>`;
}
