// What's wrong with a subscribed calendar, in words: a headline, the likely
// reason, and the raw detail for troubleshooting. The calendar's health
// (server: Calendars health) records the failure as the fetch reported it
// ("Feed fetch failed: HTTP 404"); this turns it into something a person can
// act on. Null when nothing is wrong.

import { parseISO, dayKeyOf, addDaysKey, fmtTime, fmtDateShort, fmtDateShortYear } from './dates.js';

const FAILURES = [
  [/HTTP (401|403)\b/, 'The address refused access. A private link may have been reset, or the calendar stopped being shared.'],
  [/HTTP (404|410)\b/, 'Nothing was found at the address. The calendar may have moved or stopped being shared.'],
  [/HTTP 429\b/, 'The calendar\'s server asked for fewer requests. It will be tried again at the next check.'],
  [/HTTP 5\d\d\b/, 'The calendar\'s server had a problem. That\'s usually temporary.'],
  [/HTTP \d{3}\b/, 'The calendar\'s server turned the request down.'],
  [/DNS resolution failed|no IPv4 address/, 'The address\'s server couldn\'t be found. Check that the address is still right.'],
  [/deadline exceeded|timed out|timeout/i, 'The calendar\'s server took too long to answer.'],
  [/Refused private\/internal address/, 'The address points to a private network, which Better-Cal won\'t fetch from.'],
  [/not an ICS calendar|incomplete ICS|empty response/, 'The address answered, but not with a complete calendar.'],
  [/MB cap|response budget/, 'The calendar is larger than Better-Cal accepts.'],
  [/Too many redirects/, 'The address redirected too many times.'],
];

function reasonFor(error) {
  for (const [pattern, words] of FAILURES) if (pattern.test(error || '')) return words;
  return 'The calendar couldn\'t be fetched.';
}

/** "today at 4:15 PM", "yesterday at 9:00 AM", "Oct 12 at 8:30 AM", on this device's clock. */
export function whenText(iso, now = new Date()) {
  if (!iso) return '';
  const d = parseISO(iso);
  if (Number.isNaN(d.getTime())) return '';
  const key = dayKeyOf(d);
  const today = dayKeyOf(now);
  const day = key === today ? 'today'
    : key === addDaysKey(today, -1) ? 'yesterday'
      : (d.getFullYear() === now.getFullYear() ? fmtDateShort(d) : fmtDateShortYear(d));
  return day + ' at ' + fmtTime(d);
}

/**
 * @returns {null | {level: 'error'|'warn', headline: string, reason: string, detail: string|null,
 *   tried: string, worked: string}}
 */
export function feedProblem(cal, now = new Date()) {
  if (!cal || cal.kind !== 'subscribed') return null;
  const h = cal.health || {};
  const auth = cal.subscriptionAuthorization;
  const when = { tried: whenText(h.lastPolledAt, now), worked: whenText(h.lastOkAt, now) };
  if (auth && auth.status === 'paused') {
    return { level: 'warn', headline: 'Updates are paused', reason: auth.reason || 'This subscription needs a look before it updates again.', detail: null, ...when };
  }
  if (h.status === 'error') {
    return { level: 'error', headline: 'This feed couldn\'t be updated', reason: reasonFor(h.error), detail: h.error || null, ...when };
  }
  if (h.content === 'emptied') {
    return { level: 'warn', headline: 'This feed came back empty', reason: 'It had events before. The source may have broken, or stopped sharing them.', detail: null, ...when };
  }
  if (h.content === 'stale') {
    return { level: 'warn', headline: 'Nothing has changed in this feed for a while', reason: 'And nothing is coming up. It may have stopped being updated.', detail: null, ...when };
  }
  if (h.error) {
    return { level: 'warn', headline: 'Some events were skipped', reason: 'The feed updated, but parts of it couldn\'t be read.', detail: h.error, ...when };
  }
  return null;
}
