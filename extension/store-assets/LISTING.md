# Chrome Web Store submission — copy-paste answers

Upload zip: `better-cal-gcal-redirect-1.0.0.zip` (repo root). Screenshot: `extension/store-assets/screenshot-1280x800.png`.

## Store listing tab

**Title** (from manifest): Better-Cal: Google Calendar Link Redirect

**Summary** (short description):
> Sends "Add to Google Calendar" links to your self-hosted Better-Cal instead. Toggle off to use Google Calendar normally.

**Description**:
> Rewrites "Add to Google Calendar" links — the buttons on Luma, Eventbrite, Meetup, and countless other event sites — so they open your self-hosted Better-Cal calendar with the event details pre-filled, instead of Google Calendar.
>
> The redirect happens at the network layer using Chrome's declarativeNetRequest rules: no content scripts, no access to page contents, no data collected or transmitted. Only navigations to Google Calendar's two "add event" URL formats are affected; browsing Google Calendar itself is untouched.
>
> This is a personal companion extension for a specific self-hosted calendar instance (cal.oshyan.com) and is not useful without one.

**Category**: Productivity → Tools (or Workflow & Planning)

**Language**: English

**Screenshot**: upload `screenshot-1280x800.png`

## Privacy tab

**Single purpose description**:
> Redirects Google Calendar "add event" template links (calendar.google.com/calendar/render and /r/eventedit) to the user's self-hosted calendar application, which opens its event editor pre-filled from the same URL parameters.

**Permission justifications**:
- `declarativeNetRequest`:
  > Used to declaratively redirect navigations matching Google Calendar's "add event" URL patterns to the user's self-hosted calendar. The extension contains only a static redirect rule; it executes no code against pages and cannot read any request or page content.
- Host permission `https://calendar.google.com/*`:
  > Required scope for the declarative redirect rule to act on Google Calendar "add event" URLs. No requests are read or modified beyond the URL redirect itself.

**Remote code**: No, I am not using remote code.

**Data usage**: check nothing (it collects no data of any kind), then certify the disclosures.

**Privacy policy URL**: not required when no data is collected; leave blank. If the form insists, use `https://cal.oshyan.com/` (the extension collects nothing; the target site is the user's own).

## Distribution tab

**Visibility**: Unlisted
**Regions**: all (irrelevant for unlisted)

After approval, install from the item link on every Chrome profile you use; it syncs and auto-updates from then on.
