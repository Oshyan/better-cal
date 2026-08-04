# Chrome Web Store submission — copy-paste answers

Upload zip: `better-cal-gcal-redirect-1.0.0.zip` (repo root). Screenshot: `extension/store-assets/screenshot-1280x800.png`.

## Store listing tab

**Title** (from manifest): Better-Cal: Google Calendar Link Redirect

**Summary** (short description, 132-char limit):

```text
Sends 'Add to Google Calendar' links to your Better-Cal instead. Toggle the extension off to use Google Calendar normally.
```

**Description**:

```text
Rewrites "Add to Google Calendar" links — the buttons on Luma, Eventbrite, Meetup, and countless other event sites — so they open your self-hosted Better-Cal calendar with the event details pre-filled, instead of Google Calendar.

The redirect happens at the network layer using Chrome's declarativeNetRequest rules: no content scripts, no access to page contents, no data collected or transmitted. Only navigations to Google Calendar's two "add event" URL formats are affected; browsing Google Calendar itself is untouched.

This is a personal companion extension for a specific self-hosted calendar instance (cal.oshyan.com) and is not useful without one.
```

**Category**: Productivity → Tools (or Workflow & Planning)

**Language**: English

**Screenshot**: upload `screenshot-1280x800.png`

## Privacy tab

**Single purpose description**:

```text
Redirects Google Calendar "add event" template links (calendar.google.com/calendar/render and /r/eventedit) to the user's self-hosted calendar application, which opens its event editor pre-filled from the same URL parameters.
```

**Permission justification — declarativeNetRequest**:

```text
Used to declaratively redirect navigations matching Google Calendar's "add event" URL patterns to the user's self-hosted calendar. The extension contains only a static redirect rule; it executes no code against pages and cannot read any request or page content.
```

**Permission justification — host permission https://calendar.google.com/***:

```text
Required scope for the declarative redirect rule to act on Google Calendar "add event" URLs. No requests are read or modified beyond the URL redirect itself.
```

**Remote code**: No, I am not using remote code.

**Data usage**: check nothing (it collects no data of any kind), then certify the disclosures.

**Privacy policy URL**: not required when no data is collected; leave blank. If the form insists, use `https://cal.oshyan.com/`.

## Distribution tab

**Visibility**: Unlisted
**Regions**: all (irrelevant for unlisted)

After approval, install from the item link on every Chrome profile you use; it syncs and auto-updates from then on.

## Review notes / test instructions (optional field)

No credentials — the redirect is fully verifiable without any account:

```text
No account or credentials are needed to verify the extension's complete functionality.

The extension's sole behavior is a static declarativeNetRequest redirect of Google Calendar "add event" template links to the user's self-hosted calendar (cal.oshyan.com). The redirect is fully observable in the address bar:

1. Install the extension.
2. Navigate to any Google Calendar "add event" template URL, e.g.:
   https://calendar.google.com/calendar/render?action=TEMPLATE&text=Review%20Test&dates=20260901T170000Z/20260901T180000Z&location=Test%20Location
3. Observe the navigation is redirected to https://cal.oshyan.com/add?action=TEMPLATE&text=... with identical query parameters. This redirect is the extension's entire feature set.
4. The target site then shows a personal login page — that site is the developer's private calendar application, not part of the extension. No extension functionality exists behind the login: the extension contains no scripts, only the static redirect rule visible in rules.json.
5. Ordinary Google Calendar browsing (e.g. https://calendar.google.com/calendar/r) is not affected.
```
