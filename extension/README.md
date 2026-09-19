# Better-Cal Chrome extension: GCal link redirect

Rewrites every "Add to Google Calendar" navigation — `calendar.google.com/calendar/render?...` and `/calendar/u/N/r/eventedit?...` — to `cal.oshyan.com/add?...`, which opens Better-Cal's event editor pre-filled from the same parameters. Works on any site (Luma, Eventbrite, Meetup, ...), including JavaScript-initiated `window.open` navigations, because the rewrite happens at the network layer via `declarativeNetRequest` — no content scripts, no page access.

## Install (unpacked)

1. Open `chrome://extensions`, enable **Developer mode** (top right).
2. **Load unpacked** → select this `extension/` directory.

That's it. To temporarily use Google Calendar normally, toggle the extension off on `chrome://extensions` (or via the puzzle-piece menu). Also works in Edge/Brave/Vivaldi, and on Android in Firefox or Kiwi (load as a temporary/custom add-on).

## Notes

- Only `main_frame` navigations to the two GCal "add event" URL shapes are touched; browsing calendar.google.com itself (no query on those paths) is unaffected.
- The target host is hardcoded in `rules.json` (`regexSubstitution`); edit it there if the Better-Cal host ever changes.
- Only *template* links are redirected: `render?action=TEMPLATE...` and `r/eventedit?text=|dates=|details=|location=...`. Google's own event pages are not: an event link (`google.com/calendar/event?eid=...`) resolves, when signed in, to `calendar.google.com/calendar/u/0/r/eventedit?eid=...`, the same path as a template link with different parameters, and version 1.0 redirected it by mistake (found through the "Event link" on a Google-synced event in Better-Cal). After editing `rules.json`, reload the extension on `chrome://extensions`.
