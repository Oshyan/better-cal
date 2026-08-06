// Command palette: Cmd/Ctrl-K. One input that routes three ways —
//
//   1. named actions, fuzzy-matched against the registry in commands.js
//   2. a live "Go to <date>" row whenever the text parses as a date
//   3. anything else falls through to quick add, which already parses both
//      events and "NAME is away <range>" and shows a preview before committing
//
// Commands ending in "…" ask for one more thing: selecting one swaps the
// input into a prompt (Esc, or Backspace on an empty input, backs out) rather
// than opening a second dialog on top of this one.

import { html, useState, useRef, useEffect, useMemo } from '../../vendor/index.js';
import { Icon, CalDot } from '../ui/icons.js';
import { useStore, set, state } from './store.js';
import { buildCommands, quickAddFallback } from './commands.js';
import { COMMAND_GROUPS } from './commanddefs.js';
import { rankByFuzzy } from '../lib/fuzzy.js';
import { parseJumpText, jumpGranularity } from '../lib/jumpparse.js';
import { jumpToDate, jumpAnchorFor } from './actions.js';
import { trapFocus } from '../ui/DayExpand.js';
import { dateOfDayKey, fmtDateFull } from '../lib/dates.js';

// Only cap a *ranked* list, where the tail is genuinely the worst matches.
// With no query every score ties and the order is just the registry's own, so
// a cap would silently amputate whichever groups happen to be last — with a
// dozen calendars that was the entire Manage section.
const MAX_RANKED_ROWS = 40;

function keyCap(k) {
  return k === ' ' ? 'Space' : k;
}

export function CommandPalette() {
  const open = useStore((s) => s.paletteOpen);
  // Rebuild when anything the registry reads changes underneath it.
  const stamp = useStore((s) => [
    s.calendars.length, s.people.length, s.savedViews.length, s.view, s.anchor,
    s.popover ? s.popover.instanceId : '', s.detail ? s.detail.instanceId : '',
  ].join('|'));

  const [q, setQ] = useState('');
  const [sel, setSel] = useState(0);
  const [prompt, setPrompt] = useState(null); // {label, placeholder, run}
  const [busy, setBusy] = useState(false);
  const inputRef = useRef(null);
  const panelRef = useRef(null);
  const listRef = useRef(null);

  useEffect(() => {
    if (!open) return;
    setQ('');
    setSel(0);
    setPrompt(null);
    setBusy(false);
    if (inputRef.current) inputRef.current.focus();
  }, [open]);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => { if (e.key === 'Tab') trapFocus(panelRef.current, e); };
    document.addEventListener('keydown', onKey, true);
    return () => document.removeEventListener('keydown', onKey, true);
  }, [open]);

  const commands = useMemo(() => (open ? buildCommands() : []), [open, stamp]);

  const text = q.trim();

  // The date row: only when the text actually parses, so it never occupies a
  // slot for a query that was never about a date.
  const dateKey = !prompt && text ? parseJumpText(text, state.anchor) : null;

  const matches = useMemo(() => {
    if (prompt) return [];
    const ranked = rankByFuzzy(commands, text, (c) => [c.label, c.keywords, c.group]);
    return text ? ranked.slice(0, MAX_RANKED_ROWS) : ranked;
  }, [commands, text, prompt]);

  const trimmed = text ? Math.max(0, commands.length - matches.length) : 0;

  // Row list: date row first (it is the most specific reading of the text),
  // then commands, then the quick-add escape hatch.
  const rows = [];
  if (dateKey) {
    rows.push({
      kind: 'date',
      id: 'goto:' + dateKey,
      label: 'Go to ' + fmtDateFull(dateOfDayKey(dateKey)),
      group: 'Go to',
      run: () => { jumpToDate(jumpAnchorFor(dateKey, jumpGranularity(text))); return true; },
    });
  }
  for (const c of matches) rows.push({ ...c, kind: 'command' });
  // The escape hatch, and it has to sort dead last: it matches every possible
  // query, so anywhere else it outranks the real command you were typing
  // ("mv" put "Quick add: mv" above "Month view").
  const fallback = !prompt && text ? {
    kind: 'quickadd',
    id: 'quickadd:fallback',
    label: 'Quick add: ' + text,
    group: 'Create anyway',
    hint: 'opens a preview',
    run: () => { quickAddFallback(text); return false; }, // it closes the palette itself
  } : null;

  // Group headers, in the canonical order, over the ranked rows. Ranking wins
  // within a group; the group order is fixed so the layout does not reshuffle
  // on every keystroke. `flat` is the rendered order, so selection indexes it
  // — indexing `rows` instead would leave the last row unreachable.
  const grouped = [];
  const seen = new Set();
  for (const g of COMMAND_GROUPS) {
    const items = rows.filter((r) => r.group === g);
    if (items.length) { grouped.push([g, items]); items.forEach((r) => seen.add(r.id)); }
  }
  const rest = rows.filter((r) => !seen.has(r.id));
  if (rest.length) grouped.push(['Other', rest]);
  if (fallback) grouped.push([fallback.group, [fallback]]);
  const flat = grouped.flatMap(([, items]) => items);

  const clamped = Math.min(sel, Math.max(0, flat.length - 1));

  useEffect(() => {
    const el = listRef.current && listRef.current.querySelector('.is-selected');
    if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
  }, [clamped, flat.length]);

  if (!open) return null;

  const close = () => set({ paletteOpen: false });

  const choose = async (row) => {
    if (busy) return;
    if (row.prompt) {
      setPrompt(row.prompt);
      setQ('');
      setSel(0);
      if (inputRef.current) inputRef.current.focus();
      return;
    }
    if (!row.run) return;
    setBusy(true);
    try {
      const r = await row.run();
      // A handler returning false means "did not apply" (wrong context, or it
      // took over the UI itself) — leave the palette alone in that case.
      if (r !== false) close();
    } finally {
      setBusy(false);
    }
  };

  const submitPrompt = async () => {
    if (busy || !prompt) return;
    setBusy(true);
    try {
      const ok = await prompt.run(q.trim());
      if (ok !== false) close();
    } finally {
      setBusy(false);
    }
  };

  const onKeyDown = (e) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setSel((s) => (flat.length ? (s + 1) % flat.length : 0));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setSel((s) => (flat.length ? (s - 1 + flat.length) % flat.length : 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (prompt) submitPrompt();
      else if (flat[clamped]) choose(flat[clamped]);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      if (prompt) { setPrompt(null); setQ(''); } else close();
    } else if (e.key === 'Backspace' && prompt && q === '') {
      e.preventDefault();
      setPrompt(null);
    }
  };

  const indexOf = (row) => flat.indexOf(row);

  return html`<div class="bc-overlay bc-palette-overlay" onClick=${(e) => { if (e.target === e.currentTarget) close(); }}>
    <div class="bc-palette" ref=${panelRef} role="dialog" aria-modal="true" aria-label="Command palette">
      <div class="bc-palette-top">
        ${prompt && html`<span class="bc-palette-crumb">${prompt.label}</span>`}
        <input
          ref=${inputRef}
          class="bc-palette-input"
          placeholder=${prompt ? prompt.placeholder : 'Type a command, a date, or an event…'}
          value=${q}
          disabled=${busy}
          onInput=${(e) => { setQ(e.target.value); setSel(0); }}
          onKeyDown=${onKeyDown}
          aria-label=${prompt ? prompt.label : 'Command palette'}
        />
        <button type="button" class="bc-icon-btn" aria-label="Close" onClick=${close}><${Icon} name="close" size=${15} /></button>
      </div>

      ${prompt && html`<div class="bc-palette-prompthint">Enter to confirm · Esc to go back</div>`}

      ${!prompt && html`<div class="bc-palette-list" ref=${listRef}>
        ${flat.length === 0 && html`<div class="bc-empty">No commands match "${text}"</div>`}
        ${grouped.map(([group, items]) => html`<div key=${group} class="bc-palette-group">
          <div class="bc-palette-grouplabel">${group}</div>
          ${items.map((row) => {
            const i = indexOf(row);
            return html`<button
              key=${row.id} type="button"
              class="bc-palette-row${i === clamped ? ' is-selected' : ''}"
              onClick=${() => choose(row)}
              onMouseEnter=${() => setSel(i)}
            >
              ${row.color ? html`<${CalDot} color=${row.color} />` : html`<span class="bc-palette-dotgap" />`}
              <span class="bc-palette-label">${row.label}</span>
              ${row.hint && html`<span class="bc-palette-hint">${row.hint}</span>`}
              ${row.prompt && html`<span class="bc-palette-hint">needs a date</span>`}
              ${row.keys && html`<span class="bc-palette-keys">
                ${row.keys.map((k) => html`<kbd key=${k}>${keyCap(k)}</kbd>`)}
              </span>`}
            </button>`;
          })}
        </div>`)}
        ${matches.length >= MAX_RANKED_ROWS && trimmed > 0
          && html`<div class="bc-palette-trim">Showing the ${MAX_RANKED_ROWS} closest matches — keep typing to narrow</div>`}
      </div>`}
    </div>
  </div>`;
}
