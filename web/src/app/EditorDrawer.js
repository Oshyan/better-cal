// EditorDrawer: full event editor with duration lock, all-day, tags, and an
// RRULE builder (daily / weekly-on-days / monthly / yearly / custom interval,
// ends: until date (default 12 weeks out) / count / never).
// Create mode adds an NL assist input at the top: typing there debounce-parses
// via /quickadd (400ms) and live-fills title/start/end/allDay/location below,
// with a brief flash on the fields it touched. Fields stay fully editable.

import { html, useState, useEffect, useRef, useMemo } from '../../vendor/index.js';
import { useStore, set, state, toast } from './store.js';
import { createEvent, updateEvent, deleteEvent, quickAddParse, attachToTrip, defaultTargetCalendarId, googleCalendar, editorReturn } from './actions.js';
import { api, loadPeople, ensureFullOccurrence } from './api.js';
import { saveEditorDraft, clearEditorDraft, discardEditorWithUndo } from './drafts.js';
import { TripRow } from './Trips.js';
import { trapFocus } from '../ui/DayExpand.js';
import { PlaceInput, pickFillText, calendarPlaceNear } from './PlaceInput.js';
import { PeopleInput } from './PeopleInput.js';
import { RichText } from './RichText.js';
import { Icon } from '../ui/icons.js';
import { isEmptyHtml } from '../lib/richtext.js';
import {
  parseISO, toInputValue, fromInputValue, toISOWithOffset, addDaysDate, pad, localTz,
  dateOfDayKey, eventDuration, instantFromWallTime, wallTimeInZone, sameClock, tzCity, tzOffsetLabel, zoneOptions, fmtRange,
  allDayFields, allDayInputs, addDaysKey, diffDaysKey, fmtTime, fmtWeekdayShort, todayKey, untilDayKey,
} from '../lib/dates.js';
import {
  TIMED_CHOICES, ALLDAY_CHOICES, REMINDER_UNITS, fmtOffsetMinutes, fmtReminder,
  entryToMinutes, normalizeMinutesList, effectiveReminders, toMinutes,
} from '../lib/reminders.js';
import { CalendarSelect } from '../ui/CalendarSelect.js';
import { PHONE_QUERY } from '../lib/breakpoints.js';
import { DateField, TimeField } from './WhenFields.js';
import { parseClockText, resolveClock, minsToHHMM, hhmmToMins, durationLabel } from '../lib/whenparse.js';

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

function buildRrule(r, allDay = false) {
  if (!r || r.freq === 'none') return null;
  const parts = ['FREQ=' + r.freq];
  if (r.interval > 1) parts.push('INTERVAL=' + r.interval);
  if (r.freq === 'WEEKLY' && r.byday && r.byday.length) parts.push('BYDAY=' + r.byday.join(','));
  if (r.ends === 'until' && r.until && allDay) {
    // A date series ends on a date (RFC 5545: UNTIL takes DTSTART's type).
    parts.push('UNTIL=' + r.until.replace(/-/g, ''));
  } else if (r.ends === 'until' && r.until) {
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
      r.until = untilDayKey(v);
    }
  }
  if (r.freq !== 'none' && r.ends === 'until' && !r.until) r.ends = 'never';
  return r;
}

// Start and End as instants. The fields are wall-clock readings; they are read
// on this device's clock unless the owner picked a zone (form.tzTouched), in
// which case "3:00 PM" means 3:00 PM there. Untouched stays on the exact old
// path, so drafts saved before the zone picker existed behave as they did.
function formInstants(form) {
  if (form.allDay) {
    // Whole days at local midnight, end exclusive, whatever times the fields
    // held before All day was ticked.
    const f = allDayFields(form.start, form.end);
    const v = allDayInputs(f.start, f.end);
    return [fromInputValue(v.start), fromInputValue(v.end)];
  }
  if (form.tzTouched && form.tz && !form.allDay) {
    return [instantFromWallTime(form.start, form.tz), instantFromWallTime(form.end, form.tz)];
  }
  return [fromInputValue(form.start), fromInputValue(form.end)];
}

// The quiet zone control on the duration line. A link until clicked, because
// almost every event is in the zone you are sitting in and a 420-entry select
// on every editor open would be noise (and costs a formatter per entry).
// Picking a zone keeps the typed clock time and changes the instant, the way
// Google Calendar does: you type "3:00 PM", pick New York, and mean 3 PM there.
// The line then says what that is on this device, so the consequence is never
// a surprise.
function ZoneControl({ form, occ, onPick }) {
  const [open, setOpen] = useState(false);
  const device = localTz();
  const tz = form.tz || device;
  const zones = useMemo(() => (open ? zoneOptions([tz, device, occ && occ.tzid]) : []), [open, tz, device, occ]);
  const elsewhere = form.tzTouched && !sameClock(tz, device);
  let here = '';
  if (elsewhere) {
    const [s, en] = formInstants(form);
    if (!isNaN(s) && !isNaN(en)) here = fmtRange(s, en, false) + ' on this device (' + tzCity(device) + ')';
  }
  // A stored zone that keeps a different clock from this device, e.g. an event
  // made at home, opened while travelling. UTC is what imports write when the
  // zone is unknown, so it is not worth announcing.
  const stored = occ && occ.tzid && occ.tzid !== 'UTC' && !form.tzTouched && !sameClock(occ.tzid, device) ? occ.tzid : null;
  const title = stored
    ? 'Times are shown on this device\'s clock. This event is stored in ' + tzCity(stored) + ' time (' + tzOffsetLabel(stored) + '), which is the clock it repeats on. Pick a zone to enter the time as it reads there.'
    : 'Pick a zone to enter the time as it reads there, for example a flight or a call in another city.';
  return html`<span class="bc-zone">
    ${open
      ? html`<select class="bc-zone-select" aria-label="Time zone" value=${tz} onChange=${(e) => onPick(e.target.value)}>
          ${zones.map(([z, label]) => html`<option key=${z} value=${z}>${label}</option>`)}
        </select>`
      : html`<button type="button" class="bc-ed-chip bc-zone-btn" title=${title} aria-label=${'Time zone: ' + tzCity(tz) + '. Change'} onClick=${() => setOpen(true)}><${Icon} name="globe" size=${12} />${tzCity(tz)} time<${Icon} name="chevronDown" size=${10} /></button>
          ${stored && html`<button
            type="button" class="bc-link-btn bc-zone-btn"
            title=${'This event is stored in ' + tzCity(stored) + ' time (' + tzOffsetLabel(stored) + '). Switch the fields to that clock; the event does not move.'}
            onClick=${() => { setOpen(true); onPick(stored); }}
          >edit in ${tzCity(stored)} time</button>`}`}
    ${here && html`<span class="bc-zone-here">= ${here}</span>`}
  </span>`;
}

// Smart default: bounded 12 weeks out rather than never-ending.
function defaultUntil(startDate) {
  const d = addDaysDate(startDate, 12 * 7);
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

// A datetime-local value in parts, and back.
const dateOf = (v) => String(v || '').slice(0, 10);
const minsOf = (v) => hhmmToMins(String(v || '').slice(11, 16));
const joinWhen = (key, mins) => key + 'T' + minsToHHMM(mins);
const shiftWhen = (v, minutes) => toInputValue(new Date(fromInputValue(v).getTime() + minutes * 60000));

// Whether this user reads a 24-hour clock, from how their time format prints 1 PM.
const reads24h = () => /13/.test(fmtTime(new Date(2000, 0, 1, 13, 0)));

// A calendar whose events are mine: there "Maybe" is the event's own status
// (tentative), which other calendar apps and Google see too.
const isMineCal = (cal) => !cal || (cal.role || 'mine') === 'mine';

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

  // A place the quick fill found opens the Location candidates (0.6.2):
  // typed text alone lands wherever a later lookup guesses (The Pig's Ear in
  // Dublin), so the choice is offered right away. Nothing is picked for you.
  // While typing it only opens; on Enter or Fill the cursor moves there too.
  const [placeAsk, setPlaceAsk] = useState(null);
  const applyNlDraft = (d, fromEnter = false) => {
    if (!d) return;
    if (d.location) setPlaceAsk((p) => ({ seq: ((p && p.seq) || 0) + 1, focus: fromEnter }));
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

  const runNlParse = async (v, fromEnter = false) => {
    if (!v.trim()) return;
    const id = ++nlReq.current;
    try {
      const d = await quickAddParse(v);
      if (id === nlReq.current) applyNlDraft(d, fromEnter);
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
      runNlParse(nlText, true);
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
    // A blank new event starts at the next quarter hour, not at 5:39.
    const start = occ ? anchor(occ.start, occ.allDay) : (draft.start ? anchor(draft.start, !!draft.allDay) : new Date(Math.ceil(Date.now() / 900000) * 900000));
    const end = occ ? anchor(occ.end, occ.allDay) : (draft.end ? anchor(draft.end, !!draft.allDay) : new Date(start.getTime() + 3600000));
    // A draft that names its own zone (a Google Calendar link's ctz) opens
    // in that zone, so saving keeps it: a repeating 9:00 London meeting
    // stays 9:00 in London (audit, 0.9.14).
    const draftZone = !occ && draft.tzid && draft.tzid !== 'UTC' && !draft.allDay && !sameClock(draft.tzid, localTz()) ? draft.tzid : null;
    const initial = {
      title: occ ? occ.title : (draft.title || ''),
      // New events land on the draft's calendar, else the user's default
      // calendar (settings), else the first local calendar.
      calendarId: occ ? occ.calendarId : (draft.calendarId || defaultTargetCalendarId()),
      start: draftZone ? wallTimeInZone(start, draftZone) : toInputValue(start),
      end: draftZone ? wallTimeInZone(end, draftZone) : toInputValue(end),
      allDay: occ ? !!occ.allDay : !!draft.allDay,
      // The zone Start and End are read in. It opens as this device's zone,
      // which is how the fields above are filled, so nothing changes unless
      // the owner picks another; only then (tzTouched) is it also saved as the
      // event's zone. An untouched edit never rewrites a stored zone.
      tz: draftZone || localTz(),
      tzTouched: !!draftZone,
      isContainer: occ ? !!occ.isContainer : !!draft.isContainer,
      // Planned or Maybe, on my own calendars (status confirmed / tentative).
      rel: (occ ? occ.relationship : draft.relationship) === 'maybe' ? 'maybe' : 'planned',
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

  // Phone (0.6.0): the editor is a full screen, so Back closes it, the way
  // it closes the event sheet. Opened from that sheet (Edit), it takes over
  // the sheet's history entry instead of stacking a second one, and the
  // sheet leaves the entry for it (EventPopover).
  const phoneOpen = !!editor && (() => { try { return matchMedia(PHONE_QUERY).matches; } catch { return false; } })();
  useEffect(() => {
    if (!phoneOpen) return undefined;
    // Back from a reload (0.9.1) the editor's entry is already there.
    if (history.state && history.state.bcEditor) { /* adopt */ }
    else if (history.state && history.state.bcSheet) history.replaceState({ bcEditor: 1 }, '');
    else history.pushState({ bcEditor: 1 }, '');
    let popped = false;
    const onPop = () => {
      popped = true;
      if (state.editorDirty) discardEditorWithUndo();
      else set({ editor: null, editorDirty: false, ...editorReturn(state.editor) });
    };
    window.addEventListener('popstate', onPop);
    return () => {
      window.removeEventListener('popstate', onPop);
      // Back to the event it was opened from (0.7.3): the sheet takes this entry again.
      if (!popped && history.state && history.state.bcEditor) {
        if (state.popover) history.replaceState({ bcSheet: 1 }, '');
        else history.back();
      }
    };
  }, [phoneOpen]);

  // Focus lands in the editor when it opens (the autofocus attribute does
  // nothing for an element added after the page loaded, so whatever had focus
  // before, the Filter box say, kept it). A new event starts in the quick
  // fill box (0.6.2), an edit in the title; the caret at the end, never
  // selecting. A
  // phone skips it when editing, where a keyboard nobody asked for would
  // cover the event.
  const focusedFor = useRef(null);
  useEffect(() => {
    if (!editor || !form || focusedFor.current === editor) return;
    focusedFor.current = editor;
    if (phoneOpen && editor.occ) return;
    const el = panelRef.current && panelRef.current.querySelector(!editor.occ && !editor.restore ? '.bc-nl-input' : '.bc-ed-title');
    if (!el) return;
    el.focus({ preventScroll: true });
    try { el.setSelectionRange(el.value.length, el.value.length); } catch { /* not a text box */ }
  }, [editor, form]); // eslint-disable-line

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Tab') trapFocus(panelRef.current, e); };
    if (editor) document.addEventListener('keydown', onKey, true);
    return () => document.removeEventListener('keydown', onKey, true);
  }, [editor]);

  if (!editor || !form) return null;

  const occ = editor.occ;
  const upd = (patch) => setForm((f) => ({ ...f, ...patch }));

  // Picking a zone normally keeps the typed clock time ("3 PM", now in New
  // York). The exception is picking the zone this event is ALREADY stored in
  // while the fields still show this device's clock: that means "let me edit
  // it in its own zone", so the moment is kept and the fields switch to that
  // zone's reading. Otherwise reopening "6:44 PM New York" in London and
  // re-picking New York would turn 11:44 PM London into 11:44 PM New York.
  const pickZone = (z) => setForm((f) => {
    const own = occ && occ.tzid === z && !f.tzTouched && !f.allDay;
    if (!own) return { ...f, tz: z, tzTouched: true };
    const [s, en] = formInstants(f);
    if (isNaN(s) || isNaN(en)) return { ...f, tz: z, tzTouched: true };
    return { ...f, tz: z, tzTouched: true, start: wallTimeInZone(s, z), end: wallTimeInZone(en, z) };
  });

  // Dirty tracking: any change to the form (or typed NL text) arms a
  // discard-confirm on every close path, including the global Esc (which
  // reads state.editorDirty in closeOverlays).
  const requestClose = () => {
    if (state.editorDirty) { discardEditorWithUndo(); return; }
    set({ editor: null, editorDirty: false, ...editorReturn(state.editor) });
  };

  // All-day date fields: the end field is the last day (inclusive). With the
  // duration locked, moving the first day moves the last by the same amount.
  const onAllDayStart = (v) => {
    if (!v) return;
    const cur = allDayFields(form.start, form.end);
    const last = durationLock ? addDaysKey(v, diffDaysKey(cur.end, cur.start)) : (cur.end < v ? v : cur.end);
    upd(allDayInputs(v, last));
  };
  const onAllDayEnd = (v) => {
    if (!v) return;
    const cur = allDayFields(form.start, form.end);
    upd(allDayInputs(cur.start, v));
  };

  // Unticking All day: the times ticking it left behind are midnight to
  // midnight, which as a timed event is a day long. Those become an hour,
  // at the next quarter hour today or 9 AM on another day. Times the owner
  // had set before ticking it (on and straight off again) are kept.
  const onAllDayToggle = (checked) => setForm((f) => {
    if (checked) return { ...f, allDay: true };
    const s0 = fromInputValue(f.start);
    const e0 = fromInputValue(f.end);
    const dayLong = String(f.start).slice(11, 16) === '00:00' && !(e0 - s0 < 23 * 3600000);
    if (!dayLong) return { ...f, allDay: false };
    const day = allDayFields(f.start, f.end).start;
    const [y, m, d] = day.split('-').map(Number);
    const start = day === todayKey() ? new Date(Math.ceil(Date.now() / 900000) * 900000) : new Date(y, m - 1, d, 9, 0);
    return { ...f, allDay: false, start: toInputValue(start), end: toInputValue(new Date(start.getTime() + 3600000)) };
  });

  // Start moves the end with it: by the event's length while the length is
  // locked, and otherwise only when the end would no longer be after the
  // start, to an hour later (Google Calendar's rule). An end before its start
  // is never made silently.
  const setStart = (v) => setForm((f) => {
    const ns = fromInputValue(v);
    if (isNaN(ns)) return f;
    const oldS = fromInputValue(f.start);
    const oldE = fromInputValue(f.end);
    const dur = oldE - oldS;
    let end = f.end;
    if (durationLock && dur > 0) end = toInputValue(new Date(ns.getTime() + dur));
    else if (isNaN(oldE) || oldE <= ns) end = toInputValue(new Date(ns.getTime() + 3600000));
    return { ...f, start: v, end };
  });

  // A typed start time: an hour with no am/pm keeps the half of the day the
  // start was already in.
  const onStartText = (t) => {
    const mins = resolveClock(parseClockText(t), { role: 'start', current: minsOf(form.start), h24: reads24h() });
    if (mins == null) return false;
    setStart(joinWhen(dateOf(form.start), mins));
    return true;
  };

  // A typed end time on the start's day: an hour with no am/pm is the first
  // reading after the start, and a time earlier than the start is the next
  // morning when that makes an event of 12 hours or less (10 PM to 1). Past
  // that it stays put and the fields say the end is before the start.
  const onEndText = (t) => {
    const parsed = parseClockText(t);
    const sameDay = dateOf(form.end) === dateOf(form.start);
    const mins = sameDay
      ? resolveClock(parsed, { role: 'end', after: minsOf(form.start), h24: reads24h() })
      : resolveClock(parsed, { role: 'start', current: minsOf(form.end), h24: reads24h() });
    if (mins == null) return false;
    let v = joinWhen(dateOf(form.end), mins);
    if (sameDay && fromInputValue(v) <= fromInputValue(form.start)) {
      const next = shiftWhen(v, 1440);
      if (fromInputValue(next) - fromInputValue(form.start) <= 12 * 3600000) v = next;
    }
    upd({ end: v });
    return true;
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
    const [s, en] = formInstants(form);
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
      rrule: buildRrule(form.rrule, form.allDay),
    };
    // A zone the owner picked becomes the event's own zone: it is what a
    // repeating event keeps its clock time in across DST changes. All-day
    // events are dates and have no use for one.
    if (form.tzTouched && !form.allDay) fields.tzid = form.tz;
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
    // Planned or Maybe is the event's status on my own calendars; sent on a
    // new Maybe, or when an edit changes it.
    if (isMineCal(state.calendars.find((c) => c.id === Number(form.calendarId)))) {
      const was = occ ? (occ.relationship === 'maybe' ? 'maybe' : 'planned') : 'planned';
      if (form.rel !== was) fields.status = form.rel === 'maybe' ? 'tentative' : 'confirmed';
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
  const allDayView = form.allDay ? allDayFields(form.start, form.end) : null;
  const duration = allDayView ? eventDuration({ ...allDayInputs(allDayView.start, allDayView.end), allDay: true }) : eventDuration(form);

  // --- when ---------------------------------------------------------------------
  const [whenS, whenE] = formInstants(form);
  const backwards = !isNaN(whenS) && !isNaN(whenE) && whenE <= whenS;
  const startMins = minsOf(form.start);
  const endMins = minsOf(form.end);
  const quarter = (key, i, curMins) => {
    const v = joinWhen(key, i * 15);
    return { value: v, label: fmtTime(fromInputValue(v)), current: curMins === i * 15 };
  };
  const startOptions = allDayView ? [] : Array.from({ length: 96 }, (_, i) => quarter(dateOf(form.start), i, startMins));
  // The end list runs from a quarter hour after the start through the next
  // 24 hours, each with its length, so overnight is one pick. An end days
  // later lists that day's quarter hours instead.
  const endSpan = (fromInputValue(form.end) - fromInputValue(form.start)) / 60000;
  const endFromStart = !allDayView && endSpan > 0 && endSpan <= 1440;
  const endOptions = allDayView ? [] : endFromStart || isNaN(endSpan) || endSpan <= 0
    ? Array.from({ length: 96 }, (_, i) => {
        const v = shiftWhen(form.start, (i + 1) * 15);
        const d = fromInputValue(v);
        const otherDay = dateOf(v) !== dateOf(form.start);
        return { value: v, label: fmtTime(d), note: (otherDay ? fmtWeekdayShort(d) + ' · ' : '') + durationLabel((i + 1) * 15), current: v === form.end };
      })
    : Array.from({ length: 96 }, (_, i) => quarter(dateOf(form.end), i, endMins));
  const startIdx = Math.min(95, Math.floor(startMins / 15));
  const endIdx = endFromStart ? Math.max(0, Math.min(95, Math.round(endSpan / 15) - 1)) : Math.min(95, Math.floor(endMins / 15));
  const flash = (k) => (nlFlash && nlFlash.has(k) ? ' bc-nl-applied' : '');
  // The event's length on the lock: days spelled out, shorter spans as the end list says them.
  const lengthText = backwards || isNaN(whenE - whenS) ? ''
    : duration ? duration.exact
    : allDayView ? '1 day' : durationLabel(Math.round((whenE - whenS) / 60000));
  const mineCal = isMineCal(state.calendars.find((c) => c.id === Number(form.calendarId)));

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

  const lab = (text, cls = '') => html`<span class=${'bc-ed-lab' + cls}>${text}</span>`;

  return html`<div class="bc-drawer-backdrop" onClick=${(e) => { if (e.target === e.currentTarget) requestClose(); }}>
    <form class="bc-drawer bc-editor" ref=${panelRef} onSubmit=${submit} role="dialog" aria-modal="true" aria-label=${(occ ? 'Edit ' : 'New ') + (form.isContainer ? 'trip' : 'event')}>
      <div class="bc-drawer-head">
        <button type="button" class="bc-icon-btn bc-ed-x" aria-label="Close" onClick=${requestClose}><${Icon} name="close" size=${phoneOpen ? 20 : 15} /></button>
        <h2>${(occ ? 'Edit ' : 'New ') + (form.isContainer ? 'trip' : 'event')}</h2>
        ${phoneOpen && html`<button type="submit" class="bc-btn bc-btn-primary bc-ed-headsave" disabled=${backwards}>${occ ? 'Save' : 'Create'}</button>`}
      </div>

      ${!occ && html`<div class="bc-nl" title="Fills the fields below as you type. Enter or Fill applies it now; everything stays editable.">
        <div class="bc-nl-row">
          <${Icon} name="quickadd" size=${15} />
          <input
            class="bc-nl-input"
            placeholder=${phoneOpen ? 'Type it: Lunch with Ada Fri noon' : 'Type it naturally: Lunch with Ada Friday noon at Zuni'}
            value=${nlText}
            onInput=${onNlInput}
            onKeyDown=${onNlKeyDown}
            aria-label="Describe the event in plain language"
          />
          <button type="button" class="bc-btn bc-nl-go" title="Fill the fields now" onClick=${() => { clearTimeout(nlTimer.current); runNlParse(nlText, true); }}>Fill</button>
        </div>
      </div>`}

      <div class=${'bc-ed-titlewrap' + flash('title')}>
        <input class="bc-ed-title" placeholder="Add a title" aria-label="Title" value=${form.title} onInput=${(e) => upd({ title: e.target.value })} required />
      </div>

      <div class="bc-ed-grid">
        ${lab('Calendar')}
        <div class="bc-ed-ctl">
          <${CalendarSelect}
            calendars=${state.calendars.filter((c) => c.editable)}
            value=${form.calendarId}
            onChange=${(v) => upd({ calendarId: v })}
          />
        </div>

        ${lab('Start')}
        <div class=${'bc-ed-ctl bc-ed-when' + flash('start')}>
          ${allDayView
            ? html`<${DateField} value=${allDayView.start} ariaLabel="First day" onChange=${onAllDayStart} />`
            : html`<${DateField} value=${dateOf(form.start)} ariaLabel="Start date" onChange=${(k) => setStart(joinWhen(k, startMins))} />`}
          ${!allDayView && html`<${TimeField}
            display=${fmtTime(fromInputValue(form.start))} options=${startOptions} currentIdx=${startIdx}
            ariaLabel="Start time" onText=${onStartText} onOption=${setStart}
          />`}
        </div>
        ${lab(allDayView ? 'Last day' : 'End')}
        <div class=${'bc-ed-ctl bc-ed-when' + flash('end')}>
          ${allDayView
            ? html`<${DateField} value=${allDayView.end} ariaLabel="Last day" onChange=${onAllDayEnd} />`
            : html`<${DateField} value=${dateOf(form.end)} ariaLabel="End date" onChange=${(k) => upd({ end: joinWhen(k, endMins) })} />`}
          ${!allDayView && html`<${TimeField}
            display=${fmtTime(fromInputValue(form.end))} options=${endOptions} currentIdx=${endIdx}
            ariaLabel="End time" onText=${onEndText} onOption=${(v) => upd({ end: v })}
          />`}
        </div>
        <span></span>
        <div class="bc-ed-ctl bc-ed-meta" role="status">
          <label class=${'bc-check' + flash('allDay')}>
            <input type="checkbox" checked=${form.allDay} onChange=${(e) => onAllDayToggle(e.target.checked)} />
            <span>All day</span>
          </label>
          <button
            type="button"
            class=${'bc-ed-chip' + (durationLock ? ' is-on' : '')}
            aria-pressed=${durationLock}
            aria-label="Keep the length when the start moves"
            title=${durationLock ? 'Length kept: moving the start moves the end with it. Click to set them separately.' : 'Start and end set separately. Click to keep the length when the start moves.'}
            onClick=${() => setDurationLock(!durationLock)}
          ><${Icon} name=${durationLock ? 'lock' : 'unlock'} size=${12} />${lengthText || 'Length'}</button>
          ${!form.allDay && html`<${ZoneControl} form=${form} occ=${occ} onPick=${pickZone} />`}
        </div>
        ${backwards && html`<span></span><div class="bc-ed-ctl bc-when-err" role="alert">
          <${Icon} name="warning" size=${13} /> The end is before the start.
          <button type="button" class="bc-link-btn" onClick=${() => upd(form.allDay
            ? allDayInputs(allDayView.start, allDayView.start)
            : { end: shiftWhen(form.start, 60) })}>${form.allDay ? 'Make it one day' : 'End an hour after the start'}</button>
        </div>`}

        <hr class="bc-ed-sep" />

        ${lab('Location')}
        <div class=${'bc-ed-ctl' + flash('location')}>
          <${PlaceInput}
            value=${form.location}
            ariaLabel="Location"
            tz=${occ ? occ.tzid : localTz()}
            near=${() => calendarPlaceNear(formInstants(form)[0].getTime(), occ ? occ.eventId : null)}
            ask=${placeAsk}
            onText=${(v) => upd({ location: v, locationLat: null, locationLng: null })}
            onPick=${(r) => upd({ location: pickFillText(r), locationLat: r.lat, locationLng: r.lng })}
          />
        </div>
        ${lab('Link')}
        <div class="bc-ed-ctl">
          <input type="url" placeholder="https://" aria-label="Link" value=${form.url} onInput=${(e) => upd({ url: e.target.value })} />
        </div>
        ${lab('Description', ' is-top')}
        <div class="bc-ed-ctl">
          <${RichText}
            seed=${occ ? (occ.description || '') : (editor.draft && editor.draft.description) || ''}
            seedKey=${occ ? occ.instanceId : 'new'}
            ariaLabel="Description"
            onChange=${(htmlValue, meta) => (meta && meta.seeded
              ? adoptSeededDescription(htmlValue)
              : upd({ description: htmlValue }))}
          />
        </div>

        <hr class="bc-ed-sep" />

        ${lab('People', ' is-top')}
        <div class=${'bc-ed-ctl' + flash('people')}>
          <${PeopleInput}
            value=${form.people}
            ariaLabel="People at this event"
            onChange=${(list) => upd({ people: list })}
          />
          ${availWarn.length > 0 && html`<div class="bc-avail-warn" role="status">
            ${availWarn.map((c) => html`<span key=${c.id}><${Icon} name="warning" size=${12} /> ${c.name} is ${c.kind} then${c.note ? ' (' + c.note + ')' : ''}</span>`)}
          </div>`}
        </div>
        ${lab('Tags')}
        <div class="bc-ed-ctl">
          <input placeholder="Comma separated" aria-label="Tags, comma separated" value=${form.tags} onInput=${(e) => upd({ tags: e.target.value })} />
        </div>
        ${lab(mineCal ? 'For me' : 'Trip')}
        <div class="bc-ed-ctl bc-ed-flags">
          ${mineCal && html`<span class="bc-ed-seg" role="group" aria-label="What this event is to you" title="What this event is to you. Maybe marks it tentative, which other calendar apps see too.">
            ${[['planned', 'Planned'], ['maybe', 'Maybe']].map(([v, label]) => html`<button
              key=${v} type="button" class=${'bc-ed-segbtn' + (form.rel === v ? ' is-on' : '')}
              aria-pressed=${form.rel === v} onClick=${() => upd({ rel: v })}
            >${v === 'planned' && html`<${Icon} name="star" size=${13} />`}${label}</button>`)}
          </span>`}
          <label class="bc-check" title="A trip is a span of days that other events happen inside">
            <input type="checkbox" checked=${form.isContainer} onChange=${(e) => upd({ isContainer: e.target.checked })} />
            <span>This is a trip (container)</span>
          </label>
        </div>
        ${occ && !occ.isContainer && !form.isContainer && html`${lab('Part of')}<div class="bc-ed-ctl bc-ed-trip"><${TripRow} occ=${occ} /></div>`}

        <hr class="bc-ed-sep" />

        ${lab('Reminders', ' is-top')}
        <div class="bc-ed-ctl bc-ed-rem">
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
            <option value="">+ Add</option>
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
          ${form.reminders !== null
            ? html`<button type="button" class="bc-link-btn bc-rem-reset" title="Use the calendar's or your default reminders again" onClick=${() => upd({ reminders: null })}>Reset to default</button>`
            : html`<span class="bc-rem-src">${remSrcHint}</span>`}
        </div>

        ${lab('Repeat', ' is-top')}
        <div class="bc-ed-ctl bc-ed-repeat">
          <div class="bc-ed-inline">
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
          ${r.freq !== 'none' && html`<div class="bc-ed-inline">
            <span class="bc-ed-sub">Ends</span>
            <select value=${r.ends} onChange=${(e) => updRrule({ ends: e.target.value, until: e.target.value === 'until' && !r.until ? defaultUntil(fromInputValue(form.start) || new Date()) : r.until })} aria-label="Ends">
              <option value="never">Never</option>
              <option value="until">Until date</option>
              <option value="count">After N times</option>
            </select>
            ${r.ends === 'until' && html`<input type="date" value=${r.until} onInput=${(e) => updRrule({ until: e.target.value })} aria-label="Until date" />`}
            ${r.ends === 'count' && html`<input class="bc-num" type="number" min="1" max="999" value=${r.count} onInput=${(e) => updRrule({ count: Number(e.target.value) || 1 })} aria-label="Occurrence count" />`}
          </div>`}
        </div>

        ${occ && occ.recurring && html`${lab('Apply to')}<div class="bc-ed-ctl">
          <select value=${scope} onChange=${(e) => setScope(e.target.value)} aria-label="Apply to">
            <option value="this">This event only</option>
            <option value="following">This and following</option>
            <option value="all">All events in series</option>
          </select>
        </div>`}
      </div>

      <div class="bc-drawer-actions bc-ed-actions">
        <button type="submit" class="bc-btn bc-btn-primary" disabled=${backwards} title=${backwards ? 'The end is before the start' : undefined}>${occ ? 'Save' : 'Create'}</button>
        <button type="button" class="bc-btn" onClick=${requestClose}>Cancel</button>
        ${googleCalendar(Number(form.calendarId)) && html`<span class="bc-drawer-note" title="This calendar lives at Google. Better-Cal writes straight through and cannot restore the previous version.">${occ ? 'Saves' : 'Creates'} at Google; can't be undone</span>`}
        ${occ && html`<button type="button" class="bc-btn bc-btn-danger bc-ed-delete" onClick=${() => deleteEvent(occ, scope)}>Delete</button>`}
      </div>
    </form>
  </div>`;
}
