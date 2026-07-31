// Toolbar: view switcher, today, visible month label, on-page filter,
// search and quick-add buttons. Single-row inline layout.

import { html } from '../../vendor/index.js';
import { useStore, set, shallowEq } from './store.js';
import { setView, goToday } from './actions.js';
import { fmtMonthYear } from '../lib/dates.js';

const VIEW_LABELS = [
  ['month', 'Month'],
  ['weeks3', '3 wk'],
  ['weeks2', '2 wk'],
  ['week', 'Week'],
  ['day', 'Day'],
  ['agenda', 'Agenda'],
];

export function Toolbar({ onToggleSidebar }) {
  const { view, visibleMonth, filterText } = useStore(
    (s) => ({ view: s.view, visibleMonth: s.visibleMonth, filterText: s.filterText }),
    shallowEq,
  );

  const label = visibleMonth
    ? fmtMonthYear(new Date(visibleMonth.year, visibleMonth.month - 1, 1))
    : '';

  return html`<header class="bc-toolbar">
    <button type="button" class="bc-icon-btn bc-menu-btn" aria-label="Toggle sidebar" onClick=${onToggleSidebar}>☰</button>
    <span class="bc-brand">Better-Cal</span>
    <button type="button" class="bc-btn" onClick=${goToday}>Today</button>
    <span class="bc-toolbar-month" aria-live="polite">${label}</span>
    <nav class="bc-viewswitch" aria-label="View">
      ${VIEW_LABELS.map(([v, l]) => html`<button
        key=${v} type="button"
        class="bc-viewswitch-btn${view === v ? ' is-active' : ''}"
        aria-pressed=${view === v}
        onClick=${() => setView(v)}
      >${l}</button>`)}
    </nav>
    <input
      class="bc-filter-input"
      type="search"
      placeholder="Filter visible events"
      aria-label="Filter visible events"
      value=${filterText}
      onInput=${(e) => set({ filterText: e.target.value })}
    />
    <button type="button" class="bc-icon-btn" aria-label="Search" title="Search ( / )" onClick=${() => set({ searchOpen: true })}>${'🔍'}</button>
    <button type="button" class="bc-btn bc-btn-primary" title="Quick add (c)" onClick=${() => set({ quickAddOpen: true })}>+ New</button>
  </header>`;
}
