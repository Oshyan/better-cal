// Proposals (C11): what a plugin suggests, and your decision.
//
// The contract that makes this worth having is visible in the UI: nothing on
// this page has touched your calendar. A planner can re-run hourly and revise
// its suggestions; only Accept writes, and accepting materializes the whole
// plan at once — so Undo puts it all back, not half of it.

import { html, useState, useEffect } from '../../vendor/index.js';
import { set, toast, useStore } from './store.js';
import { api, refreshWindow } from './api.js';
import { PageShell, EmptyState } from './PageShell.js';
import { Icon } from '../ui/icons.js';
import { sanitizeHtml } from '../lib/richtext.js';
import { parseISO, fmtDateFull, fmtTime } from '../lib/dates.js';

function planLine(ev) {
  const s = parseISO(ev.start);
  const allDay = String(ev.start).length === 10 || ev.allDay;
  return html`<li class="bc-proposal-item">
    <span class="bc-proposal-when">${fmtDateFull(s)}${allDay ? '' : ' ' + fmtTime(s)}</span>
    <span class="bc-proposal-what">${ev.title}</span>
    ${ev.location && html`<span class="bc-proposal-where">${ev.location}</span>`}
  </li>`;
}

function ProposalCard({ p, onChanged }) {
  const [busy, setBusy] = useState(false);
  const [open, setOpen] = useState(true);
  const plan = p.plan || {};
  const events = plan.events || [];

  const act = async (path, okMsg, after) => {
    setBusy(true);
    try {
      const r = await api('/proposals/' + p.id + path, { method: 'POST' });
      toast(typeof okMsg === 'function' ? okMsg(r) : okMsg, { undoable: false });
      refreshWindow();
      await onChanged();
      if (after) after(r);
    } catch (e) {
      toast(e.message || 'Failed', { error: true });
    } finally {
      setBusy(false);
    }
  };

  const accept = () => act(
    '/accept',
    (r) => 'Added ' + (r.created?.eventIds?.length || 0) + ' event'
      + ((r.created?.eventIds?.length || 0) === 1 ? '' : 's')
      + (r.created?.tripId ? ' as a trip' : ''),
  );
  const reject = () => act('/reject', 'Dismissed');
  const undo = () => act('/undo', 'Put back — the proposal is open again');

  return html`<div class="bc-proposal${p.status !== 'open' ? ' is-' + p.status : ''}">
    <div class="bc-proposal-head">
      <button type="button" class="bc-proposal-toggle" onClick=${() => setOpen(!open)} aria-expanded=${open}>
        <span class="bc-proposal-caret${open ? '' : ' is-closed'}"><${Icon} name="chevronDown" size=${13} /></span>
        <span class="bc-proposal-title">${p.title}</span>
      </button>
      <span class="bc-proposal-plugin">${p.pluginId}</span>
      ${p.status === 'accepted' && html`<span class="bc-badge bc-proposal-badge is-accepted">accepted</span>`}
      ${p.status === 'rejected' && html`<span class="bc-badge bc-proposal-badge">dismissed</span>`}
    </div>
    ${p.summary && html`<p class="bc-proposal-summary">${p.summary}</p>`}
    ${open && html`<div class="bc-proposal-body">
      ${p.rationaleHtml && html`<div class="bc-proposal-why bc-rich" dangerouslySetInnerHTML=${{ __html: sanitizeHtml(p.rationaleHtml) }}></div>`}
      ${plan.trip && html`<div class="bc-proposal-trip"><${Icon} name="trip" size=${12} /> ${plan.trip.title}</div>`}
      <ul class="bc-proposal-list">${events.map((ev, i) => html`${planLine(ev, i)}`)}</ul>
      <div class="bc-proposal-actions">
        ${p.status === 'open' && html`
          <button type="button" class="bc-btn bc-btn-primary" disabled=${busy} onClick=${accept}>
            Add ${events.length} event${events.length === 1 ? '' : 's'}${plan.trip ? ' as a trip' : ''}
          </button>
          <button type="button" class="bc-btn" disabled=${busy} onClick=${reject}>Dismiss</button>
        `}
        ${p.status === 'accepted' && html`<button type="button" class="bc-btn" disabled=${busy} onClick=${undo}>Undo — remove these again</button>`}
      </div>
    </div>`}
  </div>`;
}

export function ProposalsPage() {
  const [proposals, setProposals] = useState(null);
  const [showDecided, setShowDecided] = useState(false);

  const load = async () => {
    try {
      const d = await api('/proposals?status=' + (showDecided ? 'all' : 'open'));
      setProposals(d.proposals || []);
      set({ proposalCount: (d.proposals || []).filter((p) => p.status === 'open').length });
    } catch (e) {
      toast('Load failed: ' + e.message, { error: true });
      setProposals([]);
    }
  };
  useEffect(() => { load(); }, [showDecided]);

  return html`<${PageShell}
    title="Proposals"
    note="Suggestions from your plugins. Nothing here is on your calendar yet — accepting adds it all at once, and undo removes it all at once."
  >
    <div class="bc-proposal-filters">
      <button type="button" class="bc-srcchip${showDecided ? '' : ' is-on'}" onClick=${() => setShowDecided(false)}>Open</button>
      <button type="button" class="bc-srcchip${showDecided ? ' is-on' : ''}" onClick=${() => setShowDecided(true)}>All</button>
    </div>
    ${proposals === null && html`<p class="bc-page-note">Loading…</p>`}
    ${proposals !== null && proposals.length === 0 && html`<${EmptyState}
      text=${showDecided ? 'No proposals yet.' : 'Nothing waiting on you. Plugins that suggest plans will put them here.'}
    />`}
    ${(proposals || []).map((p) => html`<${ProposalCard} key=${p.id} p=${p} onChanged=${load} />`)}
  </${PageShell}>`;
}
