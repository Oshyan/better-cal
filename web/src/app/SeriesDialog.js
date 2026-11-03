// The series change panel (docs/design/series-model.md, Preview and
// Confirm): what a change to all events of a series will do, from its plan,
// with its readings as choices, before anything is written. One docked
// panel for every plan, from a drag or from the editor: bottom right of the
// calendar on a desktop (beside the editor drawer or the event panel when
// one is open), a bottom sheet on a phone. Where it sits and how tall it
// may be are app.css's alone (.bc-sd): this component writes no style and
// measures nothing. The blocks come from lib/seriesplan.js; the flow is
// serieschange.js. Enter confirms, Esc or Cancel cancels, a press outside
// only dismisses (ui/outside.js). The action stays disabled until the plan
// is there: the answer enables it, never a timer.

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
  // Dates name their year when it isn't this one, as the server's words
  // do, so a plan reads one way.
  const view = planView(sc.plan, {
    kind: sc.kind, title: sc.title, scope: sc.scope, choices: sc.choices,
    tz: sc.tz, fields: sc.fields, edits: Object.keys(sc.body || {}), changed: sc.changed,
    refYear: new Date().getFullYear(),
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
      <span class="bc-sd-title">${view.title}${view.name && html`<span class="bc-sd-name"> · ${view.name}</span>`}</span>
      <button type="button" class="bc-sd-quiet" onClick=${() => changeSeriesScope()}>Change scope</button>
    </div>
    <div class="bc-sd-body bc-scroll-edges" ref=${bodyRef}>
      ${!sc.plan ? html`<p class="bc-sd-working" role="status">Working out the plan…</p>`
        : html`<${PlanBlocks} view=${view} openGroups=${openGroups} onToggle=${toggleGroup} working=${busy} />`}
    </div>
    <div class="bc-sd-actions">
      ${sc.status === 'refreshing' && html`<span class="bc-sd-status" role="status">Working out the plan…</span>`}
      ${sc.status === 'committing' && html`<span class="bc-sd-status" role="status">Saving…</span>`}
      <button type="button" class="bc-sd-quiet" ref=${cancelRef} onClick=${() => cancelSeriesChange()}>Cancel</button>
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

// A choice's radios, each on a line of its own.
function Radios({ group, label = group.label || 'Choice' }) {
  return html`<div class="bc-sd-radios" role="radiogroup" aria-label=${label}>
    ${group.options.map((o) => html`<label key=${o.id} class=${'bc-sd-opt' + (o.disabled ? ' is-disabled' : '')} title=${o.disabled ? o.reason : ''}>
      <input type="radio" name=${'sd-' + group.id} checked=${o.selected} disabled=${o.disabled}
        onChange=${() => pickSeriesOption(group.id, o.id)} />
      <span>${o.label}</span>
    </label>`)}
  </div>`;
}

// A row of up to three lines: its head, what happens, its choice. A group
// of days has a button that opens its days under it, as rows of the same shape.
function Row({ row, open = false, onToggle = null }) {
  return html`<div class="bc-sd-row">
    <div class="bc-sd-r1">${row.strong && html`<b>${row.strong}</b>, `}${row.head}</div>
    ${row.line && html`<div class="bc-sd-r2">${row.line}</div>`}
    ${row.choice && html`<${Radios} group=${row.choice} label=${row.head || row.choice.label} />`}
    ${row.days && html`<button type="button" class="bc-btn bc-sd-toggle" aria-expanded=${open ? 'true' : 'false'} onClick=${onToggle}
      >${open ? 'Hide' : 'Show ' + row.days.length + ' days'}</button>`}
    ${row.days && open && html`<div class="bc-sd-days">${row.days.map((x) => html`<${Row} key=${x.key} row=${x} />`)}</div>`}
  </div>`;
}

function PlanBlocks({ view, openGroups, onToggle, working }) {
  const secondary = [view.start, view.gains, view.losses].filter(Boolean);
  const hasEdited = view.edited.length || view.choices.length || view.ownership;
  return html`<div class=${'bc-sd-plan' + (working ? ' is-stale' : '')} aria-busy=${working ? 'true' : 'false'}>
    ${view.changed && html`<p class="bc-sd-changed" role="status">The series changed since this was worked out. Here is the new plan.</p>`}
    ${view.fields.map((f) => html`<${Block} key=${'f:' + f.label} label=${f.label}><div class="bc-sd-r1">${f.words}</div><//>`)}
    ${(view.refused || view.change || secondary.length || view.readings.length) && html`<${Block} label="Change">
      ${view.refused
        ? html`<div class="bc-sd-r1 bc-sd-refused" role="alert">${view.refused}</div>`
        : view.change && html`<div class="bc-sd-r1">${view.change}</div>`}
      ${secondary.map((s) => html`<div key=${s} class="bc-sd-r2">${s}</div>`)}
      ${view.readings.map((g) => html`<div key=${g.id} class="bc-sd-readings" role="radiogroup" aria-label=${g.label || 'Readings'}>
        ${g.showLabel && html`<div class="bc-sd-r2">${g.label}</div>`}
        ${g.options.map((o) => html`<label key=${o.id} class=${'bc-sd-reading' + (o.disabled ? ' is-disabled' : '')}>
          <input type="radio" name=${'sd-' + g.id} checked=${o.selected} disabled=${o.disabled}
            onChange=${() => pickSeriesOption(g.id, o.id)} />
          <span class="bc-sd-reading-text">
            <span class="bc-sd-r1">${o.label}</span>
            ${o.disabled
              ? html`<span class="bc-sd-r2">${o.reason}</span>`
              : html`${o.dates && html`<span class="bc-sd-r2 bc-sd-dates">${o.dates}</span>`}${[o.words, o.note].filter(Boolean).map((t) => html`<span key=${t} class="bc-sd-r2">${t}</span>`)}`}
          </span>
        </label>`)}
      </div>`)}
    <//>`}
    ${view.next && html`<${Block} label="Next"><div class="bc-sd-r1 bc-sd-dates">${view.next}</div><//>`}
    ${!view.refused && view.size && html`<${Block} label="Size"><div class="bc-sd-r1">${view.size}</div><//>`}
    ${hasEdited && html`<${Block} label="Edited">
      ${view.edited.map((r) => html`<${Row} key=${r.key} row=${r} open=${!!r.days && openGroups.has(r.key)} onToggle=${() => onToggle(r.key)} />`)}
      ${view.ownership && html`<div class="bc-sd-row"><div class="bc-sd-r2">${view.ownership}</div></div>`}
      ${view.choices.map((r) => html`<${Row} key=${r.key} row=${r} />`)}
    <//>`}
    ${view.skipped.length > 0 && html`<${Block} label="Skipped">${view.skipped.map((r) => html`<${Row} key=${r.key} row=${r} />`)}<//>`}
    ${view.notes.length > 0 && html`<${Block} label="Note">${view.notes.map((n) => html`<div key=${n} class="bc-sd-r2">${n}</div>`)}<//>`}
  </div>`;
}

