// Review: everything waiting on your decision, in one list.
//
//   invite_change  an organizer emailed a change (or a cancellation) to an
//                  invitation already on your calendar. An emailed change is
//                  an unauthenticated message, so it is HELD, shown here with
//                  exactly what it would change, and applied only when you
//                  accept it.
//   rsvp           an invitation you have not answered.
//   proposal       a plan one of your plugins suggests.
//
// The rule that makes it trustworthy, stated in the UI: an emailed change or a
// suggested plan is not on the calendar until accepted, and accepting can be undone.
//
// Every item carries its own `actions` from the server ({label, method, path,
// body}); this page runs them as given and does not need to know what a kind's
// endpoints are. Kinds only differ in how their detail is drawn.

import { html, useState, useEffect } from '../../vendor/index.js';
import { set, toast, invalidateRecords } from './store.js';
import { api, refreshWindow, loadReviewCount } from './api.js';
import { openOccurrence } from './push.js';
import { PageShell, EmptyState, Skeleton } from './PageShell.js';
import { Icon } from '../ui/icons.js';
import { ProposalCard } from './ProposalCard.js';
import { fmtSince } from '../lib/since.js';
import { parseISO, dateOfDayKey, fmtRange, fmtDayMedium, fmtTime } from '../lib/dates.js';

const KIND_LABEL = { invite_change: 'Invitation change', rsvp: 'Invitation', proposal: 'Proposal' };
const KIND_ICON = { invite_change: 'mail', rsvp: 'mail', proposal: 'proposals' };

// One side of a changed date. All-day values are bare dates; timed ones are
// instants, shown on this device's clock like everything else on screen.
function fmtWhen(value) {
  if (!value) return 'none';
  if (String(value).length === 10) return fmtDayMedium(dateOfDayKey(value));
  const d = parseISO(value);
  return fmtDayMedium(d) + ', ' + fmtTime(d);
}

function DiffRows({ diff }) {
  return html`<dl class="bc-review-diff">${diff.map((d) => {
    const isDate = d.field === 'start' || d.field === 'end';
    const show = (v) => (isDate ? fmtWhen(v) : (v == null || v === '' ? 'none' : v));
    return html`<div class="bc-review-diffrow" key=${d.field}>
      <dt>${d.label}</dt>
      <dd><span class="bc-review-from">${show(d.from)}</span><${Icon} name="arrowRight" size=${11} /><span class="bc-review-to">${show(d.to)}</span></dd>
    </div>`;
  })}</dl>`;
}

function whenLine(detail) {
  if (!detail || !detail.start) return '';
  const allDay = !!detail.allDay;
  const s = allDay ? dateOfDayKey(detail.start.slice(0, 10)) : parseISO(detail.start);
  const e = allDay ? dateOfDayKey(detail.end.slice(0, 10)) : parseISO(detail.end);
  return fmtRange(s, e, allDay) + (detail.recurring ? ' (repeats)' : '');
}

function ReviewCard({ item, onChanged }) {
  const [busy, setBusy] = useState(false);
  const d = item.detail || {};

  const run = async (action) => {
    setBusy(true);
    try {
      await api(action.path, { method: action.method || 'POST', body: action.body || {} });
      const accepted = action.name !== 'dismiss';
      toast(
        item.kind === 'rsvp' ? 'Reply recorded: ' + action.label
          : (accepted ? (d.method === 'CANCEL' ? 'Cancellation accepted' : 'Change applied') : 'Dismissed. Your calendar is unchanged'),
        { undoable: accepted && item.kind === 'invite_change' },
      );
      invalidateRecords();
      refreshWindow();
    } catch (e) {
      // 409: the event is gone, or a newer version was already applied. The
      // server closed the item; the message says which, and the list reloads.
      toast(e.message || 'Failed', { error: true });
    } finally {
      setBusy(false);
      await onChanged();
    }
  };

  const open = item.link ? () => { set({ route: 'calendar' }); openOccurrence(item.link.instanceId, item.link.at); } : null;
  return html`<div class=${'bc-review-card' + (item.status !== 'open' ? ' is-decided' : '')}>
    <div class="bc-review-head">
      <span class="bc-review-kind" title=${KIND_LABEL[item.kind]}><${Icon} name=${KIND_ICON[item.kind] || 'proposals'} size=${13} /></span>
      ${open
        ? html`<button type="button" class="bc-link-btn bc-review-title" title="Open this event" onClick=${open}>${item.title}</button>`
        : html`<span class="bc-review-title">${item.title}</span>`}
      ${item.kind === 'rsvp' && html`<span class="bc-review-when">${whenLine(d)}${d.location ? ' · ' + d.location : ''}</span>`}
      <span class="bc-review-age" title=${item.createdAt ? new Date(item.createdAt).toLocaleString() : ''}>${item.createdAt ? fmtSince(item.createdAt) : ''}</span>
      ${item.status !== 'open' && html`<span class="bc-badge">${item.status}</span>`}
    </div>
    <div class="bc-review-body">
      <span class="bc-review-summary">${item.summary}${item.kind === 'invite_change' && d.from ? html` <span class="bc-review-sender" title="The address this email came from. Email senders are not verified.">Sent from ${d.from}</span>` : ''}</span>
      ${item.actions.length > 0 && html`<span class="bc-review-actions">${item.actions.map((a, i) => html`<button
        key=${a.name} type="button" disabled=${busy}
        class=${'bc-btn' + (i === 0 ? ' bc-btn-primary' : '')}
        onClick=${() => run(a)}
      >${a.label}</button>`)}</span>`}
    </div>
    ${item.kind === 'invite_change' && d.diff && d.diff.length > 0 && html`<${DiffRows} diff=${d.diff} />`}
  </div>`;
}

export function ReviewPage() {
  const [items, setItems] = useState(null);
  const [showDecided, setShowDecided] = useState(false);

  const load = async () => {
    try {
      const data = await api('/review?status=' + (showDecided ? 'all' : 'open'));
      setItems(data.items || []);
      set({ reviewCount: data.count || 0 });
    } catch (e) {
      toast('Load failed: ' + e.message, { error: true });
      setItems([]);
      loadReviewCount();
    }
  };
  useEffect(() => { load(); }, [showDecided]);

  return html`<${PageShell}
    title="Review"
    note="What is waiting on you: changes organizers emailed, invitations you have not answered, and plans your plugins suggest. An emailed change or a suggested plan is not on your calendar until you accept it, and accepting can be undone."
  >
    <div class="bc-proposal-filters">
      <button type="button" class="bc-srcchip${showDecided ? '' : ' is-on'}" onClick=${() => setShowDecided(false)}>Waiting</button>
      <button type="button" class="bc-srcchip${showDecided ? ' is-on' : ''}" onClick=${() => setShowDecided(true)}>All</button>
    </div>
    ${items === null && html`<${Skeleton} rows=${4} />`}
    ${items !== null && items.length === 0 && html`<${EmptyState}
      text=${showDecided ? 'Nothing has needed a decision yet.' : 'Nothing is waiting on you.'}
    />`}
    ${(items || []).map((item) => (item.kind === 'proposal'
      ? html`<${ProposalCard} key=${item.key} p=${item.detail} onChanged=${load} />`
      : html`<${ReviewCard} key=${item.key} item=${item} onChanged=${load} />`))}
  </${PageShell}>`;
}
