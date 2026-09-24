// GroupPopover: compact list for a near-duplicate group chip ("Juneteenth ·
// 17"). Anchored panel on desktop, bottom sheet on mobile. Each row opens
// that member's full detail view.

import { html, useRef, useEffect } from '../../vendor/index.js';
import { CalDot, Icon } from '../ui/icons.js';
import { useStore, set, state } from './store.js';
import { openDetail } from './actions.js';
import { isMobile, anchorPanel, trapFocus, MOBILE_QUERY } from '../ui/DayExpand.js';
import { parseISO, fmtTime } from '../lib/dates.js';
import { onOutsidePress, insideAny } from '../ui/outside.js';

const WIDTH = 300;

export function GroupPopover() {
  const gp = useStore((s) => s.groupPopover);
  const panelRef = useRef(null);

  // Document-level listeners; torn down on unmount, not just close.
  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Tab') trapFocus(panelRef.current, e);
    };
    let stop = null;
    if (gp) {
      stop = onOutsidePress(insideAny(panelRef), () => set({ groupPopover: null }));
      document.addEventListener('keydown', onKey, true);
    }
    return () => {
      if (stop) stop();
      document.removeEventListener('keydown', onKey, true);
    };
  }, [gp]);

  // Anchored panel and sheet position differently; crossing the breakpoint
  // would leave a stale layout, so close instead (same rule as the popover).
  useEffect(() => {
    if (!gp) return undefined;
    const mq = window.matchMedia(MOBILE_QUERY);
    const onChange = () => set({ groupPopover: null });
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, [gp]);

  if (!gp) return null;

  const { group } = gp;
  const cal = state.calendars.find((c) => c.id === group.calendarId);
  const mobile = isMobile();
  let style = '';
  if (!mobile && gp.anchorRect) {
    const h = Math.min(380, 46 + group.members.length * 30);
    const p = anchorPanel(gp.anchorRect, WIDTH, h);
    style = `left:${p.left}px;top:${p.top}px;width:${WIDTH}px`;
  }

  return html`<div
    class="bc-group-pop${mobile ? ' bc-sheet' : ''}"
    style=${style}
    ref=${panelRef}
    role="dialog"
    aria-modal="true"
    aria-label=${group.title + ', ' + group.count + ' similar events'}
  >
    <div class="bc-group-head">
      <${CalDot} cal=${cal} />
      <span class="bc-group-title">${group.title}</span>
      <span class="bc-group-count">${group.count}</span>
      <button type="button" class="bc-icon-btn" aria-label="Close" onClick=${() => set({ groupPopover: null })}><${Icon} name="close" size=${14} /></button>
    </div>
    <div class="bc-group-list">
      ${group.members.map((occ) => html`<button
        key=${occ.instanceId} type="button" class="bc-group-row"
        onClick=${() => openDetail(occ.instanceId)}
      >
        <span class="bc-group-time">${occ.allDay ? 'all day' : fmtTime(parseISO(occ.start))}</span>
        <span class="bc-group-name">${occ.title || '(untitled)'}</span>
      </button>`)}
    </div>
  </div>`;
}
