// The redirect rules, built for one Better-Cal address. Shared by the options
// page (when the address is saved) and the service worker (re-applied at
// startup, in case the browser lost them).
//
// Only Google Calendar's "add event" TEMPLATE links are touched:
//   calendar.google.com/calendar/render?action=TEMPLATE&text=...&dates=...
//   calendar.google.com/calendar/u/N/r/eventedit?text=...&dates=...
// Google's own event pages resolve, when signed in, to r/eventedit?eid=...,
// the same path with different parameters; they must pass through, as must
// plain browsing of calendar.google.com. RE2 has no lookahead, so the query
// is matched as a whole: an action=TEMPLATE anywhere in it, or a template
// field (text, dates, details, location) as the first or a later parameter.

const RULE_IDS = [1, 2];

function rulesFor(origin) {
  const redirect = { type: 'redirect', redirect: { regexSubstitution: origin + '/add?\\1' } };
  return [
    {
      id: 1, priority: 1, action: redirect,
      condition: {
        regexFilter: '^https://(?:www\\.)?calendar\\.google\\.com/calendar/(?:u/\\d+/)?render\\?((?:.*&)?action=TEMPLATE(?:&.*)?)$',
        resourceTypes: ['main_frame'],
      },
    },
    {
      id: 2, priority: 1, action: redirect,
      condition: {
        regexFilter: '^https://(?:www\\.)?calendar\\.google\\.com/calendar/(?:u/\\d+/)?r/eventedit\\?((?:.*&)?(?:text|dates|details|location)=.*)$',
        resourceTypes: ['main_frame'],
      },
    },
  ];
}

// An address as typed ("cal.example.com", "https://cal.example.com/") to the
// origin the rules need, or null when it is not a usable https origin.
function normalizeOrigin(text) {
  let s = String(text || '').trim();
  if (!s) return null;
  if (!/^[a-z]+:\/\//i.test(s)) s = 'https://' + s;
  let url;
  try { url = new URL(s); } catch { return null; }
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && /^(localhost|127\.0\.0\.1)(:\d+)?$/.test(url.host))) return null;
  if (!url.hostname || !url.hostname.includes('.') && url.hostname !== 'localhost') return null;
  return url.origin;
}

async function applyOrigin(origin) {
  await chrome.declarativeNetRequest.updateDynamicRules({
    removeRuleIds: RULE_IDS,
    addRules: origin ? rulesFor(origin) : [],
  });
}

async function storedOrigin() {
  const { origin } = await chrome.storage.sync.get('origin');
  return typeof origin === 'string' && origin ? origin : null;
}
