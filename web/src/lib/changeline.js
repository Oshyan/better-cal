// The second line of an undo toast for a time change: what moved, said as
// briefly as it can be ("Nov 6 → Nov 17", "3:00 PM → 4:30 PM", "ends 5:00 PM"),
// so the toast names exactly what Undo would put back.
//
// Times are {start, end, allDay} with ISO strings. All-day dates are read
// from the string's date part, never through the local zone, as everywhere
// else (dates.js occDayKey).

import { parseISO, dayKeyOf, dateOfDayKey, addDaysKey, fmtTime, fmtDateShort, fmtDateShortYear } from './dates.js';

const ARROW = ' → ';

function dayKeyOfSide(iso, allDay) {
  return allDay ? iso.slice(0, 10) : dayKeyOf(parseISO(iso));
}

// The last day an all-day span covers (its end is the morning after).
function lastDayKey(t) {
  const s = t.start.slice(0, 10);
  const e = addDaysKey(t.end.slice(0, 10), -1);
  return e < s ? s : e;
}

function clock(iso) {
  return fmtTime(parseISO(iso));
}

function dateText(key, withYear) {
  const d = dateOfDayKey(key);
  return withYear ? fmtDateShortYear(d) : fmtDateShort(d);
}

function anotherYear(keys, now) {
  const y = String(now.getFullYear());
  return keys.some((k) => k.slice(0, 4) !== y);
}

/**
 * How a start moved: "Nov 6 → Nov 17" (new day, same clock or all-day),
 * "3:00 PM → 4:30 PM" (same day), "Nov 6, 3:00 PM → Nov 17, 10:00 AM"
 * (both). The year shows when either date isn't this year. Empty when
 * nothing about the start changed.
 */
export function startChange(before, after, now = new Date()) {
  const bk = dayKeyOfSide(before.start, before.allDay);
  const ak = dayKeyOfSide(after.start, after.allDay);
  const kindChanged = !!before.allDay !== !!after.allDay;
  const dayChanged = bk !== ak;
  const clockChanged = !before.allDay && !after.allDay && clock(before.start) !== clock(after.start);
  if (!kindChanged && !dayChanged && !clockChanged) return '';
  const withYear = anotherYear([bk, ak], now);
  const side = (t, key) => {
    if (t.allDay) return dateText(key, withYear);
    if (!kindChanged && !dayChanged) return clock(t.start);
    if (!kindChanged && !clockChanged) return dateText(key, withYear);
    return dateText(key, withYear) + ', ' + clock(t.start);
  };
  return side(before, bk) + ARROW + side(after, ak);
}

/**
 * How a span's end (or start) changed when only one edge moved: "ends 5:00 PM",
 * "ends Nov 8", "starts 2:00 PM", "ends Nov 7, 1:00 AM" (an end on a later
 * day than the start says which day). Empty when neither edge moved.
 */
export function edgeChange(before, after, now = new Date()) {
  const allDay = !!after.allDay;
  if (allDay) {
    const sMoved = before.start.slice(0, 10) !== after.start.slice(0, 10);
    const eMoved = lastDayKey(before) !== lastDayKey(after);
    const withYear = anotherYear([after.start.slice(0, 10), lastDayKey(after)], now);
    if (eMoved && !sMoved) return 'ends ' + dateText(lastDayKey(after), withYear);
    if (sMoved && !eMoved) return 'starts ' + dateText(after.start.slice(0, 10), withYear);
    return '';
  }
  const sMoved = parseISO(before.start).getTime() !== parseISO(after.start).getTime();
  const eMoved = parseISO(before.end).getTime() !== parseISO(after.end).getTime();
  const sk = dayKeyOf(parseISO(after.start));
  const ek = dayKeyOf(parseISO(after.end));
  const withYear = anotherYear([sk, ek], now);
  if (eMoved && !sMoved) {
    // An end at midnight closes the start's day; any later end names its day.
    const e = parseISO(after.end);
    const sameDay = ek === sk || (ek === addDaysKey(sk, 1) && e.getHours() === 0 && e.getMinutes() === 0);
    return 'ends ' + (sameDay ? clock(after.end) : dateText(ek, withYear) + ', ' + clock(after.end));
  }
  if (sMoved && !eMoved) return 'starts ' + (sk === dayKeyOf(parseISO(before.start)) ? clock(after.start) : dateText(sk, withYear) + ', ' + clock(after.start));
  return '';
}

/**
 * The change as one phrase, whatever kind it was: a move (start changed,
 * length kept), one edge dragged, or both together ("Nov 6, 3:00 PM →
 * Nov 17, 10:00 AM, ends 11:30 AM").
 */
export function timeChange(before, after, now = new Date()) {
  if (!before || !after || !before.start || !after.start) return '';
  const len = (t) => (t.end ? parseISO(t.end).getTime() - parseISO(t.start).getTime() : 0);
  const sameKind = !!before.allDay === !!after.allDay;
  const startSame = sameKind && (before.allDay
    ? before.start.slice(0, 10) === after.start.slice(0, 10)
    : parseISO(before.start).getTime() === parseISO(after.start).getTime());
  const endSame = sameKind && !!before.end && !!after.end && (before.allDay
    ? lastDayKey(before) === lastDayKey(after)
    : parseISO(before.end).getTime() === parseISO(after.end).getTime());
  if (startSame || endSame) return edgeChange(before, after, now);
  const moved = startChange(before, after, now);
  if (!sameKind || len(before) === len(after) || !after.end) return moved;
  // Moved and resized at once (the editor's time fields): add where it ends.
  const end = after.allDay
    ? 'ends ' + dateText(lastDayKey(after), anotherYear([lastDayKey(after)], now))
    : 'ends ' + clock(after.end);
  return moved + ', ' + end;
}

// What an edit in the editor changed, in the words a toast uses: compares the
// form as it opened with the form being saved. "time" covers the start, end,
// all-day and zone together.
const EDIT_WORDS = [
  [['title'], 'title'],
  [['start', 'end', 'allDay', 'tz'], 'time'],
  [['calendarId'], 'calendar'],
  [['location'], 'place'],
  [['url'], 'link'],
  [['description'], 'description'],
  [['tags'], 'tags'],
  [['people'], 'people'],
  [['rrule'], 'repeat'],
  [['reminders'], 'reminders'],
  [['isContainer'], 'trip'],
  [['rel'], 'status'],
];

// A select hands back "3" for the calendar the form opened with as 3.
function norm(v) {
  return typeof v === 'number' || typeof v === 'string' ? String(v) : JSON.stringify(v === undefined ? null : v);
}

export function editedWords(before, after) {
  if (!before || !after) return null;
  const differs = (k) => norm(before[k]) !== norm(after[k]);
  return EDIT_WORDS.filter(([keys]) => keys.some(differs)).map(([, word]) => word);
}

/** "title", "title and time", "title, time and place". */
export function listWords(words) {
  if (words.length <= 1) return words.join('');
  return words.slice(0, -1).join(', ') + ' and ' + words[words.length - 1];
}
