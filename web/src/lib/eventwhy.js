// Why an event looks the way it does, in a few short lines: the hover card
// shows them under the event's full title (ui/HoverCard.js). An underlined or
// dimmed event used to say nothing about the filter behind it, and a filter
// matching in a long description underlined a title that never mentioned the
// word. Pure, so the smoke suite can check it.

import { timeState } from './dates.js';

const FIELD_WORDS = { title: 'title', description: 'description', location: 'location', tags: 'tags' };

function filterWords(reason, verb) {
  if (!reason) return `${verb} by one of your filters`;
  if (reason.type === 'prompt') return `${verb} by one of your plain-language filters`;
  const pattern = reason.type === 'regex' ? `/${reason.pattern}/` : `“${reason.pattern}”`;
  const where = FIELD_WORDS[reason.field] ? ` (matched in the ${FIELD_WORDS[reason.field]})` : '';
  return `${verb} by your ${pattern} filter${where}`;
}

/** @returns {string[]} one short line per reason, most telling first */
export function formattingReasons(occ, nowMs = Date.now()) {
  if (!occ) return [];
  const out = [];
  if (occ.highlighted) out.push(filterWords(occ.filterReason, 'Underlined: highlighted'));
  if (occ.dimmed) out.push(filterWords(occ.filterReason, 'Grayed out: dimmed'));
  if (occ.status === 'cancelled') out.push('Struck through: cancelled');
  if (occ.relationship === 'maybe') out.push('Dashed outline: a maybe');
  else if (occ.relationship === 'available') out.push('Muted title: available, not planned');
  else if (occ.relationship === 'context') out.push('Faded italic: information, not a plan');
  if (occ.isContainer) out.push('A trip: it holds the events during it');
  if (occ.isGroup) out.push(`${occ.count} similar events shown as one`);
  if (occ.isNew) out.push('New: arrived in the last day');
  if (nowMs && occ.start) {
    const ts = timeState(occ, nowMs);
    if (ts === 'past') out.push('Faded: already ended');
    else if (ts === 'now') out.push('Gold ring: happening now');
  }
  return out;
}
