# Chrome Web Store submission — copy-paste answers

Upload zip: `better-cal-gcal-redirect-2.1.0.zip`, attached to the GitHub release `extension-v2.1.0` (each extension version is tagged `extension-vX.Y.Z` with its store zip attached; build with `cd extension && zip -r ../better-cal-gcal-redirect-<version>.zip manifest.json rules.js background.js options.html options.js icon-128.png README.md`). Screenshot: `extension/store-assets/screenshot-1280x800.png`.

Version 2.1 adds an optional new event key (a `chrome.commands` entry with no suggested key: off until the user sets one; it opens `<address>/new` in a small window; no new permissions). The summary, description, single purpose and test instructions below are updated for it.

Version 2.0 is generic: the Better-Cal address is set in the extension's options (dynamic declarativeNetRequest rules built from it, `storage` permission for the address). The listing text, the permission justifications and the test instructions below are the 2.0 versions. Distribution: set Regions to **All regions** (1.x was accidentally United States only, which made the item "not available" from anywhere else, including to its own developer abroad).

## Store listing tab

**Title** (from manifest): Better-Cal: Google Calendar Link Redirect

**Summary** (short description, 132-char limit):

```text
Sends 'Add to Google Calendar' links to your own Better-Cal instead, and can open a new event there from a keyboard shortcut.
```

**Description**:

```text
Rewrites "Add to Google Calendar" links — the buttons on Luma, Eventbrite, Meetup, and countless other event sites — so they open your self-hosted Better-Cal calendar with the event details pre-filled, instead of Google Calendar.

Set your Better-Cal's address once in the extension's options (it opens on install). Until an address is set, nothing is redirected. "Stop redirecting" in the options, or toggling the extension off, restores Google Calendar.

The redirect happens at the network layer using Chrome's declarativeNetRequest rules: no content scripts, no access to page contents, no data collected or transmitted. Only navigations to Google Calendar's "add event" template URLs are affected; Google Calendar's own event pages and normal browsing are untouched.

Optionally, set a keyboard shortcut (none is set by default) that opens quick add in your Better-Cal in a small window, even from other apps while Chrome is running. The options page links to Chrome's shortcut settings.

Companion to Better-Cal, a self-hosted calendar (github.com/Oshyan/better-cal). Not useful without an instance of it.
```

**Category**: Productivity → Tools (or Workflow & Planning)

**Language**: English

**Screenshot**: upload `screenshot-1280x800.png`

## Privacy tab

**Single purpose description**:

```text
Connects Chrome to the user's own self-hosted Better-Cal calendar, whose address the user sets in the options: Google Calendar "add event" template links (calendar.google.com/calendar/render?action=TEMPLATE and /r/eventedit?text=...) are redirected to it, opening its event editor pre-filled from the same URL parameters, and an optional keyboard shortcut (none set by default) opens its new-event screen.
```

**Permission justification — declarativeNetRequest**:

```text
Used to declaratively redirect navigations matching Google Calendar's "add event" template URL patterns to the address the user entered in the options. The rules are two dynamic redirect rules built from that address; the extension executes no code against pages and cannot read any request or page content.
```

**Permission justification — host permission https://calendar.google.com/***:

```text
Required scope for the declarative redirect rule to act on Google Calendar "add event" URLs. No requests are read or modified beyond the URL redirect itself.
```

**Permission justification — storage**:

```text
Stores the single setting the user enters in the options page: the address of their own Better-Cal calendar, so the redirect rules can be rebuilt from it at startup. Synced with the user's Chrome profile; nothing else is stored and nothing is transmitted.
```

**Remote code**: No, I am not using remote code.

**Data usage**: check nothing (it collects no data of any kind), then certify the disclosures.

**Privacy policy URL**: not required when no data is collected; leave blank. If the form insists, use the repository's README.

## Distribution tab

**Visibility**: Public (also linked from Better-Cal's Settings → Connections)
**Regions**: **All regions** (must be set explicitly; the picker defaulted to United States on the 1.0 submission)

After approval, install from the item link on every Chrome profile you use; it syncs and auto-updates from then on.

## Review notes / test instructions (optional field, 500-char limit)

No credentials — the redirect is fully verifiable without any account:

```text
No login needed. Install; the options page opens: enter any https address, e.g. https://cal.example.com, Save. Then open https://calendar.google.com/calendar/render?action=TEMPLATE&text=Test&dates=20260901T170000Z/20260901T180000Z — it redirects to <address>/add with the same parameters (two dynamic declarativeNetRequest rules, visible in the address bar). Optional: set a key for "New event in Better-Cal" at chrome://extensions/shortcuts; it opens <address>/new in a popup. "Stop redirecting" in the options removes the rules. Google Calendar's own event pages and browsing are unaffected.
```
