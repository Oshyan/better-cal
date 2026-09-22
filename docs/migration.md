# Moving from Google Calendar

Nothing here is a cliff. You can test Better-Cal without changing anything at Google, run both for as long as you like, switch fully when you are sure, and go back at any point by simply stopping. This page is the order to do it in.

## The pieces

- **Google connector** (Settings → Connections). Connect a Google account and every calendar it can see appears here. Calendars you own or can write to are editable: what you create or change in Better-Cal goes to Google, and what changes at Google shows up here within five minutes ("Check now" on the calendar for sooner). See `docs/google-calendar.md`.
- **Browser extension** (Settings → Connections has the link and your address to paste in). Every "Add to Google Calendar" link on the web opens in Better-Cal instead. Toggle it off and the links go to Google again. Works in Chrome, Edge, Brave and Vivaldi. On a phone, share the link to the installed app or paste it into quick add.
- **Subscriptions**: any calendar you follow by URL (holidays, a venue, a friend's public calendar) can be followed here directly.
- **Takeout import** (`server/bin/import-takeout.php`): turns a Google Takeout export into local calendars, recurrence and all.
- **Adopt as local** (a calendar's settings): makes a synced calendar yours here, keeping every event, and stops the sync.
- **Email ingest** (`docs/email-ingest.md`): invitations that arrive by email become events, with real RSVP replies.
- **CalDAV** (`docs/caldav.md`): Apple Calendar, DAVx5 and Thunderbird sync natively.

## Step 1: test, changing nothing at Google

A week is enough to know. During this step Google remains the system of record, and everything you do is visible and reversible there.

1. **Connect your Google account.** Your calendars appear with their events and history. Leave them exactly as they are.
2. **Install the extension** and paste in your address. From now on, "Add to Google Calendar" buttons open Better-Cal's editor. Pick one of your Google calendars in it and the event lands at Google, as it would have anyway.
3. **Re-point your subscriptions.** For each calendar you follow by URL in Google, subscribe to the same URL here. Hide Google's copies in the sidebar to avoid seeing things twice.
4. **Use it for everything.** Create, move, edit, and check the Google tab now and then: changes converge within a few minutes in both directions.

What you will notice, so it is not a surprise:

- Writes to Google say "can't be undone", before and after. They are real writes to a calendar that is Google's; there is no local snapshot to roll back to. Ordinary changes are fine; be a little deliberate with edits to a repeating series.
- An event cannot be moved between a Google calendar and a local one yet. Copy works.
- Inviting people is still Google's job. Better-Cal does not send invitations through Google. If your week involves sending invites, do that at Google and watch them appear here.

To stop the test: toggle the extension off, and disconnect the account in Settings if you like. Nothing at Google has changed.

## Step 2: run both, with Better-Cal as the daily driver

Once the test is comfortable, make Better-Cal the thing you open and Google the safety net behind it.

- **Keep shared calendars at Google.** A family calendar, a partner's calendar, anything other people write to: leave it at Google and use it through the connector. It stays live for them and for you.
- **Put new private things in a local calendar.** Anything nobody else needs to see can live here from the start. Local calendars have undo, tags, people, trips, reminders and everything else that does not survive the trip to Google.
- **Phone**: install the app, or add the CalDAV account to Apple Calendar or DAVx5.
- **Invitations**: set up the Gmail forward filter in `docs/email-ingest.md`, so invitations sent to your address turn into events here, and you can accept or decline from here with a proper reply. Invitations still show at Google too; answer from whichever side you are on.
- **Reminders**: turn on push or email reminders here and turn Google's notifications off, or you will get both.
- **Stop opening Google Calendar.** That is the actual test. If you find yourself needing it, note what for; it is either a gap listed above or something worth an issue.

The one thing that does not work well yet in this arrangement is showing a *local* calendar to Google users. Google refreshes a subscribed ICS feed on its own schedule, often once a day. If a calendar has to be seen live by people on Google, keep it at Google for now.

## Going back

Going back is stopping.

1. **Toggle off or uninstall the extension.** Links open at Google again immediately.
2. **Anything on a Google calendar is already at Google.** You did not move it; you edited it in place.
3. **Anything in a local calendar** that you want at Google: create an outbound feed for that calendar (Settings → Connections) and subscribe Google to its URL, or export it once by opening that URL and importing the file at Google. Or leave it; local calendars keep working through CalDAV whether or not you use the web app.
4. **Turn Google's notifications back on**, and disconnect the account here if you want the token revoked.

Nothing you did in steps 1 and 2 needs undoing.

## Step 3: the full switch

Do this per calendar, when you are sure, not all at once.

**Calendars that are yours alone.** Open the calendar's settings and choose **Adopt as local calendar**. Every event and its history stays, the sync stops in both directions, and the calendar is yours here from then on. Then at Google, hide the calendar or delete it; leaving it is also fine, it just stops changing. (If you never connected Google, the Takeout route below does the same job in one go.)

**Calendars other people use.** If someone else writes to it or depends on seeing it live, keep it at Google and keep using it through the connector. That is not a compromise; it is the right home for it until Better-Cal can share a calendar to Google users in real time.

**Takeout, for a clean cut.** At [takeout.google.com](https://takeout.google.com) deselect all, select Calendar, export. Unzip, put the `Calendar/` folder on the server, and run:

```bash
php server/bin/import-takeout.php /path/to/Takeout/Calendar
```

One local calendar per file, recurrence intact, safe to re-run. Use this instead of adopting if you want to cut over everything at once without having connected Google.

**Afterwards**

- Keep the extension on. Links keep landing here, and it no longer matters that you have no Google calendars to put them on.
- Keep the Gmail forward filter. Invitations still arrive by email, and email ingest is how they become events.
- Set your reminder defaults here (Settings → Notifications), since Google's no longer apply.
- Disconnect the Google account when nothing depends on it. The calendars that were adopted are unaffected.
- Your Google account itself stays; it is still where invitations from Google users arrive, and where you answer them if you prefer.

## What each step costs to reverse

| Step | To undo it |
|---|---|
| Connect Google, use the extension | Toggle the extension off, disconnect. Nothing changed at Google. |
| Local calendars for private things | Subscribe Google to the calendar's outbound feed, or export it once. |
| Adopt a calendar as local | Cannot be re-linked to the Google copy; export it and import at Google if you want it back there. Adopt one calendar at a time, when sure. |
| Takeout import | Delete the imported calendars here; Google still has everything. |
