# Google Calendar connector

Better-Cal can read calendars through a connected Google account: the ones you own, the ones you subscribe to, and the ones other people have shared with you, including shared-but-not-public calendars that no iCal address can reach (the case that started this, GH #42). A connected calendar is an ordinary subscribed calendar here: it has a colour, lives in folders, shows feed health. Edits made in Google arrive here within the poll interval (5 minutes by default, one small request per poll).

Where the connected account may edit the calendar (Google's access role `writer` or `owner`: your own calendars, and ones shared with you with "make changes"), events can be created, edited and deleted here too. That is **write-through**, not two-way sync: the change goes to Google first, and what Google answers with is materialised locally through the same path a poll uses, so no local-only version of a Google event ever exists and there is nothing to reconcile. If Google refuses (network, revoked token, a role that changed), the request fails with a clear error and nothing here changes. Calendars the account can only view stay read-only, like any feed.

## One-time setup (the operator)

Google requires each installation to have its own OAuth client. About ten minutes, once. The console changes its layout often; as of September 2026 OAuth lives under **Google Auth Platform** (Overview, Branding, Audience, Clients, Data Access, Verification Center) rather than the old "OAuth consent screen" page.

1. Pick or create a project at https://console.cloud.google.com/ (any existing project of yours works; one that already has an OAuth setup saves the branding step).
2. **APIs & Services → Library** → search "Google Calendar API" → **Enable**. Do this first: the scope picker only lists enabled APIs.
3. **Google Auth Platform → Branding**: app name, support email, developer contact. Save.
4. **Google Auth Platform → Audience**: User type **External**; then **Publish app** so the publishing status reads *In production*. It stays unverified (Google shows a warning once per account when connecting: "Advanced", then "Go to <app>"). This matters: in *Testing* status Google expires refresh tokens after seven days and every connected account would need reconnecting weekly.
5. **Google Auth Platform → Data Access → Add or remove scopes**: filter "Google Calendar API" and tick `.../auth/calendar.readonly` and `.../auth/calendar.events`; `openid` and `.../auth/userinfo.email` from the top of the list. Update, Save. (The request itself carries the scopes, so this is what the consent screen describes; an unverified app works without it, but it is a minute.)
6. **Google Auth Platform → Clients → Create client**: Application type **Web application**, any name. Under **Authorized redirect URIs** add `https://<your host>/api/v1/google/callback` (must match `BETTERCAL_BASE_URL` exactly, including the scheme; no JavaScript origins needed). Create, then copy the **Client ID** and the **Client secret** (shown once; there is a download button).
7. Put the client id and secret in the server `.env`:

```
BETTERCAL_GOOGLE_CLIENT_ID=....apps.googleusercontent.com
BETTERCAL_GOOGLE_CLIENT_SECRET=...
```

`BETTERCAL_SESSION_SECRET` must be set (it already is on any working install): the refresh token is sealed with it (`Infra\Secrets`, libsodium secretbox) before it is stored. Changing the session secret invalidates stored tokens; reconnecting the account is the fix.

Until both variables are set, Settings → Connections says the connector is not set up and offers nothing.

## Connecting (each user)

Settings → Connections → **Connect a Google account**. Google asks for consent (calendar read access, event write access for the next step, plus your email address, which is how the account is labelled here), then sends you back. The account then lists every calendar Google shows it, with the access role Google grants and a kind Google does not state but the calendar id encodes: **Yours** (your primary, or a secondary you own), **Shared with you** (someone else's, shared with you: the shape no iCal address can reach), **Feed copy** (Google's own copy of an ICS subscription, always behind the source; subscribing to the ICS address here directly is fresher), **Google** (holidays, birthdays). **Add** subscribes one and syncs it right away. Several accounts can be connected.

**Disconnect** revokes the token at Google and forgets it. Calendars already subscribed from that account stay, with their events, but stop updating and report "Google account disconnected" as their poll error until you delete them or connect the account again (reconnecting the same email re-attaches them).

## What writes send

Title, description, location, start and end (dates for all-day, RFC 3339 with the time zone otherwise), status, and the recurrence rule with its exceptions. Not sent: attendees (they would mail people), reminders (per-user at Google), colour, the event link. Tags, people, reminders and the trip flag are local metadata on the row and survive polls.

Recurring edits map onto Google the way the local model already works: "this occurrence" patches Google's instance id and comes back as an exception; "this and following" ends the series at Google with `UNTIL` and inserts a new one; deleting one occurrence deletes the instance at Google, which comes back as an `EXDATE`. Moving an event between a Google calendar and any other calendar is refused, because half of such a move can never be undone here; use **Copy to** (the stack icon on an event) to put a copy on the other calendar, then delete the original if a move was meant. A trip on a Google calendar moves one event at a time.

Activity records each write as a plain entry ("Updated 'Dishoom' on Google calendar 'London'"); there is no Undo for these, since undoing would be a second write to Google against a row that is Google's, not a snapshot of ours.

## How syncing works

- First sync lists every event on the calendar (Google's `events.list`, masters with their `RRULE`, exceptions as instances) and stores the sync token Google returns.
- Every later poll sends only the sync token and receives only what changed: new, edited, moved, cancelled. Nothing changed is one request with an empty answer. The changes are merged into the current snapshot and handed to the same materialisation the ICS feeds use (`Feeds::sync`), so upserts, deletions, the CalDAV change log, the Activity roll-up and the health states are identical to a feed's.
- Google's cancelled instance of a recurring event becomes an `EXDATE` on the series here; a cancelled series is deleted whole.
- When Google says the token is stale (HTTP 410, which happens after long gaps), the next poll is a full list again. Nothing is lost either way.
- Reminders and attendees are not synced. Reminders are per-user on Google's side anyway; attendees are the write side's problem.
- The poll interval, stale threshold, "check now", and everything else in a calendar's settings work as for any feed.

## Endpoints

See `docs/api-contract.md`, Google.

## Failure modes you will see

- Poll error "Google token request failed: HTTP 400 (invalid_grant)": the refresh token was revoked (you removed Better-Cal under Google Account → Security → Third-party access, or the OAuth client was in Testing status for over seven days). Reconnect the account.
- Poll error "Google account disconnected": you disconnected the account; delete the calendar or reconnect.
- "Google did not return a refresh token" on connect: Google only issues one on the first consent unless asked (we do ask, with `prompt=consent`); if it still happens, remove Better-Cal under Third-party access and connect again.
