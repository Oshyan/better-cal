# Google Calendar connector

Better-Cal can read calendars through a connected Google account: the ones you own, the ones you subscribe to, and the ones other people have shared with you, including shared-but-not-public calendars that no iCal address can reach (the case that started this, GH #42). A connected calendar is an ordinary subscribed calendar here: it has a colour, lives in folders, shows feed health, and is read-only in this version. Edits still happen in Google; they arrive here within the poll interval (5 minutes by default, one small request per poll).

The write side, where an edit made here goes to Google for calendars you can edit (write-through: Google stays authoritative, our copy is a cache), is the next step. The scope for it is requested from the start so no account has to re-consent when it lands.

## One-time setup (the operator)

Google requires each installation to have its own OAuth client. About ten minutes, once.

1. Create a project at https://console.cloud.google.com/ (any name, e.g. "Better-Cal").
2. APIs & Services → Library → enable **Google Calendar API**.
3. APIs & Services → OAuth consent screen: User type **External**, fill in the app name and your email. Scopes: add `.../auth/calendar.readonly`, `.../auth/calendar.events` (the write side is the next step; asking now spares a re-consent), `openid` and `email`. Save.
4. Publishing status: click **Publish app** so it is *In production*. It stays unverified (Google shows a warning screen once per account when connecting; click "Advanced", then continue). This matters: in *Testing* status Google expires refresh tokens after seven days and every connected account would need reconnecting weekly.
5. APIs & Services → Credentials → Create credentials → **OAuth client ID**, type **Web application**. Authorized redirect URI: `https://<your host>/api/v1/google/callback` (must match `BETTERCAL_BASE_URL` exactly, including the scheme).
6. Put the client id and secret in the server `.env`:

```
BETTERCAL_GOOGLE_CLIENT_ID=....apps.googleusercontent.com
BETTERCAL_GOOGLE_CLIENT_SECRET=...
```

`BETTERCAL_SESSION_SECRET` must be set (it already is on any working install): the refresh token is sealed with it (`Infra\Secrets`, libsodium secretbox) before it is stored. Changing the session secret invalidates stored tokens; reconnecting the account is the fix.

Until both variables are set, the Google Calendar page (Manage → Google Calendar) says the connector is not set up and offers nothing.

## Connecting (each user)

Manage → Google Calendar → **Connect a Google account**. Google asks for consent (calendar read access, event write access for the next step, plus your email address, which is how the account is labelled here), then sends you back. The account then lists every calendar Google shows it, with the access role Google grants and a kind Google does not state but the calendar id encodes: **Yours** (your primary, or a secondary you own), **Shared with you** (someone else's, shared with you: the shape no iCal address can reach), **Feed copy** (Google's own copy of an ICS subscription, always behind the source; subscribing to the ICS address here directly is fresher), **Google** (holidays, birthdays). **Add** subscribes one and syncs it right away. Several accounts can be connected.

**Disconnect** revokes the token at Google and forgets it. Calendars already subscribed from that account stay, with their events, but stop updating and report "Google account disconnected" as their poll error until you delete them or connect the account again (reconnecting the same email re-attaches them).

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
