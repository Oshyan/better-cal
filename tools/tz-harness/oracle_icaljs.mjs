// Second oracle: ical.js (Thunderbird / Nextcloud Calendar engine). It has no
// tz database of its own, so it uses the VTIMEZONEs Better-Cal exported: this
// also tests that the generated VTIMEZONE is faithful.
import ICAL from 'ical.js';
import { readFileSync, writeFileSync } from 'node:fs';

const cases = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const out = {};
const fmtUtc = (t) => {
  const u = t.convertToZone(ICAL.Timezone.utcTimezone);
  const p = (n, w = 2) => String(n).padStart(w, '0');
  return `${p(u.year, 4)}${p(u.month)}${p(u.day)}T${p(u.hour)}${p(u.minute)}${p(u.second)}Z`;
};
const fmtDate = (t) => `${String(t.year).padStart(4, '0')}${String(t.month).padStart(2, '0')}${String(t.day).padStart(2, '0')}`;

for (const c of cases) {
  const comp = new ICAL.Component(ICAL.parse(c.ics));
  ICAL.TimezoneService.reset();
  for (const vtz of comp.getAllSubcomponents('vtimezone')) {
    ICAL.TimezoneService.register(vtz);
  }
  const vevents = comp.getAllSubcomponents('vevent');
  const master = new ICAL.Event(vevents.find((v) => !v.hasProperty('recurrence-id')));
  for (const v of vevents.filter((v) => v.hasProperty('recurrence-id'))) master.relateException(new ICAL.Event(v));
  const res = {};
  for (const [wname, w] of Object.entries(c.bettercal)) {
    const ws = Date.parse(w.from.replace(' ', 'T') + 'Z');
    const we = Date.parse(w.to.replace(' ', 'T') + 'Z');
    const lst = [];
    const it = master.iterator();
    let next; let guard = 0;
    while ((next = it.next()) && guard++ < 5000) {
      const d = master.getOccurrenceDetails(next);
      const sMs = d.startDate.toJSDate().getTime();
      const eMs = d.endDate.toJSDate().getTime();
      if (d.startDate.isDate) {
        // compare by date: overlap on the date range in the event's zone is what Better-Cal does via local midnights;
        // use the window as UTC instants against local midnights of the event zone (approximate by UTC midnight +/- 1d slack later)
        lst.push({ s: sMs, e: eMs, v: 'D' + fmtDate(d.startDate) + '/' + fmtDate(d.endDate), date: true });
      } else {
        lst.push({ s: sMs, e: eMs, v: fmtUtc(d.startDate) + '/' + fmtUtc(d.endDate) });
      }
      if (d.startDate.isDate ? sMs > we + 2 * 86400000 : sMs >= we) {
        // overrides moved later than their instance can still land in window; walk a little beyond
        if (guard > 0 && sMs > we + 40 * 86400000) break;
      }
    }
    res[wname] = { occ: lst.filter((o) => o.date ? true : (o.s < we && o.e > ws)).map((o) => o.v).sort(), raw: true };
  }
  out[c.n] = res;
}
writeFileSync(process.argv[3], JSON.stringify(out));
console.log(Object.keys(out).length, 'cases');
