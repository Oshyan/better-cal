// Which occurrences a change to a repeating event can be for: the one rule
// every scope chooser reads (the delete and answer rows, the new-time row,
// the drop chip after a drag, a resize, a calendar or a person drop, and the
// editor's Apply to select), so none of them offers a scope the server
// refuses for every occurrence of that calendar. The server's refusal stays
// the backstop; this only keeps a person from picking it and waiting.
//
// - On a Google calendar, "This and following" is refused until 1.5.3
//   (api-contract.md, "This and following": a split from a day after the
//   first; from the first it is the same as all events). It is left out,
//   its reason in one line (planwords.js UNOFFERED, beside the refusal
//   panel's own sentence for the same code).

import { state } from './store.js';
import { unofferedWords } from '../lib/planwords.js';

/**
 * The scopes a change to occ can take, in the order they are offered, and
 * why "This and following" is missing when it is (null when it isn't).
 *
 * @param {object} occ
 * @returns {{scopes: string[], why: string|null}}
 */
export function seriesScopes(occ) {
  const cal = occ ? state.calendars.find((c) => c.id === occ.calendarId) : null;
  if (cal && cal.provider === 'google') return { scopes: ['this', 'all'], why: unofferedWords('google') };
  return { scopes: ['this', 'following', 'all'], why: null };
}

const CHIP_LABELS = { this: 'This event', following: 'This + following' };

/**
 * The drop chip's question for a repeating event (App.js DropChoiceChip),
 * at a drop point: the occurrence's scopes as buttons, and the reason for
 * one left out as its note. cb(scope) gets null for Cancel.
 */
export function scopeDropChoice(at, occ, cb, { allLabel = 'All events' } = {}) {
  const { scopes, why } = seriesScopes(occ);
  const cal = occ ? state.calendars.find((c) => c.id === occ.calendarId) : null;
  return {
    x: at.x, y: at.y,
    title: cal && cal.provider === 'google' ? "Repeating event (goes to Google; can't be undone)" : 'Repeating event',
    options: [...scopes.map((s) => ({ label: CHIP_LABELS[s] || allLabel, value: s })), { label: 'Cancel', value: null }],
    note: why,
    cb,
  };
}
