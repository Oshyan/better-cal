// Finding a time zone by typing (ZonePicker.js): a city, a country, the tz
// database's region note, a zone's name or a UTC offset. Pure apart from the
// browser's Intl data, so the smoke tests cover it.

import { zoneOptions, tzCity, tzOffsetLabel, tzOffsetMinutes } from './dates.js';
import { ZONE_COUNTRY } from './zonecountry.js';

const fold = (s) => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

// Browsers still list some zones by an old name (Chrome: "Asia/Calcutta"),
// while the tz database files them under the new one. Renamed cities, then
// any zone whose city matches one in the database ("America/Buenos_Aires" is
// "America/Argentina/Buenos_Aires").
const RENAMED = {
  'Asia/Calcutta': 'Asia/Kolkata', 'Asia/Katmandu': 'Asia/Kathmandu', 'Asia/Saigon': 'Asia/Ho_Chi_Minh',
  'Asia/Rangoon': 'Asia/Yangon', 'Europe/Kiev': 'Europe/Kyiv', 'Atlantic/Faeroe': 'Atlantic/Faroe',
  'America/Godthab': 'America/Nuuk', 'Africa/Asmera': 'Africa/Asmara', 'Pacific/Enderbury': 'Pacific/Kanton',
  'Pacific/Truk': 'Pacific/Chuuk', 'Pacific/Ponape': 'Pacific/Pohnpei', 'Asia/Ulan_Bator': 'Asia/Ulaanbaatar',
  'Asia/Dacca': 'Asia/Dhaka', 'Asia/Thimbu': 'Asia/Thimphu', 'Asia/Macao': 'Asia/Macau',
};
let byCity = null;
function canonical(z) {
  if (ZONE_COUNTRY[z]) return z;
  if (RENAMED[z] && ZONE_COUNTRY[RENAMED[z]]) return RENAMED[z];
  if (!byCity) {
    byCity = new Map();
    for (const k of Object.keys(ZONE_COUNTRY)) byCity.set(k.split('/').pop(), k);
  }
  return byCity.get(z.split('/').pop()) || z;
}

let regionNames = null;
function countryName(cc) {
  if (!cc) return '';
  try {
    regionNames ??= new Intl.DisplayNames(undefined, { type: 'region' });
    return regionNames.of(cc) || cc;
  } catch { return cc; }
}

function zoneName(z, style, at) {
  try {
    const part = new Intl.DateTimeFormat('en-US', { timeZone: z, timeZoneName: style }).formatToParts(at).find((p) => p.type === 'timeZoneName');
    return part ? part.value : '';
  } catch { return ''; }
}

// One entry per zone, built the first time a picker opens (a few hundred
// Intl lookups) and kept: what a row shows, and the text a search matches.
const entries = new Map();
export function zoneIndex(extra) {
  const at = new Date();
  for (const [z] of zoneOptions(extra)) {
    if (entries.has(z)) continue;
    const known = canonical(z);
    const [cc, note] = ZONE_COUNTRY[known] || [];
    const country = countryName(cc);
    const offset = tzOffsetLabel(z, at);
    const city = z === 'UTC' ? 'UTC' : tzCity(known);
    entries.set(z, {
      z,
      city,
      detail: [offset, country, note].filter(Boolean).join(' · '),
      off: tzOffsetMinutes(z, at),
      cityKey: fold(city),
      countryKey: fold(country),
      hay: fold([z.replace(/[_/]/g, ' '), known !== z ? known.replace(/[_/]/g, ' ') : '', cc, country, note, zoneName(z, 'long', at), zoneName(z, 'short', at), offset].join(' ')),
    });
  }
  return [...entries.values()].sort((a, b) => a.cityKey.localeCompare(b.cityKey));
}

// "UTC-7", "gmt+5:30", "-07:00", "+0530" -> minutes east of UTC; else null.
export function parseOffset(q) {
  const m = /^(?:utc|gmt)?\s*([+\-\u2212])\s*(\d{1,2})(?:[:.]?(\d{2}))?$/i.exec(String(q || '').trim());
  if (!m || +m[2] > 14 || +(m[3] || 0) > 59) return null;
  return (m[1] === '+' ? 1 : -1) * (+m[2] * 60 + +(m[3] || 0));
}

// The zones a query finds, best first: cities that start with it, then
// cities containing it, then countries that start with it, then the rest.
export function filterZones(items, query) {
  const q = fold(query).trim();
  if (!q) return items;
  const off = parseOffset(q);
  if (off != null) return items.filter((e) => e.off === off);
  const words = q.split(/\s+/);
  const rank = (e) => (e.cityKey.startsWith(q) ? 0 : e.cityKey.includes(q) ? 1 : e.countryKey.startsWith(q) ? 2 : 3);
  return items.filter((e) => words.every((w) => e.hay.includes(w)))
    .map((e) => [rank(e), e]).sort((a, b) => a[0] - b[0]).map(([, e]) => e);
}

export function zoneLabel(z) {
  if (!z) return '';
  const [cc] = ZONE_COUNTRY[canonical(z)] || [];
  const country = countryName(cc);
  const city = z === 'UTC' ? 'UTC' : tzCity(canonical(z));
  return city + (country && country !== city ? ', ' + country : '') + ' (' + tzOffsetLabel(z) + ')';
}
