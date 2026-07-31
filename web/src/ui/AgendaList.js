// AgendaList: day-grouped agenda with windowed rendering.
// Windowing is by day group: only groups intersecting the scroll viewport
// (plus buffer) render their rows; others render fixed-height placeholders,
// which is enough for a few thousand items.

import { html, useState, useRef, useMemo, useEffect, useCallback } from '../../vendor/index.js';
import {
  parseISO, fmtTime, dateOfDayKey, fmtDayLong, dayKeyOfISO, todayKey,
} from '../lib/dates.js';
import { EventChip } from './EventChip.js';

const ROW_H = 36;
const HEAD_H = 40;

export function AgendaList({ occurrences, calendars, dimSet, onOpenEvent, onRequestWindow, emptyLabel }) {
  const scrollRef = useRef(null);
  const [win, setWin] = useState({ top: 0, height: 800 });

  const groups = useMemo(() => {
    const byDay = new Map();
    for (const occ of occurrences) {
      if (occ.attendance === 'hidden') continue;
      const k = dayKeyOfISO(occ.start);
      let g = byDay.get(k);
      if (!g) byDay.set(k, (g = []));
      g.push(occ);
    }
    const keys = [...byDay.keys()].sort();
    let offset = 0;
    return keys.map((k) => {
      const items = byDay.get(k).sort((a, b) => {
        if (a.allDay !== b.allDay) return a.allDay ? -1 : 1;
        return a.start < b.start ? -1 : 1;
      });
      const height = HEAD_H + items.length * ROW_H;
      const g = { dayKey: k, items, top: offset, height };
      offset += height;
      return g;
    });
  }, [occurrences]);

  const totalH = groups.length ? groups[groups.length - 1].top + groups[groups.length - 1].height : 0;

  const onScroll = useCallback(() => {
    const el = scrollRef.current;
    if (!el) return;
    setWin((prev) => {
      const next = { top: el.scrollTop, height: el.clientHeight };
      return Math.abs(prev.top - next.top) > 200 || prev.height !== next.height ? next : prev;
    });
  }, []);

  useEffect(() => { onScroll(); }, [groups.length, onScroll]);

  const tKey = todayKey();
  const buffer = 600;
  const visStart = win.top - buffer;
  const visEnd = win.top + win.height + buffer;

  return html`<div class="bc-agenda" ref=${scrollRef} onScroll=${onScroll}>
    ${groups.length === 0 && html`<div class="bc-empty bc-agenda-empty">${emptyLabel || 'No events in this range'}</div>`}
    <div class="bc-agenda-spacer" style=${`height:${totalH}px`}>
      ${groups.map((g) => {
        const visible = g.top + g.height >= visStart && g.top <= visEnd;
        return html`<section
          key=${g.dayKey}
          class="bc-agenda-group${g.dayKey === tKey ? ' is-today' : ''}"
          style=${`top:${g.top}px;height:${g.height}px`}
        >
          <h3 class="bc-agenda-day">${fmtDayLong(dateOfDayKey(g.dayKey))}</h3>
          ${visible && g.items.map((occ) => html`<div key=${occ.instanceId} class="bc-agenda-row" style=${`height:${ROW_H}px`}>
            <span class="bc-agenda-time">${occ.allDay ? 'all day' : fmtTime(parseISO(occ.start)) + (occ.end ? ' to ' + fmtTime(parseISO(occ.end)) : '')}</span>
            <${EventChip}
              occ=${occ} cal=${calendars[occ.calendarId]} showTime=${false}
              dimmed=${dimSet && dimSet.has(occ.instanceId)}
              onOpen=${onOpenEvent}
            />
            ${occ.location && html`<span class="bc-agenda-loc">${occ.location}</span>`}
          </div>`)}
        </section>`;
      })}
    </div>
  </div>`;
}
