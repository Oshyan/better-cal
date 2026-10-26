# Better-Cal Chrome extension: GCal link redirect

Rewrites "Add to Google Calendar" navigations (`calendar.google.com/calendar/render?action=TEMPLATE...` and `/calendar/u/N/r/eventedit?text=...`) to `<your Better-Cal>/add#...`, which opens Better-Cal's event editor pre-filled from the same parameters. The fragment keeps event details out of Better-Cal's initial HTTP request. Works on any site (Luma, Eventbrite, Meetup, ...), including JavaScript-initiated `window.open` navigations, because the rewrite happens at the network layer via `declarativeNetRequest`: no content scripts, no page access, nothing read.

Since 2.1 it also offers a **new event key** that opens quick add in a small window of its own (since 2.1.2, `<your Better-Cal>/new#window`: just the card, closing itself after Create; Better-Cal 1.1.2 or later, and older ones show the calendar with quick add open). It ships with **no key set** (a public extension shouldn't claim a shortcut for everyone): set one at `chrome://extensions/shortcuts` (linked from the options), and choose "Global" there to use it from any app while Chrome is running; Chrome allows only Ctrl+Shift+digit (Cmd+Shift+digit on a Mac) for global keys and ignores others without a message, so the options page says so when you come back with no key set. Removing the key turns it off.

Works for any Better-Cal instance: the address is set once in the extension's options (it opens on first install), and the redirect rules are built from it. Until an address is set, nothing is redirected.

## Install

From the Chrome Web Store: https://chromewebstore.google.com/detail/djlgegfeedifkamchpfhkcdjohdchijn (public; the link is also on Better-Cal's Settings → Connections, with your instance's address ready to copy). Then open the extension's options and paste the address.

Unpacked, for development: `chrome://extensions` → Developer mode → **Load unpacked** → this `extension/` directory. Code changes need the card's reload button.

To use Google Calendar normally for a moment, toggle the extension off on `chrome://extensions` (or via the puzzle-piece menu), or click "Stop redirecting" in its options. Also works in Edge/Brave/Vivaldi, and on Android in Firefox or Kiwi (load as a temporary/custom add-on).

## Notes

- Fragment redirects need the app update first, followed by extension release 2.1.1 under its separate `extension-v2.1.1` tag. This source change does not publish that release. App compatibility with older query redirects and saved webcal handlers stays for at least two app releases after that extension release, and is removed only in an announced compatibility change. Owners will need to register old webcal handlers again before that removal. Until then, old links can still put private values in the first request to Better-Cal.
- Only `main_frame` navigations to Google Calendar's two "add event" *template* URL shapes are touched. Google's own event pages are not: an event link (`google.com/calendar/event?eid=...`) resolves, when signed in, to `calendar.google.com/calendar/u/0/r/eventedit?eid=...`, the same path as a template link with different parameters, and version 1.0 redirected it by mistake (found through the "Event link" on a Google-synced event in Better-Cal). Plain browsing of calendar.google.com is unaffected.
- The rules are *dynamic* (`declarativeNetRequest.updateDynamicRules`), built in `rules.js` from the saved address; `background.js` re-applies them at startup. Version 1 shipped a static `rules.json` with one host baked in.
- The new event key needs no extra permission: a `commands` entry with no suggested key, and `chrome.windows.create`.
- Releases: each version is tagged `extension-vX.Y.Z` and published as a GitHub release with the store zip attached (separate from the app's `vX.Y.Z` releases; the `area: extension` label tracks its issues).
- Store submission notes live in `store-assets/LISTING.md`; package with `cd extension && zip -r ../better-cal-gcal-redirect-<version>.zip manifest.json rules.js background.js options.html options.js icon-128.png README.md`.
