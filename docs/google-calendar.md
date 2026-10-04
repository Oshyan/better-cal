# Google Calendar connector

Better-Cal can read calendars through a connected Google account: the ones you own, the ones you subscribe to, and the ones other people have shared with you, including shared-but-not-public calendars that no iCal address can reach (the case that started this, GH #42). A connected calendar is an ordinary subscribed calendar here: it has a colour, lives in folders, shows feed health. Edits made in Google arrive here within the poll interval (5 minutes by default, one small request per poll).

Where the connected account may edit the calendar (Google's access role `writer` or `owner`: your own calendars, and ones shared with you with "make changes"), events can be created, edited and deleted here too. That is **write-through**, not two-way sync: the change goes to Google first, and what Google answers with is materialised locally through the same path a poll uses, so no local-only version of a Google event ever exists and there is nothing to reconcile. If Google refuses (network, revoked token, a role that changed), the request fails with a clear error and nothing here changes. Calendars the account can only view stay read-only, like any feed.

## One-time setup (the operator)

Google requires each installation to have its own OAuth client, so whoever runs a Better-Cal install sets one up once, in their own Google Cloud project. About ten minutes.

- **Only needed for the Google connector.** Everything else works without it, including following a Google calendar by its secret iCal address (Subscribe) and bringing calendars over from a Google Takeout export (Import).
- **No cost and no billing account.** A Google Cloud project is free to create with any Google account, and the Calendar API has no charge at this scale.
- **The app stays "unverified"**, which is fine for your own install. Verification is for apps offered to the public; an unverified app shows a one-time warning when an account connects.

The console changes its layout often. As of October 2026, OAuth lives under **Google Auth Platform** (Overview, Branding, Audience, Clients, Data Access, Verification Center) rather than the old "OAuth consent screen" page. The links below open each page for whichever project is selected in the console's project picker at the top.

1. **Pick or create a project:** [console.cloud.google.com/projectcreate](https://console.cloud.google.com/projectcreate), or choose an existing one in the project picker. One that already has an OAuth setup saves the branding step.
2. **Enable the Google Calendar API:** [its page in the API Library](https://console.cloud.google.com/apis/library/calendar-json.googleapis.com) → **Enable**. Do this first: the scope picker in step 5 only lists APIs that are enabled.
3. **Branding:** [Google Auth Platform → Branding](https://console.cloud.google.com/auth/branding). App name (say "Better-Cal"), a support email, a developer contact. Save.
4. **Audience:** [Google Auth Platform → Audience](https://console.cloud.google.com/auth/audience). User type **External**, then **Publish app**, so the publishing status reads *In production*. This matters: in *Testing* status Google expires refresh tokens after seven days, and every connected account would need reconnecting weekly. In production it stays unverified: Google shows a warning once per account when connecting ("Advanced", then "Go to Better-Cal").
5. **Scopes:** [Google Auth Platform → Data Access](https://console.cloud.google.com/auth/scopes) → **Add or remove scopes**. Filter "Google Calendar API" and tick `.../auth/calendar.readonly`, `.../auth/calendar.events` and `.../auth/calendar.app.created` (the last one lets Better-Cal create calendars of its own, for moving a calendar to Google, and touches nothing else), then `openid` and `.../auth/userinfo.email` from the top of the list. Update, Save. The request itself carries the scopes, so this only sets what the consent screen describes. An unverified app works without it, but it takes a minute.
6. **The client:** [Google Auth Platform → Clients](https://console.cloud.google.com/auth/clients) → **Create client**. Application type **Web application**, any name. Under **Authorized redirect URIs** add `https://<your host>/api/v1/google/callback`. It must match `BETTERCAL_BASE_URL` exactly, including the scheme; no JavaScript origins are needed. Create, then copy the **Client ID** and the **Client secret** (shown once; there is a download button).
7. **Put the client ID and secret in the server's `.env`:**

```
BETTERCAL_GOOGLE_CLIENT_ID=....apps.googleusercontent.com
BETTERCAL_GOOGLE_CLIENT_SECRET=...
```

`BETTERCAL_SESSION_SECRET` must be set (it already is on any working install): the refresh token is sealed with it (`Infra\Secrets`, libsodium secretbox) before it is stored. Changing the session secret invalidates stored tokens; reconnecting the account is the fix.

Until both variables are set, Settings → Connections says the connector is not set up and offers nothing.

**Google's own documentation**, if a screen looks different from the steps above:

- [Get started with the Google Auth Platform](https://support.google.com/cloud/answer/15544987): the Branding, Audience, Clients and Data Access pages.
- [Configure the OAuth consent screen](https://developers.google.com/workspace/guides/configure-oauth-consent) and [create access credentials](https://developers.google.com/workspace/guides/create-credentials), from Google's Workspace developer guides.
- [Unverified apps](https://support.google.com/cloud/answer/7454865): what the warning means, and why verification isn't needed for your own install.
- [Refresh token expiration](https://developers.google.com/identity/protocols/oauth2#expiration): the seven-day limit in Testing status.

## Connecting (each user)

Settings → Connections → **Connect a Google account**. Google asks for consent (calendar read access, event write access, permission to create calendars of Better-Cal's own, and your email address, which is how the account is labelled here), then sends you back. An account connected before 0.9.4 lacks the permission to create calendars; Better-Cal asks you to reconnect it once when you first move a calendar to Google. The account then lists every calendar Google shows it, with the access role Google grants and a kind Google does not state but the calendar id encodes: **Yours** (your primary, or a secondary you own), **Shared with you** (someone else's, shared with you: the shape no iCal address can reach), **Feed copy** (Google's own copy of an ICS subscription, always behind the source; subscribing to the ICS address here directly is fresher), **Google** (holidays, birthdays). **Add** subscribes one and syncs it right away. Several accounts can be connected.

**Disconnect** revokes the token at Google and forgets it. Calendars already subscribed from that account stay, with their events, but stop updating and report "Google account disconnected" as their poll error until you delete them or connect the account again (reconnecting the same email re-attaches them).

**Move to Google** (a local calendar's settings, 0.9.4) goes the other way: it puts a calendar that started here into your Google account, so people who use Google Calendar can see it live and, if you share it with them that way, add to it. See [Moving a calendar to Google](#moving-a-calendar-to-google).

**Adopt as local** (a Google calendar's settings) is the other way out: the calendar stays here with every event, the link to Google is severed in both directions, and the copy at Google is left as it is. It is the final step of a migration; `docs/migration.md` has the order to do things in.

## Moving a calendar to Google

For a calendar that started in Better-Cal and now needs to be seen by people who use Google Calendar: a trip calendar for travel companions, a family calendar. Before 0.9.4 the only way was an outbound feed, which Google refreshes on its own schedule, often once a day, and which nobody at Google can add to.

**How:** the calendar's settings (the gear beside it) → **Move to Google…** → into a new Google calendar of the same name (or one you already have, best an empty one) → **Move to Google**.

- **Upload:** Better-Cal creates the calendar in your Google account and uploads every event with Google's import call, which keeps each event's identity and sends no mail to anyone. A large calendar uploads in the background; its settings show the progress, and it takes no edits until the upload is done.
- **After the upload:** the calendar is a Google calendar here, edited in place like any other: a change goes to Google first, and changes made at Google arrive within the poll interval.
- **What stays:** the events keep their identity here, so their tags, people, reminders, trip membership and links all stay.
- **Sharing:** share it from Google Calendar: the calendar's settings there, **Share with specific people**, with "See all event details" or "Make changes to events".
- **If the upload stops** (Google unreachable, the account disconnected), the calendar stays exactly as it was, local and untouched, and **Try again** continues from where it stopped. Nothing is switched over until every event is at Google.

**What changes:** as with any Google calendar, changes to it can't be undone here, since each is a write to Google. Earlier Activity entries for it stay listed but can no longer be undone. Moving events between it and a local calendar is refused (copy, then delete). **Adopt as local** reverses the whole move: the calendar becomes local again, with every event, and the copy at Google is left as it is.

Keeping a calendar local and authoritative while Google holds a copy in step both ways is a different design, recorded in #83 with the cases where it would be better.

## What writes send

Title, description, location, start and end (dates for all-day, RFC 3339 with the time zone otherwise), status, the recurrence rule with its exceptions, and the event's link as Google's `source` (0.9.4), which polls read back, so a link isn't replaced by Google's own page for the event. Not sent: attendees (they would mail people), reminders (per-user at Google), colour. Tags, people, reminders and the trip flag are local metadata on the row and survive polls.

Recurring edits map onto Google the way the local model already works: "this occurrence" patches Google's instance id and comes back as an exception; "this and following" ends the series at Google with `UNTIL` and inserts a new one; deleting one occurrence deletes the instance at Google, which comes back as an `EXDATE`. Moving an event between a Google calendar and any other calendar is refused, because half of such a move can never be undone here; use **Copy to** (the stack icon on an event) to put a copy on the other calendar, then delete the original if a move was meant. A trip on a Google calendar moves one event at a time.

Activity records each write as a plain entry ("Updated 'Dishoom' on Google calendar 'London'"); there is no Undo for these, since undoing would be a second write to Google against a row that is Google's, not a snapshot of ours.

## Invitations

An event on a connected Google calendar that lists your account as a guest, organised by someone else, is an invitation: its details show who invited you, how many are invited, and Accept / Maybe / Decline, with your current answer at Google selected. Unanswered ones also appear on the Review page.

Answering sets your response at Google (`attendees[self].responseStatus`). Google passes a guest's answer to the organizer by itself, so Better-Cal sends it with `sendUpdates=none` and nobody else on the guest list gets mail. An answer changed in Google Calendar shows here on the next poll. If Google refuses or can't be reached, nothing changes here and the error says why.

Google puts invitations on the account's main calendar, so this needs that calendar connected. A recurring invitation is answered for the whole series (or, for an occurrence Google already treats separately, for that one).

## How syncing works

- First sync lists every event on the calendar (Google's `events.list`, masters with their `RRULE`, exceptions as instances) and stores the sync token Google returns.
- Every later poll sends only the sync token and receives only what changed: new, edited, moved, cancelled. Nothing changed is one request with an empty answer. The changes are merged into the current snapshot and handed to the same materialisation the ICS feeds use (`Feeds::sync`), so upserts, deletions, the CalDAV change log, the Activity roll-up and the health states are identical to a feed's.
- Google's cancelled instance of a recurring event becomes an `EXDATE` on the series here; a cancelled series is deleted whole.
- When Google says the token is stale (HTTP 410, which happens after long gaps), the next poll is a full list again. Nothing is lost either way.
- Reminders are not synced; they are per-user on Google's side anyway. Guest lists are read only to recognise invitations (below).
- The poll interval, stale threshold, "check now", and everything else in a calendar's settings work as for any feed.

## Endpoints

See `docs/api-contract.md`, Google.

## Failure modes you will see

- Poll error "Google token request failed: HTTP 400 (invalid_grant)": the refresh token was revoked (you removed Better-Cal under Google Account → Security → Third-party access, or the OAuth client was in Testing status for over seven days). Reconnect the account.
- Poll error "Google account disconnected": you disconnected the account; delete the calendar or reconnect.
- "Google did not return a refresh token" on connect: Google only issues one on the first consent unless asked (we do ask, with `prompt=consent`); if it still happens, remove Better-Cal under Third-party access and connect again.
