# Changelog

Better-Cal uses [semantic versioning](https://semver.org). While it is below 1.0, the minor number moves for new features or anything that changes how an existing install behaves, and the patch number for fixes, including security fixes. Every release is a git tag (`v0.1.1`) and a GitHub release; the running version is the `VERSION` file at the repository root, which the Settings page shows. Database migrations always run forward on deploy, so updating is: pull the tag, deploy.

Security fixes are tracked privately as GitHub security advisories until they ship; each advisory names the affected and patched versions.

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
