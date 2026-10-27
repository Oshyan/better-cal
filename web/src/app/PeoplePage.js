// People management page: everyone linked to events (via the editor's People
// field or quick-add "with X"), with usage stats, freeform notes, rename
// (renaming onto an existing name merges the two), delete, availability
// spans (away/busy, docs/api-contract.md, People and Availability), the show-on-calendar
// toggle (mirrors the sidebar People folder), and an expandable per-person
// event list whose rows jump the calendar to the event.

import { html, useState, useEffect } from '../../vendor/index.js';
import { useStore, set, state, toast } from './store.js';
import { api, loadPeople, refreshWindow } from './api.js';
import { jumpToDate, openDetail, addAvailabilitySpan, updateAvailabilitySpan, deleteAvailabilitySpan } from './actions.js';
import { PageShell, EmptyState, Skeleton } from './PageShell.js';
import { CalDot, Icon } from '../ui/icons.js';
import { parseISO, occDayKey, fmtTime, dateOfDayKey, dayKeyOf, toISOWithOffset } from '../lib/dates.js';
import { askConfirm } from './ConfirmDialog.js';

function fmtDay(iso) {
  return parseISO(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

// "Aug 10 – Aug 15" (end instant exclusive, so display steps back a minute).
function fmtSpanRange(span) {
  const s = parseISO(span.start);
  const e = new Date(parseISO(span.end).getTime() - 60000);
  const f = (d) => d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  return f(s) === f(e) ? f(s) : f(s) + ' – ' + f(e);
}

function fmtWhen(occ) {
  const day = dateOfDayKey(occDayKey(occ)).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  return occ.allDay ? day : day + ' · ' + fmtTime(parseISO(occ.start));
}

// Expandable event list for one person; rows share the search overlay's
// jump-and-open behavior.
function PersonEvents({ personId }) {
  const [list, setList] = useState(null); // null = loading

  useEffect(() => {
    let alive = true;
    api('/people/' + personId + '/events')
      .then((d) => { if (alive) setList((d && d.results) || []); })
      .catch(() => { if (alive) setList([]); });
    return () => { alive = false; };
  }, [personId]);

  const go = (occ) => {
    if (!state.occ.has(occ.instanceId)) {
      state.occ.set(occ.instanceId, occ);
      set({ occVersion: state.occVersion + 1 });
    }
    set({ route: 'calendar' });
    jumpToDate(occDayKey(occ), occ.instanceId);
    openDetail(occ.instanceId);
  };

  // An event is "past" only once it has ENDED, so in-progress (including
  // multi-day) events stay in the upcoming group.
  const now = Date.now();
  const upcoming = (list || []).filter((o) => parseISO(o.end).getTime() >= now).reverse();
  const past = (list || []).filter((o) => parseISO(o.end).getTime() < now);

  const rows = (label, items) => items.length > 0 && html`<div class="bc-person-group">
    <div class="bc-person-group-label">${label}</div>
    ${items.map((occ) => {
      const cal = state.calendars.find((c) => c.id === occ.calendarId);
      return html`<button key=${occ.instanceId} type="button" class="bc-person-eventrow" onClick=${() => go(occ)}>
        <span class="bc-person-when">${fmtWhen(occ)}</span>
        <${CalDot} cal=${cal} color=${(cal && cal.color) || '#888'} />
        <span class="bc-person-title">${occ.title || '(untitled)'}</span>
        ${occ.recurring && html`<span class="bc-badge">repeats</span>`}
      </button>`;
    })}
  </div>`;

  return html`<div class="bc-person-events">
    ${list === null && html`<${Skeleton} rows=${3} compact=${true} />`}
    ${list !== null && list.length === 0 && html`<div class="bc-trip-empty">No events with this person</div>`}
    ${list !== null && rows('Upcoming', upcoming)}
    ${list !== null && rows('Past', past)}
  </div>`;
}

// Availability spans: a list where each entry opens for editing in the form
// below it (#159), with delete, and the same form adds a new one. Dates are
// inclusive; a time that starts or ends partway through a day keeps its time
// of day when only its dates move.
const dayDiff = (a, b) => Math.round((dateOfDayKey(b) - dateOfDayKey(a)) / 86400000);
const lastDayKey = (span) => dayKeyOf(new Date(parseISO(span.end).getTime() - 60000));
const shiftDays = (iso, n) => {
  const d = parseISO(iso);
  return toISOWithOffset(new Date(d.getFullYear(), d.getMonth(), d.getDate() + n, d.getHours(), d.getMinutes(), d.getSeconds()));
};

function PersonAvailability({ person }) {
  const [spans, setSpans] = useState(null);
  const [kind, setKind] = useState('away');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState(null); // the span being edited, or null

  const reload = () => api('/people/' + person.id + '/availability')
    .then((d) => setSpans((d && d.spans) || []))
    .catch(() => setSpans([]));

  useEffect(() => { reload(); setEditing(null); }, [person.id]); // eslint-disable-line

  const clearForm = () => { setStartDate(''); setEndDate(''); setNote(''); setKind('away'); setEditing(null); };

  const edit = (span) => {
    setEditing(span);
    setKind(span.kind);
    setStartDate(dayKeyOf(parseISO(span.start)));
    setEndDate(lastDayKey(span));
    setNote(span.note || '');
  };

  const submit = async (e) => {
    e.preventDefault();
    if (!startDate) return;
    const endIncl = endDate || startDate;
    if (endIncl < startDate) { toast('End before start', { error: true }); return; }
    setBusy(true);
    try {
      if (editing) {
        // Move each end by the days its date moved, keeping any time of day.
        const fromStart = dayKeyOf(parseISO(editing.start));
        const fields = { kind, note: note.trim() || null };
        if (startDate !== fromStart) fields.start = shiftDays(editing.start, dayDiff(fromStart, startDate));
        if (endIncl !== lastDayKey(editing)) fields.end = shiftDays(editing.end, dayDiff(lastDayKey(editing), endIncl));
        await updateAvailabilitySpan(person.id, editing.id, fields);
      } else {
        const s = new Date(startDate + 'T00:00:00');
        const eEx = new Date(new Date(endIncl + 'T00:00:00').getTime() + 86400000);
        await addAvailabilitySpan(person.id, {
          start: toISOWithOffset(s), end: toISOWithOffset(eEx), kind, note: note.trim() || null,
        });
      }
      clearForm();
      reload();
    } catch (err) {
      toast(err.message || (editing ? 'Could not save that time' : 'Could not add that time'), { error: true });
    } finally {
      setBusy(false);
    }
  };

  const remove = async (span) => {
    try {
      await deleteAvailabilitySpan(person.id, span.id);
      if (editing && editing.id === span.id) clearForm();
      reload();
    } catch (err) {
      toast(err.message || 'Delete failed', { error: true });
    }
  };

  return html`<div class="bc-person-avail">
    ${spans === null && html`<${Skeleton} rows=${2} compact=${true} />`}
    ${spans !== null && spans.length === 0 && html`<div class="bc-trip-empty">No away or busy times yet</div>`}
    ${spans !== null && spans.map((span) => html`<div key=${span.id} class=${'bc-person-availrow' + (editing && editing.id === span.id ? ' is-editing' : '')}>
      <button type="button" class="bc-person-availedit" title="Edit this time" aria-label=${'Edit ' + span.kind + ' ' + fmtSpanRange(span)} onClick=${() => edit(span)}>
        <span class="bc-badge bc-away-pill is-${span.kind}">${span.kind}</span>
        <span class="bc-person-availrange">${fmtSpanRange(span)}</span>
        ${span.note && html`<span class="bc-person-availnote">${span.note}</span>`}
      </button>
      <button type="button" class="bc-icon-btn bc-trip-x" title="Remove span" aria-label="Remove span" onClick=${() => remove(span)}><${Icon} name="close" size=${12} /></button>
    </div>`)}
    <form class=${'bc-person-availform' + (editing ? ' is-editing' : '')} onSubmit=${submit}>
      <select value=${kind} onChange=${(e) => setKind(e.target.value)} aria-label="Kind">
        <option value="away">Away</option>
        <option value="busy">Busy</option>
        <option value="here">Here</option>
      </select>
      <input type="date" value=${startDate} required onInput=${(e) => setStartDate(e.target.value)} aria-label="First day" />
      <span>to</span>
      <input type="date" value=${endDate} min=${startDate} onInput=${(e) => setEndDate(e.target.value)} aria-label="Last day (inclusive)" title="Last day, inclusive; empty = single day" />
      <input class="bc-person-availnotein" placeholder="Note (optional)" value=${note} onInput=${(e) => setNote(e.target.value)} aria-label="Note" />
      <button type="submit" class="bc-btn bc-btn-primary" disabled=${busy || !startDate}>${editing ? 'Save' : 'Add'}</button>
      ${editing && html`<button type="button" class="bc-btn" onClick=${clearForm}>Cancel</button>`}
    </form>
  </div>`;
}

export function PeoplePage() {
  const people = useStore((s) => s.people);
  const [loaded, setLoaded] = useState(false);
  const [openId, setOpenId] = useState(null);
  const [openSection, setOpenSection] = useState('events'); // 'events' | 'availability'
  const [editingId, setEditingId] = useState(null);
  const [name, setName] = useState('');
  const [notesId, setNotesId] = useState(null);   // editing
  const [notes, setNotes] = useState('');
  const [noteViewId, setNoteViewId] = useState(null); // read-only view
  useEffect(() => {
    loadPeople().catch(() => {}).finally(() => setLoaded(true));
  }, []);

  // Arrived via a person link (popover/detail/sidebar/band): expand once.
  useEffect(() => {
    if (!state.peopleFocus || people.length === 0) return;
    const target = people.find((p) => p.name.toLowerCase() === String(state.peopleFocus).toLowerCase());
    if (target) { setOpenId(target.id); setOpenSection('events'); }
    set({ peopleFocus: null });
  }, [people]); // eslint-disable-line

  const toggleOpen = (p, section) => {
    if (openId === p.id && openSection === section) setOpenId(null);
    else { setOpenId(p.id); setOpenSection(section); }
  };

  const submitRename = async (p, e) => {
    e.preventDefault();
    const trimmed = name.trim();
    setEditingId(null);
    if (!trimmed || trimmed === p.name) return;
    try {
      const merged = people.some((x) => x.id !== p.id && x.name.toLowerCase() === trimmed.toLowerCase());
      await api('/people/' + p.id, { method: 'PATCH', body: { name: trimmed } });
      toast(merged ? `Merged into ${trimmed}` : 'Renamed');
      loadPeople();
      // Occurrences carry people as names, so every cached event still shows
      // the old one. Pull the window again rather than leaving the calendar
      // asserting a name that no longer exists.
      refreshWindow();
    } catch (err) {
      toast(err.message || 'Rename failed');
    }
  };

  const saveNotes = async (p) => {
    setNotesId(null);
    setNoteViewId(null);
    const next = notes.trim() || null;
    if (next === (p.notes || null)) return;
    try {
      await api('/people/' + p.id, { method: 'PATCH', body: { notes: next } });
      loadPeople();
    } catch (err) {
      toast(err.message || 'Saving notes failed');
    }
  };

  const remove = async (p) => {
    try {
      await api('/people/' + p.id, { method: 'DELETE' });
      toast(`Removed ${p.name} (events kept)`);
      loadPeople();
      refreshWindow(); // their name is still on every cached occurrence
    } catch (err) {
      toast(err.message || 'Delete failed');
    }
  };

  const toggleShow = async (p) => {
    try {
      await api('/people/' + p.id, { method: 'PATCH', body: { showOnCalendar: !p.showOnCalendar } });
      set({ availSeq: state.availSeq + 1 });
      loadPeople();
    } catch (err) {
      toast(err.message || 'Failed', { error: true });
    }
  };

  return html`<${PageShell}
    title="People"
    note="Everyone linked to your events. Add people from the editor's People field, or just write “with Sam” when creating events. Quick add also understands “Sam is away Aug 10 to 15”. Renaming someone onto an existing name merges their history."
  >
    <div class="bc-person-createrow">
      <button type="button" class="bc-btn" onClick=${() => set({ createDrawer: { kind: 'person' } })}>+ New person</button>
    </div>
    ${loaded && people.length === 0 && html`<${EmptyState}
      text="No people yet. Create an event “with” someone — quick add understands phrases like “Lunch with Ada Friday noon” — or use the People field in the event editor."
      actionLabel="Go to calendar" onAction=${() => set({ route: 'calendar' })}
    />`}
    ${people.length > 0 && html`<div class="bc-card-list">
      ${people.map((p) => html`<div key=${p.id} class="bc-card bc-person-card">
        <div class="bc-person-head">
          ${editingId === p.id
            ? html`<form class="bc-views-saveform" onSubmit=${(e) => submitRename(p, e)}>
                <input value=${name} autofocus onInput=${(e) => setName(e.target.value)} aria-label="Person name" />
                <button type="submit" class="bc-btn bc-btn-primary">Save</button>
                <button type="button" class="bc-btn" onClick=${() => setEditingId(null)}>Cancel</button>
              </form>`
            : html`<div class="bc-card-main">
                <div class="bc-card-badges">
                  <strong class="bc-card-title">${p.name}</strong>
                  ${p.currentSpan && html`<span class="bc-badge bc-away-pill is-${p.currentSpan.kind}">${p.currentSpan.kind} now</span>`}
                  ${!p.currentSpan && p.nextSpan && html`<span class="bc-badge">${p.nextSpan.kind} ${fmtSpanRange(p.nextSpan)}</span>`}
                  <span class="bc-badge">${p.eventCount} ${p.eventCount === 1 ? 'event' : 'events'}</span>
                  ${p.nextStart && html`<span class="bc-badge">next ${fmtDay(p.nextStart)}</span>`}
                  ${p.notes && html`<button
                    type="button" class="bc-icon-btn bc-person-noteind${noteViewId === p.id ? ' is-on' : ''}"
                    title=${noteViewId === p.id ? 'Hide note' : 'View note'}
                    aria-label=${(noteViewId === p.id ? 'Hide' : 'View') + ' note for ' + p.name}
                    aria-expanded=${noteViewId === p.id}
                    onClick=${() => { setNoteViewId(noteViewId === p.id ? null : p.id); setNotesId(null); }}
                  ><${Icon} name="note" size=${13} /></button>`}
                </div>
                ${notesId !== p.id && noteViewId !== p.id && p.notes && html`<button
                  type="button" class="bc-person-notes" title="View note"
                  onClick=${() => setNoteViewId(p.id)}
                >${p.notes}</button>`}
                <label class="bc-check bc-person-show">
                  <input type="checkbox" checked=${p.showOnCalendar} onChange=${() => toggleShow(p)} />
                  <span>Show away/busy on calendar</span>
                </label>
              </div>`}
          <div class="bc-card-actions">
            <button
              type="button" class="bc-btn bc-btn-toggle${openId === p.id && openSection === 'events' ? ' is-active' : ''}"
              aria-pressed=${openId === p.id && openSection === 'events'}
              title="Show this person's events"
              onClick=${() => toggleOpen(p, 'events')}
            >Events</button>
            <button
              type="button" class="bc-btn bc-btn-toggle${openId === p.id && openSection === 'availability' ? ' is-active' : ''}"
              aria-pressed=${openId === p.id && openSection === 'availability'}
              title="Show and edit away and busy times"
              onClick=${() => toggleOpen(p, 'availability')}
            >Availability</button>
            ${!p.notes && html`<button type="button" class="bc-btn" onClick=${() => { setNotesId(p.id); setNotes(''); }}>Add note</button>`}
            <button type="button" class="bc-btn" onClick=${() => { setEditingId(p.id); setName(p.name); }}>Rename</button>
            <button
              type="button" class="bc-icon-btn bc-pop-trash" title=${'Remove ' + p.name + ' (events kept)'}
              aria-label=${'Remove ' + p.name}
              onClick=${async () => {
                if (await askConfirm({
                  title: 'Remove ' + p.name + '?',
                  body: 'Their events stay on your calendars, but they are no longer linked to them, and their notes and away times go. This can\'t be undone.',
                  confirmLabel: 'Remove ' + p.name,
                  danger: true,
                })) remove(p);
              }}
            ><${Icon} name="trash" size=${14} /></button>
          </div>
        </div>
        ${noteViewId === p.id && notesId !== p.id && html`<div class="bc-person-noteview">
          <div class="bc-person-noteview-text">${p.notes}</div>
          <div class="bc-card-actions">
            <button type="button" class="bc-btn" onClick=${() => { setNotesId(p.id); setNotes(p.notes || ''); }}>Edit note</button>
            <button type="button" class="bc-btn" onClick=${() => setNoteViewId(null)}>Close</button>
          </div>
        </div>`}
        ${notesId === p.id && html`<div class="bc-person-notesedit">
          <textarea
            value=${notes} autofocus rows="3" aria-label=${'Notes for ' + p.name}
            placeholder="Freeform notes: how you met, birthday, preferences..."
            onInput=${(e) => setNotes(e.target.value)}
          ></textarea>
          <div class="bc-card-actions">
            <button type="button" class="bc-btn bc-btn-primary" onClick=${() => saveNotes(p)}>Save notes</button>
            <button type="button" class="bc-btn" onClick=${() => setNotesId(null)}>Cancel</button>
          </div>
        </div>`}
        ${openId === p.id && openSection === 'events' && html`<${PersonEvents} personId=${p.id} />`}
        ${openId === p.id && openSection === 'availability' && html`<${PersonAvailability} person=${p} />`}
      </div>`)}
    </div>`}
  <//>`;
}
