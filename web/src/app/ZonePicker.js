// A time zone picker you can type into, for every zone choice in the app
// (Settings, the welcome, the event editor). Several hundred zones make a
// plain <select> a long scroll of similar names, so typing filters the list:
// a city ("lisbon"), a country ("portugal", "PT"), the tz database's region
// note ("pacific", "mountain"), a zone's name ("central european") or an
// offset ("UTC-7", "+5:30", "GMT+1"). Arrow keys and Enter pick; Escape
// leaves the zone as it was. Built on the place field's list styles.

import { html, useState, useRef, useMemo, useEffect, useLayoutEffect } from '../../vendor/index.js';
import { zoneIndex, filterZones, zoneLabel } from '../lib/zonesearch.js';

let pickerSeq = 0;

export function ZonePicker({ value, onChange, extra = [], ariaLabel = 'Time zone', className = '', autoFocus = false }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const inputRef = useRef(null);
  const listRef = useRef(null);
  const idRef = useRef('bc-zone-' + (++pickerSeq));
  const items = useMemo(() => (open ? zoneIndex([value, ...extra]) : []), [open, value]); // eslint-disable-line
  const shown = useMemo(() => filterZones(items, query), [items, query]);

  // Opening shows the whole list at the current zone; typing starts at the
  // top. Layout effects, so the list is never painted at the wrong place first.
  useLayoutEffect(() => {
    if (!open) return;
    const i = query ? 0 : shown.findIndex((e) => e.z === value);
    setActive(Math.max(0, i));
  }, [open, query]); // eslint-disable-line
  // Scroll the list itself to the active row. Not scrollIntoView, which also
  // scrolls every container around it: in the event editor it slid the
  // whole panel sideways.
  useLayoutEffect(() => {
    const list = listRef.current;
    const row = open && list && list.children[active];
    if (!row) return;
    if (row.offsetTop < list.scrollTop) list.scrollTop = row.offsetTop;
    else if (row.offsetTop + row.offsetHeight > list.scrollTop + list.clientHeight) list.scrollTop = row.offsetTop + row.offsetHeight - list.clientHeight;
  }, [open, active, shown]);
  // Keep the list inside whatever clips it (the editor panel, a dialog):
  // narrower than that, and shifted left where it would run past its edge.
  useLayoutEffect(() => {
    const list = listRef.current;
    if (!open || !list) return;
    list.style.left = '';
    list.style.maxWidth = '';
    let clip = list.parentElement;
    while (clip && clip !== document.body && getComputedStyle(clip).overflowX === 'visible' && getComputedStyle(clip).overflowY === 'visible') clip = clip.parentElement;
    // Its inside edges, without border or scroll bar.
    const rect = clip && clip !== document.body ? clip.getBoundingClientRect() : null;
    const bounds = rect
      ? { left: rect.left + clip.clientLeft, right: rect.left + clip.clientLeft + clip.clientWidth }
      : { left: 0, right: document.documentElement.clientWidth };
    const room = bounds.right - bounds.left - 16;
    if (list.offsetWidth > room) list.style.maxWidth = room + 'px';
    const over = list.getBoundingClientRect().right - (bounds.right - 8);
    if (over > 0) list.style.left = -over + 'px';
  }, [open, shown]);
  useEffect(() => { if (autoFocus && inputRef.current) inputRef.current.focus(); }, []); // eslint-disable-line

  const close = () => { setOpen(false); setQuery(''); };
  const pick = (z) => {
    close();
    if (inputRef.current) inputRef.current.blur();
    if (z && z !== value) onChange(z);
  };
  const onKeyDown = (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!open) { setOpen(true); return; }
      const n = shown.length;
      if (n) setActive((i) => (e.key === 'ArrowDown' ? (i + 1) % n : (i - 1 + n) % n));
    } else if (e.key === 'Enter') {
      if (!open) return;
      e.preventDefault();
      e.stopPropagation();
      if (shown[active]) pick(shown[active].z);
    } else if (e.key === 'Escape' && open) {
      e.preventDefault();
      e.stopPropagation(); // only the list, not the surrounding dialog
      close();
      if (inputRef.current) inputRef.current.blur();
    }
  };

  const listId = idRef.current + '-list';
  return html`<span class=${'bc-place bc-zone-picker ' + className}>
    <input
      ref=${inputRef}
      value=${open ? query : zoneLabel(value)}
      placeholder=${open ? zoneLabel(value) + ' · type a city, country or UTC offset' : ''}
      aria-label=${ariaLabel}
      autocomplete="off"
      spellcheck="false"
      role="combobox"
      aria-expanded=${open}
      aria-controls=${listId}
      aria-autocomplete="list"
      aria-activedescendant=${open && shown[active] ? idRef.current + '-' + active : undefined}
      onFocus=${() => { setQuery(''); setOpen(true); }}
      onBlur=${close}
      onInput=${(e) => { setQuery(e.target.value); if (!open) setOpen(true); }}
      onKeyDown=${onKeyDown}
    />
    ${open && html`<ul class="bc-place-list bc-zone-list" role="listbox" id=${listId} ref=${listRef}
      onPointerDown=${(e) => e.preventDefault()}>
      ${shown.map((e, i) => html`<li
        key=${e.z}
        id=${idRef.current + '-' + i}
        class=${'bc-place-row bc-zone-row' + (i === active ? ' is-active' : '') + (e.z === value ? ' is-current' : '')}
        role="option"
        aria-selected=${e.z === value}
        onClick=${() => pick(e.z)}
        onPointerMove=${() => active !== i && setActive(i)}
      ><span class="bc-place-name">${e.city}</span><span class="bc-place-addr">${e.detail}</span></li>`)}
      ${shown.length === 0 && html`<li class="bc-zone-none" role="presentation">No time zone matches “${query}”. Try a city, a country, or an offset like UTC-7.</li>`}
    </ul>`}
  </span>`;
}
