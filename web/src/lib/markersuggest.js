// Suggestions for quick add's markers (#169): `/` a calendar, `#` a tag, `@`
// a person. The server reads the markers (server/src/Domain/QuickAddMarkers
// .php); this only offers what to type, and every pick is written so that the
// server's reading lands on exactly the thing picked. Pure, so the smoke
// suite can check it.
//
// A marker counts only at the start of a word (after a space or at the
// start), never inside quotes, so "w/ Sam", "7/31", web addresses and
// "Room #5" never open the list.

const QUOTES = /["“”]/g;
// What can follow each marker, as the server's patterns allow.
const BODY = {
  '/': /^[\p{L}\p{N}_&'-]*$/u,
  '#': /^[\p{L}\p{N}_-]*$/u,
  '@': /^[\p{L}\p{N}'’._-]*$/u,
};
const BODY_CHAR = /[\p{L}\p{N}_&'’.-]/u;
export const SUGGEST_MAX = 8;

const norm = (s) => String(s || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');
const words = (s) => String(s || '').toLowerCase().split(/[^\p{L}\p{N}]+/u).filter(Boolean);

/**
 * The marker being typed at the caret, or null.
 * @returns {{kind:'/'|'#'|'@', query:string, start:number, end:number}|null}
 *   start: the marker character; end: the end of the word it starts.
 */
export function activeMarker(text, caret) {
  const t = String(text || '');
  if (caret == null || caret < 1 || caret > t.length) return null;
  let i = caret;
  while (i > 0 && BODY_CHAR.test(t[i - 1])) i--;
  const start = i - 1;
  if (start < 0) return null;
  const kind = t[start];
  if (kind !== '/' && kind !== '#' && kind !== '@') return null;
  if (start > 0 && !/\s/.test(t[start - 1])) return null;
  if (((t.slice(0, start).match(QUOTES) || []).length) % 2 === 1) return null;
  let end = caret;
  while (end < t.length && BODY_CHAR.test(t[end])) end++;
  const query = t.slice(start + 1, caret);
  if (!BODY[kind].test(t.slice(start + 1, end))) return null;
  return { kind, query, start, end };
}

// Rank by how a name answers the query: the same name, then one starting
// with it, then a word in it starting with it. An empty query keeps order.
function rank(items, query, nameOf) {
  const q = norm(query);
  if (!q) return items.slice();
  const tiers = [[], [], []];
  for (const it of items) {
    const n = norm(nameOf(it));
    if (n === q) tiers[0].push(it);
    else if (n.startsWith(q)) tiers[1].push(it);
    else if (words(nameOf(it)).some((w) => w.startsWith(q))) tiers[2].push(it);
  }
  return [...tiers[0], ...tiers[1], ...tiers[2]];
}

// How a calendar is written so the server's matchCalendar picks it: its name
// with everything but letters and numbers turned into hyphens ("Friends &
// Family" -> "/Friends-Family"), which compares as the same name.
export function calendarMarker(name) {
  const body = String(name || '').replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '');
  return body ? '/' + body : null;
}

// A tag as typed after #; one whose name a marker can't hold (spaces) isn't
// offered, since typing it would make a different tag.
export function tagMarker(name) {
  const n = String(name || '');
  return /^(?=[\p{L}\p{N}_-]*\p{L})[\p{L}\p{N}][\p{L}\p{N}_-]{0,49}$/u.test(n) ? '#' + n : null;
}

// A person by first name when no one else has it (the server's matchPerson
// takes an only first name), otherwise by the whole name in quotes, so a
// shared first name never links the wrong person.
export function personMarker(person, people) {
  const name = String(person.name || '').trim();
  if (!name) return null;
  const first = name.split(/\s+/)[0];
  const lower = first.toLowerCase();
  const shared = people.filter((p) => String(p.name || '').trim().split(/\s+/)[0].toLowerCase() === lower).length > 1;
  const exact = people.some((p) => p !== person && String(p.name || '').trim().toLowerCase() === lower);
  if (!shared && !exact && /^\p{L}[\p{L}\p{N}'’_-]*$/u.test(first)) return '@' + first;
  if (/^\p{L}[\p{L}\p{N}'’_-]*$/u.test(name)) return '@' + name;
  return '@"' + name.replace(/["“”]/g, '') + '"';
}

/**
 * What to offer for a marker, best first, at most SUGGEST_MAX.
 * @param {{calendars?:object[], tags?:object[], people?:object[]}} data
 *   calendars: the ones events can be added to, in display order.
 * @returns {{key:string, label:string, insert:string, hint?:string}[]}
 */
export function markerSuggestions(marker, { calendars = [], tags = [], people = [] } = {}) {
  if (!marker) return [];
  const out = [];
  if (marker.kind === '/') {
    for (const c of rank(calendars, marker.query, (c) => c.name)) {
      const insert = calendarMarker(c.name);
      if (insert) out.push({ key: 'c' + c.id, label: c.name, insert, color: c.color, cal: c });
    }
  } else if (marker.kind === '#') {
    for (const t of rank(tags, marker.query, (t) => t.name)) {
      const insert = tagMarker(t.name);
      if (insert) out.push({ key: 't' + t.id, label: '#' + t.name, insert });
    }
  } else if (marker.kind === '@') {
    for (const p of rank(people, marker.query, (p) => p.name)) {
      const insert = personMarker(p, people);
      if (insert) out.push({ key: 'p' + p.id, label: p.name, insert });
    }
  }
  return out.slice(0, SUGGEST_MAX);
}

// The text with the marker word replaced by a pick, and where the caret goes
// (after it and one space).
export function applyMarkerPick(text, marker, insert) {
  const t = String(text || '');
  const before = t.slice(0, marker.start);
  const after = t.slice(marker.end);
  const sep = after.startsWith(' ') ? '' : ' ';
  return { text: before + insert + sep + after, caret: before.length + insert.length + 1 };
}
