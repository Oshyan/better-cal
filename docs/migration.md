# Moving from Google Calendar

Nothing here is a cliff. You can try Better-Cal without changing anything at Google, run both for as long as you like, switch fully when you're sure, and go back at any point by simply stopping.

## Ways to Start

Pick by how much you want to commit today. It isn't one choice for your whole account: mix them per calendar, say connecting Google for the calendar your family shares, importing your old personal one, and subscribing to holidays. You can also start one way and move to another later: if you connect Google after subscribing or importing, events you now have twice show once (see "The same event twice" under [Step 2](#step-2-run-both-with-better-cal-as-the-daily-driver)), and you can remove the subscriptions or imported copies whenever you like.

| | Subscribe to Google's feed | Feeds both ways | Import a copy | Connect Google |
|---|---|---|---|---|
| **Good for** | Just trying it out | Seeing everything in either app, without connecting Google | Leaving Google, all at once | Living in both, for as long as you like |
| **Setup** | Two minutes: copy one address per calendar | A few more minutes: also publish a feed here and add it to Google | A few minutes: export from Google, upload here | Ten minutes, once per server, for a free Google Cloud project; then one click |
| **Your Google events here** | Yes, read-only, refreshed hourly (as often as every 15 minutes if you set it) | The same | Yes, as a snapshot from the moment you exported | Yes, live |
| **Changes you make here reach Google** | No: new things go in a Better-Cal calendar, so you juggle two apps | They show there read-only, when Google next refreshes the feed (often once a day) | No: from then on, Better-Cal is where your calendar lives | Yes, straight away (and Google's changes reach here within five minutes) |
| **Answer Google invitations here** | Only emailed ones, with [mail forwarding](email-ingest.md) | Only emailed ones, with mail forwarding | Only emailed ones, with mail forwarding | Yes |
| **To stop** | Remove the subscriptions | Remove the subscriptions here and the feed's calendar at Google | Delete the imported calendars; Google still has everything | Disconnect; nothing at Google has changed |

### Just Trying It: Subscribe to Google's Feed

Google gives every calendar a private address that calendar apps can read.

1. In Google Calendar, open the calendar's settings (the three dots next to it, then **Settings and sharing**) and copy **Secret address in iCal format**.
2. In Better-Cal's sidebar, below the calendar list, choose **+ Subscribe URL** and paste it. Repeat for each calendar you want to see.
3. Put anything new in a Better-Cal calendar (**+ New calendar**, in the same place).

You see everything in one place, but you're running two apps: what you add here stays here, and changes at Google show up after the next refresh (Google's feed can also lag behind Google Calendar itself). Treat the secret address like a password: anyone who has it can read that calendar. Google's settings page can reset it.

### Seeing Both Everywhere: Feeds Both Ways

Subscribe to Google's calendars here as above, then send Better-Cal's back the same way:

1. In Better-Cal, **Settings, Connections**, under **Outbound feeds**, create a feed that includes **One calendar** (one of your Better-Cal calendars), and **Copy URL**. Make one per calendar you want at Google. Don't use **All events**: it would include the Google calendars you subscribed to, and Google would show those twice.
2. In Google Calendar, next to **Other calendars**, choose **+**, then **From URL**, and paste it.

No Google Cloud project needed, and neither side can change the other's events: each app edits its own and shows the other's read-only. The catch is that Google refreshes subscribed calendars on its own schedule, often only once a day. Fine for seeing, not for coordinating at short notice. The feed address works like a password; deleting the feed here cuts it off.

### All In: Import a Copy

1. In Google Calendar, open **Settings** (the gear), then **Import & export**, and click **Export**. A zip downloads with one `.ics` file per calendar. ([Google Takeout](https://takeout.google.com) with only Calendar selected gives you the same, but takes longer.)
2. In Better-Cal's sidebar, choose **+ Import calendars** and pick the zip as it downloaded. Each calendar in it becomes one here, named as it was at Google, repeating events included. A single `.ics` file works too.

Importing the same export again adds nothing: a calendar whose name already exists is skipped, and anything else skipped is listed with the reason. For an export too large to upload, run this on the server (an unzipped folder of `.ics` files works too):

```bash
php server/bin/import-takeout.php /path/to/export.zip
```

The copy doesn't change when Google does, so this suits a clean break. For calendars other people share with you, connecting or subscribing works better.

### Living in Both: Connect Google

The Google connector shows every calendar your Google account can see. Changes at Google show here within five minutes (**Refresh now** in the calendar's settings for sooner). On calendars you own or can write to, you can also create, edit, reschedule and delete events here: each change goes straight to Google, and is saved here only once Google accepts it. Whoever runs the server first creates a free Google Cloud project (about ten minutes, with links to each page, in [google-calendar.md](google-calendar.md#one-time-setup-the-operator)). After that, connecting is a click in **Settings, Connections**.

It's also the path with no hard decisions: use Better-Cal daily while Google stays the source of truth, then move calendars over one at a time, or never. The steps below are that route.

### Not on Google?

Only the live connection is Google-specific (connecting to iCloud and other CalDAV services, then Microsoft, is planned: [#137](https://github.com/Oshyan/better-cal/issues/137); Proton has no way for other apps to connect). Subscribing, feeds both ways and importing work with any service that can share a calendar address or export a file:

- **iCloud:** share a calendar as a public calendar (in the Calendar app, its share settings) and subscribe to that address here, or export it from Calendar on a Mac (**File, Export**) and import the file.
- **Outlook and Microsoft 365:** publish a calendar (Outlook on the web, **Settings, Calendar, Shared calendars, Publish a calendar**) and subscribe to its ICS link, or export it as an `.ics` file.
- **Fastmail, Nextcloud, Proton and the rest:** each can give a calendar's ICS address to subscribe to, or export a file. Better-Cal is a CalDAV server, not a client, so it can't sign in to another CalDAV account directly.

### Keep the App You Already Use

Because Better-Cal is a CalDAV server, the calendar app you open every day can add it as one more account next to Google or iCloud: Apple Calendar on a Mac, iPhone or iPad, Thunderbird, or DAVx5 on Android, which puts it in the phone's own calendar app. Your Better-Cal calendars appear alongside the rest, editable. [caldav.md](caldav.md) has the steps for each.

## Step 1: Test, Changing Nothing at Google

A week is enough to know. Google stays the system of record, and everything you do is visible and reversible there.

1. **Connect your Google account.** Your calendars appear with their events and history. Leave them exactly as they are.
2. **Install the browser extension** (Settings, Connections has the link and your address to paste in). Every "Add to Google Calendar" link on the web then opens Better-Cal's editor instead; pick one of your Google calendars there and the event lands at Google as it would have anyway. It works in Chrome, Edge, Brave and Vivaldi. On a phone, share the link to the installed app or paste it into quick add.
3. **Re-point your subscriptions.** For each calendar you follow by URL in Google (holidays, a venue, a friend's public calendar), subscribe to the same URL here. Hide Google's copies in the sidebar to avoid seeing things twice.
4. **Use it for everything.** Create, edit and reschedule, and check Google now and then: your changes are there at once, and Google's show here within five minutes.

What you'll notice, so it isn't a surprise:

- Writes to Google say "can't be undone", before and after. They're real writes to Google's calendar, with no local snapshot to roll back to. Ordinary changes are fine; be a little deliberate with edits to a repeating series.
- An event can't be moved between a Google calendar and another calendar. **Copy to** works; delete the original if you meant a move.
- Inviting people is still Google's job. Better-Cal doesn't send invitations, by email or through Google. If your week involves sending invites, do that at Google and watch them appear here.

To stop the test, toggle the extension off, and disconnect the account in Settings if you like. Nothing at Google has changed.

## Step 2: Run Both, With Better-Cal as the Daily Driver

Once the test is comfortable, make Better-Cal the thing you open and Google the safety net behind it.

- **Keep shared calendars at Google.** A family calendar, a partner's calendar, anything other people write to: leave it at Google and use it through the connector. It stays live for them and for you.
- **Put new private things in a local calendar.** Local calendars have undo, tags, people, trips, reminders and everything else that doesn't survive the trip to Google.
- **Phone:** install the app and turn on reminders ([the app and reminders](app-and-reminders.md)), or add the CalDAV account to Apple Calendar or DAVx5.
- **Invitations:** those Google puts on your account's main calendar show here with Accept / Maybe / Decline, and the answer is set at Google ([google-calendar.md](google-calendar.md#invitations)). For invitations by email, forward them to the ingest address ([email-ingest.md](email-ingest.md#forwarding-setup)) so they become events here. They still show at Google too; answer from whichever side you're on.
- **Reminders:** turn on push or email reminders here and turn Google's notifications off, or you'll get both.
- **The same event twice** (an imported copy and the Google calendar it came from, a Luma feed and the confirmation you forwarded): Better-Cal shows it once, from the most live copy, and its details say "Also on <calendar>". Close matches it isn't sure of wait in Review as "Possible duplicate". "Not the same", in either place, shows both again. Nothing is merged or deleted, and only one copy reminds you.
- **Stop opening Google Calendar.** That's the actual test. If you find yourself needing it, note what for: it's either a gap listed above or something worth an issue.

**A local calendar that people on Google need to see live** (or add to): move it to Google from its settings (**Move to Google…**, see [google-calendar.md](google-calendar.md#moving-a-calendar-to-google)). It stays here and is edited in place; Google holds it, and you share it from Google Calendar. An outbound feed still works for read-only sharing, at Google's slow refresh.

## Step 3: The Full Switch

Do this per calendar, when you're sure, not all at once.

- **Calendars that are yours alone.** In the calendar's settings, choose **Adopt as local calendar**. Every event and its history stays, the sync stops in both directions, and the calendar is yours here from then on. At Google, hide or delete it, or leave it (it just stops changing). An adopted calendar can't be re-linked to its Google copy; to put it back at Google, move it there again as a new calendar or export it.
- **Calendars other people use.** Keep them at Google and use them through the connector, or move one there if it started here. That isn't a compromise: Google is where the people sharing it already are, and Better-Cal edits it in place.
- **Never connected Google?** [Import a copy](#all-in-import-a-copy) does the same job in one go.

Afterwards:

- Keep the extension on. Links keep landing here, even with no Google calendars to put them on.
- Keep forwarding invitation email. It's how invitations from Google users become events.
- Set your reminder defaults in Settings, Notifications, since Google's no longer apply.
- Disconnect the Google account when nothing depends on it. Adopted calendars are unaffected. The Google account itself stays, and you can still answer invitations there if you prefer.

## Going Back

Going back is stopping. Nothing you did in steps 1 and 2 needs undoing.

1. **Toggle off or uninstall the extension.** Links open at Google again immediately.
2. **Anything on a Google calendar is already at Google.** You edited it in place.
3. **Anything in a local calendar** (including adopted and imported ones) that you want at Google: move it there from its settings (**Move to Google…**), subscribe Google to an outbound feed for that calendar, or export it once and import the file at Google: **Download .ics** in the calendar's settings, or **Settings, Account, Export everything** for all of them (a zip of `.ics` files; Google imports them one calendar at a time). Or leave it: local calendars keep working through CalDAV whether or not you use the web app.
4. **Turn Google's notifications back on**, and disconnect the account here if you want the token revoked.
