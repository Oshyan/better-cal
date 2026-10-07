# Changelog

Better-Cal uses [semantic versioning](https://semver.org). While it is below 1.0, the minor number moves for new features or anything that changes how an existing install behaves, and the patch number for fixes, including security fixes. Every release is a git tag (`v0.1.1`) and a GitHub release; the running version is the `VERSION` file at the repository root, which the Settings page shows. Database migrations always run forward on deploy, so updating is: pull the tag, deploy.

Security fixes are tracked privately as GitHub security advisories until they ship, then published; [SECURITY.md](SECURITY.md#past-advisories) lists past advisories with the affected and patched versions.

## 0.9.23 (2026-10-06)

- **Signing out stops reminders on that device.** The server removes only that browser's reminder destination with its session, while leaving the browser permission intact so signing back in restores reminders without another prompt. Other devices and email reminders are unchanged.
- **API keys cannot inspect or disable browser reminder devices.** Device inventory and removal require a signed-in browser. An API key may still create and remove its own push subscription, but cannot overwrite a browser's or another key's subscription.
- **System health no longer returns raw push-service endpoints.** It uses an endpoint hash to recognize the current device without exposing the delivery capability.

No database migration is required.

## 0.9.22 (2026-10-06)

- **Hosts without `proc_open` work again.** Since 0.9.17 every outbound request (feeds, Google, place search, plugins) looked up its address in a separate PHP process with a time limit, and a host that disables `proc_open` or has no command-line PHP binary (some shared hosts) failed every one of them. Those hosts now look up in-process: private addresses are refused exactly as before, only the time limit is lost, and the PHP error log says so once.

## 0.9.21 (2026-10-06)

- **Every open device refreshes after a change made anywhere.** Each successful change moves one per-person marker, and open devices check it every 30 seconds and when you come back to them. Before, renaming a person, editing an away or busy time, or a thumbs up or down didn't reach your other devices until a reload. Calendars reload with it.
- **Clicking a weather, sunset or other context item while an event is open opens it,** the way clicking another event does. It used to take a second click: the first one only closed the open event.
- **Plugin calendars don't get your default reminders,** so turning on sunsets doesn't mean a reminder every evening. A reminder set on one of their events still works.
- **A Google calendar you add starts with the right role:** yours as Mine, Google's holidays and birthdays as Context, one shared with you or copied from a feed as Opportunities. Before, every Google calendar started as Opportunities, your own primary included. Calendars already added keep theirs.
- **Disconnecting a Google account says when it would stop a move to Google,** with how many events were already uploaded, before you confirm.
- **A feed you publish keeps the events nearest today.** Over its 5,000-event cap it used to leave off the furthest-out ones without a word; now the oldest past events go first, the feed's card in Settings says when it's trimmed, and `BETTERCAL_LIMIT_OUTFEED_EVENTS` raises the cap.
- **Paused calendar subscriptions wait in Review,** as "Calendar updates paused", with **Keep updating** right there (from a browser; an API key can't decide it).
- The `o` shortcut and its "Open full detail" command are gone; the full-page view they opened was removed in 0.7.2.

Operators: migration 039 adds the change marker. `scripts/deploy.sh` now ships the last commit, not the working tree: commit before deploying, and uncommitted edits are left out (it says how many). Its tests run on exactly the files that ship.

## 0.9.20 (2026-10-06)

- **Undo right after creating something undoes that creation.** The Undo on the "Event created" toast (and the other "created" toasts, and an agent's `undo`) skipped the creation and reverted whatever you had changed before it, leaving the new event in place. It now removes what was just created. Found by the docs review.

## 0.9.19 (2026-10-06)

- **A calendar moved to Google is checked every five minutes,** like every other Google calendar. It kept the hourly schedule it had as a local calendar, so changes people made at Google took up to an hour to show here. Moved calendars still on that default are switched; one you set yourself stays.
- **CalDAV refuses edits to plugin calendars.** The app and the API already did; a phone could still change or delete a plugin's events, and the plugin overwrote them on its next run.
- **The "Google account disconnected" error says what actually works:** delete the calendar or adopt it as local, then add it again from Settings, Connections. It pointed to a Settings section that has since been renamed, and to reconnecting, which doesn't re-attach a calendar.

Operators: migration 038 runs on deploy and reports how many moved calendars it switched.

## 0.9.18 (2026-10-06)

- **A repeating event with too many occurrences to show says so.** A series is expanded up to 1,000 times per request (was 500), which covers a daily event across the two-year maximum the app ever asks for. A series that goes past that, something hourly for instance, used to be cut short without a word; now the app names it once ("repeats more often than one view can show"), and the events API lists it in a new `capped` field.
- **[Scale and limits](docs/limits.md)** says who Better-Cal is sized for (one person's calendar) and which limits you might run into.

## 0.9.17 (2026-10-06)

- **Exception-heavy repeating calendars fail safely instead of consuming unbounded work.** ICS files, feeds, emailed invitations, CalDAV, Google sync, API-created events, and stored series now share limits of 1,024 skipped occurrences per event and 4,096 per input or sync batch, with non-removable hard ceilings. Ordinary calendars are unaffected; an unusually large or broken series is rejected with an actionable error and the last good subscribed-calendar copy remains visible.
- **Far-dated event links no longer make the browser page through every intervening date.** Notification, Review, and resume links use the occurrence cache first and a three-day lookup around distant targets. Normal links—including reminders up to 28 days ahead—keep their existing calendar preload; an invalid or stale distant link shows the existing “could not find” message after one bounded lookup.
- **Lost-device recovery now closes session-created disclosure channels.** **Sign out everywhere else** still keeps this browser and its push reminders, but also gives every signed-in public calendar feed a new URL and returns a custom reminder address to the account email. The confirmation says that external calendar subscribers need the new URLs. API keys and the channels they created remain separately revocable.
- **Calendar subscriptions created by an API key stop with that key.** Revoking or expiring the key pauses its recurring ICS fetches without deleting the calendar, cached events, folders, tags, or settings. Calendar settings can explicitly keep the subscription active as account-owned, adopt the cached copy as local, or delete it. Existing ICS subscriptions pause once for the same owner review because their historical creator cannot be recovered reliably.
- **A compromise reset now stops every Google path too.** `server/bin/seed.php --revoke-tokens` pauses all connected Google accounts before it returns, stops unfinished calendar moves, prevents background polls and writes, and asks Google to revoke the refresh tokens. Cached calendar events stay visible; reconnect each account under Settings, Connections to resume. Partial moves are not silently retried, because events already copied to Google cannot be taken back automatically.
- **Sensitive Google setup requires a recent password.** Connecting or reconnecting an account, adding one of its calendars, and starting or retrying a move ask for the Better-Cal password when the browser has not confirmed it in the last ten minutes. A fresh sign-in counts. Ordinary calendar use and synchronization do not prompt.
- **Google consent belongs to one browser session.** An OAuth callback started in one signed-in browser can no longer be completed through another session for the same Better-Cal user.
- **Location search follows the same outbound-network safety rules as calendar subscriptions.** Geocoding and weather lookups re-check and pin public DNS addresses after every redirect, ignore ambient proxy settings, and stop at strict redirect, response-size and time limits. Normal suggestions are unchanged; a slow or broken provider can return no result instead of tying up a PHP worker. Failures are logged without the place query or provider URL, appear in Settings immediately, reach Activity after two consecutive failures, show an owner notice after three, and qualify for the existing email alert after a three-failure streak lasts an hour.

Operators: migration 036 adds the per-session confirmation time and the durable Google quarantine/move-cancellation markers. Migration 037 adds subscription-authority provenance and pauses existing ICS subscriptions for review. An ordinary password change still leaves Google connections alone; only the explicit `--revoke-tokens` compromise reset pauses them.

## 0.9.16 (2026-10-06)

- **All-day events are stored one way.** An all-day event is a date, and every path now stores it the same way: as UTC midnights, the way imports, Google and CalDAV already did. Events made in the app used to be stored as midnights in a time zone instead. Both showed on the right days, but a phone editing such a series over CalDAV switched it to the other form, which rebuilt its edited days as new entries and dropped their tags, people and reminders. A migration converts the existing ones, with their skipped and edited days, and every occurrence keeps its date.
- **Search's "upcoming" uses your Home clock for all-day events,** so today's all-day event stays upcoming until the day ends where you are, not at midnight UTC (5 PM in Los Angeles).

Operators: migration 035 runs on deploy; it reports how many all-day events it converted.

## 0.9.15 (2026-10-06)

A second look at time zones, this time against independent calendar engines (a Python one built on the zone database, and ical.js, which Thunderbird and Nextcloud use) over about 3,800 generated repeating events. The core held up: every repeat came out the same, apart from cases the standard leaves undefined. What it found was in the code around it.

- **Editing "All events" from a later occurrence no longer restarts the series there.** The opened occurrence became the series' first: earlier ones disappeared, a repeat count started over from it, and edited days before it were left behind. Now the series changes by what you changed (a new time, a different day, a new length), from its own start. Moving it to another weekday moves its repeat day too ("every Monday" becomes "every Tuesday").
- **Skipped and edited days move with their series.** Changing a series' time, day, zone or all-day setting used to leave them where they were, so a deleted day came back and an edited one showed twice.
- **A weekly series starts on a day it repeats on.** Starting a Mon/Wed/Fri series on a Thursday was undefined, and calendar apps disagreed about it; such a start now moves to the next listed day.
- **The repeated hour when clocks go back.** A change to an occurrence in that hour could come back an hour off from a calendar app, or show twice east of UTC. It is now written unambiguously and read as the standard says.
- **Older events in Thunderbird and Outlook.** The time zone rules sent with feeds and CalDAV started this year, so those apps had no offset for anything earlier; they now start in 1970, as Google's and Outlook's do.
- **Dragging an event that keeps its own zone** moves it by days on its own clock: a London 9:00 dragged a week from a device in Los Angeles stays 9:00 in London. A repeat's end date is read and written on the event's clock too.
- **Meeting links and "address after RSVP" text** are recognized the same way by the app and by push notifications (one shared list); a location like "Registration desk, Hall B" is no longer mistaken for "address after RSVP" in a notification.

For contributors: a round-trip check (export, re-import, expand: same occurrences, 360 series) now runs with every test and deploy, and `tools/tz-harness` runs the full cross-check against the independent engines.

## 0.9.14 (2026-10-06)

A clean install found one time zone bug; an audit of every place the app converts between UTC, an event's own zone, the Home zone and all-day dates found the rest. Events already stored are unaffected unless noted.

Time zones:

- **Repeating all-day events stay on their days.** One made in the app (or through the API without a time zone) anywhere west of UTC showed a day early: a weekly Wednesday event appeared on Tuesdays. Imported, Google and CalDAV events were not affected; they store all-day dates in UTC, where the two readings agree. Ending such a series with "this and following" no longer leaves the split day in the old series too.
- **Feeds and CalDAV send timed events in their own zone.** A weekly 9:00 meeting went out as a UTC time, so calendar apps showed it at 8:00 after the clocks changed, and a CalDAV client saving it back stored it as UTC for good. Events now go out with their zone and its rules, and a series' skipped and edited days match its start (dates for all-day series).
- **Imported times keep their zone.** Outlook's zone names ("Pacific Standard Time") were stored as UTC, so a repeating meeting from Outlook moved an hour at each DST change; and times with no zone at all were read as UTC. Now Outlook names map to the real zone, and zoneless times are read in the calendar's own zone, else your Home zone. A subscribed feed that sends zoneless times corrects itself at its next update.
- **A repeat's end date stays put.** In the editor, "ends on Oct 20" came back as Oct 21 on every save west of UTC. All-day series now end on a plain date.
- **Evening reminders for imported all-day events fire.** "6 PM that day" on an all-day event from an import or Google never fired west of UTC.
- **An all-day event made timed takes your Home zone** instead of UTC, when it came from an import, Google or CalDAV.
- **Moving a trip across a DST change keeps its events on their days and times.** They moved by the elapsed hours, so an all-day one could land a day off and a timed one an hour off.
- **CalDAV all-day events sent as zoned midnights** stay on their day east of UTC; **duplicates** of an all-day event made in the app and its Google copy are found; a pasted **Google Calendar link** keeps its own time zone; **search** and **review** treat a series ending "on Oct 10" as running through that day in its own zone; **Google** occurrence ids for all-day series east of UTC name the right day.
- **"This and following" on a series that repeats a set number of times** keeps the remaining count instead of running on for ever.

Also:

- **Thumbs up and down show your choice** (#107). The chosen one stays pressed and can't be sent twice; the other switches it.
- **Month view fits the day's context to the width** (#108): weather, sun times and the rest show as many as fit, and "+N" only for what doesn't. Wide screens show them all.
- **Signing in loads everything a page load does.** After signing in on the login screen, people, saved views, plugins, the review count and the device's time zone waited for the next reload.
- **Sample data for a first look:** `php server/bin/demo-seed.php` fills an empty account with invented calendars, events and people around today (docs/install.md). The README screenshots are made from it.

## 0.9.13 (2026-10-05)

Found in a clean install on a fresh machine, signing in to a brand-new account (#79):

- **The welcome fills in your first calendar's name.** On a first sign-in it opened before the calendar list had loaded, so "Your first calendar" was blank. It now shows the name (Personal) as soon as the list arrives, and only renames the calendar if you change it.
- **The small month in the sidebar follows a new week start right away.** Changing it in the welcome or in Settings redrew the main calendar but left the sidebar month on the old first day until the next reload.

## 0.9.12 (2026-10-04)

- **The app's "New event" shortcut uses the window you have open.** On a desktop, it (and a webcal link or an .ics file) started a second Better-Cal window. Now the open window comes forward with quick add ready, without reloading. Chrome picks up the change when it next refreshes the installed app's details, which can take a relaunch of the app or up to a day.

## 0.9.11 (2026-10-04)

- **New event from the app icon.** The installed app's icon has a "New event" shortcut that opens quick add: long-press it on Android, or right-click it in the Windows taskbar or the Mac dock. The address behind it, `/new`, opens quick add from anywhere else too.
- **Chrome extension 2.1.0: an optional new event key.** Set a key in Chrome's shortcut settings (linked from the extension's options) and it opens quick add in a small Better-Cal window, even from other apps while Chrome is running when set to "Global". No key is set by default, since the extension is public. No new permissions. Reaches users once the new version is published on the Chrome Web Store.

## 0.9.10 (2026-10-04)

- **"Also on" takes one line.** An event's other copies are listed in a row, separated by dots, each with a small ✕ for "Not the same", instead of a button after each that wrapped onto extra lines.

## 0.9.9 (2026-10-04)

- **Repeating events with skipped dates no longer "change" on every poll.** MySQL hands the list of skipped dates back reformatted, and the comparison read that as a change. So on any feed or Google calendar, every series with two or more skipped dates was rewritten each poll, logged as updated in Activity (one feed showed "9 updated" every hour), and fetched again by CalDAV apps. They now compare by content, as the invitation details already did.

## 0.9.8 (2026-10-04)

- **Only clean HTML is stored (#58 follow-up).** Descriptions typed in Better-Cal were always cleaned when saved, but those from subscribed feeds, Google sync and the Takeout import were stored as they arrived, cleaned only when shown or exported, and "Adopt as local" carried them into your own calendars. Now every way in cleans them with the same allowlist, and a one-time migration cleans what is already stored. Showing and exporting still clean as a second layer.
  - **Nothing visible changes:** the app never showed what the cleaning removes (styles, tables, scripts, unknown tags are unwrapped).
  - **CalDAV apps** fetch the cleaned events once.
- **Migrations can now be PHP** as well as SQL, for data changes that need the application's own code.

## 0.9.7 (2026-10-04)

- **Descriptions leave Better-Cal inert (#58, security review D-02).** A subscribed feed or a Google calendar can put HTML in an event's description, and Better-Cal stores it as it arrived (it is cleaned when shown). Until now that HTML also went back out unchanged when the event was exported, so a script or a `javascript:` link in a feed could reach the other calendar apps reading your Better-Cal feeds or CalDAV. Every export now sends:
  - the rich version (`X-ALT-DESC`) through the same allowlist the app displays it with: plain formatting and http(s) links only;
  - the plain version (`DESCRIPTION`) with no markup at all, including markup that was only entity-escaped in the source, since some apps (Google among them) read that field as HTML.

  Descriptions written by CalDAV clients are now cleaned when they arrive, like every other edit.

## 0.9.6 (2026-10-04)

- **The same event, shown once (#9).** An event that reaches Better-Cal by two routes (a Luma feed and the confirmation forwarded from Gmail, a Takeout import and the Google calendar it came from, a booking and the venue's feed) no longer shows twice.
  - **How it's found:** every ten minutes, Better-Cal looks for the same event ID on two calendars, or the same title at the same moment on two calendars. Those are shown as one.
  - **When it isn't sure:** near-matches (close in time, similar titles, one calendar holding the same thing twice) go to Review as "Possible duplicate", with Same event / Not the same.
  - **Which copy you see:** the most live one: Google, then your own calendars, then feeds, then a booking read from email. Its details say "Also on <calendar>", with a link to the other copy and "Not the same" for a wrong match. Hide a calendar and the copy elsewhere shows instead.
  - **Nothing is merged or deleted:** every copy stays where it was, so a wrong match costs nothing to undo. Search shows one result per event too.
  - **One reminder:** only the copy that would be shown reminds you, or the best-placed one that has reminders.
  - **Copy to** is never mistaken for a duplicate: a copy you made on purpose shows on both calendars, as before.
- **Activity filters** for Geocoding, System, Plugins and Review now filter. The server ignored them before and showed everything. There's a new Duplicates filter as well.

## 0.9.5 (2026-10-04)

- **Answering invitations, finished (#70).**
  - **Bookings aren't invitations.** A reservation, ticket or confirmation read from email no longer shows Accept / Maybe / Decline, which could never send anything. It reads "Booking via <site>".
  - **Buttons only where a reply can go.** An invitation that can't be answered from here says why instead: no organizer named, no email account set up to send from, or replies would come from an address that wasn't invited (the organizer's calendar would ignore them).
  - **Says whether it went.** The old "no reply sent (organizer unknown or RSVP mail not configured)" covered four different causes. Now the reason is given. A reply that failed to send keeps your answer, says why it didn't go, and offers Retry, and you can still change your answer.
  - **Invitations from Google.** An event on a connected Google calendar that someone else invited you to shows Accept / Maybe / Decline with your current answer, and answering sets it at Google, which tells the organizer. Nobody else on the guest list is mailed. These also show on the Review page.

## 0.9.4 (2026-10-04)

- **Move a calendar to Google (#55).** A calendar that started in Better-Cal can now be seen live by people who use Google Calendar, and added to if you share it with them that way: its settings, **Move to Google…**.
  - **How it works:** Better-Cal creates the calendar in your Google account (or uses one you pick), uploads every event without mailing anyone, and from then on edits it in place like your other Google calendars.
  - **What stays:** the events keep their identity, so tags, people, reminders, trip membership and links stay with them.
  - **Big calendars and failures:** a big calendar uploads in the background with its progress shown. A failed upload leaves the calendar untouched, and Try again continues where it stopped.
  - **What you give up:** Undo on that calendar, as for any Google calendar.
  - **Reversible:** "Adopt as local calendar" brings it back.

  A full two-way bridge that keeps the calendar local is recorded separately (#83), with the cases where it would be better.
- **Better-Cal asks Google for one more, narrow permission:** to create calendars of its own and manage only those (`calendar.app.created`). An account connected earlier is asked to reconnect once, when it first moves a calendar.
- **Event links survive Google.** Writing to a Google calendar used to lose an event's link (a Partiful or Luma page, say): the next sync replaced it with Google's own page for the event. The link now travels as Google's `source` field and is read back from it.

## 0.9.3 (2026-10-04)

- **The Google connector's setup guide links straight to each page it needs** in Google Cloud: create a project, enable the Calendar API, Branding, Audience, Data Access and Clients. It now says up front that the connector is optional, that a Google Cloud project costs nothing and needs no billing account, and why an "unverified" app is fine for your own install. Google's own docs for each part are linked for when a screen has moved. (docs/google-calendar.md)
- **Where the app says Google isn't set up,** in Settings, Connections and in the welcome, it links to that guide.
- **The install guide mentions the Google connector** as an optional last step.

## 0.9.2 (2026-10-04)

- **A first-run welcome (#79).** A new account opens on two short steps over the calendar:
  - **Confirm the basics,** filled in from the device: Home time zone, the day the week starts, 12- or 24-hour clock, theme, and the first calendar's name.
  - **Where to start:** subscribe to a calendar by address, import a file or a Google Takeout export, connect Google (or a note that the server needs its Google setup first), install the app, turn on reminders on this device, and browse the plugins. Each one marks itself done.

  The welcome is skippable at every step. It stands aside while something it opened is in use and comes back until Done. On a phone, Back steps from 2 to 1, and from 1 puts it aside until the next launch. Settings, General has "Show the welcome again", which opens it right there. Things already set up (subscriptions, a Google account, plugins, this device's reminders, the installed app) show as done. Existing accounts don't see it unasked.
- **Every view can be the default view.** The server still listed an old "multiweek" view and refused 3 weeks, 2 weeks and Split as a default, though Settings offered them. A test now checks the server's list against the app's.

## 0.9.1 (2026-10-04)

- **Reminders say the time on your clock.** A reminder's time was written in the event's own time zone. Events imported from Google Takeout are stored in UTC, so a 12:45 PM flight in London (summer time) was announced as 11:45 AM. Reminders, by push and by email, now read on the zone your device last reported (then your Home zone), the way the app shows times. An event that keeps a zone of its own whose clock differs adds that time: "12:45 PM (4:45 AM in Los Angeles)". They also follow the 12- or 24-hour setting.
- **The app keeps track of where your device is while it stays open,** not only when it starts, so reminders follow you on a trip.
- **Coming back after a reload puts you exactly where you were.** As well as the view, day, filter and saved view, it now brings back:
  - the page you were on, and the Settings section;
  - an open event, on the day it was opened from, with its way back to a trip or to search;
  - an open search with its query;
  - the time of day in week and day view.

  In the installed app this also survives the phone ending the app in the background (which starts it with a new session), for up to 30 minutes as before. A notification you tap to open the app still opens its own event.
  Back keeps working after such a reload: a restored Settings section goes back to the list, then to the calendar, and a restored event opened from search goes back to the results.
- **Settings, System lists why the app last started on this device:** a new version, the browser discarding it in the background, the phone ending it, or a plain launch. Kept on the device only. It shows which kind of reload is happening.

## 0.9.0 (2026-10-03)

- **Better-Cal now needs PHP 8.4 (8.4.1 or later).** Production has run 8.4 all along, so the minimum now matches what is used every day. 8.3 was a floor nothing else ran, and 0.8.3 showed how quietly it drifts. New servers ship 8.4 or 8.5 (Debian 13, Ubuntu 26.04), and shared hosts offer both in their panels. On Ubuntu 24.04 or Debian 12, the install guide shows how to add PHP 8.4 from the Ondřej Surý packages. The dependency lock is resolved for 8.4.1, which puts Symfony back on its current 8.1 line.
- **The GitHub check installs and tests on PHP 8.4,** in the official PHP image rather than the runner's stock PHP.

## 0.8.3 (2026-10-03)

Installing on someone else's server, from a clean-install dry run (#22).

- **MariaDB is supported** (10.6 and later). Three queries used MySQL 8's `INSERT ... AS alias` form, which MariaDB rejects; they use the form both accept. Checked on MariaDB 10.11 (the whole app) and 10.6 (migrations, smoke test, worker).
- **PHP 8.3 installs work again.** The dependency lock had been resolved on PHP 8.5, which pulled in Symfony 8 (PHP 8.4.1 or later) although Better-Cal says PHP 8.3. Composer now resolves for PHP 8.3 (`config.platform.php`), so Symfony is on its 7.4 long-term line; PHP 8.4 and later are unaffected.
- **The install guide lists every PHP extension needed:** it was missing `xml` (dom, simplexml, xmlreader, xmlwriter, which CalDAV needs) and `zip`. It now gives the Debian/Ubuntu packages and the command that checks them.
- **The Apache setup in the guide is tested** (Apache 2.4 with mod_php on Ubuntu 24.04): every page, CalDAV, and API tokens through the Authorization header.
- **Apache and shared hosting need no web-server setup:** `server/public/.htaccess` now ships with the routing and the Authorization-header pass-through. A host only has to allow `FileInfo` overrides. Nginx ignores the file.
- **`.env.example` includes the Google connector's two keys.**

## 0.8.2 (2026-10-03)

- **The phone drawer's pages moved to the bottom,** under a Manage heading below the calendars, people and plugins, so the calendars come first. They are visited far less often on a phone. Review and Saved views left the grid, since the bottom bar already has them: Review with its count, and Saved views in the View sheet (switch views, or Manage views).

## 0.8.1 (2026-10-03)

- **A person's away and busy times are called that,** or their availability, everywhere they were "spans": the sidebar's hold menu ("Show only this person's availability"), its tooltips, the People page, Activity ("Moved away time for Sam") and the toasts after moving one.
- **"Open person"** replaces "Open in People" in the hold menu and on a person's name.

## 0.8.0 (2026-10-03)

The sidebar and Settings on phones.

- **The sidebar's pages are a short grid at the top** of the phone drawer (Review with its count, Settings, Calendars & folders, People, Activity, Plugins, Filters, Saved views), instead of a folded list at the bottom. The drawer's title reads Better-Cal, since it holds more than calendars now.
- **Each calendar is listed once on a phone:** in its folder, and the rest under Other calendars. All calendars used to repeat every filed calendar a second time. The controls that act on every calendar (new calendar, list only active ones, All / None / Custom) sit on one Calendars header above the folders, and "list only active" applies to every folder there.
- **Only and a calendar's settings are a press and hold away** on a phone: hold a calendar to show only it or open its settings, and hold a person to show only their availability or open them. The row keeps its full width for the name. A calendar or person shown alone keeps its "Only ✓" in view, to tap back. Desktop is unchanged.
- **Settings on a phone opens on a list of its sections,** each with a line of what it holds (General shows your default view, week start, time format and theme). A section opens as its own page with "‹ Settings" above it, and Back returns to the list. The two crowded rows of tabs are gone there; a desktop keeps its tabs.

## 0.7.4 (2026-10-03)

- **Stepping from a multi-day event walks the day you opened it on.** Open Monday's part of a Sunday-to-Tuesday event and the previous and next arrows go through Monday's events; they used to go through Sunday's, the day it began. This holds in every view: month, week, Split and Agenda.
- **Past search results are all in the past.** A repeating series that is still running matched Past by its first date and then showed under Upcoming at its next one. Under Past it now shows its latest date that has gone by.

## 0.7.3 (2026-10-03)

Search, and the leftovers from the phone work.

- **Search finds upcoming events first, and finds all of them** ([#60](https://github.com/Oshyan/better-cal/issues/60)). It used to take the 50 best matches across all time and only then split them into Upcoming and Past, so a word with a long history (a weekly class, a venue) could push the next event out of the results entirely. Now Upcoming (the default), All or Past is chosen under the search box and applied before the limit, with an optional calendar. An Upcoming search ends with "12 past matches, Show them". Both choices are remembered on each device. Results read in calendar order, the soonest upcoming first and the latest past first; relevance still decides which ones come back.
- **A repeating series shows its next date** in search results and opens there, instead of the date it began. A series counts as upcoming until its last date.
- **No result looks chosen until you choose it.** The first row was always marked as selected, so with no upcoming matches the first past result stood out at full strength as if it were current. Enter still opens the first result.
- **Back from an event opened in search returns to the results,** with the query, filters and scroll position as they were. "‹ Search" above the event does the same on a desktop. Closing the event any other way (the close button, swiping the sheet away) goes to the calendar as before.
- **Quick add uses the editor's date and time boxes:** click in and type "fri" or "7p", or pick a quarter hour from the list. The end list runs on from the start with each length, so an evening that ends after midnight is one pick, and moving the start keeps the length.
- **Leaving the editor without saving returns to the event** it was opened from, with Back on a phone, the close button or Cancel, instead of dropping to the calendar.

## 0.7.2 (2026-10-03)

- **The old full-page event view is gone.** Since 0.7.0 every event and trip opens in the side panel or the sheet, so the view, its state, its keyboard stepping and 43 style rules that only it used are removed. Every way that could still have reached it (an event not yet loaded, the full-view request from a double-click or an Agenda line) now opens the panel or sheet. The pieces the panel, sheet and trips share (the mini-map and base map, links in descriptions, a repeat rule in words) moved to their own module.

## 0.7.1 (2026-10-03)

- **A trip's events show their map numbers only when the map is in view.** On a phone the collapsed sheet hides the map, and the numbers beside the places pointed at nothing.

## 0.7.0 (2026-10-03)

Trips, in step with events.

- **A trip opens where an event does:** the side panel on a desktop, the sheet on a phone, with the same toolbar, Back and stepping. It used to open as a small card in the middle of the screen on a desktop and a sparse page on a phone.
- **It leads with the trip:** its dates and length, its place with Directions, its events as rows that say when, what and where, and a map numbering the places of its events (on a phone, once the sheet is pulled up, as with an event's map).
- **Opening one of its events keeps the way back:** "‹ London trip" above the event, and Back on a phone returns to the trip.
- **Edit and Add events** are in the desktop toolbar; New event in this trip, the calendar's settings and Delete trip are in More. On a phone, Edit and More sit beside the title and Add events under the list.
- **Removing an event from a trip asks first,** from that event's own row (its ⋯), instead of a bare x beside it.
- **Adding events offers yours:** your planned and maybe events during the trip, on calendars you show, never weather or sunset entries; feed suggestions on request. A three-week trip through a busy city offered 241 candidates before, 29 now.

## 0.6.10 (2026-10-03)

- **More on the desktop event panel draws over the map.** The map's layers (Leaflet's, at z-index 400) shared a stacking layer with the menu and covered its lower items, Delete included. Every map now keeps its layers to itself.
- **Stepping through a day keeps the event you opened.** Open an event on a hidden calendar (from search, say), step to its neighbour, and it is still in the day's list to step back to ("3 of 3" no longer turns into "2 of 2"). Opening another event starts afresh.
- **Past search results recede** the way past events do on the calendar, so it is plain which results are behind you; the one under the pointer or keyboard comes back to full strength.

## 0.6.9 (2026-10-03)

Search, the sidebar and Settings on phones.

- **Search is a full screen on a phone,** with a taller box and a thumb-sized close. Each result is two lines: the title first, up to two lines of it rather than cut to a word, then when and where under it. Dates leave the year off when it's this year ("Fri, Oct 9 · 3:00 AM"), on every screen.
- **An event opens in the event sheet everywhere on a phone,** and in the side panel on a desktop: search results, notification links, the Agenda's multi-day lines, People, Activity, grouped events and trip members used to open the older full page on a phone. Only a trip keeps its own page.
- **Back works everywhere on a phone.** It closes search and the sidebar drawer, and from Settings, People, Review or any other page it returns to the calendar; before, Back on those left the app. Opening a page from the drawer, or moving between pages, never leaves a dead Back step behind.
- **The sidebar drawer is wider on a phone** (86% of the screen, up to 360 points), so calendar names fit beside Only and the gear.
- **Settings on a phone:** every tab is in view (they wrap to a second row instead of scrolling off the edge unannounced), controls are thumb-sized with 16-point text, and the Event panel setting, which only applies to wide screens, is hidden.

## 0.6.8 (2026-10-03)

- **Settings labels read as headings on a phone,** where each sits above what it names: 16 points and bold, a step above the content under it, with a thin rule between settings. On a desktop they are bolder too. The content (device names, values) keeps its size.

## 0.6.7 (2026-10-03)

- **Reminder devices are named:** "Pixel 9 Pro · Chrome app", "Mac · Chrome", "iPhone · Safari app". Every Chromium browser on every platform uses Google's push service, so the list used to call a phone and a desktop the same thing, "Chrome, Edge or Android". A device now says what it is when it signs up, and one signed up earlier names itself the next time the app opens there. Until then its entry says so. Entries also say when no reminder has reached them yet, and the Activity log uses the names.

## 0.6.6 (2026-10-03)

- **A reminder's Map button opens the place on Google Maps again,** whose page offers to open it in the Maps app. The Android intent link tried in 0.6.4 can't be launched from a notification, so it is gone.

## 0.6.5 (2026-10-03)

- **Undo on a toast works on the first tap with the event sheet open.** A press outside the sheet closes it and is swallowed, and the toast counted as outside, so the first tap only closed the sheet. Toasts now answer for themselves.
- **The toast's countdown is easier to see:** a 4-pixel line in the accent colour.
- **A reinstalled phone signs back up for reminders.** Reinstalling the app drops its push subscription and nothing noticed. At launch, a device that isn't signed up now signs up quietly if notifications are already allowed for it, or offers "Get reminders on this device?" with Turn on if they were never asked (only a press may bring up the permission prompt). A device you switched off with Disable is left alone.
- **Settings tells this device's state from this device.** It used to fall back to whether the account had any device, so a phone with a dead old registration on the list looked enabled after Disable or a reinstall, and Enable stayed greyed out. A registered phone the browser has allowed but that isn't signed up now reads "Off on this device" with Enable.
- **Settings labels sit level with the first line of what they name,** not halfway down a list (Devices receiving reminders).

## 0.6.4 (2026-10-03)

- **Folded sidebar groups stay folded across reloads** (calendar folders, All calendars, People), kept per device, so a phone and a desktop can be set up differently.
- **A reminder's Map button asks for the Google Maps app on Android** (an intent link naming it), since a plain link from a notification opens in a browser tab. Without the app it falls back to the same place on the web, and a browser that refuses the request gets the plain link as before.
- **The web app manifest names the app's identity and has a full-bleed PNG icon,** which Android's adaptive icons and Chrome's full install both use. A full install has its own notification icon and no "Open in Chrome" item in the notification shade.

## 0.6.3 (2026-10-03)

- **Better-Cal's notification icon is a calendar, not a square.** Android draws the small status-bar and notification-header icon from the image's shape alone, and the full-colour app icon is solid all over, so it showed as a plain square. Notifications now carry a white calendar silhouette for that spot.
- **Reminders have buttons:** Map for an event with a place, opening that place in Google Maps by name at its coordinates (the way Directions does in the app), and Join for a video call (Zoom, Meet, Teams, Webex) in the location, link or description. A tap on the notification itself still opens the event.
- **Settings' test notification shows a Map button,** so it can be tried without waiting for a reminder.

## 0.6.2 (2026-10-03)

- **For me on a repeating event asks right where you tapped.** The choice of this occurrence, this and following, or all of them opens under the switch on the phone sheet; it used to open at the top of the sheet, out of view whenever the sheet had scrolled, so Planned and Maybe seemed to do nothing.
- **Quick fill keeps the place in the title.** "Lunch at The Pig's Ear at 12PM" is titled "Lunch at The Pig's Ear", with The Pig's Ear in Location too. Only date and time phrases leave the title now ("Coffee @ Blue Bottle" reads "Coffee at Blue Bottle"). The AI reading follows the same rule, and when it drops words the text kept, the plain reading's title wins.
- **A place quick fill finds opens the Location choices,** biased to where you'll be, so you pick the right Pig's Ear rather than leaving it to a later guess. Nothing is picked for you. While you type it only opens; Enter or Fill moves the cursor there to pick with the arrows and Enter.
- **A new event starts in the quick-fill box;** editing an event starts in the title.
- **Toasts:** ten seconds when there's an Undo (six otherwise), with a thin countdown along the bottom; the toast leaves when it runs out, and a tap on its message dismisses it. On a phone it's bigger, Undo is a real button, and the close button never wraps to its own line.
- **Day lists line up:** every row's dot and title start at the same place, whether the chip is tinted, timed or runs overnight. An event that continues past the day shows a chevron inside the chip's edge instead of an angled cut, which broke the gold "happening" ring and the trip outline into floating tips.

## 0.6.1 (2026-09-28)

- **The phone event sheet puts the event first.** The band of big buttons at the bottom is gone. Edit and More are two small icons beside the title; For me is a small switch at the end of the calendar line, where it reads as part of what the event is to you; everything rarer (Move, More like, Less like, Copy to another calendar) is in More, which opens as a sheet from the bottom edge. The collapsed sheet shows more of the event in the same space, and an address keeps to two lines until the sheet is pulled up. Invitation replies and Directions are a size smaller.
- **The editor takes focus when it opens:** the title, or the plain-language box when text came with it. The Filter box (or whatever had focus) used to keep it. On a phone, editing an event doesn't raise the keyboard by itself.
- **Unticking All day makes an hour, not a day.** Ticking it leaves the times at midnight to midnight; unticking now turns those into one hour, at the next quarter hour today or 9 AM on another day. Times you set before ticking it are kept.

## 0.6.0 (2026-09-27)

Creating events on phones.

- **The event editor is a full screen on a phone,** sliding up, instead of a drawer with a sliver of calendar beside it. Close, the title and Create sit in a top bar, so the keyboard never hides Create; Delete and the Google note stay at the bottom when editing.
- **Back closes it,** as it closes the event sheet (with Undo if you'd typed anything). Opened with Edit from an event, it takes over that screen's place in history, so one Back leaves both.
- **Thumb-sized and readable:** 44-point rows, 16-point text (which also stops iPhones zooming in on every field), bigger checkboxes, For me and the length and zone chips.
- **Pickers on touch:** a tap on a time opens the quarter-hour list without the keyboard, with "Type a time" pinned at its top for an exact time; a tap on a date opens the phone's own calendar. The list rows are tap-sized.
- **Dates fit:** on a phone a date this year leaves the year off ("Sun, Sep 27"), so the box never cuts it short.
- **The form shrinks above the Android keyboard** instead of sliding under it.
- **New on a day:** the day sheet (a day tapped in month view, or its +more) has a New button: today starts at the next quarter hour, any other day at 9 AM.

## 0.5.10 (2026-09-27)

- **Directions (and every map link) open the place itself in Google Maps,** not a pin on bare coordinates. The link searches the event's own place text, its name and address, with the map already at its stored coordinates, so Google shows that place's card (hours, phone, reviews, Directions) and an ambiguous address can't land in another city. Coordinates alone are used only when the text has nothing findable in it (empty, "available once RSVP'd", a link, or a pair of numbers).

## 0.5.9 (2026-09-26)

- **The location dropdown no longer waits for your device's position.** Each search used to ask the browser where you are first, when location was allowed. A browser can stay silent (Chrome on a Mac with Location Services off for it), and then the dropdown never came. Search now goes out at once with the best place already known, and a fresh position is fetched in the background for the next search.
- **Location search looks near where your calendar puts you on that date:** the planned event with a known place nearest in time to the one you're editing (within a day and a half; a stay spanning it counts first). A dinner added during a trip searches near the trip. Then your device's position, your device's time zone when you're away from Home, your Home location, and the zone.
- **"and" and "&" find the same place.** "Panda and Sons" finds "Panda & Sons" (both spellings are asked at once).
- **The panel and sidebar ease over 300ms** instead of 220ms.

## 0.5.8 (2026-09-26)

- **Copy to another calendar moved into More,** on the desktop panel and the phone sheet. It is rarely used, and on its own a copy icon read as "duplicate this event".
- **More says what each group acts on:** the event (Copy to another calendar), the calendar it is on ("Calendar: Partiful", with its colour, then Calendar settings and Hide this calendar), and the site it came from ("Source: partiful.com", then "Open on partiful.com", marked new tab, and "Copy the partiful.com link"). Nothing reads as a page or link of this app when it is the source's. That matters more once calendars can be shared.
- **On the desktop panel, More's rows are list-sized** for a mouse, with hover, rather than thumb-sized.

## 0.5.7 (2026-09-26)

- **Forwarded emails are read on the clock of the place they're about.** A booking in Edinburgh is 3 PM Edinburgh time whatever your Home zone is: the reader names the zone of the event's address, venue or city (or a zone the email states, like "3pm ET"), and the event keeps that zone. An email naming no place is read on the clock where your device last was, then Home. "Add to Google Calendar" links keep their own zone too, and a time without an offset is never read on the server's clock. Before this, emailed events were all stamped with Home's zone, so a correct 3 PM in Edinburgh also said "7:00 AM in Los Angeles".
- **The app tells the server where you are** (your device's time zone) whenever it changes, for the rule above.
- **The event panel's actions sit in a fixed toolbar at the top:** Edit and Move with their words, Copy and More as icons, For me on the right. It is the same height for every event, so stepping through a day never moves a button or the details under it.
- **The calendar eases over when the event panel opens or closes,** and the sidebar slides out and back instead of vanishing (220ms; off with reduced motion). The sidebar keeps its width as it slides, so nothing inside it reflows.
- **The event editor has labels on the left and one line per field,** grouped by thin rules instead of boxes: a large title, Calendar, Start, End, then All day, the length and the time zone on one line; Location, Link and Description; People, Tags, For me and the trip box; Reminders and Repeat. Create and Cancel stay pinned at the bottom however long the form is. The natural-language box is one line.
- **The time zone is a visible control in the editor,** a globe chip naming the zone ("London time") that opens the zone list. It was a plain link before, and easy to miss.
- **Deploys keep the newest 5 backups** of the app and the database, not 20.

## 0.5.6 (2026-09-26)

- **Dates and times in the event editor are plain text boxes now.** Clicking in selects the whole value, so you just type over it: "7p", "7:30", "1930" or "noon" for a time, "fri", "tomorrow", "10/5", "oct 5" or a day of the month for a date. Enter or moving on applies it; anything unreadable puts the old value back and marks the field, and Esc undoes the typing without closing the editor. The calendar button beside each date still opens the date picker.
- **Times have a quarter-hour list,** opened when you click in: one click for the usual choices, and any exact time can still be typed. The end list starts after the start and shows each length ("1 hr", "1.5 hr"), so an overnight end is one pick.
- **The end follows the start.** Moving the start keeps the event's length (the lock, now showing that length, turns this off). With the lock off, a start that passes the end pushes the end to an hour later. An hour typed without am or pm reads sensibly: the start keeps its half of the day, the end takes the first time after the start, and an end earlier than the start means the next morning when that makes 12 hours or less (10 PM to "1"). Otherwise the editor says the end is before the start, offers to fix it, and won't save until it's fixed.
- **Maybe when you create an event.** "For me: Planned / Maybe" sits in the editor for events on your own calendars; Maybe saves the event as tentative, which other calendar apps and Google see too.
- **A tighter editor.** It is as wide as the event panel (480). Start and end each sit on one line with their labels, All day and the length share a line under them, the trip checkbox is shorter and shares its line with For me, and Reminders and Repeat sit side by side, reminders listed first with Add reminder under them.
- **A blank new event starts at the next quarter hour,** not at the current minute.
- **Every production deploy backs up first:** the app directory and the database, into backups/ beside the app, keeping the newest 20 of each. A failed backup stops the deploy.

## 0.5.5 (2026-09-26)

- **On desktop, an event opens in a panel on the right** instead of a small card beside it. The panel is the same place and size every time, 480 points wide at full height under the top bar, so stepping through a day never moves or resizes it. It holds everything at once, the map included, so there's no separate full view to open; double-clicking an event (or anything that used to open the full view) opens the panel too. Trips keep their own view.
- **The calendar makes room for it** rather than hiding under it, so every day stays visible and clickable, with the open event outlined. Clicking another event opens it in the panel; clicking anywhere else closes it, as does Esc. [ and ] step through the day.
- **The sidebar tucks away while the panel is open,** giving the days back their width, and returns when you close it. Settings, General, "Event panel" turns that off.
- **Same contents as the phone's event sheet,** at desktop sizes: For me and labelled actions (Edit, Move, Copy to, More; or More like, Less like, Copy to, More) under the title, then when, the invitation reply, where with Directions or Join, reminders, people, the map, the description, plugin data and the source.

## 0.5.4 (2026-09-26)

- **Addresses with extra words now find their place on the map.** Feeds often decorate an address: "111 Conselyea St, Brooklyn, NY 11211, USA (The Lounge)", "[Upstairs] 5 Main St", or a venue name ahead of the street. When the full text finds nothing, Better-Cal now tries it without the bracketed parts, and then from the street number on, and uses the first that resolves. Addresses that had already been marked "couldn't place" get the retry too: the map appears the next time the event is opened, and the background matching picks them up on its next pass.

## 0.5.3 (2026-09-26)

- **Pulled up, the phone's event sheet is the full view.** Under the address, a map (a still image until you tap it, so it doesn't catch your scrolling); the full description with its links tappable; any plugin information; for feed events, the feed with a link to its source; and when the event was added and last updated. The separate full-screen view is no longer needed on phones, so "All details" leaves the More menu.
- **Invitations can be answered from the sheet:** Accept, Maybe or Decline, right under the time, in the quick view as well as pulled up.
- **Lighter controls:** For me and the invitation reply size to their labels at 36 points instead of stretching across the sheet, and the action bar is 48 points tall.
- Fixed: on an event with a lot in it, the sheet's rows could squeeze into each other instead of the sheet scrolling.

## 0.5.2 (2026-09-26)

- **The phone's event sheet, redone inside.** Bigger type (the title at 22 points, the facts at 16) and the event as rows with an icon each: when (with how it repeats and the time where it is), where, reminders, people, tags, the description and the event's link. Nothing to tap is smaller than 44 points.
- **One bar at the bottom at a time.** While an event is open the app's bottom bar slides away and the sheet's actions take its place, under the thumb; it slides back when the sheet closes.
- **Four labelled actions for every event.** Your events: Edit, Move, Copy to, More. Feed and suggested events: More like, Less like, Copy to, More. Delete is in More, set apart in red, away from Edit.
- **"For me" as buttons,** pinned just above the actions: Planned or Maybe for your own events; Planned, Maybe or Hide for suggestions (tap the lit one again to put it back to available).
- **More** holds Manage (opens the event's calendar settings in the sidebar: rename, colour, folders, what it is, unsubscribe or delete), Hide this calendar, the event's own page and link, All details (the map, invitation replies and source, until those move into the sheet) and Delete.
- **Meeting links are a Join button** (Zoom, Google Meet, Teams, Webex), with Copy beside it, instead of a raw address with a "Map" link. A real address gets Directions; a web address in the location gets Open.

## 0.5.1 (2026-09-25)

- **The phone's event sheet points at the day you're on.** Stepping onto a multi-day event outlines it where the browsed day shows it: its segment in that day's week in month view, its row on that day in lists, or, where a list shows the event as a rail between its first and last rows, the rail beside that day. The list no longer jumps back to the event's first day.
- **With the sheet open, tapping another event opens it** in the sheet straight away, instead of first only closing the sheet. A tap anywhere else still just closes it, and a row's swipe actions never take a tap through.

## 0.5.0 (2026-09-25)

- **On phones, an event opens in a sheet that holds still.** It opens at one height for every event, so stepping through a day changes what's in it, never where its top edge is. The event you tapped scrolls into view just above it in the calendar, outlined, and the outline follows as you step.
- **Swipe the sheet sideways** to step through the day's events, or tap the larger arrows either side of the date; dots show where you are in the day.
- **Pull it up for everything,** by dragging the handle, tapping it, or tapping the title or More: the same sheet grows to full height instead of switching to a different screen. Drag down to shrink it, and again to close. The phone's back gesture steps it down the same way, rather than leaving the calendar. Tapping above the sheet still closes it. (Its expand button, once grown, still opens the separate full view for the map, invitation replies and source, until those move into the sheet.)
- **Stepping through a day follows what the view shows,** on phones and desktop alike: calendars you've hidden, events you've hidden, kinds the Show filter has switched off and context such as sunset are skipped, and the count ("3 of 4") no longer changes as you step.

## 0.4.18 (2026-09-25)

- **The calendar picker's list looks scrollable at rest,** like the sidebar: a soft shadow at the top or bottom edge wherever there are more calendars that way, and a scroll bar that stays (on touch screens, a thin drawn thumb, since the platform's bar only flashes while scrolling).

## 0.4.17 (2026-09-25)

- **The calendar picker shows each calendar's colour** when creating or editing an event, and in quick add: a dot beside every name in the list and on the closed picker, so the right calendar is found by colour at a glance. This uses the browser's customizable select (Chrome and Edge 135 and later, desktop and Android); other browsers keep the plain list of names.
- Escape while a dropdown list is open now just closes the list, not the editor it's in.

## 0.4.16 (2026-09-25)

- **A dropped ssh connection can no longer take the site down mid-deploy.** The deploy scripts opened a new ssh connection for each step. While the server's sshd was busy turning away brute-force logins, it dropped one of them right after the code was copied, the ownership fix never ran, and the web server refused every file until the deploy was re-run. Now one connection, opened with retries before anything changes, carries every step, and the server side of rsync writes as the app user, so copied files have the right owner the moment they land (with any rsync on the deploying machine, including macOS's built-in one).
- **A failed deploy says so.** If a step fails after the code was copied, the script prints which step, whether the site's health check still answers, and that the deploy needs re-running. A failure before that says nothing on the server changed. A failing health check in the final smoke test now fails the deploy; before, it was printed and ignored.
- **Deploys no longer re-own the whole app directory,** only stray files a root shell left behind, and never the `.env`, which briefly passed to the app user on every deploy.
- Self-hosters: the deploy login still needs passwordless sudo (or is root); rsync is now received through `sudo -n -u APP_USER`.

## 0.4.15 (2026-09-25)

- **Holidays are written with the date, not as context.** A holiday calendar's days read "Mon, Oct 12 · Columbus Day" in agenda headings (and Split's list) and the day list, after the day number in week heads and desktop month cells, and after the date in the desktop day view's title. They no longer sit among the weather and other context tokens, where their names were cut short or cut the date. In the phone's day view the holiday gets its own line at the top of the day, in full. In the phone's full month, where a cell has room for a letter or two, it's a small flag after the day number; tap it for the name. Holiday calendars are recognized by name, as before; tapping a holiday opens it.

## 0.4.14 (2026-09-25)

- **The date title's hint is a small "jump to a date" icon** (a calendar page with a magnifier) instead of a dropdown caret, on phones and desktop.

## 0.4.13 (2026-09-25)

- **Phone day view: the date in each day's divider reads the same as the top bar** ("Tue, Oct 13"), so it doesn't reorder itself as it scrolls up to become the title.
- **The date in the top bar is never cut short;** the weather and other context after it clip instead.
- **Weather and other values come before named days** (a holiday, someone away) wherever a day's context is listed, so in a tight header it's the name that gets cut, not the numbers.

## 0.4.12 (2026-09-25)

- **Phone day view: the day's weather sits to the right of the date** in the top bar, on one line ("Thu, Sep 24 · 79/55 · AQI 68"), instead of on a cramped second line under it. The date shortens to make room.

## 0.4.11 (2026-09-25)

- **Phone day view: one date, not two.** The top bar already names the day you're on, so each day's date and weather are now a thin divider line that scrolls up under the bar, and the bar then shows that day (as "Thursday, Sep 24") with its weather on a small line under it. The sticky header below keeps only the day's all-day items, and a day without any has none, so the hours start a whole row higher. Nothing changes height as days pass the top, so the hours don't jump.
- **The today button reads as a date:** "25th" rather than a bare "25", on a small calendar-page shape (a heavier top edge).
- **The date title shows it opens the date picker,** with a small caret after it, on phones and desktop.

## 0.4.10 (2026-09-25)

- **Week view: all-day bars keep their order as you scroll.** Lanes are now worked out from real dates across everything loaded (earlier start on top, then the longer one, trips first on a shared start), so a bar no longer hops between lines as you scroll left and right. A line that's empty across the days in view still closes up, keeping the same order.
- **Fixed: the ends of finished multi-day bars trailed along the left edge** of the week's all-day area after scrolling past them (from 0.4.8's title-stays-in-view change, which could stretch a bar past its own end).
- **The "which occurrences?" choice for a repeating event fits a phone:** it spans the screen and wraps, with the question on its own line, so Cancel is no longer off the right edge.

## 0.4.9 (2026-09-25)

- **Creating and moving events by touch in week and day views.** Tap an empty time to start a one-hour event there; press and hold, then drag, to draw a longer one (dragging across days makes it all-day), or to pick up an event and move it to a new time or day. A short buzz says it's picked up. A finger that moves first is scrolling and creates nothing, and a tap that stops a scroll in motion isn't taken as a create. Until now touch creation was switched off in these views and a held event dropped as soon as your finger moved.
- **Fixed: "+N" in week view did nothing.** The all-day "+N" on phones, and the weather and context "+N" in desktop week heads, now open that day's full list.
- **Phone week: the weather goes back above the date,** as in the other views, instead of under it.

## 0.4.8 (2026-09-25)

- **Phone week: pinch sideways to set how wide days are.** Spread two fingers for wider days, pinch in to fit up to about seven across. The day under your fingers stays put, and the phone remembers the width. It starts where it always has, about 2.3 days across (one setting in the code).
- **Phone week: blocks show their title only, wrapped over the block,** instead of a cut-off title with the time under it; the hour column already says when. Overlaps keep the same cascade as before.
- **Phone week: a thinner all-day area.** Three slim lanes, then a "+N" line under any day with more (tap it for that day's full list), at one steady height, so the grid no longer grows by a lane per overlap or jumps as you scroll sideways. Day heads are the date with the weather under it, the hour column is narrower ("8a"), and when days get narrow the weekday shows as its first letter.
- **Phone day view: all-day items get full-width rows** under the date, with their full names and where they are in their span ("day 2 of 3"), instead of a narrow column beside the date. Up to four show before "+N more".
- **Week view, everywhere: a long all-day bar keeps its title in view.** A trip that began weeks ago used to show as a bare outline because its title was back at its start, off screen; the title now stays at the visible left edge.

## 0.4.7 (2026-09-25)

- **"Location available once RSVP'd" and the like** now show as a small lock with "After RSVP" ("RSVP" in phone rows; the feed's own wording on hover). They're no longer offered as a map, sent off to be placed on one, or shown on timeline blocks as if they were an address.
- **Split: steadier scrubbing, no flick.** The weeks move in the same frame as the list instead of one frame behind, and finger movement is applied once per frame. A flick no longer carries on after you let go: in whole-week glides it moved faster than you could follow. A trackpad or wheel keeps its own momentum.
- **One view order everywhere:** Split is last on phones too, as on desktop.
- **The sidebar's buttons no longer shift** when its scroll bar appears or goes away (folding or unfolding a group): the bar's space is always kept.

## 0.4.6 (2026-09-25)

- **Split: scrolling over the weeks scrubs through the days.** The weeks no longer slide on their own: moving your finger (or the wheel) over them walks the day being read through the week, one row's height of travel per week, with the list following. The weeks hold still, the day moves along its row, and at the end of the week they glide one row. A flick carries on and slows to a stop. Both halves now move the same way, and the current week never slides half out of view.
- **Split: with three or more weeks showing, the current week sits in the middle row**, a week of context above it (one above centre when the count is even); with two, at the top.
- **Phone lists: rows with actions carry a grip** (⋮) at their end, so a suggested event you can triage looks different from one you can only open; tapping the grip opens the actions too.
- **Folded "nothing on" days** have a halo around their text so it reads over the hatch, and a clearer icon: arrows pressing in on a dashed fold.
- **Sidebar on phones:** it no longer scrolls a couple of pixels sideways (the enlarged tap areas of its right-edge buttons reached past it), so no stray scroll bars flash as it slides in. It has a scroll bar that stays visible and soft shadows at the top and bottom edges wherever there's more to scroll.

## 0.4.5 (2026-09-25)

- In the phone's swipe actions, Hide is soft rose, the one action that takes an event off your calendar, and no longer the same grey as Less like this. The two thumbs sit a little apart from Maybe, Planned and Hide, since they're feedback on suggestions rather than a choice about the event.

## 0.4.4 (2026-09-25)

- The swipe actions on phones sit in a slightly taller tray that stands just proud of its row, so icons and labels have room instead of touching the edges. The buttons are soft tints of their colors (amber, green, grey, blue, slate) with labels in a deeper shade of the same hue: pastel in light mode, muted in dark. The row itself keeps its height.
- Fixed: a thin sliver of the closed tray showed at the end of every row, and the last event of a day had the bottom of its tray cut off.

## 0.4.3 (2026-09-25)

A compact list on phones, for Split and the Agenda view (the agenda redesign, brought forward from 0.5.0 for phones; desktop styling comes separately).

- **One line per event:** the start time in a narrow column ("8p", "8:30p", "all day", "1/4" for a day of a multi-day event), the title at full width, then the place, muted. A meeting link shows just its site ("zoom.us"). The full details stay on the event card, one tap away.
- **Triage on a swipe:** drag a suggested event left to reveal Maybe, Planned, Hide, More like this and Less like this. Let go past halfway and they stay open; a tap anywhere else closes them without doing anything else. The row of buttons on every event is gone.
- **Slimmer day headings:** "Fri, Sep 25" on one line with the weather and sunset.
- **Unchanged:** the rails and tinted rows that mark multi-day events, now sized to the smaller rows.
- **Folded empty days** (Split) have a stronger hatch, so they read as skipped time at a glance, on desktop too.

## 0.4.2 (2026-09-25)

- The phone's month uses each day's full height. A fixed limit of three events per day showed "+2" with room for two more still empty below; now a day shows as many as fit, and "+N" appears only when it truly overflows. Split's weeks get the same room.
- Week numbers no longer collide with the month name pinned at the top of the left column: they slide under it and fade out as they reach it.

## 0.4.1 (2026-09-25)

- The server refuses a request for more than two years of events with a clear error, instead of quietly returning only the first two years. The app already asks for long spans in pieces (0.4.0), and the refresh after an edit now does too, so this only shows if something is wrong, as the "couldn't load" note.
- Split's list never claims "nothing on" for days it hasn't loaded. A stretch not fetched yet reads "not loaded yet", in a dashed outline, and fills in as it loads.
- Jumping somewhere in Split (Today, the arrows, jump to date) lands the moment that day's events arrive, with no fixed delay. Scrolling the list yourself while it waits cancels the jump. Landing on a day inside a folded run outlines that day, not the run's first.
- Split's day outline no longer drops out after a long jump; it's put back whenever the weeks redraw.

## 0.4.0 (2026-09-25)

A new view: Split.

- **Split** puts a strip of weeks over a list that runs on without a break. The weeks are the month you know: titles in every day, trips and stays as bars across the days they cover, "+N" when a day is full. The list below is every day in order, with weather and sunset in each day's heading and multi-day events drawn with their rails.
- **The two move together.** Scroll the list and the weeks follow: they hold through a week and glide to the next as Saturday turns into Sunday, and the day at the top of the list is outlined. Scroll the weeks and the list follows; tap a day number and the list goes there. Today, the arrows and jump to date move both. Nothing pages or snaps.
- **Drag the handle between them** to show more weeks or more list, from part of a week to the whole screen. It's remembered on each device; two weeks to start.
- **Empty days fold.** In Split's list, each run of days with nothing on becomes one line ("Thu, Oct 22 to Fri, Oct 23 · nothing on"), set in italics over a faint hatch so it reads as skipped time. When a trip or stay runs through those days it says "nothing else on".
- Split is in the view menu, the phone's View sheet (after 3 day), the command palette and on key 7, so the other views keep their number keys. The phone still opens on 3 day unless you've picked another view.
- The 3-day view on phones drops the start time from each event, which took half of every pill; the title gets the room.
- Fixed: a request for more than about two years of events was quietly cut short by the server, and the app then treated the whole span as loaded, so the later part stayed empty. Long spans are now fetched in pieces.

## 0.3.10 (2026-09-24)

- The month and 3-day views' left column (month names and week numbers) now alternates by month along with the days, in both themes. Before, only the day cells did.

## 0.3.9 (2026-09-24)

- Coming back to the app after an update, or after the phone set it aside, opens where you were: the same view, the same place in the calendar, the saved view in use and the filter text. Before, a reload opened on today in the default view. Updates now apply only while the app is out of sight, or after 10 minutes untouched (was 20 seconds), and a fresh launch, or a return after half an hour, still opens on today.
- On phones and narrow windows, swipe the sidebar in from the left edge of the calendar (the column with the month names) and swipe it back out, as in Google Calendar's app. It follows the finger and finishes past a third of the way or with a flick. On Android, start just inside the screen edge; the edge itself is the system's back gesture.
- Phone top bar: previous and next sit together before the month name, so they no longer move as the month's name gets longer or shorter. Today is back in the top bar, at the right, as a badge showing today's date. The menu icon is larger.
- Phone bottom bar: Review (invitations, held changes and proposals waiting on a decision, with their count) takes the slot Today left. The bar stays on the Review page; tap Review again, or any other button, to go back to the calendar.
- The New button has a ring in the bottom bar's own colour and edge, and a soft glow, so it stands out from the calendar behind it.
- Month and 3-day views: the month names in the left column are larger and bolder, and alternate months are shaded more clearly, in both themes.
- The mark beside a day when the Show filter hides some of its events is now the half-filled circle the sidebar uses for "some shown", and on touch screens it can be tapped (it shows everything again, as clicking it does on desktop). Before, a tap missed it and opened a new event.

## 0.3.8 (2026-09-24)

Phones: the top bar and the bottom bar's edge.

- Previous and next are back on phones, either side of the month name: small to look at, the full bar height to tap. The logo is gone from the phone's top bar.
- Previous, next and Today now glide the month grid to where they land when it is near, so the days in between pass by instead of the grid cutting to a new place, on desktop too. Far jumps still move at once, and nothing glides if the device asks for reduced motion. Pressing Next again mid-glide steps on from where the glide is going.
- While anything narrows the calendar, a row of chips under the phone's top bar says what: the saved view in use, the Show setting ("Planned only", "No context") and the filter text. Tapping a chip opens its control (the Show menu right there, the Filter sheet with its text field focused, the View sheet); its × clears it. The row is gone when nothing is narrowed.
- The bottom bar has a firmer top edge, so it reads as separate from the calendar in both light and dark themes.
- Menus on touch screens no longer list keyboard shortcuts (the p, m, a, x beside the Show choices).

## 0.3.7 (2026-09-24)

- On a phone, the full month no longer squeezes context (weather, AQI, sun, tides) beside each day number, and the 3-day view shows one item, usually the weather, without a "+N". Opening a day still lists all of them. Desktop is unchanged.

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
