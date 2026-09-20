# Better-Cal Chrome extension: GCal link redirect

Rewrites "Add to Google Calendar" navigations (`calendar.google.com/calendar/render?action=TEMPLATE...` and `/calendar/u/N/r/eventedit?text=...`) to `<your Better-Cal>/add?...`, which opens Better-Cal's event editor pre-filled from the same parameters. Works on any site (Luma, Eventbrite, Meetup, ...), including JavaScript-initiated `window.open` navigations, because the rewrite happens at the network layer via `declarativeNetRequest`: no content scripts, no page access, nothing read.

Works for any Better-Cal instance: the address is set once in the extension's options (it opens on first install), and the redirect rules are built from it. Until an address is set, nothing is redirected.

## Install

From the Chrome Web Store: https://chromewebstore.google.com/detail/djlgegfeedifkamchpfhkcdjohdchijn (unlisted; the link is also on Better-Cal's Settings → Connections, with your instance's address ready to copy). Then open the extension's options and paste the address.

Unpacked, for development: `chrome://extensions` → Developer mode → **Load unpacked** → this `extension/` directory. Code changes need the card's reload button.

To use Google Calendar normally for a moment, toggle the extension off on `chrome://extensions` (or via the puzzle-piece menu), or click "Stop redirecting" in its options. Also works in Edge/Brave/Vivaldi, and on Android in Firefox or Kiwi (load as a temporary/custom add-on).

## Notes

- Only `main_frame` navigations to Google Calendar's two "add event" *template* URL shapes are touched. Google's own event pages are not: an event link (`google.com/calendar/event?eid=...`) resolves, when signed in, to `calendar.google.com/calendar/u/0/r/eventedit?eid=...`, the same path as a template link with different parameters, and version 1.0 redirected it by mistake (found through the "Event link" on a Google-synced event in Better-Cal). Plain browsing of calendar.google.com is unaffected.
- The rules are *dynamic* (`declarativeNetRequest.updateDynamicRules`), built in `rules.js` from the saved address; `background.js` re-applies them at startup. Version 1 shipped a static `rules.json` with one host baked in.
- Store submission notes live in `store-assets/LISTING.md`; package with `cd extension && zip -r ../better-cal-gcal-redirect-<version>.zip manifest.json rules.js background.js options.html options.js icon-128.png README.md`.
