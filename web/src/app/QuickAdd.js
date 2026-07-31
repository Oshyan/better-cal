// QuickAdd: natural-language event input. Debounced parse preview as you
// type (400ms), Enter or the Create button commits, Esc or Cancel dismisses.
// Shows parsed chips for date, time, location, calendar, plus a fallback
// indicator. "Open full editor" carries the current draft and text into the
// editor drawer's structured form.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, state } from './store.js';
import { quickAddParse, quickAddCommit } from './actions.js';
import { parseISO, fmtDayMedium, fmtTime } from '../lib/dates.js';

export function QuickAdd() {
  const open = useStore((s) => s.quickAddOpen);
  const [text, setText] = useState('');
  const [draft, setDraft] = useState(null);
  const [busy, setBusy] = useState(false);
  const inputRef = useRef(null);
  const timerRef = useRef(0);
  const reqRef = useRef(0);

  useEffect(() => {
    if (open && inputRef.current) {
      inputRef.current.focus();
      setText('');
      setDraft(null);
    }
  }, [open]);

  useEffect(() => () => clearTimeout(timerRef.current), []);

  if (!open) return null;

  const onInput = (e) => {
    const v = e.target.value;
    setText(v);
    clearTimeout(timerRef.current);
    if (!v.trim()) { setDraft(null); return; }
    timerRef.current = setTimeout(async () => {
      const id = ++reqRef.current;
      try {
        const d = await quickAddParse(v);
        if (id === reqRef.current) setDraft(d);
      } catch { /* parse preview is best-effort */ }
    }, 400);
  };

  const submit = async () => {
    if (!text.trim() || busy) return;
    setBusy(true);
    await quickAddCommit(text);
    setBusy(false);
    setText('');
    setDraft(null);
  };

  const onKeyDown = (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      submit();
    }
  };

  const cancel = () => {
    clearTimeout(timerRef.current);
    set({ quickAddOpen: false });
  };

  // Hand the current draft to the structured editor; the raw text rides along
  // so the drawer's NL assist can keep refining it.
  const openFullEditor = () => {
    clearTimeout(timerRef.current);
    const d = draft || {};
    set({
      quickAddOpen: false,
      editor: {
        mode: 'create',
        draft: {
          title: d.title || text.trim(),
          start: d.start, end: d.end, allDay: d.allDay,
          location: d.location, calendarId: d.calendarId,
        },
        nlText: text,
      },
    });
  };

  const cal = draft && draft.calendarId != null
    ? state.calendars.find((c) => c.id === draft.calendarId)
    : null;

  return html`<div class="bc-quickadd-wrap">
    <div class="bc-quickadd" role="dialog" aria-label="Quick add event">
      <input
        ref=${inputRef}
        class="bc-quickadd-input"
        placeholder="Dinner with Sam next Thursday 7pm at Zuni"
        value=${text}
        onInput=${onInput}
        onKeyDown=${onKeyDown}
        aria-label="Describe the event"
      />
      ${draft && html`<div class="bc-quickadd-preview">
        <span class="bc-qchip bc-qchip-title">${draft.title || '(untitled)'}</span>
        ${draft.start && html`<span class="bc-qchip">${fmtDayMedium(parseISO(draft.start))}</span>`}
        ${draft.start && !draft.allDay && html`<span class="bc-qchip">${fmtTime(parseISO(draft.start))}${draft.end ? ' to ' + fmtTime(parseISO(draft.end)) : ''}</span>`}
        ${draft.allDay && html`<span class="bc-qchip">all day</span>`}
        ${draft.location && html`<span class="bc-qchip">@ ${draft.location}</span>`}
        ${draft.personNames && draft.personNames.map((n) => html`<span key=${n} class="bc-qchip">with ${n}</span>`)}
        ${cal && html`<span class="bc-qchip"><span class="bc-cal-dot" style=${`background:${cal.color}`}></span>${cal.name}</span>`}
        ${draft.source === 'fallback' && html`<span class="bc-qchip bc-qchip-fallback" title="Parsed without the language model">basic parse</span>`}
      </div>`}
      <div class="bc-quickadd-actions">
        <button type="button" class="bc-btn bc-btn-primary" disabled=${busy || !text.trim()} onClick=${submit}>Create</button>
        <button type="button" class="bc-btn" onClick=${cancel}>Cancel</button>
        <button type="button" class="bc-link-btn bc-quickadd-expand" onClick=${openFullEditor}>Open full editor</button>
        <span class="bc-quickadd-hint">Enter to create, Esc to cancel</span>
      </div>
    </div>
  </div>`;
}
