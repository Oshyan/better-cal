// The editor's date and time fields. Each is one plain text box rather than
// the browser's segmented datetime control: clicking in selects the whole
// value, so "7p", "730", "noon", "fri" or "10/5" simply replaces it. Enter or
// leaving the field applies what was typed; text that isn't a date or time
// puts the old value back and marks the field. Esc puts it back and keeps the
// editor open.
//
// DateField keeps a calendar button that opens the browser's own date picker.
// TimeField opens a list of quarter hours under the box (Google Calendar's
// pattern): the quick choices are one click, and any exact time can still be
// typed. What a typed time means (am or pm, which day an end lands on) is the
// editor's call, through onText.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { Icon } from '../ui/icons.js';
import { fmtDateFull, fmtDayMedium, dateOfDayKey, todayKey } from '../lib/dates.js';
import { parseDateText } from '../lib/whenparse.js';
import { COARSE_QUERY, PHONE_QUERY } from '../lib/breakpoints.js';

// On a touch screen (0.6.0) the boxes open pickers rather than the keyboard:
// a tap on a date opens the phone's own calendar, a tap on a time opens the
// quarter-hour list, whose first row brings the keyboard when an exact time
// is wanted. On a phone, a date in this year leaves the year off so the box
// never cuts the date short.
const isTouch = () => { try { return matchMedia(COARSE_QUERY).matches; } catch { return false; } };
const isPhone = () => { try { return matchMedia(PHONE_QUERY).matches; } catch { return false; } };
function dateLabel(key) {
  if (!key) return '';
  const d = dateOfDayKey(key);
  return isPhone() && key.slice(0, 4) === todayKey().slice(0, 4) ? fmtDayMedium(d) : fmtDateFull(d);
}

// Select the whole value on the click that focuses the box. The browser
// places the caret on mouseup, which would undo a select() made on focus,
// so that one mouseup is cancelled.
function useSelectOnFocus() {
  const armed = useRef(false);
  return {
    onMouseDown: (e) => { armed.current = document.activeElement !== e.currentTarget; },
    onMouseUp: (e) => { if (armed.current) { e.preventDefault(); armed.current = false; } },
    onFocus: (e) => e.currentTarget.select(),
  };
}

export function DateField({ value, onChange, ariaLabel }) {
  const [text, setText] = useState(null); // null: showing the value, formatted
  const [bad, setBad] = useState(false);
  const pickRef = useRef(null);
  const sel = useSelectOnFocus();
  const shown = text != null ? text : dateLabel(value);
  const touch = isTouch();

  const commit = () => {
    if (text == null) return;
    const typed = text.trim();
    const key = typed ? parseDateText(typed, { todayKey: todayKey(), currentKey: value }) : null;
    setText(null);
    setBad(!!typed && !key);
    if (key && key !== value) onChange(key);
  };

  const openPicker = () => {
    const el = pickRef.current;
    if (!el) return;
    try { el.showPicker(); } catch { el.focus(); el.click(); }
  };

  return html`<span class=${'bc-when-date' + (bad ? ' is-bad' : '')}>
    <input
      type="text" class="bc-when-text" value=${shown} aria-label=${ariaLabel} autocomplete="off" spellcheck="false"
      title=${bad ? 'Not a date I can read. Try fri, tomorrow, 10/5 or oct 5.' : 'Type a date: fri, tomorrow, 10/5, oct 5, or a day of this month'}
      inputmode=${touch ? 'none' : undefined}
      onClick=${touch ? openPicker : undefined}
      ...${sel}
      onInput=${(e) => { setText(e.target.value); setBad(false); }}
      onBlur=${commit}
      onKeyDown=${(e) => {
        if (e.key === 'Enter') { e.preventDefault(); commit(); e.currentTarget.select(); }
        else if (e.key === 'Escape' && text != null) { e.preventDefault(); e.stopPropagation(); setText(null); setBad(false); }
      }}
    />
    <button type="button" class="bc-when-pick" aria-label=${'Pick ' + ariaLabel.toLowerCase()} title="Pick from a calendar" onClick=${openPicker}>
      <${Icon} name="calendar" size=${15} />
    </button>
    <input
      type="date" ref=${pickRef} class="bc-when-native" tabindex="-1" aria-hidden="true" value=${value}
      onInput=${(e) => { if (e.target.value && e.target.value !== value) { setBad(false); onChange(e.target.value); } }}
    />
  </span>`;
}

// options: [{ value, label, note, current }] in list order, current marking
// the one the field holds; currentIdx: the row the list opens scrolled to.
// onText(text) applies typed text and returns whether it could be read.
export function TimeField({ display, options, currentIdx, onText, onOption, ariaLabel }) {
  const [text, setText] = useState(null);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [bad, setBad] = useState(false);
  const [typing, setTyping] = useState(false); // touch: the keyboard was asked for
  const listRef = useRef(null);
  const inputRef = useRef(null);
  const sel = useSelectOnFocus();
  const shown = text != null ? text : display;
  const touch = isTouch();
  const offset = touch && !typing ? 1 : 0; // the "Type a time" row above the times

  // Opening centres the current time; arrowing keeps the active row in view.
  useEffect(() => {
    const list = listRef.current;
    if (!open || !list) return;
    const i = active >= 0 ? active : currentIdx;
    const row = list.children[i + offset];
    if (!row) return;
    if (active < 0) list.scrollTop = row.offsetTop - (list.clientHeight - row.offsetHeight) / 2;
    else if (row.offsetTop < list.scrollTop) list.scrollTop = row.offsetTop;
    else if (row.offsetTop + row.offsetHeight > list.scrollTop + list.clientHeight) list.scrollTop = row.offsetTop + row.offsetHeight - list.clientHeight;
  }, [open, active]); // eslint-disable-line

  const close = () => { setOpen(false); setActive(-1); setTyping(false); };
  // Touch: bring the keyboard up in the same tap (a phone only raises it for
  // a focus that a gesture caused), keeping the list open under it.
  const askKeyboard = () => {
    const el = inputRef.current;
    if (!el) return;
    setTyping(true);
    el.setAttribute('inputmode', 'text');
    el.blur();
    el.focus();
    el.select();
    setOpen(true);
  };
  const commit = () => {
    close();
    if (text == null) return;
    const typed = text.trim();
    const ok = typed ? onText(typed) : false;
    setText(null);
    setBad(!!typed && !ok);
  };
  const choose = (i) => {
    close();
    setText(null);
    setBad(false);
    onOption(options[i].value);
  };
  const step = (d) => {
    const from = active >= 0 ? active : (currentIdx >= 0 ? currentIdx : (d > 0 ? -1 : options.length));
    const i = Math.max(0, Math.min(options.length - 1, from + d));
    setOpen(true);
    setActive(i);
    setText(options[i].label);
  };

  return html`<span class=${'bc-when-time' + (bad ? ' is-bad' : '')}>
    <input
      type="text" class="bc-when-text" value=${shown} aria-label=${ariaLabel} autocomplete="off" spellcheck="false"
      role="combobox" aria-expanded=${open} aria-autocomplete="none" ref=${inputRef}
      inputmode=${touch && !typing ? 'none' : undefined}
      title=${bad ? 'Not a time I can read. Try 7p, 7:30pm, 1930 or noon.' : 'Type a time (7p, 7:30, 1930, noon) or pick one'}
      ...${sel}
      onFocus=${(e) => { sel.onFocus(e); setOpen(true); setActive(-1); }}
      onInput=${(e) => { setText(e.target.value); setBad(false); setOpen(true); setActive(-1); }}
      onBlur=${() => { if (!typing || document.activeElement !== inputRef.current) commit(); }}
      onKeyDown=${(e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); step(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); step(-1); }
        else if (e.key === 'Enter') {
          e.preventDefault();
          if (active >= 0 && text === options[active].label) choose(active);
          else commit();
          e.currentTarget.select();
        } else if (e.key === 'Escape' && (open || text != null)) {
          e.preventDefault(); e.stopPropagation();
          setText(null); setBad(false); close();
        }
      }}
    />
    ${open && html`<div class="bc-when-list" role="listbox" ref=${listRef} aria-label=${ariaLabel + ' choices'}>
      ${offset > 0 && html`<div
        class="bc-when-opt bc-when-type" role="option" aria-selected="false"
        onMouseDown=${(e) => e.preventDefault()}
        onClick=${askKeyboard}
      ><span><${Icon} name="keyboard" size=${15} /> Type a time</span></div>`}
      ${options.map((o, i) => html`<div
        key=${o.value} role="option" aria-selected=${!!o.current}
        class=${'bc-when-opt' + (o.current ? ' is-current' : '') + (i === active ? ' is-active' : '')}
        onMouseDown=${(e) => e.preventDefault()}
        onClick=${() => choose(i)}
      ><span>${o.label}</span>${o.note && html`<small>${o.note}</small>`}</div>`)}
    </div>`}
  </span>`;
}
