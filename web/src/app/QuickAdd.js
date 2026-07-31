// QuickAdd: natural-language event input. Debounced parse preview as you
// type (400ms), Enter commits, Esc cancels. Shows parsed chips for date,
// time, location, calendar, plus a fallback-parser indicator.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, state } from './store.js';
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

  const onKeyDown = async (e) => {
    if (e.key === 'Enter' && text.trim() && !busy) {
      e.preventDefault();
      setBusy(true);
      await quickAddCommit(text);
      setBusy(false);
      setText('');
      setDraft(null);
    }
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
        ${cal && html`<span class="bc-qchip"><span class="bc-cal-dot" style=${`background:${cal.color}`}></span>${cal.name}</span>`}
        ${draft.source === 'fallback' && html`<span class="bc-qchip bc-qchip-fallback" title="Parsed without the language model">basic parse</span>`}
      </div>`}
      <div class="bc-quickadd-hint">Enter to create, Esc to cancel</div>
    </div>
  </div>`;
}
