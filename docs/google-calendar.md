# Google Calendar Connector

Better-Cal can read calendars through a connected Google account: ones you own, ones you subscribe to, and ones other people have shared with you, including shared-but-not-public calendars no iCal address can reach. A connected calendar is an ordinary subscribed calendar here, with a color, folders and feed health. Changes made in Google arrive within the poll interval (5 minutes by default, one small request per poll).

Where the account may edit the calendar (Google's access role `writer` or `owner`: your own calendars, and ones shared with "make changes"), you can create, edit and delete events here too. That's **write-through**, not two-way sync: each change goes to Google first, and Google's answer is saved here the same way a poll would save it, so there's never a local-only version of a Google event. If Google refuses (network, revoked token, a changed role), the request fails with a clear error and nothing here changes. Calendars the account can only view stay read-only, like any feed.

## One-time setup (the operator)

Google requires each install to have its own OAuth client, so whoever runs a Better-Cal install sets one up once, in their own Google Cloud project. It takes about ten minutes.

- **Only needed for the Google connector.** Everything else works without it, including following a Google calendar by its secret iCal address (Subscribe) and bringing calendars over from Google Calendar's export or a Takeout export (Import).
- **No cost and no billing account.** A Google Cloud project is free with any Google account, and the Calendar API has no charge at this scale.
- **The app stays "unverified"**, which is fine for your own install. Verification is for apps offered to the public; an unverified app shows a one-time warning when an account connects.

The console's layout changes often. As of October 2026, OAuth lives under **Google Auth Platform** (Overview, Branding, Audience, Clients, Data Access, Verification Center). The links below open each page for whichever project is selected in the project picker at the top.

1. **Pick or create a project:** [console.cloud.google.com/projectcreate](https://console.cloud.google.com/projectcreate), or choose an existing one in the project picker. One that already has an OAuth setup saves the branding step.
2. **Enable the Google Calendar API:** [its page in the API Library](https://console.cloud.google.com/apis/library/calendar-json.googleapis.com) → **Enable**. Do this first: the scope picker in step 5 only lists enabled APIs.
3. **Branding:** [Google Auth Platform → Branding](https://console.cloud.google.com/auth/branding). App name (say "Better-Cal"), a support email, a developer contact. Save.
4. **Audience:** [Google Auth Platform → Audience](https://console.cloud.google.com/auth/audience). User type **External**, then **Publish app**, so the publishing status reads *In production*. This matters: in *Testing* status Google expires refresh tokens after seven days, and every connected account would need reconnecting weekly. In production the app is still unverified, so Google shows a warning once per account when connecting ("Advanced", then "Go to Better-Cal").
5. **Scopes:** [Google Auth Platform → Data Access](https://console.cloud.google.com/auth/scopes) → **Add or remove scopes**. Filter "Google Calendar API" and tick `.../auth/calendar.readonly`, `.../auth/calendar.events` and `.../auth/calendar.app.created` (lets Better-Cal create calendars of its own, for moving a calendar to Google, and touches nothing else), then `openid` and `.../auth/userinfo.email` from the top of the list. Update, Save. The request itself carries the scopes, so this only sets what the consent screen describes; an unverified app works without it, but it takes a minute.
6. **The client:** [Google Auth Platform → Clients](https://console.cloud.google.com/auth/clients) → **Create client**. Application type **Web application**, any name. Under **Authorized redirect URIs** add `https://<your host>/api/v1/google/callback`. It must match `BETTERCAL_BASE_URL` exactly, including the scheme; no JavaScript origins are needed. Create, then copy the **Client ID** and **Client secret** (shown once; there's a download button).
7. **Put the client ID and secret in the server's settings file, `.env.php`:**

```
BETTERCAL_GOOGLE_CLIENT_ID=....apps.googleusercontent.com
BETTERCAL_GOOGLE_CLIENT_SECRET=...
```

Refresh tokens are encrypted with `BETTERCAL_SESSION_SECRET` (already set on any working install) before they're stored. Changing the session secret invalidates stored tokens; reconnecting each account fixes it.

Until both variables are set, Settings → Connections says the connector isn't set up and offers nothing.

**Google's own documentation**, if a screen looks different from the steps above:

- [Get started with the Google Auth Platform](https://support.google.com/cloud/answer/15544987): the Branding, Audience, Clients and Data Access pages.
- [Configure the OAuth consent screen](https://developers.google.com/workspace/guides/configure-oauth-consent) and [create access credentials](https://developers.google.com/workspace/guides/create-credentials), from Google's Workspace developer guides.
- [Unverified apps](https://support.google.com/cloud/answer/7454865): what the warning means, and why your own install doesn't need verification.
- [Refresh token expiration](https://developers.google.com/identity/protocols/oauth2#expiration): the seven-day limit in Testing status.

## Connecting (Each User)

Settings → Connections → **Connect a Google account**. Google asks for consent (calendar read access, event write access, permission to create Better-Cal's own calendars, and your email address, which labels the account here), then sends you back to the browser session that started it. Several accounts can be connected.

Connecting, adding one of its calendars, moving a calendar and disconnecting ask for your Better-Cal password if this browser hasn't confirmed it in the last ten minutes (a fresh sign-in counts). Ordinary use and syncing don't ask. An account connected without the permission to create calendars is asked to reconnect once, the first time you move a calendar to Google.

The account lists every calendar Google shows it, with Google's access role and a kind read from the calendar id:

- **Yours**: your primary calendar, or a secondary one you own.
- **Shared with you**: someone else's, shared with you (the kind no iCal address can reach).
- **Feed copy**: Google's own copy of an ICS subscription, always behind the source; subscribing to the ICS address here directly is fresher.
- **Google**: holidays, birthdays.

**Add** subscribes to one and syncs it right away.

**Disconnect** revokes the token at Google and forgets it. Calendars already subscribed from that account keep their events but stop updating, with "Google account disconnected" as their poll error. To bring one back, connect the account again and use **Re-attach** on that calendar in its list: it keeps its events and everything added to them (tags, people, reminders, Planned or Maybe, trips) and picks up what changed at Google meanwhile. Or adopt it as local to keep its events without syncing.

If Google stops accepting an account's grant, Settings says **Reconnect needed**, with a Reconnect button; saved events stay visible while syncing and editing pause. A temporary network failure says **Connection problem** instead. A revocation already sent to Google can still land after you reconnect; if it does, reconnect again. Likewise, an invitation reply already sent to Google can't be recalled.

**Move to Google** (a local calendar's settings) puts a calendar that started here into your Google account; see [Moving a calendar to Google](#moving-a-calendar-to-google). **Adopt as local** (a Google calendar's settings) goes the other way: the calendar stays here with every event, the link to Google is cut in both directions, and the copy at Google is left as it is. It's the last step of a migration; [migration.md](migration.md) has the order.

**After an account compromise:** an ordinary password change keeps Google connections. The server-side compromise reset, `php server/bin/seed.php --email=you@example.com --revoke-tokens`, pauses every Google account, stops unfinished moves, and then asks Google to revoke their refresh tokens. Cached events stay visible, but Google calendars are read-only and stop polling until each account is reconnected. A request already in flight may finish, events a partial move already uploaded stay at Google (Better-Cal can't retract them from an account the owner may no longer control), and cancelled moves are never resumed automatically.

## Moving a Calendar to Google

For a calendar that started in Better-Cal and now needs to be seen by people who use Google Calendar: a trip calendar for travel companions, say, or a family calendar. An outbound feed also works for read-only sharing, but Google refreshes it on its own schedule (often once a day) and nobody at Google can add to it.

**How:** the calendar's settings (the gear beside it) → **Move to Google…** → into a new Google calendar of the same name (or one you already have, ideally empty) → **Move to Google**.

- **Upload:** Better-Cal creates the calendar in your Google account and uploads every event with Google's import call, which keeps each event's identity and sends no mail. A large calendar uploads in the background, with progress in its settings, and takes no edits until the upload is done.
- **After the upload:** it's a Google calendar here, edited in place like any other: a change goes to Google first, and changes made at Google arrive within the poll interval.
- **What stays:** events keep their identity here, so their tags, people, reminders, trip membership and links all stay.
- **Sharing:** from Google Calendar: the calendar's settings there, **Share with specific people**, with "See all event details" or "Make changes to events".
- **If the upload stops** (Google unreachable, say), the calendar stays local and untouched, and **Try again** continues where it stopped. Nothing switches over until every event is at Google. Disconnecting the account partway cancels the move instead (the Disconnect confirmation says so): the calendar stays local, events already uploaded stay at Google, and a new move starts over.

**What changes:** as with any Google calendar, changes can't be undone here, since each is a write to Google. Earlier Activity entries for it stay listed but can't be undone either. Moving events between it and a local calendar is refused (copy, then delete). **Adopt as local** reverses the whole move, leaving the copy at Google as it is.

## What Writes Send

Title, description, location, start and end (dates for all-day, RFC 3339 with the time zone otherwise), status, the recurrence rule with its exceptions, and the event's link as Google's `source`, which polls read back so the link isn't replaced by Google's own page for the event. Not sent: attendees (they would mail people), reminders (per-user at Google) and color. Tags, people, reminders and the trip flag stay local and survive polls.

Recurring edits: "this occurrence" patches Google's instance and comes back as an exception; "this and following" ends the series at Google with `UNTIL` and inserts a new one; deleting one occurrence deletes the instance at Google, which comes back as an `EXDATE`.

Moving an event between a Google calendar and any other calendar is refused, because half of such a move could never be undone here. Use **Copy to** (the stack icon on an event), then delete the original if you meant a move. A trip on a Google calendar moves one event at a time.

Activity records each write as a plain entry ("Updated 'Team lunch' on Google calendar 'Book club'"), with no Undo, since undoing would be a second write to Google.

## Invitations

An event on a connected Google calendar that lists your account as a guest, organized by someone else, is an invitation: its details show who invited you, how many are invited, and Accept / Maybe / Decline, with your current answer at Google selected. Unanswered ones also appear on the Review page.

Answering sets your response at Google (`attendees[self].responseStatus`). Google passes a guest's answer to the organizer itself, so Better-Cal sends it with `sendUpdates=none` and nobody else on the guest list gets mail. An answer changed in Google Calendar shows here on the next poll. If Google refuses or can't be reached, nothing changes here and the error says why.

Google puts invitations on the account's main calendar, so this needs that calendar connected. A recurring invitation is answered for the whole series (or, for an occurrence Google already treats separately, for that one).

## How Syncing Works

- The first sync lists every event on the calendar (Google's `events.list`: series with their `RRULE`, exceptions as instances) and stores the sync token Google returns.
- Every later poll sends the sync token and gets back only what changed; an unchanged calendar is one request with an empty answer. Changes go through the same path as ICS feeds, so deletions, the CalDAV change log, Activity and health states behave as for any feed.
- A cancelled instance of a recurring event becomes an `EXDATE` on the series here; a cancelled series is deleted whole.
- When Google says the token is stale (HTTP 410, after long gaps), the next poll lists everything again. Nothing is lost either way.
- A poll has a size and time budget. If a response is too large or stops making progress, Better-Cal keeps the last successful events and sync token, records the reason on the calendar and moves on to other work. [Scale and limits](limits.md) has the numbers and the settings that raise them.
- Reminders aren't synced (they're per-user at Google). Guest lists are read only to recognize invitations.
- The poll interval, stale threshold, "Refresh now" and the rest of a calendar's settings work as for any feed.

## Endpoints

See the [API contract, Google Calendar](api-contract.md#google-calendar).

## Failure Modes You Will See

- Poll error "Google token request failed: HTTP 400 (Token has been expired or revoked.)", Google's `invalid_grant`: the refresh token was revoked (you removed Better-Cal under Google Account → Security → Third-party access, or the OAuth client was in Testing status for over seven days). Reconnect the account.
- Poll error "Google account disconnected": you disconnected the account. Connect it again and use **Re-attach** on the calendar in its list, or adopt the calendar as local.
- "Google did not return a refresh token" on connect: Google issues one only on first consent unless asked, and Better-Cal does ask (`prompt=consent`). If it still happens, remove Better-Cal under Third-party access and connect again.
