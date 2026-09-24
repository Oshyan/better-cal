# Changelog

Better-Cal uses [semantic versioning](https://semver.org). While it is below 1.0, the minor number moves for new features or anything that changes how an existing install behaves, and the patch number for fixes, including security fixes. Every release is a git tag (`v0.1.1`) and a GitHub release; the running version is the `VERSION` file at the repository root, which the Settings page shows. Database migrations always run forward on deploy, so updating is: pull the tag, deploy.

Security fixes are tracked privately as GitHub security advisories until they ship; each advisory names the affected and patched versions.

## 0.3.6 (2026-09-24)

- The installed app picks up new versions when you come back to it. The browser only checks for an update when a page loads, and an installed app brought back from the background loads nothing, so a phone could keep running an old version for hours after a release. It now checks each time the app comes back into view (at most once a minute) and every 30 minutes while open, then updates at the next quiet moment as before.

## 0.3.5 (2026-09-24)

- Press and hold on the phone's New and Today buttons no longer ends with an extra tap. On iPhone, lifting your finger after a hold sent a click to whatever was now under it, which was the New sheet's "Calendar" row; the click that ends a hold is now dropped wherever it lands.
- After dragging an event on a touch screen, the next tap works. A drag that ended without a click left the app waiting to ignore one, so the next real tap did nothing.

## 0.3.4 (2026-09-24)

- Next and Previous in the Month view work in tall windows. They moved the grid to the 1st of the month, which left the old month filling most of a tall screen, so the month name didn't change and the next click went to the same place: Next appeared to do nothing. They now land mid-month, as jump-to-date already did.

## 0.3.3 (2026-09-24)

- A click or tap outside an open menu, popover, card or sheet now only closes it. Before, the same press also did whatever it landed on: tapping the grid to dismiss a menu could start a new event, tapping another event to dismiss an event card opened that event, tapping a toolbar button pressed it. This covers the view, saved-view, Show, New and calendar-mode menus, jump-to-date, quick add, event and "+N more" cards, the drop and plugin chips, the phone sheets and the sidebar backdrop, on desktop and phone. Full-screen dialogs already worked this way.

## 0.3.2 (2026-09-24)

- Phone Filter sheet: checked Show choices keep plain text with a check mark, instead of every checked row turning blue.

## 0.3.1 (2026-09-24)

Phones, phase 2 of the mobile pass: the phone frame.

- On a phone, the toolbar becomes a thin top bar: the menu, the month (tap it to jump to a date) and the name of a saved view in use. Previous and next go away; the scroll is the navigation.
- A bottom bar holds the five actions reached for most: View, Search, New, Filter and Today.
  - View opens a sheet with the views, your saved views (with the same save-or-discard step as the desktop menu) and, in the agenda, its order.
  - New is centred and raised. Tap opens quick add; press and hold offers Event, Trip, Person and Calendar.
  - Filter holds the text filter and the Show choices, and shows a dot while anything is filtered. The Show filter was hidden on phones until now.
  - Today shows today's date. Tap returns to today; press and hold opens jump-to-date.
- Sheets open from the bottom with a dimmed backdrop, a handle you can pull down to close, and 48-point rows.
- The keyboard-shortcuts button hides on touch screens.
- One set of breakpoints: phone below 640 pixels, compact below 800, defined once. The month grid's phone layout moved from 600 to 640 pixels to match the rest.

## 0.3.0 (2026-09-24)

Phones and touch, phase 1 of the mobile pass: fixes only, no layout changes yet.

- On touch screens every button, link and menu item gets a tap area of at least 44 by 44 points, without changing how anything looks. Menu items and sidebar rows are taller. Controls that sit side by side (triage buttons, segmented choices, the two halves of New) grow only vertically, so a tap never lands on the neighbor.
- On touch screens text fields, pickers and the description editor use at least 16px text, so iPhone Safari no longer zooms the page when you tap into one.
- Agenda on a phone: the triage buttons no longer run off the right edge. The title shrinks instead; thumbs and location stay on the event card.
- The time-zone notice on a phone keeps its message readable, with the two choices beneath it, instead of a one-word-wide column over half the screen.
- People on a phone: each card's buttons wrap instead of pushing the page sideways.
- The loading skeleton takes the phone layout, instead of a desktop sidebar squeezing the grid.
- Quick add's time fields fit their text ("06:00 PM" was clipped to "06:00 P"), on desktop too.

## 0.2.6 (2026-09-24)

Two visible fixes.

- All-day events edit as dates (#34). The event card's quick editor and the full editor used to show all-day events as date-and-time fields at 12:00 AM, ending the day after the event ends. They now show plain date fields, and the end field is the last day ("Sep 12 to Sep 27"). Ticking All day on a timed event keeps it on its own day.
- Search results show an all-day event on its own date; west of UTC it showed a day early.
- A calendar that cannot load its events says so (#48). A failed request for a range of dates used to leave the grid empty with no message until you scrolled. Now a note over the view says the events could not load (or that you are offline and those dates are not saved on this device), retries on a growing delay and as soon as the device is back online, and has a Retry now button. A cold load that takes a moment shows "Loading events…".

## 0.2.5 (2026-09-24)

Nothing generated is committed any more.

- The modulepreload block in `index.html` and the service worker's version and file list are now filled in by the server as it serves those two files, and cached until something under `web/` changes. They used to be written into the repository by a script at deploy time, which left the working tree modified after every deploy and the release tags out of step with what shipped.
- Removed: `scripts/gen-preload.mjs`, the pre-commit hook, and the deploy step that ran the generator.
- **Self-hosters:** `/sw.js` must reach `index.php`. Remove any web server rule that serves it from disk (`docs/install.md` no longer has one). If it is still served raw, the worker leaves the app's files to the network: the app works online but loses offline start.
- The page registers only `/sw.js`; the `/assets/sw.js` fallback is gone.

## 0.2.4 (2026-09-24)

Sign out everywhere else, for a lost or stolen device.

- Settings, Account shows how many other browsers are signed in, with a **Sign out everywhere else** button. It signs out every other browser, forgets every other remembered browser (so none of them gets past the sign-in brake any more) and stops push reminders to every other device. The browser you use stays signed in and keeps its reminders. API keys are left alone; revoke them on the same page.
- It is written to Activity. An API token cannot use it.
- SECURITY.md's lost-device steps now start here; resetting the password on the server is the fallback.

## 0.2.3 (2026-09-24)

Your own browsers get past the sign-in brake (issue #59, layer 1).

- A successful password sign-in now leaves a device cookie in the browser: random, stored only as a hash, valid for a year, sent only to the sign-in endpoints, and replaced with a fresh one on every sign-in. Signing out keeps it.
- While the overall brake is on (many failed sign-ins from many addresses at once), a browser carrying a valid device cookie can sign in from any network, the same way a recently used address can. The password is still required and the per-address limit still applies.
- Wrong passwords sent with a device cookie count against that cookie; after 3 in 15 minutes it stops helping, so a copied cookie is no use for guessing.
- A password reset forgets every remembered browser, and `seed.php` says how many.
- When the brake refuses a sign-in, the message now says new devices are paused, rather than blaming the network for wrong passwords.
- Migration 030 adds the `trusted_devices` table.

## 0.2.2 (2026-09-24)

Bundled plugins for context calendars.

- Weather (0.2.0): each day carries an icon for its conditions (sun, cloud, rain, snow, storm) and a title like "64°/57° rain"; air quality is included as one "AQI 54 (Moderate)" event per day with the air icon (a new setting, on by default), and unhealthy air raises a warning like severe weather does.
- Tides (0.2.0): high and low tides carry their own icons instead of arrow characters in the title.
- New Sun plugin: sunrise and sunset for a place, computed on the server with no network access or key.
- Context tokens keep a plugin's icon and still show just the value ("5.2 ft", "54", "64/57").

## 0.2.1 (2026-09-23)

API tokens get their capabilities back, safely. In 0.1.2 to 0.2.0 an API token could not manage outbound feeds, push devices or the reminder email address, because something a stolen token created could outlive the token. Now anything a token creates belongs to it instead:

- An outbound feed or push device created with an API token is removed when that token is revoked, and stops working while it is expired. Feeds and devices you create signed in are never touched by revoking a token.
- A token listing outbound feeds sees the addresses only of feeds it created itself.
- A reminder email address set with an API token is used only while that token is valid; after that reminders go to the account address again.
- Linking a Google account still needs you signed in with your password.

Also: the deploy scripts only change `.env` ownership when running as root, and the install guide says how to protect `.env` on shared hosting. The README has a Security section.

## 0.2.0 (2026-09-23)

The security pass is complete. This release contains every fix from the 2026-09-23 scan (0.1.1 to 0.1.5) and from the three adversarial reviews of those fixes (0.1.6 to 0.1.8); see those entries for the details and `SECURITY.md` for what was reviewed and what remains as accepted residuals. No functional changes beyond them.

Things that work differently after the pass, in one place:

- API tokens cannot link Google accounts (sign in with your password for that). In 0.2.0 they also could not manage outbound feeds, push devices or the reminder address; 0.2.1 restores those, bound to the token.
- A password reset removes all push devices; each browser registers again when you next open the app there.
- `seed.php --revoke-tokens` also gives outbound feeds new addresses and resets a custom reminder email address.
- `seed.php` asks for the password instead of taking it on the command line.
- Feeds, imports and CalDAV objects over their event or line budget are refused with a clear error rather than processed.
- Repeat rules with an interval over 1,000 or a count over 100,000 are treated as single events.
- The editor auto-links `https://` and `www.` addresses, not bare `example.com/page`.
- The Google connect page no longer names the account it connected.
- CalDAV clients using API tokens are not subject to the password rate limit; clients using the password are.

## 0.1.8 (2026-09-23)

Follow-up fixes from a third adversarial review.

- Calendar-file budgets normalise every line ending the parser accepts, so extra carriage returns cannot hide events.
- Sign-in: the overall brake needs failures from 10 different addresses (20 let a mid-size attacker guess without limit); a burst from one address gets at most 3 extra checks in flight.
- Exported descriptions (CalDAV, outbound feeds) keep the words after a stray unclosed tag such as "the <style> element".
- CalDAV object names issued by 0.1.3 to 0.1.5 still resolve.
- Settings shows "Enabled" only when this device is registered, so removing this device offers Enable again.
- The event popover's plain-text preview scans script/style blocks in linear time.

## 0.1.7 (2026-09-23)

Closing the gaps a second adversarial review found in the security releases.

- The overall sign-in brake now needs failures from at least 20 different addresses (0.1.4's bar of 6 was already implied by the per-address limit, so it changed nothing). A CalDAV request authenticated with an API token no longer marks its address as a trusted one.
- Calendar-file budgets (imports, feeds, CalDAV, email) count events the way the parser reads them, so line folding cannot hide events, and also bound the number of lines, so one event with a flood of properties is refused too.
- The last two slow patterns on untrusted text are gone: calendar data embedded in an email body, and script/style removal when descriptions are exported over CalDAV and outbound feeds.
- The MCP server marks third-party text in every tool result, not only the list tools.
- CalDAV clients syncing from a change recorded before 0.1.3 get the safe object name.
- Connecting, disconnecting and adding Google calendars needs you signed in with your password; an API token cannot link a Google account.
- The editor's link matcher bounds itself, so a very long paste no longer slows down link detection.

## 0.1.6 (2026-09-23)

Fixes for regressions an adversarial review found in the security releases above.

- CalDAV: an API token is accepted outside the password limit, so devices syncing with tokens keep working even when another device at the same address has used up the password attempts (for example one still using an old password). Several simultaneous connections with a correct password are no longer refused.
- CalDAV: only UIDs that actually break a path (a "/" or "\\") get an encoded object name; 0.1.3 renamed more than that, which could leave some clients with stale entries.
- Reminders: a device removed in Settings stays removed when that browser next opens the app, and opening the app no longer keeps a dead device from being cleaned up. Removing this device also turns push off in this browser.
- Mail: stripping a forwarded message's header no longer fails on some non-English text, and an unclosed script or style block in an email is treated as running to the end, as a browser would.
- Outbound fetches on IPv6-only servers can reach public IPv4 hosts through standard NAT64 again.
- Prompt filters and ranking work with non-Gemini models on the same API.
- An API token may send settings that include the reminder address unchanged.
- Operators: the dev clone keeps Google calendars as local copies (they no longer fail every poll there), and `DB_USER` is only needed when cloning; database and user names may contain hyphens.

## 0.1.5 (2026-09-23)

Security fixes from the 2026-09-23 scan (remaining low-severity findings).

- The Google sign-in landing page shows fixed messages, never text from the link, so nobody can put their own words in the app's voice with a crafted link. After connecting it says "Google account connected" rather than naming the address.
- Accepting an organizer's emailed change keeps the invitation bound to its original organizer.
- Outbound fetches to IPv6 addresses outside global unicast are refused, closing a way to reach private IPv4 through NAT64, 6to4 and similar wrappers.
- The push-notification Topic header is an opaque hash, so the push service no longer sees which event or when.
- Prompt filters and ranking send event text to the model as separate, clearly marked third-party data.
- The MCP server marks third-party text in its results as data, not instructions, and labels destructive tools.
- Descriptions with crafted text no longer freeze the event detail view or the editor. Typing a bare address like "example.com/page" in the editor is no longer turned into a link automatically; "https://" and "www." addresses still are.

## 0.1.4 (2026-09-23)

Security fixes from the 2026-09-23 scan (sign-in hardening).

- A sign-in attempt is counted before the password is checked, so a burst of simultaneous guesses can no longer slip past the limit. Successful sign-ins still never count against it.
- An unknown email address now takes exactly as long to reject as a known one, so response times no longer reveal which addresses have accounts, on the login form or CalDAV.
- The overall sign-in brake (which refuses new devices during a wide attack) now needs failures from at least six different addresses, so one or two attackers cannot shut out your new devices.
- `seed.php` asks for the password with echo off, or reads `BETTERCAL_SEED_PASSWORD`; `--password=` still works but warns, since it is visible to other users and stays in shell history.

## 0.1.3 (2026-09-23)

Security fixes from the 2026-09-23 scan.

- An event UID with unusual characters (a "/" in particular) no longer breaks CalDAV sync for its whole calendar. Ordinary UIDs keep their object names; unusual ones are named by an encoded form. A CalDAV client may re-download a calendar that held such an event once.
- A subscribed feed is checked for size before it is parsed, like an import: one with more events than the server can hold in memory (20,000 by default, `BETTERCAL_LIMIT_FEED_EVENTS`) is refused with a clear poll error instead of crashing the background worker.
- Incoming email HTML is scanned for script, style and embedded event markup in linear time, so a crafted message can no longer hold up mail processing.

## 0.1.2 (2026-09-23)

Security fixes from the 2026-09-23 scan. Updating is recommended for every install.

- Revoking access now revokes everything. A password reset removes every device registered for push reminders (your own browsers register again when you sign in), and the compromise reset (`seed.php --revoke-tokens`) also gives every outbound feed a new address and clears a reminder email address that is not the account's.
- An API token can no longer create or read outbound feed addresses, register a push device, or change where reminders go; those need you signed in with your password.
- New in Settings, Notifications: the list of every device reminders go to, with the one you are on marked, and Remove for any you do not recognise. New devices, removed devices, new outbound feeds and a changed reminder address are written to Activity.
- Operators: the deploy leaves the app's `.env` readable but not writable by the web app, and the dev-instance clone no longer puts the MySQL root password on a command line, takes its database user from `scripts/deploy.env` (new `DB_USER` setting), and strips sessions, tokens, push devices, feeds and Google links from the copy.

## 0.1.1 (2026-09-23)

Security fixes from the 2026-09-23 scan (advisories to be published once all related fixes ship):

- A successful sign-in or CalDAV request no longer resets the source's failed-password count; failures expire with the rate-limit window.
- A repeat rule with an out-of-range interval or count, from a feed, import, CalDAV, mail or Google, is dropped where it comes in, and a single event that cannot be expanded no longer fails the whole calendar.

## 0.1.0 (2026-09-23)

Baseline: the first tagged version, covering everything built from July to September 2026 (see `FEATURES.md`). Earlier commits are unversioned.
