// The series change dialog (docs/design/series-model.md, Preview and
// Confirm): what a change to all events of a series will do, from its plan,
// with its readings as choices, before anything is written. Desktop: a card
// at the drop point with no dimming, or over the editor's footer for a save.
// Phone: a bottom sheet over the bottom bar that scrolls inside, with the
// drawn rail. The lines come from lib/seriesplan.js; the flow is
// serieschange.js. Enter confirms, Esc or Cancel cancels, a press outside
// only dismisses (ui/outside.js). The action stays disabled until the plan
// is there: the answer enables it, never a timer.

import { html, useEffect, useLayoutEffect, useRef, useState } from '../../vendor/index.js';
import { useStore } from './store.js';
import { planView, planDate, cardPlace, cardFit } from '../lib/seriesplan.js';
import { fmtTime, parseISO } from '../lib/dates.js';
import { PHONE_QUERY, COARSE_QUERY } from '../lib/breakpoints.js';
import { onOutsidePress, insideAny } from '../ui/outside.js';
import { attachScrollRail } from '../ui/scrollrail.js';
import {
  confirmSeriesChange, cancelSeriesChange, onlyThisOne, pickSeriesOption, setSeriesScope,
} from './serieschange.js';

const CARD_W = 520;
const NO_DROP = { x: 16, y: 80 }; // where the card goes without a drop point

// The box the pending block is drawn in, every piece of it on screen
// (a block can show in more than one place), or null when it isn't drawn.
function pendingBox(instanceId) {
  if (typeof document === 'undefined' || instanceId == null) return null;
  const sel = '.is-series-pending[data-instance="' + String(instanceId).replace(/["\\]/g, '\\$&') + '"]';
  let box = null;
  for (const n of document.querySelectorAll(sel)) {
    const r = n.getBoundingClientRect();
    if (!r.width || !r.height) continue;
    box = box
      ? { left: Math.min(box.left, r.left), top: Math.min(box.top, r.top), right: Math.max(box.right, r.right), bottom: Math.max(box.bottom, r.bottom) }
      : { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
  }
  return box;
}

/** host: 'calendar' draws drags and resizes, 'editor' an editor save. */
export function SeriesDialog({ host = 'calendar' }) {
  const sc = useStore((s) => s.seriesChange);
  if (!sc || sc.hidden) return null;
  if ((host === 'editor') !== (sc.kind === 'editor')) return null;
  return html`<${SeriesCard} key=${sc.id} sc=${sc} />`;
}

function SeriesCard({ sc }) {
  const rootRef = useRef(null);
  const bodyRef = useRef(null);
  const cancelRef = useRef(null);
  const actionRef = useRef(null);
  const [allEdited, setAllEdited] = useState(false);
  const [, setViewSize] = useState(0);
  const phone = typeof window !== 'undefined' && window.matchMedia(PHONE_QUERY).matches;
  // Dates name their year when it isn't this one, as the server's words
  // do, so a plan reads one way.
  const view = planView(sc.plan, {
    kind: sc.kind, title: sc.title, scope: sc.scope, choices: sc.choices,
    tz: sc.tz, fields: sc.fields, changed: sc.changed,
    refYear: new Date().getFullYear(),
  });
  const following = sc.scope === 'following';
  const busy = sc.status === 'loading' || sc.status === 'refreshing' || sc.status === 'committing';
  const canConfirm = following || (!busy && view.canConfirm);

  // After a drag the card is placed by its drawn size, clear of the
  // dragged block: below it, or above or beside it when there's no room
  // below (cardPlace). Measuring needs the card drawn, so this only
  // measures and keeps the answer, for this viewport, for the render below
  // to use; it writes no style. Same place, same state: no new render.
  const [place, setPlace] = useState(null);
  useLayoutEffect(() => {
    const el = rootRef.current;
    if (!el || phone || sc.kind === 'editor') return;
    const r = el.getBoundingClientRect();
    const vw = window.innerWidth, vh = window.innerHeight;
    const p = cardPlace({
      w: r.width, h: r.height, view: { w: vw, h: vh },
      at: sc.at || NO_DROP, block: sc.at ? pendingBox(sc.occ.instanceId) : null,
    });
    const next = { view: vw + 'x' + vh, left: p.left, top: p.top };
    setPlace((was) => (was && was.view === next.view && was.left === next.left && was.top === next.top ? was : next));
  });

  // A window resized while the card is open places and fits it again.
  useEffect(() => {
    const onResize = () => setViewSize((n) => n + 1);
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, []);

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
  // card, so Enter confirms once the plan is there.
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

  // Its size and place are all in the style rendered here, from the
  // viewport and the host, so a change of layout renders them again and
  // nothing written for another layout stays behind. A desktop card, in
  // either host, is no taller than the viewport (cardFit); a longer plan,
  // such as every edited day shown, scrolls in its body. After a drag it
  // goes where it was measured to fit, or by the drop point until then;
  // over the editor app.css puts it bottom right. The sheet renders no
  // style: app.css places it and caps its height.
  let style = '';
  if (!phone) {
    const vw = window.innerWidth, vh = window.innerHeight;
    style = 'max-height:' + cardFit({ w: vw, h: vh }) + 'px';
    if (sc.kind !== 'editor') {
      const at = sc.at || NO_DROP;
      const fitted = place && place.view === vw + 'x' + vh ? place : null;
      const left = fitted ? fitted.left : Math.max(8, Math.min(at.x, vw - CARD_W - 8));
      const top = fitted ? fitted.top : Math.max(8, at.y + 12);
      style += ';left:' + left + 'px;top:' + top + 'px';
    }
  }
  const cls = 'bc-sd' + (phone ? ' is-sheet' : sc.kind === 'editor' ? ' is-editor' : '');
  const shownEdited = allEdited ? view.edited : view.edited.slice(0, phone ? 2 : 3);
  const moreEdited = view.edited.length - shownEdited.length;
  // On a phone the sheet may cover the drop point: it says where the day went.
  const thisDay = phone && sc.kind !== 'editor' && sc.body && sc.body.start
    ? 'This day: ' + planDate(sc.body.start, { refYear: new Date().getFullYear() }) + (sc.occ.allDay ? '' : ' at ' + fmtTime(parseISO(sc.body.start)))
    : '';

  return html`<div class=${cls} ref=${rootRef} style=${style} role="dialog" aria-label=${view.heading} tabIndex="-1">
    <div class="bc-sd-head">
      <span class="bc-sd-title">${view.heading}</span>
      <span class="bc-seg bc-sd-scope" role="group" aria-label="Which events">
        <button type="button" class=${'bc-seg-btn' + (following ? ' is-active' : '')} aria-pressed=${following} onClick=${() => setSeriesScope('following')}>This and following</button>
        <button type="button" class=${'bc-seg-btn' + (!following ? ' is-active' : '')} aria-pressed=${!following} onClick=${() => setSeriesScope('all')}>All events</button>
      </span>
      <button type="button" class="bc-link-btn bc-sd-only" onClick=${() => onlyThisOne()}>Only this one</button>
    </div>
    ${thisDay && html`<div class="bc-sd-thisday">${thisDay}</div>`}
    <div class="bc-sd-body bc-scroll-edges" ref=${bodyRef}>
      ${following ? html`<p class="bc-sd-note">This and following splits the series at this day, as it did before; its preview comes in a later version.</p>`
        : !sc.plan ? html`<p class="bc-sd-working" role="status">Working out the plan…</p>`
        : html`<${PlanLines} view=${view} shownEdited=${shownEdited} moreEdited=${moreEdited} onMore=${() => setAllEdited(true)} working=${busy} />`}
    </div>
    <div class="bc-sd-actions">
      ${sc.status === 'refreshing' && html`<span class="bc-sd-status" role="status">Working out the plan…</span>`}
      ${sc.status === 'committing' && html`<span class="bc-sd-status" role="status">Saving…</span>`}
      <button type="button" class="bc-btn" ref=${cancelRef} onClick=${() => cancelSeriesChange()}>Cancel</button>
      ${!(view.refused && !following) && html`<button
        type="button" ref=${actionRef} class="bc-btn bc-btn-primary"
        disabled=${!canConfirm} onClick=${() => confirmSeriesChange()}
      >${view.action}</button>`}
    </div>
  </div>`;
}

function Choice({ group, inline = false }) {
  return html`<span class="bc-sd-choice" role="radiogroup" aria-label=${group.label || 'Choice'}>
    ${!inline && group.label && html`<span class="bc-sd-choice-label">${group.label}</span>`}
    ${group.options.map((o) => html`<label key=${o.id} class=${'bc-sd-opt' + (o.disabled ? ' is-disabled' : '')} title=${o.disabled ? o.reason : ''}>
      <input type="radio" name=${'sd-' + group.id} checked=${o.selected} disabled=${o.disabled}
        onChange=${() => pickSeriesOption(group.id, o.id)} />
      <span>${o.label}</span>
    </label>`)}
  </span>`;
}

function PlanLines({ view, shownEdited, moreEdited, onMore, working }) {
  return html`<div class=${'bc-sd-plan' + (working ? ' is-stale' : '')} aria-busy=${working ? 'true' : 'false'}>
    ${view.changed && html`<p class="bc-sd-changed" role="status">The series changed since this was worked out. Here is the new plan.</p>`}
    ${view.fields.map((f) => html`<div key=${f.label} class="bc-sd-line"><span class="bc-sd-lab">${f.label}</span><span>${f.words}</span></div>`)}
    ${view.refused
      ? html`<p class="bc-sd-refused" role="alert">${view.refused}</p>`
      : (view.rule || view.bound) && html`<div class="bc-sd-rule"><span>${view.rule}</span>${view.bound && html`<span class="bc-sd-bound">${view.bound}</span>`}</div>`}
    ${view.start && html`<div class="bc-sd-sub">${view.start}</div>`}
    ${view.readings.map((g) => html`<div key=${g.id} class="bc-sd-readings" role="radiogroup" aria-label=${g.label || 'Readings'}>
      ${g.showLabel && html`<div class="bc-sd-lab">${g.label}</div>`}
      ${g.options.map((o) => html`<label key=${o.id} class=${'bc-sd-reading' + (o.disabled ? ' is-disabled' : '')}>
        <input type="radio" name=${'sd-' + g.id} checked=${o.selected} disabled=${o.disabled}
          onChange=${() => pickSeriesOption(g.id, o.id)} />
        <span class="bc-sd-reading-text">
          <span class="bc-sd-reading-label">${o.label}</span>
          ${o.disabled
            ? html`<span class="bc-sd-reason">${o.reason}</span>`
            : html`${o.dates && html`<span class="bc-sd-dates">${o.dates}</span>`}${o.note && html`<span class="bc-sd-conseq">${o.note}</span>`}${o.words && html`<span class="bc-sd-words">${o.words}</span>`}`}
        </span>
      </label>`)}
    </div>`)}
    ${view.next && html`<div class="bc-sd-line"><span class="bc-sd-lab">Next</span><span>${view.next}</span></div>`}
    ${shownEdited.map((d, i) => html`<div key=${d.key} class="bc-sd-line">
      <span class="bc-sd-lab">${i === 0 ? 'Edited' : ''}</span>
      <span>${d.words}${d.choice && html` <${Choice} group=${d.choice} inline />`}</span>
    </div>`)}
    ${moreEdited > 0 && html`<div class="bc-sd-line"><span class="bc-sd-lab"></span><button type="button" class="bc-link-btn" onClick=${onMore}>and ${moreEdited} more</button></div>`}
    ${view.skipped && html`<div class="bc-sd-line"><span class="bc-sd-lab">Skipped</span><span>${view.skipped}</span></div>`}
    ${view.choices.map((g) => html`<div key=${g.id} class="bc-sd-line"><span class="bc-sd-lab"></span><${Choice} group=${g} /></div>`)}
    ${view.ownership && html`<p class="bc-sd-sub">${view.ownership}</p>`}
    ${view.gains && html`<p class="bc-sd-sub">${view.gains}</p>`}
    ${view.losses && html`<p class="bc-sd-sub">${view.losses}</p>`}
    ${view.notes.map((n) => html`<p key=${n} class="bc-sd-note">${n}</p>`)}
  </div>`;
}
