// Text patterns the browser and the server both use (0.9.15): one copy, so
// the two can't drift apart (they had: the server's push notifications knew a
// different set of meeting links and "address comes later" phrases than the
// app). The server reads this file as JSON (server/src/Support/Patterns.php),
// so after the comments it must stay exactly `export default <JSON>;`.
// Meeting providers are classified from parsed HTTPS hostnames, never from a
// provider-looking substring. pendingLocation patterns are strings,
// case-insensitive, and work the same in JavaScript and PCRE.
export default {
  "meetings": [
    { "name": "Zoom", "hosts": ["zoom.us"], "subdomains": true },
    { "name": "Google Meet", "hosts": ["meet.google.com"], "subdomains": false },
    { "name": "Teams", "hosts": ["teams.microsoft.com", "teams.live.com"], "subdomains": false },
    { "name": "Webex", "hosts": ["webex.com"], "subdomains": true }
  ],
  "pendingLocation": [
    "^(the\\s+)?(location|address|venue)\\s+(is\\s+|will\\s+be\\s+)?(available|shown|revealed|shared|visible|sent|provided)\\s+(once|after|when|upon|to)\\b",
    "^(rsvp|register|sign\\s*up|request\\s+to\\s+join)\\s+(to|for)\\s+(see|view|reveal|get|unlock)\\s+(the\\s+)?(location|address|venue)",
    "^(location|address|venue)\\s*(:\\s*)?(tba|tbd|hidden|secret|private)\\.?$"
  ]
};
