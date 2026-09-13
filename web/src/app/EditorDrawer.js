// EditorDrawer: full event editor with duration lock, all-day, tags, and an
// RRULE builder (daily / weekly-on-days / monthly / yearly / custom interval,
// ends: until date (default 12 weeks out) / count / never).
// Create mode adds an NL assist input at the top: typing there debounce-parses
// via /quickadd (400ms) and live-fills title/start/end/allDay/location below,
// with a brief flash on the fields it touched. Fields stay fully editable.

import { html, useState, useEffect, useRef } from '../../vendor/index.js';
import { useStore, set, state, toast } from './store.js';
import { createEvent, updateEvent, deleteEvent, quickAddParse, attachToTrip, defaultTargetCalendarId } from './actions.js';
import { api, loadPeople, ensureFullOccurrence } from './api.js';
import { saveEditorDraft, clearEditorDraft, discardEditorWithUndo } from './drafts.js';
import { TripRow } from './Trips.js';
import { trapFocus } from '../ui/DayExpand.js';
import { PlaceInput, pickFillText } from './PlaceInput.js';
import { PeopleInput } from './PeopleInput.js';
import { RichText } from './RichText.js';
import { Icon } from '../ui/icons.js';
import { isEmptyHtml } from '../lib/richtext.js';
import {
  parseISO, toInputValue, fromInputValue, toISOWithOffset, addDaysDate, pad, localTz,
  dateOfDayKey, eventDuration,
} from '../lib/dates.js';
import {
  TIMED_CHOICES, ALLDAY_CHOICES, REMINDER_UNITS, fmtOffsetMinutes, fmtReminder,
  entryToMinutes, normalizeMinutesList, effectiveReminders, toMinutes,
} from '../lib/reminders.js';

const BYDAY = [['MO', 'Mon'], ['TU', 'Tue'], ['WE', 'Wed'], ['TH', 'Thu'], ['FR', 'Fri'], ['SA', 'Sat'], ['SU', 'Sun']];

// Occurrence payloads and NL drafts carry people as plain names; the editor
// works in {id, name} chips so it can save by id. Resolve each name against the
// directory (loaded before first paint, so this is reliable). A name that
// matches nobody becomes a proposal with a null id — PeopleInput asks before
// anything is created, and submit refuses to guess.
function toPeopleChips(names) {
  return (names || [])
    .map((n) => String(n == null ? '' : n).trim())
    .filter((name) => name !== '')
    .map((name) => {
      const hit = state.people.find((p) => p.name.toLowerCase() === name.toLowerCase());
      return hit ? { id: hit.id, name: hit.name } : { id: null, name };
    });
}

function buildRrule(r) {
  if (!r || r.freq === 'none') return null;
  const parts = ['FREQ=' + r.freq];
  if (r.interval > 1) parts.push('INTERVAL=' + r.interval);
  if (r.freq === 'WEEKLY' && r.byday && r.byday.length) parts.push('BYDAY=' + r.byday.join(','));
  if (r.ends === 'until' && r.until) {
    const d = new Date(r.until + 'T23:59:59');
    parts.push('UNTIL=' + d.getUTCFullYear() + pad(d.getUTCMonth() + 1) + pad(d.getUTCDate()) + 'T' + pad(d.getUTCHours()) + pad(d.getUTCMinutes()) + '00Z');
  } else if (r.ends === 'count' && r.count > 0) {
    parts.push('COUNT=' + r.count);
  }
  return parts.join(';');
}

function parseRrule(rrule) {
  const r = { freq: 'none', interval: 1, byday: [], ends: 'never', until: '', count: 10 };
  if (!rrule) return r;
  for (const part of rrule.split(';')) {
    const [k, v] = part.split('=');
    if (k === 'FREQ') r.freq = v;
    if (k === 'INTERVAL') r.interval = Number(v) || 1;
    if (k === 'BYDAY') r.byday = v.split(',');
    if (k === 'COUNT') { r.ends = 'count'; r.count = Number(v) || 10; }
    if (k === 'UNTIL') {
      r.ends = 'until';
      r.until = v.slice(0, 4) + '-' + v.slice(4, 6) + '-' + v.slice(6, 8);
    }
  }
  if (r.freq !== 'none' && r.ends === 'until' && !r.until) r.ends = 'never';
  return r;
}

// Smart default: bounded 12 weeks out rather than never-ending.
function defaultUntil(startDate) {
  const d = addDaysDate(startDate, 12 * 7);
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

export function EditorDrawer() {
  const editor = useStore((s) => s.editor);
  const panelRef = useRef(null);

  const [form, setForm] = useState(null);
  const [durationLock, setDurationLock] = useState(true);
  const [scope, setScope] = useState('this');
  // Custom reminder entry (number + unit) revealed by the Custom option.
  const [remCustom, setRemCustom] = useState(null); // {n, unit} | null

  // Dirty tracking baseline (JSON of the initial form; NL text counts too).
  const initialSnapRef = useRef(null);

  // NL assist (create mode): debounce-parse, race-guard, flash filled fields.
  const [nlText, setNlText] = useState('');
  const [nlFlash, setNlFlash] = useState(null); // Set of field names, or null
  const nlTimer = useRef(0);
  const nlReq = useRef(0);
  const flashTimer = useRef(0);

  const applyNlDraft = (d) => {
    if (!d) return;
    const touched = [];
    setForm((f) => {
      if (!f) return f;
      const nf = { ...f };
      if (d.title) { nf.title = d.title; touched.push('title'); }
      if (d.start) { nf.start = toInputValue(parseISO(d.start)); touched.push('start'); }
      if (d.end) { nf.end = toInputValue(parseISO(d.end)); touched.push('end'); }
      if (d.allDay != null) { nf.allDay = !!d.allDay; touched.push('allDay'); }
      if (d.location) {
        nf.location = d.location;
        // Parsed free text: stale picked coordinates no longer apply.
        nf.locationLat = null;
        nf.locationLng = null;
        touched.push('location');
      }
      if (d.personNames && d.personNames.length) {
        nf.people = toPeopleChips(d.personNames);
        touched.push('people');
      }
      return nf;
    });
    if (touched.length) {
      setNlFlash(new Set(touched));
      clearTimeout(flashTimer.current);
      flashTimer.current = setTimeout(() => setNlFlash(null), 900);
    }
  };

  const runNlParse = async (v) => {
    if (!v.trim()) return;
    const id = ++nlReq.current;
    try {
      const d = await quickAddParse(v);
      if (id === nlReq.current) applyNlDraft(d);
    } catch { /* assist is best-effort */ }
  };

  const onNlInput = (e) => {
    const v = e.target.value;
    setNlText(v);
    clearTimeout(nlTimer.current);
    if (!v.trim()) return;
    nlTimer.current = setTimeout(() => runNlParse(v), 400);
  };

  // Enter parses immediately instead of submitting the half-filled form; the
  // debounce (and its possible LLM round-trip) shouldn't gate quick entry.
  const onNlKeyDown = (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      clearTimeout(nlTimer.current);
      runNlParse(nlText);
    }
  };

  useEffect(() => () => { clearTimeout(nlTimer.current); clearTimeout(flashTimer.current); }, []);

  // The rich-text editor rewrites the description into its own canonical
  // markup as it loads (an empty one becomes "<div><br></div>"). Take that
  // value AND move the dirty baseline with it: the editor tidying its own
  // seed is not something the user typed, and counting it as an edit made
  // opening an event and closing it ask to discard changes.
  const adoptSeededDescription = (htmlValue) => {
    if (initialSnapRef.current != null) {
      try {
        const snap = JSON.parse(initialSnapRef.current);
        snap.description = htmlValue;
        initialSnapRef.current = JSON.stringify(snap);
      } catch { /* malformed baseline: leave it, worst case is one prompt */ }
    }
    setForm((f) => (f && f.description !== htmlValue ? { ...f, description: htmlValue } : f));
  };

  // Keep state.editorDirty current, and keep a dirty form durable: written
  // to sessionStorage as it changes, cleared when it is not dirty, saved, or
  // deliberately dropped. That is what lets closing be undoable and a
  // version reload be safe (drafts.js).
  useEffect(() => {
    if (!form || initialSnapRef.current == null) return;
    const dirty = JSON.stringify(form) !== initialSnapRef.current
      || (nlText || '').trim() !== ((editor && editor.nlText) || '').trim();
    if (dirty !== state.editorDirty) set({ editorDirty: dirty });
    if (dirty) saveEditorDraft({ editor, form, nlText, initialSnap: initialSnapRef.current });
    else clearEditorDraft();
  }, [form, nlText]); // eslint-disable-line

  // Scheduling assist (docs/design-availability.md §4): when the event's
  // people are away/busy during its time, warn inline under the People
  // field. Best-effort and debounced; never blocks saving.
  const [availWarn, setAvailWarn] = useState([]);
  const availTimer = useRef(0);
  const availReq = useRef(0);
  const warnPeople = form ? (form.people || []).map((c) => c.name) : [];
  const warnStart = form ? form.start : '';
  const warnEnd = form ? form.end : '';
  useEffect(() => {
    clearTimeout(availTimer.current);
    if (warnPeople.length === 0 || !warnStart || !warnEnd) { setAvailWarn([]); return undefined; }
    availTimer.current = setTimeout(async () => {
      const id = ++availReq.current;
      try {
        const s = fromInputValue(warnStart);
        const e = fromInputValue(warnEnd);
        if (isNaN(s) || isNaN(e)) return;
        const d = await api('/availability/check?' + new URLSearchParams({
          start: toISOWithOffset(s), end: toISOWithOffset(e), names: warnPeople.join(','),
        }));
        if (id === availReq.current) setAvailWarn((d && d.conflicts) || []);
      } catch { /* assist only */ }
    }, 300);
    return () => clearTimeout(availTimer.current);
  }, [warnPeople.join('|'), warnStart, warnEnd]); // eslint-disable-line

  useEffect(() => {
    if (!editor) { setForm(null); return; }
    const occ = editor.occ;
    // The window carries the grid's fields only; description, cadence,
    // reminders and zone seed from the single-event record (#15). Fetch it
    // BEFORE seeding rather than seeding partial and re-seeding when it
    // lands: that would flip fields under the user's cursor and move the
    // dirty baseline. Opened from the popover or detail view the record is
    // already merged and this is a no-op. If the fetch fails (offline), seed
    // from what the window had, once, rather than never opening.
    if (occ && !occ.full && !occ._detailFailed) {
      setForm(null);
      let alive = true;
      ensureFullOccurrence(occ.instanceId).then((full) => {
        if (!alive || state.editor !== editor) return;
        set({ editor: { ...editor, occ: full && full.full ? full : { ...occ, _detailFailed: true } } });
      });
      return () => { alive = false; };
    }
    const draft = editor.draft || {};
    // All-day occurrences carry literal dates at +00:00; parsing them as
    // instants would land a day early west of UTC (saving then actually
    // moved the event back a day). Anchor them to local midnight instead.
    const anchor = (iso, allDay) => (allDay ? dateOfDayKey(iso.slice(0, 10)) : parseISO(iso));
    const start = occ ? anchor(occ.start, occ.allDay) : (draft.start ? anchor(draft.start, !!draft.allDay) : new Date());
    const end = occ ? anchor(occ.end, occ.allDay) : (draft.end ? anchor(draft.end, !!draft.allDay) : new Date(start.getTime() + 3600000));
    const initial = {
      title: occ ? occ.title : (draft.title || ''),
      // New events land on the draft's calendar, else the user's default
      // calendar (settings), else the first local calendar.
      calendarId: occ ? occ.calendarId : (draft.calendarId || defaultTargetCalendarId()),
      start: toInputValue(start),
      end: toInputValue(end),
      allDay: occ ? !!occ.allDay : !!draft.allDay,
      isContainer: occ ? !!occ.isContainer : !!draft.isContainer,
      location: occ ? (occ.location || '') : (draft.location || ''),
      locationLat: occ ? (occ.locationLat != null ? occ.locationLat : null)
        : (draft.locationLat != null ? draft.locationLat : null),
      locationLng: occ ? (occ.locationLng != null ? occ.locationLng : null)
        : (draft.locationLng != null ? draft.locationLng : null),
      url: occ ? (occ.url || '') : '',
      description: occ ? (occ.description || '') : (draft.description || ''),
      tags: occ && occ.tags ? occ.tags.join(', ') : '',
      people: toPeopleChips(occ ? (occ.people || []) : (draft.personNames || [])),
      rrule: parseRrule(occ
        ? (occ.recurring ? (occ.rrule || editor.rrule || '') : '')
        : (draft.rrule || '')),
      // null = inherit calendar/global defaults; a list = explicit override
      // (minutes before start; [] = no reminders). remInitial detects changes.
      reminders: occ && occ.reminderSource === 'event'
        ? (occ.reminders || []).map((r) => Number(r.minutes) || 0)
        : null,
      remInitial: occ && occ.reminderSource === 'event'
        ? (occ.reminders || []).map((r) => Number(r.minutes) || 0).join(',')
        : null,
    };
    setForm(initial);
    initialSnapRef.current = JSON.stringify(initial);
    set({ editorDirty: false });
    // Putting a draft back (Undo after a close, or after a reload): the form
    // as it was, against the ORIGINAL baseline so it is still dirty.
    if (editor.restore && editor.restore.form) {
      setForm(editor.restore.form);
      if (editor.restore.initialSnap) initialSnapRef.current = editor.restore.initialSnap;
      set({ editorDirty: true });
    }
    setScope('this');
    setDurationLock(true);
    setRemCustom(null);
    setNlText((editor.restore && editor.restore.nlText) || editor.nlText || '');
    setNlFlash(null);
    nlReq.current++; // void any in-flight parse from a previous open
    // Transferred from quick add: re-parse the carried text once so the form
    // reflects anything typed after the bar's last debounce fired.
    if (!editor.occ && editor.nlText && editor.nlText.trim()) {
      const id = ++nlReq.current;
      quickAddParse(editor.nlText)
        .then((d) => { if (id === nlReq.current) applyNlDraft(d); })
        .catch(() => { /* assist is best-effort */ });
    }
  }, [editor]);

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Tab') trapFocus(panelRef.current, e); };
    if (editor) document.addEventListener('keydown', onKey, true);
    return () => document.removeEventListener('keydown', onKey, true);
  }, [editor]);

  if (!editor || !form) return null;

  const occ = editor.occ;
  const upd = (patch) => setForm((f) => ({ ...f, ...patch }));

  // Dirty tracking: any change to the form (or typed NL text) arms a
  // discard-confirm on every close path, including the global Esc (which
  // reads state.editorDirty in closeOverlays).
  const requestClose = () => {
    if (state.editorDirty) { discardEditorWithUndo(); return; }
    set({ editor: null, editorDirty: false });
  };

  const onStartChange = (v) => {
    if (durationLock) {
      const oldS = fromInputValue(form.start);
      const oldE = fromInputValue(form.end);
      const dur = oldE - oldS;
      const ns = fromInputValue(v);
      if (!isNaN(ns) && !isNaN(dur)) {
        upd({ start: v, end: toInputValue(new Date(ns.getTime() + dur)) });
        return;
      }
    }
    upd({ start: v });
  };

  const updRrule = (patch) => {
    setForm((f) => {
      const r = { ...f.rrule, ...patch };
      if (patch.freq && patch.freq !== 'none' && r.ends === 'until' && !r.until) {
        r.until = defaultUntil(fromInputValue(f.start) || new Date());
      }
      return { ...f, rrule: r };
    });
  };

  const submit = async (e) => {
    e.preventDefault();
    const s = fromInputValue(form.start);
    const en = fromInputValue(form.end);
    if (isNaN(s) || isNaN(en) || en <= s) return;
    // A name nobody has confirmed as a person yet. Saving would have to either
    // invent the person or drop the name silently; ask instead. The People
    // field already shows the prompt, so just point at it.
    const unconfirmed = form.people.find((c) => c.id == null);
    if (unconfirmed) {
      toast(`Add ${unconfirmed.name} to your people, or remove the name, before saving`);
      setNlFlash(new Set(['people']));
      clearTimeout(flashTimer.current);
      flashTimer.current = setTimeout(() => setNlFlash(null), 1400);
      return;
    }
    const fields = {
      title: form.title,
      calendarId: Number(form.calendarId),
      start: toISOWithOffset(s),
      end: toISOWithOffset(en),
      allDay: form.allDay,
      location: form.location || null,
      // Picked coordinates persist directly; free-typed text sends null so
      // stale coordinates clear and the lazy geocode re-resolves later.
      locationLat: form.location ? form.locationLat : null,
      locationLng: form.location ? form.locationLng : null,
      url: form.url || null,
      description: isEmptyHtml(form.description) ? null : form.description,
      tagNames: form.tags.split(',').map((t) => t.trim()).filter(Boolean),
      // Ids, never names: a name-keyed save can't tell a new person from a
      // stale copy of a renamed one, and resolves that ambiguity by creating a
      // duplicate. Proposed chips (id null) are blocked above, so every chip
      // here is a directory person the user confirmed.
      personIds: form.people.map((c) => c.id),
      rrule: buildRrule(form.rrule),
    };
    // Only send reminders when the override actually changed, so unrelated
    // edits never clobber an inherited default with a snapshot of it.
    const remNow = form.reminders === null ? null : [...form.reminders].sort((a, b) => a - b).join(',');
    if (remNow !== form.remInitial) {
      fields.reminders = form.reminders === null ? null : normalizeMinutesList(form.reminders);
    }
    // Trip flag: sent only when it changes (clearing it on a trip that still
    // has attached events gets the server's trip_has_members refusal, which
    // updateEvent turns into a helpful toast).
    if (occ ? !!occ.isContainer !== form.isContainer : form.isContainer) {
      fields.isContainer = form.isContainer;
    }
    let ok;
    if (occ) {
      ok = await updateEvent(occ, fields, scope);
    } else {
      const created = await createEvent(fields);
      ok = !!created;
      // "New event in this trip": auto-attach the fresh event to its trip.
      if (created && editor.attachTrip) await attachToTrip(editor.attachTrip, created);
    }
    if (ok) {
      // New people linked via this save should appear in the sidebar folder
      // and autocomplete right away.
      if ((fields.personIds || []).length > 0) loadPeople().catch(() => {});
      clearEditorDraft();
      set({ editor: null, editorDirty: false });
    }
  };

  const r = form.rrule;
  const duration = eventDuration(form);

  // --- reminders row --------------------------------------------------------
  const selectedCal = state.calendars.find((c) => c.id === Number(form.calendarId));
  const remEff = form.reminders !== null
    ? { reminders: form.reminders.map((m) => ({ minutes: m })), source: 'event' }
    : effectiveReminders({
        override: null,
        calendarDefaults: selectedCal ? selectedCal.reminderDefaults : null,
        settings: state.settings,
        allDay: form.allDay,
        calendarKind: selectedCal ? selectedCal.kind : 'local',
      });
  const remSrcHint = remEff.source === 'calendar' ? 'from calendar default'
    : remEff.source === 'default' ? 'from your defaults' : 'custom for this event';
  const remChoices = form.allDay
    ? ALLDAY_CHOICES
    : TIMED_CHOICES.map((m) => ({ label: fmtOffsetMinutes(m), minutes: m }));
  const remToOverride = () => (form.reminders !== null
    ? [...form.reminders]
    : remEff.reminders.map(entryToMinutes));
  const remAdd = (m) => upd({ reminders: [...new Set([...remToOverride(), m])].sort((a, b) => a - b) });
  const remRemove = (i) => {
    const list = remToOverride();
    list.splice(i, 1);
    upd({ reminders: list });
  };

  return html`<div class="bc-drawer-backdrop" onClick=${(e) => { if (e.target === e.currentTarget) requestClose(); }}>
    <form class="bc-drawer" ref=${panelRef} onSubmit=${submit} role="dialog" aria-modal="true" aria-label=${(occ ? 'Edit ' : 'New ') + (form.isContainer ? 'trip' : 'event')}>
      <div class="bc-drawer-head">
        <h2>${(occ ? 'Edit ' : 'New ') + (form.isContainer ? 'trip' : 'event')}</h2>
        <button type="button" class="bc-icon-btn" aria-label="Close" onClick=${requestClose}><${Icon} name="close" size=${15} /></button>
      </div>

      ${!occ && html`<div class="bc-nl">
        <div class="bc-nl-row">
          <input
            class="bc-nl-input"
            placeholder="Type it naturally: Lunch with Ada Friday noon at Zuni"
            value=${nlText}
            onInput=${onNlInput}
            onKeyDown=${onNlKeyDown}
            autofocus=${!!editor.nlText}
            aria-label="Describe the event in plain language"
          />
          <button type="button" class="bc-btn bc-nl-go" title="Fill the fields now" onClick=${() => { clearTimeout(nlTimer.current); runNlParse(nlText); }}>Fill</button>
        </div>
        <span class="bc-nl-hint">Fills the fields below as you type — Enter or Fill applies immediately; everything stays editable</span>
      </div>`}

      <label class=${'bc-field' + (nlFlash && nlFlash.has('title') ? ' bc-nl-applied' : '')}>
        <span>Title</span>
        <input value=${form.title} onInput=${(e) => upd({ title: e.target.value })} required autofocus=${!editor.nlText} />
      </label>

      <label class="bc-field">
        <span>Calendar</span>
        <select value=${form.calendarId} onChange=${(e) => upd({ calendarId: e.target.value })}>
          ${state.calendars.filter((c) => c.kind !== 'subscribed').map((c) => html`<option key=${c.id} value=${c.id}>${c.name}</option>`)}
        </select>
      </label>

      <div class="bc-field-row">
        <label class=${'bc-field' + (nlFlash && nlFlash.has('start') ? ' bc-nl-applied' : '')}>
          <span>Start</span>
          <input type="datetime-local" value=${form.start} onInput=${(e) => onStartChange(e.target.value)} required />
        </label>
        <button
          type="button"
          class="bc-lock-btn${durationLock ? ' is-on' : ''}"
          aria-pressed=${durationLock}
          aria-label="Lock duration"
          title=${durationLock ? 'Duration locked: moving start moves end' : 'Duration unlocked: ends edit independently'}
          onClick=${() => setDurationLock(!durationLock)}
        ><${Icon} name=${durationLock ? 'lock' : 'unlock'} size=${13} /></button>
        <label class=${'bc-field' + (nlFlash && nlFlash.has('end') ? ' bc-nl-applied' : '')}>
          <span>End</span>
          <input type="datetime-local" value=${form.end} onInput=${(e) => upd({ end: e.target.value })} required />
        </label>
        <label class=${'bc-check' + (nlFlash && nlFlash.has('allDay') ? ' bc-nl-applied' : '')}>
          <input type="checkbox" checked=${form.allDay} onChange=${(e) => upd({ allDay: e.target.checked })} />
          <span>All day</span>
        </label>
      </div>
      <div class="bc-duration-exact" role="status">${duration ? duration.exact + ' total' : ''}</div>

      <div class="bc-field-row">
        <label class=${'bc-field grow' + (nlFlash && nlFlash.has('location') ? ' bc-nl-applied' : '')}>
          <span>Location</span>
          <${PlaceInput}
            value=${form.location}
            ariaLabel="Location"
            tz=${occ ? occ.tzid : localTz()}
            onText=${(v) => upd({ location: v, locationLat: null, locationLng: null })}
            onPick=${(r) => upd({ location: pickFillText(r), locationLat: r.lat, locationLng: r.lng })}
          />
        </label>
        <label class="bc-field grow">
          <span>URL</span>
          <input type="url" value=${form.url} onInput=${(e) => upd({ url: e.target.value })} />
        </label>
      </div>

      <div class="bc-field">
        <span>Description</span>
        <${RichText}
          seed=${occ ? (occ.description || '') : (editor.draft && editor.draft.description) || ''}
          seedKey=${occ ? occ.instanceId : 'new'}
          ariaLabel="Description"
          onChange=${(htmlValue, meta) => (meta && meta.seeded
            ? adoptSeededDescription(htmlValue)
            : upd({ description: htmlValue }))}
        />
      </div>

      <div class="bc-field-row">
        <div class=${'bc-field grow'
          + (form.people.some((c) => c.id == null) ? ' bc-field-claims-row' : '')
          + (nlFlash && nlFlash.has('people') ? ' bc-nl-applied' : '')}>
          <span>People</span>
          <${PeopleInput}
            value=${form.people}
            ariaLabel="People at this event"
            onChange=${(list) => upd({ people: list })}
          />
        </div>
        <label class="bc-field grow">
          <span>Tags (comma separated)</span>
          <input value=${form.tags} onInput=${(e) => upd({ tags: e.target.value })} />
        </label>
      </div>
      ${availWarn.length > 0 && html`<div class="bc-avail-warn" role="status">
        ${availWarn.map((c) => html`<span key=${c.id}><${Icon} name="warning" size=${12} /> ${c.name} is ${c.kind} then${c.note ? ' (' + c.note + ')' : ''}</span>`)}
      </div>`}

      <fieldset class="bc-trip-fieldset">
        <legend>Trip</legend>
        <label class="bc-check" title="A trip is a span of days that other events happen inside">
          <input type="checkbox" checked=${form.isContainer} onChange=${(e) => upd({ isContainer: e.target.checked })} />
          <span>This event is itself a trip (contains other events)</span>
        </label>
        ${occ && !occ.isContainer && !form.isContainer && html`<${TripRow} occ=${occ} />`}
      </fieldset>

      <fieldset class="bc-rem">
        <legend>Reminders</legend>
        <div class="bc-rem-row">
          ${remEff.reminders.length === 0 && html`<span class="bc-rem-none">None</span>`}
          ${remEff.reminders.map((entry, i) => html`<span key=${i + ':' + fmtReminder(entry)} class="bc-rem-chip">
            <${Icon} name="bell" size=${11} /> ${fmtReminder(entry)}
            <button type="button" class="bc-rem-x" aria-label=${'Remove reminder: ' + fmtReminder(entry)} onClick=${() => remRemove(i)}><${Icon} name="close" size=${10} /></button>
          </span>`)}
          <select class="bc-rem-add" aria-label="Add reminder" value=""
            onChange=${(e) => {
              const v = e.target.value;
              e.target.value = '';
              if (v === 'custom') setRemCustom({ n: 1, unit: 'hours' });
              else if (v !== '') remAdd(Number(v));
            }}>
            <option value="">+ Add reminder</option>
            ${remChoices.map((c) => html`<option key=${c.minutes} value=${String(c.minutes)}>${c.label}</option>`)}
            <option value="custom">Custom</option>
          </select>
          ${remCustom && html`<span class="bc-rem-custom">
            <input class="bc-num" type="number" min="0" max="40320" aria-label="Custom reminder amount"
              value=${remCustom.n}
              onInput=${(e) => setRemCustom({ ...remCustom, n: Number(e.target.value) || 0 })} />
            <select aria-label="Custom reminder unit" value=${remCustom.unit}
              onChange=${(e) => setRemCustom({ ...remCustom, unit: e.target.value })}>
              ${REMINDER_UNITS.map((u) => html`<option key=${u} value=${u}>${u}</option>`)}
            </select>
            <button type="button" class="bc-btn"
              onClick=${() => { remAdd(toMinutes(remCustom.n, remCustom.unit)); setRemCustom(null); }}>Add</button>
            <button type="button" class="bc-icon-btn" aria-label="Cancel custom reminder"
              onClick=${() => setRemCustom(null)}><${Icon} name="close" size=${10} /></button>
          </span>`}
          ${form.reminders !== null && html`<button type="button" class="bc-btn bc-rem-reset" onClick=${() => upd({ reminders: null })}>Reset to default</button>`}
          <span class="bc-rem-src">${remSrcHint}</span>
        </div>
      </fieldset>

      <fieldset class="bc-rrule">
        <legend>Repeat</legend>
        <div class="bc-field-row">
          <select value=${r.freq} onChange=${(e) => updRrule({ freq: e.target.value })} aria-label="Repeat frequency">
            <option value="none">Does not repeat</option>
            <option value="DAILY">Daily</option>
            <option value="WEEKLY">Weekly</option>
            <option value="MONTHLY">Monthly</option>
            <option value="YEARLY">Yearly</option>
          </select>
          ${r.freq !== 'none' && html`<label class="bc-check">
            <span>every</span>
            <input class="bc-num" type="number" min="1" max="99" value=${r.interval} onInput=${(e) => updRrule({ interval: Number(e.target.value) || 1 })} aria-label="Interval" />
            <span>${r.freq === 'DAILY' ? 'day(s)' : r.freq === 'WEEKLY' ? 'week(s)' : r.freq === 'MONTHLY' ? 'month(s)' : 'year(s)'}</span>
          </label>`}
        </div>
        ${r.freq === 'WEEKLY' && html`<div class="bc-byday">
          ${BYDAY.map(([code, label]) => html`<label key=${code} class="bc-byday-day${r.byday.includes(code) ? ' is-on' : ''}">
            <input
              type="checkbox"
              checked=${r.byday.includes(code)}
              onChange=${(e) => updRrule({ byday: e.target.checked ? [...r.byday, code] : r.byday.filter((d) => d !== code) })}
            />${label}
          </label>`)}
        </div>`}
        ${r.freq !== 'none' && html`<div class="bc-field-row">
          <select value=${r.ends} onChange=${(e) => updRrule({ ends: e.target.value, until: e.target.value === 'until' && !r.until ? defaultUntil(fromInputValue(form.start) || new Date()) : r.until })} aria-label="Ends">
            <option value="never">Never</option>
            <option value="until">Until date</option>
            <option value="count">After N times</option>
          </select>
          ${r.ends === 'until' && html`<input type="date" value=${r.until} onInput=${(e) => updRrule({ until: e.target.value })} aria-label="Until date" />`}
          ${r.ends === 'count' && html`<input class="bc-num" type="number" min="1" max="999" value=${r.count} onInput=${(e) => updRrule({ count: Number(e.target.value) || 1 })} aria-label="Occurrence count" />`}
        </div>`}
      </fieldset>

      ${occ && occ.recurring && html`<label class="bc-field">
        <span>Apply to</span>
        <select value=${scope} onChange=${(e) => setScope(e.target.value)}>
          <option value="this">This event only</option>
          <option value="following">This and following</option>
          <option value="all">All events in series</option>
        </select>
      </label>`}

      <div class="bc-drawer-actions">
        <button type="submit" class="bc-btn bc-btn-primary">${occ ? 'Save' : 'Create'}</button>
        ${occ && html`<button type="button" class="bc-btn bc-btn-danger" onClick=${() => deleteEvent(occ, scope)}>Delete</button>`}
        <button type="button" class="bc-btn" onClick=${requestClose}>Cancel</button>
      </div>
    </form>
  </div>`;
}
