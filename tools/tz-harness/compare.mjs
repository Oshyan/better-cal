// Like compare2, but splits 'other' into 'dstDay' (every differing occurrence sits within a day of an offset change) and 'unexplained'.
import { readFileSync } from 'node:fs';
const cases = JSON.parse(readFileSync('cases_goodtz.json', 'utf8'));
const off = (zone, ms) => { const p = new Intl.DateTimeFormat('en-US', { timeZone: zone, timeZoneName: 'longOffset' }).formatToParts(new Date(ms)).find((x) => x.type === 'timeZoneName').value; return p; };
const toMs = (v) => v.startsWith('D') ? Date.UTC(+v.slice(1,5), +v.slice(5,7)-1, +v.slice(7,9)) : Date.UTC(+v.slice(0,4), +v.slice(4,6)-1, +v.slice(6,8), +v.slice(9,11), +v.slice(11,13));
const nearDst = (zone, v) => v.split('/').some((x) => { const m = toMs(x.startsWith('D') ? x : x); return off(zone, m - 86400000) !== off(zone, m + 86400000); });
const key = (v) => v.startsWith('D') ? v.slice(1, 9) + 'T000000Z' : v.slice(0, 16);
const startOf = (v) => v.split('/')[0];
const inner = (w) => {
  const a = new Date(Date.parse(w.from.replace(' ', 'T') + 'Z') + 2 * 86400000).toISOString().replace(/[-:]/g, '').slice(0, 15) + 'Z';
  const b = new Date(Date.parse(w.to.replace(' ', 'T') + 'Z') - 2 * 86400000).toISOString().replace(/[-:]/g, '').slice(0, 15) + 'Z';
  return (v) => key(v) >= a && key(v) < b;
};
const show = Number(process.env.SHOW || 0);
for (const f of process.argv.slice(2)) {
  const o = JSON.parse(readFileSync(f, 'utf8'));
  const t = { same: 0, dtstartNotInRule: 0, dstDay: 0, unexplained: 0 }; let shown = 0;
  for (const c of cases) for (const [wname, w] of Object.entries(c.bettercal)) {
    const keep = inner(w);
    const ours = w.occ.filter(keep), theirs = (o[c.n][wname].occ || []).filter(keep);
    const a = ours.filter((x) => !theirs.includes(x)), b = theirs.filter((x) => !ours.includes(x));
    if (!a.length && !b.length) { t.same++; continue; }
    if (!c.dtstartMatches) { t.dtstartNotInRule++; continue; }
    if ([...a, ...b].every((v) => !v.startsWith('D') && nearDst(c.zone, v))) { t.dstDay++; continue; }
    t.unexplained++;
    if (shown++ < show) console.log(`#${c.n} ${c.zone} ${c.start} ${c.time} ${c.rrule} ${wname}\n  BC only: ${a.slice(0,4).join(' ')}\n  oracle only: ${b.slice(0,4).join(' ')}`);
  }
  console.log(f, JSON.stringify(t));
}
