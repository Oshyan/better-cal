// QuickAdd: a compact create card. Natural-language input on top (debounced
// parse preview, 400ms) over an always-visible structured strip: date, start
// and end time (or all day), calendar. The strip live-fills from the parse
// (with the editor drawer's flash affordance) and stays directly editable;
// a field the user touched is only overwritten when a later parse actually
// changes that field. The strip is the source of truth on Create. Enter
// creates from anywhere in the card (flushing any pending parse first), Esc
// cancels, "More options" transfers everything into the full editor drawer.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, state, toast } from './store.js';
import { quickAddParse, quickAddCreate, defaultTargetCalendarId } from './actions.js';
import { api, loadPeople } from './api.js';
import { saveQuickAddText, clearQuickAddText } from './drafts.js';
import { PlaceInput, pickFillText } from './PlaceInput.js';
import { onOutsidePress, insideAny } from '../ui/outside.js';
import {
  parseISO, dayKeyOf, dateOfDayKey, addDaysKey, toISOWithOffset, pad, localTz,
} from '../lib/dates.js';

const hm = (d) => pad(d.getHours()) + ':' + pad(d.getMinutes());

// Directory lookup for a parsed name, case-insensitive like the server's.
const knownPerson = (name) =>
  state.people.find((p) => p.name.toLowerCase() === String(name).trim().toLowerCase());

// Next full hour (rolling into tomorrow near midnight) as the default slot.
function defaultForm() {
  const s = new Date();
  s.setMinutes(0, 0, 0);
  s.setHours(s.getHours() + 1);
  const e = new Date(s.getTime() + 3600000);
  return {
    dateKey: dayKeyOf(s),
    startTime: hm(s),
    endTime: hm(e),
    allDay: false,
    calendarId: defaultCalendarId(),
    location: '',
    locationLat: null,
    locationLng: null,
  };
}

function defaultCalendarId() {
  return defaultTargetCalendarId();
}

// The strip fields a parse draft wants to set. All-day drafts carry literal
// dates, read verbatim (never through timezone conversion).
function parseWants(d) {
  const want = {};
  if (d.start) {
    if (d.allDay) {
      want.dateKey = d.start.slice(0, 10);
    } else {
      const sd = parseISO(d.start);
      want.dateKey = dayKeyOf(sd);
      want.startTime = hm(sd);
    }
  }
  if (d.end && !d.allDay) want.endTime = hm(parseISO(d.end));
  if (d.allDay != null) want.allDay = !!d.allDay;
  if (d.location) want.location = d.location;
  if (d.calendarId != null &&
      state.calendars.some((c) => c.id === d.calendarId && c.kind !== 'subscribed')) {
    want.calendarId = d.calendarId;
  }
  return want;
}

// Strip values -> {start, end} ISO strings, or null when incomplete.
function buildRange(form, draft, dateTouched) {
  if (!form.dateKey) return null;
  if (form.allDay) {
    let endKey = addDaysKey(form.dateKey, 1);
    // Preserve a parsed multi-day all-day span while the date is untouched.
    if (draft && draft.allDay && draft.end && !dateTouched) {
      const parsedEnd = draft.end.slice(0, 10);
      if (parsedEnd > endKey) endKey = parsedEnd;
    }
    return {
      start: toISOWithOffset(dateOfDayKey(form.dateKey)),
      end: toISOWithOffset(dateOfDayKey(endKey)),
    };
  }
  if (!form.startTime || !form.endTime) return null;
  const base = dateOfDayKey(form.dateKey);
  const [sh, sm] = form.startTime.split(':').map(Number);
  const [eh, em] = form.endTime.split(':').map(Number);
  const s = new Date(base.getFullYear(), base.getMonth(), base.getDate(), sh, sm);
  let e = new Date(base.getFullYear(), base.getMonth(), base.getDate(), eh, em);
  if (e <= s) e = new Date(s.getTime() + 3600000); // end at/before start: give it an hour
  return { start: toISOWithOffset(s), end: toISOWithOffset(e) };
}

// "Aug 10 – Aug 15" for an availability draft (end exclusive -> inclusive).
function fmtAvailRange(d) {
  const f = (x) => x.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  const s = parseISO(d.start);
  const e = new Date(parseISO(d.end).getTime() - 60000);
  return f(s) === f(e) ? f(s) : f(s) + ' – ' + f(e);
}

export function QuickAdd() {
  const open = useStore((s) => s.quickAddOpen);
  const [text, setText] = useState('');
  // Typed text is durable across a reload (drafts.js); cleared on create,
  // cancel, or hand-off to the editor.
  useEffect(() => { saveQuickAddText(text); }, [text]);
  const [draft, setDraft] = useState(null);
  const [form, setForm] = useState(defaultForm);
  const [flash, setFlash] = useState(null); // Set of strip field names, or null
  const [busy, setBusy] = useState(false);
  const inputRef = useRef(null);
  const timerRef = useRef(0);
  const reqRef = useRef(0);
  const flashTimer = useRef(0);
  const touchedRef = useRef(new Set());  // strip fields the user edited
  const prevParseRef = useRef({});       // last parse-derived value per field
  const lastParsedRef = useRef('');      // text of the last applied parse
  const formRef = useRef(form);          // sync mirror for async callbacks
  formRef.current = form;
  const draftRef = useRef(draft);
  draftRef.current = draft;
  const cardRef = useRef(null);

  // A press outside the card dismisses it and does nothing else (ui/outside.js).
  useEffect(() => {
    if (!open) return undefined;
    return onOutsidePress(insideAny(cardRef), () => { clearQuickAddText(); set({ quickAddOpen: false }); });
  }, [open]);

  useEffect(() => {
    if (open && inputRef.current) {
      inputRef.current.focus();
      setText('');
      setDraft(null);
      const f = defaultForm();
      setForm(f);
      formRef.current = f;
      draftRef.current = null;
      setFlash(null);
      touchedRef.current = new Set();
      prevParseRef.current = {};
      lastParsedRef.current = '';
      reqRef.current++; // void any in-flight parse from a previous open
      // Share-target seed: arrive with the shared text typed and parsing.
      if (state.quickAddSeed) {
        const seed = state.quickAddSeed;
        set({ quickAddSeed: null });
        setText(seed);
        clearTimeout(timerRef.current);
        timerRef.current = setTimeout(async () => {
          const id = ++reqRef.current;
          try {
            const d = await quickAddParse(seed);
            if (id === reqRef.current) applyParse(d, seed.trim());
          } catch { /* best-effort */ }
        }, 50);
      }
    }
  }, [open]);

  useEffect(() => () => { clearTimeout(timerRef.current); clearTimeout(flashTimer.current); }, []);

  if (!open) return null;

  // Fill the strip from a parse. Untouched fields always follow the parse;
  // touched fields are only overwritten when the parse's value for that
  // field changed since the previous parse (the user's edit wins otherwise).
  const applyParse = (d, sourceText) => {
    setDraft(d);
    draftRef.current = d;
    lastParsedRef.current = sourceText;
    if (!d) return;
    const want = parseWants(d);
    const prev = prevParseRef.current;
    const touched = touchedRef.current;
    const nf = { ...formRef.current };
    const flashed = [];
    for (const k of Object.keys(want)) {
      if (touched.has(k) && want[k] === prev[k]) continue; // edit wins
      if (nf[k] !== want[k]) {
        nf[k] = want[k];
        flashed.push(k);
        // Parsed free-text location: any previously picked coordinates are
        // for old text and no longer apply.
        if (k === 'location') { nf.locationLat = null; nf.locationLng = null; }
      }
      touched.delete(k); // the parse re-took this field
    }
    prevParseRef.current = { ...prev, ...want };
    if (flashed.length) {
      setForm(nf);
      formRef.current = nf;
      setFlash(new Set(flashed));
      clearTimeout(flashTimer.current);
      flashTimer.current = setTimeout(() => setFlash(null), 900);
    }
  };

  const onInput = (e) => {
    const v = e.target.value;
    setText(v);
    clearTimeout(timerRef.current);
    if (!v.trim()) { setDraft(null); draftRef.current = null; return; }
    timerRef.current = setTimeout(async () => {
      const id = ++reqRef.current;
      try {
        const d = await quickAddParse(v);
        if (id === reqRef.current) applyParse(d, v.trim());
      } catch { /* parse preview is best-effort */ }
    }, 400);
  };

  const touch = (field, value) => {
    touchedRef.current.add(field);
    setForm((f) => {
      const nf = { ...f, [field]: value };
      formRef.current = nf;
      return nf;
    });
  };

  // Creating faster than the debounce: parse the latest text first so the
  // strip (and title) reflect what was actually typed.
  const flushParse = async () => {
    const t = text.trim();
    if (!t || t === lastParsedRef.current) return;
    clearTimeout(timerRef.current);
    const id = ++reqRef.current;
    try {
      const d = await quickAddParse(t);
      if (id === reqRef.current) applyParse(d, t);
    } catch { /* best-effort; the strip already holds usable values */ }
  };

  const submit = async () => {
    if (busy) return;
    setBusy(true);
    await flushParse();
    const d = draftRef.current;
    const f = formRef.current;
    // Availability statement ("Sam is away Aug 10-15"): record the span, not
    // an event.
    if (d && d.intent === 'availability') {
      try {
        await api('/people/' + d.personId + '/availability', {
          method: 'POST',
          body: { start: d.start, end: d.end, kind: d.kind },
        });
        clearQuickAddText();
        set({ availSeq: state.availSeq + 1, quickAddOpen: false });
        toast(d.personName + ' marked ' + d.kind);
        loadPeople().catch(() => {});
      } catch (err) {
        toast(err.message || 'Could not record availability', { error: true });
      } finally {
        setBusy(false);
      }
      return;
    }
    const title = (d && d.title) || text.trim();
    const range = buildRange(f, d, touchedRef.current.has('dateKey'));
    if (!title || !range || f.calendarId == null) { setBusy(false); return; }
    const location = (f.location || '').trim() || (d && d.location) || null;
    // A name the parse found that matches nobody is a decision, not a detail:
    // creating the person here would do it silently, and dropping the name
    // would lose it. Hand the whole draft to the editor, which asks. Names
    // already in the directory link by id and keep the one-keystroke path.
    const parsed = (d && d.personNames) || [];
    if (parsed.some((n) => !knownPerson(n))) {
      setBusy(false);
      openFullEditor();
      toast('New name in there — confirm who they are before saving');
      return;
    }
    await quickAddCreate({
      title,
      calendarId: Number(f.calendarId),
      start: range.start,
      end: range.end,
      allDay: f.allDay,
      location,
      locationLat: location && f.locationLat != null ? f.locationLat : null,
      locationLng: location && f.locationLng != null ? f.locationLng : null,
      ...(parsed.length ? { personIds: parsed.map((n) => knownPerson(n).id) } : {}),
    });
    setBusy(false);
  };

  // Enter anywhere in the card creates; buttons and links keep Enter for
  // their own activation. Esc is handled by the global keyboard map.
  const onCardKeyDown = (e) => {
    if (e.key !== 'Enter') return;
    const tag = e.target.tagName;
    if (tag === 'BUTTON' || tag === 'A') return;
    e.preventDefault();
    submit();
  };

  const cancel = () => {
    clearTimeout(timerRef.current);
    clearQuickAddText();
    set({ quickAddOpen: false });
  };

  // Hand everything to the structured editor; the raw text rides along only
  // while the strip is untouched, so the drawer's NL assist cannot clobber
  // the user's manual edits.
  const openFullEditor = () => {
    clearTimeout(timerRef.current);
    clearQuickAddText(); // the text now lives in the editor's draft
    const range = buildRange(form, draft, touchedRef.current.has('dateKey'));
    set({
      quickAddOpen: false,
      editor: {
        mode: 'create',
        draft: {
          title: (draft && draft.title) || text.trim(),
          start: range && range.start,
          end: range && range.end,
          allDay: form.allDay,
          location: (form.location || '').trim() || (draft && draft.location),
          locationLat: (form.location || '').trim() ? form.locationLat : null,
          locationLng: (form.location || '').trim() ? form.locationLng : null,
          calendarId: form.calendarId != null ? Number(form.calendarId) : undefined,
          // Carry the parsed people across; the editor resolves them to chips
          // and asks about any that aren't in the directory yet.
          personNames: (draft && draft.personNames) || [],
        },
        nlText: touchedRef.current.size === 0 ? text : '',
      },
    });
  };

  const localCals = state.calendars.filter((c) => c.editable);
  const flashCls = (f) => (flash && flash.has(f) ? ' bc-nl-applied' : '');
  const canCreate = (!busy && draft && draft.intent === 'availability') || (!busy && !!((draft && draft.title) || text.trim()) &&
    !!buildRange(form, draft, touchedRef.current.has('dateKey')) && form.calendarId != null);

  return html`<div class="bc-quickadd-wrap">
    <div class="bc-quickadd" ref=${cardRef} role="dialog" aria-label="Quick add event" onKeyDown=${onCardKeyDown}>
      <input
        ref=${inputRef}
        class="bc-quickadd-input"
        placeholder="Dinner with Sam next Thursday 7pm at Zuni"
        value=${text}
        onInput=${onInput}
        aria-label="Describe the event"
      />
      ${draft && draft.intent === 'availability' && html`<div class="bc-quickadd-preview">
        <span class="bc-qchip bc-qchip-title">${draft.personName}</span>
        <span class="bc-qchip bc-away-pill is-${draft.kind}">${draft.kind}</span>
        <span class="bc-qchip">${fmtAvailRange(draft)}</span>
        <span class="bc-qchip bc-qchip-fallback">records availability, not an event</span>
      </div>`}
      ${draft && draft.intent !== 'availability' && html`<div class="bc-quickadd-preview">
        <span class="bc-qchip bc-qchip-title">${draft.title || '(untitled)'}</span>
        ${draft.location && html`<span class="bc-qchip">@ ${draft.location}</span>`}
        ${draft.personNames && draft.personNames.map((n) => html`<span
          key=${n}
          class=${'bc-qchip' + (knownPerson(n) ? '' : ' bc-qchip-newperson')}
          title=${knownPerson(n) ? 'In your people' : 'Not in your people yet — you will be asked'}
        >with ${n}</span>`)}
        ${draft.source === 'fallback' && html`<span class="bc-qchip bc-qchip-fallback" title="Parsed without the language model">basic parse</span>`}
      </div>`}
      ${(!draft || draft.intent !== 'availability') && html`<div class="bc-quickadd-strip">
        <label class=${'bc-qa-field' + flashCls('dateKey')}>
          <span class="bc-qa-label">Date</span>
          <input
            type="date" class="bc-qa-date" value=${form.dateKey}
            onInput=${(e) => touch('dateKey', e.target.value)}
            aria-label="Date"
          />
        </label>
        ${!form.allDay && html`<label class=${'bc-qa-field' + flashCls('startTime')}>
          <span class="bc-qa-label">Start</span>
          <input
            type="time" class="bc-qa-time" value=${form.startTime}
            onInput=${(e) => touch('startTime', e.target.value)}
            aria-label="Start time"
          />
        </label>
        <span class="bc-qa-to" aria-hidden="true">to</span>
        <label class=${'bc-qa-field' + flashCls('endTime')}>
          <span class="bc-qa-label">End</span>
          <input
            type="time" class="bc-qa-time" value=${form.endTime}
            onInput=${(e) => touch('endTime', e.target.value)}
            aria-label="End time"
          />
        </label>`}
        <label class=${'bc-check bc-qa-allday' + flashCls('allDay')}>
          <input
            type="checkbox" checked=${form.allDay}
            onChange=${(e) => touch('allDay', e.target.checked)}
          />
          <span>All day</span>
        </label>
        <label class=${'bc-qa-field bc-qa-locfield' + flashCls('location')}>
          <span class="bc-qa-label">Where</span>
          <${PlaceInput}
            compact
            value=${form.location}
            ariaLabel="Location"
            placeholder="Optional"
            inputClass="bc-qa-loc"
            tz=${localTz()}
            onText=${(v) => {
              touchedRef.current.add('location');
              setForm((f) => {
                const nf = { ...f, location: v, locationLat: null, locationLng: null };
                formRef.current = nf;
                return nf;
              });
            }}
            onPick=${(r) => {
              touchedRef.current.add('location');
              setForm((f) => {
                const nf = { ...f, location: pickFillText(r), locationLat: r.lat, locationLng: r.lng };
                formRef.current = nf;
                return nf;
              });
            }}
          />
        </label>
        <label class=${'bc-qa-field bc-qa-calfield' + flashCls('calendarId')}>
          <span class="bc-qa-label">Calendar</span>
          <select
            class="bc-qa-cal" value=${form.calendarId}
            onChange=${(e) => touch('calendarId', Number(e.target.value))}
            aria-label="Calendar"
          >${localCals.map((c) => html`<option key=${c.id} value=${c.id}>${c.name}</option>`)}</select>
        </label>
      </div>`}
      <div class="bc-quickadd-actions">
        <button type="button" class="bc-btn bc-btn-primary" disabled=${!canCreate} onClick=${submit}>Create</button>
        <button type="button" class="bc-btn" onClick=${cancel}>Cancel</button>
        <button type="button" class="bc-link-btn bc-quickadd-expand" onClick=${openFullEditor}>More options</button>
        <span class="bc-quickadd-hint">Enter to create, Esc to cancel</span>
      </div>
    </div>
  </div>`;
}
