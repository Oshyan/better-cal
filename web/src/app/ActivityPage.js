// Activity log: latest-first feed of every change — manual and automated —
// with source filters, a live quick filter, jump-to-event, and per-entry
// undo inside the 7-day snapshot window (docs/api-contract.md#activity).

import { html, useState, useEffect, useRef } from '../../vendor/index.js';
import { state, set, toast, insertOccurrence } from './store.js';
import { api, refreshWindow } from './api.js';
import { jumpToDate, openDetail } from './actions.js';
import { occDayKey } from '../lib/dates.js';
import { PageShell, EmptyState } from './PageShell.js';
import { Icon } from '../ui/icons.js';

// Source key -> label + manual/automated group. mail:* tiers collapse into
// the "mail" filter server-side but keep their tier in the badge.
const SOURCES = [
  ['web', 'Web', 'manual'],
  ['quickadd', 'Quick add', 'manual'],
  ['caldav', 'CalDAV', 'manual'],
  ['rsvp', 'RSVP', 'manual'],
  ['api', 'Agent/API', 'auto'],
  ['feed', 'Feeds', 'auto'],
  ['mail', 'Email', 'auto'],
  ['import', 'Import', 'auto'],
  ['geocode', 'Geocoding', 'auto'],
  ['system', 'System', 'auto'],
  ['plugin', 'Plugins', 'auto'],
];
const GROUPS = { manual: SOURCES.filter((s) => s[2] === 'manual').map((s) => s[0]),
  auto: SOURCES.filter((s) => s[2] === 'auto').map((s) => s[0]) };

function sourceLabel(source) {
  if (source.startsWith('mail:')) return 'Email · ' + source.slice(5);
  if (source.startsWith('plugin:')) return 'Plugin · ' + source.slice(7);
  const row = SOURCES.find(([k]) => k === source);
  return row ? row[1] : source;
}

function fmtWhen(iso) {
  const d = new Date(iso);
  const now = new Date();
  const time = d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  const sameYear = d.getFullYear() === now.getFullYear();
  const day = d.toDateString() === now.toDateString() ? 'Today'
    : d.toLocaleDateString(undefined, sameYear ? { month: 'short', day: 'numeric' } : { year: 'numeric', month: 'short', day: 'numeric' });
  return day + ', ' + time;
}

export function ActivityPage() {
  const [entries, setEntries] = useState(null);
  const [nextBefore, setNextBefore] = useState(null);
  const [sel, setSel] = useState(null); // null = All; otherwise Set of source keys
  const [q, setQ] = useState('');
  const [busyId, setBusyId] = useState(null);
  const seq = useRef(0);

  const load = (before) => {
    const my = ++seq.current;
    const params = new URLSearchParams();
    if (before) params.set('before', before);
    if (sel) params.set('sources', [...sel].join(','));
    if (q.trim()) params.set('q', q.trim());
    api('/activity?' + params.toString()).then((d) => {
      if (my !== seq.current) return;
      setEntries((prev) => (before && prev ? prev.concat(d.entries) : d.entries));
      setNextBefore(d.nextBefore);
    }).catch((e) => { if (my === seq.current) toast('Load failed: ' + e.message, { error: true }); });
  };

  // Reload on filter change; quick filter debounced so typing stays live.
  useEffect(() => {
    const t = setTimeout(() => load(null), q ? 250 : 0);
    return () => clearTimeout(t);
  }, [sel, q]);

  const toggleSource = (key) => {
    const next = new Set(sel || []);
    if (next.has(key)) next.delete(key); else next.add(key);
    setSel(next.size === 0 ? null : next);
  };
  const isGroup = (g) => sel && GROUPS[g].length === sel.size && GROUPS[g].every((k) => sel.has(k));

  const jump = async (entry) => {
    try {
      const d = await api('/events/' + entry.entityId + '/occurrence');
      const occ = d.occurrence;
      insertOccurrence(occ);
      set({ route: 'calendar' });
      jumpToDate(occDayKey(occ), occ.instanceId);
      openDetail(occ.instanceId);
    } catch (e) {
      toast(e.status === 404 ? 'That event no longer exists' : 'Could not open event: ' + e.message, { error: true });
    }
  };

  const undoEntry = async (entry, force) => {
    setBusyId(entry.id);
    try {
      await api('/activity/' + entry.id + '/undo', { method: 'POST', body: force ? { force: true } : {} });
      setEntries((prev) => prev.map((x) => (x.id === entry.id ? { ...x, undone: true, undoable: false } : x)));
      toast('Undone: ' + entry.summary);
      refreshWindow();
    } catch (e) {
      if (e.code === 'stale_undo') {
        if (window.confirm('This item has newer changes; undoing will overwrite them. Undo anyway?')) {
          setBusyId(null);
          return undoEntry(entry, true);
        }
      } else {
        toast('Undo failed: ' + e.message, { error: true });
      }
    } finally {
      setBusyId(null);
    }
  };

  return html`<${PageShell}
    title="Activity"
    note="Every change, manual and automated, newest first. Entries with a snapshot can be undone for 7 days; the log keeps 90 days."
  >
    <div class="bc-activity-filters">
      <input
        type="search" class="bc-activity-search" placeholder="Quick filter…"
        value=${q} onInput=${(e) => setQ(e.target.value)}
      />
      <div class="bc-activity-chips">
        <button type="button" class="bc-srcchip${sel === null ? ' is-on' : ''}" onClick=${() => setSel(null)}>All</button>
        <button type="button" class="bc-srcchip${isGroup('manual') ? ' is-on' : ''}" onClick=${() => setSel(new Set(GROUPS.manual))}>Manual</button>
        <button type="button" class="bc-srcchip${isGroup('auto') ? ' is-on' : ''}" onClick=${() => setSel(new Set(GROUPS.auto))}>Automated</button>
        <span class="bc-activity-chipsep" />
        ${SOURCES.map(([key, label]) => html`<button
          key=${key} type="button"
          class="bc-srcchip is-src${sel && sel.has(key) ? ' is-on' : ''}"
          onClick=${() => toggleSource(key)}
        >${label}</button>`)}
      </div>
    </div>

    ${entries === null && html`<p class="bc-page-note">Loading…</p>`}
    ${entries !== null && entries.length === 0 && html`<${EmptyState} text=${q || sel ? 'Nothing matches these filters.' : 'No activity yet — changes you or your automations make will appear here.'} />`}
    ${entries !== null && entries.length > 0 && html`<ul class="bc-activity-list">
      ${entries.map((en) => html`<li key=${en.id} class="bc-activity-row${en.undone ? ' is-undone' : ''}${en.op === 'refuse' ? ' is-refused' : ''}">
        <span class="bc-activity-when" title=${en.at}>${fmtWhen(en.at)}</span>
        <span class="bc-activity-badge is-${en.source.startsWith('mail:') ? 'mail' : (en.source.startsWith('plugin:') ? 'plugin' : en.source)}">${sourceLabel(en.source)}</span>
        <span class="bc-activity-summary">
          ${en.entity === 'event' && !en.undone && en.op !== 'delete'
            ? html`<button type="button" class="bc-activity-link" onClick=${() => jump(en)}>${en.summary}</button>`
            : en.summary}
          ${en.undone && html`<span class="bc-activity-undonetag">undone</span>`}
          ${en.op === 'refuse' && html`<span class="bc-activity-blockedtag">blocked</span>`}
          ${en.op === 'refuse' && en.details
            && html`<span class="bc-activity-detail">${[
              en.details.reason,
              en.details.from && 'sender ' + en.details.from,
              en.details.boundOrganizer && 'organizer on file ' + en.details.boundOrganizer,
            ].filter(Boolean).join(' · ')}</span>`}
          ${en.details && en.details.addedTitles && en.details.addedTitles.length > 0
            && html`<span class="bc-activity-detail">${en.details.addedTitles.join(', ')}</span>`}
        </span>
        <span class="bc-activity-actions">
          ${en.entity === 'event' && !en.undone && en.op !== 'delete' && html`<button type="button" class="bc-icon-btn" title="Show on calendar" onClick=${() => jump(en)}><${Icon} name="calendar" size=${13} /></button>`}
          ${en.undoable && html`<button
            type="button" class="bc-btn" disabled=${busyId === en.id}
            onClick=${() => undoEntry(en, false)}
          >Undo</button>`}
        </span>
      </li>`)}
    </ul>`}
    ${nextBefore && html`<button type="button" class="bc-btn bc-activity-more" onClick=${() => load(nextBefore)}>Load more</button>`}
  <//>`;
}
