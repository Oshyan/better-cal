// Text patterns the browser and the server both use (0.9.15): one copy, so
// the two can't drift apart (they had: the server's push notifications knew a
// different set of meeting links and "address comes later" phrases than the
// app). The server reads this file as JSON (server/src/Support/Patterns.php),
// so after the comments it must stay exactly `export default <JSON>;`.
// Patterns are strings, case-insensitive, written to mean the same in
// JavaScript and PCRE: no lookbehind, no named groups, no \d-style shorthand
// beyond \s and \w.
export default {
  "meetings": [
    { "name": "Zoom", "pattern": "https?://[\\w.-]*zoom\\.us/[^\\s<>\"')]+" },
    { "name": "Google Meet", "pattern": "https?://meet\\.google\\.com/[^\\s<>\"')]+" },
    { "name": "Teams", "pattern": "https?://teams\\.(?:microsoft|live)\\.com/[^\\s<>\"')]+" },
    { "name": "Webex", "pattern": "https?://[\\w.-]*webex\\.com/[^\\s<>\"')]+" }
  ],
  "pendingLocation": [
    "^(the\\s+)?(location|address|venue)\\s+(is\\s+|will\\s+be\\s+)?(available|shown|revealed|shared|visible|sent|provided)\\s+(once|after|when|upon|to)\\b",
    "^(rsvp|register|sign\\s*up|request\\s+to\\s+join)\\s+(to|for)\\s+(see|view|reveal|get|unlock)\\s+(the\\s+)?(location|address|venue)",
    "^(location|address|venue)\\s*(:\\s*)?(tba|tbd|hidden|secret|private)\\.?$"
  ]
};
