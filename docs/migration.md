# Moving from Google Calendar

Nothing here is a cliff. You can try Better-Cal without changing anything at Google, run both for as long as you like, switch fully when you're sure, and go back at any point by simply stopping.

## Ways to start

Pick by how much you want to commit today. It isn't one choice for your whole account: mix them per calendar, say connecting Google for the calendar your family shares, importing your old personal one, and subscribing to holidays. You can also start one way and move to another later: if you connect Google after subscribing or importing, events you now have twice show once (see "The same event twice" below), and you can remove the subscriptions or imported copies whenever you like.

| | Subscribe to Google's feed | Feeds both ways | Import a copy | Connect Google |
|---|---|---|---|---|
| **Good for** | Just trying it out | Seeing everything in either app, without connecting Google | Leaving Google, all at once | Living in both, for as long as you like |
| **Setup** | Two minutes: copy one address per calendar | A few more minutes: also publish a feed here and add it to Google | A few minutes: export from Google, upload here | Ten minutes, once per server, for a free Google Cloud project; then one click |
| **Your Google events here** | Yes, read-only, refreshed hourly (as often as every 5 minutes if you set it) | The same | Yes, as a snapshot from the moment you exported | Yes, live |
| **Changes you make here reach Google** | No: new things go in a Better-Cal calendar, so you juggle two apps | They show there read-only, when Google next refreshes the feed (often once a day) | No: from then on, Better-Cal is where your calendar lives | Yes, both ways, within a few minutes |
| **Answer Google invitations here** | Only emailed ones, with [mail forwarding](email-ingest.md) | Only emailed ones, with mail forwarding | Only emailed ones, with [mail forwarding](email-ingest.md) | Yes |
| **To stop** | Remove the subscriptions | Remove the subscriptions here and the feed's calendar at Google | Delete the imported calendars; Google still has everything | Disconnect; nothing at Google has changed |

### Just trying it: subscribe to Google's feed

Google gives every calendar a private address that calendar apps can read.

1. In Google Calendar, open the calendar's settings (the three dots next to it, then **Settings and sharing**) and copy **Secret address in iCal format**.
2. In Better-Cal's sidebar, below the calendar list, choose **+ Subscribe URL** and paste it. Repeat for each calendar you want to see.
3. Put anything new in a Better-Cal calendar (**+ New calendar**, in the same place).

You see everything in one place, but you're running two apps: what you add here stays here, and changes you make at Google show up after the next refresh. Google's feed can also lag behind Google Calendar itself. Treat the secret address like a password: anyone who has it can read that calendar, and Google's settings page can reset it.

### Seeing both everywhere: feeds both ways

Subscribe to Google's calendars here as above, then send Better-Cal's back the same way, so each app shows everything:

1. In Better-Cal, **Settings, Connections**, under **Outbound feeds**, create a feed that includes **One calendar** (one of your Better-Cal calendars), and **Copy URL**. Make one per calendar you want at Google. Don't use **All events**: it would include the Google calendars you subscribed to, and Google would show those twice.
2. In Google Calendar, next to **Other calendars**, choose **+**, then **From URL**, and paste it.

Nothing needs a Google Cloud project, and neither side can change the other's events: each app edits its own and shows the other's read-only. The catch is Google's side: it refreshes subscribed calendars on its own schedule, often only once a day, so something you add here can take that long to show at Google. Fine for seeing, not for coordinating at short notice. The feed address works like a password; deleting the feed here cuts it off.

### All in: import a copy

1. In Google Calendar, open **Settings** (the gear), then **Import & export**, and click **Export**. A zip downloads with one `.ics` file per calendar; unzip it. ([Google Takeout](https://takeout.google.com) with only Calendar selected gives you the same files, but takes longer.)
2. In Better-Cal's sidebar, choose **+ Import ICS** and pick one `.ics` file. Each becomes a calendar here, repeating events included. Repeat for the others. (Importing the whole zip in one step is coming: [#136](https://github.com/Oshyan/better-cal/issues/136).)

With command-line access to the server, `php server/bin/import-takeout.php /path/to/unzipped-folder` imports every file in the folder in one go (see [Step 3](#step-3-the-full-switch)). The copy doesn't change when Google does, so this suits a clean break. For calendars other people share with you, connecting or subscribing works better.

### Living in both: connect Google

The Google connector shows every calendar your Google account can see and keeps them in step both ways: create, edit or move something here and it's at Google within moments; change it at Google and it shows here within five minutes. It's the most setup, because whoever runs the server first creates a free Google Cloud project (about ten minutes, with links to each page, in [google-calendar.md](google-calendar.md#one-time-setup-the-operator)). After that, connecting is a click in **Settings, Connections**.

It's also the path with no hard decisions: you can use Better-Cal daily while Google stays the source of truth, and later move calendars over one at a time, or never. The rest of this page is that route, step by step.

### Not on Google?

Only the live connection is Google-specific (there's no connector for iCloud or Microsoft yet). Subscribing, feeds both ways and importing work with any calendar service that can share a calendar address or export a file:

- **iCloud:** share a calendar as a public calendar (in the Calendar app, its share settings) and subscribe to that address here, or export it from Calendar on a Mac (**File, Export**) and import the file.
- **Outlook and Microsoft 365:** publish a calendar (Outlook on the web, **Settings, Calendar, Shared calendars, Publish a calendar**) and subscribe to its ICS link, or export it as an `.ics` file.
- **Fastmail, Proton, Nextcloud and other CalDAV services:** each can give a calendar's ICS address to subscribe to, or export a file. Better-Cal is a CalDAV server, not a client, so it can't sign in to another CalDAV account directly.

### Keep the app you already use

Better-Cal is also a CalDAV server, so the calendar app you open every day can add it as one more account next to Google or iCloud: Apple Calendar on a Mac, iPhone or iPad, Thunderbird, or DAVx5 on Android, which puts it in the phone's own calendar app. Your Better-Cal calendars appear alongside the rest, editable, without changing how you work. [caldav.md](caldav.md) has the steps for each.

## The pieces


- **Google connector** (Settings → Connections). Connect a Google account and every calendar it can see appears here. Calendars you own or can write to are editable: what you create or change in Better-Cal goes to Google, and what changes at Google shows up here within five minutes ("Check now" on the calendar for sooner). See `docs/google-calendar.md`.
- **Browser extension** (Settings → Connections has the link and your address to paste in). Every "Add to Google Calendar" link on the web opens in Better-Cal instead. Toggle it off and the links go to Google again. Works in Chrome, Edge, Brave and Vivaldi. On a phone, share the link to the installed app or paste it into quick add.
- **Subscriptions**: any calendar you follow by URL (holidays, a venue, a friend's public calendar) can be followed here directly.
- **Import** (the sidebar's **+ Import ICS**, or `server/bin/import-takeout.php` for a whole folder): turns Google Calendar's export, or a Takeout export, into local calendars, recurrence and all.
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
- Inviting people is still Google's job. Better-Cal does not send invitations, by email or through Google. If your week involves sending invites, do that at Google and watch them appear here.

To stop the test: toggle the extension off, and disconnect the account in Settings if you like. Nothing at Google has changed.

## Step 2: run both, with Better-Cal as the daily driver

Once the test is comfortable, make Better-Cal the thing you open and Google the safety net behind it.

- **Keep shared calendars at Google.** A family calendar, a partner's calendar, anything other people write to: leave it at Google and use it through the connector. It stays live for them and for you.
- **Put new private things in a local calendar.** Anything nobody else needs to see can live here from the start. Local calendars have undo, tags, people, trips, reminders and everything else that does not survive the trip to Google.
- **Phone**: install the app and turn on reminders ([the app and reminders](app-and-reminders.md)), or add the CalDAV account to Apple Calendar or DAVx5.
- **Invitations**: with your account's main Google calendar connected, the invitations Google puts on it show here with Accept / Maybe / Decline, and the answer is set at Google (see [google-calendar.md](google-calendar.md#invitations)). For invitations by email, set up the Gmail forward filter in `docs/email-ingest.md`, so they turn into events here; one that arrives with its calendar part can be answered from here with a proper reply once RSVP mail is set up (whether Gmail's forward keeps that part is not yet verified). Invitations still show at Google too; answer from whichever side you are on.
- **Reminders**: turn on push or email reminders here and turn Google's notifications off, or you will get both.
- **The same event twice** (an imported copy and the Google calendar it came from, a Luma feed and the confirmation you forwarded): Better-Cal shows it once, from the most live copy, and its details say "Also on <calendar>". Close matches it isn't sure of wait in Review as "Possible duplicate"; "Not the same" in either place shows both again. Nothing is merged or deleted, and only one copy reminds you.
- **Stop opening Google Calendar.** That is the actual test. If you find yourself needing it, note what for; it is either a gap listed above or something worth an issue.

**A local calendar that people on Google need to see live** (or add to): move it to Google from its settings (**Move to Google…**, see [google-calendar.md](google-calendar.md#moving-a-calendar-to-google)). It stays here and is edited in place; Google holds it, and you share it from Google Calendar. An outbound feed still works for read-only sharing, but Google refreshes it on its own schedule, often once a day.

## Going back

Going back is stopping.

1. **Toggle off or uninstall the extension.** Links open at Google again immediately.
2. **Anything on a Google calendar is already at Google.** You did not move it; you edited it in place.
3. **Anything in a local calendar** that you want at Google: move it there from its settings (**Move to Google…**), or create an outbound feed for that calendar (Settings → Connections) and subscribe Google to its URL, or export it once by opening that URL and importing the file at Google. Or leave it; local calendars keep working through CalDAV whether or not you use the web app.
4. **Turn Google's notifications back on**, and disconnect the account here if you want the token revoked.

Nothing you did in steps 1 and 2 needs undoing.

## Step 3: the full switch

Do this per calendar, when you are sure, not all at once.

**Calendars that are yours alone.** Open the calendar's settings and choose **Adopt as local calendar**. Every event and its history stays, the sync stops in both directions, and the calendar is yours here from then on. Then at Google, hide the calendar or delete it; leaving it is also fine, it just stops changing. (If you never connected Google, the export route below does the same job in one go.)

**Calendars other people use.** If someone else writes to it or depends on seeing it live, keep it at Google and keep using it through the connector, or move it there if it started here (**Move to Google…**). That is not a compromise: Google is where the people sharing it already are, and Better-Cal edits it in place.

**Export, for a clean cut.** In Google Calendar, **Settings**, **Import & export**, **Export**, and unzip what downloads. Import each `.ics` from the sidebar (**+ Import ICS**), or put the folder on the server and run:

```bash
php server/bin/import-takeout.php /path/to/unzipped-folder
```

One local calendar per file, recurrence intact; the command is safe to re-run. Use this instead of adopting if you want to cut over everything at once without having connected Google.

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
| Local calendars for private things | Move it to Google from its settings, subscribe Google to the calendar's outbound feed, or export it once. |
| Adopt a calendar as local | Cannot be re-linked to the Google copy; move it to Google again (a new Google calendar) or export it and import at Google if you want it back there. Adopt one calendar at a time, when sure. |
| Importing an export | Delete the imported calendars here; Google still has everything. |
