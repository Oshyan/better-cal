// Builds the live command list for the palette. The definitions live in
// commanddefs.js (pure data) and the handlers come from keyboard.js, so a
// palette entry is the same action as its keyboard shortcut rather than a
// second implementation of it.
//
// Everything here is derived from current state on every open: calendars,
// people, and saved views come and go, and a stale registry would offer
// commands that no longer mean anything.

import { state, set, toast } from './store.js';
import { handlers } from './keyboard.js';
import { STATIC_COMMANDS, MANAGE_ITEMS, VIEW_LABELS, VIEW_ICONS } from './commanddefs.js';
import { HOTKEYS } from './hotkeys.js';
import {
  VIEWS, setView, rosterViews, applySavedView, toggleCalendarVisible, jumpToDate,
  jumpAnchorFor,
} from './actions.js';
import { api, loadPeople } from './api.js';
import { localTz } from '../lib/dates.js';
import { parseJumpText, jumpGranularity } from '../lib/jumpparse.js';

// Which context a `needs` gate is asking about.
function hasContext(need) {
  if (need === 'popover') return !!state.popover;
  if (need === 'occ') {
    const src = state.detail || state.popover;
    return !!(src && state.occ.get(src.instanceId));
  }
  return true;
}

function keysFor(hotkeyId) {
  const h = HOTKEYS.find((k) => k.id === hotkeyId);
  if (!h) return null;
  return h.display || h.keys;
}

// Availability is recorded through /quickadd rather than reimplemented here:
// the server already routes "NAME is away <range>" to a span, including all
// the date-range parsing, and going through it keeps one code path for the
// typed-out form and the guided form.
async function recordAway(personName, kind, when) {
  const text = personName + ' is ' + kind + ' ' + String(when || '').trim();
  try {
    await api('/quickadd', { method: 'POST', body: { text, tz: localTz(), commit: true } });
    toast(personName + ' marked ' + kind);
    await loadPeople(); // the span has to show up in the sidebar right away
  } catch (e) {
    toast(e.message || 'Could not record availability', { error: true });
  }
}

/**
 * Every command available right now, in a sensible default order (the palette
 * re-ranks by query but keeps this order for ties and for the empty query).
 *
 * @returns {Array<{id:string,label:string,group:string,hint?:string,keys?:Array<string>,keywords?:string,run?:Function,prompt?:object}>}
 */
export function buildCommands() {
  const out = [];

  for (const def of STATIC_COMMANDS) {
    if (def.needs && !hasContext(def.needs)) continue;
    const cmd = {
      id: def.id,
      label: def.label,
      group: def.group,
      icon: def.icon,
      keywords: def.keywords,
      keys: def.hotkey ? keysFor(def.hotkey) : null,
    };
    if (def.id === 'newPerson') {
      cmd.prompt = {
        label: 'New person',
        hint: 'needs a name',
        placeholder: 'Name',
        run: async (name) => {
          const n = String(name || '').trim();
          if (!n) return false;
          try {
            const r = await api('/people', { method: 'POST', body: { name: n } });
            toast(r && r.created === false ? n + ' already exists' : 'Added ' + n, { undoable: r ? r.created !== false : true });
            await loadPeople();
            return true;
          } catch (e) {
            toast('Could not add: ' + e.message, { error: true });
            return false;
          }
        },
      };
      cmd.staysOnPage = true; // creating a person is not a calendar-surface action
      out.push(cmd);
      continue;
    }
    if (def.id === 'goDate') {
      cmd.prompt = {
        label: 'Go to',
        placeholder: 'e.g. next tuesday, aug 14, 2027-03-01',
        run: (text) => {
          const key = parseJumpText(text, state.anchor);
          if (!key) { toast('Could not read "' + text + '" as a date', { error: true }); return false; }
          jumpToDate(jumpAnchorFor(key, jumpGranularity(text)));
          return true;
        },
      };
    } else if (def.id === 'calsAll' || def.id === 'calsNone') {
      const want = def.id === 'calsAll';
      cmd.run = () => {
        for (const c of state.calendars) if (!!c.visible !== want) toggleCalendarVisible(c);
      };
    } else if (def.hotkey) {
      const h = handlers[def.hotkey];
      if (!h) continue; // definition names a handler that no longer exists
      cmd.run = h;
    } else {
      continue;
    }
    out.push(cmd);
  }

  // Views, in the switcher's own order so the palette agrees with the UI.
  const roster = rosterViews();
  for (const v of VIEWS) {
    if (!roster.includes(v)) continue;
    out.push({
      id: 'view:' + v,
      label: (VIEW_LABELS[v] || v) + ' view',
      group: 'Views',
      icon: VIEW_ICONS[v],
      hint: state.view === v ? 'current' : null,
      run: () => setView(v),
    });
  }

  // Saved views. Labelled as what they ARE; picking one obviously applies
  // it, so the label does not need a verb. 'apply' stays matchable.
  for (const v of state.savedViews || []) {
    out.push({
      id: 'sview:' + v.id,
      label: 'Saved view: ' + v.name,
      group: 'Views',
      icon: 'views',
      keywords: 'saved apply',
      run: () => { applySavedView(v); set({ route: 'calendar' }); },
    });
  }

  // One toggle per calendar. The label says what the command will DO, not
  // what the calendar currently is, so reading a row never implies the
  // opposite of what pressing it does.
  for (const c of state.calendars || []) {
    out.push({
      id: 'cal:' + c.id,
      label: (c.visible ? 'Hide calendar: ' : 'Show calendar: ') + c.name,
      group: 'Calendars',
      color: c.color,
      run: () => toggleCalendarVisible(c),
    });
  }

  // The headline case from the FR: "type 'Person gone' and it lets me put in
  // an away about the person."
  for (const p of state.people || []) {
    out.push({
      id: 'away:' + p.id,
      label: p.name + ' is away…',
      group: 'People',
      icon: 'people',
      keywords: 'gone out ooo vacation availability',
      prompt: {
        label: p.name + ' is away',
        placeholder: 'when? e.g. aug 10-15, next week, tomorrow',
        run: async (text) => { await recordAway(p.name, 'away', text); return true; },
      },
    });
    out.push({
      id: 'busy:' + p.id,
      label: p.name + ' is busy…',
      group: 'People',
      icon: 'people',
      keywords: 'unavailable availability',
      prompt: {
        label: p.name + ' is busy',
        placeholder: 'when? e.g. aug 10-15, next week, tomorrow',
        run: async (text) => { await recordAway(p.name, 'busy', text); return true; },
      },
    });
  }

  // Manage pages.
  for (const [route, label] of MANAGE_ITEMS) {
    out.push({
      id: 'route:' + route,
      label: label,
      group: 'Manage',
      icon: route, // Icon maps 'organize' to the folder glyph, same as the sidebar
      keywords: 'open page manage',
      staysOnPage: true,
      run: () => set({ route, popover: null, detail: null }),
    });
  }

  // QuickAdd, the editor, the search overlay and the popovers only mount on
  // the calendar route, so a calendar command fired from a Manage page would
  // otherwise flip a state flag with nothing on screen to render it. Send
  // every such command home first. The cheat sheet renders everywhere and the
  // page commands are the whole point of being on a page, so both opt out.
  for (const c of out) {
    if (c.staysOnPage || c.id === 'shortcuts') continue;
    if (c.run) c.run = toCalendar(c.run);
    if (c.prompt) c.prompt = { ...c.prompt, run: toCalendar(c.prompt.run) };
  }

  return out;
}

function toCalendar(fn) {
  return (...args) => {
    if (state.route !== 'calendar') set({ route: 'calendar' });
    return fn(...args);
  };
}

// Free text the palette could not match to a command still has somewhere to
// go: quick add already parses events AND "NAME is away ..." statements, and
// shows a preview before committing. Seeding it beats committing blind.
export function quickAddFallback(text) {
  // quickAddSeed is the share-target path: QuickAdd picks it up on open and
  // runs the parse itself, so there is nothing to do here but hand it over.
  // QuickAdd only mounts on the calendar route.
  set({ quickAddSeed: text, quickAddOpen: true, paletteOpen: false, route: 'calendar' });
}
