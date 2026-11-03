// The series change panel (docs/design/series-model.md, Preview and
// Confirm): what a change to all events of a series will do, from its plan,
// with its readings as choices, before anything is written. One docked
// panel for every plan, from a drag or from the editor: bottom right of the
// calendar on a desktop (beside the editor drawer or the event panel when
// one is open), a bottom sheet on a phone. Where it sits and how tall it
// may be are app.css's alone (.bc-sd): this component writes no layout and
// measures nothing. The blocks come from lib/seriesplan.js and every
// sentence in them from lib/planwords.js; the flow is serieschange.js.
// Enter confirms, Esc or Cancel cancels, a press outside only dismisses
// (ui/outside.js). The action stays disabled until the plan is there: the
// answer enables it, never a timer.

import { html, useEffect, useRef, useState } from '../../vendor/index.js';
import { useStore } from './store.js';
import { planView } from '../lib/seriesplan.js';
import { COARSE_QUERY } from '../lib/breakpoints.js';
import { onOutsidePress, insideAny } from '../ui/outside.js';
import { attachScrollRail } from '../ui/scrollrail.js';
import {
  confirmSeriesChange, cancelSeriesChange, onlyThisOne, pickSeriesOption, changeSeriesScope,
} from './serieschange.js';

/** host: 'calendar' draws drags and resizes, 'editor' an editor save. */
export function SeriesDialog({ host = 'calendar' }) {
  const sc = useStore((s) => s.seriesChange);
  if (!sc || sc.hidden) return null;
  if ((host === 'editor') !== (sc.kind === 'editor')) return null;
  return html`<${SeriesPanel} key=${sc.id} sc=${sc} />`;
}

function SeriesPanel({ sc }) {
  const rootRef = useRef(null);
  const bodyRef = useRef(null);
  const cancelRef = useRef(null);
  // The groups of edited days opened to show their days.
  const [openGroups, setOpenGroups] = useState(() => new Set());
  const toggleGroup = (key) => setOpenGroups((was) => {
    const next = new Set(was);
    if (next.has(key)) next.delete(key); else next.add(key);
    return next;
  });
  // The series' calendar, for the header's second line.
  const calendar = useStore((s) => (s.calendars || []).find((c) => sc.occ && c.id === sc.occ.calendarId) || null);
  // Every sentence is the client's (lib/planwords.js), in the user's format.
  const view = planView(sc.plan, {
    kind: sc.kind, title: sc.title, calendar: calendar ? calendar.name : '', scope: sc.scope, choices: sc.choices,
    tz: sc.tz, fields: sc.fields, edits: Object.keys(sc.body || {}), newTitle: (sc.body && sc.body.title) || '',
    changed: sc.changed, refYear: new Date().getFullYear(),
  });
  const busy = sc.status === 'loading' || sc.status === 'refreshing' || sc.status === 'committing';
  const canConfirm = !busy && view.canConfirm;

  // Keys: Enter confirms (a button keeps its own Enter), Esc cancels.
  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); cancelSeriesChange(); return; }
      if (e.key !== 'Enter') return;
      const t = e.target;
      if (t && t.closest && t.closest('button, a, textarea, select')) return;
      if (!rootRef.current || !(rootRef.current.contains(t) || t === document.body)) return;
      e.stopPropagation(); e.preventDefault();
      confirmSeriesChange();
    };
    const stop = onOutsidePress(insideAny(rootRef), () => cancelSeriesChange());
    document.addEventListener('keydown', onKey, true);
    return () => { stop(); document.removeEventListener('keydown', onKey, true); };
  }, []);

  // Focus: Cancel for a Google series (it can't be undone yet), else the
  // panel, so Enter confirms once the plan is there.
  useEffect(() => {
    const el = sc.google ? cancelRef.current : rootRef.current;
    if (el) el.focus({ preventScroll: true });
  }, []);

  // The drawn scroll rail on touch screens, where the sheet scrolls.
  useEffect(() => {
    const el = bodyRef.current;
    if (!el || !window.matchMedia(COARSE_QUERY).matches) return undefined;
    return attachScrollRail(el);
  }, []);

  return html`<div class="bc-sd" ref=${rootRef} role="dialog" aria-label=${view.heading} tabIndex="-1">
    <div class="bc-sd-head">
      <div class="bc-sd-head-row">
        <span class="bc-sd-title">${view.title}</span>
        <button type="button" class="bc-btn bc-sd-small" onClick=${() => changeSeriesScope()}>Change scope</button>
      </div>
      <div class="bc-sd-series" title=${view.series}>
        <span class="bc-cal-dot bc-sd-disc" style=${calendar && calendar.color ? '--cal:' + calendar.color : ''}></span>
        <span class="bc-sd-series-text">${view.series}</span>
      </div>
    </div>
    <div class="bc-sd-body bc-scroll-edges" ref=${bodyRef}>
      ${!sc.plan ? html`<p class="bc-sd-working" role="status">${view.working}</p>`
        : html`<${PlanBlocks} view=${view} openGroups=${openGroups} onToggle=${toggleGroup} working=${busy} />`}
    </div>
    <div class="bc-sd-actions">
      ${sc.status === 'refreshing' && html`<span class="bc-sd-status" role="status">${view.working}</span>`}
      ${sc.status === 'committing' && html`<span class="bc-sd-status" role="status">${view.saving}</span>`}
      <button type="button" class="bc-btn" ref=${cancelRef} onClick=${() => cancelSeriesChange()}>Cancel</button>
      ${view.refused
        ? html`<button type="button" class="bc-btn bc-btn-primary" onClick=${() => onlyThisOne()}>${view.onlyThis}</button>`
        : html`<button type="button" class="bc-btn bc-btn-primary" disabled=${!canConfirm} onClick=${() => confirmSeriesChange()}>${view.action}</button>`}
    </div>
  </div>`;
}

// A block: its label in the left column, its content in the right one.
const Block = ({ label, children }) => html`<div class="bc-sd-block">
  <div class="bc-sd-lab">${label}</div>
  <div class="bc-sd-rows">${children}</div>
</div>`;

// A choice: its lead and its reason, then each option on a line of its own,
// with its dates and consequence under its label when it has them.
function Choice({ choice }) {
  return html`<div class="bc-sd-choice">
    ${choice.lead && html`<div class="bc-sd-r1">${choice.lead}</div>`}
    ${choice.reason && html`<div class="bc-sd-r2">${choice.reason}</div>`}
    <div class="bc-sd-radios" role="radiogroup" aria-label=${choice.reason || choice.lead || 'Choice'}>
      ${choice.options.map((o) => html`<label key=${o.id} class=${'bc-sd-opt' + (o.disabled ? ' is-disabled' : '')}>
        <input type="radio" name=${'sd-' + choice.id} checked=${o.selected} disabled=${o.disabled}
          onChange=${() => pickSeriesOption(choice.id, o.id)} />
        <span class="bc-sd-opt-text">
          <span class="bc-sd-r1">${o.label}</span>
          ${o.disabled ? html`<span class="bc-sd-r2">${o.reason}</span>`
            : html`${o.dates && html`<span class="bc-sd-r2 bc-sd-dates">${o.dates}</span>`}${o.note && html`<span class="bc-sd-r2">${o.note}</span>`}`}
        </span>
      </label>`)}
    </div>
  </div>`;
}

// A row: its sentence, what it keeps or takes, its choice. A group of days
// has its dates and a button that opens its days under it, as rows of the
// same shape.
function Row({ row, open = false, onToggle = null }) {
  return html`<div class="bc-sd-row">
    ${row.line1 && html`<div class="bc-sd-r1">${row.line1}</div>`}
    ${row.line2 && html`<div class="bc-sd-r2">${row.line2}</div>`}
    ${row.days && html`<div class="bc-sd-r2 bc-sd-range">${row.range} · <button type="button" class="bc-btn bc-sd-small bc-sd-toggle"
      aria-expanded=${open ? 'true' : 'false'} onClick=${onToggle}>${row.toggle(open)}</button></div>`}
    ${row.choice && html`<${Choice} choice=${row.choice} />`}
    ${row.days && open && html`<div class="bc-sd-days">${row.days.map((x) => html`<${Row} key=${x.key} row=${x} />`)}</div>`}
  </div>`;
}

function PlanBlocks({ view, openGroups, onToggle, working }) {
  return html`<div class=${'bc-sd-plan' + (working ? ' is-stale' : '')} aria-busy=${working ? 'true' : 'false'}>
    ${view.changed && html`<p class="bc-sd-changed" role="status">${view.changed}</p>`}
    <${Block} label="Change">
      ${view.fields.map((f) => html`<div key=${f} class="bc-sd-r1">${f}</div>`)}
      ${view.refused ? html`<div class="bc-sd-r1" role="alert">${view.refused}</div>`
        : view.change && html`<div class="bc-sd-r1">${view.change}</div>`}
      ${view.readings.map((c) => html`<${Choice} key=${c.id} choice=${c} />`)}
    <//>
    ${view.comingUp && html`<${Block} label="Coming up"><div class="bc-sd-r1 bc-sd-dates">${view.comingUp}</div><//>`}
    ${view.affects && html`<${Block} label="Affects"><div class="bc-sd-r1">${view.affects}</div><//>`}
    ${view.edited.length > 0 && html`<${Block} label="Edited days">
      ${view.edited.map((r) => html`<${Row} key=${r.key} row=${r} open=${!!r.days && openGroups.has(r.key)} onToggle=${() => onToggle(r.key)} />`)}
    <//>`}
    ${view.skipped.length > 0 && html`<${Block} label="Skipped days">${view.skipped.map((r) => html`<${Row} key=${r.key} row=${r} />`)}<//>`}
    ${view.notes.length > 0 && html`<${Block} label="Note">${view.notes.map((n) => html`<div key=${n} class="bc-sd-r2">${n}</div>`)}<//>`}
  </div>`;
}
