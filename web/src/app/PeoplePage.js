// People management page: everyone linked to events (via the editor's People
// field or quick-add "with X"), with usage stats, freeform notes, rename
// (renaming onto an existing name merges the two), delete, and an expandable
// per-person event list whose rows jump the calendar to the event.

import { html, useState, useEffect } from '../../vendor/index.js';
import { useStore, set, state, toast } from './store.js';
import { api } from './api.js';
import { jumpToDate, openDetail } from './actions.js';
import { PageShell, EmptyState } from './PageShell.js';
import { CalDot } from '../ui/icons.js';
import { parseISO, occDayKey, fmtTime, dateOfDayKey } from '../lib/dates.js';

function fmtDay(iso) {
  return parseISO(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
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

  const now = Date.now();
  const upcoming = (list || []).filter((o) => parseISO(o.start).getTime() >= now).reverse();
  const past = (list || []).filter((o) => parseISO(o.start).getTime() < now);

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
    ${list === null && html`<div class="bc-trip-loading">Loading events</div>`}
    ${list !== null && list.length === 0 && html`<div class="bc-trip-empty">No events with this person</div>`}
    ${list !== null && rows('Upcoming', upcoming)}
    ${list !== null && rows('Past', past)}
  </div>`;
}

export function PeoplePage() {
  useStore((s) => s.route);
  const [people, setPeople] = useState(null); // null = loading
  const [openId, setOpenId] = useState(null);
  const [editingId, setEditingId] = useState(null);
  const [name, setName] = useState('');
  const [notesId, setNotesId] = useState(null);
  const [notes, setNotes] = useState('');
  const [confirmId, setConfirmId] = useState(null);

  const reload = () => api('/people')
    .then((d) => {
      const list = (d && d.people) || [];
      setPeople(list);
      // Arrived via a person link (popover/detail): expand that person once.
      if (state.peopleFocus) {
        const target = list.find((p) => p.name.toLowerCase() === String(state.peopleFocus).toLowerCase());
        if (target) setOpenId(target.id);
        set({ peopleFocus: null });
      }
    })
    .catch(() => setPeople([]));

  useEffect(() => { reload(); }, []);

  const submitRename = async (p, e) => {
    e.preventDefault();
    const trimmed = name.trim();
    setEditingId(null);
    if (!trimmed || trimmed === p.name) return;
    try {
      const merged = people.some((x) => x.id !== p.id && x.name.toLowerCase() === trimmed.toLowerCase());
      await api('/people/' + p.id, { method: 'PATCH', body: { name: trimmed } });
      toast(merged ? `Merged into ${trimmed}` : 'Renamed');
      reload();
    } catch (err) {
      toast(err.message || 'Rename failed');
    }
  };

  const saveNotes = async (p) => {
    setNotesId(null);
    const next = notes.trim() || null;
    if (next === (p.notes || null)) return;
    try {
      await api('/people/' + p.id, { method: 'PATCH', body: { notes: next } });
      reload();
    } catch (err) {
      toast(err.message || 'Saving notes failed');
    }
  };

  const remove = async (p) => {
    setConfirmId(null);
    try {
      await api('/people/' + p.id, { method: 'DELETE' });
      toast(`Removed ${p.name} (events kept)`);
      reload();
    } catch (err) {
      toast(err.message || 'Delete failed');
    }
  };

  return html`<${PageShell}
    title="People"
    note="Everyone linked to your events. Add people from the editor's People field, or just write “with Sam” when creating events. Renaming someone onto an existing name merges their history."
  >
    ${people !== null && people.length === 0 && html`<${EmptyState}
      text="No people yet. Create an event “with” someone — quick add understands phrases like “Lunch with Ada Friday noon” — or use the People field in the event editor."
      actionLabel="Go to calendar" onAction=${() => set({ route: 'calendar' })}
    />`}
    ${people !== null && people.length > 0 && html`<div class="bc-card-list">
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
                  <span class="bc-badge">${p.eventCount} ${p.eventCount === 1 ? 'event' : 'events'}</span>
                  ${p.nextStart && html`<span class="bc-badge">next ${fmtDay(p.nextStart)}</span>`}
                </div>
                ${notesId !== p.id && p.notes && html`<div class="bc-person-notes" title="Notes">${p.notes}</div>`}
              </div>`}
          <div class="bc-card-actions">
            <button type="button" class="bc-btn" onClick=${() => setOpenId(openId === p.id ? null : p.id)}>
              ${openId === p.id ? 'Hide events' : 'Events'}
            </button>
            <button type="button" class="bc-btn" onClick=${() => { setNotesId(p.id); setNotes(p.notes || ''); }}>Notes</button>
            <button type="button" class="bc-btn" onClick=${() => { setEditingId(p.id); setName(p.name); }}>Rename</button>
            ${confirmId === p.id
              ? html`<button type="button" class="bc-btn bc-btn-danger" onClick=${() => remove(p)}>Really remove?</button>`
              : html`<button type="button" class="bc-btn bc-btn-danger" onClick=${() => setConfirmId(p.id)}>Remove</button>`}
          </div>
        </div>
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
        ${openId === p.id && html`<${PersonEvents} personId=${p.id} />`}
      </div>`)}
    </div>`}
  <//>`;
}
